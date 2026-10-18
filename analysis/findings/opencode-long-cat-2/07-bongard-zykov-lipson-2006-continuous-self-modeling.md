# 07 — Continuous self-modeling (the starfish robot)

- **Paper**: Resilient Machines Through Continuous Self-Modeling
- **Source**: 10.1126/science.1133687, *Science* 314(5802):1118, 2006
- **Local file**: `assets/papers/07-bongard-zykov-lipson-2006-continuous-self-modeling.pdf`

## Core idea

A four-legged robot with eight motorized joints, eight joint-angle sensors and
two tilt sensors infers its own morphology without being told it. Three
components run continuously: **model synthesis** (stochastic optimization
produces 15 candidate self-models that explain the observed
actuation–sensation relationship), **exploratory action synthesis** (pick the
next motor command that maximizes *disagreement* between those candidates'
predicted sensor signals), and **behavior synthesis** (the single best model is
then used to evolve a forward gait, executed with no further physical trials).
After a leg is broken off, the same loop restarts from the previous best model
and recovers a scaled topology, producing a qualitatively different
compensating gait.

The evidence is a controlled comparison at matched budget (~250,000 internal
model evaluations, 16 physical actions). Model-driven exploration recovered a
topologically correct model in 43.3% of 30 runs versus 26.7% and 23.3% for two
random-action baselines; post-damage mean model error was 2.17 ± 0.55 cm versus
4.55 and 5.60 cm. The load-bearing trick: the robot *cannot* observe its own
model error, so it uses inter-model disagreement as a proxy — Spearman ρ =
0.425, P < 0.02 against true error. Note that is a modest correlation on n=30.

## Relevance to Navi-Brain

This is the paper the synthesis already leans on for the predictive self-model
(`docs/literature-synthesis.md:52-58`: "a useful self-model generates
predictions and is corrected through intervention … where alternative
hypotheses disagree, the agent should prefer safe probes that distinguish
them"). Reading the actual text sharpens that claim into three separable
mechanisms, only one of which Navi has:

1. A self-model that *predicts* rather than describes.
2. An **ensemble** of competing candidate models over the same unknown.
3. **Active probe selection** driven by ensemble disagreement, with
   disagreement doubling as an error estimate where ground truth is
   unavailable.

Navi has (1) in a thin form. She has neither (2) nor (3). And the damage-recovery
framing maps cleanly onto the self-repair experiment in
`docs/experiments/03-cognitive-self-repair.md` — the robot's broken leg is the
injected cognitive fault, and its recovered gait is H3/H4 recovery + transfer.

## Cross-reference to the existing system

**Already present**

- Expectation-versus-observation is real machinery, not aspiration.
  `finishAction()` (`src/Core/ExecutiveCore.php:387`) requires `observed`,
  `matched`, and a `repair_note`, and `ActionTrace` persists
  `expected`/`observed`/`match_status`/`repair_note`
  (`src/Model/ActionTrace.php:40-52`). That is the sensor-versus-prediction
  comparison, at the action layer.
- Mismatch actually drives a downstream process: sleep sweeps
  `match_status = 'mismatched'` traces and emits counterfactual repair
  artifacts (`src/Core/ExecutiveCore.php:3646-3676`), provenance-marked and
  explicitly "not an observation".
- Evidence-bound self-model facts exist: `self:set` /
  `setSelfModelFact()` (`src/Core/ExecutiveCore.php:2053`) requires a
  confidence and an evidence event, exposed as `brain_self_model` /
  `brain_remember_self` (`README.md:130-131`).

**Partially present**

- **Confidence without spread.** Each fact carries one scalar confidence, and
  `self_model_facts_key` is a *unique* index (`src/Model/SelfModelFact.php:18-23`),
  so an update overwrites the prior value in place
  (`src/Core/ExecutiveCore.php:2078-2086`). One value per key means there is no
  ensemble and therefore no disagreement signal — the paper's entire uncertainty
  estimator is structurally unavailable.
- **Repair proposals are never tested.** The counterfactual artifact literally
  says "then test whether it reduces the recorded expectation mismatch"
  (`src/Core/ExecutiveCore.php:3674-3676`), but nothing executes that test or
  scores the result. The paper's loop closes; Navi's stops at the proposal.
- **Metrics gesture at this.** `docs/longitudinal-cognition-metrics.md:110`
  (contradiction resolution when records disagree), `:159` (expected calibration
  error) and `:266` (perspective uncertainty calibration) are the right family,
  but they score record-level conflict and post-hoc calibration, not
  disagreement among rival models of one fact.

**Absent / open design**

- No exploratory-action synthesis of any kind. Retrieval is ranked lexical
  search (`memory:search`) — passive by construction. Nothing anywhere decides
  *which evidence to acquire next* in order to disambiguate hypotheses.
- No disagreement-as-error-proxy metric.
- `src/Model/ThoughtArtifact.php` plus `daydream()`
  (`src/Core/ExecutiveCore.php:730`) generate *divergent* proposals, but they
  free-associate; they do not compete over a shared quantity, so their spread
  means nothing.
- Damage detection has no morphological analogue. `desktop_presence` /
  `desktop_look` observe the environment, not Navi's own effector set. When a
  tool, permission, or model endpoint disappears, there is no loop that notices
  the capability change and re-derives what she can still do.

## Concrete implications for implementation

- Add a candidate layer under the self-model: relax the unique index in
  `src/Model/SelfModelFact.php` into `(fact_key, candidate_id)` with one row
  flagged canonical, keeping 2–3 rivals per key plus a derived
  `disagreement` column. Behavior keeps using the canonical value — same as the
  robot generating gaits from its single best model — while spread becomes a
  first-class readable signal in `brain_self_model`.
- Emit `self_model.contested` when spread over a key exceeds a threshold, and
  let the 5-minute reflection cycle turn that into a *probe intention* via
  `intention:add` whose next-action is the cheapest observation that would
  separate the candidates. Deliberately an intention, not an execution — user
  authority and the no-autonomous-shell invariant stay intact.
- Close the repair loop: when a repair artifact is accepted, record the action
  ID it targeted and, on the next matching `action:finish`, score whether
  `match_status` improved. That is the paper's post-damage error measurement,
  and it is exactly H3/H4 in `docs/experiments/03-cognitive-self-repair.md`.
- Add a metric to `docs/longitudinal-cognition-metrics.md` beside the
  calibration rows: *self-model disagreement versus subsequent correction rate*
  — Navi's version of the ρ = 0.425 check. If it does not correlate, the
  ensemble is decoration and should be cut.
- Cheap capability-damage probe: on worker or endpoint failure
  (`src/Model/ModelEndpoint.php`, circuit breakers in
  `src/Core/FreeModelWorker.php`), lower confidence on the affected self-model
  facts instead of leaving a stale confident capability claim — the failure
  mode `docs/literature-synthesis.md:230` already names.

## Risks / tensions

- Disagreement-maximizing probes cost tokens, attention, and possibly user
  patience. The robot's actions were free-ish; Navi's are not. Probing must be
  budgeted by the existing wall-clock/single-flight discipline and must gate
  *internal* work, not turn into asking Aku clarifying questions on a timer.
- Implemented as cascading probe workers this would violate "no recursive
  worker spawning". Candidate generation must be a fixed-width pool, generated
  once per cycle.
- The paper's own proxy is weak: ρ = 0.425 on n=30 explains under 20% of
  variance. Treat disagreement as a routing hint, never as a confidence value
  written into a fact.
- The robot's model space was hand-bounded (planar arrangements of eight
  limbs). Navi's hypothesis space over "what am I" has no such closure, so
  spread stops being meaningful unless candidates are constrained to the same
  typed slot per `fact_key`.
- The authors are careful — "unlikely that organisms maintain explicit models
  such as those presented here". Nothing here supports self-awareness talk.

## Bottom line

**Partially adopt.** The predictive self-model is already the right idea in
the repo; what is missing is the paper's actual engine — multiple competing
candidates over one fact, with their disagreement used as the trigger for the
next probe. Add a bounded candidate layer plus a disagreement signal that
raises a probe *intention*, and close the repair loop so a proposed fix is
actually scored against later mismatch.
