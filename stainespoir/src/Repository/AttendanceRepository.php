<?php
namespace App\Repository;

use App\Entity\Attendance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\DBAL\Types\Types;

final class AttendanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Attendance::class);
    }

    /** @return Attendance[] */
    public function findForChild(int $childId, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('IDENTITY(a.child) = :cid')
            ->setParameter('cid', $childId)
            ->orderBy('a.date', 'DESC');

        if ($from) {
            $qb->andWhere('a.date >= :f')
                ->setParameter('f', $from, Types::DATE_IMMUTABLE);
        }
        if ($to) {
            $qb->andWhere('a.date <= :t')
                ->setParameter('t', $to, Types::DATE_IMMUTABLE);
        }

        return $qb->getQuery()->getResult();
    }

    /** Taux de présence sur une période (en %) */
    public function presenceRate(int $childId, \DateTimeInterface $from, \DateTimeInterface $to): float
    {
        $row = $this->createQueryBuilder('a')
            ->select('COUNT(a.id) AS total,
                      SUM(CASE WHEN a.status = :p THEN 1 ELSE 0 END) AS presents')
            ->andWhere('IDENTITY(a.child) = :cid')
            ->andWhere('a.date BETWEEN :f AND :t')
            ->setParameter('cid', $childId)
            ->setParameter('p', 'present')
            ->setParameter('f', $from, Types::DATE_IMMUTABLE)
            ->setParameter('t', $to, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getSingleResult();

        $total   = (int)($row['total'] ?? 0);
        $present = (int)($row['presents'] ?? 0);

        return $total > 0 ? round($present * 100.0 / $total, 1) : 0.0;
    }
}
