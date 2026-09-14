# Grounding Language about Belief in a Bayesian Theory-of-Mind

**Authors:** Lance Ying, Tan Zhi-Xuan, Lionel Wong, Vikash Mansinghka, Joshua B. Tenenbaum; Ying and Zhi-Xuan contributed equally. **Year/version:** 2024, local arXiv:2402.10416v2, 9 July 2024. **Venue:** CogSci 2024 according to the repository manifest; the reviewed artifact is the arXiv manuscript. **Source:** [local PDF](../../../assets/papers/78-ying-et-al-2024-grounding-language-belief.pdf). **Reviewer:** GPT-6 Astra. **Implementation reference:** [shared master](IMPLEMENTATION.md), snapshot 2026-09-06, git HEAD `ee1590009d6fd4c48035c63e15b3896a585757d7`; especially I09, Q21, Q21S, Q71, Q77, and the requested Q78 clarification, re-read before finalization. All page references below are PDF pages.

## Core contribution and mechanism

The useful contribution is an executable connection between a belief sentence, the possible worlds it describes, and the actions that would make those worlds plausible. A language model translates a sentence into a restricted epistemic extension of PDDL; Bayesian inverse planning infers goal/world hypotheses from an observed trajectory; a logical evaluator sums the posterior weight of hypotheses satisfying the sentence (pp. 2–3, Fig. 1). Language supplies a query into a model whose evidence comes from behavior.

The domain contains typed objects, predicates, negation, conjunction, disjunction, and existential/universal quantification. An outer `believes` operator attributes the embedded formula to the player. This preserves distinctions such as a key being in either of two boxes versus both boxes containing keys. It is a restricted first-order epistemic fragment, not a demonstrated general implementation of nested belief or conversational semantics (p. 2, “Representing Belief Statements”).

The decisive simplification is that the player has complete, correct knowledge: its belief state is the actual environment state, `b_t ≡ s_t`. Boxes hide their contents from the observer, not from the player. Uncertainty therefore concerns which deterministic world the informed player occupies, jointly with which goal it pursues. This is observer uncertainty about an agent's certain, true beliefs, not agent uncertainty or false-belief reasoning (p. 3; explicitly acknowledged in the p. 6 Discussion).

For each goal and candidate initial state, action likelihood follows a Boltzmann distribution:

\[
P(a_t\mid b_{t-1},g)\propto\exp[-\beta\widehat Q_g(b_{t-1},a_t)].
\]

Here the quantity inside the exponential is estimated plan cost, so lower cost raises probability; heuristic search estimates instrumental routes through keys and doors (p. 3, Eq. 6). Enumerated goal/state hypotheses receive successive action-likelihood multipliers and deterministic state updates (Eqs. 7–8). Filtering is exact over this enumerated support under the specified likelihood model; the planning costs remain approximations. Deterministic transitions alone would not make a sampled subset exhaustive.

For a translated statement `ψ = believes(player, φ)`, the model averages the Boolean truth of `φ` over weighted final belief states. Crucially, the paper compares a uniform prior over worlds with a query-dependent prior making each queried statement initially equiprobable with its negation. The latter produces the normalized likelihood

\[
\overline L(\psi\mid a)=\frac{P(a\mid\psi)}{P(a\mid\psi)+P(a\mid\neg\psi)}.
\]

This distinction separates a coherent posterior under a common world prior from a statement-relative measure of behavioral evidence (p. 3, “Quantitatively Interpreting Belief Statements”).

## Evidence and limits of inference

The online study recruited 100 US Prolific participants. Eighteen constructed Doors, Keys, & Gems scenarios each had four possible goal gems, two or three boxes, and two English belief statements. Each participant rated nine scenarios at multiple trajectory checkpoints. Goals were selected using checkboxes; belief statements received seven-point ratings normalized to [0,1]. The tasks included negated, conjunctive, and disjunctive sentences, and instrumental detours where approaching a needed key can mean moving away from a goal (p. 4, “Experiments”).

Table 1 reports the following human–model correlations, with 95% bootstrap intervals (p. 6):

| Model | Goal ratings | Belief ratings |
|---|---:|---:|
| Full BToM, statement-uniform prior | 0.93 [0.91, 0.94] | 0.92 [0.91, 0.93] |
| Full BToM, state-uniform prior | Same reported goal result | 0.86 [0.85, 0.87] |
| Distance-based mentalizer, statement-uniform prior | 0.17 [0.13, 0.19] | 0.04 [0.02, 0.06] |
| Distance-based mentalizer, state-uniform prior | Same reported heuristic goal result | 0.19 [−0.03, 0.40] |
| Non-mentalizing state-prior observer | — | 0.19 [−0.03, 0.40] |
| Omniscient observer | — | 0.64 [0.48, 0.75] |

This is strong evidence that means–ends coherence explains these human ratings better than distance-to-goal or state base rates alone. Figure 2 provides useful trajectory-level examples: passing a potentially useful box shifts inferred key location because retrieving that key would be instrumental to the inferred goal (pp. 4–5). However, correlation measures agreement in variation, not calibrated mental-state probabilities. Some illustrated model goal assignments reach 1.00 while human ratings remain softer. Neither held-out action prediction nor downstream assistance benefits are reported.

The comparison does not establish that humans run this inference algorithm or that Bayesian mentalizing is uniquely necessary. Scenarios were designed around instrumental reasoning; the distance heuristic deliberately omits it. The “ignorant observer” equates failure to deduce with falsity, a particular negation-as-failure baseline rather than the strongest treatment of logical ignorance. There is no direct LLM baseline or competing learned action-conditioned inference system (pp. 4, 6).

The seven-page artifact also leaves reproducibility gaps: no reported parser model/version or semantic parsing accuracy, no explicit held-out evaluation split, and insufficient detail about the selected action-optimality parameter and bootstrap resampling unit. Participant and scenario dependence therefore cannot be independently assessed from the reported intervals. The language component is plausible infrastructure; broad compositional language generalization is not separately established.

The statement-prior result deserves particular caution. Participants apparently discounted differences in statement base rates; the authors interpret this as evidence-sensitive language use and explicitly call it non-orthodox Bayesian behavior (p. 6). My interpretation is that this supports a response model for belief language, not automatically a preferred internal uncertainty representation. Different query-specific priors need not correspond to one joint distribution over worlds, so independently scored related statements have no general guarantee of joint probabilistic coherence.

## Mapping to Navi-Brain

The shared master establishes meaningful existing social-model machinery, but also a direct mismatch with the paper's functional semantics:

- **Implemented:** actor-indexed propositions, structured metadata, prior/posterior fields, knowledge-access categories, evidence, corrections, TTLs, sealed predictions, and delayed Brier/log-loss feedback. See I09/Q21: [OtherModel](../../../src/Core/OtherModel.php), `recordHypothesis`, `sealPredictions`, `resolvePredictions`, `updateHypothesisFromPrediction`; [OtherModelHypothesis](../../../src/Model/OtherModelHypothesis.php), lines 38–72. These are real executable records and feedback paths.
- **Partially analogous:** the parser produces a quoted, source-grounded goal/belief/plan/constraint proposition. Q77 verifies `queueParser:262`, `integrateParserProposal:310`, and `grounded:1611`: exact output keys, normalized quote containment, and at least half the proposition's content terms present in the statement. These lexical checks do not establish negation scope, quantifier binding, entailment, or executable logical semantics.
- **Missing after bounded inspection:** Q21 finds no actor-specific world-state belief store updated through witnessed/unwitnessed transitions; Q71 finds no content-conditioned goal planner and trajectory likelihood feeding inverse planning. The searched scope is the inspected OtherModel prediction/update and associated observation/model paths, not implicit reasoning inside external model calls.
- **Critical existing limitation:** Q21S verifies that incompatible same-kind belief propositions under the same context receive identical forecasts. `predictionTarget:1113` selects speech state; `hypothesisDistribution:1145` adds fixed belief-kind weights without reading proposition content. `updateHypothesisFromPrediction:908` consequently applies the same likelihood-ratio evidence multiplier. Equal starting posteriors stay equal; differing initial posteriors can remain different. Good speech prediction validates this coarse forecast, not which incompatible belief is correct.
- **Additional bounded absence, confirmed in Q78:** no compositional belief-formula evaluator, shared weighted actor/world assignment set, or query-specific statement-prior/evidence scorer was found. The master searched OtherModel, OtherModelEvaluator, related record classes, and AgentObservationProjector for formula, logical-operator, possible-world, and query-prior mechanisms alongside the parser/update traces. Per-record posteriors do not supply a joint distribution for evaluating compound statements. External model internals remain outside this finding.

This paper therefore suggests a new semantic inference layer around the social model, not tuning token-memory associations or renaming stored propositions. Existing speech inhibition is an actual consumer of bounded social state (I09); that does not demonstrate correct belief-sensitive assistance.

## Prioritized recommendations and falsifiable evaluations

**P0 — Separate proposition support from coarse forecast quality.** At `OtherModel::updateHypothesisFromPrediction` and [OtherModelEvaluator](../../../src/Core/OtherModelEvaluator.php), distinguish evidence about speech/activity forecasts from evidence about proposition content. Q21/Q77 already identify null extraction-recall and label-accuracy fields pending correction fixtures; retain that uncertainty explicitly. A proposed audit should hold baseline/context fixed while swapping incompatible propositions and record whether their forecasts can discriminate content. Use independently specified semantic labels or direct corrections for proposition evaluation, rather than treating an observed speech category as its truth label. Cost is modest evaluator/accounting work, although changing the meaning of stored posterior fields requires a migration decision. **Failure criterion:** a claim of proposition validation remains unjustified if incompatible contents receive the same evidential update on content-diagnostic observations, even when Brier score improves. No such audit was run here; the current invariance is source-verified by Q21S.

**P1 — Experiment with a bounded language-to-belief-model pipeline offline.** Start with a manually specified small object/action domain, a fixed goal set, and complete known action traces. Add a proposed typed formula representation beside the Q77 parser boundary, validate symbols and scope, enumerate joint goal/world hypotheses, and evaluate all formulas against their shared posterior. Reuse the mathematical scoring functions in [ProbabilityScorer](../../../src/Core/ProbabilityScorer.php), verified by Q21, without invoking live social-model services. This is new machinery; adapting existing proposition records alone will not supply it. The dependencies are an explicit transition/cost model, semantic annotations, and planning/inference code; cost grows with the goal–world product and per-hypothesis search.

Evaluate held-out layouts and paraphrases using a factorial comparison: full planning versus distance-only/prior-only inference, each with gold versus LLM-parsed formulas. Measure action log-loss, goal Brier score, formula truth-support error against exact enumeration, parser semantic accuracy, and latency/hypothesis count. Freeze the rationality parameter before holdout evaluation. **Failure criteria:** no held-out predictive improvement over the strongest cheaper baseline, materially degraded logical answers from parser errors, or exceeding a predeclared computation budget. This disentangles instrumental reasoning from translation quality, which the paper's evaluation does not isolate.

**P2 — Keep a shared posterior and a separate statement-evidence score.** If the prototype proceeds, represent one versioned world prior/posterior and identify any query-relative normalized likelihood as a different quantity. Proposed metadata attached to `OtherModelHypothesis` should record formula, observation cutoff, model/prior version, and score semantics; reporting belongs in `OtherModelEvaluator`. This follows directly from the 0.92 versus 0.86 prior comparison, but is an engineering synthesis rather than a paper-tested production design. It depends on P1's joint hypothesis model; scoring extra formulas is comparatively cheap once weights exist.

Evaluate queries with unequal base rates and logically related formulas. Under a fixed posterior, verify complement and inclusion–exclusion identities over represented worlds and agreement for equivalent formulas. Separately compare both score types with human ratings. **Failure criterion:** a purported common posterior depends on which question was asked, violates those identities beyond numerical tolerance, or silently replaces calibrated state uncertainty with a better-correlating response score. A normalized evidence score of 0.5 without distinguishing evidence must not erase a non-0.5 world base rate.

## Adoption risks and verdict

Do not transfer `b = s` to Aku's beliefs: a user can be uninformed, mistaken, strategically indirect, or pursuing an omitted goal. Treating every detour as rational evidence about a correct hidden world can create confident, wrong attributions. Supporting uncertain or false beliefs requires a separate actor information state and observation model; the authors identify this limitation rather than solve it. In that richer setting, failing to believe a proposition is also distinct from believing its negation. No general mental-state access or consciousness claim follows from this functional architecture.

**Verdict: experiment, with P0 as the immediate design correction.** The paper offers a precise standard that current coarse social forecasting does not meet: belief content must change the modeled behavioral evidence and logical answers. Its bounded human-fit result warrants a small compositional inverse-planning experiment, not a general-purpose social-model replacement or adoption of query-dependent scores as canonical belief probabilities.

**Coverage and verification:** all seven PDF pages read, including references; there are no appendices in this local version. Equations/filtering on p. 3, Fig. 2 on p. 5, and Table 1/Fig. 3 on p. 6 were visually checked against the PDF. No external artifacts, production code, runtime state, or tests were inspected or executed by this analyst. Implementation claims derive exclusively from the shared master; Q78 resolves the requested implementation clarification. Remaining evidence gaps concern the paper's unreported parser configuration, parameter-selection details, and resampling method. All recommended evaluations are prospective.
