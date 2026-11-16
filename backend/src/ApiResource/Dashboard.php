<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\DashboardProvider;

/**
 * Role-aware KPIs for the home screen of each space (buyer, supplier, administrator).
 */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/reports/dashboard',
            security: "is_granted('ROLE_USER')",
            provider: DashboardProvider::class,
            openapi: new \ApiPlatform\OpenApi\Model\Operation(summary: 'KPIs and charts for the current user\'s space.'),
        ),
    ],
)]
final class Dashboard
{
    #[ApiProperty(identifier: true)]
    public string $space = 'buyer';

    /** @var list<array{key: string, label: string, value: float|int, format: string}> */
    public array $kpis = [];

    /** Amounts excl. VAT per billing period (spend for buyers, revenue for suppliers). @var list<array{period: string, amount: float}> */
    public array $monthly = [];

    /** @var array<string, array<string, int>> */
    public array $statusBreakdown = [];

    /** @var list<array{id: int, name: string, amount: float, rating: float|null}> */
    public array $topPartners = [];

    /** @var list<array<string, mixed>> */
    public array $actionItems = [];
}
