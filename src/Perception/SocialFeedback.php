<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

use NaviBrain\Core\ExecutiveCore;
use NaviBrain\Model\SenseEvent;
use NaviBrain\Model\SenseReading;
use NaviBrain\Model\ThreadStep;
use NaviBrain\Model\UtteranceOutcome;
use NaviBrain\Model\WorkItem;

/**
 * The closed loop around speaking.
 *
 * Speaking is the only thing Navi does that other people can react to, so it is
 * the only place Navi can learn what lands. The loop is: Navi speaks, Navi's hearing
 * observes the window that follows, a reflective pass names what it thinks
 * happened in its own words, and the association between those names and
 * observed engagement accumulates.
 *
 * Nothing here declares which descriptors are good. There is no table of
 * funny/dumb/shameful, and there is no sentiment list. The only anchor is
 * whether interaction followed, because that is the least interpretive thing
 * available. Valence is therefore discovered from co-occurrence rather than
 * asserted up front, which is the difference between a learned social sense and
 * a hand-written one.
 */
final class SocialFeedback
{
    public const WORK_TYPE = 'social_feedback_reflection';
    private const DEFAULT_WINDOW_SECONDS = 120;
    private const MIN_DESCRIPTOR_SAMPLE = 3;
    private const MAX_REFLECTION_ATTEMPTS = 3;

    public function __construct(private readonly ExecutiveCore $core)
    {
    }

    /**
     * Register an utterance the moment it is spoken, before anything is known
     * about how it landed.
     */
    public function registerUtterance(int $threadStepId, string $utterance, int $spokenAt): array
    {
        $existing = UtteranceOutcome::getByField('thread_step_id', $threadStepId);
        if ($existing instanceof UtteranceOutcome) {
            return $existing->getData();
        }
        $presence = $this->presenceAt($spokenAt);
        $presenceState = $presence === null ? 'unknown' : ((bool) $presence ? 'present' : 'away');
        /** @var UtteranceOutcome $outcome */
        $outcome = $this->core->insertRecord(UtteranceOutcome::class, [
            'thread_step_id' => $threadStepId,
            'spoken_at' => $spokenAt,
            'utterance' => $utterance,
            'window_seconds' => self::DEFAULT_WINDOW_SECONDS,
            'present_at_utterance' => $presence,
            'presence_state_at_utterance' => $presenceState,
            'responses_in_window' => 0,
            'reactions_in_window' => 0,
            'engagement' => 0.0,
            'observability_weight' => 0.0,
            'observability_basis' => null,
            'descriptor_confidence' => 0.0,
            'status' => 'awaiting_window',
        ]);
        return $outcome->getData();
    }

    /**
     * Close every response window that has elapsed, recording only observables.
     *
     * @return list<array<string, mixed>>
     */
    public function closeWindows(?int $now = null): array
    {
        $now ??= time();
        $closed = [];

        foreach (UtteranceOutcome::getAllByWhere(
            ['status' => 'awaiting_window'],
            ['order' => ['id' => 'ASC'], 'limit' => 50]
        ) as $outcome) {
            $spokenAt = $this->timestamp($outcome->spoken_at) ?? $now;
            $window = (int) $outcome->window_seconds;
            if (($now - $spokenAt) < $window) {
                continue;
            }

            $responses = [];
            $reactions = 0;
            foreach (SenseReading::getAllByWhere(
                ['source_key' => 'pet_hearing'],
                ['order' => ['id' => 'DESC'], 'limit' => 400]
            ) as $reading) {
                $at = $this->timestamp($reading->observed_at);
                if ($at === null || $at < $spokenAt || $at > ($spokenAt + $window)) {
                    continue;
                }
                $payload = is_array($reading->payload) ? $reading->payload : [];
                $text = trim((string) ($payload['text'] ?? ''));
                if ($text === '' || !$this->countsAsReply($payload)) {
                    continue;
                }
                $responses[] = ['at' => $at, 'text' => $text];
            }
            usort($responses, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

            foreach (SenseEvent::getAllByWhere(
                ['sense_key' => 'heard_reaction'],
                ['order' => ['id' => 'DESC'], 'limit' => 100]
            ) as $event) {
                $at = $this->timestamp($event->observed_at);
                if ($at !== null && $at >= $spokenAt && $at <= ($spokenAt + $window)) {
                    $reactions++;
                }
            }

            $first = $responses[0] ?? null;
            $latency = $first === null ? null : max(0, $first['at'] - $spokenAt);

            // Engagement is a measure of interaction, not of quality. It is the
            // only thing asserted here, and it asserts nothing about meaning.
            $engagement = 0.0;
            if ($first !== null) {
                $engagement += 0.5;
                if ($latency !== null && $latency <= 20) {
                    $engagement += 0.2;
                }
            }
            if ($reactions > 0) {
                $engagement += 0.3;
            }
            $engagement = round(min(1.0, $engagement), 4);

            $observability = $this->core->otherModel()->utteranceObservability(
                $outcome,
                count($responses),
                $reactions
            );
            $observabilityWeight = (float) $observability['weight'];

            // Silence only means something if someone was there to break it.
            // A line spoken to an empty room, or while the microphone was down,
            // scores zero for reasons that have nothing to do with the line, and
            // scoring it anyway teaches Navi that whatever was said failed.
            // Those observations are marked inconclusive instead: absence of
            // evidence, kept out of the evidence.
            $couldHaveLanded = $observabilityWeight >= 0.25 || $responses !== [] || $reactions > 0;
            if (!$couldHaveLanded) {
                $outcome->setFields([
                    'response_latency_seconds' => null,
                    'responses_in_window' => 0,
                    'reactions_in_window' => $reactions,
                    // Raw engagement remains non-null and uninterpreted. The
                    // zero observability weight is what keeps this absence out
                    // of learned evidence.
                    'engagement' => 0.0,
                    'observability_weight' => $observabilityWeight,
                    'observability_basis' => (string) $observability['basis'],
                    'status' => 'inconclusive',
                ]);
                $outcome->save();
                $this->core->emitEvent('utterance.inconclusive', [
                    'utterance_outcome_id' => (int) $outcome->id,
                    'reason' => 'Nobody was detected at the machine, so no response is not a response.',
                    'observability_weight' => $observabilityWeight,
                ]);
                $this->core->otherModel()->observeUtteranceOutcome($outcome);
                $closed[] = $outcome->getData();
                continue;
            }

            $outcome->setFields([
                'response_latency_seconds' => $latency,
                'responses_in_window' => count($responses),
                'response_text' => $first === null
                    ? null
                    : mb_substr(implode(' / ', array_map(
                        static fn (array $r): string => $r['text'],
                        array_slice($responses, 0, 4)
                    )), 0, 600),
                'reactions_in_window' => $reactions,
                'engagement' => $engagement,
                'observability_weight' => $observabilityWeight,
                'observability_basis' => (string) $observability['basis'],
                'status' => 'observed',
            ]);
            $outcome->save();
            $this->core->emitEvent('utterance.observed', [
                'utterance_outcome_id' => (int) $outcome->id,
                'engagement' => $engagement,
                'responses' => count($responses),
                'reactions' => $reactions,
                'latency_seconds' => $latency,
                'observability_weight' => $observabilityWeight,
            ]);
            $this->core->otherModel()->observeUtteranceOutcome($outcome);
            $closed[] = $outcome->getData();
        }

        return $closed;
    }

    /**
     * Queue descriptor work without ever putting an LLM call in the sensory
     * process. One failed outcome cannot starve the rest of the backlog.
     *
     * @return list<array<string, mixed>>
     */
    public function queueReflections(int $limit = 2): array
    {
        $limit = max(1, min(8, $limit));
        $attempts = [];
        $inFlight = [];
        foreach (WorkItem::getAllByWhere(
            ['work_type' => self::WORK_TYPE],
            ['order' => ['id' => 'DESC'], 'limit' => 1000]
        ) as $work) {
            $refs = is_array($work->input_refs) ? $work->input_refs : [];
            $outcomeId = (int) ($refs['utterance_outcome_id'] ?? 0);
            if ($outcomeId < 1) {
                continue;
            }
            $attempts[$outcomeId] = (int) ($attempts[$outcomeId] ?? 0) + 1;
            if (in_array($work->status, ['queued', 'leased', 'completed'], true)) {
                $inFlight[$outcomeId] = true;
            }
        }

        $queued = [];
        foreach (UtteranceOutcome::getAllByWhere(
            ['status' => 'observed'],
            ['order' => ['id' => 'ASC'], 'limit' => 200]
        ) as $outcome) {
            $outcomeId = (int) $outcome->id;
            $attempt = (int) ($attempts[$outcomeId] ?? 0) + 1;
            if (isset($inFlight[$outcomeId]) || $attempt > self::MAX_REFLECTION_ATTEMPTS) {
                continue;
            }
            $prompt = implode("\n", [
                'Navi said one line out loud. Below is what Navi said and what was observed in the two minutes after.',
                'Return kind social_feedback_reflection. Put the name of what happened in content as a single lowercase word or short_snake_case phrase of your own choosing.',
                'Set confidence from 0 through 1 and keep challenged_assumption to one concise sentence.',
                'Do not pick from a list, and do not judge whether it was good or bad. Just name it.',
                'Said: ' . (string) $outcome->utterance,
                'Observed after: ' . json_encode([
                    'responded' => (int) $outcome->responses_in_window > 0,
                    'response_latency_seconds' => $outcome->response_latency_seconds,
                    'vocal_reactions' => (int) $outcome->reactions_in_window,
                    'what_was_heard' => $outcome->response_text,
                    'presence_state_at_utterance' => $outcome->presence_state_at_utterance,
                    'observability_weight' => (float) $outcome->observability_weight,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $queued[] = $this->core->enqueueWork(
                parentRunId: null,
                parentIntentionId: null,
                workType: self::WORK_TYPE,
                prompt: $prompt,
                inputRefs: [
                    'utterance_outcome_id' => $outcomeId,
                    'operation' => self::WORK_TYPE,
                    'context_scope' => 'no_workspace',
                    'attempt' => $attempt,
                ],
                tokenBudget: 96,
                wallBudgetSeconds: 180,
                idempotencyKey: sprintf('utterance_reflection:%d:attempt:%d', $outcomeId, $attempt),
                depth: 0,
                maxDepth: 0
            );
            if (count($queued) >= $limit) {
                break;
            }
        }
        return $queued;
    }

    /** @param array<string, mixed> $work @param array<string, mixed> $proposal */
    public function integrateReflection(array $work, array $proposal): array
    {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $outcomeId = (int) ($refs['utterance_outcome_id'] ?? 0);
        $outcome = UtteranceOutcome::getByID($outcomeId);
        if (!$outcome instanceof UtteranceOutcome) {
            return ['status' => 'missing_outcome'];
        }
        if ($outcome->status === 'reflected') {
            return ['status' => 'already_integrated', 'outcome' => $outcome->getData()];
        }
        if ($outcome->status !== 'observed'
            || ($proposal['kind'] ?? null) !== self::WORK_TYPE
        ) {
            return ['status' => 'stale_or_invalid'];
        }
        return $this->recordDescriptor(
            $outcomeId,
            (string) ($proposal['content'] ?? ''),
            (float) ($proposal['confidence'] ?? 0.0),
            (string) ($proposal['challenged_assumption'] ?? '')
        );
    }

    /** @param array<string, mixed> $work */
    public function failReflection(array $work, string $error): array
    {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $outcomeId = (int) ($refs['utterance_outcome_id'] ?? 0);
        $this->core->emitEvent('utterance.reflection_failed', [
            'utterance_outcome_id' => $outcomeId,
            'attempt' => (int) ($refs['attempt'] ?? 0),
            'error' => mb_substr($error, 0, 1000),
        ]);
        return ['status' => 'reflection_failed', 'utterance_outcome_id' => $outcomeId];
    }

    /**
     * Record the descriptor a reflective pass chose. The vocabulary is the
     * model's own; nothing validates it against a list, only against shape.
     */
    public function recordDescriptor(
        int $outcomeId,
        string $descriptor,
        float $confidence,
        string $reason
    ): array {
        $outcome = UtteranceOutcome::getByID($outcomeId);
        if (!$outcome instanceof UtteranceOutcome) {
            return ['status' => 'missing'];
        }
        $descriptor = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}_ -]/u', '', $descriptor) ?? ''));
        $descriptor = preg_replace('/\s+/u', '_', $descriptor) ?? $descriptor;
        if ($descriptor === '') {
            return ['status' => 'empty_descriptor'];
        }

        $outcome->setFields([
            'descriptor' => mb_substr($descriptor, 0, 48),
            'descriptor_confidence' => max(0.0, min(1.0, $confidence)),
            'descriptor_reason' => $reason,
            'reflected_at' => time(),
            'status' => 'reflected',
        ]);
        $outcome->save();
        $this->core->emitEvent('utterance.reflected', [
            'utterance_outcome_id' => $outcomeId,
            'descriptor' => $outcome->descriptor,
            'engagement' => (float) $outcome->engagement,
        ]);
        return ['status' => 'recorded', 'outcome' => $outcome->getData()];
    }

    /**
     * Social capital: which of Navi own descriptors have actually gone well.
     *
     * This is the learned part. A descriptor means something only because
     * utterances Navi labelled that way did or did not produce interaction.
     *
     * @return list<array<string, mixed>>
     */
    public function descriptorStats(int $minSample = self::MIN_DESCRIPTOR_SAMPLE): array
    {
        $byDescriptor = [];
        foreach (UtteranceOutcome::getAllByWhere(
            ['status' => 'reflected'],
            ['order' => ['id' => 'DESC'], 'limit' => 500]
        ) as $outcome) {
            $key = (string) $outcome->descriptor;
            if ($key === '') {
                continue;
            }
            $weight = max(0.0, min(1.0, (float) $outcome->observability_weight));
            $byDescriptor[$key] ??= [
                'descriptor' => $key,
                'count' => 0,
                'effective_sample_size' => 0.0,
                'weighted_engagement_sum' => 0.0,
            ];
            $byDescriptor[$key]['count']++;
            $byDescriptor[$key]['effective_sample_size'] += $weight;
            $byDescriptor[$key]['weighted_engagement_sum'] += (float) $outcome->engagement * $weight;
        }

        $stats = [];
        foreach ($byDescriptor as $row) {
            $effective = (float) $row['effective_sample_size'];
            $mean = $effective <= 0.0 ? null : $row['weighted_engagement_sum'] / $effective;
            $stats[] = [
                'descriptor' => $row['descriptor'],
                'times' => $row['count'],
                'effective_sample_size' => round($effective, 4),
                'mean_engagement' => $mean === null ? null : round($mean, 4),
                'established' => $effective >= $minSample,
            ];
        }
        usort($stats, static fn (array $a, array $b): int =>
            ($b['mean_engagement'] ?? -1.0) <=> ($a['mean_engagement'] ?? -1.0)
        );
        return $stats;
    }

    /**
     * Defer to the hearing sense rather than keeping a second, divergent copy of
     * its judgement.
     *
     * This is deliberate coupling: as that sense learns what its source gets
     * wrong, the social measurement inherits the improvement. It also settles
     * two ways this loop can lie to itself. Ambient music is not a reply, and
     * neither is a stranger on a call saying something forty seconds after Navi
     * spoke into a room that was never listening to Navi.
     *
     * @param array<string, mixed> $payload
     */
    private function countsAsReply(array $payload): bool
    {
        return $this->core->sensoryCortex()->senseAccepts('heard_speech', $payload);
    }

    private function presenceAt(int $at): ?int
    {
        foreach (SenseReading::getAllByWhere(
            ['source_key' => 'desktop_presence'],
            ['order' => ['id' => 'DESC'], 'limit' => 20]
        ) as $reading) {
            $observed = $this->timestamp($reading->observed_at);
            if ($observed !== null && $observed <= $at) {
                $payload = is_array($reading->payload) ? $reading->payload : [];
                $using = $payload['using_computer'] ?? null;
                return $using === null ? null : (int) (bool) $using;
            }
        }
        return null;
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_numeric($value)) {
            return (int) $value;
        }
        $parsed = strtotime((string) $value);
        return $parsed === false ? null : $parsed;
    }
}
