# 03 — Sumers et al. — CoALA: the taxonomy that Navi-Brain already half-speaks

- **Paper**: Cognitive Architectures for Language Agents
- **Source**: arXiv:2309.02427v3, Transactions on Machine Learning Research (02/2024)
- **Local file**: assets/papers/03-sumers-et-al-2023-coala.pdf

## Core idea

CoALA is a conceptual framework, not a mechanism paper: it organizes language agents
along three axes — memory (working + episodic/semantic/procedural long-term), a
structured action space (external grounding vs internal retrieval/reasoning/learning),
and a generalized decision procedure that runs a propose–evaluate–select cycle per
"decision cycle" against the LLM as a probabilistic production system. It then uses
that lens to survey existing agents (SayCan, ReAct, Voyager, Generative Agents, ToT)
and to flag under-explored directions: adaptive retrieval, learning treated as a
first-class decision-cycle action, meta-learning over agent code, metareasoning, and
explicit action-space safety. Evidence is descriptive — it's a retrospective
taxonomy plus a design guide, not a new empirical result.

## Relevance to Navi-Brain

This is the one paper in the corpus that Navi-Brain's own docs already cite as the
origin of its four-tier memory split (`docs/literature-synthesis.md:24`) and as the
traceability anchor for episodic/semantic/procedural separation in
`docs/longitudinal-cognition-metrics.md` (paper #3 row). So the interesting work here
isn't "adopt CoALA" — the substrate already mirrors it — it's checking how faithfully
Navi-Brain implements each axis, where the decision procedure diverges, and which of
CoALA's open directions Navi-Brain has quietly already answered.

## Cross-reference to the existing system

### Already present

- **Four-tier memory taxonomy maps 1:1.** `src/Model/Memory.php:33` enforces the enum
  `working|episodic|semantic|procedural` — exactly CoALA's §4.1 long-term split.
  CoALA adds nothing structural here; it gives the tiers an externally-auditable
  justification.
- **Learning is a decision-cycle action, not a hidden pipeline.** CoALA §7
  ("Learning vs. acting") argues the agent should deliberately *choose* when to commit
  information to long-term memory. Navi-Brain does this: `memory:consolidate` promotes
  episodes to semantic only via an explicit pass, direct semantic writes are rejected
  without a `--source-event/--source-memory` anchor, and `ThoughtArtifact` proposals
  are never silently promoted to fact. Generative Agents' "reflect on episodes, store
  inference to semantic" (§5) is, in miniature, the consolidate path already built.
- **Procedural memory is bootstrap-only and guarded.** CoALA §4.1/§4.5 flags writes to
  procedural memory as the risky kind — "can easily introduce bugs or allow an agent to
  subvert its designers' intentions." Navi-Brain's invariant that procedural writes
  require an explicit override is exactly that caution turned into policy.
- **Action-space safety is treated as first-class.** CoALA §6 ("Safety of the action
  space") distinguishes internal-harm (learning) from external-harm (grounding) actions.
  Navi-Brain's stance — records actions, never autonomously executes shell commands,
  remote workers are tool-denied with a deny-all proposal surface — is a deliberate,
  documented restriction of the grounding action space consistent with CoALA's
  "take the minimal action space necessary" advice.

### Partially present

- **Decision procedure is propose-then-execute, not propose–evaluate–select.**
  CoALA §4.6's full loop has a planning stage that proposes, *evaluates* (values)
  candidates, and selects before execution. Navi-Brain's high-brain is closer to
  ReAct's single-proposal pattern: one fenced work item, one tool-denied worker.
  The `FreeModelWorker` tries up to 3 models but takes **first-success**, not best-of —
  it's a fallback ladder, not an evaluation/selection stage. There's no candidate value
  assignment (LLM perplexity, learned value, or vote) in the loop.
- **Working memory is a tier, not a decision-cycle buffer.** CoALA §4.1's working memory
  is a data structure persisting *across* LLM calls, synthesized into prompt templates
  and parsed back into variables. Navi-Brain's `working` tier exists in the enum and the
  heartbeat carries thread state forward (`CognitiveThread`/`ThreadStep`), but there's
  no explicit prompt-template/parser contract that reads and writes working memory per
  cycle.
- **Retrieval exists but isn't wired into planning.** CoALA §6 calls integrated
  retrieval+reasoning (memory search interleaved with forward simulation) a key
  direction. Navi-Brain has ranked lexical `memory:search` and cognitive threads, but
  the high-brain reflection isn't documented as a retrieve-then-reason planning step;
  retrieval is on demand via MCP, not part of the decision-cycle's propose machinery.
  CoALA's own answer to adaptive/learned retrieval ("understudied") matches Navi-Brain's
  known boundary exactly.

### Absent / open design

- **No learning actions that modify agent code or decision-making.** CoALA §4.5/§6
  (meta-learning: update retrieval procedures, prompt templates, or the decision
  procedure itself) is noted as risky and essentially unstudied — Navi-Brain rightly
  has none. That's an open design lane, not a defect.
- **Unlearning is unaddressed.** CoALA §4.5 flags deletion/modification of memory
  ("unlearning") as understudied. Navi-Brain has supersede/expire statuses in
  `Memory.php` but no documented active-unlearning procedure.
- **Boundary question answered, implicitly.** CoALA §7's "internal vs external"
  (controllability/coupling: is a database internal memory or external environment?)
  — Navi-Brain's answer is baked into its invariants: anything the agent can't
  safely control is external, and external grounding is recorded but not executed.

## Concrete implications for implementation

1. **Add an explicit evaluate/select step to the high-brain decision loop** — or
   document why first-success is intended. A cheap version: when multiple free models
   return proposals for a work item, run a bounded evaluate pass (schema-validity,
   evidence-anchoring, self-consistency against the item) before integration, instead
   of taking the first success. Keep it single-flight and within the existing
   wall-clock budget. This is the single largest CoALA divergence.
2. **Promote retrieval into the reflection planning stage.** Have `reflect_5m` open a
   thread step that runs a scoped `memory:search` on the item's topic *before*
   proposing, and record which memories informed the proposal. CoALA's "retrieve-then-
   reason" is directly implementable with existing machinery.
3. **Formalize working memory as a per-cycle buffer.** Codify a prompt-template/parser
   contract (input: current thread state + retrieved memories; output: parsed action
   name + arguments) in the reflection path so the `working` tier is actually exercised
   across LLM calls rather than being an empty enum value.
4. **File the meta-learning lane in docs, explicitly deferred.** Add a note to
   `docs/ongoing-cognition.md` that updating procedural/decision procedures is
   deliberately out of scope per CoALA's own risk warning, so it reads as a decision
   rather than an omission.
5. **Extend the longitudinal metrics for the decision cycle.** CoALA gives the
   traceability row in `docs/longitudinal-cognition-metrics.md`; add a metric for
   propose-vs-evaluate-vs-select composition (how often evaluation changes selection)
   once step 1 exists — otherwise it's untestable.

## Risks / tensions

- CoALA is a framework paper: zero new experiments, single group, largely a survey
  with a suggestive analogy to production systems (LLM-as-probabilistic-production).
  Its value is organizational, so it should inform structure, not be treated as
  empirical evidence for any mechanism. Navi-Brain already uses it that way.
- CoALA's "learning as a deliberate action" and "autonomous when-and-what-to-learn"
  could nudge toward autonomy creep. Navi-Brain's guardrails — consolidation requires
  provenance, thoughts stay proposals, no silent promotion — are the correct tension,
  and should stay.
- The internal/external boundary question (§7) is genuinely contested; Navi-Brain's
  "record, don't execute" is a defensible position but means its grounding action
  space is permanently thin. That's a feature for safety, a limit for world-action
  coverage, and should be acknowledged as a tradeoff, not a fixable gap.

## Bottom line

**Adopt-as-framing** (already partially adopted in `docs/literature-synthesis.md`).
Navi-Brain's memory tiers and guarded procedural writes are CoALA-faithful; the real
gap is the decision procedure — no explicit evaluate/select stage, retrieval not yet
hooked into planning. Closing those two turns the substrate from a CoALA-shaped
memory system into a CoALA-shaped agent.
