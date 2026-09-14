# OMNI: Open-endedness via Models of human Notions of Interestingness

Jenny Zhang, Joel Lehman, Kenneth Stanley, and Jeff Clune. First released 2023; reviewed local version: **arXiv:2306.01711v3, 15 February 2024**, a preprint with no conference venue identified in this PDF. The filename's year does not identify the reviewed revision.

Source: [local PDF](../../../assets/papers/51-zhang-et-al-2023-omni.pdf). Reviewer: **GPT-6 Astra**. Implementation evidence: [shared reference](IMPLEMENTATION.md), snapshot **2026-09-06**, git HEAD `ee1590009d6fd4c48035c63e15b3896a585757d7`; relevant sections re-read before finalization. Code claims below come exclusively from that static review.

Coverage: main text §§1–6, PDF pp. 1–13, and all appendices A–S, pp. 20–47, including prompts, algorithms, optimization details, ablations, and limitations. Equations/Algorithm 1 and embedded examples were visually checked on pp. 20, 27, 40, and 47. PDF and printed page numbers agree. Bibliographic references were not independently followed. No experiments, tests, or runtime inspections were performed.

## Core idea and mechanism

OMNI adds a learned semantic prior to automatic curriculum selection: among tasks on which an agent can improve, preferentially practice those that expand its useful behavioral repertoire. A foundation model serves as a **Model of Interestingness (MoI)**; a separate task-conditioned policy learns through environment interaction using PPO. The foundation model does not become the acting policy, and interestingness judgments do not themselves constitute acquired skills (§§3–4, pp. 4–8).

The finite-task mechanism has two components:

1. **Measured competence change.** Normalize evaluated success against a random-action baseline, `q = (p − p₀)/(1 − p₀)`. Smooth `q` with an EMA, then smooth that EMA again, both with coefficient 0.1. The intended progress statistic is `L = abs(f(p_recent) − f(p_gradual))`, where `f(p) = 0.9p/(p + 0.1(1 − 2p))`. This emphasizes changes at low success probabilities; the absolute value also prioritizes deteriorating performance. Appendix A specifies z-scoring, a sigmoid, and normalization to produce sampling probabilities (p. 20).
2. **Capability-relative redundancy filtering.** Algorithm 2 sorts tasks by smoothed success, selects the highest-success unclassified task as an interesting representative, and asks the FM which remaining tasks are boring relative to the selected representatives. It repeats until all tasks are classified. Thus the prompt's “tasks done well” can be relative representatives, not tasks exceeding a universal mastery threshold. Interesting tasks retain weight 1; boring tasks receive 0.001; these factors multiply progress-based weights before normalization (§3.3; Appendices F–G, pp. 27–28). GPT-3 Davinci supplies Crafter judgments; GPT-4 supplies BabyAI judgments, with responses cached across runs.

Appendix A's displayed progress expression has malformed parentheses; the verbal definition and visually checked Algorithm 1 support the difference-of-transforms interpretation above. Algorithm 1 also omits the sampling transformation detailed in Appendix A. Neither presentation resolves every implementation edge case, including zero progress variance, baseline success 1, or normalized values below zero.

For AI2-THOR, GPT-4 instead **generates** three proposed tasks and corresponding sequences of environment-state predicates, conditioned on tasks above 0.6 success and attempted tasks not yet mastered. LP samples the growing catalog (§5, pp. 9–12). “Infinite” refers to unbounded predicate-sequence descriptions within a fixed simulator, not measured indefinite innovation or unrestricted environment creation.

My interpretation: the strongest contribution is allocating practice according to both measured capability change and contextual redundancy. It assumes repeated comparable evaluations, an effective learning mechanism, informative task descriptions, and an MoI that understands the learner's limitations.

## Evidence and limitations

The main finite-domain experiments use 100 million environment steps and ten seeds per treatment. Reported uncertainty is a 95% bootstrap interval for the median, using 1,000 resamples (§4.3, p. 8).

| Outcome at training end | LP alone | OMNI |
|---|---:|---:|
| Crafter tasks with success ≥0.2, interesting plus boring | 55 [54–56] | 82 [80–87] |
| Crafter mean success, interesting plus boring | 0.42 [0.41–0.43] | 0.56 [0.54–0.58] |
| Crafter interesting tasks with success ≥0.2 | 9 [9–11] | 14 [14–14] |
| BabyAI tasks with success ≥0.2 | 4 [4–6] | 8 [7–10] |
| BabyAI mean success over 1,364 tasks | 0.0059 [0.0055–0.0062] | 0.0075 [0.0073–0.0077] |

The paper reports OMNI–LP differences of **p < 0.001** across these metrics at four training checkpoints. These are meaningful gains over LP under the constructed task distributions, although eight learned BabyAI tasks remains limited absolute competence. Crafter's quoted success averages cover the **105 interesting-plus-boring tasks**, not its additional 1,023 always-failing distractors; the broader “all tasks” wording elsewhere should not obscure that denominator.

Several qualifications materially narrow the headline:

- **The task ecology favors redundancy filtering.** Main Crafter removes survival, designates 15 technology-tree tasks interesting, adds 90 numerical repetitions, and assigns 1,023 extremely challenging tasks success zero by assumption (§4.1, p. 6). This isolates a useful pathology but is not a natural distribution of discoveries. Survival restoration is only three seeds at 30 million steps (Appendix P, pp. 43–44).
- **The oracle is a designed curriculum, not independent human validation.** It prioritizes the 15 Crafter tasks or BabyAI's single instructions. Matching it supports recovery of these domain heuristics; nonsignificant differences do not establish equivalence or a general human-interest model (Appendix M, pp. 33–34).
- **Cheaper semantic filtering sometimes suffices.** Sentence embeddings plus OPTICS clustering match OMNI on numerical repetitions but underperform on compound tasks (Appendix Q, pp. 44–46). This justifies evaluating contextual reasoning where compositional redundancy matters, not treating all embedding approaches as inadequate.
- **The learner model can be wrong.** With synonymous descriptions, unmodified OMNI achieves mean success 0.31 versus LP's 0.32 over interesting-plus-boring tasks. The MoI assumes semantic transfer that the bag-of-words learner lacks. Explicitly informing it of that limitation raises OMNI's result to 0.43 and learned-task count from 307 to 393 (Appendix O, pp. 37–40). This is the most consequential transfer result for Navi.

The purported automatic diagnosis deserves additional skepticism. In **Figure 24, p. 40**, GPT-3 explains that “build” requires more resources and knowledge than “acquire,” although the experimental tasks are synonyms with the same success conditions. Visual inspection reveals a persuasive but unsupported causal explanation. Its subsequent useful reclassification does not establish that it diagnosed the learner's representation correctly. Figure 33's integrated learnability judgments likewise remain illustrative outputs, not a controlled learning result (Appendix R, p. 47).

AI2-THOR reports 13 [11–17] learned tasks for OMNI versus 2 [0–3] for LP and 0 [0–2] for uniform sampling after one million steps and ten seeds (§5.6, p. 12). However, each method is evaluated on **its own previously sampled tasks**. OMNI jointly changes difficulty, task content, and redundancy; a common held-out task bank is absent. The experiment uses one kitchen floorplan and automatically selects target objects using the current task (§5.2), reducing action-grounding difficulty. It demonstrates productive task generation within these constraints, not superiority on a common general-capability distribution.

Compute is substantial: approximately 33 GPU-hours per Crafter run, 60 per BabyAI run, and 24 per AI2-THOR run, with 30 virtual CPUs. API prices and caching are documented, but a complete matched total-cost comparison is not. These are not evidence that more background language-model calls alone produce improvement.

## Mapping to Navi-Brain

The transfer belongs at **practice-task allocation**, above bounded learning operations. Navi's executive has several learning mechanisms, but the master found no backbone parameter-training interface ([I02, I08, Q14](IMPLEMENTATION.md)). Repeated prompting must therefore be evaluated for gains mediated by retained memory or reusable procedures, not assumed equivalent to PPO updates.

| Implementation status | Verified boundary and implication |
|---|---|
| Implemented: durable outcome evidence and bounded skill acquisition | [ExecutiveCore.php](../../../src/Core/ExecutiveCore.php), `finishAction:2281`, records action outcomes and episodes. [ProceduralMemory.php](../../../src/Core/ProceduralMemory.php), `observe:399`, compiles typed action shapes after three verified successes; failures can invalidate procedures. Useful raw evidence, not per-task competence estimates ([I08, Q36](IMPLEMENTATION.md)). |
| Partial substrate: persistent tasks and scheduling | `ExecutiveCore::createIntention:1385` stores success/release text; `enqueueWork:6318` supplies durable bounded jobs. The master's insertion search found only an explicit CLI caller, not internal autonomous intention generation ([I05, I10, Q32](IMPLEMENTATION.md)). A proposed curriculum catalog would be additional machinery. |
| Implemented but different: novelty and motivation controls | `ExecutiveCore::validateMindStreamMonologue:10705` checks repetition with recent-history token overlap and new-evidence exceptions; rejection causes backoff. `daydream:5489` reduces novelty pressure after artifact construction/reuse. [MotivationCompiler.php](../../../src/Core/MotivationCompiler.php), `rankCandidates:200`, uses fixed weights and action-count investment. These are not verified learning progress or OMNI filtering ([Q09, Q31, Q42](IMPLEMENTATION.md)). |
| Absent in the master's stated search scope | No per-goal/family competence-progress estimator or outcome-driven adaptive goal sampler was found across the traced motivation, intention, procedure, and thread paths ([Q20, Q36](IMPLEMENTATION.md)). Existing thread uncertainty reduction and accepted-refinement counts must not supply counterfeit success probabilities. |

The current four typed adapters and their verifiers establish operational contracts, not arbitrary intention achievement ([I05](IMPLEMENTATION.md)). In particular, a successful observation command or published semantic record need not mean a useful skill was learned. Live performance and available densities of comparable trial evidence remain **unverified**. No consequential code question remained unanswered by the shared reference.

## Prioritized recommendations

**P0 — Establish measurable practice before adding an interestingness judge.** In an isolated experimental consumer of `finishAction`/`ProceduralMemory::observe` evidence, define task-family and variant IDs, learner/model/procedure generation, verifier identity, attempt outcome, and cost. Choose an actual changeable learner component, such as procedure acquisition. Measure it on independently scored, held-out variants before and after practice. Estimate the two-timescale progress signal from comparable evaluations; distinguish gains from forgetting and mark insufficient evidence unknown. Define numerical edge cases explicitly. Dependencies are independent task verifiers and repeated evaluation, likely the dominant cost. **Failure criterion:** if equal-budget practice cannot improve held-out success over a frozen-learning control, stop; optimizing task choice has no demonstrated learning substrate. Stationary noisy histories must not systematically receive higher progress scores than controlled improvement histories.

**P1 — Compare a capability-conditioned MoI with inexpensive selectors.** Once P0 succeeds, add an experimental selection stage before `ExecutiveCore::enqueueWork`, scoped to a bounded candidate catalog under an existing intention. Give the judge verified performance by variant, available adapters, representation limitations, and unresolved failures. Ask for redundancy judgments with evidence references and an unknown option. Cache against model, prompt, catalog, and capability-summary versions. Compare uniform allocation, LP, LP plus cheap family/embedding filtering, and LP plus MoI at matched total cost. Include synonyms, numerical repetitions, compositions that transfer, and compositions that require new coordination. Evaluate on the same held-out tasks across arms. **Adoption criterion:** a positive confidence interval for held-out competence gain per budget over both LP and the cheap filter, without increased forgetting. Inconclusive results block adoption; a reliably negative effect falsifies the proposed benefit. An explicit exploration floor and a separate maintenance allocation are proposed adaptations: the paper's 0.001 penalty should not silently starve unfamiliar or deteriorating tasks.

**P2 — Defer open task generation until success contracts are trustworthy.** If P1 warrants expansion, model proposals should pair task descriptions with typed predicates over authorized observable outcomes, reviewed through the existing proposal/adapter boundaries in `ProceduralMemory::inspectCandidate:138` and `executeAction:736`. A predicate language/compiler would be new work; task acceptance must not install arbitrary executable verifiers or create authority. The paper's “slice the potato with the knife” example checks knife visibility and potato slicing, which does not establish knife use (§5.3, p. 11). Require an independently adjudicated bank of successful, partial, wrong-object, wrong-order, and merely already-satisfied traces. **Failure criterion:** any accepted counterexample that passes while violating the task's essential success condition blocks that template. This dependency is more consequential than generating a larger catalog.

All three are proposed experiments or design constraints; none was implemented or evaluated in this review.

## Risks, disagreement, and verdict

OMNI replaces a narrow explicit interestingness formula with a learned prior; it does not escape optimization against a fallible measure. The authors acknowledge this and propose future human-feedback updates (§6, p. 13; Appendix S, p. 47). Internet-derived appeal can suppress unfamiliar but valuable practice, and “boring” composition may actually teach coordination. The synonym experiment demonstrates that the judge's own competence cannot stand in for the learner's. Interestingness also supplies no authority to displace required work.

**Verdict: experiment, with P0 first; no immediate production change.** Adopt the separation of measured learnability and capability-relative redundancy. Reject the stronger inference that fluent judgments establish a general drive for worthwhile discovery, verified self-improvement, indefinite open-endedness, or consciousness. The decisive next step is demonstrating repeatable, independently verified learning in Navi's actual retained mechanisms before allowing a model to ration its practice.
