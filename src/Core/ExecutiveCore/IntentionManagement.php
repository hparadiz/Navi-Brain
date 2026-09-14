<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use Divergence\IO\Database\SQLite;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\Appraisal;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\ThoughtArtifact;
use NaviBrain\Model\WorkingMemorySlot;
use RuntimeException;

class IntentionManagement extends Component
{
    public function createIntention(Intention $intention): array
    {
        $this->requireText($intention->title, 'title');
        $this->requireText($intention->reason, 'reason');
        $this->requireText($intention->next_action, 'next action');
        $this->requireText($intention->success_condition, 'success condition');
        $this->requireText($intention->release_condition, 'release condition');
        $this->requireChoice($intention->authority, ['user', 'developer', 'system', 'agent'], 'authority');

        foreach ($intention->dependencies as $dependencyId) {
            $this->requireIntention($dependencyId);
        }
        if ($intention->parent_id !== null) {
            $this->requireIntention($intention->parent_id);
        }

        $intention->updated_at = time();
        $intention->save();

        $event = $this->emit('intention.created', [ 'intention_id' => $intention->id, 'title' => $intention->title, 'authority' => $intention->authority, ]);

        return ['intention' => $intention->getData(), 'event' => $event->getData()];
    }

    public function advanceIntention(int $id, string $nextAction, ?string $note = null): array
    {
        $this->requireText($nextAction, 'next action');
        $intention = $this->requireIntention($id);
        if (in_array($intention->status, ['completed', 'released'], true)) {
            throw new RuntimeException('A completed or released intention cannot be advanced.');
        }

        $previous = $intention->next_action;
        $intention->setFields([ 'next_action' => $nextAction, 'status' => 'active', 'updated_at' => time(), ]);
        $intention->save();

        $event = $this->emit('intention.advanced', [ 'intention_id' => $intention->id, 'previous_next_action' => $previous, 'next_action' => $nextAction, 'note' => $note, ]);

        return ['intention' => $intention->getData(), 'event' => $event->getData()];
    }

    public function closeIntention(int $id, string $status, string $note): array
    {
        $this->requireChoice($status, ['blocked', 'completed', 'released'], 'status');
        $this->requireText($note, 'closure note');
        $intention = $this->requireIntention($id);
        if (in_array($intention->status, ['completed', 'released'], true)) {
            throw new RuntimeException('The intention is already terminal.');
        }

        $intention->setFields([ 'status' => $status, 'closure_note' => $note, 'updated_at' => time(), ]);
        $intention->save();

        $event = $this->emit('intention.' . $status, [ 'intention_id' => $intention->id, 'note' => $note, ]);

        return ['intention' => $intention->getData(), 'event' => $event->getData()];
    }

    public function listIntentions(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice($status, ['active', 'blocked', 'completed', 'released'], 'status');
            $records = Intention::getAllByWhere(['status' => $status], ['order' => ['updated_at' => 'DESC']]);
        } else {
            $records = Intention::getAll(['order' => ['updated_at' => 'DESC']]);
        }

        return $this->records($records);
    }

    public function intentionsByConsideration(string $status): array
    {
        $records = Intention::getAllByQuery(
            "SELECT i.*,
                (SELECT COUNT(*) FROM work_items WHERE parent_intention_id = i.id AND status = 'completed')
                    + (SELECT COUNT(*) FROM appraisals WHERE intention_id = i.id) AS consideration_count,
                MAX(
                    COALESCE((SELECT MAX(COALESCE(completed_at, updated_at, created_at))
                        FROM work_items WHERE parent_intention_id = i.id AND status = 'completed'), ''),
                    COALESCE((SELECT MAX(created_at) FROM appraisals WHERE intention_id = i.id), '')
                ) AS last_considered_at
             FROM intentions i WHERE i.status = %s ORDER BY i.updated_at DESC",
            [SQLite::quote($status)]
        );
        $intentions = [];
        foreach ($records as $record) {
            $intention = $record->getData();
            $updatedAt = $this->timestamp($record->updated_at) ?? 0;
            $lastAt = $this->timestamp($record->last_considered_at) ?? 0;
            $intention['consideration_count'] = (int) $record->consideration_count;
            $intention['last_considered_at'] = max($updatedAt, $lastAt);
            $intentions[] = $intention;
        }

        usort($intentions, static function (array $left, array $right): int {
            return ($right['last_considered_at'] <=> $left['last_considered_at'])
                ?: ((int) $right['id'] <=> (int) $left['id']);
        });
        $recencyRanks = array_flip(array_map( static fn (array $intention): int => (int) $intention['id'], $intentions ));

        $frequent = array_values(array_filter( $intentions, static fn (array $intention): bool => $intention['consideration_count'] > 0 ));
        usort($frequent, static function (array $left, array $right): int {
            return ($right['consideration_count'] <=> $left['consideration_count'])
                ?: ($right['last_considered_at'] <=> $left['last_considered_at'])
                ?: ((int) $right['id'] <=> (int) $left['id']);
        });
        $frequencyRanks = array_flip(array_map( static fn (array $intention): int => (int) $intention['id'], $frequent ));

        usort($intentions, static function (array $left, array $right) use ($recencyRanks, $frequencyRanks): int
        {
            $leftId = (int) $left['id'];
            $rightId = (int) $right['id'];
            $leftRank = min($recencyRanks[$leftId], $frequencyRanks[$leftId] ?? PHP_INT_MAX);
            $rightRank = min($recencyRanks[$rightId], $frequencyRanks[$rightId] ?? PHP_INT_MAX);
            return ($leftRank <=> $rightRank)
                ?: ($right['last_considered_at'] <=> $left['last_considered_at'])
                ?: ($right['consideration_count'] <=> $left['consideration_count'])
                ?: ($rightId <=> $leftId);
        });
        return $intentions;
    }

    public function status(): array
    {
        $this->executive->workingMemory->expireStale();
        return [
            'active_intentions' => $this->intentionsByConsideration('active'),
            'blocked_intentions' => $this->intentionsByConsideration('blocked'),
            'pending_actions' => $this->records(ActionTrace::getAllByWhere( ['status' => 'pending'], ['order' => ['created_at' => 'DESC']] )),
            'recent_discrepancies' => $this->records(ActionTrace::getAllByWhere( ['match_status' => 'mismatched'], ['order' => ['completed_at' => 'DESC'], 'limit' => 10] )),
            'working_memory' => $this->records(Memory::inspectAllByWhere( ['tier' => 'working', 'status' => 'active'], ['order' => ['updated_at' => 'DESC'], 'limit' => 25] )),
            'working_memory_slots' => $this->records(\NaviBrain\Model\WorkingMemorySlot::getAllByWhere( ['status' => 'active'], ['order' => ['updated_at' => 'DESC'], 'limit' => 50] )),
            'semantic_memory' => $this->records(Memory::inspectAllByWhere( ['tier' => 'semantic', 'status' => 'active'], ['order' => ['updated_at' => 'DESC'], 'limit' => 25] )),
            'procedural_memory' => $this->records(Memory::inspectAllByWhere( ['tier' => 'procedural', 'status' => 'active'], ['order' => ['updated_at' => 'DESC'], 'limit' => 25] )),
            'procedures' => $this->executive->proceduralMemory->list('active'),
            'installed_decision_procedure' => $this->executive->proceduralMemory->decisionProcedure(),
            'decision_cycles' => $this->executive->decisionStateMachine->list(null, 10),
            'decision_comparison' => $this->executive->decisionStateMachine->compare(200),
            'other_model' => $this->executive->otherModel->status(),
            'needs' => $this->listNeeds(),
            'heartbeat' => $this->heartbeatStatus(),
            'proposed_thoughts' => $this->records(ThoughtArtifact::getAllByWhere( ['status' => 'proposed'], ['order' => ['created_at' => 'DESC'], 'limit' => 25] )),
            'self_model' => $this->listSelfModelFacts(),
            'top_appraisals' => $this->records(Appraisal::getAll([ 'order' => ['attention_score' => 'DESC'], 'limit' => 10, ])),
        ];
    }
}
