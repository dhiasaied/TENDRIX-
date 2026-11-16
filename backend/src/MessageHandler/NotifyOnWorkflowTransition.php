<?php

namespace App\MessageHandler;

use App\Ai\SkillMatcher;
use App\Entity\Contract;
use App\Entity\Invoice;
use App\Entity\Organization;
use App\Entity\Proposal;
use App\Entity\Tender;
use App\Entity\User;
use App\Message\WorkflowTransitioned;
use App\Repository\OrganizationRepository;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Turns lifecycle events into notifications for the right people on each side of the deal.
 */
#[AsMessageHandler]
final class NotifyOnWorkflowTransition
{
    private const BUYERS = [User::ROLE_BUYER];
    private const SUPPLIERS = [User::ROLE_SUPPLIER];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Notifier $notifier,
        private readonly OrganizationRepository $organizations,
    ) {
    }

    public function __invoke(WorkflowTransitioned $event): void
    {
        match ($event->workflow) {
            'tender' => $this->onTender($event),
            'proposal' => $this->onProposal($event),
            'contract' => $this->onContract($event),
            'invoice' => $this->onInvoice($event),
            default => null,
        };
        $this->em->flush();
    }

    private function onTender(WorkflowTransitioned $e): void
    {
        $tender = $this->em->find(Tender::class, $e->subjectId);
        if (!$tender) {
            return;
        }
        $link = '/tenders/'.$tender->getId();
        $label = \sprintf('%s « %s »', $tender->getReference(), $tender->getTitle());

        switch ($e->transition) {
            case 'submit':
                $this->notifier->notifyOrganization($tender->getOrganization(), self::BUYERS, 'tender.approval_requested', 'Appel d\'offres à valider', "$label attend votre validation.", $link, $e->actorId);
                break;
            case 'publish':
                $this->notifier->notifyUser($tender->getCreatedBy(), 'tender.published', 'Appel d\'offres publié', "$label est désormais visible par les fournisseurs.", $link);
                foreach ($this->targetSuppliers($tender) as $supplier) {
                    $this->notifier->notifyOrganization($supplier, self::SUPPLIERS, 'tender.opportunity', 'Nouvelle opportunité', \sprintf('%s — réponse attendue avant le %s.', $label, $tender->getDeadline()?->format('d/m/Y')), $link);
                }
                break;
            case 'reject':
                $this->notifier->notifyUser($tender->getCreatedBy(), 'tender.rejected', 'Appel d\'offres renvoyé en brouillon', $label.($e->comment ? " : $e->comment" : '.'), $link);
                break;
            case 'close':
            case 'cancel':
                $verb = 'close' === $e->transition ? 'clôturé' : 'annulé';
                foreach ($this->respondingSuppliers($tender) as $supplier) {
                    $this->notifier->notifyOrganization($supplier, self::SUPPLIERS, 'tender.'.$e->transition, "Appel d'offres $verb", "$label a été $verb.", $link);
                }
                break;
            case 'award':
                $this->notifier->notifyUser($tender->getCreatedBy(), 'tender.awarded', 'Appel d\'offres attribué', "$label a été attribué.", $link);
                break;
        }
    }

    private function onProposal(WorkflowTransitioned $e): void
    {
        $proposal = $this->em->find(Proposal::class, $e->subjectId);
        $tender = $proposal?->getTender();
        if (!$proposal || !$tender) {
            return;
        }
        $link = '/proposals/'.$proposal->getId();
        $label = \sprintf('Votre proposition de %s pour %s', $proposal->getCandidateName(), $tender->getReference());

        match ($e->transition) {
            'shortlist' => $this->notifier->notifyOrganization($proposal->getSupplier(), self::SUPPLIERS, 'proposal.shortlisted', 'Proposition présélectionnée', "$label a été présélectionnée.", $link),
            'reject' => $this->notifier->notifyOrganization($proposal->getSupplier(), self::SUPPLIERS, 'proposal.rejected', 'Proposition non retenue', $label.' n\'a pas été retenue.'.($e->comment ? " Motif : $e->comment" : ''), $link),
            'accept' => $this->notifier->notifyOrganization($proposal->getSupplier(), self::SUPPLIERS, 'proposal.accepted', 'Proposition retenue', "$label a été retenue. Le contrat est en cours de signature.", '/contracts'),
            'withdraw' => $this->notifier->notifyUser($tender->getCreatedBy(), 'proposal.withdrawn', 'Proposition retirée', \sprintf('%s a retiré la candidature de %s sur %s.', $proposal->getSupplier()?->getName(), $proposal->getCandidateName(), $tender->getReference()), '/tenders/'.$tender->getId()),
            default => null,
        };
    }

    private function onContract(WorkflowTransitioned $e): void
    {
        $contract = $this->em->find(Contract::class, $e->subjectId);
        if (!$contract) {
            return;
        }
        $link = '/contracts/'.$contract->getId();
        $label = \sprintf('Le contrat %s (%s)', $contract->getReference(), $contract->getConsultantName());

        match ($e->transition) {
            'sign_buyer' => $this->notifier->notifyOrganization($contract->getSupplier(), self::SUPPLIERS, 'contract.signature_requested', 'Contrat à signer', "$label a été signé par le client et attend votre signature.", $link),
            'sign_supplier' => $this->notifier->notifyOrganization($contract->getBuyer(), self::BUYERS, 'contract.activated', 'Contrat actif', "$label est signé par les deux parties.", $link),
            'complete' => $this->notifier->notifyOrganization($contract->getSupplier(), self::SUPPLIERS, 'contract.completed', 'Contrat terminé', "$label est arrivé à son terme.", $link),
            'terminate' => $this->notifier->notifyOrganization($contract->getSupplier(), self::SUPPLIERS, 'contract.terminated', 'Contrat résilié', $label.' a été résilié.'.($e->comment ? " Motif : $e->comment" : ''), $link),
            default => null,
        };
    }

    private function onInvoice(WorkflowTransitioned $e): void
    {
        $invoice = $this->em->find(Invoice::class, $e->subjectId);
        if (!$invoice) {
            return;
        }
        $link = '/invoices/'.$invoice->getId();
        $amount = number_format($invoice->getAmountInclTax(), 2, ',', ' ').' € TTC';
        $label = \sprintf('La facture %s (%s)', $invoice->getNumber(), $amount);

        match ($e->transition) {
            'submit' => $this->notifier->notifyOrganization($invoice->getBuyer(), self::BUYERS, 'invoice.submitted', 'Facture à valider', \sprintf('%s de %s attend votre validation.', $label, $invoice->getSupplier()?->getName()), $link),
            'approve' => $this->notifier->notifyOrganization($invoice->getSupplier(), self::SUPPLIERS, 'invoice.approved', 'Facture validée', "$label a été validée.", $link),
            'reject' => $this->notifier->notifyOrganization($invoice->getSupplier(), self::SUPPLIERS, 'invoice.rejected', 'Facture refusée', $label.' a été refusée.'.($e->comment ? " Motif : $e->comment" : ''), $link),
            'pay' => $this->notifier->notifyOrganization($invoice->getSupplier(), self::SUPPLIERS, 'invoice.paid', 'Facture payée', "$label a été réglée.", $link),
            default => null,
        };
    }

    /**
     * Restricted consultation: the invited suppliers. Open consultation: suppliers whose declared
     * expertise overlaps the tender's skills.
     *
     * @return iterable<Organization>
     */
    private function targetSuppliers(Tender $tender): iterable
    {
        if (!$tender->getInvitedSuppliers()->isEmpty()) {
            return $tender->getInvitedSuppliers();
        }

        $suppliers = $this->organizations->findBy(['type' => Organization::TYPE_SUPPLIER, 'active' => true]);

        return array_filter($suppliers, static fn (Organization $s) => [] === $s->getSkills()
            || SkillMatcher::compare($tender->getSkills(), $s->getSkills())['matched'] !== []);
    }

    /** @return list<Organization> */
    private function respondingSuppliers(Tender $tender): array
    {
        $suppliers = [];
        foreach ($tender->getProposals() as $proposal) {
            if (Proposal::STATUS_WITHDRAWN !== $proposal->getStatus() && $proposal->getSupplier()) {
                $suppliers[$proposal->getSupplier()->getId()] = $proposal->getSupplier();
            }
        }

        return array_values($suppliers);
    }
}
