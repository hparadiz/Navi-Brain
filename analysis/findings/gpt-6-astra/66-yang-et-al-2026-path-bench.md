# PATH-Bench: Path-Dependent Evaluation of Lifelong Agents

Xidong Yang, Xingyi Zhang, Wenhao Li, Wenyan Liu, Junjie Sheng, Yun Hua, Wei Yin, Tao Fang, Chuyun Shen, and Xiangfeng Wang. **2026; arXiv:2608.01149v1, 2 August 2026; local preprint, no publication venue established.**

Source: [local PDF](../../../assets/papers/66-yang-et-al-2026-path-bench.pdf). Reviewer: **GPT-6 Astra**. Implementation evidence: [shared reference](IMPLEMENTATION.md), snapshot **2026-09-06**, revision `ee1590009d6fd4c48035c63e15b3896a585757d7`. Page references below mean PDF pages.

## Contribution and mechanism

PATH-Bench makes a useful evaluation intervention: control the experience preceding a recurring task instead of treating an agent's accumulated memory as an incidental background variable. Its target is a frozen language model adapting through external memories or skills. For probe task \(q\), performance is a function of ordered history \(H\), \(P_q(H)\), rather than task identity alone (§3.1, p. 3). This is a benchmark and empirical harness study, not a theory of consciousness or a new parameter-learning algorithm.

The construction has three stages (§§3.2–3.3, pp. 3–4):

1. Select 120 tasks from each of BigCodeBench and WildToolBench with headroom for improvement.
2. Estimate directed one-shot transfer, \(M_{ij}=S(t_j\mid t_i)-S(t_j\mid\varnothing)\), independently using three frozen models and five seeds. A two-model majority determines positive, zero, or negative relationships; three-way disagreement is excluded.
3. Sample histories around an eligible probe. Measure it cold, provide five positive warm-up tasks, measure it again, then interleave further probes with experience drawn from a history whose dominant relationship contributes 70% of tasks. Only intervening tasks update learning state.

The main evaluation uses a 100-task intervening queue, probe intervals of 6–12 tasks, 50 sequences per dataset/condition, and five runs per sequence. All eight agents use DeepSeek-V4-Flash (§4, p. 4). Average performance (AP) includes every execution; transfer and forgetting describe only recurring probes.

Selective Experience Use (SEU) adds one task-conditioned model call between retrieval and inference. Its output either preserves experience, summarizes transferable information, or suppresses it, without writing the result to long-term memory. **The reproducible specification acts on the entire retrieved bundle:** Figure 11 explicitly forbids separately selecting or ranking its constituent items. This qualifies §4.3's description of judging “each retrieved item” (pp. 7, 12, 14). SEU is ephemeral context compilation, not memory deletion, utility learning, or consolidation.

## What the evidence supports

External experience is not uniformly beneficial. On BigCodeBench, AutoSkill obtains AP 73.93% versus the memory-free model's 70.18% under positive-dominant histories; AWM obtains only 65.56%. On WildToolBench, Clin reaches 46.29% versus 42.80%, while AutoSkill reaches 42.38% (Table 1, p. 5). These reversals justify evaluating representation and retrieval policy against the intended workload. They do not isolate abstraction level as the cause: the compared harnesses differ in multiple mechanisms, and task domain changes together with interaction structure.

Transfer and retention can diverge. For negative-dominant BigCodeBench, HippoRAG-v2 reports FWT 7.03 percentage points (pp) and FGT 16.15 pp. Controlled two-interval experiments use 30 matched sequences and five runs: seven agents' mean scores improve after a negative-to-positive transition, whereas five decline after positive-to-negative experience (Fig. 5, pp. 6–7). Several confidence intervals cross zero, so counts of mean directions should not become counts of statistically established effects. Matching probe identity also does not, by itself, establish an order-only intervention with identical source-task multisets.

SEU is evaluated on 20 of the original 50 sequences for three agents. Counting Table 2's twelve conditions, FGT improves in 12/12, FWT in 10/12, and AP in 8/12. AWM gains 4.77 pp AP under negative-dominant BigCodeBench; AutoSkill loses 2.18 and 3.32 pp AP in the two BigCodeBench conditions despite lower FGT (p. 7). The result supports an experiment with selective context use, not unconditional adoption. Table 2 supplies paired means without uncertainty intervals or latency/token costs; its subset must not be combined arithmetically with Table 1's full-sample baselines.

## Measurement limits and disagreements

**These metrics are a particular probe decomposition.** Writing \(c=p_{cold}\), \(w=p_{warm}\), and averaging subsequent probes, the definitions on p. 4 imply

\[
\mathrm{FWT}=\overline p-c,\qquad
\mathrm{BWT}=\overline{(p-w)_+},\qquad
\mathrm{FGT}=\overline{(w-p)_+}.
\]

Consequently,

\[
\mathrm{FWT}=(w-c)+\mathrm{BWT}-\mathrm{FGT}.
\]

This identity is a reviewer derivation, not an additional experimental result. BWT is clipped nonnegative, rather than conventional signed backward transfer; FGT measures shortfall from one warm-up reference, rather than decline from the best historical performance. FWT includes warm-up benefit. A trajectory can therefore show positive FWT and considerable FGT without contradiction.

More consequentially, **lower FGT alone does not identify preserved capability**. If SEU changes warm-up performance, its comparison anchor changes too: a lower \(w\) can reduce FGT without improving later absolute scores. The paper does not separately tabulate cold, warm, and later absolute probe scores for the paired SEU conditions. This is an unresolved explanation, not evidence that SEU actually lowered its warm-up reference. Positive-part clipping also produces apparent BWT and FGT under stochastic fluctuations around an unchanged expectation. Repeated frozen-state probes are needed to estimate that noise floor; the order of seed averaging and clipping matters. The memory-free baseline receives no transfer/forgetting entries in Table 1.

**Relationship labels are model-conditioned proxies.** Appendix B's agreement rates compare the three voters with their own majority, not independent judgments: at least two of three necessarily agree on every retained pair. Five-seed signs and an exact-zero neutral category have no uncertainty margin. Multi-task validation is stronger: on 50 BigCodeBench targets, positive prefixes produce mean gains of 8.8–13.7 pp and negative prefixes losses of 9.9–15.9 pp across lengths 3–12. But it tests in-context composition using a contributing model, not every agent's learned external state, and does not cover WildToolBench (pp. 11–12). The latter's negative-dominant curves sometimes outperform positive-dominant ones, reinforcing this distinction (Appendix C).

**Coverage and cost constrain transfer.** Headroom filtering and eligible-probe selection change the evaluated population; detailed difficulty thresholds are not supplied. The 120 WildToolBench scenarios have four turns each, far from open-ended deployment, and the code subset emphasizes scientific/data libraries (Appendix E, pp. 12–13). One probe reduces longitudinal scoring cost but misses forgetting elsewhere. Matrix construction itself remains quadratic: the stated off-diagonal design entails \(120\times119\times3\times5=214{,}200\) demonstration-conditioned task evaluations per domain, before zero-shot baselines. This is a calculated workload, not reported billing. The single evaluation backbone and missing SEU action/compute ablations limit generalization.

## Mapping to Navi-Brain

The architectural fit is substantial: Navi already changes external token associations, semantic records, working context, and typed procedures while using model workers. The relevant question is whether this accumulated state improves future behavior under controlled histories.

| Status | Verified implementation and implication |
|---|---|
| Implemented: history-dependent retrieval | [tokmem.c](../../../memories/src/tokmem.c), `query_memories:5631`, `scored_memory_better:5359`, and `increment_memory_access:2369` implement associative candidate reachability and counted retrieval. Effective distinct-record ordering is ACTIVATE span criteria, then access count, then updated time/ID; later weighted-score comparisons do not decide distinct-record order. Frequently read active records can gain priority without demonstrated usefulness (I02, Q57D). |
| Implemented: selective context, narrower than SEU | [DecisionStateMachine.php](../../../src/Core/DecisionStateMachine.php), `start:133–218`, retrieves up to eight memories before queuing a proposal. [CapsuleAssembler.php](../../../src/Core/CapsuleAssembler.php), `contest:446` and `score:579`, supplies bounded role competition using lexical coverage, confidence, recency, and hysteresis. These are actual selection mechanisms, not demonstrated semantic compatibility judgments (I03–I04, Q03, Q46, Q66). |
| Implemented: durable learning | [ExecutiveCore.php](../../../src/Core/ExecutiveCore.php), `integrateConsolidation:5020`, performs guarded episodic-to-semantic publication; [ProceduralMemory.php](../../../src/Core/ProceduralMemory.php), `observe:399`, learns verified typed action shapes. Source-grounding and adapter postconditions are narrower than independently scored task success (I05, I08, Q17, Q36). |
| Absent within the master's searched production scope | A chronological reset-task evaluator with frozen-memory controls and recurring held-out probes was not found. `DecisionStateMachine::compare:682` aggregates stored cycles, without executing such trials. Consolidation has no old-task behavioral acceptance gate; checkpoints are persistence barriers, not restore images (Q17, Q58). |
| Absent within inspected decision/context routes | The master's Q66 follow-up found no task-conditioned whole-bundle KEEP/SUMMARIZE/IGNORE stage. `DecisionStateMachine::start` publishes the first result to `supporting_evidence:180`, constructs the retrieved-memory prompt at `:210`, and queues the proposal at `:218` without an intervening gate. Searches also covered working-memory/context compilers; persistent consolidation and reflection have different semantics. |

The most immediate implementation hazard is **measurement changing the learning history**. ACTIVATE learns query links and increments selected-record access; RANK_RECORDS avoids candidate-read counts but still learns query usage/links (QMEM, Q57D). Calling either on the continuing agent for supposedly read-only probes violates PATH-Bench's central no-probe-update condition. A downstream SEU filter would also act after retrieval has already changed those counters: suppressing prompt content does not undo native reinforcement.

## Prioritized recommendations

**P0 — Establish an isolated history-and-probe evaluator before changing retrieval.** Use the existing decision retrieval/action IDs and verified adapter boundaries as instrumentation, while keeping task-success scoring independent of `compare` and adapter completion. The new research harness should run a small set of repeatable local task families through matched helpful, conflicting, neutral, and order-swapped histories, with matched task multisets where order is the variable. Build each measurement from a disposable copy of the full learning state and discard probe-side updates; a restore/export mechanism must first be specified and verified, since current checkpoints do not provide it. Include continuing-memory, fixed-memory, and memory-free controls, and occasional broader held-out sweeps. Cost: environment reset, state isolation, independent scoring, and repeated model calls. **Failure criteria:** any continuing-state change caused by measurement invalidates the run; apparent retention gains that disappear against the frozen-state noise floor or on absolute probe scores do not justify adoption. Report cold/warm scores, signed drift, clipped metrics, uncertainty clustered by probe/history, and the algebraic identity above.

**P1 — Experiment with SEU at the retrieval-to-proposal boundary.** Q66 locates the seam in `DecisionStateMachine::start` after retrieval IDs are fixed but **before both supporting-evidence publication and final prompt construction**. Filtering only the explicit retrieval block would leave the first memory able to reenter through workspace context appended by `enqueueWork`. Give one bounded compiler call the task and complete bundle; allow preserve, abstract, or ignore, retain source IDs, and keep output ephemeral across all its consumers. Preserve exact prerequisites, action authority, and verifier boundaries. Compare unfiltered retrieval, ignore-all, length-matched truncation, summary-only, and full SEU to distinguish semantic decisions from compression or extra inference. Cost: an extra model call, latency, and summary errors; it cannot recover records excluded by native top-k. **Proposed acceptance gate:** on held-out matched histories, the confidence interval for absolute probe improvement excludes zero, AP regression is less than a preregistered 1 pp margin, and added latency fits an agreed budget. Reject a benefit confined to lower FGT through a weaker warm-up anchor. These thresholds are proposed, not paper results.

**P2 — Separately test the access-reinforcement mechanism.** This is a Navi-specific synthesis motivated by the paper, not SEU's algorithm. In the isolated harness, intervene on native access-count ordering at `scored_memory_better` and distinguish ordinary exposure from task-credited utility; change one factor at a time. Include an old, frequently accessed active rule followed by a context-dependent correction, alongside helpful-repeat controls. Existing explicit supersession already excludes superseded records, so test active competition separately (Q57S). Log candidate inclusion, final rank, admitted context, and independently scored outcomes; Q60 confirms semantic principle utility is not currently learned from task success. **Failure criterion:** if removing access priority does not improve correction accuracy under paired histories, or loses comparable helpful reuse, reject that proposed ranking change. Do not infer that the paper's recency effects establish the right decay schedule for Navi.

## Verdict and coverage

**Adopt the controlled-history evaluation principle; experiment with SEU; defer production changes.** The priority is a probe mechanism whose reads cannot alter the continuing learning state, followed by absolute-score evaluation that distinguishes retention from movement of the warm-up reference. This paper gives a useful diagnostic design and promising intervention, but does not establish that a universal semantic filter or more abstract memories will improve Navi.

Coverage: the complete main text (pp. 1–8) and all Appendices A–F (pp. 11–14), including the complete SEU prompt. The metric equations, Table 2/Figure 5, and Figure 11 were visually checked in the PDF. Implementation claims come exclusively from the shared master's static reference, reread with the Q66 answer before finalization. No tests, experiments, runtime inspection, or production/memory changes were performed. No implementation question remains unanswered; live behavior, state-cloning support, and actual benchmark outcomes remain unestablished by this static review.
