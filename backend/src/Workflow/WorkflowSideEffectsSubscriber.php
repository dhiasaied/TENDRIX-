<?php

namespace App\Workflow;

use App\Entity\Contract;
use App\Entity\Invoice;
use App\Entity\Proposal;
use App\Entity\Tender;
use App\Entity\User;
use App\Message\WorkflowTransitioned;
use App\Service\DomainEventRecorder;
use App\Service\ReferenceGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\LeaveEvent;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Applies the consequences of each transition (timestamps, signatures, contract generation...)
 * and records a WorkflowTransitioned domain event for asynchronous consumers.
 */
final class WorkflowSideEffectsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly ReferenceGenerator $references,
        private readonly DomainEventRecorder $events,
        #[Target('tender')] private readonly WorkflowInterface $tenderWorkflow,
        #[Target('proposal')] private readonly WorkflowInterface $proposalWorkflow,
    ) {
    }

    /** @var array<int, string> place each subject is leaving, keyed by object id */
    private array $leaving = [];

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.leave' => 'rememberOrigin',
            'workflow.completed' => ['recordTransition', -100],
            'workflow.tender.completed.publish' => 'onTenderPublished',
            'workflow.proposal.completed.accept' => 'onProposalAccepted',
            'workflow.contract.completed.sign_buyer' => 'onContractSignedByBuyer',
            'workflow.contract.completed.sign_supplier' => 'onContractSignedBySupplier',
            'workflow.invoice.completed.submit' => 'onInvoiceSubmitted',
            'workflow.invoice.completed.approve' => 'onInvoiceApproved',
            'workflow.invoice.completed.pay' => 'onInvoicePaid',
        ];
    }

    public function rememberOrigin(LeaveEvent $event): void
    {
        $subject = $event->getSubject();
        if ($subject instanceof WorkflowSubjectInterface) {
            $this->leaving[spl_object_id($subject)] = $subject->getStatus();
        }
    }

    public function recordTransition(CompletedEvent $event): void
    {
        $subject = $event->getSubject();
        if (!$subject instanceof WorkflowSubjectInterface) {
            return;
        }

        $comment = $event->getContext()['comment'] ?? null;
        if (method_exists($subject, 'setStatusComment')) {
            $subject->setStatusComment($comment);
        }

        $transition = $event->getTransition();
        $from = $this->leaving[spl_object_id($subject)] ?? '';
        unset($this->leaving[spl_object_id($subject)]);
        $actorId = $this->actor()?->getId();
        $workflow = $event->getWorkflowName();
        $name = $transition?->getName() ?? '';
        $to = $subject->getStatus();

        $this->events->record(static fn () => new WorkflowTransitioned(
            $workflow, (int) $subject->getId(), $name, $from, $to, $actorId, $comment,
        ));
    }

    public function onTenderPublished(CompletedEvent $event): void
    {
        /** @var Tender $tender */
        $tender = $event->getSubject();
        $tender->setPublishedAt(new \DateTimeImmutable());
        $tender->setApprovedBy($this->actor());
    }

    /**
     * Awarding a proposal: generate the contract, award the tender and decline competing proposals.
     */
    public function onProposalAccepted(CompletedEvent $event): void
    {
        /** @var Proposal $proposal */
        $proposal = $event->getSubject();
        $tender = $proposal->getTender() ?? throw new \LogicException('Proposal without tender.');

        $contract = Contract::fromAcceptedProposal($proposal, $this->references->next(ReferenceGenerator::CONTRACT));
        $this->em->persist($contract);

        if ($this->tenderWorkflow->can($tender, 'award')) {
            $this->tenderWorkflow->apply($tender, 'award', ['comment' => \sprintf('Attribué à %s.', $proposal->getSupplier()?->getName())]);
        }

        foreach ($tender->getProposals() as $other) {
            if ($other !== $proposal && $this->proposalWorkflow->can($other, 'reject')) {
                $this->proposalWorkflow->apply($other, 'reject', ['comment' => 'Une autre proposition a été retenue pour ce besoin.']);
            }
        }
    }

    public function onContractSignedByBuyer(CompletedEvent $event): void
    {
        /** @var Contract $contract */
        $contract = $event->getSubject();
        $contract->signAsBuyer($this->requireActor());
    }

    public function onContractSignedBySupplier(CompletedEvent $event): void
    {
        /** @var Contract $contract */
        $contract = $event->getSubject();
        $contract->signAsSupplier($this->requireActor());
    }

    public function onInvoiceSubmitted(CompletedEvent $event): void
    {
        /** @var Invoice $invoice */
        $invoice = $event->getSubject();
        $invoice->markSubmitted();
    }

    public function onInvoiceApproved(CompletedEvent $event): void
    {
        /** @var Invoice $invoice */
        $invoice = $event->getSubject();
        $invoice->markApproved($this->requireActor());
    }

    public function onInvoicePaid(CompletedEvent $event): void
    {
        /** @var Invoice $invoice */
        $invoice = $event->getSubject();
        $invoice->markPaid();
    }

    private function actor(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    private function requireActor(): User
    {
        return $this->actor() ?? throw new \LogicException('This transition requires an authenticated user.');
    }
}
