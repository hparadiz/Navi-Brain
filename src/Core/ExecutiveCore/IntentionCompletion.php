<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use NaviBrain\Core\ExecutiveComposition;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\WorkItem;

class IntentionCompletion extends Component
{
    public function enqueueCompletionCheck(Intention $intention, ?int $runId, int $now): ?array
    {
        if (trim((string) $intention->success_condition) === '') {
            return null;
        }

        $evidence = [];
        $evidenceCharacters = 0;
        $completionInput = [
            'intention_hash' => $this->completionIntentionHash($intention),
            'evidence' => [],
        ];
        foreach ($this->searchMemory((string) $intention->title . ' ' . (string) $intention->next_action, 8) as $memory) {
            if (!$this->completionEvidenceEligible($memory)) {
                continue;
            }

            $content = (string) $memory['content'];
            $characters = mb_strlen($content);
            if ($characters === 0 || $characters > 1920 - $evidenceCharacters) {
                continue;
            }
            $evidenceCharacters += $characters;
            $evidence[] = [
                'memory_id' => (int) $memory['id'],
                'content' => $content,
            ];
            $completionInput['evidence'][] = [
                'id' => (int) $memory['id'],
                'hash' => $this->completionEvidenceHash($memory),
            ];
        }

        $composition = new ExecutiveComposition('Navi is checking whether something being worked on is actually finished.');
        $composition->contribute(
            'termination_test',
            'Decide whether the success condition is met by the evidence, and nothing more. '
            . 'Being close does not count and neither does having worked on it. '
            . 'If it is met say so plainly; if it is not, say what specifically is still missing.',
            [
                'working_on' => (string) $intention->title,
                'done_when' => (string) $intention->success_condition,
                'believed_next_step' => (string) $intention->next_action,
            ]
        );
        $composition->contribute('evidence', 'Retrieved memory evidence, which may be incomplete. If this does not settle the question, it is not settled.', $evidence);

        return $this->enqueueWork(new WorkItem([
            'parent_run_id' => $runId,
            'parent_intention_id' => (int) $intention->id,
            'work_type' => Executive::COMPLETION_WORK_TYPE,
            'prompt' => $composition->prompt() . "\n\n" . implode("\n", [
                'Return kind intention_complete if the success condition is met, or kind intention_incomplete if it is not.',
                'Put in content either what completed it, or the single next step that would move it closest to done.',
                'Put your confidence in confidence, and in challenged_assumption the belief this check called into question.',
                'Output exactly the four JSON fields in the supplied schema and nothing else.',
            ]),
            'input_refs' => ['intention_id' => (int) $intention->id, 'completion_input' => $completionInput],
            'token_budget' => 384,
            'wall_budget_seconds' => 300,
            'idempotency_key' => sprintf('completion:%d:%d:%s', $intention->id, intdiv($now, 3600), hash('sha256', json_encode($completionInput, JSON_THROW_ON_ERROR)))
        ], true, true));
    }

    public function integrateCompletionCheck(array $work, array $proposal, ?string $model): array
    {
        return $this->applyCompletionCheck($work, $proposal, $model);
    }

    public function applyCompletionCheck(array $work, array $proposal, ?string $model): array
    {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $intentionId = (int) ($refs['intention_id'] ?? 0);
        $intention = Intention::getByID($intentionId);
        if (!$intention instanceof Intention || $intention->status !== 'active') {
            return ['status' => 'not_applicable'];
        }
        $snapshot = is_array($refs['completion_input'] ?? null) ? $refs['completion_input'] : [];
        if (!is_string($snapshot['intention_hash'] ?? null)
            || !hash_equals($snapshot['intention_hash'], $this->completionIntentionHash($intention))
        ) {
            return ['status' => 'rejected', 'reason' => 'stale_or_missing_completion_contract'];
        }
        if (!is_array($snapshot['evidence'] ?? null) || $snapshot['evidence'] === []) {
            return ['status' => 'rejected', 'reason' => 'missing_completion_evidence'];
        }
        foreach ($snapshot['evidence'] as $reference) {
            if (!is_array($reference) || !is_int($reference['id'] ?? null) || $reference['id'] < 1) {
                return ['status' => 'rejected', 'reason' => 'invalid_completion_evidence'];
            }
            $memory = Memory::inspectByID((int) ($reference['id'] ?? 0));
            if (!$memory instanceof Memory || !$this->completionEvidenceEligible($memory->getData())
                || !is_string($reference['hash'] ?? null)
                || !hash_equals($reference['hash'], $this->completionEvidenceHash($memory->getData()))
            ) {
                return ['status' => 'rejected', 'reason' => 'changed_completion_evidence'];
            }
        }

        $kind = (string) ($proposal['kind'] ?? '');
        $content = trim((string) ($proposal['content'] ?? ''));
        $confidence = is_numeric($proposal['confidence'] ?? null) ? (float) $proposal['confidence'] : 0.0;
        if ($content === '') {
            return ['status' => 'rejected', 'reason' => 'empty_judgement'];
        }
        if (!in_array($kind, ['intention_complete', 'intention_incomplete'], true)
            || !is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0
        ) {
            return ['status' => 'rejected', 'reason' => 'invalid_completion_judgement'];
        }
        if ($confidence < Executive::COMPLETION_CONFIDENCE_FLOOR) {
            return ['status' => 'unsettled', 'intention_id' => $intentionId];
        }

        $now = time();
        if ($kind === 'intention_complete' && $confidence >= Executive::COMPLETION_CONFIDENCE_FLOOR) {
            $intention->setFields([ 'status' => 'completed', 'closure_note' => $content, 'updated_at' => $now, ]);
            $intention->save();
            $this->emit('intention.completed', [
                'intention_id' => $intentionId,
                'title' => (string) $intention->title,
                'because' => $content,
                'confidence' => $confidence,
                'model' => $model,
                'assessment' => 'model_judgement_from_unchanged_memory_evidence',
                'completion_input' => $snapshot,
            ]);

            $thread = CognitiveThread::getByField('thread_key', Executive::SELF_PRESENCE_THREAD_KEY);
            if ($thread instanceof CognitiveThread
                && trim((string) $thread->desired_outcome) === (string) $intention->title
            ) {
                $this->releaseFocus($thread, $now, 'achieved');
            }
            return ['status' => 'completed', 'intention_id' => $intentionId];
        }

        if ($content !== (string) $intention->next_action) {
            $previous = (string) $intention->next_action;
            $intention->setFields(['next_action' => $content, 'updated_at' => $now]);
            $intention->save();
            $this->emit('intention.advanced', [ 'intention_id' => $intentionId, 'from' => $previous, 'to' => $content, 'model' => $model, ]);
            return ['status' => 'advanced', 'intention_id' => $intentionId, 'next_action' => $content];
        }
        return ['status' => 'unchanged', 'intention_id' => $intentionId];
    }

    public function completionIntentionHash(Intention $intention): string
    {
        $contract = [];
        $ids = array_unique(array_map('intval', array_merge([(int) $intention->id], (array) $intention->dependencies, $intention->parent_id === null ? [] : [$intention->parent_id])));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $source = $id === (int) $intention->id ? $intention : Intention::getByID($id);
            $contract[$id] = null;
            if (!$source instanceof Intention) {
                continue;
            }
            $contract[$id] = [];
            foreach (['title', 'reason', 'authority', 'status', 'next_action', 'success_condition',
                'release_condition', 'dependencies', 'parent_id'] as $field) {
                $contract[$id][$field] = $source->$field;
            }
        }
        return hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR));
    }

    public function completionEvidenceEligible(array $memory): bool
    {
        $expiry = $memory['expires_at'] ?? null;
        return ($memory['status'] ?? null) === 'active'
            && in_array($memory['tier'] ?? null, ['episodic', 'semantic'], true)
            && $this->isEvidence((string) ($memory['content'] ?? ''))
            && ($expiry === null || (($this->timestamp($expiry) ?? 0) > time()));
    }

    public function completionEvidenceHash(array $memory): string
    {
        return hash('sha256', json_encode([
            'content' => (string) ($memory['content'] ?? ''),
            'tier' => (string) ($memory['tier'] ?? ''),
            'status' => (string) ($memory['status'] ?? ''),
            'confidence' => (float) ($memory['confidence'] ?? 0.0),
            'source_event_id' => $memory['source_event_id'] ?? null,
            'source_event_kind' => $memory['source_event_kind'] ?? null,
            'source_memory_id' => $memory['source_memory_id'] ?? null,
            'supersedes_id' => $memory['supersedes_id'] ?? null,
            'expires_at' => $this->timestamp($memory['expires_at'] ?? null),
        ], JSON_THROW_ON_ERROR));
    }
}
