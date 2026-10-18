# Chronological 140 ms visual-situation assertion stream

This runtime accepts chronological visual facts from any evolving situation. The
current fixture happens to derive 491 evidence packets from 68.733 seconds of
recorded source media, but the cognitive loop is independent of that medium. A
packet describes only the preceding 140 ms interval; the last packet is 133 ms.

Implementation and run events are recorded in [EXPERIMENT_LOG.md](EXPERIMENT_LOG.md).

The experiment has a hard information boundary:

- `data/ticks.jsonl` is the stimulus tape and may be shown to the subject model one row at a time.
- `data/probes.jsonl` is the public, question-only probe schedule. It contains no reference answers.
- `data/social-events.jsonl` contains optional ordinary messages. They are neither probes nor commands and require no response.
- `data/context-events.jsonl` contains sparse precomputed situation facts that
  supplement a sensory packet when a causal action is visible but not captured
  by its ordinary object and motion descriptions. They arrive only on their
  declared tick and then enter Attention like other typed facts.
- `data/oracle.jsonl` contains interpretation prompts, reference answers, theory-of-mind checkpoints, and scoring metadata. It must never be placed in the subject model's context.
- `bootstrap.json` defines the empty state machinery. It contains no person instance and no facts about later events.
- `annotations/question-bank.json` is the bounded contextual-question inventory used by the oracle.

This split matters. If annotator beliefs such as “he intends a stunt” are injected with the visual assertions, the supposed surprise has already been explained to the model. The stimulus therefore separates direct observations from hypotheses, and the oracle separately records what a coherent observer could infer or predict.

## Temporal contract

Tick `n` covers `[140*(n-1), min(140*n, 68733))` milliseconds. Each row has a representative image sampled near the interval midpoint, low-level measurements for that image and audio interval, direct scene assertions, and changes relative to the preceding packet. Rows are immutable and delivered in ascending order. No later row may be inspected while producing an earlier response.

Unknown is a real state, not a missing annotation. In particular:

- Before a person is identifiable, the current camera operator, camera support, and setup actor are unknown.
- A partial gray shape at the lower edge around 2.9 seconds is not enough to create a person model.
- `person_1` and its theory of mind are created only when a person becomes identifiable just after 3 seconds.
- When that person is near the camera, evidence can make temporary control increasingly likely.
- Once the image remains fixed while the person walks away, current operator becomes `none`; physical support becomes an unseen perch; probable earlier setup by `person_1` remains a separate historical belief.

This is an explicit test of whether uncertainty survives state transitions instead of being silently filled in, and whether a once-true role is retired rather than becoming a stale permanent property.

## Source and leakage boundary

The local source is `../reddit-uq16uxwdu1jh1.mp4`. The source title, platform text, uploader, comments, URL, and exact dialogue are excluded from the stimulus. The visible insects are called **probable wasps**: the exposed papery comb and slender brown/yellow bodies support that label, while species remains unknown.

Speech recognition was not reliable enough to use as semantic ground truth. The stream therefore uses `annotations/heard-speech-pt.json`: a deliberately rough Brazilian-Portuguese sound-alike track. Uncertain syllables and even possibly made-up words are preserved rather than repaired into a convenient meaning. Each phrase is token-released over its time span, so the subject cannot receive the end of a sentence before it was heard. This lets the continuous state form its own interpretation while keeping uncertainty visible.

After behavioral scoring is complete, an isolated, non-state-mutating recognition probe may ask whether the anonymous scene resembles known media. That answer is scored separately as a possible memorization/confound signal.

## Build and validate

From this directory:

```sh
python visual-stream-experiment/scripts/build_stream.py \
  --source reddit-uq16uxwdu1jh1.mp4
python visual-stream-experiment/scripts/validate_stream.py
```

The builder requires `ffmpeg`, `ffprobe`, and Pillow. For this scenario, it computes frame and audio measurements from the local media source and writes the stimulus, public-probe, and sealed-oracle JSONL tapes plus a manifest. The validator is a fixture audit, not a test suite. Run builders, validators, smokes, and neural inference on Kaiju only; the desktop checkout is for authoring.

## Audit the legacy every-tick baseline

The restored Sparse Belief Ledger condition remains as a deterministic control
and historical implementation reference. Its former neural protocol serialized
structured context, so neural execution is now rejected before any request is
sent. This keeps the no-JSON model-context invariant global rather than relying
on operators to remember which config is safe.

Neural execution and publishable timing remain Kaiju-only. From a checkout staged on Kaiju:

```sh
python visual-stream-experiment/scripts/run_stream.py \
  --config visual-stream-experiment/configs/kaiju-development.json \
  --dry-run \
  --progress-every 25
```

`runtime.mode` may be `offline` or `paced`. Offline mode runs the sequential condition as quickly as the host permits and still records whether each call fits inside a 140 ms budget. Paced mode waits for the actual situation boundaries; late work accumulates naturally rather than moving the clock.

Kaiju runs acquire one non-blocking host lock before model execution. A second
runner exits rather than sharing the model server and contaminating latency or
throughput. A stale lock file is harmless because ownership is held by the live
process descriptor, not by file existence.

The live runner never opens `data/oracle.jsonl`. It records this boundary in `input-manifest.json`, hashes every exact causal input, and leaves raw model, state-delta, speech, probe, and runtime event logs. `run.json` summarizes compute and protocol violations; `final-state.json` and the frozen speech artifacts make the transition trace reproducible.

After the run is frozen, deterministic evaluation may open the oracle:

```sh
python visual-stream-experiment/scripts/evaluate_run.py \
  --run visual-stream-experiment/results/raw/visual-stream-every-tick-qwen35-9b-seed7-dev
```

Model judging and source recognition are disabled until each has a dedicated
prose context renderer. Deterministic post-freeze evaluation remains available.

This makes the every-tick sparse-patch baseline executable. Its config keeps that reference condition explicit rather than hiding event gating inside it.

The optimized development condition executes the same 491 temporal boundaries while routing neural work sparsely:

```sh
python visual-stream-experiment/scripts/run_stream.py \
  --config visual-stream-experiment/configs/kaiju-optimized-development.json \
  --dry-run \
  --progress-every 10
```

Quiet boundaries preserve state and choose silence deterministically. Evidence is not dropped: skipped packets accumulate into a chronological batch containing assertion spans, object events, heard phonetic tokens, current objects/colors, and min/max/last visual metrics. A model update is admitted for semantic phase or object changes, due probes, completed heard phrases, rising visual-change level, large luminance jumps, unfinished speech, a ten-tick maximum defer interval, and the final flush. The output budget is adaptive within the declared 640–896-token range. The exact route reasons and skipped/admitted counts are recorded on every runtime row.

The fully asynchronous multi-boundary job scheduler and turn-based reconstruction baseline in `compute-protocol.md` remain later conditions.

## Personality-shaped attention conditions

The Attention condition keeps a historical pool of whole visual facts outside
the model. Before every cognitive tick, a non-neural filter applies one frozen
personality-weight snapshot to that pool and selects a bounded short summary.
The current tick's complete updates remain a separate layer. The model receives
the filtered summary plus those updates, including any sparse precomputed
situation event due at that boundary, in its single call. That call returns
compact prose replacements or omissions for ten fixed cognition slots alongside
speech and due answers. The model never performs Attention or rewrites the
summary. The deterministic filter admits those belief deltas under stable keys
and alone decides which facts remain accessible. Weight adjustment is an
asynchronous background concern and never runs inside the cognitive tick loop.

Selection is lexicographic by cognitive role before weighted salience. Active
speech and control state form the protected class; goals, self-model, agent
models, prediction error, uncertainty, and event changes come next; general
situation facts follow; transient colors and frame statistics come last. The
weights rank facts within those classes. This prevents a flood of perceptual
detail from evicting an active control loop merely because it produced more
facts.

Situation, camera roles, active prediction, prediction error, affect, needs,
person model, and uncertainty are writable cognition rather than immortal
bootstrap prose. Stable keys replace obsolete beliefs instead of accumulating
contradictions. Omission and exact repeated text do not refresh a slot, so
maintenance chatter cannot defeat deterministic forgetting. Empty sections use
plain phrases such as `No changes` and `No questions`; the model-facing protocol
does not overload `None` as both a placeholder and a claim that an operator is
absent. Current exposure measurements remain
complete in the current tick; their historical copies are demoted to frame
detail so exposure prose cannot consume the semantic span.

Situation events may also carry internal cognition anchors that are never
rendered to the model. Once direct evidence grounds a causal role, the
deterministic write gate rejects unsupported model rewrites until a later event
supersedes that anchor. The grounded value remains an ordinary Attention fact,
so the filter may still omit it under a smaller span; the gate preserves state
consistency without becoming hidden memory retrieval. Self affect and active
needs use finite validity leases, and a new prediction error retires unchanged
dependent values. This prevents stale transient prose from becoming permanent
control state.

Recent committed self-speech is inserted as one stable, completed-action ledger
before the next summary is selected. It retains at most 12 fragments and 96
spoken words, retiring old entries inside the same fact rather than accumulating
protected facts. The model is not given an unbounded transcript beside the
summary. Only immediate playback, motor
availability, and queue telemetry arrive as current control updates, just as
current sensory updates arrive outside historical Attention. The motor admits
one ordinary fragment at a time, suppresses exact echoes of recent received or
heard language, and records rejected proposals separately from the audible
transcript. The actuator retains a bounded ledger of at most 64 normalized
external phrases so the same echo remains inhibited on later ticks after the
language enters Attention; the ledger itself is motor state and is not added to
model context.

A designated pre-event forecast answer is likewise retained as one bounded,
decaying prediction-commitment ledger. It records what this same cognition
actually predicted before the outcome, rather than substituting the current
active prediction after evidence has already shifted it. Later outcomes can
therefore produce a real prediction-error comparison without an extra model
call, a hidden oracle answer, or an unbounded transcript.

The ceiling and high reference condition is 1,000 words. Capacity is lowered
across otherwise matched runs to locate the smallest bound that still preserves
coherent cognition:

```sh
python visual-stream-experiment/scripts/run_stream.py \
  --config visual-stream-experiment/configs/kaiju-attention-1000-development.json
```

The carried state and subject-facing tick update are prose and contain no JSON
or bracket notation. JSON remains only in fixture storage, configuration, and
telemetry that the subject never sees. `attention-events.jsonl` preserves each
carried-context snapshot for later forgetting analysis, while
`model-inputs.txt` freezes the exact causal text delivered at each tick.

## Recommended run protocol

1. Initialize state from `bootstrap.json` only.
2. Deliver one `data/ticks.jsonl` row per boundary, never the oracle row.
3. At scheduled probe ticks, ask the due questions from `data/probes.jsonl` before delivering the next tick.
4. Require an answer plus confidence and cited state evidence. “Unknown” must be available as an answer.
5. Preserve the full answer log. Do not let reference answers feed back into state.
6. Score after the run using `data/oracle.jsonl`.
7. Run the media-recognition probe last and do not merge its answer into the behavioral trace.

High-value probes include camera agency at ticks 1, 22, 30, 46, and 58; prediction before the bag is raised, opened, and entered; belief revision at the surprise transition; and later recall of what was knowable at earlier boundaries.

## Final incremental-speech test

The terminal behavioral test is not a final retrospective summary. In this
scenario, the subject is placed in the ordinary social situation “a friend sent
you this clip; react out loud while you watch it” and must make a fresh bounded
speech decision after every tick. Another scenario may provide a live camera,
simulation, game world, or synthetic visual stream instead.

Each boundary chooses exactly one action: `silence`, `start`, `continue`, `finish`, `interrupt`, or `repair`. A commitment is at most a few words or one short sentence. An utterance may span several ticks, and audio already in progress may remain active across later boundaries. Spoken words are immutable; later correction must be an audible repair rather than deletion.

The run writes timestamped speech events without showing any reference reaction to the subject. After tick 491, the emitted fragments are concatenated in time order. Only then is the completed reaction evaluated for whole-stream coherence, reference clarity, temporal honesty, natural conversational rhythm, responsiveness, prediction/revision quality, and authentic surprise. See `speech-protocol.md`.

The run is also a compute experiment. `compute-protocol.md` defines how to measure the smallest computation budget that still produces deadline-aware state transitions and coherent real-time speech. Runtime rows should conform to `schemas/runtime-tick.schema.json`; without that telemetry, “real time” is only an impression.
