<?php
namespace App\Repository;

use App\Entity\Outing;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class OutingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Outing::class); }

    /** Retourne les N prochaines sorties (à partir de maintenant, tri asc.) */
    public function findUpcoming(int $limit = 3): array
    {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.startsAt >= :now')->setParameter('now', new \DateTimeImmutable('now'))
            ->orderBy('o.startsAt', 'ASC')
            ->setMaxResults($limit);

        // (Optionnel) si tu as un champ "published" ou "isPublic"
        // $qb->andWhere('o.published = :pub')->setParameter('pub', true);

        return $qb->getQuery()->getResult();
    }

}
