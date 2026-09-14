# Cognition restart review — September 2026

The first resumed lane is a public-code reflection pilot, not a declaration that
the entire cognitive architecture is repaired. Movement/parkour work was an
accidental request and is out of scope.

## What the fresh audit found

- The native C token-memory daemon was healthy. Sandboxed Unix-socket requests
  failed with EPERM, but PHP reported a locked/unresponsive store and waited for
  startup. Access-denied errors now fail immediately with the correct diagnosis.
  Approved local CLI status returned native activation in 0.09 seconds. Old
  long-lived MCP servers can still run old PHP code; reconnect that client rather
  than killing the healthy resident daemon or every other session's MCP server.
- Background services were stopped. Heartbeat logs stopped August 18; worker logs
  stopped August 19. The recorded shutdown reason was expensive SQLite polling.
  Current code already has indexed/bounded consolidation reads; the old profile
  should not be re-enabled on the assumption that its historical cost is fixed
  or still identical. The former Spark endpoint had 92 consecutive failures.
- Completed generation was being confused with cognitive benefit. Historical
  generic curiosity/challenge/audit jobs produced proposals without a specialized
  integration path. Specialized consolidation, decision, and social curators do
  exist; those should not be conflated with the unintegrated generic jobs.
- Narrative synthesis recorded an evidence hash but deduplicated by trigger ID
  or manual nonce. It now reuses the latest matching synthesis across triggers
  inside the queue transaction, while allowing failed work to be retried. The
  full synthesis prompt is hashed so instruction changes are not suppressed.
  Clock-varying compiled weights can still change that hash: this is exact
  evidence/prompt identity, not a claim of semantic novelty detection.
- The generic plain-text sanitizer removed braces and brackets from source code.
  The public source-review path now preserves exact bytes. Normal Brain status
  remains native token-budgeted prose, not a raw state envelope.
- Removed free models could become selectable when an old cooldown expired.
  Catalogue removal now clears that loophole, with explicit recovery on return.
- OpenCode errors lost HTTP status and Retry-After. The worker now preserves rate
  limit meaning, serializes local requests, persists provider-wide backoff, and
  defers fenced work without calling failed-proposal/consolidation rejection.

## Configured profile and privacy boundary

`config/cognition.php` selects `public-reflection`, pinned to
`opencode/muse-spark-1.3-contributor-free`. No paid/local/other-model fallback.
OpenCode's [Contributor privacy policy](https://dev.opencode.ai/docs/zen#privacy)
permits training on submitted prompts/completions. This lane sends only selected
code whose hashes were verified against an unauthenticated public GitHub revision.
Private memories, conversation, hearing, needs, intentions, and uncommitted code
are not included. Tools, plugins, and automatic compaction are disabled by the
isolated worker configuration and pure invocation.

The cycle is: selected published evidence changes → one model proposal → explicit
local review → wait for another evidence change. A proposal is not automatically
a memory, intention, observation, action, or accepted belief. The review records
what was useful/rejected and why; it never promotes factual memory. Failed jobs
also require review in this pilot, to prevent silent failure loops.

Source edits fail closed as `publication_required`. After deliberately publishing
an approved change, verify the exact raw public file hash, then update the source
allowlist and public revision. Do not weaken the gate to export private changes.

The worker checks its small local gate every 30 seconds when waiting, not the full
executive ledger. No inference occurs merely because a timer fired. One OpenRC
worker is sufficient; heartbeat, senses, second worker, autonomous speech, old
mind-stream, and broad narrative/consolidation scheduling stay off during this
pilot. This does not restart or change Orpheus, GPU limits, or the optional CPU
model service.

## Verification

- A synthetic non-sensitive Meta probe returned valid JSON in 9.8 seconds.
- First real code review: 25.8 seconds. Its source was public, but the old
  sanitizer damaged syntax; that limitation is recorded in its review.
- Corrected full-source review: 36.3 seconds. Stored source JSON decoded to both
  verified public hashes, and no private working/background projection was added.
  The model found the missing evidence gate, but incorrectly described duplicate
  work requests as necessarily different prompts. Review corrected that claim.
- The first repeat returned `awaiting_review` without another inference. Editing
  a selected source returned `publication_required`, also without inference.
- Inline diagnostic runs used disposable SQLite schema/activity directories and
  synthetic records, not the live private memory store. They verified exact
  synthesis reuse, changed evidence admission, failed-work retry, deferred lease
  exclusion, stale-owner rejection, exact source transport, and catalogue
  removal/reappearance. No test infrastructure was added to this testless project.
- A separate local quota check verified mutual exclusion, persistence of a
  two-hour cooldown, numeric/date/invalid Retry-After handling, 429 classification,
  and preservation of valid structured proposal content.
- One hundred local guarded idle passes took 29.8 ms total (0.30 ms/pass).
  This measures the new pilot gate, not a full legacy heartbeat or inference.
- The exact persistent-worker entry point was exercised in a 50-second
  foreground run: its Meta job completed in 30.7 seconds and it then waited for
  review. That finding repeated the already-fixed issue without the queue
  implementation in view; it was rejected as non-actionable. The next manual
  pass returned `unchanged_evidence` with no model call. The foreground run exited
  at its deliberate timeout and left no unmanaged worker behind.
- Earlier TTS episodes were recovered through their original idempotency keys,
  and stale operational self-model claims were corrected. Memory receipts must
  match every original input, including confidence, not just key and content.

The work ledger's token budget is not a proven provider-side output-token ceiling
in the OpenCode CLI path. Source input is not character-truncated. Existing wall
deadlines and transport resource guards still apply.

## Operations and remaining boundaries

Persistent OpenRC startup was completed on September 5 using KDE authentication:
`kdesu -n -c '/sbin/rc-service navi-brain-model-worker start'`. Host-namespace
status reported `started`; the worker logged `unchanged_evidence`. An initial
idle sample showed about 52 MiB RSS and 0.1% CPU, not an inference-load benchmark.
Worker 2 remained stopped. The earlier sudo-password blocker is resolved.

The waiting gate runs every 30 seconds; model inference does not. New published
evidence and review of the previous proposal are required before another model
job. Unchanged evidence causes zero further model calls. Check OpenRC status
from the host process namespace: the harness's isolated PID view can incorrectly
report an active host service as `unsupervised`.

Use `reflection:status --debug-json` for deliberate diagnostics and
`reflection:review --work=<id> --verdict=useful|rejected --note='<evidence>'` to
curate a finished proposal. `rc-service navi-brain-model-worker stop` stops the
persistent lane. Set the config profile to `disabled` to keep it disabled across
subsequent starts. Do not use the broader manual `opencode:once` as if it carried
the public-only producer's egress guarantee.

Quota coordination covers this checkout's FreeModelWorker processes, not other
OpenCode applications or remote hosts. DigitalOcean was not configured as an IP
rotation fallback. A future authorized remote worker needs one shared dispatcher
and quota state, not independent retries from each IP.

Before resuming private cognition: choose a privacy-appropriate provider or obtain
informed permission for the contributor route, check current sensory freshness,
measure a full idle scheduler pass on a safe state copy, and select useful tasks
with explicit success/rejection/closure conditions. Do not replay a month's stale
intentions or sensory events as current experience.
