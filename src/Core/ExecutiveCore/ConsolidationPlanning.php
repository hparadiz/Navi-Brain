<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use NaviBrain\Core\ExecutiveComposition;
use NaviBrain\Model\Memory;
use NaviBrain\Model\MemoryConsolidationEpisode;
use NaviBrain\Model\WorkItem;
use RuntimeException;

class ConsolidationPlanning extends Component
{
    public function enqueueConsolidation(?int $runId, int $now, bool $synchronize = true): ?array
    {
        if ($synchronize) {
            $this->synchronizeConsolidationEpisodes();
        }

        $pendingEntries = [];
        foreach (MemoryConsolidationEpisode::getAllByWhere(['status' => 'pending'], ['order' => ['episode_id' => 'ASC'], 'limit' => Executive::CONSOLIDATION_PENDING_WINDOW]) as $entry) {
            $pendingEntries[(int) $entry->episode_id] = $entry;
        }
        $episodesById = [];
        foreach (Memory::getManyByID(array_keys($pendingEntries), true) as $episode) {
            $episodesById[(int) $episode->id] = $episode;
        }
        $pending = [];
        foreach ($pendingEntries as $episodeId => $entry) {
            $episode = $episodesById[$episodeId] ?? null;
            if (!$episode instanceof Memory || $episode->tier !== 'episodic' || $episode->status !== 'active'
                || ($episode->expires_at !== null && ($this->timestamp($episode->expires_at) ?? 0) <= $now)) {
                $entry->setFields([ 'status' => 'rejected', 'reason' => 'episode_missing_inactive_or_expired_before_replay', 'updated_at' => $now, ]);
                $entry->save();
                continue;
            }
            $pending[(int) $episode->id] = $episode;
        }
        if ($pending === []) {
            return null;
        }

        $fresh = [$pending[array_key_first($pending)]];
        $seed = $fresh[0];
        $evidenceIdentity = fn (Memory $episode): string => hash('sha256', $this->normalizeSearchText($this->consolidationEvidenceText($episode)));
        $selectedIds = [(int) $seed->id => true];
        $selectedEvidence = [$evidenceIdentity($seed) => true];

        $recentEntries = MemoryConsolidationEpisode::getAllByWhere(['status' => 'pending'], ['order' => ['episode_id' => 'DESC'], 'limit' => Executive::CONSOLIDATION_PENDING_WINDOW]);
        $recentIds = array_map(static fn (MemoryConsolidationEpisode $entry): int => (int) $entry->episode_id, $recentEntries);

        $recentById = [];
        foreach (Memory::getManyByID($recentIds, false) as $episode) {
            $recentById[(int) $episode->id] = $episode;
        }
        foreach ($recentIds as $episodeId) {
            $episode = $recentById[$episodeId] ?? null;
            if (!$episode instanceof Memory
                || $episode->tier !== 'episodic'
                || $episode->status !== 'active'
                || isset($selectedIds[$episodeId])
            ) {
                continue;
            }
            $expiresAt = $this->timestamp($episode->expires_at);
            if (($episode->expires_at !== null && ($expiresAt ?? 0) <= $now)
                || $this->consolidationSimilarity($seed, $episode) <= 0.0
            ) {
                continue;
            }
            $identity = $evidenceIdentity($episode);
            if (isset($selectedEvidence[$identity])) {
                continue;
            }
            $fresh[] = $episode;
            $selectedIds[$episodeId] = true;
            $selectedEvidence[$identity] = true;
            break;
        }
        $ranked = [];
        foreach ($pending as $episodeId => $episode) {
            if (isset($selectedIds[$episodeId])) {
                continue;
            }
            $score = $this->consolidationSimilarity($seed, $episode);
            if ($score > 0.0) {
                $ranked[] = ['score' => $score, 'episode' => $episode];
            }
        }
        usort($ranked, static function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];
            return $score !== 0
                ? $score
                : ((int) $left['episode']->id <=> (int) $right['episode']->id);
        });
        foreach ($ranked as $candidate) {
            if (count($fresh) >= Executive::CONSOLIDATION_NEW) {
                break;
            }
            $episode = $candidate['episode'];
            $identity = $evidenceIdentity($episode);
            if (isset($selectedEvidence[$identity])) {
                continue;
            }
            $fresh[] = $episode;
            $selectedIds[(int) $episode->id] = true;
            $selectedEvidence[$identity] = true;
        }

        $interleaved = [];
        $interleavedEntries = MemoryConsolidationEpisode::getAllByWhere(['status' => 'consolidated'], ['order' => ['episode_id' => 'DESC'], 'limit' => 300]);
        $interleavedById = [];
        foreach (Memory::getManyByID(array_map( static fn (MemoryConsolidationEpisode $entry): int => (int) $entry->episode_id, $interleavedEntries ), true) as $episode) {
            $interleavedById[(int) $episode->id] = $episode;
        }
        foreach ($interleavedEntries as $entry) {
            $episode = $interleavedById[(int) $entry->episode_id] ?? null;
            if (!$episode instanceof Memory
                || $episode->tier !== 'episodic'
                || $episode->status !== 'active'
                || $this->consolidationSimilarity($seed, $episode) <= 0.0
            ) {
                continue;
            }
            $expiresAt = $this->timestamp($episode->expires_at);
            if ($episode->expires_at !== null && ($expiresAt ?? 0) <= $now) {
                continue;
            }
            $interleaved[] = $episode;
            if (count($interleaved) >= Executive::CONSOLIDATION_INTERLEAVED) {
                break;
            }
        }

        $subject = implode(' ', array_map( fn (Memory $episode): string => $this->consolidationEvidenceText($episode), array_slice($fresh, 0, 3) ));
        $existing = $this->consolidationExistingKnowledge($subject);

        $freshEvidence = array_map(fn (Memory $episode): array => [ 'id' => (int) $episode->id, 'evidence' => $this->consolidationEvidenceText($episode), ], $fresh);
        $comparisonEvidence = array_map(fn (Memory $episode): array => [ 'id' => (int) $episode->id, 'evidence' => $this->consolidationEvidenceText($episode), ], $interleaved);
        $episodeIds = array_column($freshEvidence, 'id');
        $evidenceHashes = [];
        foreach ($freshEvidence as $evidence) {
            $evidenceHashes[(string) $evidence['id']] = hash('sha256', (string) $evidence['evidence']);
        }

        $composition = new ExecutiveComposition('Consolidate related episodes into at most one durable claim, with exact source accounting.');
        $composition->contribute(
            'fresh_evidence',
            implode(' ', [
                'Only these IDs may support the new claim.',
                'Observed results are evidence; intentions, expected outcomes, questions, and failed-command rationales are not.',
                'Use source wording for concrete names, paths, versions, flags, quantities, and quoted text.',
            ]),
            $freshEvidence
        );
        if ($comparisonEvidence !== []) {
            $composition->contribute('comparison_only', 'These older episodes may expose contradictions, but their IDs cannot be listed as support.', $comparisonEvidence);
        }
        $prompt = $composition->prompt() . "\n\n" . implode("\n", [
            'Return kind memory_consolidation and put the claim itself in content.',
            'Return supported_episode_ids and rejected_episode_ids as arrays of integer IDs.',
            sprintf('Those two arrays may contain only fresh evidence IDs %s; no other integer is valid in either array.', json_encode($episodeIds, JSON_THROW_ON_ERROR)),
            'Return rejection_reason as a string and supersedes_memory_id as either an integer ID or null.',
            'supported_episode_ids and rejected_episode_ids must be disjoint and together contain every fresh evidence ID exactly once.',
            'A claim may use only supported fresh evidence. Comparison episodes cannot support it.',
            'If no durable claim is warranted, use empty content, no supported IDs, reject every fresh ID, and explain why.',
            'When no fresh ID is rejected, set rejection_reason to "none".',
            'supersedes_memory_id must be null. Related existing memories are resolved deterministically after grounding.',
            'Put calibrated confidence in confidence and the tested belief in challenged_assumption.',
            'Output exactly the eight JSON fields in the supplied schema and nothing else.',
        ]);

        $attempt = 1;
        foreach ($fresh as $episode) {
            $entry = MemoryConsolidationEpisode::getByField('episode_id', (int) $episode->id);
            if ($entry instanceof MemoryConsolidationEpisode) {
                $attempt = max($attempt, (int) $entry->attempts + 1);
            }
        }
        $batchHash = substr(hash('sha256', implode(',', $episodeIds)), 0, 16);
        $queued = $this->enqueueWork(new WorkItem([
            'parent_run_id' => $runId,
            'parent_intention_id' => null,
            'work_type' => Executive::MEMORY_CONSOLIDATION_WORK_TYPE,
            'prompt' => $prompt,
            'input_refs' => [
                'validator_version' => Executive::CONSOLIDATION_VALIDATOR_VERSION,
                'episode_ids' => $episodeIds,
                'interleaved_ids' => array_column($comparisonEvidence, 'id'),
                'existing_memory_ids' => array_column($existing, 'id'),
                'evidence_hashes' => $evidenceHashes,
            ],
            'token_budget' => 512,
            'wall_budget_seconds' => 300,
            'idempotency_key' => sprintf('consolidate:v%d:%d:%d:%s:%s', Executive::CONSOLIDATION_VALIDATOR_VERSION, (int) $seed->id, $attempt, $batchHash, bin2hex(random_bytes(4)))
        ], true, true));
        $workId = (int) ($queued['work_item']['id'] ?? 0);
        if ($workId < 1) {
            throw new RuntimeException('Consolidation queue did not return a work item ID.');
        }
        foreach ($fresh as $episode) {
            $entry = MemoryConsolidationEpisode::getByField('episode_id', (int) $episode->id);
            if (!$entry instanceof MemoryConsolidationEpisode) {
                throw new RuntimeException('Consolidation ledger lost a queued episode.');
            }
            $entry->setFields([
                'work_item_id' => $workId,
                'status' => 'queued',
                'attempts' => (int) $entry->attempts + 1,
                'reason' => null,
                'validator_version' => Executive::CONSOLIDATION_VALIDATOR_VERSION,
                'updated_at' => $now,
            ]);
            $entry->save();
        }
        $this->emit('memory.consolidation.batch.queued', [ 'work_item_id' => $workId, 'episode_ids' => $episodeIds, 'validator_version' => Executive::CONSOLIDATION_VALIDATOR_VERSION, ]);
        return $queued + ['episode_ids' => $episodeIds];
    }

    public function consolidationSimilarity(Memory $left, Memory $right): float
    {
        $leftType = $this->consolidationEpisodeType((string) $left->content);
        if ($leftType !== $this->consolidationEpisodeType((string) $right->content)) {
            return 0.0;
        }
        $leftEvidence = $this->consolidationEvidenceText($left);
        $rightEvidence = $this->consolidationEvidenceText($right);
        if ($this->normalizeSearchText($leftEvidence) === $this->normalizeSearchText($rightEvidence)) {
            return 10.0;
        }
        $leftCommand = $this->consolidationCommand($leftEvidence);
        $rightCommand = $this->consolidationCommand($rightEvidence);
        if ($leftCommand !== null && $leftCommand === $rightCommand) {
            return 8.0;
        }
        $leftTokens = array_flip($this->consolidationTokens($leftEvidence));
        $rightTokens = array_flip($this->consolidationTokens($rightEvidence));
        $minimum = min(count($leftTokens), count($rightTokens));
        if ($minimum === 0) {
            return 0.0;
        }
        $shared = count(array_intersect_key($leftTokens, $rightTokens));
        if ($shared < 3) {
            return 0.0;
        }
        $containment = $shared / $minimum;
        $union = count($leftTokens + $rightTokens);
        $jaccard = $union === 0 ? 0.0 : $shared / $union;
        return $containment >= 0.35 && $jaccard >= 0.2
            ? $jaccard
            : 0.0;
    }

    public function consolidationEpisodeType(string $content): string
    {
        return match (true) {
            str_starts_with($content, 'On this machine '),
            str_starts_with($content, 'Looking at this machine ') => 'machine_observation',
            str_starts_with($content, 'Action "') => 'action_outcome',
            str_starts_with($content, 'The user said:') => 'user_utterance',
            str_starts_with($content, 'Session in '),
            preg_match('/\AAutomatic .+ continuity from the most recent captured (?:Codex|Claude Code) session\./u', $content) === 1,
            str_starts_with($content, 'Implemented and verified ') => 'session_record',
            default => 'observation',
        };
    }

    public function consolidationEvidenceText(Memory $episode): string
    {
        $content = trim((string) $episode->content);
        if (str_starts_with($content, 'On this machine "')) {
            $answers = strpos($content, '" answers ');
            $returned = strrpos($content, '. It returned: ');
            if ($answers !== false && $returned !== false && $returned > $answers) {
                $command = substr($content, strlen('On this machine "'), $answers - strlen('On this machine "'));
                return sprintf('Command "%s" succeeded. Observed output: %s', $command, substr($content, $returned + strlen('. It returned: ')));
            }
            $failed = strpos($content, '" does not work as a way to find out ');
            $exited = strrpos($content, '. It exited ');
            if ($failed !== false && $exited !== false && $exited > $failed) {
                $command = substr($content, strlen('On this machine "'), $failed - strlen('On this machine "'));
                return sprintf('Command "%s" failed. Observed result: %s', $command, substr($content, $exited + strlen('. It exited ')));
            }
        }
        if (str_starts_with($content, 'Action "')) {
            $observed = strpos($content, ' and observed "');
            $outcome = strrpos($content, '". Outcome: ');
            if ($observed !== false && $outcome !== false && $outcome > $observed) {
                return 'Action observation: '
                    . substr($content, $observed + strlen(' and observed "'), $outcome - ($observed + strlen(' and observed "')))
                    . '. Outcome: ' . substr($content, $outcome + strlen('". Outcome: '));
            }
        }
        return mb_substr($content, 0, 800);
    }

    public function consolidationCommand(string $evidence): ?string
    {
        if (!str_starts_with($evidence, 'Command "')) {
            return null;
        }
        $end = strpos($evidence, '" ', strlen('Command "'));
        return $end === false
            ? null
            : $this->normalizeSearchText(substr($evidence, strlen('Command "'), $end - strlen('Command "')));
    }

    public function consolidationTokens(string $content): array
    {
        $stop = array_flip([
            'about', 'after', 'again', 'also', 'because', 'been', 'before', 'being', 'could', 'does',
            'from', 'have', 'into', 'just', 'more', 'most', 'only', 'other', 'should', 'that', 'their',
            'there', 'these', 'they', 'this', 'through', 'user', 'what', 'when', 'where', 'which', 'while',
            'with', 'would', 'your', 'action', 'command', 'episode', 'observed', 'observation', 'outcome',
            'result', 'returned', 'said', 'succeeded', 'failed',
        ]);
        $tokens = [];
        foreach (preg_split('/[^\p{L}\p{N}_=.\/-]+/u', mb_strtolower($content)) ?: [] as $token) {
            $token = trim($token, './-_=');
            if (mb_strlen($token) < 3 || isset($stop[$token])) {
                continue;
            }
            $tokens[$token] = true;
        }
        return array_keys($tokens);
    }

    public function consolidationExistingKnowledge(string $subject): array
    {
        $subjectTokens = array_flip($this->consolidationTokens($subject));
        if ($subjectTokens === []) {
            return [];
        }

        $ranked = [];
        foreach (Memory::rankCandidates($subject, 100, 'semantic', 'active') as $candidate) {
            $claimTokens = array_flip($this->consolidationTokens((string) $candidate->content));
            $shared = count(array_intersect_key($subjectTokens, $claimTokens));
            if ($shared < 3) {
                continue;
            }
            $ranked[] = [
                'id' => (int) $candidate->id,
                'claim' => (string) $candidate->content,
                'shared' => $shared,
                'containment' => $shared / max(1, count($claimTokens)),
            ];
        }
        usort($ranked, static function (array $left, array $right): int {
            $shared = $right['shared'] <=> $left['shared'];
            if ($shared !== 0) {
                return $shared;
            }
            $containment = $right['containment'] <=> $left['containment'];
            return $containment !== 0
                ? $containment
                : ($right['id'] <=> $left['id']);
        });

        $selected = array_slice($ranked, 0, Executive::CONSOLIDATION_EXISTING);
        Memory::observeRecords(Memory::getManyByID( array_map(static fn (array $candidate): int => (int) $candidate['id'], $selected), false ));
        return array_map(static fn (array $candidate): array => [ 'id' => $candidate['id'], 'claim' => $candidate['claim'], ], $selected);
    }

    public function consolidationCoveredByExisting(string $claim, array $candidateIds): ?Memory
    {
        $candidates = Memory::getManyByID($candidateIds, false);
        foreach ($candidates as $candidate) {
            if ($this->consolidationClaimIsIdentical($claim, $candidate)) {
                Memory::observeRecords([$candidate]);
                return $candidate;
            }
        }
        return null;
    }

    public function consolidationClaimIsIdentical(string $claim, Memory $candidate): bool
    {

        return $candidate->tier === 'semantic' && $candidate->status === 'active'
            && ($candidate->expires_at === null
                || ($this->timestamp($candidate->expires_at) ?? 0) > time())
            && trim((string) $candidate->content) === trim($claim);
    }
}
