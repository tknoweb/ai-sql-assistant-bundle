<?php

namespace Tknoweb\AiSqlAssistantBundle\Contract;

/**
 * Repository of the conversation exchange entity of the application.
 */
interface ConversationExchangeRepositoryInterface
{
    /**
     * Exchanges made from $from to $to included, those of archived conversations included, in their chronological order.
     */
    public function findBetween(\DateTimeInterface $from, \DateTimeInterface $to): array;
}
