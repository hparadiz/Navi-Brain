<?php

declare(strict_types=1);

namespace NaviBrain\Support;

class CompilerText
{
    /** @param array<string, mixed> $result */
    public static function render(string $command, array $result): string
    {
        return match ($command) {
            'personality:compile' => self::personality($result),
            'motivation:compile' => self::motivation($result),
            'intention:compile' => self::intentions($result),
            default => PlainText::render($result, PHP_INT_MAX, PHP_INT_MAX),
        };
    }

    /** @param array<string, mixed> $result */
    private static function personality(array $result): string
    {
        $health = self::array($result['evidence_health'] ?? []);
        $lines = [
            'NAVI PERSONALITY COMPILE',
            'protocol: ' . self::text($result['protocol'] ?? ''),
            'context: ' . self::text($result['context'] ?? ''),
            'mode: read-only; generation disconnected; complete ranked set',
            sprintf(
                'evidence: %d nodes; %d fundamental identity; %d semantic ideas; %d reflected utterances',
                (int) ($health['ranked_node_count'] ?? 0),
                (int) ($health['fundamental_identity_node_count'] ?? 0),
                (int) ($health['semantic_idea_count'] ?? 0),
                (int) ($health['reflected_utterance_count'] ?? 0)
            ),
            '',
            'RANKED IDENTITY AND IDEAS',
        ];

        foreach (self::list($result['ranked_identity_and_ideas'] ?? []) as $index => $node) {
            $weights = self::array($node['weights'] ?? []);
            $label = self::text($node['key'] ?? '');
            if ($label === 'none') {
                $label = self::text($node['node_id'] ?? '');
            }
            $lines[] = sprintf('%d. %s [%s] activation %s', (int) ($node['rank'] ?? ($index + 1)), $label, self::text($node['node_type'] ?? ''), self::number($node['activation'] ?? 0.0));
            $lines[] = self::indent(self::text($node['content'] ?? ''), 3);
            $lines[] = sprintf(
                '   weights: confidence %s; fundamental %s; rehearsal %s; recency %s; context %s',
                self::number($weights['confidence'] ?? 0.0),
                self::number($weights['fundamental_identity'] ?? 0.0),
                self::number($weights['independent_rehearsal'] ?? 0.0),
                self::number($weights['recency'] ?? 0.0),
                self::number($weights['context'] ?? 0.0)
            );
            $matched = self::strings($node['matched_terms'] ?? []);
            $lines[] = '   context terms: ' . ($matched === [] ? 'none' : implode(', ', $matched));
            $lines[] = '   independent support: ' . (int) ($node['independent_support_count'] ?? 0);
            $lines[] = '';
        }

        self::section($lines, 'CURRENT STATE', $result['current_state'] ?? []);
        self::section($lines, 'SOCIAL EVIDENCE', $result['social_evidence'] ?? []);
        self::section($lines, 'EVIDENCE HEALTH', $health);

        return rtrim(implode("\n", $lines));
    }

    /** @param array<string, mixed> $result */
    private static function motivation(array $result): string
    {
        $health = self::array($result['evidence_health'] ?? []);
        $lines = [
            'NAVI MOTIVATION COMPILE',
            'protocol: ' . self::text($result['protocol'] ?? ''),
            'context: ' . self::text($result['context'] ?? ''),
            'mode: read-only; action selection disconnected; complete ranked set',
            sprintf(
                'evidence: %d motives; %d active needs; %d open intentions; %d pending interrupts',
                (int) ($health['motive_count'] ?? 0),
                (int) ($health['active_need_count'] ?? 0),
                (int) ($health['open_intention_count'] ?? 0),
                (int) ($health['pending_interrupt_count'] ?? 0)
            ),
            '',
            'RANKED MOTIVES',
        ];

        foreach (self::list($result['ranked_motives'] ?? []) as $index => $motive) {
            $weights = self::array($motive['weights'] ?? []);
            $lines[] = sprintf(
                '%d. %s [%s] activation %s',
                (int) ($motive['rank'] ?? ($index + 1)),
                self::text($motive['title'] ?? $motive['motive_id'] ?? ''),
                self::text($motive['motive_type'] ?? ''),
                self::number($motive['activation'] ?? 0.0)
            );
            $lines[] = self::indent(self::text($motive['content'] ?? ''), 3);
            $lines[] = sprintf(
                '   weights: drive %s; authority %s; context %s; recency %s; investment %s; actionability %s',
                self::number($weights['drive'] ?? 0.0),
                self::number($weights['authority'] ?? 0.0),
                self::number($weights['context'] ?? 0.0),
                self::number($weights['recency'] ?? 0.0),
                self::number($weights['investment'] ?? 0.0),
                self::number($weights['actionability'] ?? 0.0)
            );
            $matched = self::strings($motive['matched_terms'] ?? []);
            $lines[] = '   context terms: ' . ($matched === [] ? 'none' : implode(', ', $matched));
            $lines[] = '   evidence: ' . PlainText::inline($motive['evidence'] ?? [], PHP_INT_MAX);
            $lines[] = '';
        }

        self::section($lines, 'AFFECTIVE STATE', $result['affective_state'] ?? []);
        self::section($lines, 'AUTHORITY CONSTRAINTS', $result['authority_constraints'] ?? []);
        self::section($lines, 'EVIDENCE HEALTH', $health);

        return rtrim(implode("\n", $lines));
    }

    /** @param array<string, mixed> $result */
    private static function intentions(array $result): string
    {
        $health = self::array($result['evidence_health'] ?? []);
        $lines = [
            'NAVI INTENTION COMPILE',
            'protocol: ' . self::text($result['protocol'] ?? ''),
            'mode: read-only; intention evidence only; ' . (isset($result['mode']['intention_id'])
                ? 'intention #' . (int) $result['mode']['intention_id'] : 'complete open set'),
            sprintf(
                'evidence: %d open; %d active; %d blocked; %d dependency-ready; %d dependency-blocked',
                (int) ($health['open_intention_count'] ?? 0),
                (int) ($health['active_intention_count'] ?? 0),
                (int) ($health['blocked_intention_count'] ?? 0),
                (int) ($health['ready_intention_count'] ?? 0),
                (int) ($health['dependency_blocked_count'] ?? 0)
            ),
            '',
            'OPEN INTENTIONS',
        ];

        foreach (self::list($result['open_intentions'] ?? []) as $intention) {
            $lines[] = sprintf('%d. %s [%s; %s]', (int) ($intention['id'] ?? 0), self::text($intention['title'] ?? ''), self::text($intention['authority'] ?? ''), self::text($intention['status'] ?? ''));
            $lines[] = '   reason: ' . self::text($intention['reason'] ?? '');
            $lines[] = '   next action: ' . self::text($intention['next_action'] ?? '');
            $lines[] = '   success condition: ' . self::text($intention['success_condition'] ?? '');
            $lines[] = '   release condition: ' . self::text($intention['release_condition'] ?? '');
            $lines[] = '   parent: ' . PlainText::inline($intention['parent'] ?? null, PHP_INT_MAX);
            $lines[] = '   dependencies: ' . PlainText::inline($intention['dependencies'] ?? [], PHP_INT_MAX);
            $lines[] = '   dependency ready: '
                . (($intention['dependency_ready'] ?? false) ? 'yes' : 'no');
            $lines[] = '   actions: ' . PlainText::inline($intention['action_evidence'] ?? [], PHP_INT_MAX);
            $lines[] = '   decisions: ' . PlainText::inline($intention['decision_evidence'] ?? [], PHP_INT_MAX);
            $lines[] = sprintf('   created: %s; updated: %s', self::text($intention['created_at'] ?? ''), self::text($intention['updated_at'] ?? ''));
            $lines[] = '';
        }

        self::section($lines, 'EVIDENCE HEALTH', $health);

        return rtrim(implode("\n", $lines));
    }

    /** @param list<string> $lines */
    private static function section(array &$lines, string $title, mixed $value): void
    {
        $lines[] = '';
        $lines[] = $title;
        $lines[] = PlainText::render($value, PHP_INT_MAX, PHP_INT_MAX);
    }

    /** @return array<string, mixed> */
    private static function array(mixed $value): array
    {
        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    /** @return list<array<string, mixed>> */
    private static function list(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        return array_values(array_filter($value, 'is_array'));
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        return array_values(array_map( static fn (mixed $item): string => self::text($item), $value ));
    }

    private static function text(mixed $value): string
    {
        $text = trim(PlainText::sanitize((string) $value));
        return $text === '' ? 'none' : $text;
    }

    private static function number(mixed $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', (float) $value), '0'), '.');
    }

    private static function indent(string $text, int $spaces): string
    {
        $prefix = str_repeat(' ', $spaces);
        return $prefix . str_replace("\n", "\n" . $prefix, $text);
    }
}
