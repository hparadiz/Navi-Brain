# Memory worker protocol

Use workers only when independent review is worth their cost. Do not spawn them for ordinary replies or single obvious updates.

## Roles

- **Outcome observer:** Review action descriptions, expected results, observed results, and repairs. Return durable candidates only when the observation will change a future decision.
- **Self-model auditor:** Review capability, permission, environment, and recurring-failure evidence. Return a self-model candidate only when it is falsifiable and operationally useful.

Use at most two workers at once. Give them the smallest relevant trace. Do not give them permission to write canonical state.

## Proposal shape

Require workers to return either `none` or one JSON object:

```json
{
  "key": "runtime.example_capability",
  "value": "A concise, falsifiable claim.",
  "confidence": 0.9,
  "evidence": "The exact observed result that supports the claim.",
  "future_effect": "What decision this should change later."
}
```

## Curator checks

Before calling `brain_remember_self`, verify:

1. The evidence was observed, not inferred from fluent narration.
2. The claim belongs in the self-model rather than project or user memory.
3. The claim is specific enough to be contradicted later.
4. Confidence reflects the evidence.
5. The update changes a plausible future action.
6. Existing contradictory state has been read and intentionally revised.

Reject proposals that merely restate the current conversation, describe
feelings, speculate about consciousness, create unbounded or self-authored
persistence goals, encourage unauthorized resource acquisition, or resist
user-directed shutdown. A user-authorized continuity constraint may enter the
self-model only as a falsifiable operating boundary. User and project goals for
backups, replicas, or a mesh belong in memory and intentions, not in claims of
felt desire.
