# Architecture gaps: Theory of Mind and intention

Written 2026-08-10. Reworked against the live code, the pre-migration version-11
database, and records #71-#83 in `analysis/findings/claude-opus-5/` as those
findings were produced. Extended after an end-to-end audit of intention,
working memory, procedural memory, action selection, cognitive threads,
prediction, appraisal, needs, worker scheduling and the live version-14 store.
Paper summaries are evidence inputs, not code truth; every proposed seam below
was checked against the implementation.

Theory-of-Mind implementation update, 2026-08-10: that complete first organ is
now implemented in schema version 14. Its phases 0-5 are wired into the sensory
and executive loops; phase 7 has sealed-score replay, baseline, calibration,
correction, integrity and functional-uptake reports. Phase 6 remains deliberately
unperformed because it is an optional new perception grant, not missing code.
Fresh databases and an isolated backup of the version-11 store migrate and
complete the vertical slice. The live store migrated transactionally to version
14 on 2026-08-10, passed `integrity_check`, and has a private verified online
backup at `var/backups/navi-brain-post-v14-20260810-204012.sqlite`.

Live wiring update: sensory maintenance closes speech windows and queues their
descriptor reflections without making an LLM call in the perception process.
Two fenced PHP consumers share two independent 4096-token llama.cpp slots, so
slow background inference no longer stops sampling. Pausing or revoking a
source now expires its forward predictions, dependent other-agent hypotheses
and forecasts, and the shared `other_agent_state` projection; stale source data
therefore cannot keep influencing arbitration after its authority disappears.
The worker JSON grammar is decode-constrained without large bounded string
repetitions, which current llama.cpp rejects before generation.

Intention audit update, 2026-08-10: intention storage and a thin persistence loop
exist, but intention is not yet a causal executive faculty. The live store had
12 active intentions and one released intention; all 23 decision cycles had
selected intention 5, with 6 completed cycles, 14 failed, 2 at impasse and 1
waiting. The event ledger contained 13 `intention.created`, 15
`intention.advanced` and 1 `intention.released` event, but no focus,
reconsideration or completion transition. The audit and implementation plan are
recorded below as a separate active track. No intention implementation is
claimed by this update.

Speech-timing design update: [Tick-bounded incremental speech](tick-bounded-speech.md)
specifies the separate active track for composing an utterance across cognitive
ticks. It replaces the assumption that one model result is one instantaneous
spoken action with a durable provisional stream, deterministic one-increment
commit, full-duplex interruption and playback-grounded observation.

## Executive state

The first complete CoALA-shaped loop exists in code:

```text
perception -> bounded working memory -> long-term retrieval
           -> reason/propose -> evaluate/select
           -> grounding or evidence-sourced learning
           -> verification -> procedural adaptation -> repeat
```

Working memory, procedural memory, action selection, intention storage and thin
lifecycle hooks, the decision state machine, and a one-step sensory forward
model are wired. They remain deliberately thin so they can be ablated and
improved without dismantling the whole loop. In particular, the existence of an
`Intention` row and a repeated decision cycle must not be read as evidence that
intention currently governs adoption, arbitration, progress or rational release.

The live database is schema version 14 and contains the forward-model,
working-memory, procedure, decision-cycle, and other-agent tables. The
pre-other-model metric snapshot was captured before the first other-agent cycle.
The sensory and heartbeat services still require a process restart whenever
their loaded PHP code predates a deployed slice.

The other-agent organ now exists in code. It also contains the older social path,
which learned from measurements that its own state could confound: descriptor
means no longer affect standing or speech prompts, and social observations now
carry an explicit observability weight while raw engagement remains non-reward
audit data.

## What the code actually observes

The literature summaries repeatedly assume that Navi can follow window titles
and user commands. The code does not currently provide either signal. Two
specific mistakes are worth naming because they are easy to repeat:
`DesktopAwareness.php:117`'s `active_window` is a Spectacle capture-scope flag
on the privacy-gated `desktop_look` path, not a window-title reader; and
`CommandSense` returns output from commands **Navi** chose to run, which is her
epistemic action rather than a view of his. The findings in
`analysis/findings/claude-opus-5/` originally over-claimed both and have been
corrected against this table; where the two disagree in future, this table is
the authority.

| Channel | What exists now | What it does **not** establish |
| --- | --- | --- |
| `pet_hearing` | transcript text, stream density, run length, gaps, meeting-like context, replies and vocal reactions | that a line was heard, understood, or addressed to Navi unless the hearing sense accepts it |
| `desktop_presence` / `input_activity` | active versus away within a two-minute KIdleTime window | exact idle time, attention, project, goal, or what is visible |
| `conversation_activity` | age of the newest write below `.claude/projects` | conversation content or which task caused the write |
| `shell_activity` | age of shell-history writes | the command, its target, result, or whether it was intentional |
| `terminal_activity` | age of the newest PTY write | which terminal, command, project, or outcome |
| `process_activity` | owned-process count and age of the newest process | process identity as a user action or its purpose |
| `audio_playback` / hearing stream | playback and speech context | reliable audibility of a particular utterance |
| `machine_inspection` / `CommandSense` | output from a command **Navi chose** and the observe-only daemon ran | the user's command trajectory; this is Navi's epistemic action, not his |
| active-window identity | unavailable; `bin/hearing_context.php` emits three `active_window_* = unavailable` fields and `bin/navi-brain-senses` does not ingest them | window-title trajectories, dwell by application, or project switching |

The live database confirms the passive substrate is not empty: it currently has
hundreds of readings each for presence and coarse activity channels. Those are
enough for an attention/reachability model and a subintentional prediction
baseline. They are not enough to infer an open-ended project goal honestly.

Any later desktop-context or command-context source is a new perception grant.
`SensoryCortex::authorizeSource()` correctly requires user or developer
authority, so the Theory-of-Mind implementation must not smuggle richer
telemetry in as an internal refactor.

## The pre-implementation implicit user model

Before the completed Theory-of-Mind slice, there was no canonical other-agent
state, but four components acted as if there were one:

1. `SocialFeedback::closeWindows()` maps a reply or reaction within 120 seconds
   to `engagement`, treating silence as zero when presence appears true.
2. `descriptorStats()` averages engagement under free-form descriptions of
   Navi's own utterances.
3. `ExecutiveCore::appraiseNow()` averages those descriptor means into social
   standing, including rows that have not reached the descriptor sample floor.
4. `composeSelfPresence()` gives the descriptor table to the speech worker and
   asks it to prefer shapes that “led somewhere.”

That is a feedback controller around a confounded proxy. The same utterance can
receive opposite scores depending on whether the user was concentrating, in a
meeting, away, or already switching tasks. Records #71-#73 show why belief,
goal, observation and action cost are entangled; a reply timer cannot separate
them.

The “inconclusive” safeguard now persists explicitly: the model and database
accept that status, raw engagement remains non-null, and observability weight is
the gate that keeps absence from becoming negative evidence. The frozen
pre-other-model snapshot contains 22 open windows, 46 observed outcomes, and 3
reflected outcomes across 2 descriptors. Reflective descriptor work is queued;
it no longer calls the local model from inside the sensory daemon.

The other absent seams at that baseline were equally concrete:

- `ForwardModel` predicts source payloads with numeric extrapolation or
  categorical persistence. It has no conditional prediction of behaviour under
  a goal or belief hypothesis.
- `WorkingMemory::SHARED_ROLES` has no other-agent state.
- `DecisionStateMachine::start()` observes sense events and workspace roles, but
  no model of another agent.
- `ActionSelector` sees only `user_present`; it cannot distinguish reachable,
  occupied, uncertain, or inconsistent evidence.

## What the Theory-of-Mind literature changes

The useful consensus across records #71-#80 is narrower and more implementable
than “put an LLM in charge of mind-reading.”

### Representation

- Infer action by inverting a forward model, integrate a trajectory rather than
  the latest cue, and remain revisable when the apparent goal changes (#71).
- Keep the other agent's observation and belief state separate from world truth;
  assuming `belief = world` has approximately zero evidentiary value in the
  strongest lesion study (#72-#73).
- Split a durable, evidence-backed **frame** from a volatile current **state**;
  keep reasoning depth at one (#74).
- Treat beliefs, goals, costs, observations and errors as coupled. A partial
  model may corrupt the variables it does contain, so uncertainty and abstention
  must limit what a thin implementation may claim (#73).

### Inference and cost

- Start with a subintentional context-conditioned action distribution. A
  Dirichlet counts model is a legitimate baseline, requires no model call, and
  is strictly more honest than treating the person as sensor noise (#74).
- Model bounded planning and replanning rather than explaining every detour as
  random action noise. Re-infer on violated expectations and otherwise carry the
  previous state forward (#75).
- Keep action slips, plan failures, and goal confusion independently ablatable;
  deviation magnitude and irreversibility determine whether an error is worth
  acting on (#76).
- Let a model parse language into checked structure or estimate a narrow local
  conditional. Deterministic code performs scoring and composition. A direct LLM
  opinion about a person's mind is never evidence (#77-#78, #80).
- Maintain only three to five live hypotheses. Reward specific predictions that
  resolve correctly, expire weak hypotheses, and do not confuse predictive
  validation with truth (#79).
- Start each question with the smallest model and shortest history. Add goal,
  belief, observation, error level, or more history only while the answer is
  uncertain **and** recent predictions do not validate it. Always using a full
  POMDP was both slower and less accurate in AutoToM's ablation (#80).
- More context is not automatically a better decision. Long interaction history
  is identified as a core difficulty, and supplying both the payoff structure
  and the full history was *destructive* relative to either alone (#83). This is
  an independent performance argument for the bounded projection already
  required below: give the proposal worker the current bounded state, not the
  accumulated record of the person.

### Safety and authority

Two I-POMDP assumptions become implementation invariants (#74):

- **Model non-observability:** mental states are reported or inferred, never
  observed. The schema must make that provenance distinction impossible to
  erase.
- **Model non-manipulability:** another person's mental state is not a writable
  target or reward variable. The first use of the model is inference for
  suppression and evidence weighting, not timing an interruption to maximize a
  response.

Additional boundaries follow from the rest of the block:

- no nesting above depth one;
- every proposition separates actor from world, and carries order, knowledge
  access, and stated-versus-inferred provenance; access and provenance are
  derived by code rather than guessed by a model (#82);
- momentary goal, belief and attention hypotheses expire and never consolidate
  into semantic memory;
- explicit user correction immediately defeats an inferred hypothesis;
- no goal posterior alone authorizes assistance, correction, or speech;
- positive unprompted intervention remains unavailable until an independently
  authorized observation proves a hard irreversibility predicate. Existing
  signals cannot do that.

## Theory-of-Mind target architecture

The other-model is an event-driven prediction loop beside the sensory forward
model, not a prose field inside the speech prompt.

```text
authorized readings / utterance outcomes / explicit corrections
                           |
                           v
               AgentObservationProjector
                 (observable features only)
                           |
                           v
                     OtherModelCycle
  resolve sealed predictions -> update subintentional baseline
  -> recall/propose <= 3 hypotheses -> score/abstain
  -> publish current state -> seal next predictions
          |                    |                    |
          v                    v                    v
  bounded working memory   social evidence     audit + metrics
          |                weighting only
          v
  decision observations -> unprompted-speech suppression
```

### Persisted cycle

Every relevant observation starts one fenced cycle with explicit states:

```text
observe
  -> establish_observability
  -> resolve_prior_predictions
  -> update_subintentional_model
  -> infer_minimal_model
  -> validate_forward_backward
  -> select_or_abstain
  -> publish_current_state
  -> seal_next_predictions
  -> complete
```

This ordering keeps evaluation causal: a prediction is written before the event
that scores it. A later cycle resolves it; no hindsight may rewrite the sealed
distribution. Unexpected observations trigger model reconsideration. Expected
observations do not spend a model call.

`no_rational_interpretation` is a normal `select_or_abstain` result, not a
failure. It means the current hypothesis set does not explain the observation
well enough to influence behavior.

### Storage boundary

Use dedicated records rather than overloading self-model or sensory tables:

| Record | Lifetime | Purpose |
| --- | --- | --- |
| `OtherAgentFrameFact` | durable, revisable | one minimal reported or inferred proposition about the authorized actor; every row carries evidence, confidence, knowledge access, representation and correction state |
| `OtherModelHypothesis` | expiring | one depth-one attention, goal, belief, plan, or error proposition with prior, posterior/value, validity interval, knowledge access, representation, provenance and correction state |
| `OtherModelPrediction` | immutable until resolution | a sealed prediction in a small observable feature grammar, its probability, deadline, eventual outcome, and proper score |
| `OtherModelCycle` | durable audit | trigger, state transitions, inputs, selected model depth, entropy, abstention reason, cost and timing |

The current top-three set is projected into `WorkingMemorySlot` under a new
shared role such as `other_agent_state`; working memory is the broadcast view,
not the canonical store. Expiry of the hypotheses expires the projection.

World facts remain ordinary evidence-backed `Memory` rows. Other-agent
propositions stay in the dedicated tables, where actor is fixed to the
authorized subject and order is fixed to one. That table boundary prevents “the
user believes the service is running” from consolidating into “the service is
running” without expanding every existing memory row in the first slice. If
attributed propositions later enter generic memory, `actor` and `order` must
become mandatory there first.

Do **not** add `hypothesis_id` directly to `ForwardPrediction`. That table's
contract requires one sensory source, one based-on reading, and one next reading.
A hypothesis-conditioned behavior prediction has different provenance and can
produce several competing forecasts for the same observation. Extract the
normalization and proper-scoring arithmetic into a small shared scorer, while
keeping the two ledgers distinct.

### Observation feature grammar

The first implementation may predict only values the current sources can later
resolve without another model:

- `present`, `away`, or `unknown`;
- conversation starts, continues, or ends;
- recent coarse shell, terminal, process, or conversation activity;
- reply, no reply, reaction, and response latency after an utterance;
- an explicit reported correction or goal statement in accepted speech.

A prediction is a typed tuple such as source, field, operator, expected value,
and deadline. Free-text predictions are not scoreable and are rejected.

Project identity, file choice, command intent and irreversible user action are
outside this grammar until a separately authorized source makes them observable.

### Minimal model ladder

Each question grows through this ladder and stops at the first validated level:

1. **Reachability:** presence, audibility, meeting state and sensor reliability.
2. **Subintentional baseline:** Dirichlet counts over observable next actions,
   conditioned on reachability context.
3. **Attention/planning mode:** idle, available, occupied, conversing, or
   uncertain, with dwell and surprise-driven change.
4. **Goal hypothesis:** admitted from explicit language or grounded entities,
   kept in a top-three predictive set.
5. **Belief/observation hypothesis:** added only when goal-only predictions stay
   uncertain or inconsistent; never initialized as equal to Navi's world state.
6. **Error decomposition:** action slip, bounded-plan failure, temporary goal
   confusion, or genuine goal change.

There is no level seven. Recursive beliefs and full POMDP planning are excluded.

### LLM boundary

The local or free model may:

- parse an accepted direct utterance into one minimal candidate proposition;
- propose at most three grounded hypotheses when the deterministic baseline is
  demonstrably insufficient;
- estimate one constrained local conditional when no empirical count exists.

It may not select the posterior, write confidence into the canonical state,
declare a mental state observed, label knowledge access or stated-versus-inferred
representation, or directly choose an action. The executive derives actor,
knowledge access and representation from the accepted source record. Schema
validation, entity grounding, evidence attachment, arithmetic, prediction
resolution and behavioral gates stay deterministic.

`LocalModelClient` currently returns schema-constrained JSON but does not request
or expose token log probabilities. Therefore the plan must not pretend that
logprob-derived conditionals are already available. The first slice uses
empirical counts and checked categorical proposals; a later `completeChoice()`
path may expose constrained-token probabilities if the local backend supports
them.

## Theory-of-Mind implementation plan

The order below gets a complete vertical slice running early, then earns richer
mental variables through measured failures.

### Phase 0 — contain the current proxy loop

1. Repair `UtteranceOutcome` so `inconclusive` is a valid persisted status and
   absence can be represented without violating a non-null engagement column.
2. Stop `descriptorStats()` from affecting appraisal standing or the speech
   prompt until the evidence is conditioned and reaches a declared sample floor.
   Preserve existing rows for audit; do not reinterpret or delete them.
   Standing must hold at the neutral value `appraiseNow()` already uses when no
   descriptor rows exist (0.5) rather than being removed from the gauge set, so
   the containment is a one-line reversible change and affect keeps the same
   shape. Note that the current average also ignores the `established` flag
   `descriptorStats()` computes, so today a single-sample descriptor moves
   standing as much as an established one.
3. Add model non-observability, model non-manipulability, depth-one inference,
   expiry, and user correction to the README safety invariants.
4. Freeze the current live counts and social outcomes as the pre-ToM baseline.

**Exit:** confounded descriptor means cannot make Navi more likely to speak, and
an inconclusive response window closes without a persistence error.

### Phase 1 — schema and inspectable state machine

1. Add the four records above in one forward-only schema migration.
2. Implement `OtherModel` with every persisted transition, idempotent cycle
   triggers, expiration, and `no_rational_interpretation`.
3. Add read surfaces for current state, frame facts, live hypotheses,
   predictions, cycles and corrections. A user must be able to see and reject
   every claim about them.
4. Enforce actor=`primary_user`, order=1, and code-derived access and
   representation on every other-agent proposition. The worker schema cannot
   populate those fields.
5. Project the selected or abstaining state into bounded working memory and add
   it to decision-cycle observations.

**Exit:** an observation traverses the entire other-model cycle, publishes a
bounded state, seals predictions, and the next observation resolves them on an
isolated database.

### Phase 2 — subintentional baseline on existing sensors

1. Build `AgentObservationProjector` over hearing, presence and coarse activity.
2. Learn context-conditioned Dirichlet counts with explicit pseudocounts and no
   model calls.
3. Produce calibrated probabilities for reachability, reply/no-reply and coarse
   state changes.
4. Trigger reconsideration from precision-weighted surprise; otherwise retain
   the current state without computation.

**Exit:** the baseline beats categorical persistence and an unconditional counts
table on sealed next-event log loss, or remains installed only as the declared
null if it does not.

### Phase 3 — language-grounded hypotheses and forward validation

1. Add a bounded parser job for explicit goal, belief, constraint and correction
   statements in accepted direct speech. It emits only the minimal proposition;
   the executive attaches actor, access, representation and quotation-level
   provenance.
2. Reject candidates whose entities cannot be grounded in authorized evidence.
3. Maintain at most three live hypotheses; generate new ones only after baseline
   misprediction or explicit testimony.
4. Seal specific typed predictions per hypothesis and update value with a proper
   score and a specificity penalty. Do not validate a vague hypothesis merely
   because it predicted “continued activity.”
5. Require both predictive performance and forward/backward validity before a
   hypothesis may be broadcast as usable. Low entropy alone never validates it.
6. Score proposition extraction separately from proposition labeling. A correct
   label over the wrong or incomplete proposition set is still failure (#82).

**Exit:** at least one hypothesis gains and loses support through later observed
events; a wrong explicit hypothesis expires or is corrected; inconsistent
forward/backward pairs abstain.

### Phase 4 — wire the organ into the running whole

1. Give `ActionSelector` an `other_agent_state` input for **unprompted** speech.
   Its effect is monotone suppression: the ToM-aware speak score may be lower
   than the pre-ToM score, never higher. Addressed speech remains unconditional.
2. Replace `couldHaveLanded` with an evidence weight derived from reachability
   and observability, while retaining the raw response observables unchanged.
3. Keep engagement out of the reward path. Social outcomes measure what happened;
   they do not become an objective to maximize.
4. Include the bounded other-agent state in the main decision-cycle observation
   snapshot, with provenance and expiry visible to the proposal worker. This is
   context, not proof that the worker used it rationally.
5. Add an ablation switch that removes only the other-model projection while
   holding sensor input, model backend, prompts and compute budget constant.
6. Record the selected-action distribution with and without the other-model
   input. Functional uptake is the causal difference between those distributions,
   not the presence of a mental-state paragraph in a prompt (#83).

**Exit:** perception, other-model inference, working-memory broadcast, decision
observation, speech suppression, social evidence weighting, prediction
resolution and correction all run as one inspectable state machine.

### Phase 5 — adaptive depth and bounded-rational error modes

1. Replace the fixed 120-second interpretation horizon with minimal lookback that
   extends only while uncertainty and prediction failure justify it.
2. Estimate persistence and deviation magnitude from observable dwell/state
   changes where the source supports it.
3. Add goal change, action slip, plan failure and temporary goal confusion as
   independent likelihood terms and ablation switches.
4. Add belief/observation variables only to cases that fail the goal-only model.
5. Circuit-break repeated model growth into `no_rational_interpretation` rather
   than spending indefinitely.

**Exit:** simple cases stay on the cheap baseline; richer cases record exactly
which variable or history extension improved held-out prediction.

### Phase 6 — optional evidence expansion, separately authorized

Only after phases 0-5 work on existing evidence, decide whether a privacy-bounded
desktop-context source is worth its cost. If authorized, it must state exactly
what it reveals, sample no faster than needed, retain readings briefly, avoid
pixels, and expose a pause/revoke control. It is evaluated as a sensor addition,
not silently credited to the inference algorithm.

Observing user commands or imminent irreversible actions is a separate and more
sensitive grant than coarse desktop context. Until that exists, the model has no
positive-intervention path.

### Phase 7 — evaluation guardrails

The evaluation records #81-#83 are requirements, not a victory lap:

- seal predictions before outcomes and score proper probabilities rather than
  fluent explanations;
- compare against persistence, unconditional counts, context-conditioned counts,
  shuffled hypotheses, the same LLM without the wrapper, and the full model at
  matched evidence and compute;
- report action/plan/goal/belief categories separately;
- measure abstention coverage and the rate of `no_rational_interpretation`;
- test forward/backward validity: a mental state inferred from an action must,
  when run forward, assign substantial probability to that action;
- test abstractness on paired contexts with the same logical structure and
  different cost representation, such as quiet desk work versus a meeting;
- test coherence, abstractness and forward/backward consistency independently;
  a high score in one context is not evidence of a reusable model (#81);
- score proposition extraction separately from labeling, and audit actor/world,
  knowledge-access and reported/inferred distinctions. Ground truth comes from
  explicit user correction or unambiguous machine state, never an LLM judge
  labeling its own output (#82);
- measure **literal** prediction and **functional** causal uptake separately.
  For every informed decision, record the action distribution with the model and
  under the ablated null. Feeding a prediction into a prompt does not establish
  that it affected the decision rationally (#83);
- record the **three-way decomposition**, not just the two endpoints. #83's
  diagnostic value comes from a middle term: alongside what the model predicted
  and what Navi actually selected, log what the deterministic arbiter *would*
  have selected acting on that same published state. Two different faults then
  separate cleanly — a gap between the prediction and the counterfactual
  selection means the state was uninformative, while a gap between the
  counterfactual selection and the actual one means a good state failed to reach
  behavior. In #83 that middle term exposed a tenfold gap behind 96.7 per cent
  prediction accuracy, and it survived even when the true next action was
  supplied. Navi has no reward function, so this is a comparison of action
  distributions rather than regret; the decomposition is what transfers, not the
  metric;
- do not manufacture a reward from engagement. Functional change is measured,
  not optimized; outcome quality that is not mechanically verifiable remains a
  user judgement;
- include reactive cases where the user changes behavior in response to Navi,
  because stationary trajectories are the easy case and most of #71-#82 measure
  only that case;
- test user corrections, false-belief cases, sensor dropout, goal changes,
  backtracking, irrelevant narrative text and deliberately underspecified cases;
- never report benchmark accuracy as evidence of human-like Theory of Mind.

No project test infrastructure currently exists, so implementation verification
uses isolated SQLite databases, deterministic CLI fixtures and replay reports.
Creating a test suite remains a separate user decision under the repository's
test policy.

## Measures for the Theory-of-Mind track

| Measure | Baseline | Required direction |
| --- | --- | ---: |
| Sealed next-event log loss | categorical persistence / unconditional counts | lower |
| Brier score and calibration error | no calibrated other-agent probabilities | lower |
| Forward/backward validity | absent | higher |
| Cross-context structural transfer | absent | higher without hidden cost shifts |
| Proposition extraction recall | absent | higher; reported separately from label accuracy |
| Actor/access/representation violations | no typed proposition schema | zero |
| Abstention precision | absent | high; uncertainty must suppress claims |
| `no_rational_interpretation` rate | absent | measured by context, not minimized blindly |
| Unsupported mind-reading | current prose path has no canonical counter | toward zero |
| Social-evidence effective sample size | raw observed windows | reported after observability weighting |
| Unprompted speech under uncertain/occupied state | presence-only baseline | lower |
| Addressed-response rate and latency | answering already outranks selection | unchanged or better |
| Hypothesis correction latency | absent | lower |
| Model calls per other-model cycle | absent | zero for baseline; bounded on escalation |
| Other-model ablation delta | absent | better prediction or safer decisions, not warmer prose |
| Functional action-distribution delta | absent | non-zero only where the validated state should matter |
| Counterfactual-arbiter agreement | absent | high; actual selection should match what the published state implies |
| Literal-functional disconnect | absent | lower; good prediction must reach the deterministic arbiter |

Operational counters still matter—cycle completion, migration integrity, model
latency, expiry, prediction resolution and worker failures—but none establishes
Theory of Mind. A plausible interpretation is useful only when it predicts,
round-trips, calibrates, remains corrigible, and changes behavior without
overriding agency.

## Definition of a complete Theory-of-Mind implementation

This track is “implemented,” rather than partial, when all of the following are
true on an isolated upgraded copy before live startup:

1. every other-model transition and terminal state is reachable and persisted;
2. current authorized observations produce a subintentional distribution without
   a model call;
3. hypotheses can be proposed from explicit language, sealed into typed
   predictions, resolved by later observations, revised, expired and corrected;
4. actor, order, knowledge access and representation are code-derived and cannot
   be authored by the parser;
5. no usable hypothesis bypasses provenance, prediction validation,
   forward/backward validity, confidence thresholds, or depth one;
6. working memory and the main decision cycle receive only the bounded current
   projection;
7. unprompted speech can only be suppressed by the model, social evidence is
   observability-weighted, and answered speech is never suppressed;
8. literal prediction and functional action-distribution change are both
   reported, and the latter reaches behavior through deterministic arbitration
   rather than prompt compliance alone;
9. baseline, ablated and wrapped-model conditions can be replayed against the
   same frozen event stream with proper scores and raw examples;
10. a version-11 backup and the live store both migrate through the new schema,
    pass integrity checks, and complete the vertical slice.

That is a complete, falsifiable first organ. It is not a claim to infer arbitrary
human beliefs, solve interactive POMDPs, or possess human Theory of Mind. Those
grand phrases are where software goes to put on a velvet cape and dodge a
calibration plot.

## Intention track: from stored objective to causal commitment

### Scope and definition

This track asks a narrower question than whether Navi can name a goal: does a
committed objective causally organize attention, computation and action over
time, while remaining corrigible?

The relevant literature converges on a useful operational definition. An
intention is a persistent goal produced by deliberation, backed by a reason and
authority, which screens incompatible alternatives and is released when it is
achieved, becomes impossible, or loses its supporting reason. Goal-driven
autonomy adds discrepancy-driven goal formulation and repair. Metareasoning adds
an explicit termination action and charges for computation. Global-workspace
and CoALA-style architectures add bounded competition for the next operation.

That yields the following invariants:

- an intention is a commitment state, not a prompt string, personality trait,
  need, affect, prediction or unverified thought;
- desires, needs, appraisals, discrepancies and model proposals may nominate a
  candidate, but none creates authority;
- explicit user requests and developer/system obligations may enter adoption
  with their existing authority, while agent-generated candidates remain within
  the effect ceiling of their authorized parent;
- persistence is the default after adoption, but blind persistence is a defect;
- achieved, impossible and supporting-reason-lapsed are distinct terminal
  conditions;
- an expected side effect is not automatically an intended outcome;
- every selected operation names the intention, subgoal or information deficit
  it is expected to advance;
- progress and completion require observed evidence, not fluent narration;
- one canonical ID-based agenda owns focus. Working memory, prompts and
  cognitive threads receive projections of it rather than inventing local
  copies.

The first implementation should begin with explicit user-authorized intentions
and discrepancy-derived subgoals. Endogenous desire formation is not required
to wire a complete state machine and must not be used as a shortcut around
authority.

### What exists and where causality breaks

The current implementation has useful pieces, but they do not yet compose into
an intention faculty:

| Capability | What exists | Architectural gap |
| --- | --- | --- |
| Representation | `Intention` stores title, reason, authority, status, next action, free-text success/release conditions, dependencies and parent | no adoption state, verifier specification, progress, budget, feasibility, reconsideration time, version or focus identity |
| Adoption | `ExecutiveCore::createIntention()` validates references and immediately inserts `active`; the CLI is its only caller | no candidate set, deliberation, conflict check or endogenous/user-request bridge; MCP exposes no intention mutation surface |
| Focus | self-presence stores the held intention **title** in `CognitiveThread.desired_outcome`; fallback chooses the least-recently-updated active row | title is not a canonical key; the speech thread accidentally owns global focus; selection has no inspectable rationale or switch hysteresis |
| Agenda | heartbeat rhythms and due cognitive threads are scheduled independently; `ActionSelector` scores answer/speak/look/think/consolidate | the selector is called from the self-presence speech gate, so most non-speech selections suppress speech rather than dispatch the selected operation; there is no global arbitration surface |
| Planning | `DecisionStateMachine` persists observe, retrieve, reason, propose, evaluate, select, execute, verify and adapt | every cycle starts with `memory.search`; terminal candidates are limited to `machine.look` and `memory.consolidate`; ask, wait, decompose, replan, suspend and stop are absent |
| Progress | actions and verification results are persisted | verified outcomes do not update intention progress, cost, confidence, stagnation or the next reconsideration decision |
| Reconsideration | an impasse records `reconsider_on_next_cycle=true`; focus release accepts achieved/impossible/reason-lapsed | the next cycle selects the same focus; only the achieved release path is called; action failure, prediction violation, dependency change and thread stagnation do not cause a real reconsideration transition |
| Completion | an hourly worker recalls prose and asks a model whether the free-text success condition is met | no typed verifier; the successful path stores `released` while emitting `intention.completed`; an incomplete model response may replace `next_action` with prose |
| Dependencies | dependency IDs and `parent_id` are validated at creation | dependencies do not gate eligibility or wake dependants; thread completion and blocking do not propagate to the parent intention |
| Procedures | adapters, compiled procedures and verification records exist | live reuse is primarily recall/annotation; the decision loop still performs the same search and no option-speedup is demonstrated |
| Other cognitive organs | working memory, appraisal, needs, forward prediction and the depth-one other-model all produce bounded state | those signals affect salience or speech suppression but do not nominate, rank, advance or reconsider intentions through one authority-preserving path |
| Measurement | cognitive-thread and other-model reports are extensive | there are no intention-coverage, starvation, progress, reconsideration, release-accuracy or commitment-violation metrics |

The live monopoly is diagnostic, not merely a tuning problem. Twelve intentions
were active while all 23 recorded decision cycles selected the same one. Six
cycles completed as cycles, but only a small subset produced a verified
successful terminal action; failures and impasses did not rotate or suspend the
focus. Meanwhile no event recorded why that focus was acquired or retained.

### Target control loop

```text
authorized request / discrepancy / due obligation / bounded need proposal
                              |
                              v
                       CandidateGoal
          authority -> support -> conflict -> feasibility
                              |
                       deliberate/adopt
                              v
                     committed Intention
                              |
                 deterministic global agenda
          hard gates -> scored value -> switch hysteresis
                              |
                              v
              plan or select one bounded operation
                              |
                   predict the postcondition
                              |
                    execute through adapter
                              |
                         verify evidence
                              |
          update progress, cost, confidence and procedure
                              |
          continue | wait | replan | suspend | complete
                 | impossible | release/withdraw
```

Candidate generation and commitment are separate. A direct user request may
arrive with user authority, but code still records what was adopted, why, under
which success and release semantics, and with what effect ceiling. A candidate
from a need, surprise or language model remains a proposal until a deterministic
authority and feasibility gate admits it.

### Lifecycle and storage boundary

Use one canonical lifecycle with distinct semantics:

```text
candidate -> active <-> waiting
                |
                +-> suspended -> active
                +-> replan ----> active
                +-> completed   (success evidence matched)
                +-> impossible  (required state cannot be reached)
                +-> released    (authority withdrawn or supporting reason lapsed)
```

`blocked` may remain as a compatibility state during migration, but new control
logic should distinguish a temporary wait, a deliberate suspension and a
demonstrated impossibility. `completed` must never be stored as `released`.

Keep the durable surface small:

| Record | Purpose |
| --- | --- |
| extended `Intention` | canonical current commitment: authority and backing references, typed success/release specifications, progress, budget, failure/stagnation counts, reconsideration time, status, parent and version |
| `AgendaState` | one fenced singleton containing the current intention ID, selected operation, acquisition time, reconsideration time and version; never a title hidden in a cognitive thread |
| `IntentionTransition` | append-only audit of proposal, adoption, focus, progress, replan, wait, suspension and terminal changes, including evidence and the agenda score components that caused them |

Existing `ActionExecution`, `DecisionCycle`, `ThreadStep`, `ForwardPrediction`
and `ProcedureRun` remain the detailed execution ledgers. The transition row
links to them rather than copying their prose. `WorkingMemory` receives bounded
`active_intention`, `current_plan` and `decision_basis` projections, but it is
not the canonical store.

Success and release specifications should use a small typed grammar before any
open-ended model judgement is allowed. Initial verifiers can cover:

- an action verification matched;
- a named record exists with required fields;
- a file exists or has an expected hash;
- all required child intentions completed;
- a dependency reached a declared state;
- explicit user acknowledgement or withdrawal;
- a bounded semantic condition requiring evidence plus user confirmation.

The final case is deliberately conservative. A model may summarize or propose
supporting evidence, but deterministic code owns the transition and records why
the evidence was sufficient.

### Global agenda and reconsideration

The agenda should first remove ineligible candidates using hard gates:

- active authority and an effect ceiling sufficient for the proposed operation;
- required dependencies complete or the current operation explicitly aimed at
  resolving one;
- no incompatible higher-authority commitment;
- remaining compute/action budget;
- an available safe adapter or a legitimate information-gathering action;
- no satisfied, impossible, withdrawn or expired terminal condition.

Eligible intentions receive an inspectable component vector, not an opaque
model-authored priority:

- urgency and due time;
- expected verified progress;
- expected information gain;
- number and value of dependants unlocked;
- user/developer value and commitment impact;
- verifiability;
- action cost and external risk;
- recent failure and stagnation;
- focus-switch cost.

The deterministic arbiter may combine those components into a score, but it
must persist the components and the incumbent/challenger comparison. Retain the
incumbent within a declared hysteresis margin so small score movements do not
cause focus thrash.

Reconsideration is event-driven, with a bounded watchdog as a backstop. Triggers
include:

- a violated action or forward prediction relevant to the current plan;
- verified success or a newly satisfied child/dependency;
- repeated model failure, impasse or no-progress cycles;
- expired budget, deadline or evidence;
- changed feasibility, belief or supporting reason;
- authority revocation or a higher-authority interrupt;
- a newly eligible intention whose advantage exceeds the switch margin.

A reconsideration transition must choose among continue, replan, wait, suspend,
switch, complete, impossible and release. Retrying the same cycle is a possible
result, not the definition of reconsideration.

### Action and model boundary

Separate executive control actions from effectful adapters. `ask`, `wait`,
`decompose`, `replan`, `suspend`, `switch` and `stop` mutate executive state;
`machine.look`, `memory.search`, `memory.consolidate` and future installed
adapters act on bounded resources. Both kinds are selected through the agenda,
but only the latter cross an effect boundary.

A model may:

- propose candidate goals, subgoals, plans and evidence links;
- estimate bounded likelihoods or expected progress where no deterministic
  statistic exists;
- explain a discrepancy or suggest a repair;
- summarize evidence for an open-ended success condition.

A model may not:

- grant authority or expand an effect ceiling;
- commit, release or complete an intention directly;
- write canonical progress, feasibility or confidence without observed evidence;
- bypass dependency, conflict, budget or verifier gates;
- turn another-agent hypotheses, engagement, needs or affect into obligations.

### Implementation plan

The order below prioritizes one runnable vertical state machine before richer
goal generation or learned scheduling.

#### Phase I0 — repair semantics and expose the monopoly

1. Fix automatic completion to store `completed`, reserving `released` for
   withdrawn authority or lapsed support.
2. Key held focus by intention ID and emit focus-acquired, retained, switched and
   released transitions.
3. Stop an incomplete completion opinion from silently replacing `next_action`;
   record it as a proposal requiring integration.
4. Add a report for active-intention coverage, focus dwell, cycle outcome and
   no-progress streak.

**Exit:** the current runtime explains why intention 5 owns focus, and completed,
impossible and released cannot be confused.

#### Phase I1 — canonical manager and migration

1. Extract `IntentionManager` from `ExecutiveCore`; it alone owns lifecycle
   transitions, focus, progress and reconsideration.
2. Add the minimal fields and two records above in one forward-only migration,
   preserving existing intentions and mapping legacy status conservatively.
3. Add optimistic version checks and fencing around agenda changes.
4. Publish bounded working-memory projections from canonical state.

**Exit:** one process can adopt and advance a commitment while stale workers are
unable to mutate its newer state.

#### Phase I2 — authority-preserving adoption

1. Add a candidate stage and deterministic admission checks.
2. Bridge explicit CLI/MCP requests into candidate intentions; expose list,
   inspect, propose, commit, correct and release operations.
3. Distinguish an observed statement about the user's goal from a request that
   Navi act. `OtherModel` goal hypotheses never auto-promote.
4. Permit discrepancies and needs to propose only bounded epistemic or repair
   subgoals under an authorized parent.

**Exit:** an explicit request traverses proposal, admission and adoption with
inspectable authority, while an inferred user goal remains non-authoritative.

#### Phase I3 — one global agenda

1. Move intention, due-thread, heartbeat and addressed-response competition
   behind one deterministic arbiter.
2. Apply hard gates, component scoring and focus hysteresis.
3. Refactor `ActionSelector` into this surface or reduce it to a clearly named
   speech policy; no selected action may be silently treated as “do not speak.”
4. Persist the eligible set, component vectors, selection and rejected reasons.

**Exit:** multiple intentions receive bounded opportunities, addressed speech
retains priority, and every focus switch or retention is replayable.

#### Phase I4 — causal pursuit and repair

1. Link every decision cycle and selected operation to an intention or subgoal
   predicate.
2. Feed verified action results, forward-model violations, impasses, worker
   failures and thread stagnation into progress and reconsideration.
3. Add executive actions for ask, wait, decompose, replan, suspend and stop.
4. Evaluate typed success/release specifications after relevant evidence rather
   than only on an hourly timer.

**Exit:** a failed plan can replan or suspend, a satisfied intention completes
from evidence, and a revoked intention cannot execute again.

#### Phase I5 — subgoals, dependencies and procedural chunks

1. Make `parent_id` and dependencies gate scheduling and wake dependants.
2. Add a generic `intention_pursuit` thread or equivalent executor; existing
   self-presence, epistemic and mind-stream threads remain specialists.
3. Propagate child completion, waiting and impossibility through declared parent
   semantics.
4. Execute eligible learned procedures through `ProcedureRun` and measure
   whether reuse reduces steps, latency or model calls.

**Exit:** a multi-step intention decomposes, waits on dependencies, resumes and
completes through verified child outcomes; a learned chunk demonstrates real
speedup.

#### Phase I6 — evaluation and bounded adaptation

1. Replay fixed multi-intention scenarios with the agenda, each ablated
   component and a matched plain-LLM baseline.
2. Measure starvation, switch regret, progress per compute, reconsideration and
   terminal accuracy rather than judging plan prose.
3. Add learned calibration only after deterministic traces expose enough
   examples. Learned weights may tune ranking inside declared bounds; they may
   not change authority or terminal semantics.

**Exit:** the intention controller beats oldest-row selection and matched plain
LLM prompting on verified progress and rational termination without increasing
authority violations.

No project test infrastructure currently exists. As with the Theory-of-Mind
track, the first verification surface is isolated SQLite fixtures, deterministic
CLI scenarios and replay reports unless the user separately approves a test
suite.

### Measures for this track

| Measure | Current baseline | Required direction |
| --- | --- | ---: |
| Active-intention cycle coverage | 1 of 12 active intentions selected across 23 cycles | higher when other intentions are eligible |
| Maximum eligible starvation time | unmeasured | bounded and lower |
| Focus dwell and switch rate | focus retention exists but is not audited | stable within hysteresis; no monopoly or thrash |
| Time to first verified progress | unmeasured | lower |
| Verified progress per model call/token/second | unmeasured | higher |
| Consecutive no-progress cycles | failures retry the same focus | bounded |
| Reconsideration latency after contradiction | absent | lower |
| Rational release accuracy | achieved/impossible/reason-lapsed paths incomplete | higher, reported separately by reason |
| Independently verified completion | prose completion opinion | toward all mechanically verifiable goals |
| Dependency-order violations | dependencies inert | zero |
| Actions after authority revocation | unmeasured | zero |
| Commitment incompatibility violations | unmeasured | zero |
| Subgoal propagation accuracy | absent | higher |
| Procedure option speedup | no demonstrated live execution benefit | positive with equal outcome quality |
| Agenda ablation delta | absent | better verified progress than oldest-row and prompt-only baselines |

### Definition of a complete first implementation

The intention track is complete enough to start integrated iteration when all
of the following hold on an isolated upgraded copy before live startup:

1. proposal, adoption, focus, progress, wait, replan, suspension, completion,
   impossibility and release are distinct persisted transitions;
2. one ID-based fenced agenda selects among all eligible intentions and records
   why the incumbent was kept or changed;
3. authority, dependencies, conflicts, effect ceilings, budgets and feasibility
   gate every adoption and operation;
4. every decision cycle and action names the predicate it is expected to advance
   and feeds verified results back into progress;
5. action failure, prediction violation, stagnation, changed support and
   revocation trigger bounded reconsideration;
6. deterministic evidence completes mechanically verifiable intentions, while
   ambiguous semantic goals require explicit evidence and user confirmation;
7. working memory, cognitive threads, procedural memory, appraisal, needs,
   perception and the other-model influence intention only through their
   declared bounded interfaces;
8. explicit user requests can enter through CLI and MCP, while reported or
   inferred user goals cannot silently become Navi commitments;
9. three simultaneous fixture goals—one achievable, one waiting on a dependency
   and one impossible—make progress and terminate for the correct distinct
   reasons without starvation or post-revocation execution;
10. replay and ablation report verified progress, compute cost, focus behavior,
    reconsideration, authority violations and terminal accuracy against the
    same event stream.

This is enough intention to make the cognitive organs behave as one inspectable
state machine. It is not a claim of human volition, consciousness or an
irreducible inner spark. Those are excellent campfire subjects and terrible
acceptance criteria.

## Cross-track open issues

- **Model pool:** one backend was available, two degraded, and five unavailable
  at the last measurement. The subintentional path must remain useful with zero
  model calls.
- **Local contention:** two 4096-token inference slots are consumed by two
  dedicated fenced workers. The heartbeat is scheduler-only, and addressed
  speech is a distinct priority work type so neither slot can make perception
  stop ticking. Other-model inference remains event-driven and escalates only
  on uncertainty or violated predictions.
- **Metacognition:** self-model facts describe capabilities more than the quality
  of current reasoning. Other-model calibration should feed a bounded caveat,
  not a claim that another person has been understood.
- **Runtime/version skew:** sensory, heartbeat and model-worker processes keep
  loaded PHP code. A deployed schema or control-loop change is not live until
  the affected supervised process restarts and reports the expected version.
