<?php

namespace App\Message;

/**
 * Domain event published after any workflow transition has been committed.
 */
final class WorkflowTransitioned
{
    public function __construct(
        public readonly string $workflow,
        public readonly int $subjectId,
        public readonly string $transition,
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $actorId,
        public readonly ?string $comment = null,
    ) {
    }
}
