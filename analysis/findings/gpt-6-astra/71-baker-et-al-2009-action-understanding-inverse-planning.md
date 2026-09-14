# Action understanding as inverse planning

**Chris L. Baker, Rebecca Saxe, and Joshua B. Tenenbaum (2009).** *Cognition* 113, 329–349. Published journal version; DOI: 10.1016/j.cognition.2009.07.005. [Local PDF](../../../assets/papers/71-baker-et-al-2009-action-understanding-inverse-planning.pdf). **Reviewer:** GPT-6 Astra. **Implementation reference:** [IMPLEMENTATION.md](IMPLEMENTATION.md), snapshot 2026-09-06, git `ee1590009d6fd4c48035c63e15b3896a585757d7`; section identifiers below refer to the shared implementation master's static inspection.

**Judgment:** Experiment with a bounded, content-sensitive goal likelihood model. The paper's strongest architectural lesson is that interpreting behavior requires competing goals to predict different actions under the available alternatives. Storing goal propositions and updating their confidence is insufficient. Its experiments support flexible goal representations in simple navigation, not general belief inference or unrestricted human preference discovery.

## Source coverage

Read the complete supplied 21-page article, including methods, results, discussion, conclusion, and references. PDF pages 1–21 correspond to printed pages 329–349; citations below use **PDF pages**. Visually checked p. 5, Eqs. (1)–(2); p. 15, Fig. 9; p. 18, discussion; and p. 20, the supplementary-material notice. The separate appendix repeatedly cited for planning algorithms, exact priors, bootstrap procedures, and additional analyses is **not included** in this local PDF: p. 20 provides only its DOI pointer. Those derivations were not inspected. No code, runtime state, tests, or experiments were independently inspected or executed.

## Mechanism and assumptions

The contribution is a generative account of goal attribution: construct a model of how an approximately rational agent would act for each possible goal, then invert it. In the experimental maze worlds, the environment supplies states, obstacles, goal locations, and available movements. Transitions are deterministic; cardinal movements cost one distance unit and diagonals cost √2, with a cost for staying still until the goal is attained. A softmax over expected action values introduces stochasticity controlled by β: larger β makes efficient actions more probable (§2, pp. 4–5).

Writing the observed trajectory as τ, the paper's Eq. (1) becomes

\[
P(g\mid\tau,E)\propto P(\tau\mid g,E)P(g\mid E).
\]

Crucially, environmental alternatives affect the likelihood. Going around a wall favors one interpretation when a shorter route to another destination was available through a gap. The same observed direction can mean something different when that shortcut is blocked (§3.2, pp. 9–10). This is counterfactual sensitivity supplied by a planning model, rather than an association between a movement and a goal label.

The models differ in their goal hypothesis spaces (§2, pp. 5–6):

- **M1:** one fixed final goal; apparently inconsistent actions must be explained as noise.
- **M2:** a sequence of goals with a switching parameter γ. With γ=0 it reduces to M1; intermediate values retain history while allowing new evidence to support a change.
- **M3:** a stable complex goal containing a subgoal before the final destination, controlled by prior parameter κ. The implemented experimental comparisons allow zero or one subgoal, not unrestricted hierarchical plans.
- **H:** the limiting γ=1 case, using only the latest action. Because it is formulated within this framework, it is a narrow temporal-integration baseline, not an exhaustive comparison against all nonplanning heuristics.

Eq. (2) predicts future behavior by averaging goal-conditioned action distributions over the goal posterior. This preserves ambiguity that selecting one most likely goal would discard. Online inference, retrospective inference, and prediction from a new starting point consequently interrogate different aspects of the representation.

The implemented theory is the **teleological special case** in Fig. 1b: agent and observer share complete environmental access. Fig. 1c sketches a richer belief–goal model, but the authors explicitly defer joint belief inference and POMDP approximations (§1, p. 3; §6, p. 19). The paper also leaves open whether people invert their own planner or an independently represented theory of another agent. It supplies a computational-level explanation, not evidence identifying a neural implementation.

## Evidence and its limits

All three experiments used MIT subject-pool adults judging animated agents. Instructions supplied intentional agency and important task assumptions; trajectory traces remained visible as memory aids. Reported performance concerns agreement with aggregate human judgments, not independently established access to an actor's actual mental state.

| Experiment | Design | Bootstrap cross-validated correlations |
|---|---|---|
| 1: online goal inference | 16 participants; 99 unique stimuli across 36 conditions varying goals, walls, paths, and judgment points | M1 .82; M2 **.97**; M3 .93; H .96 |
| 2: retrospective goal inference | 16 participants; 95 stimuli asking about earlier points after showing longer paths | M1 .57; M2 **.95**; M3 .58; H .91 |
| 3: prediction from a new start | 23 participants; eight conditions, four accumulating example paths per condition | M1 −.03; extended M2/H .54 each; M3 **.96** |

Sources: §§3–5, Tables 1–2, pp. 6–17. The .98/.95/.97 figure fits for the preferred models should not be substituted for their cross-validated results. Table 1–2 parenthetical uncertainties are standard deviations, not confidence intervals.

Experiment 1's M2 advantage over H is small overall (.97 versus .96; reported bootstrap p=.032). Its more informative result comes from trajectories with locally ambiguous movements but diagnostic earlier routes, followed sometimes by reversals: M2 can both preserve history and revise the current goal, whereas H forgets the history and M1 retains too much (§3.2, Fig. 6, pp. 10–11). Most trials do not strongly discriminate models. Experiment 2 makes temporal representation more decisive: fixed final-goal models cannot reproduce judgments that vary across earlier positions on the same complete path; M2 also exceeds H (p=.0168; §4.2, pp. 12–13).

Experiment 3 supplies the strongest transfer-relevant contrast. Repeated deviations through the same intermediate location, across different starts, support a subgoal when the environment does not already explain that detour. Merely observing a waypoint on an efficient obstacle-avoiding route is weaker evidence. M3 captures both evidence accumulation and prediction of an indirect route from a new location (§5, Figs. 9–10, pp. 14–17). This is stronger than fitting the original trajectory, although the alternative response paths remain experimentally supplied.

Several qualifications materially constrain adoption:

1. **Correlation does not establish calibrated probabilities.** Experiments 1–2 normalize relative ratings. Experiment 3 transforms model log posterior odds through z-scores and a standard-normal cumulative distribution before correlation (§5.1.5, p. 16). Its high fit therefore is not a direct probability-calibration result.
2. **Model selection is constrained by the candidate set.** The article reports bootstrap cross-validation, but the absent supplement prevents checking resampling units, exact fitting grids, and dependence handling. Closely related trajectory prefixes and repeated ratings make those details consequential. H's defeat does not eliminate richer history-sensitive heuristics.
3. **Representations and rationality are task-dependent.** Best-fitting M2 parameters change from β=2, γ=.25 online to β=.5, γ=.65 retrospectively; instructions and task difficulty also change. These are not established stable human traits. Reward/cost scale and β are furthermore confounded in a softmax likelihood—an implementation-level identifiability concern, not a measurement reported here.
4. **The broad extensions remain proposals.** Table 3's marginal likelihoods favor M2 for Experiments 1–2 and M3 for Experiment 3, consistent with choosing different representation classes (§6, pp. 18–19). This retrospective model analysis does not show that participants implemented the proposed hierarchical learner. Naturalistic social reasoning, hidden information, multiple agents, and learned task representations remain unvalidated.

## Mapping to Navi-Brain

**Implemented foundation.** [OtherModel.php](../../../src/Core/OtherModel.php), `observeReading:52`, `runObservation:158`, `recordHypothesis:500`, and `sealPredictions:756`, supplies inference cycles, grounded proposals, hypotheses, and sealed predictions. [OtherModelHypothesis.php](../../../src/Model/OtherModelHypothesis.php):38–72 stores goal/belief/plan propositions, structured data, priors/posteriors, access labels, and provenance. Explicit corrections and TTLs exist. These are useful components, rather than a static user-profile string (I09, Q21).

**Partially aligned evidence loop.** `OtherModel::resolvePredictions:848` scores later matching observations; `updateHypothesisFromPrediction:908` updates odds with a clipped forecast-to-baseline likelihood ratio. [OtherModelEvaluator.php](../../../src/Core/OtherModelEvaluator.php), `report:16`, `onlineBaselines:221`, and `functionalUptake:305`, distinguishes proper forecast scores from action-distribution changes. This is genuine delayed observational feedback. However, measured categories such as speech/activity/replies are not ground-truth mental states; proposition extraction recall and label accuracy remain null without correction fixtures (Q21).

**Verified mismatch and bounded absence.** The master's paper-specific answer, now incorporated as **Q71**, confirms that `predictionTarget:1113` maps goals to conversation activity and plans to shell activity. `hypothesisDistribution:1145` adds 2.0 to baseline weight for `recent` and .5 for `cooling`, then normalizes; it does not consume goal/plan content or alternative user actions. Incompatible same-kind goals under the same baseline therefore receive identical likelihood evidence. Posterior equality additionally requires equal prior/state. This validates a kind-conditioned activity forecast, not semantic discrimination between goals. Targeted searches of OtherModel and inspection of its prediction/sealing/update paths found no goal-conditioned transition/cost planner, trajectory-likelihood inversion, or explicit goal-switch/subgoal model (Q71; compare Q21S for beliefs). External models' implicit reasoning remains outside this bounded finding.

**Adjacent mechanisms are different.** Navi's own commitments and parent/dependency records are not hypotheses about another person's trajectory. [ProceduralMemory.php](../../../src/Core/ProceduralMemory.php), `simulateCandidate:174`, checks adapter feasibility/contracts; [DecisionStateMachine.php](../../../src/Core/DecisionStateMachine.php), `integrate:333`, scores bounded candidates with fixed terms. Neither inspected mechanism supplies the paper's goal-dependent navigation likelihood (I05, Q03). Social state currently influences unprompted-speech inhibition; the inspected predictor does not evaluate alternative candidate utterances, and that is distinct from predicting the *observed person's* choices (I09, Q13S). Belief/plan/goal TTLs likewise do not establish latent goal transitions or retrospective smoothing (Q21).

## Prioritized recommendations

**P1 — Establish content-sensitive likelihoods in an isolated finite domain before production adoption.** At the existing `OtherModel::predictionTarget`/`hypothesisDistribution` boundary, design an optional typed goal predictor whose input includes the hypothesized goal, observed state/action, feasible alternatives, transition model, and explicit cost assumptions. First exercise it in small, fully observed navigation or similarly enumerable tasks. Keep the existing generic category predictor as a separate baseline. This follows directly from Eq. (1): a posterior can distinguish goal contents only when evidence has different likelihood under them. The main cost is defining reliable states, alternatives, and goal semantics; another confidence field does not supply them.

**Proposed evaluation:** hold out whole environments and starts; include identical observed motions with different shortcut availability. Compare goal-prior-only, H, fixed-goal, and content-sensitive predictions using goal/action log loss and calibration. Require a preregistered positive held-out improvement over the strongest baseline, with a paired interval excluding zero. A decisive failure is identical likelihood ratios for goals that predict different optimal actions in a controlled task. Symmetric, observationally indistinguishable goals should correctly remain ambiguous. No benchmark or implementation is created by this review.

**P2 — Compare goal persistence, switching, and subgoals without rewriting historical forecasts.** Conditional on P1 succeeding, add an episode-level goal sequence alongside current hypotheses, with explicit transition probabilities and a complexity prior for at most one subgoal initially. Reuse `runObservation:158` and hypothesis provenance; keep sealed forecasts immutable and distinguish filtering estimates from later smoothed interpretations. This is a new proposed temporal mechanism, not TTL tuning. Candidate growth and repeated planning are the main costs; cap goal/waypoint candidates and cache policies for unchanged environments.

**Proposed evaluation:** reproduce the paper's diagnostic contrasts with known generated goals: ambiguous recent motion, genuine goal switches, obligatory detours, and recurring optional waypoints from new starts. Compare M1-like persistence, M2-like switching, M3-like subgoals, and simple recency weighting. Measure next-action log loss, switch-detection delay, and false subgoal attribution on obligatory detours. Reject the added structure if it only improves reconstruction of training paths, misses held-out waypoint generalization, or raises false switching/subgoal rates without predictive benefit. Synthetic identifiability does not establish human transfer.

**P3 — Make human transfer a separate prediction experiment.** If the finite-domain mechanism works, extend `OtherModelEvaluator` with a narrowly defined, explicitly labeled human task and held-out complete episodes. Average action forecasts over unresolved goal hypotheses as Eq. (2) prescribes. Retain unknown alternatives and observation gaps as uncertainty; direct user statements/corrections remain distinct evidence. This extension is engineering synthesis, not an experiment demonstrated in the paper. It requires task labels and an adequate model of what the person could know and do.

**Proposed evaluation:** compare with current context baselines and direct-report-only inference under unseen starts and changed constraints. Require improved proper scores plus calibrated uncertainty on deliberately ambiguous or partially observed episodes. Reject transfer if confidence rises while prospective prediction or explicit goal-label accuracy fails to improve. An inferred goal remains a prediction hypothesis; it should not automatically create a user-authority commitment.

## Risks and verdict

The principal risk is explaining model error as human intention: a missed constraint becomes an invented goal change, and an unmodeled routine becomes a fabricated subgoal. Strong rationality assumptions amplify this failure. The paper's richer priors help within a known environment, but they also increase explanatory flexibility; held-out predictions must constrain that flexibility. Real human workflows add private information, habit, interruptions, and costs absent from distance-minimizing mazes.

**Experiment; defer broad production integration.** The next consequential step is a small benchmark that forces different goal contents to predict different actions and measures whether those differences improve held-out inference. Preserve Navi's existing provenance, delayed scoring, and corrections. The paper supports that computational discipline; it does not validate general mind-reading, a consciousness claim, or behavioral authority derived from inferred desires.
