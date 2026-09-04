# tokmem

tokmem is Navi-Brain's token-native Memory prototype. It uses an online,
lossless tokenizer owned by the store, keeps the complete token registry and
recall graph resident in a C daemon, and treats token IDs as the cognitive ABI.
No model tokenizer, JSON document, PHP serialization, or ORM object enters
recall.

## Build

SQLite development headers and pthreads are required.

~~~sh
make
~~~

The executable is build/tokmem.

## Store layout

~~~text
STORE/
  catalog.sqlite3
  sqlite-sequence
  memories/
    TIER/
      ID/
        content.memory
        metadata.memory
        manifest.sha256
~~~

Every memory has its own tier/ID directory. content.memory and metadata.memory
are both versioned binary token streams. manifest.sha256 contains the SHA-256
of the exact bytes of both blobs. Publication stages and fsyncs all three files
and their directory before an atomic no-clobber rename. The tier directory is
then fsynced. STORE/memories is also fsynced before every record publication,
covering the first record in a newly-created tier. New store, tier, staging,
and record directories are mode 0700; a process-wide 0077 creation mask and
explicit mode 0600 keep blobs, manifests, catalogs, journals, and sidecars
private without chmodding pre-existing stores.

The catalog is control-plane state with seven tables:

- token_translation maps append-only IDs to exact byte segments, optional
  left/right composite parentage, and the three token counters;
- memory_accounting binds each published memory ID and tier to both blob
  digests for crash reconciliation and exactly-once write accounting;
- neutral_accounting_intent journals counter-neutral publications before any
  filesystem rename, so import, catch-up, and repair cannot acquire synthetic
  write experience after a crash;
- pair_observation persists tokenizer formation history;
- link_reinforcement persists directional recall traversal learning;
- memory_state persists per-memory access counts used by hot ordering.
- operation_receipt durably binds retry keys to request digests, operation
  kinds, reserved memory IDs, and pending/complete state.

Token counters are independent saturating unsigned 64-bit little-endian blobs:

- write_count: occurrences committed by successful cognitive creates and
  metadata changes;
- read_count: content-token occurrences delivered by GET, QUERY,
  RECALL_RECORDS, ACTIVATE, or an explicitly COUNTED FETCH/LIST/BATCH/OBSERVE
  operation;
- usage_count: cue-token occurrences used by QUERY, RECALL_RECORDS, or ACTIVATE.

Mechanical SQLite import is counter-neutral. Before a neutral publication, the
store commits a digest-bound neutral_accounting_intent; after the record rename,
the accounting marker and intent deletion commit together. Startup deletes an
intent whose record was never published, finalizes an exact published record
without incrementing counters, and rejects every ambiguous digest/tier state.
Startup validation, checksum verification, index rebuilding, inspection, and
SQLite export are also counter-neutral. Cognitive publications without a
neutral intent retain exactly-once write reconciliation. Orphaned/mismatched
markers and invalid composite parentage are rejected.

## Import and export

Import opens the source read-only, starts a consistent read transaction, and
imports every row regardless of tier or status:

~~~sh
build/tokmem import STORE ../var/navi-brain.sqlite
~~~

The source is never modified. Import preserves the memories sqlite_sequence
value. Live creates advance it durably; export uses the greater of that value and
the current maximum memory ID. Use --daemon to enter resident service after a
successful import:

~~~sh
build/tokmem import STORE ../var/navi-brain.sqlite --daemon
~~~

After an initial import, an offline catch-up reconciles every source ID in one
read-only SQLite snapshot. Exact rows are no-ops, mutable metadata drift is
updated counter-neutrally, and missing source rows are appended with their
original IDs. Content, tier, or created-at drift and store IDs absent from the
source fail closed. The source sequence is reconciled with its maximum ID,
reserved receipt IDs, and the store's current sequence. This is intended for
the final stopped-writer cutover window:

~~~sh
build/tokmem catchup STORE ../var/navi-brain.sqlite
~~~

Catch-up and export refuse to run while any operation_receipt is pending.
Pending CREATE/REPLACE records may exist physically for exact replay, but are
hidden from every public fetch, list, rank, query, and recall operation until
the receipt commits.

Import and export build sibling staging targets, fsync them, and publish with an
atomic no-clobber rename; a concurrently-created destination is never replaced.
Export refuses to overwrite an existing path. It recreates the current
memories table, its three indexes, all column values and NULLs, explicit IDs,
content bytes, and sqlite_sequence:

~~~sh
build/tokmem export STORE /tmp/export.sqlite
~~~

## Resident daemon

The daemon is a foreground, long-lived process. It validates every manifest,
loads every token translation/counter into its resident segment hash and trie,
rebuilds content-only postings and directed links, and listens on a mode-0600
Unix-domain socket:

~~~sh
build/tokmem daemon STORE
build/tokmem daemon STORE /tmp/tokmem.sock
~~~

The default socket is STORE/tokmem.sock. One exclusive store lock prevents a
second process from opening the store while the daemon is resident.

The PHP `Memory` model is a thin adapter to this daemon; it does not read or
write the legacy SQLite memories table. Private operations negotiate the
explicit `TOKMEM/1` ABI. On its first operation, one starter
takes a private lock, removes only a provably stale socket, launches the daemon
in a detached session, and waits for readiness. Concurrent PHP workers
converge on that one daemon. Warm operations connect directly without a STATS
preflight. A failed connection clears readiness and performs one guarded
restart/retry. A connected daemon with an incompatible ABI fails closed rather
than being replaced. Memory writes are rejected while the PHP process has an open
SQLite application transaction, avoiding cross-store lock coupling.

Client commands:

~~~sh
build/tokmem client SOCKET stats
build/tokmem client SOCKET get TIER ID
build/tokmem client SOCKET fetch ID
build/tokmem client SOCKET list TIER|- STATUS|- id|updated_at ASC|DESC LIMIT
build/tokmem client SOCKET query CUE_FILE [LIMIT]
build/tokmem client SOCKET recall-records CUE_FILE [LIMIT]
build/tokmem client SOCKET activate CUE_FILE TOKEN_BUDGET
build/tokmem client SOCKET add TIER ID CONTENT_FILE
build/tokmem client SOCKET create OP_KEY METADATA_FILE CONTENT_FILE
build/tokmem client SOCKET update OP_KEY METADATA_FILE CONTENT_FILE
build/tokmem client SOCKET replace OP_KEY NEW_METADATA_FILE CONTENT_FILE OLD_METADATA_FILE
build/tokmem client SOCKET flush
build/tokmem client SOCKET close
~~~

get emits one whitespace-separated token-ID line. query emits one bare token-ID
line per recalled memory. It never emits an ID, tier, timestamp, score,
confidence, provenance, or metadata wrapper around cognitive content.
ACTIVATE treats its body as incoming thought, spreads activation through the
resident token/link graph, and emits only decoded content from active working,
semantic, or procedural traces that fit its native token budget. Raw episodic
session captures remain available to explicit recall instead of spilling into
every status read. Separators count against the same budget.
Before workspace ignition, ACTIVATE measures distinct contiguous cue-span hits
and their matched bytes directly against decoded candidate content. This
sequence-level signal outranks loose BPE constituent overlap. Only the strongest
equal-coverage cohort can enter the returned workspace, and byte-identical
traces are collapsed there. Whole traces are preferred; if none fits, the
strongest trace is cut only at a resident token boundary. Among traces with equal
span coverage, prior access and newer update time outrank token occurrence and
association strength. Only emitted tokens acquire read evidence and only emitted
memories acquire access evidence.
FETCH, LIST, BATCH, and RECALL_RECORDS return current application records in a
bounded positional batch frame. FETCH/LIST/BATCH have explicit COUNTED and
NEUTRAL modes; OBSERVE counts only a caller-selected ID set without returning a
second copy of its payload. PAGE provides bounded ID-ascending traversal after
an explicit cursor. COUNT reads the resident tier/status index after an
exclusive ID cursor without changing counters. PROVENANCE returns the
highest-ID visible record with an exact source-memory or source-event match and
optional tier/status filters, also counter-neutral. RANK_RECORDS returns at most 100 full candidate records,
counts cue use and traversed-link reinforcement, and deliberately does not count
candidate reads; callers OBSERVE only accepted records. RECALL_RECORDS and both public recall forms count
delivered content reads. CREATE assigns a monotonically increasing ID. CREATE,
UPDATE, and compound REPLACE require caller-owned 64-hex SHA-256 operation keys,
so retrying an ambiguous request returns the same canonical record without
repeating the mutation. Receipt identity ignores created/updated timestamp drift
but includes all semantic metadata and content. Reusing a key for different
semantics or a different operation fails closed. UPDATE permits metadata changes
only; an episodic record may leave active but can never return to active, which
makes ascending consolidation cursors final for every lower ID. REPLACE
publishes immutable new content and retires the old record under one receipt.
Background cognition uses the same explicit modes.

The wire protocol is one bounded request per connection:

~~~text
TOKMEM/1 ABI
TOKMEM/1 STATS
TOKMEM/1 FLUSH
TOKMEM/1 CLOSE
GET TIER ID
TOKMEM/1 FETCH COUNTED|NEUTRAL ID
TOKMEM/1 LIST COUNTED|NEUTRAL TIER|- STATUS|- id|updated_at ASC|DESC LIMIT
TOKMEM/1 PAGE COUNTED|NEUTRAL TIER|- STATUS|- AFTER_ID LIMIT
TOKMEM/1 COUNT TIER|- STATUS|- AFTER_ID
TOKMEM/1 PROVENANCE MEMORY|EVENT SOURCE_ID TIER|- STATUS|-
TOKMEM/1 BATCH COUNTED|NEUTRAL RECORD_COUNT BYTE_LENGTH\n<ID lines>
TOKMEM/1 OBSERVE RECORD_COUNT BYTE_LENGTH\n<ID lines>
QUERY LIMIT BYTE_LENGTH\n<exact bytes>
TOKMEM/1 RECALL_RECORDS LIMIT BYTE_LENGTH\n<exact bytes>
TOKMEM/1 ACTIVATE TOKEN_BUDGET BYTE_LENGTH\n<exact bytes>
TOKMEM/1 RANK_RECORDS TIER|- STATUS|- LIMIT BYTE_LENGTH\n<exact bytes>
ADD TIER ID BYTE_LENGTH\n<exact bytes>
TOKMEM/1 CREATE OP_KEY METADATA_LENGTH CONTENT_LENGTH\n<metadata><content>
TOKMEM/1 UPDATE OP_KEY METADATA_LENGTH CONTENT_LENGTH\n<metadata><content>
TOKMEM/1 REPLACE OP_KEY NEW_METADATA_LENGTH CONTENT_LENGTH OLD_METADATA_LENGTH\n<new metadata><content><old metadata>
~~~

Responses are OK BYTE_LENGTH or ERR BYTE_LENGTH, followed by exact bytes. Input
bodies and response capacity are physically capped at 64 MiB, query result count
at 1,000, and ACTIVATE budgets at 64 Mi native tokens.

The listener preadmits 128 ordinary incomplete sockets plus one 250 ms fast-lane
slot, enforces one absolute five-second ordinary deadline, and enqueues only a
complete, syntactically bounded request. The admitted queue holds at most 64
connections, is served by eight workers, and has a 128 MiB aggregate
declared-request budget. Workers reserve response memory before building a
reply; the shared cap admits at most two 64 MiB replies plus one 4 KiB allowance
per response slot. A separate 72-slot writer polls every reply socket
nonblockingly under an absolute five-second send deadline, so a client that
does not read never occupies a worker. Workers release shared admission and
return as soon as state work and response enqueue finish. The first complete
CLOSE receives priority, atomically stops new admission, takes the exclusive
barrier, waits for already-admitted state work, flushes only stale resident
state, then makes the writer discard every slow non-CLOSE reply before shutdown.

The maintenance thread gives changed tokens and outgoing links separate
deduplicated priority queues. Token counter changes copy at most 64 stable
ordering snapshots per interval, ordered by usage, read, write, then token ID,
and heap-fix those entries. Heap positions are locators only; recall comparisons
use the snapshot tuple itself, so partial lazy maintenance remains deterministic.
Cue roots remain exhaustive and first; the bounded constituent link-source
frontier uses that lazily maintained hot order. In one bounded link operation an
edge stronger than the weakest current query
edge swaps into the window, then the 16-slot prefix is sorted exactly. QUERY's
hot traversal therefore does not wait for a graph-wide sweep, even when several
window edges changed in one ADD. The thread also drains
at most 256 entries from each of four independently deduplicated persistence
queues per interval: token counters, pair observations, directional link
reinforcement, and memory access state. Consumed queue prefixes are compacted
before growth, bounding storage by the live high-water mark. flush, close,
SIGINT, and SIGTERM synchronously drain all four persistence queues; a commit or
shutdown flush failure makes the process exit nonzero. Exact result selection
uses a touched-candidate top-k heap rather than rescanning every memory once per
result slot. Failed ADD tokenizer
observations are transaction-local. A clean
post-COMMIT publication failure deletes any new durable token rows and
poison-closes the resident process so unpublished identities cannot affect a
later write. QUERY and ACTIVATE apply learning only after their complete bounded
responses have been built successfully.

Set TOKMEM_RANK_INTERVAL_MS to 10 through 60000 to change the default 250 ms
maintenance interval.

## Bounded verification

These commands do not mutate salience:

~~~sh
build/tokmem verify STORE
build/tokmem tokens STORE 32
~~~

verify rejects checksum differences, symlinks/nonregular record files, unknown
token IDs, noncanonical or overflowing ULEB128, count mismatch, trailing blob
bytes, decoded payloads over 64 MiB, recursive posting expansion over its
4,194,304-node budget, composite bytes that differ from left||right, and
non-bijective accounting markers. tokens prints:

~~~text
TOKEN_ID SEGMENT_BYTES WRITE_COUNT READ_COUNT USAGE_COUNT
~~~
