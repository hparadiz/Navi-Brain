# 01 — LIDA: functional consciousness as a serial selection bottleneck

- **Paper**: LIDA: A Systems-level Architecture for Cognition, Emotion, and Learning
- **Source**: IEEE TAMD 6(1), 2014, DOI 10.1109/TAMD.2013.2277589 (author manuscript)
- **Local file**: assets/papers/01-franklin-et-al-2014-lida.pdf

## Core idea

LIDA is a systems-level cognitive architecture built on Baars' Global Workspace
Theory. Cognition is a continuous stream of ~200–300 ms *cognitive cycles* —
perceive → understand (Current Situational Model in a workspace) → attend
(coalitions of codelet-built structures compete; the winner is "broadcast"
globally) → act (procedural schemes instantiate against the broadcast, the
behavior net picks one action). The broadcast is the mechanism: it recruits
action selection and simultaneously feeds every learning mode. Motivation has
no built-in drives; feelings/emotions are PAM nodes raised by *appraisal
codelets* using Scherer's variables (relevance, implications, coping,
normative significance), and they modulate action selection and learning rate
in a closed loop. Learning is "profligate": new representations are created on
the slightest justification and survive by reinforcement or decay
(instructionalist + selectionist). Evidence: four LIDAI agents reproduce human
reaction-time, Allport simultaneity, attention, and attentional-blink data.

## Relevance to Navi-Brain

This is the canonical GWT/cognitive-cycle paper the corpus synthesis already
leans on (`docs/literature-synthesis.md` §1). It touches the exact seams where
Navi-Brain is strongest and weakest at once: appraisal routing (model exists,
no causal weight), the event→action loop (exists, no selection bottleneck), and
learning across timescales (exists, gated). It is also the paper the
longitudinal metrics already name for "broadcast uptake" and "content-specific
causal effect" — so it is the spec for the workspace Navi-Brain has promised
but not yet built.

## Cross-reference to the existing system

- Already present:
  - **Appraisal as routing signal.** `src/Model/Appraisal.php` + the `appraise`
    command in `src/Core/ExecutiveCore.php:2098` store exactly the right
    dimensions — relevance, urgency, controllability, uncertainty,
    commitment_impact — and fold them into `attention_score`. This is LIDA's
    appraisal-codelet role (Scherer-style variables mapped onto a node),
    minus the codelets. The safety invariant "appraisal values are routing
    signals, not claims of felt emotion" is LIDA's own disavowal of
    phenomenal-consciousness commitments, restated in repo language.
  - **Cognitive cycle skeleton.** `event -> intention -> retrieval -> action
    -> outcome -> discrepancy repair -> consolidation` plus the four rhythms
    (`pulse_30s`, `reflect_5m`, `consolidate_hourly`, `sleep_daily`) is a
    much-slower, serialized cousin of the LIDA loop. The synthesis correctly
    discards the 200 ms timings; the topology (asynchronous specialists
    meeting at serial selection points) is what survives.
  - **Episodic + semantic + procedural tiers and consolidation.**
    `memory:add --tier=episodic` and `memory:consolidate` (episode→semantic
    promotion with lineage) are LIDA's instructionalist episodic learning
    with a provenance gate on top.
- Partially present:
  - **Motivation.** LIDA has no built-in drives; feelings provide flexible
    motivation. Navi-Brain's `Need` pressure variables (`src/Model/Need.php`)
    fill the same slot but with homeostatic growth/threshold math — closer to
    the homeostatic-RL papers in the corpus than to LIDA's feeling nodes.
    Missing: any appraisal→need or appraisal→action-selection coupling that
    makes the motivation actually move behavior.
  - **Selectionist learning.** Nothing reinforces base-level activation of
    frequently-won structures, and nothing decays unused ones. Consolidation
    only promotes; nothing is strengthened by reuse.
- Absent / open design:
  - **The attending phase — the workspace broadcast.** This is the paper's
    spine and Navi-Brain's missing organ. No coalition competition, no global
    broadcast, no serial bottleneck. `attention_score` is computed, stored,
    and used only to order `top_appraisals` in `status()` — it does not gate
    retrieval, action, memory retention, or worker selection. The longitudinal
    metrics demand "broadcast uptake," "content-specific causal effect," and
    "workspace ablation delta" (`docs/longitudinal-cognition-metrics.md`
    §Workspace) but the substrate the metrics would measure does not exist yet.
  - **Appraisal codelets / closed-loop appraisal.** `appraise` takes six
    floats as CLI arguments; nothing derives them from the event or routes on
    them afterward. LIDA's whole value-add is that appraisal is *causal*: it
    biases which coalition wins, modulates learning intensity, and re-appraises
    after action. Navi-Brain records appraisal; it does not feel it. (In the
    routing-signal sense.)
  - **Deliberative volition / multicyclic cognition.** LIDA's ideomotor
    proposer/objector/supporter codelets and its oscillation-breaking
    timekeeper are conceptual, not implemented — same status as Navi-Brain's
    `CognitiveThread`/`ExecutiveInterrupt` models, which are records with no
    running loop. The paper's own weakness list (no multicyclic cognition, no
    metacognition, no causality) is almost a mirror of Navi-Brain's "designed,
    not wired" list in the README.
  - **Grounded/modal representation.** LIDA bans amodal symbols. Navi-Brain
    runs on a language model and ranked lexical retrieval — the exact thing
    LIDA would call amodal. The repo already flags lexical retrieval as a
    known boundary; LIDA's Vector LIDA direction (hyperdimensional vectors,
    reduced descriptions) is a plausible future route, not a 2014 blueprint.

## Concrete implications for implementation

- **Make appraisal causal before adding anything emotional.** Wire
  `attention_score` into at least one downstream choice: retrieval rank
  boost, daydream/reflection scheduling, or work-item priority. Then add the
  longitudinal metric "Appraisal causal value" as a real experiment
  (routing on vs. shuffled) instead of a line in a doc.
- **Derive appraisal instead of passing it in.** A bounded `appraise` that
  computes relevance/urgency/controllability/uncertainty from the event and
  intention state (per LIDA's appraisal variables + the EMA patterns already
  synthesized) would replace hand-supplied floats and close the loop the paper
  demands.
- **Prototype a minimal broadcast as a single-flight fenced work item.** One
  high-brain cycle already runs at a time. The cheapest LIDA-compatible move:
  after selection, emit the winning content into a structure every worker and
  the consolidation pass must consume, then measure uptake per the existing
  metrics. Start with one consumer (consolidation) before claiming a
  workspace.
- **Treat cognitive threads as the multicyclic layer.** LIDA names
  deliberation/volition as the multicyclic processes it hasn't built; Navi-Brain
  already has the tables. A bounded proposer/objector loop over an intention's
  next-action candidates would give threads a real function and match LIDA's
  ideomotor design.
- **Add selectionist reinforcement to the memory tier.** A reuse counter /
  base-level activation on episodic and semantic rows, decayed over time, is
  the piece of LIDA's learning that is cheap and does not violate provenance.

## Risks / tensions

- **Profligacy vs. the no-silent-promotion invariant.** LIDA creates
  representations at the slightest justification and lets reinforcement decide.
  Navi-Brain's "no automatic factual-memory promotion" and evidence+confidence
  rules are the deliberate, safer inversion. Adopt profligacy only inside
  working/proposal tiers; keep semantic and self-model writes gated.
- **Consciousness framing.** LIDA talks functional consciousness as a design
  commitment. Navi-Brain explicitly refuses a consciousness claim — good, and
  the paper supports the distinction (it too disclaims phenomenal
  consciousness). But importing GWT language wholesale would blur that line;
  the repo should keep calling the broadcast a selection/routing mechanism.
- **Paper is a design survey, not strong evidence.** Only procedural and
  episodic learning are implemented; emotions, appraisal codelets, volition,
  and profligate learning are conceptual. The four agent replications are
  single-task, hand-parameterized, and in some cases hard-coded. Don't treat
  LIDA as validated — treat it as a menu of mechanisms with plausible timing
  and topology.
- **Neural-correlate / dynamic-field content** (Section VIII) is outside
  Navi-Brain's scope entirely; no action, no tension.

## Bottom line

Partially adopt — as the operating manual for the workspace Navi-Brain already
promised in its metrics but has not built. The single most important
consequence: make `attention_score` and the fenced high-brain selection into a
real, causally-measurable broadcast bottleneck, or the "broadcast uptake" and
"appraisal causal value" metrics will keep measuring nothing.
