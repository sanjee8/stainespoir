<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Child;
use App\Entity\Message;
use App\Entity\Outing;
use App\Entity\OutingRegistration;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class OutingInvitationManager
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Invite des enfants à une sortie :
     *  - $levels : ex ['6e','5e'] (filtre multi)
     *  - $childIds : liste exacte d’IDs
     *  - $onlyEligible : exclut ceux déjà inscrits (quel que soit le statut)
     *  - $sendMessage : crée un Message "staff" au parent pour info
     *  - $messageTpl : texte optionnel avec placeholders {ENFANT} {SORTIE} {DATE} {LIEU}
     */
    public function invite(
        Outing $outing,
        array $levels = [],
        array $childIds = [],
        bool $onlyEligible = true,
        bool $sendMessage = false,
        ?string $messageTpl = null
    ): array {
        // 1) Cibles : enfants validés (par niveau / id)
        $children = $this->getValidatedChildren($levels, $childIds);
        if (!$children) {
            return ['targets' => 0, 'created' => 0, 'skipped' => 0, 'messages' => 0];
        }

        $cidList = array_map(fn(Child $c) => (int)$c->getId(), $children);

        // 2) Pré-charger les regs existantes pour cette sortie
        $existing = $this->em->createQuery(
            'SELECT r, c FROM App\Entity\OutingRegistration r
             JOIN r.child c
             WHERE r.outing = :o AND c.id IN (:ids)'
        )->setParameter('o', $outing)
            ->setParameter('ids', $cidList)
            ->getResult();

        $existsByChild = [];
        foreach ($existing as $r) {
            /** @var OutingRegistration $r */
            $existsByChild[(int)$r->getChild()->getId()] = $r;
        }

        $created = 0; $skipped = 0; $msgCount = 0;

        foreach ($children as $child) {
            $cid = (int) $child->getId();
            $reg = $existsByChild[$cid] ?? null;

            if ($reg && $onlyEligible) {
                $skipped++;
                continue;
            }

            if (!$reg) {
                $reg = (new OutingRegistration())
                    ->setChild($child)
                    ->setOuting($outing)
                    ->setStatus('invited'); // statut initial
                $this->em->persist($reg);
                $created++;
            }

            // Message optionnel au parent
            if ($sendMessage) {
                $msg = (new Message())
                    ->setChild($child)
                    ->setSender('staff')
                    ->setSubject('Invitation à une sortie')
                    ->setBody($this->renderMessage($messageTpl, $child, $outing));
                $this->em->persist($msg);
                $msgCount++;
            }
        }

        $this->em->flush();

        return [
            'targets'  => count($children),
            'created'  => $created,
            'skipped'  => $skipped,
            'messages' => $msgCount,
        ];
    }

    /**
     * Relance les non-répondants (status=invited) pour une sortie.
     * En option, envoie un Message.
     */
    public function remindInvited(
        Outing $outing,
        bool $sendMessage = true,
        ?string $messageTpl = null
    ): array {
        $rows = $this->em->createQuery(
            'SELECT r, c FROM App\Entity\OutingRegistration r
             JOIN r.child c
             WHERE r.outing = :o AND r.status = :st'
        )->setParameter('o', $outing)
            ->setParameter('st', 'invited')
            ->getResult();

        $msgCount = 0;
        if ($sendMessage && $rows) {
            foreach ($rows as $r) {
                /** @var OutingRegistration $r */
                $c = $r->getChild();
                $msg = (new Message())
                    ->setChild($c)
                    ->setSender('staff')
                    ->setSubject('Relance — invitation sortie')
                    ->setBody($this->renderMessage($messageTpl, $c, $outing));
                $this->em->persist($msg);
                $msgCount++;
            }
            $this->em->flush();
        }

        return ['invited' => count($rows), 'messages' => $msgCount];
    }

    private function renderMessage(?string $tpl, Child $child, Outing $outing): string
    {
        $base = $tpl ?: "Bonjour,\n\nVotre enfant {ENFANT} est invité(e) à la sortie « {SORTIE} » le {DATE} à {LIEU}.\nMerci de vous connecter à votre espace pour autoriser la participation.\n\nL’équipe Stains Espoir";
        $map = [
            '{ENFANT}' => trim(($child->getFirstName() ?? '') . ' ' . ($child->getLastName() ?? '')),
            '{SORTIE}' => $outing->getTitle(),
            '{DATE}'   => $outing->getStartsAt()->format('d/m/Y H:i'),
            '{LIEU}'   => $outing->getLocation() ?? '—',
        ];
        return strtr($base, $map);
    }

    /**
     * Enfants validés (parents approuvés + enfant approuvé si champ dispo),
     * filtrés par niveaux et/ou IDs.
     */
    private function getValidatedChildren(array $levels, array $childIds): array
    {
        $em = $this->em;
        $cmChild = $em->getClassMetadata(Child::class);
        $qb = $em->createQueryBuilder()->select('c')->from(Child::class, 'c');

        // Lien Child -> User ou Child -> Profile -> User
        $childToUserField = null; $childToProfileField = null; $profileClass = null;

        foreach (($cmChild->associationMappings ?? $cmChild->getAssociationMappings()) as $field => $map) {
            $target = $map['targetEntity'] ?? null;
            if ($target === User::class) { $childToUserField = $field; break; }
        }
        if (!$childToUserField) {
            foreach (($cmChild->associationMappings ?? $cmChild->getAssociationMappings()) as $field => $map) {
                $target = $map['targetEntity'] ?? null;
                if ($target && class_exists($target) && stripos((string)$target, 'Profile') !== false) {
                    $childToProfileField = $field; $profileClass = $target; break;
                }
            }
        }

        if ($childToUserField) {
            $qb->join('c.'.$childToUserField, 'u')
                ->andWhere('u.isApproved = :ok');
        } elseif ($childToProfileField && $profileClass) {
            $qb->join('c.'.$childToProfileField, 'p');
            $cmProfile = $em->getClassMetadata($profileClass);
            $profileToUserField = null;
            foreach (($cmProfile->associationMappings ?? $cmProfile->getAssociationMappings()) as $pf => $map) {
                $target = $map['targetEntity'] ?? null;
                if ($target === User::class) { $profileToUserField = $pf; break; }
            }
            if ($profileToUserField) {
                $qb->join('p.'.$profileToUserField, 'u')
                    ->andWhere('u.isApproved = :ok');
            }
        }
        if (strpos((string)$qb->getDQL(), ':ok') !== false) {
            $qb->setParameter('ok', true);
        }

        // Filtre Child.isApproved si dispo
        if ($cmChild->hasField('isApproved')) {
            $qb->andWhere('c.isApproved = true OR c.isApproved IS NULL');
        }

        // Filtre niveaux
        if ($levels && $cmChild->hasField('level')) {
            $qb->andWhere('c.level IN (:lv)')->setParameter('lv', $levels);
        }

        // Filtre IDs
        if ($childIds) {
            $qb->andWhere('c.id IN (:ids)')->setParameter('ids', array_map('intval', $childIds));
        }

        // Tri
        if ($cmChild->hasField('lastName'))  { $qb->addOrderBy('c.lastName','ASC'); }
        if ($cmChild->hasField('firstName')) { $qb->addOrderBy('c.firstName','ASC'); }

        return $qb->getQuery()->getResult();
    }
}
