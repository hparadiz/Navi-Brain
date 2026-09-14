<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use DateTimeInterface;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\DecisionCycle;
use NaviBrain\Model\Intention;

/**
 * Compile every open canonical intention and only intention-related evidence.
 *
 * This compiler is read-only and uncapped, or scoped to one requested ID.
 * It deliberately excludes needs, affect, sensory state, working memory, and conversational context so the
 * resulting narrative cannot mistake background pressure for an intention.
 */
final class IntentionCompiler
{
    private const PROTOCOL = 'intention-evidence-v1';

    /** @return array<string, mixed> */
    public function compile(?int $intentionId = null): array
    {
        $allIntentions = Intention::getAll(['order' => ['id' => 'ASC']]);
        $intentionsById = [];
        foreach ($allIntentions as $intention) {
            $intentionsById[(int) $intention->id] = $intention;
        }

        $open = [];
        $health = [
            'open_intention_count' => 0,
            'active_intention_count' => 0,
            'blocked_intention_count' => 0,
            'ready_intention_count' => 0,
            'dependency_blocked_count' => 0,
        ];

        foreach ($allIntentions as $intention) {
            if ($intentionId !== null && (int) $intention->id !== $intentionId) {
                continue;
            }
            $status = (string) $intention->status;
            if (!in_array($status, ['active', 'blocked'], true)) {
                continue;
            }

            $dependencies = $this->dependencyStates($intention->dependencies, $intentionsById);
            $dependencyBlocked = false;
            foreach ($dependencies as $dependency) {
                if (!in_array($dependency['status'], ['completed', 'released'], true)) {
                    $dependencyBlocked = true;
                    break;
                }
            }
            $parent = $this->parentState($intention->parent_id, $intentionsById);
            $actions = $this->actionEvidence((int) $intention->id);
            $decisions = $this->decisionEvidence((int) $intention->id);

            $open[] = [
                'id' => (int) $intention->id,
                'title' => (string) $intention->title,
                'authority' => (string) $intention->authority,
                'status' => $status,
                'reason' => (string) $intention->reason,
                'next_action' => (string) $intention->next_action,
                'success_condition' => (string) $intention->success_condition,
                'release_condition' => (string) $intention->release_condition,
                'parent' => $parent,
                'dependencies' => $dependencies,
                'dependency_ready' => !$dependencyBlocked,
                'action_evidence' => $actions,
                'decision_evidence' => $decisions,
                'created_at' => $this->timestamp($intention->created_at),
                'updated_at' => $this->timestamp($intention->updated_at),
            ];

            ++$health['open_intention_count'];
            ++$health[$status . '_intention_count'];
            if ($dependencyBlocked) {
                ++$health['dependency_blocked_count'];
            } else {
                ++$health['ready_intention_count'];
            }
        }

        return [
            'protocol' => self::PROTOCOL,
            'mode' => [
                'compiler_writes' => false,
                'result_count_limited' => false,
                'intention_evidence_only' => true,
                'intention_id' => $intentionId,
            ],
            'open_intentions' => $open,
            'evidence_health' => $health,
        ];
    }

    /**
     * @param mixed $dependencyIds
     * @param array<int, Intention> $intentionsById
     * @return list<array<string, mixed>>
     */
    private function dependencyStates(mixed $dependencyIds, array $intentionsById): array
    {
        if (!is_array($dependencyIds)) {
            return [];
        }

        $dependencies = [];
        foreach ($dependencyIds as $dependencyId) {
            $id = (int) $dependencyId;
            $dependency = $intentionsById[$id] ?? null;
            $dependencies[] = $dependency instanceof Intention
                ? [
                    'id' => $id,
                    'title' => (string) $dependency->title,
                    'status' => (string) $dependency->status,
                ]
                : ['id' => $id, 'title' => 'missing', 'status' => 'missing'];
        }
        return $dependencies;
    }

    /**
     * @param array<int, Intention> $intentionsById
     * @return array<string, mixed>|null
     */
    private function parentState(mixed $parentId, array $intentionsById): ?array
    {
        if ($parentId === null) {
            return null;
        }
        $id = (int) $parentId;
        $parent = $intentionsById[$id] ?? null;
        return $parent instanceof Intention
            ? ['id' => $id, 'title' => (string) $parent->title, 'status' => (string) $parent->status]
            : ['id' => $id, 'title' => 'missing', 'status' => 'missing'];
    }

    /** @return array<string, mixed> */
    private function actionEvidence(int $intentionId): array
    {
        $records = ActionTrace::getAllByWhere(
            ['intention_id' => $intentionId],
            ['order' => ['id' => 'ASC']]
        );
        $statuses = [];
        $matches = [];
        foreach ($records as $record) {
            $status = (string) $record->status;
            $match = (string) $record->match_status;
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            $matches[$match] = ($matches[$match] ?? 0) + 1;
        }
        $latest = $records === [] ? null : $records[array_key_last($records)];

        return [
            'count' => count($records),
            'status_counts' => $statuses,
            'match_counts' => $matches,
            'latest' => $latest instanceof ActionTrace ? [
                'id' => (int) $latest->id,
                'description' => (string) $latest->description,
                'expected' => (string) $latest->expected,
                'observed' => $latest->observed,
                'status' => (string) $latest->status,
                'match_status' => (string) $latest->match_status,
                'repair_note' => $latest->repair_note,
                'created_at' => $this->timestamp($latest->created_at),
                'completed_at' => $this->timestamp($latest->completed_at),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function decisionEvidence(int $intentionId): array
    {
        $records = DecisionCycle::getAllByWhere(
            ['intention_id' => $intentionId],
            ['order' => ['id' => 'ASC']]
        );
        $statuses = [];
        foreach ($records as $record) {
            $status = (string) $record->status;
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
        }
        $latest = $records === [] ? null : $records[array_key_last($records)];

        return [
            'count' => count($records),
            'status_counts' => $statuses,
            'latest' => $latest instanceof DecisionCycle ? [
                'id' => (int) $latest->id,
                'trigger' => (string) $latest->trigger,
                'state' => (string) $latest->state,
                'status' => (string) $latest->status,
                'error' => $latest->error,
                'created_at' => $this->timestamp($latest->created_at),
                'updated_at' => $this->timestamp($latest->updated_at),
                'completed_at' => $this->timestamp($latest->completed_at),
            ] : null,
        ];
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $timestamp = strtotime($value);
            return $timestamp === false ? null : $timestamp;
        }
        return null;
    }
}
