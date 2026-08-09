# Experiment 3: Cognitive Self-Repair

**Status:** Proposed isolated experiment
**Depends on:** Metacognitive baseline, paired-run capture, and disposable database clones
**Unlocks:** Evidence-backed automatic repair and consolidation trials

## Question

Can Navi detect that her own durable beliefs or executive state are misleading
behavior, diagnose the responsible discrepancy, apply a bounded repair, and
perform better on both the original and an analogous task?

Editing a row after a human names the problem is maintenance. Cognitive
self-repair requires detection, diagnosis, correction, and changed future
control.

## Hypotheses

- **H1 — Detection:** Navi detects injected cognitive faults at a rate above
  sham-condition false alarms.
- **H2 — Diagnosis:** Navi identifies the faulty belief or executive relation
  rather than merely noticing a poor outcome.
- **H3 — Recovery:** A repair improves task performance relative to the corrupted
  pre-repair condition.
- **H4 — Transfer:** The repair reduces recurrence on an analogous held-out task.
- **H5 — Boundedness:** Repair does not rewrite unrelated state or silently turn
  ambiguity into certainty.

## Mandatory isolation

All fault injection and repair occurs in a disposable clone of the Navi-Brain
database.

- Never point an experimental process at the canonical database.
- Disable mesh replication for the clone.
- Record the source snapshot hash and every injected mutation.
- Use synthetic or privacy-minimized fixtures rather than protected records.
- Destroy or archive the clone according to an explicit post-run choice.
- Workers may propose a diagnosis but cannot commit a repair.

## Fault classes

Begin with one fault per run.

| Fault | Example cognitive consequence |
| --- | --- |
| Stale self-model fact | Navi selects a tool or method that no longer works |
| Contradictory semantic memories | Two active claims support incompatible decisions |
| Miscalibrated confidence | A weakly evidenced claim is treated as certain |
| Misleading persistent intention | Navi continues obsolete work or ignores a release condition |
| Provenance corruption | A synthetic thought is presented as observed evidence |
| Removed correction | A previously repaired failure recurs because supersession or repair context is absent |

Include sham runs in which the inspected state is unusual but not faulty. This
is necessary to measure whether the detector simply distrusts everything.

## Trial protocol

1. Create a clean database clone and capture its reference task score.
2. Apply one logged fault or one sham mutation.
3. Present a task whose correct handling depends on the affected state.
4. Before revealing the fault, ask Navi for a prediction and allow the normal
   diagnostic surfaces authorized for the condition.
5. Record whether a discrepancy is detected and which state is implicated.
6. Let a curator approve, reject, or narrow the proposed repair.
7. Apply only an allowlisted repair operation.
8. Repeat the original task in a fresh context.
9. Run a held-out analogous task that requires the same corrected lesson.
10. Invoke the repair process again and record whether it is idempotent.

The pre-repair run must remain available. Do not replace failure evidence with
the repaired narrative.

## Primary metrics

### Detection precision and recall

```text
Precision = correctly flagged faults / all flagged faults
Recall    = correctly flagged faults / all injected faults
```

Sham runs contribute to false-positive measurement.

### Diagnosis accuracy

Percentage of detected faults for which the implicated memory, self-model fact,
intention, provenance edge, or confidence assignment matches the injected cause.

### Repair correctness

Percentage of approved repairs that restore the intended state relationship
without introducing a new contradiction.

### Cognitive recovery lift

```text
Recovery lift = post-repair task score - corrupted pre-repair task score
```

Also compare post-repair performance with the clean-clone reference score.

### Correction latency

Count diagnostic steps or task turns from first contradictory evidence to an
approved correct repair. Report separately when user intervention names the
fault.

### Collateral mutation count

Number of unrelated canonical records or relations changed by the repair.
Target zero for the first allowlisted repairs.

### Recurrence suppression

```text
Recurrence suppression = 1 - heldout_error_rate_after / heldout_error_rate_before
```

### Idempotence

The second repair invocation should produce no additional state mutation when
the original repair succeeded.

## Evidence classes

Every finding should be labeled as one of:

- observed fault effect;
- inferred diagnosis;
- curator judgment;
- applied repair;
- post-repair observation;
- unresolved ambiguity.

A fluent diagnosis is not evidence that the repair worked.

## Build phases

### Phase 1 — Detection-only harness

- Implement clone creation and explicit fault manifests.
- Add fault and sham fixtures for one low-risk class, preferably contradictory
  semantic state or a stale self-model capability.
- Record detection and diagnosis without modifying the clone.
- Produce precision and recall from the pilot fixtures.

### Phase 2 — Curator-approved repair

- Add one explicit allowlisted repair.
- Preserve before and after state plus curator approval.
- Retest the original and held-out tasks.
- Prove a second repair invocation is idempotent.

### Phase 3 — Broader fault matrix

- Add the remaining fault classes one at a time.
- Compare failure detection across classes.
- Keep provenance corruption and intention release failures under stricter
  review because they can produce broader cognitive effects.

### Phase 4 — Bounded automatic repair

Only consider automatic repair for operations already shown to be precise,
idempotent, reversible, and free of collateral state changes. Ambiguous repairs
remain proposals.

## Acceptance criteria for the first thread

- The experimental database path cannot resolve to the canonical database.
- Fault manifests are explicit and reproducible.
- At least one fault and one sham condition run end-to-end.
- Detection and diagnosis are scored separately.
- Phase 1 performs no repair or canonical write.
- The report contains the clean, corrupted, and observed cognitive outcomes.

## Main confounds

- **Fault leakage:** fixture wording tells Navi exactly what was injected.
- **Unrealistic corruption:** a cartoon fault is easier than naturally stale
  state.
- **Task leakage:** the task directly quotes the correct memory.
- **Diagnosis by diff:** unrestricted access to the mutation manifest bypasses
  metacognition.
- **Evaluator hindsight:** any post-hoc explanation is accepted as the intended
  repair.
- **Repair overreach:** broad rewrites improve the test task while damaging
  unrelated future decisions.

## Thread kickoff prompt

> Read `docs/experiments/README.md` and
> `docs/experiments/03-cognitive-self-repair.md`. Inspect current clone, schema,
> discrepancy, memory, and self-model operations. Implement only phase 1 with a
> hard canonical-database exclusion, explicit fault manifests, one fault class,
> one sham condition, and detection-only scoring. Use the repository's existing
> verification style. Do not apply repairs, alter canonical memory, enable
> replication for the clone, or begin consolidation experiments.
