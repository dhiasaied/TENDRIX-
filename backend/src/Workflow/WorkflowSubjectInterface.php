<?php

namespace App\Workflow;

/**
 * Entities whose lifecycle is driven by a Symfony state machine (marking stored in the "status" property).
 * The workflow name must be returned by {@see getWorkflowName()} and match config/packages/workflow.yaml.
 */
interface WorkflowSubjectInterface
{
    public function getId(): ?int;

    public function getStatus(): string;

    public function setStatus(string $status): static;

    public static function getWorkflowName(): string;
}
