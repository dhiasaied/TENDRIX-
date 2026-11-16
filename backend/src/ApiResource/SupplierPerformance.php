<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\State\SupplierPerformanceProvider;

/**
 * Supplier performance scorecard. Buyers see metrics restricted to their own relationship with
 * each supplier (ratings excepted, which are platform-wide); suppliers see their own scorecard.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/reports/suppliers',
            security: "is_granted('ROLE_USER')",
            provider: SupplierPerformanceProvider::class,
            paginationEnabled: false,
            openapi: new \ApiPlatform\OpenApi\Model\Operation(summary: 'Supplier performance scorecards.'),
        ),
    ],
)]
final class SupplierPerformance
{
    #[ApiProperty(identifier: true)]
    public int $supplierId = 0;

    public string $name = '';
    public ?string $city = null;
    public int $proposalsCount = 0;
    public int $acceptedCount = 0;
    public float $winRate = 0.0;
    public ?float $averageMatchScore = null;
    public ?float $averageDailyRate = null;
    public int $contractsCount = 0;
    public int $activeContracts = 0;
    public float $totalBilled = 0.0;
    public ?float $averageRating = null;
    public int $evaluationsCount = 0;

    /** @var array{quality: float|null, reactivity: float|null, communication: float|null, deadlines: float|null} */
    public array $ratingDetails = ['quality' => null, 'reactivity' => null, 'communication' => null, 'deadlines' => null];
}
