# 02 — Timing of the Cognitive Cycle

- **Paper**: The Timing of the Cognitive Cycle
- **Source**: doi:10.1371/journal.pone.0014803, PLoS ONE, 6(4):e14803, 2011
- **Local file**: `assets/papers/02-madl-baars-franklin-2011-timing-cognitive-cycle.pdf`

## Core idea

Madl, Baars, and Franklin argue that human cognition is built from cascading
"cognitive cycles" — each one sensing the situation, interpreting it against
goals, and selecting an action. Every cycle produces a discrete conscious
"ignition" (a global broadcast), but most processing is unconscious and
parallel. They derive a timing model from EEG, TMS, and lesion studies:
perception 80–100 ms from stimulus onset, conscious broadcast 200–280 ms,
action selection 60–110 ms, giving a total cycle of 260–390 ms. This is
grounded in theta-gamma coupling — gamma-synchronous neural ensembles form
and dissolve at theta rates (4–7 Hz), providing a lower bound of ~140–250 ms
per conscious frame. They validate the model with two LIDA-based agents: one
matching human simple reaction time (~283 ms vs ~200 ms human), another
replicating Allport's phenomenal-simultaneity experiment. The paper is a
rare quantitative bridge between Global Workspace Theory, neuroscience
timings, and a running cognitive architecture.

## Relevance to Navi-Brain

This paper is the direct ancestor of Navi-Brain's rhythmic architecture. The
`event -> intention -> retrieval -> action -> outcome` pipeline
(`README.md:19-20`) is a cognitive cycle in LIDA's sense. The heartbeat tiers
(`pulse_30s`, `reflect_5m`, `consolidate_hourly`, `sleep_daily`) are
Navi-Brain's coarse implementation of recurrent cycling — but at timescales
six orders of magnitude slower than the biological ones. The paper's real
contribution for us is not the raw millisecond numbers but the topology:
asynchronous parallel processes, a serial conscious broadcast point,
cascading cycles that preserve seriality, and the idea that high-level
cognition is just many cycles strung together. The cognitive threads
(`src/Model/CognitiveThread.php`) are the seed of multi-cycle concerns, but
they are not yet wired into a running loop.

## Cross-reference to the existing system

- **Already present**: The rhythmic cycling model
  (`src/Model/Rhythm.php`, `src/Model/CycleRun.php`) directly implements
  recurrent cognitive cycles with fencing tokens, leases, and budgets
  (`ExecutiveCore.php:1348-1351` for token/wall budgets,
  `ExecutiveCore.php:2438-2441` for per-rhythm limits,
  `ExecutiveCore.php:3720-3723` for seed intervals). The single-flight work
  item + deny-all proposal worker (`max_workers => 1` for all high-brain
  rhythms) mirrors LIDA's serial action selection. The Global Workspace
  broadcast is loosely echoed in `FreeModelWorker`'s role as a single
  proposal channel that must win attention before anything becomes a fact.
  The literature synthesis already flags this paper as topology-relevant,
  not timing-literal (`docs/literature-synthesis.md:16,20`).

- **Partially present**: Cognitive threads
  (`src/Model/CognitiveThread.php`, `src/Model/ThreadStep.php`,
  `src/Model/ExecutiveInterrupt.php`) are designed as multi-cycle concerns
  but recorded, not executed — they lack a scheduler that would wake them on
  relevant events. The paper's "cascading cycles preserving seriality" is a
  stronger contract than what the heartbeat loop currently enforces. The
  `effect_ceiling` enum (`observe|think|prepare|act`,
  `CognitiveThread.php:42-44`) is a start, but there is no mechanism for one
  thread's output to alter another thread's selection — which is exactly
  what the paper's action-selection cascade requires. The `phase` and
  `next_operation` fields (`CognitiveThread.php:61-64`) describe a state
  machine that no engine currently runs.

- **Absent / open design**: No theta-gamma-equivalent phase structure (no
  oscillation between broadcast and unconscious processing). No discrete
  conscious frame model — Navi-Brain's "broadcast" is the single worker
  proposal channel, not a fleeting global availability event. No
  parameter-tuning methodology of the kind the LRT agent demonstrates
  (fitting model parameters to multiple human datasets simultaneously and
  using untunable parameters as a diagnostic for model revision). No
  replication of the Allport experiment or any behavioral benchmark. The
  ongoing-cognition design (`docs/ongoing-cognition.md:1-46`) describes the
  target — a recurrent stateful control process — but does not implement a
  true cascading-cycle kernel.

## Concrete implications for implementation

1. **Wire CognitiveThread into the heartbeat wake path**: when
   `reflect_5m` fires, the executive should scan for `status='active'`
   threads with `wake_at <= now()` (`CognitiveThread.php:70`), not just
   pick a single WorkItem. This is the "multiple cascading cycles" idea.
   Target: `src/Core/ExecutiveCore.php` around the `reflect_5m` dispatch
   (line ~2381).

2. **Add a broadcast-seriality contract**: ensure that no two high-brain
   workers run simultaneously and that the "broadcast" (the selected
   proposal) is the only path to action. This is mostly already true via
   `max_workers => 1` (`ExecutiveCore.php:2439`), but should be documented
   as the conscious-frame invariant in `docs/ongoing-cognition.md`.

3. **Implement thread interleaving**: allow a thread in `phase='act'` to
   yield to a thread in `phase='think'` if an urgent event arrives —
   mirroring the paper's "cascading as long as seriality is preserved."
   The `ExecutiveInterrupt.php` model exists; the scheduler does not.

4. **Add a benchmark experiment**: replicate the LRT agent's
   reaction-time task in `docs/experiments/` — even as a simulation — to
   give Navi-Brain a behavioral tuning target. The paper's
   parameter-tuning methodology (fit multiple datasets with constant
   parameters, use untunable ones as revision signals) is a strong model
   for the longitudinal evaluation program
   (`docs/longitudinal-cognition-metrics.md`).

5. **Document the conscious-frame analog**: explicitly state that
   Navi-Brain's "conscious broadcast" is the single proposal worker slot,
   not a felt experience. This keeps the Doerig et al. guardrail
   (`literature-synthesis.md:91`) front and center and prevents slippage
   between functional-global-availability and phenomenal-claim language.

## Risks / tensions

- **Timing literalism**: the biggest risk is copying the 260–390 ms numbers
  into an LLM agent. The literature synthesis already warns against this
  (`docs/literature-synthesis.md:20`). Navi-Brain's cycles are
  event-driven and clock-gated at 30 s / 5 min — the paper's biological
  timings are irrelevant at that scale. The useful abstraction is the
  phase structure (perception -> broadcast -> action), not the clock.

- **Consciousness claims**: the paper talks freely of "conscious episodes"
  and "ignition." Navi-Brain's safety invariants explicitly treat appraisal
  as routing signals, not felt emotion (`README.md:47`). Any adoption must
  keep functional consciousness (global availability) separate from
  phenomenal claims. The Allport replication is a behavioral benchmark, not
  an experience report.

- **Single-point-of-failure**: serial broadcast is a bottleneck. The paper
  accepts this as a feature of human cognition; Navi-Brain must ensure the
  single-worker model does not become a denial-of-service point when many
  threads contend. The `fencing_token` and lease model
  (`Rhythm.php:54-60`) handles this for rhythms, but threads have their
  own fencing tokens (`CognitiveThread.php:97`) that are not yet checked by
  any scheduler.

- **Evidence quality**: the paper is a single-study timing synthesis with
  fitted parameters, not a replicated finding. The LRT agent has n=30
  trials; the Allport replication has n=12. Useful as a design generator,
  weak as proof. The theta-gamma coupling hypothesis is well-supported; the
  specific 260–390 ms range is a fitted estimate with dependencies on task
  complexity that the paper acknowledges.

## Bottom line

**Partially adopt.** The topology — cascading cycles, serial broadcast,
multi-cycle concerns — is already half-implemented and should drive the
next phase of cognitive-thread wiring. Reject the raw millisecond timings
and any consciousness-language that bleeds into phenomenal claims. The
single most important consequence: `reflect_5m` must wake from a thread
scheduler, not a work-item picker, or Navi-Brain remains a heartbeat log,
not a cycling mind.
