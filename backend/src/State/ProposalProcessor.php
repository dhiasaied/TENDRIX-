<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\Proposal;
use App\Entity\User;
use App\Message\ProposalSubmitted;
use App\Service\DomainEventRecorder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Creates or updates a supplier proposal and schedules its asynchronous AI scoring.
 *
 * @implements ProcessorInterface<Proposal, Proposal>
 */
final class ProposalProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Proposal, Proposal> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly Security $security,
        private readonly DomainEventRecorder $events,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Proposal
    {
        \assert($data instanceof Proposal);
        $user = $this->security->getUser();
        \assert($user instanceof User && null !== $user->getOrganization());

        $tender = $data->getTender();
        if (!$tender?->isAcceptingProposals()) {
            throw new UnprocessableEntityHttpException('This tender is not accepting proposals (closed or deadline passed).');
        }

        $isCreation = $operation instanceof Post;
        if ($isCreation) {
            if (!$tender->isOpenTo($user->getOrganization())) {
                throw new UnprocessableEntityHttpException('Your organization is not invited to this consultation.');
            }
            $data->setSupplier($user->getOrganization());
            $data->setSubmittedBy($user);
            $data->setStatus(Proposal::STATUS_SUBMITTED);
        }
        // Re-validate now that the supplier is known (uniqueness of the candidate per tender and supplier).
        $violations = $this->validator->validate($data);
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }
        // Content changed: the previous score is obsolete until the agent re-evaluates.
        $data->setMatchScore(null)->setMatchBreakdown(null)->setMatchAnalysis(null);

        // Recorded before the flush; the factory runs after commit, once the id exists.
        $this->events->record(static fn () => new ProposalSubmitted((int) $data->getId(), !$isCreation));

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
