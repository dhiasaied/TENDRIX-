<?php

namespace App\MessageHandler;

use App\Ai\MatchingAgent;
use App\Entity\AuditLog;
use App\Entity\Proposal;
use App\Entity\User;
use App\Message\ProposalSubmitted;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the AI matching agent on a new/edited proposal, then alerts the buyer side.
 * Executed by the async worker so a slow LLM call never blocks the supplier's submission.
 */
#[AsMessageHandler]
final class ScoreProposal
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MatchingAgent $matcher,
        private readonly Notifier $notifier,
    ) {
    }

    public function __invoke(ProposalSubmitted $message): void
    {
        $proposal = $this->em->find(Proposal::class, $message->proposalId);
        if (!$proposal || !$proposal->getTender()) {
            return;
        }

        $result = $this->matcher->evaluate($proposal);
        $proposal->setMatchScore($result->score)
            ->setMatchBreakdown($result->breakdown)
            ->setMatchAnalysis($result->analysis);

        $tender = $proposal->getTender();
        $this->em->persist(new AuditLog($proposal->getSubmittedBy()?->getEmail(), $message->isUpdate ? 'proposal.updated' : 'proposal.submitted', 'proposal', (int) $proposal->getId(), ['matchScore' => $result->score, 'source' => $result->source]));

        if (!$message->isUpdate) {
            $text = \sprintf('%s propose %s (%s) — adéquation %d %%.', $proposal->getSupplier()?->getName(), $proposal->getCandidateName(), $proposal->getCandidateTitle(), $result->score);
            $link = '/proposals/'.$proposal->getId();
            $this->notifier->notifyUser($tender->getCreatedBy(), 'proposal.received', 'Nouvelle proposition sur '.$tender->getReference(), $text, $link);
            $this->notifier->notifyOrganization($tender->getOrganization(), [User::ROLE_BUYER], 'proposal.received', 'Nouvelle proposition sur '.$tender->getReference(), $text, $link, $tender->getCreatedBy()?->getId());
        }

        $this->em->flush();
    }
}
