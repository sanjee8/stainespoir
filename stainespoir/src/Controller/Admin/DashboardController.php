<?php
namespace App\Controller\Admin;

use App\Entity\Insurance;
use App\Entity\Outing;
use App\Entity\OutingRegistration;
use App\Entity\User;
use App\Entity\Child;
use App\Entity\Attendance;
use App\Entity\Message;
use App\Enum\InsuranceStatus; // <-- AJOUT
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        // Derniers fils où le DERNIER message par enfant vient d'un parent
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

        // Construit l'URL /admin/parent-validated/{userId} pour chaque enfant
        $parentUrls = [];
        foreach ($unrepliedMessages as $m) {
            /** @var Message $m */
            $child = $m->getChild();
            $pid = null;

            // Cas 1 : Child->getParent() renvoie un Profile -> getUser()->getId()
            if ($child && method_exists($child, 'getParent')) {
                $profile = $child->getParent();
                if ($profile && method_exists($profile, 'getUser')) {
                    $user = $profile->getUser();
                    if ($user instanceof User) {
                        $pid = $user->getId();
                    }
                }
            }
            // Cas 2 (fallback) : lien direct Child->getUser()
            if (!$pid && $child && method_exists($child, 'getUser')) {
                $u = $child->getUser();
                if ($u instanceof User) {
                    $pid = $u->getId();
                }
            }

            if ($pid) {
                $parentUrls[(int)$child->getId()] = '/admin/parent-validated/'.$pid;
            }
        }

        return $this->render('admin/dashboard.html.twig', [
            'unrepliedMessages' => $unrepliedMessages,
            'parentUrls'        => $parentUrls,
        ]);
    }

    /**
     * Page d'émargement (Présences)
     */
    #[Route('/admin/presences', name: 'admin_presences', methods: ['GET','POST'])]
    public function presences(Request $request): Response
    {
        [$dayStart, $dayEnd, $date] = $this->resolveDayBounds($request->get('date'));
        $storeAt = $date->setTime(12, 0, 0);

        $children = $this->getValidatedChildren();

        $attRows = $this->em->createQuery(
            'SELECT a, c FROM App\Entity\Attendance a
             JOIN a.child c
             WHERE a.date >= :d1 AND a.date < :d2'
        )->setParameter('d1', $dayStart)->setParameter('d2', $dayEnd)->getResult();

        $attMap = [];
        foreach ($attRows as $a) {
            /** @var Attendance $a */
            $attMap[(int)$a->getChild()->getId()] = $a;
        }
        $existingCount = count($attMap);

        if ($request->isMethod('POST') && $request->request->get('do') === 'save') {
            if (!$this->isCsrfTokenValid('presence_save', (string)$request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $posted = (array)$request->request->all('st');
            $created=0; $updated=0; $deleted=0;

            foreach ($children as $child) {
                /** @var Child $child */
                $cid = (int)$child->getId();
                $val = $posted[$cid] ?? 'unset';
                $val = in_array($val, ['present','absent','unset'], true) ? $val : 'unset';

                $existing = $attMap[$cid] ?? null;

                if ($val === 'unset') {
                    if ($existing) { $this->em->remove($existing); $deleted++; }
                    continue;
                }

                if ($existing) {
                    $existing->setDate($storeAt)->setStatus($val);
                    $updated++;
                } else {
                    $a = (new Attendance())->setChild($child)->setDate($storeAt)->setStatus($val);
                    $this->em->persist($a);
                    $created++;
                }
            }

            $this->em->flush();
            $this->addFlash('success', sprintf('Présences enregistrées — +%d, ✎%d, −%d', $created, $updated, $deleted));

            return $this->redirectToRoute('admin_presences', ['date' => $date->format('Y-m-d')]);
        }

        return $this->render('admin/presences/index.html.twig', [
            'date'          => $date,
            'dayStart'      => $dayStart,
            'children'      => $children,
            'attMap'        => $attMap,
            'existingCount' => $existingCount,
            'token_id'      => 'presence_save',
        ]);
    }

    private function resolveDayBounds(?string $input): array
    {
        $tz = new \DateTimeZone('Europe/Paris');
        if ($input && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $input, $tz) ?: new \DateTimeImmutable('now', $tz);
            $start = $start->setTime(0,0,0);
        } else {
            $start = (new \DateTimeImmutable('now', $tz))->setTime(0,0,0);
        }
        $end = $start->modify('+1 day');
        return [$start, $end, $start];
    }

    private function getValidatedChildren(): array
    {
        $em = $this->em;
        $cmChild = $em->getClassMetadata(Child::class);
        $qb = $em->createQueryBuilder()->select('c')->from(Child::class, 'c');

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
            $qb->join('c.'.$childToUserField, 'u')->andWhere('u.isApproved = :ok');
        } elseif ($childToProfileField && $profileClass) {
            $qb->join('c.'.$childToProfileField, 'p');
            $cmProfile = $em->getClassMetadata($profileClass);
            $profileToUserField = null;
            foreach (($cmProfile->associationMappings ?? $cmProfile->getAssociationMappings()) as $pf => $map) {
                $target = $map['targetEntity'] ?? null;
                if ($target === User::class) { $profileToUserField = $pf; break; }
            }
            if ($profileToUserField) {
                $qb->join('p.'.$profileToUserField, 'u')->andWhere('u.isApproved = :ok');
            }
        }
        if (strpos((string)$qb->getDQL(), ':ok') !== false) {
            $qb->setParameter('ok', true);
        }

        if ($cmChild->hasField('isApproved')) {
            $qb->andWhere('c.isApproved = true OR c.isApproved IS NULL');
        }

        if ($cmChild->hasField('lastName'))  { $qb->addOrderBy('c.lastName','ASC'); }
        if ($cmChild->hasField('firstName')) { $qb->addOrderBy('c.firstName','ASC'); }
        if (!$cmChild->hasField('lastName') && !$cmChild->hasField('firstName')) {
            $qb->addOrderBy('c.id','ASC');
        }

        return $qb->getQuery()->getResult();
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Stains Espoir — Admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToRoute('Accueil', 'fa fa-home', 'admin');

        yield MenuItem::section('Sorties');
        yield MenuItem::linkToCrud('Sorties', 'fa fa-route', Outing::class)
            ->setController(OutingCrudController::class);

        yield MenuItem::linkToCrud('Inscriptions', 'fa fa-ticket', OutingRegistration::class)
            ->setController(OutingRegistrationCrudController::class);

        yield MenuItem::section('Présences');
        yield MenuItem::linkToRoute('Fiche du jour', 'fa fa-calendar-check', 'admin_presences');

        yield MenuItem::section('Parents');

        $pendingCount = (int) $this->em->createQuery(
            'SELECT COUNT(u.id) FROM App\Entity\User u WHERE u.isApproved = :approved'
        )->setParameter('approved', false)->getSingleScalarResult();

        $pendingItem = MenuItem::linkToCrud('Parents en attente', 'fa fa-user-clock', User::class)
            ->setController(ParentPendingCrudController::class);

        if ($pendingCount > 0) {
            $pendingItem = $pendingItem->setBadge((string) $pendingCount, 'danger');
        }
        yield $pendingItem;

        yield MenuItem::linkToCrud('Parents validés', 'fa fa-user-check', User::class)
            ->setController(ParentValidatedCrudController::class);

        yield MenuItem::section('Enfants');
        yield MenuItem::linkToCrud('Tous les enfants', 'fa fa-child', Child::class)
            ->setController(ChildCrudController::class);

        // === Assurances avec badge "Pending" ===
        // Si votre champ status est un enum Doctrine (recommandé) :
        $pendingIns = (int) $this->em->createQuery(
            'SELECT COUNT(i.id) FROM App\Entity\Insurance i WHERE i.status = :st'
        )
            ->setParameter('st', InsuranceStatus::PENDING) // <-- si status est un ENUM Doctrine
            // ->setParameter('st', 'PENDING')             // <-- décommentez ceci et commentez la ligne du dessus si c'est un VARCHAR
            ->getSingleScalarResult();

        $insItem = MenuItem::linkToCrud('Assurances', 'fa-solid fa-file-shield', Insurance::class);
        if ($pendingIns > 0) {
            $insItem = $insItem->setBadge((string) $pendingIns, 'danger');
        } else {
            $insItem = $insItem->setBadge('0', 'secondary');
        }
        yield $insItem;
    }
}
