<?php

namespace App\Ai;

/**
 * Drafts a structured call for tenders from a short brief written by the requester.
 */
class TenderWriterAgent
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string', 'description' => 'Full tender text in French, plain text with section headings.'],
            'skills' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '3 to 10 key skills.'],
            'seniority' => ['type' => 'string', 'enum' => ['junior', 'confirmed', 'senior', 'expert']],
            'estimatedDays' => ['type' => 'integer'],
        ],
        'required' => ['title', 'description', 'skills', 'seniority', 'estimatedDays'],
        'additionalProperties' => false,
    ];

    public function __construct(private readonly LlmClient $llm)
    {
    }

    /**
     * @param list<string> $skills
     *
     * @return array{title: string, description: string, skills: list<string>, seniority: string, estimatedDays: int, source: string}
     */
    public function draft(string $title, string $context, array $skills, string $seniority, int $estimatedDays): array
    {
        $llm = $this->llm->json(
            system: 'Tu es un acheteur expert en prestations intellectuelles IT. Tu rédiges des appels d\'offres clairs, structurés et non discriminatoires, '
                .'avec les sections : Contexte, Missions, Livrables attendus, Profil recherché, Modalités. N\'invente pas de nom d\'entreprise.',
            prompt: \sprintf(
                "Rédige un appel d'offres à partir de ce besoin.\nIntitulé : %s\nSéniorité souhaitée : %s\nCompétences pressenties : %s\nDurée estimée : %d jours\nContexte fourni par le demandeur :\n%s",
                $title, $seniority, implode(', ', $skills) ?: 'à définir', $estimatedDays, $context,
            ),
            schema: self::SCHEMA,
            maxTokens: 6000,
        );

        if (null !== $llm) {
            return [
                'title' => (string) $llm['title'],
                'description' => (string) $llm['description'],
                'skills' => array_values(array_map('strval', (array) $llm['skills'])),
                'seniority' => (string) $llm['seniority'],
                'estimatedDays' => max(1, (int) $llm['estimatedDays']),
                'source' => 'llm',
            ];
        }

        return [
            'title' => $title,
            'description' => $this->template($title, $context, $skills, $seniority, $estimatedDays),
            'skills' => $skills,
            'seniority' => $seniority,
            'estimatedDays' => $estimatedDays,
            'source' => 'template',
        ];
    }

    /** @param list<string> $skills */
    private function template(string $title, string $context, array $skills, string $seniority, int $days): string
    {
        $levels = ['junior' => 'junior (0 à 2 ans)', 'confirmed' => 'confirmé (3 à 5 ans)', 'senior' => 'senior (6 à 9 ans)', 'expert' => 'expert (10 ans et plus)'];
        $skillList = [] === $skills ? '- À préciser' : '- '.implode("\n- ", $skills);

        return <<<TXT
            CONTEXTE
            {$context}

            MISSIONS
            Dans le cadre du projet « {$title} », le consultant aura pour missions :
            - Analyser le besoin et contribuer aux choix de conception ;
            - Réaliser les développements / travaux confiés dans le respect des standards de qualité ;
            - Documenter les livrables et assurer le transfert de compétences ;
            - Participer aux rituels de l'équipe (méthodologie agile).

            LIVRABLES ATTENDUS
            - Livrables techniques ou fonctionnels conformes aux spécifications ;
            - Documentation associée ;
            - Comptes rendus d'activité mensuels.

            PROFIL RECHERCHÉ
            Niveau : {$levels[$seniority]}
            Compétences clés :
            {$skillList}

            MODALITÉS
            Charge estimée : {$days} jours ouvrés.
            Les propositions doivent préciser le TJM, la date de disponibilité et un CV détaillé du consultant.
            TXT;
    }
}
