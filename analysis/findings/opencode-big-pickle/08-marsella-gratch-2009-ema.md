# 08 — EMA: one appraisal, many timings

- **Paper**: EMA: A Process Model of Appraisal Dynamics
- **Source**: Cognitive Systems Research 10(1):70–90, 2009, DOI 10.1016/j.cogsys.2008.03.005
- **Local file**: assets/papers/08-marsella-gratch-2009-ema.pdf

## Core idea

EMA (EMotion and Adaptation) argues that appraisal is a single, fast, parallel,
automatic process — a set of continuously active feature detectors scanning a
uniform symbolic representation of the agent's relationship to its environment
(the "causal interpretation", a blackboard of beliefs, intentions, plans,
probabilities and utilities). Emotional dynamics are *not* produced by multiple
appraisal layers (against Scherer's sequential checks and Smith & Kirby's
two-process model) but fall out of the time course of whatever perceptual,
inferential and action processes update that representation: as the causal
interpretation changes, appraisal re-runs for free and re-emotion happens
instantly. Appraisal frames attach to every proposition (relevance, perspective,
desirability, likelihood, expectedness, causal attribution, controllability,
changeability); patterns map to emotion labels (surprise, hope, joy, fear,
sadness, anger, guilt); frames aggregate into a slow mood that bleeds back into
appraisal; and appraisal patterns select *coping strategies* — control signals
that enable or suppress the cognitive operators (planning, belief update,
attention shifts, intention adoption/release). A 2.6 s pigeon-in-the-window video
is modeled as surprise → fear → anger → relief/compassion to show the full loop.
Evidence: prior consistency studies against human subjective reports on evolving
vignettes; the bird case is illustrative, explicitly not validation.

## Relevance to Navi-Brain

This is the paper `docs/longitudinal-cognition-metrics.md` already names for
"Appraisal calibration, reappraisal dynamics, and causal routing value" — so it
is effectively the spec for the appraisal machinery Navi-Brain has stubbed and
left inert. The synthesis already steals its framing ("EMA treats emotion as
continuing appraisal of the relationship among events, beliefs, goals, control,
responsibility, and coping options"), but the repo has none of the *dynamics*:
appraisal here is a one-shot insert, not a continuously re-evaluating feature
detector. EMA is also the cleanest available answer to the one thing every prior
finding (LIDA, GWT-2, CoALA) says Navi-Brain is missing — appraisal that is
causal — and it hands over the exact lever: *pattern selects coping strategy,
coping gates which cognitive process runs next*. That is a routing signal with a
defined job, no felt-emotion anywhere in the chain.

## Cross-reference to the existing system

- Already present:
  - **Appraisal as a record.** `src/Model/Appraisal.php` + `appraise` in
    `src/Core/ExecutiveCore.php:2098` store relevance, urgency, controllability,
    uncertainty and commitment_impact, folded into `attention_score`
    (`ExecutiveCore.php:2127`). The safety invariant — appraisal values are
    routing signals, not claims of felt emotion — is EMA's own stance: its
    emotion labels are explicitly "primarily a convenience"; the appraisal
    configuration, not the label, drives coping.
  - **The appraisal → coping → re-appraisal cycle, slow-motion.** Navi-Brain's
    `event -> intention -> retrieval -> action -> outcome -> discrepancy repair
    -> consolidation` is a decaffeinated EMA loop; the rhythms
    (`pulse_30s`, `reflect_5m`, `consolidate_hourly`, `sleep_daily`) are the
    "time course of cognitive processes" EMA says appraisal rides on.
  - **Intention-related coping, half-built.** EMA's planning/action-selection,
    resignation, procrastination and avoidance strategies map onto Navi-Brain's
    `Intention` lifecycle: `next_action` (planning), release/block conditions
    (resignation), deferred status (procrastination). `ActionTrace` with
    expected-vs-observed and repair text is the closest thing to a coping trace
    there is. `ThoughtArtifact` (proposed, no silent promotion) is a bounded,
    evidence-gated echo of EMA's emotion-focused coping.
  - **Perceptual dynamics exist in the substrate.** `desktop_presence` /
    `desktop_look` and the "monitor unexpected event" flavor of low-brain
    perception mirror EMA's perceptual operators feeding the representation.
- Partially present:
  - **Dimension overlap, wrong set.** Navi-Brain keeps relevance and
    controllability but has no expectedness, desirability, causal attribution,
    changeability or perspective. The big miss is expectedness: surprise is
    EMA's cheapest appraisal and Navi-Brain's `action:finish --matched=no`
    discrepancy path *is* an expectedness failure wearing a repair-text costume.
  - **Aggregation exists, mood doesn't.** `attention_score` is a plain mean of
    four floats. EMA aggregates frame intensities through a sigmoid into a
    slow, event-detached *mood* that biases later appraisals. Navi-Brain's
    `Need` pressure variables have the right slow dynamics but are never
    consulted by appraisal and don't bias anything.
  - **No re-appraisal.** EMA's core claim is that appraisal re-runs
    continuously as the causal interpretation changes. Navi-Brain appraises once
    at insert time and never touches the frame again, even when the linked
    action resolves.
- Absent / open design:
  - **The causal interpretation.** No uniform symbolic blackboard that
    perception, inference and action all write into and appraisal reads from.
    Retrieval is ranked lexical search (known boundary); there is no shared
    agent–environment state object at all. This is EMA's spine and Navi-Brain's
    missing organ — same verdict as the LIDA workspace finding.
  - **Coping as a gating mechanism.** EMA's coping strategies *are* control
    signals that enable/suppress cognitive processes. Navi-Brain's appraisal has
    no downstream: `attention_score` only orders `top_appraisals` in `status()`
    (`ExecutiveCore.php:2177-2180`). Nothing routes on it.
  - **Emotion-as-interrupt.** EMA (via Simon) appraises the whole of working
    memory so affect can interrupt and refocus. Navi-Brain computes attention
    but never interrupts anything with it.

## Concrete implications for implementation

- **Derive expectedness from the loop you already run.** `action:finish` knows
  expected vs observed outcome. That is EMA's expectedness variable. Add an
  `expectedness` column and compute it from `match_status` (or an explicit
  prediction field) instead of hand-feeding floats; an unexpected event should
  spike attention without anyone naming an emotion.
- **Make the appraisal pattern select the coping move on the intention.** Wire a
  bounded rule over the appraisal frame to the intention lifecycle, EMA-style:
  controllability high → plan (advance `next_action`); controllability medium/low
  + changeability high → defer (procrastination); controllability low + no
  changeability → resign (release/block condition) or distance (lower the linked
  need's pressure). This replaces ad-hoc close decisions with a justifiable,
  measurable routing rule — and it is exactly the "Appraisal causal value"
  experiment in `docs/longitudinal-cognition-metrics.md:257`.
- **Trigger re-appraisal on outcome.** When the action linked to an intention
  finishes (matched or not), re-run appraisal on that intention/event and write a
  second frame. EMA's re-appraisal is the whole point; a paired before/after
  frame is also the cheapest possible "reappraisal dynamics" data for the
  metrics.
- **Add a mood aggregate.** Sum recent appraisal intensities through a sigmoid
  into a slowly-changing mood row (or reuse `Need` as the carrier), and apply it
  as an additive bias to new appraisals. EMA's mood bleed is simple, and it gives
  `attention_score` the slow-vs-fast split the paper uses to explain dynamics.
- **Add changeability and causal attribution only if needed.** They matter for
  social/other-directed coping; the bird example itself shows EMA over-predicts
  anger where humans give empathy. Skip them until there is an actual
  other-agent model — `brain_stimulate`/daydreams don't need blame yet.

## Risks / tensions

- **Felt-emotion leakage.** EMA names emotions but insists labels are cosmetic;
  Navi-Brain's invariant is compatible. The risk is import drift: pattern → label
  → "Navi felt anger." Adopt pattern → coping, keep labels out of memory and the
  persona. The "fear-for"/empathy over-prediction in the bird example is a
  warning that pattern→label mappings mislead even their authors.
- **Belief-related coping is unsafe here.** Wishful thinking, shift responsibility
  and distancing actively distort the representation. Navi-Brain's evidence+
  confidence and no-silent-promotion invariants are the correct refusal. Adopt
  only intention-related and attention-related coping; leave belief/desire
  distortion to `ThoughtArtifact` proposals, provenance-marked.
- **Under-constrained timing.** The paper admits its action-effect delays are free
  parameters fitted to data — the model is thin on real temporal constraints. Do
  not import EMA's millisecond claims; Navi-Brain's seconds-to-hours rhythms are
  the honest scale.
- **Single illustrative case.** One pigeon video and vignette-consistency studies
  are not strong validation. Treat EMA as a mechanism menu with a good falsifiability
  argument against multi-layer appraisal, not as empirical proof.

## Bottom line

Partially adopt — as the operating spec for making Navi-Brain's appraisal causal
and *dynamic* instead of a static insert. The single most important consequence:
reinterpret the existing discrepancy-repair path as an expectedness failure,
add expectedness + outcome-triggered re-appraisal, and let the appraisal pattern
select the coping move (advance/defer/release/distance) on the intention — that
single loop turns `attention_score` from a display field into the routing signal
the metrics already promise to measure.
