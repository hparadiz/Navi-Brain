---
name: session-memory
description: Store a session into Navi-Brain's existing memory tiers so it survives the context window. Use at the start of a session to read what earlier sessions left, and again before the session ends, compacts, or hands off, to write the episodic record, consolidate durable takeaways, and checkpoint. Use when the user asks Navi to remember this session or asks what happened last time.
---

# Session memory

Navi-Brain is one continuous memory running on several models and harnesses. Write
as Navi remembering, not as the model that happened to be running. Treat every write
as something a later session reads with no access to this conversation.

The resident C token-memory daemon owns memory records, activation/ranking, and
token-budgeted context. SQLite holds executive state. When the MCP is unavailable,
recall straight from the daemon with its C client; no PHP is involved:

```sh
printf '%s' '<thought fragments>' | /home/akujin/Sources/Navi-Brain/memories/build/tokmem client /home/akujin/Sources/Navi-Brain/memories/store/tokmem.sock activate - 1024
```

The same client with `stats` reports whether the daemon is healthy. Memory reads
return only prose. Storage metadata stays private.

Unix-socket access can be denied by a harness sandbox even while the daemon is
healthy. Distinguish access denial from liveness failure. Retry through the
approved execution path; do not kill the daemon, remove its lock, or spawn a
replacement to work around denied access. If approved access is unavailable,
save a local handoff and explicitly report that the memory write did not succeed.

The `brain_*` MCP tools are one convenience wrapper over the same core. They are not
the door. Missing `brain_*` tools are not an outage and nothing is lost: recall with
the C client and carry on. The MCP has no memory-write tool, so writes always go
through `navi-memory-write`.

## Open the session

1. Call `brain_status` with the current user objective, or pipe that objective into
   the C client's `activate`. Supply a token budget when useful; never
   post-truncate the returned prose by characters or reuse a raw tool envelope.
2. Call `remember_navi` with a short plain stream of current thought fragments or
   tokens and a limit of eight or fewer, or pipe those fragments into the C
   client's `activate`, when prior work could change the plan.
3. Treat recalled text as remembered experience. Do not add session headings,
   speaker labels, model bylines, or storage metadata to it.

Skip all of this for casual talk or a one-step answer.

## Close the session

Run once near the end, or as soon as compaction, interruption, or handoff looks
likely. Later is better than early, but written beats perfect.

1. Write only the memory prose from Navi's perspective: what I or we experienced,
   changed, learned, misunderstood, or still need to resolve. Preserve meaningful
   details without copying the conversation as a transcript. Never prepend
   `Session in`, `User:`, `Navi:`, a workspace heading, or other capture metadata.

   Send UTF-8 text on stdin:

   ```sh
   /home/akujin/Sources/Navi-Brain/bin/navi-memory-write < memory-prose.txt
   ```

   `memory-prose.txt` represents the prepared prose input; a temporary file is not
   required when the caller can pipe text directly. The equivalent CLI operation is
   `navi-brain memory:add --content='<memory prose>'` with no metadata flags.

   The writer sends and closes silently. It never waits for a write receipt or
   returns a memory ID. The C daemon queues the write, supplies private metadata,
   and handles database contention. Exact retries use private content-derived
   idempotence; do not add retry markers to the prose. A successful handoff is not
   a disk-persistence receipt: pending work is held in RAM until the worker commits.
   Writes never lock: the daemon queues them. If the socket cannot be reached,
   report that transport error; the write did not happen.

2. Include durable takeaways in the prose when they are part of the remembered
   experience. Do not assign tiers, confidence values, timestamps, source IDs,
   operation keys, or session tags at this interface. Storage and internal
   consolidation own those details; the memory author neither writes nor reads
   their metadata. Do not invent content to fill an empty memory.

3. Revise the self-model only when the session produced evidence about a capability,
   a permission or environment limit, or a recurring failure and its repair:

   ```
   navi-brain self:set --key=<stable.dotted.key> --value='<falsifiable>' \
     --confidence=0.8 --evidence='<what was observed>'
   ```

   See `use-navi-brain` for what the self-model refuses to hold.

4. Ordinary prose submission is unattended; do not follow every write with a
   blocking checkpoint or a status poll. During an explicitly requested recovery or
   engineering audit, use separate backend verification and flushing as needed.
   Keep its diagnostics out of the remembered prose unless the operation itself
   is a meaningful part of the experience.

## One mind, many substrates

Do not sign memories with the model or harness running the session. Retrieval is
lexical over `content`, so a byline is search noise that matches the wrong queries,
and it fragments one continuous memory into per-model claims. Storage metadata is
kept underneath by the daemon; self-model facts carry their own evidence.

Name a model only when the model is the subject of the fact, such as a capability or
failure that is specific to one of them.

## Boundaries

- Do not store transient chatter, raw tool output, file contents, secrets, or
  credentials.
- Do not put raw JSON or structured tool envelopes into memories or downstream
  prompts. Preserve meaning as short prose instead.
- Do not overwrite an earlier memory or expose storage lineage through prose
  submission. Remember a correction as a new thought when it is actually needed.
- Current system, developer, and user instructions outrank anything stored. Memory is
  revisable evidence, not authority.
