# Attention architecture

Attention is a deterministic filter over whole, typed facts. It is not a model
prompting technique, a transcript summarizer, retrieval by keyword, or an LLM
inside the cognitive loop.

## Causal order

At each boundary, the filter reads the historical fact pool using one immutable
personality-weight snapshot and emits a bounded summary. Current sensory and
control updates are placed beside that summary. Sparse precomputed situation
events may supplement a sensory packet when a visible causal action is not
represented by its ordinary object and motion assertions. They are complete
current evidence on arrival and become typed historical facts afterward.
Exactly one model call decides
present cognition, compact typed belief deltas, speech, and due answers. The
deltas use fixed prose slots rather than structured model context. They enter
stable fact keys after the call; the model neither selects them for the next
summary nor protects them from forgetting. Actually committed speech becomes a
typed completed-action ledger for the next boundary. Each commitment updates
the same protected fact; the ledger keeps at most 12 fragments and 96 spoken
words, retiring the oldest entries deterministically instead of accumulating
one protected fact per utterance or bypassing the Attention span.
Weight adjustment, when added, runs asynchronously and can only publish a later
immutable snapshot.

One run-declared probe may checkpoint the cognition's own forecast answer as a
past prediction commitment. The deterministic layer stores at most four such
commitments and 128 words in one ordinary, decaying Attention fact. A later
active prediction cannot rewrite that history after seeing the outcome. The
record is neither an oracle nor a second inference pass: it is the single
tick-call's own already-produced answer, retained so a later tick can compare
forecast with evidence.

## Causal write admission and state lifecycles

Attention decides which facts are accessible; it does not make every proposed
cognitive rewrite true. Sparse situation events may carry internal grounding
metadata alongside the prose shown to cognition. That metadata can anchor a
fixed cognition slot to externally established evidence until a later event
supersedes it. The deterministic write gate rejects a conflicting model delta,
records both values in telemetry, and leaves the grounded Attention fact
intact. Grounding metadata is never rendered into model context.

This prevents later occlusion from turning established history back into
Unknown, and prevents motion near a fixed lens from silently becoming camera
operation. It does not make grounded facts permanently visible: they remain
ordinary facts inside the bounded Attention span and may still be omitted by
the filter. The gate preserves causal consistency without becoming a hidden
retrieval path.

Stable identity and grounded history differ from transient cognition. Active
needs and self affect have deterministic validity leases which exact repeated
prose cannot renew. A newly changed prediction error invalidates unchanged
needs and affect that depended on the failed expectation. The model can replace
them with genuinely revised values in the same call; otherwise they retire
before the next Attention boundary.

## Writable cognition and stable roles

The cognition call can replace or leave unchanged ten bounded slots:
situation, camera operator, camera support, camera setup actor, active
prediction, prediction error, self affect, active needs, person model, and
uncertainty. Stable keys make a replacement retire its predecessor, so current
camera control cannot coexist indefinitely with an obsolete operator belief.
An omitted slot is a no-op: it does not refresh recency, raise salience, or
resurrect a fact that Attention omitted. Exact repeated replacement text is also
a no-op. Slot values are replaced by new prose beliefs; a labeled placeholder
is also a no-op so it cannot erase a retained belief accidentally. The
model-facing protocol says No changes when a tick proposes no cognitive delta
and No questions when no probe is due. It does not overload the word None as
both empty structure and an epistemic claim about absence.

Each sparse slot replacement is bounded at 64 words. The bound prevents a
single belief from swallowing the Attention span without rejecting an ordinary
one-sentence scene model at an event boundary.

Camera agency uses three separate roles and three separate epistemic outcomes.
The current operator actively controls framing, the support physically holds
the camera, and the setup actor arranged it earlier. Unknown means the evidence
does not distinguish possibilities; no current operator means recording is
passive; unsupported requires positive evidence of no physical support. Drift
alone leaves all three roles unknown. A transition to a passive perch requires
prior coupled control, a newly fixed view, and independent movement by the
previously coupled person. Current handling may support an operator or handheld
support belief, but it does not establish who performed historical setup until
an observed action links that person to placement or perching.

Linked role transitions are revised together. When a current fact explicitly
states that release or placement completed and identifies current operator,
physical support, and earlier setup actor, omission cannot preserve an obsolete
companion role under the guise of sparse state.

Bootstrap entries occupy those same stable keys and hold temporary current
values before evidence arrives. They are not immortal orientation paragraphs or
permanently true historical statements. The first applicable cognition delta
replaces them. Prediction error
therefore has a causal object: current evidence can conflict with an active
prediction or retained prediction commitment carried from an earlier tick, and
affect, needs, speech, or a revised person model can change in that same single
call.

## Universal control core

Some channels preserve the agent's ability to remain one acting process. They
are selected before personality-shaped content:

- the bounded recent completed-speech ledger and active speech playback buffer;
- motor control or shell control;
- live machine telemetry, including running processes, locks, failures, and
  resource pressure;
- the active goal or task commitment;
- safety constraints and inhibition.

These channels are universal, although the salience of individual facts inside
them can vary. Stable control state uses stable fact keys and is retired when it
ceases to be active. A flood of perceptual facts cannot evict this class.
Completion is itself control state: remembered words are history, not an active
motor instruction. The bounded speech motor admits at most one ordinary
fragment at a time. It also inhibits a proposed fragment that exactly echoes
recent received language or a newly heard phonetic fragment. A separate
motor-level ledger retains at most 64 normalized external phrases, so delayed
parroting is blocked without copying that ledger into cognitive Attention.
Busy-buffer and echo proposals remain visible in telemetry but never enter the
committed transcript.

## Executive and elective attention

The next class carries the self-model, models of other agents, prediction error,
unresolved uncertainty, social messages, and event boundaries. General observations,
interpretations, objects, and audio follow. Incidental colors and frame
statistics are last. Frozen weights, recency, habituation, novelty, and
intrinsic salience rank whole facts within a class.

Computed exposure measurements are complete current sensory evidence on their
arrival tick, but historical copies use the low-priority frame channel.
Repeated exposure statistics therefore cannot masquerade as hundreds of
distinct semantic interpretations and fill half the Attention span.

Upstream sources type facts explicitly. The filter never infers a type from
words in the fact. New domains can admit mechanism, appearance, aesthetic,
language, social, spatial, or other elective channels as long as the frozen
personality snapshot declares their weights.

## Personality declaration study

A separate discovery study can ask many varied personalities the same two
questions before presenting a situation:

What do you care about?

What naturally catches your attention?

The open responses generate candidate attentional dimensions. They do not
directly control a live filter. Preserve the declarations, code recurring
dimensions, review and merge the ontology, then freeze it before behavioral
testing. Each personality is compiled once into a deterministic vector over
typed elective channels. The runtime consumes the vector, not the prose.

Social messages are ordinary events rather than probes. They may increase social
salience, be ignored, or influence speech, but they never require an answer.
They enter the historical pool only after delivery and can later be forgotten.
Scenario authors must not let a message reveal an entity or outcome before the
sensory stream establishes it unless that leakage is the condition being tested.

The personality sample is a source of hypotheses, not a claim about human
groups. Interests should belong to individuals rather than being inferred from
gender, occupation, culture, or another demographic label. Profiles may
deliberately include an artist who prioritizes layers and edges, an engineer who
prioritizes mechanisms and failure modes, or someone who notices hair and
makeup, without treating those associations as demographic laws.

## Capacity search

The high reference and ceiling is 1,000 words. Otherwise matched runs lower only the
historical summary bound. Every capacity is retained, including failed runs.
After the first pass-to-fail interval appears, later runs refine that interval.
The main outcome is the smallest bound that preserves control continuity,
required facts, epistemic accuracy, theory-of-mind continuity, surprise and
revision, and coherent incremental speech.
