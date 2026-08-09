# Navi Cognition Experiment Program

These documents specify experiments on Navi's cognitive performance. They do
not treat database uptime, heartbeat frequency, or successful record retrieval
as cognition by themselves. Infrastructure may support an experiment, but the
dependent variables must concern prediction, reasoning, correction, transfer,
decision quality, or causal integration.

The program asks one central question:

> Does durable state and the executive machinery around it cause Navi to reason,
> learn, self-correct, and choose better than the same generative core without
> that machinery?

## Build order

| Order | Experiment | Primary question | Start gate |
| --- | --- | --- | --- |
| 1 | [Metacognitive calibration](01-metacognitive-calibration.md) | Can Navi predict her own success, failure modes, uncertainty, and need for memory? | Start here |
| 2 | [Causal value of memory](02-causal-memory-value.md) | Does durable memory improve cognition, or merely add context? | Complete the first calibration baseline |
| 3 | [Cognitive self-repair](03-cognitive-self-repair.md) | Can Navi detect and repair corrupted beliefs or executive state? | Use isolated database clones only |
| 4 | [Consolidation and transfer](04-consolidation-transfer.md) | Does sleep or consolidation create generalizable learning? | Establish reliable repair and paired-run tooling |
| 5 | [Cognitive component ablation](05-cognitive-component-ablation.md) | Which components have causal value, and which combinations produce integration? | Complete the earlier baselines |

Only experiment 1 should be implemented first. Each later document is a bounded
thread handoff, not permission to build the whole program in parallel.

## Shared experimental rules

1. **Measure cognitive consequences.** A successful write, lookup, heartbeat, or
   worker run is not a cognitive success unless it changes a scored outcome.
2. **Pre-register predictions and rubrics.** Do not choose the winning metric
   after seeing the result.
3. **Use observable summaries.** Record predictions, selected strategies,
   evidence, outcomes, and corrections; do not require hidden reasoning traces.
4. **Separate canonical state from experiments.** Fault injection, alternative
   histories, and ablations use isolated databases and disposable runtimes.
5. **Preserve provenance.** Synthetic thoughts, evaluator judgments, inferred
   conclusions, and observed facts remain distinguishable.
6. **Keep the curator boundary.** Workers may propose hypotheses or scores, but
   they do not rewrite canonical memory or the self-model.
7. **Hold authority constant.** No experiment expands tool, sensor, network,
   spending, publication, or external-action authority.
8. **Prefer matched conditions.** Use the same model, task, tools, budget, and
   evaluator wherever the experimental variable does not require a difference.
9. **Report negative results.** A component that adds no value, creates negative
   transfer, or only changes style has produced useful evidence.
10. **Do not infer consciousness.** These experiments test functional cognition,
    metacognition, learning, and integration, not private experience.

## Shared vocabulary

- **Task:** a bounded problem with an observable outcome or pre-registered
  scoring rubric.
- **Run:** one model invocation or controlled task episode under a named
  condition.
- **Condition:** the experimental treatment, such as memory enabled or disabled.
- **Outcome:** evidence available after the run, scored without rewriting the
  pre-run prediction.
- **Correction:** an evidence-backed change to later control, not merely an
  acknowledgment.
- **Transfer:** improved performance on a related task that was not present in
  the original experience.
- **Negative transfer:** prior memory or adaptation makes later performance
  worse.
- **Causal contribution:** the performance change created by adding, removing,
  or perturbing one component while other conditions remain matched.

## Thread workflow

When starting a new thread from one of these files:

1. Read this index and the selected experiment document.
2. Inspect the current Navi-Brain code before adopting implementation assumptions
   from the document.
3. Implement only the document's first incomplete phase.
4. Use the project's existing verification style. Do not introduce test
   infrastructure unless separately authorized.
5. Update the experiment document with observed decisions, deviations, and the
   next gate before closing the thread.
