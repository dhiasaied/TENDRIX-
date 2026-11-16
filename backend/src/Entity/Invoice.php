<?php

namespace App\Entity;

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
use App\Repository\InvoiceRepository;
use App\State\InvoiceProcessor;
use App\State\WorkflowTransitionProcessor;
use App\Workflow\WorkflowSubjectInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Monthly invoice issued by a supplier against an active contract (days worked x contractual daily rate).
 */
#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(
            security: "is_granted('ROLE_SUPPLIER')",
            processor: InvoiceProcessor::class,
            denormalizationContext: ['groups' => ['invoice:write', 'invoice:create']],
        ),
        new Patch(
            security: "is_granted('ROLE_SUPPLIER') and is_granted('ORG_MEMBER', object.getSupplier()) and object.isEditable()",
            processor: InvoiceProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_SUPPLIER') and is_granted('ORG_MEMBER', object.getSupplier()) and object.getStatus() == 'draft'"),
        new Post(
            uriTemplate: '/invoices/{id}/transition',
            status: 200,
            input: TransitionInput::class,
            denormalizationContext: ['groups' => ['transition']],
            read: false,
            processor: WorkflowTransitionProcessor::class,
            openapi: new \ApiPlatform\OpenApi\Model\Operation(summary: 'Apply a workflow transition (submit, approve, reject, pay).'),
            name: 'invoice_transition',
        ),
    ],
    normalizationContext: ['groups' => ['invoice:read', 'contract:summary', 'org:summary', 'user:summary', 'timestamps']],
    denormalizationContext: ['groups' => ['invoice:write']],
    order: ['createdAt' => 'DESC'],
    // Financial data: suppliers, buyers (procurement) and administrators only; requesters have no access.
    security: "is_granted('ROLE_SUPPLIER') or is_granted('ROLE_BUYER') or is_granted('ROLE_ADMIN')",
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'contract' => 'exact', 'supplier' => 'exact', 'buyer' => 'exact', 'period' => 'exact', 'number' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'dueDate', 'amountExclTaxCents', 'period'])]
class Invoice implements WorkflowSubjectInterface
{
    use TimestampableTrait;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PAID = 'paid';

    public const PAYMENT_TERM_DAYS = 30;
    public const DEFAULT_VAT_RATE = 20.0;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['invoice:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    #[Groups(['invoice:read'])]
    private ?string $number = null;

    #[ORM\ManyToOne(inversedBy: 'invoices')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['invoice:read', 'invoice:create'])]
    private ?Contract $contract = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['invoice:read'])]
    private ?Organization $supplier = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['invoice:read'])]
    private ?Organization $buyer = null;

    /** Billing period, format YYYY-MM. */
    #[ORM\Column(length: 7)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^\d{4}-(0[1-9]|1[0-2])$/', message: 'Period must use the YYYY-MM format.')]
    #[Groups(['invoice:read', 'invoice:write'])]
    private string $period = '';

    #[ORM\Column]
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(23)]
    #[Assert\DivisibleBy(0.5, message: 'Days worked must be a multiple of half a day.')]
    #[Groups(['invoice:read', 'invoice:write'])]
    private float $daysWorked = 0.0;

    #[ORM\Column]
    #[Groups(['invoice:read'])]
    private int $dailyRate = 0;

    #[ORM\Column]
    #[Groups(['invoice:read'])]
    private float $vatRate = self::DEFAULT_VAT_RATE;

    #[ORM\Column]
    private int $amountExclTaxCents = 0;

    #[ORM\Column]
    private int $amountInclTaxCents = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 2000)]
    #[Groups(['invoice:read', 'invoice:write'])]
    private ?string $notes = null;

    #[ORM\Column(length: 20)]
    #[Groups(['invoice:read'])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['invoice:read'])]
    private ?string $statusComment = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['invoice:read'])]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['invoice:read'])]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\ManyToOne]
    #[Groups(['invoice:read'])]
    private ?User $approvedBy = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['invoice:read'])]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['invoice:read'])]
    private ?\DateTimeImmutable $dueDate = null;

    public static function getWorkflowName(): string { return 'invoice'; }

    #[Assert\Callback]
    public function validateAgainstContract(ExecutionContextInterface $context): void
    {
        if (null === $this->contract) {
            return;
        }
        if (Contract::STATUS_ACTIVE !== $this->contract->getStatus()) {
            $context->buildViolation('Invoices can only be issued on active contracts.')->atPath('contract')->addViolation();

            return;
        }
        if ($this->daysWorked > $this->contract->getRemainingDays()) {
            $context->buildViolation(\sprintf('Only %s days remain on this contract.', $this->contract->getRemainingDays()))
                ->atPath('daysWorked')->addViolation();
        }
        if ('' !== $this->period && $this->contract->getStartDate()
            && $this->period < $this->contract->getStartDate()->format('Y-m')) {
            $context->buildViolation('The period precedes the contract start date.')->atPath('period')->addViolation();
        }
        if ('' !== $this->period && $this->period > (new \DateTimeImmutable())->format('Y-m')) {
            $context->buildViolation('You cannot invoice a future period.')->atPath('period')->addViolation();
        }
        foreach ($this->contract->getInvoices() as $other) {
            if ($other !== $this && $other->getPeriod() === $this->period && self::STATUS_REJECTED !== $other->getStatus()) {
                $context->buildViolation('An invoice already exists for this period.')->atPath('period')->addViolation();
                break;
            }
        }
    }

    /** Snapshot the contractual rate and compute amounts; called by the processor before every save. */
    public function computeAmounts(): void
    {
        if ($this->contract) {
            $this->dailyRate = $this->contract->getDailyRate();
            $this->supplier = $this->contract->getSupplier();
            $this->buyer = $this->contract->getBuyer();
        }
        $this->amountExclTaxCents = (int) round($this->daysWorked * $this->dailyRate * 100);
        $this->amountInclTaxCents = (int) round($this->amountExclTaxCents * (1 + $this->vatRate / 100));
    }

    public function getId(): ?int { return $this->id; }

    public function getNumber(): ?string { return $this->number; }
    public function setNumber(string $number): static { $this->number = $number; return $this; }

    public function getContract(): ?Contract { return $this->contract; }
    public function setContract(?Contract $contract): static { $this->contract = $contract; return $this; }

    public function getSupplier(): ?Organization { return $this->supplier; }
    public function getBuyer(): ?Organization { return $this->buyer; }

    public function getPeriod(): string { return $this->period; }
    public function setPeriod(string $period): static { $this->period = $period; return $this; }

    public function getDaysWorked(): float { return $this->daysWorked; }
    public function setDaysWorked(float $daysWorked): static { $this->daysWorked = $daysWorked; return $this; }

    public function getDailyRate(): int { return $this->dailyRate; }
    public function getVatRate(): float { return $this->vatRate; }

    public function getAmountExclTaxCents(): int { return $this->amountExclTaxCents; }
    public function getAmountInclTaxCents(): int { return $this->amountInclTaxCents; }

    #[Groups(['invoice:read'])]
    public function getAmountExclTax(): float { return $this->amountExclTaxCents / 100; }

    #[Groups(['invoice:read'])]
    public function getAmountInclTax(): float { return $this->amountInclTaxCents / 100; }

    #[Groups(['invoice:read'])]
    public function getVatAmount(): float { return ($this->amountInclTaxCents - $this->amountExclTaxCents) / 100; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function isEditable(): bool { return \in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true); }

    #[Groups(['invoice:read'])]
    public function isOverdue(): bool
    {
        return self::STATUS_APPROVED === $this->status && $this->dueDate && $this->dueDate < new \DateTimeImmutable('today');
    }

    public function getStatusComment(): ?string { return $this->statusComment; }
    public function setStatusComment(?string $statusComment): static { $this->statusComment = $statusComment; return $this; }

    public function getSubmittedAt(): ?\DateTimeImmutable { return $this->submittedAt; }
    public function markSubmitted(): void
    {
        $this->submittedAt = new \DateTimeImmutable();
        $this->dueDate = new \DateTimeImmutable(\sprintf('today +%d days', self::PAYMENT_TERM_DAYS));
    }

    public function getApprovedAt(): ?\DateTimeImmutable { return $this->approvedAt; }
    public function getApprovedBy(): ?User { return $this->approvedBy; }
    public function markApproved(User $by): void { $this->approvedBy = $by; $this->approvedAt = new \DateTimeImmutable(); }

    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function markPaid(): void { $this->paidAt = new \DateTimeImmutable(); }

    public function getDueDate(): ?\DateTimeImmutable { return $this->dueDate; }
}
