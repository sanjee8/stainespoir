<?php
namespace App\Controller\Admin;

use App\Controller\Admin\OutingRegistrationCrudController;
use App\Entity\Child;
use App\Entity\Outing;
use App\Service\OutingInvitationManager;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin', name: 'admin_')]
final class OutingInviteController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AdminUrlGenerator $adminUrl,
        private OutingInvitationManager $inviter
    ) {}

    /**
     * Page d’invitation (GET) + envoi (POST)
     */
    #[Route('/sorties/{id}/inviter', name: 'outing_invite', methods: ['GET','POST'])]
    public function invite(Request $req, int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        /** @var Outing|null $outing */
        $outing = $this->em->getRepository(Outing::class)->find($id);
        if (!$outing) { throw $this->createNotFoundException('Sortie introuvable'); }

        // URL vers la Vue par sortie (inscriptions)
        $groupedUrl = $this->adminUrl
            ->setController(OutingRegistrationCrudController::class)
            ->setAction('groupedView')
            ->set('outing', $outing->getId())
            ->set('expand', $outing->getId())
            ->generateUrl();

        if ($req->isMethod('GET')) {
            // Liste fixe des niveaux (ordre souhaité) + compte par niveau
            $levels = ['CP','CE1','CE2','CM1','CM2','6e','5e','4e','3e','2nde','1ère','Terminale'];

            $counts = $this->em->createQuery(
                'SELECT c.level AS level, COUNT(c.id) AS n
                 FROM App\Entity\Child c
                 GROUP BY c.level'
            )->getResult();

            $by = [];
            foreach ($counts as $r) {
                $key = $r['level'] ?? '';
                $by[$key] = (int)$r['n'];
            }

            $levelsView = array_map(
                fn(string $lv) => ['level'=>$lv, 'n'=>$by[$lv] ?? 0],
                $levels
            );

            return $this->render('admin/outing_invite/index.html.twig', [
                'outing'     => $outing,
                'levels'     => $levelsView,
                'groupedUrl' => $groupedUrl,
            ]);
        }

        // POST : exécuter invitations
        if (!$this->isCsrfTokenValid('invite_outing_'.$id, (string)$req->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $levels       = array_values((array)$req->request->all('levels')); // multi
        $childIds     = array_map('intval', (array)$req->request->all('child_ids')); // depuis le picker
        $onlyEligible = (bool)$req->request->get('only_eligible', true);
        $sendMessage  = (bool)$req->request->get('send_message', false);
        $messageTpl   = (string)$req->request->get('message_tpl', '');

        $res = $this->inviter->invite(
            $outing,
            $levels,
            $childIds,
            $onlyEligible,
            $sendMessage,
            $messageTpl ?: null
        );

        $this->addFlash('success', sprintf(
            'Invitations envoyées — cibles:%d, créées:%d, déjà inscrits:%d, messages:%d',
            $res['targets'], $res['created'], $res['skipped'], $res['messages']
        ));

        return $this->redirect($groupedUrl);
    }

    /**
     * Relance des non-répondants (status=invited)
     */
    #[Route('/sorties/{id}/relancer', name: 'outing_remind', methods: ['POST'])]
    public function remind(Request $req, int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        /** @var Outing|null $outing */
        $outing = $this->em->getRepository(Outing::class)->find($id);
        if (!$outing) { throw $this->createNotFoundException('Sortie introuvable'); }

        if (!$this->isCsrfTokenValid('remind_outing_'.$id, (string)$req->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $sendMessage = (bool)$req->request->get('send_message', true);
        $messageTpl  = (string)$req->request->get('message_tpl', '');

        $res = $this->inviter->remindInvited($outing, $sendMessage, $messageTpl ?: null);

        $this->addFlash('success', sprintf(
            'Relance envoyée — non-répondants:%d, messages:%d',
            $res['invited'], $res['messages']
        ));

        $groupedUrl = $this->adminUrl
            ->setController(OutingRegistrationCrudController::class)
            ->setAction('groupedView')
            ->set('outing', $outing->getId())
            ->set('expand', $outing->getId())
            ->generateUrl();

        return $this->redirect($groupedUrl);
    }

    /**
     * Recherche AJAX d’enfants par nom/prénom (max 20)
     */
    #[Route('/children/search', name: 'children_search', methods: ['GET'])]
    public function searchChildren(Request $req): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $q = trim((string)$req->query->get('q', ''));
        if ($q === '' || mb_strlen($q) < 2) {
            return new JsonResponse(['items' => []]);
        }

        $rows = $this->em->createQueryBuilder()
            ->select('c.id, c.firstName, c.lastName, c.level')
            ->from(Child::class, 'c')
            ->andWhere('LOWER(c.firstName) LIKE :q OR LOWER(c.lastName) LIKE :q')
            ->setParameter('q', '%'.mb_strtolower($q).'%')
            ->orderBy('c.lastName', 'ASC')
            ->addOrderBy('c.firstName', 'ASC')
            ->setMaxResults(20)
            ->getQuery()->getArrayResult();

        return new JsonResponse(['items' => $rows]);
    }
}
