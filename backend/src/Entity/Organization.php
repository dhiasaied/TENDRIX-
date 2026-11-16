<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\OrganizationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company on the platform: either a buyer (client issuing tenders) or a supplier (IT services firm / freelancer).
 */
#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity('name')]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(security: "is_granted('ROLE_ADMIN')", denormalizationContext: ['groups' => ['org:write', 'org:create']]),
        new Patch(
            security: "is_granted('ROLE_ADMIN') or (is_granted('ORG_MEMBER', object) and (is_granted('ROLE_BUYER') or is_granted('ROLE_SUPPLIER')))",
            // An administrator must not suspend the organization they belong to (self lock-out).
            securityPostDenormalize: "object.isActive() or not is_granted('ORG_MEMBER', object)",
            securityPostDenormalizeMessage: 'You cannot suspend your own organization.',
        ),
        // No DELETE: organizations own tenders, contracts and invoices that must be retained; suspend instead.
    ],
    normalizationContext: ['groups' => ['org:read', 'org:summary', 'timestamps']],
    denormalizationContext: ['groups' => ['org:write']],
    order: ['name' => 'ASC'],
    security: "is_granted('ROLE_USER')",
)]
#[ApiFilter(SearchFilter::class, properties: ['type' => 'exact', 'name' => 'partial', 'city' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'averageRating', 'createdAt'])]
class Organization
{
    use TimestampableTrait;

    public const TYPE_BUYER = 'buyer';
    public const TYPE_SUPPLIER = 'supplier';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['org:summary'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    #[Groups(['org:summary', 'org:write'])]
    private string $name = '';

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::TYPE_BUYER, self::TYPE_SUPPLIER])]
    #[Groups(['org:summary', 'org:create'])]
    private string $type = self::TYPE_BUYER;

    #[ORM\Column(length: 14, nullable: true)]
    #[Assert\Regex('/^\d{14}$/', message: 'SIRET must contain exactly 14 digits.')]
    #[Groups(['org:read', 'org:write'])]
    private ?string $siret = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    #[Groups(['org:read', 'org:write'])]
    private ?string $sector = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    #[Groups(['org:read', 'org:write', 'org:summary'])]
    private ?string $city = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(requireTld: true)]
    #[Groups(['org:read', 'org:write'])]
    private ?string $website = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 5000)]
    #[Groups(['org:read', 'org:write'])]
    private ?string $description = null;

    /** @var list<string> Supplier areas of expertise, used by the matching engine. */
    #[ORM\Column(type: 'json')]
    #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 60)])]
    #[Groups(['org:read', 'org:write'])]
    private array $skills = [];

    /** Denormalized average of supplier evaluations (1..5), refreshed on each new evaluation. */
    #[ORM\Column(nullable: true)]
    #[Groups(['org:read', 'org:summary'])]
    private ?float $averageRating = null;

    #[ORM\Column]
    #[Groups(['org:read', 'org:admin'])]
    private bool $active = true;

    /** @var Collection<int, User> */
    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'organization')]
    private Collection $members;

    public function __construct(string $name = '', string $type = self::TYPE_BUYER)
    {
        $this->name = $name;
        $this->type = $type;
        $this->members = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = trim($name); return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }
    public function isBuyer(): bool { return self::TYPE_BUYER === $this->type; }
    public function isSupplier(): bool { return self::TYPE_SUPPLIER === $this->type; }

    public function getSiret(): ?string { return $this->siret; }
    public function setSiret(?string $siret): static { $this->siret = $siret ? preg_replace('/\s+/', '', $siret) : null; return $this; }

    public function getSector(): ?string { return $this->sector; }
    public function setSector(?string $sector): static { $this->sector = $sector; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): static { $this->city = $city; return $this; }

    public function getWebsite(): ?string { return $this->website; }
    public function setWebsite(?string $website): static { $this->website = $website ?: null; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    /** @return list<string> */
    public function getSkills(): array { return $this->skills; }
    /** @param list<string> $skills */
    public function setSkills(array $skills): static { $this->skills = array_values(array_unique(array_filter(array_map('trim', $skills)))); return $this; }

    public function getAverageRating(): ?float { return $this->averageRating; }
    public function setAverageRating(?float $averageRating): static { $this->averageRating = null === $averageRating ? null : round($averageRating, 2); return $this; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }

    /** @return Collection<int, User> */
    public function getMembers(): Collection { return $this->members; }
}
