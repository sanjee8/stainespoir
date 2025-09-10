<?php
namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\Child;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractDashboardController
{
    public function __construct(private EntityManagerInterface $em) {}
    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
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
