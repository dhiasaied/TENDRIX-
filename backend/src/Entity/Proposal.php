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
use App\Dto\TransitionInput;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\ProposalRepository;
use App\State\ProposalProcessor;
use App\State\WorkflowTransitionProcessor;
use App\Workflow\WorkflowSubjectInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A supplier's answer to a tender, presenting one candidate consultant.
 */
#[ORM\Entity(repositoryClass: ProposalRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_proposal_candidate', columns: ['tender_id', 'supplier_id', 'candidate_name'])]
#[UniqueEntity(fields: ['tender', 'supplier', 'candidateName'], message: 'This candidate has already been proposed for this tender.', errorPath: 'candidateName')]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(
            security: "is_granted('ROLE_SUPPLIER')",
            processor: ProposalProcessor::class,
            denormalizationContext: ['groups' => ['proposal:write', 'proposal:create']],
        ),
        new Patch(security: "is_granted('ROLE_SUPPLIER') and is_granted('ORG_MEMBER', object.getSupplier()) and object.getStatus() == 'submitted'", processor: ProposalProcessor::class),
        new Post(
            uriTemplate: '/proposals/{id}/transition',
            status: 200,
            input: TransitionInput::class,
            denormalizationContext: ['groups' => ['transition']],
            read: false,
            processor: WorkflowTransitionProcessor::class,
            openapi: new \ApiPlatform\OpenApi\Model\Operation(summary: 'Apply a workflow transition (shortlist, reject, accept, withdraw).'),
            name: 'proposal_transition',
        ),
    ],
    normalizationContext: ['groups' => ['proposal:read', 'tender:summary', 'org:summary', 'user:summary', 'timestamps']],
    denormalizationContext: ['groups' => ['proposal:write']],
    order: ['createdAt' => 'DESC'],
    security: "is_granted('ROLE_USER')",
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'tender' => 'exact', 'supplier' => 'exact', 'candidateName' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'matchScore', 'dailyRate'])]
class Proposal implements WorkflowSubjectInterface
{
    use TimestampableTrait;

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_SHORTLISTED = 'shortlisted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_WITHDRAWN = 'withdrawn';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['proposal:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'proposals')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['proposal:read', 'proposal:create'])]
    private ?Tender $tender = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['proposal:read'])]
    private ?Organization $supplier = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['proposal:read'])]
    private ?User $submittedBy = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Groups(['proposal:read', 'proposal:write'])]
    private string $candidateName = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Groups(['proposal:read', 'proposal:write'])]
    private string $candidateTitle = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    #[Assert\Count(min: 1, max: 30)]
    #[Assert\All([new Assert\Type('string'), new Assert\NotBlank, new Assert\Length(max: 60)])]
    #[Groups(['proposal:read', 'proposal:write'])]
    private array $candidateSkills = [];

    #[ORM\Column]
    #[Assert\Range(min: 0, max: 50)]
    #[Groups(['proposal:read', 'proposal:write'])]
    private int $experienceYears = 0;

    /** Résumé / profile summary pasted by the supplier; fed to the matching agent. */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(min: 50, max: 20000)]
    #[Groups(['proposal:read', 'proposal:write'])]
    private string $candidateSummary = '';

    #[ORM\Column]
    #[Assert\Range(min: 100, max: 5000)]
    #[Groups(['proposal:read', 'proposal:write'])]
    private int $dailyRate = 500;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['proposal:read', 'proposal:write'])]
    private ?\DateTimeImmutable $availableFrom = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 5000)]
    #[Groups(['proposal:read', 'proposal:write'])]
    private ?string $coverLetter = null;

    #[ORM\Column(length: 20)]
    #[Groups(['proposal:read'])]
    private string $status = self::STATUS_SUBMITTED;

    /**
     * Adequacy rate (0-100) computed asynchronously by the matching agent. Like the breakdown and
     * the analysis, it is a buyer-side decision aid: suppliers never see it, so it cannot be gamed.
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['proposal:internal'])]
    private ?int $matchScore = null;

    /** @var array<string, int>|null Per-criterion breakdown of the match score. */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['proposal:internal'])]
    private ?array $matchBreakdown = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['proposal:internal'])]
    private ?string $matchAnalysis = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['proposal:read'])]
    private ?string $statusComment = null;

    public static function getWorkflowName(): string { return 'proposal'; }

    public function getId(): ?int { return $this->id; }

    public function getTender(): ?Tender { return $this->tender; }
    public function setTender(?Tender $tender): static { $this->tender = $tender; return $this; }

    public function getSupplier(): ?Organization { return $this->supplier; }
    public function setSupplier(Organization $supplier): static { $this->supplier = $supplier; return $this; }

    public function getSubmittedBy(): ?User { return $this->submittedBy; }
    public function setSubmittedBy(User $submittedBy): static { $this->submittedBy = $submittedBy; return $this; }

    public function getCandidateName(): string { return $this->candidateName; }
    public function setCandidateName(string $candidateName): static { $this->candidateName = trim($candidateName); return $this; }

    public function getCandidateTitle(): string { return $this->candidateTitle; }
    public function setCandidateTitle(string $candidateTitle): static { $this->candidateTitle = trim($candidateTitle); return $this; }

    /** @return list<string> */
    public function getCandidateSkills(): array { return $this->candidateSkills; }
    /** @param list<string> $skills */
    public function setCandidateSkills(array $skills): static { $this->candidateSkills = array_values(array_unique(array_filter(array_map('trim', $skills)))); return $this; }

    public function getExperienceYears(): int { return $this->experienceYears; }
    public function setExperienceYears(int $experienceYears): static { $this->experienceYears = $experienceYears; return $this; }

    public function getCandidateSummary(): string { return $this->candidateSummary; }
    public function setCandidateSummary(string $candidateSummary): static { $this->candidateSummary = $candidateSummary; return $this; }

    public function getDailyRate(): int { return $this->dailyRate; }
    public function setDailyRate(int $dailyRate): static { $this->dailyRate = $dailyRate; return $this; }

    public function getAvailableFrom(): ?\DateTimeImmutable { return $this->availableFrom; }
    public function setAvailableFrom(?\DateTimeImmutable $availableFrom): static { $this->availableFrom = $availableFrom; return $this; }

    public function getCoverLetter(): ?string { return $this->coverLetter; }
    public function setCoverLetter(?string $coverLetter): static { $this->coverLetter = $coverLetter; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getMatchScore(): ?int { return $this->matchScore; }
    public function setMatchScore(?int $matchScore): static { $this->matchScore = $matchScore; return $this; }

    /** @return array<string, int>|null */
    public function getMatchBreakdown(): ?array { return $this->matchBreakdown; }
    /** @param array<string, int>|null $matchBreakdown */
    public function setMatchBreakdown(?array $matchBreakdown): static { $this->matchBreakdown = $matchBreakdown; return $this; }

    public function getMatchAnalysis(): ?string { return $this->matchAnalysis; }
    public function setMatchAnalysis(?string $matchAnalysis): static { $this->matchAnalysis = $matchAnalysis; return $this; }

    public function getStatusComment(): ?string { return $this->statusComment; }
    public function setStatusComment(?string $statusComment): static { $this->statusComment = $statusComment; return $this; }
}
