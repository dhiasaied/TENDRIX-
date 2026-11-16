<?php

namespace App\Ai;

final class PriceSuggestion
{
    public function __construct(
        public readonly int $low,
        public readonly int $median,
        public readonly int $high,
        public readonly int $sampleSize,
        public readonly string $basis,
        public readonly string $rationale,
        public readonly string $source = 'heuristic',
    ) {
    }

    public function withRationale(string $rationale, string $source): self
    {
        return new self($this->low, $this->median, $this->high, $this->sampleSize, $this->basis, $rationale, $source);
    }
}
