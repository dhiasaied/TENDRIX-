<?php

namespace App\MessageHandler;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Message\WorkflowTransitioned;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AuditWorkflowTransition
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(WorkflowTransitioned $event): void
    {
        $actor = null !== $event->actorId ? $this->em->find(User::class, $event->actorId) : null;

        $this->em->persist(new AuditLog(
            $actor?->getEmail(),
            $event->workflow.'.'.$event->transition,
            $event->workflow,
            $event->subjectId,
            array_filter(['from' => $event->from, 'to' => $event->to, 'comment' => $event->comment], static fn ($v) => null !== $v && '' !== $v),
        ));
        $this->em->flush();
    }
}
