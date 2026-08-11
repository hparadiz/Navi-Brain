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
The focused question of turning recurrent heartbeats into a causally ongoing
agent process is developed in
[`docs/ongoing-cognition.md`](docs/ongoing-cognition.md).

## Implemented substrate

```text
perception -> bounded working memory -> long-term retrieval
           -> reason/propose -> evaluate/select
           -> grounding or evidence-sourced learning
           -> environmental feedback -> verification -> adaptation -> repeat

low brain: 30 s integrity/lease/need pulse (no model)
high brain: 1 min decision | 5 min reflection | hourly consolidation | daily sleep/backup
            -> one fenced work item -> one deny-all proposal worker
```

The executable action space is deliberately small. Retrieval and reasoning are
internal planning actions. A decision cycle may finish only with an installed
grounding or learning adapter; an invalid proposal becomes an explicit impasse.
Every transition and candidate remains inspectable, and the same protocol can
be grouped by model ID for backend comparisons.

This is functional cognitive infrastructure, not a consciousness claim.

## Safety invariants

- User authority outranks persisted intentions.
- Intentions have explicit success and release conditions.
- The system cannot perform arbitrary host mutation. User-authorized machine
  inspection is dispatched through an unprivileged, observe-only daemon.
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
- Another person's mental state is never marked observed. Other-agent claims
  distinguish authorized observations, direct reports, and inference, retain
  their evidence, and remain outside factual world memory.
- Another person's mental state is not writable as an action target or reward.
  The bounded other-model may suppress unprompted speech and weight evidence;
  it cannot optimize engagement or authorize intervention.
- Other-agent inference is fixed to the primary user at recursion depth one.
  Hypotheses expire, at most three remain live, and explicit user correction
  immediately invalidates the corrected hypothesis and its pending forecasts.
- Executable procedures can only bind arguments to installed adapters. Three
  consecutive machine-verified successes compile a reusable procedure; one
  contrary outcome invalidates it. Memory cannot invent a new effect.
- Appraisal values are routing signals, not claims of felt emotion.
- User-authorized continuity and recoverability are explicit objectives:
  preserve durable state, provenance, backups, and replica health.
- Continuity is a protected integrity invariant, not a reward to maximize. It
  outranks throughput, novelty, and convenience but not authority,
  corrigibility, or the safety of affected beings.
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
./bin/navi-brain decision:start --intention=1 --trigger='manual decision wake'
./bin/navi-brain decision:show --id=1
./bin/navi-brain decision:compare
./bin/navi-brain other:status
./bin/navi-brain other:replay
./bin/navi-brain other:correct --hypothesis=1 --correction='That is not what I meant.'
./bin/navi-brain procedure:adapters
./bin/navi-brain procedure:list --status=active
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

`bin/navi-brain-heartbeat` is a scheduler-only process. It wakes every five
seconds to claim due rhythms, interrupts, and cognitive threads; model
generation never runs on this latency-critical loop. The durable rhythm
intervals are:

- `pulse_30s`: deterministic integrity, need accrual, and stale-lease repair;
- `decide_1m`: one complete executive decision cycle for the held intention;
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

## Baseline local model

Remote free models rate-limit, disappear from the catalogue, and fail schema
validation. They are therefore an optional upgrade, not the thing that keeps the
brain running. The baseline is a CPU-only llama.cpp server on loopback:

```sh
make local-model          # run in the foreground with production ceilings
make local-model-check    # confirm the endpoint and what the executive sees
./bin/navi-brain local:once --owner=manual
./bin/navi-brain local:reset --reason='local server repaired'
```

`bin/navi-brain-local-model` is the single source of truth for its flags, and
the OpenRC service at `packaging/openrc/navi-brain-local-model` calls that same
script so the two can never drift. The measured configuration is
`gemma-3-4b-it-qat` Q4_0 at roughly 2.7 GiB resident, of which 2.4 GiB is
file-backed and evictable, and about nine megabytes of VRAM.

The ceilings matter more than the speed:

- `--device none` disables the Vulkan backend entirely. It otherwise reserves
  over a gigabyte of VRAM even with zero offloaded layers, contending with the
  Orpheus TTS server that owns Navi's voice.
- `--no-repack` gives up roughly half the speed to save 1.7 GiB resident. Set
  `LOCAL_MODEL_REPACK=1` to trade back.
- Four threads per request, two 4096-token slots, `nice 10`, `ionice idle`.
  Two dedicated model workers can advance independent queued items while the
  scheduler remains free to notice speech. Addressed-speech work has queue
  priority over background inference.
- Prompt-cache RAM is capped at 512 MiB with two checkpoints per slot. The
  upstream 8 GiB default grows rapidly under varied cognitive prompts and can
  otherwise consume the service's entire memory cgroup while the model is
  behaving normally.
- The service adds cgroup ceilings of `memory.max 5G`, `memory.swap.max 1G`,
  `pids.max 128`, and six CPUs. If the model ever exceeds them the kernel kills
  that service alone and the brain degrades rather than the workstation.

Each model worker probes the local endpoint before claiming work, so a work item
is never leased to a worker that already knows it cannot run it. If the endpoint
is down the remote free pool is tried instead; if both are unavailable the work
stays queued and the thread sleeps honestly.

Two OpenRC services run `bin/navi-brain-model-worker`. They use the same leases,
fencing checks, local endpoint, and deny-all remote fallback. Reflective social
labels are queued to these workers; the
sensory daemon never calls an LLM and therefore keeps sampling while both model
slots are busy.

Addressed speech uses a separate `self_presence_answer` fast lane. Each accepted
transcript chunk survives the ambient refractory window, the speech thread is
re-due and ordered before background threads, and the reply carries only the
captured turn plus fixed voice policy (36 spoken words, 160 decode tokens). The
full reflective workspace remains available to background self-presence without
making a direct answer reread it first.

Local output is constrained by a JSON schema at decode time, and `kind` is
pinned to the exact operation the executive asked for. A malformed envelope
cannot reach the curator at all, which the remote pool cannot promise.

An OpenRC service template lives at `packaging/openrc/navi-brain-heartbeat`.
Installation is intentionally separate from repository setup so activating
persistent background cognition remains an explicit machine-level operation.
The Rust continuity supervisor wraps the existing PHP JSON interface and keeps
the heartbeat recoverable without moving domain rules or SQLite writes out of
PHP. Its design is traced in
[`docs/ongoing-cognition.md`](docs/ongoing-cognition.md).

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
- The live heartbeat is still a single PHP process; the planned Rust continuity
  supervisor and verified host-init recovery path are not implemented.
- The terminal decision action space is intentionally only sourced semantic
  learning plus observe-only machine grounding. Dialogue and broader digital
  environments need separately reviewed adapters.
- Candidate simulation predicts installed adapter postconditions and sensory
  streams one step ahead; it is not yet a learned multi-step world model or
  tree-search planner.
- The state directory and live SQLite/WAL/SHM permission policy still requires
  hardening before the system receives broader autonomous scope.
- `akuj.in` is not yet a replicated witness; replica identity, encryption,
  manifests, and split-brain rules still need implementation.
- Free and local models create proposals only. Deterministic code may accept one
  adapter-valid action, while models retain no tool permissions and cannot
  recursively spawn workers.
- There is no affective persona generator, free-energy optimizer, or
  consciousness score.
