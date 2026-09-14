# Level-zero recall

Recall is an independent availability boundary. Executive migrations, dreams,
working-memory projection recovery, desktop initialization, and model providers
must never be prerequisites for the MCP handshake or memory retrieval.

## Implemented repair (2026-09-07)

The existing C token daemon has loaded 7,888 memories. No store conversion or
reimport was required. The outage was an executive migration 23 checksum mismatch
combined with eager executive initialization in both entry points. The deployed
migration contains only part of the now-edited migration definition. Its checksum
and schema have not been rewritten by this repair.

CLI and MCP now load `bootstrap/recall.php`, a project-only autoloader with no
Composer, framework, executive database, or migration initialization. Normal
`brain_status` and `remember_navi` call the token transport directly. Executive
tools initialize on demand inside the per-call error boundary, so their failure
leaves the MCP connection and recall available. Desktop initialization is also
lazy. Normal CLI status, recall, memory search, and help avoid executive startup.
Debug executive status intentionally still requires the executive.

`navi-brain recall:init` converges on the existing daemon using the existing
startup protocol: probe ABI, acquire a bounded startup lock, probe again, prove
exclusive store ownership before removing a stale socket, launch once, and wait
for ABI readiness. Repeating startup does not create memories, import data, run
migrations, or replace a healthy daemon. A denied socket connection is an access
error and must never be treated as proof that the daemon is dead. An unresponsive
locked store or incompatible protocol produces an explicit error.

The native binary has its own direct client path with no PHP dependency:

```sh
navi-brain recall:init
navi-brain recall --query='current thought fragments' --token-budget=1024
navi-brain status --active-intention='current user objective'
navi-brain memory:search --query='current thought fragments' --limit=8
printf '%s' 'current thought fragments' | navi-brain-tokmem client \
  /home/akujin/Sources/Navi-Brain/memories/store/tokmem.sock activate - 1024
```

`navi-brain`, `navi-brain-mcp`, and `navi-brain-tokmem` are installed as links in
`~/.local/bin`. The last resolves to the existing compiled C executable. The CLI
and MCP are PHP transports. `NAVI_TOKEN_MEMORY_STORE` overrides the store for the
PHP transports; the native client takes its socket explicitly.

## Idempotence and failure boundaries

Startup is idempotent. Recall is intentionally a learning read: delivery and cue
counters can change. Blindly retrying a lost recall response is therefore not
exactly-once learning; the current client does not automatically retry a request
after it has been sent. Memory publication uses the native receipt keys and
request digests for idempotent retry. These are different contracts.

No implementation can promise availability after disk loss, corrupted records,
resource exhaustion, a killed runtime, or denied access. The guarantee here is
that unrelated executive failures do not block recall, and failures are explicit.
The existing binary and store format have been preserved during this repair.

## Further hardening design (not deployed)

1. Give the token daemon a dedicated OS service, independent of the executive
   heartbeat. Keep cold-start locking and service ownership coordinated so a
   service restart adopts a healthy on-demand daemon rather than fighting it.
   Explicit user stop must disable respawn; recall must not override it.
2. Publish versioned native executables atomically and retain the last working
   version. Never rebuild over the running executable or couple executable
   selection to an executive schema deployment. The installed link currently
   follows the repository build; immutable release pinning remains future work.
3. Validate new store formats on a consistent copy, keep readable snapshots, and
   promote only after checksum and recall verification. A failed migration must
   retain a compatible executable and store pair. Never reimport to repair a
   transient connection failure.
4. If exactly-once recall learning is required, add bounded, digest-bound request
   receipts to ACTIVATE and replay the original response for the same key.
   Do not confuse idempotent startup with deduplicated cognitive reads.

## Verification

Live MCP initialize, tools/list, brain_status, and remember_navi succeeded with
an intentionally inaccessible executive database path. PHP lint and whitespace
checks passed. No test suite or test infrastructure was created. The executive
migration mismatch remains separate work.
