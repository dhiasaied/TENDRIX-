<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\State\ProviderInterface;
use App\Dto\TransitionInput;
use App\Workflow\WorkflowGuardSubscriber;
use App\Workflow\WorkflowSubjectInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\TransitionBlocker;

/**
 * POST /{resource}/{id}/transition — single entry point for every lifecycle action.
 *
 * The subject is loaded through the Doctrine item provider so tenant isolation applies
 * (an entity of another organization is a 404). Failures are mapped precisely:
 * unknown transition 400, wrong role 403, wrong current state 409, business rule 422.
 *
 * @implements ProcessorInterface<TransitionInput, WorkflowSubjectInterface>
 */
final class WorkflowTransitionProcessor implements ProcessorInterface
{
    /** Transitions that must be justified (traceability of negative decisions). */
    private const COMMENT_REQUIRED = [
        'tender' => ['reject', 'cancel'],
        'proposal' => ['reject'],
        'contract' => ['terminate'],
        'invoice' => ['reject'],
    ];

    /** Transitions only fired internally by the platform. */
    private const INTERNAL = ['tender' => ['award']];

    /**
     * @param ProviderInterface<object> $itemProvider
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private readonly ProviderInterface $itemProvider,
        private readonly Registry $workflows,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): WorkflowSubjectInterface
    {
        \assert($data instanceof TransitionInput);

        $subject = $this->itemProvider->provide($operation, $uriVariables, $context);
        if (!$subject instanceof WorkflowSubjectInterface) {
            throw new NotFoundHttpException('Resource not found.');
        }

        $name = $subject::getWorkflowName();
        $workflow = $this->workflows->get($subject, $name);
        $transition = $data->transition;

        $known = array_map(static fn ($t) => $t->getName(), $workflow->getDefinition()->getTransitions());
        if (!\in_array($transition, $known, true) || \in_array($transition, self::INTERNAL[$name] ?? [], true)) {
            throw new UnprocessableEntityHttpException(\sprintf('Unknown transition "%s". Available: %s.', $transition, implode(', ', array_diff(array_unique($known), self::INTERNAL[$name] ?? []))));
        }

        if (\in_array($transition, self::COMMENT_REQUIRED[$name] ?? [], true) && '' === trim((string) $data->comment)) {
            throw new UnprocessableEntityHttpException('A comment is required for this action.');
        }

        $blockers = $workflow->buildTransitionBlockerList($subject, $transition);
        if (!$blockers->isEmpty()) {
            /** @var TransitionBlocker $blocker */
            foreach ($blockers as $blocker) {
                match ($blocker->getCode()) {
                    TransitionBlocker::BLOCKED_BY_MARKING => throw new ConflictHttpException(\sprintf('Action "%s" is not possible while the %s is "%s".', $transition, $name, $subject->getStatus())),
                    WorkflowGuardSubscriber::BUSINESS_RULE => throw new UnprocessableEntityHttpException($blocker->getMessage()),
                    default => null,
                };
            }
            throw new AccessDeniedHttpException('You are not allowed to perform this action.');
        }

        $this->em->wrapInTransaction(function () use ($workflow, $subject, $transition, $data): void {
            $workflow->apply($subject, $transition, ['comment' => $data->comment ? trim($data->comment) : null]);
        });

        return $subject;
    }
}
