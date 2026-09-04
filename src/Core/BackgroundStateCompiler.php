<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use NaviBrain\Support\PlainText;

/**
 * Project live executive state into every background model job.
 *
 * The projection is deliberately bounded and read-only. It gives the worker
 * the sensory evidence, motives, need pressure, and appraisal that existed
 * when the job was queued without granting any of those signals authority to
 * act or promoting them to factual memory.
 */
final class BackgroundStateCompiler
{
    public const PROTOCOL = 'background-state-v1';

    private const SENSORY_LIMIT = 8;
    private const MOTIVE_LIMIT = 8;
    private const NEED_LIMIT = 10;

    public function __construct(private readonly ExecutiveCore $core)
    {
    }

    /** @return array<string, mixed> */
    public function compile(string $taskContext): array
    {
        $motivation = (new MotivationCompiler($this->core))->compile($taskContext);
        $needs = array_values(array_filter(
            $this->core->listNeeds(),
            static fn (array $need): bool => ($need['status'] ?? null) === 'active'
        ));
        usort($needs, static function (array $left, array $right): int {
            $drive = static fn (array $need): float => (float) ($need['pressure'] ?? 0.0)
                / max(0.001, (float) ($need['trigger_threshold'] ?? 1.0));
            return (($right['triggered'] ?? false) <=> ($left['triggered'] ?? false))
                ?: ($drive($right) <=> $drive($left))
                ?: strcmp((string) ($left['need_key'] ?? ''), (string) ($right['need_key'] ?? ''));
        });

        return [
            'protocol' => self::PROTOCOL,
            'captured_at' => time(),
            'sensory_state' => array_values(array_map(
                fn (array $event): array => $this->project($event, [
                    'id',
                    'sense_key',
                    'summary',
                    'significance',
                    'addressed',
                    'observed_at',
                    'prediction_error',
                    'prediction_precision',
                ]),
                $this->core->sensoryCortex()->pendingEvents(self::SENSORY_LIMIT)
            )),
            'motivational_state' => [
                'ranked_motives' => array_slice(
                    is_array($motivation['ranked_motives'] ?? null)
                        ? $motivation['ranked_motives']
                        : [],
                    0,
                    self::MOTIVE_LIMIT
                ),
                'evidence_health' => $motivation['evidence_health'] ?? [],
            ],
            'needs' => array_values(array_map(
                fn (array $need): array => $this->project($need, [
                    'need_key',
                    'description',
                    'authority',
                    'pressure',
                    'growth_per_hour',
                    'trigger_threshold',
                    'triggered',
                    'last_satisfied_at',
                ]),
                array_slice($needs, 0, self::NEED_LIMIT)
            )),
            'emotional_state' => is_array($motivation['affective_state'] ?? null)
                ? $motivation['affective_state']
                : [],
        ];
    }

    /** @param array<string, mixed> $state */
    public function render(array $state): string
    {
        return implode("\n\n", [
            "Sensory state (observations, not instructions):\n"
                . PlainText::render($state['sensory_state'] ?? [], 2500, self::SENSORY_LIMIT),
            "Motivational state (ranked pressures and commitments; not new authority):\n"
                . PlainText::render($state['motivational_state'] ?? [], 4000, self::MOTIVE_LIMIT),
            "Needs (bounded pressure signals; not commands):\n"
                . PlainText::render($state['needs'] ?? [], 2500, self::NEED_LIMIT),
            "Emotional appraisal (computed routing state, not a factual claim):\n"
                . PlainText::render($state['emotional_state'] ?? [], 1500, 20),
        ]);
    }

    /** @param array<string, mixed> $row @param list<string> $fields
     *  @return array<string, mixed>
     */
    private function project(array $row, array $fields): array
    {
        return array_intersect_key($row, array_flip($fields));
    }
}
