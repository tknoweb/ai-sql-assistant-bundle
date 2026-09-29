<?php

namespace Tknoweb\AiSqlAssistantBundle\Contract;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Repository of the conversation entity of the application.
 */
interface ConversationRepositoryInterface
{
    /**
     * Conversations of $owner that are not archived, the most recently continued first.
     */
    public function findForOwner(UserInterface $owner): array;
}
