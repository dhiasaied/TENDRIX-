<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\RegistrationProcessor;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Self-service onboarding for suppliers: creates the supplier organization and its first account.
 * Buyer organizations are onboarded by platform administrators.
 */
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/auth/register',
            security: 'true',
            processor: RegistrationProcessor::class,
            openapi: new \ApiPlatform\OpenApi\Model\Operation(summary: 'Register a supplier organization and its first user.'),
        ),
    ],
    normalizationContext: ['groups' => ['registration:read']],
    denormalizationContext: ['groups' => ['registration:write']],
)]
final class Registration
{
    #[Groups(['registration:read'])]
    public ?int $userId = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    #[Groups(['registration:read', 'registration:write'])]
    public string $companyName = '';

    #[Assert\Regex('/^\d{14}$/', message: 'SIRET must contain exactly 14 digits.')]
    #[Groups(['registration:write'])]
    public ?string $siret = null;

    #[Assert\Length(max: 120)]
    #[Groups(['registration:write'])]
    public ?string $city = null;

    /** @var list<string> */
    #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 60)])]
    #[Groups(['registration:write'])]
    public array $skills = [];

    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    #[Groups(['registration:write'])]
    public string $firstName = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    #[Groups(['registration:write'])]
    public string $lastName = '';

    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['registration:read', 'registration:write'])]
    public string $email = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 12, max: 4096)]
    #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM)]
    #[Assert\NotCompromisedPassword(skipOnError: true)]
    #[Groups(['registration:write'])]
    public string $password = '';
}
