<?php
namespace App\Repository;

use App\Entity\OutingRegistration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class OutingRegistrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, OutingRegistration::class); }

    /** sorties à venir pour l'enfant */
    public function upcomingForChild(int $childId): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.outing', 'o')->addSelect('o')
            ->andWhere('IDENTITY(r.child) = :cid')->setParameter('cid', $childId)
            ->andWhere('o.startsAt >= :now')->setParameter('now', new \DateTimeImmutable())
            ->orderBy('o.startsAt', 'ASC')
            ->getQuery()->getResult();
    }

    /** dernières sorties passées */
    public function recentPastForChild(int $childId, int $limit = 5): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.outing', 'o')->addSelect('o')
            ->andWhere('IDENTITY(r.child) = :cid')->setParameter('cid', $childId)
            ->andWhere('o.startsAt < :now')->setParameter('now', new \DateTimeImmutable())
            ->orderBy('o.startsAt', 'DESC')
            ->setMaxResults($limit)->getQuery()->getResult();
    }

    public function countSignedByOutingIds(array $outingIds): array
    {
        if (empty($outingIds)) {
            return [];
        }

        $raw = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.outing) AS oid, COUNT(r.id) AS cnt')
            ->andWhere('r.outing IN (:ids)')
            ->andWhere('r.signedAt IS NOT NULL')
            ->setParameter('ids', $outingIds)
            ->groupBy('r.outing')
            ->getQuery()
            ->getScalarResult();

        $map = [];
        foreach ($raw as $row) {
            $map[(int)$row['oid']] = (int)$row['cnt'];
        }
        return $map;
    }

}
