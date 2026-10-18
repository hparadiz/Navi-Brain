# 16 — Complementary learning systems (fast hippocampus, slow neocortex)

- **Paper**: McClelland, McNaughton & O'Reilly, "Why There Are Complementary Learning Systems in the Hippocampus and Neocortex: Insights from the Successes and Failures of Connectionist Models of Learning and Memory"
- **Source**: DOI 10.1037/0033-295X.102.3.419, *Psychological Review* 102(3), 419–457, 1995
- **Local file**: `assets/papers/16-mcclelland-mcnaughton-oreilly-1995-complementary-learning-systems.pdf`

## Core idea

The brain runs two learning systems on purpose. The hippocampus grabs the contents of individual episodes fast, as sparse, non-overlapping conjunctive patterns that can pattern-complete from partial cues. The neocortex learns slowly, because structure is only discoverable through gradual, *interleaved* exposure to a whole ensemble of experiences — focused rapid learning of new material wrecks existing knowledge (catastrophic interference, shown in the Rumelhart penguin simulations and the AB-AC paired-associates work). So the hippocampus is the neocortex's teacher: it reinstates recent memories during off-line periods (slow-wave sleep sharp waves), giving the neocortex one more small training trial each time, until the memory is slowly baked into neocortical weights and becomes independent of the hippocampus. Temporally graded retrograde amnesia is therefore not a quirk — it is the necessary consequence of the design.

## Relevance to Navi-Brain

This is the theoretical backbone under Navi-Brain's two-tier episodic→semantic memory design. It names *why* there should be a fast episodic store and a slow semantic one, and — critically — it says the slow layer's stability depends on repeated, interleaved incorporation rather than one-shot transfer. It touches the consolidation rhythm (`memory:consolidate`, `consolidate_hourly`), the sleep rhythm (`sleep_daily`, and the pending presence-driven staged sleep in active intention 13), the consolidation-transfer experiment (`docs/experiments/04-consolidation-transfer.md`), and the longitudinal metric family for interference/backward transfer (`docs/longitudinal-cognition-metrics.md`). The `supersedes_id` field is a dormant lever for exactly the interference problem the paper centers.

## Cross-reference to the existing system

**Already present:**
- Fast episodic tier + slow curated semantic tier (`src/Model/Memory.php` tier enum; `ExecutiveCore::addMemory`/`consolidateMemory`). The paper supplies the missing *reason*: episodic is fast-and-sloppy because it is sparse and conjunctive; semantic is slow because it must generalize.
- Consolidation carries lineage — `consolidateMemory` only accepts episodic sources and inherits `source_event_id`/`source_memory_id` (`ExecutiveCore.php:615`). That is the "hippocampus as teacher" provenance discipline: nothing enters the semantic layer without a traceable episode behind it.
- Off-line rhythms exist: `consolidate_hourly` (a bounded high-brain worker pass, `ExecutiveCore.php:2476`) and `sleep_daily`. Active intention 13 pushes sleep toward presence-driven, 90-minute staged phases with a "light consolidation pass" on short absence — structurally the paper's off-line reinstatement window.
- No silent promotion: free-model workers only produce proposals (`FreeModelWorker.php`), thought artifacts are provenance-marked and never auto-promote. Matches the paper's own rule that the slow system must not be forced.
- The measurement program already anticipated this paper: backward transfer / catastrophic interference metrics, source-entailment precision, provenance-chain survival, and compression ratio are defined in `docs/longitudinal-cognition-metrics.md`, and experiment 04 (consolidation transfer) exists as a proposed paired-clone study.

**Partially present:**
- Consolidation is *single-shot*: one `memory:consolidate` call promotes one episode to one semantic row and stops. The paper's core mechanistic claim is that gradual, repeated, interleaved re-exposure is exactly what makes the structured layer stable. Navi-Brain has the two tiers but not the temporal loop between them — no reinstatement-and-interleave, no repeated small trials, no drift toward shared structure over time.
- No interference management on the semantic layer. New semantic claims can be appended that overlap or contradict older ones; nothing checks the existing ensemble. The scaffold for this exists — `supersedes_id` on `Memory.php` — but nothing populates it automatically.
- Retrieval is lexical, ranked `working > semantic > episodic` (`ExecutiveCore.php:665`). There is no notion of representational overlap, so no way to even *measure* interference at recall time. Lexical search is a documented boundary in `_SYSTEM.md`.
- Sleep and consolidation are not yet causally connected to memory change: the hourly pass runs a worker proposal, and `docs/ongoing-cognition.md` admits the curator/outcome loop is unwired ("the brain is not yet a continuing undertaking").

**Absent / open design:**
- No analog of the hippocampus's sparse, conjunctive, compressed representation — episodes are stored as verbatim text rows. No compression, no "summary sketch" (McNaughton 1989) at encode time.
- No reinstatement/rehearsal loop: nothing re-presents episodic sources to the slow layer during sleep in an interleaved fashion.
- No forgetting/decay curve or temporal gradient. The paper's most famous phenomenon (recent memory depends on the fast store, remote memory does not) has no mapping — there is no "age of memory" factor in retrieval ranking or consolidation priority.

## Concrete implications for implementation

1. **Make consolidation a repeated, interleaved pass, not a one-shot promote.** In the `consolidate_hourly` pass (or the staged sleep intention), re-present a bounded sample of recent episodic sources *interleaved with* a rotating sample of existing semantic claims — the paper's "rest of the database". Same fencing, same wall-clock budget, same single-flight rule; the only change is that a semantic candidate only lands after it has survived contact with the existing semantic ensemble, and older episodic sources keep getting small re-trials rather than a single shot.
2. **Wire up `supersedes_id` as conflict-aware consolidation.** When a proposed semantic takeaway overlaps an existing semantic claim, the curator outcome should be merge / supersede / reject — with the old row's evidence chain preserved under `supersedes_id`, never destroyed. This is the direct, concrete version of "incorporate new material into a structured system without catastrophic interference".
3. **Instrument the already-specified backward-transfer metric in the live loop.** `docs/longitudinal-cognition-metrics.md` defines it; make a cheap live check: after each consolidation batch, verify retrieval scores of overlapping semantic memories did not drop. That turns the paper's central failure mode into an alarm.
4. **Give the light sleep pass a reinstatement job.** When intention 13 lands, the short-absence "light consolidation pass" should re-present flagged episodic sources to a bounded worker for candidate semantic takeaways — the paper's reinstatement-as-teacher — instead of a generic proposal.
5. **Add an "age-of-memory" factor to retrieval or consolidation priority** (episodic weight decays toward semantic relevance as time passes), which is the closest implementable proxy for the temporally graded retrograde amnesia gradient.

## Risks / tensions

- The paper's interference results are connectionist (backprop, distributed weights). Navi-Brain has no weights; the mapping is architectural analogy, not mechanism. Don't import "gradual weight change" language — the semantic layer is curated rows, and the *discipline* transfers, the gradient doesn't.
- Repeated reinstatement is in tension with Navi-Brain's "no silent promotion" invariant if it becomes unsupervised: replaying the same episode too often drifts toward overgeneralization. The paper itself flags that idiosyncratic aspects of an episode consolidate slower than shared ones — that's exactly what source-entailment precision (already in the metrics doc) must police.
- Aggressive superseding could violate the evidence-and-confidence invariant if the old claim's evidence chain is dropped. Supersedes must retain provenance, or the fix becomes the disease.
- The paper is a 1995 landmark but its "standard" systems-consolidation view is contested (multiple-trace theory, reconsolidation work shows hippocampal traces are not simply wiped after consolidation). The temporal-gradient claim is load-bearing for the theory and still debated. Treat the *architecture* as the takeaway, the amnesia-phenomenology mapping as context.
- A rehearsal loop costs budget and token calls. It must stay inside the existing wall-clock/fencing model, or the hourly pass silently grows into a catch-up storm — which the safety invariants explicitly forbid.

## Bottom line

Adopt, partially — the two-tier fast/slow architecture already exists and this paper is its best justification, but the temporal loop is missing: Navi-Brain consolidates once, the paper says the whole game is consolidating *gradually and interleaved*. The single most important change is converting single-shot promote into a repeated, interleaved, conflict-aware consolidation pass with `supersedes_id` wired in and a live backward-transfer check — that is the paper's failure mode converted into Navi-Brain's alarm.
