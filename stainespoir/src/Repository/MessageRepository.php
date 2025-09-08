<?php
namespace App\Repository;

use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Message::class); }

    /** @return Message[] */
    public function findForChild(int $childId, int $limit = 50): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('IDENTITY(m.child) = :cid')->setParameter('cid', $childId)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)->getQuery()->getResult();
    }

    public function countUnreadForChild(int $childId): int
    {
        return (int)$this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('IDENTITY(m.child) = :cid')->setParameter('cid', $childId)
            ->andWhere('m.readAt IS NULL')
            ->getQuery()->getSingleScalarResult();
    }
}
