# 01 — LIDA: A Systems-level Architecture for Cognition, Emotion, and Learning

- **Paper**: LIDA: A Systems-level Architecture for Cognition, Emotion, and Learning
- **Source**: IEEE Transactions on Autonomous Mental Development (TAMD), 2014 (published online 2013). DOI: 10.1109/TAMD.2013.2277589
- **Local file**: `assets/papers/01-franklin-et-al-2014-lida.pdf`

## Core idea

LIDA is a systems-level cognitive architecture grounded in Baars' Global Workspace Theory (GWT). Its central claim is that all cognition is composed of iterative ~200-300ms "cognitive cycles" with three phases: understanding (build a preconscious current situational model from percepts + memory associations), attending (coalitions of structures compete for access to a global workspace; the winner is broadcast as conscious content), and action selection/learning (the broadcast recruits procedural schemes, selects an action, and simultaneously writes into multiple memory systems). Memory is fine-grained: sensory, perceptual associative (PAM, using Barsalou-style perceptual symbols), transient episodic, declarative, procedural, attentional, spatial, and sensory-motor. Motivation comes from feelings/emotions represented as valenced PAM nodes that add activation to action schemes. Learning is "profligate" — new representations enter memory at the slightest justification upon reaching consciousness, then survive by reinforcement or decay. The paper summarizes the conceptual model (LIDAC), the Java framework (LIDAI), and four agents replicating human behavioral data (reaction time, Allport simultaneity, attentional cueing, attentional blink).

## Relevance to Navi-Brain

This is the most directly relevant paper in the corpus. LIDA's cognitive cycle is the clearest published analogue to Navi-Brain's `event -> intention -> retrieval -> action -> outcome -> discrepancy repair -> later consolidation` pipeline. The attention/competition mechanism maps onto the question of how Navi-Brain selects what to reflect on during its 5-minute reflection cycle. The multi-system memory architecture speaks directly to Navi-Brain's tier design (working, episodic, semantic, procedural + self-model). The emotion-as-motivation model is a foil for Navi-Brain's needs/appraisal system — and a test of the safety invariant that appraisal values are routing signals, not felt emotion. The profligacy principle contrasts sharply with Navi-Brain's evidence+confidence discipline on memory writes.

## Cross-reference to the existing system

- **Already present**: The high-brain rhythm structure (`reflect_5m`, `consolidate_hourly`, `sleep_daily` in `src/Model/Rhythm.php`) is a slow-motion version of LIDA's cognitive-cycle stacking. Memory tiers (`src/Model/Memory.php` lines 33-34: working/episodic/semantic/procedural) mirror LIDA's subdivision, including explicit lineage fields (`source_event_id`, `source_memory_id`, `supersedes_id` lines 46-52). Appraisal exists as a routing signal with relevance/urgency fields (`src/Model/Appraisal.php` lines 37-40). Actions track expected vs observed (`src/Model/ActionTrace.php`). FreeModelWorker (`src/Core/FreeModelWorker.php`) is a bounded, tool-denied proposal generator in the spirit of LIDA's codelets, though schema-gated and without the coalitional competition. Consolidation (`memory:consolidate`) promotes episode-derived takeaways into semantic with inherited lineage, matching LIDA's conscious-broadcast-driven learning.

- **Partially present**: Cognitive threads exist as records (`src/Model/CognitiveThread.php`, `src/Model/ThreadStep.php`, `src/Model/ExecutiveInterrupt.php`) but are not wired into a running loop — they are LIDA's multi-cycle "behavior streams" on paper without the scheduler. The attention mechanism is the missing piece: Navi-Brain has no coalitional competition or global workspace for selecting what enters reflection. The appraisal model has relevance/urgency but not Scherer's full set (relevance, implications, coping potential, normative significance). Sparse Distributed Memory is absent; retrieval is ranked lexical search (`memory:search`), which the system already flags as a known boundary.

- **Absent / open design**: The global workspace / consciousness broadcast mechanism as a selection principle for high-brain work. Modal/perceptual-symbol grounding (Navi-Brain is all text and structured records, no sensor ground). Emotion nodes as activation sources on procedural schemes. The ideomotor volitional deliberation loop (proposers/objectors/supporters as attention codelets). Vector LIDA's high-dimensional representations and integer SDM for noise-robust episodic retrieval. Temporal expectation effects on cycle timing.

## Concrete implications for implementation

1. **Wire cognitive threads as a scheduler** (`src/Model/CognitiveThread.php`, `src/Core/ExecutiveCore.php`): implement the coalitional competition so `reflect_5m` selects the most salient intention/thread for a bounded work session, rather than picking by queue order. This is the single biggest structural gap between design and operation.

2. **Add an explicit attention/competition step to the reflection path**: before `memory:consolidate`, run a salience pass over active intentions, needs, and appraisal scores to choose the single work item. Model it as a lightweight score-and-rank (no model call on the 30s path) — this is LIDA's Global Workspace without the phenomenology.

3. **Extend appraisal dimensions** (`src/Model/Appraisal.php`): add implications and coping-potential fields alongside relevance/urgency. These map onto the discrepancy-repair data Navi-Brain already collects (`ActionTrace` expected-vs-observed). This would give the reflection selector richer signal without touching the safety invariant — still routing signals, still corrigible.

4. **Consider SDM or a similar content-addressable associative memory** for episodic retrieval (`src/Model/Memory.php`): the lexical search is a known boundary. LIDA's sparse distributed memory accounts for tip-of-the-tongue effects and graceful degradation. A prototype could live behind `memory:search` as an alternate retrieval backend.

5. **Document the cognitive-cycle mapping** in `docs/literature-synthesis.md`: Navi-Brain's low-brain/high-brain split corresponds to LIDA's single-cycle vs multi-cycle processing. Making that explicit would clarify why the 30s path is model-free and the 5m path is single-flight.

6. **Model temporal expectation** in `CycleRun.php` / `Rhythm.php`: LIDA accounts for faster reaction times to predictable stimuli via pre-activation. Navi-Brain's fixed rhythms could adapt within bounded windows based on scheduled tasks or user patterns.

## Risks / tensions

- **Emotion as motivation vs routing signal**: LIDA explicitly uses feelings as primitive motivators that add activation to procedural schemes. Navi-Brain's invariant (safety section of `_SYSTEM.md`, line 86) says appraisal values are routing signals, not claims of felt emotion. Adopting LIDA's scheme-level activation boost from emotion nodes would blur that line. The safer path is to let needs/appraisal scores influence selection ranking without claiming affective content — treat emotion nodes as a computational device, not an interiority claim.

- **Profligacy vs evidence discipline**: LIDA adds representations "at the slightest justification" and lets decay sort them out. Navi-Brain requires source events, confidence, and explicit lineage for semantic writes. The profligacy principle conflicts with the safety invariant on evidence+confidence. Navi-Brain should keep its discipline; profligacy is a generating strategy for candidate thoughts (already partially mirrored in `ThoughtArtifact.php`), not a memory-promotion policy.

- **Global workspace implies a broadcast bottleneck**: LIDA's conscious broadcast is serial, which is how it maintains coherence. Navi-Brain's high-brain work is already single-flight, so this maps cleanly. But the paper makes no commitment to phenomenal consciousness — it explicitly disclaims it (Section III, footnote 3). Navi-Brain should do the same and stay on the functional side.

- **Evidence base is narrow**: four agents, each replicating a single basic-perception experiment (reaction time, simultaneity, cueing, blink). No real-world task performance, no learning validation beyond procedural. The architecture is sweeping; the verification is thin. This is a conceptual reference, not an empirical mandate.

- **No probability/causality model**: Section XI admits LIDA has little work on causality or probability, which limits real-world robustness. Navi-Brain's action-trace expected-vs-observed tracking is arguably more rigorous here.

## Bottom line

**Partially adopt.** LIDA is the best structural map in the corpus for Navi-Brain's already-chosen architecture — the cycle-based processing, the memory subdivision, and the appraisal/needs motivation all rhyme. The single most important concrete takeaway is wiring the cognitive-thread scheduler with an explicit attention/competition mechanism so high-brain reflection selects its work item rather than draining a queue. Everything else — SDM, vector representations, emotion nodes — is optional elaboration. Hold the line on appraisal-as-signal (not felt emotion) and evidence-gated memory writes; LIDA's profligacy and emotional motivation do not get imported as-is.
