# Online Bayesian Goal Inference for Boundedly-Rational Planning Agents

**Authors:** Tan Zhi-Xuan, Jordyn L. Mann, Tom Silver, Joshua B. Tenenbaum, and Vikash K. Mansinghka. **Year/venue:** NeurIPS 2020, local 13-page conference version. **Source:** [local PDF](../../../assets/papers/75-zhi-xuan-et-al-2020-online-bayesian-goal-inference.pdf). **Reviewer:** GPT-6 Astra. **Implementation evidence:** [shared implementation reference](IMPLEMENTATION.md), snapshot 2026-09-06, HEAD `ee1590009d6fd4c48035c63e15b3896a585757d7`, especially I05, I09, Q21, Q53, and Q71.

## Core idea and mechanism

The important contribution is a causal explanation of unsuccessful behavior: an agent can retain its goal while executing a partial plan that makes that goal unreachable. Inferring a different preference from every apparently irrational action confuses limitations of planning with changes of intention. The paper makes this distinction computationally useful through a generative model of resource-limited planning and an incremental inference algorithm, Sequential Inverse Plan Search (SIPS).

The agent has a goal g, environment state sₜ, and persistent internal plan pₜ. It updates the plan, selects an action from it, and changes the environment; the observer receives potentially noisy state observations. Goal inference marginalizes over latent states, actions, and plans (§3, equations 1–5, PDF p. 4; §4, equation 8, p. 5). Although the motivation describes observing actions, the SIPS formulation conditions on state observations rather than assuming that every action is directly available.

Goals and states use PDDL predicates and numeric fluents, with supplied action preconditions and effects. The evaluated dynamics are deterministic. Predicate corruption and numeric observation noise are representable, but that representational support does not establish robustness to arbitrary missing desktop telemetry (§3.1).

Bounded rationality operates inside planning (§3.2, p. 5). A negative-binomial random variable η models the number of search nodes the agent may expand. Probabilistic A* samples expansions according to

\[
P_{\mathrm{expand}}(s)\propto\exp[-(c(s)+h(s,g))/\gamma],
\]

where c is accumulated path cost and h is a supplied goal-distance heuristic. On termination it returns the path to the most recently selected successor. The agent follows that partial plan until it ends or the observed state is inconsistent with it, then searches again. Noise therefore produces temporally organized plans, rather than independent action mistakes. Search-budget parameters are modeling assumptions; the paper does not demonstrate a learned optimal allocation of computation.

SIPS retains weighted particles containing goal, state, and plan histories, extends each as observations arrive, and multiplies weights by observation likelihood (§4, Algorithm 1, p. 6). A plan that still covers the current state/time needs no new search. Low effective sample size triggers resampling; optional Metropolis–Hastings rejuvenation proposes another goal or replans near a trajectory divergence. Gen supplies programmable inference and acceptance-ratio machinery. This reuses expensive partial computation, but is neither exact posterior inference nor a worst-case constant-time filter. Random search budgets and history-dependent rejuvenation still require separate engineering limits.

The persistent latent goal matters: Figure 1b (p. 2) retains the blue-gem explanation after a myopic key expenditure creates irreversible failure. Reconsidering which fixed goal explains a trajectory is distinct from modeling the agent actually switching goals.

## Evidence and limits

The experiments cover Taxi, Doors/Keys/Gems, Block Words, and Intrusion Detection, with respectively 3, 3, 5, and 20 candidate goals (§5.1, p. 7). The last domain has an estimated 10³⁰ states, showing the benefit of structured search over enumeration; it is a supplied symbolic cybersecurity domain, not a deployment on real attack telemetry.

The comparison uses Bayesian IRL with goal-conditioned Boltzmann policies computed by asynchronous value iteration. An unbiased version samples states; an oracle version receives future observed trajectories to guide that computation (§5.2). The oracle is an informative but unavailable online comparator. Much of SIPS's advantage over unbiased BIRL reflects unsuccessful value-iteration convergence, so the comparison does not isolate bounded-rational modeling from the benefit of heuristic symbolic planning. It also does not compare against all efficient goal-recognition alternatives discussed in §2.

Table 1a (p. 9) provides a useful corrective to a blanket superiority claim:

| Domain | Q3 top-1: SIPS / unbiased BIRL / oracle BIRL | Average seconds per observation: same order |
|---|---:|---:|
| Taxi | .67 / .92 / .86 | 2.55 / .16 / .12 |
| Doors, Keys & Gems | .74 / .33 / .80 | .86 / 154 / 7.01 |
| Block Words | .91 / .56 / .96 | 4.15 / 63.6 / 2.12 |
| Intrusion Detection | .87 / .05 / 1.00 | 28.0 / 1069 / 6.00 |

Q3 means three-quarters through each trajectory, not terminal accuracy or a distributional performance quartile. The table's N counts visited states, not evaluation trajectories. Intrusion Detection still requires 375 seconds of startup and 6.60 seconds marginally per timestep for SIPS. These measurements support relative efficiency in difficult symbolic domains, not an interactive latency guarantee. Higher probability assigned to the true goal is also not, by itself, calibrated uncertainty or a proper-score evaluation.

Primary non-optimal trajectories come from the same replanning family used for inference, with matched r=2, q=.95, γ=.1. Reported main results use ten particles per goal **without rejuvenation** (§5.4, p. 8). They consequently do not establish the incremental value of the two sophisticated rejuvenation kernels.

Two small human studies offer complementary evidence. Eight participants judged ten trajectories, six suboptimal or failed; correlation with mean human judgments was .89 for SIPS versus .51 for oracle BIRL across the dataset (§5.3, Figure 4, p. 8). Separately, five participants generated 30 trajectories each; Table 1b reports Q3 top-1 accuracy of .79 for Doors/Keys/Gems and .73 for Block Words. Agreement with averaged judgments does not identify human internal search algorithms, and these pilots do not establish general interpersonal understanding.

Mismatch experiments are valuable precisely because degradation is substantial: Block Words accuracy falls from .87 with the matched heuristic to .43 with goal-count-generated behavior (Table 1b). The authors attribute some deterioration to behavioral randomness, but this does not eliminate the practical dependence on a suitable planner/heuristic model. Main tables provide no uncertainty intervals or exhaustive experimental settings; the referenced supplement is absent from this local PDF.

## Mapping to Navi-Brain

**Already implemented:** [OtherModel](../../../src/Core/OtherModel.php) has proposition-bearing hypotheses, provenance, priors/posteriors, TTLs, corrections, sealed forecasts, and delayed outcomes. `sealPredictions:756`, `resolvePredictions:848`, and `updateHypothesisFromPrediction:908` constitute real prediction feedback. [OtherModelEvaluator](../../../src/Core/OtherModelEvaluator.php), `report:16` and `onlineBaselines:221`, and [ProbabilityScorer](../../../src/Core/ProbabilityScorer.php), Brier/log-score methods at lines 39/50, provide reusable measurement boundaries (I09, Q21). This is substantially more than a static user profile.

**Partially implemented at the representational level:** goal and plan propositions exist, but their contents do not generate distinct predictions. Q71 verifies that `OtherModel::predictionTarget:1113` maps goals to conversation activity and plans to shell activity. `hypothesisDistribution:1145` adds the same 2.0 recent/.5 cooling weights for either kind before normalization, without consulting proposition content. With identical context/baseline, incompatible goals receive identical forecasts and evidence multipliers; equal starting posteriors remain equal. Current scoring can validate those coarse activity predictions, not discriminate the asserted goals.

**Absent within the master's stated search:** Q71 found no goal-conditioned forward planner with action alternatives, transition/cost model, and trajectory likelihood in the inspected OtherModel prediction/update paths. Hypothesis expiry is not a latent goal-switch process. This is the precise missing mechanism that SIPS would add. External model reasoning inside proposal text remains unverified.

**Existing executive structures are useful but not equivalent:** [ExecutiveCore](../../../src/Core/ExecutiveCore.php), `createIntention:1385`, retains Navi's explicit commitments; [ProceduralMemory](../../../src/Core/ProceduralMemory.php), `simulateCandidate:174`, checks adapter-specific feasibility/contracts (I05). Neither supplies a PDDL world model of Aku's alternative actions. Q53 also establishes that linked action records do not guarantee complete observations. Navi's own execution history cannot simply be relabeled as the user's state/action trajectory.

The most promising transfer is thus at the social-inference boundary. It does not require replacing token memory or executive scheduling, and supplies no evidence about consciousness or identity continuity.

## Prioritized recommendations and falsifiable evaluations

**P0 — Separate activity forecasting from goal-content evidence.** At the `OtherModel::sealPredictions`/`updateHypothesisFromPrediction` boundary, introduce explicit evidence scope: coarse activity scores remain activity evidence; content-level goal support must come from a target whose distribution depends on the structured goal. Report those separately in `OtherModelEvaluator`. Existing provenance and scoring machinery reduce cost, but prediction records/consumers would need a clear schema contract. This follows from the paper's requirement that alternative goals cause different trajectory distributions.

**Validation:** construct incompatible same-kind goals under the same observations and prior, then swap their text. Current activity forecasts should remain identical; an activity observation must not be reported as deciding between their meanings. For a separately specified content-sensitive target, a discriminating observation should change relative goal odds in the expected direction. Failure is any report of semantic discrimination supported only by the identical activity likelihoods. This is a proposed evaluation, not a reproduced runtime result.

**P1 — Evaluate a small, explicitly modeled task before integrating a general planner.** Build an isolated analysis prototype adjacent conceptually to `OtherModel`, with a finite candidate-goal set, documented action preconditions/effects, timestamped observations, and independently recorded intended goals. Use a controlled task with an irreversible resource expenditure and a recoverable detour, reflecting Figure 1. Candidate generation and state extraction must be evaluated separately; an LLM-generated goal cannot serve as its own truth label. Current [AgentObservationProjector](../../../src/Perception/AgentObservationProjector.php) activity categories are insufficient for this experiment (Q21/Q71).

Compare bounded partial-plan inference with optimal/Boltzmann inverse planning and a simple task-conditioned action-likelihood baseline under equal observation access and measured compute. Include held-out human behavior or a mismatched planner, successful runs, recoverable errors, and irreversible failures. Score goal log loss, Brier score, Q1–Q3 top-1 accuracy, and latency; split by trajectory/person rather than correlated prefixes. A suggested adoption gate is a positive paired improvement in goal log loss on failed/detouring held-out trajectories, with a 95% trajectory-level interval excluding zero, and no material successful-run regression under a predeclared margin. Failure includes benefits confined to the model that generated training/evaluation data. Labeled episodes and a faithful transition model are the principal costs.

**P2 — Add persistent plan particles only after P1 justifies them.** Keep inferred plans, weights, observation cursor, model version, and effective sample size in a separate observer state. Reuse valid partial plans; distinguish this state from Navi's own intentions. For the paper's mutually exclusive candidate goals, normalize one posterior over the alternatives; the current independently clipped likelihood-to-baseline odds update is not SIPS. Begin without rejuvenation, consistent with the main results, and add it only when particle loss measurably matters. Costs include history storage, reproducible random state, and either a Gen integration or independently derived inference kernels.

**Validation:** compare retained plans against recomputation at each observation, then separately ablate goal and replanning rejuvenation on misleading-prefix/recovery sequences. Measure planner expansions, p95 update latency, goal recovery delay, and proper scores at equal budgets. Reject the added machinery if it saves no planning work or cannot recover pruned plausible goals without materially worsening latency. Check tiny enumeratable cases against exact inference before trusting a port. Algorithm 1 itself needs reconciliation: line 25 prints softmax of positive heuristic distance despite prose favoring nearby goals, and line 26 conditions on the old goal despite describing the new one. These are visible pseudocode ambiguities, not demonstrated bugs in the uninspected released code.

## Risks, scope, and verdict

The paper assumes a finite set of fixed final goals; hierarchy, unbounded goal spaces, and richer stochastic/continuous environments are future work (§6, p. 9). Missing beliefs, changing goals, and incorrect environment models can resemble resource limits. A confident posterior within a wrong candidate set can therefore be confidently wrong. An unknown-goal alternative or explicit goal-change model would be a **speculative extension**, requiring its own evaluation rather than inheriting SIPS's results. Inferred intent should remain evidence for assistance, not silently become user authorization; §7 (p. 10) explicitly recognizes autonomy and consent concerns.

**Verdict: experiment, with P0 as the immediate architectural correction.** The strongest lesson is to predict the consequences of goal-conditioned partial plans before treating behavior as evidence about a goal. Production adoption of SIPS is premature until Navi has an independently evaluated task representation and content-distinguishing likelihoods.

**Coverage and unresolved evidence:** read all 13 PDF pages, including main argument, algorithm, experiments, limitations, broader impact, and references; visually checked equations 6–8, Algorithm 1, and Table 1 on PDF pp. 5, 6, and 9. The local PDF contains no appendix/supplement. Supplementary settings, released-code behavior, and deployed Navi performance remain unverified. No consequential implementation question remained beyond the shared reference's stated limits. No code, runtime, services, or tests were independently inspected or executed; all evaluations above are proposals.
