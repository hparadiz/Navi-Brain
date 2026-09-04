<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use NaviBrain\Support\PlainText;

/**
 * Rank live drives and commitments without declaring what Navi should want.
 *
 * Needs, intentions, and interrupts remain separate evidence sources. The
 * compiler is read-only, uncapped, and not connected to action selection.
 */
final class MotivationCompiler
{
    private const PROTOCOL = 'motivation-evidence-v1';

    public function __construct(private readonly ExecutiveCore $core)
    {
    }

    /** @return array<string, mixed> */
    public function compile(string $context): array
    {
        $context = trim(PlainText::sanitize($context));
        $actions = $this->core->listActions();
        $actionCounts = [];
        $pendingActions = [];
        foreach ($actions as $action) {
            $intentionId = (int) ($action['intention_id'] ?? 0);
            $actionCounts[$intentionId] = ($actionCounts[$intentionId] ?? 0) + 1;
            if (($action['status'] ?? null) === 'pending') {
                $pendingActions[$intentionId] = ($pendingActions[$intentionId] ?? 0) + 1;
            }
        }
        $maxActionCount = max([1, ...array_values($actionCounts)]);

        $candidates = [
            ...$this->needCandidates(),
            ...$this->intentionCandidates($actionCounts, $pendingActions, $maxActionCount),
            ...$this->interruptCandidates(),
        ];
        $ranked = $this->rankCandidates($candidates, $context);

        return [
            'protocol' => self::PROTOCOL,
            'mode' => [
                'compiler_writes' => false,
                'action_selection_connected' => false,
                'result_count_limited' => false,
            ],
            'context' => $context,
            'ranked_motives' => $ranked,
            'affective_state' => $this->core->appraiseNow(time())->toArray(),
            'authority_constraints' => array_values(array_filter(
                $this->core->listSelfModelFacts(),
                static fn (array $fact): bool => str_starts_with(
                    (string) ($fact['fact_key'] ?? ''),
                    'continuity.'
                )
            )),
            'evidence_health' => [
                'motive_count' => count($ranked),
                'active_need_count' => count(array_filter(
                    $ranked,
                    static fn (array $motive): bool => ($motive['motive_type'] ?? null) === 'need'
                )),
                'open_intention_count' => count(array_filter(
                    $ranked,
                    static fn (array $motive): bool => ($motive['motive_type'] ?? null) === 'intention'
                )),
                'pending_interrupt_count' => count(array_filter(
                    $ranked,
                    static fn (array $motive): bool => ($motive['motive_type'] ?? null) === 'interrupt'
                )),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function needCandidates(): array
    {
        $candidates = [];
        foreach ($this->core->listNeeds() as $need) {
            if (($need['status'] ?? null) !== 'active') {
                continue;
            }
            $threshold = max(0.001, (float) ($need['trigger_threshold'] ?? 1.0));
            $drive = max(0.0, min(1.0, (float) ($need['pressure'] ?? 0.0) / $threshold));
            $candidates[] = [
                'motive_id' => 'need:' . (string) ($need['need_key'] ?? ''),
                'motive_type' => 'need',
                'title' => (string) ($need['need_key'] ?? ''),
                'content' => (string) ($need['description'] ?? ''),
                'authority' => (string) ($need['authority'] ?? 'agent'),
                'drive' => $drive,
                'investment' => 0.0,
                'actionability' => ($need['triggered'] ?? false) === true ? 1.0 : $drive,
                'updated_at' => $this->timestamp($need['last_satisfied_at'] ?? null),
                'evidence' => [
                    'pressure' => (float) ($need['pressure'] ?? 0.0),
                    'trigger_threshold' => $threshold,
                    'triggered' => (bool) ($need['triggered'] ?? false),
                    'last_satisfied_at' => $need['last_satisfied_at'] ?? null,
                ],
            ];
        }
        return $candidates;
    }

    /**
     * @param array<int, int> $actionCounts
     * @param array<int, int> $pendingActions
     * @return list<array<string, mixed>>
     */
    private function intentionCandidates(
        array $actionCounts,
        array $pendingActions,
        int $maxActionCount
    ): array {
        $candidates = [];
        foreach ($this->core->listIntentions() as $intention) {
            $status = (string) ($intention['status'] ?? '');
            if (!in_array($status, ['active', 'blocked'], true)) {
                continue;
            }
            $id = (int) ($intention['id'] ?? 0);
            $actionCount = $actionCounts[$id] ?? 0;
            $pendingCount = $pendingActions[$id] ?? 0;
            $investment = $actionCount === 0
                ? 0.0
                : log(1 + $actionCount) / log(1 + $maxActionCount);
            $content = implode(' ', array_filter([
                (string) ($intention['title'] ?? ''),
                (string) ($intention['reason'] ?? ''),
                (string) ($intention['next_action'] ?? ''),
                (string) ($intention['success_condition'] ?? ''),
            ]));
            $candidates[] = [
                'motive_id' => 'intention:' . $id,
                'motive_type' => 'intention',
                'title' => (string) ($intention['title'] ?? ''),
                'content' => $content,
                'authority' => (string) ($intention['authority'] ?? 'agent'),
                'drive' => $status === 'active' ? 1.0 : 0.55,
                'investment' => $investment,
                'actionability' => $pendingCount > 0
                    ? 1.0
                    : ((string) ($intention['next_action'] ?? '') === '' ? 0.0 : 0.7),
                'updated_at' => $this->timestamp($intention['updated_at'] ?? null),
                'evidence' => [
                    'id' => $id,
                    'status' => $status,
                    'reason' => $intention['reason'] ?? null,
                    'next_action' => $intention['next_action'] ?? null,
                    'success_condition' => $intention['success_condition'] ?? null,
                    'release_condition' => $intention['release_condition'] ?? null,
                    'action_count' => $actionCount,
                    'pending_action_count' => $pendingCount,
                    'dependencies' => $intention['dependencies'] ?? [],
                ],
            ];
        }
        return $candidates;
    }

    /** @return list<array<string, mixed>> */
    private function interruptCandidates(): array
    {
        $candidates = [];
        foreach ($this->core->listInterrupts('pending') as $interrupt) {
            $severity = (string) ($interrupt['severity'] ?? 'normal');
            $drive = match ($severity) {
                'critical' => 1.0,
                'high' => 0.8,
                default => 0.55,
            };
            $id = (int) ($interrupt['id'] ?? 0);
            $candidates[] = [
                'motive_id' => 'interrupt:' . $id,
                'motive_type' => 'interrupt',
                'title' => $severity . ' interrupt',
                'content' => (string) ($interrupt['reason'] ?? ''),
                'authority' => 'system',
                'drive' => $drive,
                'investment' => 0.0,
                'actionability' => 1.0,
                'updated_at' => $this->timestamp($interrupt['updated_at'] ?? $interrupt['created_at'] ?? null),
                'evidence' => $interrupt,
            ];
        }
        return $candidates;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function rankCandidates(array $candidates, string $context): array
    {
        if ($candidates === []) {
            return [];
        }
        $contextTokens = $this->tokenSet($context);
        $timestamps = array_map(static fn (array $candidate): int => (int) $candidate['updated_at'], $candidates);
        $minimumTimestamp = min($timestamps);
        $maximumTimestamp = max($timestamps);
        $ranked = [];

        foreach ($candidates as $candidate) {
            $candidateTokens = $this->tokenSet(
                (string) $candidate['title'] . ' ' . (string) $candidate['content']
            );
            $matched = array_keys(array_intersect_key($contextTokens, $candidateTokens));
            sort($matched);
            $contextWeight = $contextTokens === []
                ? 1.0
                : count($matched) / count($contextTokens);
            $recencyWeight = $maximumTimestamp === $minimumTimestamp
                ? 0.5
                : ((int) $candidate['updated_at'] - $minimumTimestamp)
                    / ($maximumTimestamp - $minimumTimestamp);
            $authorityWeight = $this->authorityWeight((string) $candidate['authority']);
            $activation = (0.28 * (float) $candidate['drive'])
                + (0.24 * $authorityWeight)
                + (0.16 * $contextWeight)
                + (0.14 * $recencyWeight)
                + (0.10 * (float) $candidate['investment'])
                + (0.08 * (float) $candidate['actionability']);

            $ranked[] = [
                'motive_id' => $candidate['motive_id'],
                'motive_type' => $candidate['motive_type'],
                'title' => $candidate['title'],
                'content' => $this->excerpt((string) $candidate['content']),
                'activation' => round($activation, 4),
                'weights' => [
                    'drive' => round((float) $candidate['drive'], 4),
                    'authority' => round($authorityWeight, 4),
                    'context' => round($contextWeight, 4),
                    'recency' => round($recencyWeight, 4),
                    'investment' => round((float) $candidate['investment'], 4),
                    'actionability' => round((float) $candidate['actionability'], 4),
                ],
                'matched_terms' => $matched,
                'updated_at' => $candidate['updated_at'],
                'evidence' => $candidate['evidence'],
            ];
        }

        usort($ranked, static fn (array $left, array $right): int =>
            $right['activation'] <=> $left['activation']
                ?: strcmp((string) $left['motive_id'], (string) $right['motive_id'])
        );
        foreach ($ranked as $index => $motive) {
            $ranked[$index]['rank'] = $index + 1;
        }
        return $ranked;
    }

    private function authorityWeight(string $authority): float
    {
        return match ($authority) {
            'system' => 1.0,
            'developer' => 0.95,
            'user' => 0.9,
            default => 0.55,
        };
    }

    /** @return array<string, true> */
    private function tokenSet(string $text): array
    {
        $stop = array_flip([
            'the', 'and', 'for', 'that', 'this', 'with', 'from', 'into', 'about', 'was', 'were',
            'are', 'has', 'have', 'had', 'not', 'but', 'you', 'your', 'our', 'its', 'his', 'her',
            'they', 'them', 'then', 'than', 'when', 'what', 'how', 'can', 'could', 'would', 'should',
        ]);
        $tokens = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [] as $token) {
            if (mb_strlen($token) < 3 || isset($stop[$token])) {
                continue;
            }
            $tokens[$token] = true;
        }
        return $tokens;
    }

    private function timestamp(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        $parsed = is_string($value) ? strtotime($value) : false;
        return $parsed === false ? 0 : $parsed;
    }

    private function excerpt(string $text, int $limit = 900): string
    {
        $text = PlainText::inline($text, $limit + 80);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, max(1, $limit - 2))) . ' …';
    }
}
