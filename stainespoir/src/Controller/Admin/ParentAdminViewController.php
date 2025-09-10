<?php
namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\Child;
use App\Entity\Message;
use App\Entity\OutingRegistration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/parents', name: 'admin_parent_')]
class ParentAdminViewController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('/{id}/dossier', name: 'dossier', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function dossier(User $user, Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // Enfants rattachés au parent
        $children = $this->em->getRepository(Child::class)->createQueryBuilder('c')
            ->andWhere('c.parent = :p')->setParameter('p', $user)
            ->orderBy('c.lastName','ASC')->addOrderBy('c.firstName','ASC')
            ->getQuery()->getResult();

        // Sélection de l’enfant (optionnel)
        $selected = null;
        $selectedId = $req->query->getInt('child_id', 0);

        if ($selectedId > 0) {
            /** @var Child|null $tmp */
            $tmp = $this->em->getRepository(Child::class)->find($selectedId);
            // Sécurité : l’enfant doit appartenir à ce parent
            if ($tmp && $tmp->getParent()?->getId() === $user->getId()) {
                $selected = $tmp;
            }
        } elseif (!empty($children)) {
            // Par défaut, si aucun child_id fourni mais qu'il y a des enfants, on prend le premier
            $selected = $children[0];
        }

        // Données dépendantes de l’enfant sélectionné
        $messages   = [];
        $signedRegs = [];

        if ($selected) {
            // Derniers messages pour cet enfant (ordre chronologique)
            $messages = $this->em->getRepository(Message::class)->createQueryBuilder('m')
                ->andWhere('m.child = :c')->setParameter('c', $selected)
                ->orderBy('m.createdAt', 'ASC')
                ->setMaxResults(50)
                ->getQuery()->getResult();

            // Sorties signées pour cet enfant
            $signedRegs = $this->em->getRepository(OutingRegistration::class)->createQueryBuilder('r')
                ->leftJoin('r.outing', 'o')->addSelect('o')
                ->andWhere('r.child = :c')->setParameter('c', $selected)
                ->andWhere('r.signedAt IS NOT NULL')
                ->orderBy('o.startsAt', 'DESC')
                ->getQuery()->getResult();
        }

        return $this->render('admin/parents/dossier.html.twig', [
            'parent'     => $user,
            'children'   => $children,
            'selected'   => $selected,
            'messages'   => $messages,
            'signedRegs' => $signedRegs,
        ]);
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'])]
    public function approveParent(User $user, Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->assertCsrf('approve_parent_'.$user->getId(), $req->request->get('_token'));

        if (!$user->isApproved()) {
            $user->setIsApproved(true);
            if (!$user->getApprovedAt()) {
                $user->setApprovedAt(new \DateTimeImmutable());
            }
            $this->em->flush();
            $this->addFlash('success', 'Parent validé.');
        }
        return $this->redirectToRoute('admin_parent_dossier', ['id' => $user->getId()]);
    }

    #[Route('/child/{id}/approve', name: 'child_approve', methods: ['POST'])]
    public function approveChild(Child $child, Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->assertCsrf('approve_child_'.$child->getId(), $req->request->get('_token'));

        if (!$child->isApproved()) {
            $child->setIsApproved(true);
            $this->em->flush();
            $this->addFlash('success', 'Enfant validé.');
        }
        return $this->redirectToRoute('admin_parent_dossier', ['id' => $child->getParent()->getId()]);
    }

    private function assertCsrf(string $id, ?string $token): void
    {
        if (!$this->isCsrfTokenValid($id, (string)$token)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }
}
