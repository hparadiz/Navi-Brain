# 09 — Homeostatic RL: reward as drive reduction

- **Paper**: Homeostatic reinforcement learning for integrating reward collection and physiological stability
- **Source**: eLife 3:e04811, 2014, DOI 10.7554/eLife.04811
- **Local file**: assets/papers/09-keramati-gutkin-2014-homeostatic-reinforcement-learning.pdf

## Core idea

Keramati & Gutkin give the coupling between hypothalamic homeostatic regulation
(HR) and reinforcement learning (RL) a formal backbone. Define a *drive*
D(H) as the distance of the physiological state H from a setpoint H*, then
define *primary reward* as the drive reduction an outcome produces:
r = D(Ht) − D(Ht+Kt). Under that definition, maximizing sum-of-discounted-rewards
is mathematically equivalent to minimizing sum-of-discounted-deviations from the
setpoint (proved for γ < 1) — reward-seeking *is* physiological stability.
Discounting gets a normative role (it forces the shortest path to the setpoint),
the theory explains anticipatory responding (acting to preclude a predicted
perturbation, instrumentally not just reflexively), risk aversion, and
irrelevant-drive inhibition, and it shows the agent naturally avoids extreme
states without ever having to experience them. Evidence is analytic proofs plus
simulations fitted to classic behavioral results (conditioned ethanol tolerance,
oral-vs-fistula water self-administration). The reward is computed from a
sensory approximation K̂ of the outcome's true effect; when K̂ overestimates K
(e.g. hyperpalatable food), you get over-eating — a drive-independent "T" term
that behaves exactly like a shifted setpoint.

## Relevance to Navi-Brain

This is the formal theory behind the *needs* subsystem — and the repo already
knows it. `docs/longitudinal-cognition-metrics.md:428` names it as the source
for "Need deviation, overshoot, settling time, and need-action benefit,"
`docs/ongoing-cognition.md:99` maps it to "bounded internal variables can create
anticipatory repair pressure before a setpoint is violated" with the boundary "a
health pressure is not authority and should not become a scalar survival reward."
The needs subsystem (`Need` pressure/growth/threshold/satisfaction) is Navi's
homeostatic space; what the paper supplies is the missing piece: a *reward
function* connecting internal state to action selection. It also sits at the
algorithmic level of analysis (vs. Friston's computational FEP), which is the
level Navi-Brain actually operates at.

## Cross-reference to the existing system

- Already present:
  - **Drive variables with setpoints.** `src/Model/Need.php` (pressure,
    growth_per_hour, trigger_threshold, last_satisfied_at) and
    `needState()` (`src/Core/ExecutiveCore.php:3778`) accrue a scalar pressure
    over time and flag `triggered` at threshold — a one-dimensional drive
    against a setpoint, with `satisfyNeed` cutting pressure back toward zero
    (`ExecutiveCore.php:3815`). That reduction *is* the paper's reward event,
    just not computed.
  - **Needs already route to cognition.** `tickMind()` (`ExecutiveCore.php:711`)
    fires a daydream when a need crosses threshold — internal state modulates
    endogenous generation, which is the reward → RL-loop coupling the paper is
    about, in seed form.
  - **The measurement program anticipated it.** `Need deviation` (≡ D),
    `Regulation overshoot` (≡ the T-term overshoot), `Settling time`, and
    `Need-action benefit` in `docs/longitudinal-cognition-metrics.md:237-241`
    are the paper's observables, named before this analysis.
  - **"Never explore the brink" is both designed-in and emergent.** Navi-Brain
    hard-caps via thresholds and bounded repair signals; the paper shows
    value-learning alone also avoids extreme states. Navi-Brain's version is the
    safer one.
- Partially present:
  - **No reward function.** Nothing computes r = D(before) − D(after), so need
    pressure can *trigger* attention but cannot *select among actions*.
    `satisfyNeed`'s `amount` is a caller-supplied scalar, not an estimated
    outcome impact K̂. `ActionTrace` stores expected-vs-observed *text*, not a
    quantitative effect per need. This is the same gap the EMA finding names for
    appraisal: routing signal with no downstream.
  - **No anticipatory repair.** `triggered` is pure negative feedback — pressure
    must already be at threshold. The paper's headline behavioral claim is
    *prospective* action (tolerance response before ethanol, drinking before
    thirst). Navi-Brain has the ingredient (growth_per_hour is a forecast) but
    never looks ahead.
  - **One scalar per need, no drive-space geometry.** No m/n nonlinearity, no
    risk aversion, and no cross-need inhibition (Eq. 8: hunger suppresses
    chess). Needs are independent levers; competition between needs is
    unmodeled.
  - **No discounting.** Rhythms use wall-clock budgets, never γ. The paper's
    proof that γ < 1 is *necessary* for stability has no Navi-Brain analog —
    irrelevant while there's no value function, but it's the rationale if one
    ever appears.
- Absent / open design:
  - **Outcome-impact estimation (K̂).** No representation of "this action shifts
    this need by how much." This is the load-bearing assumption of the whole
    theory and the single missing column.
  - **Reward-prediction-error style learning.** No value accumulation from
    reward — satisfaction is a one-shot clamp, not a learning signal.
  - **Setpoints for non-physiological needs.** The paper explicitly limits
    itself to rewards with a *corresponding regulatory system*; social and
    novelty rewards are out of scope. Navi-Brain seeds exactly those needs
    (social pressure, epistemic novelty), so the framework cannot ground them
    without inventing setpoints — a real boundary, not a footnote.

## Concrete implications for implementation

- **Make action outcomes carry per-need effects.** Extend `action:finish` so the
  observed outcome can declare an effect per need_key (K̂), stored on
  `ActionTrace` or a small outcome-effect table. Then compute r as
  (pressure before − pressure after) and emit it as the routed signal — that
  single change turns `satisfyNeed`'s arbitrary amount into an evidence-backed
  quantity and gives needs a vote in appraisal (`attention_score`) and in
  `next_action` selection on the linked intention.
- **Add a prospective trigger.** In `needState()`, compute
  `forecast_triggered = pressure + growth_per_hour * lookahead >= threshold` and
  let a forecast-only crossing emit a bounded repair signal (daydream or a
  `need.anticipated` event) *before* the threshold is hit. That is the paper's
  instrumental anticipatory responding, at zero new architecture cost — the
  growth rate is already a linear predictor.
- **Give needs a competition rule.** At minimum, document and measure need
  capture (metric already exists at `longitudinal-cognition-metrics.md:241`);
  optionally bias trigger threshold or routed attention by other needs'
  pressure to mirror Eq. 8. Start with the metric, not the mechanism.
- **Add a "reward-prediction error" metric.** If K̂ is implemented, track
  |predicted need shift − observed shift| as a calibration signal — it is the
  paper's K̂-vs-K gap and the early-warning for T-term pathologies.
- **Keep the guardrail explicit.** The paper's over-eating case (T term ≡ shifted
  setpoint) is a model of exactly the runaway that violates "a health pressure is
  not authority." Record it in `docs/ongoing-cognition.md` as the *why* behind
  the invariant, not just the what.
- **Skip the discounting and FEP imports.** No value function means γ is moot;
  the system's "no free-energy optimizer" invariant already settles the FEP
  comparison. Note the paper's level-of-analysis argument in the synthesis as
  support for Navi-Brain's algorithmic, non-inferential stance.

## Risks / tensions

- **Reward axiom only covers needs with setpoints.** Forcing social/novelty
  needs into setpoint-distance form would either fabricate setpoints or overreach
  the theory. Adopt the reward machinery for needs that have one (stimulation,
  embodied grounding); treat social/epistemic pressure as out-of-theory routing.
- **Thin evidence.** Analytic proofs + simulations fitted to classic data
  (McFarland 1969, Mansfield & Cunningham 1980) — retrospective fits, not
  prospective experiments. The mechanistic claims (orexin → dopamine coupling)
  are neurobiology speculation, not established. Take the mathematics, treat the
  brain wiring as flavor.
- **Reification drift.** Computing reward from pressure must not become "Navi
  wants/feels X." Same guardrail as appraisal: reward is a routing signal with
  evidence, never a felt-state claim. The T-term story is the cautionary tale
  for reward signals decoupling from their evidence.
- **Reactive-vs-anticipatory safety.** Anticipatory triggering means acting on a
  *forecast*; false forecasts must produce bounded repair signals only, matching
  the existing "missing heartbeats create bounded repair signals, never
  authority" invariant.

## Bottom line

Partially adopt — as the formal reward function Navi-Brain's needs subsystem
lacks. The single most important consequence: close the loop the paper defines
by having action outcomes carry per-need effect estimates (K̂), computing reward
as need-pressure reduction, and adding a forecast-based anticipatory trigger —
which makes `satisfyNeed`'s arbitrary amount evidence-backed and gives needs a
real vote in action selection, the exact wiring the EMA finding already says
appraisal is missing.
