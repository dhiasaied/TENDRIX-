<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Dto\TransitionInput;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\ContractRepository;
use App\State\WorkflowTransitionProcessor;
use App\Workflow\WorkflowSubjectInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Engagement contract generated when a proposal is accepted. Never created directly through the API.
 */
#[ORM\Entity(repositoryClass: ContractRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(
            uriTemplate: '/contracts/{id}/transition',
            status: 200,
            input: TransitionInput::class,
            denormalizationContext: ['groups' => ['transition']],
            read: false,
            processor: WorkflowTransitionProcessor::class,
            openapi: new \ApiPlatform\OpenApi\Model\Operation(summary: 'Apply a workflow transition (sign_buyer, sign_supplier, complete, terminate).'),
            name: 'contract_transition',
        ),
    ],
    normalizationContext: ['groups' => ['contract:read', 'contract:summary', 'tender:summary', 'org:summary', 'user:summary', 'timestamps']],
    order: ['createdAt' => 'DESC'],
    security: "is_granted('ROLE_USER')",
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'reference' => 'exact', 'supplier' => 'exact', 'buyer' => 'exact', 'consultantName' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'startDate', 'endDate'])]
class Contract implements WorkflowSubjectInterface
{
    use TimestampableTrait;

    public const STATUS_PENDING_BUYER_SIGNATURE = 'pending_buyer_signature';
    public const STATUS_PENDING_SUPPLIER_SIGNATURE = 'pending_supplier_signature';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_TERMINATED = 'terminated';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['contract:summary'])]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    #[Groups(['contract:summary'])]
    private string $reference = '';

    #[ORM\Column(length: 200)]
    #[Groups(['contract:summary'])]
    private string $title = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['contract:read'])]
    private ?Tender $tender = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Proposal $proposal = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['contract:read'])]
    private ?Organization $buyer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['contract:read'])]
    private ?Organization $supplier = null;

    #[ORM\Column(length: 120)]
    #[Groups(['contract:summary'])]
    private string $consultantName = '';

    #[ORM\Column]
    #[Groups(['contract:read'])]
    private int $dailyRate = 0;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['contract:read'])]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['contract:read'])]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column]
    #[Groups(['contract:read'])]
    private int $plannedDays = 0;

    #[ORM\Column(length: 30)]
    #[Groups(['contract:summary'])]
    private string $status = self::STATUS_PENDING_BUYER_SIGNATURE;

    #[ORM\Column(nullable: true)]
    #[Groups(['contract:read'])]
    private ?\DateTimeImmutable $buyerSignedAt = null;

    #[ORM\ManyToOne]
    #[Groups(['contract:read'])]
    private ?User $buyerSignatory = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['contract:read'])]
    private ?\DateTimeImmutable $supplierSignedAt = null;

    #[ORM\ManyToOne]
    #[Groups(['contract:read'])]
    private ?User $supplierSignatory = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['contract:read'])]
    private ?string $statusComment = null;

    /** @var Collection<int, Invoice> */
    #[ORM\OneToMany(targetEntity: Invoice::class, mappedBy: 'contract')]
    private Collection $invoices;

    #[ORM\OneToOne(mappedBy: 'contract')]
    #[Groups(['contract:read'])]
    private ?SupplierEvaluation $evaluation = null;

    public function __construct()
    {
        $this->invoices = new ArrayCollection();
    }

    public static function getWorkflowName(): string { return 'contract'; }

    public static function fromAcceptedProposal(Proposal $proposal, string $reference): self
    {
        $tender = $proposal->getTender() ?? throw new \LogicException('Proposal has no tender.');
        $start = max($tender->getStartDate(), $proposal->getAvailableFrom());
        // Calendar estimate: ~5 working days per 7 calendar days.
        $calendarDays = (int) ceil($tender->getEstimatedDays() * 7 / 5);

        $contract = new self();
        $contract->reference = $reference;
        $contract->title = $tender->getTitle();
        $contract->tender = $tender;
        $contract->proposal = $proposal;
        $contract->buyer = $tender->getOrganization();
        $contract->supplier = $proposal->getSupplier();
        $contract->consultantName = $proposal->getCandidateName();
        $contract->dailyRate = $proposal->getDailyRate();
        $contract->plannedDays = $tender->getEstimatedDays();
        $contract->startDate = $start;
        $contract->endDate = $start->modify(\sprintf('+%d days', $calendarDays));

        return $contract;
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function getTitle(): string { return $this->title; }
    public function getTender(): ?Tender { return $this->tender; }
    public function getProposal(): ?Proposal { return $this->proposal; }
    public function getBuyer(): ?Organization { return $this->buyer; }
    public function getSupplier(): ?Organization { return $this->supplier; }
    public function getConsultantName(): string { return $this->consultantName; }
    public function getDailyRate(): int { return $this->dailyRate; }
    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function getPlannedDays(): int { return $this->plannedDays; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getBuyerSignedAt(): ?\DateTimeImmutable { return $this->buyerSignedAt; }
    public function getBuyerSignatory(): ?User { return $this->buyerSignatory; }
    public function signAsBuyer(User $user): void { $this->buyerSignatory = $user; $this->buyerSignedAt = new \DateTimeImmutable(); }

    public function getSupplierSignedAt(): ?\DateTimeImmutable { return $this->supplierSignedAt; }
    public function getSupplierSignatory(): ?User { return $this->supplierSignatory; }
    public function signAsSupplier(User $user): void { $this->supplierSignatory = $user; $this->supplierSignedAt = new \DateTimeImmutable(); }

    public function getStatusComment(): ?string { return $this->statusComment; }
    public function setStatusComment(?string $statusComment): static { $this->statusComment = $statusComment; return $this; }

    /** @return Collection<int, Invoice> */
    public function getInvoices(): Collection { return $this->invoices; }

    public function getEvaluation(): ?SupplierEvaluation { return $this->evaluation; }

    #[Groups(['contract:read'])]
    public function getTotalValue(): int { return $this->plannedDays * $this->dailyRate; }

    /** Days already invoiced, counting every invoice that is not rejected/draft. */
    #[Groups(['contract:read'])]
    public function getInvoicedDays(): float
    {
        $days = 0.0;
        foreach ($this->invoices as $invoice) {
            if (\in_array($invoice->getStatus(), [Invoice::STATUS_SUBMITTED, Invoice::STATUS_APPROVED, Invoice::STATUS_PAID], true)) {
                $days += $invoice->getDaysWorked();
            }
        }

        return $days;
    }

    #[Groups(['contract:read'])]
    public function getRemainingDays(): float
    {
        return max(0.0, $this->plannedDays - $this->getInvoicedDays());
    }

    #[Groups(['contract:read'])]
    public function getConsumptionRate(): float
    {
        return $this->plannedDays > 0 ? round($this->getInvoicedDays() / $this->plannedDays * 100, 1) : 0.0;
    }
}
