<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use InvalidArgumentException;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\DecisionCandidate;
use NaviBrain\Model\DecisionCycle;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\WorkItem;
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

        $working = $this->core->workingMemory()->snapshot($threadId);
        if ($this->core->otherModel()->isAblated()) {
            $working = array_values(array_filter(
                $working,
                static fn (array $slot): bool => ($slot['slot_role'] ?? null) !== 'other_agent_state'
            ));
        }
        $workingJson = $this->encode($working);
        /** @var DecisionCycle $cycle */
        $cycle = $this->core->insertRecord(DecisionCycle::class, [
            'intention_id' => $intentionId,
            'thread_id' => $threadId,
            'trigger' => mb_substr($trigger, 0, 160),
            'model_id' => $modelHint,
            'state' => 'observe',
            'status' => 'running',
            'working_memory_checksum' => hash('sha256', $workingJson),
            'observations' => [],
            'retrieval' => [],
            'reasoning' => [],
            'evaluation' => [],
            'selection' => [],
            'execution' => [],
            'verification' => [],
            'adaptation' => [],
            'stage_timings' => [],
            'updated_at' => time(),
        ]);
        $this->core->emitEvent('decision_cycle.started', [
            'decision_cycle_id' => (int) $cycle->id,
            'intention_id' => $intentionId,
            'thread_id' => $threadId,
            'trigger' => $trigger,
            'working_memory_checksum' => (string) $cycle->working_memory_checksum,
            'model_hint' => $modelHint,
        ]);

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
        $cycle->setField('observations', $observations);
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
        $retrievalAction = $this->core->proceduralMemory()->executeAction(
            $intentionId,
            'memory.search',
            ['query' => $query, 'limit' => 8],
            'Retrieve long-term memory relevant to the active decision.',
            'A bounded, machine-verified result set is returned.'
        );
        if (($retrievalAction['status'] ?? null) !== 'succeeded') {
            return $this->fail($cycle, 'The installed retrieval procedure failed during planning.');
        }
        $retrievalObserved = is_array($retrievalAction['dispatch']['observed'] ?? null)
            ? $retrievalAction['dispatch']['observed']
            : [];
        $retrieved = [];
        foreach ((array) ($retrievalObserved['memory_ids'] ?? []) as $memoryId) {
            $memory = Memory::getByID((int) $memoryId);
            if (!$memory instanceof Memory || $memory->status !== 'active' || $memory->tier === 'working') {
                continue;
            }
            $retrieved[] = [
                'id' => (int) $memory->id,
                'tier' => (string) $memory->tier,
                'content' => mb_substr((string) $memory->content, 0, 320),
                'confidence' => (float) $memory->confidence,
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
            'Intention: ' . $this->encode([
                'title' => (string) $intention->title,
                'next_action' => (string) $intention->next_action,
                'success_condition' => (string) $intention->success_condition,
            ]),
            'Trigger: ' . $trigger,
            'Observations: ' . $this->encode($observations),
            'Retrieved memory: ' . $this->encode($retrieval['memories']),
            'Installed adapters: ' . $this->encode($adapters),
        ]);
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
        $timings = is_array($cycle->stage_timings) ? $cycle->stage_timings : [];
        $timings['reason_dispatch_ms'] = $this->elapsedMs($started);
        $cycle->setFields([
            'proposal_work_item_id' => (int) ($queued['work_item']['id'] ?? 0),
            'status' => 'waiting',
            'stage_timings' => $timings,
            'updated_at' => time(),
        ]);
        $cycle->save();
        $this->core->emitEvent('decision_cycle.waiting', [
            'decision_cycle_id' => (int) $cycle->id,
            'state' => 'reason',
            'work_item_id' => (int) $cycle->proposal_work_item_id,
        ]);
        return ['status' => 'waiting', 'cycle' => $cycle->getData(), 'work_item' => $queued['work_item'] ?? null];
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
        $cycle->setFields(['model_id' => $model, 'status' => 'running', 'updated_at' => time()]);
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
        $scope = $cycle->thread_id === null ? 'shared' : 'thread:' . (int) $cycle->thread_id;
        if ($currentPlan !== '') {
            $this->core->workingMemory()->publish(
                role: 'current_plan',
                claim: mb_substr($currentPlan, 0, 800),
                recordType: 'decision_cycle',
                recordId: (int) $cycle->id,
                confidence: (float) ($proposal['confidence'] ?? 0.0),
                ttlSeconds: 900,
                scope: $scope,
                threadId: $cycle->thread_id === null ? null : (int) $cycle->thread_id
            );
        }
        if ($decisionBasis !== '') {
            $this->core->workingMemory()->publish(
                role: 'decision_basis',
                claim: mb_substr($decisionBasis, 0, 800),
                recordType: 'decision_cycle',
                recordId: (int) $cycle->id,
                confidence: (float) ($proposal['confidence'] ?? 0.0),
                ttlSeconds: 900,
                scope: $scope,
                threadId: $cycle->thread_id === null ? null : (int) $cycle->thread_id
            );
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
            'reason' => 'Highest deterministic score among adapter-valid candidates.',
        ]);
        $this->advance($cycle, 'execute', 'select', $this->elapsedMs($started));

        $started = hrtime(true);
        $outcome = $this->core->proceduralMemory()->executeAction(
            (int) $cycle->intention_id,
            (string) $selected->action_kind,
            is_array($selected->arguments) ? $selected->arguments : [],
            (string) $selected->description,
            (string) $selected->expected,
            isset($inspection['procedure']['procedure_id'])
                ? (int) $inspection['procedure']['procedure_id']
                : null
        );
        $actionId = (int) ($outcome['started']['action']['id'] ?? 0);
        $cycle->setField('execution', [
            'candidate_id' => (int) $selected->id,
            'action_id' => $actionId,
            'status' => $outcome['status'] ?? 'failed',
            'adapter_dispatch' => $outcome['dispatch'] ?? null,
        ]);
        $this->advance($cycle, 'verify', 'execute', $this->elapsedMs($started));
        if (($outcome['status'] ?? null) === 'waiting') {
            $cycle->setFields(['status' => 'waiting', 'updated_at' => time()]);
            $cycle->save();
            return ['status' => 'waiting', 'cycle' => $cycle->getData(), 'selected' => $selected->getData(), 'execution' => $outcome];
        }
        return $this->finishVerified($cycle, $outcome);
    }

    /** @return array<string, mixed> */
    public function completeAsyncAction(int $actionId, bool $matched, array $finished): array
    {
        foreach (DecisionCycle::getAllByWhere(
            ['status' => 'waiting', 'state' => 'verify'],
            ['order' => ['id' => 'DESC'], 'limit' => 50]
        ) as $cycle) {
            $execution = is_array($cycle->execution) ? $cycle->execution : [];
            if ((int) ($execution['action_id'] ?? 0) !== $actionId) {
                continue;
            }
            return $this->finishVerified($cycle, [
                'status' => $matched ? 'succeeded' : 'failed',
                'finished' => $finished,
            ]);
        }
        return ['status' => 'no_waiting_decision_cycle'];
    }

    /** @return array<string, mixed> */
    public function failWork(array $work, string $error): array
    {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $cycle = DecisionCycle::getByID((int) ($refs['decision_cycle_id'] ?? 0));
        return $cycle instanceof DecisionCycle
            ? $this->fail($cycle, $error)
            : ['status' => 'not_applicable'];
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
