<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * In-app notification, produced asynchronously by domain event handlers.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Index(columns: ['recipient_id', 'read_at'])]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(security: 'object.getRecipient() == user'),
        new Patch(security: 'object.getRecipient() == user'),
    ],
    normalizationContext: ['groups' => ['notification:read']],
    denormalizationContext: ['groups' => ['notification:write']],
    order: ['createdAt' => 'DESC'],
    paginationItemsPerPage: 20,
    security: "is_granted('ROLE_USER')",
)]
#[ApiFilter(ExistsFilter::class, properties: ['readAt'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['notification:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\Column(length: 60)]
    #[Groups(['notification:read'])]
    private string $type;

    #[ORM\Column(length: 200)]
    #[Groups(['notification:read'])]
    private string $title;

    #[ORM\Column(type: 'text')]
    #[Groups(['notification:read'])]
    private string $message;

    /** Front-end route the notification points to, e.g. /tenders/12. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['notification:read'])]
    private ?string $link;

    #[ORM\Column(nullable: true)]
    #[Groups(['notification:read'])]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column]
    #[Groups(['notification:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $recipient, string $type, string $title, string $message, ?string $link = null)
    {
        $this->recipient = $recipient;
        $this->type = $type;
        $this->title = $title;
        $this->message = $message;
        $this->link = $link;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getRecipient(): User { return $this->recipient; }
    public function getType(): string { return $this->type; }
    public function getTitle(): string { return $this->title; }
    public function getMessage(): string { return $this->message; }
    public function getLink(): ?string { return $this->link; }
    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    #[Groups(['notification:read'])]
    public function isRead(): bool { return null !== $this->readAt; }

    #[Groups(['notification:write'])]
    public function setRead(bool $read): static
    {
        $this->readAt = $read ? ($this->readAt ?? new \DateTimeImmutable()) : null;

        return $this;
    }
}
