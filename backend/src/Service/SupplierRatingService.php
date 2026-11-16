<?php

namespace App\Service;

use App\Entity\Organization;
use App\Entity\SupplierEvaluation;
use Doctrine\ORM\EntityManagerInterface;

final class SupplierRatingService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Recomputes the supplier's denormalized average rating from all its evaluations. */
    public function refresh(?Organization $supplier): void
    {
        if (null === $supplier) {
            return;
        }

        $avg = $this->em->createQueryBuilder()
            ->select('AVG((e.quality + e.reactivity + e.communication + e.deadlines) / 4.0)')
            ->from(SupplierEvaluation::class, 'e')
            ->where('e.supplier = :supplier')
            ->setParameter('supplier', $supplier)
            ->getQuery()
            ->getSingleScalarResult();

        $supplier->setAverageRating(null === $avg ? null : (float) $avg);
        $this->em->flush();
    }
}
