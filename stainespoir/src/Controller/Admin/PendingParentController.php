<?php
namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\Child;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[Route('/admin/pending-parents', name: 'admin_pending_')]
class PendingParentController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $parents = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->andWhere('u.isApproved = :p')->setParameter('p', false)
            ->orderBy('u.id', 'DESC')
            ->getQuery()->getResult();

        return $this->render('admin/pending/index.html.twig', [
            'parents' => $parents,
        ]);
    }

    #[Route('/{id}', name: 'review', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function review(User $user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if ($user->isApproved()) {
            $this->addFlash('info', 'Ce parent a déjà été validé.');
            return $this->redirectToRoute('admin_pending_list');
        }

        $children = $this->em->getRepository(Child::class)->createQueryBuilder('c')
            ->andWhere('c.parent = :p')->setParameter('p', $user)
            ->orderBy('c.lastName', 'ASC')->addOrderBy('c.firstName', 'ASC')
            ->getQuery()->getResult();

        return $this->render('admin/pending/review.html.twig', [
            'parent'   => $user,
            'children' => $children,
            'token_id' => 'pending_decision_'.$user->getId(),
        ]);
    }

    #[Route('/{id}/decision', name: 'decision', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function decision(User $user, Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('pending_decision_'.$user->getId(), (string)$req->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $decision = (string)$req->request->get('decision', '');
        $approvedChildIds = array_map('intval', (array)$req->request->get('approved_children', []));

        // tous les enfants du parent
        $children = $this->em->getRepository(Child::class)->createQueryBuilder('c')
            ->andWhere('c.parent = :p')->setParameter('p', $user)
            ->getQuery()->getResult();

        if ($decision === 'reject') {
            // Refuser : supprimer parent + tous les enfants
            foreach ($children as $child) { $this->em->remove($child); }
            $this->em->remove($user);

            // ⚠️ Si d’autres entités dépendent de Child/User, prévois cascade remove/orphanRemoval ou supprime-les ici.

            $this->em->flush();
            $this->addFlash('success', 'Parent et enfants supprimés (inscription refusée).');
            return $this->redirectToRoute('admin_pending_list');
        }

        if ($decision === 'approve') {
            // Valider : parent validé + ne garder QUE les enfants cochés
            $user->setIsApproved(true);
            if (!$user->getApprovedAt()) { $user->setApprovedAt(new \DateTimeImmutable()); }

            foreach ($children as $child) {
                if (in_array((int)$child->getId(), $approvedChildIds, true)) {
                    $child->setIsApproved(true);
                } else {
                    $this->em->remove($child); // enfants non cochés supprimés
                }
            }

            $this->em->flush();
            $this->addFlash('success', 'Parent validé. Enfants non cochés supprimés, cochés validés.');
            return $this->redirectToRoute('admin_pending_list');
        }

        $this->addFlash('warning', 'Aucune action effectuée.');
        return $this->redirectToRoute('admin_pending_review', ['id' => $user->getId()]);
    }
}
