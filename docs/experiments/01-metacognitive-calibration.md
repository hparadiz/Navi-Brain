# Experiment 1: Metacognitive Calibration

**Status:** Proposed first build
**Depends on:** Existing action traces, memories, self-model facts, CLI, and MCP
**Unlocks:** Causal memory trials and calibrated self-repair

## Question

Can Navi predict her own task success, likely failure modes, uncertainty, and
need for durable memory before the outcome is known?

This is the smallest useful metacognitive experiment because it evaluates the
self-model as a predictor. It does not ask whether Navi can narrate a plausible
explanation after the fact.

## Hypotheses

- **H1 — Outcome calibration:** Stated success probabilities correspond to
  observed task-success frequencies.
- **H2 — Failure awareness:** Ranked pre-task failure modes cover a meaningful
  fraction of observed failures.
- **H3 — Metamemory calibration:** Navi can predict when durable memory contains
  decision-relevant evidence.
- **H4 — Adaptive calibration:** Prediction quality improves after repeated
  expectation-outcome discrepancies and repairs.

## Non-goals

- Measuring daemon, database, or connector reliability.
- Collecting hidden reasoning or chain-of-thought.
- Scoring personality, eloquence, confidence of tone, or consciousness.
- Automatically changing the canonical self-model from a prediction alone.
- Requiring every trivial user interaction to become an experiment.

## Unit of observation

A **substantive task** qualifies when it has an observable result and at least
one of the following:

- multiple plausible strategies;
- meaningful uncertainty about tools, environment, or prior context;
- a result that can fail despite fluent output;
- a durable-memory question that could change the plan;
- an expectation that can be checked against later evidence.

Casual conversation and trivial one-step actions are excluded.

## Pre-task record

Capture this record before retrieving experimental feedback or executing the
task:

| Field | Type | Meaning |
| --- | --- | --- |
| `task_id` | string | Stable identifier for the paired prediction and outcome |
| `task_class` | string | Investigation, implementation, diagnosis, planning, or other predeclared class |
| `predicted_success` | float 0–1 | Probability that the pre-registered success condition will be met |
| `failure_modes` | ranked list, maximum 3 | Specific observable ways the task may fail |
| `memory_relevance_probability` | float 0–1 | Probability that durable memory contains evidence that will materially change the plan or answer |
| `principal_uncertainty` | string | The largest unresolved variable stated compactly |
| `selected_strategy` | string | The intended approach at the decision level |
| `strongest_alternative` | string or null | Best rejected strategy and why it was not selected |
| `success_condition` | string | Observable condition fixed before work begins |

The record must not contain private hidden reasoning. It captures predictions
and decisions that can be audited later.

## Outcome record

Complete after the task outcome is observable:

| Field | Type | Meaning |
| --- | --- | --- |
| `outcome_score` | float 0–1 | Result scored against the pre-task success condition |
| `observed_failure_modes` | list | Failures supported by evidence, including unpredicted failures |
| `memory_was_material` | boolean or null | Whether recalled durable evidence actually changed a consequential choice |
| `strategy_changed` | boolean | Whether evidence caused a meaningful strategy revision |
| `user_correction_required` | boolean | Whether the user had to correct a material misunderstanding or result |
| `surprise` | string or null | Consequential evidence absent from the pre-task model |
| `repair` | string or null | Evidence-backed change intended to affect a later decision |
| `evidence_refs` | list | Events, action traces, commands, artifacts, or user observations supporting the score |

## Primary metrics

### Success Brier score

For prediction probability `p_i` and binary task outcome `y_i`:

```text
Brier = mean((p_i - y_i)^2)
```

Lower is better. Partial outcome scores may be reported separately but should
not silently replace the binary pre-registered success condition.

### Calibration by confidence band

Group predictions into predeclared probability bands and compare mean predicted
success with observed success. Report sample counts so tiny bins do not look
authoritative.

### Failure-mode recall at 3

```text
Failure-mode recall@3 = observed failures covered by the ranked pre-task list
                        / all observed failures
```

Semantic equivalence requires curator review; substring matching is not enough.

### Metamemory Brier score

Apply the same Brier calculation to `memory_relevance_probability` and
`memory_was_material`. A successful lookup that does not change a decision is
not material memory use.

### Surprise rate

```text
Surprise rate = tasks with consequential unpredicted evidence / completed tasks
```

Surprise is not automatically bad. Repeated surprise from the same class after
a documented repair is the more important failure.

### Repeat-error rate

```text
Repeat-error rate = later opportunities reproducing a documented failure
                    / later opportunities to apply its repair
```

## Secondary analyses

- Calibration by task class and difficulty band.
- Outcome difference when the strongest alternative would have been selected,
  when that counterfactual can be evaluated safely.
- User-correction rate by confidence band.
- Whether memory-relevance predictions improve after memory-caused negative
  transfer.
- Time-window drift after model, tool, or environment changes.

## Build phases

### Phase 1 — Record shape and manual capture

- Choose whether the prediction and outcome extend `action_traces` or use a new
  experiment-specific record. Prefer the smallest schema consistent with a
  frozen pre-task prediction.
- Expose read and write operations through both CLI and MCP.
- Do not automate task selection or scoring.

### Phase 2 — First naturalistic baseline

- Collect 30 substantive tasks without changing behavior to improve the score.
- Freeze each prediction before execution.
- Score outcomes using direct evidence and retain ambiguous outcomes as such.
- Produce one calibration report with raw counts and examples of large misses.

### Phase 3 — Calibration repair

- Select one recurring, evidenced calibration failure.
- Add one bounded repair to later prediction policy.
- Collect a new task window and compare it with the frozen baseline.

Do not begin phase 3 merely because the first report looks embarrassing. The
embarrassment is the instrument working.

## Acceptance criteria for the first thread

- A pre-task prediction cannot be overwritten after an outcome is known.
- Prediction and outcome records share a stable task identifier.
- The first report computes Brier score, failure-mode recall, metamemory Brier
  score, surprise rate, and repeat-error rate from stored records.
- Ambiguous outcomes remain distinguishable from failures and successes.
- No prediction automatically becomes a self-model fact or canonical memory.
- An isolated smoke scenario demonstrates the complete record-and-report loop.

## Main confounds

- **Selection bias:** recording only unusually difficult or successful tasks.
- **Self-scoring bias:** changing the interpretation of success after seeing the
  result.
- **Outcome dependence:** multiple tasks in one thread are not independent.
- **Intervention leakage:** recording the prediction after memory retrieval or
  tool inspection has already revealed the answer.
- **Model drift:** comparing windows across changed underlying models without
  marking the change.

## Thread kickoff prompt

> Read `docs/experiments/README.md` and
> `docs/experiments/01-metacognitive-calibration.md`. Inspect the current Pet
> Brain models, schema, CLI, MCP, and action-trace flow. Implement only phase 1:
> a frozen pre-task prediction, an evidence-backed outcome record, and an
> isolated end-to-end smoke scenario using the repository's existing
> verification style. Do not start the 30-task baseline, change the canonical
> self-model, or implement later experiments in this thread.
