# Continuous State, Bounded Computation

## A Performance-Oriented Architecture for Real-Time LLM Cognition, Dynamic Theory of Mind, and Persistent Machine Agency

### Abstract

Contemporary large-language-model agents are usually organized around model invocations: an observation is assembled into context, an LLM is called, internal state is reconstructed implicitly from that context, and an action or response is produced. This organization becomes computationally pathological when extended to persistent, real-time cognition. A system that must maintain a self-model, world model, theory of mind (ToM) for multiple entities, memory, appraisal, planning, and incremental speech cannot afford to reconstruct those representations from history at every decision boundary.

This paper proposes an architectural inversion. **Persistent cognitive state, rather than the LLM invocation, becomes the primary computational object.** Neural inference becomes a state-transition operator acting on that persistent state. A bounded temporal kernel repeatedly gives cognitive processes an opportunity to advance, but unchanged representations are carried forward without recomputation. We call this principle **Continuous-State, Bounded-Compute cognition (CSBC)**.

The key distinction is:

[
\boxed{\text{continuous cognitive state} \neq \text{continuous cognitive recomputation}}
]

and, correspondingly,

[
\boxed{\text{bounded cognitive tick} \neq \text{completion of all cognition within every tick}}
]

Instead, a fixed temporal boundary constrains how much computation may occur before control returns to the system. Relevant state receives incremental updates; irrelevant state remains unchanged; expensive reasoning may span multiple ticks; unresolved computations remain represented explicitly; and action selection proceeds from the latest coherent state available.

This synthesis is grounded in several established lines of work. POMDPs and I-POMDPs establish recursive belief-state updating rather than replaying history. Recurrent Entity Networks demonstrate continuously updated entity representations. ToMnet separates persistent agent characteristics from transient mental state. Recent dynamic-ToM research demonstrates that temporal belief maintenance is a distinct weakness of ordinary LLM inference. PDDL-Mind shows that externalizing reliable state tracking can simultaneously improve ToM performance and reduce LLM calls. Full-duplex systems such as SyncLLM and DuplexSLA demonstrate wall-clock-synchronous language generation, while recent work on online controllers, asynchronous agents, latent reasoning, and agent-aware LLM serving provides several of the runtime mechanisms necessary for a bounded cognitive architecture. ([arXiv][1])

The contribution of this paper is not any one of these mechanisms individually. It is their unification into a performance-oriented system in which decision cost is decoupled from accumulated history and maintenance cost scales primarily with **information flux through a bounded active working set**. This claim requires bounded dependency fan-out, bounded hot-state residency, bounded transition output, and a resident inference engine.

We evaluate the state-first incremental subset of CSBC in a deterministic 100,000-tick belief-maintenance environment with 5,038 events. Using the same 9B language model, sparse persistent state achieved 75.8% aggregate semantic accuracy, compared with 48.5% for full-history reconstruction and 54.5% for a fixed-window baseline. Its maintenance prompt remained between 154.2 and 157.7 tokens per new event across the trajectory, while the full-history decision prompt grew from 1,006 to 77,493 tokens and the final decision took 621.7 seconds even with server-side prefix-cache reuse. Sparse maintenance produced no false-clean updates, spurious mutations, failed transitions, or quiescent-state invariant violations; it repaired 944 of 946 observed error episodes, although two presence-state errors remained at the end.

The prototype also exposes the tradeoff clearly. Sparse maintenance consumed more total inference than six occasional reconstruction queries, and its median semantic update took 1.37 seconds on the CPU test platform. The result supports history-independent sparse transition size, improved logical continuity, and zero neural work at the measured decision boundary. It does not establish a universal lifetime-compute advantage, a 140 ms deadline, learned dependency discovery, or the complete runtime proposed here.

The paper further argues—explicitly as a hypothesis rather than an established result—that this distinction may be significant for artificial general intelligence. If semantic competence is already present in modern foundation models, one remaining structural bottleneck may be the absence of an appropriate temporal architecture in which knowledge, beliefs, goals, social models, partial reasoning, perception, and action persist and evolve continuously.

---

# 1. Introduction

Large language models are extraordinarily capable semantic processors, but the native computational abstraction of a transformer language model is poorly matched to persistent cognition:

[
y_{1:n}=F_\theta(x_{1:m})
]

A context is supplied. Computation occurs. A sequence is returned.

Agent architectures add memory, tools, planning, retrieval, and environmental interaction around this interface. CoALA, for example, provides a useful framework involving modular memory, structured internal and external actions, and generalized action selection. ([arXiv][2])

Yet the usual runtime abstraction remains approximately:

[
\text{construct context}
\rightarrow
\text{invoke model}
\rightarrow
\text{generate}
\rightarrow
\text{update external state}.
]

This works surprisingly well for tasks whose natural timescale is seconds or minutes.

It becomes problematic when the desired system is continuously situated in the world.

Consider an agent that must simultaneously maintain:

* a world model;
* a self-model;
* multiple entity models;
* beliefs about what those entities know;
* goals and intentions;
* attention;
* affective/appraisal state;
* episodic memory;
* working memory;
* ongoing plans;
* unresolved tool operations;
* incoming speech;
* outgoing speech;
* physical or software actions.

Suppose further that the agent targets a scheduling opportunity approximately every 140 ms.

A straightforward LLM implementation would repeatedly reconstruct these representations from text:

[
M_i(t)
======

LLM(H_i^{0:t})
]

for entity (i), where (H_i^{0:t}) is its relevant history.

Its decision cost grows with the retained history, and repeated reconstruction can also revise state for which no new evidence arrived.

The essential observation of this paper is that **reconstruction is unnecessary**.

A cognitive system should instead behave like a stateful dynamical system:

[
X_{t+1}=U(X_t,\Delta O_t)
]

where (X_t) already contains the current cognitive state and (\Delta O_t) contains only newly available evidence.

This is not itself a new mathematical concept. POMDP belief filtering is based precisely on carrying an updated belief state forward rather than repeatedly solving from complete history. Interactive POMDPs extend the state to models of other agents and nested beliefs. ([arXiv][1])

What changes with modern foundation models is the possible implementation of (U).

The transition function no longer needs to be a narrow hand-designed Bayesian model. An LLM or related learned model can provide a powerful prior over language, causality, social behavior, goals, human conventions, plans, and world knowledge.

This suggests a different role for the LLM:

[
\boxed{\text{LLM as semantic transition operator}}
]

rather than:

[
\boxed{\text{LLM as the location in which cognitive state exists}}
]

This distinction is foundational to the architecture proposed here.

The minimal prototype evaluated in this paper tests precisely this inversion. It does not implement the complete scheduler, asynchronous transaction model, latent state, or consolidation hierarchy. It holds a small explicit world-and-belief state across 100,000 ticks, invokes a language model only for semantically dirty fields, and compares the result with full-history and fixed-window reconstruction. This narrow implementation is sufficient to test whether persistent sparse transitions change the history-scaling curve and whether write scoping reduces unrelated state drift.

---

# 2. The Central Distinction

## 2.1 Continuous state is not continuous recomputation

Let the complete cognitive state at temporal boundary (k) be

[
X_k =
{
W_k,S_k,A_k,M_k,P_k,\ldots
}
]

where:

* (W_k) is world state;
* (S_k) is self state;
* (A_k) is appraisal/attention state;
* (M_k) is the set of modeled entities;
* (P_k) contains current plans and intentions.

The system receives new events:

[
E_k={e_1,e_2,\ldots,e_n}.
]

A dependency router determines which portions of (X_k) could have changed:

[
D_k=R(X_k,E_k).
]

Only state in (D_k) needs active computation.

For every unaffected component (x_j),

[
x_{j,k+1}=x_{j,k}.
]

For an affected component,

[
x_{j,k+1}
=========

U_j(x_{j,k},E_k,X_k).
]

Therefore the system may possess **continuous representational existence** without continuously rerunning every representation.

This is the first critical distinction.

A person whom the agent has not observed, heard about, interacted with, or reasoned about for six hours does not require six hours of neural computation in order for their model to continue existing.

Their state persists.

---

## 2.2 A cognitive tick is an opportunity boundary, not a recomputation mandate

Suppose:

[
\Delta t=140\text{ ms}.
]

The naïve interpretation is:

> Every cognitive subsystem must completely rerun every 140 ms.

That interpretation is neither computationally necessary nor desirable.

The proposed interpretation is:

> Every 140 ms, control returns to the cognitive runtime, which determines what must advance before the next boundary.

The clock constrains **control latency**, not universal recomputation.

Thus:

[
\boxed{
\text{synchronous control}
+
\text{asynchronous computation}
}
]

is entirely compatible.

A process may be:

* unchanged;
* completed;
* newly runnable;
* awaiting information;
* partially completed;
* superseded;
* invalidated;
* asleep;
* overdue.

The clock defines when the system regains the opportunity to reconsider those states.

This idea has direct precedent in recent online LLM-controller work. *Learning When to Think While Listening* evaluates a controller on a fixed 0.5-second grid and explicitly permits stale triggers to be skipped when a previous inference is still running, because an online controller cannot perform unlimited work on every trigger. ([arXiv][3])

The important architectural generalization is to apply that rule to cognition as a whole.

---

## 2.3 Bounded progress is not bounded completion

The second major distinction is:

[
\boxed{
\text{each cognitive operation must make bounded progress}
}
]

rather than:

[
\boxed{
\text{each cognitive operation must finish inside one tick}
}
]

Consider an ambiguous social event.

At (t_0), an entity says something surprising.

Within the first tick the system may only establish:

[
\text{belief-model-inconsistency}=HIGH
]

and schedule additional reasoning.

At subsequent boundaries:

[
t_1:\quad \text{retrieve relevant episode}
]

[
t_2:\quad \text{revise likely belief hypothesis}
]

[
t_3:\quad \text{compare alternative intent hypotheses}
]

[
t_4:\quad \text{commit higher-confidence interpretation}.
]

The incomplete state itself remains representable:

[
M_i =
{
\text{status}=\text{pending-revision},
\text{confidence}=0.31,
\text{pending-job}=9271
}.
]

Action selection does not need to stop because cognition is incomplete.

It can act on uncertainty.

That is an essential property of real cognition in an evolving environment.

---

## 2.4 The scaling consequence

Under repeated reconstruction, approximate work is:

[
C_{\text{reconstruct}}
\approx
\sum_{i=1}^{N}
C\left(F(H_i)\right)
]

where (H_i) may grow indefinitely.

Under incremental state maintenance:

[
C_k
=
C_{\text{route}}
+
|D_k|C_{\text{transition}}
+
C_{\text{commit}}
+
C_{\text{arbitrate}}.
]

This form is conditional. It requires:

* bounded active-state residency;
* bounded dependency fan-out;
* bounded transition input and output;
* a resident inference engine;
* a router that either finds the causal closure or conservatively widens it.

Under those conditions, the expected dirty set may remain much smaller than the stored population:

[
|D_k|\ll N.
]

The strongest formulation of the architecture is therefore:

[
\boxed{
\text{computation should scale with information flux,
not stored history}
}
]

This is an architectural target rather than an unconditional asymptotic theorem. One event can invalidate a large portion of state; an unbounded provenance chain can make a nominally small hot state expensive to touch; and transformer weight movement, batch saturation, database operations, dependency routing, and fixed scheduling costs still exist.

But it changes the dominant variable.

In principle, an indexed persistent store may contain ten people or ten million people without requiring active mentalization of every stored person every 140 ms; the active-residency and fan-out bounds above still determine runtime cost.

The expensive variable is the size and complexity of the **active dirty working set**.

The prototype separates the two relevant cost curves. Full-history decision cost grew with accumulated history, while sparse maintenance stayed between 154.2 and 157.7 prompt tokens per new event. CSBC paid that maintenance continuously to make the measured decision boundary a state read with zero neural inference. Because the workload requested only six decisions, this cost exceeded the total inference spent by reconstruction. The measured claim is therefore history-decoupled decision work and information-flux maintenance, not lower lifetime compute under every query rate.

---

# 3. Intellectual Lineage

## 3.1 Belief-state filtering

A POMDP represents uncertainty through a belief state that is recursively updated as observations and actions arrive. The belief state can summarize the relevant history for subsequent decisions instead of requiring repeated reasoning directly over the complete observation sequence. I-POMDPs extend this idea by incorporating models of other agents into interactive state. ([arXiv][1])

I-POMDP work also makes the central computational problem obvious: nested beliefs dramatically expand the belief and policy spaces. Approximate methods such as interactive particle filtering were introduced specifically because recursively modeling what agents believe about other agents rapidly becomes expensive. ([arXiv][4])

The lesson for a performant cognitive architecture is therefore not merely “use beliefs.”

It is:

> **maintain beliefs recursively, but materialize recursive depth sparsely.**

---

## 3.2 Persistent neural entity state

Recurrent Entity Networks introduced dynamic long-term memory slots whose contents are continuously updated as new information arrives. The model reasons while reading rather than postponing world-state construction until a question is asked. Multiple memory slots can be updated in parallel. ([arXiv][5])

That is extremely close to the required computational primitive.

An entity representation should be something that **evolves**, not something regenerated when queried.

---

## 3.3 ToMnet: stable character versus transient state

Rabinowitz et al.'s ToMnet introduced a particularly important decomposition. The architecture contains a character model, a mental-state model, and a prediction component. The system uses observations of previous behavior to infer relatively stable properties of an agent and current-episode information to infer transient mental state. ([Proceedings of Machine Learning Research][6])

This decomposition should be taken considerably further in persistent agents.

An entity should not have a single undifferentiated “ToM context.”

It should have state at several temporal frequencies.

---

## 3.4 Dynamic ToM is a temporal-memory problem

Recent research increasingly supports the view that ToM is not merely a static question-answering capability.

DToM-Track explicitly tests belief trajectories over extended interactions. Its authors report an asymmetry in which LLMs can often infer a current belief while having substantially more difficulty maintaining and recovering prior belief states after updates. ([arXiv][7])

Dynamic Belief Graphs similarly models beliefs as time-varying latent states with evolving dependencies rather than as independent static judgments. ([arXiv][8])

These results matter because a persistent agent does not need to answer:

> “What does Alice believe?”

once.

It must maintain the answer as Alice observes, forgets, misunderstands, learns, acts, and receives information.

---

## 3.5 Explicit state tracking can reduce computation

PDDL-Mind directly separates state tracking from high-level LLM reasoning.

On the evaluated ToM benchmarks, the authors report that their explicit-state approach used three LLM calls per example, while AutoToM required averages of 17.6 and 35.1 calls on two corresponding datasets; PDDL-Mind also achieved higher reported accuracy in those evaluations. ([arXiv][9])

The importance is not the PDDL representation itself.

The more general result is:

[
\boxed{
\text{externalizing reliable state can reduce,
not increase, neural inference}
}
]

This strongly supports the CSBC design.

---

# 4. From Real-Time Speech to Real-Time Cognition

## 4.1 Wall-clock language models

SyncLLM introduced explicit real-world synchronization into a full-duplex language model, evaluating fixed speech chunks including a 160 ms baseline. ([arXiv][10])

DuplexSLA advances this concept further by placing assistant speech and a structured action stream on a shared 160 ms timeline. Every chunk emits a fixed assistant-audio unit and at most ten action-text tokens; longer action output spills into later chunks because the decoding workload must remain within the wall-clock budget. ([arXiv][11])

This is an important demonstration of:

[
\text{unbounded semantic objective}
\rightarrow
\text{bounded temporal execution fragments}.
]

But speech is only one component of cognition.

The architectural generalization is:

[
\boxed{
\text{all cognitively relevant computation
should obey temporal resource governance}
}
]

---

## 4.2 Action selection should own the clock

In ordinary streaming generation, the model starts producing an utterance and token generation effectively owns control until generation finishes or is externally interrupted.

A continuous cognitive architecture should invert this.

Speech is an action:

[
a_t \in
{
SPEAK,
WAIT,
THINK,
OBSERVE,
RETRIEVE,
PLAN,
MOVE,
CALL,
WRITE,
\ldots
}.
]

The action selector is reconsidered at every temporal boundary.

Thus speech becomes:

[
SPEAK(fragment,\ budget)
]

rather than:

[
SPEAK(complete\ utterance).
]

A sentence may begin under state (X_t) and change direction under (X_{t+1}).

The system need not finish what it began saying if the world has changed.

---

# 5. Proposed Continuous-State Bounded-Compute Architecture

The proposed architecture contains nine principal layers.

---

## 5.1 Layer 1: Temporal Kernel

The **Temporal Kernel** owns monotonic time and cognitive deadlines.

Every job contains at minimum:

```text
job_id
cognitive_process
input_state_version
dependencies
earliest_run
deadline
maximum_budget
stale_after
priority
continuation
```

The kernel does not ask:

> “Which agent should run next?”

It asks:

> “Given the latest state, current deadlines, uncertainty and available compute, what cognition is worth advancing before the next control boundary?”

This is closer to an operating-system scheduler than a chatbot orchestrator.

A simple initial policy can combine deadline classes with earliest-deadline-first scheduling.

### Suggested priority classes

**P0 — Integrity and safety**

State corruption detection, actuator cancellation, critical interrupts.

**P1 — Control-critical cognition**

Perception synchronization, action selection, speech continuation, interruption.

**P2 — Active-state maintenance**

Appraisal, active entity ToM, immediate working-memory maintenance.

**P3 — Deliberation**

Planning, episodic retrieval, explicit reasoning.

**P4 — Reflection**

Longer self-evaluation and strategy revision.

**P5 — Consolidation**

Memory abstraction, training, replay, compression.

Lower classes may be starved temporarily. P0 and admitted P1 work require reserved capacity, admission control, and a bounded non-neural fallback. Priority labels alone do not prevent kernel overruns, accelerator non-preemption, or priority inversion.

---

## 5.2 Layer 2: Event and Delta Bus

Every observation enters as an immutable timestamped event:

[
e=(type,source,time,payload,confidence).
]

Examples include:

```text
speech.partial
speech.final
person.entered
person.gaze_changed
window.focus_changed
tool.completed
memory.retrieved
goal.updated
interoception.changed
assistant.spoke
```

Consumers do not receive complete reconstructed worlds.

They receive deltas.

A dependency graph maps event types to potentially affected state:

```text
speech.user
 ├── discourse state
 ├── user ToM
 ├── self appraisal
 ├── active goal
 └── speech-action selector
```

This creates the **dirty set** (D_k).

The dependency graph is a runtime contract, not an assumption delegated to an opaque model. A transition job declares at least:

```text
event type
semantic read set
semantic write set
control read set
control write set
provenance roots
dependency versions
maximum supported fan-out
fallback reconstruction scope
```

The routed closure is computed conservatively:

[
D_0=\operatorname{directDependencies}(E_k)
]

[
D_{n+1}=D_n\cup\operatorname{dependents}(D_n).
]

An unknown edge, missing provenance root, incompatible schema version, or latent-state ambiguity widens the dirty set or schedules reconstruction. A false-clean field is a correctness failure; an unnecessary-dirty field is a performance cost.

The experiment in Section 31 uses a hand-declared router and an independently generated oracle dirty set. It observed zero false-clean and unnecessary-dirty updates on that domain. This validates explicit write authority and conservative routing in the prototype; it does not solve learned dependency discovery or high-fan-out invalidation.

---

## 5.3 Layer 3: Authoritative State Store

Durable state lives outside the LLM.

An RDBMS is a reasonable substrate because it provides:

* identity;
* transactions;
* indexing;
* explicit relations;
* provenance;
* versioning;
* temporal queries;
* durable episodic storage.

The RDBMS should not necessarily be queried synchronously on every cognitive tick.

A hot materialized state cache sits above it:

```text
                 neural semantic layer
                         │
                  hot cognitive state
                         │
                  authoritative RDBMS
                         │
                    episodic log
```

The database is authoritative.

The hot state is optimized for latency.

The neural model is allowed to be approximate.

This distinction permits both performance and recoverability.

---

# 6. Materialized Theory of Mind

For each entity (i), maintain:

[
M_i =
(
C_i,
B_i,
G_i,
I_i,
A_i,
V_i,
R_i,
U_i,
Z_i,
P_i
).
]

Where:

### (C_i): Character state

Slow-changing model of relatively persistent tendencies.

Examples:

* communication habits;
* preferences;
* expertise;
* recurring behavioral tendencies.

This corresponds loosely to ToMnet's character representation. ([Proceedings of Machine Learning Research][6])

### (B_i): Epistemic state

What the entity is believed to know, believe, suspect, or misunderstand.

CSBC-Core uses typed categorical beliefs unless a calibrated probabilistic semantics has been established:

```text
subject: Alice
proposition_id: server_A_online
belief_value: true | false | unknown
provenance_root: episode_19283
last_updated: 2026-08-13T10:14:22Z
source_model: qwen3.5-9b-heretic-q4ks
status: clean | dirty | unknown
```

If a numerical value is retained, the representation must define whether it is a calibrated probability or merely a model score, together with its calibration domain, scoring rule, source model, and update rule. Probability-looking decimals without such a contract are not interchangeable with subjective Bayesian belief.

### (G_i): Goals

Current inferred objectives.

### (I_i): Intentions

Likely immediate commitments or planned actions.

### (A_i): Attention

What the entity is currently attending to or likely to notice.

### (V_i): Affect/appraisal

Estimated motivational or emotional variables relevant to behavior.

### (R_i): Relations

Relationships to other entities and objects.

### (U_i): Uncertainty

Explicit uncertainty over the model itself.

### (Z_i): Latent residual

A learned dense representation carrying information that does not fit cleanly into explicit fields.

The latent residual is optional and noncanonical. It carries model identity, schema version, invalidation and expiration rules, and a reconstruction fallback. Explicit state wins on conflict unless a typed reconciliation policy explicitly says otherwise.

### (P_i): Provenance

Evidence supporting updates.

This is necessary because inferred mental states are hypotheses, not observations.

---

# 7. Incremental ToM Updates

Suppose Alice's model currently exists as:

[
M_{Alice,t}.
]

A new observation arrives:

> Alice asks, “Did you already restart it?”

Instead of supplying Alice's entire history to an LLM, the update request contains:

[
(
M_{Alice,t}^{relevant},
\Delta O_t,
W_t^{relevant}
).
]

The semantic updater produces:

[
\Delta M_{Alice,t}.
]

Then:

[
M_{Alice,t+1}
=============

merge(M_{Alice,t},\Delta M_{Alice,t}).
]

A structured output might be:

```text
belief:
  henry_restarted_server:
    old: 0.42
    new: 0.29

attention:
  henry:
    delta: +0.28

goal:
  determine_restart_status:
    new: 0.74

uncertainty:
  interpretation:
    0.22
```

Everything not present in the delta remains unchanged.

This is fundamentally different from regenerating Alice's complete model.

---

# 8. Sparse Recursive Theory of Mind

I-POMDP research makes clear that recursive models of beliefs-about-beliefs become computationally expensive rapidly. ([arXiv][4])

Therefore CSBC should not instantiate complete arbitrary recursion.

Instead use **lazy epistemic recursion**.

First-order:

[
B_{Alice}(server_failed)
]

Second-order:

[
B_{Alice}(B_{Navi}(server_failed))
]

should only be materialized if something makes it decision-relevant.

For example:

> Alice asks whether Navi already knows about the outage.

That event dirties:

[
B_{Alice\rightarrow Navi}.
]

Before that moment, there may be no reason to compute it.

Higher-order ToM therefore behaves like lazily allocated virtual memory.

Potential state space can be enormous.

Actual resident state remains small.

---

# 9. Active Entity Working Set

Let all known entities be:

[
E={e_1,\ldots,e_N}.
]

The runtime maintains an active set:

[
A_t \subseteq E.
]

Activation can depend on:

* physical presence;
* current conversation;
* recent mention;
* active plans involving the entity;
* predicted relevance;
* unresolved state changes;
* salience.

Within the active set exists a dirty set:

[
D_t\subseteq A_t.
]

Only (D_t) requires update.

For example:

```text
Known entities:          42,891
Warm entities:              180
Active entities:              6
Dirty entities this tick:     2
```

The system's cost is therefore primarily related to the final two numbers, not the first.

This is one of the most important performance properties of the proposed design.

---

# 10. The Shared Neural Semantic Operator

The central neural model should be resident.

Different cognitive systems access the same learned semantic substrate:

```text
                 ┌──────────────────┐
                 │ shared LLM/model │
                 └────────┬─────────┘
                          │
       ┌───────────┬──────┼──────┬───────────┐
       │           │      │      │           │
      ToM       appraisal action speech   memory
      update     update    rank   form     scoring
```

The weights are shared.

The transient cognitive states are not.

This permits reuse of the model's learned priors without forcing every subsystem into one gigantic language context.

---

## 10.1 The LLM is not the canonical memory

KV state can be retained for performance, but KV cache should not become the sole authoritative representation of beliefs, goals, or identity.

KV caches are optimized representations of an attention history.

They are not an ideal database schema.

The canonical cognitive state must remain inspectable and recoverable independently of inference implementation.

---

## 10.2 Prefer latent and structured operations over prose

A critical performance principle is:

> **Most cognition should not require generating English.**

If action selection needs:

[
argmax(a_1,\ldots,a_8)
]

the model should output an action ID or logits, not:

> “After considering my various possible choices, I believe the best course…”

Likewise a ToM updater should preferably produce compact structured deltas.

Full language decoding is reserved for cognition that actually requires linguistic representation.

Recent work on latent reasoning in full-duplex speech systems is relevant here: FLAIR explicitly explores reasoning through continuous hidden representations rather than requiring all intermediate computation to be externalized as textual chain-of-thought. ([arXiv][12])

---

# 11. Tick-Level Neural Microbatching

Suppose a tick contains these jobs:

```text
update self appraisal
update Alice ToM
update Bob ToM
rank action candidates
continue speech
score retrieved memory
```

A poor runtime performs six serialized model invocations.

A performant runtime constructs a microbatch:

[
Q_t=
\{
q_{self},
q_{Alice},
q_{Bob},
q_{action},
q_{speech},
q_{memory}
\}.
]

The accelerator can then process multiple logical cognitive operations while sharing model-weight residency.

Entity updates are especially well suited to this because they naturally share schemas and model parameters.

This design resembles an SIMD/SIMT interpretation of mental-state filtering.

---

# 12. Deadline-Aware Model Serving

Current serving research already provides several useful pieces.

vLLM's PagedAttention was designed to reduce KV-cache fragmentation and enable more efficient batching. ([arXiv][13])

Autellix argues that agentic workloads should be scheduled as programs rather than treating every LLM request independently; its reported experiments show large throughput improvements from program-aware scheduling. ([arXiv][14])

CONCUR demonstrates another relevant problem: long-lived agent workloads can experience KV-cache thrashing before nominal memory capacity is exhausted, motivating proactive admission control. ([arXiv][15])

A cognitive runtime needs to go further.

Each inference request should expose:

```text
deadline
importance
continuation state
maximum output
stale-after
state version
dependency set
```

The serving engine can then optimize **cognitive utility per unit time**, not merely tokens per second. Integrity constraints are lexicographic: preserve safety and action arbitration first, service admitted control-path work from reserved capacity second, and schedule optional cognition by expected decision value per compute third.

A starting score for optional job (j) is:

[
S(j)=
\frac{
P(\text{decision changes}\mid j)V(j)U(j)
}{E[T_j]}
f(\text{deadline},\text{staleness}),
]

where (V(j)) is decision value and (U(j)) is useful uncertainty reduction. A job whose result is expected after its usefulness horizon should not be admitted. This is a proposed policy, not one evaluated by the present prototype.

The cognitive scheduler decides which semantic operations deserve computation. A separate inference scheduler decides how admitted jobs are packed onto hardware. Microbatching may improve throughput, but it must have a maximum queueing delay and an urgent bypass path; homogeneous cognitive purpose does not imply compatible tensor shapes or KV state.

---

# 13. A Proposed 140 ms Soft Real-Time Cognitive Boundary

The following is an example deployment budget rather than a biological claim.

```text
0–10 ms
    ingest sensory/environment deltas

10–20 ms
    dependency routing
    active-set update
    dirty-set construction

20–85 ms
    realtime neural microbatch
      - active ToM updates
      - appraisal
      - percept interpretation
      - short internal evaluations

85–105 ms
    validate and commit state deltas

105–125 ms
    action arbitration

125–140 ms
    launch bounded actions
    schedule continuations
    publish next state snapshot
```

Not every cycle must follow identical timings.

For an implementation engineered to this deployment point, the target is:

[
T_{control,target}\leq140\text{ ms}.
]

This architecture does not by itself establish a hard real-time bound. A hard guarantee would additionally require bounded worst-case execution time, preallocated and non-blocking fast-path state, reserved or isolated compute, admission control, priority-inversion prevention, and bounded validation. Until those properties are measured, the correct claim is a soft real-time control epoch with deadline-miss telemetry and a non-neural fallback.

If a deep reasoning process cannot finish:

```text
reasoning_job_82:
    status = suspended
    continuation = ...
```

Control still returns.

The CPU prototype in Section 31 is not a real-time implementation: its median sparse semantic transition took 1.37 seconds. The 140 ms value remains an illustrative target and an experimental sweep point, not a result of the present evaluation.

DuplexSLA provides a closely related precedent for explicitly capping the amount of model output per temporal chunk so work cannot grow without bound inside one conversational interval. ([arXiv][11])

---

# 14. Multi-Rate Cognition

A global clock does not imply identical execution rates.

For example:

```text
sensory synchronization       20–100 Hz
motor reflexes                20–100 Hz
cognitive control             ~7 Hz at 140 ms
speech control                ~7 Hz
active ToM                    event-driven
appraisal                     event-driven / ~7 Hz max
self-model maintenance        1–7 Hz
planning                      opportunistic
reflection                    sub-Hz
memory consolidation          seconds to hours
parameter learning            minutes to hours
```

LIDA's proposed cognitive timing model estimates approximately 260–390 ms for one complete cognitive cycle while noting that high-level cognition can involve multiple cycles. That work should not be interpreted as fixing the correct engineering frequency for an artificial agent, but it provides precedent for cognition being organized as repeated temporally structured cycles rather than one unbounded reasoning process. ([PLOS][16])

CSBC is deliberately more general:

[
\text{one clock}
\neq
\text{one frequency}.
]

---

# 15. Action Selection as the Temporal Authority

At the end of each control boundary, the action policy receives the latest coherent snapshot:

[
a_t
===

\pi(
W_t,
S_t,
M_t,
G_t,
P_t,
U_t
).
]

Possible actions include both external and internal operations:

```text
SPEAK
MOVE
TYPE
CALL_TOOL
WAIT
RETRIEVE
THINK
INSPECT
ASK
REPLAN
INTERRUPT
```

This is consistent with CoALA's broad concept of structured internal and external action spaces, but CSBC adds a hard temporal execution semantics to the action-selection process. ([arXiv][2])

Crucially:

[
THINK
]

is an action competing for computational time.

That means additional reasoning must implicitly or explicitly justify delaying external action.

The architecture therefore contains a natural **value-of-computation problem**.

---

# 16. Speech as a Bounded Action

Speech should not monopolize cognitive control.

At tick (t):

[
a_t=SPEAK(\delta y_t)
]

where (\delta y_t) is only the fragment permitted by the current speech budget.

At the next boundary, action selection runs again.

The system may:

* continue;
* pause;
* backchannel;
* interrupt itself;
* change wording;
* stop speaking;
* perform another action concurrently.

This is more profound than ordinary streaming.

Ordinary streaming divides an already selected generation process into transport chunks.

Bounded incremental speech divides **commitment** into temporal chunks.

That allows cognition to reconsider the communicative act itself.

DuplexSLA and SyncLLM show that such wall-clock chunking is technically practical for speech generation. ([arXiv][10])

---

# 17. Asynchrony and Cognitive Futures

Many operations have uncertain latency:

* database retrieval;
* network tools;
* perception;
* another model;
* file operations;
* physical actions.

The cognitive loop must not block on them.

Instead:

```text
memory_lookup_812:
    status = pending
```

can itself be represented in working state.

Recent asynchronous agent research explicitly decouples model reasoning from tool and user-input latency, allowing reasoning and acting to continue while information remains outstanding. ([arXiv][17])

AsyncFC goes further by showing that models can operate over symbolic placeholders representing unresolved future tool results, allowing dependency-safe work to proceed before those futures resolve. ([arXiv][18])

This maps cleanly onto CSBC.

The world need not stop because cognition is awaiting something.

---

# 18. Working Memory and Snapshot Semantics

Every action decision should observe a coherent cognitive snapshot.

A practical implementation can use double-buffered state:

```text
READ SNAPSHOT k
      │
      ├── updater A
      ├── updater B
      └── updater C
             │
        proposed deltas
             │
          validator
             │
      COMMIT SNAPSHOT k+1
```

Updates carry the state version on which they were computed.

Each asynchronous update is a versioned cognitive transaction:

```text
job_id
snapshot_version
read_set: key -> version
write_set
provenance_roots
deadline
staleness_policy
merge_operator
```

At completion, the runtime validates every read version and provenance root. It commits directly only when those dependencies remain valid. A merge is legal only when the field type declares a commutative and associative merge operator; disjoint writes are insufficient when the computations read shared state. Otherwise, the result may be:

* merged;
* recomputed;
* degraded in confidence;
* discarded.

This prevents late asynchronous work from silently overwriting newer cognition.

Every commit, conflict, merge, degradation, and discard should enter a deterministic replay log. Snapshot conflict semantics are part of CSBC-Core, but the current single-threaded prototype does not evaluate them.

---

# 19. Memory Hierarchy

The architecture should contain at least four operational tiers.

## 19.1 L0: Active cognitive state

The smallest, fastest materialized representations:

* current world;
* active entities;
* current goals;
* current uncertainty;
* active plans.

This is effectively the register file of the cognitive runtime.

L0 must remain bounded. It stores provenance heads or content-addressed roots, not complete evidence chains. The prototype initially exposed this requirement directly: embedding every evidence identifier in each hot node caused state size and snapshot-hash work to grow with history even though the semantic field set was fixed. Complete provenance belongs in L3 and is reached through bounded roots.

## 19.2 L1: Neural semantic memory

The resident foundation model contains generalized semantic knowledge and progressively internalized stable information.

It functions as an extremely high-bandwidth associative knowledge layer.

## 19.3 L2: Explicit semantic memory

Structured facts, relationships, summaries and reusable abstractions stored externally.

## 19.4 L3: Episodic memory

Raw timestamped experience and provenance.

The distinction is important:

[
\text{episodic evidence}
\neq
\text{semantic abstraction}
\neq
\text{parametric knowledge}.
]

---

# 20. RDBMS-to-Neural Consolidation

Parametric consolidation is not required by the central CSBC result. The architecture is divided into:

* **CSBC-Core:** persistent state, sparse validated transitions, bounded control epochs, recurrent arbitration, futures, and durable episodic evidence;
* **CSBC-Plastic:** optional semantic and parametric consolidation with staged evaluation, rollback, provenance, and compute isolation.

The prototype evaluates only a subset of CSBC-Core. Online weight updates remain outside the realtime path and outside the core acceptance criteria.

The proposed long-term memory process is:

[
\text{experience}
\rightarrow
\text{episodic store}
\rightarrow
\text{semantic consolidation}
\rightarrow
\text{parametric consolidation}.
]

Initially, a novel fact may require retrieval:

[
P(\text{retrieve}\mid x)\approx1.
]

If it becomes stable, repeatedly useful, and successfully internalized:

[
P(\text{retrieve}\mid x)\downarrow.
]

The RDBMS remains authoritative.

The neural model becomes increasingly able to approximate the computational consequence of having retrieved the memory.

The training target should therefore not merely be:

> reproduce this database row.

A stronger target is:

[
\pi_{\theta+\Delta\theta}(a\mid s)
\approx
\pi_\theta(a\mid s,R(s)).
]

In words:

> **train the no-retrieval model to behave like the retrieval-augmented model.**

This transforms explicit memory into implicit capability.

---

# 21. Consolidation Must Be Gated

Continuous memory rewriting is dangerous.

Recent experiments on LLM-maintained consolidated memories found that repeated consolidation can degrade useful memories and that preserving raw episodic evidence can outperform forced continuous consolidation. ([arXiv][19])

Therefore CSBC uses:

```text
raw episode
    ↓
candidate abstraction
    ↓
evidence checks
    ↓
candidate parameter update
    ↓
regression evaluation
    ↓
promotion or rejection
```

Raw episodes are never destroyed merely because a higher-level memory exists.

---

# 22. Versioned Parametric Learning

The live cognitive model should not receive uncontrolled gradient updates mid-tick.

Instead:

[
W_{live}
========

W_{base}
+
\Delta W_n.
]

Background training produces:

[
\Delta W_{n+1}^{candidate}.
]

The candidate undergoes:

* new-memory evaluation;
* old-memory regression;
* behavioral regression;
* ToM evaluation;
* action-policy testing;
* safety testing.

If accepted, promotion occurs at a safe temporal boundary:

[
n\rightarrow n+1.
]

Thus neural plasticity becomes transactional.

---

# 23. Retrieval as a Value-of-Information Decision

Even after consolidation, retrieval remains necessary when:

* precision matters;
* provenance matters;
* information is volatile;
* confidence is low;
* consequences are high.

A simple policy is:

[
Retrieve
\iff
U(x)\cdot C(x)\cdot V(x)>\tau
]

where:

* (U) = uncertainty;
* (C) = consequence;
* (V) = expected value of obtaining exact information.

This prevents the semantic cache from being mistaken for authoritative memory.

---

# 24. Architectural Inversion

This is perhaps the most important conceptual contribution of the synthesis.

Most LLM-agent systems can be described as:

```text
LLM invocation
     ↓
state emerges
     ↓
action
```

CSBC instead says:

```text
persistent state
     ↓
LLM transforms selected state
     ↓
persistent state
     ↓
action
```

Formally, the primary object changes from:

[
F_\theta(context)
]

to:

[
X_{t+1}
=

\mathcal{T}_\theta
(
X_t,
E_t,
B_t
).
]

The LLM becomes part of the transition operator (\mathcal{T}).

**The cognitive process exists between model invocations.**

This is a substantial architectural distinction from conventional agents.

---

# 25. Why This Is Performance-Relevant

The proposed architecture attacks several major sources of waste simultaneously.

## 25.1 No repeated historical reconstruction

Persistent state eliminates repeatedly encoding large histories merely to recover the present.

## 25.2 Sparse entity activation

Only active and dirty entity models consume significant compute.

## 25.3 Batching

Homogeneous cognitive updates can run together.

## 25.4 Short outputs

Most cognition produces deltas, labels, logits or latent state rather than long text.

## 25.5 Deadline-aware abandonment

Work that has become irrelevant can be dropped.

## 25.6 Asynchronous execution

External latency does not stall cognition.

## 25.7 Parametric consolidation

Frequently retrieved knowledge can move toward cheaper neural access.

## 25.8 Lazy higher-order ToM

Recursive mental models are created only when useful.

## 25.9 Multi-rate scheduling

Slow-changing cognitive state is not pointlessly updated at sensor frequency.

Together these turn performance from an afterthought into a property of the cognitive model itself.

---

# 26. Proposed Runtime Algorithm

At cognitive tick (k):

```text
1. Collect all events since tick k-1.

2. Commit completed asynchronous operations.

3. Resolve entity identity and update active-set membership.

4. Traverse the dependency graph and compute dirty state.

5. Generate bounded cognitive jobs:
       perception update
       appraisal update
       ToM delta
       self-model delta
       memory lookup
       plan continuation
       etc.

6. Admit jobs according to:
       deadlines
       utility
       uncertainty
       resource budget
       staleness

7. Microbatch compatible neural jobs.

8. Run until budget or deadline.

9. Validate returned state deltas.

10. Preserve incomplete jobs as continuations.

11. Commit coherent cognitive snapshot.

12. Run action arbitration.

13. Dispatch bounded actions.

14. Return control at the temporal boundary.
```

The core invariant is:

[
\boxed{
\text{the runtime always regains control}
}
]

No cognitive subsystem owns the machine indefinitely.

---

# 27. Overload Behavior

A real system must define cognition under insufficient compute.

Suppose demand exceeds the 140 ms budget.

The correct behavior is not uncontrolled latency growth.

It is graceful cognitive degradation.

Possible mechanisms:

### Drop stale updates

If three sensory updates arrived while an older interpretation was running, process the newest useful state instead of all intermediate triggers.

This is directly analogous to stale-trigger skipping demonstrated in recent online LLM-controller work. ([arXiv][3])

### Reduce ToM depth

First-order beliefs remain active.

Second-order beliefs wait.

### Reduce semantic resolution

Update broad appraisal now; refine interpretation later.

### Suspend reflection

Background reflection receives no realtime guarantee.

### Preserve action selection

The system still decides what to do.

This yields:

[
\text{less sophisticated cognition}
]

rather than:

[
\text{no cognition because the queue exploded}.
]

---

# 28. Hardware Architecture

A practical high-performance deployment naturally separates latency-critical inference from plasticity.

```text
             REALTIME COMPUTE
                    │
       ┌────────────┴────────────┐
       │ resident semantic model │
       │ active latent state     │
       │ cognitive microbatches  │
       │ speech/action control   │
       └────────────┬────────────┘
                    │
              shared memory
                    │
       ┌────────────┴────────────┐
       │     durable RDBMS       │
       │     episodic store      │
       └────────────┬────────────┘
                    │
              background bus
                    │
       ┌────────────┴────────────┐
       │   PLASTICITY COMPUTE    │
       │ retrieval abstraction   │
       │ replay                  │
       │ adapter training        │
       │ regression testing      │
       └─────────────────────────┘
```

The realtime accelerator should optimize:

[
p99(\text{control latency}).
]

The plasticity accelerator should optimize:

[
\text{learning throughput}.
]

They are fundamentally different workloads.

---

# 29. A Two-Model Extension

There is no requirement that all cognition use one model scale.

A highly performant architecture might contain:

### Fast semantic model

Used for:

* ToM deltas;
* appraisal;
* intent classification;
* action ranking;
* short speech decisions;
* relevance.

### Slow deliberative model

Used asynchronously for:

* difficult planning;
* ambiguous social reasoning;
* deep reflection;
* complex code;
* scientific reasoning.

The fast model maintains continuity.

The slow model supplies optional depth.

If the slow model returns after the world has changed, its output is version-checked before commit.

This resembles a cognitive version of speculative execution.

---

# 30. Performance Metrics

Tokens per second is not the primary metric.

The runtime should measure:

### Temporal metrics

[
p50,\ p95,\ p99\ tick\ latency
]

[
deadline\ miss\ rate
]

[
maximum\ control\ blackout
]

### Cognitive workload

```text
active entities/tick
dirty entities/tick
direct and transitive dirty fan-out
dependency depth
neural jobs/tick
jobs deferred/tick
jobs invalidated/tick
state bytes touched/tick
active-set churn
```

### Neural efficiency

```text
microbatch occupancy
prefill/decode ratio
KV residency
KV eviction
tokens per cognitive transition
HBM utilization
```

### ToM quality

```text
belief calibration
belief-change detection
action-prediction accuracy
historical belief recall
higher-order belief accuracy
```

### Incremental-state integrity

```text
false-clean rate
unnecessary-dirty rate
spurious-mutation rate
error-state occupancy
error episode age
repair success and harm rate
reconstruction correction rate
blast radius
```

### Snapshot consistency

```text
read/write conflict rate
invalidated asynchronous results
stale commit rate
deterministic replay equality
late-result merge rate
```

### Memory

```text
retrievals per decision
retrieval cache hit rate
episodic-to-semantic promotion
semantic-to-parametric promotion
post-consolidation retrieval reduction
```

### Behavioral metrics

```text
interrupt response latency
speech correction latency
action reconsideration frequency
incorrect commitment duration
```

---

# 31. Empirical Evaluation: Sparse Belief Ledger

The complete architecture contains more mechanisms than can be evaluated responsibly in a first prototype. We therefore isolate its central computational claim:

> Under bounded state-change rate and explicit dependency routing, persistent sparse state transitions should decouple decision work from accumulated interaction history while preserving logical continuity better than repeated reconstruction.

The experiment does not test the temporal scheduler, asynchronous snapshot conflicts, microbatching, latent residuals, online consolidation, or hard real-time service.

## 31.1 Environment and state

Sparse Belief Ledger is a deterministic multi-entity server environment. Its canonical immutable snapshot contains explicit world state, the presence of recurring entities, first-order beliefs about two servers, deterministic control state, per-node versions, and provenance. Eleven semantic fields are scored.

Natural-language events belong to seven classes:

```text
DIRECT
OBSERVATIONAL
COMMUNICATED
TEMPORAL
CORRECTION
FALSE_BELIEF
SECOND_HAND
```

The frozen endurance trajectory contains 100,000 ticks, of which 5,038 contain events and 94,962 are quiescent. At the paper's nominal 140 ms clock this represents 3.89 hours of environment time. Evaluation occurs at ticks 1,000, 5,000, 10,000, 25,000, 50,000, and 100,000.

The simulator independently produces the correct transition, oracle dirty set, and expected snapshot. The runtime router is hand declared. This choice isolates the value of persistence and sparse write authority without pretending that learned dependency discovery has been solved.

## 31.2 Conditions

All neural conditions use the same Qwen3.5 9B Heretic model quantized to Q4_K_S, temperature zero, top-k one, and a grammar-constrained compact value protocol.

**A — Full-history reconstruction.** At every decision boundary, the model receives the initial state and all events so far, then emits the complete current semantic state.

**B — Fixed-window reconstruction.** The model receives the initial state and the ten most recent events, then emits the complete current semantic state.

**D — Persistent sparse state.** Each event dirties typed fields through the declared router. The model receives only current values for those fields and the new event, emits only their replacements, and the runtime structurally shares all untouched state. A decision reads the maintained snapshot without a neural call.

**E — Oracle sparse reducer.** The deterministic simulator applies the correct sparse transition. It is a diagnostic ceiling rather than a competing learned system.

The endurance conditions ran on a dual-socket Intel Xeon E5-2687W v4 host with 24 physical cores and approximately 256 GiB RAM. llama.cpp was configured for a 131,072-token context. The model declares a 262,144-token training context.

All inference is charged. For D, cumulative cost includes every state-maintenance call since tick zero; decision latency does not hide that maintenance. For A and B, cumulative cost includes every reconstruction query. llama.cpp reused common prompt prefixes between successive A queries, so A's measured wall time already benefits from KV/prompt caching even though token accounting reports each submitted prompt in full.

## 31.3 Accuracy and decision cost

| Condition | Aggregate semantic accuracy | Final semantic accuracy | Final decision prompt | Final decision latency | Total prompt tokens | Total inference wall |
|---|---:|---:|---:|---:|---:|---:|
| A Full history | 48.5% | 36.4% | 77,493 | 621.73 s | 149,243 | 1,025.53 s |
| B Fixed window | 54.5% | 63.6% | 447 | 4.82 s | 2,703 | 28.11 s |
| D Persistent sparse | 75.8% | 81.8% | 0 | 0 neural s | 792,525 | 6,709.86 s |
| E Oracle sparse | 100.0% | 100.0% | 0 | 0 | 0 | 0 |

Aggregate accuracy pools all eleven semantic fields over the six evaluation boundaries. D exceeds A by 27.3 percentage points and B by 21.2 points. At tick 100,000, the margins are 45.5 and 18.2 points, respectively.

The result does not show that persistence always uses less total inference. With only six decisions, D spends 5.31 times A's prompt tokens and 6.54 times A's inference wall time maintaining a low-latency present. It demonstrates a different computational regime: incremental maintenance purchases a zero-neural-work decision boundary and improved continuity. The total-compute crossover depends on decision frequency.

## 31.4 History scaling

A's current prompt grew from 1,006 tokens at tick 1,000 to 77,493 at tick 100,000, a 77.0-fold increase. Its decision latency grew from 8.81 to 621.73 seconds. The final request contained 77,493 prompt tokens, of which llama.cpp evaluated 38,581 not already covered by its retained prefix.

B remained bounded between 446 and 460 prompt tokens and 4.54 and 4.82 seconds, but discarded older evidence.

D's maintenance cost followed new information:

| Interval ending at tick | New events | D interval prompt tokens | Prompt tokens per new event |
|---:|---:|---:|---:|
| 1,000 | 49 | 7,554 | 154.2 |
| 5,000 | 204 | 32,035 | 157.0 |
| 10,000 | 267 | 42,116 | 157.7 |
| 25,000 | 796 | 125,250 | 157.3 |
| 50,000 | 1,264 | 199,240 | 157.6 |
| 100,000 | 2,458 | 386,330 | 157.2 |

The interval remained within 154.2–157.7 prompt tokens per new event across a 100-fold increase in elapsed tick history. This is the direct empirical support for the conditional information-flux claim.

## 31.5 Write isolation, drift, and repair

D completed 4,835 neural transitions with:

* zero failed transitions;
* zero quiescent-state invariant violations;
* zero false-clean routing errors;
* zero unnecessary-dirty updates;
* zero spurious mutations outside the oracle dirty set.

Sparse write authority therefore provided a mechanical correctness property on this domain: a field outside the routed set could not be accidentally rewritten by the model.

Persistence did not eliminate semantic error. D entered 946 error episodes and repaired 944 of them. Error-state occupancy was 20.153% of semantic field-ticks; mean observed error age was 234.3 ticks, maximum age was 1,688 ticks, and at most seven fields were simultaneously wrong. Two errors remained at the end.

The weakness was concentrated in presence tracking. At the final boundary, all world and belief fields were correct while both presence fields were wrong. Across checkpoints, world, belief, and presence accuracies were 88.9%, 86.1%, and 25.0%, respectively. This prevents a broad claim that persistent sparse state improves every semantic type and motivates typed transition operators or deterministic reducers for simple state.

The repair trajectory is nevertheless important: sparse errors were not necessarily permanent. New relevant events repaired 944 prior error episodes without full reconstruction. This motivates the separate hypothesis that periodic provenance replay or state-wide offline auditing could reduce residual error occupancy, but the current experiment does not test such maintenance and says nothing about why biological organisms sleep.

## 31.6 Short-horizon replication

Before the endurance run, a frozen three-seed sweep used seeds 11, 29, and 47 over 5,000 ticks with evaluation at ticks 100, 500, 1,000, and 5,000. At the final boundary, D achieved 84.8% ± 10.5% semantic accuracy, compared with 66.7% ± 13.9% for A and 66.7% ± 5.2% for B. A's current prompt grew from 388 ± 8 tokens at tick 100 to 3,533 ± 205 at tick 5,000, while D remained between 157.2 and 163.7 prompt tokens per incoming event across intervals. All three D runs had zero malformed transitions, false-clean errors, spurious mutations, and quiescent-state invariant violations.

This replication supports the direction of the endurance result across three additional trajectories, but its histories are much shorter and its eleven-field checkpoint accuracy remains coarse.

## 31.7 Scope and threats to validity

The evidence is deliberately narrow:

* one frozen endurance trajectory;
* one quantized 9B model;
* eleven scored semantic fields;
* oracle-known dependency structure and bounded fan-out;
* a single-threaded transition path without snapshot conflicts;
* no learned latent state;
* no scheduler or microbatching comparison;
* no active-set churn or large entity population;
* no hard real-time implementation.

The median D transition was 1.370 seconds, with p95 1.595 seconds and p99 1.862 seconds on the CPU host. These measurements reject any claim that this prototype meets the illustrative 140 ms boundary. They do not reject the architectural separation between an always-bounded control path and slower asynchronous semantic maintenance, because that separation is not implemented here.

The experiment establishes two narrower claims:

[
\boxed{
\text{under bounded fan-out, sparse maintenance work followed new information rather than accumulated history}
}
]

[
\boxed{
\text{sparse write isolation improved semantic continuity and prevented unrelated mutation on this domain}
}
]

Replication across seeds, models, active-state sizes, fan-out distributions, query rates, and fault injections remains necessary.

---

# 32. Extended Falsifiable Experimental Program

The architecture should be tested against reconstruction-based baselines rather than merely demonstrated qualitatively.

## Experiment 1: Persistent versus reconstructed ToM

Section 31 implements a minimal version of this comparison. The next study should replicate it across seeds, models, and decision rates rather than treating one endurance trace as definitive.

Compare:

### Baseline

Every decision reconstructs entity state from full history.

### CSBC

Entity state persists and receives only event deltas.

Measure:

* inference tokens;
* wall-clock latency;
* accelerator or CPU utilization;
* ToM accuracy;
* belief-state consistency.

Test two separate predictions:

[
C_{decision,CSBC}\ll C_{decision,reconstruct}
]

as histories become long, and

[
C_{maintenance,CSBC}\propto\text{new relevant information}.
]

Sweep decision frequency explicitly to locate the total-compute crossover. Do not predict that CSBC uses less lifetime inference when decisions are rare.

---

## Experiment 2: Number of known entities

Hold active entities constant while increasing stored entities:

[
100,\ 1K,\ 10K,\ 100K,\ 1M.
]

The desired result is near-flat tick latency.

Then increase active dirty entities independently.

Also vary direct dirty-set size, transitive fan-out, dependency depth, active-set churn, state-cache hit rate, provenance-graph size, and late asynchronous results. This empirically tests the conditions under which compute scales with information flux rather than memory population.

---

## Experiment 3: Dynamic belief tracking

Use DToM-Track-style temporally evolving beliefs rather than isolated false-belief questions. ([arXiv][7])

Compare:

* context-only LLM;
* explicit structured state;
* latent persistent state;
* hybrid structured + latent state.

---

## Experiment 4: Tick-frequency sweep

Test:

[
70,\ 100,\ 140,\ 200,\ 280,\ 500\text{ ms}.
]

Measure both cognitive quality and compute pressure.

The purpose is not to assume 140 ms is correct.

It is to discover the point where temporal responsiveness stops providing enough additional utility to justify its cost.

---

## Experiment 5: Bounded incremental speech

Construct cases in which critical information arrives while the system is mid-sentence.

Measure how quickly the system can:

* pause;
* reverse;
* correct;
* abandon an obsolete utterance;
* act instead of speaking.

Compare against ordinary token streaming.

---

## Experiment 6: Cognitive overload

Artificially saturate inference.

Verify:

[
deadline\ pressure
\rightarrow
\text{graceful quality reduction}
]

rather than uncontrolled latency.

---

## Experiment 7: Consolidation

Measure the probability of external retrieval for a stable fact over repeated experience:

[
R(m,t)
======

\frac{\text{retrievals involving }m}
{\text{decision opportunities involving }m}.
]

A successful consolidation system should exhibit:

[
R(m,t)\downarrow
]

without corresponding correctness loss.

---

## Experiment 8: Dependency faults and state recovery

Inject:

* a missing dependency edge;
* an unnecessary edge;
* an incorrect semantic delta;
* an entity-identity collision;
* a dropped or reordered event;
* an asynchronous result delayed by ten control epochs.

Measure:

[
T_{detect},\quad
T_{repair},\quad
P(\text{error propagation}),\quad
\text{blast radius}.
]

False-clean rate is the primary correctness metric. Unnecessary-dirty rate is a performance metric. Periodic reconstruction or provenance replay should be evaluated as a repair mechanism, not assumed to work.

---

## Experiment 9: Snapshot conflicts and scheduler policy

Create asynchronous jobs with overlapping read sets and both disjoint and overlapping writes. Inject dependency changes before completion. Measure conflict rate, invalidated-result rate, stale-commit rate, deterministic replay equality, and decision regret.

Under a fixed inference budget, compare FIFO, priority-only, earliest-deadline-first, and expected-decision-value scheduling across competing speech, ToM, retrieval, appraisal, and planning jobs. Report queueing separately from execution and sweep microbatch delay against p50, p95, and p99 latency.

---

# 33. Important Failure Modes

## 33.1 State drift

Repeated small incorrect deltas may accumulate into an incorrect mental model.

The endurance prototype measured this directly. Sparse state occupied an incorrect semantic value for 20.153% of scored field-ticks, although 944 of 946 error episodes were later repaired by relevant events. Two presence errors survived to the final snapshot. Persistent state therefore preserves both correct knowledge and mistakes; continuity is not the same as correctness.

Periodic reconstruction from authoritative evidence may therefore still be useful as a background integrity check.

Incremental maintenance should replace routine reconstruction, not eliminate the ability to reconstruct.

---

## 33.2 Entity identity errors

Incorrectly merging two people can corrupt persistent ToM much more severely than a transient prompt error.

Identity resolution must therefore be treated as an integrity-sensitive subsystem.

---

## 33.3 Hallucinated mental states

Theory of mind is inference, not mind reading.

Every mental-state estimate should preserve:

* confidence;
* provenance;
* alternatives.

---

## 33.4 Nested-belief explosion

Uncontrolled second- and third-order ToM recreates the computational problems encountered by I-POMDPs. ([arXiv][4])

Recursive states must remain sparse.

---

## 33.5 Neural-state opacity

Pure latent state is fast but difficult to inspect.

Pure symbolic state is inspectable but may discard nuance.

A hybrid architecture is therefore preferable.

---

## 33.6 KV-cache explosion

Long-lived cognitive contexts can create severe memory pressure; current agent-serving research already observes pathological KV behavior in sustained agent workloads. ([arXiv][15])

KV caches should be treated as disposable acceleration state, not cognitive truth.

---

## 33.7 Consolidation corruption

Incorrect abstractions can become harder to correct once parametrically learned.

Versioning, provenance and regression evaluation are mandatory.

---

## 33.8 Unbounded hot-state metadata

A fixed number of semantic fields does not imply bounded active-state cost if each field embeds an ever-growing provenance list, message history, or latent cache. The prototype's first hot-state representation accumulated complete evidence-ID chains and made snapshot hashing revisit expanding metadata. L0 must retain bounded heads or content-addressed roots while full evidence remains in durable storage.

---

## 33.9 Type-specific transition weakness

One shared semantic operator may not update all field types equally. In the endurance run, persistent world and belief state substantially outperformed presence state, and both presence fields were wrong at the end. Simple categorical transitions may be better implemented by deterministic reducers, while neural inference is reserved for genuinely semantic ambiguity.

---

# 34. Relationship to Global-Workspace Architectures

The proposed architecture is compatible with Global Workspace Theory but does not require its philosophical claims.

A workspace can serve as a low-capacity mechanism for choosing what information receives broad cognitive distribution.

LIDA is one computational example of repeated cognitive cycles, and recent language-agent work has begun explicitly connecting global-workspace concepts to event-driven LLM systems. ([PLOS][16])

Within CSBC, workspace competition becomes another resource-governance mechanism:

[
\text{many state changes}
\rightarrow
\text{limited broadcast set}
\rightarrow
\text{distributed subsystem updates}.
]

This is useful primarily because bandwidth is finite.

---

# 35. The Potential AGI Significance

This section is intentionally separated from the engineering evidence.

Nothing in the cited literature demonstrates that this architecture produces AGI.

The claim here is narrower.

Modern foundation models already contain substantial:

* linguistic competence;
* world knowledge;
* social priors;
* abstraction;
* planning capability;
* code knowledge;
* transfer ability.

Language-agent research has therefore increasingly focused on architectures combining memory, actions, reasoning, and environmental interaction. ([arXiv][2])

What such systems often lack is a computational substrate in which these capabilities form **one continuously evolving causal process**.

CSBC supplies several properties absent from simple prompt-response agents:

1. **Temporal continuity**

   State exists even when no model call is running.

2. **Persistent perspective**

   The agent carries its own history forward rather than reconstructing an identity from prompt text.

3. **Continuous social cognition**

   Other agents' mental models evolve through experience.

4. **Interruptibility**

   No generated plan or utterance permanently owns control.

5. **Resource-bounded deliberation**

   Thinking competes with acting for time.

6. **Partial cognition**

   The system can know that it has not finished understanding something.

7. **Asynchronous cognition**

   Multiple processes may remain unresolved simultaneously.

8. **Incremental world modeling**

   New evidence changes existing models rather than replacing them.

9. **Consolidation**

   Repeated experience may progressively alter future computation.

10. **Closed-loop action**

    Cognition is continuously tested against the external world.

These properties do not prove general intelligence.

They do, however, alter the object being built.

Instead of:

[
\boxed{\text{a model that repeatedly answers}}
]

the resulting system is:

[
\boxed{\text{a persistent dynamical process that uses models}}
]

That may be an important architectural step.

---

# 36. The Most Important Distinction, Restated

The central idea of this paper should not be confused with merely running an LLM frequently.

It is **not**:

> Run an LLM every 140 ms.

It is **not**:

> Reconstruct the self and every other entity every 140 ms.

It is **not**:

> Finish an entire cognitive cycle every 140 ms.

It is **not**:

> Put a large conversation context into a faster inference engine.

The proposal is:

> **Maintain cognition as persistent state.**

> **Allow new evidence to dirty only relevant portions of that state.**

> **Use learned semantic models to calculate incremental transitions.**

> **Give every cognitive process bounded opportunities to advance.**

> **Return control to action selection at a predictable temporal boundary.**

> **Permit unfinished cognition to persist across those boundaries.**

In compact form:

[
\boxed{
\textbf{continuous state}
+
\textbf{sparse updates}
+
\textbf{bounded progress}
+
\textbf{recurrent action selection}
}
]

This can also be stated as:

[
\boxed{
\textbf{temporal continuity is a property of state,
not of uninterrupted computation}
}
]

That distinction is what changes the feasibility of the system.

---

# 37. What Is Established and What Is Proposed

For scientific clarity, the components should be separated by evidential status.

## Established or independently demonstrated

Researchers have demonstrated:

* recursive belief-state updating in partially observable decision systems; ([arXiv][1])
* explicit models of other agents and nested beliefs in I-POMDPs; ([arXiv][4])
* persistent neural entity-state updates; ([arXiv][5])
* learned character and mental-state representations in ToMnet; ([Proceedings of Machine Learning Research][20])
* temporal belief tracking as a distinct ToM challenge for LLMs; ([arXiv][7])
* benefits from explicit state tracking in LLM ToM tasks; ([arXiv][9])
* wall-clock synchronized incremental LLM speech; ([arXiv][10])
* online LLM controllers that reconsider actions on temporal grids and skip stale triggers; ([arXiv][3])
* asynchronous reasoning while environmental operations remain outstanding; ([arXiv][17])
* efficient multi-request LLM serving and agent-aware scheduling. ([arXiv][13])

## Demonstrated in the present prototype

Within one bounded, oracle-routed domain, this paper demonstrates:

* exact snapshot identity and hash preservation across 94,962 quiescent ticks with no semantic model call;
* sparse structural write authority with zero false-clean, unnecessary-dirty, or spurious mutations;
* maintenance prompt cost of 154.2–157.7 tokens per incoming event across 100,000 ticks;
* a full-history decision prompt that grew from 1,006 to 77,493 tokens;
* aggregate semantic accuracy of 75.8% for persistent sparse state versus 48.5% for full replay and 54.5% for a ten-event window;
* repair of 944 out of 946 observed persistent error episodes;
* zero neural work at the evaluated decision boundary after state had been maintained.

The prototype also demonstrates limits: maintenance used more total inference than six reconstruction queries, two presence errors remained, and 1.37-second median transitions did not meet the illustrative 140 ms target.

## Proposed synthesis

This paper proposes combining those mechanisms into one runtime with:

* a soft real-time cognitive control boundary, with a hard-bound deployment requiring additional isolation and WCET evidence;
* persistent external cognitive state;
* event-driven dirty propagation;
* sparse active ToM;
* hybrid structured/latent entity representations;
* a shared resident semantic model;
* tick-level neural microbatching;
* bounded incremental internal cognition;
* bounded incremental speech;
* deadline-aware action arbitration;
* asynchronous continuations;
* durable episodic memory;
* optional semantic consolidation;
* optional parametric consolidation;
* transactional model promotion for CSBC-Plastic.

I am not aware, from the literature examined here, of an existing system that combines this entire set of properties as a single runtime invariant.

That is a statement about the examined literature, not a proof that no such system exists.

## Hypothesis

The final claim remains explicitly hypothetical:

> **A temporal architecture of this general form may be an important missing systems layer between powerful foundation models and general persistent agency.**

The state-first incremental subset now has initial empirical support. The complete runtime and its relevance to general persistent agency remain hypotheses requiring substantially broader implementation and testing.

---

# 38. Conclusion

The computational challenge of persistent machine cognition is frequently framed as a model-capacity problem.

A different possibility is that a significant portion of the difficulty is architectural.

If a system repeatedly reconstructs itself, its world, its memories, and the minds of others from text, decision cost grows with retained history and state can drift even when no relevant evidence changed.

The alternative is conceptually simple:

[
X_{t+1}=X_t+\Delta X_t.
]

The implications are not.

Once cognitive state persists independently of model execution:

* theory of mind becomes a filtering problem;
* memory becomes a hierarchy;
* action selection becomes a scheduler;
* speech becomes a bounded motor action;
* reasoning becomes resumable;
* uncertainty becomes state;
* asynchronous operations become futures;
* model inference becomes a shared semantic service;
* learning becomes consolidation;
* temporal continuity becomes intrinsic.

The prototype provides initial evidence for the narrow version of this claim. Across 100,000 ticks, sparse maintenance cost remained effectively constant per incoming event and produced substantially higher semantic accuracy than both reconstruction baselines. Full replay's final decision required a 77,493-token prompt and 621.7 seconds; the maintained snapshot required no neural decision-time work.

The same result establishes the boundary of the claim. Sparse maintenance spent more total inference under a six-decision workload, retained two final errors, and ran far slower than 140 ms on the CPU platform. A complete CSBC system must therefore choose maintenance by expected decision value, bound active-state residency and dependency fan-out, preserve reconstruction as a repair path, and isolate the action-control fast path from slower semantics.

Under those conditions, the architecture is intended to maintain large accumulated memory while expending active computation only on a bounded relevant closure of what changed.

Its core scaling objective is therefore:

[
\boxed{
\text{compute} \propto
\text{relevant information change}
}
]

rather than:

[
\boxed{
\text{compute} \propto
\text{everything the agent has ever experienced}.
}
]

That is the performance argument.

The deeper hypothesis is that it may also be a cognitive argument.

A generally intelligent system may not need to reconsider its entire existence at every instant.

It may need something much more mundane and much more powerful:

**to remain the same system from one instant to the next, while changing only what the new instant requires.**

---

# Artifact Availability

The accompanying `experiment/` artifact contains the implementation, frozen configurations and manifests, experiment log, result tables, and SHA-256 provenance. The completed endurance summaries and ledgers are stored under `experiment/results/raw/endurance-100k-v2`; the manuscript-level tabulation is `experiment/results/tables/endurance-100k-v2.md`.

---

# References

Rabinowitz, N. C., Perbet, F., Song, H. F., Zhang, C., Eslami, S. M. A., & Botvinick, M. *Machine Theory of Mind*. ICML, 2018. ([Proceedings of Machine Learning Research][20])

Henaff, M., Weston, J., Szlam, A., Bordes, A., & LeCun, Y. *Tracking the World State with Recurrent Entity Networks*. ICLR, 2017. ([arXiv][5])

Gmytrasiewicz, P. J., & Doshi, P. *A Framework for Sequential Planning in Multi-Agent Settings / Interactive POMDPs*. ([arXiv][1])

Doshi, P., & Gmytrasiewicz, P. J. *Monte Carlo Sampling Methods for Approximating Interactive POMDPs*. Journal of Artificial Intelligence Research. ([arXiv][4])

Sumers, T. R., Yao, S., Narasimhan, K., & Griffiths, T. L. *Cognitive Architectures for Language Agents*. TMLR / arXiv:2309.02427. ([arXiv][2])

Madl, T., Baars, B. J., & Franklin, S. *The Timing of the Cognitive Cycle*. PLOS ONE, 2011. ([PLOS][16])

Veluri, B. et al. *Synchronous LLMs as Full-Duplex Dialogue Agents*. EMNLP, 2024. ([arXiv][10])

Nguyen, T. N., Phan, D. N., & Gonzalez, C. *Dynamic Theory of Mind as a Temporal Memory Problem: Evidence from Large Language Models*. arXiv:2603.14646, 2026. ([arXiv][7])

Chen, R. et al. *Learning Dynamic Belief Graphs for Theory-of-Mind Reasoning*. arXiv:2603.20170, 2026. ([arXiv][8])

Zhu, W. B., Yi, Q. T., Jia, R., & Thomason, J. *PDDL-Mind: Large Language Models are Capable on Belief Reasoning with Reliable State Tracking*. arXiv:2604.17819, 2026. ([arXiv][21])

Zhang, H. et al. *DuplexSLA: A Full-Duplex Spoken Language Model with Synchronized Speech, Language, and Action*. arXiv:2605.20755, 2026. ([arXiv][22])

*Learning When to Think While Listening in Large Audio-Language Models*. arXiv:2605.27190, 2026. ([arXiv][3])

*The Silent Thought: Modeling Internal Cognition in Full-Duplex Spoken Dialogue Models via Latent Reasoning*. arXiv:2603.17837, 2026. ([arXiv][12])

Hooper, C. et al. *Building Interactive Real-Time Agents with Asynchronous I/O and Speculative Tool Calling*. arXiv:2605.13360, 2026. ([arXiv][17])

Feng, G., Mao, H., Dutta, P., & Gonzalez, J. E. *Concurrency without Model Changes: Future-based Asynchronous Function Calling for LLMs*. arXiv:2605.15077, 2026. ([arXiv][18])

Kwon, W. et al. *Efficient Memory Management for Large Language Model Serving with PagedAttention*. arXiv:2309.06180, 2023. ([arXiv][13])

Luo, M. et al. *Autellix: An Efficient Serving Engine for LLM Agents as General Programs*. arXiv:2502.13965, 2025. ([arXiv][14])

Chen, Q. et al. *CONCUR: High-Throughput Agentic Batch Inference of LLM via Congestion-Based Concurrency Control*. arXiv:2601.22705, 2026. ([arXiv][15])

Zhang, D. et al. *Useful Memories Become Faulty When Continuously Updated by LLMs*. arXiv:2605.12978, 2026. ([arXiv][19])

[1]: https://arxiv.org/pdf/1109.2135?utm_source=chatgpt.com "A Framework for Sequential Planning in Multi-Agent Settings"
[2]: https://arxiv.org/abs/2309.02427?utm_source=chatgpt.com "Cognitive Architectures for Language Agents"
[3]: https://arxiv.org/html/2605.27190v1 "Learning When to Think While Listening in Large Audio-Language Models"
[4]: https://arxiv.org/abs/1401.3455 "[1401.3455] Monte Carlo Sampling Methods for Approximating Interactive POMDPs"
[5]: https://arxiv.org/abs/1612.03969 "[1612.03969] Tracking the World State with Recurrent Entity Networks"
[6]: https://proceedings.mlr.press/v80/rabinowitz18a/rabinowitz18a.pdf?utm_source=chatgpt.com "Machine Theory of Mind"
[7]: https://arxiv.org/abs/2603.14646?utm_source=chatgpt.com "Dynamic Theory of Mind as a Temporal Memory Problem: Evidence from Large Language Models"
[8]: https://arxiv.org/abs/2603.20170?utm_source=chatgpt.com "Learning Dynamic Belief Graphs for Theory-of-mind Reasoning"
[9]: https://arxiv.org/html/2604.17819v1 "PDDL-Mind: Large Language Models are Capable on Belief Reasoning with Reliable State Tracking"
[10]: https://arxiv.org/html/2409.15594v1?utm_source=chatgpt.com "Synchronous LLMs as Full-Duplex Dialogue Agents"
[11]: https://arxiv.org/html/2605.20755v1 "DuplexSLA: A Full-Duplex Spoken Language Model with Synchronized Speech, Language, and Action"
[12]: https://arxiv.org/html/2603.17837v5 "The Silent Thought: Modeling Internal Cognition in Full-Duplex Spoken Dialogue Models via Latent Reasoning"
[13]: https://arxiv.org/abs/2309.06180?utm_source=chatgpt.com "Efficient Memory Management for Large Language Model Serving with PagedAttention"
[14]: https://arxiv.org/abs/2502.13965?utm_source=chatgpt.com "Autellix: An Efficient Serving Engine for LLM Agents as General Programs"
[15]: https://arxiv.org/abs/2601.22705?utm_source=chatgpt.com "CONCUR: High-Throughput Agentic Batch Inference of LLM via Congestion-Based Concurrency Control"
[16]: https://journals.plos.org/plosone/article?id=10.1371%2Fjournal.pone.0014803&utm_source=chatgpt.com "The Timing of the Cognitive Cycle | PLOS One"
[17]: https://arxiv.org/abs/2605.13360 "Building Interactive Real-Time Agents with Asynchronous I/O and Speculative Tool Calling"
[18]: https://arxiv.org/abs/2605.15077 "Concurrency without Model Changes: Future-based Asynchronous Function Calling for LLMs"
[19]: https://arxiv.org/abs/2605.12978?utm_source=chatgpt.com "Useful Memories Become Faulty When Continuously Updated by LLMs"
[20]: https://proceedings.mlr.press/v80/rabinowitz18a.html?utm_source=chatgpt.com "Machine Theory of Mind"
[21]: https://arxiv.org/abs/2604.17819?utm_source=chatgpt.com "PDDL-Mind: Large Language Models are Capable on Belief Reasoning with Reliable State Tracking"
[22]: https://arxiv.org/abs/2605.20755?utm_source=chatgpt.com "DuplexSLA: A Full-Duplex Spoken Language Model with Synchronized Speech, Language, and Action"
