<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use Divergence\IO\Database\SQLite;
use InvalidArgumentException;
use NaviBrain\Model\Event;
use NaviBrain\Model\Memory;
use NaviBrain\Model\MemoryConsolidationEpisode;
use NaviBrain\Model\MemoryConsolidationScan;
use NaviBrain\Model\WorkItem;
use NaviBrain\Model\WorkItemLane;
use NaviBrain\Storage\TokenMemoryDaemon;
use RuntimeException;

class ConsolidationLedger extends Component
{
    public function reuseConsolidationEvidence(bool $apply = false): array
    {
        $ingestion = $apply ? $this->ingestConsolidationEpisodes(100, false) : null;
        $known = [];
        $after = 0;
        do {
            $claims = Memory::inspectPage($after, 200, 'semantic', 'active');
            $sourceIds = [];
            foreach ($claims as $claim) {
                $after = (int) $claim->id;
                if ($claim->source_memory_id !== null) {
                    $sourceIds[] = (int) $claim->source_memory_id;
                }
            }
            $sources = [];
            foreach (Memory::getManyByID(array_values(array_unique($sourceIds)), false) as $source) {
                $sources[(int) $source->id] = $source;
            }
            foreach ($claims as $claim) {
                $source = $sources[(int) $claim->source_memory_id] ?? null;
                if ($source instanceof Memory && $this->consolidationReuseIsGrounded($claim, $source)) {
                    $known[hash('sha256', (string) $source->content)] = [
                        'source_id' => (int) $source->id, 'semantic_id' => (int) $claim->id,
                    ];
                }
            }
        } while ($claims !== []);

        $after = 0;
        $matched = 0;
        $reused = 0;
        do {

            $queryRows = MemoryConsolidationEpisode::getAllRecordsByWhere(['status IN (\'pending\', \'rejected\')', 'work_item_id' => null, sprintf('episode_id > %s', SQLite::quote($after))], ['calcFoundRows' => false, 'order' => ['episode_id' => 'ASC'], 'limit' => 200]);
            $entries = $queryRows;

            $episodes = [];
            foreach (Memory::getManyByID(array_map('intval', array_column($entries, 'episode_id')), false) as $episode) {
                $episodes[(int) $episode->id] = $episode;
            }
            foreach ($entries as $entry) {
                $after = (int) $entry['episode_id'];
                $episode = $episodes[$after] ?? null;
                if (!$episode instanceof Memory || $episode->tier !== 'episodic' || $episode->status !== 'active'
                    || ($episode->expires_at !== null && ($this->timestamp($episode->expires_at) ?? 0) <= time())) {
                    continue;
                }
                $match = $known[hash('sha256', (string) $episode->content)] ?? null;
                if ($match === null) {
                    continue;
                }

                $episode = Memory::inspectByID($after);
                $source = Memory::inspectByID($match['source_id']);
                $claim = Memory::inspectByID($match['semantic_id']);
                if (!$episode instanceof Memory || $episode->tier !== 'episodic' || $episode->status !== 'active'
                    || ($episode->expires_at !== null && ($this->timestamp($episode->expires_at) ?? 0) <= time())
                    || !$source instanceof Memory || !$claim instanceof Memory
                    || (string) $source->content !== (string) $episode->content
                    || !$this->consolidationReuseIsGrounded($claim, $source)) {
                    continue;
                }
                $matched++;
                if (!$apply) {
                    continue;
                }
                $updateRecord = MemoryConsolidationEpisode::getByWhere(['id' => $entry['id'], 'status' => $entry['status'], 'work_item_id' => null, 'updated_at' => $entry['updated_at'], sprintf('reason IS %s', SQLite::quote($entry['reason']))]);
                if (!$updateRecord instanceof MemoryConsolidationEpisode) {
                    continue;
                }
                $updateRecord->setFields([
                    'status' => 'consolidated',
                    'semantic_memory_id' => $match['semantic_id'],
                    'reason' => 'exact_grounded_evidence_reused',
                    'validator_version' => Executive::CONSOLIDATION_VALIDATOR_VERSION,
                    'updated_at' => time()
                ]);
                $updateRecord->save();
                $this->emit('memory.consolidation.evidence_reused', [
                    'episode_id' => (int) $entry['episode_id'],
                    'source_episode_id' => $match['source_id'],
                    'semantic_memory_id' => $match['semantic_id'],
                    'previous_ledger' => $entry,
                    'validator_version' => Executive::CONSOLIDATION_VALIDATOR_VERSION,
                ]);
                $reused++;
            }
        } while ($entries !== []);
        return ['status' => $apply ? 'completed' : 'preview', 'matched' => $matched,
            'reused' => $reused, 'known_evidence' => count($known), 'ingestion' => $ingestion, 'inference_calls' => 0];
    }

    public function consolidationReuseIsGrounded(Memory $claim, Memory $source): bool
    {
        $now = time();
        return $claim->tier === 'semantic' && $claim->status === 'active'
            && (float) $claim->confidence >= Executive::CONSOLIDATION_CONFIDENCE_FLOOR
            && (int) $claim->source_memory_id === (int) $source->id
            && ($claim->expires_at === null || ($this->timestamp($claim->expires_at) ?? 0) > $now)
            && $source->tier === 'episodic' && $source->status === 'active'
            && ($source->expires_at === null || ($this->timestamp($source->expires_at) ?? 0) > $now)
            && $this->consolidationGroundingFailure((string) $claim->content, [$source]) === null;
    }

    public function maintainConsolidationQueue(int $targetDepth = 2): array
    {
        if ($targetDepth < 1 || $targetDepth > 8) {
            throw new InvalidArgumentException('consolidation queue depth must be between 1 and 8.');
        }

        $recovered = $this->recoverConsolidationAssignments();
        $this->synchronizeConsolidationEpisodes();
        $queued = [];

        while ($this->activeConsolidationWorkCount() < $targetDepth) {
            $work = $this->enqueueConsolidation(null, time(), false);
            if ($work === null) {
                break;
            }
            $queued[] = $work;
        }

        return [
            'status' => $queued === [] ? 'steady' : 'queued',
            'target_depth' => $targetDepth,
            'active_work_items' => $this->activeConsolidationWorkCount(),
            'queued_batches' => array_values(array_filter(array_map( static fn (array $item): ?int => isset($item['work_item']['id']) ? (int) $item['work_item']['id'] : null, $queued ))),
            'recovered' => $recovered,
            'ledger' => $this->consolidationLedgerCounts(),
        ];
    }

    public function consolidationStatus(): array
    {
        $ledgerCursor = (int) (MemoryConsolidationScan::getByID(1)?->ascending_after_id ?? 0);

        $activeEpisodes = Memory::inspectCount('episodic', 'active');

        $unscannedActiveEpisodes = Memory::inspectCount('episodic', 'active', $ledgerCursor);
        $ledger = $this->consolidationLedgerCounts();
        return [
            'validator_version' => Executive::CONSOLIDATION_VALIDATOR_VERSION,
            'active_work_items' => $this->activeConsolidationWorkCount(),
            'active_episodes' => $activeEpisodes,
            'ledger_cursor' => $ledgerCursor,
            'ascending_after_id' => $ledgerCursor,
            'tracked_active_episodes' => null,
            'untracked_active_episodes' => null,
            'scanned_active_episodes' => max(0, $activeEpisodes - $unscannedActiveEpisodes),
            'unscanned_active_episodes' => $unscannedActiveEpisodes,
            'untracked_upper_bound' => $unscannedActiveEpisodes,
            'ledger' => $ledger,
        ];
    }

    public function repairConsolidationHistory(string $reason): array
    {
        $this->requireText($reason, 'consolidation repair reason');

        $derivedIds = [];
        foreach (Event::getAllByWhere(['kind' => 'memory.consolidated']) as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            if ((int) ($payload['validator_version'] ?? 0) >= Executive::CONSOLIDATION_MEMORY_FLOOR) {
                continue;
            }
            $memoryId = (int) ($payload['memory_id'] ?? 0);
            if ($memoryId > 0) {
                $derivedIds[$memoryId] = true;
            }
        }

        $parentIds = [];
        $quarantined = [];
        foreach (array_keys($derivedIds) as $memoryId) {
            $memory = Memory::inspectByID($memoryId);
            if (!$memory instanceof Memory || $memory->tier !== 'semantic') {
                continue;
            }
            if ($memory->supersedes_id !== null) {
                $parentIds[(int) $memory->supersedes_id] = true;
            }
            if ($memory->status !== 'quarantined') {
                $memory->setFields(['status' => 'quarantined', 'updated_at' => time()]);
                $memory->saveWithOperation(TokenMemoryDaemon::operationKey( 'repair-consolidation-quarantine', $memoryId . ':' . hash('sha256', $reason) ));
            }
            $quarantined[] = $memoryId;
        }

        $activelySuperseded = [];
        foreach (Memory::inspectAllByWhere(['tier' => 'semantic', 'status' => 'active']) as $semantic) {
            if ($semantic->supersedes_id !== null) {
                $activelySuperseded[(int) $semantic->supersedes_id] = true;
            }
        }
        $restored = [];
        foreach (array_keys($parentIds) as $parentId) {
            if (isset($derivedIds[$parentId]) || isset($activelySuperseded[$parentId])) {
                continue;
            }
            $parent = Memory::inspectByID($parentId);
            if ($parent instanceof Memory && $parent->tier === 'semantic' && $parent->status === 'superseded') {
                $parent->setFields(['status' => 'active', 'updated_at' => time()]);
                $parent->saveWithOperation(TokenMemoryDaemon::operationKey( 'repair-consolidation-restore', $parentId . ':' . hash('sha256', $reason) ));
                $restored[] = $parentId;
            }
        }

        $unsafeBatchRepair = $this->repairUnsafeConsolidationBatches($reason);
        $this->synchronizeConsolidationEpisodes();
        $event = $this->emit('memory.consolidation.history_repaired', [
            'reason' => $reason,
            'validator_version' => Executive::CONSOLIDATION_VALIDATOR_VERSION,
            'quarantined_memory_ids' => $quarantined,
            'restored_memory_ids' => $restored,
            'unsafe_batch_repair' => $unsafeBatchRepair,
            'source_episodes_preserved' => true,
        ]);

        return [
            'status' => 'repaired',
            'quarantined_memory_ids' => $quarantined,
            'restored_memory_ids' => $restored,
            'unsafe_batch_repair' => $unsafeBatchRepair,
            'ledger' => $this->consolidationLedgerCounts(),
            'event' => $event->getData(),
        ];
    }

    public function repairUnsafeConsolidationBatches(string $reason): array
    {
        $unsafeWorkIds = [];
        $cancelledWorkIds = [];
        $episodeIds = [];
        $now = time();
        foreach (WorkItem::getAllByWhere(['work_type' => Executive::MEMORY_CONSOLIDATION_WORK_TYPE]) as $work) {
            $refs = is_array($work->input_refs) ? $work->input_refs : [];
            $validatorVersion = (int) ($refs['validator_version'] ?? 0);
            $workspaceLeak = $validatorVersion >= 2 && isset($refs['working_memory_checksum']);
            $obsoleteValidator = $validatorVersion > 0
                && $validatorVersion < Executive::CONSOLIDATION_VALIDATOR_VERSION;
            if (!$workspaceLeak && !$obsoleteValidator) {
                continue;
            }
            $workId = (int) $work->id;
            $unsafeWorkIds[] = $workId;
            foreach ($this->consolidationIdList($refs['episode_ids'] ?? []) as $episodeId) {
                $episodeIds[$episodeId] = true;
            }
            if (!in_array($work->status, ['queued', 'leased'], true)) {
                continue;
            }
            $work->setFields([
                'completed_at' => $now,
                'updated_at' => $now,
                'status' => 'cancelled',
                'lease_owner' => null,
                'lease_expires_at' => null,
                'error' => 'Cancelled because the consolidation batch predates the current evidence fence: ' . $reason,
            ]);
            $work->save();
            $cancelledWorkIds[] = $workId;
            $this->emit('work.cancelled', [ 'work_item_id' => $workId, 'reason' => $workspaceLeak ? 'consolidation_workspace_prompt_leak' : 'obsolete_consolidation_validator', ]);
        }

        $unsafeWork = array_fill_keys($unsafeWorkIds, true);
        $resetEpisodeIds = [];
        foreach (array_keys($episodeIds) as $episodeId) {
            $entry = MemoryConsolidationEpisode::getByField('episode_id', $episodeId);
            if (!$entry instanceof MemoryConsolidationEpisode
                || !in_array($entry->status, ['pending', 'queued'], true)
            ) {
                continue;
            }
            $assignedUnsafeWork = $entry->work_item_id !== null
                && isset($unsafeWork[(int) $entry->work_item_id]);
            if (!$assignedUnsafeWork
                && (int) $entry->validator_version >= Executive::CONSOLIDATION_VALIDATOR_VERSION
            ) {
                continue;
            }
            $entry->setFields([
                'work_item_id' => null,
                'status' => 'pending',
                'attempts' => 0,
                'reason' => 'requeued_after_unsafe_batch_repair',
                'validator_version' => Executive::CONSOLIDATION_VALIDATOR_VERSION,
                'updated_at' => $now,
            ]);
            $entry->save();
            $resetEpisodeIds[] = $episodeId;
        }

        return [
            'unsafe_work_item_ids' => $unsafeWorkIds,
            'cancelled_work_item_ids' => $cancelledWorkIds,
            'reset_episode_ids' => $resetEpisodeIds,
        ];
    }

    public function consolidationLedgerCounts(): array
    {
        $counts = [];
        foreach (['pending', 'queued', 'consolidated', 'rejected', 'excluded'] as $status) {
            $rows = MemoryConsolidationEpisode::getAllRecordsByWhere(['status' => $status], [ 'calcFoundRows' => false, 'extraColumns' => ['total' => 'COUNT(*)'], ]);
            $counts[$status] = (int) ($rows[0]['total'] ?? 0);
        }
        $counts['total'] = array_sum($counts);
        return $counts;
    }

    public function activeConsolidationWorkCount(): int
    {

        $statementRows = WorkItem::getAllRecordsByWhere(['work_type' => Executive::MEMORY_CONSOLIDATION_WORK_TYPE, 'status IN (\'queued\', \'leased\')'], ['calcFoundRows' => false, 'extraColumns' => ['total' => 'COUNT(*)']]);
        $count = (int) (($statementRows[0]['total'] ?? false) ?: 0);

        return $count;
    }

    public function synchronizeConsolidationEpisodes(): void
    {

        $this->ingestConsolidationEpisodes(100, false);
    }

    public function ingestConsolidationEpisodes(int $limit, bool $recent): array
    {
        $scan = MemoryConsolidationScan::getByID(1);
        if (!$scan instanceof MemoryConsolidationScan) {
            throw new RuntimeException('Consolidation scan cursor is missing; initialize the current schema.');
        }
        $afterId = (int) $scan->ascending_after_id;
        $episodes = $recent
            ? Memory::inspectAllByWhere(['tier' => 'episodic', 'status' => 'active'], ['order' => ['id' => 'DESC'], 'limit' => $limit])
            : Memory::inspectPage($afterId, $limit, 'episodic', 'active');
        $scanned = count($episodes);
        $lastId = $recent || $episodes === [] ? $afterId : (int) $episodes[array_key_last($episodes)]->id;
        $admissions = [];
        $now = time();
        foreach ($episodes as $episode) {
            $id = (int) $episode->id;
            if (MemoryConsolidationEpisode::getByField('episode_id', $id) instanceof MemoryConsolidationEpisode) {
                continue;
            }

            $semantic = Memory::inspectProvenance('memory', $id, 'semantic', 'active');
            $semanticId = $semantic instanceof Memory
                && ($semantic->expires_at === null || ($this->timestamp($semantic->expires_at) ?? 0) > $now)
                ? (int) $semantic->id : null;
            $status = $semanticId !== null ? 'consolidated' : 'pending';
            $reason = $semanticId !== null ? 'already_has_active_semantic_provenance' : null;
            if ($semanticId === null && !$this->isEvidence((string) $episode->content)) {
                $status = 'excluded';
                $reason = 'agent_output_is_not_external_evidence';
            } elseif ($semanticId === null && $this->isInsufficientUserFragment((string) $episode->content)) {
                $status = 'rejected';
                $reason = 'user_utterance_fragment_has_insufficient_context';
            }
            $admissions[] = ['id' => $id, 'semantic' => $semanticId, 'status' => $status, 'reason' => $reason];
        }
        unset($episodes, $episode, $semantic);
        if (!$recent) {
            $scan->ascending_after_id = $lastId;
            $scan->save();
        }
        $admitted = 0;
        foreach ($admissions as $row) {
            if (MemoryConsolidationEpisode::getByField('episode_id', $row['id'])) {
                continue;
            }
            $entry = new MemoryConsolidationEpisode([
                'episode_id' => $row['id'], 'semantic_memory_id' => $row['semantic'],
                'status' => $row['status'], 'attempts' => 0, 'reason' => $row['reason'],
                'validator_version' => Executive::CONSOLIDATION_VALIDATOR_VERSION, 'updated_at' => $now,
            ], true, true);
            $entry->save();
            $admitted++;
        }
        return ['scanned' => $scanned, 'admitted' => $admitted, 'ascending_after_id' => $lastId, 'raced' => false];
    }

    public function isInsufficientUserFragment(string $content): bool
    {
        if (!str_starts_with($content, 'The user said:')) {
            return false;
        }
        $said = trim(substr($content, strlen('The user said:')));
        return count($this->consolidationTokens($said)) < 3;
    }

    public function consolidationCursorPage(string $kind, int $limit): array
    {
        if ($limit < 1 || $limit > 32) {
            throw new InvalidArgumentException('Consolidation cursor page must contain between 1 and 32 rows.');
        }
        [$column, $idColumn, $model, $where] = match ($kind) {
            'pending' => ['pending_after_id', 'episode_id', MemoryConsolidationEpisode::class, ['status' => 'pending']],
            'queued' => ['queued_after_id', 'episode_id', MemoryConsolidationEpisode::class, ['status' => 'queued']],
            'work' => ['work_after_id', 'work_id', WorkItemLane::class, []],
            default => throw new InvalidArgumentException('Unknown consolidation traversal.'),
        };
        $scan = MemoryConsolidationScan::getByID(1);
        if (!$scan instanceof MemoryConsolidationScan) {
            throw new RuntimeException('Consolidation traversal cursor is missing.');
        }
        $rows = $model::getAllRecordsByWhere([...$where, $idColumn . ' > ' . (int) $scan->$column], ['order' => [$idColumn => 'ASC'], 'limit' => $limit, 'calcFoundRows' => false]);
        $scan->$column = $rows === [] ? 0 : (int) $rows[array_key_last($rows)][$idColumn];
        $scan->save();
        return $rows;
    }

    public function recoverConsolidationAssignments(?int $limit = null): array
    {
        $recovered = [];
        if ($limit === null) {
            $statementRows = MemoryConsolidationEpisode::getAllRecordsByWhere(['status = \'queued\'', 'work_item_id IS NOT NULL'], ['calcFoundRows' => false, 'order' => ['work_item_id' => 'ASC']]);
            $statementRows = array_values(array_column($statementRows, null, 'work_item_id'));
            $workIds = array_map('intval', array_column($statementRows, 'work_item_id') ?: []);

        } else {

            $rows = $this->consolidationCursorPage('queued', $limit);
            $workIds = array_values(array_unique(array_filter(array_map( static fn (array $row): int => (int) $row['work_item_id'], $rows ))));
        }

        foreach ($workIds as $workId) {
            $work = WorkItem::getByID($workId);
            if (!$work instanceof WorkItem) {
                $recovered[] = $this->rejectConsolidationAttempt(['id' => $workId, 'input_refs' => []], 'assigned_work_item_missing');
                continue;
            }
            if ($work->status === 'completed') {
                $result = is_array($work->result) ? $work->result : [];
                $recovered[] = $result === []
                    ? $this->rejectConsolidationAttempt($work->getData(), 'completed_work_has_no_result')
                    : $this->integrateConsolidation($work->getData(), $result, (string) $work->model);
            } elseif (in_array($work->status, ['failed', 'cancelled'], true)) {
                $recovered[] = $this->rejectConsolidationAttempt($work->getData(), 'assigned_work_' . (string) $work->status);
            }
        }
        return $recovered;
    }
}
