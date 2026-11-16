<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Ai\PricingAgent;
use App\Entity\Tender;
use App\Entity\User;
use App\Service\ReferenceGenerator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stamps a new tender with its owner organization, author, reference and AI fair-price estimate.
 *
 * @implements ProcessorInterface<Tender, Tender>
 */
final class TenderCreateProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Tender, Tender> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly Security $security,
        private readonly ReferenceGenerator $references,
        private readonly PricingAgent $pricing,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Tender
    {
        \assert($data instanceof Tender);
        $user = $this->security->getUser();
        \assert($user instanceof User && null !== $user->getOrganization());

        $data->setReference($this->references->next(ReferenceGenerator::TENDER));
        $data->setOrganization($user->getOrganization());
        $data->setCreatedBy($user);
        $data->setStatus(Tender::STATUS_DRAFT);
        $data->setSuggestedDailyRate($this->pricing->suggest($data->getSkills(), $data->getSeniority())->median);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
