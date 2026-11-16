<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Dto\TransitionInput;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\TenderRepository;
use App\State\TenderCreateProcessor;
use App\State\WorkflowTransitionProcessor;
use App\Workflow\WorkflowSubjectInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Call for tenders ("appel d'offres") for an intellectual service engagement.
 */
#[ORM\Entity(repositoryClass: TenderRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['status'])]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(
            security: "is_granted('ROLE_BUYER') or is_granted('ROLE_REQUESTER')",
            processor: TenderCreateProcessor::class,
        ),
        new Patch(security: "is_granted('TENDER_EDIT', object)"),
        new Delete(security: "is_granted('TENDER_EDIT', object) and object.getStatus() == 'draft'"),
        new Post(
            uriTemplate: '/tenders/{id}/transition',
            status: 200,
            input: TransitionInput::class,
            denormalizationContext: ['groups' => ['transition']],
            read: false,
            processor: WorkflowTransitionProcessor::class,
            openapi: new \ApiPlatform\OpenApi\Model\Operation(summary: 'Apply a workflow transition (submit, publish, reject, close, cancel).'),
            name: 'tender_transition',
        ),
    ],
    normalizationContext: ['groups' => ['tender:read', 'org:summary', 'user:summary', 'timestamps']],
    denormalizationContext: ['groups' => ['tender:write']],
    order: ['createdAt' => 'DESC'],
    security: "is_granted('ROLE_USER')",
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'title' => 'partial', 'reference' => 'exact', 'seniority' => 'exact', 'organization' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'deadline', 'budgetMax', 'title'])]
#[ApiFilter(DateFilter::class, properties: ['deadline', 'createdAt'])]
class Tender implements WorkflowSubjectInterface
{
    use TimestampableTrait;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_AWARDED = 'awarded';
    public const STATUS_CANCELLED = 'cancelled';

    public const SENIORITIES = ['junior', 'confirmed', 'senior', 'expert'];
    public const REMOTE_POLICIES = ['onsite', 'hybrid', 'remote'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['tender:read', 'tender:summary'])]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    #[Groups(['tender:read', 'tender:summary'])]
    private ?string $reference = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 5, max: 200)]
    #[Groups(['tender:read', 'tender:write', 'tender:summary'])]
    private string $title = '';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(min: 30, max: 20000)]
    #[Groups(['tender:read', 'tender:write'])]
    private string $description = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    #[Assert\Count(min: 1, max: 20)]
    #[Assert\All([new Assert\Type('string'), new Assert\NotBlank, new Assert\Length(max: 60)])]
    #[Groups(['tender:read', 'tender:write'])]
    private array $skills = [];

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::SENIORITIES)]
    #[Groups(['tender:read', 'tender:write'])]
    private string $seniority = 'confirmed';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Groups(['tender:read', 'tender:write'])]
    private string $location = 'Paris';

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::REMOTE_POLICIES)]
    #[Groups(['tender:read', 'tender:write'])]
    private string $remotePolicy = 'hybrid';

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['tender:read', 'tender:write'])]
    private ?\DateTimeImmutable $startDate = null;

    /** Estimated number of billable days. */
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 1000)]
    #[Groups(['tender:read', 'tender:write'])]
    private int $estimatedDays = 60;

    /** Daily rate range (TJM, EUR excl. VAT). */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 100, max: 5000)]
    #[Groups(['tender:read', 'tender:write'])]
    private ?int $budgetMin = null;

    #[ORM\Column]
    #[Assert\Range(min: 100, max: 5000)]
    #[Groups(['tender:read', 'tender:write'])]
    private int $budgetMax = 600;

    /** Fair daily rate suggested by the pricing agent at creation time (buyer-internal). */
    #[ORM\Column(nullable: true)]
    #[Groups(['tender:internal'])]
    private ?int $suggestedDailyRate = null;

    /** Deadline for suppliers to submit proposals. */
    #[ORM\Column]
    #[Assert\NotNull]
    #[Groups(['tender:read', 'tender:write'])]
    private ?\DateTimeImmutable $deadline = null;

    #[ORM\Column(length: 30)]
    #[Groups(['tender:read', 'tender:summary'])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['tender:read'])]
    private ?Organization $organization = null;

    /** Buyer staff identities are not disclosed to suppliers. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['tender:internal'])]
    private ?User $createdBy = null;

    #[ORM\ManyToOne]
    #[Groups(['tender:internal'])]
    private ?User $approvedBy = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['tender:read'])]
    private ?\DateTimeImmutable $publishedAt = null;

    /** Internal approval / cancellation notes. */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['tender:internal'])]
    private ?string $statusComment = null;

    /**
     * Restricted consultation: only these suppliers may see and answer. Empty = open to every supplier.
     *
     * @var Collection<int, Organization>
     */
    #[ORM\ManyToMany(targetEntity: Organization::class)]
    #[ORM\JoinTable(name: 'tender_invited_supplier')]
    #[Groups(['tender:internal', 'tender:write'])]
    private Collection $invitedSuppliers;

    /** @var Collection<int, Proposal> */
    #[ORM\OneToMany(targetEntity: Proposal::class, mappedBy: 'tender', fetch: 'EXTRA_LAZY')]
    private Collection $proposals;

    public function __construct()
    {
        $this->invitedSuppliers = new ArrayCollection();
        $this->proposals = new ArrayCollection();
    }

    public static function getWorkflowName(): string { return 'tender'; }

    #[Assert\Callback]
    public function validateBudgetAndDates(ExecutionContextInterface $context): void
    {
        if (null !== $this->budgetMin && $this->budgetMin > $this->budgetMax) {
            $context->buildViolation('The minimum daily rate cannot exceed the maximum.')->atPath('budgetMin')->addViolation();
        }
        if ($this->deadline && $this->startDate && $this->deadline > $this->startDate->setTime(23, 59, 59)) {
            $context->buildViolation('The response deadline must be before the mission start date.')->atPath('deadline')->addViolation();
        }
        foreach ($this->invitedSuppliers as $supplier) {
            if (!$supplier->isSupplier()) {
                $context->buildViolation('Only supplier organizations can be invited.')->atPath('invitedSuppliers')->addViolation();
                break;
            }
        }
    }

    public function getId(): ?int { return $this->id; }

    public function getReference(): ?string { return $this->reference; }
    public function setReference(string $reference): static { $this->reference = $reference; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = trim($title); return $this; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): static { $this->description = $description; return $this; }

    /** @return list<string> */
    public function getSkills(): array { return $this->skills; }
    /** @param list<string> $skills */
    public function setSkills(array $skills): static { $this->skills = array_values(array_unique(array_filter(array_map('trim', $skills)))); return $this; }

    public function getSeniority(): string { return $this->seniority; }
    public function setSeniority(string $seniority): static { $this->seniority = $seniority; return $this; }

    public function getLocation(): string { return $this->location; }
    public function setLocation(string $location): static { $this->location = $location; return $this; }

    public function getRemotePolicy(): string { return $this->remotePolicy; }
    public function setRemotePolicy(string $remotePolicy): static { $this->remotePolicy = $remotePolicy; return $this; }

    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function setStartDate(?\DateTimeImmutable $startDate): static { $this->startDate = $startDate; return $this; }

    public function getEstimatedDays(): int { return $this->estimatedDays; }
    public function setEstimatedDays(int $estimatedDays): static { $this->estimatedDays = $estimatedDays; return $this; }

    public function getBudgetMin(): ?int { return $this->budgetMin; }
    public function setBudgetMin(?int $budgetMin): static { $this->budgetMin = $budgetMin; return $this; }

    public function getBudgetMax(): int { return $this->budgetMax; }
    public function setBudgetMax(int $budgetMax): static { $this->budgetMax = $budgetMax; return $this; }

    public function getSuggestedDailyRate(): ?int { return $this->suggestedDailyRate; }
    public function setSuggestedDailyRate(?int $rate): static { $this->suggestedDailyRate = $rate; return $this; }

    public function getDeadline(): ?\DateTimeImmutable { return $this->deadline; }
    public function setDeadline(?\DateTimeImmutable $deadline): static { $this->deadline = $deadline; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getOrganization(): ?Organization { return $this->organization; }
    public function setOrganization(Organization $organization): static { $this->organization = $organization; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(User $createdBy): static { $this->createdBy = $createdBy; return $this; }

    public function getApprovedBy(): ?User { return $this->approvedBy; }
    public function setApprovedBy(?User $approvedBy): static { $this->approvedBy = $approvedBy; return $this; }

    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static { $this->publishedAt = $publishedAt; return $this; }

    public function getStatusComment(): ?string { return $this->statusComment; }
    public function setStatusComment(?string $statusComment): static { $this->statusComment = $statusComment; return $this; }

    /** @return Collection<int, Organization> */
    public function getInvitedSuppliers(): Collection { return $this->invitedSuppliers; }
    public function addInvitedSupplier(Organization $supplier): static
    {
        if (!$this->invitedSuppliers->contains($supplier)) {
            $this->invitedSuppliers->add($supplier);
        }

        return $this;
    }
    public function removeInvitedSupplier(Organization $supplier): static { $this->invitedSuppliers->removeElement($supplier); return $this; }

    public function isOpenTo(Organization $supplier): bool
    {
        return $this->invitedSuppliers->isEmpty() || $this->invitedSuppliers->contains($supplier);
    }

    /** @return Collection<int, Proposal> */
    public function getProposals(): Collection { return $this->proposals; }

    /** Competition intelligence: buyer side only. */
    #[Groups(['tender:internal'])]
    public function getProposalsCount(): int { return $this->proposals->count(); }

    #[Groups(['tender:read'])]
    public function isAcceptingProposals(): bool
    {
        return self::STATUS_PUBLISHED === $this->status && $this->deadline > new \DateTimeImmutable();
    }
}
