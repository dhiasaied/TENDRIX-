<?php

namespace App\Workflow;

use App\Entity\Contract;
use App\Entity\Invoice;
use App\Entity\Proposal;
use App\Entity\Tender;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

/**
 * Business invariants that complement the role-based guards declared in workflow.yaml.
 * Blockers raised here carry the BUSINESS_RULE code, which the API turns into a 422.
 */
final class WorkflowGuardSubscriber implements EventSubscriberInterface
{
    public const BUSINESS_RULE = 'business_rule';

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.tender.guard.submit' => 'guardTenderReadyForPublication',
            'workflow.tender.guard.publish' => 'guardTenderReadyForPublication',
            'workflow.tender.guard.reopen' => 'guardTenderReadyForPublication',
            'workflow.proposal.guard.shortlist' => 'guardTenderStillOpenForSelection',
            'workflow.proposal.guard.accept' => 'guardTenderStillOpenForSelection',
            'workflow.invoice.guard.submit' => 'guardInvoiceWithinContract',
        ];
    }

    public function guardTenderReadyForPublication(GuardEvent $event): void
    {
        /** @var Tender $tender */
        $tender = $event->getSubject();
        if (null === $tender->getDeadline() || $tender->getDeadline() <= new \DateTimeImmutable()) {
            $event->addTransitionBlocker(new TransitionBlocker('The response deadline must be in the future.', self::BUSINESS_RULE));
        }
    }

    public function guardTenderStillOpenForSelection(GuardEvent $event): void
    {
        /** @var Proposal $proposal */
        $proposal = $event->getSubject();
        $status = $proposal->getTender()?->getStatus();
        if (!\in_array($status, [Tender::STATUS_PUBLISHED, Tender::STATUS_CLOSED], true)) {
            $event->addTransitionBlocker(new TransitionBlocker(\sprintf('The tender is %s; proposals can no longer be selected.', $status), self::BUSINESS_RULE));
        }
    }

    public function guardInvoiceWithinContract(GuardEvent $event): void
    {
        /** @var Invoice $invoice */
        $invoice = $event->getSubject();
        $contract = $invoice->getContract();
        if (null === $contract || Contract::STATUS_ACTIVE !== $contract->getStatus()) {
            $event->addTransitionBlocker(new TransitionBlocker('Invoices can only be submitted on active contracts.', self::BUSINESS_RULE));

            return;
        }
        if ($invoice->getDaysWorked() > $contract->getRemainingDays()) {
            $event->addTransitionBlocker(new TransitionBlocker(\sprintf('Only %s days remain on contract %s.', $contract->getRemainingDays(), $contract->getReference()), self::BUSINESS_RULE));
        }
    }
}
