<?php

namespace App\Serializer;

use App\Workflow\WorkflowSubjectInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Workflow\Registry;

/**
 * Adds "availableTransitions" to workflow-driven resources returned as the root item: the
 * actions the *current user* may perform right now, evaluated with the same guards as the
 * transition endpoint. The UI renders buttons from it instead of duplicating business rules.
 */
final class AvailableTransitionsNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'available_transitions_normalizer_called';

    /** Transitions fired by the platform only, never offered in the UI. */
    private const INTERNAL = ['award'];

    public function __construct(private readonly Registry $workflows)
    {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        \assert($data instanceof WorkflowSubjectInterface);
        $isRoot = !isset($context['api_sub_level']) && !isset($context[self::ALREADY_CALLED]);
        $context[self::ALREADY_CALLED] = true;

        $normalized = $this->normalizer->normalize($data, $format, $context);

        if ($isRoot && \is_array($normalized)) {
            $enabled = $this->workflows->get($data, $data::getWorkflowName())->getEnabledTransitions($data);
            $normalized['availableTransitions'] = array_values(array_diff(
                array_unique(array_map(static fn ($t) => $t->getName(), $enabled)),
                self::INTERNAL,
            ));
        }

        return $normalized;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof WorkflowSubjectInterface && !isset($context[self::ALREADY_CALLED]);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [WorkflowSubjectInterface::class => false];
    }
}
