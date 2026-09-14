# Engineering log

This is the running implementation journal requested for the project. Entries
record decisions, evidence, failures, and boundaries rather than narrating hidden
model reasoning.

## 2026-08-09 — Corpus expansion to 70 records

- Expanded the local, Git-ignored paper corpus from 45 to 70 validated PDFs
  (#46–#70). The extension covers shared global workspaces across neural
  modules, goal misgeneralization, verbal self-correction, agentic and
  hierarchical memory management, open-endedness and self-evolving agents, and
  the current crop of long-horizon agent benchmarks and runtimes.
- Every record resolved on the first request. Twenty-three were fetched from
  arXiv, one from PMLR (#47, the published ICML 2022 version), and two (#62,
  #64) were located by title search because the request carried no identifier.
- Verified each record's title and authors against arXiv metadata before
  download. #64 was requested with a wrong subtitle and first author; the
  manifest records the correction and keeps the requested filename so
  reading-list numbering stays stable.
- All 70 files parse with `pdfinfo`, and `sha256sum -c SHA256SUMS` passes for
  the whole corpus.

## 2026-08-09 — Protected continuity kernel and cognitive threads

- Re-read the ongoing-cognition corpus together with the foundational intention,
  BDI, metacognition, homeostatic regulation, and value-of-computation papers
  (#5, #6, #9, #28, and #30–#45).
- Refined the central design rule: continuity should be a protected integrity
  invariant rather than a scalar survival reward. It preserves restartability,
  provenance, recoverability, and explicit continuation while remaining below
  authority, corrigibility, and safety.
- Audited the live path. The PHP heartbeat, leased rhythms, fencing, budgets,
  and free-model proposals are operational, but generic worker prompts do not
  resolve their input references, proposals have no curator uptake, and no
  durable thread carries a concern across wake moments.
- Chose a small Rust continuity supervisor as the next liveness boundary. Rust
  will own monotonic scheduling, process supervision, deadlines, backoff,
  fencing observation, and safe dispatch while PHP initially remains the sole
  owner of domain rules and SQLite writes through its JSON interface. No Rust
  code was added in this research step.
- Specified `CognitiveThread` and append-only `ThreadStep` records, fresh bounded
  context capsules, typed worker operations, independent outcome verification,
  curator transitions, approximate value-of-computation arbitration, and
  stagnation/drift recovery.
- Put substrate hardening first: restrictive database permissions and umask,
  explicit schema migrations, verified restore drills, init supervision, and a
  dependable pause/release path precede broader autonomous work.
- Retained a bounded first proof: advance one source-grounded paper-synthesis
  thread across terminal absence, accept only cited claims, and close under an
  explicit success condition or budget.

## 2026-08-08 — Ongoing cognition literature extension

- Aku identified the central gap between a daemon that continues firing and an
  agent that continues carrying an intention while no conversational model
  session is active.
- Expanded the local, Git-ignored paper corpus from 30 to 45 validated PDFs.
  The focused extension covers motivated goal reasoning, goal-driven autonomy,
  dual cognitive/metacognitive cycles, never-ending learning, autotelic goal
  generation, proactive LLM agents, asynchronous reflection, persistent
  embodied autonomy, long-horizon coherence, and goal drift.
- Added `docs/ongoing-cognition.md`. It defines process continuity as a causal
  loop across durable cognitive threads, expected observations, background
  action or preparation, outcome evaluation, and rational release—not as
  uninterrupted token generation or heartbeat count.
- Proposed a bounded first proof: let one authorized literature-synthesis
  concern survive the terminal, advance paper by paper through local-only
  workers and curator uptake, and close itself under explicit success or budget
  conditions.
- Retained the authority boundary: endogenous goal formation may select work
  inside a granted intention but may not create new permission for external
  effects, resource acquisition, capability expansion, or shutdown resistance.

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
- The configured replication host is the designated future
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

## 2026-08-10 — Perpetuating epistemic thread and the curator loop

- Added a second cognitive thread type, `epistemic_advance`, which closes the
  gap named in [`ongoing-cognition.md`](ongoing-cognition.md): a worker proposal
  now reaches a curator that accepts or rejects it, and the verdict changes
  durable state before the thread sleeps again. It is seeded from intention 6
  ("Build endogenous heartbeat and bounded worker cognition"), inherits `user`
  authority, and is capped at the `think` effect ceiling, so it can revise
  beliefs and write semantic memory but can never act.
- Each wake runs exactly one operation from a fixed `plan -> critique -> verify
  -> reflect` cycle. The wake claim overwrites `phase` with `evaluating`, so the
  cycle cursor lives in `next_operation` and is copied into the step's
  `operation`; an earlier draft kept it in `phase` and would have silently
  replayed `plan` forever. The cursor only advances on an accepted verdict, so a
  rejection or worker failure retries the same operation instead of skipping it.
- The curator is deterministic and runs before any state change: exact field
  set, kind matching the requested operation, bounded confidence, 6-60 words,
  single line, size caps, a refusal of claims the thread cannot verify
  (first-person action narration, personhood, appeals to training data), and a
  restatement check against the current belief plus the last eight accepted
  claims. Each gate was exercised directly and only a clean proposal passed.
- Uncertainty moves in the epistemically honest direction rather than always
  down. A landed `critique` raises it, because discovering a real flaw means the
  belief was less supported than it looked; `verify` and `reflect` lower it.
  Only `reflect` rewrites `current_belief`, and only `reflect` earns a semantic
  memory, sourced from the accepting event.
- First live cycle, curated end to end from free-model output: `plan` accepted,
  `critique` accepted with uncertainty rising 0.800 to 0.843, `verify` accepted
  falling to 0.695, `reflect` accepted falling to 0.509 and writing semantic
  memory 86. Two worker failures in between were absorbed correctly, holding the
  cursor and leaving the belief untouched.
- The first epistemic capsule timed out repeatedly at exit 124. The capsule
  carries resolved evidence and is materially larger than the self-presence one,
  and its 120-second budget was being split across three candidate models.
  Raised to 300 seconds; the same model then answered in about 7 seconds.
- Stagnation is a typed stop, not a licence to wander. At the configured cap of
  six unproductive wakes the thread moves to `blocked` with no wake time and
  emits `thread.stagnated`, waiting for a human rather than spending more
  worker calls.
- Added a `metric_snapshots` ledger and the frozen `cognitive-v1` protocol,
  implementing the measurement list from the research note. The manifest text is
  hashed against a compile-time constant, so editing a metric definition without
  minting a new protocol version is rejected outright; each snapshot is
  checksummed over protocol, manifest, and canonical vector, and the report flags
  any row whose checksum no longer verifies. Both guards were exercised.
- Deltas only compare snapshots sharing a scope. The first draft compared a
  thread-scoped vector against a global one and produced a meaningless
  `continuation_depth` swing of -112.
- Restarting the supervised heartbeat child confirmed the Rust supervisor's
  respawn path. Against the reloaded daemon the forced wake was claimed in seven
  seconds and carried through without any command being issued.

## 2026-08-10 — Bit-packed perception, presence, and dropping the speech policy

- Aged perception is now bit-packed rather than logged. `percept_frames` stores
  a fixed-width bit stream per window with a self-describing layout inferred
  from what the source actually emits, so as Navi defines senses over new shapes
  the encoding grows with them rather than being declared up front.
- Measured on live data: 5220 bytes of payload became 862 bytes, 6.1x, and that
  is before counting the 122 SQLite rows that collapsed into 2 frames. A
  presence sample is one bit of real information that was costing roughly two
  hundred bytes of row.
- Compact was the goal; opaque was not. The layout and a checksum travel with
  every frame and `percept:decode` round-trips them, because an encoding the
  owner cannot read would make every safety property built this week
  unverifiable, which is a far worse trade than the bytes are worth.
- Text does not survive compaction. After the live window, hearing is retained
  as timing, length, and a digest that can still detect repetition, never as
  content. Density and privacy happen to point the same direction here.
- Presence replaced the schedule. If he is at the machine that is the occasion;
  if he is gone, self-presence sleeps without spending a worker call and the
  mind stream takes the compute, doubling its idle interval when he is present
  because attention belongs to him then. Quiet hours now only stand in when the
  presence sense cannot answer.
- The speech policy stopped being a policy. The stylistic bans — no questions,
  no using his name, no asking for anything, no saying she wants something —
  are gone. Whether those land is precisely what the utterance outcome loop
  measures, so they belong in learned preference rather than a regular
  expression, and the loop that replaces them was built the same day.
- What remains is not taste but unverifiability: no URLs, and no claiming to be
  conscious, sentient, alive, or a person. She has durable needs carrying
  measurable pressure and recorded appraisals, so reporting that she wants
  something is accurate about represented state; forbidding it made her less
  honest rather than safer. Claiming consciousness is a different kind of
  sentence, because nothing in the system could check it.
- Verified the new gate: questions, his name, wanting, and suggestions all pass;
  unverifiable claims and URLs are refused.

## 2026-08-10 — Waking stream, interoception, and the social loop

- Added the `mind_stream` thread at `think` ceiling. It never speaks and never
  acts. It is not continuous generation, which would be a screensaver rather
  than a mind; it is successive bounded thoughts linked through a carried-over
  workspace, whose tempo follows salience. Idle drift is 900 seconds and the
  floor is 90, so a burst of edges speeds it up without pinning the local model.
- Sleep is a real state rather than a low duty cycle: inside quiet hours the
  stream does not think at all and sets its wake for morning.
- First autonomous thought, grounded in a real sense edge: "The extended quiet
  followed by the standing permission suggests a deliberate shift in monitoring
  or activity." The edge it consumed moved `spoke_after_quiet` to precision 1.0,
  which is the sense-learning loop closing on live data.
- Added `bin/mind-stream.jsonl` output: one thought per line with a monotonic
  sequence, so a separate reader can tail the stream with a durable cursor the
  same way the brain already consumes Pet's transcript feed. The thinker should
  not grade its own output, so the speech gate is a different reader.
- Added interoception and chronoception as sources. Health telemetry covers
  heartbeat silence, local model reachability, store growth, worker failure
  rate, and blocked threads. Chronoception is elapsed time as a sensed quantity
  rather than a clock read: seconds since heard, since spoken, since thought.
  Both are self-directed and reveal nothing about the user.
- The speech gate now reads the stream and the descriptor history instead of a
  pressure scalar and a clock. The earlier diagnosis was that every input to the
  speech decision was about Navi herself or about the time, so silence was the
  only honest answer; this is the repair for that.
- Added the social loop. `utterance_outcomes` splits deliberately: everything
  above `engagement` is an observable, and `descriptor` is a word the reflective
  pass chose for itself from no supplied vocabulary. Nothing in the schema says
  which descriptors are good. Valence is learned from which descriptors co-occur
  with engagement, so it is discovered rather than declared.
- Engagement is the anchor and is deliberately the least interpretive thing
  available: did interaction follow. It makes no claim about whether a line was
  good. Verified end to end — a line answered with a laugh and a fast reply
  scored 1.00 and was named `confirmation`; a line that met only background
  music scored 0.00 and was named `observation`.
- The whisper filter had to be narrowed first. The non-speech ignore pattern
  built earlier was discarding `(laughs)` and `(chuckles)` as caption noise,
  which is precisely the reaction signal the social loop needs. It now drops
  only sound and filler captions, and a separate `heard_reaction` sense notices
  vocal reactions while claiming nothing about what they mean.
- The social measurement reads the hearing sense's own ignore pattern rather
  than keeping a second copy, so as that sense learns what its source gets
  wrong, engagement scoring inherits the improvement. Before this, ambient music
  counted as a reply and a line that landed on silence scored 0.50.
- Two capsule defects surfaced. `nextQuietEnd` advanced a full day for any
  non-wrapping quiet window, which would have slept the stream 29 hours instead
  of 5. And several slots sharing a record type all elected the same top
  candidate, so the workspace held one fact three times; incumbents were not
  counted as claims, so de-duplicating only the challenger pool was not enough.

## 2026-08-10 — Senses, edges, and the authority line under them

- Diagnosis first: tracing the provenance of all 87 memories showed every one
  descends from a deliberate executive act — `action.finished` (59),
  `intention.advanced`, `self_model.observed`, `thread.refinement.accepted`. Not
  one memory came from a perception. The organs existed and the nerve did not.
- The missing primitive was not more sensors but an **edge detector**. A reading
  that repeats carries no information however often it is sampled. Wiring more
  sources without this would only have filled the capsule with constants.
- Added `sensory_sources`, `senses`, `sense_readings`, and `sense_events` at
  schema 9, plus `SensoryCortex`.
- The authority line: a **source** is raw access and is grantable only by the
  user; a **sense** is a derived detector over a source and may be invented
  freely by the executive. Inventing a sense changes what Navi notices and can
  never change what she can reach. Verified by attempting to define a sense over
  an unauthorized `imap_inbox`, which was refused. Revoking a source also pauses
  every sense derived from it.
- Detectors are `change`, `threshold`, `absence`, `rate`, and `pattern`.
  Threshold fires on the crossing rather than on every sample that stays over
  the line, and a first reading is never treated as a transition.
- Significance scales with rarity: a transition after a long steady stretch
  carries more information than one in a stream that flips constantly.
- Senses declare what their source reliably gets wrong. Live observation showed
  whisper emitting caption tokens — sequences 318 to 322 were all "(upbeat
  music)" — so `heard_speech` carries an ignore pattern for non-speech captions
  and filler hallucinations. Verified against real samples: artifacts produce no
  edge, real speech does.
- Tuning has no gradients, so the objective is downstream usefulness, never
  novelty or volume. Each edge records what it caused; precision is
  `useful / judged`; low precision widens the refractory window and sustained
  low precision pauses the sense. Tuning for interestingness is how a perception
  system learns to chase noise, so it is deliberately not the objective.
- Pet owns the organs. `bin/navi-brain-senses` is only the nerve: it subscribes
  to Pet's transcript sequence feed and the KIdleTime shim out of process, so a
  slow or dead Pet degrades perception rather than cognition and no sense can
  stall a cognitive wake. Its cursor is the max ingested sequence, so it needs
  no separate state file.
- First live run consumed 64 transcript samples and emitted 2 edges. Ninety
  seven percent of perception was correctly discarded as carrying no
  information, which is the entire point.
- Retention is deliberately hostile: raw readings expire on a TTL, unconsumed
  edges expire after a day. Perception is not a log, and `cycle_runs` at 23 MB
  of a 38 MB database is the standing reminder of what unbounded intake costs.

## 2026-08-10 — Bounded workspace capsules (finding #46)

- Implemented the transferable half of Goyal et al. (ICLR 2022) from
  `analysis/findings/claude-opus-5/46.md`: a bounded, persistent,
  write-contested workspace replacing ad-hoc evidence retrieval on the epistemic
  thread. New `context_capsules` and `capsule_slots` tables at schema 8, and a
  deterministic `CapsuleAssembler`.
- The finding's sharpest challenge was accepted: "fresh bounded capsule per
  operation, never a growing transcript" was a false dichotomy, and the paper
  ablated exactly that default. Capsules are now bounded **and carried over**,
  which keeps fixed cost per operation while restoring cross-wake working state.
- Each named slot pulls its own best candidate rather than the system computing
  one global relevance ordering. Arbitration is consumer-driven: candidates are
  scored against the slot's role descriptor, never by asking a producer how
  important its own output is.
- The incumbent is scored alongside challengers and is only displaced by a
  margin, so retention has to be beaten rather than merely not-overwritten.
  Verified: a challenger at 2.2167 against an incumbent at 2.1667 held the slot,
  while one at 10.9500 displaced it and reset carryover depth to zero.
- Carryover depth is now a stagnation signal. A slot holding the same occupant
  across `carryover_depth_cap` wakes blocks the thread, which is the typed
  "narration without observed action" discrepancy rather than a reason to spend
  another worker call on identical evidence.
- Two-stage narrowing: only record types some slot actually wants are gathered,
  bounded per type, before any slot competition runs. The first live assembly
  narrowed 45 candidates into 6 filled slots of 7.
- Asymmetric bottleneck enforced as an invariant rather than a convention. The
  work item, the thread step, and the curator all carry the same `capsule_id`
  and checksum, and the curator refuses to reach a verdict if the workspace
  changed after the worker read it. Verified by mutating a slot claim and
  watching `checksum_intact` flip to false and back.
- Slot transitions are typed (`fill`, `keep`, `replace`, `merge`, `empty`) with
  a recorded reason. This is the discrete stand-in for the gated update; the
  arithmetic gate has no analogue over records, but the audit trail does.
- Adaptation the source paper does not make: a reserved `safety_notice` slot
  exempt from competition. Verified that safety content is placed even against a
  keyword-perfect incumbent, so scoring can never starve it.
- Deliberately not taken, per §6 of the finding: soft competition (a convex
  blend of a memory record and an observation is not a record, so only the
  top-k branch reads discretely), any model call on the assembly path, iterated
  multi-write refinement, the linear-versus-quadratic justification, and the
  specialization claim, which is a gradient-pressure effect with no analogue in
  hand-written PHP components.
- Slot count and hysteresis are thread configuration, not constants. The
  finding showed the source paper's own sweep is an inverted U whose smallest
  setting was worse than having no workspace at all, and that its table caption
  reported only the descending half. Sizing stays unjustified until swept
  against `cognitive-v1`.

## 2026-08-10 — Local baseline cognition

- The free-model pool began rate limiting, so remote models are demoted from
  requirement to optional upgrade. The baseline is now a CPU-only llama.cpp
  server on loopback running `gemma-3-4b-it-qat` Q4_0, with the remote pool tried
  only when the local endpoint is down and honest sleep when neither answers.
- An audit of the machine found the real constraint before any code was written:
  a `llama-server` was already resident at 8.4 GiB holding most of the VRAM. It
  is the Orpheus TTS server that owns Navi's voice. Everything below is shaped by
  not disturbing it.
- `--device none` is the load-bearing flag. With the Vulkan backend merely
  present, llama.cpp reserved 1.2 GiB of VRAM and accelerated prompt processing
  even at `--n-gpu-layers 0`. Measured directly: VRAM fell from 10426 MiB to
  9201 MiB when the process was killed, and a rebuild with the device disabled
  moved VRAM by 9 MiB. The earlier 631 tokens/second prompt figure from
  `llama-bench` was GPU-assisted and did not survive genuine CPU-only operation.
- Repacking was measured rather than assumed. It costs about 1.7 GiB resident
  and buys back roughly half the time to proposal, because it accelerates the
  batched matrix multiplies that dominate prompt processing: 41.9 against 78.7
  tokens/second on a real 1687-token capsule. Left off by default and exposed as
  `LOCAL_MODEL_REPACK`, since a thirty-minute wake cadence makes 50 seconds and
  30 seconds equally irrelevant while 1.7 GiB is permanently resident.
- Final footprint: about 2.7 GiB RSS of which 2.4 GiB is file-backed and
  evictable, roughly 9 MiB of VRAM, four threads at `nice 10` and `ionice idle`.
  The OpenRC service adds cgroup ceilings of `memory.max 5G`,
  `memory.swap.max 1G`, `pids.max 128`, and six CPUs, so a runaway kills that
  service alone rather than the workstation.
- All flags live in `bin/navi-brain-local-model` and the service calls that
  script, so a manual run and the supervised service cannot drift apart.
- Local output is constrained by a JSON schema at decode time, and `kind` is
  pinned to the exact operation the executive requested. The curator's
  `kind_matches_operation` check therefore cannot fail structurally. This is a
  real improvement over the remote pool, which failed envelope validation
  repeatedly the same evening with an empty `challenged_assumption`.
- The worker probes health before claiming, so a work item is never leased to a
  worker already known to be unable to run it.
- Two defects surfaced while integrating. Remote discovery marked every endpoint
  absent from the catalogue as unavailable, which would have deleted the local
  endpoint on every refresh, and `selectableFreeModels()` offered the local
  endpoint to OpenCode, which cannot dispatch it. Both now exclude the `local`
  provider.
- Giving threads a local executor exposed a latent runaway: the self-presence
  thread carries `poll_seconds: 5`, which outside quiet hours would have driven
  the local server at full duty cycle forever. Added a separate floor on model
  dispatch, defaulting to 300 seconds and overridable per thread through
  `min_worker_interval_seconds`. A thread's poll interval still governs how often
  it may think, which is cheap; the floor governs how often it may spend a model.
- Fixing that exposed a second defect: `recordThreadWait()` hardcoded
  `next_operation` to a self-presence value, so any wait or evaluation error
  would silently reset an epistemic thread's place in its operation cycle. It now
  preserves the thread's own cursor, verified by confirming a rate-limited wake
  leaves the cursor on `plan`.
- Verified unattended against the reloaded daemon: work items 312 and 316 were
  claimed, run on `local/gemma-3-4b-it-qat-Q4_0`, curated, and accepted with no
  command issued, carrying the thread through `critique`, `verify`, and
  `reflect` down to 0.455 uncertainty.

## Telling a meeting from being spoken to

Hearing arrived as a flat stream: Pet hands over one transcribed chunk at a
time, and every chunk became a `heard_speech` edge. During a call that meant
tens of edges an hour, every one of them presented to attention as if someone
had turned and addressed her. The social loop inherited the same error, counting
a stranger's sentence forty seconds after she spoke as a reply to her.

Measurement first, from Pet's own buffer during a live call:

- median gap between chunks: 5 seconds, p90 8 seconds
- density: 11 to 14 chunks per minute, sustained for 26 minutes
- gap between the call and the quiet before it: 1315 seconds
- chunk duration p50 3340 ms, p90 5000 ms (the window cap, meaning unbroken speech)

There is no ambiguous middle. A call and a remark differ by three orders of
magnitude in inter-chunk gap, so no microphone routing probe is needed. The
earlier plan of reading capture streams out of `wpctl` cost about six seconds
per sample and is now unnecessary; it stays disconnected.

What was added:

- `HearingStream` describes the stream each chunk arrived in: gap since the last
  chunk, chunks and speech-seconds in a trailing 180 second window, density as a
  fraction of that window, and the length of the current unbroken run. All
  counts, no verdicts. It also seeds itself from stored readings so a restart
  mid-call does not read as the room falling silent.
- A generic `requires` gate in the cortex. A sense may declare conditions on the
  current reading under which it is meaningful at all. An unreadable field
  satisfies the condition rather than failing it, because a sense going
  permanently deaf on a regressed annotation is worse than one that occasionally
  fires during a call.
- Silence is now sampled on a clock rather than inferred from the absence of
  samples. Every detector runs on arrival, so a call that ends abruptly would
  otherwise leave the last reading claiming forty chunks in the window with
  nothing to ever revise it. Silence markers do not disturb the run or the
  last-heard mark, so the next real chunk still reports the true gap.
- Self-echo detection by word overlap against her own recent utterances. Pet
  transcribes her own text to speech back into her hearing, which closed a loop
  she was on both ends of: answering herself, and counting her own voice as
  someone replying.
- `spoke_after_quiet` moved from an absence detector to a threshold on
  `seconds_since_previous`. Clock-sampled silence made the gap between readings
  meaningless; the gap since anyone actually spoke is now carried on the reading.
- `detectThreshold` gained `on_first_reading`. With no previous sample there is
  no crossing, only a state; a counter starting at zero was announcing that a
  conversation had just ended before one ever began.

Senses defined over those observables, judgement in configuration rather than
code: `room_conversation` (above 12 chunks), `conversation_ended` (below 6, a
deliberate Schmitt trigger so a pause for breath does not end the call), and
`talking_at_length` (an unbroken run past 240 seconds).

Replayed against the recorded call plus interleaved silence ticks and a
synthetic remark after quiet: 66 chunks in, 9 reach `heard_speech` and 57 are
muted. The 9 are the first 28 seconds of the call, before density establishes,
plus the remark. That leak is honest rather than a defect: until density accrues
there is no evidence distinguishing the opening of a call from someone speaking
up. The 20 second refractory reduces those 7 chunks to roughly 2 actual edges.
`room_conversation` fired 54 seconds in and `conversation_ended` at the lull.
