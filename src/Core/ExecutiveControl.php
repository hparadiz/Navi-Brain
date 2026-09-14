<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use NaviBrain\Model\Event;
use RuntimeException;

class ExecutiveControl
{
    /** @return array{paused: bool, event_id: int, reason: string, pause_event_id: ?int} */
    public static function status(): array
    {
        $event = Event::getByWhere(["kind IN ('executive.control.paused', 'executive.control.resumed')"], ['order' => ['id' => 'DESC']]);
        if (!$event instanceof Event) {
            return ['paused' => false, 'event_id' => 0, 'reason' => '', 'pause_event_id' => null];
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $paused = $event->kind === 'executive.control.paused';
        $pauseId = $paused ? (int) $event->id : ($payload['pause_event_id'] ?? null);
        if (!$paused && (!is_int($pauseId) || $pauseId < 1 || $pauseId >= (int) $event->id)) {
            throw new RuntimeException('Cognition resume event has an invalid pause identity.');
        }

        return [
            'paused' => $paused,
            'event_id' => (int) $event->id,
            'reason' => is_string($payload['reason'] ?? null) ? $payload['reason'] : '',
            'pause_event_id' => $pauseId,
        ];
    }
}
