<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use NaviBrain\Storage\TokenMemoryDaemon;

use NaviBrain\Model\Memory;

use InvalidArgumentException;
use NaviBrain\Core\ExecutiveControl;
use NaviBrain\Model\Event;
use NaviBrain\Model\Intention;
use NaviBrain\Model\LookRequestClaim;
use RuntimeException;

class VisionResults extends Component
{
    public function rememberLook(string $command, string $because, array $reading, ?int $requestEventId = null): array
    {
        $refused = ($reading['refused'] ?? false) === true;
        $exit = $reading['exit_code'] ?? null;
        $worked = !$refused && $exit === 0;
        $outcomeHash = $requestEventId === null
            ? null
            : $this->stageLookCompletion($command, $because, $reading, $requestEventId);

        if ($refused) {
            $content = sprintf('Looking at this machine with "%s" could not happen: %s. The question was %s.', $command, (string) ($reading['refused_because'] ?? 'no reason given'), $because);
        } elseif (!$worked) {
            $content = sprintf(
                'On this machine "%s" does not work as a way to find out %s. It exited %s%s.',
                $command,
                $because,
                var_export($exit, true),
                trim((string) ($reading['error_output'] ?? '')) === ''
                    ? ''
                    : ': ' . mb_substr(trim((string) $reading['error_output']), 0, 200)
            );
        } else {
            $content = sprintf('On this machine "%s" answers %s. It returned: %s', $command, $because, mb_substr(trim((string) ($reading['output'] ?? '')), 0, 600));
        }

        $eventPayload = [
            'command' => $command,
            'because' => $because,
            'worked' => $worked,
            'refused' => $refused,
            'exit_code' => $exit,
            'request_event_id' => $requestEventId,
        ];
        $event = $requestEventId === null
            ? $this->emit('look.observed', $eventPayload)
            : $this->emitOnce('look.observed:request:' . $requestEventId, 'look.observed', $eventPayload);

        $memory = new Memory([
            'tier' => 'episodic', 'content' => $content, 'confidence' => $worked ? 0.9 : 0.8,
            'operation_key' => TokenMemoryDaemon::operationKey('memory-add', $requestEventId === null ? 'look-observed:' . (int) $event->id : 'look-observed-request:' . $requestEventId),
            'source_event_id' => (int) $event->id, 'source_event_kind' => 'event',
        ], true, true);
        $memory->save();
        $procedure = $requestEventId === null
            ? null
            : $this->executive->proceduralMemory->completePendingLook($requestEventId, $reading);
        if ($requestEventId !== null) {
            $this->finishLookCompletion($requestEventId, $outcomeHash);
        }
        return ['memory' => $memory->getData(), 'event' => $memory->storedEvent->getData(), 'procedure_completion' => $procedure];
    }

    public function stageLookCompletion(string $command, string $because, array $reading, int $requestEventId): string
    {
        $this->requireText($command, 'command');
        $this->requireText($because, 'reason for looking');
        if ($requestEventId < 1) {
            throw new InvalidArgumentException('Look request event identity is invalid.');
        }
        $request = Event::getByID($requestEventId);
        $payload = $request instanceof Event && is_array($request->payload)
            ? $request->payload
            : [];
        if (!$request instanceof Event
            || (string) $request->kind !== 'look.requested'
            || !hash_equals((string) ($payload['command'] ?? ''), $command)
            || !hash_equals((string) ($payload['because'] ?? ''), $because)
        ) {
            throw new RuntimeException('Look completion does not match its immutable request event.');
        }
        $outcomeHash = hash('sha256', serialize([ 'command' => $command, 'because' => $because, 'reading' => $reading, ]));
        $this->beginLookCompletion($requestEventId, $outcomeHash, $reading);
        return $outcomeHash;
    }

    public function beginLookCompletion(int $eventId, string $outcomeHash, array $reading): void
    {
        $claim = LookRequestClaim::getByWhere(['event_id' => $eventId]);
        if (!$claim instanceof LookRequestClaim) {
            throw new RuntimeException('Look completion has no executor claim.');
        }
        if (in_array($claim->status, ['completing', 'completed'], true)) {
            if (!is_string($claim->outcome_hash) || !hash_equals($claim->outcome_hash, $outcomeHash)) {
                throw new RuntimeException('Look request already has a different durable outcome.');
            }
            return;
        }
        if ($claim->status !== 'claimed' || !hash_equals((string) $claim->owner, $this->dispatchOwner())) {
            throw new RuntimeException('Look completion is not owned by this executor process.');
        }
        $claim->setFields(['status' => 'completing', 'outcome_hash' => $outcomeHash, 'outcome_data' => serialize($reading)]);
        $claim->save();
    }

    public function finishLookCompletion(int $eventId, string $outcomeHash): void
    {
        $claim = LookRequestClaim::getByWhere(['event_id' => $eventId]);
        if (!$claim instanceof LookRequestClaim || !in_array($claim->status, ['completing', 'completed'], true)
            || !is_string($claim->outcome_hash) || !hash_equals($claim->outcome_hash, $outcomeHash)) {
            throw new RuntimeException('Look completion could not commit its exact outcome.');
        }
        if ($claim->status === 'completing') {
            $claim->status = 'completed';
            $claim->save();
        }
    }

    public function claimPendingLooks(int $limit = 3): array
    {
        $this->executive->decisionStateMachine->reconcileAsyncActions(max(8, $limit * 4));
        $limit = max(1, $limit);
        $pending = [];
        $recoveryIds = $this->lookRecoveryCandidateIds($limit);

        $queuedRows = LookRequestClaim::getAllRecordsByWhere(['status = \'queued\''], ['calcFoundRows' => false, 'order' => ['event_id' => 'ASC'], 'limit' => $limit]);
        $queuedIds = array_map('intval', array_column($queuedRows, 'event_id'));

        foreach (array_values(array_unique(array_merge($recoveryIds, $queuedIds))) as $eventId) {
            $event = Event::getByID($eventId);
            if (!$event instanceof Event || (string) $event->kind !== 'look.requested') {
                continue;
            }
            $payload = is_array($event->payload) ? $event->payload : [];
            $command = (string) ($payload['command'] ?? '');
            if ($command === '') {
                continue;
            }
            $because = (string) ($payload['because'] ?? '');
            $claim = $this->claimLookRequest((int) $event->id, $command, $because);
            if (!in_array($claim['status'], ['claimed', 'replay'], true)) {
                continue;
            }
            $pending[] = [
                'command' => $command,
                'because' => $because,
                'event_id' => (int) $event->id,
                'action_id' => isset($payload['action_id']) ? (int) $payload['action_id'] : null,
                'procedure_run_id' => isset($payload['procedure_run_id'])
                    ? (int) $payload['procedure_run_id']
                    : null,
                'procedure_step' => isset($payload['procedure_step'])
                    ? (int) $payload['procedure_step']
                    : null,
                'replay_reading' => $claim['reading'] ?? null,
            ];
            if (count($pending) >= max(1, $limit)) {
                break;
            }
        }
        return $pending;
    }

    public function lookRecoveryCandidateIds(int $limit): array
    {
        $page = static function (int $after) use ($limit): array {
            $claims = LookRequestClaim::getAllByQuery(
                "SELECT claim.* FROM look_request_claims AS claim
                 JOIN events AS event ON event.id = claim.event_id
                 WHERE event.kind = 'look.requested'
                   AND claim.status IN ('legacy','claimed','completing')
                   AND claim.event_id > " . $after . '
                 ORDER BY claim.event_id ASC LIMIT ' . max(1, $limit)
            );
            return array_map(static fn (LookRequestClaim $claim): int => (int) $claim->event_id, $claims);
        };
        $ids = $page(Executive::$lookClaimSweepCursor);
        if ($ids === [] && Executive::$lookClaimSweepCursor > 0) {
            Executive::$lookClaimSweepCursor = 0;
            $ids = $page(0);
        }
        if ($ids !== []) {
            Executive::$lookClaimSweepCursor = max($ids);
        }
        return $ids;
    }

    public function claimLookRequest(int $eventId, string $command, string $because): array
    {
        $owner = $this->dispatchOwner();

        $row = LookRequestClaim::getByWhere(['event_id' => $eventId])?->getData();

        if (!is_array($row)) {
            throw new RuntimeException('Look request is missing its atomic queue record.');
        }
        if ($row['status'] === 'queued') {
            if (ExecutiveControl::status()['paused']) {
                return ['status' => 'skip'];
            }
            $request = Event::getByID($eventId);
            $refs = $request instanceof Event && is_array($request->payload) ? $request->payload : [];
            $changes = [];
            if (($refs['intention_id'] ?? null) !== null) {
                $intention = Intention::getByID((int) $refs['intention_id']);
                if (!$intention instanceof Intention || $intention->status !== 'active') {
                    $changes[] = 'intention is no longer active';
                }
            }
            if (($refs['action_id'] ?? null) !== null) {
                $cycleId = $this->decisionCycleForAsyncAction((int) $refs['action_id']);
                if ($cycleId !== null) {
                    $changes = array_merge($changes, $this->executive->decisionStateMachine->decisionInputChanges($cycleId));
                }
            }

            $claimRecord = LookRequestClaim::getByWhere(['event_id' => $eventId, 'status = \'queued\'']);
            if (!$claimRecord instanceof LookRequestClaim) {
                throw new RuntimeException('Look queue claim changed concurrently.');
            }
            $claimRecord->setFields([ 'owner' => $owner, 'status' => 'claimed' ]);
            $claimRecord->save();
            if ($changes !== []) {
                $reading = [
                    'ran' => false, 'refused' => true,
                    'refused_because' => 'Look admission changed; start a fresh cycle: ' . implode('; ', $changes),
                    'exit_code' => null, 'timed_out' => false, 'seconds' => 0.0,
                    'output' => '', 'error_output' => '',
                    'output_digest' => hash('sha256', ''), 'output_bytes' => 0,
                ];

                $this->stageLookCompletion($command, $because, $reading, $eventId);
                return ['status' => 'replay', 'reading' => $reading];
            }
            return ['status' => 'claimed'];
        }
        if ($row['status'] === 'legacy') {
            $reading = [
                'refused' => true,
                'refused_because' => 'This request predates the durable executor ledger; its outcome is indeterminate.',
                'exit_code' => null,
                'timed_out' => false,
                'output_digest' => null,
                'output_bytes' => null,
            ];

            $recoverRecord = LookRequestClaim::getByWhere(['event_id' => $eventId, 'status = \'legacy\'']);
            if ($recoverRecord instanceof LookRequestClaim) {
                $recoverRecord->setFields([
                    'owner' => $owner,
                    'status' => 'completing',
                    'outcome_hash' => hash('sha256', serialize([ 'command' => $command, 'because' => $because, 'reading' => $reading, ])),
                    'outcome_data' => serialize($reading)
                ]);
                $recoverRecord->save();
            }
            return $recoverRecord instanceof LookRequestClaim
                ? ['status' => 'replay', 'reading' => $reading]
                : ['status' => 'skip'];
        }
        if (!in_array($row['status'], ['claimed', 'completing'], true)) {
            return ['status' => 'skip'];
        }
        $previousOwner = (string) $row['owner'];
        if ($row['status'] === 'completing') {
            if (!hash_equals($previousOwner, $owner)
                && $this->dispatchOwnerIsLive($previousOwner)
            ) {
                return ['status' => 'skip'];
            }

            $recoverRecord = LookRequestClaim::getByWhere(['event_id' => $eventId, 'owner' => $previousOwner, 'status = \'completing\'']);
            if ($recoverRecord instanceof LookRequestClaim) {
                $recoverRecord->setFields([ 'owner' => $owner ]);
                $recoverRecord->save();
            }
            $reading = isset($row['outcome_data']) ? @unserialize((string) $row['outcome_data']) : null;
            if (!is_array($reading)
                || (!$recoverRecord instanceof LookRequestClaim && !hash_equals($previousOwner, $owner))
            ) {
                throw new RuntimeException('Indeterminate look completion has no replayable outcome.');
            }
            return ['status' => 'replay', 'reading' => $reading];
        }
        if (!hash_equals($previousOwner, $owner)
            && $this->dispatchOwnerIsLive($previousOwner)
        ) {
            return ['status' => 'skip'];
        }

        $reading = [
            'refused' => true,
            'refused_because' => hash_equals($previousOwner, $owner)
                ? 'The command executor returned to an unstaged request; its outcome is indeterminate.'
                : 'The command executor exited after claiming this request; its outcome is indeterminate.',
            'exit_code' => null,
            'timed_out' => false,
            'output_digest' => null,
            'output_bytes' => null,
        ];
        $recoverRecord = LookRequestClaim::getByWhere(['event_id' => $eventId, 'owner' => $previousOwner, 'status = \'claimed\'']);
        if ($recoverRecord instanceof LookRequestClaim) {
            $recoverRecord->setFields([
                'owner' => $owner,
                'status' => 'completing',
                'outcome_hash' => hash('sha256', serialize([ 'command' => $command, 'because' => $because, 'reading' => $reading, ])),
                'outcome_data' => serialize($reading)
            ]);
            $recoverRecord->save();
        }
        return $recoverRecord instanceof LookRequestClaim
            ? ['status' => 'replay', 'reading' => $reading]
            : ['status' => 'skip'];
    }
}
