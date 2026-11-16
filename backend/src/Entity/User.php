<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\UserRepository;
use App\State\MeProvider;
use App\State\UserProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity('email', message: 'An account already exists with this email.')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_BUYER') or is_granted('ROLE_SUPPLIER')"),
        // Declared first so that it is the canonical IRI (/api/users/{id}) rather than /api/me.
        new Get(security: "is_granted('ROLE_ADMIN') or object == user or is_granted('ORG_MEMBER', object.getOrganization())"),
        new Get(uriTemplate: '/me', provider: MeProvider::class, security: "is_granted('ROLE_USER')", name: 'me'),
        new Post(
            security: "is_granted('ROLE_ADMIN')",
            validationContext: ['groups' => ['Default', 'user:create']],
            processor: UserProcessor::class,
            denormalizationContext: ['groups' => ['user:write', 'user:create']],
        ),
        new Patch(security: "is_granted('ROLE_ADMIN') or object == user", processor: UserProcessor::class),
        // No DELETE: accounts referenced by tenders, contracts and the audit trail are deactivated, never erased.
    ],
    normalizationContext: ['groups' => ['user:read', 'user:summary', 'org:summary', 'timestamps']],
    denormalizationContext: ['groups' => ['user:write']],
    order: ['lastName' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['email' => 'partial', 'lastName' => 'partial', 'organization' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['active'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;

    public const ROLE_ADMIN = 'ROLE_ADMIN';
    /** Procurement officer: approves/publishes tenders, awards, signs contracts, approves invoices. */
    public const ROLE_BUYER = 'ROLE_BUYER';
    /** Hiring manager ("donneur d'ordre"): drafts tenders and reviews proposals for their needs. */
    public const ROLE_REQUESTER = 'ROLE_REQUESTER';
    public const ROLE_SUPPLIER = 'ROLE_SUPPLIER';

    public const ASSIGNABLE_ROLES = [self::ROLE_ADMIN, self::ROLE_BUYER, self::ROLE_REQUESTER, self::ROLE_SUPPLIER];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:summary'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    #[Groups(['user:read', 'user:create'])]
    private string $email = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    #[Groups(['user:summary', 'user:write'])]
    private string $firstName = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    #[Groups(['user:summary', 'user:write'])]
    private string $lastName = '';

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    #[Groups(['user:read', 'user:write'])]
    private ?string $jobTitle = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Regex('/^\+?[0-9 .\-()]{6,30}$/')]
    #[Groups(['user:read', 'user:write'])]
    private ?string $phone = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    #[Assert\Count(min: 1, groups: ['user:create'])]
    #[Assert\All([new Assert\Choice(choices: self::ASSIGNABLE_ROLES)])]
    #[Groups(['user:read', 'user:admin'])]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[Assert\NotBlank(groups: ['user:create'])]
    #[Assert\Length(min: 12, max: 4096)]
    #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM)]
    #[Assert\NotCompromisedPassword(skipOnError: true)]
    #[Groups(['user:write'])]
    #[SerializedName('password')]
    private ?string $plainPassword = null;

    /** Required (and checked by UserProcessor) when users change their own password. Never persisted. */
    #[Groups(['user:write'])]
    private ?string $currentPassword = null;

    /**
     * Embedded in every JWT ("sv" claim). Incremented on logout, password change and deactivation,
     * which revokes every token issued before.
     */
    #[ORM\Column(options: ['default' => 1])]
    private int $sessionVersion = 1;

    #[ORM\ManyToOne(inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['user:read', 'user:admin'])]
    private ?Organization $organization = null;

    #[ORM\Column]
    #[Groups(['user:read', 'user:admin'])]
    private bool $active = true;

    #[ORM\Column(nullable: true)]
    #[Groups(['user:read'])]
    private ?\DateTimeImmutable $lastLoginAt = null;

    public function getId(): ?int { return $this->id; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = mb_strtolower(trim($email)); return $this; }

    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('User has no email.');
        }

        return $this->email;
    }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): static { $this->firstName = trim($firstName); return $this; }

    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): static { $this->lastName = trim($lastName); return $this; }

    #[Groups(['user:summary'])]
    public function getFullName(): string { return trim($this->firstName.' '.$this->lastName); }

    public function getJobTitle(): ?string { return $this->jobTitle; }
    public function setJobTitle(?string $jobTitle): static { $this->jobTitle = $jobTitle; return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): static { $this->phone = $phone ?: null; return $this; }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique(array_diff($roles, ['ROLE_USER'])));

        return $this;
    }

    public function hasRole(string $role): bool { return \in_array($role, $this->getRoles(), true); }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }

    public function getPlainPassword(): ?string { return $this->plainPassword; }
    public function setPlainPassword(?string $plainPassword): static { $this->plainPassword = $plainPassword; return $this; }

    public function getCurrentPassword(): ?string { return $this->currentPassword; }
    public function setCurrentPassword(?string $currentPassword): static { $this->currentPassword = $currentPassword; return $this; }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
        $this->currentPassword = null;
    }

    public function getSessionVersion(): int { return $this->sessionVersion; }

    /** Revokes every token issued so far. */
    public function revokeSessions(): void
    {
        ++$this->sessionVersion;
    }

    /** Roles must match the organization's side of the marketplace. */
    #[Assert\Callback]
    public function validateRolesAgainstOrganization(ExecutionContextInterface $context): void
    {
        if (null === $this->organization) {
            return;
        }
        $allowed = $this->organization->isSupplier()
            ? [self::ROLE_SUPPLIER]
            : [self::ROLE_BUYER, self::ROLE_REQUESTER, self::ROLE_ADMIN];
        if ([] !== array_diff($this->roles, $allowed)) {
            $context->buildViolation('These roles are not allowed for a {{ type }} organization.')
                ->setParameter('{{ type }}', $this->organization->getType())
                ->atPath('roles')->addViolation();
        }
    }

    public function getOrganization(): ?Organization { return $this->organization; }
    public function setOrganization(?Organization $organization): static { $this->organization = $organization; return $this; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }

    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static { $this->lastLoginAt = $lastLoginAt; return $this; }
}
