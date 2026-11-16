<?php

namespace App\EventSubscriber;

use App\Entity\AuditLog;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Records who changed what on accounts and organizations (roles, activation, identity data):
 * the administrative half of the audit trail. Secrets never reach the log.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class AdministrationAuditListener
{
    /** Fields that are secret or pure technical noise. */
    private const IGNORED = ['password', 'sessionVersion', 'lastLoginAt', 'updatedAt', 'createdAt', 'averageRating'];

    public function __construct(private readonly Security $security)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $meta = $em->getClassMetadata(AuditLog::class);
        $actor = $this->security->getUser()?->getUserIdentifier();

        $entries = [];
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof User && !$entity instanceof Organization) {
                continue;
            }
            $changes = [];
            foreach ($uow->getEntityChangeSet($entity) as $field => $change) {
                // Collection changes are reported as PersistentCollection, not as [old, new] pairs.
                if (\in_array($field, self::IGNORED, true) || !\is_array($change)) {
                    continue;
                }
                $changes[$field] = ['from' => self::scalar($change[0] ?? null), 'to' => self::scalar($change[1] ?? null)];
            }
            if ([] !== $changes) {
                $entries[] = new AuditLog($actor, self::type($entity).'.updated', self::type($entity), (int) $entity->getId(), ['changes' => $changes]);
            }
        }

        foreach ($entries as $entry) {
            $em->persist($entry);
            $uow->computeChangeSet($meta, $entry);
        }
    }

    private static function type(User|Organization $entity): string
    {
        return $entity instanceof User ? 'user' : 'organization';
    }

    private static function scalar(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Organization => $value->getName(),
            $value instanceof \DateTimeInterface => $value->format(\DATE_ATOM),
            \is_object($value) => null,
            default => $value,
        };
    }
}
