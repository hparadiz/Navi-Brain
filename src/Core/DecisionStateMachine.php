<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use Divergence\IO\Database\Connections;
use InvalidArgumentException;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\DecisionCandidate;
use NaviBrain\Model\DecisionCycle;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\SenseEvent;
use NaviBrain\Model\SensorySource;
use NaviBrain\Model\WorkItem;
use NaviBrain\Support\PlainText;
use RuntimeException;
use Throwable;

/**
 * Thin complete cognitive loop with explicit, measurable state transitions.
 *
 * Components stay replaceable: perception supplies observations, memory owns
 * retrieval, a model worker proposes, deterministic code evaluates/selects,
 * procedural adapters execute, ActionTrace verifies, and the procedure
 * compiler performs bounded post-outcome adaptation.
 */
final class DecisionStateMachine
{
    public const WORK_TYPE = 'decision_cycle_proposal';
    private const MAX_CANDIDATES = 3;

    public function __construct(private readonly ExecutiveCore $core)
    {
    }

    /** @return array<string, mixed> */
    public function start(
        int $intentionId,
        string $trigger,
        ?int $threadId = null,
        ?string $modelHint = null,
        ?int $parentRunId = null
    ): array {
        $this->core->recoverLocallyRelinquishedDecisions();
        if (ExecutiveControl::status()['paused']) {
            throw new CognitionPaused('Cognition is paused; new decision cycles are not admitted.');
        }
        $intention = Intention::getByID($intentionId);
        if (!$intention instanceof Intention || $intention->status !== 'active') {
            throw new RuntimeException('A decision cycle requires an active intention.');
        }
        foreach (['running', 'waiting'] as $activeStatus) {
            $active = DecisionCycle::getAllByWhere(
                ['intention_id' => $intentionId, 'status' => $activeStatus],
                ['order' => ['id' => 'ASC'], 'limit' => 1]
            );
            if ($active !== []) {
                throw new RuntimeException(sprintf(
                    'Intention %d already has active decision cycle %d.',
                    $intentionId,
                    (int) $active[0]->id
                ));
            }
        }
        $trigger = trim($trigger);
        if ($trigger === '') {
            throw new InvalidArgumentException('decision trigger cannot be empty.');
        }
        if ($threadId !== null) {
            $thread = CognitiveThread::getByID($threadId);
            if (!$thread instanceof CognitiveThread || (int) $thread->parent_intention_id !== $intentionId) {
                throw new RuntimeException('Decision thread must belong to the selected intention.');
            }
        }

        $inputSnapshot = ['version' => 1, 'dependencies' => []];
        $intentionIds = array_merge([$intentionId], (array) $intention->dependencies,
            $intention->parent_id === null ? [] : [(int) $intention->parent_id]);
        foreach (array_unique($intentionIds) as $id) {
            $this->captureDependency($inputSnapshot, 'intention', (int) $id,
                (int) $id === $intentionId ? $intention->getData() : null);
        }

        $working = $this->core->workingMemory()->snapshot($threadId);
        if ($this->core->otherModel()->isAblated()) {
            $working = array_values(array_filter(
                $working,
                static fn (array $slot): bool => ($slot['slot_role'] ?? null) !== 'other_agent_state'
            ));
        }
        $workingJson = $this->encode($working);
        $cycle = $this->core->beginDecisionPlanning(
            $intentionId, $threadId, $trigger, $modelHint, hash('sha256', $workingJson)
        );
        try {
            $result = $this->planStartedCycle($cycle, $intention, $inputSnapshot, $working,
                $trigger, $modelHint, $parentRunId);
        } catch (Throwable $throwable) {
            $failed = $this->core->finishDecisionPlanning((int) $cycle->id,
                'Decision planning failed: ' . $throwable->getMessage());
            if ($failed !== null) {
                return $failed;
            }
            // A committed work item has its own owner and must not be undone.
            throw $throwable;
        }
        return $this->core->finishDecisionPlanning((int) $cycle->id) ?? $result;
    }

    /** @param array<string, mixed> $inputSnapshot
     *  @param list<array<string, mixed>> $working
     *  @return array<string, mixed>
     */
    private function planStartedCycle(
        DecisionCycle $cycle,
        Intention $intention,
        array $inputSnapshot,
        array $working,
        string $trigger,
        ?string $modelHint,
        ?int $parentRunId
    ): array {
        $intentionId = (int) $cycle->intention_id;
        $threadId = $cycle->thread_id === null ? null : (int) $cycle->thread_id;
        $started = hrtime(true);
        $pending = $this->core->sensoryCortex()->pendingEvents(6);
        $observations = [
            'sense_events' => array_values(array_map(static fn (array $event): array => [
                'id' => (int) ($event['id'] ?? 0),
                'sense_key' => $event['sense_key'] ?? null,
                'summary' => $event['summary'] ?? null,
                'significance' => $event['significance'] ?? null,
                'prediction_error' => $event['prediction_error'] ?? null,
                'prediction_precision' => $event['prediction_precision'] ?? null,
            ], $pending)),
            'working_memory_roles' => array_values(array_map(
                static fn (array $slot): string => (string) ($slot['slot_role'] ?? ''),
                $working
            )),
            'other_agent_state' => array_values(array_filter(
                $working,
                static fn (array $slot): bool => ($slot['slot_role'] ?? null) === 'other_agent_state'
            ))[0] ?? null,
            'other_agent_model_ablated' => $this->core->otherModel()->isAblated(),
        ];
        foreach ($pending as $event) {
            $this->captureDependency($inputSnapshot, 'sense_event', (int) ($event['id'] ?? 0), $event);
        }
        $cycle->setField('observations', $observations + ['input_snapshot' => $inputSnapshot]);
        $this->advance($cycle, 'retrieve', 'observe', $this->elapsedMs($started));

        $started = hrtime(true);
        $query = implode(' ', [
            (string) $intention->title,
            (string) $intention->next_action,
            $trigger,
        ]);
        // Retrieval is an internal planning action, not the outcome of the
        // decision cycle. Run it through the same typed procedural boundary so
        // repeated success can become a reusable procedure without a model.
        try {
            $retrievalAction = $this->core->proceduralMemory()->executeAction(
                $intentionId,
                'memory.search',
                ['query' => $query, 'limit' => 8],
                'Retrieve long-term memory relevant to the active decision.',
                'A bounded, machine-verified result set is returned.'
            );
        } catch (CognitionPaused) {
            return $this->fail($cycle, 'Cognition paused before planning retrieval; start a fresh cycle after resume.');
        }
        if (($retrievalAction['status'] ?? null) === 'held') {
            $actionId = (int) ($retrievalAction['started']['action']['id'] ?? 0);
            $this->core->rejectPendingActionExecution($actionId, [
                'error' => 'Planning retrieval was held by cognition pause; start a fresh cycle.',
                'dispatch_not_attempted' => true,
            ]);
            $this->core->proceduralMemory()->finishDurableAction($actionId);
            return $this->fail($cycle, 'Cognition paused before planning retrieval; start a fresh cycle after resume.');
        }
        if (($retrievalAction['status'] ?? null) !== 'succeeded') {
            return $this->fail($cycle, 'The installed retrieval procedure failed during planning.');
        }
        $retrievalObserved = is_array($retrievalAction['dispatch']['observed'] ?? null)
            ? $retrievalAction['dispatch']['observed']
            : [];
        $retrieved = [];
        foreach ((array) ($retrievalObserved['memories'] ?? []) as $memory) {
            if (!is_array($memory)
                || ($memory['status'] ?? null) !== 'active'
                || ($memory['tier'] ?? null) === 'working'
            ) {
                continue;
            }
            $this->captureDependency($inputSnapshot, 'memory', (int) ($memory['id'] ?? 0), $memory);
            $retrieved[] = [
                'id' => (int) ($memory['id'] ?? 0),
                'tier' => (string) ($memory['tier'] ?? ''),
                'content' => mb_substr((string) ($memory['content'] ?? ''), 0, 320),
                'confidence' => (float) ($memory['confidence'] ?? 0.0),
            ];
        }
        $retrieval = [
            'query' => $query,
            'action_id' => (int) ($retrievalAction['started']['action']['id'] ?? 0),
            'procedure_id' => $retrievalAction['started']['procedure']['procedure_id'] ?? null,
            'memory_ids' => array_values(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $retrieved)),
            'memories' => $retrieved,
        ];
        $cycle->setField('retrieval', $retrieval);
        if ($retrieved !== []) {
            $first = $retrieved[0];
            $this->core->workingMemory()->publish(
                role: 'supporting_evidence',
                claim: mb_substr((string) ($first['content'] ?? ''), 0, 800),
                recordType: 'memory',
                recordId: (int) ($first['id'] ?? 0),
                confidence: (float) ($first['confidence'] ?? 0.5),
                ttlSeconds: 900,
                scope: $threadId === null ? 'shared' : 'thread:' . $threadId,
                threadId: $threadId
            );
        }
        $this->advance($cycle, 'reason', 'retrieve', $this->elapsedMs($started));

        $started = hrtime(true);
        $adapters = $this->core->proceduralMemory()->terminalAdapters();
        $prompt = implode("\n", [
            'Run the reasoning and proposal stages of one cognitive decision cycle.',
            'Return exactly kind decision_candidates, confidence, challenged_assumption, and content.',
            'content must be one minified JSON object with keys current_plan, decision_basis, and candidates.',
            'candidates must contain one to three objects with exactly these keys:',
            'action_kind, description, expected, arguments, rationale.',
            'Use only an installed action_kind below. arguments must match that adapter exactly.',
            'Retrieval and reasoning have already happened inside planning. Propose only a terminal grounding or learning action.',
            'Do not execute anything. Deterministic code evaluates and dispatches after you return.',
            "Intention:\n" . PlainText::render([
                'title' => (string) $intention->title,
                'next_action' => (string) $intention->next_action,
                'success_condition' => (string) $intention->success_condition,
            ], 3000, 8),
            'Trigger: ' . PlainText::sanitize($trigger),
            "Observations:\n" . PlainText::render($observations, 4000, 12),
            "Retrieved memory:\n" . PlainText::render($retrieval['memories'], 5000, 8),
            "Installed adapters:\n" . PlainText::render($adapters, 6000, 20),
        ]);
        $cycle->setField('observations', $observations + ['input_snapshot' => $inputSnapshot]);
        $cycle->save();
        $queued = $this->core->enqueueWork(
            parentRunId: $parentRunId,
            parentIntentionId: $intentionId,
            workType: self::WORK_TYPE,
            prompt: $prompt,
            inputRefs: [
                'decision_cycle_id' => (int) $cycle->id,
                'thread_id' => $threadId,
                'operation' => 'decision_candidates',
                'context_scope' => 'decision_cycle_workspace',
                'model_hint' => $modelHint,
            ],
            tokenBudget: 768,
            wallBudgetSeconds: 300,
            idempotencyKey: 'decision_cycle:' . (int) $cycle->id . ':proposal',
            allowedActions: array_values(array_map(
                static fn (array $adapter): string => (string) $adapter['action_kind'],
                $adapters
            ))
        );
        // Queue publication binds the exact workspace and work item before a
        // worker can claim it. A fast worker may already have advanced it.
        $cycle = DecisionCycle::getByID((int) $cycle->id);
        if (!$cycle instanceof DecisionCycle) {
            throw new RuntimeException('Decision cycle disappeared during work enqueue.');
        }
        if ($cycle->status === 'waiting' && $cycle->state === 'reason') {
            $timings = is_array($cycle->stage_timings) ? $cycle->stage_timings : [];
            $timings['reason_dispatch_ms'] = $this->elapsedMs($started);
            $timing = Connections::getConnection()->prepare(
                "UPDATE decision_cycles SET stage_timings = :timings
                 WHERE id = :id AND proposal_work_item_id = :work_id
                   AND status = 'waiting' AND state = 'reason'"
            );
            $timing->execute([
                'timings' => serialize($timings), 'id' => (int) $cycle->id,
                'work_id' => (int) ($queued['work_item']['id'] ?? 0),
            ]);
            $cycle = DecisionCycle::getByID((int) $cycle->id);
        }
        if (!$cycle instanceof DecisionCycle) {
            throw new RuntimeException('Decision cycle disappeared after work publication.');
        }
        return ['status' => (string) $cycle->status, 'cycle' => $cycle->getData(), 'work_item' => $queued['work_item'] ?? null];
    }

    /** @param array<string, mixed> $work
     *  @param array<string, mixed> $proposal
     *  @return array<string, mixed>
     */
    public function integrate(array $work, array $proposal, ?string $model): array
    {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $cycleId = (int) ($refs['decision_cycle_id'] ?? 0);
        $cycle = DecisionCycle::getByID($cycleId);
        if (!$cycle instanceof DecisionCycle) {
            throw new RuntimeException('Decision proposal references a missing cycle.');
        }
        if ($cycle->status !== 'waiting' || $cycle->state !== 'reason') {
            return ['status' => 'already_integrated', 'cycle' => $cycle->getData()];
        }
        if ((int) $cycle->proposal_work_item_id !== (int) ($work['id'] ?? 0)) {
            throw new RuntimeException('Decision proposal work item does not match its cycle.');
        }
        $claimed = $this->core->claimDecisionIntegration($cycleId, (int) $work['id'], $model);
        if (!$claimed) {
            $durable = DecisionCycle::getByID($cycleId);
            return ['status' => 'already_integrated', 'cycle' => $durable?->getData() ?? $cycle->getData()];
        }
        try {
            $cycle = DecisionCycle::getByID($cycleId);
            if (!$cycle instanceof DecisionCycle) {
                throw new RuntimeException('Decision cycle disappeared during result admission.');
            }
            $result = $cycle->status === 'running' && $cycle->state === 'reason'
                ? $this->integrateClaimed($cycle, $work, $proposal)
                : ['status' => 'already_integrated', 'cycle' => $cycle->getData()];
        } catch (Throwable $throwable) {
            $failed = $this->core->finishDecisionIntegration(
                $cycleId, (int) $work['id'], 'Decision integration failed: ' . $throwable->getMessage()
            );
            if ($failed !== null) {
                return $failed;
            }
            // A bound action owns its durable outcome and existing recovery.
            // A worker failure must not overwrite that action's decision cycle.
            throw $throwable;
        }
        return $this->core->finishDecisionIntegration($cycleId, (int) $work['id']) ?? $result;
    }

    /** @param array<string, mixed> $work
     *  @param array<string, mixed> $proposal
     *  @return array<string, mixed>
     */
    private function integrateClaimed(DecisionCycle $cycle, array $work, array $proposal): array
    {
        $cycleId = (int) $cycle->id;
        $changes = $this->decisionInputChanges($cycleId);
        if ($changes !== []) {
            return $this->fail($cycle, 'Stale decision input; start a fresh cycle: ' . implode('; ', $changes));
        }
        $workRecord = WorkItem::getByID((int) $work['id']);
        $createdAt = $workRecord instanceof WorkItem ? $this->timestamp($workRecord->created_at) : null;
        $timings = is_array($cycle->stage_timings) ? $cycle->stage_timings : [];
        $timings['reason_model_ms'] = $createdAt === null ? 0 : max(0, (time() - $createdAt) * 1000);
        $cycle->setField('stage_timings', $timings);
        $cycle->save();

        $started = hrtime(true);
        try {
            $document = json_decode((string) ($proposal['content'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            return $this->fail($cycle, 'Proposal content is not valid candidate JSON: ' . $throwable->getMessage());
        }
        if (!is_array($document)) {
            return $this->fail($cycle, 'Proposal content must decode to a JSON object.');
        }
        // Accept the earlier list-only shape for already queued work, but all
        // newly emitted prompts carry explicit reasoning variables.
        $legacyList = array_is_list($document);
        $candidates = is_array($document['candidates'] ?? null) ? $document['candidates'] : $document;
        if (!is_array($candidates) || !array_is_list($candidates) || $candidates === []) {
            return $this->fail($cycle, 'Proposal content must be a non-empty JSON candidate array.');
        }
        $currentPlan = trim((string) ($legacyList ? '' : ($document['current_plan'] ?? '')));
        $decisionBasis = trim((string) ($legacyList ? '' : ($document['decision_basis'] ?? '')));
        if (!$legacyList && ($currentPlan === '' || $decisionBasis === '')) {
            return $this->fail($cycle, 'Reasoning output must contain current_plan and decision_basis.');
        }
        try {
            $this->core->publishDecisionReasoning(
                $cycleId,
                ['claim' => $currentPlan, 'confidence' => (float) ($proposal['confidence'] ?? 0.0)],
                ['claim' => $decisionBasis, 'confidence' => (float) ($proposal['confidence'] ?? 0.0)]
            );
        } catch (Throwable $throwable) {
            $durable = DecisionCycle::getByID($cycleId);
            if ($durable instanceof DecisionCycle
                && ($durable->status !== 'running' || $durable->state !== 'reason')) {
                return ['status' => 'already_integrated', 'cycle' => $durable->getData()];
            }
            return $this->fail($durable instanceof DecisionCycle ? $durable : $cycle,
                'Reasoning publication rejected: ' . $throwable->getMessage());
        }
        $cycle->setField('reasoning', [
            'current_plan' => $currentPlan,
            'decision_basis' => $decisionBasis,
            'challenged_assumption' => (string) ($proposal['challenged_assumption'] ?? ''),
            'model_confidence' => $proposal['confidence'] ?? null,
            'working_memory_roles_written' => array_values(array_filter([
                $currentPlan === '' ? null : 'current_plan',
                $decisionBasis === '' ? null : 'decision_basis',
            ])),
        ]);
        $this->advance($cycle, 'propose', 'reason', $this->elapsedMs($started));

        $started = hrtime(true);
        $candidates = array_slice($candidates, 0, self::MAX_CANDIDATES);
        $baseConfidence = is_numeric($proposal['confidence'] ?? null)
            ? max(0.0, min(1.0, (float) $proposal['confidence']))
            : 0.0;
        $stored = [];
        foreach ($candidates as $index => $candidate) {
            $candidate = is_array($candidate) ? $candidate : [];
            $candidateKeys = array_keys($candidate);
            $expectedKeys = ['action_kind', 'arguments', 'description', 'expected', 'rationale'];
            sort($candidateKeys);
            sort($expectedKeys);
            $exactShape = $candidateKeys === $expectedKeys;
            $kind = trim((string) ($candidate['action_kind'] ?? ''));
            $description = trim((string) ($candidate['description'] ?? ''));
            $expected = trim((string) ($candidate['expected'] ?? ''));
            $arguments = is_array($candidate['arguments'] ?? null) ? $candidate['arguments'] : [];
            $rationale = trim((string) ($candidate['rationale'] ?? ''));
            $inspection = $this->core->proceduralMemory()->inspectCandidate(
                $kind,
                $arguments,
                $description,
                $expected
            );
            $actionClass = (string) ($inspection['adapter']['action_class'] ?? '');
            $retrievalState = is_array($cycle->retrieval) ? $cycle->retrieval : [];
            $sourceWasRetrieved = $actionClass !== 'learning'
                || in_array(
                    (int) ($arguments['episode_id'] ?? 0),
                    array_map('intval', (array) ($retrievalState['memory_ids'] ?? [])),
                    true
                );
            $valid = ($inspection['valid'] ?? false) === true
                && in_array($actionClass, ['grounding', 'learning'], true)
                && $exactShape
                && $sourceWasRetrieved
                && $description !== ''
                && $expected !== ''
                && $rationale !== '';
            $procedureConfidence = (float) ($inspection['procedure']['confidence'] ?? 0.0);
            $effect = (string) ($inspection['adapter']['effect'] ?? 'act');
            $simulation = is_array($inspection['forward_simulation'] ?? null)
                ? $inspection['forward_simulation']
                : [];
            $simulationConfidence = (float) ($simulation['confidence'] ?? 0.0);
            $grounding = $this->groundingScore(
                $description . ' ' . $rationale,
                (string) $cycle->trigger . ' ' . $this->retrievalText($cycle)
            );
            $score = $valid
                ? ($baseConfidence * 0.25)
                    + 0.2
                    + ($procedureConfidence * 0.15)
                    + ($simulationConfidence * 0.15)
                    + $this->actionClassBonus($actionClass)
                    + ($grounding * 0.15)
                : -1.0;
            $evaluation = [
                'valid_adapter_contract' => ($inspection['valid'] ?? false) === true,
                'terminal_action_class' => $actionClass,
                'exact_candidate_shape' => $exactShape,
                'learning_source_was_retrieved' => $sourceWasRetrieved,
                'complete_description' => $description !== '' && $expected !== '' && $rationale !== '',
                'adapter_error' => $inspection['error']
                    ?? ($sourceWasRetrieved ? null : 'Learning source was not retrieved during this planning cycle.'),
                'effect_ceiling' => $effect,
                'recalled_procedure_id' => $inspection['procedure']['procedure_id'] ?? null,
                'recalled_procedure_memory_id' => $inspection['procedure']['memory_id'] ?? null,
                'grounding_score' => round($grounding, 4),
                'forward_simulation' => $simulation,
            ];
            /** @var DecisionCandidate $row */
            $row = $this->core->insertRecord(DecisionCandidate::class, [
                'decision_cycle_id' => (int) $cycle->id,
                'rank' => $index + 1,
                'action_kind' => $kind === '' ? 'invalid' : mb_substr($kind, 0, 96),
                'description' => $description,
                'expected' => $expected,
                'arguments' => $arguments,
                'rationale' => $rationale,
                'proposed_confidence' => $baseConfidence,
                'score' => $score,
                'evaluation' => $evaluation,
                'status' => $valid ? 'proposed' : 'rejected',
            ]);
            $stored[] = ['row' => $row, 'inspection' => $inspection];
        }
        $this->advance($cycle, 'evaluate', 'propose', $this->elapsedMs($started));

        $started = hrtime(true);
        $valid = array_values(array_filter(
            $stored,
            static fn (array $entry): bool => $entry['row']->status === 'proposed'
        ));
        usort($valid, static fn (array $left, array $right): int => $right['row']->score <=> $left['row']->score);
        $cycle->setField('evaluation', [
            'candidate_ids' => array_values(array_map(static fn (array $entry): int => (int) $entry['row']->id, $stored)),
            'valid_count' => count($valid),
            'rejected_count' => count($stored) - count($valid),
            'challenged_assumption' => (string) ($proposal['challenged_assumption'] ?? ''),
        ]);
        $this->advance($cycle, 'select', 'evaluate', $this->elapsedMs($started));
        if ($valid === []) {
            return $this->impasse($cycle, 'No proposed terminal action satisfied an installed adapter contract.');
        }

        $started = hrtime(true);
        /** @var DecisionCandidate $selected */
        $selected = $valid[0]['row'];
        $selected->setField('status', 'selected');
        $selected->save();
        $inspection = $valid[0]['inspection'];
        $cycle->setField('selection', [
            'candidate_id' => (int) $selected->id,
            'action_kind' => (string) $selected->action_kind,
            'score' => (float) $selected->score,
            'procedure_id' => $inspection['procedure']['procedure_id'] ?? null,
            'procedure_memory_id' => $inspection['procedure']['memory_id'] ?? null,
            'reason' => 'Highest deterministic score among adapter-valid candidates.',
        ]);
        $this->advance($cycle, 'execute', 'select', $this->elapsedMs($started));

        $started = hrtime(true);
        $changes = $this->decisionInputChanges((int) $cycle->id);
        if ($changes !== []) {
            return $this->fail($cycle, 'Stale decision input before execution; start a fresh cycle: ' . implode('; ', $changes));
        }
        try {
            $outcome = $this->core->proceduralMemory()->executeAction(
                (int) $cycle->intention_id,
                (string) $selected->action_kind,
                is_array($selected->arguments) ? $selected->arguments : [],
                (string) $selected->description,
                (string) $selected->expected,
                isset($inspection['procedure']['procedure_id'])
                    ? (int) $inspection['procedure']['procedure_id']
                    : null,
                procedureMemoryId: isset($inspection['procedure']['memory_id'])
                    ? (int) $inspection['procedure']['memory_id']
                    : null,
                decisionCycleId: (int) $cycle->id
            );
        } catch (Throwable $throwable) {
            $durable = DecisionCycle::getByID((int) $cycle->id);
            if ($durable instanceof DecisionCycle
                && (int) (((array) $durable->execution)['action_id'] ?? 0) === 0
                && $this->decisionInputChanges((int) $cycle->id) !== []) {
                return $this->fail($durable, 'Decision admission changed; start a fresh cycle: ' . $throwable->getMessage());
            }
            throw $throwable;
        }
        $actionId = (int) ($outcome['started']['action']['id'] ?? 0);
        $durableCycle = DecisionCycle::getByID((int) $cycle->id);
        if (!$durableCycle instanceof DecisionCycle) {
            throw new RuntimeException('Decision cycle disappeared after action dispatch.');
        }
        $durableExecution = is_array($durableCycle->execution) ? $durableCycle->execution : [];
        if ((int) ($durableExecution['action_id'] ?? 0) !== $actionId) {
            throw new RuntimeException('Decision cycle lost its pre-dispatch action binding.');
        }
        $actionExecution = \NaviBrain\Model\ActionExecution::getByField('action_trace_id', $actionId);
        if ($actionExecution instanceof \NaviBrain\Model\ActionExecution
            && in_array($actionExecution->status, ['succeeded', 'failed', 'cancelled'], true)
        ) {
            $finished = is_array($outcome['finished'] ?? null)
                ? $outcome['finished']
                : $this->core->proceduralMemory()->finishDurableAction($actionId);
            return $this->completeAsyncAction(
                $actionId,
                (string) $actionExecution->status === 'succeeded'
                    && (int) $actionExecution->verified === 1,
                $finished
            );
        }
        if ((string) $durableCycle->state === 'verify') {
            return [
                'status' => (string) $durableCycle->status,
                'cycle' => $durableCycle->getData(),
                'selected' => $selected->getData(),
                'execution' => $outcome,
            ];
        }
        if (in_array($durableCycle->status, ['completed', 'failed', 'cancelled'], true)) {
            return ['status' => (string) $durableCycle->status, 'cycle' => $durableCycle->getData()];
        }
        return [
            'status' => ($outcome['status'] ?? null) === 'held' ? 'held' : 'in_progress',
            'cycle' => $durableCycle->getData(),
            'selected' => $selected->getData(),
            'execution' => $outcome,
        ];
    }

    /** @return array<string, mixed> */
    public function completeAsyncAction(int $actionId, bool $matched, array $finished): array
    {
        $cycleId = $this->core->decisionCycleForAsyncAction($actionId);
        if ($cycleId === null) {
            // Pre-v21 cycles have no indexed claim. Scan only the outstanding
            // set once, then install a durable legacy binding before finish.
            foreach (DecisionCycle::getAllByWhere(
                ['status' => 'waiting', 'state' => 'verify'],
                ['order' => ['id' => 'ASC']]
            ) as $cycle) {
                $execution = is_array($cycle->execution) ? $cycle->execution : [];
                if ((int) ($execution['action_id'] ?? 0) !== $actionId) {
                    continue;
                }
                $actionExecution = \NaviBrain\Model\ActionExecution::getByField(
                    'action_trace_id',
                    $actionId
                );
                $requestEventId = $actionExecution instanceof \NaviBrain\Model\ActionExecution
                    ? (int) ($actionExecution->dispatch_event_id ?? 0)
                    : 0;
                $this->core->bindLegacyDecisionAsyncClaim(
                    (int) $cycle->id,
                    $actionId,
                    $requestEventId
                );
                $cycleId = (int) $cycle->id;
                break;
            }
        }
        if ($cycleId !== null) {
            $cycle = DecisionCycle::getByID($cycleId);
            if (!$cycle instanceof DecisionCycle) {
                throw new RuntimeException('Asynchronous decision claim points to a missing cycle.');
            }
            $execution = is_array($cycle->execution) ? $cycle->execution : [];
            if ((int) ($execution['action_id'] ?? 0) !== $actionId) {
                throw new RuntimeException('Asynchronous decision claim crossed its action identity.');
            }
            return $this->core->finalizeAsyncDecisionCycle($cycleId, $actionId, $matched, $finished);
        }
        return ['status' => 'no_waiting_decision_cycle'];
    }

    /**
     * Native upkeep: finish only proven non-attempts after integration ended.
     * No dispatching recovery, legacy scan, adapter invocation or model queue.
     *
     * @return list<array<string, mixed>>
     */
    public function reconcileUnattemptedActions(int $limit = 16): array
    {
        $reconciled = [];
        foreach ($this->core->pendingDecisionActionClaims($limit, true) as $claim) {
            $actionId = (int) $claim['action_id'];
            try {
                if (!($claim['integration_settled'] ?? false)) {
                    continue;
                }
                $execution = \NaviBrain\Model\ActionExecution::getByField('action_trace_id', $actionId);
                if (!$execution instanceof \NaviBrain\Model\ActionExecution) {
                    throw new RuntimeException('Decision action claim points to a missing execution.');
                }
                if ($execution->status === 'pending') {
                    if (!in_array($claim['status'], ['started', 'held'], true)
                        || $this->core->rejectSettledDecisionPendingAction(
                            (int) $claim['decision_cycle_id'], $actionId
                        ) === null) {
                        continue;
                    }
                    $execution = \NaviBrain\Model\ActionExecution::getByField('action_trace_id', $actionId);
                }
                if (!$execution instanceof \NaviBrain\Model\ActionExecution
                    || !in_array($execution->status, ['failed', 'cancelled'], true)
                    || (((array) $execution->observed)['dispatch_not_attempted'] ?? false) !== true) {
                    continue;
                }
                $finished = $this->core->proceduralMemory()->finishDurableAction($actionId);
                $reconciled[] = ['action_id' => $actionId,
                    'result' => $this->completeAsyncAction($actionId, false, $finished)];
            } catch (Throwable $error) {
                $reconciled[] = ['action_id' => $actionId, 'status' => 'recovery_blocked',
                    'error' => $error->getMessage()];
            }
        }
        return $reconciled;
    }

    /**
     * Close crash-stranded decision actions without ever re-running an adapter.
     * Pending actions become an explicit refusal after their owner exits, or
     * after resume when the owner explicitly returned a durable held action.
     * Dead/same-owner dispatches
     * use the action ledger's atomic indeterminate outcome; terminal actions
     * replay their durable finish into the exact decision claim.
     *
     * @return list<array<string, mixed>>
     */
    public function reconcileAsyncActions(int $limit = 64): array
    {
        $reconciled = [];
        foreach ($this->core->pendingDecisionActionClaims($limit) as $claim) {
            $actionId = (int) $claim['action_id'];
            $execution = \NaviBrain\Model\ActionExecution::getByField('action_trace_id', $actionId);
            if (!$execution instanceof \NaviBrain\Model\ActionExecution) {
                throw new RuntimeException('Decision action claim points to a missing execution.');
            }
            if ((string) $execution->status === 'pending') {
                if (ExecutiveControl::status()['paused']
                    || ($claim['owner_live'] && $claim['status'] !== 'held'
                        && !($claim['status'] === 'started' && ($claim['integration_settled'] ?? false)))) {
                    continue;
                }
                if (in_array($claim['status'], ['started', 'held'], true)
                    && ($claim['integration_settled'] ?? false)) {
                    if ($this->core->rejectSettledDecisionPendingAction(
                        (int) $claim['decision_cycle_id'], $actionId
                    ) === null) {
                        continue;
                    }
                } else {
                    $observed = $claim['status'] === 'held'
                        ? [
                            'error' => 'Decision dispatch was held by cognition pause; start a fresh cycle.',
                            'dispatch_not_attempted' => true,
                        ]
                        : [
                            'error' => 'Decision worker exited before its bound adapter dispatch began.',
                            'dispatch_not_attempted' => true,
                        ];
                    // Pause may arrive after the observation above. Its final
                    // check belongs to the same transaction as the pending CAS.
                    $this->core->rejectPendingActionExecution($actionId, $observed, holdDuringPause: true);
                }
                $execution = \NaviBrain\Model\ActionExecution::getByField('action_trace_id', $actionId);
            } elseif ((string) $execution->status === 'dispatching') {
                $dispatch = $this->core->claimActionDispatch($actionId);
                if (!$dispatch['recover']) {
                    continue;
                }
                $execution = \NaviBrain\Model\ActionExecution::getByField('action_trace_id', $actionId);
            }
            if (!$execution instanceof \NaviBrain\Model\ActionExecution
                || !in_array($execution->status, ['succeeded', 'failed', 'cancelled'], true)
            ) {
                continue;
            }
            $finished = $this->core->proceduralMemory()->finishDurableAction($actionId);
            $reconciled[] = $this->completeAsyncAction(
                $actionId,
                (string) $execution->status === 'succeeded' && (int) $execution->verified === 1,
                $finished
            );
        }

        // Pre-v21 waiting cycles cannot be indexed because their serialized
        // action identity was not normalized. This scans only outstanding
        // cycles and installs the durable claim before terminal replay.
        foreach (DecisionCycle::getAllByWhere(
            ['status' => 'waiting', 'state' => 'verify'],
            ['order' => ['id' => 'ASC'], 'limit' => max(1, min(512, $limit))]
        ) as $cycle) {
            $cycleExecution = is_array($cycle->execution) ? $cycle->execution : [];
            $actionId = (int) ($cycleExecution['action_id'] ?? 0);
            if ($actionId < 1 || $this->core->decisionCycleForAsyncAction($actionId) !== null) {
                continue;
            }
            $execution = \NaviBrain\Model\ActionExecution::getByField('action_trace_id', $actionId);
            if (!$execution instanceof \NaviBrain\Model\ActionExecution
                || !in_array($execution->status, ['succeeded', 'failed', 'cancelled'], true)
                || (int) ($execution->dispatch_event_id ?? 0) < 1
            ) {
                continue;
            }
            $this->core->bindLegacyDecisionAsyncClaim(
                (int) $cycle->id,
                $actionId,
                (int) $execution->dispatch_event_id
            );
            $finished = $this->core->proceduralMemory()->finishDurableAction($actionId);
            $reconciled[] = $this->completeAsyncAction(
                $actionId,
                (string) $execution->status === 'succeeded' && (int) $execution->verified === 1,
                $finished
            );
        }
        return $reconciled;
    }

    /** @return array<string, mixed> */
    public function failWork(array $work, string $error): array
    {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $cycleId = (int) ($refs['decision_cycle_id'] ?? 0);
        $cycle = DecisionCycle::getByID($cycleId);
        if (!$cycle instanceof DecisionCycle) {
            return ['status' => 'not_applicable'];
        }
        if ((int) $cycle->proposal_work_item_id !== (int) ($work['id'] ?? 0)) {
            throw new RuntimeException('Decision failure work item does not match its cycle.');
        }
        // Failed inference competes with result integration for the same state.
        // Late failures cannot replace an admitted result or a bound action.
        $statement = Connections::getConnection()->prepare(
            "UPDATE decision_cycles
             SET state = 'failed', status = 'failed', error = :error,
                 completed_at = :completed_at, updated_at = :updated_at
             WHERE id = :id AND proposal_work_item_id = :work_id
               AND status = 'waiting' AND state = 'reason'"
        );
        $now = date('Y-m-d H:i:s');
        $statement->execute([
            'error' => $error,
            'completed_at' => $now,
            'updated_at' => $now,
            'id' => $cycleId,
            'work_id' => (int) ($work['id'] ?? 0),
        ]);
        $failed = $statement->rowCount() === 1;
        $cycle = DecisionCycle::getByID($cycleId);
        if (!$cycle instanceof DecisionCycle) {
            throw new RuntimeException('Decision cycle disappeared during failure admission.');
        }
        if (!$failed) {
            return ['status' => 'already_integrated', 'cycle' => $cycle->getData()];
        }
        $event = $this->core->emitEvent('decision_cycle.failed', [
            'decision_cycle_id' => $cycleId,
            'failed_state' => 'reason',
            'model_id' => $cycle->model_id,
            'error' => $error,
        ]);
        return ['status' => 'failed', 'cycle' => $cycle->getData(), 'event' => $event];
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $status = null, int $limit = 20): array
    {
        if ($status !== null && !in_array($status, ['running', 'waiting', 'completed', 'impasse', 'failed', 'cancelled'], true)) {
            throw new InvalidArgumentException('decision status is invalid.');
        }
        $options = ['order' => ['id' => 'DESC'], 'limit' => max(1, min(200, $limit))];
        $cycles = $status === null
            ? DecisionCycle::getAll($options)
            : DecisionCycle::getAllByWhere(['status' => $status], $options);
        return array_values(array_map(static fn (DecisionCycle $cycle): array => $cycle->getData(), $cycles));
    }

    /** @return array<string, mixed> */
    public function show(int $id): array
    {
        $cycle = DecisionCycle::getByID($id);
        if (!$cycle instanceof DecisionCycle) {
            throw new RuntimeException(sprintf('Decision cycle %d does not exist.', $id));
        }
        $candidates = DecisionCandidate::getAllByWhere(
            ['decision_cycle_id' => $id],
            ['order' => ['rank' => 'ASC']]
        );
        return [
            'cycle' => $cycle->getData(),
            'candidates' => array_values(array_map(
                static fn (DecisionCandidate $candidate): array => $candidate->getData(),
                $candidates
            )),
        ];
    }

    /** Model-neutral outcome/timing surface for backend comparisons. */
    public function compare(int $limit = 200): array
    {
        $groups = [];
        $pending = 0;
        foreach (DecisionCycle::getAll(['order' => ['id' => 'DESC'], 'limit' => max(1, min(2000, $limit))]) as $cycle) {
            if (in_array($cycle->status, ['running', 'waiting'], true)) {
                $pending++;
                continue;
            }
            if (!in_array($cycle->status, ['completed', 'impasse', 'failed'], true)) {
                continue;
            }
            $model = trim((string) $cycle->model_id);
            $model = $model === '' ? 'unassigned' : $model;
            $group = $groups[$model] ?? [
                'model_id' => $model,
                'cycles' => 0,
                'completed' => 0,
                'impasses' => 0,
                'failed' => 0,
                'verified_successes' => 0,
                'retrieval_with_procedure' => 0,
                'selected_with_procedure' => 0,
                'candidate_total' => 0,
                'total_ms' => 0,
            ];
            $group['cycles']++;
            $group['completed'] += $cycle->status === 'completed' ? 1 : 0;
            $group['impasses'] += $cycle->status === 'impasse' ? 1 : 0;
            $group['failed'] += $cycle->status === 'failed' ? 1 : 0;
            $verification = is_array($cycle->verification) ? $cycle->verification : [];
            $group['verified_successes'] += ($verification['matched'] ?? false) === true ? 1 : 0;
            $retrieval = is_array($cycle->retrieval) ? $cycle->retrieval : [];
            $group['retrieval_with_procedure'] += isset($retrieval['procedure_id']) ? 1 : 0;
            $selection = is_array($cycle->selection) ? $cycle->selection : [];
            $group['selected_with_procedure'] += isset($selection['procedure_id']) ? 1 : 0;
            $evaluation = is_array($cycle->evaluation) ? $cycle->evaluation : [];
            $group['candidate_total'] += count((array) ($evaluation['candidate_ids'] ?? []));
            $timings = is_array($cycle->stage_timings) ? $cycle->stage_timings : [];
            $group['total_ms'] += (int) ($timings['total_ms'] ?? array_sum(array_map('intval', $timings)));
            $groups[$model] = $group;
        }
        foreach ($groups as $model => $group) {
            $cycles = max(1, (int) $group['cycles']);
            $groups[$model]['completion_rate'] = round($group['completed'] / $cycles, 4);
            $groups[$model]['impasse_rate'] = round($group['impasses'] / $cycles, 4);
            $groups[$model]['verified_success_rate'] = round($group['verified_successes'] / $cycles, 4);
            $groups[$model]['retrieval_procedure_reuse_rate'] = round($group['retrieval_with_procedure'] / $cycles, 4);
            $groups[$model]['procedure_reuse_rate'] = round($group['selected_with_procedure'] / $cycles, 4);
            $groups[$model]['mean_candidates'] = round($group['candidate_total'] / $cycles, 2);
            $groups[$model]['mean_total_ms'] = round($group['total_ms'] / $cycles, 2);
            unset($groups[$model]['candidate_total'], $groups[$model]['total_ms']);
        }
        return [
            'protocol' => 'decision-cycle-v1',
            'pending_cycles_excluded' => $pending,
            'by_model' => array_values($groups),
        ];
    }

    /** @return array<string, mixed> */
    private function finishVerified(DecisionCycle $cycle, array $outcome): array
    {
        $started = hrtime(true);
        $matched = ($outcome['status'] ?? null) === 'succeeded';
        $finished = is_array($outcome['finished'] ?? null) ? $outcome['finished'] : [];
        $action = is_array($finished['action'] ?? null) ? $finished['action'] : [];
        if ($action === []) {
            $execution = is_array($cycle->execution) ? $cycle->execution : [];
            $trace = ActionTrace::getByID((int) ($execution['action_id'] ?? 0));
            $action = $trace instanceof ActionTrace ? $trace->getData() : [];
        }
        $cycle->setField('verification', [
            'matched' => $matched,
            'action_id' => $action['id'] ?? null,
            'status' => $action['status'] ?? ($outcome['status'] ?? null),
            'match_status' => $action['match_status'] ?? ($matched ? 'matched' : 'mismatched'),
            'observed' => $action['observed'] ?? null,
        ]);
        $this->advance($cycle, 'adapt', 'verify', $this->elapsedMs($started));

        $started = hrtime(true);
        $learned = is_array($finished['procedure'] ?? null) ? $finished['procedure'] : [];
        $cycle->setField('adaptation', [
            'procedure_compiled_or_updated' => $learned !== [],
            'procedure_id' => $learned['procedure']['id'] ?? null,
            'memory_id' => $learned['memory']['id'] ?? null,
            'invalidating_failure_observed' => !$matched,
        ]);
        $this->advance($cycle, 'complete', 'adapt', $this->elapsedMs($started));
        $timings = is_array($cycle->stage_timings) ? $cycle->stage_timings : [];
        $timings['total_ms'] = array_sum(array_map('intval', $timings));
        $cycle->setFields([
            'status' => 'completed',
            'completed_at' => time(),
            'stage_timings' => $timings,
            'updated_at' => time(),
        ]);
        $cycle->save();
        $selection = is_array($cycle->selection) ? $cycle->selection : [];
        $event = $this->core->emitEvent('decision_cycle.completed', [
            'decision_cycle_id' => (int) $cycle->id,
            'model_id' => $cycle->model_id,
            'matched' => $matched,
            'selected_action_kind' => $selection['action_kind'] ?? null,
            'total_ms' => $timings['total_ms'],
        ]);
        return ['status' => 'completed', 'cycle' => $cycle->getData(), 'event' => $event];
    }

    /**
     * Add references from the exact workspace appended by enqueueWork. Occupant
     * changes alone are not invalidation: validate the referenced source record.
     * Access counters, update timestamps, and whole-workspace checksums are
     * excluded; memory expiry remains an explicit eligibility constraint.
     *
     * @param list<array<string, mixed>> $workspace
     * @param array<int, array<string, mixed>|null> $memoryObservations Exact queue-local reads, never worker input refs.
     */
    public function prepareWorkspaceDependencies(
        int $cycleId,
        array &$workspace,
        array $memoryObservations = []
    ): array
    {
        $cycle = DecisionCycle::getByID($cycleId);
        if (!$cycle instanceof DecisionCycle) {
            throw new RuntimeException('Cannot capture inputs for a missing decision cycle.');
        }
        if ($cycle->status !== 'running' || $cycle->state !== 'reason'
            || $cycle->proposal_work_item_id !== null) {
            throw new DecisionPreparationChanged('Decision cycle is not preparing its first reasoning work item.');
        }
        $observations = (array) $cycle->observations;
        $originalHash = hash('sha256', serialize($observations));
        $snapshot = $observations['input_snapshot'] ?? null;
        if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== 1) {
            throw new RuntimeException('Decision input snapshot is missing; start a fresh cycle.');
        }
        foreach ($workspace as &$slot) {
            $type = (string) ($slot['record_type'] ?? '');
            $type = match ($type) {
                'procedure' => 'memory',
                'sense_edge' => 'sense_event',
                default => $type,
            };
            if (in_array($type, ['memory', 'intention', 'sense_event'], true)) {
                $id = (int) ($slot['record_id'] ?? 0);
                if ($type === 'memory' && array_key_exists($id, $memoryObservations)) {
                    $observed = $memoryObservations[$id];
                    if ($observed !== null && (!is_array($observed)
                        || (int) ($observed['id'] ?? 0) !== $id)) {
                        throw new RuntimeException('Workspace source observation has a mismatched identity.');
                    }
                    $data = $observed === null ? null : $this->dependencyData($type, $id, $observed);
                } else {
                    $data = $this->dependencyData($type, $id);
                }
                if (!isset($snapshot['dependencies'][$type . ':' . $id])) {
                    $snapshot['dependencies'][$type . ':' . $id] = [
                        'type' => $type, 'id' => $id, 'hash' => hash('sha256', $this->encode($data)),
                    ];
                }
                // A cached slot can predate an in-place source correction.
                // Render the very record fingerprinted above, never bless old
                // slot text with a newer source hash. Existing retrieval hashes
                // are retained so inconsistent inputs still reject.
                if ($data !== null) {
                    $slot['claim'] = match ($type) {
                        'memory' => mb_substr((string) $data['content'], 0, 800),
                        'sense_event' => mb_substr((string) $data['summary'], 0, 800),
                        'intention' => PlainText::render($data, 3000, 12),
                    };
                    if ($type === 'memory') {
                        $slot['confidence'] = (float) $data['confidence'];
                    }
                }
            }
        }
        unset($slot);
        $observations['input_snapshot'] = $snapshot;
        return [
            'cycle_id' => $cycleId,
            'intention_id' => (int) $cycle->intention_id,
            'thread_id' => $cycle->thread_id === null ? null : (int) $cycle->thread_id,
            'original_observations_hash' => $originalHash,
            'observations' => $observations,
        ];
    }

    /**
     * Read-only freshness gate, also called at the executive dispatch boundary.
     * SQLite callers can fence their own state, but native memory is a separate
     * store: this is a last-moment check, not a cross-store atomic transaction.
     * Unknown/legacy snapshots fail closed without trusting worker-supplied refs.
     *
     * @return list<string>
     */
    public function decisionInputChanges(int $cycleId): array
    {
        $cycle = DecisionCycle::getByID($cycleId);
        if (!$cycle instanceof DecisionCycle) {
            return ['decision cycle is missing'];
        }
        $snapshot = ((array) $cycle->observations)['input_snapshot'] ?? null;
        if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== 1
            || !is_array($snapshot['dependencies'] ?? null)
            || !isset($snapshot['dependencies']['intention:' . (int) $cycle->intention_id])) {
            return ['decision input snapshot is missing or unsupported'];
        }
        $changes = [];
        foreach ($snapshot['dependencies'] as $key => $dependency) {
            if (!is_array($dependency)
                || !is_string($dependency['type'] ?? null)
                || !in_array($dependency['type'], ['intention', 'memory', 'sense_event'], true)
                || !is_int($dependency['id'] ?? null) || $dependency['id'] < 1
                || $key !== $dependency['type'] . ':' . $dependency['id']
                || !is_string($dependency['hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $dependency['hash']) !== 1) {
                $changes[] = 'decision input snapshot contains a malformed dependency';
                continue;
            }
            $current = $this->dependencyData((string) $dependency['type'], (int) $dependency['id']);
            if ($current === null || !hash_equals((string) $dependency['hash'], hash('sha256', $this->encode($current)))) {
                $changes[] = $key . ' changed or disappeared';
            } elseif ($dependency['type'] === 'memory' && ($current['status'] ?? null) !== 'active') {
                $changes[] = $key . ' is not active';
            } elseif ($dependency['type'] === 'memory' && !$current['expiry_valid']) {
                $changes[] = $key . ' has an invalid expiry';
            } elseif ($dependency['type'] === 'memory' && $current['expires_at'] !== null
                && $current['expires_at'] <= time()) {
                $changes[] = $key . ' has expired';
            }
        }
        $intention = Intention::getByID((int) $cycle->intention_id);
        if (!$intention instanceof Intention || $intention->status !== 'active') {
            $changes[] = 'intention is no longer active';
        }
        if ($cycle->thread_id !== null) {
            $thread = CognitiveThread::getByID((int) $cycle->thread_id);
            if (!$thread instanceof CognitiveThread || (int) $thread->parent_intention_id !== (int) $cycle->intention_id) {
                $changes[] = 'decision thread ownership changed';
            }
        }
        return $changes;
    }

    /** @param array<string, mixed> $snapshot
     *  @param array<string, mixed>|null $supplied Actual retrieval result, before truncation.
     */
    private function captureDependency(array &$snapshot, string $type, int $id, ?array $supplied = null): void
    {
        if ($id < 1 || isset($snapshot['dependencies'][$type . ':' . $id])) {
            return;
        }
        $data = $this->dependencyData($type, $id, $supplied);
        $snapshot['dependencies'][$type . ':' . $id] = [
            'type' => $type, 'id' => $id, 'hash' => hash('sha256', $this->encode($data)),
        ];
    }

    /** @param array<string, mixed>|null $supplied
     *  @return array<string, mixed>|null
     */
    private function dependencyData(string $type, int $id, ?array $supplied = null): ?array
    {
        $fields = match ($type) {
            'intention' => ['title', 'reason', 'authority', 'status', 'next_action',
                'success_condition', 'release_condition', 'dependencies', 'parent_id'],
            'memory' => ['tier', 'status', 'content', 'confidence', 'source_event_id', 'source_event_kind',
                'source_memory_id', 'supersedes_id', 'expires_at'],
            // Outcome/consumption and tuning telemetry do not revise the evidence.
            'sense_event' => ['sense_key', 'source_key', 'summary', 'before', 'after', 'reading_id'],
            default => throw new RuntimeException('Unsupported decision input dependency: ' . $type),
        };
        if ($supplied === null) {
            $record = match ($type) {
                'intention' => Intention::getByID($id),
                'memory' => Memory::inspectByID($id),
                'sense_event' => SenseEvent::getByID($id),
            };
            if ($record === null) {
                return null;
            }
            $supplied = $record->getData();
        }
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $supplied[$field] ?? null;
        }
        if ($type === 'memory') {
            $expiresAt = $this->timestamp($data['expires_at']);
            $data['expiry_valid'] = $data['expires_at'] === null || $expiresAt !== null;
            $data['expires_at'] = $expiresAt;
        }
        if ($type === 'sense_event') {
            $sources = SensorySource::getAllByWhere(['source_key' => (string) ($supplied['source_key'] ?? '')], ['limit' => 1]);
            $source = $sources[0] ?? null;
            $data['source_grant'] = $source instanceof SensorySource ? [
                'authority' => $source->authority, 'status' => $source->status,
                'reveals' => $source->reveals, 'effect_ceiling' => $source->effect_ceiling,
                'acquisition' => $source->acquisition,
            ] : null;
        }
        return $data;
    }

    /** @return array<string, mixed> */
    private function fail(DecisionCycle $cycle, string $error): array
    {
        $failedState = (string) $cycle->state;
        $cycle->setFields([
            'state' => 'failed',
            'status' => 'failed',
            'error' => $error,
            'completed_at' => time(),
            'updated_at' => time(),
        ]);
        $cycle->save();
        $event = $this->core->emitEvent('decision_cycle.failed', [
            'decision_cycle_id' => (int) $cycle->id,
            'failed_state' => $failedState,
            'model_id' => $cycle->model_id,
            'error' => $error,
        ]);
        return ['status' => 'failed', 'cycle' => $cycle->getData(), 'event' => $event];
    }

    /** @return array<string, mixed> */
    private function impasse(DecisionCycle $cycle, string $reason): array
    {
        $now = time();
        $cycle->setFields([
            'state' => 'impasse',
            'status' => 'impasse',
            'error' => $reason,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
        $cycle->save();
        $event = $this->core->emitEvent('decision_cycle.impasse', [
            'decision_cycle_id' => (int) $cycle->id,
            'model_id' => $cycle->model_id,
            'reason' => $reason,
            'reconsider_on_next_cycle' => true,
        ]);
        return ['status' => 'impasse', 'cycle' => $cycle->getData(), 'event' => $event];
    }

    private function advance(DecisionCycle $cycle, string $next, string $completed, int $elapsedMs): void
    {
        $timings = is_array($cycle->stage_timings) ? $cycle->stage_timings : [];
        $timings[$completed . '_ms'] = (int) ($timings[$completed . '_ms'] ?? 0) + max(0, $elapsedMs);
        $cycle->setFields(['state' => $next, 'stage_timings' => $timings, 'updated_at' => time()]);
        $cycle->save();
        $this->core->emitEvent('decision_cycle.transition', [
            'decision_cycle_id' => (int) $cycle->id,
            'completed_state' => $completed,
            'next_state' => $next,
            'elapsed_ms' => $elapsedMs,
        ]);
    }

    private function retrievalText(DecisionCycle $cycle): string
    {
        $retrieval = is_array($cycle->retrieval) ? $cycle->retrieval : [];
        return implode(' ', array_map(
            static fn (array $memory): string => (string) ($memory['content'] ?? ''),
            array_filter((array) ($retrieval['memories'] ?? []), 'is_array')
        ));
    }

    private function groundingScore(string $candidate, string $context): float
    {
        $words = static fn (string $text): array => array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [],
            static fn (string $word): bool => mb_strlen($word) > 3
        )));
        $candidateWords = $words($candidate);
        $contextWords = $words($context);
        if ($candidateWords === [] || $contextWords === []) {
            return 0.0;
        }
        return count(array_intersect($candidateWords, $contextWords)) / count($candidateWords);
    }

    private function actionClassBonus(string $actionClass): float
    {
        return match ($actionClass) {
            'grounding' => 0.1,
            'learning' => 0.08,
            default => -0.2,
        };
    }

    private function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    private function timestamp(mixed $value): ?int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $parsed = strtotime($value);
        return $parsed === false ? null : $parsed;
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
