<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use InvalidArgumentException;
use NaviBrain\Model\Checkpoint;
use NaviBrain\Model\Event;
use NaviBrain\Storage\TokenMemoryDaemon;
use RuntimeException;
use Throwable;

class EventLog extends Component
{
    public function listEvents(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('limit must be between 1 and 200.');
        }

        return $this->records(Event::getAll([ 'order' => ['created_at' => 'DESC'], 'limit' => $limit, ]));
    }

    public function emitEvent(string $kind, array $payload): array
    {
        return $this->emit($kind, $payload)->getData();
    }

    public function emitEventOnce(string $dedupeKey, string $kind, array $payload): array
    {
        return $this->emitOnce($dedupeKey, $kind, $payload)->getData();
    }

    public function checkpoint(string $reason): array
    {
        $this->requireText($reason, 'checkpoint reason');
        TokenMemoryDaemon::flush();

        $checkpoint = new Checkpoint([ 'reason' => $reason, 'snapshot' => [], ], true, true);
        $checkpoint->save();

        return $checkpoint->getData();
    }

    public function emit(string $kind, array $payload): Event
    {

        $event = new Event(['kind' => $kind, 'payload' => $payload], true, true);
        $event->save();
        $this->publishActivityEvent($kind, $payload);
        return $event;
    }

    public function emitOnce(string $dedupeKey, string $kind, array $payload): Event
    {
        $this->requireText($dedupeKey, 'event dedupe key');
        $existing = $this->eventByDedupeKey($dedupeKey);
        if ($existing instanceof Event) {
            $this->assertExactEvent($existing, $kind, $payload);
            return $existing;
        }
        $event = new Event([ 'kind' => $kind, 'payload' => $payload, 'dedupe_key' => $dedupeKey, ], true, true);
        $event->save();
        $this->publishActivityEvent($kind, $payload);
        return $event;
    }

    public function eventByDedupeKey(string $dedupeKey): ?Event
    {
        return Event::getByField('dedupe_key', $dedupeKey);
    }

    public function assertExactEvent(Event $event, string $kind, array $payload): void
    {
        $existingPayload = is_array($event->payload) ? $event->payload : [];
        if ((string) $event->kind !== $kind
            || !hash_equals($this->canonicalJson($existingPayload), $this->canonicalJson($payload))
        ) {
            throw new RuntimeException('Event dedupe key was reused with different event data.');
        }
    }

    public function publishActivityEvent(string $kind, array $payload): void
    {
        try {
            $activity = new \NaviBrain\Support\Activity();
            $activity->source = 'core';
            $activity->phase = 'event';
            $activity->operation = $kind;
            $activity->context = $payload;
            $this->executive->activityBus->publish($activity);
        } catch (Throwable) {

        }
    }

    public function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }
            ksort($item, SORT_STRING);
            foreach ($item as $key => $nested) {
                $item[$key] = $normalize($nested);
            }
            return $item;
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
