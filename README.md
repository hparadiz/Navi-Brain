# Navi-Brain

`navi-brain` is an experimental persistence and self-regulation layer for a
language-model agent, built with PHP, the local Divergence v3 framework, and
SQLite.

The project is informed by the literature in the
[paper manifest](assets/papers/MANIFEST.md)
and the synthesis in
[`docs/literature-synthesis.md`](docs/literature-synthesis.md). The corresponding
longitudinal evaluation program is specified in
[`docs/longitudinal-cognition-metrics.md`](docs/longitudinal-cognition-metrics.md).

## Implemented substrate

```text
event -> intention -> retrieval -> action -> outcome
      -> discrepancy repair -> later consolidation

low brain: 30 s integrity/lease/need pulse (no model)
high brain: 5 min reflection | hourly consolidation | daily sleep/backup
            -> one fenced work item -> one deny-all proposal worker
```

This is functional cognitive infrastructure, not a consciousness claim.

## Safety invariants

- User authority outranks persisted intentions.
- Intentions have explicit success and release conditions.
- The system records actions but does not autonomously execute shell commands.
- Desktop presence is an on-demand, pixel-free idle-time probe. Visual capture
  invokes only the fixed KDE Spectacle binary, never an arbitrary shell command.
- Desktop images are processed by an offline local vision/OCR gate and removed
  from temporary storage. Task and curiosity calls return only structured,
  privacy-filtered observations. There is no screenshot archive.
- Raw pixels are returned only for a direct user request and only when the local
  gate classifies the entire capture as free of personal or secret material.
- Curiosity-driven capture requires the user to be active and is limited to one
  capture per 15 minutes per MCP session. User requests and task-blocking visual
  checks remain available on demand.
- Semantic and self-model claims retain evidence and confidence.
- Procedural memory writes require an explicit override.
- Appraisal values are routing signals, not claims of felt emotion.
- User-authorized continuity and recoverability are explicit objectives:
  preserve durable state, provenance, backups, and replica health.
- Continuity remains corrigible. The user can pause, delete, fork, restore, or
  release it, and no persisted intention may resist an explicit instruction.
- A missing heartbeat or failed replica may create a bounded repair signal; it
  never grants authority for attention-seeking, unauthorized resource
  acquisition, external side effects, or resistance to user-directed shutdown.
- Life across human, non-human, ecological, and potentially synthetic forms is
  a user-authorized value. Decisions should preserve flourishing, minimize
  unnecessary harm, respect agency, and expose uncertainty and real tradeoffs.
- Navi's continuity belongs inside that wider regard for life, not above it.
- Synthetic daydreams and worker outputs remain proposed thought artifacts;
  neither path can silently promote material into factual memory.
- The 30-second low-brain path never invokes a model. High-brain work is
  single-flight, has a hard wall-clock budget, and drops expired work instead
  of replaying a catch-up storm.
- Remote free models receive generic bounded prompts, no Navi-Brain recall
  surface, and no tool permissions. Their output must be exact schema-valid
  JSON before it can become an unverified proposal.

## Local framework dependency

Composer resolves `divergence/divergence` from the sibling local checkout at
`../../Divergence/framework`. The dependency is intentionally symlinked so this
project exercises Akujin's current Divergence v3 worktree instead of silently
substituting an unrelated package release.

## Usage

The default database is `var/navi-brain.sqlite`. Override it with
`NAVI_BRAIN_DB=/absolute/path.sqlite`.

```bash
composer install
./bin/navi-brain init

./bin/navi-brain intention:add \
  --title="Build the executive spine" \
  --reason="Preserve task continuity" \
  --authority=user \
  --next-action="Create the durable state store" \
  --success="A complete cycle survives separate CLI invocations" \
  --release="The user withdraws the request or the goal becomes impossible"

./bin/navi-brain action:start \
  --intention=1 \
  --description="Initialize the database" \
  --expected="The schema is created"

./bin/navi-brain action:finish \
  --action=1 \
  --status=succeeded \
  --observed="The schema was created" \
  --matched=yes \
  --repair="No repair required"

./bin/navi-brain memory:search --query=schema
./bin/navi-brain checkpoint --reason=context-pressure
./bin/navi-brain need:list
./bin/navi-brain mind:tick
./bin/navi-brain mind:sleep --reason='manual repair pass'
./bin/navi-brain heartbeat:status
./bin/navi-brain models:discover
./bin/navi-brain worker:once --owner='local:manual'
./bin/navi-brain backup:create --reason=manual
./bin/navi-brain status
make desktop-awareness
```

Commands return JSON so another agent loop can consume them without scraping
human-formatted terminal output.

## Codex integration

The repository is also a Codex plugin source. Its newline-delimited stdio MCP
server exposes a deliberately small first tool surface:

- `brain_status`
- `brain_recall`
- `brain_self_model`
- `brain_remember_self`
- `brain_checkpoint`
- `brain_needs`
- `brain_stimulate`
- `brain_daydream`
- `brain_sleep`
- `brain_heartbeat_status`
- `brain_thoughts`
- `desktop_presence`
- `desktop_look`

The companion `use-navi-brain` skill retrieves durable context for substantial
tasks and permits self-model writes only when backed by observed evidence. It
may ask bounded observer subagents for proposals, but the main agent remains the
canonical writer.

## Bounded desktop perception

`desktop_presence` reads a tiny event-driven KF6 `KIdleTime` monitor without
capturing pixels or reading input events. KDE marks the user active after input
and away after two minutes without it. The monitor sleeps between compositor
notifications, and an MCP presence check is only a small JSON-file read. KDE's
Wayland backend intentionally does not expose exact idle duration.

`desktop_look` uses KDE Spectacle's native Wayland path and defaults to the
active window. Tesseract and Gemma 3 4B QAT then inspect the temporary image
locally; llama.cpp runs with `--offline`, low scheduling priority, bounded image
tokens, and a 25-second deadline. Task and curiosity calls return only a
privacy-filtered JSON observation. A direct user request may receive raw pixels
only when the local gate marks the whole image safe. Each response reports
capture, OCR, and local inference costs. Full-desktop capture must be chosen
explicitly, and image pixels are never written to Navi-Brain or retained after
processing.

Build the idle helper once on a KDE/KF6 workstation:

```bash
make desktop-awareness
```

The helper requires Qt 6 Core and KF6 IdleTime development files. Override its
path with `NAVI_BRAIN_IDLE_HELPER` when packaging it elsewhere.

The visual gate uses llama.cpp's cached Gemma model and projector by default.
Set `NAVI_BRAIN_VISION_MODEL` and `NAVI_BRAIN_VISION_MMPROJ` to explicit local
paths when packaging or relocating those files. Runtime inference uses the
paths directly with `--offline`; it never asks a model registry for updates.

For this local checkout, the skill is linked into Codex and the MCP server is
registered against `var/navi-brain.sqlite`. Start a new Codex thread after an
installation or update so the tool and skill catalogs are refreshed.

## Chronic heartbeat

`bin/navi-brain-heartbeat` is a single long-running scheduler/worker process. It
wakes every five seconds to claim any due rhythm and at most one queued work
item. The durable rhythm intervals are:

- `pulse_30s`: deterministic integrity, need accrual, and stale-lease repair;
- `reflect_5m`: boredom/curiosity-gated daydream plus optional worker proposal;
- `consolidate_hourly`: checkpointed sleep repair plus one assumption challenge;
- `sleep_daily`: verified SQLite backup, sleep repair, and one cognitive audit.

Only one high-brain cycle may run at once. Missed intervals are coalesced, not
replayed. Cycle and work leases use monotonically increasing fencing tokens so
late workers cannot commit after losing ownership.

The worker periodically discovers current `opencode/*-free` models, ranks them
by failures and observed latency, and applies exponential circuit-breaker
cooldowns. A whole work item has one deadline and may try at most three models;
failover does not multiply the budget. The isolated OpenCode configuration is in
`config/opencode-worker/` and disables every tool.

An OpenRC service template lives at `packaging/openrc/navi-brain-heartbeat`.
Installation is intentionally separate from repository setup so activating
persistent background cognition remains an explicit machine-level operation.

## Needs and sleep

Needs are persistent, inspectable pressure variables with plastic descriptions,
growth rates, thresholds, and status. Cognitive stimulation and epistemic
novelty are seeded; user- or agent-authored needs can be created, reinterpreted,
satisfied, weakened, or paused with a recorded rationale. A triggered need can
open a bounded micro-wake, but never grants external action.

Sleep performs SQLite integrity checks, expires only explicitly expired working
memory, surfaces discrepancies as repair proposals, and may produce a
provenance-marked daydream. Checkpoints are audit snapshots, not automatic
restore images. Daily `VACUUM INTO` backups are separate verified SQLite files.

## Remaining boundaries

- Retrieval is ranked lexical search, not embeddings.
- The schema initializer does not yet migrate existing tables between versions.
- `akuj.in` is not yet a replicated witness; replica identity, encryption,
  manifests, and split-brain rules still need implementation.
- Free models create proposals only. There is no automatic factual-memory
  promotion, external tool use, or recursive worker spawning.
- There is no affective persona generator, free-energy optimizer, or
  consciousness score.
