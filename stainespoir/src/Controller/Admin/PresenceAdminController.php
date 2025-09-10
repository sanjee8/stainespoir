<?php
namespace App\Controller\Admin;

use App\Entity\Child;
use App\Entity\Attendance;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class PresenceAdminController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('/admin/presences', name: 'admin_presences', methods: ['GET','POST'])]
    public function index(Request $request): Response
    {
        // 1) Date du jour (Europe/Paris) si non fournie
        [$dayStart, $dayEnd, $date] = $this->resolveDayBounds($request->get('date'));

        // 2) Enfants validés (et parents validés) — détection dynamique du mapping
        $children = $this->getValidatedChildren();

        // 3) Map des présences existantes pour la date (childId => Attendance)
        $attRows = $this->em->createQuery(
            'SELECT a, c FROM App\Entity\Attendance a
             JOIN a.child c
             WHERE a.date >= :d1 AND a.date < :d2'
        )->setParameter('d1', $dayStart)
            ->setParameter('d2', $dayEnd)
            ->getResult();

        $attMap = [];
        foreach ($attRows as $a) {
            /** @var Attendance $a */
            $cid = (int)$a->getChild()->getId();
            $attMap[$cid] = $a;
        }

        // 4) POST => enregistrement
        if ($request->isMethod('POST') && $request->request->get('do') === 'save') {
            $tokenId = 'presence_save_'.$date->format('Ymd');
            if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $posted = (array) $request->request->all('st'); // [childId => present|absent|unset]
            $created = 0; $updated = 0; $deleted = 0;

            foreach ($children as $child) {
                /** @var Child $child */
                $cid = (int) $child->getId();
                $val = $posted[$cid] ?? 'unset';
                $val = in_array($val, ['present','absent','unset'], true) ? $val : 'unset';

                $existing = $attMap[$cid] ?? null;

                if ($val === 'unset') {
                    if ($existing) { $this->em->remove($existing); $deleted++; }
                    continue;
                }

                if ($existing) {
                    if (method_exists($existing,'setStatus')) { $existing->setStatus($val); }
                    $updated++;
                } else {
                    $a = new Attendance();
                    if (method_exists($a,'setChild')) $a->setChild($child);
                    if (method_exists($a,'setDate'))  $a->setDate($dayStart); // minuit du jour
                    if (method_exists($a,'setStatus'))$a->setStatus($val);
                    $this->em->persist($a);
                    $created++;
                }
            }

            $this->em->flush();
            $this->addFlash('success', sprintf('Présences enregistrées — +%d, ✎%d, −%d', $created, $updated, $deleted));

            // PRG
            return $this->redirectToRoute('admin_presences', ['date' => $date->format('Y-m-d')]);
        }

        return $this->render('admin/presences/index.html.twig', [
            'date'     => $date,
            'dayStart' => $dayStart,
            'children' => $children,
            'attMap'   => $attMap,
            'token_id' => 'presence_save_'.$date->format('Ymd'),
        ]);
    }

    /**
     * Retourne [startOfDay, endOfDay, dateObj] en Europe/Paris
     */
    private function resolveDayBounds(?string $input): array
    {
        $tz = new DateTimeZone('Europe/Paris');
        if ($input && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
            $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $input.' 00:00:00', $tz);
        } else {
            $now = new DateTimeImmutable('now', $tz);
            $d = $now->setTime(0,0,0);
        }
        $start = $d;
        $end   = $d->modify('+1 day');
        return [$start, $end, $d];
    }

    /**
     * Récupère TOUS les enfants rattachés à un parent validé,
     * quel que soit le mapping (Child->User ou Child->Profile->User).
     * Inclut filtre Child.isApproved si ce champ existe.
     *
     * @return Child[]
     */
    private function getValidatedChildren(): array
    {
        $em = $this->em;
        $cmChild = $em->getClassMetadata(Child::class);

        $qb = $em->createQueryBuilder()->select('c')->from(Child::class, 'c');

        // ---- Trouver comment rejoindre le User ----
        $childToUserField = null;
        $childToProfileField = null;
        $profileClass = null;

        foreach (($cmChild->associationMappings ?? $cmChild->getAssociationMappings()) as $field => $map) {
            $target = $map['targetEntity'] ?? null;
            if ($target === User::class) {
                $childToUserField = $field;
                break;
            }
        }
        if (!$childToUserField) {
            // Cherche une assoc vers un *Profile* qui lui-même pointe vers User
            foreach (($cmChild->associationMappings ?? $cmChild->getAssociationMappings()) as $field => $map) {
                $target = $map['targetEntity'] ?? null;
                if ($target && class_exists($target)) {
                    // Heuristique: nom de classe contient 'Profile'
                    if (stripos((string)$target, 'Profile') !== false) {
                        $childToProfileField = $field;
                        $profileClass = $target;
                        break;
                    }
                }
            }
        }

        if ($childToUserField) {
            // c.<userField> = u
            $qb->join('c.'.$childToUserField, 'u')
                ->andWhere('u.isApproved = :ok');
        } elseif ($childToProfileField && $profileClass) {
            // c.<profileField> = p ; p.<toUser> = u ; u.isApproved = true
            $qb->join('c.'.$childToProfileField, 'p');

            $cmProfile = $this->em->getClassMetadata($profileClass);
            $profileToUserField = null;
            foreach (($cmProfile->associationMappings ?? $cmProfile->getAssociationMappings()) as $pf => $map) {
                $target = $map['targetEntity'] ?? null;
                if ($target === User::class) { $profileToUserField = $pf; break; }
            }
            if ($profileToUserField) {
                $qb->join('p.'.$profileToUserField, 'u')
                    ->andWhere('u.isApproved = :ok');
            } else {
                // pas de lien explicite profile->user => on ne filtre pas sur u.isApproved
            }
        } else {
            // Aucun lien découvert vers User/Profile => pas de filtre parent validé
        }

        // Paramètre commun si utilisé
        if (strpos((string)$qb->getDQL(), ':ok') !== false) {
            $qb->setParameter('ok', true);
        }

        // Filtrer enfant validé si le champ existe
        if ($cmChild->hasField('isApproved')) {
            $qb->andWhere('c.isApproved = true OR c.isApproved IS NULL');
        }

        // Tri: lastName/firstName si dispos, sinon par id
        $order = false;
        if ($cmChild->hasField('lastName')) { $qb->addOrderBy('c.lastName', 'ASC'); $order = true; }
        if ($cmChild->hasField('firstName')) { $qb->addOrderBy('c.firstName', 'ASC'); $order = true; }
        if (!$order) { $qb->addOrderBy('c.id', 'ASC'); }

        return $qb->getQuery()->getResult();
    }
}
