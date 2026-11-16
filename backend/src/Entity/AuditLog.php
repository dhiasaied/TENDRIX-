<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Immutable trail of business-significant actions (ISO 27001 traceability requirement).
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Index(columns: ['entity_type', 'entity_id'])]
#[ApiResource(
    operations: [new GetCollection()],
    normalizationContext: ['groups' => ['audit:read']],
    order: ['createdAt' => 'DESC'],
    paginationItemsPerPage: 50,
    security: "is_granted('ROLE_ADMIN')",
)]
#[ApiFilter(SearchFilter::class, properties: ['entityType' => 'exact', 'entityId' => 'exact', 'actorEmail' => 'partial', 'action' => 'partial'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['audit:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['audit:read'])]
    private ?string $actorEmail;

    #[ORM\Column(length: 80)]
    #[Groups(['audit:read'])]
    private string $action;

    #[ORM\Column(length: 60)]
    #[Groups(['audit:read'])]
    private string $entityType;

    #[ORM\Column]
    #[Groups(['audit:read'])]
    private int $entityId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    #[Groups(['audit:read'])]
    private array $payload;

    #[ORM\Column]
    #[Groups(['audit:read'])]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $payload */
    public function __construct(?string $actorEmail, string $action, string $entityType, int $entityId, array $payload = [])
    {
        $this->actorEmail = $actorEmail;
        $this->action = $action;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getActorEmail(): ?string { return $this->actorEmail; }
    public function getAction(): string { return $this->action; }
    public function getEntityType(): string { return $this->entityType; }
    public function getEntityId(): int { return $this->entityId; }
    /** @return array<string, mixed> */
    public function getPayload(): array { return $this->payload; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
