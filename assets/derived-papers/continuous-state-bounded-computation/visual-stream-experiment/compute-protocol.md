# Compute needed for a real-time speaking agent

## Question

How much neural and non-neural computation is actually required for an agent to stay situated on a 140 ms clock, update a changing self/world/social model, and produce one coherent real-time spoken reaction?

The answer is not automatically “one full model call per tick.” The temporal kernel must regain control at every boundary, but many boundaries may only preserve state, advance playback, admit work, or record silence. A semantic update may run asynchronously across several boundaries. The experiment should measure that distinction instead of treating 491 ticks as 491 identical generations.

## Unit of measurement

Every boundary writes one runtime row matching `schemas/runtime-tick.schema.json`, including no-op boundaries. The row separates:

- temporal-kernel work;
- deterministic routing, attention-transition validation, and state-commit work;
- neural jobs admitted, continued, completed, cancelled, or skipped;
- prompt, cache, and generated token counts;
- speech commitment and playback state;
- deadlines, lateness, backlog, and staleness;
- hardware time and, when available, energy/utilization samples.

Model-call latency is not the only real-time latency. Record at least:

- evidence arrival to durable state commit;
- evidence arrival to speech decision;
- evidence arrival to first audible reaction;
- contradiction arrival to stale-speech interruption;
- job admission to completion;
- boundary start to kernel release.

## Experimental conditions

Use the same stimulus and scoring oracle under a small set of scheduler conditions:

1. **Every-tick neural update:** request a bounded model transition at all 491 boundaries. This is the expensive timing reference, not presumed optimal.
2. **Personality-shaped Attention filter:** before every cognitive transition, a non-neural filter applies one frozen personality-weight snapshot to whole facts in the historical pool and emits a bounded summary. Supply that summary beside only the newly arrived evidence. The model never selects or rewrites memory. Weight adjustment may run asynchronously outside the tick loop and publish a later immutable snapshot. Establish the ceiling and high reference at 1,000 words, then lower the bound across matched runs until required retention or behavioral coherence fails.
3. **Adaptive budget:** allocate more work when salience, uncertainty, prediction error, affect/need conflict, or speech staleness rises; preserve state cheaply otherwise.
4. **Turn-based reconstruction baseline:** periodically rebuild from available history and generate speech in larger chunks. This tests whether lower scheduling complexity loses temporal honesty or interruptibility.

Within each condition, vary only declared parameters: model and quantization, maximum input/output tokens, KV/prompt-cache policy, maximum concurrent jobs, speech-fragment bound, and queue limit. Use repeat runs because timing and sampling vary.

For the Attention capacity search, hold every declared parameter and frozen
weight snapshot constant except the summary word bound. Record every attempted
bound, including failures. After locating the first failing interval, choose
additional bounds inside that interval rather than assuming the threshold lies
on a preselected tier.

The filter protects control continuity before representational richness. Its
priority classes are, in order: active speech, motor or shell control, live
telemetry, inhibition, and safety; active goals, self-model, agent models,
prediction error, uncertainty, and event boundaries; general observations and
interpretations; incidental sensory detail. Weighted salience, recency,
habituation, and novelty rank whole facts only within a class. Committed speech
is part of the bounded historical pool; the full transcript must never bypass
the capacity condition.

For an embodied agent, the protected control class carries speech, motor plans,
proprioceptive feedback, and inhibition. For a computer-situated agent, the
same interface carries speech, active commands, process and service state,
locks, failures, resource pressure, and task commitments. These are generic
Attention channels; the current visual scenario exercises self-speech first.

## Primary compute measures

- neural invocations per second and per clip;
- input, cached, uncached-prefill, and output tokens per second and per clip;
- total accelerator/CPU busy time and duty cycle;
- median, p95, p99, and maximum kernel, state-commit, model-job, and speech-decision latency;
- percentage of 140 ms boundaries released on time;
- number and duration of unfinished jobs spanning boundaries;
- cancelled, stale, superseded, or wasted completions;
- maximum job backlog and speech queue depth;
- silent/no-neural-work tick fraction;
- carried-context words before and after each transition, details retained, forgotten, or reintroduced, and words processed per call;
- energy per clip and per spoken word when reliable power telemetry exists.

## Primary behavioral measures

- camera-agency and temporal-knowledge probe accuracy;
- person theory-of-mind creation timing and nonexistence before tick 23;
- prediction quality before the reversal and belief revision afterward;
- speech onset and interruption latency at salient events;
- whole-stream speech rubric, including emotional/need continuity;
- future leakage, stale-role, hallucinated-certainty, and long-uninterruptible-queue failures.

## The frontier

Do not report a single “minimum tokens” number divorced from quality. Plot behavioral quality and deadline success against compute. A useful minimum is the lowest-compute configuration that satisfies predeclared thresholds, for example:

- at least 99% of kernel boundaries released on time;
- no hard information-leak or early-entity failures;
- camera and temporal-knowledge probes above threshold;
- contradiction-to-interruption latency below threshold;
- whole-stream coherence and temporal-honesty scores above threshold.

Report configurations that are cheap but incoherent, coherent but late, or fast but overconfident. Those failures locate different boundaries of the architecture.

## Interpreting a mess

A fragmented transcript with correct state suggests the speech controller is underbudgeted or poorly queued. Fluent speech with stale camera roles suggests continuity failed despite language quality. Correct reactions arriving seconds late suggest semantic capacity without temporal viability. Excessive silence may preserve deadlines while failing social participation. A wildly expressive reaction that predicts the reveal early suggests leakage or memorization, not superior continuity.

The experiment is informative even if no configuration clears every threshold. The useful result is a measured compute/latency/quality surface and a diagnosis of which property fails first as budget shrinks.
