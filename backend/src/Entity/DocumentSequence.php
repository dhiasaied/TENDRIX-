<?php

namespace App\Entity;

use App\Repository\DocumentSequenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Gap-less yearly counter per document prefix (AO, CT, FAC); invoices legally require sequential numbering.
 */
#[ORM\Entity(repositoryClass: DocumentSequenceRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_sequence_prefix_year', columns: ['prefix', 'year'])]
class DocumentSequence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $lastValue = 0;

    public function __construct(
        #[ORM\Column(length: 10)]
        private string $prefix,
        #[ORM\Column(type: 'smallint')]
        private int $year,
    ) {
    }

    public function getId(): ?int { return $this->id; }
    public function getPrefix(): string { return $this->prefix; }
    public function getYear(): int { return $this->year; }

    public function next(): int
    {
        return ++$this->lastValue;
    }
}
