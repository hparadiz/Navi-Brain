# Navi-Brain

`navi-brain` is an experimental persistence and self-regulation layer for a
language-model agent, built with PHP, the local Divergence v3 framework, SQLite
for executive/control state, and a resident token-native store for Memory.

Memory content and positional metadata live as checksum-manifested binary
`.memory` token streams, partitioned by tier. The daemon holds the complete token
translation/hash state and three counters per token in RAM, persists learned
changes lazily, and flushes dirty state on close. The protocol and migration
boundary are documented in [`memories/README.md`](memories/README.md) and
[`memories/ARCHITECTURE.md`](memories/ARCHITECTURE.md).

PHP executive behavior lives in the [`ExecutiveCore` namespace](src/Core/ExecutiveCore/),
with `Executive.php` coordinating its components. Application records use
Divergence models; the resident C core owns memory storage and recall.

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
project uses that Divergence v3 worktree directly. Set up the framework checkout
before running `composer install`.

## Local configuration

Copy [`.env.example`](.env.example) to `.env` on a fresh checkout. Identity and
replication settings read this file; exported process environment values take
precedence. `.env` is ignored by Git.

| Setting | Default | Purpose |
| --- | --- | --- |
| `Name` | `Navi` | Name used in generated prompts, session context, and display text. |
| `PronounSubject` | `she` | Subject pronoun. |
| `PronounObject` | `her` | Object pronoun. |
| `PronounReflexive` | `herself` | Reflexive pronoun. |
| `NAVI_USER_NAME` | `User` | Name of the user. |
| `NAVI_USER_NAME_PRONUNCIATION` | Empty | Alternate pronunciation; an empty value uses the user name. |

For example, the default identity settings are:

```dotenv
Name="Navi"
PronounSubject="she"
PronounObject="her"
PronounReflexive="herself"
NAVI_USER_NAME="User"
NAVI_USER_NAME_PRONUNCIATION=""
```

Set all three pronoun forms together. `they`, `them`, and `themselves` also
produce the matching `they are` wording. Restart running processes after
changing identity settings. New output uses the configured values; stored
memories retain their existing wording. Command names, MCP tool identifiers
such as `remember_navi`, and storage filenames stay stable.

Replication has no default remote destination:

| Setting | Default | Purpose |
| --- | --- | --- |
| `NAVI_REPLICATION_LOCAL_DIR` | This checkout | Local repository and default state location. |
| `NAVI_REPLICATION_REMOTE_HOST` | Empty | Required SSH destination for `bin/replicate`. |
| `NAVI_REPLICATION_REMOTE_DIR` | Empty | Required absolute repository path on the remote host. |
| `NAVI_REPLICATION_QUIESCE_HELPER` | Empty | Required absolute path to the executable that stops and resumes application memory writers. |

See the [replication instructions](docs/token-memory-cutover.md#mesh-replication)
for the helper contract and backup transfer procedure.

The application database defaults to `var/navi-brain.sqlite`, and the native
memory store defaults to `memories/store`. Export `NAVI_BRAIN_DB` and
`NAVI_TOKEN_MEMORY_STORE` to override these paths for CLI/MCP processes. Other
runtime settings also use the process environment; `.env` is not a general
shell environment loader. `bin/replicate` additionally reads its database and
store path overrides from `.env`.

## Fresh checkout and usage

A fresh clone contains no stored memories, learned personality narratives,
intentions, or captured session history. The application database, native
memory store, session captures, and local `.env` are excluded from Git.
Initialization creates the schema and operational defaults, including needs,
rhythms, model registration, and a baseline metrics record. Default prompt
wording and behavior still come from the source code.

For a new checkout with no existing memory store, build the C core and
initialize both stores. The C build requires a C17 compiler, SQLite development
headers, and pthreads. PHP dependencies are declared in `composer.json`.

```bash
cp .env.example .env
composer install
make -C memories
./memories/build/tokmem init ./memories/store
./bin/navi-brain init
./bin/navi-brain recall:init
./bin/navi-brain status
```

`recall:init` starts the resident memory daemon. Heartbeat, model-worker, and
sensory services are installed and started separately; they are optional for
using the memory CLI and MCP. For an existing brain, use the
[backup and restore instructions](docs/token-memory-cutover.md#restore-and-rollback)
to preserve its state.

```bash
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
./bin/navi-brain memory:consolidation:status
./bin/navi-brain memory:consolidation:pump --depth=2
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

Commands return bounded plain text by default. Add `--debug-json` only when a
script or serialization diagnosis needs the raw machine representation.

## Codex integration

The repository is also a Codex plugin source. Its newline-delimited stdio MCP
server exposes a deliberately small first tool surface:

- `brain_status`
- `remember_navi`
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
- `intentions_10m`: queue an intention-only prose synthesis of every open
  canonical commitment, including authority, dependencies, and observed
  progress, without importing affect, needs, senses, or working memory;
- `decide_1m`: one complete executive decision cycle for the held intention;
- `reflect_5m`: boredom/curiosity-gated daydream plus optional worker proposal;
- `consolidate_hourly`: checkpointed sleep repair plus one assumption challenge;
- `sleep_daily`: verified SQLite + token-memory bundle backup, sleep repair, and one cognitive audit.

Only one high-brain cycle may run at once. Missed intervals are coalesced, not
replayed. Cycle and work leases use monotonically increasing fencing tokens so
late workers cannot commit after losing ownership.

The default persistent profile in `config/cognition.php` is now
`public-reflection`: one evidence-change-driven, proposal-only code-review lane
pinned to `opencode/muse-spark-1.3-contributor-free`. It accepts only explicitly
selected source files matching verified public-repository hashes, preserves their
syntax, exposes no tools, and appends no personal memories or sensory state. A
finished proposal requires explicit review before another job can run; unchanged
evidence does not generate more work. The broad heartbeat/sensory/narrative loops
are not enabled by this profile. See [the September cognition review](docs/cognition-review-2026-09.md).

This Meta Contributor Free route may use prompts and completions for training;
do not route private cognition through it without informed authorization.
`codex-spark` remains an explicit legacy profile using the existing
ChatGPT-authenticated CLI, not a fallback from the public pilot. `disabled` turns
off the model-worker profile. Changing profiles requires restarting the worker.

Narratives use their complete evidence compiles without unrelated live-state
injection. Identical synthesis inputs reuse the latest queued, leased, or
completed job across triggers; failed/cancelled work can be retried by a new
trigger. Other legacy cognition jobs still carry their checksummed background
state projection; this does not establish that old observations are fresh.

The legacy manual `opencode:once` worker can still discover current
`opencode/*-free` models and rank them
by failures and observed latency, and applies exponential circuit-breaker
cooldowns. A whole work item has one deadline and may try at most three models;
failover does not multiply the budget. The isolated OpenCode configuration is in
`config/opencode-worker/` and disables every tool. All `FreeModelWorker` instances
in this checkout share one provider slot and a persisted cooldown. HTTP 429 honors
`Retry-After` (seconds or date) plus exponential backoff, without trying another
model/IP or rejecting the work's source evidence. This is local coordination, not
a distributed account-quota service. The public pilot never falls back to another
model. Manual `opencode:once` is broader and is not the public-only entry point.

```sh
./bin/navi-brain reflection:once --owner=manual
./bin/navi-brain reflection:status --debug-json
./bin/navi-brain reflection:review --work=<id> --verdict=useful --note='<checked evidence and limitations>'
```

## Optional local model

The former CPU-only llama.cpp baseline remains available for manual comparison
and ablation. Persistent background workers no longer start or call it:

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
  Addressed-speech work still has queue priority in the durable work ledger.
- Prompt-cache RAM is capped at 512 MiB with two checkpoints per slot. The
  upstream 8 GiB default grows rapidly under varied cognitive prompts and can
  otherwise consume the service's entire memory cgroup while the model is
  behaving normally.
- The service adds cgroup ceilings of `memory.max 5G`, `memory.swap.max 1G`,
  `pids.max 128`, and six CPUs. If the model ever exceeds them the kernel kills
  that service alone and the brain degrades rather than the workstation.

Two OpenRC service templates run `bin/navi-brain-model-worker`, which selects the
explicit cognition profile. Only one service is needed for the public pilot;
its provider slot also serializes manual OpenCode workers. In the legacy profile,
reflective social labels are queued to the workers; the
sensory daemon never calls an LLM and therefore keeps sampling while both model
slots are busy.

Addressed speech uses a separate `self_presence_answer` fast lane. Each accepted
transcript chunk survives the ambient refractory window, the speech thread is
re-due and ordered before background threads, and the reply carries the captured
turn, fixed voice policy (36 spoken words, 160 decode tokens), and the same bounded
live-state projection without loading the full reflective workspace.

Codex Spark output is constrained by a JSON schema, and `kind` is pinned to the
exact operation the executive asked for. A malformed envelope cannot reach the
curator.

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
provenance-marked daydream. Checkpoints are persistence barriers, not automatic
restore images: they flush the resident token graph and record only a lightweight
SQLite marker. Canonical executive rows are already durable and are not copied
into that marker. Daily backups are
atomic `NAVITOKBACKUP1` directories containing
the application SQLite snapshot and the complete verified token store; a
SQLite-only file is not treated as a memory backup. See
[`docs/token-memory-cutover.md`](docs/token-memory-cutover.md) for restore,
replication, and legacy-write fencing.

## Remaining boundaries

- Retrieval is ranked lexical search, not embeddings.
- The heartbeat domain loop is still one PHP process supervised by Rust; remote
  witness restore and split-brain recovery remain unimplemented.
- The terminal decision action space is intentionally only sourced semantic
  learning plus observe-only machine grounding. Dialogue and broader digital
  environments need separately reviewed adapters.
- Candidate simulation predicts installed adapter postconditions and sensory
  streams one step ahead; it is not yet a learned multi-step world model or
  tree-search planner.
- The state directory and live SQLite/WAL/SHM permission policy still requires
  hardening before the system receives broader autonomous scope.
- The configured replica is not yet a replicated witness; replica identity, encryption,
  manifests, and split-brain rules still need implementation.
- Free and local models create proposals only. Deterministic code may accept one
  adapter-valid action, while models retain no tool permissions and cannot
  recursively spawn workers.
- There is no affective persona generator, free-energy optimizer, or
  consciousness score.
