# 06 — Computational Metacognition

- **Paper**: Computational Metacognition: Metacognitive Integrated Dual-Cycle
  Architecture (MIDCA)
- **Source**: Cox et al., Advances in Cognitive Systems, 2022. Local file:
  `assets/papers/06-cox-et-al-2022-computational-metacognition.pdf`

## Core idea

MIDCA stacks two action-perception cycles. The cognitive cycle
(Perceive → Interpret → Evaluate → Intend → Plan → Act) acts on the world.
The metacognitive cycle watches a cognitive trace, detects "metacognitive expectation failures" — moments when `Interpret`
cannot explain a discrepancy — and generates a meta-level goal to fix the
root cause.
Three kinds: **explanatory** (hindsight repair), **immediate** (sketched),
and **anticipatory** (forward-looking delegation of suspended goals to
other agents). Only explanatory is implemented and evaluated.

The mechanism: `Interpret` tries to explain a world-level discrepancy using
meta-explanation patterns (Meta-XPs) — case-based causal graphs. When no XP
applies, a metacognitive expectation fails and a learning goal is generated.
Meta-level control plans a learning action with Fast Downward (PDDL 2.2
operators like `perform-learning`) and executes it via FOIL induction over
plan-execution examples. The learned Horn clause (e.g., "spraying kills the
cell to the north") folds back into the action operator as a conditional
effect. Empirically, on a 10x10 plant-protection domain, the learning agent
crushes a standard planner across 2000 trials. The paper contrasts MIDCA
with ACT-R, CLARION, and LIDA.

## Relevance to Navi-Brain

This is the most directly on-point paper in the corpus for the layer
Navi-Brain is missing: a self-repair loop that watches its own reasoning
trace and intervenes on itself. Navi-Brain already records
expected-vs-observed mismatches per action (`action:finish` in
`src/Core/ExecutiveCore.php:391-433`), consolidates episodic takeaways, and
has a schema-backed but unwired cognitive-thread model. MIDCA says: that is
the cognitive half. The metacognitive half — a second cycle that watches
the first, explains why repair keeps failing, and schedules root-cause
learning — is not implemented. The paper also gives a formal target for
the self-model: a state transition system Ω = (S^M, A^M, ω) over mental
states, not just a fact table.

## Cross-reference to the existing system

- **Already present**: `src/Model/ActionTrace.php` captures expected vs
  observed with `match_status` and `repair_note` — cognitive-level
  discrepancy detection, same role as MIDCA's `Interpret`. The
  `action:finish` transaction lives at `ExecutiveCore.php:404-433`.
  `src/Model/SelfModelFact.php` is a declarative self-model with
  `confidence` and `evidence_event_id`, thinner than MIDCA's Ω but the
  same idea. `memory:consolidate` (`ExecutiveCore.php:536-540`) promotes
  episode-derived knowledge with lineage, like MIDCA's post-learning
  knowledge updates. The `reflect_5m` and `consolidate_hourly` rhythms
  (`src/Model/Rhythm.php`, `src/Model/CycleRun.php`) are existing
  high-brain cycles that could host metacognitive work — single-flight,
  budgeted (`ExecutiveCore.php:2439-2440`).

- **Partially present**: `src/Model/CognitiveThread.php` has
  `expected_postcondition`, `uncertainty`, `stagnation_count`, `progress`,
  `budget`, `spent` — all the instrumentation for meta-level monitoring.
  But threads are "designed but NOT yet wired into a running loop"
  (`_SYSTEM.md`). MIDCA's meta-level control (Intend → Plan →
  Controller) is the missing execution surface for these fields. The
  FreeModelWorker (`src/Core/FreeModelWorker.php`) is a deny-all,
  schema-validated proposal worker — right safety posture, wrong prompt
  type (curiosity/speech, not learning goals). `ThoughtArtifact`
  (`src/Model/ThoughtArtifact.php`) enforces provenance-marked proposed
  thoughts with no silent promotion — the exact sink MIDCA's learning
  output needs.

- **Absent / open design**: No metacognitive trace. MIDCA records
  `τ_l = ⟨s_0^M, α_1^M, s_1^M, ..., s_n^M⟩` — mental states and mental
  actions. Navi-Brain's `src/Model/Event.php` records domain-level events,
  not cognitive-phase transitions. No Meta-XP library. No meta-level
  planner. No cognitive transition function ω. No anticipatory
  metacognition (no other agents to delegate to).

## Concrete implications for implementation

1. **Add a cognitive-phase trace table** — new
   `src/Model/CognitivePhaseTrace.php` recording the 7-tuple mental state
   vector (current goal, goal agenda, plan, world state, discrepancies,
   explanations, last action), `mental_action`, `trace_sequence`, and
   `source_run_id` per MIDCA's Ω. Wire `reflect_5m` to append to it. This
   is the highest-leverage schema addition in the corpus — without a
   trace, nothing to watch.

2. **Wire `CognitiveThread` stagnation into reflection** —
   `src/Model/CognitiveThread.php:82` (`stagnation_count`) and `:52`
   (`uncertainty`) are inert. When stagnation crosses a threshold,
   generate a meta-level goal. The `reflect_5m` work-type selection at
   `ExecutiveCore.php:2395` should branch on thread state, not just a
   fixed `curiosity_reflection` type.

3. **Build a Meta-XP table** — `src/Model/MetaExplanationPattern.php` with
   pre-XP conditions, explains-node, causal chain, repair-goal template.
   Extend `memory:consolidate` to propose Meta-XP instances when a
   `repair_note` pattern repeats across `action_traces` sharing the same
   `intention_id` root — the "my repair keeps failing" trigger.

4. **Reuse the deny-all worker for meta-level learning** —
   `src/Core/FreeModelWorker.php` already strips recall, denies tools,
   validates JSON, enforces circuit breakers. Add a `metacognitive_learning`
   work-type branch and schema entry in `workerSchema()` (line 207-223).
   Output lands in `ThoughtArtifact`. No new worker infrastructure.

5. **Document the gap** — `docs/ongoing-cognition.md` should get a
   dual-cycle section mapping MIDCA's phases to Navi-Brain's rhythms and
   naming the trace as the next milestone.

## Risks / tensions

- The paper admits metacognition wastes resources under time constraints
  (cites Norman 2020, "Metacognition is not always helpful"). A nested
  metacognitive loop needs its own wall-clock guard so it can't starve
  the parent reflection cycle. The `wall_budget_seconds` field
  (`ExecutiveCore.php:52`) is the right mechanism.

- MIDCA's FOIL learner directly mutates the action model. Navi-Brain's
  invariants mean learning output must land as `ThoughtArtifact`, not a
  direct rewrite. Route through proposal-first.

- Single toy domain evaluation (10x10 grid, FOIL only, one competitor).
  Generality unclaimed. MIDCA handles only singleton goal sets; multi-goal
  metacognition is future work. The formalism (trace → expectation failure
  → Meta-XP → learning goal) is what transfers, not the FOIL specifics.

- Anticipatory metacognition relies on Theory of Mind about other agents.
  Navi-Brain has no other agents to delegate to, so this third of the
  paper is not actionable. Monitor it for when the mesh-replication
  layer (`bin/mesh-replicate-*`) matures.

- Kralik et al. (2018) is cited for an over-broad metacognition definition
  that would swallow planning whole. MIDCA avoids this by anchoring on
  expectation-failure-driven explanation. The `repair_note` repetition
  trigger is the operational version of the same discipline: metacognition
  is repair-of-repair, not "anything that looks at an action trace."

## Bottom line

**Partially adopt.** Build the cognitive-phase trace and wire the existing
`CognitiveThread` stagnation signals into a metacognitive trigger. Do not
build the full MIDCA planner — Navi-Brain's worker/curator contract is the
wrong shape for PDDL meta-level plans, and the deny-all `FreeModelWorker`
already covers execution. Reuse `ThoughtArtifact` as the safe vessel for
learning output. The single most important consequence:
`src/Model/CognitiveThread.php` goes from dead schema to the skeleton of a
meta-level controller the moment someone records a mental-state trace for
it to watch. Everything else — Meta-XPs, learning goals, anticipatory
delegation — sits on top of that trace.
