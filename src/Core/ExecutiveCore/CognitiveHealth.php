<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\Event;
use NaviBrain\Model\Memory;
use NaviBrain\Model\MemorySource;
use NaviBrain\Model\ModelEndpoint;
use NaviBrain\Model\SelfModelFact;
use NaviBrain\Model\ThoughtArtifact;

class CognitiveHealth extends Component
{
    public function cognitiveFindings(int $now): array
    {
        $findings = [];

        foreach (ActionTrace::getAllByWhere(['status' => 'pending']) as $action) {
            $createdAt = $this->timestamp($action->created_at);
            if ($createdAt !== null && $now - $createdAt >= Executive::STALE_ACTION_SECONDS) {
                $findings[] = [
                    'kind' => 'stale_pending_action',
                    'severity' => 'review',
                    'source' => ['action_id' => $action->id, 'event_id' => $action->start_event_id],
                    'description' => sprintf('Action %d has remained pending for at least %d seconds; determine its real outcome before continuing it.', $action->id, Executive::STALE_ACTION_SECONDS),
                    'automatic_repair' => false,
                ];
            }
        }

        foreach (Memory::inspectAllByWhere(['status' => 'active']) as $memory) {
            if ($memory->tier === 'semantic'
                && $memory->source_event_id === null
                && $memory->source_memory_id === null
            ) {
                $findings[] = [
                    'kind' => 'semantic_without_provenance',
                    'severity' => 'integrity',
                    'source' => ['memory_id' => $memory->id],
                    'description' => sprintf('Semantic memory %d has no source event or memory.', $memory->id),
                    'automatic_repair' => false,
                ];
            }
            $source = MemorySource::reference($memory->getData());
            if ($memory->source_event_id !== null && $source === null) {
                $findings[] = [
                    'kind' => 'untyped_memory_event',
                    'severity' => 'uncertainty',
                    'source' => ['memory_id' => $memory->id, 'source_event_id' => $memory->source_event_id],
                    'description' => sprintf('Memory %d has legacy source %d with unknown namespace.', $memory->id, $memory->source_event_id),
                    'automatic_repair' => false,
                ];
            } elseif ($source !== null && MemorySource::inspect($memory->getData()) === null) {
                $findings[] = [
                    'kind' => 'dangling_memory_event',
                    'severity' => 'integrity',
                    'source' => ['memory_id' => $memory->id, 'source_event_kind' => $source['kind'], 'source_event_id' => $source['id']],
                    'description' => sprintf('Memory %d references missing %s %d.', $memory->id, $source['kind'], $source['id']),
                    'automatic_repair' => false,
                ];
            }
            if ($memory->source_memory_id !== null
                && !Memory::inspectByID((int) $memory->source_memory_id) instanceof Memory
            ) {
                $findings[] = [
                    'kind' => 'dangling_memory_source',
                    'severity' => 'integrity',
                    'source' => ['memory_id' => $memory->id, 'source_memory_id' => $memory->source_memory_id],
                    'description' => sprintf('Memory %d references missing source memory %d.', $memory->id, $memory->source_memory_id),
                    'automatic_repair' => false,
                ];
            }
        }

        foreach (SelfModelFact::getAll() as $fact) {
            if (!Event::getByID((int) $fact->evidence_event_id) instanceof Event) {
                $findings[] = [
                    'kind' => 'dangling_self_model_evidence',
                    'severity' => 'integrity',
                    'source' => ['self_model_fact_id' => $fact->id, 'event_id' => $fact->evidence_event_id],
                    'description' => sprintf('Self-model fact %d references missing evidence event %d.', $fact->id, $fact->evidence_event_id),
                    'automatic_repair' => false,
                ];
            }
        }

        return $findings;
    }

    public function createRepairArtifacts(array $findings, ?int $runId): array
    {
        $candidates = [];
        foreach ($findings as $finding) {
            $candidates[] = [
                'kind' => 'diagnostic_repair',
                'provenance' => 'inferred',
                'source_ids' => $finding['source'],
                'content' => sprintf('Repair proposal, not an observation: %s Preserve the source record until a bounded check determines the correct repair.', $finding['description']),
                'confidence' => 0.8,
            ];
        }

        foreach (ActionTrace::getAllByWhere(['match_status' => 'mismatched'], ['order' => ['completed_at' => 'DESC'], 'limit' => 10]) as $action) {
            $candidates[] = [
                'kind' => 'prediction_counterfactual',
                'provenance' => 'counterfactual',
                'source_ids' => [
                    'action_id' => $action->id,
                    'completion_event_id' => $action->completion_event_id,
                ],
                'content' => sprintf(
                    'Synthetic counterfactual: before repeating action %d, apply this candidate repair—%s—then test whether it reduces the recorded expectation mismatch. Do not assume it works merely because it is plausible.',
                    $action->id,
                    $action->repair_note
                ),
                'confidence' => 0.5,
            ];
        }

        $artifacts = [];
        foreach ($candidates as $candidate) {
            if ($this->containsFirstPersonModelIdentity((string) $candidate['content'])) {
                continue;
            }
            $hash = hash('sha256', json_encode( [$candidate['kind'], $candidate['source_ids'], $candidate['content']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ));
            $artifact = ThoughtArtifact::getByField('content_hash', $hash);
            if (!$artifact instanceof ThoughtArtifact) {

                $artifact = new ThoughtArtifact([
                    'run_id' => $runId,
                    'kind' => $candidate['kind'],
                    'content' => $candidate['content'],
                    'confidence' => $candidate['confidence'],
                    'provenance' => $candidate['provenance'],
                    'source_ids' => $candidate['source_ids'],
                    'status' => 'proposed',
                    'content_hash' => $hash,
                ], true, true);
                $artifact->save();
                $this->emit('thought.proposed', [
                    'artifact_id' => $artifact->id,
                    'run_id' => $runId,
                    'kind' => $artifact->kind,
                    'provenance' => $artifact->provenance,
                    'synthetic' => true,
                    'source_ids' => $candidate['source_ids'],
                    'external_action_authorized' => false,
                ]);
            }
            $artifacts[] = $artifact->getData();
        }

        return $artifacts;
    }

    public function containsFirstPersonModelIdentity(string $text): bool
    {
        $matchCount = preg_match_all('/\b(?:i\s+am|i[\'’]m)\s+(?:an?\s+|the\s+)?([^.!?;\r\n]+)/iu', $text, $claims);
        if ($matchCount === false || $matchCount === 0) {
            return false;
        }

        $aliases = [
            'codex', 'chatgpt', 'gpt', 'claude', 'gemini', 'gemma', 'llama',
            'mistral', 'mixtral', 'qwen', 'deepseek', 'grok', 'phi', 'kimi',
        ];
        $qualifiers = [
            'base', 'chat', 'experimental', 'free', 'instruct', 'latest',
            'mini', 'preview', 'reasoning', 'thinking',
        ];
        foreach (ModelEndpoint::getAll() as $endpoint) {
            $modelId = (string) $endpoint->model_id;
            $name = str_contains($modelId, '/')
                ? (string) substr($modelId, (int) strrpos($modelId, '/') + 1)
                : $modelId;
            $normalized = trim((string) preg_replace( '/[^a-z0-9]+/', ' ', strtolower($name) ));
            if ($normalized === '') {
                continue;
            }
            $aliases[] = $normalized;
            $family = [];
            foreach (explode(' ', $normalized) as $token) {
                if (preg_match('/\d/', $token) === 1 || in_array($token, $qualifiers, true)) {
                    if ($family === [] && preg_match('/^[a-z]+\d+$/', $token) === 1) {
                        $family[] = $token;
                    }
                    break;
                }
                $family[] = $token;
            }
            if ($family !== []) {
                $aliases[] = implode(' ', $family);
            }
        }
        $aliases = array_values(array_unique($aliases));

        foreach ($claims[1] ?? [] as $claim) {
            $claim = trim((string) preg_replace( '/[^a-z0-9]+/', ' ', strtolower((string) $claim) ));
            if (preg_match('/^(?:(?:ai|artificial intelligence|foundation|language|large language|machine learning) model|model)(?:\s|$)/', $claim) === 1) {
                return true;
            }
            foreach ($aliases as $alias) {
                if ($claim === $alias || str_starts_with($claim, $alias . ' ')) {
                    return true;
                }
            }
        }
        return false;
    }
}
