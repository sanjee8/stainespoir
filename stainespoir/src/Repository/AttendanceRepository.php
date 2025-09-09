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

    /**
     * @return Attendance[]
     */
    public function findForChild(int $childId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.child = :cid')->setParameter('cid', $childId)
            ->andWhere('a.date >= :from')->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->andWhere('a.date <= :to')->setParameter('to', $to, Types::DATETIME_IMMUTABLE)
            ->orderBy('a.date','ASC')
            ->getQuery()->getResult();
    }

    /** Pourcentage de présence sur la période */
    public function presenceRate(int $childId, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $total = (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.child = :cid')->setParameter('cid', $childId)
            ->andWhere('a.date >= :from')->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->andWhere('a.date <= :to')->setParameter('to', $to, Types::DATETIME_IMMUTABLE)
            ->andWhere('a.status IN (:st)')->setParameter('st', ['present','absent','late','excused'])
            ->getQuery()->getSingleScalarResult();

        if ($total === 0) return 0.0;

        $present = (int) $this->createQueryBuilder('a2')
            ->select('COUNT(a2.id)')
            ->andWhere('a2.child = :cid')->setParameter('cid', $childId)
            ->andWhere('a2.date >= :from')->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->andWhere('a2.date <= :to')->setParameter('to', $to, Types::DATETIME_IMMUTABLE)
            ->andWhere('a2.status = :p')->setParameter('p', 'present')
            ->getQuery()->getSingleScalarResult();

        return ($present * 100.0) / $total;
    }

    // src/Repository/AttendanceRepository.php
    public function presentAbsentStats(int $childId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // Compte uniquement présent/absent (late/excused exclus du dénominateur)
        $qb = $this->createQueryBuilder('a')
            ->select(
                "SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS presentCount",
                "SUM(CASE WHEN a.status = 'absent'  THEN 1 ELSE 0 END) AS absentCount"
            )
            ->andWhere('a.child = :cid')->setParameter('cid', $childId)
            ->andWhere('a.date  >= :from')->setParameter('from', $from, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->andWhere('a.date  <= :to')->setParameter('to',   $to,   \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE);

        $row = $qb->getQuery()->getSingleResult(); // ['presentCount' => '4', 'absentCount' => '1'] (strings)
        $present = (int) ($row['presentCount'] ?? 0);
        $absent  = (int) ($row['absentCount']  ?? 0);

        return ['present' => $present, 'absent' => $absent, 'total' => $present + $absent];
    }

}
