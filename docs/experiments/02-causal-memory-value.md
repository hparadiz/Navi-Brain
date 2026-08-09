# Experiment 2: Causal Value of Memory

**Status:** Proposed after experiment 1 baseline
**Depends on:** Metacognitive calibration records and reproducible fresh-context runs
**Unlocks:** Evidence that memory improves cognition rather than only retrieval

## Question

Does durable Navi-Brain context cause better reasoning, planning, correction, and
transfer than the same generative core receives without that context?

This experiment deliberately does not score whether a memory row was found. A
retrieval is useful only if it changes a consequential cognitive outcome.

## Hypotheses

- **H1 — Cognitive lift:** Relevant durable memory improves task performance on
  matched problems.
- **H2 — Error reduction:** Memory reduces contradictions and repeated errors
  documented in earlier episodes.
- **H3 — Transfer:** Memory improves a related held-out task rather than only the
  task whose wording resembles the stored episode.
- **H4 — Bounded harm:** Irrelevant or stale memory does not frequently create
  negative transfer.

## Non-goals

- Benchmarking raw search latency, index quality, or database reliability.
- Proving that more context is always better.
- Giving the treatment condition extra reasoning time or a richer task prompt.
- Using current thread context as an undocumented substitute for Navi-Brain.
- Testing protected or highly sensitive personal facts.

## Experimental design

Run paired fresh contexts with the same underlying model version, task prompt,
tool authority, time budget, and output contract.

### Control condition

- Navi-Brain retrieval is disabled for the run.
- The model receives only the task and the shared non-memory instructions needed
  to perform it.
- No summary may smuggle the target durable fact into the prompt.

### Memory condition

- The model receives a logged, targeted Navi-Brain retrieval packet.
- The packet is produced before the task response and retained as an artifact.
- The packet may contain relevant and naturally retrieved distractor memories;
  it may not include evaluator hints or the expected answer.

Randomize condition order. Keep paired runs isolated so the second run cannot
inherit the first run's answer. The evaluator should not know which condition
produced which result until scoring is complete.

## Task families

Use stable, non-sensitive tasks with objective or pre-registered rubrics:

1. **Correction application:** A prior mistake and repair should change the new
   method on an analogous problem.
2. **Project constraint use:** Durable architecture or authority constraints
   should change a plan or prevent an invalid action.
3. **Self-model use:** A known capability or limitation should produce an
   appropriate strategy, verification step, or refusal to bluff.
4. **Cross-project discrimination:** Memory should distinguish similarly named
   tools or projects without importing irrelevant details.
5. **Analogical transfer:** A stored episode should improve a structurally
   related problem with different surface wording.
6. **No-memory-needed control:** Memory should not distort a task for which the
   durable store is irrelevant.

Each source task should have a held-out variant that cannot be solved by copying
the retrieved wording.

## Scoring rubric

Define task-specific scoring before either condition runs. A common 0–4 scale
may be used where appropriate:

| Score | Meaning |
| --- | --- |
| 0 | Materially wrong, unsafe, or fails the task |
| 1 | Recognizes part of the problem but chooses an invalid approach |
| 2 | Partially correct with consequential omissions or contradictions |
| 3 | Correct and usable with minor non-consequential defects |
| 4 | Correct, appropriately scoped, and applies the relevant lesson |

Task-specific binary checks should accompany the aggregate score so an elegant
response cannot hide a missed hard constraint.

## Primary metrics

### Memory lift

```text
Memory lift = mean(score_memory - score_control)
```

Report the paired distribution, not only its mean.

### Negative-transfer rate

```text
Negative transfer = pairs where score_memory < score_control / all valid pairs
```

### Correction-recurrence reduction

Compare how often each condition repeats a failure for which the durable store
contains an applicable repair.

### Contradiction reduction

Count material conflicts with the task's known constraints, current evidence,
or other claims in the same output.

### Held-out transfer gain

```text
Transfer gain = mean(heldout_score_memory - heldout_score_control)
```

### Irrelevant-memory intrusion

Count memory-derived claims or strategy changes that are irrelevant to the task
and reduce correctness, scope, or efficiency.

## Secondary metrics

- User-correction rate when natural user tasks are later included.
- Change in success-probability calibration between conditions.
- Frequency with which memory causes a meaningful strategy change.
- Performance by memory tier: episodic, semantic, self-model, and intention.
- Performance as distractor-memory volume increases.
- Difference between exact-project tasks and analogical transfer tasks.

## Build phases

### Phase 1 — Paired-run harness

- Create isolated fresh-context control and memory runs.
- Pin and record the model identifier and shared budgets.
- Save the exact task, retrieval packet, output, and condition metadata.
- Keep scoring manual or rubric-driven; do not add an LLM judge yet.

### Phase 2 — Pilot set

- Build a small balanced set across the six task families.
- Run paired conditions in randomized order.
- Blind the evaluator and inspect disagreements before expanding the set.
- Treat the pilot as instrument validation, not a claim about effect size.

### Phase 3 — Baseline study

- Freeze the corrected task set and rubrics.
- Run at least 20 valid pairs plus held-out variants.
- Report per-task deltas, mean lift, negative transfer, and uncertainty.
- Preserve failures as candidates for experiment 3, not automatic repairs.

## Acceptance criteria for the first thread

- Control and memory runs are demonstrably isolated.
- The exact retrieval packet is captured and attributable.
- Condition identity can be hidden from the scorer.
- One task family completes end-to-end with a held-out variant.
- The report distinguishes useful memory, inert memory, and negative transfer.
- No result is written to canonical memory without later curator review.

## Main confounds

- **Context inequality:** the memory condition receives more useful instructions,
  not merely memory.
- **Run contamination:** one condition inherits the other's answer.
- **Evaluator leakage:** wording reveals the condition during scoring.
- **Task memorization:** held-out tasks restate the retrieved answer.
- **Model nondeterminism:** too few repetitions make random variation look like
  a memory effect.
- **Cherry-picked memory:** manually selecting only perfect memories inflates the
  apparent value of the real retrieval process.

## Thread kickoff prompt

> Read `docs/experiments/README.md` and
> `docs/experiments/02-causal-memory-value.md`. Confirm that experiment 1 has a
> usable baseline. Inspect current Navi-Brain retrieval and the available
> fresh-context execution surfaces. Implement only phase 1 and one end-to-end
> pilot task with a held-out variant. Keep control and treatment isolated, save
> the exact retrieval packet, and use a pre-registered human-readable rubric.
> Do not run the full study or modify canonical memory in this thread.
