<?php

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class TransitionInput
{
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z_]+$/')]
    #[Groups(['transition'])]
    public string $transition = '';

    /** Optional justification, required by some transitions (rejections, terminations). */
    #[Assert\Length(max: 2000)]
    #[Groups(['transition'])]
    public ?string $comment = null;
}
