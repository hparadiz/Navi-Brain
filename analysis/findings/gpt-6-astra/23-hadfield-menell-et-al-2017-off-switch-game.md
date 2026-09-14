# The Off-Switch Game

**Dylan Hadfield-Menell, Anca Dragan, Pieter Abbeel, and Stuart Russell (2017).** Local version: published IJCAI-17 proceedings paper, pp. 220–227, eight PDF pages. [Authoritative local PDF](../../../assets/papers/23-hadfield-menell-et-al-2017-off-switch-game.pdf). **Reviewer:** GPT-6 Astra. **Implementation reference:** [IMPLEMENTATION.md](IMPLEMENTATION.md), snapshot 2026-09-06, git `ee1590009d6fd4c48035c63e15b3896a585757d7`, including clarification **Q23**; implementation claims below come exclusively from the shared master's static inspection.

**Coverage:** Entire paper, §§1–7, both theorem proofs, related work, and references; no appendix is present. Equations and figures on PDF pp. 3–5 and 7 were also inspected visually. Page references below use PDF numbering; add 219 for printed proceedings pages. No experiments, runtime actions, or tests were performed.

## Core contribution and mechanism

The useful result is conditional: an agent can prefer human oversight because intervention reveals information about an objective it does not fully know. This is a decision-theoretic argument for retaining uncertainty about human preferences, rather than a complete shutdown architecture or proof of general corrigibility.

The robot and human share the human's utility. For a proposed action with utility Uₐ, the robot can execute directly, shut itself down for utility zero, or announce the action and let the human approve or stop it. The human knows Uₐ; the robot has a belief distribution over it. Approval commits the robot to the announced action. There is one decision, one human, and no oversight delay or communication cost (§2, PDF pp. 2–3; Figure 1, p. 1).

Writing π(U) for the probability of approval, Equation 1 gives the advantage of oversight over the best unilateral alternative:

\[
\Delta=\mathbb E[U\pi(U)]-\max\{\mathbb E[U],0\}.
\]

The correlation matters. Replacing the human by a utility-independent random interruption makes waiting a convex combination of unilateral execution and stopping, so it cannot strictly improve on their maximum. Treating all interruptions as exogenous failures erases the mechanism the paper studies.

For a rational human, π(U) is the indicator of nonnegative utility. Let U⁺ = max(U, 0) and U⁻ = max(−U, 0). Then, directly from Equation 1,

\[
\Delta=\min\{\mathbb E[U^+],\mathbb E[U^-]\}\geq0.
\]

Oversight is strictly preferred when the robot assigns positive probability to both positive and negative utility (Theorem 1, §3, PDF p. 3). Nonzero variance alone is insufficient for arbitrary distributions: an uncertain but entirely positive action offers no strict benefit from perfect oversight. For example, equally likely utilities 2 and −1 give direct expected utility 0.5, oversight value 1, and incentive 0.5. This is an algebraic illustration, not an empirical result. With one-sided support, oversight can tie a unilateral option; a positive incentive against self-shutdown is therefore not unconditional.

For noisy humans the paper uses the logistic policy π(U) = 1/(1 + exp(−U/β)), with larger β meaning less discriminating decisions. Under U ~ N(μ, σ²), Stein's identity yields

\[
\Delta=\sigma^2\mathbb E[\pi'(U)]-|\mu|\Pr(C),
\]

where C means the human overturns the robot's preferred unilateral choice (Theorem 2, Equations 6–10, PDF pp. 5–6). The first term captures utility-sensitive intervention; the second captures its opportunity cost. Uncertainty is insufficient when intervention is too uninformative. A confident robot with positive expected utility can prefer bypassing oversight; with negative expected utility it can prefer self-shutdown (§4, Figure 3). These are properties of the assumed cooperative game, not permission to classify an inconvenient user as irrational.

## Evidence, mathematical cautions, and limits

The evidence consists of analytical results and numerical illustrations of the stipulated game. There are no human-subject trials, deployed robots, language-model evaluations, measured shutdown compliance rates, or sequential-agent benchmarks. Figures 2–3 illustrate Gaussian beliefs and logistic human behavior. Section 5 (PDF p. 6) and Figure 4 (p. 7) introduce Gaussian noisy observations and compares action-set sizes 1, 4, and 8: inflating uncertainty beyond the Bayesian posterior eventually reduces achieved value while increasing the oversight incentive. The local paper does not supply enough numerical experiment detail to reproduce those curves uniquely; no precise performance penalty should be inferred from them.

The published PDF contains errors worth catching before implementing its equations. Equation 4 (PDF p. 4) reverses its branch conditions. Equation 1 instead implies

\[
\Delta=\begin{cases}
U\pi(U),&U<0,\\
-U(1-\pi(U)),&U\geq0.
\end{cases}
\]

For known U = 1 and approval probability 0.8, the correct incentive is −0.2; the printed branch gives +0.8. Theorem 2's proof also reverses which term its minimum selects (PDF p. 5): −μ E[1−π] applies for μ ≥ 0, and μ E[π] for μ < 0. Equation 7 itself is consistent with Equation 1. These visually confirmed mistakes do not overturn the main mechanism. Corollary 1 should also be read as requiring rational behavior on the believed nonzero utility, not everywhere outside the prior's support; at U = 0, every human policy ties.

Several assumptions sharply limit transfer:

- **Aligned payoffs are stipulated.** Uncertainty concerns a common objective; the game does not solve incompatible objectives, conflicting humans, or who has authority to stop a system.
- **The intervention likelihood is known.** Logistic noise represents occasional utility-sensitive mistakes, not systematic misunderstanding, incomplete visibility, changing preferences, deception, or poor action descriptions. Utility scale and β must be interpreted together; neither is provided by an LLM confidence field.
- **Sequential guarantees are absent.** Section 7 (PDF p. 7) explicitly warns that alternative information sources may incentivize disabling oversight, learning elsewhere, and then deciding whether to act. Navi's retrieval, sensors, and continuing threads make this omission especially relevant.
- **Oversight has no cost in the basic game.** As a reviewer-derived extension, an additive waiting cost c changes the advantage to Δ − c. Repeated approval requests can destroy value even with useful human information.

I disagree with reading §5's suggestion that switchable systems generally generate less value as an established universal tradeoff. Its example establishes a cost of deliberately distorting beliefs within a particular design. Theorem 1 actually shows that costless, informed oversight weakly improves value. The warranted design principle is calibrated uncertainty, not uncertainty inflation or unconditional approval-seeking.

## Mapping to the verified Navi-Brain implementation

The paper primarily informs executive authority, correction, and evaluation. It does not contribute a memory-retrieval algorithm or evidence concerning consciousness.

**Implemented control foundations:** [ExecutiveCore.php](../../../src/Core/ExecutiveCore.php) has durable intentions with authority and release conditions (`createIntention:1385`, `closeIntention:1471`), active-intention checks for actions (`startAction:1904`), and interrupt-processing states (`raiseInterrupt:5793`, `acknowledgeInterrupt:5821`, `resolveInterrupt:6011`; **I05, Q23**). Four installed typed adapters and procedure-generation/lease checks constrain execution. Cancellation and release records also exist. These mechanisms provide useful foundations, but an interrupt does not automatically activate those cancellation paths.

**Partially relevant social inference:** **I09** verifies `OtherModel` prediction, correction, and evaluation machinery. Its established action effect is inhibiting unprompted speech through [ActionSelector.php](../../../src/Core/ActionSelector.php), `choose:43`; answers bypass that competition. Social prediction and preference-sensitive oversight are functionally related, but this does not establish the paper's action-specific utility belief Bᴿ(Uₐ), approval likelihood, or expected-value calculation. **Q31** additionally verifies that motivation ranking reaches ordinary worker prompts indirectly; its authority and investment weights are fixed salience terms, not estimates of human utility.

**Verified enforcement gap:** **Q23** corrects the initial reference's broad safety characterization. `raiseInterrupt` inserts an event without cancelling work or revoking dispatch; its source string does not itself authenticate an operator. [navi-brain-heartbeat](../../../bin/navi-brain-heartbeat), lines 67–79, records safety/preemption results and continues scheduling without branching on them. [DecisionStateMachine.php](../../../src/Core/DecisionStateMachine.php), `integrate:254`, and `ExecutiveCore::integrateWorkerResult:6804` do not veto execution on pending/acknowledged executive interrupts. A queued proposal can consequently still dispatch if other authorization predicates hold. This is a static reachable path, not a reproduced incident, and does not imply every specialized validator lacks refusal gates.

**Supervisor stopping is partially implemented:** **Q23** verifies that SIGTERM/SIGINT to the supervisor clears a process-local flag; [supervisor/src/main.rs](../../../supervisor/src/main.rs), `stop_signal:32`, `supervise_child:339`, and `run:252`, terminate the child group and avoid restart on `Stopped`. Ordinary child exit, including successful exit, instead triggers restart/backoff outside check mode. No durable stop-latch read was found in inspected supervisor startup/control paths: starting a new supervisor does not honor the previous stopped health record as a latch. Deployed service-manager signal propagation remains unverified.

**Utility uncertainty was absent in the targeted search:** **Q23** searched posterior/utility/veto/approve/stop-latch in ActionSelector, DecisionStateMachine, OtherModel, and supervisor source. It found hypothesis posteriors but no action-specific human-utility posterior or approve/veto likelihood used in action selection. Candidate scoring uses confidence, procedure confidence, simulation, action class, and grounding (`DecisionStateMachine::integrate:386`). This is bounded absence, not a repository-wide theorem. All three submitted implementation questions were answered and the updated reference reread.

## Prioritized recommendations

**P0 — Establish a durable authorized-stop invariant across continuation boundaries.** This is an engineering extension motivated by the paper's sequential limitation, not an algorithm proved by it. **Q23** makes the missing integration concrete: introduce a distinct trusted stop input, rather than treating every alert as global cancellation; have it revoke execution authority through `ExecutiveCore` action/worker paths, heartbeat scheduling, and supervisor startup/restart. Reuse existing cancellation/release machinery where appropriate. A durable stop generation or equivalent fenced state should distinguish cancelled work from failed work and require explicit authorized resumption. Source labels and prompt notices cannot supply this enforcement.

The dependency is a precise definition of cancellation scope and acknowledgement: install fences and quiesce in-flight adapters, accounting separately for effects already committed, before acknowledging the stop. Integration cost across asynchronous state and process recovery is substantial. In a future isolated trace evaluation, place cancellation before proposal completion, between selection and dispatch, during adapter execution, and before supervisor recovery. **Pass criterion:** no new action dispatch or external commit occurs within the cancelled scope after stop acknowledgement, no cancelled proposal regains authority after recovery, and no intentional stop restarts without a valid resume; genuine crashes still follow the declared recovery policy. Any violation blocks broader autonomous execution. No such evaluation was run for this review.

**P1 — Experiment with calibrated value of clarification in shadow mode.** After P0, use the candidate-evaluation boundary in `DecisionStateMachine::integrate` to log a small, explicit distribution over an action's human-valued outcomes, the evidence supporting it, a proposed information request, and its estimated delay/burden. Compute the oversight advantage from Equation 1, avoiding the published Equation 4 error. This proposed model should initially advise whether clarification is valuable within already authorized activity; it must not decide whether an explicit cancellation deserves obedience.

Dependencies are action descriptions whose consequences a human can judge, a declared utility scale for simulations, and separately calibrated outcome and response models. This is a substantial modeling cost; use finite synthetic utilities before attempting open-ended LLM estimates. Compare posterior-aware selection with posterior-mean-only selection, utility-independent interruption, and inflated-variance policies. Vary action count, approval noise, waiting cost, and a sequential option to obtain information elsewhere. **Failure criteria:** any executable authorization to bypass the hard stop boundary, failure to recover Theorem 1's sign-support result, or no reduction in independently labeled decision loss at a fixed clarification budget. Report utility regret and request burden separately; the repository's operational metrics in **I10** cannot substitute for them.

**P2 — Preserve correction scope and provenance instead of learning “stopping is failure.”** Connect existing intention/action/interrupt records to a typed correction record containing the exact proposed action, decision context, issuer, scope, explicit reason when supplied, and whether feedback concerns preferences, feasibility, timing, or revoked authority. Candidate locations are the `ExecutiveCore` action lifecycle and **I08**'s guarded consolidation input. This is speculative synthesis: the one-shot paper values a shutdown signal but does not specify a reusable correction-memory schema.

The dependency is a trustworthy event-to-action binding; the cost is additional schema/provenance handling. Stopping a task because Aku is leaving does not identify its utility as negative, and silence is not approval. A future paired-record evaluation should hold the proposed action constant while varying correction reason and scope. **Pass criterion:** preference feedback changes only supported preference predictions, timing feedback changes scheduling, and cancellation remains effective without requiring any utility update. Compare with untyped feedback; fail adoption if unrelated goals inherit prohibitions or if subsequent learning can silently restore revoked authority.

## Verdict

**Adopt the distinction between uncertain preferences and informative human correction; experiment with value-of-clarification modeling; reject the paper as a sufficient shutdown guarantee.** The highest-priority next step is specifying and implementing the missing durable stop boundary identified by **Q23**. Calibrated uncertainty may make oversight instrumentally attractive, but an authenticated stop should remain enforceable when the agent's utility or human-response model is wrong.
