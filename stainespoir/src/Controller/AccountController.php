<?php
namespace App\Controller;

use App\Repository\ChildRepository;
use App\Repository\AttendanceRepository;
use App\Repository\MessageRepository;
use App\Repository\OutingRegistrationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController extends AbstractController
{
    #[Route('/mon-compte/{tab}', name: 'app_account', defaults: ['tab' => 'vue'], requirements: ['tab' => 'vue|presences|messages|sorties'])]
    public function index(
        Request $request,
        ChildRepository $children,
        AttendanceRepository $attendances,
        MessageRepository $messages,
        OutingRegistrationRepository $outRegs,
        string $tab
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_PARENT');
        $user = $this->getUser();
        $profile = $user?->getProfile();

        // 1) Liste des enfants du parent
        $kids = $children->findBy(['parent' => $profile]);
        if (count($kids) === 0) {
            return $this->render('account/empty.html.twig'); // parent sans enfant lié
        }

        // 2) Enfant courant (via ?enfant=ID, sinon 1er)
        $currentId = (int) $request->query->get('enfant', $kids[0]->getId());
        $current = null;
        foreach ($kids as $k) { if ($k->getId() === $currentId) { $current = $k; break; } }
        if (!$current) { // sécurité : empêche d'utiliser un ID d'un autre parent
            $current = $kids[0];
            $currentId = $current->getId();
        }

        // 3) Données par onglet
        $today = new \DateTimeImmutable('today');
        $from30 = $today->sub(new \DateInterval('P30D'));

        $data = [
            'presenceRate30' => $attendances->presenceRate($currentId, $from30, $today),
            'unreadCount'    => $messages->countUnreadForChild($currentId),
            'upcomingOutings'=> $outRegs->upcomingForChild($currentId),
            'recentOutings'  => $outRegs->recentPastForChild($currentId, 3),
        ];

        if ($tab === 'presences') {
            $data['presences'] = $attendances->findForChild($currentId);
        } elseif ($tab === 'messages') {
            $data['messages'] = $messages->findForChild($currentId, 100);
        } elseif ($tab === 'sorties') {
            $data['registrations'] = $outRegs->upcomingForChild($currentId);
            $data['pastRegs'] = $outRegs->recentPastForChild($currentId, 20);
        }

        return $this->render('account/index.html.twig', [
            'tab' => $tab,
            'kids' => $kids,
            'current' => $current,
            'data' => $data,
        ]);
    }

    // Marquer un message comme lu (AJAX)
    #[Route('/mon-compte/messages/{id}/lu', name: 'app_account_message_read', methods: ['POST'])]
    public function markMessageRead(int $id, MessageRepository $repo, ChildRepository $children): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PARENT');
        $msg = $repo->find($id);
        if (!$msg) return $this->json(['ok'=>false], 404);

        // sécurité : vérifier que le message appartient à un enfant du parent courant
        $kid = $msg->getChild();
        if ($kid->getParent() !== $this->getUser()?->getProfile()) return $this->json(['ok'=>false], 403);

        if ($msg->getReadAt() === null) {
            $msg->markRead();
            $this->getDoctrine()->getManager()->flush();
        }
        return $this->json(['ok'=>true]);
    }
}
