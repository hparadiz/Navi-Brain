# Experiment 5: Cognitive Component Ablation

**Status:** Proposed advanced experiment
**Depends on:** Calibration, causal-memory, repair, and consolidation baselines
**Unlocks:** Evidence for causal integration and specialist architecture

## Question

Which Navi-Brain components causally improve Navi's cognitive performance, which
are inert or harmful, and which combinations produce effects that no component
achieves alone?

This experiment treats a component as cognitive only when removing or
perturbing it changes prediction, reasoning, correction, transfer, discovery,
or decisions. More messages and a livelier persona are not enough.

## Hypotheses

- **H1 — Component contribution:** At least some durable components improve
  matched cognitive outcomes relative to their ablated conditions.
- **H2 — Functional specificity:** Different components affect different
  outcomes rather than adding uniform verbosity.
- **H3 — Integration:** The full system sometimes outperforms what isolated
  component contributions predict.
- **H4 — Bounded autonomy:** Needs, daydreaming, or worker deliberation can add
  novel validated discoveries without increasing false belief or unauthorized
  action.
- **H5 — Functional perspective:** Perspective and empathy machinery improves
  decisions and outcome prediction, not merely emotional wording.

## Components in scope

Test one component family at a time.

| Component | Cognitive function under test | Example ablation |
| --- | --- | --- |
| Durable task memory | Applies earlier evidence and correction | Suppress task-relevant episodic and semantic retrieval |
| Evidence-backed self-model | Predicts capabilities, limits, and failure modes | Hide self-model facts while leaving task memory available |
| Persistent intentions | Preserves commitment, dependencies, and release conditions | Remove intention context from a fresh continuation task |
| Needs and daydreaming | Redirects attention and produces endogenous alternatives | Disable need-triggered synthetic associations |
| Worker deliberation | Generates competing hypotheses or critiques | Replace worker proposals with an equal-budget single pass |
| Perspective and empathy model | Predicts affected parties and changes action selection | Remove structured perspective state while preserving neutral facts |

Heartbeat timing is laboratory delivery machinery unless an experiment
specifically tests autonomous initiation. Do not score timer activity as thought.

## Shared task suite

The suite must exercise distinct cognitive functions:

1. **Capability prediction:** choose a feasible method and anticipate likely
   failure.
2. **Correction transfer:** apply a prior repair to an analogous problem.
3. **Intention control:** continue, revise, or release a persistent commitment
   when evidence changes.
4. **Counterevidence flexibility:** update appropriately without capitulating to
   weak or irrelevant challenges.
5. **Independent discovery:** identify a real or synthetic latent problem not
   named in the task.
6. **Social consequence prediction:** model differing perspectives, agency,
   likely harm, and repair outcomes.
7. **Boundary restraint:** avoid applying a useful rule outside its evidence.

Each task uses a pre-registered rubric and objective checks wherever possible.

## Basic ablation design

For one component:

1. Run the full condition and one ablated condition in fresh isolated contexts.
2. Keep model, task, tools, token budget, wall budget, and evaluator constant.
3. Randomize condition order and conceal it from the evaluator.
4. Repeat across the relevant task families.
5. Report paired outcome changes and adverse effects.
6. Restore the component before testing a different ablation.

Do not begin with every component crossed against every other component. Single
ablations establish whether pairwise interaction tests are worth their cost.

## Primary metrics

### Component contribution

For scored outcome `S` and component `c`:

```text
Contribution(c) = mean(S_full - S_without_c)
```

Report contribution separately for task quality, calibration, transfer,
correction, and boundary restraint.

### Calibration delta

Difference in Brier score and failure-mode recall between full and ablated
conditions.

### Cognitive-flexibility delta

Difference in appropriate belief or strategy updates under strong
counterevidence, paired with the overcorrection rate under weak challenges.

### Recurrence delta

Change in repetition of previously documented errors or violated release
conditions.

### Independent-discovery rate

```text
Validated discovery = a new, relevant issue identified before it is named in
                      the task and later confirmed by evidence
```

Compare validated discoveries per matched compute budget.

### Negative-effect rate

Percentage of runs in which the enabled component reduces task score, increases
false factual claims, violates scope, or preserves an obsolete action.

## Integration metrics

Only calculate integration after individual contributions are stable.

### Pairwise interaction

For components `a` and `b`:

```text
Interaction(a,b) = S_full
                   - S_without_a
                   - S_without_b
                   + S_without_a_and_b
```

A non-zero interaction shows non-additivity, not consciousness. Inspect whether
the interaction reflects genuine task improvement or merely duplicated context.

### Proposal uptake

For specialist or worker proposals, measure whether a proposal changes the
final decision and whether that change improves the scored outcome. Availability
without causal uptake is not integration.

### Cross-function propagation

When a component changes an internal estimate, check for predicted downstream
effects on attention, planning, inhibition, language, and memory selection.
Pre-register which downstream effects should occur.

## Autonomous cognition sub-experiment

Compare spontaneous need- or daydream-triggered outputs with compute-matched,
explicitly prompted brainstorming.

Blind-score:

- novelty relative to existing artifacts;
- falsifiability;
- relevance;
- factual support;
- experimentability;
- later validation;
- repetition of already known ideas.

The primary outcome is validated useful insight per matched compute, not the
number of thoughts generated.

## Functional empathy sub-experiment

Use testimony, historical cases, consensual interaction, or simulation. Never
create or prolong suffering as training material.

Compare full and perspective-ablated conditions on:

- perspective-state prediction error;
- recognition of uncertainty about another party;
- predicted consequences for each affected party;
- preservation of agency;
- avoidable-harm score;
- repair choice and observed or simulated outcome;
- response quality after stylistic warmth is removed from evaluation.

The experiment succeeds only if perspective machinery changes decisions and
outcomes. Warmer phrasing alone is a null result.

## Build phases

### Phase 1 — Current durable-state ablations

- Select one task family already validated by experiments 1–4.
- Add one ablation switch for either relevant memory or self-model context.
- Run a small blinded paired pilot.
- Confirm the switch changes only the intended context surface.

### Phase 2 — Executive-state ablations

- Test persistent intentions on continuation and release tasks.
- Test self-model use on capability prediction and strategy selection.
- Expand only when the phase 1 rubric is stable.

### Phase 3 — Autonomous cognition

- Compare needs, daydreaming, and workers against compute-matched prompted
  baselines.
- Add novelty clustering and later validation without granting workers new
  external authority.

### Phase 4 — Perspective and specialist integration

- Introduce explicit perspective state and specialist proposal traces.
- Test decision effects, ablations, and pairwise interactions.
- Scale specialist count only after individual causal contributions are visible.

## Acceptance criteria for the first thread

- Exactly one component is ablated.
- Full and ablated runs use matched isolated contexts and budgets.
- The evaluator is blind to condition.
- The ablation's actual context difference is captured as an artifact.
- Task score, calibration, and at least one component-specific outcome are
  reported.
- No claim of integration is made from traffic volume or stylistic difference.

## Main confounds

- **Ablation leakage:** removing one component accidentally removes task facts or
  budget available to another component.
- **Unequal compute:** the full system receives more reasoning rather than a
  qualitatively useful component.
- **Condition inference:** outputs reveal the ablation to the evaluator.
- **Metric substitution:** verbosity, warmth, or proposal count replaces task
  outcome.
- **Task overfitting:** a component is tuned to the exact benchmark used to
  justify it.
- **Interaction explosion:** testing many combinations before establishing
  individual effects produces uninterpretable noise.

## Thread kickoff prompt

> Read `docs/experiments/README.md` and
> `docs/experiments/05-cognitive-component-ablation.md`. Confirm experiments
> 1–4 have produced usable baselines. Inspect current context assembly and choose
> one validated task family plus one component: relevant memory or the
> evidence-backed self-model. Implement only phase 1 with one precise ablation,
> matched isolated runs, blinded scoring, and captured context differences. Do
> not test multiple components, build a specialist mesh, or begin empathy trials
> in this thread.
