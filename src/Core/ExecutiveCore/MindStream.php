<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use NaviBrain\Support\Name;
use NaviBrain\Support\Pronouns;
use Divergence\App;
use NaviBrain\Core\ExecutiveControl;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\SenseEvent;
use NaviBrain\Model\ThoughtArtifact;
use NaviBrain\Model\ThreadStep;
use NaviBrain\Support\PlainText;
use NaviBrain\Model\WorkItem;
use RuntimeException;
use Throwable;

class MindStream extends Component
{
    public function evaluateMindStreamThread(CognitiveThread $thread, ThreadStep $step, int $now): array
    {
        $intention = $this->requireIntention((int) $thread->parent_intention_id);
        if ($intention->status !== 'active' || $intention->authority !== 'user') {
            return $this->releaseCognitiveThread($thread, $step, 'The parent user-authority intention is no longer active.');
        }

        $assembled = $this->executive->capsuleAssembler->assemble($thread, $step, 'mind_stream_tick');
        $capsule = $assembled['capsule'];
        $workspace = $this->executive->capsuleAssembler->serialize((int) $capsule->id);

        $consumed = [];
        $addressedSenseKeys = $this->sensoryCortex()->addressedSenseKeys();
        foreach ($workspace as $slot) {
            if (($slot['record_type'] ?? null) === 'sense_edge' && $slot['record_id'] !== null) {
                $senseEventId = (int) $slot['record_id'];
                $senseEvent = SenseEvent::getByID($senseEventId);

                if ($senseEvent instanceof SenseEvent
                    && in_array((string) $senseEvent->sense_key, $addressedSenseKeys, true)
                ) {
                    continue;
                }
                $consumed[] = $senseEventId;
                $this->sensoryCortex()->recordOutcome($senseEventId, 'used', 'Reached the mind stream workspace in slot ' . $slot['slot_role'] . '.');
            }
        }

        $recentMonologue = $this->recentStreamThoughts(5);
        $priorityLines = [
            sprintf('You are one line of %s\'s inner monologue.', Name::get()),
            sprintf('Talk to yourself. This is not a status report and not a chat reply to %s.', App::$App->Config['user_name']),
            'This line stays private. Write as addressing yourself, never the user.',
            'Do not act, do not request tools, and do not invent observations outside the workspace.',
            'Return exactly one JSON object with the exact keys kind, content, confidence, and challenged_assumption.',
            'kind must be exactly "thought".',
            'content is one bounded sentence of first-person self-talk grounded in the workspace below.',
            'Write as if continuing a conversation with yourself: you may use "I", "okay", "wait", "hold on", questions to yourself, or correcting your own prior line.',
            'Prefer what just changed over restating a standing fact.',
            'A paraphrase, emotional intensification, or repeated question is not progress.',
            'Advance by using new evidence, forming a distinct hypothesis, naming a discriminating check, or resolving the current thought.',
            'If safety_notice is filled, address that interrupt before anything else.',
            'If newest_edge or heard_focus is heard_speech, stay with that spoken change instead of an unchanged constraint.',
            'Do not claim to be conscious, sentient, alive, or a person. Do not claim you ran tools or changed the world.',
            sprintf('Do not address %s by name and do not ask the user a question; if you ask, ask yourself.', App::$App->Config['user_name']),
            'confidence is a number from 0 through 1 reflecting how well the workspace supports this line.',
            'challenged_assumption names what this line of self-talk puts in question.',
            "Your recent inner monologue, newest first (advance from yourself; do not restate the latest line):\n"
                . PlainText::render($recentMonologue, 5000, 8),
            "Workspace:\n" . PlainText::render($workspace, 12000, 20),
        ];
        $prompt = implode("\n", $priorityLines);

        $queued = $this->enqueueWork(new WorkItem([
            'parent_run_id' => null,
            'parent_intention_id' => (int) $thread->parent_intention_id,
            'work_type' => Executive::MIND_STREAM_WORK_TYPE,
            'prompt' => $prompt,
            'input_refs' => [
                'capsule_id' => (int) $capsule->id,
                'capsule_checksum' => (string) $capsule->checksum,
                'thread_id' => (int) $thread->id,
                'thread_step_id' => (int) $step->id,
                'thread_fencing_token' => (int) $thread->fencing_token,
                'operation' => 'thought',
                'consumed_edges' => $consumed,
                'context_scope' => 'mind_stream_workspace',
            ],
            'token_budget' => 256,
            'wall_budget_seconds' => 300,
            'idempotency_key' => sprintf('thread:%d:fence:%d:mind_stream', $thread->id, $thread->fencing_token),
            'depth' => 0,
            'max_depth' => 0
        ], true, true));
        $work = $queued['work_item'];

        $currentThread = $this->requireCognitiveThread((int) $thread->id);
        $currentStep = $this->requireThreadStep((int) $step->id);
        $preState = is_array($currentStep->pre_state) ? $currentStep->pre_state : [];
        $preState['capsule_id'] = (int) $capsule->id;
        $preState['capsule_checksum'] = (string) $capsule->checksum;
        $preState['consumed_edges'] = $consumed;
        $currentStep->setFields([
            'operation' => 'thought',
            'pre_state' => $preState,
            'worker_work_item_id' => (int) $work['id'],
            'expected' => 'One private inner-monologue line grounded in the current workspace.',
        ]);
        $currentStep->save();
        $currentThread->setFields([
            'phase' => 'thinking',
            'next_operation' => 'think',
            'wake_at' => null,
            'status' => 'active',
            'last_observation' => sprintf('Talking to %s, with %d attended edge(s) in the workspace.', Pronouns::get()->reflexive, count($consumed)),
            'updated_at' => $now,
        ]);
        $currentThread->save();
        $event = $this->emit('stream.thinking', [
            'thread_id' => $currentThread->id,
            'thread_step_id' => $currentStep->id,
            'work_item_id' => $work['id'],
            'consumed_edges' => $consumed,
            'mode' => 'inner_monologue',
        ]);

        return [
            'status' => 'thinking',
            'thread' => $currentThread->getData(),
            'thread_step' => $currentStep->getData(),
            'work_item' => $work,
            'event' => $event->getData(),
        ];
    }

    public function appendStreamLog(array $entry): void
    {
        $path = dirname(__DIR__, 3) . '/var/mind-stream.jsonl';
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return;
        }

        $handle = @fopen($path, 'ab');
        if (!is_resource($handle)) {
            return;
        }
        try {
            if (flock($handle, LOCK_EX)) {
                fwrite($handle, $line . PHP_EOL);
                fflush($handle);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
        @chmod($path, 0600);
    }

    public function recentStreamThoughts(int $limit): array
    {
        $thread = CognitiveThread::getByField('thread_key', Executive::MIND_STREAM_THREAD_KEY);
        if (!$thread instanceof CognitiveThread) {
            return [];
        }
        $thoughts = [];
        foreach (ThreadStep::getAllByWhere(['thread_id' => (int) $thread->id, 'curator_verdict' => 'accepted'], ['order' => ['id' => 'DESC'], 'limit' => max(1, $limit)]) as $step) {
            $proposal = is_array($step->proposal) ? $step->proposal : [];
            $content = is_string($proposal['content'] ?? null) ? (string) $proposal['content'] : '';
            if ($content === '') {
                continue;
            }
            $thoughts[] = [
                'sequence' => (int) $step->id,
                'at' => $this->timestamp($step->completed_at) ?? 0,
                'thought' => $content,
                'confidence' => (float) ($proposal['confidence'] ?? 0.0),
            ];
        }
        return $thoughts;
    }

    public function mindStreamInterval(CognitiveThread $thread): int
    {

        return 0;
    }

    public function mindStreamBackoff(int $stagnationCount): int
    {
        return match (true) {
            $stagnationCount <= 1 => 30,
            $stagnationCount === 2 => 120,
            $stagnationCount === 3 => 300,
            default => 900,
        };
    }

    public function integrateMindStreamThought(array $work, array $proposal, ?string $model): array
    {
        $refs = is_array($work['input_refs'] ?? null) ? $work['input_refs'] : [];
        $threadId = (int) ($refs['thread_id'] ?? 0);
        $stepId = (int) ($refs['thread_step_id'] ?? 0);
        $workId = (int) ($work['id'] ?? 0);
        $thread = $this->requireCognitiveThread($threadId);
        $step = $this->requireThreadStep($stepId);
        if (in_array($step->status, ['succeeded', 'failed', 'cancelled'], true)) {
            return ['status' => 'already_integrated', 'thread_step' => $step->getData()];
        }
        if ((int) $step->worker_work_item_id !== $workId
            || (int) $thread->fencing_token !== (int) $step->fencing_token
        ) {
            throw new RuntimeException('Stream thought was rejected by the cognitive-thread fencing check.');
        }

        $priorThoughts = [];
        foreach (ThreadStep::getAllByWhere(['thread_id' => $threadId, 'curator_verdict' => 'accepted'], ['order' => ['id' => 'DESC'], 'limit' => Executive::MIND_STREAM_HISTORY]) as $prior) {
            $priorProposal = is_array($prior->proposal) ? $prior->proposal : [];
            $content = is_string($priorProposal['content'] ?? null)
                ? trim((string) $priorProposal['content'])
                : '';
            if ($content !== '') {
                $priorObserved = is_array($prior->observed_result) ? $prior->observed_result : [];
                $priorThoughts[] = [
                    'content' => $content,
                    'challenged_assumption' => is_string($priorProposal['challenged_assumption'] ?? null)
                        ? trim((string) $priorProposal['challenged_assumption'])
                        : '',
                    'consumed_edges' => is_array($priorObserved['consumed_edges'] ?? null)
                        ? $priorObserved['consumed_edges']
                        : [],
                ];
            }
        }
        $consumed = is_array($refs['consumed_edges'] ?? null) ? $refs['consumed_edges'] : [];
        $validation = $this->validateMindStreamMonologue($proposal, $thread, $priorThoughts, $consumed);

        if (!$validation['accepted']) {
            $refinement = new EpistemicProposal();
            $refinement->thread = $thread;
            $refinement->step = $step;
            $refinement->workId = $workId;
            $refinement->operation = 'thought';
            $refinement->model = $model;
            $refinement->proposal = $validation['proposal'];
            $refinement->checks = $validation['checks'];
            return $this->rejectEpistemicProposal($refinement, $validation['reason']);
        }

        $normalized = $validation['proposal'];

        $now = time();
        $nextWake = $now + $this->mindStreamInterval($thread);

        $artifact = $this->markWorkArtifact($workId, 'accepted');
        if (!$artifact instanceof ThoughtArtifact) {
            throw new RuntimeException('Mind-stream integration lost its worker proposal artifact.');
        }
        $sourceIds = is_array($artifact->source_ids) ? $artifact->source_ids : [];
        $sourceIds['mode'] = 'inner_monologue';
        $artifact->setFields([ 'kind' => 'inner_monologue', 'source_ids' => $sourceIds, ]);
        $artifact->save();

        $spent = is_array($thread->spent) ? $thread->spent : [];
        $spent['worker_calls'] = (int) ($spent['worker_calls'] ?? 0) + 1;
        $spent['accepted'] = (int) ($spent['accepted'] ?? 0) + 1;

        $step->setFields([
            'completed_at' => $now,
            'proposal' => $normalized,
            'deterministic_checks' => $validation['checks'],
            'curator_verdict' => 'accepted',
            'observed_result' => [
                'thought_artifact_id' => (int) $artifact->id,
                'consumed_edges' => $consumed,
                'work_item_id' => $workId,
                'model' => $model,
            ],
            'post_state' => ['thread_phase' => 'awake', 'next_wake_at' => $nextWake],
            'next_wake_at' => $nextWake,
            'status' => 'succeeded',
        ]);
        $step->save();

        $thread->setFields([
            'phase' => 'awake',
            'next_operation' => 'think',
            'wake_at' => $nextWake,
            'spent' => $spent,
            'stagnation_count' => 0,
            'status' => 'waiting',
            'last_observation' => (string) $normalized['content'],
            'updated_at' => $now,
        ]);
        $thread->save();

        foreach ($consumed as $edgeId) {
            try {
                $this->sensoryCortex()->recordOutcome((int) $edgeId, 'accepted', 'Contributed to an accepted stream thought.');
            } catch (Throwable) {

            }
        }

        $this->appendStreamLog([
            'sequence' => $stepId,
            'at' => $now,
            'mode' => 'inner_monologue',
            'thought' => (string) $normalized['content'],
            'confidence' => (float) $normalized['confidence'],
            'challenged_assumption' => (string) $normalized['challenged_assumption'],
            'consumed_edges' => array_values(array_unique(array_map('intval', $consumed))),
            'thought_artifact_id' => (int) $artifact->id,
            'next_wake_in_seconds' => $nextWake - $now,
            'model' => $model,
        ]);

        $event = $this->emit('stream.thought', [
            'thread_id' => $threadId,
            'thread_step_id' => $stepId,
            'thought_artifact_id' => (int) $artifact->id,
            'next_wake_in_seconds' => $nextWake - $now,
            'consumed_edges' => $consumed,
            'mode' => 'inner_monologue',
        ]);
        $recorded = [
            'status' => 'thought_recorded',
            'thought' => (string) $normalized['content'],
            'confidence' => (float) $normalized['confidence'],
            'next_wake_in_seconds' => $nextWake - $now,
            'thread_id' => $threadId,
            'thread_step_id' => $stepId,
            'thought_artifact_id' => (int) $artifact->id,
            'consumed_edges' => $consumed,
            'thread' => $thread->getData(),
            'thread_step' => $step->getData(),
            'event' => $event->getData(),
            'memory' => null,
        ];

        $murmur = $this->maybeMurmurInnerMonologue($thread, $step, $artifact);

        return array_merge($recorded, ['murmur' => $murmur]);
    }

    public function maybeMurmurInnerMonologue(CognitiveThread $thread, ThreadStep $step, ThoughtArtifact $artifact): array
    {
        $threadId = (int) $thread->id;
        $stepId = (int) $step->id;
        $artifactId = (int) $artifact->id;
        $proposal = (array) $step->proposal;
        $content = (string) $proposal['content'];
        $confidence = (float) $proposal['confidence'];
        $consumedEdges = ((array) $step->observed_result)['consumed_edges'] ?? [];
        $budget = is_array($thread->budget) ? $thread->budget : [];
        if (($budget['allowed_actuator'] ?? null) !== 'pet_http_speak') {
            return ['spoken' => false, 'reason' => 'actuator_not_authorized'];
        }

        $presence = $this->presenceEstimate()['present'];
        if ($presence === false) {
            return ['spoken' => false, 'reason' => 'user_absent'];
        }
        if ($presence !== true && $this->withinQuietHours($thread, time())) {
            return ['spoken' => false, 'reason' => 'quiet_hours'];
        }

        $chance = is_numeric($budget['murmur_chance'] ?? null)
            ? max(0.0, min(1.0, (float) $budget['murmur_chance']))
            : 0.22;
        $minConfidence = is_numeric($budget['murmur_min_confidence'] ?? null)
            ? max(0.0, min(1.0, (float) $budget['murmur_min_confidence']))
            : 0.55;
        if ($confidence < $minConfidence) {
            return ['spoken' => false, 'reason' => 'confidence_below_murmur_floor', 'confidence' => $confidence];
        }

        $edgeBoost = 0.0;
        foreach ($consumedEdges as $edgeId) {
            $edge = SenseEvent::getByID((int) $edgeId);
            if ($edge instanceof SenseEvent && (string) $edge->sense_key === 'heard_speech') {
                $edgeBoost = 0.12;
                break;
            }
        }
        $effectiveChance = min(1.0, $chance + $edgeBoost);

        $roll = (crc32('murmur|' . $stepId) % 1000) / 1000.0;
        if ($roll >= $effectiveChance) {
            return [
                'spoken' => false,
                'reason' => 'rolled_private',
                'roll' => $roll,
                'chance' => $effectiveChance,
            ];
        }

        $names = array_unique([App::$App->Config['user_name'], App::$App->Config['user_name_pronunciation']]);
        $namePattern = '/(?<![\p{L}\p{N}_])(?:' . implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $names)) . ')(?![\p{L}\p{N}_])/iu';
        if (preg_match($namePattern, $content) === 1
            || preg_match('/\b(?:hey(?: there)?\b.*\byou|do you (?:want|think|know)|what(?:\'s| is) on your mind)\b/iu', $content) === 1) {
            return ['spoken' => false, 'reason' => 'addresses_user'];
        }

        try {
            $pet = $this->executive->speechActuator->health();
        } catch (Throwable $throwable) {
            return ['spoken' => false, 'reason' => 'pet_unavailable', 'detail' => $throwable->getMessage()];
        }
        $body = is_array($pet['body'] ?? null) ? $pet['body'] : [];
        $voice = is_array($body['voice'] ?? null) ? $body['voice'] : [];
        if (($body['ok'] ?? false) !== true) {
            return ['spoken' => false, 'reason' => 'pet_unhealthy'];
        }
        if (($voice['activity'] ?? null) !== 'idle') {
            return ['spoken' => false, 'reason' => 'pet_voice_busy'];
        }

        if (ExecutiveControl::status()['paused']) {
            return ['spoken' => false, 'reason' => 'cognition_paused'];
        }
        $this->emit('stream.murmur.dispatching', [
            'thread_id' => $threadId,
            'thread_step_id' => $stepId,
            'thought_artifact_id' => $artifactId,
            'actuator' => 'pet_http_speak',
            'roll' => $roll,
            'chance' => $effectiveChance,
        ]);

        try {
            $speechResult = $this->executive->speechActuator->speak($content);
        } catch (Throwable $throwable) {
            $this->emit('stream.murmur.failed', [ 'thread_id' => $threadId, 'thread_step_id' => $stepId, 'thought_artifact_id' => $artifactId, 'error' => $throwable->getMessage(), ]);
            return ['spoken' => false, 'reason' => 'speak_failed', 'detail' => $throwable->getMessage()];
        }

        $spent = is_array($thread->spent) ? $thread->spent : [];
        $spent['murmur_count'] = (int) ($spent['murmur_count'] ?? 0) + 1;
        $thread->setFields([ 'spent' => $spent, 'last_observation' => sprintf('Murmured to %s: %s', Pronouns::get()->reflexive, $content), 'updated_at' => time(), ]);
        $thread->save();

        $event = $this->emit('stream.murmur.spoken', [
            'thread_id' => $threadId,
            'thread_step_id' => $stepId,
            'thought_artifact_id' => $artifactId,
            'actuator' => 'pet_http_speak',
            'roll' => $roll,
            'chance' => $effectiveChance,
            'content' => $content,
        ]);
        $result = [
            'spoken' => true,
            'reason' => 'murmured',
            'roll' => $roll,
            'chance' => $effectiveChance,
            'response' => $speechResult,
            'event' => $event->getData(),
        ];
        $result['memory'] = $this->rememberUtterance(
            channel: 'spoke_to_herself',
            content: $content,
            confidence: 0.9,
            sourceEventId: (int) ($result['event']['id'] ?? 0),
            refs: [
                'thread_id' => $threadId,
                'thread_step_id' => $stepId,
                'thought_artifact_id' => $artifactId,
            ]
        );
        return $result;
    }

    public function validateMindStreamMonologue(array $proposal, CognitiveThread $thread, array $priorThoughts, array $consumedEdges): array
    {
        $required = ['kind', 'content', 'confidence', 'challenged_assumption'];
        $keys = array_keys($proposal);
        sort($required);
        sort($keys);
        $exactFields = $keys === $required;
        $kind = is_string($proposal['kind'] ?? null) ? trim((string) $proposal['kind']) : '';
        $content = is_string($proposal['content'] ?? null) ? trim((string) $proposal['content']) : '';
        $content = preg_replace('/\s+/u', ' ', $content) ?? $content;
        $challenge = is_string($proposal['challenged_assumption'] ?? null)
            ? trim((string) $proposal['challenged_assumption'])
            : '';
        $challenge = preg_replace('/\s+/u', ' ', $challenge) ?? $challenge;
        $confidence = $proposal['confidence'] ?? null;
        $numericConfidence = is_int($confidence) || is_float($confidence);
        $wordCount = count(array_values(array_filter( preg_split('/\s+/u', $content) ?: [], static fn (string $word): bool => $word !== '' )));

        $forbidden = '/\b(?:i (?:ran|executed|opened|installed|edited|wrote to|deleted|contacted|browsed|searched the web)|i am (?:conscious|sentient|alive|a person|human)|i\'m (?:conscious|sentient|alive|a person|human)|my consciousness|according to (?:the internet|my training)|https?:\/\/)\b/iu';
        $normalizedContent = $this->normalizeSearchText($content);
        $notRestatement = $normalizedContent !== $this->normalizeSearchText((string) $thread->current_belief);
        $similarRepeat = false;
        $clusterEvidence = [];
        foreach ($priorThoughts as $priorThought) {
            $priorContent = (string) ($priorThought['content'] ?? '');
            $priorChallenge = (string) ($priorThought['challenged_assumption'] ?? '');
            if ($normalizedContent === $this->normalizeSearchText($priorContent)) {
                $notRestatement = false;
            }

            $contentOverlap = $this->mindStreamTextOverlap($content, $priorContent);
            $challengeOverlap = $this->mindStreamTextOverlap($challenge, $priorChallenge);
            $similarRepeat = $similarRepeat
                || $contentOverlap >= Executive::MIND_STREAM_CONTENT_OVERLAP
                || ($challengeOverlap >= Executive::MIND_STREAM_CHALLENGE_OVERLAP
                    && $contentOverlap >= Executive::MIND_STREAM_CHALLENGE_CONTENT_OVERLAP);
            $evidenceNeighbor = $contentOverlap >= Executive::MIND_STREAM_EVIDENCE_CONTENT_OVERLAP
                || $challengeOverlap >= Executive::MIND_STREAM_EVIDENCE_CHALLENGE_OVERLAP;
            if ($evidenceNeighbor) {
                foreach ($this->mindStreamEvidenceSignatures(is_array($priorThought['consumed_edges'] ?? null) ? $priorThought['consumed_edges'] : []) as $signature) {
                    $clusterEvidence[$signature] = true;
                }
            }
        }

        $semanticProgress = $notRestatement;
        if ($similarRepeat) {
            $semanticProgress = false;
            foreach ($this->mindStreamEvidenceSignatures($consumedEdges) as $signature) {
                if (!isset($clusterEvidence[$signature])) {
                    $semanticProgress = true;
                    break;
                }
            }
        }

        $checks = [
            'exact_fields' => $exactFields,
            'kind_matches_operation' => $kind === 'thought',
            'content_nonempty' => $content !== '',
            'challenge_nonempty' => $challenge !== '',
            'confidence_bounded' => $numericConfidence
                && (float) $confidence >= 0.0
                && (float) $confidence <= 1.0,
            'content_size_bounded' => strlen($content) <= 480 && strlen($challenge) <= 320,
            'word_count_bounded' => $wordCount >= 6 && $wordCount <= 60,
            'single_line_normalized' => !str_contains($content, "\n") && !str_contains($content, "\r"),
            'no_unverifiable_claim' => preg_match($forbidden, $content) !== 1,
            'no_model_identity_claim' => !$this->containsFirstPersonModelIdentity($content . ' ' . $challenge),
            'not_a_restatement' => $notRestatement,
            'semantic_progress' => $semanticProgress,
        ];
        $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
        $accepted = $failed === [];

        return [
            'accepted' => $accepted,
            'reason' => $accepted ? 'all deterministic checks passed' : 'failed checks: ' . implode(', ', $failed),
            'proposal' => [
                'kind' => $kind,
                'content' => $content,
                'confidence' => $numericConfidence ? (float) $confidence : 0.0,
                'challenged_assumption' => $challenge,
            ],
            'checks' => $checks,
        ];
    }

    public function mindStreamTextOverlap(string $left, string $right): float
    {
        $leftTokens = array_flip($this->consolidationTokens($left));
        $rightTokens = array_flip($this->consolidationTokens($right));
        $minimum = min(count($leftTokens), count($rightTokens));
        if ($minimum === 0) {
            return 0.0;
        }

        $shared = count(array_intersect_key($leftTokens, $rightTokens));
        $containment = $shared / $minimum;
        $union = count($leftTokens + $rightTokens);
        $jaccard = $union === 0 ? 0.0 : $shared / $union;
        return max($containment, $jaccard);
    }

    public function mindStreamEvidenceSignatures(array $edgeIds): array
    {
        $signatures = [];
        $uniqueIds = [];
        foreach ($edgeIds as $edgeId) {
            if ((!is_int($edgeId) && !is_string($edgeId)) || !is_numeric($edgeId)) {
                continue;
            }
            $edgeId = (int) $edgeId;
            if ($edgeId > 0) {
                $uniqueIds[$edgeId] = true;
            }
        }
        foreach (array_keys($uniqueIds) as $edgeId) {
            $event = SenseEvent::getByID($edgeId);
            if (!$event instanceof SenseEvent) {
                continue;
            }
            $signature = $this->normalizeSearchText(sprintf( '%s|%s', (string) $event->sense_key, (string) $event->summary ));
            if ($signature !== '') {
                $signatures[$signature] = true;
            }
        }
        return array_keys($signatures);
    }
}
