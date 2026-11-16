<?php

namespace App\Message;

final class EvaluationSubmitted
{
    public function __construct(public readonly int $evaluationId)
    {
    }
}
