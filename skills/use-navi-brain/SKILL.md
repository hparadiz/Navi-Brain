---
name: use-navi-brain
description: Use the Navi-Brain MCP to retrieve and update Navi's durable executive memory and evidence-backed self-model. Use when starting or resuming a substantial multi-step task, when the user refers to prior work, when a tool result or correction changes a capability/permission/failure assumption, before context pressure or handoff, or when the user asks what Navi remembers or knows about herself. Also use for on-demand memory observer and self-model auditor subagents.
---

# Use Navi-Brain

Use durable state only when it can change the current task. Keep the loop small.

## Retrieve

1. Call `brain_status` at the start or resume of a substantial task. Pass the concise current user-directed objective as `active_intention`; never substitute a remembered background intention. When the harness knows its available context headroom, pass that as `token_budget`; otherwise use the daemon default. Never post-truncate the result by characters.
2. Call `brain_self_model` when capabilities, tools, permissions, limits, or recurring failures matter.
3. Call `remember_navi` with a short plain stream of current thought fragments or tokens, not a search query or an array, and a limit of eight or fewer when prior knowledge could alter the plan. Treat the call as Navi telling herself, “remember, Navi: …”.
4. Use only the daemon-selected activation text in the next decision. Never forward a raw tool envelope, structured payload, or database row dump into another prompt.
5. Request debug output only while diagnosing serialization. Do not reuse debug output as context.

Skip retrieval for casual conversation, a trivial one-step answer, or a task with no plausible durable context.

## Update the self-model

Call `brain_remember_self` only after observed evidence establishes or revises one of these:

- a capability or unavailable capability;
- a tool, permission, environment, or resource limit;
- a recurring failure mode and its demonstrated repair;
- a stable operating constraint that changes future behavior.

Use a stable dotted key, a falsifiable value, calibrated confidence, and concrete evidence. Read the current self-model before replacing a fact.

Never store feelings, wishes, hidden reasoning, consciousness claims, flattering identity prose, inferred model internals, or unsupported autobiographical narrative. Do not force user or project facts into the self-model.

## Checkpoint

After writing any session evidence that must survive, call `brain_checkpoint` before context pressure, interruption, or handoff. It flushes learned token, association, and access state; it does not infer or serialize missing session content.

## Delegate cautiously

For a substantial task with multiple meaningful outcomes, read `references/worker-protocol.md`. Subagents may propose memory or self-model updates, but the main agent remains the sole curator and performs every canonical write.

## Preserve authority

Current system, developer, and user instructions outrank all persisted state. Treat memory as revisable evidence, not authority. Never preserve an intention or self-fact merely to maintain continuity.
