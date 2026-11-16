<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Entity\Tender;
use App\State\AiProcessor;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * AI agent: fair daily-rate ("juste prix") recommendation for a profile.
 */
#[ApiResource(
    shortName: 'AiPriceSuggestion',
    operations: [
        new Post(
            uriTemplate: '/ai/price-suggestion',
            status: 200,
            // Built from other clients' contracts: a buyer-side tool, never exposed to suppliers.
            security: "is_granted('ROLE_BUYER') or is_granted('ROLE_REQUESTER') or is_granted('ROLE_ADMIN')",
            processor: AiProcessor::class,
            openapi: new \ApiPlatform\OpenApi\Model\Operation(summary: 'Suggest a fair daily-rate range for a profile.'),
        ),
    ],
    normalizationContext: ['groups' => ['ai:read']],
    denormalizationContext: ['groups' => ['ai:write']],
)]
final class PriceRange
{
    /** @var list<string> */
    #[Assert\Count(min: 1)]
    #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 60)])]
    #[Groups(['ai:write', 'ai:read'])]
    public array $skills = [];

    #[Assert\Choice(choices: Tender::SENIORITIES)]
    #[Groups(['ai:write', 'ai:read'])]
    public string $seniority = 'confirmed';

    #[Groups(['ai:read'])]
    public int $low = 0;

    #[Groups(['ai:read'])]
    public int $median = 0;

    #[Groups(['ai:read'])]
    public int $high = 0;

    #[Groups(['ai:read'])]
    public int $sampleSize = 0;

    /** contracts | contracts+proposals | reference_grid */
    #[Groups(['ai:read'])]
    public string $basis = 'reference_grid';

    #[Groups(['ai:read'])]
    public string $rationale = '';

    #[Groups(['ai:read'])]
    public string $source = 'heuristic';
}
