# 05 — Intention is Choice with Commitment

- **Paper**: Intention Is Choice with Commitment
- **Source**: Artificial Intelligence 42(2-3), 1990, DOI 10.1016/0004-3702(90)90055-5
- **Local file**: assets/papers/05-cohen-levesque-1990-intention-choice-commitment.pdf

## Core idea

Cohen and Levesque build a modal logic of belief, goal, action, and time in
which intention is *defined*, not primitive: an intention is a persistent goal
(P-GOAL), a chosen desire plus a commitment to hold it until the agent believes
it is satisfied (`BEL p`) or believes it is forever unachievable (`BEL []not-p`).
Relativizing the persistence condition gives P-R-GOAL, which is also dropped
when the justifying background evaporates (`BEL not-q`). The formalism
reproduces Bratman's three functional roles of intention (poses planning
problems, screens the admissibility of new intentions, tracks success and
replans after failure), proves that expected side-effects are *chosen but not
intended*, solves McDermott's "Little Nell" over-eager-drop problem, and — by
relativizing to other agents' goals — sketches interpersonal and interlocking
commitments. It all runs on two idealizations: limited persistence (Assumption
3.25, every achievement goal is eventually dropped) and no infinite
procrastination. The authors insist it is a *specification* for agents, not a
logic agents should reason with.

## Relevance to Navi-Brain

This is the canonical formal theory behind the intention ledger Navi-Brain
already runs. It is the explicit source for `docs/literature-synthesis.md` §3
("Persistent intentions") and feeds the thread life cycle in
`docs/ongoing-cognition.md` (commit, plan, observe, complete-or-release). It
touches `src/Model/Intention.php`, the action/outcome loop in
`src/Model/ActionTrace.php`, the thread design, and the user-authority safety
invariant — "release when the user withdraws the request" is literally a
P-R-GOAL relativization to the user's goal.

## Cross-reference to the existing system

- **Already present**: The P-GOAL / P-R-GOAL shape is recognizably the
  `intentions` table. `success_condition` is the `BEL p` drop path;
  `release_condition` is the background `q`; the `status` enum
  `active/blocked/completed/released` covers the achieved and withdrawn drops.
  `reason` carries the justification, and `dependencies` + `parent_id` sketch
  the subgoal/supergoal web. The user-authority invariant (README: "no persisted
  intention may resist an explicit instruction") is exactly the interpersonal
  "off the hook" condition. `src/Model/ActionTrace.php` implements Bratman role
  (3): expected vs observed outcome, matched/mismatched, `repair_note`; a
  failed action does not touch intention status, so the "keep the intention
  after failure" theorem holds by default.
- **Partially present**: Limited persistence exists only socially (user
  release, obsolescence), never as an internal pass — `blocked` is the closest
  thing to "believed unachievable" and is not terminal. The screen of
  admissibility (Theorem 7.1) is unenforced: `intention:add` records conflicts
  via `dependencies`, but nothing rejects or flags a new intention that makes
  an existing active one unachievable. Tracking happens, but replanning is
  manual (`intention:advance` + `repair_note`); the heartbeat reflects and
  never dispatches, so "the agent replans after failure" is data, not behavior.
- **Absent / open design**: No distinct terminal status for impossibility
  (`BEL []not-p`) versus withdrawal (`BEL not-q`). No representation of the
  chosen-but-not-intended distinction, so a mismatched side-effect can misroute
  repair effort toward the wrong target. Interpersonal and interlocking
  commitments (the mesh hypothesis) exist only as data replication scripts, not
  as goal relativization to another agent.

## Concrete implications for implementation

1. Give impossibility its own terminal status (e.g. `abandoned`) alongside
   `completed` and `released`, so the three C&L drop paths (`BEL p`,
   `BEL []not-p`, `BEL not-q`) map one-to-one onto the schema instead of all
   folding into `released` with a `closure_note`.
2. Add a cheap screen of admissibility at `intention:add` / `intention:advance`:
   before persisting, walk existing active intentions' `next_action` and
   `success_condition` against the new one's `dependencies` and reject or flag
   obvious conflicts (the egg-and-omelette case) instead of just recording them.
3. Wire the thread life cycle's "integrate, replan, wait, complete, or release"
   step to actually close or advance intentions on their success/release
   conditions. Right now cognitive threads stay records; nothing ever evaluates
   a stored `success_condition` or `release_condition`.
4. Add a bounded, proposal-only staleness pass (limited persistence) that
   reviews active intentions for "believed impossible" or "reason gone" and
   emits candidates — respecting the no-auto-promotion and no-autonomous-action
   invariants. This is the internal half of Assumption 3.25.
5. Mark action traces' `expected` outcomes that are side-effects, not the
   intended result, so a mismatch on a side-effect does not drive `repair_note`
   against the core intention.
6. Update `docs/ongoing-cognition.md` to cite the three drop conditions and the
   screen-of-admissibility theorem explicitly, and note that user authority is
   already an interpersonal P-R-GOAL relativization — making the safety
   invariant a theorem of the commitment theory rather than a bolt-on rule.

## Risks / tensions

- The paper is a normative modal-logic specification that assumes logical
  omniscience (beliefs closed under consequence), competence about primitive
  acts, and perfect consistency. Navi-Brain is a bounded, lexical,
  evidence-carrying system; the theorems do not survive contact with real
  belief revision, so treat the *drop conditions* as a spec for bookkeeping,
  not as a reasoner to embed.
- C&L assumes agents act on intentions (`HAPPENS`); Navi-Brain's hard invariant
  is to record actions without executing them. The theory is therefore adopted
  selectively: commitment is real, execution authority stays with Aku.
- The fanatical P-GOAL default is an end-of-persistence strawman the paper
  itself dismantles; Navi-Brain's user-authority release already avoids it.
- Contested ground: the paper admits it lacks default/nonmonotonic reasoning
  and causal self-reference (Searle), and its social-commitment sketch is
  explicitly preliminary. None of that weakens the core P-R-GOAL idea.

## Bottom line

Adopt — mostly confirmed already. The intention ledger is Cohen-and-Levesque
in schema form; the highest-value gap is turning the stored success and release
conditions from inert columns into something the thread lifecycle actually
evaluates, plus a real screen of admissibility at add time.
