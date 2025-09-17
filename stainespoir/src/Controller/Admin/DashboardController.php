<?php
namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\Child;
use App\Entity\Attendance;
use App\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use DateTimeImmutable;
use DateTimeZone;

class DashboardController extends AbstractDashboardController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        // Derniers messages "non répondus" :
        // Pour chaque enfant, on prend le DERNIER message ; s’il vient d’un parent, on le considère à répondre.
        // (Ainsi, si le staff a répondu après, cet enfant ne sortira pas.)
        $unrepliedMessages = $this->em->createQuery(
            'SELECT m, c
             FROM App\Entity\Message m
             JOIN m.child c
             WHERE m.createdAt = (
               SELECT MAX(m2.createdAt) FROM App\Entity\Message m2 WHERE m2.child = c
             )
             AND m.sender = :parent
             ORDER BY m.createdAt DESC'
        )
            ->setParameter('parent', 'parent')
            ->setMaxResults(15)
            ->getResult();

        return $this->render('admin/dashboard.html.twig', [
            'unrepliedMessages' => $unrepliedMessages,
        ]);
    }

    /**
     * Page d'émargement (Présences)
     */
    #[Route('/admin/presences', name: 'admin_presences', methods: ['GET','POST'])]
    public function presences(Request $request): Response
    {
        // 1) Bornes locales du jour sélectionné (00:00 → 00:00+1)
        [$dayStart, $dayEnd, $date] = $this->resolveDayBounds($request->get('date'));

        // 2) Pour éviter le -1 jour en DB (UTC), on STOCKE à 12:00 local
        $storeAt = $date->setTime(12, 0, 0); // Europe/Paris (grâce à resolveDayBounds)

        // 3) Enfants validés
        $children = $this->getValidatedChildren();

        // 4) Présences existantes pour ce jour (map childId => Attendance)
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
            $attMap[(int)$a->getChild()->getId()] = $a;
        }
        $existingCount = count($attMap);

        // 5) POST => enregistrer
        if ($request->isMethod('POST') && $request->request->get('do') === 'save') {
            // CSRF id FIXE (et non dépendant de la date)
            if (!$this->isCsrfTokenValid('presence_save', (string) $request->request->get('_token'))) {
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
                    // On force aussi la date à 12:00 pour homogénéiser ce qui existe déjà
                    if (method_exists($existing, 'setDate'))   { $existing->setDate($storeAt); }
                    if (method_exists($existing, 'setStatus')) { $existing->setStatus($val); }
                    $updated++;
                } else {
                    $a = new Attendance();
                    if (method_exists($a, 'setChild'))  { $a->setChild($child); }
                    if (method_exists($a, 'setDate'))   { $a->setDate($storeAt); }  // 12:00 local
                    if (method_exists($a, 'setStatus')) { $a->setStatus($val); }
                    $this->em->persist($a);
                    $created++;
                }
            }

            $this->em->flush();
            $this->addFlash('success', sprintf('Présences enregistrées — +%d, ✎%d, −%d', $created, $updated, $deleted));

            // PRG
            return $this->redirectToRoute('admin_presences', ['date' => $date->format('Y-m-d')]);
        }

        // 6) Affichage
        return $this->render('admin/presences/index.html.twig', [
            'date'          => $date,
            'dayStart'      => $dayStart,
            'children'      => $children,
            'attMap'        => $attMap,
            'existingCount' => $existingCount,
            // Si ton Twig utilise encore {{ csrf_token(token_id) }}, on fournit l'id fixe :
            'token_id'      => 'presence_save',
        ]);
    }

    private function resolveDayBounds(?string $input): array
    {
        $tz = new \DateTimeZone('Europe/Paris');

        if ($input && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $input, $tz);
            if (!$start) {
                $now = new \DateTimeImmutable('now', $tz);
                $start = $now->setTime(0, 0, 0);
            }
        } else {
            $now = new \DateTimeImmutable('now', $tz);
            $start = $now->setTime(0, 0, 0);
        }

        $end = $start->modify('+1 day');
        return [$start, $end, $start];
    }

    /**
     * Enfants liés à un parent validé, mapping robuste (Child->User ou Child->Profile->User)
     * + filtre Child.isApproved si dispo
     */
    private function getValidatedChildren(): array
    {
        $em = $this->em;
        $cmChild = $em->getClassMetadata(Child::class);

        $qb = $em->createQueryBuilder()->select('c')->from(Child::class, 'c');

        // Trouver un lien vers User
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
            $cmProfile = $this->em->getClassMetadata($profileClass);
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

        if ($cmChild->hasField('isApproved')) {
            $qb->andWhere('c.isApproved = true OR c.isApproved IS NULL');
        }

        $order = false;
        if ($cmChild->hasField('lastName'))  { $qb->addOrderBy('c.lastName', 'ASC');  $order = true; }
        if ($cmChild->hasField('firstName')) { $qb->addOrderBy('c.firstName', 'ASC'); $order = true; }
        if (!$order) { $qb->addOrderBy('c.id', 'ASC'); }

        return $qb->getQuery()->getResult();
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Stains Espoir — Admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToRoute('Accueil', 'fa fa-home', 'admin');

        yield MenuItem::section('Présences');
        yield MenuItem::linkToRoute('Fiche du jour', 'fa fa-calendar-check', 'admin_presences');

        yield MenuItem::section('Parents');

        // 🔴 Compteur des parents en attente
        $pendingCount = (int) $this->em->createQuery(
            'SELECT COUNT(u.id) FROM App\Entity\User u WHERE u.isApproved = :approved'
        )->setParameter('approved', false)->getSingleScalarResult();

        // Item "Parents en attente" + badge rouge si > 0
        $pendingItem = MenuItem::linkToCrud('Parents en attente', 'fa fa-user-clock', User::class)
            ->setController(ParentPendingCrudController::class);

        if ($pendingCount > 0) {
            $pendingItem = $pendingItem->setBadge((string) $pendingCount, 'danger'); // <- badge rouge
        }
        yield $pendingItem;

        // Liste des parents validés (sans badge)
        yield MenuItem::linkToCrud('Parents validés', 'fa fa-user-check', User::class)
            ->setController(ParentValidatedCrudController::class);

        yield MenuItem::section('Enfants');
        yield MenuItem::linkToCrud('Tous les enfants', 'fa fa-child', Child::class)
            ->setController(ChildCrudController::class);
    }
}
