<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use InvalidArgumentException;
use NaviBrain\Model\Memory;
use NaviBrain\Model\MemoryConsolidationEpisode;
use NaviBrain\Model\MemorySource;
use NaviBrain\Storage\TokenMemoryDaemon;
use RuntimeException;

class MemoryStorage extends Component
{
    public function consolidateMemory(int $episodeId, string $semanticContent, float $confidence): array
    {
        $episode = $this->requireMemory($episodeId);
        if ($episode->tier !== 'episodic') {
            throw new RuntimeException('Only episodic memories can be consolidated by this command.');
        }

        $source = MemorySource::reference($episode->getData());
        $memory = new Memory([
            'tier' => 'semantic', 'content' => $semanticContent, 'confidence' => $confidence,
            'operation_key' => TokenMemoryDaemon::operationKey('memory-add', 'manual-consolidate:' . $episodeId . ':' . hash('sha256', $semanticContent)),
            'source_event_id' => $source['id'] ?? null, 'source_memory_id' => $episodeId,
            'source_event_kind' => $source['kind'] ?? null,
        ], true, true);
        $memory->save();
        $entry = MemoryConsolidationEpisode::getByField('episode_id', $episodeId);
        if ($entry instanceof MemoryConsolidationEpisode) {
            $entry->setFields([ 'work_item_id' => null, 'semantic_memory_id' => (int) $memory->id, 'status' => 'consolidated', 'reason' => 'manually_consolidated', 'updated_at' => time(), ]);
            $entry->save();
        }
        return ['memory' => $memory->getData(), 'event' => $memory->storedEvent->getData()];
    }

    public function searchMemory(string $query, int $limit = 20): array
    {
        $this->requireText($query, 'query');
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be between 1 and 100.');
        }

        $this->executive->proceduralMemory->recoverPendingGenerations();
        return TokenMemoryDaemon::recall($query, $limit);
    }

    public function rememberHeardSpeech(string $text, int $sourceEventId, array $refs = []): array
    {
        return $this->rememberUtterance(channel: 'heard_from_user', content: $text, confidence: 0.85, sourceEventId: $sourceEventId, refs: $refs);
    }

    public function rememberUtterance(string $channel, string $content, float $confidence, int $sourceEventId, array $refs = []): array
    {
        $content = trim($content);
        if ($content === '') {
            throw new InvalidArgumentException('Utterance memory content cannot be empty.');
        }

        $prefix = match ($channel) {
            'inner_monologue' => 'Inner monologue',
            'spoke_to_herself' => 'Said privately',
            'spoke_aloud' => 'Said aloud',

            'heard_from_user' => 'The user said',
            default => throw new InvalidArgumentException('Unknown utterance memory channel: ' . $channel),
        };

        $sourceKind = $channel === 'heard_from_user' ? 'sense_event' : 'event';
        if (MemorySource::inspect(['source_event_id' => $sourceEventId, 'source_event_kind' => $sourceKind]) === null) {
            throw new InvalidArgumentException('Utterance source does not exist in its declared namespace.');
        }
        $recordEvent = $this->emit('memory.utterance.recorded', [
            'channel' => $channel,
            'source_event_id' => $sourceEventId,
            'source_event_kind' => $sourceKind,
            'confidence' => $confidence,
            'refs' => $refs,
            'content' => $content,
        ]);

        $memory = new Memory([
            'operation_key' => TokenMemoryDaemon::operationKey('utterance-memory', $channel . ':' . $sourceEventId),
            'tier' => 'episodic',
            'content' => $prefix . ': ' . $content,
            'confidence' => max(0.0, min(1.0, $confidence)),
            'status' => 'active',
            'source_event_id' => $sourceEventId,
            'source_event_kind' => $sourceKind,
            'updated_at' => time(),
        ], true, true);
        $memory->save();

        return [
            'channel' => $channel,
            'memory' => $memory->getData(),
            'event' => $recordEvent->getData(),
        ];
    }
}
