<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use NaviBrain\Core\ExecutiveCore\Executive;

use NaviBrain\Model\Memory;
use NaviBrain\Model\UtteranceOutcome;
use NaviBrain\Support\PlainText;

class PersonalityCompiler
{
    private const PROTOCOL = 'personality-evidence-v2';
    private const MIN_MEMORY_CONFIDENCE = 0.55;
    private const MIN_VOICE_SAMPLE_OBSERVABILITY = 0.25;
    private const RECURRENCE_OVERLAP = 0.30;
    private const RECURRENCE_SHARED_TERMS = 3;

    public function __construct(private readonly Executive $core)
    {
    }

    /** @return array<string, mixed> */
    public function compile(string $context): array
    {
        $context = trim(PlainText::sanitize($context));
        $nodes = $this->candidateNodes();
        $rankedNodes = $this->rankNodes($nodes, $context);
        $outcomes = UtteranceOutcome::getAllByWhere(['status' => 'reflected'], ['order' => ['id' => 'DESC']]);
        $voiceSamples = $this->contextualVoiceSamples($outcomes, $context);
        $descriptorStats = $this->descriptorEvidence();
        $establishedDescriptors = count(array_filter( $descriptorStats, static fn (array $row): bool => ($row['established'] ?? false) === true ));

        return [
            'protocol' => self::PROTOCOL,
            'mode' => [
                'compiler_writes' => false,
                'generation_connected' => false,
                'result_count_limited' => false,
            ],
            'context' => $context,
            'ranked_identity_and_ideas' => $rankedNodes,
            'current_state' => [
                'affect' => $this->core->appraiseNow(time())->toArray(),
                'active_needs' => $this->activeNeeds(),
            ],
            'social_evidence' => [
                'contextual_voice_samples' => $voiceSamples,
                'descriptor_statistics' => $descriptorStats,
            ],
            'evidence_health' => [
                'ranked_node_count' => count($rankedNodes),
                'fundamental_identity_node_count' => count(array_filter( $rankedNodes, static fn (array $node): bool => ($node['weights']['fundamental_identity'] ?? 0.0) > 0.0 )),
                'semantic_idea_count' => count(array_filter( $rankedNodes, static fn (array $node): bool => ($node['node_type'] ?? null) === 'semantic_memory' )),
                'reflected_utterance_count' => count($outcomes),
                'contextual_voice_sample_count' => count($voiceSamples),
                'descriptor_count' => count($descriptorStats),
                'established_descriptor_count' => $establishedDescriptors,
                'descriptor_inference_ready' => $establishedDescriptors > 0,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function candidateNodes(): array
    {
        $nodes = [];
        $selectedMemories = [];
        foreach ($this->core->listSelfModelFacts() as $fact) {
            $key = (string) ($fact['fact_key'] ?? '');
            $nodes[] = [
                'node_id' => 'self:' . (int) ($fact['id'] ?? 0),
                'node_type' => 'self_model',
                'key' => $key,
                'content' => (string) ($fact['fact_value'] ?? ''),
                'confidence' => (float) ($fact['confidence'] ?? 0.0),
                'updated_at' => $this->timestamp($fact['updated_at'] ?? null),
                'source_root' => 'self:' . $key,
                'source' => [
                    'id' => (int) ($fact['id'] ?? 0),
                    'evidence_event_id' => isset($fact['evidence_event_id'])
                        ? (int) $fact['evidence_event_id']
                        : null,
                ],
                'fundamental_identity' => $this->fundamentalIdentityWeight($key),
            ];
        }

        foreach (Memory::inspectAllByWhere([ 'status' => 'active', 'tier' => 'semantic', ], ['order' => ['updated_at' => 'DESC']]) as $memory) {
            if ((float) $memory->confidence < self::MIN_MEMORY_CONFIDENCE) {
                continue;
            }
            $selectedMemories[] = $memory;
            $id = (int) $memory->id;
            $sourceMemoryId = $memory->source_memory_id === null
                ? null
                : (int) $memory->source_memory_id;
            $nodes[] = [
                'node_id' => 'memory:' . $id,
                'node_type' => 'semantic_memory',
                'key' => null,
                'content' => (string) $memory->content,
                'confidence' => (float) $memory->confidence,
                'updated_at' => $this->timestamp($memory->updated_at),
                'source_root' => 'memory:' . ($sourceMemoryId ?? $id),
                'source' => [
                    'id' => $id,
                    'source_event_id' => $memory->source_event_id === null
                        ? null
                        : (int) $memory->source_event_id,
                    'source_memory_id' => $sourceMemoryId,
                ],
                'fundamental_identity' => 0.0,
            ];
        }

        Memory::observeRecords($selectedMemories);

        return $nodes;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private function rankNodes(array $nodes, string $context): array
    {
        if ($nodes === []) {
            return [];
        }

        $tokens = [];
        $documentFrequency = [];
        $timestamps = [];
        foreach ($nodes as $index => $node) {
            $tokens[$index] = $this->tokenSet(trim((string) ($node['key'] ?? '') . ' ' . (string) $node['content']));
            foreach (array_keys($tokens[$index]) as $term) {
                $documentFrequency[$term] = ($documentFrequency[$term] ?? 0) + 1;
            }
            $timestamps[] = (int) $node['updated_at'];
        }

        $support = [];
        foreach ($nodes as $index => $_node) {
            $support[$index] = [];
        }
        $nodeCount = count($nodes);
        for ($left = 0; $left < $nodeCount; $left++) {
            for ($right = $left + 1; $right < $nodeCount; $right++) {
                $shared = count(array_intersect_key($tokens[$left], $tokens[$right]));
                if ($shared < self::RECURRENCE_SHARED_TERMS) {
                    continue;
                }
                $overlap = $shared / max(1, min(count($tokens[$left]), count($tokens[$right])));
                if ($overlap < self::RECURRENCE_OVERLAP) {
                    continue;
                }
                if ($nodes[$left]['source_root'] === $nodes[$right]['source_root']) {
                    continue;
                }
                $support[$left][(string) $nodes[$right]['source_root']] = (string) $nodes[$right]['node_id'];
                $support[$right][(string) $nodes[$left]['source_root']] = (string) $nodes[$left]['node_id'];
            }
        }

        $maxSupport = max(array_map('count', $support));
        $minimumTimestamp = min($timestamps);
        $maximumTimestamp = max($timestamps);
        $contextTokens = $this->tokenSet($context);
        $ranked = [];

        foreach ($nodes as $index => $node) {
            [$contextWeight, $matchedTerms] = $this->contextWeight($contextTokens, $tokens[$index], $documentFrequency, $nodeCount);
            $supportCount = count($support[$index]);
            $rehearsalWeight = $maxSupport <= 0
                ? 0.0
                : log(1 + $supportCount) / log(1 + $maxSupport);
            $recencyWeight = $maximumTimestamp === $minimumTimestamp
                ? 0.5
                : ((int) $node['updated_at'] - $minimumTimestamp)
                    / ($maximumTimestamp - $minimumTimestamp);
            $fundamentalWeight = (float) $node['fundamental_identity'];
            $confidenceWeight = max(0.0, min(1.0, (float) $node['confidence']));

            $activation = (0.40 * $fundamentalWeight)
                + (0.20 * $rehearsalWeight)
                + (0.15 * $recencyWeight)
                + (0.15 * $contextWeight)
                + (0.10 * $confidenceWeight);
            if ($fundamentalWeight <= 0.0) {

                $activation *= $contextWeight;
            }

            $ranked[] = [
                'node_id' => $node['node_id'],
                'node_type' => $node['node_type'],
                'key' => $node['key'],
                'content' => $this->excerpt((string) $node['content']),
                'activation' => round($activation, 4),
                'weights' => [
                    'fundamental_identity' => round($fundamentalWeight, 4),
                    'independent_rehearsal' => round($rehearsalWeight, 4),
                    'recency' => round($recencyWeight, 4),
                    'context' => round($contextWeight, 4),
                    'confidence' => round($confidenceWeight, 4),
                ],
                'matched_terms' => $matchedTerms,
                'independent_support_count' => $supportCount,
                'supporting_node_ids' => array_values($support[$index]),
                'updated_at' => $node['updated_at'],
                'source' => $node['source'],
            ];
        }

        usort($ranked, static fn (array $left, array $right): int =>
            $right['activation'] <=> $left['activation']
                ?: $right['weights']['fundamental_identity'] <=> $left['weights']['fundamental_identity']
                ?: strcmp((string) $left['node_id'], (string) $right['node_id'])
        );
        foreach ($ranked as $index => $node) {
            $ranked[$index]['rank'] = $index + 1;
        }
        return $ranked;
    }

    /**
     * @param list<UtteranceOutcome> $outcomes
     * @return list<array<string, mixed>>
     */
    private function contextualVoiceSamples(array $outcomes, string $context): array
    {
        $contextTokens = $this->tokenSet($context);
        $unconditioned = $contextTokens === [];
        $minimumMatches = count($contextTokens) > 3 ? 2 : 1;
        $ranked = [];

        foreach ($outcomes as $outcome) {
            $utteranceTokens = $this->tokenSet((string) $outcome->utterance);
            $matched = array_keys(array_intersect_key($contextTokens, $utteranceTokens));
            if (!$unconditioned && count($matched) < $minimumMatches) {
                continue;
            }
            sort($matched);

            $observability = max(0.0, min(1.0, (float) $outcome->observability_weight));
            if ($observability < self::MIN_VOICE_SAMPLE_OBSERVABILITY) {
                continue;
            }
            $coverage = $unconditioned
                ? 1.0
                : count($matched) / max(1, count($contextTokens));
            $descriptorConfidence = max(0.0, min(1.0, (float) $outcome->descriptor_confidence));
            $interactionObserved = ((int) $outcome->responses_in_window
                + (int) $outcome->reactions_in_window) > 0;
            $score = ($coverage * 0.55)
                + ($observability * 0.25)
                + ($descriptorConfidence * 0.15)
                + ($interactionObserved ? 0.05 : 0.0);

            $ranked[] = [
                'score' => $score,
                'id' => (int) $outcome->id,
                'utterance' => $this->excerpt((string) $outcome->utterance, 600),
                'spoken_at' => $outcome->spoken_at,
                'matched_terms' => $matched,
                'response_latency_seconds' => $outcome->response_latency_seconds === null
                    ? null
                    : (int) $outcome->response_latency_seconds,
                'responses_in_window' => (int) $outcome->responses_in_window,
                'response_text' => $outcome->response_text === null
                    ? null
                    : $this->excerpt((string) $outcome->response_text, 500),
                'reactions_in_window' => (int) $outcome->reactions_in_window,
                'engagement' => (float) $outcome->engagement,
                'observability_weight' => $observability,
                'observability_basis' => $outcome->observability_basis,
                'descriptor' => $outcome->descriptor,
                'descriptor_confidence' => $descriptorConfidence,
                'descriptor_reason' => $outcome->descriptor_reason,
            ];
        }

        usort($ranked, static fn (array $left, array $right): int => $right['score'] <=> $left['score'] ?: $right['id'] <=> $left['id']);
        return array_values(array_map(static function (array $entry): array { $entry['context_relevance'] = round((float) $entry['score'], 4); unset($entry['score']); return $entry; }, $ranked));
    }

    /** @return list<array<string, mixed>> */
    private function descriptorEvidence(): array
    {
        $all = $this->core->socialFeedback()->descriptorStats();
        usort($all, static fn (array $left, array $right): int =>
            ($right['effective_sample_size'] ?? 0.0) <=> ($left['effective_sample_size'] ?? 0.0)
                ?: ($right['times'] ?? 0) <=> ($left['times'] ?? 0)
                ?: strcmp((string) ($left['descriptor'] ?? ''), (string) ($right['descriptor'] ?? ''))
        );
        return $all;
    }

    /** @return list<array<string, mixed>> */
    private function activeNeeds(): array
    {
        $needs = array_values(array_filter( $this->core->listNeeds(), static fn (array $need): bool => ($need['status'] ?? null) === 'active' ));
        usort($needs, static function (array $left, array $right): int {
            $leftThreshold = max(0.001, (float) ($left['trigger_threshold'] ?? 1.0));
            $rightThreshold = max(0.001, (float) ($right['trigger_threshold'] ?? 1.0));
            $leftRatio = (float) ($left['pressure'] ?? 0.0) / $leftThreshold;
            $rightRatio = (float) ($right['pressure'] ?? 0.0) / $rightThreshold;
            return (($right['triggered'] ?? false) <=> ($left['triggered'] ?? false))
                ?: $rightRatio <=> $leftRatio
                ?: strcmp((string) ($left['need_key'] ?? ''), (string) ($right['need_key'] ?? ''));
        });

        return array_values(array_map(static fn (array $need): array => [
            'need_key' => (string) ($need['need_key'] ?? ''),
            'description' => (string) ($need['description'] ?? ''),
            'authority' => (string) ($need['authority'] ?? ''),
            'pressure' => (float) ($need['pressure'] ?? 0.0),
            'trigger_threshold' => (float) ($need['trigger_threshold'] ?? 0.0),
            'triggered' => (bool) ($need['triggered'] ?? false),
        ], $needs));
    }

    /**
     * @param array<string, true> $contextTokens
     * @param array<string, true> $documentTokens
     * @param array<string, int> $documentFrequency
     * @return array{0: float, 1: list<string>}
     */
    private function contextWeight(array $contextTokens, array $documentTokens, array $documentFrequency, int $documentCount): array {
        if ($contextTokens === []) {

            return [1.0, []];
        }
        $possible = 0.0;
        $matchedWeight = 0.0;
        $matched = [];
        foreach (array_keys($contextTokens) as $term) {
            $weight = log(($documentCount + 1) / (($documentFrequency[$term] ?? 0) + 1)) + 1.0;
            $possible += $weight;
            if (isset($documentTokens[$term])) {
                $matchedWeight += $weight;
                $matched[] = $term;
            }
        }
        sort($matched);
        return [$possible <= 0.0 ? 0.0 : $matchedWeight / $possible, $matched];
    }

    private function fundamentalIdentityWeight(string $key): float
    {
        return match (true) {
            str_starts_with($key, 'identity.') => 1.0,
            str_starts_with($key, 'continuity.') => 0.9,
            str_starts_with($key, 'interaction.') => 0.75,
            default => 0.0,
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
