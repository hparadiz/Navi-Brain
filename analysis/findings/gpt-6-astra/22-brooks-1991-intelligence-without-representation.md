# Intelligence without representation

**Author:** Rodney A. Brooks. **Publication:** *Artificial Intelligence* 47 (1991), 139–159; received September 1987. **Local version:** a 12-page reformatted PDF whose experimental status is explicitly mid-1987. References below use **PDF pages**, not journal pagination. **Source:** [local paper](../../../assets/papers/22-brooks-1991-intelligence-without-representation.pdf). **Reviewer:** GPT-6 Astra. **Implementation evidence:** [shared implementation reference](IMPLEMENTATION.md), snapshot 2026-09-06, git HEAD `ee1590009d6fd4c48035c63e15b3896a585757d7`; static inspection by the implementation master, not runtime validation.

## Assessment

Adopt Brooks's unit of engineering progress: a complete, environmentally grounded behavior that continues working when another capability is added or unavailable. Experiment with that principle inside Navi's existing executive. The paper does **not** justify removing persistent memory, intentions, or model-based reasoning. Its strongest contribution is a construction and validation methodology; its broader rejection of representation outruns its evidence.

## Core idea and mechanism

Brooks separates an observation about simple intelligence—explicit world models can obstruct useful behavior—from the hypothesis that representation is the wrong abstraction for the largest parts of intelligent systems (§1, p. 1). The proposed decomposition cuts across the conventional perception–reasoning–action pipeline. Each *activity-producing layer* connects sensing to action and decides when to operate, rather than waiting to be invoked as a centrally selected subroutine (§4.2, p. 5). Start with a complete obstacle-avoiding robot; add wandering and exploration while retaining the earlier behavior.

The implementation is concrete: fixed-topology networks of asynchronous finite-state machines, with a few states, registers, timers, simple computations, and fixed-length messages. Machines have no global data access or dynamically created communication links (§6.2, pp. 7–8). Two distinct wiring operations compose layers:

- **Suppression** substitutes an incoming message and rejects the original input stream for a specified interval.
- **Inhibition** blocks an output stream for a specified interval without substituting the new message.

These are timed interventions on particular channels, not a global ranking of behavior labels. In the worked example, twelve sonar ranges are sampled every second. Repulsive forces drive avoidance; wandering adds an attractive heading roughly every ten seconds; exploration monitors actual travel and corrects departures from its desired path while avoidance remains operative (§6.2, pp. 8–9, Fig. 2). Exploration also inhibits wandering during observation so that the observation remains valid. This is a particularly useful detail: maintaining an observation's validity is part of control itself.

“Without representation” requires a narrow reading. Brooks acknowledges implicit encodings but excludes them from traditional AI representation (§5.1, pp. 6–7). Yet the controller has registers, an instantaneous polar sonar “map,” desired directions, and integrated path estimates. The demonstrated departure is from a centralized, explicit symbolic world description and its reasoning interface; it is not statelessness or the absence of internal information about the world. Whether these local quantities count as representations depends on the definition, not the robot's performance.

Using the world as its own model works where relevant conditions can be sensed again promptly and local feedback can repair deviations. Dependence on hidden history, inaccessible events, or irreversible actions weakens that substitution. The paper's claim that additional goals impose no processing penalty also assumes separate hardware per layer (§5, p. 6); it does not eliminate hardware, communication, energy, or interaction costs.

## Evidence and limits of inference

The paper reports four robots comprising three designs, operating around people in MIT laboratory and office environments (§6.2, pp. 7–8, Fig. 1). One depends on an offboard LISP machine; autonomy therefore does not mean every computation is onboard. Sonar specular reflections and moving obstacles provide meaningful departures from idealized symbolic inputs. The three illustrated layers reportedly had been run on the first robot for well over a year; this is not a quantified year-long uninterrupted reliability trial.

The decisive scope statement is §8.1 (pp. 10–11): **three layers had run on a physical robot; six had run in simulation**. The roughly fourteen-layer office soda-can collection behavior is work in progress (§8.2, p. 11), not a completed benchmark. Learning is demonstrated only as an isolated subsystem with unconnected interfaces; Brooks explicitly acknowledges that this violates his own methodological standard (§8.3, p. 11). Neither scalable autonomous learning nor human-level cognition is established.

The evidence supports the feasibility of useful mobile behavior without a central symbolic model. It does not isolate the causal contribution of removing representation: there is no controlled comparison holding sensors, compute, task, and engineering effort constant. The paper supplies no collision-rate table, trial counts, uncertainty intervals, standardized robustness curve, or measured comparative latency. “Most reactive” is the author's mid-1987 assessment (§8, p. 10), not a reproduced comparative result. The setting is physically real and dynamic but remains a limited family of office environments.

Two further arguments deserve resistance. Evolutionary elapsed time is not an engineering complexity measurement; long periods before language do not show that language becomes easy once locomotion exists (§2, p. 2). Likewise, debugging a lower layer cannot exclude failures exposed only by a new layer's resource use or intervention (§6.1, p. 7). Even the example's halt message may be ignored outside the appropriate machine state (§6.2, p. 8): the architecture alone is no unconditional safety guarantee. The categorical rejection of simplified environments is also stronger than necessary: controlled simulations can isolate faults, provided success there is not substituted for deployment evidence.

## Mapping to Navi-Brain

The appropriate transfer is architectural and methodological. Navi's operating system, authorized sensory streams, and actuator feedback can supply real environmental contingencies without a mobile chassis. Language, durable commitments, and historical recall nevertheless demand capabilities outside this paper's demonstrated domain.

All implementation claims below come from the shared master; no source tree or runtime was independently inspected.

| Mechanism | Verified implementation and status | Consequence for this paper |
|---|---|---|
| Complete action cycle | **Implemented, narrowly:** [DecisionStateMachine.php](../../../src/Core/DecisionStateMachine.php), `start:34`, `integrate:254`, connects observation/retrieval, model proposals, deterministic selection, execution, verification, and adaptation. Four installed adapters bound the action vocabulary (I04–I05). | Navi already has a complete narrow loop. Brooks asks whether each useful behavior remains competent under realistic disturbances, beyond merely possessing named stages. |
| Situated sensing and feedback | **Implemented pieces:** [SensoryCortex.php](../../../src/Perception/SensoryCortex.php), `ingest:234`, `evaluate:311`, and [ForwardModel.php](../../../src/Perception/ForwardModel.php), `observe:35`, process readings and forecast errors. Workspace use feeds sensory tuning through `recordOutcome:860` and `tune:945` (I07, Q04). | These are actual feedback paths. They do not establish independent activity producers with direct control of actuators or bounded end-to-end response time. |
| Behavioral suppression | **Partial functional analogy:** [ActionSelector.php](../../../src/Core/ActionSelector.php), `choose:43`, returns losing actions as suppressed; [ExecutiveCore.php](../../../src/Core/ExecutiveCore.php), `selfPresenceSpeechGate:11420`, uses selection to refuse speech (I04, Q08). | A non-speech winner there does not dispatch every alternative. This differs from Brooks's persistent parallel behaviors and timed channel substitution. |
| Current-world validity | **Scoped gap:** `DecisionStateMachine::integrate:254` checks cycle/work identity and adapter contracts but does not compare its saved workspace checksum with the current workspace or enforce a general new-observation check (Q02). | Contract-valid delayed proposals can survive a changed situation. Existing consolidation source-hash checks are a separate, stronger guarantee for their own evidence (I08). |
| Independent inhibition at dispatch | **Absent in inspected interrupt/dispatch paths:** `ExecutiveCore::raiseInterrupt:5793` records an interrupt; decision integration and `ExecutiveCore::claimActionDispatch:797` do not consume it as a universal veto (Q23, Q22). | A stored interrupt is not a functioning equivalent of obstacle avoidance that can constrain later action independently of deliberation. This negative claim is limited to the master's inspected paths. |

Navi's representation-heavy components are deliberate capabilities: persistent intentions, working-memory slots, and a resident C token-memory engine with bounded associative retrieval (I02–I05). Brooks's robots provide no evidence that deleting these would improve Navi. Conversely, the `LOW/HIGH` enum is not proof of subsumption layering (I01), and asynchronous model workers are not equivalent to autonomous sensor-to-actuator controllers (I10). Model-outage behavior, actual sensing latency, and operational independence remain **unverified**, rather than inferred from process separation.

## Prioritized recommendations and falsifiable evaluations

These are proposed engineering experiments, not work performed in this review. Thresholds below are suggested acceptance criteria, not paper results.

### P1 — Make one environmental inhibition a complete behavior

**Mechanism and location:** Define a bounded, model-independent inhibition contract for queued terminal actions when a designated stop condition is active. Connect the condition's recorded state to `DecisionStateMachine::integrate`, [ProceduralMemory.php](../../../src/Core/ProceduralMemory.php), `executeAction:736`, and `ExecutiveCore::claimActionDispatch:797` (I05, Q23, Q22). The latter currently establishes dispatch ownership and procedure-generation validity, not global admission policy. Specify an ordering invariant between condition activation and effect admission; an early check alone leaves a race. Preserve explicit release semantics for a stop condition rather than blindly copying Brooks's expiring inhibition timer.

**Reason:** The obstacle-avoidance layer remains effective while exploration is active (§4.2, p. 5; §6.2, pp. 8–9). This motivates an independently executable constraint, although the particular durable stop contract is a Navi-specific synthesis, not Brooks's algorithm.

**Dependencies/cost:** A trusted condition producer, scoped effect categories, concurrency semantics, and a defined boundary between admitted and already-running effects. Extra model inference should not be required. This need not stop unrelated observation or preservation work.

**Evaluation:** In an isolated future integration exercise, activate the condition before proposal integration, between selection and dispatch, and during delayed/retried work, including model unavailability. Require zero newly admitted prohibited effects after activation's defined ordering point, preserved behavior for permitted work, and bounded recovery after explicit release. Any bypass fails the contract; reporting an interrupt event without behavioral effect also fails. This establishes one complete competence before adding broader behavior layers.

### P2 — Revalidate the observations an action actually depends on

**Mechanism and location:** Extend the saved evidence in `DecisionStateMachine::start` with action-relevant observation identities, timestamps, and source validity; revalidate them during integration and effect admission. If the relevant condition changes or evidence expires, abandon or refresh the proposal. Avoid invalidating every proposal on any workspace checksum change. Stable historical evidence for consolidation needs different freshness rules from a transient machine condition (Q02, I08).

**Reason:** Brooks repeatedly samples the world and preserves observation validity during exploration (§5, p. 6; §6.2, p. 9). Navi's queue introduces a temporal separation absent from a simple immediate reflex.

**Dependencies/cost:** Source-specific validity periods, explicit evidence dependencies, and potentially additional authorized observations. A newly read database record can still contain an old observation; storage freshness must not substitute for measurement freshness.

**Evaluation:** Compare the current path, a whole-workspace invalidation rule, and dependency-specific revalidation under proposed delays of 0, 5, 30, and 120 seconds. Include reversed relevant conditions, missing readings, and irrelevant workspace changes. Require rejection or refresh of every deliberately invalidated prerequisite, while unnecessary rejection under irrelevant changes stays below a preregistered 5%. Report completion delay and observation cost. Failure to improve obsolete-action frequency, or starvation through continual refreshing, rejects the design.

### P3 — Grow one verified sensing-to-outcome capability at a time

**Mechanism and location:** Select a single machine-state discrepancy with an independently observable resolution predicate. Connect existing sensory ingestion, authorized `ExecutiveCore::requestLook:3624`, and `ProceduralMemory::dispatch:1682` to that predicate; retain a small deterministic behavior with explicit activation, completion, retry bounds, and inhibition. Add model-generated interpretation only after the basic observation behavior works. `machine.look` currently verifies exit code zero, so successfully running the inspection must remain distinct from resolving the discrepancy (I05).

**Reason:** This applies incremental activity decomposition directly, rather than adding another named cognitive faculty (§4.2, p. 5; §6.1, p. 7).

**Dependencies/cost:** A source-specific outcome oracle, authorization-preserving inspection path, and bounded sampling budget. Shared database or scheduling bottlenecks must be measured; separate behavior names do not create fault isolation.

**Evaluation:** Compare the basic behavior alone, with interpretation enabled, and with interpretation delayed or unavailable. Perturb source availability and the underlying condition. Require no false resolved outcomes, bounded retries, and no more than 10% degradation of the basic behavior's p95 response latency when the extra layer is added. Correlate observation, queue, integration, and effect timestamps: existing `reason_model_ms` includes queue time, and recorded cycle totals can omit asynchronous wait (Q02). Follow controlled exercises with authorized real-environment observation before claiming situated robustness.

## Verdict and coverage

**Adopt the methodology; experiment with bounded reactive behaviors; reject wholesale removal of representation.** First establish that one real inhibition constrains queued effects independently of model availability. Then evaluate whether additional capabilities preserve it. Brooks provides a persuasive engineering challenge, not a scalability theorem or evidence about consciousness.

Coverage: all twelve PDF pages, §§1–8.4, acknowledgement and references; the pages containing Figs. 1–2 were also visually inspected. There are no appendices. Cited external works were not independently reviewed. Implementation mapping uses only the shared reference, reread before finalization, including the master's Q22 clarification of dispatch-claim attribution. No experiments, tests, service actions, or production changes were performed. No code question remains pending; runtime timing, robustness, and outage behavior remain unmeasured.
