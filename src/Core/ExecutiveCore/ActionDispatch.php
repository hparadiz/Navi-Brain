<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use InvalidArgumentException;
use NaviBrain\Core\ExecutiveControl;
use NaviBrain\Model\ActionDispatchClaim;
use NaviBrain\Model\ActionExecution;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\DecisionAsyncClaim;
use NaviBrain\Model\Intention;
use RuntimeException;

class ActionDispatch extends Component
{
    public function claimActionDispatch(int $actionId): array
    {
        if ($actionId < 1) {
            throw new InvalidArgumentException('Action dispatch identity is invalid.');
        }
        $execution = ActionExecution::getByField('action_trace_id', $actionId);
        if (!$execution instanceof ActionExecution) {
            throw new RuntimeException('Action dispatch has no execution record.');
        }
        $owner = $this->dispatchOwner();
        if ($execution->status === 'pending') {
            if (ExecutiveControl::status()['paused']) {

                $holdRecord = DecisionAsyncClaim::getByWhere(['action_id' => $actionId, 'status = \'started\'', 'request_event_id' => null]);
                if ($holdRecord instanceof DecisionAsyncClaim) {
                    $holdRecord->setFields([ 'status' => 'held' ]);
                    $holdRecord->save();
                }
                return ['execution' => $execution->getData(), 'claimed' => false, 'recover' => false];
            }

            $decisionStatus = DecisionAsyncClaim::getByWhere(['action_id' => $actionId])?->status ?? false;

            if ($decisionStatus === 'held') {
                $rejected = $this->rejectPendingActionExecution($actionId, [ 'error' => 'Decision dispatch was held by cognition pause; start a fresh cycle.', 'dispatch_not_attempted' => true, ]);
                return ['execution' => $rejected, 'claimed' => false, 'recover' => false];
            }
            $action = ActionTrace::getByID($actionId);
            $intention = $action instanceof ActionTrace
                ? Intention::getByID((int) $action->intention_id)
                : null;
            $changes = !$intention instanceof Intention || $intention->status !== 'active'
                ? ['intention is no longer active']
                : [];
            $decisionCycleId = $this->decisionCycleForAsyncAction($actionId);
            if ($decisionCycleId !== null) {
                $changes = array_merge($changes, $this->executive->decisionStateMachine->decisionInputChanges($decisionCycleId));
            }
            if ($changes !== []) {
                $rejected = $this->rejectPendingActionExecution($actionId, [ 'error' => 'Action admission changed; start a fresh cycle: ' . implode('; ', $changes), ]);
                return ['execution' => $rejected, 'claimed' => false, 'recover' => false];
            }

            $claimRecord = new ActionDispatchClaim([ 'action_trace_id' => $actionId, 'owner' => $owner, 'claimed_at' => time(), 'status' => 'claimed', 'outcome_hash' => null, 'outcome_data' => null ], true, true);
            $claimRecord->save();

            $statementRecord = ActionExecution::getByWhere(['action_trace_id' => $actionId, 'status = \'pending\'', '(procedure_id IS NULL OR EXISTS (
                                       SELECT 1 FROM procedures
                                       WHERE procedures.id = action_executions.procedure_id
                                         AND procedures.memory_id = action_executions.procedure_memory_id
                                         AND procedures.status = \'active\'
                                   ))', '(procedure_run_id IS NULL OR EXISTS (
                                       SELECT 1
                                       FROM procedure_runs
                                       INNER JOIN procedures
                                         ON procedures.id = procedure_runs.procedure_id
                                        AND procedures.memory_id = procedure_runs.procedure_memory_id
                                        AND procedures.status = \'active\'
                                       WHERE procedure_runs.id = action_executions.procedure_run_id
                                         AND procedure_runs.status = \'running\'
                                   ))']);
            if (!$statementRecord instanceof ActionExecution) {
                throw new RuntimeException('Unable to claim action dispatch.');
            }
            $statementRecord->setFields([ 'status' => 'dispatching', 'updated_at' => date('Y-m-d H:i:s') ]);
            $statementRecord->save();
            $execution = ActionExecution::getByField('action_trace_id', $actionId);
            if (!$execution instanceof ActionExecution) {
                throw new RuntimeException('Claimed action dispatch disappeared.');
            }
            return ['execution' => $execution->getData(), 'claimed' => true, 'recover' => false];
        }
        if ($execution->status === 'dispatching') {

            $claimRow = ActionDispatchClaim::getByWhere(['action_trace_id' => $actionId])?->getData();

            $previousOwner = is_array($claimRow) ? $claimRow['owner'] ?? null : null;
            if (!is_string($previousOwner) || $previousOwner === '') {
                throw new RuntimeException('Dispatching action is missing its process claim.');
            }
            $previousStatus = (string) ($claimRow['status'] ?? '');
            if (!in_array($previousStatus, ['legacy', 'claimed'], true)) {
                throw new RuntimeException('Dispatching action has an invalid claim state.');
            }
            if ($previousStatus === 'claimed'
                && !hash_equals($previousOwner, $owner)
                && $this->dispatchOwnerIsLive($previousOwner)
            ) {
                return ['execution' => $execution->getData(), 'claimed' => false, 'recover' => false];
            }
            $observed = [
                'error' => $previousStatus === 'legacy'
                    ? 'Adapter dispatch predates the durable owner ledger; its outcome is indeterminate.'
                    : (hash_equals($previousOwner, $owner)
                        ? 'Adapter dispatch returned without durably staging its outcome.'
                        : 'Adapter dispatch outcome became indeterminate after its owning process exited.'),
            ];
            $outcomeHash = hash('sha256', serialize([ 'observed' => $observed, 'verified' => false, 'status' => 'failed', ]));

            $recoverRecord = ActionDispatchClaim::getByWhere(['action_trace_id' => $actionId, 'owner' => $previousOwner, 'status' => $previousStatus]);
            if (!$recoverRecord instanceof ActionDispatchClaim) {
                throw new RuntimeException('Unable to claim indeterminate dispatch recovery.');
            }
            $recoverRecord->setFields([ 'owner' => $owner, 'claimed_at' => time(), 'status' => 'completed', 'outcome_hash' => $outcomeHash, 'outcome_data' => serialize($observed) ]);
            $recoverRecord->save();
            $now = date('Y-m-d H:i:s');

            $terminalRecord = ActionExecution::getByWhere(['action_trace_id' => $actionId, 'status = \'dispatching\'']);
            if (!$terminalRecord instanceof ActionExecution) {
                throw new RuntimeException('Unable to close indeterminate action dispatch atomically.');
            }
            $terminalRecord->setFields([ 'observed' => $observed, 'verified' => 0, 'status' => 'failed', 'completed_at' => $now, 'updated_at' => $now ]);
            $terminalRecord->save();
            $execution = ActionExecution::getByField('action_trace_id', $actionId);
            if (!$execution instanceof ActionExecution) {
                throw new RuntimeException('Recovered action dispatch disappeared.');
            }
            return ['execution' => $execution->getData(), 'claimed' => false, 'recover' => true];
        }
        return [
            'execution' => $execution->getData(),
            'claimed' => false,
            'recover' => false,
        ];
    }

    public function finalizeActionExecution(int $actionId, array $observed, bool $verified, string $status): array
    {
        $this->requireChoice($status, ['succeeded', 'failed', 'cancelled'], 'action execution status');
        $outcomeHash = hash('sha256', serialize([ 'observed' => $observed, 'verified' => $verified, 'status' => $status, ]));
        $before = ActionExecution::getByField('action_trace_id', $actionId);
        if (!$before instanceof ActionExecution) {
            throw new RuntimeException('Action execution disappeared before outcome finalization.');
        }
        if (in_array($before->status, ['succeeded', 'failed', 'cancelled'], true)) {
            if ((array) $before->observed !== $observed
                || (int) $before->verified !== ($verified ? 1 : 0)
                || (string) $before->status !== $status
            ) {
                throw new RuntimeException('Action execution already has a different durable outcome.');
            }

            $claimRow = ActionDispatchClaim::getByWhere(['action_trace_id' => $actionId])?->getData();

            if (is_array($claimRow) && (string) $claimRow['status'] === 'legacy') {

                $upgradeRecord = ActionDispatchClaim::getByWhere(['action_trace_id' => $actionId, 'status = \'legacy\'']);
                if (!$upgradeRecord instanceof ActionDispatchClaim) {
                    throw new RuntimeException('Unable to upgrade legacy dispatch receipt.');
                }
                $upgradeRecord->setFields([ 'status' => 'completed', 'outcome_hash' => $outcomeHash, 'outcome_data' => serialize($observed) ]);
                $upgradeRecord->save();
                $claimRow = ['status' => 'completed', 'outcome_hash' => $outcomeHash];
            }
            if (is_array($claimRow)
                && ((string) $claimRow['status'] !== 'completed'
                    || !is_string($claimRow['outcome_hash'])
                    || !hash_equals($claimRow['outcome_hash'], $outcomeHash))
            ) {
                throw new RuntimeException('Terminal action has a different dispatch receipt.');
            }
            return ['execution' => $before->getData(), 'applied' => false];
        }
        if (!in_array($before->status, ['dispatching', 'waiting'], true)) {
            throw new RuntimeException('Action execution is not ready for an outcome.');
        }

        $claimRow = ActionDispatchClaim::getByWhere(['action_trace_id' => $actionId])?->getData();

        $expectedClaimStatus = (string) $before->status === 'waiting' ? 'waiting' : 'claimed';
        if (!is_array($claimRow)
            || (string) $claimRow['status'] !== $expectedClaimStatus
            || ((string) $before->status === 'dispatching'
                && !hash_equals((string) $claimRow['owner'], $this->dispatchOwner()))
        ) {
            throw new RuntimeException('Action outcome does not own its dispatch claim.');
        }

        $stageRecord = ActionDispatchClaim::getByWhere(['action_trace_id' => $actionId, 'status' => $expectedClaimStatus]);
        if (!$stageRecord instanceof ActionDispatchClaim) {
            throw new RuntimeException('Action outcome lost its durable staging claim.');
        }
        $stageRecord->setFields([ 'status' => 'completing', 'outcome_hash' => $outcomeHash, 'outcome_data' => serialize($observed) ]);
        $stageRecord->save();

        $now = date('Y-m-d H:i:s');
        $statementRecord = ActionExecution::getByWhere(['action_trace_id' => $actionId, 'status IN (\'dispatching\', \'waiting\')']);
        if ($statementRecord instanceof ActionExecution) {
            $statementRecord->setFields([ 'observed' => $observed, 'verified' => $verified ? 1 : 0, 'status' => $status, 'completed_at' => $now, 'updated_at' => $now ]);
            $statementRecord->save();
        }
        $execution = ActionExecution::getByField('action_trace_id', $actionId);
        if (!$execution instanceof ActionExecution
            || (array) $execution->observed !== $observed
            || (int) $execution->verified !== ($verified ? 1 : 0)
            || (string) $execution->status !== $status
        ) {
            throw new RuntimeException('Action execution already has a different durable outcome.');
        }

        $completeRecord = ActionDispatchClaim::getByWhere(['action_trace_id' => $actionId, 'status = \'completing\'', 'outcome_hash' => $outcomeHash]);
        if (!$completeRecord instanceof ActionDispatchClaim) {
            throw new RuntimeException('Action outcome lost its completion receipt.');
        }
        $completeRecord->setFields([ 'status' => 'completed' ]);
        $completeRecord->save();
        return [
            'execution' => $execution->getData(),
            'applied' => $statementRecord instanceof ActionExecution,
        ];
    }

    public function rejectPendingActionExecution(int $actionId, array $observed, bool $holdDuringPause = false): array
    {
        if ($holdDuringPause && ExecutiveControl::status()['paused']) {
            $execution = ActionExecution::getByField('action_trace_id', $actionId);
            if (!$execution instanceof ActionExecution) {
                throw new RuntimeException('Paused pending action disappeared.');
            }
            return $execution->getData();
        }
        $now = date('Y-m-d H:i:s');

        $statementRecord = ActionExecution::getByWhere(['action_trace_id' => $actionId, 'status = \'pending\'']);
        if ($statementRecord instanceof ActionExecution) {
            $statementRecord->setFields([ 'observed' => $observed, 'verified' => 0, 'status' => 'failed', 'completed_at' => $now, 'updated_at' => $now ]);
            $statementRecord->save();
        }
        $execution = ActionExecution::getByField('action_trace_id', $actionId);
        if (!$execution instanceof ActionExecution
            || (array) $execution->observed !== $observed
            || (int) $execution->verified !== 0
            || (string) $execution->status !== 'failed'
        ) {
            throw new RuntimeException('Pending action authorization denial lost its dispatch race.');
        }
        return $execution->getData();
    }
}
