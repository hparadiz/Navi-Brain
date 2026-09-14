<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use InvalidArgumentException;
use NaviBrain\Core\WorkCompletion;
use NaviBrain\Core\DecisionStateMachine;
use NaviBrain\Core\ExecutiveControl;
use NaviBrain\Core\NarrativeSynthesis;
use NaviBrain\Core\OtherModel;
use NaviBrain\Core\PublicReflection;
use NaviBrain\Model\CycleRun;
use NaviBrain\Model\Need;
use NaviBrain\Model\Rhythm;
use NaviBrain\Model\ThoughtArtifact;
use NaviBrain\Model\WorkItem;
use NaviBrain\Model\WorkingMemorySlot;
use NaviBrain\Perception\SocialFeedback;
use RuntimeException;
use Throwable;

class WorkerResults extends Component
{
    public function finishWork(WorkCompletion $completion): array
    {
        $completion->validate();
        $work = WorkItem::getByID($completion->work->id);
        if (!$work instanceof WorkItem) {
            throw new RuntimeException(sprintf('Work item %d does not exist.', $completion->work->id));
        }
        if ($work->status !== 'leased'
            || $work->lease_owner !== $completion->work->lease_owner
            || (int) $work->fencing_token !== $completion->work->fencing_token
            || ($this->timestamp($work->lease_expires_at) ?? 0) < time()
        ) {
            throw new RuntimeException('Worker lease is stale; completion was rejected by the fencing check.');
        }

        $now = time();
        $artifact = null;
        $workingProjection = null;
        if ($completion->succeeded) {
            $this->validateWorkerProposal($completion->result, (string) $work->work_type);
            $content = json_encode($completion->result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $hash = hash('sha256', 'worker|' . $work->id . '|' . $content);
            $artifact = ThoughtArtifact::getByField('content_hash', $hash);
            if (!$artifact instanceof ThoughtArtifact) {

                $artifact = new ThoughtArtifact([
                    'run_id' => $work->parent_run_id,
                    'kind' => (string) $completion->result['kind'],
                    'content' => (string) $completion->result['content'],
                    'confidence' => (float) $completion->result['confidence'],
                    'provenance' => 'worker',
                    'source_ids' => [
                        'work_item_id' => $work->id,
                        'run_id' => $work->parent_run_id,
                        'input_refs' => $work->input_refs,
                    ],
                    'status' => 'proposed',
                    'content_hash' => $hash,
                ], true, true);
                $artifact->save();
                $this->emit('thought.proposed', [
                    'artifact_id' => $artifact->id,
                    'run_id' => $work->parent_run_id,
                    'work_item_id' => $work->id,
                    'provenance' => 'worker',
                    'synthetic' => true,
                    'model' => $completion->model,
                    'external_action_authorized' => false,
                ]);
            }

            if (!in_array($work->work_type, [
                OtherModel::WORK_TYPE,
                SocialFeedback::WORK_TYPE,
                Executive::MEMORY_CONSOLIDATION_WORK_TYPE,
                Executive::COMPLETION_WORK_TYPE,
                Executive::MIND_STREAM_WORK_TYPE,
                NarrativeSynthesis::PERSONALITY_WORK_TYPE,
                NarrativeSynthesis::MOTIVATION_WORK_TYPE,
                NarrativeSynthesis::INTENTION_WORK_TYPE,
                PublicReflection::WORK_TYPE,
            ], true)) {
                $workingProjection = new WorkingMemorySlot([
                    'slot_role' => 'reasoning_result',
                    'claim' => sprintf('Uncurated %s proposal: %s', (string) $completion->result['kind'], (string) $completion->result['content']),
                    'record_type' => 'thought_artifact',
                    'record_id' => $artifact instanceof ThoughtArtifact ? (int) $artifact->id : null,
                    'confidence' => (float) $completion->result['confidence'],
                    'expires_at' => $now + 900,
                ], true, true);
            }
        }

        $work->setFields([
            'completed_at' => $now,
            'updated_at' => $now,
            'status' => $completion->succeeded ? 'completed' : 'failed',
            'model' => $completion->model,
            'result' => $completion->result,
            'error' => $completion->error,
            'lease_owner' => null,
            'lease_expires_at' => null,
        ]);
        $work->save();
        $event = $this->emit($completion->succeeded ? 'work.completed' : 'work.failed', [
            'work_item_id' => $work->id,
            'parent_run_id' => $work->parent_run_id,
            'model' => $completion->model,
            'artifact_id' => $artifact?->id,
            'error' => $completion->error,
        ]);

        if ($work->parent_run_id !== null) {
            $run = CycleRun::getByID((int) $work->parent_run_id);
            if ($run instanceof CycleRun && $run->status === 'running') {
                $rhythm = Rhythm::getByID((int) $run->rhythm_id);
                if ($rhythm instanceof Rhythm) {
                    if ($completion->succeeded) {
                        $output = $run->output;
                        $output['worker'] = [
                            'work_item_id' => $work->id,
                            'artifact_id' => $artifact?->id,
                            'model' => $completion->model,
                        ];
                        $this->completeCycleRun($run, $rhythm, (string) $run->node_id, $output);
                    } else {
                        $this->failCycleRun($run, $rhythm, (string) $run->node_id, (string) $completion->error);
                    }
                }
            }
        }

        $completed = [
            'work_item' => $work->getData(),
            'artifact' => $artifact?->getData(),
            'event' => $event->getData(),
        ];
        if ($workingProjection instanceof WorkingMemorySlot) {
            $this->executive->workingMemory->publish($workingProjection);
        }
        return $completed;
    }

    public function integrateWorkerResult(array $work, array $proposal, ?string $model): array
    {
        if (($work['work_type'] ?? null) === OtherModel::WORK_TYPE) {
            return $this->executive->otherModel->integrateParserProposal($work, $proposal, $model);
        }
        if (($work['work_type'] ?? null) === SocialFeedback::WORK_TYPE) {
            return $this->socialFeedback()->integrateReflection($work, $proposal);
        }
        if (($work['work_type'] ?? null) === DecisionStateMachine::WORK_TYPE) {
            return $this->executive->decisionStateMachine->integrate($work, $proposal, $model);
        }
        if (($work['work_type'] ?? null) === Executive::MIND_STREAM_WORK_TYPE) {
            return $this->integrateMindStreamThought($work, $proposal, $model);
        }
        if (($work['work_type'] ?? null) === Executive::EPISTEMIC_ADVANCE_WORK_TYPE) {
            return $this->integrateEpistemicAdvanceResult($work, $proposal, $model);
        }
        if (($work['work_type'] ?? null) === Executive::MEMORY_CONSOLIDATION_WORK_TYPE) {
            return $this->integrateConsolidation($work, $proposal, $model);
        }
        if (($work['work_type'] ?? null) === Executive::LOOK_WORK_TYPE) {
            return $this->integrateLookProposal($work, $proposal, $model);
        }
        if (($work['work_type'] ?? null) === Executive::COMPLETION_WORK_TYPE) {
            return $this->integrateCompletionCheck($work, $proposal, $model);
        }
        if (NarrativeSynthesis::isWorkType((string) ($work['work_type'] ?? ''))) {
            return $this->integrateNarrativeSynthesis($work, $proposal, $model);
        }
        $workType = (string) ($work['work_type'] ?? '');
        if (!Executive::isSelfPresenceWorkType($workType)) {
            return ['status' => 'not_applicable'];
        }

        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $threadId = (int) ($refs['thread_id'] ?? 0);
        $stepId = (int) ($refs['thread_step_id'] ?? 0);
        $workId = (int) ($work['id'] ?? 0);
        $thread = $this->requireCognitiveThread($threadId);
        $step = $this->requireThreadStep($stepId);
        if ((int) $step->thread_id !== $threadId) {
            throw new RuntimeException('Worker result references a thread step from another thread.');
        }
        if (in_array($step->status, ['succeeded', 'failed', 'cancelled'], true)) {
            return ['status' => 'already_integrated', 'thread_step' => $step->getData()];
        }
        if ((int) $step->worker_work_item_id !== $workId
            || (int) $step->fencing_token !== (int) ($refs['thread_fencing_token'] ?? -1)
            || (int) $thread->fencing_token !== (int) $step->fencing_token
        ) {
            throw new RuntimeException('Worker result was rejected by the cognitive-thread fencing check.');
        }

        $validation = $this->validateSelfPresenceProposal($proposal, $thread, $workType === Executive::SELF_PRESENCE_ANSWER_WORK_TYPE);
        if (!$validation['accepted']) {
            $step->setFields(['proposal' => $proposal, 'deterministic_checks' => $validation['checks']]);
            return $this->rejectSelfPresenceProposal($thread, $step, $validation['reason']);
        }

        $normalized = $validation['proposal'];

        if ($workType === Executive::SELF_PRESENCE_SPEECH_WORK_TYPE
            && $this->pendingAddressedEvents() !== []
        ) {
            $checks = $validation['checks'];
            $checks['not_superseded_by_addressed_speech'] = false;
            $step->setFields(['proposal' => $normalized, 'deterministic_checks' => $checks]);
            return $this->rejectSelfPresenceProposal($thread, $step, 'Addressed speech arrived after this background proposal was composed.');
        }
        if ($workType === Executive::SELF_PRESENCE_ANSWER_WORK_TYPE
            && $normalized['kind'] !== 'self_presence_utterance'
        ) {
            $checks = $validation['checks'];
            $checks['addressed_speech_requires_answer'] = false;
            $step->setFields(['proposal' => $normalized, 'deterministic_checks' => $checks]);
            return $this->rejectSelfPresenceProposal($thread, $step, 'Addressed speech requires an utterance, not a silence choice.');
        }
        if ($normalized['kind'] === 'remain_silent') {
            $step->setFields(['proposal' => $normalized, 'deterministic_checks' => $validation['checks']]);
            return $this->acceptSelfPresenceSilence($thread, $step, $model);
        }

        $now = time();
        $gate = $this->selfPresenceSpeechGate($thread, $now, $workType === Executive::SELF_PRESENCE_ANSWER_WORK_TYPE);
        if (!$gate['allowed']) {
            $step->setFields(['proposal' => $normalized, 'deterministic_checks' => $validation['checks']]);
            return $this->deferAcceptedSelfPresenceSpeech($thread, $step, $gate, $model);
        }

        if (ExecutiveControl::status()['paused']) {
            $step->setFields(['proposal' => $normalized, 'deterministic_checks' => $validation['checks']]);
            return $this->deferAcceptedSelfPresenceSpeech($thread, $step, ['allowed' => false, 'reason' => 'cognition_paused'], $model);
        }

        $currentThread = $this->requireCognitiveThread($threadId);
        $currentStep = $this->requireThreadStep($stepId);
        if ($currentStep->status !== 'running'
            || (int) $currentThread->fencing_token !== (int) $currentStep->fencing_token
        ) {
            throw new RuntimeException('Self-presence dispatch lost its thread fence.');
        }
        $currentStep->setFields([
            'proposal' => $normalized,
            'deterministic_checks' => $validation['checks'],
            'curator_verdict' => 'accepted',
            'observed_result' => [
                'dispatch_committed' => true,
                'speech_outcome' => 'pending',
                'work_item_id' => $workId,
                'model' => $model,
            ],
            'status' => 'dispatching',
        ]);
        $currentStep->save();
        $currentThread->setFields([ 'phase' => 'dispatching', 'last_observation' => 'A curated local speech dispatch was committed; its delivery outcome is not recorded yet.', 'updated_at' => time(), ]);
        $currentThread->save();
        $this->emit('thread.speech.dispatching', [ 'thread_id' => $threadId, 'thread_step_id' => $stepId, 'work_item_id' => $workId, 'actuator' => 'pet_http_speak', 'model' => $model, ]);

        try {
            $speechResult = $this->executive->speechActuator->speak((string) $normalized['content']);
        } catch (Throwable $throwable) {
            return $this->finishIndeterminateSelfPresenceDispatch($threadId, $stepId, $workId, $throwable->getMessage());
        }

        $spokenSeconds = (float) ($speechResult['body']['duration_secs'] ?? 0.0);
        if ($spokenSeconds > 0.0) {
            $this->emit('speech.timed', [ 'thread_id' => $threadId, 'words' => str_word_count((string) $normalized['content']), 'duration_seconds' => round($spokenSeconds, 2), ]);
        }

        $currentThread = $this->requireCognitiveThread($threadId);
        $currentStep = $this->requireThreadStep($stepId);
        if ($currentStep->status !== 'dispatching') {
            return ['status' => 'dispatch_already_finalized', 'thread_step' => $currentStep->getData()];
        }
        $now = time();
        $nextWake = $now;
        $spent = is_array($currentThread->spent) ? $currentThread->spent : [];
        $spent['worker_calls'] = (int) ($spent['worker_calls'] ?? 0) + 1;
        $spent['speech_count'] = (int) ($spent['speech_count'] ?? 0) + 1;
        $currentStep->setFields([
            'completed_at' => $now,
            'observed_result' => [
                'spoken' => true,
                'speech_outcome' => 'accepted_by_pet_bridge',
                'actuator' => 'pet_http_speak',
                'response' => $speechResult,
                'work_item_id' => $workId,
                'model' => $model,
            ],
            'post_state' => ['thread_phase' => 'waiting', 'next_operation' => 'evaluate_self_presence'],
            'next_wake_at' => $nextWake,
            'status' => 'succeeded',
        ]);
        $currentStep->save();
        $currentThread->setFields([
            'phase' => 'waiting',
            'next_operation' => 'evaluate_self_presence',
            'wake_at' => $nextWake,
            'spent' => $spent,
            'progress' => min(1.0, (float) $currentThread->progress + 0.25),
            'stagnation_count' => 0,
            'status' => 'waiting',
            'last_observation' => 'A bounded self-presence utterance was accepted by the local Pet speech bridge.',
            'updated_at' => $now,
        ]);
        $currentThread->save();
        $this->markWorkArtifact($workId, 'accepted');

        $this->socialFeedback()->registerUtterance($stepId, (string) $normalized['content'], $now);

        $need = Need::getByField('need_key', Executive::SELF_PRESENCE_NEED_KEY);
        $satisfaction = $need instanceof Need
            ? $this->satisfyNeedRecord($need, 1.0, 'local Pet speech from thread step ' . $stepId, $now)
            : null;

        $event = $this->emit('thread.speech.spoken', [ 'thread_id' => $threadId, 'thread_step_id' => $stepId, 'work_item_id' => $workId, 'next_wake_at' => $nextWake, 'actuator' => 'pet_http_speak', ]);
        $result = [
            'status' => 'spoken',
            'thread' => $currentThread->getData(),
            'thread_step' => $currentStep->getData(),
            'satisfaction' => $satisfaction,
            'event' => $event->getData(),
        ];

        $memory = $this->rememberUtterance(
            channel: 'spoke_aloud',
            content: (string) $normalized['content'],
            confidence: 0.9,
            sourceEventId: (int) ($result['event']['id'] ?? 0),
            refs: [
                'thread_id' => $threadId,
                'thread_step_id' => $stepId,
                'work_item_id' => $workId,
            ]
        );
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        foreach ((array) ($refs['addressed_event_ids'] ?? []) as $senseEventId) {
            try {
                $this->sensoryCortex()->recordOutcome((int) $senseEventId, 'accepted', 'Answered by self-presence speech.', (int) ($memory['memory']['id'] ?? 0) ?: null);
            } catch (Throwable) {

            }
        }
        $result['memory'] = $memory;
        return $result;
    }

    public function integrateNarrativeSynthesis(array $work, array $proposal, ?string $model): array
    {
        $workId = (int) ($work['id'] ?? 0);
        $workType = (string) ($work['work_type'] ?? '');
        $errors = NarrativeSynthesis::validationErrors($workType, $proposal);
        if ($errors !== []) {
            $artifact = $this->markWorkArtifact($workId, 'rejected');
            $event = $this->emit('narrative.synthesis_rejected', [
                'work_item_id' => $workId,
                'work_type' => $workType,
                'artifact_id' => $artifact?->id,
                'model' => $model,
                'errors' => $errors,
                'experimental' => true,
            ]);
            return [
                'status' => 'rejected',
                'errors' => $errors,
                'artifact' => $artifact?->getData(),
                'event' => $event->getData(),
            ];
        }

        $path = NarrativeSynthesis::mirrorLatest($workType, (string) $proposal['content']);
        $artifact = $this->markWorkArtifact($workId, 'accepted');
        if (!$artifact instanceof ThoughtArtifact) {
            throw new RuntimeException('Narrative synthesis artifact could not be found for acceptance.');
        }
        $event = $this->emit('narrative.synthesized', [
            'work_item_id' => $workId,
            'work_type' => $workType,
            'artifact_id' => $artifact->id,
            'model' => $model,
            'path' => $path,
            'evidence_checksum' => is_array($work['input_refs'] ?? null)
                ? ($work['input_refs']['evidence_checksum'] ?? null)
                : null,
            'experimental' => true,
        ]);

        return [
            'status' => 'accepted',
            'artifact' => $artifact->getData(),
            'path' => $path,
            'event' => $event->getData(),
        ];
    }

    public function handleWorkerFailure(array $work, string $error): array
    {
        $workType = $work['work_type'] ?? null;
        if (NarrativeSynthesis::isWorkType((string) $workType)) {
            $event = $this->emit('narrative.synthesis_failed', [ 'work_item_id' => (int) ($work['id'] ?? 0), 'work_type' => $workType, 'error' => $error, 'experimental' => true, ]);
            return [
                'status' => 'narrative_synthesis_failed',
                'event' => $event->getData(),
            ];
        }
        if ($workType === OtherModel::WORK_TYPE) {
            return $this->executive->otherModel->failParserWork($work, $error);
        }
        if ($workType === SocialFeedback::WORK_TYPE) {
            return $this->socialFeedback()->failReflection($work, $error);
        }
        if ($workType === DecisionStateMachine::WORK_TYPE) {
            return $this->executive->decisionStateMachine->failWork($work, $error);
        }
        if ($workType === Executive::MEMORY_CONSOLIDATION_WORK_TYPE) {
            return $this->rejectConsolidationAttempt($work, $error);
        }
        if (!Executive::isSelfPresenceWorkType((string) $workType)
            && $workType !== Executive::EPISTEMIC_ADVANCE_WORK_TYPE
            && $workType !== Executive::MIND_STREAM_WORK_TYPE
        ) {
            return ['status' => 'not_applicable'];
        }
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $threadId = (int) ($refs['thread_id'] ?? 0);
        $stepId = (int) ($refs['thread_step_id'] ?? 0);
        $workId = (int) ($work['id'] ?? 0);

        if ($workType === Executive::EPISTEMIC_ADVANCE_WORK_TYPE
            || $workType === Executive::MIND_STREAM_WORK_TYPE
        ) {
            return $this->failEpistemicAdvanceStep($threadId, $stepId, $workId, $error);
        }

        $thread = $this->requireCognitiveThread($threadId);
        $step = $this->requireThreadStep($stepId);
        if (in_array($step->status, ['succeeded', 'failed', 'cancelled'], true)) {
            return ['status' => 'already_finalized', 'thread_step' => $step->getData()];
        }
        if ($step->status === 'dispatching') {
            return $this->finishIndeterminateSelfPresenceDispatch($threadId, $stepId, $workId, 'Worker failure reported after dispatch commit: ' . $error);
        }

        $now = time();
        $addressed = $this->pendingAddressedEvents() !== [];
        $nextWake = $now;
        $step->setFields([
            'completed_at' => $now,
            'observed_result' => ['worker_failed' => true, 'work_item_id' => $workId],
            'post_state' => ['thread_phase' => 'waiting'],
            'next_wake_at' => $nextWake,
            'status' => 'failed',
            'error' => $error,
        ]);
        $step->save();
        $thread->setFields([
            'phase' => $addressed ? 'interrupted' : 'waiting',
            'wake_at' => $nextWake,
            'status' => 'waiting',
            'stagnation_count' => (int) $thread->stagnation_count + 1,
            'last_observation' => 'The bounded speech-judgment worker failed; the thread stayed active and will try a fresh generation.',
            'updated_at' => $now,
        ]);
        $thread->save();
        $event = $this->emit('thread.worker.failed', [ 'thread_id' => $threadId, 'thread_step_id' => $stepId, 'work_item_id' => $workId, 'error' => $error, 'next_wake_at' => $nextWake, ]);
        return [
            'status' => 'worker_failed',
            'thread' => $thread->getData(),
            'thread_step' => $step->getData(),
            'event' => $event->getData(),
        ];
    }

    public function validateWorkerProposal(array $result, ?string $workType = null): void
    {
        if ($workType === PublicReflection::WORK_TYPE && ($result['kind'] ?? '') !== $workType) {
            throw new InvalidArgumentException('Public reflection returned an unrelated proposal kind.');
        }
        $consolidation = $workType === Executive::MEMORY_CONSOLIDATION_WORK_TYPE;
        $required = ['kind', 'content', 'confidence', 'challenged_assumption'];
        if ($consolidation) {
            $required = array_merge($required, [ 'supported_episode_ids', 'rejected_episode_ids', 'rejection_reason', 'supersedes_memory_id', ]);
        }
        $keys = array_keys($result);
        sort($required);
        sort($keys);
        if ($keys !== $required) {
            throw new InvalidArgumentException(
                $consolidation
                    ? 'Consolidation proposal must contain exactly the eight fields in its worker schema.'
                    : 'Worker proposal must contain exactly kind, content, confidence, and challenged_assumption.'
            );
        }
        foreach (['kind', 'challenged_assumption'] as $key) {
            if (!is_string($result[$key]) || trim($result[$key]) === '') {
                throw new InvalidArgumentException(sprintf('Worker proposal %s must be non-empty text.', $key));
            }
        }
        if (!is_string($result['content']) || (!$consolidation && trim($result['content']) === '')) {
            throw new InvalidArgumentException('Worker proposal content must be text.');
        }
        if ($this->containsFirstPersonModelIdentity($result['content'] . ' ' . $result['challenged_assumption'])) {
            throw new InvalidArgumentException('Worker proposal rejected: first-person model identity claim.');
        }
        if ($consolidation) {
            if (!is_string($result['rejection_reason']) || trim($result['rejection_reason']) === '') {
                throw new InvalidArgumentException('Consolidation rejection_reason must be non-empty text.');
            }
            foreach (['supported_episode_ids', 'rejected_episode_ids'] as $key) {
                if (!is_array($result[$key])) {
                    throw new InvalidArgumentException('Consolidation source partitions must be arrays.');
                }
                $seen = [];
                foreach ($result[$key] as $episodeId) {
                    if (!is_int($episodeId) || $episodeId < 1 || isset($seen[$episodeId])) {
                        throw new InvalidArgumentException('Consolidation source partitions require unique positive integer IDs.');
                    }
                    $seen[$episodeId] = true;
                }
            }
            $supersedes = $result['supersedes_memory_id'];
            if ($supersedes !== null && (!is_int($supersedes) || $supersedes < 1)) {
                throw new InvalidArgumentException('Consolidation supersedes_memory_id must be null or a positive integer.');
            }
        }
        $contentLimit = NarrativeSynthesis::isWorkType((string) $workType) ? 20000 : 8000;
        if (strlen($result['kind']) > 96 || strlen($result['content']) > $contentLimit) {
            throw new InvalidArgumentException('Worker proposal exceeds the bounded output size.');
        }
        if (!is_int($result['confidence']) && !is_float($result['confidence'])) {
            throw new InvalidArgumentException('Worker proposal confidence must be numeric.');
        }
        $this->requireUnitInterval((float) $result['confidence'], 'worker proposal confidence');
    }
}
