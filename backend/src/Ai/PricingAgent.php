<?php

namespace App\Ai;

use App\Entity\Contract;
use App\Entity\Proposal;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Juste prix" assistant: estimates a fair daily rate (TJM) for a profile from the platform's
 * anonymized market data (signed contracts first, then proposals), with a reference grid as
 * a cold-start fallback. Claude, when configured, explains the recommendation.
 */
class PricingAgent
{
    /** Reference daily rates (EUR excl. VAT, Île-de-France market) used when data is scarce. */
    private const REFERENCE_GRID = ['junior' => 420, 'confirmed' => 550, 'senior' => 680, 'expert' => 850];

    /** Skills commanding a market premium. */
    private const PREMIUM_SKILLS = ['ai' => 0.12, 'machine learning' => 0.12, 'kubernetes' => 0.08, 'sap' => 0.15, 'cybersecurity' => 0.12, 'security' => 0.08, 'aws' => 0.06, 'azure' => 0.06, 'data' => 0.05, 'go' => 0.05, 'rust' => 0.08];

    private const MIN_SAMPLE = 3;

    private const SCHEMA = [
        'type' => 'object',
        'properties' => ['rationale' => ['type' => 'string', 'description' => 'Short explanation (2-4 sentences, French) of the recommended range.']],
        'required' => ['rationale'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LlmClient $llm,
    ) {
    }

    /**
     * @param list<string> $skills
     */
    public function suggest(array $skills, string $seniority): PriceSuggestion
    {
        $rates = $this->marketRates(Contract::class, $skills, $seniority);
        $basis = 'contracts';
        if (\count($rates) < self::MIN_SAMPLE) {
            $rates = array_merge($rates, $this->marketRates(Proposal::class, $skills, $seniority));
            $basis = 'contracts+proposals';
        }

        if (\count($rates) >= self::MIN_SAMPLE) {
            sort($rates);
            $suggestion = new PriceSuggestion(
                low: (int) round($this->percentile($rates, 25)),
                median: (int) round($this->percentile($rates, 50)),
                high: (int) round($this->percentile($rates, 75)),
                sampleSize: \count($rates),
                basis: $basis,
                rationale: \sprintf('Estimation basée sur %d tarif(s) observé(s) sur la plateforme pour des profils %s comparables.', \count($rates), $seniority),
            );
        } else {
            $base = self::REFERENCE_GRID[$seniority] ?? self::REFERENCE_GRID['confirmed'];
            $premium = 0.0;
            foreach ($skills as $skill) {
                $premium = max($premium, self::PREMIUM_SKILLS[SkillMatcher::normalize($skill)] ?? 0.0);
            }
            $median = (int) (round($base * (1 + $premium) / 10) * 10);
            $suggestion = new PriceSuggestion(
                low: (int) (round($median * 0.88 / 10) * 10),
                median: $median,
                high: (int) (round($median * 1.15 / 10) * 10),
                sampleSize: \count($rates),
                basis: 'reference_grid',
                rationale: \sprintf('Données de marché insuffisantes : estimation issue de la grille de référence pour un profil %s%s.', $seniority, $premium > 0 ? ', avec une prime pour les compétences rares' : ''),
            );
        }

        $llm = $this->llm->json(
            system: 'Tu es un expert des achats de prestations intellectuelles IT en France. Tu expliques les fourchettes de TJM de manière factuelle et concise.',
            prompt: \sprintf(
                "Profil : %s, compétences : %s.\nFourchette calculée : %d € – %d € (médiane %d €), base : %s, échantillon : %d.\nExplique cette recommandation à un acheteur.",
                $seniority, implode(', ', $skills), $suggestion->low, $suggestion->high, $suggestion->median, $suggestion->basis, $suggestion->sampleSize,
            ),
            schema: self::SCHEMA,
            maxTokens: 1000,
        );

        return null === $llm ? $suggestion : $suggestion->withRationale((string) $llm['rationale'], 'llm');
    }

    /**
     * @param class-string<Contract|Proposal> $class
     * @param list<string>                    $skills
     *
     * @return list<int>
     */
    private function marketRates(string $class, array $skills, string $seniority): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('e.dailyRate AS rate', 't.skills AS skills')
            ->from($class, 'e')
            ->innerJoin('e.tender', 't')
            ->where('t.seniority = :seniority')
            ->setParameter('seniority', $seniority)
            ->setMaxResults(500);
        if (Proposal::class === $class) {
            // Accepted proposals are already counted through their contract.
            $qb->andWhere('e.status NOT IN (:excluded)')->setParameter('excluded', [Proposal::STATUS_ACCEPTED, Proposal::STATUS_WITHDRAWN]);
        }
        $rows = $qb->getQuery()->getArrayResult();

        $rates = [];
        foreach ($rows as $row) {
            if (SkillMatcher::compare($skills, (array) $row['skills'])['ratio'] >= 0.5) {
                $rates[] = (int) $row['rate'];
            }
        }

        return $rates;
    }

    /** @param list<int> $sorted */
    private function percentile(array $sorted, int $p): float
    {
        $index = ($p / 100) * (\count($sorted) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($index - $lower);
    }
}
