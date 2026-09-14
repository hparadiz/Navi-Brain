<?php

declare(strict_types=1);

namespace NaviBrain\Core\WorkingMemory;

use InvalidArgumentException;
use RuntimeException;
use NaviBrain\Model\Memory;
use NaviBrain\Model\WorkingMemorySlot;
use NaviBrain\Model\WorkspaceMaintenanceScan;

class Maintenance extends Component
{
    private int $maintenanceCursor = 0;
    private int $maintenancePriorityCursor = 0;
    private int $maintenanceAuditCursor = 0;
    private bool $maintenanceAuditNext = false;

    /** @return array<string, mixed> */
    public function maintainBatch(int $limit = 32): array
    {
        if ($limit < 1 || $limit > 128) {
            throw new InvalidArgumentException('Workspace maintenance limit must be between 1 and 128.');
        }
        $slots = $this->selectMaintenanceBatch($limit);
        $result = ['visited' => 0, 'replayed' => 0, 'expired' => 0, 'refreshed' => 0,
            'unchanged' => 0, 'raced' => 0, 'deferred_legacy' => 0, 'errors' => []];
        foreach ($slots as $slot) {
            $id = (int) $slot->id;
            $this->maintenanceCursor = $id;
            $result['visited']++;
            try {
                $state = $this->projectionState($slot);
                if ($state['memory_id'] === null) {
                    $result['deferred_legacy']++;
                    continue;
                }
                if ($state['pending'] !== null) {
                    $this->replayProjection($slot, false);
                    $result['replayed']++;
                }
                $slot = WorkingMemorySlot::getByID($id);
                if (!$slot instanceof WorkingMemorySlot) {
                    throw new RuntimeException('Workspace maintenance source slot disappeared.');
                }
                $fields = [];
                $now = time();
                $change = 'unchanged';
                if ($slot->status === 'active') {
                    $expiry = $slot->expires_at;
                    if ($expiry !== null && (($this->timestamp($expiry) ?? 0) <= $now)) {
                        $fields = ['status' => 'expired'];
                    } elseif (in_array($slot->record_type, ['memory', 'procedure'], true)) {
                        $sourceId = (int) ($slot->record_id ?? 0);
                        $source = $sourceId < 1 ? null : Memory::inspectByID($sourceId);
                        if (!$this->eligibleBackingRecord((string) $slot->record_type, $source instanceof Memory ? $source->getData() : null, $now)) {
                            $fields = ['status' => 'expired'];
                        } else {
                            $claim = mb_substr((string) $source->content, 0, 800);
                            $confidence = round((float) $source->confidence, 4);
                            if ((string) $slot->claim !== $claim || (float) $slot->confidence !== $confidence) {
                                $fields = ['claim' => $claim, 'confidence' => $confidence];
                            }
                        }
                        unset($source);
                    }
                    if ($fields !== []) {
                        $change = isset($fields['status']) ? 'expired' : 'refreshed';
                        $fields['updated_at'] = $now;
                    }
                }
                if ($fields !== []) {
                    $fresh = $this->maintainSlotIfUnchanged($slot, $fields);
                    if ($fresh === null) {
                        $result['raced']++;
                        continue;
                    }
                    $slot = $fresh;
                }

                $this->synchronizeProjection($slot, false);
                $result[$change]++;
            } catch (\Throwable $error) {
                $result['errors'][] = ['slot_id' => $id, 'error' => $error->getMessage()];
            }
        }

        $result['after_id'] = $this->maintenanceCursor;
        $result['priority_after_id'] = $this->maintenancePriorityCursor;
        $result['audit_after_id'] = $this->maintenanceAuditCursor;
        return $result;
    }

    /** @return list<WorkingMemorySlot> */
    public function selectMaintenanceBatch(int $limit): array
    {
        $state = WorkspaceMaintenanceScan::getByID(1);
        if (!$state instanceof WorkspaceMaintenanceScan) {
            throw new RuntimeException('Workspace maintenance cursor is missing.');
        }
        $this->maintenancePriorityCursor = (int) $state->priority_after_id;
        $this->maintenanceAuditCursor = (int) $state->audit_after_id;
        $this->maintenanceAuditNext = (int) $state->audit_next === 1;
        if ($limit === 1) {
            $priority = !$this->maintenanceAuditNext;
            $this->maintenanceAuditNext = !$this->maintenanceAuditNext;
            $slots = $this->maintenancePage($priority, 1);
            if ($slots === []) {
                $slots = $this->maintenancePage(!$priority, 1);
            }
        } else {

            $slots = $this->maintenancePage(true, $limit - 1);
            $audit = $this->maintenancePage(false, $limit - count($slots));
            $slots = array_merge($slots, $audit);
        }

        $state->setFields([ 'priority_after_id' => $this->maintenancePriorityCursor, 'audit_after_id' => $this->maintenanceAuditCursor, 'audit_next' => $this->maintenanceAuditNext ? 1 : 0, ]);
        $state->save();
        return $slots;
    }

    /** @return list<WorkingMemorySlot> */
    public function maintenancePage(bool $priority, int $limit): array
    {
        $cursor = $priority ? $this->maintenancePriorityCursor : $this->maintenanceAuditCursor;

        $predicate = $priority ? "(status = 'active' OR projection_pending IS NOT NULL)"
            : "(status <> 'active' AND projection_pending IS NULL)";
        $read = static fn (int $after): array => WorkingMemorySlot::getAllByWhere(['id > ' . $after, $predicate], ['order' => ['id' => 'ASC'], 'limit' => $limit]);
        $rows = $read($cursor);
        if ($rows === [] && $cursor > 0) {
            $cursor = 0;
            $rows = $read(0);
        }
        if ($rows !== []) {
            $cursor = (int) $rows[array_key_last($rows)]->id;
        }
        if ($priority) {
            $this->maintenancePriorityCursor = $cursor;
        } else {
            $this->maintenanceAuditCursor = $cursor;
        }
        return $rows;
    }

    /** @param array<string, mixed> $fields */
    public function maintainSlotIfUnchanged(WorkingMemorySlot $observed, array $fields): ?WorkingMemorySlot
    {
        $expected = serialize($observed->getData());
        $current = WorkingMemorySlot::getByID((int) $observed->id);
        $state = $current instanceof WorkingMemorySlot ? $this->projectionState($current) : null;
        if (!$current instanceof WorkingMemorySlot
            || serialize($current->getData()) !== $expected
            || $state['pending'] !== null || $state['memory_id'] === null) {
            return null;
        }
        $current->setFields($fields);
        $current->save();
        $this->installProjectionIntent($current);
        return $current;
    }
}
