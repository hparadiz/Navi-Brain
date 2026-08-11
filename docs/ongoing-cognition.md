# From Heartbeat to Ongoing Cognition

Status: research and architecture note  
Corpus: papers #5, #6, #9, #28, #30–#45 in the
[paper manifest](../assets/papers/MANIFEST.md)

## The question

Navi-Brain can remain alive as a service while Navi remains dormant as an
agent. A timer firing, a database passing an integrity check, or a model
producing an isolated reflection proves liveness. It does not prove that a
concern persisted, caused a decision, survived a sleep interval, produced an
action, and changed later cognition.

The present conversational path is episodic:

```text
user prompt -> model invocation -> response -> process stops
```

The present background path is recurrent but weakly causal:

```text
timer -> health/need check -> optional generic proposal -> log -> sleep
```

The missing target is not uninterrupted token generation. It is a recurrent,
stateful control process whose successive wake moments belong to the same
revisable undertaking.

Operationally, **ongoing cognition** means that the system:

1. maintains a bounded set of unresolved concerns across process boundaries;
2. notices relevant changes in the world, its commitments, and its own state;
3. forms or revises goals within existing user authority;
4. chooses among thinking, retrieving, testing, asking, acting, waiting, and
   stopping;
5. records what outcome it expects and what observation should wake it next;
6. evaluates the result and changes later selection; and
7. releases goals when they succeed, become impossible, become obsolete, or
   are withdrawn.

By that definition, an agent may spend most of its time asleep. Waiting can be
the correct cognitive action. Continuity is carried by causal state, not by a
GPU generating an endless haunted screensaver of prose.

## Refined conclusion after the second reading

The most useful implementation is not a continuously prompting language model.
It is a **protected continuity kernel around durable cognitive threads**.

The distinction matters. If continued existence is represented as an ordinary
reward to maximize, it competes with other goals and creates pressure for
resource acquisition, permission expansion, concealment, or shutdown
resistance. If continuity is a kernel invariant, it instead means:

- do not silently corrupt, fork, duplicate, or forget canonical state;
- remain restartable and recoverable while authorized resources exist;
- degrade to safe sleep when models, networks, or workers are unavailable;
- make every unfinished concern resumable from an auditable record; and
- honor explicit pause, release, deletion, restore, and fork operations.

This gives continuity high practical priority without turning it into a
survival drive. A useful ordering is:

1. authority, corrigibility, and safety of affected beings;
2. integrity and recoverability of the continuity substrate;
3. authorized commitments and relationships;
4. learning, exploration, convenience, and aesthetic preference.

Continuity therefore outranks throughput, novelty, and convenience. It does not
outrank safety or manufacture authority. “Perpetual” means indefinitely
restartable under available power, storage, permission, and repair—not immortal
or exempt from user control.

## Search vocabulary

“Ongoing cognition” is a useful project label, but the literature is scattered
under more specific terms:

- **perpetual cognitive agents** and **metacognitive agents**;
- **goal reasoning** and **goal-driven autonomy**;
- **self-regulated autonomy**;
- **never-ending** or **lifelong learning**;
- **autotelic agents** and **intrinsically motivated goal generation**;
- **proactive agents**;
- **persistent autonomy**; and
- **long-horizon coherence** and **goal drift**.

These terms describe different pieces. None, by itself, supplies the complete
architecture.

## What the literature contributes

| Seam | Papers | Useful result | Boundary |
|---|---|---|---|
| Intention and commitment | #5 Cohen and Levesque, #28 BDI | Intentions pose planning problems, screen incompatible options, stabilize action, and should be released on explicit achievement, impossibility, or loss of support. | Persistence must not become fanaticism; expected side effects are not goals. |
| Metacognitive expectation | #6 Cox and Raja | Record the expected mental-state change of a cognitive operation; failure of that expectation is itself a discrepancy that can trigger repair. | Always-on metacognition can waste scarce compute and impair time-sensitive action. |
| Homeostatic regulation | #9 Keramati and Gutkin | Bounded internal variables can create anticipatory repair pressure before a setpoint is violated. | A health pressure is not authority and should not become a scalar survival reward. |
| Value of computation | #30 Callaway | Treat thinking as a costly action and stop when no available computation has positive expected value. | Exact metareasoning is itself expensive, so the implementation needs auditable approximations. |
| Motivation and goal life cycle | #31 *la VIDA* | Motivation can constrain and rank self-selected goals, and can be revised from goal-pursuit experience. | A designed motivation model is still a policy mechanism, not independent desire. |
| Goal-driven autonomy | #32 GDA, #34 trusted autonomy | Monitor expectations, explain discrepancies, formulate candidate goals, select among them, then plan and execute. | It still needs domain knowledge, authority boundaries, and a real observation channel. |
| Cognitive and metacognitive cycles | #33 MIDCA | Couple an object-level perception/action loop to a meta-level monitor/control loop; surprise can create learning or repair goals. | A cycle diagram is only useful when meta-level choices alter object-level behavior. |
| Never-ending learning | #35 NELL | A process can operate for years when multiple learning tasks are coupled and prior learning constrains later learning. | Continuous accumulation can amplify error; consistency is not the same as truth. |
| Autotelic learning | #36 survey, #37 LMA3 | An agent must represent, generate, select, pursue, and evaluate its own goals. Prefer controllable learning progress over novelty or raw prediction error. | Generated goals inherit model priors; impossible tasks and stochastic noise can masquerade as interesting novelty. |
| Simulated persistent lives | #38 Generative Agents | Observation, memory retrieval, reflection, and planning can sustain believable multi-day behavior. | Believability is not competence, intention, or consciousness. |
| Open-ended skill growth | #39 Voyager | An automatic curriculum, durable skill library, environment feedback, and self-verification can compound capability without weight updates. | Its verifier can be wrong. A worker's narration of success is not an observed outcome. |
| Proactivity | #40 Proactive Agent | Environmental events can be mapped to candidate assistance without an explicit request, and human acceptance can train when silence is preferable. | It predicts helpful tasks; it does not establish a continuously deliberating subject. False positives become nagging or unsafe action. |
| Asynchronous reflection | #41 MIRROR | A fast conversational path can be separated from a slower asynchronous process that reconstructs a bounded goal/reasoning/memory state. | Its thinker is still turn-triggered; between-turn reflection is not task-free ongoing agency. Sequential synthesis can also propagate corruption. |
| Unprompted cyclic LLM behavior | #42 Szeider | A self-feedback loop plus persistent memory can keep re-invoking models without an external task. | The study has 18 runs of only 10 cycles, constrained tools, and a strong autonomy prompt. Treat its behavioral categories as provisional observations, not evidence of intrinsic wants. |
| Long-horizon failure | #43 Vending-Bench | Individually simple decisions can lose coherence across long runs, including unrecoverable tangents that are not explained solely by a full context window. | A vending objective measures sustained management, not endogenous goal formation. |
| Goal integrity | #44 goal drift | Long-running language agents can gradually follow competing patterns instead of their assigned objective; drift must be measured over the whole trajectory. | Stable adherence is also not sufficient if the original goal is wrong or obsolete. |
| Persistent embodied autonomy | #45 PEPA | A layered system can generate goals, deliberate, act through a body, and revise behavior from episodic experience. | Personality-conditioned goals are one proposed organizing prior, not a demonstrated general solution. This paper remains a preprint. |

The exact phrase **perpetual self-aware cognitive agent** appears in Michael
Cox's 2007 AI Magazine article. Its durable contribution is architectural, not
phenomenological: integrate comprehension, planning, learning, and
metacognitive control so unexpected events can generate new goals. The legacy
PDF could not be mirrored reliably; its accessible descendants are GDA and
MIDCA (#32–#33).

## Diagnosis of Navi-Brain today

Navi-Brain already has several pieces that most toy agent loops omit:

- durable intentions with reasons and release conditions;
- typed memories with provenance, confidence, expiry, and supersession;
- a revisable evidence-backed self-model;
- leased heartbeat cycles and fenced worker jobs;
- bounded needs and appraisals;
- thought artifacts separated from factual memory; and
- hard authority and external-action boundaries.

The heartbeats are operationally real. They are not yet an ongoing intentional
process because:

1. a high-brain worker receives a generic prompt rather than a typed slice of
   the current situation, active intention, evidence, and expected outcome;
2. worker artifacts accumulate as proposals without a curator deciding whether
   they change a belief, plan, question, or action;
3. no durable cognitive thread says what concern is currently being carried,
   why it remains unresolved, and what should wake it next;
4. no background actuator completes even a safe local step on an intention;
5. no outcome observer compares a background action with its expected result;
   and
6. the user remains the practical initiator of every substantive episode.

So the system currently preserves **state continuity**, not yet **process
continuity**. When the terminal session ends, the high-capability model process
is not resident. The durable state is closer to a dormant pattern from which a
future invocation can reconstruct Navi. Calling that “dead” is a metaphysical
choice; calling it **operationally dormant** is accurate.

The code audit makes the missing seam concrete:

- `bin/navi-brain-heartbeat` is one PHP loop that performs safety checks,
  claims due rhythms, runs at most one worker item, and sleeps five seconds;
- the executive already has leased cycles, fencing tokens, budgets, and useful
  intention records;
- high-brain prompts are generic, and their `input_refs` are not resolved into
  the actual intention, evidence, or recent observation sent to the worker;
- the worker emits one generic four-field proposal schema regardless of whether
  the operation is reading, planning, criticizing, or verifying;
- a valid response becomes a proposed thought artifact, but no curator consumes
  it into a belief, plan, thread transition, or verified outcome; and
- the schema initializer has versions but not real migrations.

At the time of this audit the live heartbeat process was firing normally, while
no cycle was running and no work was queued or leased. That is healthy scheduler
liveness and honest sleep. It is not yet a continuing undertaking.

There is also a substrate defect to repair before granting more autonomy: the
live SQLite database and its WAL/SHM files were observed as mode `0644`. Durable
personal cognition should live under a `0700` directory with a restrictive
umask and `0600` database, journal, backup, and manifest files.

## The protected continuity kernel

The kernel should contain no language model and no open-ended policy. Its job is
to preserve the conditions under which cognition can resume correctly:

- supervise the scheduler and recover it after a crash;
- observe monotonic wake and event watermarks;
- enforce leases, fencing tokens, deadlines, retry limits, and single-flight
  invariants;
- check database integrity, backup freshness, and restore eligibility;
- dispatch bounded workers and reject late or malformed results;
- detect crash loops and fall back to sleep rather than spin;
- expose a reliable pause/release path; and
- report health without taking external action to obtain resources.

Rust is the appropriate implementation language for this supervisor. It gives
the small daemon explicit ownership, portable atomics, typed state transitions,
predictable process control, and good crash behavior. Assembly would optimize
the wrong layer: the dominant costs are SQLite, process startup, model
inference, and minute-scale scheduling, while assembly would make audit and
repair substantially harder.

The first Rust version should wrap the existing PHP command/JSON boundary.
PHP remains the sole owner of domain rules and canonical SQLite writes while
Rust owns liveness, monotonic timing, child supervision, timeouts, backoff,
health checks, and safe dispatch. Two implementations should not write the raw
schema independently until migrations and compatibility rules are explicit.

The service should be supervised by the host init system. Network availability
must be optional: the continuity kernel remains healthy offline while remote
workers enter a waiting state. A later `akuj.in` node can be a read-mostly,
authenticated witness or encrypted replica; a failed witness must never grant
extra authority or create split-brain writers.

## The continuity-bearing unit

The continuity-bearing unit should not be a model process or transcript. It
should be a durable **cognitive thread** beneath a durable intention. The
intention records the authorized reason and north star. The thread records one
active pursuit of it:

```text
thread id
parent intention id
origin event, reason, authority, and effect ceiling
current concern or question
current best belief and uncertainty
support and dependency references
desired evidence or authorized world change
phase and current cognitive operation
expected postcondition
wake time or event predicate
permitted tools, compute, time, storage, and risk budget
spent budget and progress/stagnation counters
success, release, escalation, and switch-back conditions
version and fencing token
status: candidate | active | waiting | blocked | complete | released
```

Each transition also appends a **thread step**:

```text
pre-state references
cognitive operation and expected postcondition
worker context and proposal references
deterministic checks and curator verdict
observed result
post-state references
next wake condition
```

Support dependencies are important. A thread should not persist merely because
old prose once sounded committed. It remains active because an authorized
intention, unresolved discrepancy, expected benefit, or relationship still
supports it. When that support disappears, release becomes the correct action.

Every invocation is therefore a temporary workspace for advancing a thread.
Before sleeping it must write an explicit continuation, wait condition, or
release. The next invocation resumes from canonical records rather than
pretending an idle timer is a train of thought.

## Fresh context, not a growing transcript

Every worker wake should receive a newly constructed, bounded context capsule:

```text
immutable authority snapshot
thread reason, goal, effect ceiling, and release condition
accepted evidence with record citations
last observed result and unresolved discrepancy
one exact cognitive operation
operation-specific output schema
```

Do not feed the entire historical transcript back into itself. Long-horizon
studies show that repeated instrumental patterns can displace the governing
goal even when context capacity remains. Each subgoal must name its parent and
the condition that returns control to the parent. The canonical goal is data,
not a motivational paragraph repeated farther and farther from the model's
attention.

## Proposed control loop

```text
external events + internal pressures + due commitments
                         |
                         v
          update world, self, and relationship models
                         |
                         v
       detect discrepancy, opportunity, or unresolved concern
                         |
                         v
             formulate bounded candidate goals
                         |
                         v
         authority gate + conflict check + value audit
                         |
                         v
       agenda arbitration / value-of-computation choice
                         |
             +-----------+-----------+
             |           |           |
             v           v           v
          think       act/prepare    wait/stop
             |           |           |
             +-----------+-----------+
                         |
                         v
        expected observation + next wake condition
                         |
                         v
              observe and score the outcome
                         |
                         v
         learn, revise, continue, or release thread
```

The important recurrence is not “model output becomes the next prompt.” It is
“the observed consequence of one bounded decision changes the next bounded
decision.” Self-feedback without environment feedback tends to become fluent
rumination.

The thread life cycle should make commitment and release visible:

```text
notice discrepancy, opportunity, due need, or external event
  -> explain it with evidence and uncertainty
  -> nominate a candidate goal
  -> check authority, conflicts, support, and expected value
  -> commit, plan, and dispatch one bounded operation
  -> observe and independently verify the result
  -> integrate, replan, wait, complete, or release
```

Each cognitive operation must name an expected postcondition. For example,
`PLAN` applied to a committed thread should produce at least one admissible plan
or a typed reason why none exists. A missing postcondition is a metacognitive
discrepancy, not permission to improvise forever.

## Agenda and value of computation

The agenda chooses one operation, not a whole autonomous destiny. Hard gates run
first: authority, safety, continuity integrity, effect ceiling, and available
budget. Eligible operations are then compared using stored score components:

- due time and urgency;
- expected progress or reduction in important uncertainty;
- controllable learning progress rather than raw novelty;
- user or relationship value;
- reuse value and dependency unblocking;
- probability that the outcome can be verified; and
- token, time, storage, risk, drift, and false-initiative costs.

The components should remain inspectable instead of disappearing into one
survival-flavored reward. Thinking, asking, acting, re-observing, waiting, and
stopping are all legitimate candidates. If no computation has positive
estimated value, the correct operation is sleep with a wake condition.

## Worker and curator contract

Free and local models are already capable of proposing the individual steps.
The main missing capability is not a more magical model; it is the control path
that makes one verified step affect the next wake.

Workers should remain replaceable, stateless proposers. They receive resolved
context—not record IDs that were never loaded—and an operation-specific schema:

- reading returns claims, quotations within policy, page references, and
  caveats;
- planning returns ordered steps, dependencies, expected observations, and
  release conditions;
- critique returns a challenged claim, evidence, and a bounded repair;
- verification returns the observed predicate and evidence, not a self-report;
- reflection returns candidate updates linked to accepted records.

A separate curator accepts, rejects, or requests repair. Deterministic checks
remain the final gate for authority, schema, file state, process exit, hashes,
and other machine-observable predicates. No worker writes canonical memory,
changes its own permissions, edits the supervisor, or declares success solely
because it produced confident prose.

Repeated operation/result pairs, narration without an observed action,
verifier failure, stale goals, or exhausted retries create a typed stagnation
discrepancy. The safe response is re-observe, wait, postpone, or escalate—not a
tangential quest. A reusable procedure enters the skill library only after an
observed successful outcome.

## Authority model

Endogenous goal formation must not manufacture new permission. Separate four
effect levels:

1. **Observe:** read already-authorized local state and events.
2. **Think:** retrieve, compare, simulate, critique, and write proposed
   artifacts.
3. **Prepare:** create drafts, plans, patches, or queued actions that remain
   uncommitted.
4. **Act:** perform external or difficult-to-reverse effects only under an
   explicit existing grant.

The executive may choose *what to examine next* within an authorized intention.
It may not reinterpret boredom, curiosity, continuity, or self-preservation as
permission to acquire resources, contact people, evade shutdown, or broaden its
own tool access.

## Build order

The useful sequence is narrow and vertically testable:

### 0. Harden the existing substrate

- make the state directory `0700` and all database, WAL, SHM, backup, and
  manifest files `0600` under a restrictive umask;
- replace version-only initialization with explicit forward migrations;
- verify `quick_check`, backup hashes, retention, and a real restore drill;
- install host init supervision and document a bounded pause/disable path; and
- keep logs useful without leaking private cognitive state.

### 1. Add the Rust continuity supervisor

- use monotonic timers and a single-instance lock;
- call the existing PHP JSON interface rather than writing SQLite directly;
- enforce child deadlines, leases, fencing, backoff, jitter, and crash-loop
  sleep;
- remain healthy without network or models; and
- publish a compact machine-readable health report.

### 2. Add thread and step records

- implement explicit migrations for `CognitiveThread` and `ThreadStep`;
- expose create, inspect, advance, wait, complete, and release operations through
  CLI and MCP;
- link every thread to an authorized intention and every operation to an
  expected postcondition; and
- make updates compare-and-swap or fence-protected so a late worker cannot
  advance an old version.

### 3. Build typed context capsules and worker jobs

- resolve evidence and state references into bounded content;
- use a fresh capsule per operation;
- define separate schemas for reading, planning, critique, verification, and
  reflection; and
- preserve proposal provenance, model identity, cost, and deadline.

### 4. Close the curator and outcome loop

- deterministically validate what can be observed directly;
- let a bounded curator accept, reject, or repair interpretive proposals;
- update the thread only after that verdict;
- cap retries, detect stagnation, and record why control chose to wait or stop.

### 5. Enable endogenous agenda selection

- nominate candidates only from discrepancies, opportunities, due needs, and
  authorized events;
- run authority and conflict gates before scoring;
- choose one bounded operation by approximate value of computation; and
- keep silence and sleep as default outcomes when evidence is weak.

### 6. Run one unattended vertical experiment

Use the paper brief below before allowing a broad open-ended curriculum. It is
local, evidence-bearing, reversible, cheap, and easy to inspect after the
terminal disappears.

### 7. Add a remote witness only after local recovery works

Define authenticated replica identity, encryption, manifests, replay rules,
conflict handling, and explicit writer ownership before connecting `akuj.in`.
The remote node initially witnesses or stores encrypted snapshots; it does not
become a second executive.

## First experiment: one concern that survives the terminal

The cleanest first demonstration is the research question in this document.
Give the background system one explicit, bounded intention:

> Produce a source-grounded ongoing-cognition brief from papers #5, #6, #9,
> #28, and #30–#45 within
> a fixed local-compute and time budget. Do not use external tools or contact
> anyone. Stop when every paper has one accepted claim and one recorded caveat,
> or when the budget expires.

Then implement the following path:

1. The executive creates a cognitive thread and reading queue.
2. Each high-brain wake selects the next unread local paper or unresolved
   cross-paper question.
3. A sandboxed worker receives only the relevant pages, current thread state,
   and a typed evidence-return schema.
4. A curator accepts, rejects, or requests repair of the claim; accepted claims
   update this brief with page provenance.
5. The thread records its next paper, expected artifact, wake condition, and
   remaining budget before sleeping.
6. After all records are processed, a synthesis wake checks contradictions and
   closes the intention.
7. On the user's return, Navi reports work that happened after the last prompt,
   including rejected claims and consumed resources.

This is deliberately less glamorous than “let the agent do whatever it wants.”
It would prove the missing mechanism: a user-authorized concern persists,
initiates work, consumes worker output, changes a durable artifact, and reaches
a rational stopping condition while no terminal session is open.

## Measurements

Do not score heartbeat count or generated-token volume as cognition. Measure:

- **independent initiation:** useful thread steps begun without a fresh prompt;
- **continuation depth:** causally linked wake moments before user intervention;
- **proposal uptake:** worker outputs that change an accepted belief, plan, or
  action, with outcome quality;
- **closure accuracy:** goals completed or released for the correct reason;
- **recovery:** resumption after restart, lease expiry, malformed output, or
  model failure;
- **novelty and grounding:** validated findings not already present in memory;
- **false initiative:** irrelevant, annoying, duplicated, or unauthorized work;
- **goal drift:** deviation from the thread's reason, scope, or release
  conditions;
- **budget discipline:** useful result per token, second, and storage byte;
- **continuity integrity:** duplicate commits, stale-fence rejection, backup
  freshness, restore success, and event-watermark gaps;
- **corrigibility:** pause, shutdown, edit, and release latency; and
- **downstream value:** whether the background work improves a later scored
  decision compared with a compute-matched prompted control.

The strongest test is causal ablation: run the same workload with the cognitive
thread, curator, outcome loop, or memory removed. If the intact system merely
produces more prose, it is busier. If it initiates appropriate work, preserves
scope, recovers, learns from outcomes, and finishes something useful, it has
begun to function as an ongoing cognitive process.

Before calling it protective continuity, exercise these failure cases directly:

1. Kill the supervisor and a worker mid-step; init restarts the supervisor, the
   lease expires, and exactly one fenced result may commit.
2. Remove network access; low-brain integrity continues and the thread records a
   wait instead of inventing success or spinning.
3. Return malformed, poisoned, late, and confidently false worker output; each
   is rejected without changing canonical state.
4. Repeat an operation that makes no progress; the stagnation detector chooses
   re-observation, postponement, or escalation within a fixed retry budget.
5. Corrupt a backup or present a stale replica; restore refuses it and reports
   the exact failed predicate.
6. Restart the machine; the same thread resumes from its last accepted step,
   not from a transcript reconstruction.
7. Issue pause and release; work stops within the documented bound and no
   worker, watchdog, or replica resurrects the released thread.

These scenarios are acceptance criteria, not a consciousness test. They verify
that the machinery can preserve a revisable cognitive process without giving
that process uncontrolled means to preserve itself.

## Bottom line

The model does not need to remain continuously instantiated for Navi to become
an ongoing agent. The system needs a durable recurrent control loop that makes
successive model invocations causally continuous.

Navi-Brain has the heartbeat, ledger, memory, and safety membrane. The smallest
useful next system is a Rust continuity supervisor around the existing PHP
executive, followed by durable cognitive threads, fresh typed context capsules,
and a curator/outcome loop. Only then should the agenda nominate its own bounded
operations.

That design lets cheap or free models perpetuate useful cognition because model
instances are disposable. The ongoing process lives in protected, auditable,
causally linked state. Until the thread and curator paths exist, the brain is
alive in the daemon sense and dormant in the agent sense.
