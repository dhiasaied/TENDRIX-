<?php

namespace App\Service;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Collects domain events during a unit of work and publishes them on the message bus only once
 * the data is committed: consumers never see an event for a rolled-back change, and newly created
 * entities already have their identifier.
 *
 * Events become "committed" on Doctrine postFlush and are dispatched at the end of the HTTP
 * request, console command or handled message, never from inside flush() itself (re-entrant
 * flushes from synchronous handlers would corrupt the unit of work).
 */
#[AsDoctrineListener(event: Events::postFlush)]
final class DomainEventRecorder implements EventSubscriberInterface
{
    /** @var list<\Closure(): object> */
    private array $pending = [];

    /** @var list<\Closure(): object> */
    private array $committed = [];

    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['publish', -1024],
            ConsoleEvents::TERMINATE => 'publish',
            WorkerMessageHandledEvent::class => 'publish',
        ];
    }

    /**
     * @param \Closure(): object $messageFactory evaluated after flush, so it may read generated ids
     */
    public function record(\Closure $messageFactory): void
    {
        $this->pending[] = $messageFactory;
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        array_push($this->committed, ...$this->pending);
        $this->pending = [];
    }

    public function publish(): void
    {
        while ([] !== $this->committed) {
            $factory = array_shift($this->committed);
            $this->bus->dispatch($factory());
        }
        // Anything still pending belongs to a unit of work that was never flushed.
        $this->pending = [];
    }
}
