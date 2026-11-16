<?php

namespace App\Ai;

/**
 * Normalizes and compares free-text skill labels ("Symfony 7", "symfony", "PHP8" ...).
 */
final class SkillMatcher
{
    private const ALIASES = [
        'js' => 'javascript',
        'ts' => 'typescript',
        'k8s' => 'kubernetes',
        'postgres' => 'postgresql',
        'gcp' => 'google cloud',
        'ml' => 'machine learning',
        'ia' => 'ai',
        'llm' => 'ai',
        'angularjs' => 'angular',
        'reactjs' => 'react',
        'node' => 'nodejs',
        'node.js' => 'nodejs',
    ];

    public static function normalize(string $skill): string
    {
        $s = mb_strtolower(trim($skill));
        $s = preg_replace('/\s*\d+(\.\d+)*\+?$/', '', $s) ?? $s; // drop trailing versions: "symfony 7" -> "symfony"
        $s = preg_replace('/^(php)\d+$/', '$1', $s) ?? $s;

        return self::ALIASES[$s] ?? $s;
    }

    /**
     * @param list<string> $required
     * @param list<string> $offered
     *
     * @return array{matched: list<string>, missing: list<string>, ratio: float}
     */
    public static function compare(array $required, array $offered): array
    {
        $offeredNorm = array_map(self::normalize(...), $offered);
        $matched = [];
        $missing = [];

        foreach ($required as $skill) {
            $needle = self::normalize($skill);
            $found = false;
            foreach ($offeredNorm as $candidate) {
                if ($candidate === $needle || ('' !== $candidate && (str_contains($candidate, $needle) || str_contains($needle, $candidate)) && min(\strlen($candidate), \strlen($needle)) >= 3)) {
                    $found = true;
                    break;
                }
            }
            $found ? $matched[] = $skill : $missing[] = $skill;
        }

        return [
            'matched' => $matched,
            'missing' => $missing,
            'ratio' => [] === $required ? 1.0 : \count($matched) / \count($required),
        ];
    }
}
