<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use Divergence\IO\Database\SQLite;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\Event;
use NaviBrain\Model\ExecutiveInterrupt;
use RuntimeException;

class Interrupts extends Component
{
    public function raiseInterrupt(string $reason, string $severity = 'high', string $source = 'external'): array
    {
        $this->requireText($reason, 'interrupt reason');
        $this->requireChoice($severity, ['critical', 'high', 'normal'], 'severity');
        $this->requireText($source, 'interrupt source');

        $interrupt = new ExecutiveInterrupt([ 'reason' => $reason, 'severity' => $severity, 'source' => $source, 'status' => 'pending', 'fencing_token' => 0, 'updated_at' => time(), ], true, true);
        $interrupt->save();

        $event = $this->emit('interrupt.raised', [ 'interrupt_id' => $interrupt->id, 'severity' => $severity, 'source' => $source, 'reason' => $reason, ]);

        return ['interrupt' => $interrupt->getData(), 'event' => $event->getData()];
    }

    public function acknowledgeInterrupt(int $id, string $nodeId): array
    {
        $this->requireText($nodeId, 'node id');

        $interrupt = ExecutiveInterrupt::getByID($id);
        if (!$interrupt instanceof ExecutiveInterrupt) {
            throw new RuntimeException(sprintf('Interrupt %d does not exist.', $id));
        }
        if ($interrupt->status !== 'pending') {
            throw new RuntimeException(sprintf('Interrupt %d is already %s.', $id, $interrupt->status));
        }

        $newFence = (int) $interrupt->fencing_token + 1;

        $statementRecord = ExecutiveInterrupt::getByWhere(['id' => $id, 'status = \'pending\'']);
        if (!$statementRecord instanceof ExecutiveInterrupt) {
            throw new RuntimeException('Interrupt claim lost.');
        }
        $statementRecord->setFields([
            'status' => 'acknowledged',
            'acknowledged_by' => $nodeId,
            'acknowledged_at' => date('Y-m-d H:i:s', time()),
            'fencing_token' => $newFence,
            'updated_at' => date('Y-m-d H:i:s', time())
        ]);
        $statementRecord->save();

        $interrupt = ExecutiveInterrupt::getByID($id);
        $event = $this->emit('interrupt.acknowledged', [ 'interrupt_id' => $id, 'node_id' => $nodeId, 'fencing_token' => $newFence, ]);

        return ['interrupt' => $interrupt->getData(), 'event' => $event->getData()];
    }

    public function runSafetyCheck(): ?array
    {
        $now = time();
        $findings = [];

        $integrity = $this->periodicQuickCheck($now);
        if ($integrity !== ['ok']) {
            $findings[] = [
                'kind' => 'integrity_failure',
                'lizard_brain' => true,
                'description' => 'SQLite quick_check failed: ' . implode('; ', $integrity),
            ];
        }

        foreach (ActionTrace::getAllByWhere(['status' => 'pending']) as $action) {
            $createdAt = $this->timestamp($action->created_at);
            if ($createdAt !== null && $createdAt < $now - Executive::STALE_ACTION_SECONDS * 2) {
                $findings[] = [
                    'kind' => 'stale_action_critical',
                    'lizard_brain' => false,
                    'description' => sprintf('Action %d pending for over %d seconds.', $action->id, Executive::STALE_ACTION_SECONDS * 2),
                    'action_id' => $action->id,
                ];
            }
        }

        if ($findings === []) {
            return null;
        }

        $halting = array_values(array_filter( $findings, static fn (array $f): bool => in_array($f['kind'], Executive::HALTING_FINDINGS, true) ));

        $signature = hash('sha256', json_encode( array_map( static fn (array $f): string => $f['kind'] . ':' . (string) ($f['action_id'] ?? $f['description']), $findings ), JSON_THROW_ON_ERROR ));
        $previous = Event::getByWhere(['kind' => 'safety.alert'], ['order' => ['id' => 'DESC']]);
        $known = $previous instanceof Event
            && is_array($previous->payload)
            && ($previous->payload['signature'] ?? null) === $signature;

        $event = null;
        if (!$known) {
            $event = $this->emit('safety.alert', [ 'findings' => $findings, 'signature' => $signature, 'timestamp' => $now, ]);
        }

        if ($halting === [] && $known) {

            return null;
        }

        return [
            'status' => 'safety_alert',
            'preempt_any_interrupt' => $halting !== [],
            'findings' => $findings,
            'event' => $event?->getData(),
        ];
    }

    public function checkExecutiveInterrupts(string $nodeId): ?array
    {
        $this->requireText($nodeId, 'node id');

        $safety = $this->runSafetyCheck();
        if ($safety !== null) {
            return $safety;
        }

        $pending = ExecutiveInterrupt::getAllByWhere(['status' => 'pending'], ['order' => ['created_at' => 'ASC']]);

        $critical = array_values(array_filter( $pending, static fn (ExecutiveInterrupt $i): bool => $i->severity === 'critical' ));

        if ($critical !== []) {
            $interrupt = $critical[0];
            $ack = $this->acknowledgeInterrupt((int) $interrupt->id, $nodeId);
            return [
                'status' => 'critical_interrupt_claimed',
                'interrupt' => $ack['interrupt'],
                'event' => $ack['event'],
                'trigger_immediate_wake' => true,
            ];
        }

        $high = array_values(array_filter( $pending, static fn (ExecutiveInterrupt $i): bool => $i->severity === 'high' ));

        if ($high !== [] && !$this->highBrainBusy()) {
            $interrupt = $high[0];
            $ack = $this->acknowledgeInterrupt((int) $interrupt->id, $nodeId);
            return [
                'status' => 'high_interrupt_claimed',
                'interrupt' => $ack['interrupt'],
                'event' => $ack['event'],
                'trigger_immediate_wake' => true,
            ];
        }

        if ($pending !== []) {
            return [
                'status' => 'interrupts_pending_deferred',
                'pending_count' => count($pending),
                'trigger_immediate_wake' => false,
            ];
        }

        return null;
    }

    public function resolveInterrupt(int $id, string $nodeId, ?int $runId = null): array
    {
        $this->requireText($nodeId, 'node id');

        $interrupt = ExecutiveInterrupt::getByID($id);
        if (!$interrupt instanceof ExecutiveInterrupt) {
            throw new RuntimeException(sprintf('Interrupt %d does not exist.', $id));
        }
        if ($interrupt->status !== 'acknowledged') {
            throw new RuntimeException(sprintf('Interrupt %d is not acknowledged.', $id));
        }
        if ($interrupt->acknowledged_by !== $nodeId) {
            throw new RuntimeException('Only the acknowledging node can resolve an interrupt.');
        }

        $interrupt->setFields([ 'status' => 'resolved', 'resolution_run_id' => $runId, 'updated_at' => time(), ]);
        $interrupt->save();

        $event = $this->emit('interrupt.resolved', [ 'interrupt_id' => $id, 'node_id' => $nodeId, 'resolution_run_id' => $runId, ]);

        return ['interrupt' => $interrupt->getData(), 'event' => $event->getData()];
    }

    public function listInterrupts(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice($status, ['pending', 'acknowledged', 'resolved'], 'status');
            return $this->records(ExecutiveInterrupt::getAllByWhere( ['status' => $status], ['order' => ['created_at' => 'DESC']] ));
        }
        return $this->records(ExecutiveInterrupt::getAll(['order' => ['created_at' => 'DESC']]));
    }
}
