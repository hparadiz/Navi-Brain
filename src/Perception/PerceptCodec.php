<?php

declare(strict_types=1);

namespace NaviBrain\Perception;

use InvalidArgumentException;
use NaviBrain\Core\ExecutiveCore\Executive;
use NaviBrain\Model\PerceptFrame;
use NaviBrain\Model\SenseReading;
use RuntimeException;

class PerceptCodec
{
    public const VERSION = 1;

    private const T_BOOL = 'b1';
    private const T_U8 = 'u8';
    private const T_U16 = 'u16';
    private const T_Q8 = 'q8';
    private const T_TEXT = 't';

    public function __construct(private readonly Executive $core)
    {
    }

    /**
     * @param list<array<string, mixed>> $samples
     * @return list<array{name: string, type: string}>
     */
    public function inferLayout(array $samples): array
    {
        $fields = [];
        foreach ($samples as $sample) {
            foreach ($sample as $name => $value) {
                if (!is_string($name)) {
                    continue;
                }
                $type = $this->typeOf($value);
                $existing = $fields[$name] ?? null;

                $fields[$name] = $existing === null ? $type : $this->widen($existing, $type);
            }
        }
        ksort($fields);

        $layout = [];
        foreach ($fields as $name => $type) {
            $layout[] = ['name' => $name, 'type' => $type];
        }
        return $layout;
    }

    /**
     * @param list<array{observed_at: int, payload: array<string, mixed>}> $readings
     * @return array{frame: PerceptFrame, ratio: float}
     */
    public function pack(string $sourceKey, array $readings, int $windowStart, int $windowEnd): array
    {
        if ($readings === []) {
            throw new InvalidArgumentException('Cannot pack an empty window.');
        }
        $layout = $this->inferLayout(array_map( static fn (array $r): array => $r['payload'], $readings ));

        $writer = new BitWriter();
        $sourceBytes = 0;
        foreach ($readings as $reading) {
            $sourceBytes += strlen(json_encode( $reading['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '');

            $offset = max(0, min(65535, $reading['observed_at'] - $windowStart));
            $writer->write($offset, 16);

            foreach ($layout as $field) {
                $value = $reading['payload'][$field['name']] ?? null;
                $this->writeField($writer, $field['type'], $value);
            }
        }

        $packed = $writer->toBytes();
        $encoded = base64_encode($packed);
        $checksum = hash('sha256', self::VERSION . '|' . $sourceKey . '|' . $packed);

        /** @var PerceptFrame $frame */
        $frame = new PerceptFrame([
            'source_key' => $sourceKey,
            'codec_version' => self::VERSION,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'sample_count' => count($readings),
            'layout' => $layout,
            'payload' => $encoded,
            'packed_bytes' => strlen($packed),
            'source_bytes' => $sourceBytes,
            'checksum' => $checksum,
        ], true, true);
        $frame->save();

        return [
            'frame' => $frame,
            'ratio' => $sourceBytes === 0 ? 1.0 : round(strlen($packed) / $sourceBytes, 4),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function unpack(PerceptFrame $frame): array
    {
        $packed = base64_decode((string) $frame->payload, true);
        if ($packed === false) {
            throw new RuntimeException('Percept frame payload is not valid base64.');
        }
        $expected = hash('sha256', $frame->codec_version . '|' . $frame->source_key . '|' . $packed);
        if (!hash_equals((string) $frame->checksum, $expected)) {
            throw new RuntimeException('Percept frame checksum does not verify.');
        }

        $layout = is_array($frame->layout) ? $frame->layout : [];
        $reader = new BitReader($packed);
        $windowStart = $this->timestamp($frame->window_start) ?? 0;
        $samples = [];

        for ($index = 0; $index < (int) $frame->sample_count; $index++) {
            $offset = $reader->read(16);
            $sample = ['observed_at' => $windowStart + $offset];
            foreach ($layout as $field) {
                $sample[$field['name']] = $this->readField($reader, $field['type']);
            }
            $samples[] = $sample;
        }
        return $samples;
    }

    /** @return list<array<string, mixed>> */
    public function compact(int $olderThanSeconds = 900, ?int $now = null): array
    {
        $now ??= time();
        $cutoff = $now - max(60, $olderThanSeconds);
        $bySource = [];

        foreach (SenseReading::getAll(['order' => ['id' => 'ASC'], 'limit' => 5000]) as $reading) {
            $observedAt = $this->timestamp($reading->observed_at);
            if ($observedAt === null || $observedAt > $cutoff) {
                continue;
            }
            $key = (string) $reading->source_key;
            $bySource[$key] ??= [];
            $bySource[$key][] = [
                'id' => (int) $reading->id,
                'observed_at' => $observedAt,
                'payload' => is_array($reading->payload) ? $reading->payload : [],
            ];
        }

        $results = [];
        foreach ($bySource as $sourceKey => $readings) {
            if (count($readings) < 2) {
                continue;
            }
            usort($readings, static fn (array $a, array $b): int => $a['observed_at'] <=> $b['observed_at']);
            $windowStart = $readings[0]['observed_at'];
            $windowEnd = $readings[count($readings) - 1]['observed_at'];

            $packed = $this->pack($sourceKey, $readings, $windowStart, $windowEnd);
            foreach ($readings as $reading) {
                $row = SenseReading::getByID($reading['id']);
                if ($row instanceof SenseReading) {
                    $row->destroy();
                }
            }

            $frame = $packed['frame'];
            $this->core->emitEvent('percept.compacted', [
                'source_key' => $sourceKey,
                'frame_id' => (int) $frame->id,
                'samples' => count($readings),
                'source_bytes' => (int) $frame->source_bytes,
                'packed_bytes' => (int) $frame->packed_bytes,
                'ratio' => $packed['ratio'],
            ]);
            $results[] = [
                'source_key' => $sourceKey,
                'frame_id' => (int) $frame->id,
                'samples' => count($readings),
                'source_bytes' => (int) $frame->source_bytes,
                'packed_bytes' => (int) $frame->packed_bytes,
                'ratio' => $packed['ratio'],
            ];
        }
        return $results;
    }

    private function typeOf(mixed $value): string
    {
        if (is_bool($value) || $value === null) {
            return self::T_BOOL;
        }
        if (is_int($value)) {
            return $value >= 0 && $value <= 255 ? self::T_U8 : self::T_U16;
        }
        if (is_float($value)) {
            return $value >= 0.0 && $value <= 1.0 ? self::T_Q8 : self::T_U16;
        }
        return self::T_TEXT;
    }

    private function widen(string $left, string $right): string
    {
        if ($left === $right) {
            return $left;
        }
        $rank = [self::T_BOOL => 0, self::T_Q8 => 1, self::T_U8 => 2, self::T_U16 => 3, self::T_TEXT => 4];
        return ($rank[$left] ?? 4) >= ($rank[$right] ?? 4) ? $left : $right;
    }

    private function writeField(BitWriter $writer, string $type, mixed $value): void
    {
        switch ($type) {
            case self::T_BOOL:
                $writer->write($value === null ? 0 : (int) (bool) $value, 1);
                return;
            case self::T_U8:
                $writer->write(max(0, min(255, (int) $value)), 8);
                return;
            case self::T_U16:
                $writer->write(max(0, min(65535, (int) $value)), 16);
                return;
            case self::T_Q8:
                $writer->write((int) round(max(0.0, min(1.0, (float) $value)) * 255), 8);
                return;
            case self::T_TEXT:

                $text = is_string($value) ? $value : '';
                $writer->write(min(4095, mb_strlen($text)), 12);
                $writer->write($text === '' ? 0 : (crc32($text) & 0xFFFF), 16);
                return;
            default:
                throw new RuntimeException('Unknown percept field type: ' . $type);
        }
    }

    private function readField(BitReader $reader, string $type): mixed
    {
        return match ($type) {
            self::T_BOOL => (bool) $reader->read(1),
            self::T_U8 => $reader->read(8),
            self::T_U16 => $reader->read(16),
            self::T_Q8 => round($reader->read(8) / 255, 4),
            self::T_TEXT => ['length' => $reader->read(12), 'digest' => $reader->read(16)],
            default => throw new RuntimeException('Unknown percept field type: ' . $type),
        };
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_numeric($value)) {
            return (int) $value;
        }
        $parsed = strtotime((string) $value);
        return $parsed === false ? null : $parsed;
    }
}

class BitWriter
{
    private string $bytes = '';
    private int $current = 0;
    private int $filled = 0;

    public function write(int $value, int $width): void
    {
        for ($bit = $width - 1; $bit >= 0; $bit--) {
            $this->current = ($this->current << 1) | (($value >> $bit) & 1);
            $this->filled++;
            if ($this->filled === 8) {
                $this->bytes .= chr($this->current);
                $this->current = 0;
                $this->filled = 0;
            }
        }
    }

    public function toBytes(): string
    {
        if ($this->filled === 0) {
            return $this->bytes;
        }
        return $this->bytes . chr($this->current << (8 - $this->filled));
    }
}

class BitReader
{
    private int $position = 0;

    public function __construct(private readonly string $bytes)
    {
    }

    public function read(int $width): int
    {
        $value = 0;
        for ($index = 0; $index < $width; $index++) {
            $byteIndex = $this->position >> 3;
            $bitIndex = 7 - ($this->position & 7);
            $bit = $byteIndex < strlen($this->bytes)
                ? ((ord($this->bytes[$byteIndex]) >> $bitIndex) & 1)
                : 0;
            $value = ($value << 1) | $bit;
            $this->position++;
        }
        return $value;
    }
}
