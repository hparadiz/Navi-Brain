<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\Mapping\Column;

class ContextCapsule extends ActiveRecord
{
    public static $tableName = 'context_capsules';
    public static $primaryKey = 'id';

    public static $indexes = [
        'context_capsules_thread' => ['fields' => ['thread_id', 'created_at']],
        'context_capsules_step' => ['fields' => ['thread_step_id']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'integer', unsigned: true)]
    protected int $thread_id;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $thread_step_id = null;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $previous_capsule_id = null;

    #[Column(type: 'string', length: 96)]
    protected string $trigger;

    #[Column(type: 'integer', unsigned: true)]
    protected int $slot_budget;

    #[Column(type: 'integer', unsigned: true)]
    protected int $candidate_count = 0;

    #[Column(type: 'integer', unsigned: true)]
    protected int $filled_slots = 0;

    #[Column(type: 'string', length: 64)]
    protected string $checksum;

    public static function serializeSlots(int $capsuleId): array
    {
        $payload = [];
        foreach (CapsuleSlot::getAllByWhere(['capsule_id' => $capsuleId], ['order' => ['id' => 'ASC']]) as $slot) {
            $payload[] = [
                'slot_role' => (string) $slot->slot_role,
                'record_type' => $slot->record_type,
                'record_id' => $slot->record_id === null ? null : (int) $slot->record_id,
                'claim' => $slot->claim,
                'confidence' => round((float) $slot->confidence, 4),
                'carryover_depth' => (int) $slot->carryover_depth,
            ];
        }
        return $payload;
    }

    public function saveSlots(array $resolved): array
    {
        $this->setFields([
            'filled_slots' => count(array_filter($resolved, static fn (array $slot): bool => $slot['record_id'] !== null)),
            'checksum' => str_repeat('0', 64),
        ]);
        $this->save();

        $slots = [];
        foreach ($resolved as $slot) {
            /** @var CapsuleSlot $record */
            $record = new CapsuleSlot([
                'capsule_id' => (int) $this->id,
                'slot_role' => $slot['role'],
                'record_type' => $slot['record_type'],
                'record_id' => $slot['record_id'],
                'claim' => $slot['claim'],
                'confidence' => (float) $slot['confidence'],
                'score' => (float) $slot['score'],
                'recorded_at' => $slot['recorded_at'],
                'transition' => $slot['transition'],
                'transition_reason' => $slot['reason'],
                'carried_from_capsule_id' => $slot['carried_from'] && $this->previous_capsule_id !== null
                    ? (int) $this->previous_capsule_id
                    : null,
                'carryover_depth' => (int) $slot['carryover_depth'],
                'reserved' => (int) $slot['reserved'],
            ], true, true);
            $record->save();
            $slots[] = $record;
        }

        $this->setField('checksum', hash('sha256', json_encode(static::serializeSlots((int) $this->id), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
        $this->save();

        return ['capsule' => $this, 'slots' => $slots];
    }
}
