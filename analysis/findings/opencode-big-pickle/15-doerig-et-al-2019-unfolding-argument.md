# 15 — The Unfolding Argument

- **Paper**: The unfolding argument: Why IIT and other causal structure theories cannot explain consciousness
- **Source**: DOI 10.1016/j.concog.2019.04.002, Consciousness and Cognition 72 (2019) 49–59
- **Local file**: assets/papers/15-doerig-et-al-2019-unfolding-argument.pdf

## Core idea

Recurrent and feedforward neural networks are both universal function
approximators, so any input-output function one can learn, the other can learn
too. That means any recurrent network — any brain, any "conscious" system — can
be *unfolded* into a feedforward network with identical input-output behaviour,
and vice versa. Since every behavioural experiment about consciousness is an
input-output function, causal structure theories (IIT's phi, RPT's recurrent
processing) can always be pointed at a system that empirically behaves exactly
like the "conscious" one but has phi = 0, or the reverse. Their key structural
measure is therefore doubly dissociated from all possible experimental data:
neither necessary nor sufficient, so these theories are either false (if they
grant the unfolded twin consciousness) or unfalsifiable/outside science (if
they insist on a difference no experiment can touch). Functionalist theories —
Global Workspace, Higher-Order Thought, Predictive Processing — escape the
argument because they tie consciousness to *what a system does* (broadcasting,
meta-representation, prediction) rather than *how it's wired*. The conclusion:
consciousness must be described functionally, at a level abstracted away from
implementation details.

## Relevance to Navi-Brain

Navi-Brain's entire stated posture is the class of theory this paper defends:
"functional cognitive infrastructure, not a consciousness claim" (README.md:28),
needs/appraisal as routing signals rather than felt states, and an explicit
ban on consciousness scores. The paper is the theoretical warrant for that
posture, and it's already been adopted as a guardrail rather than discovered
fresh — `docs/literature-synthesis.md:91` calls the unfolding argument "the
main guardrail," and `docs/longitudinal-cognition-metrics.md:320` codifies
"do not optimize Phi or any single consciousness proxy." What this paper adds
is the sharper negative: it's not just that the system declines to claim
consciousness — it's that *no structural or indicator measurement could settle
it in principle*, so structural language in the project narrative (the "causal
traffic," "recurrently coupled processes" of the mesh hypothesis) has zero
evidential weight on its own and must be cashed out functionally.

## Cross-reference to the existing system

**Already present**
- The guardrail is live: README.md:28, `docs/literature-synthesis.md:91`,
  `docs/longitudinal-cognition-metrics.md:318-336` (theory-relative indicator
  matrix, gaming paths, "do not optimize Phi").
- No-claim discipline is enforced in code: free-model worker prompts explicitly
  forbid claiming "observation, memory, consciousness, or permission to act"
  (src/Core/ExecutiveCore.php:2396), and the ambient-line generator has a
  regex denylist against "i am alive/conscious/sentient/a person" claims
  (src/Core/ExecutiveCore.php:3097).
- The evaluation program already builds the *unfolded control* the paper
  demands: `docs/literature-synthesis.md:107-112` calls for "controls made from
  duplicated but non-integrated workers," and
  `docs/experiments/05-cognitive-component-ablation.md:137` notes non-additive
  interaction "is not consciousness." Duplicated-but-not-integrated workers are
  the empirical twin of the paper's feedforward doubles: same function, no
  integration, and therefore no inference to structure.
- Appraisal/needs (`src/Model/Appraisal.php`, `src/Model/Need.php`) and the
  self-model fact table are all functional layers, not causal-structure
  claims — the paper's favoured stance.

**Partially present**
- The indicator matrix (longitudinal-cognition-metrics.md:318-336) treats
  single scores as non-decisive but doesn't yet state the *constructive* half
  of the argument: for every indicator, a structurally different system can be
  built that reproduces the same measured outcome, so an indicator only counts
  as evidence under an explicitly stated functional theory, never as support
  for a structural one.
- The mesh hypothesis is stated in partially structural language —
  "differentiated, recurrently coupled processes," "causal broadcast paths"
  (literature-synthesis.md:95-118). It's hedged as a research target, but the
  unfolding argument makes that hedge load-bearing: the recurrent coupling
  must be justified by measured functional integration and continuity, or it's
  decorative wiring.

**Absent / open design**
- No explicit note anywhere that the paper's result generalizes to Navi-Brain's
  *own* internal measures: the evidence+confidence self-model facts, retrieval
  ranking, and heartbeat rhythms are each one of infinitely many equivalent
  implementations. The paper's premises P3/P4 say the same thing about any
  property-based internal metric that isn't anchored to a functional outcome.
- The paper's section 2.7 (the correlation/bridging approach) is exactly what
  `brain_recall` / `brain_self_model` / evidence+confidence do — and the paper
  shows that approach only works for *functionalist* theories. That permission
  slip isn't written down anywhere in the repo.

## Concrete implications for implementation

1. In `docs/longitudinal-cognition-metrics.md` under "Consciousness-related
   evidence," add an explicit "unfolding test" requirement for each indicator
   row: state the minimal structurally different system that reproduces the
   same observed value, and what functional outcome the indicator must
   predict for it to count as evidence. This is a one-paragraph edit that
   operationalizes the paper instead of just citing it.
2. In `docs/experiments/05-cognitive-component-ablation.md` (or
   `docs/experiments/README.md`), extend the "not consciousness" note with the
   two-sided version: a duplicated non-integrated control matching the
   integrated system's behaviour is not evidence of integration *or* of its
   absence — it is exactly the paper's unfolded twin. Report both directions.
3. In `docs/navi-intelligence-and-learning.md` §6 ("Distinguish persistence
   from consciousness"), add a line that structural additions (recurrence,
   mesh coupling, broadcasts) earn their keep only through measurable
   functional consequences — and that functionally equivalent alternatives
   exist by construction, so no build decision should ever be sold as "more
   conscious."
4. In `docs/literature-synthesis.md` §7-8, add the correlation-approach
   permission: evidence+confidence self-model claims are a legitimate
   *functionalist* bridging program (Chalmers-style correlations), which the
   paper explicitly preserves — they only become invalid if tied to a
   causal-structure identity.
5. In `docs/engineering-log.md`, note that `self:set` claims and appraisal
   values are functional routing signals, with a pointer to this paper's
   P3/P4 — so future self-model writes don't drift into structuralist
   framings.

## Risks / tensions

- The paper is a philosophical result, not an empirical one, and it's
  contested. IIT proponents reject the premise that a feedforward twin is
  physically realizable for genuinely causal/dynamical systems (a printed,
  stateless unfolding isn't a system that *does* anything over time), and
  later work (e.g., Kleiner & Tull on the mathematical structure of phi, and
  Doerig et al.'s own follow-ups) has softened "phi is meaningless" into
  "phi is theory-relative." Use it as a guardrail, not as a proof that all
  structural metrics are worthless.
- Over-correction risk: reading the argument as "integration measures are
  junk" would undercut Navi-Brain's legitimately *functional* integration
  metrics (consolidation lineage, need dynamics, ablation interactions). The
  paper kills structure-as-consciousness-meter; it does not kill structure as
  a functional measurement. Keep those two readings separate.
- No tension with the safety invariants — the paper *supports* the no-claim
  discipline. The real risk is narrative drift: the mesh hypothesis'
  "recurrently coupled" language could quietly re-import structuralism, which
  is why implication 3 matters.
- Note the argument's empirical leg rests on first-person reports (P1), and
  Navi-Brain has no such report channel. The negative result (no structural
  meter is decisive) still binds; the positive machinery of the argument
  simply doesn't have a Navi-side dataset to lean on.

## Bottom line

Adopt — already largely adopted. Navi-Brain treats the unfolding argument as
its main guardrail in the synthesis and metrics; this paper's contribution
here is sharpening that into an explicit, testable "unfolding test" for every
consciousness-adjacent indicator and keeping the mesh narrative functional,
not structural. Most important consequence: no structural feature of the
system — recurrence, mesh coupling, broadcasts, phi-like scores — ever counts
as evidence of anything phenomenal, only as a mechanism whose worth must be
shown in measured functional outcomes.
