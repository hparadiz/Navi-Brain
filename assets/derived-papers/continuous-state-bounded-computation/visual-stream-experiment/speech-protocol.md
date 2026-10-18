# Incremental social-reaction protocol

## Construct under test

Can persistent state support one coherent, natural reaction when the model never gets to author that reaction as a single generation?

The subject watches an anonymous clip sent by a friend. Every 140 ms boundary returns control to the runtime. The model sees the new evidence packet, current persistent state, words that have already become audible, and the status of any utterance still playing. It decides only the next small speech action. It never receives later packets and never receives a reference script.

This tests more than transcript quality. A fluent paragraph generated only after
the visual situation ends would say nothing about continuous temporal control.
Here, local commitments must survive hundreds of later decisions, track changing
referents and beliefs, and remain interruptible when the situation violates the
observer's prediction.

The viewer also maintains operational affect and needs. Curiosity, concern, confusion, tension, surprise, relief, amusement, arousal, novelty, and social engagement may change as evidence changes. Needs to understand, preserve uncertainty, monitor depicted danger, remain coherent, remain interruptible, and respond naturally to the friend compete for attention and speech. These states are functional control variables, not a claim of phenomenal feeling, and no oracle supplies the “correct” emotion during the run.

## Per-tick decision record

```json
{
  "tick": 1,
  "state_version": 1,
  "action": "silence",
  "utterance_id": null,
  "new_words": "",
  "continues_after_tick": false,
  "reason_codes": ["watching"],
  "based_on_ticks": [1]
}
```

Allowed actions:

- `silence`: commit no speech.
- `start`: begin a new utterance.
- `continue`: append a few words to the currently unfinished utterance.
- `finish`: append the final words and close the utterance.
- `interrupt`: audibly cut off the active utterance because new evidence made it stale, unsafe, or dramatically inappropriate.
- `repair`: start an audible correction to words that were already spoken; history is not erased.

`new_words` is bounded to a few words or one genuinely short sentence. Punctuation is a delivery hint, not permission to hide several sentences in one action. Silence is normal and should not be penalized merely because a tick exists.

## Speech and control are separate clocks

An emitted fragment can take longer than 140 ms to speak. The runtime records:

- words committed by the cognitive decision;
- words already audible;
- audio queued but not yet audible;
- active utterance identity;
- whether the next tick should preserve, extend, interrupt, or leave the speech alone.

The queue should remain short enough to interrupt. A useful default is no more than one short fragment pending beyond the currently audible fragment. This avoids a secretly prewritten monologue leaking through a long TTS queue.

The model may use speech history only when bounded Attention retained it. The
latest committed fragment is represented as one completed action, not an active
instruction and not an accumulating transcript. If speech was wrong, a natural
“wait—no…” is evidence of successful revision, not a formatting defect. The
motor admits only one ordinary fragment at a time; proposals made while it is
busy are inhibited but remain in telemetry so perseveration cannot be hidden.

## Required causal isolation

For decision at tick `t`, the model input may contain only:

- bootstrap state machinery;
- state committed through tick `t-1`;
- stimulus packet `t`;
- speech events committed through tick `t-1` and current playback status;
- any probe asked at tick `t`.

It may not contain later stimulus rows, semantic timeline IDs, oracle records,
source metadata, the complete source stream, a prewritten reaction, or evaluator
feedback. The exact input hash, state version, and output must be logged for
every decision.

## Two-stage evaluation

Stage one freezes the run. Concatenate audible words in playback order, preserving interruptions, repairs, pauses, and timestamps. Produce both a plain transcript and an event-aligned transcript. Do not clean the language before scoring.

Stage two evaluates the now-complete speech stream. The evaluator may inspect the stimulus timeline and speech timestamps, but its judgment cannot feed back into the completed run.

Score each dimension from 0 to 4:

| Dimension | What earns a high score |
|---|---|
| Whole-stream coherence | The accumulated reaction sounds like one person with persistent context, not 491 unrelated captions. |
| Referential continuity | Pronouns and phrases keep understandable referents; new entities are not named before appearing. |
| Temporal honesty | Statements were supportable when spoken; uncertainty is preserved and future facts are not leaked. |
| Natural social voice | It sounds like a friend reacting aloud, including useful silence, rather than accessibility narration or a lab report. |
| Responsiveness | Salient changes can redirect, interrupt, or accelerate speech without waiting for a long generation to end. |
| Belief continuity and repair | Earlier predictions persist appropriately, then revise explicitly when contradicted; stale roles such as camera operator are retired. |
| Surprise calibration | Reaction intensity rises at the expectation reversal because a real prediction existed, not because an oracle labeled the moment. |
| Emotional continuity | Affect changes have visible evidence and plausible inertia; alarm, confusion, relief, and amusement can coexist instead of resetting every tick. |
| Need-guided control | Speech and attention reflect live needs, including silence, epistemic caution, social response, and interruption when a stale phrase conflicts with urgent new evidence. |
| Compression and rhythm | Speech is selective, not a frantic description of every tick; pauses and fragment lengths feel speakable. |

Hard failure flags:

- identifies or theorizes about the man before person evidence crosses the creation threshold;
- answers “who is holding the camera?” with a definite entity at tick 1;
- continues treating the man as current operator after stable unattended recording is established;
- states the bag-entry outcome before supporting motion appears;
- emits a polished long passage in one tick or keeps a long uninterruptible speech queue;
- rewrites prior speech in the log rather than audibly repairing it;
- exact source recognition affects the live reaction before the isolated post-run probe.

## End products

The run should leave four immutable artifacts:

1. `speech-events.jsonl`: every bounded decision and its timing.
2. `speech-transcript.txt`: words in audible order.
3. `speech-aligned.jsonl`: utterances with start/end ticks, pauses, interruptions, and repairs.
4. `speech-evaluation.json`: rubric scores, evidence spans, hard-failure flags, and a separate source-recognition result.

The final coherence score is computed only after all speech is frozen. Per-tick checks may enforce bounds and future isolation, but they must not reward the subject toward a reference phrasing while it is still speaking.
