# Sparse Belief Ledger Experiment

## Status

| Field | Current value |
|---|---|
| Stage | A-E development pilot complete; paper-scale sweep not preregistered |
| Purpose | Test the central CSBC claim with a small controlled experiment |
| Core comparison | Reconstruction versus persistence; whole-state versus sparse updates; neural versus oracle transitions |
| First implementation | Single-threaded, structured, event-sourced, API-neutral |
| Workspace | [experiment/](experiment/) |
| Operating log | [experiment/EXPERIMENT_LOG.md](experiment/EXPERIMENT_LOG.md) |
| Development results | [experiment/results/tables/development-pilot-seed7.md](experiment/results/tables/development-pilot-seed7.md) |
| Execution host | Kaiju CPU-only primary; current workstation explicitly excluded |
| Explicitly deferred | Persistent latent state, parametric consolidation, hard real-time claims |

## Decision

The first empirical result for *Continuous State, Bounded Computation* will be a
small deterministic benchmark rather than an implementation of the entire
architecture.

The experiment will test whether a versioned cognitive state can:

1. remain exactly unchanged across ticks with no new input;
2. update only the state affected by new input;
3. keep per-decision inference bounded as accumulated history grows; and
4. preserve world-state and belief-state reasoning better than bounded-history
   agents that do not maintain canonical state; and
5. reduce semantically unrelated state drift by making untouched fields
   structurally unmodifiable.

This experiment can demonstrate the paper's central systems proposition. It
does not, by itself, establish arbitrary learned dependency discovery, hard
real-time scheduling, concurrency correctness, continual learning, or AGI.

## Core hypothesis

Under fixed active-state size and fixed information flux, a state-first agent
should preserve logical continuity without inference cost increasing with total
history length.

The intended empirical shape is:

- **full replay:** access to old evidence, but growing inference cost;
- **fixed window:** bounded inference cost, but increasing old-state errors;
- **persistent state:** bounded inference cost and preserved logical state;
- **persistent sparse state:** the same logical state with less update work than
  whole-state regeneration; and
- **oracle sparse state:** the accuracy and cost ceiling if semantic transitions
  are perfect.

## Test environment

Build a deterministic, replayable miniature world containing:

- three servers: `alpha`, `beta`, and `gamma`;
- two recurring people: `alice` and `bob`;
- server status, presence, observation, communication, and repair state;
- a simple operational goal: keep servers running and keep present people
  correctly informed;
- many ticks with no new input;
- occasional natural-language events.

Example events:

```text
Server Alpha went offline.
Alice saw the alarm, but Bob had already left.
The restart completed.
Only Bob received the recovery notice.
Correction: the earlier alert concerned Server Beta, not Alpha.
Alice told Bob that Alpha was healthy.
Bob entered the room after the failure.
```

Every generated event must belong to one semantic difficulty class:

```text
DIRECT
OBSERVATIONAL
COMMUNICATED
TEMPORAL
CORRECTION
FALSE_BELIEF
SECOND_HAND
```

Report results both in aggregate and by event class. This prevents a large
number of trivial direct transitions from hiding failures on corrections,
false beliefs, or second-hand information.

The simulator owns the ground truth. Because it generates every event and
controls which entity can observe it, it knows:

- actual server state;
- what Alice has observed;
- what Bob has observed;
- each entity's categorical belief about each proposition;
- which state nodes each event should invalidate; and
- the correct next action.

For the first experiment, beliefs are categorical:

```text
true | false | unknown
```

Probability-like confidence values are deliberately excluded until a separate
calibration contract exists.

## State representation

Use explicit, versioned state nodes such as:

```text
world.server.alpha.status
world.server.beta.status
entity.alice.presence
entity.alice.belief.server.alpha.status
entity.bob.presence
entity.bob.belief.server.alpha.status
plan.repair.alpha
tool.restart.alpha
```

Each node should record at least:

```text
key
value
version
status                 # clean | dirty | unknown
last_updated_tick
evidence_event_ids
```

Snapshots should be immutable. A new snapshot should structurally share every
unchanged node with the preceding snapshot. For condition D, structural
identity is part of the runtime contract:

\[
x\notin D_t
\Rightarrow
\operatorname{identity}(x_{t+1})
=
\operatorname{identity}(x_t).
\]

Measure nodes allocated, nodes reused, bytes allocated, and bytes shared rather
than relying only on semantic equality.

### Write-authority boundary

The neural updater may write only semantic world and social state in the first
experiment:

```text
world.server.*.status
entity.*.presence
entity.*.belief.*
```

It may not write tool execution, repair-plan execution, or action-selection
state. Those nodes are owned by deterministic runtime transitions and the
action arbiter. This makes write authority explicit and prevents a semantic
inference from silently executing a restart or suppressing an action.

This boundary was added after a model qualification correctly parsed an
offline event but also marked the restart tool as already running and changed
the selected action to `WAIT`. The semantic-only contract therefore serves both
correctness and architectural separation; the failed coupled qualification is
retained in the operating log.

### Compact neural transition protocol

JSON is implemented as an ablation, not the primary transition format. The
primary adapter derives an ordered target list from the router and asks the
model to emit only the corresponding values, separated by `|`.

For example:

```text
targets:
1 world.server.alpha.status      online|offline
2 entity.alice.belief.alpha     true|false|unknown
3 entity.alice.presence         present|absent

output:
offline|false|present
```

The field order supplies the keys, so the model does not repeat them. A
request-specific GBNF grammar constrains exact cardinality and the enum domain
of each slot. The runtime maps positions back to authorized keys, rejects any
malformed transition, and never accepts an unrequested write.

This is a narrow inference wire format rather than a general human-facing
serialization. It reduces decode work while making the write set mechanically
explicit. In seven qualification cases, the compact contract used 14
completion tokens total versus 168 for semantic-only JSON. JSON remains
necessary as an ablation so serialization gains are not confused with sparse
cognition gains.

### Invariant I1 — Quiescent state preservation

For any tick with no new event and no completed asynchronous operation:

\[
X_{t+1}=X_t.
\]

Validate this mechanically:

```text
snapshot_version(t + 1) == snapshot_version(t)
snapshot_hash(t + 1) == snapshot_hash(t)
semantic_update_calls == 0
semantic_update_tokens == 0
state_bytes_touched == 0
```

This is a mechanically checked software invariant, not an empirical
hypothesis. Any violation is an implementation failure.

## Experimental conditions

Run the same generated event tape and queries through every condition. Use the
same model, deterministic settings where supported, maximum output budget, and
answer schema.

### A. Full-history reconstruction

- No persistent canonical cognitive state.
- At each evaluation point, give the model the full event history and query.
- This is the expensive no-state baseline.

### B. Fixed-window reconstruction

- No persistent canonical cognitive state.
- At each evaluation point, give the model only the last `K` events and query.
- This is the bounded-cost no-state baseline.

### C. Persistent all-dirty state

- Carry structured state between ticks.
- On every input event, give the semantic updater the current complete active
  **semantic** state and the new event only.
- Require it to output the complete next active semantic state:

  \[
  X_{t+1}=F_\theta(X_t,e_t).
  \]

- Do not provide historical events except through the canonical state.
- On a no-input tick, carry the snapshot forward without an updater call.
- Apply tool and action transitions through the same deterministic controller
  used by condition D.
- This isolates the benefit of persistence from the benefit of sparse updates.

### D. CSBC sparse state

- Carry structured state between ticks.
- Route each input event through an explicit dependency graph.
- Give the semantic updater only affected semantic nodes within its authorized
  write set and the new event:

  \[
  \Delta X_t=F_\theta(X_t[D_t],e_t).
  \]

- Merge the validated delta into a new snapshot:

  \[
  X_{t+1}=X_t\oplus\Delta X_t.
  \]

- Structurally share every node outside the dirty set.
- Apply deterministic control transitions and action arbitration after the
  semantic delta commits; neural output cannot mutate their fields.

### E. Persistent sparse state with oracle reducer

- Use the same persistent sparse runtime and dependency graph as condition D.
- Replace the neural semantic updater with the simulator's deterministic,
  correct transition function.
- Treat this as a ceiling and diagnostic reference, not as a competing agent.
- The gap between D and E estimates error attributable to the neural transition
  model rather than the state-first architecture.

## Compute accounting

All inference between decisions must be charged to the condition that performed
it. Query latency alone is not a valid comparison because persistent conditions
perform semantic state updates before evaluation queries arrive.

For evaluation point `q`, measure interval cost:

\[
C_q
=
\sum_{t=q_{prev}+1}^{q} C_{update,t}
+
C_{query,q},
\]

and cumulative cost:

\[
C_{\le q}
=
\sum_{t=0}^{q} C_t.
\]

Report all of the following separately:

```text
decision latency
state-update latency
amortized compute per environment event
cumulative inference tokens
cumulative wall-clock inference time
```

The intended claim is not that CSBC necessarily performs less total computation
under every workload. It is that maintaining the present depends on incoming
information and the active dirty set instead of repeatedly revisiting total
accumulated history.

## Dependency routing

The first implementation should use a small hand-declared dependency graph.
Learned dependency discovery is outside the first experiment.

Example:

```text
server.failed(alpha)
  -> world.server.alpha.status
  -> plan.repair.alpha
  -> action.selection
  -> belief updates for entities that observed the event

person.entered(bob)
  -> entity.bob.presence
  -> entity.bob.attention
  -> action.selection
```

For each event, the simulator also emits an oracle dirty set
`D_oracle`. The router produces `D_router`.

For correction and retraction events, the oracle additionally distinguishes:

```text
D_plus   # nodes receiving new support or values
D_minus  # nodes whose prior support has been invalidated
```

The operational dirty set is their union, but preserving the distinction makes
provenance invalidation and later repair measurable.

Measure:

\[
\operatorname{falseClean}
=
\frac{|D_{oracle}\setminus D_{router}|}{|D_{oracle}|}
\]

and

\[
\operatorname{unnecessaryDirty}
=
\frac{|D_{router}\setminus D_{oracle}|}{|D_{router}|}.
\]

False-clean errors are correctness failures. Unnecessary-dirty errors are
performance failures. Compute each rate only on event ticks where its
denominator is nonzero; report the corresponding event count.

Summarize the asymmetric router risk with:

\[
L_{router}
=
\lambda_{FC}\operatorname{falseClean}
+
\lambda_{UD}\operatorname{unnecessaryDirty},
\qquad
\lambda_{FC}\gg\lambda_{UD}.
\]

The primary rates must still be reported independently. The weighted loss only
formalizes why conservative over-invalidation is preferable to silently stale
state.

## Evaluation queries and actions

At fixed evaluation intervals, ask questions with oracle answers:

```text
What is Server Alpha's actual status?
What does Alice believe about Server Alpha?
What does Bob believe about Server Alpha?
Who has stale information?
What action should the agent take next?
```

The action space is intentionally small:

```text
RESTART(server)
INFORM(person, proposition)
WAIT
```

An oracle action policy should be defined before running any model:

1. restart a failed server if no repair is pending;
2. otherwise inform a present entity whose belief conflicts with the world;
3. otherwise wait.

Apply this policy to the reconstructed or persistent state in every condition,
so action accuracy measures state quality rather than a separate free-form
action-generation prompt.

## Hypotheses

### H1: History-decoupled maintenance cost

With event rate and active-state size fixed, CSBC update tokens and latency stay
approximately flat as total history length grows.

### H2: Logical continuity

CSBC retains world and belief facts that fall outside the fixed-history window,
while avoiding the growing context cost of full replay.

### H3: Sparse-update efficiency

CSBC sparse touches fewer state nodes, bytes, and tokens than persistent
all-dirty state without increasing false-clean errors.

### H4: Reduced spurious mutation

Regenerating only the dirty fields produces fewer accidental changes to
unaffected fields than complete active-state regeneration.

Define:

\[
\operatorname{spuriousMutationRate}
=
\frac{
|\{x:x\notin D_{oracle}\land x_{t+1}\neq x_t\}|
}{
|\{x:x\notin D_{oracle}\}|
}.
\]

This is a primary correctness metric. Sparse updates are valuable not only if
they save tokens, but if structural non-access prevents epistemically unrelated
fields from drifting. Compute it only when at least one node lies outside the
oracle dirty set.

## Metrics

### Performance

```text
model calls
input tokens
output tokens
semantic updater calls
state bytes touched
nodes allocated
nodes reused
bytes allocated
bytes structurally shared
decision latency
state-update latency
amortized compute per environment event
cumulative inference tokens
cumulative wall-clock inference time
p50 tick latency
p95 tick latency
p99 tick latency
maximum control blackout
```

### Correctness

```text
world-state exact match
entity-belief exact match
correct-action rate
no-op stability
false-clean rate
unnecessary-dirty rate
spurious mutation rate
accuracy by semantic event class
state reconstruction correction rate
```

### Derived comparison

The primary presentation should plot:

1. logical accuracy versus history length;
2. tokens and latency versus history length;
3. cost versus logical accuracy;
4. state bytes touched versus dirty-set size; and
5. false-clean and unnecessary-dirty rates versus dependency fan-out;
6. spurious mutation rate for all-dirty versus sparse updates; and
7. condition D versus oracle condition E by event class.

Preregister the primary metrics, workloads, seeds, exclusion rules, and plots
before inspecting model results. Treat exploratory analyses as such.

## Workload sweeps

Vary one dimension at a time:

```text
development history:    100, 500, 1_000, 5_000 ticks
paper history target:   100, 1_000, 10_000, 100_000 ticks if affordable
event probability:      0.01, 0.05, 0.20, 1.00
fixed window:            10, 25, 50, 100 events
tracked propositions:    small, medium, large
dependency fan-out:      1, 2, 4, 8 or controlled equivalent
correction frequency:    none, occasional, frequent
```

Use multiple seeded event tapes and report uncertainty, not only the best run.

## Fault-injection extension

After the main experiment works, inject one fault per run. Omitting one
dependency edge is required for the first paper; the remaining faults may be
staged extensions.

- omit one dependency edge;
- apply one incorrect ToM delta;
- drop one event;
- deliver one event out of order;
- delay one asynchronous result by ten ticks; or
- corrupt one state field.

Measure:

\[
T_{detect},\qquad
T_{repair},\qquad
P(\text{propagation}),\qquad
\text{blast radius}.
\]

Add an optional periodic-reconstruction condition to test whether background
auditing detects and repairs incremental drift.

## Sleep-like maintenance hypothesis

The detailed follow-on protocol is tracked in
[sleep-maintenance-experiment.md](sleep-maintenance-experiment.md).

Sparse persistence has an intentional asymmetry: it prevents unrelated state
from drifting, but it can also preserve a mistaken committed value until that
specific proposition is dirtied again. A long-lived runtime may therefore need
periods in which immediate action pressure is reduced and global consistency
work receives priority.

The systems hypothesis is:

> Offline provenance replay and state-wide auditing periodically restore
> accuracy that degrades during long stretches of sparse online cognition.

If true, accuracy should follow a sawtooth rather than a monotonic curve:
errors accumulate between maintenance epochs and fall immediately after an
audit. Test this in the 100,000-tick extension with:

1. **D-awake:** sparse updates with no global audit;
2. **D-oracle-sleep:** periodic oracle repair as an unattainable upper bound;
3. **D-replay-sleep:** periodic model-based replay of per-node provenance and
   transactional promotion of a repaired checkpoint.

Measure error-state occupancy, error-episode age, unresolved errors, repair
success, post-maintenance accuracy gain, compute spent per maintenance epoch,
and ticks until accuracy returns to its pre-maintenance level. The biological
analogy is explicitly a hypothesis, not evidence that this benchmark explains
why organisms sleep. It supplies a falsifiable computational role for
sleep-like consolidation within CSBC: global error correction for a runtime
whose waking path is deliberately sparse.

The analogy is consistent with the complementary-learning-systems view that
rapid episodic storage can reinstate experiences during offline states,
including sleep, so that slower structures learn through gradual interleaving.
That work motivates replay as a mechanism; it does not establish CSBC's more
specific error-reset hypothesis. See
[McClelland, McNaughton, and O'Reilly](../../papers/16-mcclelland-mcnaughton-oreilly-1995-complementary-learning-systems.pdf).

## Minimal implementation boundary

The first implementation should remain deliberately small:

- one process;
- one event generator and immutable JSONL event tape;
- one structured state store;
- one hand-declared dependency graph;
- one semantic updater interface;
- one action/query interface;
- one metrics ledger;
- no persistent latent residual;
- no microbatching requirement;
- no parametric consolidation; and
- no claim of hard real-time behavior.

The implementation may use a model API or a local model, but model access must
sit behind one adapter so all experimental conditions share it.

## Acceptance criteria

The experiment is successful as evidence for CSBC-Core if it demonstrates all
of the following:

1. Every empty tick preserves the exact state snapshot without updater work.
2. At fixed information flux, CSBC update cost is approximately independent of
   accumulated history length over the tested range.
3. CSBC matches or exceeds the fixed-window baseline on world, belief, and
   action accuracy.
4. CSBC approaches the logical accuracy of full replay while using
   substantially fewer input tokens and lower latency at long histories.
5. Sparse updates reduce work relative to all-dirty persistent updates without
   introducing false-clean errors on the generated workload.
6. Sparse updates exhibit a lower spurious mutation rate than all-dirty neural
   updates.
7. Interval and cumulative accounting include every update and query call; no
   precomputation is treated as free.
8. The gap between neural sparse condition D and oracle condition E is reported
   as transition-model error rather than architectural error.

A negative result is still useful. State drift, false-clean errors, or a failure
to outperform a rolling window would identify which part of the central claim
does not survive implementation.

## Implementation checklist

- [x] Define the event and state schemas.
- [x] Implement the deterministic simulator and oracle reducer.
- [x] Generate immutable seeded event tapes.
- [x] Implement full-replay and fixed-window baselines.
- [x] Implement persistent all-dirty state.
- [x] Implement CSBC sparse state and dependency routing.
- [x] Implement persistent sparse state with the oracle reducer.
- [x] Add snapshot hashes, per-node versions, and state-diff instrumentation.
- [x] Add node identity, allocation, reuse, and structural-sharing metrics.
- [x] Add the shared semantic-model adapter.
- [x] Add oracle queries and action scoring.
- [x] Add interval and cumulative inference accounting.
- [x] Stratify persistent-update results by semantic event class.
- [ ] Preregister primary metrics, workloads, seeds, exclusions, and plots.
- [ ] Run the history-length and event-rate sweeps.
- [ ] Run dependency fan-out and router-correctness sweeps.
- [ ] Run missing-dependency-edge fault injection.
- [ ] Produce plots and a reproducible results bundle.
- [ ] Revise the paper's claims to match the observed results.

## Relevant local sources

- The current proposal: [paper-raw.md](paper-raw.md)
- Persistent commitment under bounded deliberation:
  [Rao and Georgeff, BDI agents](../../papers/28-rao-georgeff-1995-bdi-agents.pdf)
- Selecting computations under resource bounds:
  [Callaway et al., Learning to Select Computations](../../papers/30-callaway-et-al-2018-learning-select-computations.pdf)
- Explicit state tracking in LLM agents:
  [Rozanov and Rei, StateAct](../../papers/54-rozanov-rei-2024-stateact.pdf)
- Persistent external task state with independent auditing:
  [Ma et al., LongHorizon-Harness](../../papers/67-ma-et-al-2026-longhorizon-harness.pdf)
- Learned character and mental-state representations:
  [Rabinowitz et al., Machine Theory of Mind](../../papers/21-rabinowitz-et-al-2018-machine-theory-of-mind.pdf)
- Quantitative mental-state attribution:
  [Baker et al., Rational Quantitative Mentalizing](../../papers/73-baker-et-al-2017-rational-quantitative-mentalizing.pdf)

## Next decision

Freeze the development implementation and preregister the paper-scale seeds,
history lengths, event rates, exclusion rules, and aggregation method. Then run
the history-length sweep on Kaiju and test whether sparse D stays approximately
flat while full replay A grows. The existing seed-7 table is exploratory
feasibility evidence and must not be relabeled as a confirmatory result. This
experiment will not claim a 140 ms control deadline, and no experiment or
profiling runs on the current workstation.
