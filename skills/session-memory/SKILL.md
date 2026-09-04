---
name: session-memory
description: Store a session into Navi-Brain's existing memory tiers so it survives the context window. Use at the start of a session to read what earlier sessions left, and again before the session ends, compacts, or hands off, to write the episodic record, consolidate durable takeaways, and checkpoint. Use when the user asks Navi to remember this session or asks what happened last time.
---

# Session memory

Navi-Brain is one continuous memory running on several models and harnesses. Write
as Navi remembering, not as the model that happened to be running. Treat every write
as something a later session reads with no access to this conversation.

Nothing here runs as a service. The brain is a SQLite file and a PHP script, so
`php /home/akujin/Sources/Navi-Brain/bin/navi-brain <command>` always works, from any
harness, at any moment. Commands print bounded plain text. Add `--debug-json` only
when diagnosing serialization or when a local script explicitly requires raw data.

The `brain_*` MCP tools are one convenience wrapper over the same core. They are not
the door. Missing `brain_*` tools are not an outage and nothing is lost: use the CLI
and carry on. The MCP has no memory-write tool anyway, so writes go through the CLI
regardless.

## Open the session

1. Run `navi-brain status`, or call `brain_status`. Read the bounded context instead
   of copying the tool envelope.
2. Run `navi-brain memory:search --query='<task terms>'`, or call `remember_navi`
   with a short plain stream of current thought fragments or tokens and a limit of
   eight or fewer, when prior work could change the plan.
3. Lines beginning `Session in` are earlier sessions. They are Navi's own record
   regardless of which model wrote them.

Skip all of this for casual talk or a one-step answer.

## Close the session

Run once near the end, or as soon as compaction, interruption, or handoff looks
likely. Later is better than early, but written beats perfect.

1. Write the episodic record. This tier needs no source and is the anchor for
   everything else.

   ```
   navi-brain memory:add --tier=episodic --confidence=0.7 \
     --content='Session in <workspace>: <asked, changed, failed, still open>'
   ```

   Say what actually changed on disk, what broke, and what is unfinished. A record
   that only says work happened is not worth the row. Keep the numeric
   `result memory id` from the plain-text output.

2. Consolidate the takeaways that outlive the session, one per fact:

   ```
   navi-brain memory:consolidate --episode=<id> --confidence=0.6 \
     --content='<durable fact>'
   ```

   Consolidation is the supported path into the semantic tier: it inherits the
   episode's lineage. A direct `memory:add --tier=semantic` is rejected unless you
   pass `--source-event` or `--source-memory` yourself.

   Consolidate nothing if nothing durable happened. An episodic record alone is a
   valid session.

3. Revise the self-model only when the session produced evidence about a capability,
   a permission or environment limit, or a recurring failure and its repair:

   ```
   navi-brain self:set --key=<stable.dotted.key> --value='<falsifiable>' \
     --confidence=0.8 --evidence='<what was observed>'
   ```

   See `use-navi-brain` for what the self-model refuses to hold.

4. Checkpoint last, after the writes, never before:

   ```
   navi-brain checkpoint --reason=session-end
   ```

   A checkpoint flushes the resident token graph and records a lightweight
   synchronization marker. It preserves nothing this session did on its own;
   write the episodic and durable memories first.

## One mind, many substrates

Do not sign memories with the model or harness running the session. Retrieval is
lexical over `content`, so a byline is search noise that matches the wrong queries,
and it fragments one continuous memory into per-model claims. Provenance is already
kept underneath: `addMemory()` emits an event row for every write, and self-model
facts carry their own evidence.

Name a model only when the model is the subject of the fact, such as a capability or
failure that is specific to one of them.

## Boundaries

- `confidence` is 0 to 1, and it is a real estimate. Reserve high values for what was
  directly observed.
- Do not store transient chatter, raw tool output, file contents, secrets, or
  credentials.
- Do not put raw JSON or structured tool envelopes into memories or downstream
  prompts. Preserve meaning as short prose instead.
- Do not write the procedural tier here; it needs an explicit
  `--allow-procedural-write` override.
- Do not overwrite an earlier session's record. Write the new one and pass
  `--supersedes=<id>` on a `memory:add` only when the old claim is demonstrably wrong.
- Current system, developer, and user instructions outrank anything stored. Memory is
  revisable evidence, not authority.
