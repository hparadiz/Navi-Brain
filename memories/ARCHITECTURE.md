# Token-native Memory architecture

Status: Memory-only implementation prototype

## Hard invariants

1. The store owns one lossless, append-only token identity space. Foundational
   IDs 0 through 255 translate to their exact byte values, so arbitrary input
   is always representable. Repeated adjacent sequences may form larger tokens
   online; there is no configured vocabulary ceiling and no external model
   tokenizer.
2. One memory is one memories/TIER/ID directory. content.memory is the only
   cognitive payload. metadata.memory is maintenance/control-plane data and
   never seeds postings, associations, or recall.
3. Both blobs use the same token registry and strict binary framing. One
   adjacent manifest.sha256 covers the exact bytes of both.
4. The daemon holds the entire translation table, segment hash, prefix trie,
   counters, pair-observation hash, postings, directed associations, learned
   reinforcement/access state, memory ID/update indexes, and hot orderings in
   RAM for its lifetime.
5. Every token has independent saturating unsigned 64-bit write, read, and
   query-usage counters. Semantic operations count whether initiated by a user
   or a background cognition worker. Mechanical maintenance does not count.
6. The bare cognitive recall ABI emits token IDs only. ACTIVATE is the prompt
   context readout: it emits only decoded activated content under an exact
   resident-token budget. The application adapter has a separate positional
   record frame for exact fetch, filtering, and record recall; metadata never
   seeds recall or graph learning.
7. Maintenance is bounded and correctness never depends on sorting finishing.
   Shutdown stops admission, joins maintenance, and drains all four persistent
   learned-state queues synchronously; lazy token/link ordering queues are
   rebuilt from durable counters at startup.

## Binary .memory protocol

Both blobs have this layout:

~~~text
offset  size  meaning
0       8     ASCII NAVIMEM1
8       8     unsigned little-endian token count
16      ...   exactly that many canonical unsigned LEB128 token IDs
~~~

The decoder rejects a wrong version, unknown ID, overflowing or overlong
ULEB128, count mismatch, trailing bytes, symlink, nonregular file, an encoded or
decoded payload over 64 MiB, or content whose recursively-expanded posting tree
exceeds 4,194,304 nodes. The manifest has exactly:

~~~text
SHA256(content.memory)  content.memory
SHA256(metadata.memory)  metadata.memory
~~~

Staged files and the staged directory are fsynced before an atomic no-clobber
directory rename; STORE/memories is fsynced before every publication and the
tier directory after it. Metadata-only replacement stages a complete record
and uses an atomic directory exchange, preserving the immutable content blob.
Ambiguous post-rename failures fail closed. Names are restricted to fixed
basenames, decimal positive IDs, and safe tier characters.

## Positional metadata protocol

After lossless token decoding, legacy metadata has ten LF-terminated lines in
this fixed order. Typed source provenance appends an optional eleventh line:

~~~text
1  id
2  created_at
3  updated_at
4  tier
5  confidence
6  status
7  source_event_id
8  source_memory_id
9  supersedes_id
10 expires_at
11 source_event_kind (optional: event or sense_event)
~~~

There are no braces, field names, colons, or schema words in the payload.
Non-null text lines are positional BYTE_COUNT HEX_BYTES; this preserves empty
strings, embedded whitespace, and arbitrary stored text bytes without allowing
LF to blur field boundaries. Confidence is exactly 16 lowercase hex digits
containing its IEEE-754 binary64 bits. Nullable positions contain the single
reserved token - for NULL; non-null integer positions contain canonical decimal
integers, and non-null expires_at uses the text encoding.

A missing source_event_kind means unknown namespace, not an inferred Event or
SenseEvent identity. A typed source requires a positive source_event_id. Legacy
untyped records retain their exact ten-line encoding. New readers accept both
forms, but old readers/daemons cannot necessarily accept typed records/writes;
TOKMEM/1 currently remains unchanged, so rollout must coordinate all readers and
writers. A retry changing an untyped source into a typed one changes semantic
receipt identity.

## Online formation and resident recall

Input is greedily segmented by the resident prefix trie. During a chronological
write pass, the fourth observation of an adjacent pair forms or finds the exact
concatenated token and replaces that occurrence. Composite parentage is durable
and startup verifies segment == left.segment || right.segment. Metadata uses
existing tokens but does not train pair formation. Pair observations for an ADD
remain in a transaction-local overlay until record publication and accounting
succeed.

Content tokens and every recursively-persisted constituent create
token-to-memory postings. Tokens within a 16-token window create separate A ->
B and B -> A records. Evidence may begin symmetrically, but each direction is
independent: query traversal reinforces only the exact direction traversed.

Queries tokenize their cue without forming new identities. Direct top-level
cue postings score at weight 1024; each unique recursively-persisted cue
constituent scores at 512. This keeps byte-identical older memories recallable
after a later write creates a larger composite token. The first 16 hot outgoing
links of at most 256 deduplicated sources add bounded association scores.
Top-level roots come first in cue order; constituents follow depth/near-first
via breadth-first traversal across the whole
root forest, so each root contributes near constituents before any one deep
branch dominates. Only actual top-level cues accrue usage; every
directional edge actually traversed, including constituent edges, accrues
reinforcement.

ACTIVATE admits active working, semantic, and procedural traces; raw episodic
captures require explicit recall. It uses the same token/link scoring pass, then
measures distinct contiguous cue-span hits and matched cue bytes against decoded
candidate content. That sequence-level coverage outranks loose constituent
overlap. Ordering is lexicographic: span hits, matched span bytes, direct cue match
score, total query score, then update time and ID. Memory access counts record
exposure and no longer determine recall rank. When span hits exist, only the strongest equal-span-hit/byte cohort can
enter the workspace; without span hits, eligibility is the strongest
equal-direct-match-score cohort. Byte-identical
content is collapsed using a separate decoded-content SHA-256 computed once
at resident publication; encoded-blob digests remain the durability identity. It packs whole active traces in that order, charging the
resident tokenization of separators to the same budget. If no whole trace fits,
it emits a prefix of the strongest trace ending at a native token boundary. No
metadata, score, ID, field name, wrapper, or echoed cue enters the result.

## Counters and persistence

A successful cognitive create increments write count for every top-level token
occurrence in both blobs. A metadata update counts occurrences in the new
metadata token stream once and advances its digest-bound accounting marker;
the unchanged content is not counted again. Mechanical SQLite import installs neutral accounting
markers and does not manufacture experience. Delivering recalled or directly
requested content increments read count for every delivered top-level content
token. Using a query cue increments usage count for every cue-token occurrence.
QUERY buffers and cap-checks its full response before applying any read, usage,
access, or directional-reinforcement mutation.

Learned state uses four independently deduplicated resident queues: token
counters, pair observations, directional link reinforcement, and memory access
counts. Each maintenance interval atomically persists at most 256 rows from
each queue. Unsaved entries remain dirty after rollback. Unsigned counters are
exact 8-byte little-endian blobs so the full saturating range survives SQLite's
signed-integer limit. Explicit flush and orderly shutdown drain every queue.

Startup performs one complete sort from restored learned state before service.
Thereafter each changed token and edge enters its own deduplicated order-priority
queue. At most 64 token entries copy a stable counter snapshot and are
heap-fixed per interval by usage, then read, then write count, with token ID as
the deterministic tie break. Heap positions are only internal locators; recall
compares the snapshot tuples directly. Cue roots remain
first and exhaustive; the bounded constituent link-source frontier consumes the
lazy hot token order. A
bounded maintenance operation scans the 16-slot window for its true weakest
edge, swaps a stronger outside candidate into that position, and sorts the
16-slot prefix exactly. Up to 64 changed edges are processed per interval, so
QUERY's window converges independently of total graph size even after batched
changes. Memory/posting orders are restored initially and remain
correctness-neutral; direct postings are always exhaustive. Exact result
selection uses one touched-candidate top-k heap rather than K full-memory scans.
ACTIVATE currently retains and sorts all eligible touched candidates before
packing its bounded output; the output token budget does not bound ranking
work. All six queues compact consumed prefixes before growing, so sustained partial
drain and re-enqueue is bounded by the live high-water mark.

## Application and SQLite boundary

SQLite is not the memory payload. The token catalog is a seven-table control plane:
token_translation, memory_accounting, neutral_accounting_intent,
pair_observation, link_reinforcement, memory_state, and operation_receipt.
memory_accounting creates a digest-bound bijection between
published records and accounting markers. It makes crash reconciliation of a
published-but-unaccounted record exactly-once; orphan and mismatched markers are
fatal.

Counter-neutral publication first commits neutral_accounting_intent. Startup
deletes an unrenamed intent, atomically finalizes the marker for an exact
renamed record, or fails closed on an ambiguous digest/tier state. Neutral
recovery never increments write counters.

The PHP Memory model uses the daemon as its sole live backend. Private commands
require the explicit TOKMEM/1 ABI; public GET, QUERY, and ADD retain their prior
byte framing. FETCH, LIST, and BATCH expose explicit COUNTED and NEUTRAL modes;
OBSERVE counts only caller-selected records after a neutral candidate scan.
LIST provides equality filters over tier/status and ordering by id or updated_at.
PAGE walks the resident ID index after a cursor without materializing or sorting
the corpus. COUNT uses the visibility-aware resident tier/status index and a
binary ID cursor without changing counters. PROVENANCE uses resident
source-memory/source-event indexes to return the highest-ID exact match under
optional tier/status filters, also counter-neutral. Typed EVENT and SENSE_EVENT
selectors distinguish source namespaces; legacy untyped provenance remains unknown. RANK_RECORDS returns at most 100 full candidates while counting cue
usage/reinforcement but not candidate reads; accepted IDs are counted through
OBSERVE.
RECALL_RECORDS runs the
same ranker once, counts cue usage and reinforcement once, and counts selected
content reads once while returning the corresponding positional records in the
same response. ACTIVATE performs selection and exact token-budget packing in
the daemon, counts reads only for content actually emitted, and returns no
record or metadata framing. Content is immutable per ID; callers publish a replacement and
retire the prior record when content changes. The adapter refuses a memory
write while a legacy SQLite application transaction is open.

Episodic status is one-way at the daemon boundary: active may become
superseded, expired, or quarantined, but no inactive episodic ID can become
active again. This makes an ascending active-episodic consolidation cursor
complete for all lower IDs. Other tiers retain their existing status semantics.

Each retryable CREATE, UPDATE, or compound REPLACE first commits an
operation_receipt containing the semantic request digest and a reserved
monotonic ID. Created/updated timestamp drift is excluded from receipt identity;
all other positional metadata and content are included. A completed retry is
neutral; a pending retry recognizes any already-published semantic state before
finishing it at the reserved ID. REPLACE publishes the new immutable content and
retires the old record under one receipt. IDs may be burned after failed work and
are never reused.

CREATE and REPLACE records remain invisible until their receipt reaches the
complete state, including after restart. FETCH, LIST, BATCH, GET, QUERY,
RECALL_RECORDS, ACTIVATE, RANK_RECORDS, PAGE, COUNT, and PROVENANCE all exclude them. Catch-up and export
refuse pending receipts, while exact operation replay may inspect the hidden
physical record and atomically reveal it when the receipt commits.

The listener holds 128 ordinary incomplete sockets plus one 250 ms fast-lane
slot outside the worker queue. Ordinary input has one absolute five-second
deadline. Complete requests enter eight workers behind a 64-descriptor,
128 MiB aggregate declared-body budget. Before building a response, a worker
must reserve either 4 KiB or the 64 MiB maximum from a shared cap sized for two
maximum replies plus one small allowance per writer slot. Completed replies
move to a dedicated 72-slot writer that polls nonblocking sockets under an
absolute five-second send deadline; workers release shared admission after
state work and enqueue, never after client delivery. A complete CLOSE receives
queue priority and wins a single atomic transition, takes the exclusive barrier
after admitted state work finishes, flushes dirty queues, then causes the writer
to discard every non-CLOSE reply before workers and writer are joined.

Import uses a real read transaction and selects records in ID order, reading
the optional source namespace when present. Non-content fields enter the
positional metadata blob; content alone enters recall. Import and export construct sibling staging targets, fsync them,
and publish with atomic no-clobber renames. Export reconstructs the table, its
three indexes, NULLness, values, explicit IDs, and sqlite_sequence without
incrementing cognitive counters. An offline catch-up compares every source row
in one read-only snapshot. Exact rows are no-ops, mutable metadata changes and
absent store rows are applied counter-neutrally, while content/tier/created drift
and extra store IDs fail closed. It is used after old writers are stopped and
before the daemon becomes live. Source sequence reconciliation includes source
max, reserved receipt IDs, and the existing store sequence. Live create durably
advances the saved sequence; export also takes max(saved sequence, maximum
memory ID).

A failed ADD before publication cannot become learned state. Pair observations
were never installed. If tokenizer rows committed but publication failed
cleanly, the trailing rows are deleted transactionally and the daemon
poison-closes so its resident trie is discarded. If publication visibility is
ambiguous, the rows are retained so a possibly-published record remains
decodable, and the daemon still fails closed for startup reconciliation.

## Accounting and backup qualifications

Neutral import suppresses cognitive write counters, but new records still pass
through content tokenization, pair formation and structural posting/link
construction. It is not a graph-identical replay of the original store. SQLite
export preserves application records, not the native registry, learned-state
queues/history or operation receipts. An exact native backup must preserve the
coherent complete store and its registry.

Query read accounting commits after a bounded response is constructed, before
socket delivery is acknowledged. A later disconnect does not undo that
experience; retrying a query is another query. RANK_RECORDS avoids candidate
read accounting but still trains cue usage and traversed directional links.
