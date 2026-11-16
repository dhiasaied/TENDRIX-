<?php

namespace App\Tests\Unit;

use App\Entity\Contract;
use App\Entity\Invoice;
use App\Entity\Organization;
use App\Entity\Proposal;
use App\Entity\Tender;
use PHPUnit\Framework\TestCase;

final class ContractAndInvoiceTest extends TestCase
{
    public function testContractIsDerivedFromAcceptedProposal(): void
    {
        $contract = $this->contract();

        self::assertSame('CT-2026-0001', $contract->getReference());
        self::assertSame(650, $contract->getDailyRate());
        self::assertSame(40, $contract->getPlannedDays());
        self::assertSame(26000, $contract->getTotalValue());
        // Starts when the consultant is available (after the tender start), 56 calendar days for 40 working days.
        self::assertSame('2027-01-11', $contract->getStartDate()->format('Y-m-d'));
        self::assertSame('2027-03-08', $contract->getEndDate()->format('Y-m-d'));
    }

    public function testInvoiceAmountsAndConsumption(): void
    {
        $contract = $this->contract();
        $invoice = (new Invoice())->setContract($contract)->setPeriod('2027-01')->setDaysWorked(12.5);
        $invoice->computeAmounts();
        $contract->getInvoices()->add($invoice);

        self::assertSame(812500, $invoice->getAmountExclTaxCents());
        self::assertSame(975000, $invoice->getAmountInclTaxCents());
        self::assertSame(0.0, $contract->getInvoicedDays(), 'Drafts do not consume the contract.');

        $invoice->setStatus(Invoice::STATUS_SUBMITTED);
        self::assertSame(12.5, $contract->getInvoicedDays());
        self::assertSame(27.5, $contract->getRemainingDays());
        self::assertSame(31.3, $contract->getConsumptionRate());
    }

    private function contract(): Contract
    {
        $tender = (new Tender())->setTitle('Mission')->setEstimatedDays(40)->setStartDate(new \DateTimeImmutable('2027-01-04'))
            ->setOrganization(new Organization('Buyer'));
        $proposal = (new Proposal())->setTender($tender)->setSupplier(new Organization('Supplier', Organization::TYPE_SUPPLIER))
            ->setCandidateName('Jane Doe')->setDailyRate(650)->setAvailableFrom(new \DateTimeImmutable('2027-01-11'));

        return Contract::fromAcceptedProposal($proposal, 'CT-2026-0001');
    }
}
