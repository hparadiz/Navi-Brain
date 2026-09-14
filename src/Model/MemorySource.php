<?php

declare(strict_types=1);

namespace NaviBrain\Model;

/** Resolve only explicitly typed lineage; a coincident numeric ID is not evidence. */
final class MemorySource
{
    /**
     * @param array<string, mixed> $memory
     * @return array{kind: string, id: int}|null
     */
    public static function reference(array $memory): ?array
    {
        $kind = $memory['source_event_kind'] ?? null;
        $id = $memory['source_event_id'] ?? null;
        if (!in_array($kind, ['event', 'sense_event'], true)
            || (!is_int($id) && !is_string($id))
        ) {
            return null;
        }
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($id) ? ['kind' => $kind, 'id' => $id] : null;
    }

    /** @param array<string, mixed> $memory */
    public static function inspect(array $memory): Event|SenseEvent|null
    {
        $reference = self::reference($memory);
        return match ($reference['kind'] ?? null) {
            'event' => Event::getByID($reference['id']),
            'sense_event' => SenseEvent::getByID($reference['id']),
            default => null,
        };
    }
}
