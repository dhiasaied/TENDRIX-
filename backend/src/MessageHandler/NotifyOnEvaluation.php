<?php

namespace App\MessageHandler;

use App\Entity\SupplierEvaluation;
use App\Entity\User;
use App\Message\EvaluationSubmitted;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class NotifyOnEvaluation
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Notifier $notifier,
    ) {
    }

    public function __invoke(EvaluationSubmitted $message): void
    {
        $evaluation = $this->em->find(SupplierEvaluation::class, $message->evaluationId);
        if (!$evaluation) {
            return;
        }

        $this->notifier->notifyOrganization(
            $evaluation->getSupplier(),
            [User::ROLE_SUPPLIER],
            'evaluation.received',
            'Nouvelle évaluation',
            \sprintf('Votre prestation sur %s a été évaluée %s/5.', $evaluation->getContract()?->getReference(), $evaluation->getOverallScore()),
            '/contracts/'.$evaluation->getContract()?->getId(),
        );
        $this->em->flush();
    }
}
