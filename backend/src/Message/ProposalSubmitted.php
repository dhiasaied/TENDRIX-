<?php

namespace App\Message;

/**
 * Published when a proposal is created or edited; triggers AI scoring and buyer notification.
 */
final class ProposalSubmitted
{
    public function __construct(
        public readonly int $proposalId,
        public readonly bool $isUpdate = false,
    ) {
    }
}
