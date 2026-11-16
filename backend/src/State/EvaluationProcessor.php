<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\SupplierEvaluation;
use App\Entity\User;
use App\Message\EvaluationSubmitted;
use App\Service\DomainEventRecorder;
use App\Service\SupplierRatingService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @implements ProcessorInterface<SupplierEvaluation, SupplierEvaluation>
 */
final class EvaluationProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<SupplierEvaluation, SupplierEvaluation> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly Security $security,
        private readonly SupplierRatingService $ratings,
        private readonly DomainEventRecorder $events,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SupplierEvaluation
    {
        \assert($data instanceof SupplierEvaluation);
        $user = $this->security->getUser();
        \assert($user instanceof User);

        if ($data->getContract()?->getBuyer()?->getId() !== $user->getOrganization()?->getId()) {
            throw new AccessDeniedHttpException('You can only evaluate your own contracts.');
        }
        $data->setEvaluator($user);

        $this->events->record(static fn () => new EvaluationSubmitted((int) $data->getId()));
        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        $this->ratings->refresh($data->getSupplier());

        return $result;
    }
}
