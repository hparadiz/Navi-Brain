# 08 — EMA: A process model of appraisal dynamics

- **Paper**: EMA: A process model of appraisal dynamics
- **Source**: doi:10.1016/j.cogsys.2008.03.005,
  Cognitive Systems Research 10 (2009) 70–90
- **Local file**: assets/papers/08-marsella-gratch-2009-ema.pdf

## Core idea

EMA (EMotion and Adaptation) is a computational process model of
appraisal and coping built on Lazarus's theory.

Its central claim: emotion dynamics do not require multiple levels of
appraisal. Instead, appraisal is a single, fast, parallel, automatic
process that operates over a "causal interpretation" — a working-memory
representation of the person–environment relationship (beliefs, desires,
plans, intentions). Temporal dynamics arise from the perceptual and
inferential processes that *construct and update* that representation,
not from appraisal itself.

EMA aggregates appraisal frames into a slowly-changing mood that biases
subsequent appraisals, and runs a coping cycle sending control signals
back onto the causal interpretation — altering attention, beliefs,
desires, or intentions to overturn or maintain the conditions that
produced the appraisal. The paper illustrates this with a
bird-flies-into-the-room scenario (surprise → fear → anger → hope).

## Relevance to Navi-Brain

This paper is the most direct architectural ancestor of Navi-Brain's
`Appraisal` model, and the gap between what EMA does and what Navi-Brain
currently does is the most useful thing in the file.

Right now `src/Model/Appraisal.php` is a passive record: relevance,
urgency, controllability, uncertainty, commitment_impact, and a derived
attention_score get written once and surfaced in `status()`. EMA says
appraisal should be a *control signal* modulating downstream cognition
— with a mood aggregate, coping layer, and reappraisal loop on top. None
exist yet.

The paper speaks directly to the heartbeat design (EMA's moment-to-moment
coping is what `mind:tick` could select among), to intentions (EMA's
intention-related coping maps onto `Intention.php`), and to the safety
invariant that appraisal values are routing signals, not felt emotion —
EMA explicitly says its emotion labels are conveniences, and the variable
configurations drive coping. The synthesis at
`docs/literature-synthesis.md:62` already flags EMA, but the
implementation has not caught up.

## Cross-reference to the existing system

- **Already present**: `src/Model/Appraisal.php` stores the core
  variables and derives an attention_score — EMA's pattern matching minus
  dynamics. `src/Model/Intention.php` holds the commitments EMA's coping
  operates on. `status()` at `src/Core/ExecutiveCore.php:2177` surfaces
  `top_appraisals` by attention_score, a primitive version of EMA's
  activation-based focus selection. `ActionTrace.php` provides the
  expected-vs-observed comparison that triggers reappraisal.

- **Partially present**: EMA's mood aggregate (sigmoid-bounded,
  slowly-changing, biasing frames via `X + Mood(type)`) has no counterpart.
  EMA's eleven coping strategies are not modeled. The
  `CognitiveThread.php` / `ExecutiveInterrupt.php` pair supports exactly
  this interrupt-and-reorient behavior but is not wired into a running
  loop (`_SYSTEM.md:48`). `ThoughtArtifact.php` captures proposed thoughts
  with provenance, but no resolver adopts one and acts.

- **Absent / open design**: The causal interpretation itself does not
  exist as a first-class object. EMA's reappraisal loop is not
  implemented. `mind:tick` has no appraisal-driven focus selection. No
  coping strategy modifies an intention, belief, or attention target.
  The longitudinal metrics at
  `docs/longitudinal-cognition-metrics.md:427` already flag "appraisal
  calibration, reappraisal dynamics, and causal routing value" as the
  EMA-derived measurement target, but the system being measured is
  missing that machinery.

## Concrete implications for implementation

1. **Mood aggregate**: add a `Mood` model (or mood columns on a new
   `affective_state` table) maintaining per-type [0,1] intensities via
   sigmoid aggregation of recent frames. Wire into `reflect_5m` so mood
   decays on the rhythm, not per event. Reference:
   `src/Model/Rhythm.php`, `src/Model/CycleRun.php`. Cheapest
   high-leverage addition — turns appraisal into a temporal signal.

2. **Mood-adjusted appraisal**: bias each new appraisal by current mood
   (`X + Mood(type)`). Modify `ExecutiveCore::appraise()` around line
   2127. Keep raw value stored alongside the adjusted value so the bias
   is auditable.

3. **Coping strategy layer**: a `CopingStrategy` resolver proposing in
   parallel, adopting sequentially via EMA's preference ordering
   (problem-directed if controllability high, procrastination if
   changeability high, emotion-focused if both low). Each emits a control
   signal — not an action — modifying an intention, belief, or attention
   target. This is the missing reverse path from appraisal back to the
   causal interpretation. Start with the three safe under current
   invariants: planning, seek information, procrastination.

4. **Reappraisal loop**: when an `ActionTrace` finishes mismatched or an
   `Event` changes an active intention's expected outcome, reappraise
   that intention. `action:finish` is the natural hook — a mismatched
   finish spawns a new appraisal frame linked to the same intention.

5. **Cognitive threads as coping carriers**: wire `CognitiveThread.php`
   and `ExecutiveInterrupt.php` so a coping strategy requiring
   deliberation spawns a bounded cognitive thread rather than blocking
   the heartbeat. `ExecutiveInterrupt.php` already carries the signal
   schema.

6. **Measurement**: extend `docs/longitudinal-cognition-metrics.md` with
   reappraisal-latency, coping-strategy-distribution, and mood-drift
   metrics. EMA predicts mood should be bounded and slow-moving — a
   falsifiable claim.

## Risks / tensions

- Mood-as-routing-variable is fine; mood-as-characteristic is not. Keep
  mood a numeric bias term modulating attention allocation, never a
  narrated state, and do not let it leak into spoken output.

- EMA models wishful thinking, distancing, and denial as adaptive coping.
  Navi-Brain's evidence+confidence invariant means belief-distorting
  coping needs provenance and a confidence discount, or should be blocked.
  EMA treats denial as functional; Navi-Brain treats belief distortion as
  damage.

- Single research group, illustrative validation against one video.
  Empirical studies use artificial vignettes. Adopt the process structure,
  not Table 2's emotion mapping (which the authors revised between
  papers).

- EMA's "resignation" drops goals; Navi-Brain's user-authority invariant
  means resigned intentions must stay reversible, never override
  user-authorized commitments, and be marked resigned-with-reason. Do not
  introduce EMA's blackboard literally; treat `status()` as a read-only
  interpretation view and route coping signals through existing models.

## Bottom line

**Partially adopt.** EMA's core insight — appraisal as a single fast
process over a causal interpretation, dynamics from perception/inference,
coping feeding control signals back onto that interpretation — is the
upgrade path from Navi-Brain's passive appraisal records to a genuinely
self-regulating system.

Most important consequence: appraisal must become a *driver* of the
heartbeat, not a *footnote* in `status()`. Implement mood-adjusted focus
selection and the reappraisal loop first; coping strategies follow. Do not
adopt EMA's emotion-label mapping or its denial-class coping — they
conflict with the evidence+confidence and no-affective-persona invariants.
