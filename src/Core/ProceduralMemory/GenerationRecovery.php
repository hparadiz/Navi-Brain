<?php

declare(strict_types=1);

namespace NaviBrain\Core\ProceduralMemory;

use NaviBrain\Model\ProcedureCompileGuard;
use InvalidArgumentException;
use NaviBrain\Model\ActionExecution;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\Procedure;
use RuntimeException;

class GenerationRecovery extends Component
{
    /** @return list<array{shape_key: string, generation: int, action_id: int}> */
    public function recoverPendingGenerations(int $limit = 16): array {
        if ($limit < 1 || $limit > 256) {
            throw new InvalidArgumentException('Procedure recovery limit is out of range.');
        }

        $pending = ProcedureCompileGuard::getAllByWhere(['pending_generation IS NOT NULL'], ['order' => ['pending_generation' => 'ASC', 'shape_key' => 'ASC'], 'limit' => $limit]);

        $recovered = [];
        foreach ($pending as $row) {
            $shapeKey = (string) $row->shape_key;
            $generation = (int) $row->pending_generation;
            $pendingHash = $row->pending_hash;
            if ($shapeKey === '' || $generation < 1 || !is_string($pendingHash)) {
                throw new RuntimeException('Procedure generation guard has invalid pending identity.');
            }
            $this->recoverPendingProcedureGeneration($shapeKey, $generation, $pendingHash);
            $recovered[] = [
                'shape_key' => $shapeKey,
                'generation' => $generation,
                'action_id' => $generation,
            ];
        }
        return $recovered;
    }

    public function recoverPendingProcedureGeneration(string $shapeKey, int $generation, string $pendingHash): void {
        $guard = $this->procedureGeneration($shapeKey);
        if ($guard === null) {
            throw new RuntimeException('Pending procedure generation guard disappeared.');
        }
        if ($guard['current_generation'] >= $generation
            && $guard['pending_generation'] === null
        ) {
            return;
        }
        if ($guard['pending_generation'] !== $generation
            || !is_string($guard['pending_hash'])
            || !hash_equals($guard['pending_hash'], $pendingHash)
        ) {
            throw new RuntimeException('Procedure recovery no longer owns the pending generation.');
        }

        $action = ActionTrace::getByID($generation);
        if (!$action instanceof ActionTrace
            || !in_array($action->status, ['succeeded', 'failed', 'cancelled'], true)
        ) {
            throw new RuntimeException('Pending procedure generation has no finished source action.');
        }
        $execution = ActionExecution::getByField('action_trace_id', $generation);
        $actualShape = $execution instanceof ActionExecution
            ? $this->typedShape($action, $execution)['key']
            : $this->legacyShape((string) $action->description, (string) $action->expected)['key'];
        if (!hash_equals($shapeKey, $actualShape)) {
            throw new RuntimeException('Pending procedure generation crossed its action shape.');
        }

        if ($execution instanceof ActionExecution) {
            if (!in_array($execution->status, ['succeeded', 'failed', 'cancelled'], true)) {
                throw new RuntimeException('Pending typed procedure action has no terminal execution.');
            }
            $this->finishDurableExecution($generation);
        } else {
            $observed = (string) ($action->observed ?? '');
            $repairNote = (string) ($action->repair_note ?? '');
            if ($observed === '' || $repairNote === '') {
                throw new RuntimeException('Pending legacy procedure action is not replayable.');
            }
            $this->core->finishAction($generation, (string) $action->status, $observed, (string) $action->match_status === 'matched', $repairNote);
        }

        $guard = $this->procedureGeneration($shapeKey);
        if ($guard === null
            || $guard['current_generation'] < $generation
            || $guard['pending_generation'] !== null
            || $guard['pending_hash'] !== null
        ) {
            throw new RuntimeException('Procedure generation recovery did not commit its exact guard.');
        }
    }

    /** @return 'claimed'|'replay'|'obsolete'|'blocked' */
    public function claimProcedureGeneration(string $shapeKey, int $generation, string $pendingHash, int $seedGeneration): string {
        $guard = ProcedureCompileGuard::getByWhere(['shape_key' => $shapeKey]);
        if ($guard instanceof ProcedureCompileGuard
            && $guard->pending_generation !== null
            && (int) $guard->pending_generation < $generation
        ) {
            if (!is_string($guard->pending_hash)) {
                throw new RuntimeException('Procedure generation guard is missing its pending hash.');
            }
            $this->recoverPendingProcedureGeneration($shapeKey, (int) $guard->pending_generation, $guard->pending_hash);
            $guard = ProcedureCompileGuard::getByWhere(['shape_key' => $shapeKey]);
        }
        if (!$guard instanceof ProcedureCompileGuard) {
            $guard = new ProcedureCompileGuard([ 'shape_key' => $shapeKey, 'current_generation' => max(0, $seedGeneration), ], true, true);
        }
        if ($generation <= (int) $guard->current_generation) {
            $guard->save();
            return 'obsolete';
        }
        if ($guard->pending_generation !== null) {
            if ((int) $guard->pending_generation !== $generation) {
                return (int) $guard->pending_generation < $generation ? 'blocked' : 'obsolete';
            }
            if (!is_string($guard->pending_hash)
                || !hash_equals($guard->pending_hash, $pendingHash)
            ) {
                throw new RuntimeException('Procedure generation was replayed with different compiled data.');
            }
            return 'replay';
        }
        $guard->setFields([ 'pending_generation' => $generation, 'pending_hash' => $pendingHash, ]);
        $guard->save();
        return 'claimed';
    }

    public function commitProcedureGeneration(string $shapeKey, int $generation): void {
        $guard = ProcedureCompileGuard::getByWhere(['shape_key' => $shapeKey]);
        if (!$guard instanceof ProcedureCompileGuard) {
            $guard = new ProcedureCompileGuard(['shape_key' => $shapeKey], true, true);
        }
        if ($guard->pending_generation !== null
            && (int) $guard->pending_generation !== $generation
        ) {
            throw new RuntimeException('Cannot commit a procedure generation out of order.');
        }
        $guard->setFields([ 'current_generation' => max((int) $guard->current_generation, $generation), 'pending_generation' => null, 'pending_hash' => null, ]);
        $guard->save();
    }

    /** @return array{current_generation: int, pending_generation: ?int, pending_hash: ?string}|null */
    public function procedureGeneration(string $shapeKey): ?array {
        $guard = ProcedureCompileGuard::getByWhere(['shape_key' => $shapeKey]);
        if (!$guard instanceof ProcedureCompileGuard) {
            return null;
        }
        return [
            'current_generation' => (int) $guard->current_generation,
            'pending_generation' => $guard->pending_generation === null
                ? null
                : (int) $guard->pending_generation,
            'pending_hash' => $guard->pending_hash,
        ];
    }

    public function canReplayProcedureGeneration(string $shapeKey, int $generation): bool {
        $guard = $this->procedureGeneration($shapeKey);
        return $guard === null
            || ($guard['current_generation'] <= $generation
                && ($guard['pending_generation'] === null
                    || $guard['pending_generation'] === $generation));
    }

    /** @param list<mixed> $actionIds */
    public function generation(array $actionIds): int {
        $ids = array_values(array_filter(array_map('intval', $actionIds), static fn (int $id): bool => $id > 0));
        return $ids === [] ? 0 : max($ids);
    }
}
