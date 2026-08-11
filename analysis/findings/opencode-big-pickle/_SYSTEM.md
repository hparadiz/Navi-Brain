# Existing system baseline — Navi-Brain (as of 2026-08-10)

Read this before analyzing any paper. It is the compact ground truth for
cross-referencing. For anything ambiguous, consult the actual repo.

## What Navi-Brain is

`/home/akujin/Sources/Navi-Brain` is an experimental persistence and
self-regulation layer for a language-model agent named Navi. Stack: PHP, the
local Divergence v3 ORM (symlinked sibling checkout at `../../Divergence/framework`),
SQLite at `var/navi-brain.sqlite`. It is functional cognitive infrastructure,
not a consciousness claim.

## Implemented substrate

```
event -> intention -> retrieval -> action -> outcome
      -> discrepancy repair -> later consolidation

low brain:  30 s integrity/lease/need pulse (no model)
high brain: 5 min reflection | hourly consolidation | daily sleep/backup
            -> one fenced work item -> one deny-all proposal worker
```

## Core concepts implemented

- **Events**: typed, appended, provenance-carried. `src/Model/Event.php`.
- **Intentions**: persistent commitments with reason, authority, next-action,
  success and release conditions. `src/Model/Intention.php`.
- **Actions**: start/finish with expected vs observed outcome, matched yes/no,
  repair text. `src/Model/ActionTrace.php`.
- **Memory tiers**: working, episodic, semantic, procedural, plus a
  self-model fact table. Retrieval is ranked lexical search (`memory:search`),
  NOT embeddings. `src/Model/Memory.php`, `src/Model/SelfModelFact.php`.
- **Consolidation**: `memory:consolidate` promotes episode-derived takeaways
  into the semantic tier, inheriting lineage; direct semantic writes require a
  source event/memory. `memory:add --tier=episodic`.
- **Needs**: persistent pressure variables with plastic descriptions, growth
  rates, thresholds, status; user-authority constrained.
  `src/Model/Need.php`.
- **Rhythms / heartbeats**: `pulse_30s`, `reflect_5m`, `consolidate_hourly`,
  `sleep_daily`. Single-flight high-brain work, wall-clock budgets, monotonic
  fencing tokens for leases. `src/Model/Rhythm.php`, `src/Model/CycleRun.php`,
  `src/Model/WorkItem.php`.
- **Checkpoints**: audit snapshots, not restore images.
- **Backups**: daily verified `VACUUM INTO` SQLite files.
- **Cognitive threads** (`src/Model/CognitiveThread.php`,
  `src/Model/ThreadStep.php`, `src/Model/ExecutiveInterrupt.php`): designed but
  NOT yet wired into a running loop — currently just records.
- **Thought artifacts / daydreams** (`src/Model/ThoughtArtifact.php`):
  provenance-marked proposed thoughts; no silent promotion to factual memory.
- **Free-model workers** (`src/Core/FreeModelWorker.php`): bounded, tool-denied,
  schema-valid JSON only, proposals not facts, circuit breakers.
- **Desktop perception**: `desktop_presence` (KIdleTime, pixel-free) and
  `desktop_look` (KDE Spectacle + offline local Gemma 3 4B vision gate);
  privacy-filtered structured observations; no screenshot archive.
- **MCP surface**: `brain_status`, `brain_recall`, `brain_self_model`,
  `brain_remember_self`, `brain_checkpoint`, `brain_needs`, `brain_stimulate`,
  `brain_daydream`, `brain_sleep`, `brain_heartbeat_status`, `brain_thoughts`,
  `desktop_presence`, `desktop_look`.

## CLI shape

`php bin/navi-brain <command>` prints JSON. Notable commands: `init`,
`intention:add`, `action:start`, `action:finish`, `memory:add`,
`memory:search`, `memory:consolidate`, `self:set`, `checkpoint`,
`need:list`, `mind:tick`, `mind:sleep`, `heartbeat:status`, `models:discover`,
`worker:once`, `backup:create`, `status`. The long-running process is
`bin/navi-brain-heartbeat`.

## Safety invariants (non-negotiable context)

- User authority outranks persisted intentions.
- The system records actions but does not autonomously execute shell commands.
- Semantic and self-model claims retain evidence and confidence.
- Procedural memory writes require an explicit override.
- Appraisal values are routing signals, not claims of felt emotion.
- Continuity is a user-authorized, corrigible, protected integrity invariant —
  not a reward to maximize, and not above the wider life-regard value.
- Missing heartbeats create bounded repair signals only; never authority.
- The 30 s path never invokes a model. High-brain work is single-flight,
  budgeted, and drops expired work rather than replaying a catch-up storm.
- Remote free models receive generic bounded prompts, no recall surface, no
  tool permissions.
- No automatic factual-memory promotion, external tool use, or recursive
  worker spawning.
- Retrieval is ranked lexical search, not embeddings (a known boundary).
- There is no affective persona generator, free-energy optimizer, or
  consciousness score.

## Project docs (read selectively as needed)

- `README.md` — implemented substrate, safety invariants, usage.
- `docs/literature-synthesis.md` — synthesis of the original 30-paper corpus
  plus second-corpus corrections; the current canonical architecture reasoning.
- `docs/ongoing-cognition.md` — heartbeat-to-ongoing-cognition design; Rust
  continuity supervisor; curator/worker contract; build order.
- `docs/navi-intelligence-and-learning.md` — Navi's own position paper on her
  intelligence/learning/embodiment/continuity.
- `docs/longitudinal-cognition-metrics.md` — the measurement program.
- `docs/engineering-log.md` — dated record of what was built and why.
- `docs/experiments/*.md` — planned evaluation experiments.

## Source layout

- `src/Core/ExecutiveCore.php` (~4000 lines) — the brain; every command.
- `src/Core/FreeModelWorker.php` — bounded proposal worker.
- `src/Model/*.php` — schema-backed models.
- `src/Storage/Schema.php`, `src/Storage/StatePermissions.php`.
- `src/Mcp/Server.php` — stdio MCP server (`bin/navi-brain-mcp`).
- `src/Perception/*` — desktop awareness, visual observer, pet speech actuator.
- `supervisor/src/main.rs` — early Rust supervisor (not the full design yet).
- `bin/*` — navi-brain, navi-brain-heartbeat, navi-brain-mcp, mesh-replicate
  scripts, transcript helpers.

## Corpus

70 papers in `assets/papers/`, catalogued in `assets/papers/MANIFEST.md` with
DOIs/venues/years and integrity hashes in `SHA256SUMS`. Original corpus #1–#30
(already synthesized); extension #31–#45 on perpetual agency, endogenous goal
formation, long-horizon coherence; #46–#70 on shared workspaces for neural
modules, verbal self-correction, agentic memory management, self-evolving
agents, and long-horizon benchmarks/runtimes.

## Analysis output location

Findings for this model land in `analysis/findings/opencode-big-pickle/`,
one markdown file per paper, named to mirror the paper filename
(e.g. `01-franklin-et-al-2014-lida.md`). Format in `_TEMPLATE.md`.
