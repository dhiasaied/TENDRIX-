<?php

namespace App\Service;

use App\Entity\DocumentSequence;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issues human-readable sequential references such as AO-2026-0042.
 * The sequence row is locked (SELECT ... FOR UPDATE) so concurrent requests never get the same number.
 */
final class ReferenceGenerator
{
    public const TENDER = 'AO';
    public const CONTRACT = 'CT';
    public const INVOICE = 'FAC';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function next(string $prefix): string
    {
        $year = (int) date('Y');

        return $this->em->wrapInTransaction(function () use ($prefix, $year): string {
            $sequence = $this->em->getRepository(DocumentSequence::class)
                ->createQueryBuilder('s')
                ->where('s.prefix = :prefix AND s.year = :year')
                ->setParameters(new \Doctrine\Common\Collections\ArrayCollection([
                    new \Doctrine\ORM\Query\Parameter('prefix', $prefix),
                    new \Doctrine\ORM\Query\Parameter('year', $year),
                ]))
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getOneOrNullResult();

            if (!$sequence instanceof DocumentSequence) {
                $sequence = new DocumentSequence($prefix, $year);
                $this->em->persist($sequence);
            }
            $value = $sequence->next();
            $this->em->flush();

            return \sprintf('%s-%d-%04d', $prefix, $year, $value);
        });
    }
}
