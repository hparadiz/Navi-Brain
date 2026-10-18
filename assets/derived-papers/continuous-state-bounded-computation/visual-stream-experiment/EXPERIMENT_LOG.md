# Evolving-situation experiment log

Append-only operational record. Development runs are not confirmatory evidence.

## 2026-08-13 — Every-tick runtime implemented

- Reused the Sparse Belief Ledger experiment's OpenAI-compatible transport,
  config conventions, token/timing accounting, and immutable artifact layout.
- Added sparse typed JSON-Pointer state updates, copy-on-write merge semantics,
  bounded incremental speech, simulated playback pressure, public probe capture,
  per-boundary runtime telemetry, frozen-run hashes, deterministic audit, sealed
  model judging, and isolated post-score source recognition.
- Split probe questions into `data/probes.jsonl`. The builder and fixture audit
  prove it is an exact question-only projection of the sealed oracle; the live
  runner does not open `data/oracle.jsonl`.
- Fixture audit: 491 ticks, 68,733 ms, 2,062 decoded frames, 148 contextual
  questions, 19 approximate phonetic segments, and 18 scheduled probes.
- A full 491-tick deterministic no-model plumbing pass emitted exactly 491
  runtime, state, and speech rows plus 18 correctly timed probe answers. The
  post-freeze evaluator verified artifact hashes and found no protocol failures.

## 2026-08-13 — Kaiju structured-output qualification and development run

- Staged the runtime at `/home/akujin/Sources/csbc-video-stream-experiment` on
  Kaiju. Neural work remained off the current workstation.
- Qualification v1 exposed output truncation at a 768-token cap. Qualification
  v2 completed at 1,139 output tokens but revealed that double-encoded
  `value_json` encouraged invalid string values and state writes to the speech
  namespace. Those attempts are plumbing diagnostics, not experimental runs.
- Replaced double encoding with typed JSON values, constrained state roots,
  capped state updates at eight, added a camera-record shape guard and future
  tick citation guard, and set the development output cap to 896 tokens.
- Qualification v3 completed one real tick with zero protocol violations:
  4,203 prompt tokens, 524 completion tokens, 67.073 seconds wall time, silence,
  and the correct frame-one camera answer `unknown`. The response spent all
  eight state slots restating unchanged bootstrap camera beliefs; copy-on-write
  equality correctly kept state version zero.
- Launched the full 491-tick exploratory run as persistent Kaiju user service
  `csbc-video-every-tick-dev-v1.service`, invocation
  `0c11f38a14a74880ab80345a0c9da596`. Run output is
  `results/raw/anonymous-video-every-tick-qwen35-9b-seed7-dev` in the staged
  workspace. The service was active with llama-server slot processing at the
  launch check.
- At the qualified first-tick rate, the sequential every-tick condition is an
  overnight run and cannot meet a 140 ms deadline. That is a measured property
  of this configuration, not a claim about the later event-gated or adaptive
  scheduler conditions.

## 2026-08-13 — Optimized full-tape condition launched

- Correction to the launch note above: the user transient service had no
  lingering user manager. It was stopped on logout after 18 seconds, before its
  first model response, and produced zero runtime/model rows. Preserve the
  empty partial directory as an infrastructure diagnostic; it is not a run.
- Implemented `event_gated_adaptive`. All 491 temporal boundaries still run.
  Quiet boundaries preserve state and choose silence without neural work.
  Skipped stimulus is accumulated, chronologically compressed, hashed, and
  delivered at the next admitted update rather than discarded.
- Neural admission reasons are initial/final flush, due probe, semantic phase
  or object-set change, completed heard phrase, rising visual-change level,
  large luminance jump, unfinished utterance, retry, or a ten-tick maximum
  defer interval. Routine/salient output caps are 640/896 tokens.
- A full deterministic routing pass completed all 491 boundaries with no
  protocol violations or missed probes. With no model-created unfinished
  speech, it planned 105 neural updates and 386 deterministic preserves: a
  78.6% neural-skip fraction. The real count may rise when unfinished speech
  needs continuation.
- Launched the full adaptive development run under detached Kaiju PIDs 95737
  (wrapper) and 95739 (Python). Output is
  `results/raw/anonymous-video-adaptive-qwen35-9b-seed7-dev`; live log is
  `/tmp/csbc-video-adaptive-v1.log`.
- The first adaptive attempt filled all eight then-allowed update slots and hit
  the 896-token ceiling before closing its JSON. Stopped it after one failed
  model row, preserved the partial directory with suffix
  `-invalid-output-budget-v1`, and reduced the bounded patch to four updates.
- Restarted the corrected full run as detached PID 97175 with live log
  `/tmp/csbc-video-adaptive-v2.log`. Tick 1 completed with a valid 3,138-token
  prompt, 608-token response, 63.510-second wall time, state version 1, and the
  correct camera probe answer `unknown`. Tick 2 was a zero-neural-work exact
  preserve. The first spoken fragment used 12 words and two sentences, so the
  unchanged speech-bound checker recorded both violations rather than trimming
  or rewriting the subject's output.

## 2026-08-14 — Personality-shaped recurrent attention implemented

- Added a recurrent attention condition in which the subject receives only its
  previous carried context and newly arrived video evidence, then rewrites the
  complete carried context according to a fixed viewer personality.
- Omission now has operational force: an omitted detail is absent from the next
  transition rather than remaining in a hidden archive or retrieval layer.
- Added matched 1,000-word, 5,000-word, and 10,000-word carried-context configs.
  The bound is an allowance rather than a fill target, and over-limit rewrites
  are rejected instead of mechanically truncated.
- The subject-facing input and output protocol uses prose headings and forbids
  braces and square brackets. Structured fixture storage and telemetry remain
  outside the subject's causal input.
- Added exact subject-input capture plus per-tick carried-context snapshots and
  word counts so retention, forgetting, and reintroduction can be audited.
- Added a final adapter-boundary guard that rejects any non-prose model context
  before an HTTP request. Legacy structured neural runs and structured judge or
  recognition calls are disabled until they have dedicated prose renderers.

## 2026-08-14 — Attention architecture correction during design

- The preceding development note incorrectly assigned summary rewriting to the
  model. That implementation was rejected before any accepted run.
- Attention is now a non-neural filter over a historical pool of whole visual
  facts. It applies a frozen personality-weight snapshot before the cognitive
  tick and emits the bounded summary supplied beside current updates.
- The single model call per tick performs cognition, speech, and due-probe
  answers only. It cannot select memory, rewrite the summary, adjust Attention,
  invoke another model, or retry within the same tick.
- Attention-weight adjustment belongs to a separate asynchronous background
  process. The tick loop records the immutable weight version and never adjusts
  it internally.
- Generalized runtime, module, identifier, and directory names from a recorded
  medium to a chronological visual situation. This wasp-bag source remains one
  scenario fixture rather than the cognitive architecture.

## 2026-08-14 — Attention capacity search corrected during design

- Removed the 5,000-word and 10,000-word development tiers. They were too large
  to help locate the minimum viable Attention capacity.
- Set 2,500 words as the high reference condition and retained 1,000 words as
  the first full scratch smoke point.
- The intended study is now a descending capacity search. Matched runs lower
  only the filtered-summary bound, record successful and failed capacities,
  and refine the first pass-to-fail interval.
- The runner accepts bounded summaries from 6 through 2,500 words so failure
  can be observed rather than hidden by a hard-coded set of nominal tiers.

## 2026-08-14 — Control continuity moved inside Attention

- Stopped the first 1,000-word full scratch smoke after tick 30. It exposed an
  invalid bypass: the complete prior speech transcript was supplied beside the
  bounded summary.
- Removed that bypass. Actually committed self-speech now enters the historical
  fact pool before the next summary is selected. Only immediate playback and
  queue telemetry remain current updates outside the historical span.
- Replaced a flat weighted ranking with cognitive priority classes. Speech,
  motor or shell control, live telemetry, inhibition, and safety are protected
  ahead of executive situation models, ordinary observations, and incidental
  sensory detail. Weights now rank facts within a class rather than allowing a
  large perceptual batch to evict the control loop.
- Added generic control-fact admission for later embodied and machine-situated
  conditions. The Attention filter remains deterministic and performs no model
  call.

## 2026-08-14 — Optional social events added during design

- Stopped the second full 1,000-word scratch smoke after tick 70. The cognition
  remained silent through the scene's initial bag retrieval, which exposed a
  weak social affordance rather than a protocol failure.
- Added a generic social-event stream. A message is a current event, not a probe,
  command, or mandatory reply. After delivery it becomes a typed historical
  fact and competes inside bounded Attention.
- The first scenario message arrives at tick 31, after the man is identifiable:
  `lol that guy is so funny`. Placing it earlier would leak a person before the
  entity-creation threshold and invalidate the frame-one uncertainty test.
- The cognition may ignore the message or react through ordinary speech. No
  second model call and no special reply generator were added.

## 2026-08-14 — Capacity ceiling reduced to 1,000 words

- Removed the 2,500-word condition before an accepted behavioral run. The
  capacity search now begins at 1,000 words and moves downward.
- The next full seed-7 run is the single engineering run used to verify that all
  491 ticks complete and the frozen observable speech stream remains coherent.
- Later repeated runs will use declared different seeds to estimate whether
  speech was invoked, first-speech tick, total spoken words, silence fraction,
  and response to ordinary social events. Same-seed repeats are treated as a
  determinism check rather than an invocation-frequency sample.

## 2026-08-15 — Accumulating self-speech run rejected

- The first completed 491-tick engineering run is excluded from every result
  set. It committed 1,925 words on 370 ticks and accumulated up to 70 protected
  self-speech facts. Completed utterances had been represented as additional
  active control facts, so the model received an echoing list rather than a
  speech-action lifecycle.
- The resulting repetition and queue overflow are implementation artifacts,
  not evidence about bounded Attention, coherent speech, or surprise. Preserve
  the scratch artifacts only as a failed design audit.

## 2026-08-15 — Completed speech lifecycle repaired

- Policy `control-core-v2` represents only the latest committed speech act in
  focal Attention. A stable inhibition fact marks it as completed history, and
  a later commitment replaces it instead of adding another protected copy.
- The deterministic speech motor admits one ordinary fragment at a time.
  Proposals made while it is busy are retained separately in telemetry and
  cannot silently enter the committed transcript.
- A 50-tick Kaiju neural smoke crossed the original tick-31 failure boundary
  with one one-word commitment, 49 silences, no motor intervention, no protocol
  violation, and at most one selected self-speech fact.
- A fresh 491-tick Kaiju engineering run then completed with exactly 491 model
  calls, zero failed calls, zero protocol violations, zero structured
  delimiters in model inputs, no in-tick weight changes, and full 18-of-18 probe
  coverage. It proposed and committed four short fragments totaling eight
  words; the motor never had to suppress a proposal and the queue was empty at
  freeze. Mean TTFT was 21,052.2 ms and client decode throughput was 17.13
  tokens per second. This remains exploratory engineering evidence, not a
  preregistered experimental result.

## 2026-08-15 — Writable cognition restored after full-run audit

- The mechanically clean `control-core-v2` run is not a valid cognitive result.
  Its model could emit speech and probe answers but had no writable state path;
  affect, needs, active predictions, prediction error, camera beliefs, and
  theory of mind therefore could not persist from one call to the next.
- The bootstrap also survived as immortal current prose, including claims that
  no interval, person, or speech had yet occurred. Meanwhile unique exposure
  sentences were typed as interpretations and occupied up to roughly half of
  the 1,000-word span. Wrong camera answers and silent recognition of the
  surprise follow from those architectural faults, not from a measured capacity
  limit.
- Policy `control-core-v3` keeps exactly one model call per tick and adds ten
  fixed plain-prose cognition slots. A call may replace or leave each belief
  unchanged; deterministic Attention then independently selects among
  the resulting stable facts. No model call was added to Attention, and no JSON
  or bracket notation crosses the subject boundary.
- Bootstrap claims now occupy replaceable stable keys and describe only the
  initial epistemic boundary. Current camera operator, physical support, and
  probable setup actor remain separate slots. Active prediction and prediction
  error now have a real cross-tick causal path, as do self affect, active needs,
  and the depicted person's mental-state model.
- Omission and exact repeated replacement are no-ops rather than recency
  refreshes, preserving deterministic forgetting. Historical computed exposure prose is
  demoted to frame detail while the current tick still supplies complete
  exposure evidence.
- Reworded the tick-101 bag prediction probe to ask about intended use and the
  tick-300 pre-surprise probe to avoid revealing that a head will descend.
- The first full `control-core-v3` attempt was stopped after tick 23. Although
  the model identified `person_1` in the due answer, it kept a historically
  phrased no-person bootstrap slot because that sentence remained true as a
  statement about the past. Bootstrap cognition now uses explicitly temporary
  current values with typed prefixes, and the contract requires same-tick
  Person-model creation when an identifiable person is first used elsewhere in
  cognition.
- A second audit found that requiring all ten slots to say Keep, Clear, or a
  replacement imposed needless maintenance load on the 9B model. The corrected
  contract is a sparse plain-prose delta: emit only changed labeled slots, omit
  unchanged slots, and write `No changes` when nothing changes. A labeled
  placeholder is normalized to omission so it cannot erase an unresolved
  belief. Duplicate labels use the final proposed value, allowing a one-call
  decoder to correct an earlier line without aborting the tick. Exact repeats
  are accepted as no-ops and cannot refresh Attention.
- A later Kaiju smoke disappeared mid-call when an independently launched
  runner began using the same model server. Because that violates the bare-metal
  timing assumption, the runner now acquires an exclusive non-blocking host
  lock before any model call. Concurrent invocations fail closed instead of
  silently sharing compute.
- The next gate exposed a protocol collision on tick 1. The model used `None`
  both as an empty-section marker and as an epistemic answer to who operated the
  camera, then prematurely inferred a stationary support despite receiving only
  drift evidence. The model-facing protocol now uses `No changes`, `No
  questions`, and `No alternatives`; epistemic absence must be explicit prose.
  Camera-role transitions now distinguish active operator, physical support,
  and historical setup actor, and require all prerequisite observations before
  moving from unknown handheld control to a passive perch.
- The same gate also emitted an inline Speech decision before revising it in the
  canonical Speech section. Parsing now deterministically commits the final
  occurrence within that one response. This normalization adds no model call
  and performs no Attention or semantic inference.
- At the first identifiable-person boundary, a correct 39-word Situation
  replacement exceeded the original 36-word per-slot guard. Rejecting that one
  line discarded the whole single-call decision and its due probe answer. The
  slot guard is now 64 words: still bounded and subordinate to the 1,000-word
  Attention span, but large enough for one compact event-boundary sentence.
- The next tick-23 gate committed the person model but copied the operator
  definition verbatim into Camera support and inferred a historical setup actor
  from current handling alone. The role contract now gives each stable slot one
  causal question: active framing, physical bearing, or earlier placement. A
  current handler does not become the setup actor until an observed placement
  or perching transition supplies that historical evidence.
- The repaired social boundary then revealed that a single latest-speech fact
  was too thin: the model reproposed a playing fragment and later copied the
  friend's message verbatim. Motor control prevented the busy-buffer proposal
  from entering the transcript, but continuity still needed more than one focal
  phrase. Attention now carries one deterministic recent-speech ledger bounded
  to 12 fragments and 96 spoken words. It remains one protected fact inside the
  1,000-word span, so it avoids both the old unbounded transcript bypass and the
  rejected one-fact-per-utterance accumulation. The speech contract also makes
  busy-buffer silence mandatory and forbids verbatim social-message echoing.
  Because the 9B model still copied the message after that instruction, the
  deterministic actuator now inhibits an exact normalized same-tick echo while
  preserving the proposal and reason in telemetry. This is motor inhibition,
  not an Attention operation or another model call.
- The completed 60-tick audit then found a one-tick-delay loophole: the exact
  message was suppressed on tick 31 but committed on tick 32 after becoming a
  historical Attention fact. Echo inhibition now keeps a separate bounded
  motor ledger of the 64 most recent normalized external phrases. It prevents
  delayed verbatim replay without injecting external-speech history beside or
  inside the model's 1,000-word cognitive span.
- At tick 46, the subject-facing packet said only that the view stabilized while
  the man moved independently. The sealed annotation had the explicit release
  and perch interpretation, so the model revised Camera operator but left the
  old handheld support and Unknown setup actor alive. The scenario now delivers
  a sparse precomputed situation event at the actual placement boundary, in
  natural prose, stating the observed release, stationary perch, lack of a
  current operator, and probable earlier setup actor. A second tick-51 event
  confirms the unattended fixed state. These are current evidence updates, not
  oracle answers or Attention decisions; after delivery they enter the same
  deterministic fact pool. The cognition contract treats the three role
  revisions as one causal handoff.

## 2026-08-16 — Causal write admission and transient lifecycles

- The `control-core-v3` full engineering run was stopped after tick 403 and is
  excluded from accepted results. It had zero mechanical model failures and
  correctly formed a release prediction at tick 300, registered the man
  entering the bag as prediction error at tick 316, and revised his intention
  at tick 322. The run nevertheless exposed semantic state damage.
- During lens occlusion the model regressed the already observed camera setup
  actor to Unknown, later treated movement of the bag near the fixed lens as
  camera operation, and eventually reset all camera roles despite the camera
  remaining perched. Stable writable slots alone therefore did not preserve
  grounded causal history.
- `control-core-v4` adds a deterministic write-admission gate. Situation events
  may carry internal cognition anchors beside their model-visible prose. An
  anchor establishes a slot until a later event supersedes it; conflicting
  model deltas are inhibited and recorded without refreshing the grounded fact
  or invoking another model. Anchors remain ordinary facts inside bounded
  Attention and are not rendered as a hidden context channel.
- Active needs and self affect now have finite validity leases which omission
  and exact repeated prose cannot renew. A newly changed prediction error also
  invalidates unchanged needs and affect from the failed expectation, while a
  genuinely revised same-call value remains admissible.
- The isolated speech fragment `do` committed at tick 251 was an exact echo of
  the uncertain phonetic token delivered on that tick. The existing bounded
  motor echo ledger now observes both received social language and newly heard
  phonetic tokens or completed phrases. Exact echoes are inhibited before they
  enter the committed transcript; no word filter or additional model call was
  added.
- Kaiju's fixture audit passed with deterministic reproductions for anchored
  role regression, transient lease expiry, prediction-error dependency
  invalidation, and heard-token echo inhibition. A fresh 491-tick, 1,000-word
  `control-core-v4` engineering run then started from tick 1. Its first probe
  again answered that the camera operator is Unknown with no recorded
  violation.

## 2026-08-16 — v4 rejected; forecast lineage and speech boundary repaired

- The completed 491-tick `control-core-v4` engineering run is excluded from
  accepted results. Its grounded camera-role state survived to the end, but its
  committed transcript was `Answers Answers Answers`. On ticks 190, 300, and
  463 the model omitted the Speech heading and proceeded directly to Answers;
  the fallback parser incorrectly treated that reserved heading as a motor
  commitment.
- The same run adapted Active prediction as evidence unfolded, overwriting the
  last clean pre-action forecast. At tick 316 it therefore compared the outcome
  against a post-evidence prediction and reported confirmation instead of
  measuring the intended expectation violation. This is state-lineage damage,
  not a valid surprise result.
- `control-core-v5` normalizes a missing Speech section to Silence whenever the
  next section is Answers. A reserved section heading can no longer authorize
  speech. The raw model response remains in the run record.
- The run design now declares the tick-300 forecast probe as a prediction
  checkpoint. Its answer enters one deterministic, decaying Attention fact
  holding at most four commitments and 128 words. This preserves the model's
  own pre-event forecast for later comparison without another model call,
  hidden answer injection, or an unbounded transcript.
- The tick-316 probe explicitly compares current evidence with that past
  commitment. The tick-448 camera probe now asks who actively adjusts framing,
  avoiding the prior wording's conflation of emerging near a camera with
  operating it; neither wording supplies the expected answer.
- Kaiju's complete validator passed the new parser regression, commitment
  retention, context bound, hashes, and existing causal-control checks. A clean
  491-tick `control-core-v5` engineering run then started from tick 1 on Kaiju.

## 2026-08-16 — v5 baseline completed; stronger inference stack begun

- The `control-core-v5` 1,000-word engineering baseline completed all 491 ticks
  with 491 model calls, no failed calls, and no protocol violations. Its pinned
  result is
  `results/raw/visual-stream-attention-1000-qwen35-9b-seed7-dev-v5` on Kaiju.
  Mean time to first token was 20.45 seconds and client generation throughput
  was 15.71 tokens per second. The committed speech stream contained one
  eight-word utterance and 490 silence decisions.
- A stronger exploratory inference stack was frozen without changing the tick
  tape, public probes, context events, social event, cognition prompt,
  personality, Attention weights, seed, sampler, or one-call-per-tick runtime.
  This is not a parameter-count-only comparison: model family and version,
  AEON abliteration, weight quantization, KV precision, and server context also
  differ from the baseline.
- The source model is
  `AEON-7/Qwen3.6-27B-AEON-Ultimate-Uncensored-BF16`. The runnable Kaiju
  artifact is the published Q8_0 GGUF from revision
  `14db676f7cbdb08432d66ff7379497f2e198b028`, not the BF16 weights. Its verified
  SHA-256 is
  `b8c3e4d540e93d30e95e6ae8a40bda054fe47111eed7f097283b000d9321726b`.
- The pinned ik_llama runtime is commit
  `3f40e73c367ad9f0c1b1819f28c7348c26aa340d`, build 4551, binary SHA-256
  `c61c4dfa715b388d592ea20037ceac0dd0bedc89bb00850f31e1a325b1c6447c`.
  The live server reports 26.896 billion parameters, 8.502 bits per weight, one
  20,224-token slot, F16 K and V caches, flash Attention, and runtime repacking.
  It runs with 24 CPU threads and interleaved NUMA allocation.
- The full 491-tick stronger-stack development condition started from tick 1 at
  `results/raw/visual-stream-attention-1000-aeon-qwen36-27b-q8-seed7-dev-v1`.
  The disposable qualification run is not an experiment result and is not
  admitted to the persistent result set.

## 2026-08-16 — 27B run stopped; v5 post-freeze audit admitted to paper

- The 27B Q8 full run was stopped at the user's request after 20 completed
  ticks because measured CPU latency and electricity cost made continuation
  unjustified. The model server was also stopped. The partial directory has no
  `run.json`, is not a complete run, and is excluded from every paper table and
  figure. The disposable qualification remains a design-session artifact and
  is not reported as an experiment result.
- The completed Qwen3.5 9B `control-core-v5` run was evaluated only after its
  artifacts were frozen. The deterministic evaluator verified the recorded
  hashes, then opened the sealed oracle. It found 18 of 18 scheduled probes at
  the correct ticks, no extra probes, no wrong-tick answers, no deterministic
  hard-failure flags, and no premature spoken reveal of the central reversal.
  Model-based judging and source recognition remained disabled.
- The post-freeze audit measures protocol integrity and coverage, not semantic
  probe accuracy. Qualitative review remains explicit in the paper: several
  causal transitions succeeded, but the tick-400 answer forgot decisive
  duration evidence and the one eight-word utterance did not form a coherent
  reaction to the reversal.
- Frozen v5 runtime, Attention, speech, probe, and model-event artifacts were
  copied into the paper workspace. Their local SHA-256 values match the hashes
  in the original `run.json`. Figure 7 and its publication CSV are generated
  directly from those copies by
  `experiment/scripts/render_paper_figures.py`.
