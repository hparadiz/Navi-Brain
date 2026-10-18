# 12 — Global Neuronal Workspace

- **Paper**: Mashour, Roelfsema, Changeux & Dehaene, "Conscious Processing and the Global Neuronal Workspace Hypothesis"
- **Source**: Neuron 105(5), 776–792, 2020, DOI 10.1016/j.neuron.2020.01.026
- **Local file**: `assets/papers/12-mashour-et-al-2020-global-neuronal-workspace.pdf`

## Core idea

The global neuronal workspace (GNW) is the hypothesis that content becomes
conscious when it stops living in one specialized processor and gets broadcast,
via a core of densely interconnected long-range neurons (a "router" centered on
prefrontal and parietal hubs), to all the other specialized processors. The
broadcast is not gradual — it is *ignition*: a sudden (~200–300 ms), coherent,
self-sustained, reverberant activation of a workspace subset, with everything
else inhibited. Ignition is the transition into the attended working-memory
state and the threshold for conscious access (mapped onto signal detection
theory in van Vugt et al.: threshold = ignition in PFC, sensitivity = how well
the signal propagated to it, false alarms = spontaneous ignition when the
system sits near threshold). The review's evidence base: late global ignition
tracks conscious trials across modalities; anesthesia, sleep, and disorders of
consciousness all specifically kill long-range fronto-parietal broadcasting
while preserving early local sensory processing; spontaneous resting-state
ignition is the proposed source of endogenous mental diversity. The authors
also sketch self-consciousness as error awareness (fast local error signal vs
late conscious ignition) and working memory as global *versus* "activity-silent"
states. They position GNW against IIT, recurrent processing, and higher-order
thought theories.

## Relevance to Navi-Brain

This is the architectural backbone paper. Navi-Brain already inherited it:
`docs/literature-synthesis.md` states the core conclusion in GNW terms — a
limited-capacity workspace that *selects* among specialist proposals and whose
winning broadcast must have measurable causal effects downstream, plus the
explicit warning that "a shared message log that consumers do not reliably use
is not a functional global workspace." The workspace scheduler sits at
implementation-order item 4, and `docs/longitudinal-cognition-metrics.md`
(metric family 3) already defines the GNW-aligned tests: broadcast uptake,
content-specific causal effect (matched vs sham broadcast), workspace ablation
delta, specialist unique contribution. So the paper's *operational contract* is
already in the docs. What it adds on top is the mechanism vocabulary: ignition
as a thresholded transition, broadcast as a causal act, and the activity-silent
vs globally-active distinction — all of which make the design testable.

## Cross-reference to the existing system

- **Already present**:
  - Specialist-ish proposal flow: `src/Core/FreeModelWorker.php` runs bounded,
    tool-denied workers whose schema-valid JSON gets a `curator_verdict`
    (accept/reject) — this is the "specialists propose, workspace adjudicates"
    loop in embryo.
  - The event log (`src/Model/Event.php`), typed and provenance-carried, is the
    substrate a broadcast would ride on.
  - Endogenous diversity source: `brain_daydream` /
    `src/Model/ThoughtArtifact.php` is a stochastic generator of proposed
    thoughts with no external stimulus — a decent analog of the paper's
    spontaneous resting-state ignition, minus the threshold and minus the
    broadcast.
  - Memory tiers (working/episodic/semantic/procedural) plus consolidation with
    lineage inheritance give the paper's "items move between activation states"
    a concrete home.
  - Level vs content maps onto the two cognitive layers: `pulse_30s` low brain
    (integrity/lease/need, no model) is a *level* operation; `reflect_5m` /
    consolidation / sleep are *content* operations. The paper's finding that
    level disruption preserves local processing but kills broadcast is a good
    lens on what `sleep_daily` should and should not do.
  - Appraisal/needs as routing signals matches the paper's treatment of value
    systems as workspace *processors*, not feelings.

- **Partially present**:
  - Selection without ignition. Workers propose and a curator accepts/rejects,
    but there is no competition among candidate contents and no threshold
    crossing that turns a candidate into the single broadcast item. Everything
    is a linear, roughly sequential command path in
    `src/Core/ExecutiveCore.php` (~4000 lines) — no non-linear selection step.
  - "Activity-silent" memory exists conceptually: dormant items in semantic
    tier are reinstated by ranked lexical retrieval (`memory:search`), but
    there is no distinction between an *active* representation (available for
    manipulation) and a merely storable one, and no decay/refresh cycle for
    weak content.
  - Daydreams propose thoughts but there is no ignition gate deciding when a
    thought artifact gets promoted into the active stream versus left to decay.

- **Absent / open design**:
  - The workspace scheduler itself. Not implemented anywhere in `src/` — only
    documented. `runDueHeartbeats` / the long-running `bin/navi-brain-heartbeat`
    is a rhythm+lease scheduler, not a cognitive selection-and-broadcast
    scheduler. Grep for `workspace|broadcast|ignition` across `src/` and `bin/`
    returns nothing.
  - No broadcast primitive. Nothing appends a record saying "this content was
    selected and is now globally available," and no downstream processor
    *consumes* such a record. The broadcast-uptake metrics in
    `longitudinal-cognition-metrics.md` are designed but unmeasured.
  - Cognitive threads (`src/Model/CognitiveThread.php`, `ThreadStep.php`,
    `ExecutiveInterrupt.php`) remain "designed but not wired into a running
    loop." The thread is the natural ignition vehicle — activation = sudden
    commitment to a content, each step = a broadcast record, next-invocation
    capsule = what was broadcast — but right now they only record.
  - No error-awareness loop. `ActionTrace` records expected-vs-observed match
    and repair text, but there is no gate requiring a repair to reach the
    "ignited"/committed state before it is allowed to change future control —
    which is exactly the paper's finding that early local error signals don't
    predict next-trial behavior; only late sustained ignition does.

## Concrete implications for implementation

1. Add a workspace selection step to the high-brain loop in `ExecutiveCore`:
   gather due candidates (active intentions, unresolved discrepancies,
   accepted worker proposals, daydreams) and select ONE per cycle by a bounded
   arbitration rule, instead of only "one fenced work item" with no
   competition. Keep single-flight and budgets; the change is *which* item
   wins and that its selection is recorded.
2. Implement the broadcast primitive as a schema addition: a
   `broadcast_id` field on the event/action records, so downstream processors
   (consolidation, intention update, next action) can be tagged with which
   broadcast their content came from. Then measure the family-3 metrics —
   broadcast uptake and content-specific causal effect (matched vs sham) —
   that are already defined in `docs/longitudinal-cognition-metrics.md`.
3. Gate consolidation on an ignition-style threshold. Currently
   `memory:consolidate` promotes episode-derived takeaways; add a
   confidence/evidence floor so weak, sub-threshold content decays (the
   activity-silent analog) instead of silently promoting. Semantics stays
   evidence-and-confidence-backed, just graded.
4. Wire cognitive threads as the ignition vehicle: thread activation commits a
   content, `ThreadStep` carries what was selected/broadcast, and the
   fresh-context capsule in `docs/ongoing-cognition.md` ("Every invocation is
   a temporary workspace for advancing a thread") becomes the recipient of the
   broadcast. This gives the causal broadcast path a concrete, budgeted home.
5. Make discrepancy repair conditional: only repairs that reach a committed
   state change subsequent action selection, and instrument whether they
   actually do (the metacognition test from the synthesis — explanation that
   changes future processing vs after-the-fact prose).
6. Use the paper's adversarial-collaboration model for evaluation: the GNW-vs-IIT
   same-dataset fight says metrics should discriminate, not bless. The workspace
   ablation delta + sham-broadcast controls are already the right shape — run
   them in `docs/experiments/` when the scheduler lands.

## Risks / tensions

- This is a review written largely by the theory's founders; Dehaene holds
  patent EP 2 983 586 B1 on monitoring consciousness. Not a neutral
  adjudication, and the causal evidence (optogenetic/thalamic-stimulation
  studies) is a small minority of what's cited. The GNW-vs-IIT dispute is
  unresolved, so metrics must not quietly crown one theory.
- Implementing a "broadcast" that is really just logging would be exactly the
  "consciousness theater" the synthesis already names. The broadcast is only
  real if uptake is measured with sham controls.
- The paper's no-felt-emotion discipline is friendly to Navi-Brain's invariants:
  value systems are processors, appraisal is routing, and the review explicitly
  refuses a scalar consciousness score (it criticizes Φ's computability).
  Consistent with "no consciousness score" and "appraisal is not felt emotion."
- Tension with the mesh hypothesis: GNW is a *centralized* bottleneck
  (bow-tie core), while Navi-Brain's mesh is decentralized specialists. The
  synthesis already flags the central workspace as a single point of failure;
  take the broadcast contract from GNW, not the one-core topology.
- No autonomous-shell-execution invariant is untouched here; the threat is
  subtler — a selection step could become the single choke point that
  silently drops authorized work. Budgets, fencing tokens, and user authority
  must gate the *selection*, not just the workers.

## Bottom line

Partially adopt. The GNW contract (select → broadcast → measure causal uptake)
is already the documented architecture; this paper supplies the missing
mechanism details — ignition as a thresholded transition, broadcast as a
causal act, activity-silent vs active memory states — that turn it from a
sentence in the docs into a buildable and testable step. The single most
important consequence: Navi-Brain has the event log, the workers, and the
curator verdicts, but nothing that *selects one content and broadcasts it to
consumers that demonstrably use it* — so the workspace, by the paper's own
definition and the synthesis's own warning, is not yet functional. Building
the selection-and-broadcast step with uptake instrumentation is the move.
