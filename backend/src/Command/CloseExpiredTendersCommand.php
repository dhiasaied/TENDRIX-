<?php

namespace App\Command;

use App\Entity\Tender;
use App\Message\WorkflowTransitioned;
use App\Service\DomainEventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Closes published tenders whose response deadline has passed. Run every few minutes (cron /
 * scheduler container). This is a system action, so it bypasses user-role guards on purpose.
 */
#[AsCommand(name: 'app:tenders:close-expired', description: 'Close published tenders whose response deadline has passed.')]
final class CloseExpiredTendersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DomainEventRecorder $events,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<Tender> $expired */
        $expired = $this->em->getRepository(Tender::class)->createQueryBuilder('t')
            ->where('t.status = :published AND t.deadline <= :now')
            ->setParameter('published', Tender::STATUS_PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()->getResult();

        foreach ($expired as $tender) {
            $tender->setStatus(Tender::STATUS_CLOSED);
            $tender->setStatusComment('Clôture automatique à l\'échéance.');
            $id = (int) $tender->getId();
            $this->events->record(static fn () => new WorkflowTransitioned('tender', $id, 'close', Tender::STATUS_PUBLISHED, Tender::STATUS_CLOSED, null, 'Clôture automatique à l\'échéance.'));
        }
        $this->em->flush();

        $io->success(\sprintf('%d tender(s) closed.', \count($expired)));

        return Command::SUCCESS;
    }
}
