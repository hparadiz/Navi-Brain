# Active cognition implementation handoff

Updated 2026-09-07 05:58 UTC. This is an in-progress index, not completion.

## Current task and time

Aku requested at least two hours of substantive implementation. The resumed productive pass began at **04:24:20 UTC** and must continue until at least **06:24:20 UTC**. The earlier 30% weekly reserve explicitly applies to an earlier prompt and does not stop this pass. The prior idle gap does not count.

Latest direction: background work must not depend on SOTA usage. Use cheap OpenCode inference sparingly, about one or two prompts per90-minute dream cycle, skip quiet cycles, clear redundant active context and derive useful assertions while retaining evidence. Eight prompts cannot guarantee provider availability; one per90 minutes permits up to16 per continuously active day, not an eight-per-day cap.

Explicit permission to create tests/infrastructure remains pending under supplied AGENTS. No tests, fixtures or benchmarks were created. Ordinary read-only operator inspection is not test-suite creation: root ran `php -n bin/navi-brain-model-worker --status` at05:56 UTC. It reported configured native-maintenance/local_only and missing_or_unreadable quota with unknown eligibility, without application bootstrap, database access, credentials or provider calls. No worker pass, live memory probe, migration, deployment, service restart, purchase or commit ran.

Private dream inference remains disabled pending the unanswered data-use question. All87 paper reviews and the single curated deep finding remain intact.

## Current implementation

The [implementation checkpoint](cognition-implementation-2026-09.md) now covers current work. Architecture reports00–07 cover representation, conversion, accounting, compiler analysis, publication, reference queues and hydration bounds.

- Later correction from the September 7 `memory:add` repair: schema23's original integration-claims table/index were already deployed. Their exact checksum is restored; later additions moved to schema24, which was applied after a checked SQLite backup. The planning claims, dedicated work_item_lanes, partial recovery/workspace indexes and durable consolidation/workspace/decision scan singletons now exist in the live database. There is no WorkItem.worker_lane column. This later repair did not restart services or deploy the remaining runtime changes.
- Planning publication is atomic. Exact process-owned integration, dead-owner recovery and local relinquishment proof prevent stranded or duplicated decisions. Native upkeep also settles exact never-dispatched started/held actions, recording idempotent refusal episodes without procedure learning or adapter execution. General/restricted action cursors are independent.
- Workspace priority/audit traversal and limit-one phase survive --once. Selection commits before native work. Scope/role locks protect projection replay; pointerless adoption stays deferred. Procedure references use native procedural memory IDs, with TTL/status eligibility.
- Dream admission splits bounded history/recent input, uses three newest selections then one oldest, and persists independent traversal/recovery cursors. Every supported/rejected source is hash-checked before settlement. Changed source versions reset attempts; event identity is not claim coverage. No automatic truth supersession.
- Dream v2 stores a marker, ordered IDs, source hashes, version IDs and prompt hash. Fresh dispatch reuses ephemeral prepared text; resumed work reconstructs neutrally before reservation. Exact leased source drift cancels only its assignment. Incremental JSON sizing preserves exact bytes. V1 remains supported.
- One prompt per5400 seconds defaults; max2 needs explicit configuration and same-stack continuation after acceptance. Persistent reservations never refund or accumulate credits. MiMo direct transport disables thinking, caps output512 tokens, and sends one nonretrying POST. Lease/manifest checks precede reservation.
- Launcher supports --status before bootstrap, --help, --once and explicit profiles. Default remains native/local-only. Status reads at most16KiB of published quota state and never grants admission.
- ActivityBus now uses one reused nonblocking datagram socket per publication, closes in finally and preserves packet privacy. Subscriber glob enumeration remains unbounded.
- Native optimizations include byte digests/length, segmented matching/equality, in-place postings, smaller final ACTIVATE sort, cached manifest hashes, exact metadata comparison, sorted-tail ID allocation and an index for receipt MAX. Typed provenance, close-failure cleanup and FIFO-open protection remain. Current frozen C SHA before any next paging patch: 55241f451dce909f2bab162d3a885f7e9fde37e4a49b2de0b49120fa2df41d61.

## Verification and remaining work

A prior combined PHP lint covered100 files; later changes were relinted. Native warning builds and focused Clang checks pass. Nine prior whole-file baseline Clang sites remain qualified; GCC fanalyzer earlier hit its2GiB cap. Recent artifacts: /tmp/navi-native-id-tail-gpoikg33 and /tmp/navi-native-receipt-index-aptknvlx.

Row bounds are separate per phase, not byte/latency guarantees. Stored-result recovery may integrate up to two source-limit pages without inference. Crash after cursor commit defers a page until wrap. PAGE/LIST above64MiB currently fail the entire frame; source_lineage is evaluating safe positive-limit whole-prefix behavior. Exact-count legacy PAGE loops in WorkingMemory and ProceduralMemory must be updated or a new command used before that change.

After compaction, read the actual transcript around the latest dream-cycle user prompt and all subsequent messages: /home/akujin/.codex/sessions/2026/09/06/rollout-2026-09-06T00-01-47-01a07585-f294-72b1-b59d-9792687da1a9.jsonl. This handoff is only an index.

Check agents before editing. Provider status/quota code is frozen awaiting master review. Master recovery/ActivityBus is frozen; provider cleared ActivityBus. Source_lineage owns native paging proposal/C. Root owns integration/schema/docs. Continue useful work until06:24:20 UTC, then final combined checks, documentation SHA update and canonical continuity capture without initialize. Do not deploy unverified mixed-version code or export private memories.

Canonical memory now includes episode7888/checkpoint951 at06:00 via /tmp/navi-astra-dream-session.php, without initialize, using key navi-cognition-2026-09-07-dream-in-progress-0600. Episode7887/checkpoint950 at05:14 is historical. Future captures need a NEW idempotency key. Navi-Body voice transport began failing around05:47; keep attempting speak_navi and always send written replies.
