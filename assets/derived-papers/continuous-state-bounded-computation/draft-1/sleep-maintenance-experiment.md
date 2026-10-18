# Sleep-Like Maintenance Experiment

## Status

Drafted during the frozen 100,000-tick no-maintenance endurance run. Do not
lock or execute this protocol until the D-awake baseline completes and its
error dynamics are inspected. The biological analogy is a hypothesis; this is
a systems experiment about offline audit and repair.

## Question

Sparse online cognition gives untouched state strong continuity, but that same
continuity can preserve a bad committed transition. Does periodic global
maintenance reduce long-lived state error enough to justify its compute cost?

The predicted accuracy shape is a sawtooth:

```text
accuracy
  ^       |\        |\        |\
  |       | \       | \       | \
  |_______|  \______|  \______|  \____
          sleep     sleep     sleep      time
```

The claim is falsified if audits fail to repair error, introduce as much error
as they remove, or cost more than reconstruction without preserving a useful
waking decision boundary.

## Shared substrate

Use the exact seed-83 event tape from `endurance-100k-v1`:

```text
100,000 ticks
5% event probability
11 semantic fields
Qwen3.5 9B Heretic Q4_K_S
compact grammar-constrained transition protocol
nominal environment duration: 3.89 hours at 140 ms/tick
```

The no-maintenance D result is the waking baseline. Do not regenerate its
model outputs or change its runner.

## Conditions

### S0 — D-awake

Sparse online transitions with no global maintenance. This is the already
running endurance condition.

### S1 — D-oracle-sleep

Run the same sparse neural transitions. At maintenance boundaries, replace all
semantic fields with the simulator oracle in one transaction. This is an
unattainable repair ceiling, not an agent architecture.

It answers: if maintenance were perfect, how much error occupancy and error age
could this schedule remove?

### S2 — D-replay-sleep

Run the same sparse neural transitions. At maintenance boundaries:

1. pause action publication;
2. for each semantic field, retrieve its ordered provenance events from the
   durable ledger using the same hand-declared dependency relation;
3. reconstruct that field from its defined initial value and relevant evidence
   only;
4. stage all eleven reconstructed values in a candidate snapshot;
5. validate field cardinality, enum domains, provenance coverage, and baseline
   version;
6. atomically promote the candidate only if the entire audit validates;
7. recompute deterministic action arbitration;
8. resume the waking path.

Per-field replay keeps each prompt below the global full-history context size
and tests the value of indexed provenance. It still allows evidence lists to
grow, so audit cost versus age must be reported.

## Maintenance schedule

Provisional boundaries:

```text
25,000
50,000
75,000
100,000 ticks
```

At each boundary record two samples at the same logical tick:

```text
PRE_MAINTENANCE
POST_MAINTENANCE
```

Also record waking checkpoints every 5,000 ticks so the slope between
maintenance epochs is visible. The schedule must be locked only after the S0
baseline establishes that errors occur often enough to measure.

## Primary metrics

```text
semantic accuracy before maintenance
semantic accuracy after maintenance
post-maintenance accuracy gain
error-state occupancy (wrong node-ticks / all node-ticks)
error episode count
mean, median, p95, and maximum error age
unresolved error episodes
repair attempts and repair success
errors introduced by maintenance
ticks until accuracy returns to its pre-maintenance value
area under the semantic-error curve
maintenance prompt and completion tokens
maintenance wall time
waking transition tokens and wall time
total lifetime compute
decision-boundary neural work
```

## Hypotheses

### S-H1 — Periodic repair

Both sleep conditions reduce error-state occupancy and maximum error age versus
S0, with S1 defining the maximum possible improvement for the schedule.

### S-H2 — Sawtooth recovery

Post-maintenance semantic accuracy exceeds pre-maintenance accuracy at most
boundaries, followed by gradual degradation during waking updates.

### S-H3 — Realizable replay

S2 recovers a meaningful fraction of S1's gain without oracle values.

### S-H4 — Concentrated optional cost

Maintenance adds substantial lifetime compute but does not add neural work to
ordinary waking decision boundaries. It belongs to a lower-priority offline
budget rather than the control path.

## Critical controls

- Use the same event tape, model file, server build, model seed, compact
  protocol, and write-authority rules.
- Keep S0 raw artifacts immutable.
- Record all replay calls; maintenance is never free.
- Preserve malformed or semantically bad audits as results.
- Do not use oracle accuracy to decide whether an S2 candidate is promoted.
- Report S2 state immediately before and after every audit, including harm.
- Recompute error metrics from the independent oracle only during scoring.
- Execute S1 and S2 in counterbalanced order if repeated over multiple tapes.

## Interpretation boundary

A positive result would show that a persistent sparse runtime benefits from
periodic global error correction and that online and offline cognition can have
different compute policies. It would not show that biological sleep exists for
this reason, that LLM maintenance resembles neural sleep, or that parametric
learning is required.

The relevant intellectual precedent is complementary learning systems:
rapidly stored experiences can be reinstated during offline states, including
sleep, and gradually interleaved into slower representations. CSBC's proposed
error-reset curve is a narrower runtime hypothesis built on that replay idea:
[McClelland, McNaughton, and O'Reilly](../../papers/16-mcclelland-mcnaughton-oreilly-1995-complementary-learning-systems.pdf).
