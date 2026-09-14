<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use NaviBrain\Model\MemoryStoreOperation;

use NaviBrain\Model\Event;
use NaviBrain\Model\Memory;
use NaviBrain\Model\MemoryConsolidationEpisode;
use NaviBrain\Model\MemorySource;
use NaviBrain\Storage\TokenMemoryDaemon;
use RuntimeException;

class ConsolidationResults extends Component
{
    public function integrateConsolidation(array $work, array $proposal, ?string $model): array
    {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $workId = (int) ($work['id'] ?? 0);
        $expectedIds = $this->consolidationIdList($refs['episode_ids'] ?? []);
        $confidence = is_numeric($proposal['confidence'] ?? null) ? (float) $proposal['confidence'] : 0.0;
        if ($workId < 1 || $expectedIds === []) {
            return $this->rejectConsolidationAttempt($work, 'missing_work_or_episode_ids');
        }
        if ($this->eventByDedupeKey('memory.consolidated:' . $workId) instanceof Event
            || $this->eventByDedupeKey('memory.consolidation.recovery-settled:' . $workId) instanceof Event) {
            return ['status' => 'already_integrated', 'work_item_id' => $workId, 'accepted_batch' => false];
        }

        $observed = [];
        $expectedHashes = is_array($refs['evidence_hashes'] ?? null) ? $refs['evidence_hashes'] : [];
        foreach ($expectedIds as $episodeId) {
            $episode = Memory::inspectByID($episodeId);
            if (!$episode instanceof Memory || $episode->tier !== 'episodic' || $episode->status !== 'active'
                || ($episode->expires_at !== null && ($this->timestamp($episode->expires_at) ?? 0) <= time())) {
                return $this->rejectConsolidationAttempt($work, 'source_missing_inactive_or_expired', true);
            }
            $actualHash = hash('sha256', $this->consolidationEvidenceText($episode));
            if (($expectedHashes[(string) $episodeId] ?? null) !== $actualHash) {
                return $this->rejectConsolidationAttempt($work, 'source_evidence_changed_after_queueing', true);
            }
            $observed[$episodeId] = $episode;
        }
        if (($proposal['kind'] ?? null) !== Executive::MEMORY_CONSOLIDATION_WORK_TYPE) {
            return $this->rejectConsolidationAttempt($work, 'wrong_proposal_kind');
        }

        $claim = is_string($proposal['content'] ?? null) ? trim($proposal['content']) : '';
        $rejectionReason = is_string($proposal['rejection_reason'] ?? null)
            ? trim($proposal['rejection_reason'])
            : '';
        $supportedIds = $this->consolidationIdList($proposal['supported_episode_ids'] ?? null);
        $rejectedIds = $this->consolidationIdList($proposal['rejected_episode_ids'] ?? null);
        $partition = array_values(array_unique(array_merge($supportedIds, $rejectedIds)));
        sort($partition);
        sort($expectedIds);
        if ($partition !== $expectedIds
            || count($supportedIds) + count($rejectedIds) !== count($expectedIds)
        ) {
            return $this->rejectConsolidationAttempt($work, 'fresh_episode_partition_is_incomplete');
        }
        if ($rejectedIds !== [] && ($rejectionReason === '' || $rejectionReason === 'none')) {
            return $this->rejectConsolidationAttempt($work, 'rejected_sources_have_no_reason');
        }

        if ($supportedIds === []) {
            if ($claim !== '' || $rejectedIds !== $expectedIds) {
                return $this->rejectConsolidationAttempt($work, 'empty_support_must_reject_the_whole_batch');
            }
            foreach ($expectedIds as $episodeId) {
                $entry = MemoryConsolidationEpisode::getByField('episode_id', $episodeId);
                if ($entry instanceof MemoryConsolidationEpisode
                    && $entry->status === 'queued'
                    && (int) $entry->work_item_id === $workId
                ) {
                    $entry->setFields([ 'work_item_id' => null, 'status' => 'rejected', 'reason' => mb_substr($rejectionReason, 0, 1000), 'updated_at' => time(), ]);
                    $entry->save();
                }
            }
            $event = $this->emit('memory.consolidation.batch.rejected', [
                'work_item_id' => $workId,
                'episode_ids' => $expectedIds,
                'reason' => $rejectionReason,
                'confidence' => $confidence,
                'model' => $model,
                'validator_version' => Executive::CONSOLIDATION_VALIDATOR_VERSION,
            ]);
            return [
                'status' => 'sources_rejected',
                'episode_ids' => $expectedIds,
                'reason' => $rejectionReason,
                'event' => $event->getData(),
            ];
        }

        if ($claim === '') {
            return $this->rejectConsolidationAttempt($work, 'supported_sources_require_a_claim');
        }
        if ($confidence < Executive::CONSOLIDATION_CONFIDENCE_FLOOR) {
            return $this->rejectConsolidationAttempt($work, 'supported_claim_below_confidence_floor');
        }

        $supersedes = $proposal['supersedes_memory_id'] ?? null;
        if ($supersedes !== null && (!is_int($supersedes) || $supersedes < 1)) {
            return $this->rejectConsolidationAttempt($work, 'invalid_supersedes_memory_id');
        }
        $allowedExisting = $this->consolidationIdList($refs['existing_memory_ids'] ?? []);
        if ($supersedes !== null) {
            return $this->rejectConsolidationAttempt($work, 'automatic_consolidation_does_not_supersede_existing_memory');
        }

        $episodes = array_map(static fn (int $id): Memory => $observed[$id], $supportedIds);
        $groundingFailure = $this->consolidationGroundingFailure($claim, $episodes);
        if ($groundingFailure !== null) {
            return $this->rejectConsolidationAttempt($work, $groundingFailure);
        }

        $activeEntries = 0;
        foreach (array_merge($supportedIds, $rejectedIds) as $episodeId) {
            $entry = MemoryConsolidationEpisode::getByField('episode_id', $episodeId);
            if ($entry instanceof MemoryConsolidationEpisode
                && $entry->status === 'queued'
                && (int) $entry->work_item_id === $workId
            ) {
                $activeEntries++;
            }
        }
        $semantic = null;
        if ($activeEntries === 0) {

            $operation = MemoryStoreOperation::getByField('operation_key', TokenMemoryDaemon::operationKey( 'memory-add', 'automatic-consolidation:' . $workId ));
            $primary = MemoryConsolidationEpisode::getByField('episode_id', $supportedIds[0]);
            $receiptId = (int) ($operation?->memory_id ?? 0);
            if ($receiptId > 0 && $primary instanceof MemoryConsolidationEpisode
                && $primary->status === 'consolidated'
                && $primary->reason === 'active_semantic_provenance_committed'
                && (int) $primary->semantic_memory_id === $receiptId) {
                $candidate = Memory::inspectByID($receiptId);
                if ($candidate instanceof Memory && (int) $candidate->source_memory_id === $supportedIds[0]
                    && $this->consolidationClaimIsIdentical($claim, $candidate)) {
                    $semantic = $candidate;
                }
            }
            if (!$semantic instanceof Memory) {
                return ['status' => 'already_integrated', 'work_item_id' => $workId, 'accepted_batch' => false];
            }
        }

        Memory::observeRecords($episodes);
        $semantic ??= $this->consolidationCoveredByExisting($claim, $allowedExisting);
        if (!$semantic instanceof Memory) {
            foreach (Memory::rankCandidates($claim, 100, 'semantic', 'active') as $candidate) {
                if ($this->consolidationClaimIsIdentical($claim, $candidate)) {
                    $semantic = $candidate;
                    Memory::observeRecords([$candidate]);
                    break;
                }
            }
        }
        if (!$semantic instanceof Memory) {
            $primary = $episodes[0];
            $source = MemorySource::reference($primary->getData());
            $semantic = new Memory([
                'tier' => 'semantic', 'content' => $claim, 'confidence' => $confidence,
                'operation_key' => TokenMemoryDaemon::operationKey('memory-add', 'automatic-consolidation:' . $workId),
                'source_event_id' => $source['id'] ?? null, 'source_memory_id' => (int) $primary->id,
                'source_event_kind' => $source['kind'] ?? null,
            ], true, true);
            $semantic->save();
        }

        $semanticData = $semantic instanceof Memory ? $semantic->getData() : $semantic;
        $semanticId = (int) ($semanticData['id'] ?? 0);
        if ($semanticId < 1) {
            throw new RuntimeException('Stored consolidation memory receipt is invalid.');
        }

        if ($this->eventByDedupeKey('memory.consolidated:' . $workId) instanceof Event) {
            return ['status' => 'already_integrated', 'work_item_id' => $workId, 'accepted_batch' => false];
        }
        $operation = MemoryStoreOperation::getByField('operation_key', TokenMemoryDaemon::operationKey( 'memory-add', 'automatic-consolidation:' . $workId ));
        foreach (array_merge($supportedIds, $rejectedIds) as $episodeId) {
            $entry = MemoryConsolidationEpisode::getByField('episode_id', $episodeId);
            $assigned = $entry instanceof MemoryConsolidationEpisode
                && $entry->status === 'queued' && (int) $entry->work_item_id === $workId;
            $primaryCommitted = $episodeId === $supportedIds[0]
                && $entry instanceof MemoryConsolidationEpisode && $entry->status === 'consolidated'
                && $entry->reason === 'active_semantic_provenance_committed'
                && (int) $entry->semantic_memory_id === $semanticId
                && (int) ($operation?->memory_id ?? 0) === $semanticId;
            if (!$assigned && !$primaryCommitted) {
                return ['status' => 'already_integrated', 'work_item_id' => $workId, 'accepted_batch' => false];
            }
        }

        foreach ($supportedIds as $episodeId) {
            $entry = MemoryConsolidationEpisode::getByField('episode_id', $episodeId);
            if ($entry instanceof MemoryConsolidationEpisode) {
                $entry->setFields([ 'work_item_id' => null, 'semantic_memory_id' => $semanticId, 'status' => 'consolidated', 'reason' => 'supported_by_grounded_claim', 'updated_at' => time(), ]);
                $entry->save();
            }
        }
        foreach ($rejectedIds as $episodeId) {
            $entry = MemoryConsolidationEpisode::getByField('episode_id', $episodeId);
            if ($entry instanceof MemoryConsolidationEpisode) {
                $entry->setFields([ 'work_item_id' => null, 'status' => 'rejected', 'reason' => mb_substr($rejectionReason, 0, 1000), 'updated_at' => time(), ]);
                $entry->save();
            }
        }

        $event = $this->emitOnce('memory.consolidated:' . $workId, 'memory.consolidated', [
            'work_item_id' => $workId,
            'episode_ids' => $supportedIds,
            'rejected_episode_ids' => $rejectedIds,
            'rejection_reason' => $rejectionReason,
            'interleaved_ids' => $refs['interleaved_ids'] ?? [],
            'supersedes_memory_id' => $supersedes,
            'memory_id' => $semanticId,
            'confidence' => $confidence,
            'model' => $model,
            'validator_version' => Executive::CONSOLIDATION_VALIDATOR_VERSION,
        ]);
        return [
            'status' => 'consolidated',

            'new_assertion' => false,
            'accepted_batch' => true,
            'memory' => $semanticData,
            'supported_episode_ids' => $supportedIds,
            'rejected_episode_ids' => $rejectedIds,
            'event' => $event->getData(),
        ];
    }

    public function consolidationIdList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            if (!is_int($id) || $id < 1 || isset($ids[$id])) {
                return [];
            }
            $ids[$id] = true;
        }
        $ids = array_keys($ids);
        sort($ids);
        return $ids;
    }

    public function consolidationGroundingFailure(string $claim, array $episodes): ?string
    {
        $sourceTexts = array_map(fn (Memory $episode): string => $this->consolidationEvidenceText($episode), $episodes);

        $corpus = implode("\n", $sourceTexts);
        $literalPatterns = [
            '/`([^`]{2,160})`/u',
            '/"([^"\n]{2,200})"/u',
            '/(?<![\p{L}\p{N}_])(\/[-\p{L}\p{N}_.\/]+|--?[-\p{L}\p{N}_.]+(?:=[-\p{L}\p{N}_.\/]+)?)/u',
            '/(?<![\p{L}\p{N}_])([\p{L}_][\p{L}\p{N}_.-]*=[-\p{L}\p{N}_.\/]+)/u',
            '/\b\d+(?:\.\d+)+(?:[-_\p{L}\p{N}.]*)?\b/u',
            '/(?<![\p{L}\p{N}])([$€£]?\d+(?:,\d{3})*(?:\.\d+)?%?)(?![\p{L}\p{N}])/u',
        ];
        foreach ($literalPatterns as $pattern) {
            if (preg_match_all($pattern, $claim, $matches) !== false) {
                foreach ($matches[1] ?? $matches[0] as $literal) {
                    $literal = trim((string) $literal);
                    if ($literal !== '' && !str_contains($corpus, $literal)) {
                        return 'unsupported_literal:' . mb_substr($literal, 0, 120);
                    }
                }
            }
        }

        $claimTokens = array_flip($this->consolidationTokens($claim));
        if ($claimTokens === []) {
            return 'claim_has_no_groundable_terms';
        }
        $sourceTokens = [];
        foreach ($sourceTexts as $sourceText) {
            foreach ($this->consolidationTokens($sourceText) as $token) {
                $sourceTokens[$token] = true;
            }
        }
        $matched = count(array_intersect_key($claimTokens, $sourceTokens));
        if ($matched / count($claimTokens) < 0.5) {
            return 'claim_lexical_support_below_half';
        }
        foreach ($sourceTexts as $index => $sourceText) {
            $episodeTokens = array_flip($this->consolidationTokens($sourceText));
            if (count(array_intersect_key($claimTokens, $episodeTokens)) === 0) {
                return 'listed_support_does_not_support_claim:' . (int) $episodes[$index]->id;
            }
        }
        return null;
    }

    public function rejectConsolidationAttempt(array $work, string $reason, bool $sourceChanged = false): array
    {
        $workId = (int) ($work['id'] ?? 0);
        $reason = mb_substr(trim($reason), 0, 1000);
        $retryIds = [];
        $rejectedIds = [];
        foreach (MemoryConsolidationEpisode::getAllByWhere([ 'work_item_id' => $workId, 'status' => 'queued', ]) as $entry) {
            $terminal = !$sourceChanged && (int) $entry->attempts >= Executive::CONSOLIDATION_MAX_ATTEMPTS;
            $entry->setFields([
                'work_item_id' => null,
                'status' => $terminal ? 'rejected' : 'pending',
                'reason' => $reason,

                'attempts' => $sourceChanged ? 0 : (int) $entry->attempts,
                'updated_at' => time(),
            ]);
            $entry->save();
            if ($terminal) {
                $rejectedIds[] = (int) $entry->episode_id;
            } else {
                $retryIds[] = (int) $entry->episode_id;
            }
        }
        if ($retryIds === [] && $rejectedIds === []) {
            return ['status' => 'already_finalized', 'work_item_id' => $workId];
        }
        $event = $this->emit('memory.consolidation.attempt_rejected', [
            'work_item_id' => $workId,
            'reason' => $reason,
            'retry_episode_ids' => $retryIds,
            'rejected_episode_ids' => $rejectedIds,
            'max_attempts' => Executive::CONSOLIDATION_MAX_ATTEMPTS,
            'validator_version' => Executive::CONSOLIDATION_VALIDATOR_VERSION,
        ]);
        return [
            'status' => $retryIds === [] ? 'sources_rejected' : 'retry_scheduled',
            'reason' => $reason,
            'retry_episode_ids' => $retryIds,
            'rejected_episode_ids' => $rejectedIds,
            'event' => $event->getData(),
        ];
    }
}
