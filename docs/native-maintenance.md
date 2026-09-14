# Deterministic background maintenance

The local-only profile is `native-maintenance`. [config/cognition.php](../config/cognition.php) currently selects `dream-cycle` with the free Muse lane; its private-memory gate remains disabled pending explicit transmission approval. The existing [model-worker launcher](../bin/navi-brain-model-worker) selects [NativeMaintenance](../src/Core/NativeMaintenance.php) for that profile. Routine maintenance does not construct an inference client, invoke an inference CLI, discover providers, or consume a provider allowance.

This lane maintains existing executive state: it checks a bounded set of working-memory projections against their backing records and performs bounded recovery of abandoned decision owners. These are deterministic repairs of stored state. It does not generate new semantic claims, reinterpret memories with a model, rehearse memories, or consume queued model-assisted consolidation proposals. Model-assisted work remains an optional, separately selected capability.

## Configuration

```php
'profile' => 'native-maintenance',
'native' => [
    'interval_seconds' => 30,
    'error_backoff_seconds' => 60,
    'slot_limit' => 32,
    'recovery_limit' => 16,
],
```

| Setting | Default | Accepted values | Effect |
|---|---|---|---|
| `interval_seconds` | 30 | Integer from 1 through 3600 | Delay after a normal native pass, including an idle pass. |
| `error_backoff_seconds` | 60 | Integer from 1 through 3600, at least the normal interval | Delay after an exception or a returned `backoff`, `error`, or `failed` status. |
| `slot_limit` | 32 | Integer from 1 through 128 | Maximum working-memory slots selected for a maintenance pass. |
| `recovery_limit` | 16 | Integer from 1 through 128 | Maximum candidates in each planning, integration and unattempted-action sweep; up to three times this number per pass. |

The worker validates the profile and these bounds before bootstrapping the database. Missing native settings receive the defaults; noninteger or out-of-range values fail startup. An unknown profile fails closed. `disabled` exits before database bootstrap.

The interval is a delay after each pass finishes, so a slow pass does not trigger catch-up iterations. Row limits bound the selected work; they are not a wall-time guarantee. The loop handles termination signals and sleeps in one-second increments. Repeated idle status lines are suppressed; maintenance changes and errors are reported as JSON records with the selected profile and owner.

Maintenance continues while cognition is paused because expiry, journal recovery, and correction of derived state are maintenance operations. Pause still fences new cognitive action admission. Recovering abandoned ownership does not authorize automatic repetition of an uncertain external action.

Slot traversal uses two independently wrapping ID cursors persisted in `workspace_maintenance_scan`. A short SQLite transaction selects both disjoint pages and saves their positions before any source reads or native writes. Active slots and pending journals receive up to `slot_limit - 1` places; the remaining allowance audits settled inactive slots for native drift. At a limit of one, the durable phase alternates queues with an empty-queue fallback, including across `--once` invocations. The total visited never exceeds `slot_limit`; partial indexes keep settled history out of the priority scan. `priority_after_id` and `audit_after_id` expose those cursors, while `after_id` remains the last visited ID. A crash after selection can postpone that page until wraparound; cursor advancement does not attest successful repair, and continuing append does not provide a strict latency bound.

The worker defers slots that have no `projection_memory_id`, including a crash before a new slot acquired its first projection pointer. The existing identity recovery path can scan the working-memory corpus, so this bounded worker does not invoke it. The `deferred_legacy` count includes these pointerless cases; it does not mean all such rows necessarily predate the current journal. Linked rows can replay their pending projection journal before freshness checks.

Native projection writes share a scope/role file lock across replay and legacy expiry. A replayer rereads the canonical row after obtaining ownership, preventing an old journal from overwriting a newer native projection. Background acquisition is nonblocking; foreground acquisition waits at most five seconds. The protocol requires cooperating processes using the same private lock directory in one checkout on local storage. Running old PHP workers or separate checkouts do not acquire the same locks. Source inspection and SQLite are separate stores, so repairs are eventual; decision and prompt hydration retain their independent freshness checks. See [typed workspace references](working-memory-references.md).

The native pass also completes exact settled decision actions that were never dispatched. It can append their idempotent episodic refusal records and finish cycle bookkeeping; it does not generate semantic claims, learn procedures, run adapters, or enter general dispatch recovery. Independent durable scan cursors keep repeated `--once` invocations moving beyond the first page. See [decision recovery](decision-recovery.md).

On its first pass and then 5400 seconds after each completed reuse sweep, native maintenance also reuses existing semantic claims for exact repeated episodes. It ingests up to 100 active episodes through the existing ascending cursor, then scans active semantic claims and unassigned pending/rejected ledger entries in 200-record pages. This sweep is linear in those collections: the usual slot/recovery row limits do not bound it. The timer is local to the worker; restarting or repeated `--once` calls can repeat a sweep, which is idempotent. At the September 8 audit corpus size, a no-change replay of the CLI repair plus its status query took about 0.11 seconds. This is not a growth-independent latency guarantee.

Reuse requires a direct source-memory reference, active and unexpired source/claim/target, the existing confidence floor and grounding validator, and byte-identical **complete** episode text. A hash locates candidates; exact comparison and fresh neutral source reads confirm the match. Queued/leased work is left alone. Each conditional ledger update and its previous-state audit event commit together. No native memory, confidence, source timestamp, or claim text changes. This records existing coverage, not new corroboration; the lexical grounding validator is still not a semantic entailment proof. Native observations and SQLite commits are separate stores, so arbitrary concurrent native changes are not atomically fenced by this repair.

`php bin/navi-brain memory:consolidation:reuse` previews matches; `--apply` performs the repair and bounded ingestion. Both make zero inference calls. Novel evidence remains for explicit semantic consolidation or an authorized model lane. See [the live audit](memory-consolidation-audit-2026-09-08.md).

A pass reports `failed` if a slot reports an error or decision/action recovery reports a blocked record, which activates the error backoff. It reports `idle` only when it visits no slots, returns no decision or action recovery results, and reuses no consolidation claims. Cursor bookkeeping alone does not count as a completed recovery outcome. An unchanged canonical row can still repair its native projection, so a pass that visits rows reports `completed` rather than claiming it made no changes.

## Explicit model profiles

`dream-cycle` selects the [sparse dream coordinator](dream-consolidation.md), which retains native upkeep and adds explicitly admitted cheap-model work. Its private-memory permission remains false by default. `public-reflection` and `codex-spark` remain separate explicit profile choices. The existing flat `model`, `sources`, `public_revision`, and `question` values are retained for the public reflection pilot; the native branch does not use them. Selecting `public-reflection` invokes that pilot's public-source allowlist and proposal review behavior. Selecting `codex-spark` invokes its existing worker and inference route; native and dream profiles do not fall back to it.

Separate manual inference entrypoints, including the [synthetic Groq pilot](groq-synthetic-pilot.md), remain explicit operator actions. Changing the default profile neither starts a local model server nor invokes those entrypoints. The native profile does not fall back to a model when maintenance fails.

The launcher selects its worker class when the process starts. The existing public reflection class also rereads configuration on each pass and returns `disabled` when its profile is no longer selected; it cannot turn itself into the native worker. This change does not install or restart a service or replace an existing worker process. Deployment and service changes are separate operator actions.

`bin/navi-brain-model-worker --help` exits before configuration or database bootstrap. `--once` performs one pass of the configured profile and exits; it is an actual operation, not a dry run. Unknown arguments fail before bootstrap. Startup still performs normal executive initialization, including schema checks, model registration as metadata, and a possible first-time historical baseline capture. Initialization makes no inference request, but its work is not included in the per-pass row bounds.

`bin/navi-brain-model-worker --status` validates the selected configuration and prints a JSON snapshot before application bootstrap, including when the profile is disabled. It reports the profile, configuration-only privacy policy and the shared OpenCode quota file's recorded attempt count, cap, cooldown and next-cycle time. It creates no directories, files or locks, constructs no worker, reads no credentials, and makes no database, native or catalogue request. The fixed quota file is read without locking, with a 16 KiB content cap and shallow JSON validation. Missing or inaccessible state reports `missing_or_unreadable` with unknown due status; it is not evidence of an unused allowance. Valid or missing/inaccessible snapshots exit zero; explicit malformed, changed-file or read failures exit one. Consumers must inspect the JSON state rather than interpret process success as provider admission.

The snapshot does not check durable pause, ready evidence, catalogue availability or current lock ownership. `new_cycle_due` describes only the observed file's timing, and a recorded second slot does not establish continuation entitlement in a different process. The recorded window count remains visible after expiry; it is not a lifetime usage total. Byte limits do not guarantee filesystem latency. See the [transport budget](opencode-dream-transport.md) for reservation and continuation rules.

## Verification limits

Advisory activity publication reuses one nonblocking Unix datagram socket per event and drops undeliverable packets without retrying. A stalled listener cannot make a send wait for queue capacity. Recipient discovery still enumerates every matching `activity-*.sock` path, so the per-pass row limits do not bound subscriber-count work or filesystem enumeration time.

The configuration and launcher were syntax checked and their branch selection reviewed from source. No worker, service, inference route, or live database operation was run as part of this change. Runtime checks and test infrastructure remain subject to the pending explicit test approval. No inference cost or maintenance latency was measured.
