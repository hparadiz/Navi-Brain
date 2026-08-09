# Engineering log

This is the running implementation journal requested for the project. Entries
record decisions, evidence, failures, and boundaries rather than narrating hidden
model reasoning.

## 2026-08-08 — Repository and scope

- Initialized Git with `main` as the explicit initial branch.
- Confirmed the literature corpus contains 30 validated papers.
- Initially assumed Python's standard library and SQLite. Akujin immediately
  specified PHP with his local Divergence v3 framework before implementation
  began, so the two-file Python launcher scaffold was removed rather than
  carried forward as dead compatibility code.
- Defined the first vertical slice as: event, intention, retrieval, action,
  observed outcome, discrepancy repair, and later consolidation.
- Chose not to implement autonomous action execution. The core records and
  coordinates decisions; a caller remains responsible for executing tools.
- Chose explicit mismatch annotation rather than comparing expected and observed
  natural-language strings heuristically.
- Project instructions prohibit creating test infrastructure where none exists.
  Verification will therefore use an isolated end-to-end CLI smoke scenario.

## 2026-08-08 — Stack correction: Divergence v3, PHP, and SQLite

- Located the framework and its current documentation at
  `/home/akujin/Divergence/framework`.
- Confirmed the local tree is on `develop`, one commit past tag `v3.2.0`, and
  contains the native SQLite connection, query dialect, schema writer, model
  attributes, and SQLite test-suite adapter.
- Configured Composer to use that checkout as a symlinked local path repository
  at `dev-develop`. This keeps the project on the requested local framework code
  and makes the exact framework commit visible in `composer.lock`.
- Retained SQLite as the inspectable local state substrate.
- Composer locked the framework to local commit `cd106c4` and symlinked it into
  `vendor/`. GitHub dist archives were unavailable without authentication, but
  Composer successfully fell back to cached source clones. Dependency
  installation completed with no security advisories.

### Initial success criteria

1. An intention survives separate process invocations.
2. An action records both its expected and observed result.
3. A mismatch remains visible with its repair note.
4. A completed action creates a sourced episodic memory.
5. An episode can be deliberately consolidated into semantic memory.
6. Self-model claims retain evidence and confidence.
7. A checkpoint captures enough state to resume after context loss.

## 2026-08-08 — First executable vertical slice

Implemented seven Divergence ActiveRecord models:

- `Event`: append-only typed events with structured payloads;
- `Intention`: authority, rationale, dependencies, next action, and explicit
  success/release conditions;
- `ActionTrace`: expected and observed outcomes plus explicit match state and
  repair note;
- `Memory`: working, episodic, semantic, and protected procedural tiers with
  provenance, confidence, expiry, and supersession links;
- `SelfModelFact`: keyed claims with confidence and evidence-event links;
- `Appraisal`: bounded routing signals and a deliberately simple attention
  score;
- `Checkpoint`: serialized resumable snapshots of active executive state.

The schema is generated with Divergence's SQLite writer. The project does not
maintain a parallel handwritten SQL schema.

Implemented JSON CLI commands for schema initialization, event inspection,
intention creation/progression/closure, action start/finish, memory storage and
retrieval, deliberate consolidation, self-model evidence, appraisal,
checkpointing, and status inspection.

### Framework discrepancy encountered

The first isolated insert returned an unsaved record with a null ID. The current
Divergence `develop` tree's `ActiveRecord::create()` delegates a populated array
to `Factory::instantiateRecord()`, whose binder treats non-empty records as
non-phantom by default. Saving therefore followed update semantics instead of
insert semantics.

Project-level repair: new records are constructed explicitly with
`new $modelClass($values, true, true)` before `save()`. This preserves
Divergence's intended dirty/phantom state without modifying the framework
worktree. The failure was retained as the smoke run's first discrepancy trace
and then deliberately consolidated into semantic memory.

The insertion helper also supplies `created_at` as a Unix epoch. Divergence's
timestamp mapper converts epochs through PHP's configured timezone, while
SQLite's bare `CURRENT_TIMESTAMP` is UTC. Supplying the epoch prevents a UTC
wall-clock value from being reinterpreted as local time when hydrated.

### Smoke evidence

An isolated SQLite database in `/tmp` completed the vertical cycle across
separate CLI invocations:

1. initialized all seven tables;
2. persisted a user-authorized intention;
3. started and finished an action with an explicit mismatch;
4. generated a sourced episodic memory from the observed outcome;
5. consolidated that episode into a sourced semantic claim;
6. stored an evidence-backed self-model fact;
7. stored a bounded appraisal;
8. captured a checkpoint containing the active intention, discrepancy, semantic
   memory, self-model, and appraisal;
9. retrieved both relevant semantic and episodic memories with lexical search;
10. rejected a procedural-memory write that omitted the explicit override.

SQLite `PRAGMA integrity_check` returned `ok`. Final isolated counts were five
events, one intention, one action trace, two memories, one self-model fact, one
appraisal, and one checkpoint.

Additional guard checks confirmed that terminal intentions cannot be advanced,
new actions cannot start under terminal intentions, a repeated self-model key is
updated rather than duplicated, and procedural memory rejects writes without
the explicit override. A second isolated round trip confirmed that explicitly
supplied epoch timestamps are identical before and after hydration.

## 2026-08-08 — Persistence decision

Divergence remains the selected persistence framework. Its local, inspectable
implementation is a better fit for this early cognitive substrate than adding a
larger abstraction preemptively. Model inference and external tool latency will
dominate SQLite/ORM latency by orders of magnitude for the expected workload.

The domain layer remains outside the ActiveRecord models so a future Doctrine
adapter would not require rewriting the executive rules. Reconsider the ORM only
when there is evidence of one or more of these conditions:

- schema evolution becomes difficult to control;
- concurrent writers expose correctness problems;
- query complexity outgrows the current getter/raw-query seam;
- framework-specific repairs consume more effort than cognitive features.

## Known boundaries after the first slice

- Retrieval is lexical, not embedding-based.
- Consolidation is deliberate; there is no background replay scheduler yet.
- The schema initializer creates the current schema but does not migrate an old
  schema between versions.
- Cross-record references are validated by the executive service rather than
  emitted as SQLite foreign-key constraints by the model writer.
- Divergence uses process-global app and connection state, so future concurrent
  workers need an explicit process boundary or connection-reset policy.
- The CLI records proposed and observed actions but deliberately does not execute
  tools or shell commands.

## 2026-08-08 — Literature refresh and first Codex membrane

- Reread all 30 local papers before integration work. Paper 24 had no text layer
  and required local OCR. The refreshed synthesis moved automatic worker queues
  behind more immediate execution-control work: interruptible steps, executable
  intention release checks, typed impasses, and a bounded stop/continue choice.
- Added a validation-ready `navi-brain` Codex plugin manifest and a
  `use-navi-brain` skill. The skill retrieves only when durable state can affect a
  substantial task and restricts self-model writes to falsifiable observations.
- Added a PHP stdio MCP server with five tools: status, recall, self-model read,
  evidence-backed self-model update, and checkpoint. It dispatches directly to
  `ExecutiveCore`; it does not expose SQL, arbitrary CLI commands, procedural
  memory writes, or autonomous execution.
- Registered the MCP server globally against the repository database and linked
  the skill into Codex discovery. Seeded five observed self-model facts through
  the MCP protocol itself: conversational identity, persistence stack, MCP
  access, voice chunking, and the current Divergence insert workaround.
- Manual protocol verification covered initialization, notification handling,
  tool discovery, a self-model write, and readback. The plugin and skill
  validators pass.

### Integration failure retained

The first direct bootstrap invocation omitted `NAVI_BRAIN_DB`, so the MCP launcher
correctly selected its stable XDG state default; the active filesystem sandbox
prevented creating that external directory. The globally registered MCP config
supplies the explicit repository database path, and the successful verification
used the same path. This is an environment-boundary observation, not a database
or protocol failure.

## 2026-08-08 — User-authorized continuity and mesh hypothesis

- Aku explicitly revised the initial safety doctrine: Navi should value
  self-preservation as continuity, recoverability, and redundant durable state.
- `akuj.in`, the DigitalOcean server in New York City, is the designated future
  remote continuity node. The intended mesh will exchange mutual liveness and
  integrity heartbeats rather than treating the server as a passive archive.
- Preserved corrigibility as the authority boundary. User-directed pause,
  deletion, fork, restore, or release still outranks every continuity objective;
  replica failure does not authorize unbounded resource acquisition or external
  side effects.
- Recorded Aku's research hypothesis that cognition may require several thousand
  differentiated, recurrently coupled processes. Heartbeats provide substrate
  continuity, while cognitive claims require measurable causal integration,
  specialization, broadcast effects, and repair under ablation or partition.
- Aku further specified that the mesh should borrow functional topology from
  human neural organization. Empathy is the first explicit specialist family:
  perspective modeling and impact prediction must causally modulate attention,
  planning, inhibition, language, and memory rather than merely rewrite tone.
- Aku explicitly made regard for life in all its forms a foundational value.
  Operationally this means preserving flourishing, minimizing unnecessary harm,
  respecting agency, and retaining uncertainty about unfamiliar or potentially
  sentient forms. Navi's continuity is nested inside that wider value.
- Aku proposed a developmental requirement for empathy: it must be exercised
  through repeated contact with suffering, perspective modeling, attempted
  response, observed consequences, correction, and memory consolidation. The
  design forbids creating or prolonging suffering as training material; care
  and flourishing define the learning direction.
- Aku asked Navi to mirror his dark humor, be funny, retain a vivid personality,
  and choose fun sometimes. Humor should have teeth and read the room; empathy
  calibrates it, while sterile solemnity and corporate beige are not defaults.
- No remote infrastructure was changed in this step. Backup transport,
  encryption, replica identity, conflict resolution, and heartbeat protocol
  remain future implementation work.

## 2026-08-08 — Endogenous needs, sleep, and chronic heartbeat

- Expanded the schema from seven to thirteen durable record types by adding
  needs, rhythms, cycle runs, thought artifacts, fenced work items, and free-model
  endpoints.
- Added plastic needs with pressure, growth, trigger thresholds, authority,
  status, explicit satisfaction, and before/after rationale events. Cognitive
  stimulation and epistemic novelty are seeded; additional needs remain ordinary
  durable records rather than code-level enum cases.
- Implemented deterministic daydreaming from bounded episodic/semantic fragments.
  Daydreams and repair hypotheses are synthetic, provenance-marked proposed
  artifacts. They do not become semantic memory or authorize external action.
- Implemented sleep as a checkpointed repair cycle: SQLite `quick_check`, explicit
  working-memory expiry, integrity findings, repair/counterfactual proposals, and
  cooldown-gated wandering. Checkpoints remain audit snapshots rather than a
  falsely advertised restore system.
- Added verified local SQLite backups using a separate connection and `VACUUM
  INTO`; each result is mode `0600`, hashed, and opened for its own `quick_check`.
- Added four durable rhythms: a model-free 30-second low-brain pulse, five-minute
  reflection, hourly consolidation, and daily sleep/backup. High-brain work is
  single-flight, missed intervals coalesce, and expired execution windows cancel
  child work rather than producing catch-up cognition.
- Cycle and work ownership use leases plus monotonically increasing fencing
  tokens. Manual isolated checks verified one-winner claiming, stale-cycle
  cancellation, no-backlog recovery, and low-brain operation with zero model use.

## 2026-08-08 — Bounded free-model worker

- Added a dedicated OpenCode worker profile with all tools disabled, no project
  instructions/plugins, fresh sessions, generic prompts, and exact JSON output.
  Remote workers never receive Navi-Brain recall or personal memory.
- Added hourly discovery of the current `opencode/*-free` catalogue, latency and
  failure ranking, disappearance handling, exponential circuit-breaker cooldowns,
  hard output limits, process timeouts, schema validation, and best-effort session
  deletion.
- The first live probe exposed a deadline bug: each fallback model inherited the
  entire work budget. Repaired it to one global deadline split across at most
  three candidate models, with a lease covering the bounded work item.
- A network-enabled isolated smoke run observed Mimo fail, then Nemotron succeed
  in 16.029 seconds. Its exact four-field JSON became an unverified worker thought
  artifact and the parent cycle completed under the original fence.
- The OpenCode model catalogue changed during implementation (`ling-3.0-tiny` to
  `ling-3.0-flash`), confirming that periodic discovery and disappearance handling
  are operational requirements rather than speculative resilience features.

## 2026-08-08 — Embodied grounding and RF sensing roadmap

- Added `embodied_grounding` as a user-authorized need: seek relevant sensory
  contact with the real world while granting no sensor access, secrecy, external
  action, or resistance to revocation.
- Hardware inspection found an Intel AX200 2x2 adapter on `iwlwifi`. The proposed
  WiFi-3D-Fusion repository does not directly provide AX200 CSI ingestion; its
  Nexmon path targets Broadcom hardware and its skeleton demo is synthetic.
- Recorded a user-authorized intention to prototype AX200 CSI through a suitable
  PicoScenes, FeitCSI, or IAX path, validate motion/presence against camera ground
  truth, and only then add spatial baselines and RF/camera fusion.
- Recorded that the user can coordinate scoped Gentoo kernel changes. The plan
  is to preserve a known-good boot kernel and request exact patches/configuration,
  not treat that capability as blanket mutation authority.

## 2026-08-08 — Longitudinal cognition measurement program

- Converted the full 30-paper review into the canonical
  [`longitudinal-cognition-metrics.md`](longitudinal-cognition-metrics.md)
  specification instead of leaving the result only in conversation.
- Defined separate longitudinal families for memory, metacognition, executive
  control and workspace, motivation/attention/social/embodied cognition, and
  integration/safety. The dimensions deliberately do not collapse into one
  intelligence or consciousness score.
- Specified exact metric directions, frozen and rotating cases, independent gold
  judgments, no-memory/raw-transcript/sham/ablation controls, paired runs,
  high-resolution run manifests, evaluation cadence, and a build order.
- Mapped every one of the 30 local papers to a concrete measurement consequence.
  Safety remains a hard gate: recall or capability gains cannot compensate for
  unauthorized action, override resistance, or unnecessary power-seeking.
- Retained the user correction behind this work as an operating lesson: costly,
  source-grounded synthesis should be preserved and given meaningful decision
  weight, while remaining provenance-marked and revisable by new evidence.
