<?php

namespace App\Ai;

final class MatchResult
{
    /**
     * @param array<string, int> $breakdown
     */
    public function __construct(
        public readonly int $score,
        public readonly array $breakdown,
        public readonly string $analysis,
        public readonly string $source,
    ) {
    }
}
