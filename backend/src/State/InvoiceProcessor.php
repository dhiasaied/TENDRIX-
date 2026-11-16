<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Invoice;
use App\Entity\User;
use App\Service\ReferenceGenerator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Numbers new invoices and (re)computes amounts from the contractual daily rate.
 *
 * @implements ProcessorInterface<Invoice, Invoice>
 */
final class InvoiceProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Invoice, Invoice> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly Security $security,
        private readonly ReferenceGenerator $references,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Invoice
    {
        \assert($data instanceof Invoice);
        $user = $this->security->getUser();
        \assert($user instanceof User);

        if ($data->getContract()?->getSupplier()?->getId() !== $user->getOrganization()?->getId()) {
            throw new AccessDeniedHttpException('You can only invoice your own contracts.');
        }

        if ($operation instanceof Post) {
            $data->setNumber($this->references->next(ReferenceGenerator::INVOICE));
        }
        $data->computeAmounts();

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
