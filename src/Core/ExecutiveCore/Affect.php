<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use InvalidArgumentException;
use NaviBrain\Core\EmotionalAppraisal;
use NaviBrain\Model\Appraisal;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\Event;
use NaviBrain\Model\ExecutiveInterrupt;
use NaviBrain\Model\ModelEndpoint;
use NaviBrain\Model\SenseEvent;
use NaviBrain\Model\UtteranceOutcome;
use NaviBrain\Model\WorkItem;

class Affect extends Component
{
    public function appraiseNow(int $now): EmotionalAppraisal
    {
        $cortex = $this->sensoryCortex();
        $pending = $cortex->pendingEvents(8);

        $salience = 0.0;
        foreach (SenseEvent::getAllByWhere([], ['order' => ['id' => 'DESC'], 'limit' => 40]) as $event) {
            $observedAt = $this->timestamp($event->observed_at);
            if ($observedAt === null || ($now - $observedAt) > Executive::AFFECT_WINDOW_SECONDS) {
                continue;
            }
            $salience += (float) $event->significance;
        }
        $relevance = min(1.0, $salience / 3.0);

        $addressed = false;
        foreach ($pending as $event) {
            $addressed = $addressed || ($event['addressed'] ?? false) === true;
        }

        $expectedness = $this->meanPredictability();

        $done = 0;
        $failed = 0;
        foreach (WorkItem::getAllByWhere([], ['order' => ['id' => 'DESC'], 'limit' => 40]) as $item) {
            if ($item->status === 'completed') {
                $done++;
            } elseif ($item->status === 'failed') {
                $failed++;
            }
        }
        $judged = $done + $failed;
        $successRate = $judged === 0 ? 0.5 : $done / $judged;
        $desirability = ($successRate - 0.5) * 2.0;

        $modelReady = false;
        foreach (ModelEndpoint::getAll() as $endpoint) {
            $cooldown = $this->timestamp($endpoint->cooldown_until);
            if ($endpoint->status === 'available' && ($cooldown === null || $cooldown <= $now)) {
                $modelReady = true;
                break;
            }
        }
        $control = ($successRate * 0.6) + ($modelReady ? 0.4 : 0.0);

        $urgency = $addressed ? 0.9 : 0.0;
        foreach (ExecutiveInterrupt::getAllByWhere(['status' => 'pending'], ['limit' => 5]) as $interrupt) {
            $urgency = max($urgency, $interrupt->severity === 'critical' ? 1.0 : 0.6);
        }

        $standing = 0.5;

        return EmotionalAppraisal::from(
            [
                'relevance' => $relevance,
                'desirability' => $desirability,
                'expectedness' => $expectedness,
                'control' => $control,
                'urgency' => $urgency,
                'standing' => $standing,
            ],
            $this->lastMood(),
            $this->spokenWordRange()
        );
    }

    public function randomUnit(): float
    {
        return random_int(0, PHP_INT_MAX - 1) / (PHP_INT_MAX - 1);
    }

    public function meanPredictability(): float
    {
        $scores = $this->sensoryCortex()->predictabilityByRecentSense();
        if ($scores === []) {
            return 0.5;
        }
        return array_sum($scores) / count($scores);
    }

    public function lastMood(): ?array
    {
        $event = Event::getByWhere(['kind' => 'affect.appraised'], ['order' => ['id' => 'DESC']]);
        if (!$event instanceof Event || !is_array($event->payload)) {
            return null;
        }
        $mood = $event->payload['mood'] ?? null;
        if (!is_array($mood) || !isset($mood['valence'], $mood['arousal'])) {
            return null;
        }
        return ['valence' => (float) $mood['valence'], 'arousal' => (float) $mood['arousal']];
    }

    public function tooSimilarToRecent(string $content): bool
    {
        $words = static function (string $text): array {
            $stop = array_flip(['the', 'a', 'an', 'and', 'but', 'or', 'is', 'it', 'to', 'of',
                'in', 'on', 'at', 'with', 'you', 'your', 'i', 'im', 'my', 'me', 'that', 'this',
                'was', 'been', 'still', 'just', 'so', 'for', 'right', 'here', 's', 't']);
            $out = [];
            foreach (preg_split('/[^a-z0-9]+/', mb_strtolower($text)) ?: [] as $word) {
                if ($word !== '' && mb_strlen($word) > 2 && !isset($stop[$word])) {
                    $out[$word] = true;
                }
            }
            return $out;
        };

        $candidate = $words($content);
        if (count($candidate) < 3) {
            return false;
        }

        $since = time() - Executive::REPEAT_WINDOW_SECONDS;
        foreach (UtteranceOutcome::getAllByWhere([], ['order' => ['id' => 'DESC'], 'limit' => 12]) as $past) {
            $spokenAt = $this->timestamp($past->created_at);
            if ($spokenAt === null || $spokenAt < $since) {
                continue;
            }
            $previous = $words((string) $past->utterance);
            if ($previous === []) {
                continue;
            }
            $shared = count(array_intersect_key($candidate, $previous));
            $overlap = $shared / min(count($candidate), count($previous));
            if ($overlap >= Executive::REPEAT_OVERLAP) {
                return true;
            }
        }
        return false;
    }

    public function isEvidence(string $content): bool
    {
        foreach (['Said aloud:', 'Inner monologue:', 'Said privately:', 'Spoke to herself:'] as $echo) {
            if (str_starts_with($content, $echo)) {
                return false;
            }
        }
        return true;
    }

    public function appraise(Appraisal $appraisal): array
    {
        if ($appraisal->event_id === null && $appraisal->intention_id === null) {
            throw new InvalidArgumentException('An appraisal requires an event or intention.');
        }
        if ($appraisal->event_id !== null) {
            $this->requireEvent($appraisal->event_id);
        }
        if ($appraisal->intention_id !== null) {
            $this->requireIntention($appraisal->intention_id);
        }

        foreach ([
            'relevance' => $appraisal->relevance,
            'urgency' => $appraisal->urgency,
            'controllability' => $appraisal->controllability,
            'uncertainty' => $appraisal->uncertainty,
            'commitment impact' => $appraisal->commitment_impact,
        ] as $name => $value) {
            $this->requireUnitInterval($value, $name);
        }

        $attentionScore = round(($appraisal->relevance + $appraisal->urgency + $appraisal->uncertainty + $appraisal->commitment_impact) / 4, 3);

        $appraisal->attention_score = $attentionScore;
        $appraisal->save();

        return $appraisal->getData();
    }

    public function spokenWordRange(?CognitiveThread $thread = null): array
    {
        $thread ??= CognitiveThread::getByField('thread_key', Executive::SELF_PRESENCE_THREAD_KEY);
        $shortest = $thread instanceof CognitiveThread
            ? $this->threadBudgetInt($thread, 'turn_seconds_min', 4)
            : 4;
        $longest = $thread instanceof CognitiveThread
            ? $this->threadBudgetInt($thread, 'turn_seconds_max', 120)
            : 120;

        $rate = $this->measuredSpeakingRate();
        $floor = max(3, (int) round($shortest * $rate));
        $ceiling = max($floor + 1, (int) round($longest * $rate));
        return [$floor, $ceiling];
    }

    public function measuredSpeakingRate(): float
    {
        $words = 0;
        $seconds = 0.0;
        foreach (Event::getAllByWhere(['kind' => 'speech.timed'], ['order' => ['id' => 'DESC'], 'limit' => 40]) as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $words += (int) ($payload['words'] ?? 0);
            $seconds += (float) ($payload['duration_seconds'] ?? 0.0);
        }
        if ($seconds <= 0.0 || $words <= 0) {
            return Executive::NOMINAL_SPEAKING_RATE;
        }
        return max(0.5, min(6.0, $words / $seconds));
    }
}
