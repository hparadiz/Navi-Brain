# 03 — Cognitive Architectures for Language Agents (CoALA)

- **Paper**: Cognitive Architectures for Language Agents
- **Source**: arXiv:2309.02427v3, Transactions on Machine Learning Research, 02/2024
- **Local file**: assets/papers/03-sumers-et-al-2023-coala.pdf

## Core idea

CoALA reframes LLM-based agents through the lens of classical cognitive
architectures (Soar, ACT-R) by organizing them along three axes: memory
(working, episodic, semantic, procedural), action space (internal — reasoning,
retrieval, learning; external — grounding), and decision-making (a
propose-evaluate-select cycle). The paper's central move is treating LLMs as
probabilistic production systems, then showing how decades of cognitive
architecture control flow maps onto prompt chaining, tool use, and memory
management. It surveys existing agents (SayCan, ReAct, Voyager, Generative
Agents, Tree of Thoughts) using this framework and identifies open directions:
modular agent design, structured reasoning beyond prompt engineering, long-term
memory beyond retrieval augmentation, and metareasoning to allocate compute
adaptively.

## Relevance to Navi-Brain

This paper is a conceptual map for exactly the kind of system Navi-Brain is
becoming. Its three-axis breakdown (memory, action, decision-making) lines up
almost one-to-one with Navi-Brain's implemented substrate, which means the
framework is useful both as a sanity check and as a gap analysis. The strongest
touchpoints: memory tiers, cognitive threads (CoALA's "decision procedure" is
the missing executor), the worker/curator split (CoALA's proposal vs evaluation
sub-stages), and the safety-invariant-friendly insistence that procedural
self-modification is the riskiest action class. The paper also validates the
project's operating assumption that cognitive-science vocabulary is the right
level of abstraction for talking about agent internals.

## Cross-reference to the existing system

- Already present: The memory tier model (`src/Model/Memory.php`, tiers
  working/episodic/semantic/procedural) directly mirrors CoALA's taxonomy.
  Events (`src/Model/Event.php`), Intentions (`src/Model/Intention.php`), and
  ActionTraces (`src/Model/ActionTrace.php`) implement the
  event->intention->action->outcome chain. Rhythms (`src/Model/Rhythm.php`)
  provide the low/high brain pacing with fencing tokens and leases. Needs
  (`src/Model/Need.php`) and SelfModelFacts (`src/Model/SelfModelFact.php`)
  cover the self-monitoring layer. The FreeModelWorker
  (`src/Core/FreeModelWorker.php`) is a bounded proposal generator with
  circuit breakers, matching CoALA's call for "code to complement LLM
  limitations." Desktop perception (`src/Perception/`) implements CoALA's
  grounding interface (observations in, actions out) with the privacy
  filtering the paper does not discuss but any responsible deployment needs.
- Partially present: CognitiveThread and ThreadStep
  (`src/Model/CognitiveThread.php`, `src/Model/ThreadStep.php`) are fully
  designed with phases (observe/think/prepare/act), budgets, wake conditions,
  fencing tokens, and uncertainty tracking — but they are not yet wired into a
  running decision loop (acknowledged in `_SYSTEM.md`). This is CoALA's
  "decision procedure" sitting there as data without an executor.
  ThoughtArtifact (`src/Model/ThoughtArtifact.php`) captures proposed
  thoughts with provenance but has no silent promotion to factual memory,
  matching CoALA's warning about procedural self-modification risk.
  ExecutiveInterrupt (`src/Model/ExecutiveInterrupt.php`) has the shape of an
  internal action signal but no consumer yet.
- Absent / open design: CoALA's propose-evaluate-select decision cycle is not
  implemented — Navi-Brain's high-brain work is currently a single-flight "do
  one thing" heartbeat rather than an explicit proposal-evaluation-selection
  loop. There is no metareasoning (estimating the value of planning before
  spending compute). The "internal action space" distinction between
  reasoning, retrieval, and learning is implicit in code paths but not
  modeled as a first-class set the agent chooses over. The paper's
  "controllability and coupling" test for agent/environment boundaries has not
  been applied to Navi-Brain's own boundaries (is SQLite internal memory or
  external environment? — the paper would say internal, because Navi-Brain
  alone writes to it).

## Concrete implications for implementation

- Wire `CognitiveThread` into a decision loop: the schema already has phases,
  `wake_at`, `budget`, `spent`, `next_operation`, and `fencing_token`.
  `docs/ongoing-cognition.md` describes the target; the next step is a
  thread-aware executor in `src/Core/ExecutiveCore.php` that runs one active
  thread per high-brain tick through propose-evaluate-select. The
  CognitiveThread model has everything except something that calls it.
- Model the internal action space explicitly: add an action-type enum
  (reasoning, retrieval, learning, grounding) to work items so the agent can
  deliberate over what kind of action to take next, not just what content to
  produce. This belongs in the work-item selection logic in ExecutiveCore.
- Add a lightweight evaluation sub-stage to `FreeModelWorker.php`: currently
  it proposes. CoALA's Voyager/Generative Agents pattern suggests a separate
  evaluation pass before committing a thought artifact to semantic memory.
  Keep it bounded and tool-denied — proposals stay proposals until explicitly
  accepted.
- Treat consolidation as a first-class learning action: `memory:consolidate`
  exists but is scheduled. CoALA's "learning on par with external actions"
  suggests making `consolidate` a selectable internal action the agent can
  propose during planning, not just an hourly cron. The mechanism is already
  there; the change is in who chooses to invoke it.
- Use CoALA as a documentation vocabulary: `docs/literature-synthesis.md`
  already synthesizes this corpus. Adding a CoALA-axis mapping (memory / action
  / decision) to the architecture docs would make the design legible to anyone
  coming from the agent-architecture literature.

## Risks / tensions

- CoALA explicitly flags procedural self-modification (rewriting agent code)
  as the riskiest learning action. Navi-Brain's safety invariants already deny
  autonomous shell execution and require explicit override for procedural
  memory writes, which is the right default. The paper's "update reasoning /
  update grounding / update learning or decision-making" ladder should
  reinforce the existing line: never let the worker self-modify without a
  human in the loop.
- The paper's metareasoning direction ("estimate the utility of planning and
  modify the decision procedure accordingly") edges toward the kind of
  recursive self-modification Navi-Brain should not implement unguarded. Any
  adaptive depth-budgeting should stay within hard bounds and never rewrite
  the decision procedure itself.
- CoALA treats appraisal/evaluation as a first-class sub-stage. Navi-Brain's
  invariants (appraisal values are routing signals, not claims of felt
  emotion) are compatible but the paper's language can blur this. Keep
  evaluation as "how well does this proposal match the success condition," not
  "how does the agent feel about this outcome."
- The paper is a conceptual survey, not an empirical evaluation. Its value is
  in terminology and gap identification, not in proven mechanisms. No single
  CoALA component should be adopted just because the paper names it; adopt
  where it fills a concrete gap in the existing substrate.

## Bottom line

Partially adopt. CoALA is the best available conceptual vocabulary for what
Navi-Brain is building, and it confirms the existing design is on the right
track. The single most important consequence is wiring the already-designed
`CognitiveThread` schema into a real decision loop that implements
propose-evaluate-select — that is the gap between Navi-Brain's current
scheduled heartbeat and CoALA's "decision procedure." Everything else
(metareasoning, adaptive consolidation, explicit action-space modeling) is
downstream of that.
