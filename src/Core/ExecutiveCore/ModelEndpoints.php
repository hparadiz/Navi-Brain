<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use InvalidArgumentException;
use NaviBrain\Model\ModelEndpoint;
use RuntimeException;

class ModelEndpoints extends Component
{
    public function syncFreeModels(array $modelIds): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(static fn (mixed $model): string => is_string($model) ? trim($model) : '', $modelIds), static fn (string $model): bool => preg_match('~\Aopencode/[a-z0-9][a-z0-9._-]*-free\z~', $model) === 1)));

        $now = time();
        foreach ($normalized as $modelId) {
            [$provider] = array_pad(explode('/', $modelId, 2), 2, 'unknown');
            $endpoint = ModelEndpoint::getByField('model_id', $modelId);
            if (!$endpoint instanceof ModelEndpoint) {

                $endpoint = new ModelEndpoint([
                    'model_id' => $modelId,
                    'provider' => $provider,
                    'status' => 'discovered',
                    'last_discovered_at' => $now,
                    'consecutive_failures' => 0,
                    'updated_at' => $now,
                ], true, true);
                $endpoint->save();
            } else {
                $fields = ['last_discovered_at' => $now, 'updated_at' => $now];
                if ($endpoint->last_error === 'Model disappeared from the latest free-model catalogue.') {
                    $fields['status'] = 'discovered';
                    $fields['last_error'] = null;
                }
                $endpoint->setFields($fields);
                $endpoint->save();
            }
        }

        foreach (ModelEndpoint::getAll() as $endpoint) {

            if (in_array($endpoint->provider, [ Executive::LOCAL_MODEL_PROVIDER, Executive::CODEX_MODEL_PROVIDER, ], true)) {
                continue;
            }
            if (!in_array($endpoint->model_id, $normalized, true)) {
                $endpoint->setFields([ 'status' => 'unavailable', 'cooldown_until' => null, 'updated_at' => $now, 'last_error' => 'Model disappeared from the latest free-model catalogue.', ]);
                $endpoint->save();
            }
        }

        $event = $this->emit('models.discovered', [ 'free_model_ids' => $normalized, 'count' => count($normalized), ]);
        return ['models' => $this->listModelEndpoints(), 'event' => $event->getData()];
    }

    public function listModelEndpoints(): array
    {
        return $this->records(ModelEndpoint::getAll([ 'order' => ['consecutive_failures' => 'ASC', 'latency_ms' => 'ASC'], ]));
    }

    public function selectableFreeModels(): array
    {
        $now = time();
        $candidates = array_values(array_filter(
            ModelEndpoint::getAll(),
            function (ModelEndpoint $endpoint) use ($now): bool {

                if (preg_match('~\Aopencode/[a-z0-9][a-z0-9._-]*-free\z~', (string) $endpoint->model_id) !== 1) {
                    return false;
                }
                $cooldown = $this->timestamp($endpoint->cooldown_until);
                if ($endpoint->status === 'unavailable' && $cooldown === null) {
                    return false;
                }
                return $cooldown === null || $cooldown <= $now;
            }
        ));
        usort($candidates, static function (ModelEndpoint $left, ModelEndpoint $right): int {
            $leftLatency = $left->latency_ms === null ? PHP_INT_MAX : (int) $left->latency_ms;
            $rightLatency = $right->latency_ms === null ? PHP_INT_MAX : (int) $right->latency_ms;
            return (int) $left->consecutive_failures <=> (int) $right->consecutive_failures
                ?: $leftLatency <=> $rightLatency;
        });
        return array_values(array_map( static fn (ModelEndpoint $endpoint): string => (string) $endpoint->model_id, $candidates ));
    }

    public function registerLocalModel(string $modelId): array
    {
        return $this->registerManagedModel($modelId, Executive::LOCAL_MODEL_PROVIDER, 'models.local.registered');
    }

    public function registerCodexModel(string $modelId): array
    {
        return $this->registerManagedModel($modelId, Executive::CODEX_MODEL_PROVIDER, 'models.codex.registered');
    }

    public function registerManagedModel(string $modelId, string $provider, string $eventKind): array
    {
        $this->requireText($modelId, 'model id');
        $endpoint = ModelEndpoint::getByField('model_id', $modelId);
        $now = time();
        if ($endpoint instanceof ModelEndpoint) {
            $endpoint->setFields([ 'provider' => $provider, 'last_discovered_at' => $now, 'updated_at' => $now, ]);
            $endpoint->save();
            return $endpoint->getData();
        }

        $endpoint = new ModelEndpoint([
            'model_id' => $modelId,
            'provider' => $provider,
            'status' => 'discovered',
            'last_discovered_at' => $now,
            'consecutive_failures' => 0,
            'updated_at' => $now,
        ], true, true);
        $endpoint->save();
        $this->emit($eventKind, [ 'model_id' => $modelId, 'provider' => $provider, ]);
        return $endpoint->getData();
    }

    public function recordLocalModelUnavailable(string $modelId, string $reason): array
    {
        $this->registerLocalModel($modelId);
        return $this->recordModelResult($modelId, false, 0, $reason);
    }

    public function localModelCooldownRemaining(string $modelId): ?int
    {
        return $this->modelCooldownRemaining($modelId);
    }

    public function modelCooldownRemaining(string $modelId): ?int
    {
        $endpoint = ModelEndpoint::getByField('model_id', $modelId);
        if (!$endpoint instanceof ModelEndpoint) {
            return null;
        }
        $cooldown = $this->timestamp($endpoint->cooldown_until);
        if ($cooldown === null) {
            return null;
        }
        $remaining = $cooldown - time();
        return $remaining > 0 ? $remaining : null;
    }

    public function resetLocalModelBackoff(string $modelId, string $reason): array
    {
        $this->requireText($modelId, 'model id');
        $this->requireText($reason, 'reset reason');
        $endpoint = ModelEndpoint::getByField('model_id', $modelId);
        if (!$endpoint instanceof ModelEndpoint || $endpoint->provider !== Executive::LOCAL_MODEL_PROVIDER) {
            throw new InvalidArgumentException('Only the registered local model backoff can be reset here.');
        }
        $now = time();
        $previousFailures = (int) $endpoint->consecutive_failures;
        $endpoint->setFields([ 'status' => 'discovered', 'consecutive_failures' => 0, 'cooldown_until' => null, 'last_error' => null, 'updated_at' => $now, ]);
        $endpoint->save();
        $event = $this->emit('models.local.backoff_reset', [ 'model_id' => $modelId, 'previous_consecutive_failures' => $previousFailures, 'reason' => $reason, ]);
        return ['endpoint' => $endpoint->getData(), 'event' => $event->getData()];
    }

    public function recordModelResult(string $modelId, bool $succeeded, int $latencyMs, ?string $error = null): array
    {
        $this->requireText($modelId, 'model id');
        if ($latencyMs < 0) {
            throw new InvalidArgumentException('model latency cannot be negative.');
        }
        $endpoint = ModelEndpoint::getByField('model_id', $modelId);
        if (!$endpoint instanceof ModelEndpoint) {
            throw new RuntimeException(sprintf('Model %s is not in the registered model pool.', $modelId));
        }

        $now = time();
        $failures = $succeeded ? 0 : (int) $endpoint->consecutive_failures + 1;
        $cooldown = $succeeded ? null : $now + min(3600, 30 * (2 ** min($failures, 7)));
        $endpoint->setFields([
            'status' => $succeeded ? 'available' : ($failures >= 3 ? 'unavailable' : 'degraded'),
            'last_probed_at' => $now,
            'last_success_at' => $succeeded ? $now : $endpoint->last_success_at,
            'consecutive_failures' => $failures,
            'latency_ms' => $latencyMs,
            'cooldown_until' => $cooldown,
            'last_error' => $succeeded ? null : $error,
            'updated_at' => $now,
        ]);
        $endpoint->save();
        $event = $this->emit($succeeded ? 'model.available' : 'model.failed', [
            'model_id' => $endpoint->model_id,
            'latency_ms' => $latencyMs,
            'consecutive_failures' => $failures,
            'cooldown_until' => $cooldown,
            'error' => $error,
        ]);
        return ['model' => $endpoint->getData(), 'event' => $event->getData()];
    }
}
