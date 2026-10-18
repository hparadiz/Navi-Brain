# 11 — MemGPT: LLMs as an OS

- **Paper**: Packer et al., "MemGPT: Towards LLMs as Operating Systems"
- **Source**: arXiv:2310.08560v2, Berkeley (Feb 2024)
- **Local file**: `assets/papers/11-packer-et-al-2023-memgpt.pdf`

## Core idea

MemGPT treats the LLM context window as a constrained memory resource and
borrows OS virtual-memory paging to fake "infinite context." The prompt is
split into read-only system instructions, a read/write working context, and a
FIFO queue of recent messages; outside the window sit two external tiers —
archival storage (arbitrary-length text, documents) and recall storage (the
full message log). The LLM *self-directs* its own memory: via function calls it
pages data in and out, evicts stale context, and chains function calls
(`request heartbeat=true`) to do multi-hop retrieval before yielding back to
the user. A queue manager watches token counts and injects a "memory pressure"
warning at ~70% of the window, then flushes at 100% with a recursive summary of
evicted messages. Evaluation covers document QA, a multi-hop nested key-value
task, and a long-term-chat "deep memory retrieval" consistency task.

## Relevance to Navi-Brain

This is a founding paper for the whole tiered-memory idea — and the repo
already knows it: `docs/literature-synthesis.md:24` cites "CoALA and MemGPT
imply separate working, episodic, semantic, and procedural memory systems,"
and `docs/longitudinal-cognition-metrics.md` flags paper #11 for "memory-tier
policies, useful retrieval per write, and long-context baselines." So this is
less *new* territory and more a check of whether the *mechanisms* MemGPT used
to earn those tiers actually exist here. They half-do.

## Cross-reference to the existing system

**Already present:**

- **Memory tiers.** Working / episodic / semantic / procedural plus the
  self-model fact table (`src/Model/Memory.php:33`) already realize MemGPT's
  main-context ↔ external-context split in spirit: working ≈ working context,
  semantic ≈ archival, episodic ≈ recall storage. `memory:search` reads across
  all four.
- **Event-driven control flow.** MemGPT's "events trigger inference" — user
  messages, system alerts, and *timed scheduled events* — is exactly Navi-Brain's
  `event -> intention -> retrieval -> action -> outcome` pipeline with
  rhythm-driven heartbeats (`pulse_30s`, `reflect_5m`, `consolidate_hourly`,
  `sleep_daily`, `src/Model/Rhythm.php`). MemGPT's "scheduled interrupts" and
  Navi-Brain's high-brain single-flight work items are the same idea, with the
  paper as the prior art.
- **Consolidation-with-summary.** MemGPT's flush generates a recursive summary
  of evicted messages; Navi-Brain's `memory:consolidate` promotes episode-derived
  takeaways to semantic with lineage inherited (`ExecutiveCore.php:536`). Same
  compression instinct, different cadence (MemGPT: on token pressure; Navi-Brain:
  hourly clock).
- **Checkpoint under pressure.** The MCP surface already exposes
  "capture the current executive state before context pressure, interruption,
  or task handoff" (`src/Mcp/Server.php:347`) — a faint echo of MemGPT's
  memory-pressure warning, as a manual/event hook rather than an automatic
  trigger.

**Partially present:**

- **Self-directed memory editing — the heart of the paper — exists only as a
  fenced seed.** MemGPT's whole claim is the *agent* autonomously decides what
  to store, evict, and retrieve. Navi-Brain has the machinery (free-model
  worker proposes schema-valid JSON, thought artifacts are provenance-marked
  proposals) but deliberately constrains the autonomy: semantic writes require
  a source event/memory, procedural writes need an explicit override, and there
  is no automatic factual promotion (`ExecutiveCore.php:482-485`). The
  *retrieval* half is present (`brain_recall`, `memory:search`); the *eviction*
  half is not — nothing ever decides what leaves, and nothing summarizes it.
- **Memory pressure as a live signal.** Navi-Brain's "pressure" is need-pressure
  (boredom/curiosity, `Need.php`, `need:stimulate`) plus token/wall-clock budgets
  on work items (`WorkItem.php:55`). It does *not* watch the host agent's actual
  context-window usage. MemGPT's trigger is real token accounting; Navi-Brain's
  closest analogue fires on a clock.
- **Multi-hop retrieval.** MemGPT's function chaining + paginated `archival_storage.search`
  is how it won the nested-KV task. Navi-Brain's `memory:search` returns ranked
  lexical hits with no pagination semantics; chaining across tiers is something
  an engineer could do by hand, not an engine feature. Lexical ranking over
  embeddings is a documented known boundary.

**Absent / open design:**

- **No paging layer for the host's own context.** Navi-Brain persists state in
  SQLite but can't move facts in/out of the running agent's prompt window —
  there is no FIFO with recursive summary, no token accounting of the live
  session, no eviction policy. MemGPT's main contribution (the agent *modifying
  its own context*) has no Navi-Brain counterpart beyond "search pulls things
  in on demand."
- **Recursive summary-of-summaries compression.** `consolidate_hourly`
  produces one takeaway at a time; nothing produces the paper's compressed
  summary of a *history* that keeps earlier summaries inside it.
- **Embedding/vector retrieval.** The paper leans on pgvector + HNSW for
  archival search. Navi-Brain explicitly chooses ranked lexical search as a
  known boundary — the paper is a useful benchmark for *why* that boundary
  exists (document-QA was capped by retriever quality even for MemGPT).

## Concrete implications for implementation

- **Pressure-driven checkpoint trigger.** Add an MCP surface the host agent can
  call when its own context nears its window (a `brain_pressure_hit` event),
  which fires a `checkpoint` plus an eviction-candidate scan of stale/expired
  working memory. This imports MemGPT's "memory pressure warning" *without*
  giving the model autonomous write authority — the safety fence stays.
- **Pagination + multi-hop retrieval.** Give `memory:search` a page/limit
  cursor and a `memory:hop` (or extend search with a `--chain` mode) that
  re-queries on a hit's `supersedes_id`/`source_memory_id` lineage. That is
  Navi-Brain's native version of the nested-KV capability, using the lineage it
  already records instead of the paper's UUID hops.
- **Recursive consolidation.** Extend `memory:consolidate` (or add a
  `consolidate_hourly` stage) to cluster related episodic entries and emit a
  summary that itself inherits lineage — the recursive-summary idea applied to
  a store that already tracks provenance.
- **Auto-demotion policy.** Working memories with `expires_at` could be
  auto-demoted to episodic on expiry rather than deleted, giving a cheap
  eviction-to-storage analogue of MemGPT's flush (minus the FIFO).
- **Evaluation.** Port MemGPT's deep-memory-retrieval task into
  `docs/experiments/`: query episodes that are answerable only from the episodic
  tier, not from semantic/self-model — a clean test of whether recall beats
  summary. The nested-KV multi-hop design maps directly onto the lineage-hopping
  test above. The "useful retrieval per write" metric from
  `docs/longitudinal-cognition-metrics.md:430` can borrow the DMR
  consistency/engagement framing.

## Risks / tensions

- **The core mechanism is in direct tension with safety invariants.** MemGPT's
  value *is* the model silently editing its own durable memory on a felt token
  budget. Navi-Brain's invariants — no silent factual promotion, evidence +
  confidence on semantic/self-model claims, user authority outranking persisted
  intentions, no autonomous writes — forbid exactly that. Adopt the *triggers
  and retrieval* half, keep the *self-authorized write* half fenced.
- **Honesty vs. roleplay.** The appendix's DMR persona prompt tells the agent
  to "never say that I am an AI" and "reply as if I am playing this role." That
  is a persona choice, not memory architecture, but Navi should not import the
  "hide what you are" bit.
- **Thin evidence.** Preprint (v2, Feb 2024), 50-question doc-QA sample, LLM
  judges for correctness, ROUGE-L for dialogue, and results that swing hard on
  the base model's function-calling skill (GPT-3.5 falls apart; GPT-4 Turbo
  *underperforms* plain GPT-4 on nested KV). Treat the numbers as directional.
- **Model-dependent by construction.** MemGPT's quality is bounded by the
  retriever *and* by the model's tool discipline — which is exactly the reason
  Navi-Brain's worker contract demands schema-valid JSON, bounded prompts, and
  circuit breakers. That part of the paper *validates* Navi-Brain's choice.

## Bottom line

Partially adopt. Navi-Brain already owns MemGPT's tiered-memory taxonomy and
event-driven skeleton; what's missing is the *living* half — real context
pressure triggers, multi-hop retrieval, and recursive summarization — and that
half can be built without relaxing a single safety invariant, because the
self-authorized-write mechanism is precisely what Navi-Brain should keep not
doing. Single most important consequence: implement pressure-driven
checkpointing and lineage-hopping retrieval, and port the deep-memory-retrieval
experiment into the evaluation program.
