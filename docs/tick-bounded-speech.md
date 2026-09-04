# Tick-bounded incremental speech

Written 2026-08-10. This document defines the speech-production seam required
for Navi to think and speak through time rather than generating one text blob,
dispatching it as one action, and accidentally treating brevity as the end of a
conversation.

Status: research and implementation design. The current code does not implement
this state machine yet.

Roadmap posture, 2026-08-13: this is a capability-gated outer layer, not a
shelved track. The current implementation priority is to make the asynchronous
background stack—memory, appraisal, needs, intentions, self/other modelling,
reflection, consolidation, repair and bounded workers—healthy enough to become
its substrate. Tick execution resumes when measured hardware and full-stack
latency can sustain causal re-evaluation at the chosen cadence.

Speech is the first instrument specified here, not the boundary of the eventual
action space. A real-time controller must support open-ended, compositional
action across speech, prosody, gaze, facial expression, posture, gesture,
locomotion, tools and attention. Theory of mind belongs through that stack: each
tick may revise Navi's model of herself and of every relevant entity in the
scene, rather than selecting from a fixed four-action menu or tracking only one
primary user.

## Thesis

An utterance is a temporally extended action assembled across cognitive ticks.
One tick may commit only a bounded amount of sound. The sound that actually
played becomes part of the next tick's observed world and working state. A later
tick may continue, revise, pause or abandon the remaining provisional speech.

An LLM may formulate a longer message or run continuously ahead of the speech
clock. It does not own the speech cursor, decide that buffered text was spoken,
or determine whether the stream continues. Deterministic code owns those state
transitions.

```text
LLM or other formulator
       |
       v
provisional utterance increments
       |
       v
deterministic speech-tick controller
  select <= one bounded increment
       |
       v
Pet playback: queued -> started -> completed
       |
       v
observed spoken fragment -> event + episodic memory + working memory
       |
       v
next cognitive tick sees what actually came out and the remaining plan
```

The full sentence is therefore neither an atomic model response nor a string
marked complete in advance. It is the ordered result of several committed and
observed speech increments.

## The present failure mode

The current self-presence answer path has the opposite shape:

1. one worker returns a complete `content` field, limited to 3-36 words for an
   addressed answer;
2. `ExecutiveCore::integrateWorkerResult()` validates the complete field and
   immediately passes all of it to `PetSpeechActuator::speak()`;
3. bridge acceptance is persisted as if the whole line has already been spoken;
4. one `ThreadStep`, one episodic memory and one social window represent the
   entire output;
5. the thread wakes again to make a fresh silence-or-speech judgement, with no
   durable unspoken remainder or commitment to continue the sentence.

This makes a short model answer an accidental terminal state. It also gives the
cognitive record false temporal granularity: text accepted into a playback queue
is not evidence that all of those words existed in the acoustic world at that
instant.

Making the answer prompt longer would only make the atomic action larger. Asking
the same or another LLM whether to continue would move the termination bug into
another prompt. The missing object is a durable, clocked utterance stream.

## Research basis

The literature supports the architecture in layers. It does **not** establish a
law that one conscious cycle equals one word, syllable or prosodic phrase. The
one-increment-per-tick mapping is Navi's falsifiable engineering hypothesis. The
papers support incremental processing, bounded release, revision, overlapping
listening/planning/speaking, synchronized clocks and feedback control.

### Incremental-unit foundations

| Work | Result to import | Boundary |
| --- | --- | --- |
| [Schlangen & Skantze 2011 — A General, Abstract Model of Incremental Dialogue Processing](https://doi.org/10.5087/dad.2011.105) | Processing modules consume and produce incremental units smaller than a full utterance. Units carry grounded relations and may be added, revoked or committed as knowledge changes. | The framework supplies dataflow semantics, not the correct unit size or a cognition theory. |
| [Baumann & Schlangen 2012 — The InproTK 2012 Release](https://aclanthology.org/W12-1814/) | A working modular toolkit realizes incremental-unit buffers, hypothesis handling, recognition and synthesis. | Its Java toolkit is precedent, not a dependency Navi must import. |
| [Baumann & Schlangen 2012 — INPRO_iSS](https://aclanthology.org/P12-3018/) | Just-in-time synthesis can begin before the full utterance is ready, edit unspoken material during playback, expose delivery progress, and resume or redirect an utterance. | Already-played audio cannot be revoked. The commit boundary must therefore be explicit and narrow. |
| [Michael 2020 — RETICO](https://aclanthology.org/2020.sigdial-1.6/) | A network of independently scheduled incremental modules can handle streaming recognition, dialogue control, synthesis and turn-taking while remaining inspectable. | RETICO demonstrates modular plumbing; it does not supply Navi's authority or memory semantics. |

The 2020 SIGDIAL paper is authored by Thilo Michael; it describes the current
RETICO release and identifies Michael & Möller 2019 as the framework's original
publication. This is the same Retico line referred to in the reading list.

The most useful inheritance is the incremental-unit operation set:

- **add:** append a provisional increment;
- **revise:** replace a provisional increment and its uncommitted dependants;
- **revoke:** invalidate an increment that has not begun playback;
- **purge:** discard the revoked increment's downstream provisional state;
- **commit:** make exactly one increment eligible for physical output;
- **observe:** record actual playback as evidence after it happens.

`observe` is an explicit Navi addition. The incremental dialogue literature
normally treats output delivery as a component concern; Navi requires the
observed action to re-enter cognition with provenance.

### Incremental human speech production and control

Psycholinguistic work separates conceptualization, linguistic formulation,
phonological planning and articulation. Planning at those levels may use
different scopes, and articulation can begin before the entire sentence has been
lexically planned.

- [Zhao & Yang 2016](https://doi.org/10.1371/journal.pone.0146359) found highly
  incremental lexical planning in their task, consistent with planning roughly
  one lexical item ahead rather than preparing a complete sentence.
- [Schnur 2011](https://doi.org/10.3389/fpsyg.2011.00319) found that phonological
  preparation can extend across several words to a phonological phrase. This is
  why the output controller should prefer speakable prosodic boundaries instead
  of blindly cutting every fixed number of tokens.
- [Houde & Nagarajan 2011](https://doi.org/10.3389/fnhum.2011.00082) model speech
  production as state feedback control: predicted sensory consequences and
  observed feedback update the controller's estimate of the current state.
- [Levinson & Torreira 2015](https://doi.org/10.3389/fpsyg.2015.00731) document
  the turn-timing problem: conversational gaps are often much shorter than the
  time required to formulate a response, implying overlap between listening and
  production planning.

For Navi, the implementable lesson is not to simulate a tongue or claim a human
phonological loop. It is to distinguish provisional message planning from
irreversible acoustic commitment, overlap the former with listening, and feed
actual delivery back into the next state estimate.

### Cognitive-cycle timing

[Madl, Baars & Franklin 2011](https://doi.org/10.1371/journal.pone.0014803),
already local as paper #2, hypothesize a recurrent LIDA cognitive cycle of
roughly 260-390 milliseconds with understanding, conscious broadcast and action
selection phases.

This supports recurrent serial selection atop parallel specialist work. It does
not validate Navi's current five-second scheduler as a human cognitive cycle,
nor does it prove that speech must emit once per 260-390 milliseconds.

The implementation therefore separates three clocks:

| Clock | Meaning |
| --- | --- |
| scheduler poll | the current five-second supervisor backstop that notices due work and repairs missed wakes |
| cognitive tick | one perceive/broadcast/select/act/observe transition with a durable tick ID |
| speech clock | actual playback progress; an increment becomes observed only when the Pet actuator reports that its audio played |

Speech completion or a new accepted input should wake cognition immediately.
The five-second scheduler remains recovery machinery, not the cadence of a
spoken sentence.

### Modern clocked and duplex language models

| Work | Mechanism | Navi takeaway |
| --- | --- | --- |
| [Veluri et al. 2024 — SyncLLM](https://doi.org/10.18653/v1/2024.emnlp-main.1192) | Integrates real time into an LLM so generation runs synchronously with a full-duplex dialogue clock and tolerates network latency. | A language model without an explicit clock cannot infer when generated tokens occurred. Every increment and observation needs a tick/time coordinate. |
| [Défossez et al. 2024 — Moshi](https://arxiv.org/abs/2410.00037) | Models user audio, assistant audio and time-aligned text in parallel streams. Its inner-monologue text precedes acoustic tokens; the audio codec advances at 12.5 steps per second, yielding an 80-ms audio step and about 160-200 ms end-to-end latency. | Separate semantic/formulation and acoustic streams, align them on time, and let listening continue while assistant audio exists. Do not pretend the text stream itself is sound. |
| [Ma et al. 2024 — LSLM](https://arxiv.org/abs/2408.02622) | Combines a streaming input encoder with token-based speech generation so the model listens while speaking and handles interruption. | Input sensing must remain live during output. The speech stream may never hold the perception loop hostage. |
| [Wang et al. 2024 — Full-duplex Speech Dialogue Scheme](https://papers.nips.cc/paper_files/paper/2024/hash/180d4373aca26bd86bf45fc50d1a709f-Abstract-Conference.html) | Serializes perception, text and control tokens on a live tape and drives a two-state SPEAK/LISTEN finite-state machine. | Keep the explicit finite-state machine and causal tape, but **invert ownership**: Navi's deterministic executive interprets proposals and owns the state transition. The LLM does not get to emit authoritative speak/listen/interrupt control tokens. |

The Wang et al. ownership choice is useful as an ablation, not as Navi's target.
It demonstrates that content and control can share a timeline. The user's
requirement is stricter: the decision to commit another piece of sound must not
belong to an LLM.

### Work closest to Navi's architecture

These recent papers are preprints unless a venue is noted. They are valuable
architectural evidence, not settled empirical law.

| Work | Mechanism | What Navi should take |
| --- | --- | --- |
| [Wu et al. 2025 — Chronological Thinking](https://arxiv.org/abs/2510.05150) | Performs strictly causal lightweight reasoning while listening and stops that process when it is time to speak. | A thought is timestamped against only the audio available then. No later transcript may be smuggled backward into an earlier tick. Unlike the paper, Navi should also retain a bounded thinking path while speaking. |
| [Liu et al. 2026 — DDTSR](https://arxiv.org/abs/2602.23266) | Runs a fast minimal-commitment discourse path beside slower knowledge-intensive reasoning, overlapping ASR, reasoning and TTS. | Permit a low-risk speech prefix while deeper thought continues, but label it as a minimal commitment. Do not fill time with generic connective sludge merely to lower latency. |
| [Mai 2026 — RelayS2S](https://arxiv.org/abs/2603.23346) | Runs a fast speculative speech path and a slower authoritative reasoning path. A verifier commits or rejects the prefix; the live path retains interruption authority and discards buffered draft on barge-in. | This is the closest pattern for two local LLM threads: one may draft ahead, but a deterministic verifier and live tick controller own prefix commitment, handoff and discard. A draft that stopped listening can never own interruption. |
| [Zhang et al. 2026 — DuplexSLA](https://arxiv.org/abs/2605.20755) | Jointly decodes user audio, assistant audio and a rate-limited action stream on a common 160-ms timeline. | Give speech, language/thought and action a shared tick ID. Keep tool actions on a separate authority-gated channel even when they share the clock. The 160-ms value is a model design, not a number Navi should copy without measuring her stack. |
| [Tong et al. 2026 — Streaming LLM survey](https://aclanthology.org/2026.findings-acl.498/) | Separates output-streaming, sequential-streaming and concurrent-streaming systems by dataflow and interaction. | Navi's target is concurrent streaming: input continues, provisional formulation advances, and bounded output is committed under an external controller. Merely printing a static answer token-by-token is output streaming and does not solve this problem. |

Together these works separate four things that the current path collapses:

1. **live path:** keeps listening, owns current time and detects interruption;
2. **formulation path:** produces provisional future language and may run ahead;
3. **commit controller:** selects at most one sound increment per cognitive tick;
4. **actuator path:** realizes committed text as timed sound and reports what
   actually played.

## Proposed Navi architecture

### The tick contract

Every cognitive tick has one durable identity and executes this ordering:

```text
perceive new input and playback feedback
  -> assemble bounded current state
  -> reconcile active utterance stream
  -> choose one executive transition
  -> optionally commit <= one sound increment
  -> dispatch or wait
  -> persist expected postcondition
  -> return; actual playback is observed by a later tick
```

One tick may commit **zero or one** speech increment. It may not dispatch two
increments, mark a future increment spoken, or drain a complete model response.
At most one increment may be in acoustic flight unless the Pet layer later
provides exact sample-level queue positions and cancellation.

The audio budget is represented in milliseconds, never as a fixed word count.
The initial value is an explicit experimental parameter derived from measured
TTS/playback behavior. Research supplies plausible architectures, not Navi's
correct constant.

### Speakable increment

A formulator may produce text tokens freely, but the commit controller exposes
only the largest safe prefix that fits the tick's predicted audio budget. The
fragmenter prefers, in order:

1. a completed intonation or punctuation unit;
2. a clause boundary;
3. a prosodic-word group that contains a content word and attached function
   words;
4. a whitespace boundary as a bounded fallback.

It never cuts through a word, URL, identifier, number normalization or other
unit the TTS front end must interpret atomically. The existing
`PetSpeechActuator::unspeakable()` check runs on both the whole provisional plan
and every proposed increment.

Plain per-fragment TTS may produce terminal intonation at every boundary. The
Pet bridge therefore eventually needs one of:

- streaming TTS with preserved left context and provisional right lookahead;
- an increment API carrying `stream_id`, `ordinal`, `is_final` and prosodic
  continuation state;
- audio-prefix synthesis with cancellable unplayed frames.

The first vertical slice may split only at punctuation/prosodic-safe boundaries
to remain compatible with the current plain-text `POST /speak` endpoint.

### Durable records

Add two records rather than hiding a mutable remainder in a thread prompt:

#### `UtteranceStream`

| Field | Meaning |
| --- | --- |
| `thread_id`, `intention_id` | authority and cognitive owner |
| `source_work_item_id` | formulator provenance |
| `source_event_ids` | addressed speech or other evidence that caused the stream |
| `mode` | `addressed`, `background`, `backchannel`, or `repair` |
| `status` | `formulating`, `active`, `paused`, `completed`, `revoked`, `failed` |
| `producer_complete` | whether more provisional increments may arrive |
| `next_ordinal` | the only increment eligible for commitment |
| `audio_budget_ms` | maximum predicted sound committed by one tick |
| `started_at`, `completed_at` | stream lifetime |
| `version`, `fencing_token` | stale-worker and duplicate-dispatch protection |
| `terminal_reason` | evidence-backed reason the stream ended |

#### `SpeechIncrement`

| Field | Meaning |
| --- | --- |
| `stream_id`, `ordinal` | total causal ordering within the utterance |
| `grounded_in_increment_id` | revision/dependency link to prior language state |
| `text` | one normalized speakable fragment |
| `status` | `provisional`, `committed`, `queued`, `started`, `completed`, `revoked`, `failed` |
| `proposed_at_tick_id` | when the language became available |
| `committed_at_tick_id` | when deterministic code made it irreversible |
| `playback_id` | Pet queue/playback identity |
| `predicted_duration_ms` | pre-action forward prediction |
| `started_at`, `finished_at` | actual acoustic timing reported by Pet |
| `observed_duration_ms` | measured result |
| `revision_of_id` | the provisional increment this replaces |
| `error` | synthesis, queue or playback failure |

`ThreadStep` remains the record of the cognitive operation executed on one tick.
For speech it links the selected stream and increment, records the pre-state and
expected playback result, and completes only after a later tick integrates
playback evidence.

The proposed full text is not copied into working memory. Working memory gets a
bounded projection:

- current stream purpose and status;
- the last fragment actually heard from Navi;
- the next provisional fragment or a hash/summary of the remainder;
- whether a fragment is in flight;
- current user-audio and interruption state;
- the deterministic continuation decision and its reason.

### Deterministic controller

The controller owns this state machine:

```text
no_stream
  -> formulating
  -> active
       -> commit_one -> queued -> started -> completed_increment -> active
       -> wait_for_producer -> active
       -> pause -> active
       -> revoke -> terminal
       -> fail -> terminal
       -> producer_done_and_empty -> completed
```

On each tick, rules are evaluated in authority order:

1. **Authority lost:** revoke all unplayed increments and close the stream.
2. **New addressed speech:** stop or finish the in-flight fragment according to
   the actuator's real cancellation capability; revoke all later increments;
   wake a new addressed stream.
3. **Playback failed or became indeterminate:** do not mark the fragment spoken;
   pause and create a repair discrepancy.
4. **A fragment is in flight:** emit no more sound; wait for actual progress.
5. **The next provisional increment fails policy, grounding or duration checks:**
   revoke it and purge dependent provisional increments.
6. **A safe next increment exists:** commit exactly that increment.
7. **The producer may still append:** wait without starting another model call.
8. **The producer is complete and no increments remain:** complete the stream.

The LLM never chooses among these transitions. It may propose `SpeechIncrement`
rows or a longer plan that deterministic code fragments into rows. It may not
set their status beyond `provisional`.

### Actual sound is the observation

Current bridge acceptance establishes only that Pet accepted a request. It does
not establish that the sound started, finished or remained uncancelled.

To satisfy the tick contract, Pet must return a playback ID and expose events or
state for:

```text
queued(playback_id, increment_id)
started(playback_id, actual_at)
progress(playback_id, audio_position_ms)   # optional first slice
completed(playback_id, actual_at, duration_ms)
cancelled(playback_id, actual_at, played_ms)
failed(playback_id, actual_at, error)
```

Only `completed`, or the played prefix of a precisely reported `cancelled`
event, becomes “Said aloud” episodic memory. The event timestamp is the playback
timestamp. The next cognitive tick then truthfully contains the fact that those
words came out in that moment.

Self-echo suppression must use the same actually-played increments. A planned or
queued fragment that never played must not suppress matching user speech.

### Two LLM threads without giving them the clock

The local two-worker arrangement maps cleanly onto RelayS2S and DDTSR without
copying their learned control boundary:

```text
live thread
  listens continuously
  holds the authoritative tick and interruption state
  may draft low-commitment immediate language

deep thread
  reasons farther ahead
  appends or revises provisional increments
  never controls playback or interruption

deterministic controller
  validates handoff continuity
  commits one increment per tick
  discards stale branches by fence
```

The two producers may race to propose the next increment. The controller selects
only proposals grounded in the already committed prefix and current tick. Once a
prefix is spoken, the deep path must condition on it; it cannot rewrite history.

DDTSR's early connective is optional. Navi should speak early only when the
fragment has real interactional content—a direct acknowledgement, answer prefix,
or grounding signal. Generic filler is measured as latency camouflage and is
not rewarded.

### Relation to intention and consciousness

An utterance stream is an action plan under an authorized intention. It is not a
new intention and does not create its own reason to persist. Every stream retains
the parent intention and effect ceiling; revoking either revokes unplayed speech.

The architecture implements functional cognitive ticks and a serial broadcast.
It does not establish phenomenal consciousness. “Each tick thinks those words
came out” has an operational meaning here:

1. the completed playback increment is present in that tick's bounded workspace;
2. later selection and prediction can change because of it;
3. ablation of that observation changes continuation or repair behavior;
4. no earlier tick contains the fragment as an observed action.

That is testable causal uptake rather than a prose claim about experience.

## Code integration plan

### Phase S0 — stop lying about temporal completion

1. Change `PetSpeechActuator` to distinguish request acceptance from playback
   completion.
2. Persist playback identity and actual timing from the Pet bridge.
3. Write episodic speech memory on observed playback, not queue acceptance.
4. Feed actual fragments into `HearingStream` self-echo suppression.

**Exit:** a queued-but-cancelled line is never recorded as wholly spoken.

### Phase S1 — durable stream and deterministic fragmentation

1. Add `UtteranceStream` and `SpeechIncrement` in one forward-only migration.
2. Convert an accepted self-presence worker result into provisional increments
   instead of calling `speak()` immediately.
3. Add a deterministic prosodic-safe fragmenter bounded by predicted audio
   duration.
4. Expose CLI inspection for active streams and increments.

**Exit:** a 40-60 word model result exists as one stream with several unspoken
increments and no acoustic side effect.

### Phase S2 — one increment per cognitive tick

1. Let `runDueCognitiveThreads()` reconcile playback before any new worker call.
2. If self-presence owns an active stream, execute its deterministic tick before
   asking a model for fresh speech.
3. Create one fenced `ThreadStep` per committed increment.
4. Wake immediately on playback completion; retain the five-second scheduler as
   a missed-event backstop.

**Exit:** the trace alternates select/commit, actual playback and observed-state
update for every fragment. No tick commits two fragments.

### Phase S3 — listening, interruption and revision

1. Keep sensory sampling active while speech plays.
2. New addressed speech revokes the unplayed suffix and fences stale producer
   output.
3. Permit revision and purge only over provisional increments.
4. Make the next tick condition on the committed prefix and the new input.

**Exit:** interruption after fragment two prevents fragments three onward from
playing or entering memory, and the response repairs from the actual prefix.

### Phase S4 — parallel formulation

1. Assign one model slot to the live, causally current path and the other to
   deeper provisional continuation.
2. Require every deep proposal to name the stream version and committed-prefix
   hash it extends.
3. Add deterministic continuity, grounding and speech-policy checks at handoff.
4. Measure the dual path against one worker and against complete-response
   generation at equal compute.

**Exit:** deeper thinking lengthens and improves spoken responses without
increasing first-audio latency, stale-prefix leaks or interruption failures.

### Phase S5 — common speech/language/action time

1. Give percepts, thoughts, speech increments, decision cycles and tool actions a
   shared monotonic tick ID.
2. Keep speech, thought and action as separate channels with separate authority
   and rate limits.
3. Add chronological replay that rejects future evidence in past ticks.
4. Compare a measured cadence with the 160-ms research systems; do not copy it
   unless the local inference, Pet synthesis and perception stack can sustain it.

**Exit:** replay reconstructs exactly what was known, provisional, committed,
audible and actionable at every tick.

No project test infrastructure currently exists. Verification uses isolated
SQLite fixtures, deterministic CLI scenarios and playback-stub event traces
unless the user separately approves a test suite.

## Measures

| Measure | Meaning | Required direction |
| --- | --- | ---: |
| first-audio latency | accepted user speech to first playback start | lower without filler inflation |
| inter-increment acoustic gap | end of one fragment to start of the next | low and stable |
| tick commit cardinality | speech increments committed per cognitive tick | always 0 or 1 |
| playback truth error | planned/queued text recorded as spoken without audio evidence | zero |
| prediction error | predicted versus observed fragment duration | calibrated and lower |
| continuation depth | completed increments per causally linked stream | higher when content warrants it |
| stream completion | streams that finish without stale or duplicate fragments | higher |
| revoke latency | accepted interruption to unplayed-suffix cancellation | lower |
| post-interruption leakage | words played after their increment was revocable | zero within actuator limit |
| provisional waste | generated increments purged before commitment | measured; not minimized blindly |
| filler ratio | low-information early words used only to mask latency | low |
| prefix handoff failure | deep continuation contradicts or repeats committed prefix | toward zero |
| speech-memory timestamp error | playback time minus persisted observation time | lower |
| self-echo false suppression | user speech suppressed by text that never played | zero |
| model calls per audible second | formulation compute cost | lower at equal quality |
| dual-path ablation delta | verified gain from two producers over one | positive |

## First acceptance scenario

Use a fixed addressed utterance and a worker fixture that returns one coherent
50-word response.

1. The response becomes at least three provisional increments under one stream.
2. Tick one commits only increment one.
3. Queue acceptance does not create spoken memory.
4. A playback-completed event creates one fragment memory with its actual time.
5. Tick two observes that fragment and commits only increment two.
6. A new user utterance arrives during increment two.
7. The live path revokes every later increment and fences both producer paths.
8. No revoked text reaches playback, episodic memory or self-echo suppression.
9. A repair stream starts from the exact words that actually played.
10. Restarting any process at every boundary produces neither duplicate audio nor
    a lost committed fragment.

Run a second fixture without interruption. Its concatenated completed fragments
must equal the normalized planned utterance, its social outcome window must
belong to the whole stream rather than each fragment, and every fragment must be
causally visible to the tick that selected the next one.

## What not to build

- Do not split a completed answer into chunks and enqueue them all at once. That
  changes packaging, not cognition.
- Do not treat token streaming from a static response as concurrent streaming.
- Do not let an LLM control SPEAK/LISTEN state, interruption, cursor advancement
  or terminal status.
- Do not store the full planned answer as if it were already inner speech or
  episodic action.
- Do not use the five-second scheduler as the audible gap between fragments.
- Do not copy a 160-ms or 260-390-ms cadence without measuring whether the local
  stack can preserve causal ordering at that rate.
- Do not reward filler, engagement or uninterrupted floor-holding.
- Do not make tool actions inherit speech authority merely because they share a
  clock.

The smallest honest implementation is a durable stream, a deterministic
one-increment commit rule, and playback-grounded observation. Everything else—
native audio tokens, 160-ms clocks, speculative dual paths and synchronized tool
calling—can then be added without having to lie about when a sentence existed.
