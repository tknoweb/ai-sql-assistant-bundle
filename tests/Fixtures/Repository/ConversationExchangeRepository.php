<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Tknoweb\AiSqlAssistantBundle\Contract\ConversationExchangeRepositoryInterface;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\ConversationExchange;

class ConversationExchangeRepository extends ServiceEntityRepository implements ConversationExchangeRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConversationExchange::class);
    }

    public function findBetween(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('exchange')
            ->andWhere('exchange.createdAt >= :from')
            ->andWhere('exchange.createdAt < :to')
            ->setParameter('from', \DateTimeImmutable::createFromInterface($from))
            // The last day of the period is included
            ->setParameter('to', \DateTimeImmutable::createFromInterface($to)->setTime(0, 0)->modify('+1 day'))
            ->orderBy('exchange.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
