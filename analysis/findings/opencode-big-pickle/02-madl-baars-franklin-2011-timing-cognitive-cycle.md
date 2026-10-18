# 02 — Timing the cognitive cycle: the clock, not the mechanism

- **Paper**: The Timing of the Cognitive Cycle
- **Source**: PLOS ONE 6(4): e14803, 2011, DOI 10.1371/journal.pone.0014803
- **Local file**: assets/papers/02-madl-baars-franklin-2011-timing-cognitive-cycle.pdf

## Core idea

Human cognition is a cascade of ~260–390 ms *cognitive cycles*, each one a
perceive → understand → act pass that mostly runs unconscious and ends in a
serial conscious "broadcast" (the LIDA/GWT claim). The paper's contribution is
the clock: perception 80–100 ms, onset of the conscious episode 200–280 ms,
action selection 60–110 ms. It grounds these in theta-gamma coupling — gamma
ensembles form and dissolve at theta (4–7 Hz), so each conscious frame takes at
least 140–250 ms, and consciousness stays serial across cascades. Evidence is
synthetic: EEG/TMS/MEG/iEEG literatures plus two LIDA agents that reproduce
human reaction-time (avg 283 ms vs ~200 ms) and Allport's phenomenal
simultaneity data. The mechanism part is already in the corpus; this paper is
the one that says *how long each phase takes* and treats those durations as
tunable, validated parameters.

## Relevance to Navi-Brain

This is the timing companion to LIDA paper #1, and the corpus synthesis already
cites it for its *topology*, not its milliseconds: `docs/literature-synthesis.md`
§1 explicitly discards the biological timings for an LLM agent and keeps only
"overlapping asynchronous activity coordinated by serial selection and
broadcast points." `docs/longitudinal-cognition-metrics.md` lines 421–422 flag
it for "cycle timing distributions and the separation of operational cadence
from capability." So the interesting surface is not "should pulse_30s become
300 ms" — it is what Navi-Brain does and does not share with a cycle whose
structure it already mirrors: a fast model-free sampling loop, a serial
selection point, and learning that runs *after* the broadcast.

## Cross-reference to the existing system

- Already present:
  - **The two-layer loop.** LIDA distinguishes two inputs to action circuits:
    voluntary/deliberate (prefrontal → preSMA → basal ganglia) vs fast
    stimulus-driven (premotor, built from sensory cortices). That is Navi-Brain's
    low/high split in anatomical drag: `pulse_30s` runs `lowBrainPulse`
    (`src/Core/ExecutiveCore.php:2352`) with `model_invoked => false` — the fast,
    deterministic sampling loop — while `reflect_5m` / `consolidate_hourly` /
    `sleep_daily` are the slow, model-invoking moments. The 30 s cadence is
    absurdly far from 260–390 ms and that is correct: the split is the load-bearing
    idea, the numbers are biology.
  - **Expectation codelets ↔ ActionTrace.** LIDA's acting codelets include at
    least one expectation codelet that watches the action and brings *failures
    of expected results* to consciousness. `src/Model/ActionTrace.php` is this
    in schema form: `expected`, `observed`, `match_status` (matched/mismatched),
    `repair_note`. The substrate line `event -> intention -> retrieval -> action
    -> outcome -> discrepancy repair -> consolidation` names the same loop.
  - **Learning after the broadcast.** LIDA's learning mechanisms (perceptual,
    episodic, procedural) are initiated *following* the conscious broadcast;
    the broadcast content determines what is learned. Navi-Brain's analog is the
    consolidation/sleep pass: `memory:consolidate` promotes episode-derived
    takeaways, and mismatched ActionTraces become `prediction_counterfactual`
    repair artifacts in the consolidation phase
    (`src/Core/ExecutiveCore.php:3662`). Post-broadcast learning, with a gate.
  - **Serial selection points.** LIDA enforces seriality only at the broadcast
    and at action selection; everything else runs asynchronously. Navi-Brain's
    single-flight high-brain invariant (one fenced work item, fencing tokens,
    leases, monotonic sequence) is the same "one thing at a time at the neck"
    idea, enforced on a much slower substrate.
- Partially present:
  - **Wake triggers.** LIDA broadcasts on activation (a coalition exceeds a
    threshold) *and* has a NoBroadcastOccurring timeout (set to 200 ms) so
    consciousness isn't starved. Navi-Brain has the timeout half — `reflect_5m`
    is purely clock-driven, only firing if a `Need` is `triggered` — but no
    activation/event-driven trigger. A mismatched ActionTrace does *not* make
    the next reflection happen sooner; it sits in `recent_discrepancies`
    (`src/Core/ExecutiveCore.php:2158`) until the hourly/daily pass turns it
    into a counterfactual proposal. LIDA's expectation codelets *bring* the
    failure to the serial point; Navi-Brain files it for the night shift.
  - **Two-stage action selection.** LIDA selects in two stages: recruit *all*
    applicable behaviors from Procedural Memory, then pick the one best serving
    goals. Navi-Brain has the second stage (an intention's `next_action`) and
    only a design sketch of the first (the "agenda arbitration / value-of-
    computation choice" over think/act/prepare/wait in
    `docs/ongoing-cognition.md`). No candidate set is enumerated or competed.
  - **Cascading cycles.** LIDA lets cycles cascade as long as the seriality of
    consciousness is preserved. Navi-Brain's high-brain is strictly one item per
    moment — no cascade yet; the planned `CognitiveThread` continuation
    ("continuation depth" in the metrics) is the cascade mechanism, and the
    paper's seriality constraint is exactly the invariant that design must keep.
- Absent / open design:
  - **Timing validation.** The paper's two agents are the point: an implemented
    architecture should reproduce behavioral *time* distributions, and a
    parameter set that survives several datasets validates the model. Navi-Brain
    measures heartbeat cadence and missed intervals but has no per-phase latency
    characterization — no distributions for event→intention→retrieval→action→
    outcome, no discrepancy-to-detection latency despite the metric existing
    (`docs/longitudinal-cognition-metrics.md:165`). The LRT/Allport style
    probe is the missing experiment template.
  - **Cadence as tuned parameter.** The 30 s / 5 m / 1 h / daily intervals are
    chosen constants, not a parameter set validated against anything. The paper's
    "ultimately a tuned parameter set whose values remain constant across
    disparate datasets" methodology has no Navi-Brain analog.
  - **Perceptual sampling benchmark.** LIDA's Sensory Memory Ticks (20 ms) is
    explicitly a domain-specific parameter found anew per agent; the
    `desktop_presence` / `desktop_look` sampling cadence has never been tuned
    to its domain.

## Concrete implications for implementation

- **Add a mismatch-triggered wake.** Cheapest, most paper-faithful change: when
  `action:finish` records `match_status = mismatched`, set the reflection rhythm
  due (or write a typed discrepancy event that `reflect_5m` checks). This is the
  "bring the expectation failure to the serial point" loop, and it stays inside
  the existing invariant — a bounded repair signal, not authority. It turns
  clock-only consciousness into clock-plus-activation, which is LIDA's actual
  design.
- **Run an LRT-style timing probe.** Script a scripted event→retrieval→action→
  outcome task and record per-phase latency distributions over N runs, feeding
  `docs/longitudinal-cognition-metrics.md` "cycle timing distributions" (line
  421). Purpose is regression detection and substrate characterization, not
  matching human milliseconds — the doc already separates operational cadence
  from capability; keep it that way.
- **Prototype candidate enumeration in the agenda.** Before selection, produce
  an admissible candidate list (think / act / prepare / wait / stop) with a
  cheap score, then pick one — the paper's two-stage action selection as the
  skeleton for the value-of-computation agenda in `docs/ongoing-cognition.md`.
  Start with the existing intention `next_action` as the only actor if needed.
- **Document cadence as tunable, and give it a tuning rule.** Treat rhythm
  intervals as parameters with an explicit validation protocol (missed-interval
  rate, discrepancy-to-detection latency, useful-yield per cycle) instead of
  constants; write the intended anchoring into `docs/ongoing-cognition.md` or
  the engineering log.

## Risks / tensions

- **Do not import the milliseconds.** The 260–390 ms numbers come from simple
  tasks with no memory recall; the authors themselves warn recall tasks run
  significantly longer. Navi-Brain's real cycle includes retrieval by design,
  so any attempt to tighten cadence toward the paper's numbers would be
  category error. `docs/literature-synthesis.md:20` already says this — hold the
  line.
- **Automatic post-broadcast learning vs. no-silent-promotion.** LIDA learns
  automatically from every broadcast; Navi-Brain gates consolidation behind
  source events and the curator contract. Keep the gate. The shared insight is
  that broadcast content *shapes* learning, not that it *is* learning.
- **Seriality language.** "Seriality of consciousness" is a contested, heavily
  loaded framing. Navi-Brain's single-flight is a resource/safety invariant,
  not a consciousness claim; the synthesis and metrics docs should keep saying
  selection/routing, not frames of consciousness.
- **Evidence quality.** Model paper with simulation support: two agents,
  hand-parameterized, small trials (n=30, n=12), timing estimates stitched
  from heterogeneous studies. Use it as a validation template, not measured
  truth.

## Bottom line

Partially adopt — the topology is already absorbed, the one missing mechanism
is the wake-on-discrepancy path that lets an expectation failure reach the next
serial moment instead of waiting for the consolidation shift. Everything else
worth taking is the validation posture: measure cycle-phase timing
distributions and treat cadence as a tuned parameter, LRT-agent style, without
ever importing the biological clock.
