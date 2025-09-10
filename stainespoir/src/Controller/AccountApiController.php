<?php
namespace App\Controller;

use App\Entity\Child;
use App\Repository\AttendanceRepository;
use App\Repository\ChildRepository;
use Doctrine\DBAL\Types\Types;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PARENT')]
#[Route('/mon-compte/api', name: 'app_account_api_')]
final class AccountApiController extends AbstractController
{
    private function assertOwnedChild(int $id, ChildRepository $children): Child
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $kid = $children->find($id);
        if (!$kid || $kid->getParent() !== $user->getProfile()) {
            throw $this->createAccessDeniedException();
        }
        return $kid;
    }


    #[Route('/presences-annee', name: 'presences_year', methods: ['GET'])]
    public function presencesYear(
        Request $req,
        AttendanceRepository $repo,
        ChildRepository $children
    ): JsonResponse {
        $cid  = (int) $req->query->get('enfant');
        $year = (int) $req->query->get('annee') ?: $this->defaultSchoolStartYear();
        $kid  = $this->assertOwnedChild($cid, $children);

        $start = new \DateTimeImmutable(sprintf('%d-09-01 00:00:00', $year), new \DateTimeZone('Europe/Paris'));
        $end   = new \DateTimeImmutable(sprintf('%d-08-31 23:59:59', $year + 1), new \DateTimeZone('Europe/Paris'));

        $list = $repo->createQueryBuilder('a')
            ->andWhere('a.child = :cid')->setParameter('cid', $kid->getId())
            ->andWhere('a.date >= :from')->setParameter('from', $start, Types::DATETIME_IMMUTABLE)
            ->andWhere('a.date <= :to')->setParameter('to', $end, Types::DATETIME_IMMUTABLE)
            ->orderBy('a.date','ASC')
            ->getQuery()->getResult();

        return $this->json([
            'kid'   => $kid->getId(),
            'range' => ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')],
            'items' => array_map(static function($a){
                return [
                    'date'   => $a->getDate()->format('Y-m-d'),
                    'status' => $a->getStatus(), // present|absent|late|excused
                ];
            }, $list),
        ]);
    }

    private function defaultSchoolStartYear(\DateTimeImmutable $today = null): int
    {
        $today = $today ?? new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $y = (int)$today->format('Y');
        $m = (int)$today->format('n');
        return ($m >= 9) ? $y : ($y - 1);
    }
}
