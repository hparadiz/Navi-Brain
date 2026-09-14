# Self-Evolving Software Agents

**Authors / publication:** Marco Robol and Paolo Giorgini, 2026. *Extended Abstract*, Proceedings of AAMAS 2026, Paphos, May 25–29; DOI: 10.65109/HKPK4104. Reviewed local version: arXiv:2604.27264v1, April 29, 2026.

**Source:** [Local PDF](../../../assets/papers/62-robol-giorgini-2026-self-evolving-software-agents.pdf). **Reviewer:** GPT-6 Astra. **Implementation reference:** [IMPLEMENTATION.md](IMPLEMENTATION.md), snapshot September 6, 2026, git `ee1590009d6fd4c48035c63e15b3896a585757d7`; all implementation claims below derive from the shared master's static inspection.

**Coverage:** All three PDF pages: §§1–4, Figure 1, and the reference list. Figure 1 and its surrounding text were visually checked against the PDF. There are no appendices. The cited dissertation [28] and external prototype artifacts were not inspected. Page references below are PDF pages.

## Assessment

This is a useful architectural proposal with a qualitatively described prototype, not a measured demonstration of reliable autonomous software evolution. Its distinctive contribution is to place requirements elicitation, design revision, code synthesis, and integration beside a BDI runtime, explicitly making the agent's goal and action repertoire changeable. Its most consequential admission is that retaining acquired behavior and maintaining robustness remain unresolved (§§3–4, p. 2).

For Navi-Brain, the transferable question is how an observed capability gap should become an evaluated change to an executable component. Persistent memory, reusable procedures, and source reflection already provide pieces of that process. The verified implementation does not provide the full evolution loop. Recommend a bounded research experiment focused on behavioral inheritance; this paper alone does not justify production self-modification.

## Core idea and mechanism

The authors distinguish adaptation within designer-specified goals and capabilities from evolution of those goals and capabilities themselves (§1, p. 1). Their BDI runtime updates beliefs, generates options, selects intentions, generates plans, and executes actions. An **Automated Evolution Module** operates alongside that loop and reacts to experience that the existing knowledge, goals, or action repertoire cannot accommodate (§2, p. 1; §3, p. 2).

Figure 1 makes the proposed engineering pipeline more concrete than the prose alone:

1. **Requirements:** infer which new requirements sensed data suggests.
2. **Design:** identify goal-triggering events, intention-selection choices, and actions needed to construct plans.
3. **Implementation:** revise components such as the knowledge-base structure and option-generation function.
4. **Integration:** incorporate the resulting codebase into the agent.

The diagram shows successive versions of belief revision, option generation, intention selection, plan generation, and execution. Evolution therefore spans three layers: knowledge/reasoning, goals/decisions, and executable behavior. This is broader than storing an additional experience or selecting another existing plan.

The stated evolutionary principles are variation, selection, and inheritance (§2). In the prototype, interaction with the environment validates synthesized behaviors; successful ones are retained and reused, ineffective ones discarded (§3). However, the paper specifies no population, fitness function, parent-selection algorithm, inheritance representation, or acceptance threshold. Its evolutionary vocabulary should not be expanded into an undocumented evolutionary-search algorithm.

Several assumptions carry substantial weight. The system must recognize that a difficulty requires a structural change, derive an appropriate requirement from observations, synthesize a compatible implementation, and distinguish useful change from transient success. Separating the evolution module from runtime reasoning organizes these responsibilities, but does not itself establish coherence. An evolved perception schema and an unchanged plan can still disagree; version boundaries, state migration, and treatment of in-flight actions are unspecified.

## Evidence and limits

The prototype uses an LLM-extended BDI controller in a dynamic multi-agent environment inspired by Deliveroo.js. Initialization provides a textual environment description and minimal APIs, with no predefined domain-specific knowledge or goals in the agent scaffold. The authors report autonomous discovery of operational goals and generation of executable behaviors, alongside problems with inheritance and robustness as complexity increases (§3, pp. 1–2).

This is **author-reported qualitative feasibility evidence**. The three-page paper contains no numerical results, task-success table, example generated program, before/after behavior trace, trial count, seeds, complexity definition, baseline comparison, uncertainty estimate, latency, or inference-cost measurement. It does not specify the prompts, operational success criterion, validation protocol, or enough model configuration for reproduction. Reference [21] names the GPT-4o System Card; that citation does not establish an exact deployed model version or sampling setup. Reference [28] points to a dissertation, whose contents cannot be treated as evidence read for this review.

Consequently, the paper does not establish improvement over a fixed BDI controller, a controller given more complete initial plans, or a memory/prompt-adaptation baseline. It also does not isolate whether gains arise from new executable code, newly stated goals, or an LLM supplying domain knowledge already available through pretraining. “Minimal prior knowledge” describes the supplied scaffold; the pretrained model and textual environment/API description remain substantial priors.

Goal discovery also needs a narrower interpretation than autonomous invention of terminal values. Inferring a useful operational objective from an environment description can be task interpretation or subgoal construction. The paper supplies no evidence distinguishing those from revision of the ultimate criterion for success. A system that changes both its goals and its evaluator could make apparent progress by choosing easier goals; the abstract does not explain how that possibility is excluded.

The reported inheritance weakness matters, but its mechanism is unresolved. It could involve omitted generated code, incompatible revisions, unavailable behavior at retrieval time, poor selection, or environmental nonstationarity. The text does not discriminate among these explanations. Reinforcement, memory consolidation, stronger selection, cooperative evolution, and retrieval-augmented generation are proposed future directions (§4, p. 2), not demonstrated remedies. In particular, adding retrieval is not evidence that executable behavior survives a sequence of revisions.

## Code-grounded transfer to Navi-Brain

The following statuses concern the master's inspected paths, not live operation or every external tool that might act on the repository.

| Paper mechanism | Verified Navi-Brain relationship |
|---|---|
| Structured runtime reasoning | **Implemented at a narrower level.** `src/Core/DecisionStateMachine.php::start:34` and `integrate:254` implement observation, retrieval, model proposals, deterministic selection, dispatch, and verification. `src/Core/ExecutiveCore.php::createIntention:1385` stores durable goal commitments. These support an architectural analogy to structured deliberation, not equivalence to the paper's BDI implementation. Reference I04–I05. |
| Experience-driven goal evolution | **Partial precursors; autonomous creation bridge absent in the traced scope.** Prediction errors can change salience and wake existing threads. `ExecutiveCore::createRepairArtifacts:12474` creates proposed diagnostic/counterfactual text. Q32 found only `src/Cli/Application.php::addIntention:348` calling `createIntention`, with no internal discrepancy-to-new-intention consumer; Q06 found no dedicated repair-artifact executor. An external agent using the CLI is a different mechanism. |
| Retained executable behavior | **Implemented within installed operator semantics.** `src/Core/ProceduralMemory.php::observe:399` compiles successful typed action shapes after a default three-success streak. `compose:983` supports bounded composition, and failure can invalidate generations. Its four installed adapters are `memory.search`, `working_memory.write`, `memory.consolidate`, and `machine.look`. Learning does not extend that registry or synthesize new operator semantics. Reference I05, I08, Q06, Q27. |
| LLM-driven source evolution | **Proposal infrastructure exists; executable evolution loop absent after bounded search.** `src/Core/PublicReflection.php::runOnce:23` supplies published, hash-checked source evidence to a constrained proposal worker. `review:100` records useful/rejected feedback. Q59 found no source-version generation, isolated benchmark evaluation, archive selection, or deployment promotion/rollback loop. A useful review is not an installed revision. |
| Inheritance and stability | **Durability and version guards exist; behavioral retention gate absent in inspected paths.** Procedure generation checks and replay-safe persistence protect narrower operational invariants. `ExecutiveCore::integrateConsolidation:5020` checks evidence and publication conditions, without before/after old-task evaluation. `checkpoint:8104` is a persistence barrier with `snapshot=[]`, not a restorable agent image. References Q17, Q29, Q58–Q59. |

Navi also already has learned token associations and guarded episodic-to-semantic synthesis (`memories/src/tokmem.c::index_associations:2536`; `ExecutiveCore::integrateConsolidation:5020`; I02, I08). These can supply experience and reusable information to an evolution proposal. They are not substitutes for evaluating a changed interpreter, goal policy, or executable adapter. Likewise, an adapter's postcondition verification is narrower than independently measured achievement of a whole intention (I05, Q36).

## Prioritized recommendations

These are proposed research designs, not experiments performed or changes implemented. Their detailed controls are reviewer synthesis; the paper does not validate them.

### P0 — Make inheritance a measured acceptance condition

Before constructing a production evolution module, specify an isolated chronological evaluation: acquire behavior A, introduce requirement B, evaluate the revision on held-out B cases, and remeasure A after every accepted change. Start with scripted other-agent behavior and paired seeds to separate revision effects from multi-agent nonstationarity. All comparison arms should receive identical environment API permissions and initial information.

Compare a fixed implementation with existing memory/procedure adaptation, an evolution candidate without a retention gate, and the same candidate process with one. Match inference budgets. Keep external task predicates fixed and separate selection cases from untouched audit cases, including withheld old tasks. Report new-task success, old-task retention, invalid executions, and total inference/runtime cost. This directly addresses the paper's reported inheritance problem rather than counting generated goals or programs.

**Location and dependencies:** a separate research evaluator could consume `src/Model/DecisionCycle.php`, `ActionExecution.php`, and `ProcedureRun.php` identities/outcomes (Q29). `DecisionStateMachine::compare:682` supplies historical summaries, not resettable trials (Q58). Environment reset, complete state capture, and independent task scoring require new machinery. Memory evaluation must use isolated state: counted retrieval and even candidate ranking can learn (I02, QMEM).

**Falsifiable criterion:** predeclare a retention margin, for example five percentage points. Require a positive lower 95% confidence bound on new-task improvement over the fixed baseline and an upper 95% bound on old-task loss below that margin. Insufficient evidence leaves the candidate unaccepted. Reject the experiment's adoption case if the benefit disappears under matched budgets or arises from changing the success predicate. These thresholds are proposed design choices, not paper results.

### P1 — Distinguish a capability gap from an ordinary failure

Add a proposal-only requirements record to an offline prototype, with the observation, failed current behavior, hypothesized gap, affected architectural layer, proposed operational subgoal, and independently checkable expected benefit. Explicitly distinguish missing information, transient execution failure, unavailable code capability, and an inappropriate plan. Otherwise every surprising observation can become a software-change request.

**Location and dependencies:** use `DecisionStateMachine::impasse:815` and mismatched action evidence as inputs, plus the provenance pattern in `ExecutiveCore::createRepairArtifacts`. Extend the evidence supplied through the `PublicReflection::runOnce` pattern where source revision is relevant. Current impasses terminate the selection attempt and repair artifacts remain proposals; connecting these is new work (Q06, Q27, Q59). Deduplication, a fixed inference budget, and explicit parent-requirement constraints are needed. A proposed subgoal should not silently rewrite the parent success condition or create its own authority.

**Falsifiable criterion:** on independently labeled cases, compare escalation precision with a simple repeated-impasse trigger at matched recall of actual missing capabilities. Reject the mechanism if it provides no precision improvement, repeatedly escalates recoverable failures, or proposes weakening the evaluation target. This evaluates the paper's otherwise unspecified trigger before paying for code generation.

### P2 — Treat cross-layer evolution as a versioned candidate bundle

If P0 and P1 justify further work, represent each candidate by its parent source hash, requirement delta, affected perception/goal/action interfaces, code patch, state-migration assumptions, and evaluation results. Keep a complete prior candidate/state bundle in the isolated evaluator. Pin outstanding jobs and procedure runs to their originating version, or drain them before switching versions. This gives operational meaning to Figure 1's successive component versions and addresses interactions between the three evolution layers.

**Location and dependencies:** reuse provenance conventions from `PublicReflection::runOnce`, work fencing from `ExecutiveCore::claimWork:6485`, and generation checks in `ProceduralMemory::run:920` / `advanceRun:1221` (I10, Q29, Q59). These are building blocks, not an existing global code/state transaction. A separate candidate archive, compatible state snapshots, and controlled integration are substantial additional engineering; SQLite plus the resident/file-backed token store complicates restoration (I01–I02).

**Falsifiable criterion:** compare bundle integration against independently applied component patches using incompatible schema changes and delayed results. Reject on any mixed-version dispatch, unreproducible restoration, or old-task regression beyond P0's margin. The expected benefit is reliable inheritance and integration, not simply a larger number of accepted revisions.

## Verdict

**Experiment; defer production self-evolution.** The requirements–design–implementation separation is worth retaining as an architectural model. The assertion that separation preserves coherence is under-supported, and the prototype report cannot quantify benefit, cost, or reliability. Navi's existing proposal boundaries and typed procedural learning offer a credible starting point, but do not close those gaps.

The most consequential next step is an isolated before/after retention evaluation with fixed success predicates. Evidence that a new capability survives later revisions while earlier capabilities remain usable would advance this proposal more than another layer named “evolution.” No blocking implementation question remained after consulting the shared reference; exact prototype configuration, measured performance, and the cause of inheritance failures remain unresolved in the reviewed source.
