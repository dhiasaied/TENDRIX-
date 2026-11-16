<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\SupplierEvaluationRepository;
use App\State\EvaluationProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Buyer's assessment of a supplier at the end (or during) a contract; feeds supplier performance reporting.
 */
#[ORM\Entity(repositoryClass: SupplierEvaluationRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity('contract', message: 'This contract has already been evaluated.')]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(security: "is_granted('ROLE_BUYER') or is_granted('ROLE_REQUESTER')", processor: EvaluationProcessor::class),
    ],
    normalizationContext: ['groups' => ['evaluation:read', 'contract:summary', 'org:summary', 'user:summary', 'timestamps']],
    denormalizationContext: ['groups' => ['evaluation:write']],
    order: ['createdAt' => 'DESC'],
    security: "is_granted('ROLE_USER')",
)]
#[ApiFilter(SearchFilter::class, properties: ['supplier' => 'exact', 'contract' => 'exact'])]
class SupplierEvaluation
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['evaluation:read', 'contract:read'])]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'evaluation')]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Assert\NotNull]
    #[Groups(['evaluation:read', 'evaluation:write'])]
    private ?Contract $contract = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['evaluation:read'])]
    private ?Organization $supplier = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['evaluation:read'])]
    private ?User $evaluator = null;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1, max: 5)]
    #[Groups(['evaluation:read', 'evaluation:write', 'contract:read'])]
    private int $quality = 3;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1, max: 5)]
    #[Groups(['evaluation:read', 'evaluation:write', 'contract:read'])]
    private int $reactivity = 3;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1, max: 5)]
    #[Groups(['evaluation:read', 'evaluation:write', 'contract:read'])]
    private int $communication = 3;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1, max: 5)]
    #[Groups(['evaluation:read', 'evaluation:write', 'contract:read'])]
    private int $deadlines = 3;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 3000)]
    #[Groups(['evaluation:read', 'evaluation:write', 'contract:read'])]
    private ?string $comment = null;

    #[Assert\Callback]
    public function validateContract(ExecutionContextInterface $context): void
    {
        if ($this->contract && !\in_array($this->contract->getStatus(), [Contract::STATUS_ACTIVE, Contract::STATUS_COMPLETED, Contract::STATUS_TERMINATED], true)) {
            $context->buildViolation('Only signed contracts can be evaluated.')->atPath('contract')->addViolation();
        }
    }

    public function getId(): ?int { return $this->id; }

    public function getContract(): ?Contract { return $this->contract; }
    public function setContract(?Contract $contract): static
    {
        $this->contract = $contract;
        $this->supplier = $contract?->getSupplier();

        return $this;
    }

    public function getSupplier(): ?Organization { return $this->supplier; }

    public function getEvaluator(): ?User { return $this->evaluator; }
    public function setEvaluator(User $evaluator): static { $this->evaluator = $evaluator; return $this; }

    public function getQuality(): int { return $this->quality; }
    public function setQuality(int $quality): static { $this->quality = $quality; return $this; }

    public function getReactivity(): int { return $this->reactivity; }
    public function setReactivity(int $reactivity): static { $this->reactivity = $reactivity; return $this; }

    public function getCommunication(): int { return $this->communication; }
    public function setCommunication(int $communication): static { $this->communication = $communication; return $this; }

    public function getDeadlines(): int { return $this->deadlines; }
    public function setDeadlines(int $deadlines): static { $this->deadlines = $deadlines; return $this; }

    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): static { $this->comment = $comment; return $this; }

    #[Groups(['evaluation:read', 'contract:read'])]
    public function getOverallScore(): float
    {
        return round(($this->quality + $this->reactivity + $this->communication + $this->deadlines) / 4, 2);
    }
}
