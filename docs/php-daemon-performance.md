# PHP daemon CPU investigation

Date: 2026-08-18

## Outcome

The sustained PHP CPU load came from two Navi-Brain OpenRC services:

- `navi-brain-senses`
- `navi-brain-heartbeat`

Both services are stopped and removed from the `default` runlevel while this
is investigated. The Navi-Brain MCP servers and `navi-brain-local-model` were
left running.

Current service state after the change:

| Service | Runtime | Default runlevel |
| --- | --- | --- |
| `navi-brain-senses` | stopped | absent |
| `navi-brain-heartbeat` | stopped | absent |
| `navi-brain-local-model` | started | unchanged |

Re-enable the stopped services deliberately with:

```sh
pkexec rc-update add navi-brain-senses default
pkexec rc-update add navi-brain-heartbeat default
pkexec rc-service navi-brain-senses start
pkexec rc-service navi-brain-heartbeat start
```

## Observed processes

Before stopping the services:

| PID | Command | Owner |
| ---: | --- | --- |
| 1392685 | `php bin/navi-brain-senses` | OpenRC `navi-brain-senses` |
| 1414190 | `php bin/navi-brain-heartbeat` | Rust continuity supervisor under OpenRC `navi-brain-heartbeat` |
| 1410967 | `php bin/navi-brain-mcp` | Codex app-server |
| 208405 | `php bin/navi-brain-mcp` | Codex app-server |
| 260595 | `llama-server ... --port 5007` | OpenRC `navi-brain-local-model` |

A five-second live sample showed:

- `navi-brain-senses`: approximately 50% of one CPU continuously.
- `navi-brain-heartbeat`: normally sleeping, with bursts from approximately
  79% to 100% of one CPU.
- both MCP processes: 0% during the sample.
- `llama-server`: 0% during the sample.

The high lifetime percentages shown by `ps` were not all current load. In
particular, the MCP process that had recently handled a full `brain_sleep`
call retained a high lifetime average while being idle in live sampling.

## Are the PHP daemons running a local LLM?

No.

- The heartbeat source explicitly keeps inference out of the scheduler process
  and only queues work for dedicated workers.
- The sensory daemon polls sources, normalizes observations, and writes the
  SQLite state store. It does not initialize or invoke a model.
- The PHP process trees contained no model child.
- No TCP connection to the local model's port `5007` was present during the
  investigation.
- The separate `llama-server` was alive but idle at 0% live CPU.

The local model service therefore was not the cause of the measured PHP CPU
load.

## Native profiler evidence

`perf` profiles show that both hot PHP processes spent almost all sampled CPU
inside SQLite:

- heartbeat: at least 87.5% of sampled cycles in `libsqlite3`;
- senses: at least 96.5% of sampled cycles in `libsqlite3`.

A six-second `strace -c` sample of the sensory daemon recorded:

| System call | Calls |
| --- | ---: |
| `pread64` | 14,374 |
| `pwrite64` | 268 |
| `fcntl` | 476 |

Those calls consumed only about 9.7 ms of syscall time. The CPU cost was
therefore SQLite userspace query and B-tree work, not blocked disk I/O and not
model inference.

The live SQLite database was 462,845 pages at 4,096 bytes per page:
1,895,813,120 bytes, approximately 1.77 GiB. Relevant row counts were:

| Table | Rows |
| --- | ---: |
| `events` | 1,598,760 |
| `cycle_runs` | 31,977 |
| `work_items` | 9,258 |
| `memories` | 6,830 |
| `memory_consolidation_episodes` | 6,170 |
| `thread_steps` | 3,895 |
| `sense_events` | 2,141 |
| `sense_readings` | 209 |

## What the heartbeat does

Every scheduler pass executes:

1. a safety check;
2. interrupt handling, which invokes the safety check again;
3. due-rhythm recovery and dispatch;
4. consolidation-queue maintenance;
5. due cognitive-thread handling;
6. a five-second sleep.

The heartbeat log was emitting approximately every 10 to 11 seconds despite
the configured five-second sleep, showing that a nominally idle pass was taking
roughly another five seconds of work.

The strongest code-level candidate is
`ExecutiveCore::maintainConsolidationQueue()`, which runs on every pass. Its
steady-state path repeatedly:

- loads all consolidation ledger rows;
- synchronizes the ledger against active episodic and semantic memories;
- loads all memory-consolidation work items to count active work;
- loads the full ledger again to count statuses.

`activeConsolidationWorkCount()` filters by `work_type`, but the
`work_items` schema has no index beginning with `work_type`. The method is
also called more than once per maintenance pass. The result is repeated
full-table ORM hydration and SQLite scanning even when the returned state is
`steady`.

Separately, `runSafetyCheck()` performs `PRAGMA quick_check` every 900
seconds. On a 1.77 GiB database this is expected to create a large periodic
spike, although it does not explain every steady-state heartbeat pass.

## What the sensory daemon does

The sensory daemon runs a one-second loop with these cadences:

| Work | Cadence |
| --- | ---: |
| Pet transcript polling | 1 second |
| pending machine-inspection drain | 10 seconds |
| local signal sampling | 20 seconds |
| desktop presence | 30 seconds |
| silence, health, and time sampling | 60 seconds |
| compaction, decay, tuning, and social maintenance | 300 seconds |

Observations are passed through `SensoryCortex::ingest()` and persisted in the
same SQLite database. Pending machine inspections also query recent
`sense_readings` and `events` before deciding whether work remains.

The native profile proves the continuous cost is SQLite execution. It does not
contain PHP-level symbols precise enough to attribute every sampled cycle to
one PHP method. The next diagnostic should add per-stage monotonic timings
around the sensory loop on a copied database, especially:

- transcript fetch and each `SensoryCortex::ingest()`;
- `claimPendingLooks()`;
- each `SignalSampler` source;
- the 300-second maintenance stages.

## Repair direction

Do not re-enable the daemons unchanged. First:

1. Measure each heartbeat and sensory stage independently against a database
   snapshot.
2. Replace full-row ORM scans used only for counts or existence checks with
   indexed aggregate queries.
3. Add an index supporting consolidation work lookup by `work_type` and
   status after confirming the exact query plan.
4. Run consolidation synchronization only when episodic/semantic memory state
   changes, not every five-second scheduler pass.
5. Move or amortize database-wide `quick_check` so it cannot monopolize a
   scheduler pass.
6. Re-enable one daemon at a time and require a sustained live CPU measurement
   before restoring both to the default runlevel.

No repository implementation was changed during this investigation; only this
document was added and the two OpenRC services were stopped/disabled.
