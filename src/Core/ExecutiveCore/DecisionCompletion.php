<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use InvalidArgumentException;
use NaviBrain\Core\ExecutiveControl;
use NaviBrain\Model\ActionDispatchClaim;
use NaviBrain\Model\ActionExecution;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\DecisionAsyncClaim;
use NaviBrain\Model\DecisionCycle;
use NaviBrain\Model\DecisionIntegrationClaim;
use NaviBrain\Model\Procedure;
use RuntimeException;

class DecisionCompletion extends Component
{
    public function decisionCycleForAsyncAction(int $actionId): ?int
    {

        $claim = DecisionAsyncClaim::getByField('action_id', $actionId);
        return $claim instanceof DecisionAsyncClaim ? (int) $claim->decision_cycle_id : null;
    }

    public function pendingDecisionActionClaims(int $limit = 64, bool $unattemptedScan = false): array
    {
        $limit = max(1, min(512, $limit));

        $rows = $this->decisionRecoveryPage($unattemptedScan ? 'unattempted_action' : 'action', $limit);
        return array_values(array_map(fn (array $row): array => [
            'action_id' => (int) $row['action_id'],
            'decision_cycle_id' => (int) $row['decision_cycle_id'],
            'status' => (string) $row['status'],
            'owner_live' => $this->dispatchOwnerIsLive((string) $row['owner']),
            'integration_settled' => (int) $row['integration_settled'] === 1,
        ], $rows));
    }

    public function rejectSettledDecisionPendingAction(int $cycleId, int $actionId): ?array
    {
        if ($cycleId < 1 || $actionId < 1) {
            throw new InvalidArgumentException('Decision refusal requires positive cycle/action IDs.');
        }
        if (ExecutiveControl::status()['paused']) {
            return null;
        }
        $integration = DecisionIntegrationClaim::getByQuery(
            "SELECT integration.* FROM decision_async_claims AS async
             JOIN decision_integration_claims AS integration
               ON integration.cycle_id = async.decision_cycle_id
              AND integration.owner = async.owner AND integration.settled = 1
             WHERE async.action_id = " . $actionId . ' AND async.decision_cycle_id = ' . $cycleId . "
               AND async.status IN ('started','held') AND async.request_event_id IS NULL"
        );
        $workId = $integration instanceof DecisionIntegrationClaim ? $integration->work_id : false;
        if ($workId === false) {
            return null;
        }
        $cycle = DecisionCycle::getByID($cycleId);
        $action = ActionTrace::getByID($actionId);
        $execution = ActionExecution::getByField('action_trace_id', $actionId);
        if (!$cycle instanceof DecisionCycle || (int) $cycle->proposal_work_item_id !== (int) $workId
            || (int) (((array) $cycle->execution)['action_id'] ?? 0) !== $actionId
            || !$action instanceof ActionTrace || (int) $action->intention_id !== (int) $cycle->intention_id
            || !$execution instanceof ActionExecution || $execution->status !== 'pending'
            || $execution->dispatch_event_id !== null) {
            return null;
        }

        if (ActionDispatchClaim::getByField('action_trace_id', $actionId) instanceof ActionDispatchClaim) {
            return null;
        }

        return $this->rejectPendingActionExecution($actionId, [
            'error' => 'Decision integration ended before its bound adapter dispatch began; start a fresh cycle.',
            'dispatch_not_attempted' => true,
        ], holdDuringPause: true);
    }

    public function bindLegacyDecisionAsyncClaim(int $decisionCycleId, int $actionId, int $requestEventId): void
    {
        if ($decisionCycleId < 1 || $actionId < 1 || $requestEventId < 1) {
            throw new InvalidArgumentException('Legacy asynchronous decision identity is invalid.');
        }
        $claim = DecisionAsyncClaim::getByField('action_id', $actionId);
        if (!$claim instanceof DecisionAsyncClaim) {
            $claim = new DecisionAsyncClaim([
                'action_id' => $actionId,
                'decision_cycle_id' => $decisionCycleId,
                'request_event_id' => $requestEventId,
                'owner' => $this->dispatchOwner(),
                'status' => 'waiting',
            ], true, true);
            $claim->save();
        }
        if ((int) $claim->decision_cycle_id !== $decisionCycleId
            || (int) $claim->request_event_id !== $requestEventId
        ) {
            throw new RuntimeException('Legacy asynchronous decision claim conflicts with durable state.');
        }
    }

    public function finalizeAsyncDecisionCycle(int $decisionCycleId, int $actionId, bool $matched, array $finished): array
    {
        $claim = DecisionAsyncClaim::getByField('action_id', $actionId);

        if (!$claim instanceof DecisionAsyncClaim || (int) $claim->decision_cycle_id !== $decisionCycleId) {
            throw new RuntimeException('Asynchronous decision has no exact durable claim.');
        }
        $execution = ActionExecution::getByField('action_trace_id', $actionId);
        if (!$execution instanceof ActionExecution
            || ($claim->request_event_id !== null
                && (int) ($execution->dispatch_event_id ?? 0) !== (int) $claim->request_event_id)
            || !in_array($execution->status, ['succeeded', 'failed', 'cancelled'], true)
            || $matched !== ((string) $execution->status === 'succeeded'
                && (int) $execution->verified === 1)
        ) {
            throw new RuntimeException('Asynchronous decision outcome conflicts with its durable action.');
        }
        $outcomeHash = hash('sha256', serialize([
            'decision_cycle_id' => $decisionCycleId,
            'action_id' => $actionId,
            'matched' => $matched,
            'execution_status' => (string) $execution->status,
            'verified' => (int) $execution->verified,
            'observed' => (array) $execution->observed,
        ]));
        if ((string) $claim->status === 'completed') {
            if (!is_string($claim->outcome_hash)
                || !hash_equals($claim->outcome_hash, $outcomeHash)
            ) {
                throw new RuntimeException('Asynchronous decision already has a different outcome.');
            }
            $cycle = DecisionCycle::getByID($decisionCycleId);
            if (!$cycle instanceof DecisionCycle || (string) $cycle->status !== 'completed') {
                throw new RuntimeException('Completed asynchronous decision lost its terminal cycle.');
            }
            $event = $this->emitOnce('decision-cycle-completed:' . $decisionCycleId, 'decision_cycle.completed', $this->decisionCompletedPayload($cycle, $matched));
            return [
                'status' => 'completed',
                'cycle' => $cycle->getData(),
                'event' => $event->getData(),
                'replayed' => true,
            ];
        }
        if (!in_array((string) $claim->status, ['started', 'held', 'waiting'], true)) {
            throw new RuntimeException('Asynchronous decision completion is in an invalid state.');
        }
        $cycle = DecisionCycle::getByID($decisionCycleId);
        $cycleExecution = $cycle instanceof DecisionCycle && is_array($cycle->execution)
            ? $cycle->execution
            : [];
        $waiting = (string) $claim->status === 'waiting';
        if (!$cycle instanceof DecisionCycle
            || (string) $cycle->status !== ($waiting ? 'waiting' : 'running')
            || (string) $cycle->state !== ($waiting ? 'verify' : 'execute')
            || (int) ($cycleExecution['action_id'] ?? 0) !== $actionId
        ) {
            throw new RuntimeException('Asynchronous decision cycle is not waiting for its claimed action.');
        }

        $trace = ActionTrace::getByID($actionId);
        if (!$trace instanceof ActionTrace) {
            throw new RuntimeException('Asynchronous decision lost its durable action trace.');
        }
        $action = $trace->getData();
        $learned = is_array($finished['procedure'] ?? null) ? $finished['procedure'] : [];
        $learnedProcedureId = (int) ($learned['procedure']['id'] ?? 0);
        $learnedMemoryId = (int) ($learned['memory']['id'] ?? 0);
        $learnedProcedure = $learnedProcedureId > 0
            ? Procedure::getByID($learnedProcedureId)
            : null;
        if (!$learnedProcedure instanceof Procedure
            || (int) $learnedProcedure->memory_id !== $learnedMemoryId
            || !in_array($actionId, array_map('intval', (array) $learnedProcedure->evidence_action_ids), true)
        ) {
            $learnedProcedureId = 0;
            $learnedMemoryId = 0;
        }
        $timings = is_array($cycle->stage_timings) ? $cycle->stage_timings : [];
        $timings['verify_ms'] ??= 0;
        $timings['adapt_ms'] ??= 0;
        $timings['total_ms'] = array_sum(array_map('intval', $timings));
        $cycle->setFields([
            'verification' => [
                'matched' => $matched,
                'action_id' => $action['id'] ?? $actionId,
                'status' => $action['status'] ?? (string) $execution->status,
                'match_status' => $action['match_status'] ?? ($matched ? 'matched' : 'mismatched'),
                'observed' => $action['observed'] ?? null,
            ],
            'adaptation' => [
                'procedure_compiled_or_updated' => $learnedProcedureId > 0,
                'procedure_id' => $learnedProcedureId > 0 ? $learnedProcedureId : null,
                'memory_id' => $learnedMemoryId > 0 ? $learnedMemoryId : null,
                'invalidating_failure_observed' => !$matched
                    && (((array) $execution->observed)['dispatch_not_attempted'] ?? false) !== true,
            ],
            'state' => 'complete',
            'status' => 'completed',
            'completed_at' => time(),
            'stage_timings' => $timings,
            'updated_at' => time(),
        ]);
        $cycle->save();
        if (!$waiting) {
            $this->emitOnce(
                'decision-cycle-transition:' . $decisionCycleId . ':execute-verify',
                'decision_cycle.transition',
                [
                    'decision_cycle_id' => $decisionCycleId,
                    'completed_state' => 'execute',
                    'next_state' => 'verify',
                    'elapsed_ms' => 0,
                ]
            );
        }
        $this->emitOnce(
            'decision-cycle-transition:' . $decisionCycleId . ':verify-adapt',
            'decision_cycle.transition',
            [
                'decision_cycle_id' => $decisionCycleId,
                'completed_state' => 'verify',
                'next_state' => 'adapt',
                'elapsed_ms' => 0,
            ]
        );
        $this->emitOnce(
            'decision-cycle-transition:' . $decisionCycleId . ':adapt-complete',
            'decision_cycle.transition',
            [
                'decision_cycle_id' => $decisionCycleId,
                'completed_state' => 'adapt',
                'next_state' => 'complete',
                'elapsed_ms' => 0,
            ]
        );
        $event = $this->emitOnce('decision-cycle-completed:' . $decisionCycleId, 'decision_cycle.completed', $this->decisionCompletedPayload($cycle, $matched));

        $claim->setFields([ 'status' => 'completed', 'outcome_hash' => $outcomeHash ]);
        $claim->save();
        return ['status' => 'completed', 'cycle' => $cycle->getData(), 'event' => $event->getData()];
    }

    public function decisionCompletedPayload(DecisionCycle $cycle, bool $matched): array
    {
        $selection = is_array($cycle->selection) ? $cycle->selection : [];
        $timings = is_array($cycle->stage_timings) ? $cycle->stage_timings : [];
        return [
            'decision_cycle_id' => (int) $cycle->id,
            'model_id' => $cycle->model_id,
            'matched' => $matched,
            'selected_action_kind' => $selection['action_kind'] ?? null,
            'total_ms' => (int) ($timings['total_ms'] ?? 0),
        ];
    }
}
