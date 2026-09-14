# The Timing of the Cognitive Cycle

**Tamas Madl, Bernard J. Baars, and Stan Franklin (2011).** *PLoS ONE* 6(4): e14803, published April 25, 2011. DOI: 10.1371/journal.pone.0014803. Reviewed version: [local published PDF](../../../assets/papers/02-madl-baars-franklin-2011-timing-cognitive-cycle.pdf).

**Reviewer:** GPT-6 Astra. **Implementation evidence:** [shared implementation reference](IMPLEMENTATION.md), September 6, 2026, revision `ee1590009d6fd4c48035c63e15b3896a585757d7`, especially I02–I05, I10, Q01, Q02, and Q14. Implementation claims below come from that master's static inspection, not independent code inspection or runtime observation.

## Core contribution

The paper makes LIDA's perception–understanding–action cycle temporally explicit. Specialized modules operate asynchronously; attention selects content for a serial broadcast, which recruits applicable procedures and precedes action selection. Multiple cycles may overlap, while broadcast seriality is preserved. Higher cognition requires repeated cycles rather than one comprehensive deliberation. The conceptual cycle also includes outcome-monitoring expectation codelets and broadcast-conditioned perceptual, episodic, and procedural learning (pp. 1–5, “The LIDA Cognitive Cycle”; Fig. 4).

The proposed timing is clearest in Figure 6 (p. 7):

| Quantity | Proposed range | Endpoint and meaning |
|---|---:|---|
| Perception, P | 80–100 ms | Stimulus onset to initial recognition under simple conditions |
| Broadcast onset, C | 200–280 ms | Stimulus onset to conscious access; includes perception |
| Action selection, A | 60–110 ms | Broadcast onset to action chosen |
| Cycle duration, D = C + A | 260–390 ms | Stimulus onset to action chosen; excludes subsequent motor execution |

Consequently, adding P + C + A double-counts perception. C is a latency to broadcast, not the duration of consciousness. Similarly, theta-band rates of 4–7 Hz are proposed constraints on successive conscious episodes, not measurements of complete cycle throughput. Overlapping processing makes latency and recurrence interval different quantities (p. 2; pp. 5, 14).

A second important distinction concerns continuity. LIDA permits content to persist across discrete broadcasts according to recency and attention. It therefore rejects Stroud's stronger assumption of distinct, non-overlapping perceptual contents. Discrete publication need not erase previous evidence or its temporal relationships (p. 4, Fig. 3). This is a useful computational design principle even if the proposed discrete structure of human consciousness is wrong.

The software agents implement periodic module tasks in simulated millisecond “ticks.” Table 3 specifies sensory sampling at 20 ms, feature detection at 30 ms, attention codelets and a no-broadcast timeout at 200 ms, and procedural-memory processing at 110 ms (p. 14). Threshold and timeout triggers coexist in the broadcast mechanism. These are deliberately adjusted scheduling parameters; they are not measured execution costs of general perception, reasoning, or action selection.

## Evidence and limitations

**Neuroscientific synthesis.** The timing ranges assemble EEG, MEG, intracranial, TMS, behavioral, and oscillatory findings across different tasks and populations. This provides convergent plausibility for subsecond processing, but does not identify a unique universal cycle. Object-information decodability, disruption windows, awareness-related activity, and response onset are different observables. Subtracting their latencies only isolates an action-selection duration under assumptions about stage identity and dependence (pp. 7–9).

The broadcast range specifically uses the smallest and largest *lower limits* from selected results; later activity is assigned to action selection. The action range also excludes a derived 20 ms estimate as an outlier (pp. 8–9). These are interpretive working estimates, not confidence intervals or parameters inferred from a joint statistical model. The authors acknowledge simple stimuli, often without memory retrieval, and longer processing for more complex tasks (p. 6). Their theta–gamma interpretation does not establish that synchrony entails consciousness or that desynchronization creates experiential gaps. The comparison with ACT-R/EPIC also requires care: those architectures assign action selection and motor execution to differently named stages (pp. 9–10).

**Reaction-time agent.** Thirty simulated trials yield a mean of 283 ms, compared with the paper's 200 ms human reference: an 83 ms, approximately 41.5% excess (p. 11, Fig. 10). This is broad temporal compatibility, not close quantitative reproduction. The paper also discusses human simple reaction times of 190–220 ms and inferred cycle durations of 170–200 ms, below its proposed 260 ms lower bound (p. 9). Temporal expectation is offered as an explanation, but is not implemented and ablated; the discrepancy therefore remains unexplained by the demonstrated mechanism.

The limitation is deeper than sample size. The agents inspect symbolic environment state rather than process images; episodic and declarative memory are omitted; and every environment state supplies exactly one applicable action, eliminating action competition (p. 13, Methods). The 110 ms procedural interval contributes delay despite very short actual processing (p. 14). A timing fit under these conditions does not validate realistic recognition, memory retrieval, or decision complexity. The paper candidly states that parameter adjustment and consistent outputs do not prove its hypotheses, and proposes reproducing disparate datasets without retuning as the stronger future criterion (pp. 14–15).

**Allport agent.** The reproduced experiment varies a moving line's display period until motion disappears. Its display “cycle time” is distinct from LIDA's complete cognitive-cycle duration. Human data are imported from Allport's twelve participants; these are not newly collected human observations (pp. 11–13).

| Display condition | Human decreasing / increasing period, mean ± SD (ms) | LIDA decreasing / increasing (ms) |
|---|---|---|
| Half screen | 95.5 ± 16.0 / 81.4 ± 14.6 | 96 / 96 |
| Full screen | 86.2 ± 12.5 / 70.7 ± 8.1 | 84 / 84 |

Tables 1–2 (p. 13) support rejection of the particular twofold directional difference attributed to strict discrete moments. They do not establish equality between directions: humans show differences of 14.1 and 15.5 ms, whereas the model predicts zero. The statement that the human difference is “not significant” (p. 12) lacks a reported test. Indeed, under the stated repeated-participant design and a conventional paired-t interpretation of the reported SDs, the full-screen statistic must satisfy `t ≥ 15.5√12/(12.5 + 8.1) ≈ 2.61`, because the SD of differences cannot exceed the sum of marginal SDs. That exceeds the two-sided 5% threshold for 11 degrees of freedom. This is an arithmetic consistency check on the reported summaries, not a raw-data reanalysis; the original pairing, distribution, and intended statistical procedure remain unavailable here.

More conservatively, the simulation shows that retained percepts can make discrete broadcasts compatible with approximate simultaneity thresholds. It does not distinguish that architecture from continuous integration, establish phenomenal consciousness, or reproduce all human directional effects. The authors themselves specify *functional* consciousness for the artificial agent (p. 11).

## Transfer to Navi-Brain

The architectural transfer is stronger than the numerical transfer. Navi already has persistent state, asynchronous workers, bounded selection, and executable action verification. A 260–390 ms requirement would have no demonstrated validity for language-model proposals or memory-rich tasks.

**Already implemented:** [DecisionStateMachine](../../../src/Core/DecisionStateMachine.php), `start:34` and `integrate:254`, implements a narrow observe/retrieve/reason/evaluate/select/execute/verify/adapt loop. It snapshots workspace, retrieves evidence, queues a reasoning job to propose one to three installed terminal-action candidates, and deterministically integrates the result. Async completion has durable recovery. This is more than merely naming a cognitive cycle (I04–I05).

**Partially analogous:** [WorkingMemory](../../../src/Core/WorkingMemory.php), `publish:122`, `snapshot:201`, and `contextForWork:223`, maintains scoped, provenance-bearing slots with TTL. [CapsuleAssembler](../../../src/Core/CapsuleAssembler.php), `contest:446`, provides selection and hysteresis. This supports cross-cycle persistence and selective availability. However, it scores individual records with ordered cross-role exclusion, not LIDA's coalition competition (I03, Q01). Ordinary worker prompts receive workspace projections, with deliberate exclusions for evidence-fenced jobs; universal broadcast uptake is not established (Q14). Learning exists, but no shared accepted-broadcast gate was found across the inspected learning routes (I02, Q01).

**Timing instrumentation exists but is incomplete:** Q02 verifies that `DecisionStateMachine::elapsedMs:880` uses monotonic elapsed time. However, `reason_model_ms:273` measures second-resolution wall time from queue creation to integration, including waiting and other processing. `total_ms:773` sums stage fields; async finalization in [ExecutiveCore](../../../src/Core/ExecutiveCore.php), `finalizeAsyncDecisionCycle:3934` and its calculation at `:4033`, can omit the full sensor wait. Existing worker latency measurements have different scopes and lack a reliable common per-job correlation. These fields cannot yet answer the paper's basic endpoint question: elapsed from which event to which event?

**Absent in the inspected decision integration path:** Q02 finds no comparison of the saved workspace checksum with the current workspace before accepting a proposal. `integrate:254` checks cycle/work identity and adapter validity, then publishes `current_plan` and `decision_basis` before selection. New contradictory evidence can therefore coexist with acceptance of an older, still contract-valid proposal. This is a static reachable-path finding, not a reproduced incident. Per-intention start exclusion is not demonstrated atomic, and existing work/action leases do not establish global version-ordered publication. Other integration paths do have distinct freshness checks; this conclusion is scoped.

**Not verified:** live timing distributions, effective stored scheduler settings, actual contention, and stale-action incidence. The 30 s pulse and 60 s decision defaults in `ExecutiveCore::ensureDefaultRhythms:12618` are scheduling intervals, while the C memory engine's 250 ms maintenance interval serves another subsystem entirely (I02, I04). Neither establishes a cognitive frequency.

## Prioritized recommendations

All evaluations below are proposed; none was performed for this review.

**1. Adopt explicit timing semantics before changing cadence.** Extend `DecisionStateMachine::advance`, `ExecutiveCore::claimWork`, `finishWork`, and typed integration with correlated events for observation availability, queueing, attempt claim, model start/end, integration, dispatch, and outcome observation (I04, I10, Q02). Retain cycle/work/attempt/fence identities. Separate queue delay, model service time, integration delay, and external wait; preserve overlapping spans rather than adding them as sequential stages. Use monotonic durations within a clock domain and identify clock provenance for cross-process/restart boundaries. This follows directly from the paper's separation of onset, stage duration, and recurrence. Cost is additional event storage and instrumentation across workers, not more inference.

Proposed validation: offline traces with injected queue stalls, retries, delayed sensor completion, and clock discontinuities must account for known waits without duplication. Fail if a known wait disappears from end-to-end latency, if provider timings remain incomparable, or if uncertain intervals are reported as precise durations. These measurements must precede any claim that faster scheduling improves cognition.

**2. Experiment with version-checked acceptance at relevant commitment boundaries.** Before `DecisionStateMachine::integrate` publishes proposal-derived workspace content, validate the versions of evidence and intention state on which the proposal depends. Classify updates as irrelevant, revalidatable, or invalidating; a checksum difference alone should not force regeneration. Couple acceptance atomically to the relevant state/resource version and carry its fence to dispatch. Reuse existing lease and projection-replay mechanisms (I02, Q02). This is engineering synthesis inspired by overlapping cycles with ordered selection, not a mechanism demonstrated by the neuroscience.

Cost includes dependency tracking and occasional discarded inference. Proposed validation: delay a proposal while inserting contradictory evidence, then deliver competing results out of order. Fail if the obsolete proposal publishes an actionable plan or dispatches without revalidation, or if two incompatible commitments consume the same exclusive version. Also measure unnecessary regeneration on unrelated updates. Do not serialize every worker behind a global reasoning lock: scope ordering to actual conflicts.

**3. Experiment with task-dependent scheduling under a fixed inference budget.** At `ExecutiveCore::runDueHeartbeats` and `runDueCognitiveThreads`, compare existing periodic scheduling with bounded event-triggered eligibility plus a maximum-silence fallback, reflecting the paper's threshold/timeout combination (p. 14). Coalesce redundant events while retaining contradiction and ordering information. Keep inexpensive sensing/state updates independent of slow proposal completion, and preserve existing working-memory carryover. Dependencies are recommendations 1–2 and an explicit starvation/backpressure policy; increased model-call frequency is a cost to control, not a success metric.

Freeze the scheduling parameters before evaluating held-out immediate-response, delayed-recall, and changing-evidence scenarios. Compare p50/p95 observation-to-validated-action latency, missed deadlines, stale commitments, independently judged task completion, and inference cost. Ablate content carryover separately to distinguish scheduling gains from lost context. Reject the change if tail latency fails to improve at matched cost, task quality falls, or backlog grows without bound. This transfers the authors' fixed-parameter, cross-task validation aspiration rather than their fitted millisecond constants.

## Verdict

**Adopt the timing distinctions; experiment with acceptance ordering and bounded scheduling; reject literal biological retiming as an implementation requirement.** The strongest immediate finding is that Navi's existing timing fields mix measurement scopes while delayed proposals lack a general snapshot-freshness check in the inspected integration path. Establish a trustworthy causal timeline first. The paper supplies useful architectural constraints, but its calibrated toy simulations and overstated Allport agreement do not warrant a consciousness claim or a universal executive clock.

**Coverage and unresolved evidence:** Read the complete 16-page local paper, including Methods, all tables and figure captions, and references; no appendix is present. PDF and printed page numbers coincide. Visually verified Figure 6 and Tables 1–2 against the PDF. Re-read the shared implementation reference before finalization. No consequential code question remains unanswered by that reference; runtime behavior, original Allport participant data, and unreported simulation variability remain unverified. No tests, runtime actions, or production changes were performed.
