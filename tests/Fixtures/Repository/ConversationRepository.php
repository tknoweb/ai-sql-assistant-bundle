<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;
use Tknoweb\AiSqlAssistantBundle\Contract\ConversationRepositoryInterface;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\Conversation;

class ConversationRepository extends ServiceEntityRepository implements ConversationRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    public function findForOwner(UserInterface $owner): array
    {
        return $this->createQueryBuilder('conversation')
            ->andWhere('conversation.ownerIdentifier = :ownerIdentifier')
            ->andWhere('conversation.archivedAt IS NULL')
            ->setParameter('ownerIdentifier', $owner->getUserIdentifier())
            ->orderBy('conversation.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
