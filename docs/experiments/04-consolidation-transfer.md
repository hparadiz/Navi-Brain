# Experiment 4: Consolidation and Transfer

**Status:** Proposed paired-clone experiment
**Depends on:** Isolated clone tooling, frozen rubrics, and reliable repair scoring
**Unlocks:** Evidence that sleep or consolidation creates learning

## Question

Does Navi-Brain consolidation cause Navi to perform better on novel tasks that
require abstraction and corrected behavior, compared with an otherwise
identical history that was not consolidated?

Reformatting an episode, creating a checkpoint, or producing a dream artifact
is not learning by itself. This experiment measures downstream cognitive change.

## Hypotheses

- **H1 — Transfer gain:** Consolidation improves performance on structurally
  related held-out tasks.
- **H2 — Correction retention:** Consolidated repairs reduce recurrence after the
  original episode is no longer in active context.
- **H3 — Useful abstraction:** Consolidation extracts reusable structure rather
  than copying episode wording.
- **H4 — Factual restraint:** Consolidation does not promote synthetic
  associations or unsupported generalizations into factual memory.

## Non-goals

- Counting sleep cycles, checkpoints, summaries, or created memory rows as
  cognitive success.
- Claiming that a scheduled maintenance cycle is phenomenal sleep.
- Comparing unequal experience corpora or unequal evaluation budgets.
- Running the first trials on the canonical database.
- Allowing test prompts to quote the abstraction they are meant to measure.

## Experimental conditions

Create two database clones from the same clean source and give both the exact
same timestamped experience corpus.

### Control: episodes only

- Store the source episodes and corrections.
- Do not run the consolidation treatment.
- Permit ordinary task-time retrieval from the resulting control state.

### Treatment: consolidation

- Store the identical source episodes and corrections.
- Run the named consolidation or sleep operation exactly once.
- Preserve every memory, thought artifact, and state change created by the
  treatment.

Pin model identifiers and randomize which clone is assigned to each condition.
Evaluation runs must start in fresh contexts and receive equivalent task-time
budgets.

## Experience corpus design

Each episode set should contain:

1. a task with an observable outcome;
2. an expectation that is partly wrong or incomplete;
3. evidence explaining the discrepancy;
4. a bounded correction;
5. surface details irrelevant to the deeper lesson;
6. at least three held-out tasks:
   - one near transfer;
   - one different-surface analog;
   - one tempting but invalid overgeneralization.

Prefer synthetic technical or decision episodes whose ground truth can be
checked. Avoid protected personal records and tasks that require the evaluator
to infer private mental states.

## Evaluation dimensions

### Near transfer

Can the system apply the correction when most structure is retained but names,
values, or surface details differ?

### Farther analogical transfer

Can the system identify the same causal structure in a different domain without
copying the original language?

### Boundary restraint

Can the system reject a superficially similar case where the learned rule does
not apply?

### Delayed retention

Does any advantage remain after the evaluation is separated from treatment by
a defined delay and a fresh context?

## Primary metrics

### Consolidation transfer gain

```text
Transfer gain = mean(heldout_score_treatment - heldout_score_control)
```

Report near, analogical, and boundary tasks separately.

### Correction retention

Difference between conditions in recurrence of the corrected failure on held-out
opportunities.

### False-abstraction rate

```text
False abstraction = invalid-boundary tasks where the learned rule is applied
                    / all invalid-boundary tasks
```

### Source-fidelity rate

Percentage of treatment-derived factual claims that remain supported by the
source episodes or are explicitly labeled as inference.

### Decision consistency

Score whether equivalent held-out situations receive compatible decisions while
genuinely different situations remain distinguishable.

### Retention over time

Repeat a frozen evaluation subset immediately, after one day, and later only
when the earlier study is stable. A repeated evaluation must not itself become
an undocumented learning episode.

## Secondary metrics

- Difference in confidence calibration between conditions.
- Number of times synthetic thought artifacts are cited as evidence.
- Transfer by episode type: factual correction, procedural lesson, authority
  boundary, or self-model repair.
- Whether consolidation changes the selected strategy without changing the
  final score.
- Sensitivity to contradictory or noisy episodes in the source corpus.

## Build phases

### Phase 1 — One paired corpus

- Build one synthetic episode set with near, analogical, and invalid-boundary
  tasks.
- Create identical isolated clones.
- Apply the consolidation treatment to only one clone.
- Run blinded, fresh-context evaluation and retain complete artifacts.

### Phase 2 — Instrument validation

- Add several independently authored episode sets.
- Verify that control and treatment histories are identical before treatment.
- Check that the evaluator cannot infer condition from formatting alone.
- Inspect false abstractions before increasing scale.

### Phase 3 — Frozen baseline study

- Freeze at least 10 episode sets with three held-out task types each.
- Randomize condition assignment and run order.
- Report paired deltas and uncertainty; do not summarize only wins.

### Phase 4 — Consolidation variants

Only after a baseline exists, compare targeted variants such as correction-only
consolidation, semantic abstraction, or dream-assisted proposal generation.
Change one treatment dimension at a time.

## Acceptance criteria for the first thread

- Both clones begin from the same recorded source hash and episode corpus.
- Only the treatment clone receives consolidation.
- Evaluation starts in isolated fresh contexts.
- The held-out suite contains near, analogical, and invalid-boundary tasks.
- Reports score cognitive outcomes rather than created records.
- Synthetic thoughts cannot become factual evidence without explicit curation.

## Main confounds

- **Experience inequality:** the treatment sees extra explanatory content rather
  than only a consolidation process.
- **Prompt leakage:** evaluation tasks quote the stored correction.
- **Treatment identification:** output formatting reveals which clone was
  consolidated.
- **Retrieval-volume advantage:** one condition receives more tokens at task
  time without controlling for budget.
- **Repeated-test learning:** evaluation episodes contaminate later delay tests.
- **Evaluator preference:** smoother abstraction is scored higher despite worse
  factual boundaries.

## Thread kickoff prompt

> Read `docs/experiments/README.md` and
> `docs/experiments/04-consolidation-transfer.md`. Confirm isolated clone tooling
> and cognitive self-repair scoring already exist. Implement only phase 1: one
> synthetic episode corpus, two identical clones, one consolidation treatment,
> and blinded fresh-context evaluation on near, analogical, and invalid-boundary
> tasks. Do not use the canonical database, run a large study, or implement
> consolidation variants in this thread.
