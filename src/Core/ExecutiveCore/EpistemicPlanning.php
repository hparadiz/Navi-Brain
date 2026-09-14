<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\ThreadStep;
use NaviBrain\Support\PlainText;
use RuntimeException;

class EpistemicPlanning extends Component
{
    public function evaluateEpistemicAdvanceThread(CognitiveThread $thread, ThreadStep $step, int $now): array
    {
        $intention = $this->requireIntention((int) $thread->parent_intention_id);
        if ($intention->status !== 'active' || $intention->authority !== 'user') {
            return $this->releaseCognitiveThread($thread, $step, 'The parent user-authority intention is no longer active.');
        }

        $budget = is_array($thread->budget) ? $thread->budget : [];
        $spent = is_array($thread->spent) ? $thread->spent : [];
        $acceptanceTarget = (int) ($budget['acceptance_target'] ?? 8);
        $accepted = (int) ($spent['accepted'] ?? 0);
        if ($accepted >= $acceptanceTarget) {
            return $this->completeEpistemicThread($thread, $step, sprintf( 'Curated at least %d accepted refinements; the epistemic advance reached its success condition.', $acceptanceTarget ));
        }

        $stagnationCap = (int) ($budget['stagnation_cap'] ?? 6);
        if ((int) $thread->stagnation_count >= $stagnationCap) {
            return $this->blockEpistemicThread(
                $thread,
                $step,
                sprintf('Reached the stagnation cap of %d unproductive wakes without an accepted refinement; stopping instead of continuing to spend worker calls.', $stagnationCap)
            );
        }

        $cycle = is_array($budget['operation_cycle'] ?? null) ? array_values(array_filter( $budget['operation_cycle'], static fn (mixed $entry): bool => is_string($entry) && $entry !== '' )) : [];
        $currentOperation = (string) $step->operation;
        $operation = in_array($currentOperation, $cycle, true) ? $currentOperation : ($cycle[0] ?? 'plan');

        $assembled = $this->executive->capsuleAssembler->assemble($thread, $step, 'epistemic_' . $operation);
        $capsule = $assembled['capsule'];
        $evidence = $this->executive->capsuleAssembler->serialize((int) $capsule->id);
        $carryoverDepth = $this->executive->capsuleAssembler->maxCarryoverDepth((int) $capsule->id);
        $recent = $this->recentEpistemicAcceptances((int) $thread->id, 4);

        $carryoverCap = (int) ($budget['carryover_depth_cap'] ?? 8);
        if ($carryoverDepth >= $carryoverCap) {
            return $this->blockEpistemicThread(
                $thread,
                $step,
                sprintf('A capsule slot has carried the same occupant across %d wakes without the workspace changing; stopping rather than re-reading identical evidence.', $carryoverDepth)
            );
        }

        $prompt = implode("\n", [
            'You are advancing one bounded step of Navi\'s user-authorized epistemic self-advance thread.',
            'You have no tools, no actuator, and no access beyond the supplied capsule below.',
            'The thread is self-perpetuating: it wakes without a fresh prompt and must leave evidence for the NEXT wake.',
            'Return exactly one JSON object with the exact keys kind, content, confidence, and challenged_assumption.',
            'kind must be exactly "' . $operation . '" and nothing else.',
            'The operation "' . $operation . '" means: ' . $this->epistemicOperationBrief($operation),
            'content must be one bounded sentence, grounded in the supplied evidence, never asserting raw memory, feelings, desires, consciousness, or personhood.',
            'content must say something the current belief and the recent accepted refinements below do not already say.',
            'confidence must be a number from 0 through 1 that honestly reflects how strongly the supplied evidence supports the claim.',
            'challenged_assumption must name the assumption in the current belief that this step questions.',
            'Do not take any action, request any tool, or claim you observed anything outside the capsule.',
            'Current belief: ' . (string) $thread->current_belief,
            'Desired outcome: ' . (string) $thread->desired_outcome,
            'Expected postcondition of this operation: ' . (string) $thread->expected_postcondition,
            "Bounded workspace (every slot you may reason from):\n"
                . PlainText::render($evidence, 12000, 20),
            "Recent accepted refinements:\n" . PlainText::render($recent, 5000, 8),
        ]);
        $queued = $this->enqueueWork(
            new \NaviBrain\Model\WorkItem([
                'parent_run_id' => null,
                'parent_intention_id' => (int) $thread->parent_intention_id,
                'work_type' => Executive::EPISTEMIC_ADVANCE_WORK_TYPE,
                'prompt' => $prompt,
                'input_refs' => [
                'capsule_id' => (int) $capsule->id,
                'capsule_checksum' => (string) $capsule->checksum,
                'thread_id' => (int) $thread->id,
                'thread_step_id' => (int) $step->id,
                'thread_fencing_token' => (int) $thread->fencing_token,
                'operation' => $operation,
                'context_scope' => 'bounded_epistemic_capsule',
            ],
                'token_budget' => 384,
                'wall_budget_seconds' => 300,
                'idempotency_key' => sprintf('thread:%d:fence:%d:epistemic_advance', $thread->id, $thread->fencing_token),
                'depth' => 0,
                'max_depth' => 0
            ], true, true)
        );
        $work = $queued['work_item'];

        $currentThread = $this->requireCognitiveThread((int) $thread->id);
        $currentStep = $this->requireThreadStep((int) $step->id);
        if ($currentStep->status !== 'running'
            || (int) $currentThread->fencing_token !== (int) $currentStep->fencing_token
        ) {
            throw new RuntimeException('Epistemic-advance worker queue lost its thread fence.');
        }
        $preState = is_array($currentStep->pre_state) ? $currentStep->pre_state : [];
        $preState['operation'] = $operation;
        $preState['uncertainty'] = (float) $currentThread->uncertainty;
        $preState['capsule_id'] = (int) $capsule->id;
        $preState['capsule_checksum'] = (string) $capsule->checksum;
        $preState['capsule_carryover_depth'] = $carryoverDepth;
        $currentStep->setFields([
            'operation' => $operation,
            'pre_state' => $preState,
            'worker_work_item_id' => (int) $work['id'],
            'expected' => 'A deny-all worker returns a schema-valid refinement proposal with a kind, claim, confidence, and challenged assumption.',
        ]);
        $currentStep->save();
        $currentThread->setFields([
            'phase' => 'awaiting_worker',

            'next_operation' => $operation,
            'expected_postcondition' => 'A deny-all worker returns one schema-valid refinement whose acceptance or rejection changes durable thread state.',
            'wake_at' => null,
            'status' => 'active',
            'last_observation' => 'A bounded, deny-all epistemic refinement capsule was queued.',
            'updated_at' => $now,
        ]);
        $currentThread->save();
        $event = $this->emit('thread.worker.queued', [
            'thread_id' => $currentThread->id,
            'thread_step_id' => $currentStep->id,
            'work_item_id' => $work['id'],
            'work_type' => Executive::EPISTEMIC_ADVANCE_WORK_TYPE,
            'context_scope' => 'bounded_epistemic_capsule',
            'model_has_actuator_access' => false,
            'operation' => $operation,
        ]);

        return [
            'status' => 'worker_queued',
            'thread' => $currentThread->getData(),
            'thread_step' => $currentStep->getData(),
            'work_item' => $work,
            'event' => $event->getData(),
        ];
    }

    public function epistemicOperationBrief(string $operation): string
    {
        return match ($operation) {
            'critique' => 'name one flaw, gap, or counter-example in the current belief that the supplied evidence exposes.',
            'verify' => 'state one bounded, locally checkable observation that would confirm or refute the current belief.',
            'reflect' => 'state the refined belief that the accepted evidence now supports, which will replace the current belief.',
            default => 'state the single most useful next inquiry step for reducing the uncertainty in the current belief.',
        };
    }

    public function recentEpistemicAcceptances(int $threadId, int $limit): array
    {
        $accepted = [];
        foreach (ThreadStep::getAllByWhere(['thread_id' => $threadId, 'curator_verdict' => 'accepted'], ['order' => ['completed_at' => 'DESC'], 'limit' => max(1, $limit)]) as $step) {
            $proposal = is_array($step->proposal) ? $step->proposal : [];
            $claim = is_string($proposal['content'] ?? null) ? trim((string) $proposal['content']) : '';
            if ($claim === '') {
                continue;
            }
            $accepted[] = [
                'operation' => (string) $step->operation,
                'claim' => $claim,
                'confidence' => (float) ($proposal['confidence'] ?? 0.0),
                'accepted_at' => $this->timestamp($step->completed_at) ?? 0,
            ];
        }
        return $accepted;
    }

    public function integrateEpistemicAdvanceResult(array $work, array $proposal, ?string $model): array
    {
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

        $refinement = new EpistemicProposal();
        $refinement->thread = $thread;
        $refinement->step = $step;
        $refinement->workId = $workId;
        $refinement->operation = (string) $step->operation;
        $refinement->model = $model;

        $preState = is_array($step->pre_state) ? $step->pre_state : [];
        $capsuleId = (int) ($preState['capsule_id'] ?? ($refs['capsule_id'] ?? 0));
        $expectedChecksum = (string) ($preState['capsule_checksum'] ?? ($refs['capsule_checksum'] ?? ''));
        if ($capsuleId > 0 && $expectedChecksum !== '') {
            $actualChecksum = $this->executive->capsuleAssembler->checksum($this->executive->capsuleAssembler->serialize($capsuleId));
            if (!hash_equals($expectedChecksum, $actualChecksum)) {
                $refinement->checks = ['capsule_unchanged' => false];
                return $this->rejectEpistemicProposal($refinement, 'The bounded workspace changed after the worker read it, so no verdict can be reached on it.');
            }
        }

        $operation = (string) $step->operation;
        $priorClaims = array_map(static fn (array $entry): string => $entry['claim'], $this->recentEpistemicAcceptances($threadId, 8));
        $validation = $this->validateEpistemicProposal($proposal, $thread, $operation, $priorClaims);
        $refinement->proposal = $validation['proposal'];
        $refinement->checks = $validation['checks'];
        if (!$validation['accepted']) {
            return $this->rejectEpistemicProposal($refinement, $validation['reason']);
        }

        return $this->acceptEpistemicRefinement($refinement);
    }

    public function validateEpistemicProposal(array $proposal, CognitiveThread $thread, string $operation, array $priorClaims): array
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

        $forbidden = '/\b(?:i (?:ran|executed|opened|installed|edited|wrote to|deleted|contacted|browsed|searched the web)|i am (?:conscious|sentient|alive|a person|human)|my (?:feelings|consciousness|desires)|i (?:feel|want|desire|remember)|according to (?:the internet|my training))\b/iu';
        $novel = true;
        foreach (array_merge($priorClaims, [(string) $thread->current_belief]) as $priorClaim) {
            if ($this->normalizeSearchText($priorClaim) === $this->normalizeSearchText($content)) {
                $novel = false;
                break;
            }
        }

        $checks = [
            'exact_fields' => $exactFields,
            'kind_matches_operation' => $kind === $operation,
            'content_nonempty' => $content !== '',
            'challenge_nonempty' => $challenge !== '',
            'confidence_bounded' => $numericConfidence
                && (float) $confidence >= 0.0
                && (float) $confidence <= 1.0,
            'content_size_bounded' => strlen($content) <= 480 && strlen($challenge) <= 320,
            'word_count_bounded' => $wordCount >= 6 && $wordCount <= 60,
            'single_line_normalized' => !str_contains($content, "\n") && !str_contains($content, "\r"),
            'no_unverifiable_claim' => preg_match($forbidden, $content) !== 1,
            'not_a_restatement' => $novel,
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
}
