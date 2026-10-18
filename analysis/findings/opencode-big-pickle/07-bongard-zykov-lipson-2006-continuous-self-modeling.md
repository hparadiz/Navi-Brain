# 07 — Continuous Self-Modeling as a Damage-Triggered Model–Test–Predict Loop

- **Paper**: Resilient Machines Through Continuous Self-Modeling
- **Source**: DOI 10.1126/science.1133687, Science 314(5802):1118–1121, 2006
- **Local file**: assets/papers/07-bongard-zykov-lipson-2006-continuous-self-modeling.pdf

## Core idea

A four-legged physical robot learns its own body — topology *and* limb parameters — with no manual model, no built-in redundancy, no dedicated sensor arrays, and no contingency plans. It infers morphology indirectly from actuation–sensation relationships via three components run continuously: **modeling** (stochastic optimization synthesizes ~15 competing candidate self-models to explain observed sensor data), **exploratory action synthesis** (pick the next motor command that maximizes disagreement across the candidate models' predicted sensor signals), and **behavior synthesis** (the best model generates locomotion). When damage causes unexpected sensor–motor patterns, the modeling cycle reinitiates, recovers the changed topology/parameters, and synthesizes a qualitatively new compensating gait.

The headline result is active, directed data acquisition: the model-driven algorithm infers correct topology in 43.3% of 30 runs vs 26.7% / 23.3% for two random-action baselines at equal compute (~250k internal simulations, 16 physical actions each), and after damage reaches lower mean model error (2.17 ± 0.55 cm vs ~4.5 / 5.6). Because the robot cannot observe ground truth about its own body, it uses a proxy: disagreement among candidate models correlates with actual model error (Spearman ρ = 0.425, P < 0.02). No one knows which model is right — the *spread* is the signal.

## Relevance to Navi-Brain

Touches the self-model and repair substrate head-on: `SelfModelFact` (evidence + confidence), `ActionTrace` expected-vs-observed mismatch, discrepancy repair in the event loop, the repair-proposal artifacts, the clocked reflection/consolidation rhythms, and the longitudinal self-repair evaluation. It is a paper about *re-modeling after damage*, which is exactly the loop `docs/experiments/03-cognitive-self-repair.md` is trying to isolate.

## Cross-reference to the existing system

- **Already present**
  - The mismatch primitive: `ActionTrace` carries expected vs observed, `match_status` = matched/mismatched, and a `repair_note` (`src/Model/ActionTrace.php`; `action:finish` at `src/Core/ExecutiveCore.php:393-436`). This is Navi's equivalent of the robot's "unexpected sensor-motor patterns."
  - Bounded, non-committal repair proposals: `createRepairArtifacts` emits `diagnostic_repair` and `prediction_counterfactual` artifacts, the latter literally reading "before repeating action %d, apply this candidate repair… then test whether it reduces the recorded expectation mismatch" (`ExecutiveCore.php:3646-3679`). That sentence *is* the paper's test-the-repair move. It just never runs.
  - Evidence + confidence self-model: `SelfModelFact` has a `confidence` decimal plus `evidence_event_id` (`src/Model/SelfModelFact.php`), mirroring the paper's best-model selection with a convergence criterion.
  - Clocked re-audit: `reflect_5m`, `consolidate_hourly`, and the `sleep_daily` deep replay pass (`ExecutiveCore.php:2398-2411`) re-examine the self-model on a schedule, which is the paper's "continuous" but on wall-clock, not event-driven.

- **Partially present**
  - Damage-triggered re-modeling: the paper reinitiates model synthesis *when* unexpected patterns appear, cycling until models re-converge. Navi records the mismatch and proposes a repair artifact, then waits for the next clocked high-brain pass. The repair loop is single-flight and periodic, not damage-triggered.
  - Competing candidate models: `self_model_facts.fact_key` is unique (`SelfModelFact.php:18-23`) — one canonical model per fact. The paper's engine is an ensemble of ~15 candidates whose *disagreement* drives everything. Navi has no ensemble, hence no disagreement signal.
  - Active probe selection: the paper's headline is choosing the next action to maximize model disagreement. Navi's retrieval is passive ranked lexical search (`memory:search`, a documented boundary), and nothing decides *which evidence to acquire next* for maximal disambiguation. The counterfactual artifact is proposed but never executed as a probe, and nothing measures whether it actually reduced the mismatch.

- **Absent / open design**
  - Disagreement-as-error-proxy metric: no cross-model variance or confidence spread anywhere; `docs/longitudinal-cognition-metrics.md:110` ("Contradiction resolution … when active records disagree") and :266 ("Perspective uncertainty calibration — match between confidence and correctness") are seeds but measure record-level conflict, not competing models of the same fact.
  - The continuous behavior↔model cycle: the paper's three loops run *while behaving*. Navi's cognitive threads are designed but not wired into a running loop, so there is no live model-driving-behavior arc at all.

## Concrete implications for implementation

- Cheapest, highest-value move: turn the existing `prediction_counterfactual` artifact (`ExecutiveCore.php:3673`) into a real probe. When a mismatched action is re-attempted after a candidate repair, write the mismatch delta back and update the artifact's confidence (currently a flat 0.5). That is the paper's model→test→predict cycle at the granularity Navi already supports.
- Add a damage-triggered self-model re-audit: when mismatched traces spike or a heartbeat misses (the bounded repair signal already exists), schedule a high-brain self-model synthesis pass instead of waiting for the next clocked rhythm. Reuse the existing single-flight lease + fencing tokens; no new authority.
- Add a small shadow ensemble behind the self-model: keep 2–3 candidate values per `fact_key` with per-candidate confidence and a disagreement (variance) column, picking the canonical one for behavior. Gives a real "when to probe" signal without a full free-model swarm.
- Add a longitudinal metric mirroring ρ = 0.425: measure correlation between self-model confidence spread and actual correctness over time in `docs/longitudinal-cognition-metrics.md`, and use `docs/experiments/03-cognitive-self-repair.md` (H2 diagnosis) to test whether confidence spread predicts *where* an injected fault lives.
- Frame retrieval as probe selection in `docs/experiments/02-causal-memory-value.md`: the paper is direct evidence that directed data acquisition beats random sampling, which argues for evaluating query-choice strategies, not just retrieval quality.

## Risks / tensions

- Active probe selection means choosing *what information to acquire next*. For a robot that's a motor command; for Navi that's web/file fetches or user attention — which collides with the safety invariants (no autonomous external tool use, user authority outranks). Probes must stay bounded proposals, never autonomous acquisition.
- The robot gets cheap physical trials; Navi's "trials" are model invocations and user interactions that cost attention and tokens. Aggressive disagreement-seeking could turn into nagging or manufactured doubt. Disagreement should gate *internal* probing, not external speech.
- Maximizing disagreement as a goal can conflict with "no recursive worker spawning" if implemented as cascading probe workers. Keep the probe pool bounded.
- Evidence is one physical system, n = 30 experiments, one robot, and a modest correlation (0.425). It's a well-executed but narrow result — treat the loop discipline as a design principle, not a proven requirement.
- The paper re-models a *body*; Navi re-models beliefs whose ground truth is user judgment. Disagreement is only a useful proxy if the environment eventually corrects the model — which for Navi means the probe must actually reach reality, not just re-read the same store.

## Bottom line

Partially adopt. The takeaway is not the 15-model ensemble but the loop discipline: mismatch → targeted probe → measure the delta → update confidence, which would turn Navi's currently inert counterfactual repair artifacts into a genuine model–test–predict cycle and hand the longitudinal program its first disagreement→error metric. Full query-by-committee ensembles are worth monitoring, not building now — the safety and cost boundaries aren't ready for autonomous probe selection.
