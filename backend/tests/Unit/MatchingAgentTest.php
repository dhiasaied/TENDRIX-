<?php

namespace App\Tests\Unit;

use App\Ai\LlmClient;
use App\Ai\MatchingAgent;
use App\Ai\SkillMatcher;
use App\Entity\Proposal;
use App\Entity\Tender;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MatchingAgentTest extends TestCase
{
    private MatchingAgent $agent;

    protected function setUp(): void
    {
        $this->agent = new MatchingAgent(new LlmClient(null, 'claude-opus-5-5', new NullLogger()));
    }

    public function testSkillNormalizationHandlesVersionsAndAliases(): void
    {
        $result = SkillMatcher::compare(['Symfony', 'JavaScript', 'Kubernetes', 'Rust'], ['symfony 7', 'JS', 'k8s']);

        self::assertSame(['Symfony', 'JavaScript', 'Kubernetes'], $result['matched']);
        self::assertSame(['Rust'], $result['missing']);
        self::assertEqualsWithDelta(0.75, $result['ratio'], 0.001);
    }

    public function testPerfectProfileScoresHundred(): void
    {
        $result = $this->agent->evaluate($this->proposal(['PHP', 'Symfony'], years: 7, rate: 600, availableDaysAfterStart: 0));

        self::assertSame(100, $result->score);
        self::assertSame('heuristic', $result->source);
    }

    public function testOverBudgetAndLateProfileIsPenalized(): void
    {
        // 15% over budget -> half of the rate points; 4 weeks late -> half of availability points.
        $result = $this->agent->evaluate($this->proposal(['PHP'], years: 3, rate: 690, availableDaysAfterStart: 28));

        self::assertSame(23, $result->breakdown['skills']); // 1 of 2 skills
        self::assertSame(10, $result->breakdown['experience']); // 3 of 6 expected years
        self::assertSame(10, $result->breakdown['rate']);
        self::assertSame(8, $result->breakdown['availability']);
        self::assertSame(51, $result->score);
        self::assertStringContainsString('au-dessus du budget', $result->analysis);
    }

    public function testCandidateDataIsPseudonymisedBeforeReachingTheLlm(): void
    {
        $cv = 'Sofiane Ben Ali, lead dev. Contact : sofiane.benali@mail.test ou +33 6 12 34 56 78, https://linkedin.example/in/sbenali. Sofiane a dirigé 3 projets Symfony.';
        $clean = MatchingAgent::pseudonymise($cv, 'Sofiane Ben Ali');

        self::assertStringNotContainsStringIgnoringCase('sofiane', $clean);
        self::assertStringNotContainsString('Ben Ali', $clean);
        self::assertStringNotContainsString('@', $clean);
        self::assertStringNotContainsString('12 34', $clean);
        self::assertStringNotContainsString('linkedin', $clean);
        self::assertStringContainsString('3 projets Symfony', $clean);
    }

    /** @param list<string> $skills */
    private function proposal(array $skills, int $years, int $rate, int $availableDaysAfterStart): Proposal
    {
        $start = new \DateTimeImmutable('2027-01-04');
        $tender = (new Tender())->setTitle('Dev Symfony')->setDescription(str_repeat('Contexte du besoin. ', 5))
            ->setSkills(['PHP', 'Symfony'])->setSeniority('senior')->setBudgetMax(600)->setStartDate($start);

        return (new Proposal())->setTender($tender)->setCandidateName('Test')->setCandidateTitle('Dev')
            ->setCandidateSkills($skills)->setExperienceYears($years)->setDailyRate($rate)
            ->setAvailableFrom($start->modify("+$availableDaysAfterStart days"))->setCandidateSummary(str_repeat('x', 60));
    }
}
