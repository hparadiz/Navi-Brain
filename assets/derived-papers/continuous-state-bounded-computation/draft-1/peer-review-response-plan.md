# Peer-Review Response and Revision Plan

## Revised contribution claim

Build the revision around one narrow sentence:

> CSBC is a cognitive runtime in which persistent self/world/social state is
> the primary computational object; learned models perform validated sparse
> transitions over a dirtied working set; unfinished cognition may persist
> across bounded control epochs; and recurrent action arbitration, rather than
> model invocation, owns temporal authority.

Do not claim novelty for persistent memory, ticks, event sourcing, schedulers,
ToM, microbatching, or asynchronous execution individually. Claim novelty for
their coupling, write-authority boundaries, and measurable runtime contracts.

The first implemented Sparse Belief Ledger experiment supports only the
state-first incremental subset of that claim. It does not yet validate hard
real-time service, learned routing, snapshot concurrency, latent-state
persistence, microbatch scheduling, or continual learning.

## 1. Dependency routing

### Manuscript change

Replace the single opaque router function with an explicit dependency
contract:

\[
D_0=\operatorname{directDependencies}(E_t)
\]

\[
D_{n+1}=D_n\cup\operatorname{dependents}(D_n)
\]

Each transition job declares:

```text
event type
semantic read set
semantic write set
control read set
control write set
provenance dependencies
dependency versions
maximum supported fan-out
fallback reconstruction scope
```

The router is conservative: unknown dependency, missing provenance, schema
version mismatch, or latent-state ambiguity widens the dirty set or schedules
reconstruction. A clean result is a claim backed by a dependency version, not
the absence of a field name in model output.

For the first paper, explicitly defer learned dependency discovery. Use a
hand-declared router and an independently generated oracle dirty set. Define
false-clean errors as correctness failures and unnecessary-dirty errors as
performance failures.

### Implemented evidence

The current experiment has explicit semantic write authority, independent
oracle and runtime dirty sets, exact structural sharing outside the routed set,
and metrics for false-clean and unnecessary-dirty rates. All completed sparse
runs have zero false-clean errors. This validates the contract on the toy
domain, not general semantic dependency discovery.

### Required next experiment

Sweep direct dirty-set size, transitive fan-out, dependency depth, and one
deliberately omitted edge. Report false-clean rate, unnecessary-dirty rate,
error lifetime, repair time, and blast radius.

## 2. Scaling law

### Manuscript change

Replace the unconditional slogan with a conditional systems claim:

\[
C_t = C_{route} + |D_t|C_{transition} + C_{commit} + C_{arbitrate}
\]

under bounded active-state residency, bounded dependency fan-out, bounded
transition output, and a resident inference engine. Total accumulated memory
may grow, but the hot state, provenance heads, and routed closure must remain
bounded for the claim to hold.

Separate two curves:

1. **decision cost versus accumulated history**;
2. **maintenance cost versus information flux**.

CSBC pays the second incrementally to flatten the first. It does not guarantee
lower lifetime compute when decisions are extremely rare.

### Implemented evidence

The frozen three-seed history sweep found:

- full replay's current prompt grew from 388 tokens at tick 100 to 3,533 at
  tick 5,000, a 9.11× increase;
- sparse maintenance stayed between 157.2 and 163.7 prompt tokens per incoming
  event across history intervals;
- at tick 5,000, sparse semantic accuracy was 84.8% versus 66.7% for both
  reconstruction baselines;
- sparse decision-boundary neural work was zero, but cumulative maintenance
  time was 302 seconds versus 40 seconds for only four replay queries.

This supports history-decoupled maintenance and bounded response work while
also exposing the workload-dependent cumulative-compute tradeoff.

### Newly exposed limitation

The 100,000-tick tape generator revealed that hot state currently stores
ever-growing evidence-ID lists. Snapshot hashing therefore revisits expanding
provenance. The runtime revision should retain only bounded provenance heads or
content-addressed roots in L0 and move full evidence chains to the durable
store. This is direct evidence that bounded active-state residency must be a
stated precondition, not an assumption hidden inside the notation.

## 3. The 140 ms boundary

### Manuscript change

Rename the current claim from a hard invariant to a **soft real-time cognitive
boundary** or **target control epoch**. State clearly that the architecture
alone does not establish worst-case execution time.

A future hard-bound implementation requires:

```text
reserved fast-path capacity
admission control
non-blocking or preallocated hot-state access
bounded validation work
preemptible or isolated inference
priority-inversion prevention
watchdog and deadline-miss telemetry
```

The 140 ms value remains an illustrative sweep point rather than a biological
or hardware constant.

### Experimental scope

Kaiju is an offline CPU platform. Neither the development pilot nor the
history sweep makes a 140 ms claim. At the paper's nominal 140 ms clock,
100,000 ticks correspond to 3.89 hours of environment time, but Kaiju's model
calls run much slower than that clock.

## 4. Snapshot consistency

### Manuscript change

Define cognitive jobs as versioned transactions:

```text
job_id
snapshot_version
read_set: key -> version
write_set
provenance_roots
deadline
staleness policy
merge operator
```

On completion:

1. validate every read version and provenance root;
2. commit directly if all dependencies are unchanged;
3. merge only when the operation has a declared commutative/associative merge;
4. otherwise recompute, confidence-degrade, or discard according to a typed
   policy;
5. append the decision to a deterministic replay log.

Disjoint writes are not sufficient for mergeability when jobs read shared
state. Say this explicitly and use snapshot-isolation terminology carefully.

### Required implementation

Add two asynchronous jobs that read overlapping state and write disjoint
fields. Inject late completion and dependency changes. Measure conflict rate,
invalidated result rate, stale commit rate, and deterministic replay equality.

## 5. Materialized ToM contracts

### Manuscript change

Use categorical beliefs in CSBC-Core until probability calibration exists:

```text
belief_value: true | false | unknown
subject
proposition_id
evidence_ids or provenance_root
source
last_updated
status: clean | dirty | unknown
```

If numerical confidence is retained, define its semantics, calibration domain,
scoring rule, update rule, and source model. Otherwise call it a score rather
than a probability.

The latent residual `Z_i` is optional and noncanonical. It must carry a model
identity, schema/version, invalidation policy, expiration rule, and
reconstruction fallback. Explicit state wins on conflict unless a typed
reconciliation procedure says otherwise.

### Implemented evidence

The experiment uses `true | false | unknown`, explicit provenance IDs, node
versions, and status. A model contract ambiguity about whether belief meant
subjective belief or objective truth was caught during qualification and fixed
before the confirmatory sweep. That failure should appear in the paper as an
example of why semantic types matter.

## 6. Scheduler objective

### Manuscript change

Define lexicographic integrity constraints first, then utility scheduling:

1. preserve action arbitration and safety deadlines;
2. service admitted control-path work within reserved capacity;
3. maximize expected decision value per unit compute among optional jobs.

A starting score is:

\[
S(j)=
\frac{
P(\text{decision changes}\mid j)\,V(j)\,U(j)
}{E[T_j]}
f(\text{deadline},\text{staleness})
\]

where uncertainty reduction `U(j)` is useful only when it can affect a pending
decision. Jobs whose results will arrive after their usefulness horizon should
not be admitted.

### Required experiment

Create competing speech, ToM, retrieval, appraisal, and planning jobs under a
fixed budget. Compare FIFO, priority-only, earliest deadline first, and the
utility score. Report deadline misses, decision regret, discarded work, and
quality under saturation.

## 7. Microbatching

### Manuscript change

Separate:

- the **cognitive scheduler**, which admits semantic work based on value and
  deadlines;
- the **inference scheduler**, which batches admitted tensor work based on
  shape, cache compatibility, and hardware occupancy.

Batching is optional and deadline-limited. Define maximum batching delay,
shape classes, KV-cache compatibility, starvation rules, and a bypass path for
urgent single jobs. Do not imply that homogeneous semantic intent guarantees
hardware-efficient batching.

### Required experiment

Sweep batching delay and job-shape heterogeneity. Plot throughput against p50,
p95, and p99 completion latency. Record queue time separately from execution.

## 8. Closest-work positioning

### Manuscript change

Add a closest-work matrix comparing bundles rather than ancestors. At minimum,
include AIOS, AgenticCache, RACE-Sched, Helium, classical cognitive
architectures, incremental computation/dataflow, event sourcing, and real-time
scheduling.

Recommended columns:

| System | canonical cognition | dirty propagation | bounded control epoch | resumable cognition | materialized ToM | action arbitration owns clock | snapshot conflict semantics | consolidation |
|---|---:|---:|---:|---:|---:|---:|---:|---:|

The text should answer directly why CSBC is not merely "AIOS plus cognitive
state plus event sourcing": serving systems schedule calls and resources;
CSBC additionally defines canonical cognitive state, semantically scoped write
authority, dependency invalidation, recurrent arbitration, and incomplete
cognition as persistent runtime state.

Broaden the intellectual lineage to Soar, ACT-R, blackboard systems, BDI,
anytime algorithms, real-time AI, reactive systems, incremental view
maintenance, event sourcing, dataflow, and admission control.

## 9. Consolidation scope

### Manuscript change

Split the architecture:

- **CSBC-Core:** persistent state, sparse transitions, bounded epochs,
  arbitration, futures, and durable episodic evidence;
- **CSBC-Plastic:** optional semantic and parametric consolidation with staged
  validation, rollback, provenance, and resource isolation.

Move online weight updates out of the critical path and out of the core
acceptance criteria. The paper remains substantial without them.

### Sleep-like extension

Add a narrower nonparametric maintenance hypothesis before weight learning:
periodic provenance replay and state-wide auditing may repair errors accumulated
during sparse online cognition. Compare no audit, oracle repair ceiling, and
model-based provenance replay. Look for sawtooth accuracy: degradation between
maintenance epochs and recovery immediately afterward.

This supplies a falsifiable computational role for sleep-like offline work but
must not be presented as evidence that CSBC explains biological sleep.
The local complementary-learning-systems paper provides a legitimate lineage
for offline reinstatement and interleaved consolidation, while the proposed
accuracy-reset mechanism remains unvalidated:
[McClelland, McNaughton, and O'Reilly](../../papers/16-mcclelland-mcnaughton-oreilly-1995-complementary-learning-systems.pdf).

## 10. Experimental revision

Use the Sparse Belief Ledger as the first empirical section, not as proof of
the entire architecture.

### Completed

- A: full-history reconstruction;
- B: fixed-window reconstruction;
- C: persistent all-dirty neural state;
- D: persistent sparse neural state;
- E: sparse oracle reducer;
- compact protocol and JSON ablation;
- immutable snapshots, structural sharing, quiescent invariant, routing and
  spurious-mutation metrics;
- single-seed A-E development pilot;
- frozen three-seed 5,000-tick A/B/D/E history sweep.

### Running

- one frozen 100,000-tick endurance trajectory with log-spaced checkpoints,
  context-failure recording, error episode age, repair opportunities, and
  state-error occupancy.

### Still required

- repeat endurance or use more seeds where affordable;
- fixed-query-rate crossover analysis for cumulative compute;
- dependency fan-out and missing-edge fault injection;
- periodic maintenance/no-maintenance comparison;
- snapshot concurrency experiment;
- scheduler and batching experiments for the broader runtime claims.

## 11. AGI section

For a systems submission, reduce the AGI discussion to one short closing
paragraph. Preserve the statement that relevance to persistent general agency
is a hypothesis, not a result. Lead with the demonstrated systems claim:

> Under bounded active-state residency and bounded dependency fan-out,
> persistent explicit cognition with sparse neural transitions decouples
> decision work from accumulated interaction history while making maintenance
> cost track incoming information.

## Revision order

1. Rewrite the contribution and claim boundaries.
2. Add dependency, state-type, and transaction contracts.
3. Replace the hard real-time claim with a soft target and implementation
   requirements.
4. Insert the completed experiment and its accounting caveat.
5. Add the closest-work matrix and older systems/cognitive lineage.
6. Split CSBC-Core from CSBC-Plastic.
7. Shorten the AGI section.
8. Add future experiments only for claims that remain explicitly unvalidated.
