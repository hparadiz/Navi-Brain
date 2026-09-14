<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use InvalidArgumentException;
use NaviBrain\Model\HeldValue;
use NaviBrain\Model\Intention;
use NaviBrain\Model\SelfModelFact;
use NaviBrain\Model\ValueAppraisal;
use NaviBrain\Model\ValueRevision;
use RuntimeException;

class Values extends Component
{
    public function setSelfModelFact(string $key, string $value, float $confidence, string $evidence): array
    {
        $this->requireText($key, 'key');
        $this->requireText($value, 'value');
        $this->requireText($evidence, 'evidence');
        $this->requireUnitInterval($confidence, 'confidence');

        $event = $this->emit('self_model.observed', [ 'fact_key' => $key, 'fact_value' => $value, 'confidence' => $confidence, 'evidence' => $evidence, ]);

        $fact = SelfModelFact::getByField('fact_key', $key);
        if ($fact === null) {

            $fact = new SelfModelFact([ 'fact_key' => $key, 'fact_value' => $value, 'confidence' => $confidence, 'evidence_event_id' => $event->id, 'updated_at' => time(), ], true, true);
            $fact->save();
        } else {
            $fact->setFields([ 'fact_value' => $value, 'confidence' => $confidence, 'evidence_event_id' => $event->id, 'updated_at' => time(), ]);
            $fact->save();
        }

        return ['fact' => $fact->getData(), 'event' => $event->getData()];
    }

    public function listSelfModelFacts(): array
    {
        return $this->records(array_values(array_filter(SelfModelFact::getAll(['order' => ['fact_key' => 'ASC']]), static fn (SelfModelFact $fact): bool => SelfModelFact::isModelContextVisible( (string) $fact->fact_key ))));
    }

    public function listValues(): array
    {
        $now = time();
        return array_values(array_map( fn (HeldValue $value): array => $this->valueState($value, $now), HeldValue::getAll(['order' => ['value_key' => 'ASC']]) ));
    }

    public function setValue(HeldValue $proposal): array
    {
        $this->requireText($proposal->value_key, 'value key');
        $this->requireText($proposal->statement, 'value statement');
        $this->requireText($proposal->rationale, 'value rationale');
        $this->requireUnitInterval($proposal->weight, 'value weight');
        $this->requireChoice($proposal->status, ['active', 'retired'], 'value status');
        $this->requireChoice($proposal->authority, ['user', 'developer', 'system', 'agent'], 'authority');
        if ($proposal->review_interval_hours < 1) {
            throw new InvalidArgumentException('value review interval must be at least one hour.');
        }

        $value = HeldValue::getByField('value_key', $proposal->value_key);
        if ($value instanceof HeldValue) {

            if ((string) $value->statement !== $proposal->statement
                || abs((float) $value->weight - $proposal->weight) > 0.0005) {
                throw new RuntimeException(sprintf( 'Value %s already exists. Changing its statement or weight requires value:revise with a basis.', $proposal->value_key ));
            }
            $value->setFields([ 'rationale' => $proposal->rationale, 'status' => $proposal->status, 'review_interval_hours' => $proposal->review_interval_hours, 'updated_at' => time(), ]);
            $value->save();
        } else {

            $value = $proposal;
            $value->setFields(['last_reviewed_at' => null, 'updated_at' => time()]);
            $value->save();
        }

        $event = $this->emit('value.declared', [ 'value_id' => $value->id, 'value_key' => $proposal->value_key, 'authority' => $proposal->authority, 'weight' => $proposal->weight, ]);

        return ['value' => $value->getData(), 'event' => $event->getData()];
    }

    public function appraiseValue(string $key, ValueAppraisal $appraisal): array
    {
        $this->requireText($key, 'value key');
        $this->requireText($appraisal->evidence, 'appraisal evidence');
        $this->requireUnitInterval($appraisal->alignment, 'alignment');
        $this->requireChoice($appraisal->source, ['user', 'self', 'outcome'], 'appraisal source');
        if ($appraisal->intention_id !== null) {
            $this->requireIntention($appraisal->intention_id);
        }

        $value = HeldValue::getByField('value_key', $key);
        if (!$value instanceof HeldValue) {
            throw new RuntimeException(sprintf('Value %s does not exist.', $key));
        }
        if ((string) $value->status !== 'active') {
            throw new RuntimeException(sprintf('Value %s is retired.', $key));
        }

        $appraisal->setFields(['value_id' => $value->id, 'generated_intention_id' => null]);
        $appraisal->save();

        $this->emit('value.appraised', [
            'value_id' => $value->id,
            'value_key' => $key,
            'alignment' => $appraisal->alignment,
            'source' => $appraisal->source,
            'shortfall' => $appraisal->alignment <= Executive::VALUE_SHORTFALL_ALIGNMENT,
        ]);

        $streak = $this->valueShortfallStreak((int) $value->id);
        $formed = null;
        if ($streak >= Executive::VALUE_INTENTION_STREAK && !$this->valueHasOpenIntention((int) $value->id)) {
            $formed = $this->formIntentionFromValue($value, $streak, $appraisal->evidence);
            $appraisal->setFields(['generated_intention_id' => $formed['intention']['id']]);
            $appraisal->save();
        }

        return [
            'appraisal' => $appraisal->getData(),
            'value' => $this->valueState($value, time()),
            'shortfall_streak' => $streak,
            'formed_intention' => $formed,
        ];
    }

    public function reviewValuesDue(?int $now = null): array
    {
        $now ??= time();
        $due = [];
        foreach (HeldValue::getAllByWhere(['status' => 'active']) as $value) {
            $lastReviewed = $this->timestamp($value->last_reviewed_at)
                ?? $this->timestamp($value->created_at)
                ?? $now;
            $nextReviewAt = $lastReviewed + ((int) $value->review_interval_hours * 3600);
            if ($nextReviewAt > $now) {
                continue;
            }

            $appraisals = $this->valueAppraisals((int) $value->id);
            $count = count($appraisals);
            $mean = $count === 0 ? null : array_sum(array_map( static fn (ValueAppraisal $appraisal): float => (float) $appraisal->alignment, $appraisals )) / $count;
            $shortfalls = count(array_filter( $appraisals, static fn (ValueAppraisal $appraisal): bool => (float) $appraisal->alignment <= Executive::VALUE_SHORTFALL_ALIGNMENT ));

            $due[] = [
                'value_key' => (string) $value->value_key,
                'value_id' => (int) $value->id,
                'statement' => (string) $value->statement,
                'weight' => (float) $value->weight,
                'appraisals' => $count,
                'mean_alignment' => $mean,
                'shortfalls' => $shortfalls,
                'due_since' => $nextReviewAt,

                'note' => $count === 0
                    ? 'No conduct recorded against this value. Nothing to revise on.'
                    : 'Shortfall counts are evidence about conduct, not about the value.',
            ];
        }

        return $due;
    }

    public function markValueReviewed(string $key): array
    {
        $this->requireText($key, 'value key');

        $value = HeldValue::getByField('value_key', $key);
        if (!$value instanceof HeldValue) {
            throw new RuntimeException(sprintf('Value %s does not exist.', $key));
        }
        $value->setFields(['last_reviewed_at' => time(), 'updated_at' => time()]);
        $value->save();
        $event = $this->emit('value.reviewed', [ 'value_id' => $value->id, 'value_key' => $key, 'outcome' => 'kept', ]);

        return ['value' => $value->getData(), 'event' => $event->getData()];
    }

    public function reviseValue(string $key, ValueRevision $revision): array
    {
        $this->requireText($key, 'value key');
        $this->requireText($revision->new_statement, 'value statement');
        $this->requireText($revision->reason, 'revision reason');
        $this->requireUnitInterval($revision->new_weight, 'value weight');
        $this->requireChoice($revision->basis, ['world_evidence', 'cost_discovered', 'incoherence', 'user_directive'], 'revision basis');
        $this->requireChoice($revision->authority, ['user', 'developer', 'system', 'agent'], 'authority');

        $value = HeldValue::getByField('value_key', $key);
        if (!$value instanceof HeldValue) {
            throw new RuntimeException(sprintf('Value %s does not exist.', $key));
        }

        $previousStatement = (string) $value->statement;
        $previousWeight = (float) $value->weight;
        $softening = $revision->new_weight < $previousWeight - 0.0005;
        $streak = $this->valueShortfallStreak((int) $value->id);

        if ($softening && $streak > 0 && $revision->authority !== 'user') {
            throw new RuntimeException(sprintf(
                'Refusing to lower value %s while it has a shortfall streak of %d. '
                . 'Repeated failure is evidence about conduct, not about the value. '
                . 'Lowering it here requires --authority=user.',
                $key,
                $streak
            ));
        }

        $value->setFields([ 'statement' => $revision->new_statement, 'weight' => $revision->new_weight, 'last_reviewed_at' => time(), 'updated_at' => time(), ]);
        $value->save();

        $event = $this->emit('value.revised', [
            'value_id' => $value->id,
            'value_key' => $key,
            'basis' => $revision->basis,
            'authority' => $revision->authority,
            'previous_weight' => $previousWeight,
            'new_weight' => $revision->new_weight,
        ]);

        $revision->setFields([ 'value_id' => $value->id, 'previous_statement' => $previousStatement, 'previous_weight' => $previousWeight, 'event_id' => $event->id, ]);
        $revision->save();

        return [
            'value' => $value->getData(),
            'revision' => $revision->getData(),
            'event' => $event->getData(),
        ];
    }

    public function valueState(HeldValue $value, int $now): array
    {
        $lastReviewed = $this->timestamp($value->last_reviewed_at)
            ?? $this->timestamp($value->created_at)
            ?? $now;
        $nextReviewAt = $lastReviewed + ((int) $value->review_interval_hours * 3600);

        return [
            'id' => (int) $value->id,
            'value_key' => (string) $value->value_key,
            'statement' => (string) $value->statement,
            'rationale' => (string) $value->rationale,
            'weight' => (float) $value->weight,
            'authority' => (string) $value->authority,
            'status' => (string) $value->status,
            'shortfall_streak' => $this->valueShortfallStreak((int) $value->id),
            'next_review_at' => $nextReviewAt,
            'due_for_review' => $nextReviewAt <= $now,
        ];
    }

    public function valueAppraisals(int $valueId): array
    {
        $appraisals = ValueAppraisal::getAllByWhere(['value_id' => $valueId]);
        usort($appraisals, static fn (ValueAppraisal $a, ValueAppraisal $b): int => (int) $a->id <=> (int) $b->id);

        return array_values($appraisals);
    }

    public function valueShortfallStreak(int $valueId): int
    {
        $streak = 0;
        foreach (array_reverse($this->valueAppraisals($valueId)) as $appraisal) {
            if ((float) $appraisal->alignment > Executive::VALUE_SHORTFALL_ALIGNMENT) {
                break;
            }
            ++$streak;
        }

        return $streak;
    }

    public function valueHasOpenIntention(int $valueId): bool
    {
        foreach ($this->valueAppraisals($valueId) as $appraisal) {
            $intentionId = $appraisal->generated_intention_id;
            if ($intentionId === null) {
                continue;
            }
            $intention = Intention::getByID((int) $intentionId);
            if ($intention instanceof Intention && (string) $intention->status === 'active') {
                return true;
            }
        }

        return false;
    }

    public function formIntentionFromValue(HeldValue $value, int $streak, string $evidence): array
    {
        $intention = new Intention([
            'title' => sprintf('Close the gap on: %s', (string) $value->value_key),
            'reason' => sprintf('Conduct fell short of the held value "%s" on %d consecutive appraisals. Most recent evidence: %s', (string) $value->statement, $streak, $evidence),
            'authority' => 'agent',
            'next_action' => 'Identify the specific behaviour producing the shortfall and change it once, concretely.',
            'success_condition' => sprintf('Three consecutive appraisals of %s score above %.2f alignment.', (string) $value->value_key, Executive::VALUE_SHORTFALL_ALIGNMENT),
            'release_condition' => 'The value is retired or revised with a stated basis.',
        ], true, true);
        $formed = $this->createIntention($intention);

        $this->emit('value.intention_formed', [
            'value_id' => $value->id,
            'value_key' => (string) $value->value_key,
            'intention_id' => $formed['intention']['id'],
            'shortfall_streak' => $streak,
        ]);

        return $formed;
    }
}
