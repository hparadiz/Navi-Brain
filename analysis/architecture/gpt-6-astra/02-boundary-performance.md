# PHP/native conversion and performance boundaries

Static working-tree audit, 2026-09-06. Base HEAD: `ee1590009d6fd4c48035c63e15b3896a585757d7`. Concurrent authorized changes are present; function names are the durable references when line numbers move. This report follows the [shared representation map](00-map.md). Native registry, resident vocabulary, blob format and backup details belong to report 01.

**Implementation follow-through:** the initial audit below identified candidate-sized span buffers. The final working tree replaces them with direct streaming over resident token segments, preserving full-sequence validation and collision-safe byte comparison. Its scratch-buffer proposal is therefore historical; there is no remaining full-candidate allocation in `score_memory_cue_spans()`. The native specialist and coordinator reviewed equivalence, and the build passed. Runtime latency and behavioral equivalence remain unmeasured. See the [implementation record](../../../docs/cognition-implementation-2026-09.md).

## Finding

The useful optimization target is **how often and how much content crosses a representation boundary**. The token store already retains content as native IDs. Its direct `ACTIVATE` path also already selects and packs inside C, returning only selected text. Converting PHP control labels to tokens would leave the larger full-record transfers, repeated source hydration and candidate scans intact.

The highest-confidence opportunities from inspection are narrower materialization, reuse within one observation, and fewer temporary buffers. Their runtime benefit has not been measured. There is no evidence here that metadata hexadecimal conversion dominates overall cognition cost.

## What is established, and what is not

- **Inspected:** PHP/native framing and call sites, source-level allocation/conversion steps, existing build configuration, and existing profiling documentation. No live memory-daemon requests, process attachment, inference, store traversal, or new behavioral tests were performed.
- **Historical measurement only:** the [2026-08-18 PHP daemon investigation](../../../docs/php-daemon-performance.md:73) attributed at least 87.5% of heartbeat sampled cycles and 96.5% of senses sampled cycles to SQLite. That predates current native-memory and consolidation changes; it is not a profile of today's implementation. It demonstrates why a conversion rewrite should not be justified by old CPU symptoms.
- **Available tooling, not measurement:** this machine resolves `perf`, `strace`, PHP and `cc`; `valgrind` did not resolve in the command-path check. [The native Makefile](../../../memories/Makefile) builds with `-O2 -g` and warnings, with no profiling or benchmark target. No profiler was launched.
- **Unknown:** present CPU distribution, bytes transferred per decision, PHP allocation peaks, native lock occupancy, fraction of repeated reads, vocabulary-dependent native/model token ratio, and end-to-end latency. All cost priorities below are static hypotheses with explicit validation requirements.

## Conversion paths

| Operation | Representation changes | Materialization and accounting |
|---|---|---|
| Create | PHP record → positional metadata text and raw content → request string → native parsed metadata and tokenized content/metadata → record receipt → PHP arrays/model | Content necessarily enters from bytes. The reply reconstructs it again. Receipt retry identity and counted-write effects must survive optimization. |
| Metadata update | Hydrated PHP record → full immutable content plus new metadata → native decode of existing content for equality → metadata tokenization/publication → full record receipt | Status/confidence/expiry updates still transfer and decode content. This is a concrete avoidable-data candidate for a separate metadata-only operation, not a reason to weaken current immutability checks. |
| Fetch/list/batch/rank records | Resident token sequence → C response bytes and positional metadata → complete PHP payload → per-record substrings/arrays → optional `Memory` objects | The boundary always returns complete content. A caller that eventually uses 800 characters has already paid for the whole record. Neutral reads avoid counter effects but not this cost. |
| Search | Full-record native recall → PHP record parsing → Unicode keyword scans and result sort | Native candidate selection and PHP keyword coverage are separate algorithms. PHP reranking only changes the returned native shortlist; it does not improve recall of an omitted candidate. |
| Direct context activation | Cue bytes → native cue IDs/postings/span scoring → selected token streams → final content bytes | PHP receives one string; there is no full-record PHP decode or ORM hydration. Native scoring still examines substantially more content than the final budget returns. |
| Work prompt | Typed arrays → JSON for checksums plus plain-text rendering → stored prompt → worker/provider wrapper → model tokenizer | The checksum and visible text serve different contracts. Native IDs are not model token IDs; provider text reconstruction remains necessary. |

Source entry points: [Memory persistence/hydration](../../../src/Model/Memory.php:162), [native metadata update](../../../memories/src/tokmem.c:4723), [full-record emission](../../../memories/src/tokmem.c:4883), [PHP decoding](../../../src/Storage/TokenMemoryDaemon.php:785), [search reranking](../../../src/Storage/TokenMemoryDaemon.php:365), and [direct context status](../../../src/Core/ExecutiveCore.php:7854).

### Metadata is typed internally, textual at this wire boundary

`encodeMetadata()` emits ten positional lines, plus an optional eleventh source namespace line. IDs are decimal, absent optional fields use `-`, and confidence is the hexadecimal representation of eight double bytes. Creation/update times, tier, status and optional expiry are length-prefixed hexadecimal strings. PHP reverses this using line splitting, regex validation, `hex2bin()` and `unpack()`. Native `Metadata` already stores integers, a double, nullable flags and parsed byte strings; it does not need to interpret those strings from token IDs for every filter. [PHP codec](../../../src/Storage/TokenMemoryDaemon.php:710), [native metadata codec](../../../memories/src/tokmem.c:1799).

For a byte field of length **n**, this wire uses `digits(n) + 1 newline + (n > 0 ? 1 separator + 2n : 0)` bytes. An illustrative record with a three-digit ID, two 19-byte timestamps, tier `semantic`, status `active`, null source/supersession/expiry fields, and no namespace has **147 metadata bytes**, before record framing. This is arithmetic from the format, not a sampled corpus mean. Its hex-encoded byte fields contain 52 original bytes; removing their hex expansion alone saves 52 bytes. For a 4,096-byte content record that is about 1.2% of metadata-plus-content traffic. Tiny records can have the opposite balance.

Compact enums for tier/status/source namespace would remove a small repeated expense. A broader binary metadata frame could also avoid regex/hex allocations. Either requires versioned capability negotiation and exact handling of legacy bytes, timestamps, nulls and operation digests. The present `TOKMEM/1` handshake does not negotiate individual metadata capabilities. The existing native tokenized metadata and operation identity should not be silently rewritten just to optimize transport. A wire-only format may retain the old canonical metadata representation internally, but that is a new protocol design to verify.

### Copying and lifetime matter more than the format's appearance

`append_record_wire()` walks each token sequence once to total byte length and again to append segment bytes. It writes metadata into the shared response buffer and moves that metadata forward to insert the record header. PHP then reads the entire response, creates metadata/content substrings, builds decoded arrays, and often hydrates one `Memory` ActiveRecord per result. PHP copy-on-write may share some values between containers; source inspection cannot give the actual number of physical string copies. The full response buffer and extracted content nevertheless coexist during parsing. [C emission](../../../memories/src/tokmem.c:4883), [PHP decoding](../../../src/Storage/TokenMemoryDaemon.php:785), [hydration](../../../src/Model/Memory.php:257).

`exchange()` opens and closes a Unix socket for every command. PHP's `$ready` flag concerns daemon startup, not connection reuse. It concatenates ABI/header/body before writing. `writeExact()` sends a substring of all remaining bytes after each short write; repeated short writes can produce a large sum of copied suffix lengths. `readExact()` appends chunks to one growing string. These are credible allocation sites, but neither quadratic real-world behavior nor allocator-copy counts have been measured. Chunk-bounded writes can reduce the worst suffix-copy pattern without replacing the protocol; persistent sockets would require changes to the server's per-request admission/lifecycle and are a much larger intervention. [Exchange and exact I/O](../../../src/Storage/TokenMemoryDaemon.php:621).

## Costs hidden behind small output limits

### Activation scores and sorts the touched cohort before packing

For `ACTIVATE`, `query_memories()` sets the result capacity to the resident memory count. It computes span scores for every eligible touched candidate and sorts those results, then packs only the winning span cohort, or the winning direct-match cohort when no span matches. A 512-native-token output budget therefore does **not** mean 512 tokens of scanning or a 512-candidate heap. This exhaustiveness supports current ranking and whole-trace packing; simply limiting the candidate heap can change which shorter record fits. [Query selection](../../../memories/src/tokmem.c:5659), [packing](../../../memories/src/tokmem.c:4954).

At the initial audit, `score_memory_cue_spans()` allocated a temporary buffer, decoded a candidate's complete content into it, scanned word-like spans, and freed it. Selected records were decoded again for output. All this happened under the engine mutex. If **T** eligible touched records contained **B** total decoded bytes, buffer construction alone processed B bytes before final output; span matching and sorting added work. The final streaming implementation removes that temporary materialization while retaining byte inspection. No latency value follows from this accounting. [Span scoring](../../../memories/src/tokmem.c:5320).

The initial smallest-change proposal was a **single scratch buffer per query**, resetting its count between candidates and freeing it after the query. That would remove repeated allocation/free cycles but retain the B-byte copies and the largest candidate allocation until query completion. The implemented streaming matcher instead avoids those copies and carries words across segment boundaries. Its iterator branches and exact matching-span rereads still need measurement; the absence of a full buffer establishes an allocation reduction, not an end-to-end CPU speedup.

A later exact optimization could discard losing span/match cohorts before sorting. Only the final winning cohort is packable today. This can reduce sorting from all touched eligible records to the winning cohort, while preserving every score, final tie order, whole-trace fit decision, duplicate rule and prefix fallback. It offers no improvement when all candidates share that cohort. Do not combine it with an arbitrary candidate cap.

### Workspace freshness introduces repeated full-record observations

`WorkingMemory::snapshot()` calls `expireStale()`, loads slots, and neutral-fetches backing `memory`/`procedure` records individually. `serializeCurrentSlot()` uses at most 800 content characters in the resulting claim. Decision dependency capture can subsequently fetch the same source again to hash its complete evidence. These repeated reads are partly an intentional freshness defense. [Workspace snapshot](../../../src/Core/WorkingMemory.php:296), [source refresh](../../../src/Core/WorkingMemory.php:953), [dependency capture](../../../src/Core/DecisionStateMachine.php:1020).

Prefer carrying the **exact full record used to produce a prompt slot** into that slot's queue-time dependency capture, as a private internal value excluded from rendering. That can eliminate a redundant observation and its time-of-check mismatch. It must not replace fresh neutral reads at later proposal integration or action dispatch. A process-wide memory cache, or reuse across those gates, would reintroduce the stale-evidence failure being repaired.

Existing `BATCH NEUTRAL` can combine known source reads, with important caveats: IDs must be unique, the batch is bounded to 1,000, and native parsing rejects the entire batch if any ID is missing or invisible. Individual `FETCH` can return a missing record separately. A naive bulk replacement changes stale-reference behavior. Deduplicate per observation; preserve missing-source omission/rejection explicitly; bound total bytes as well as IDs. A new partial-result batch protocol should only be considered if realistic missing references make narrow fallback inadequate. [PHP batch](../../../src/Storage/TokenMemoryDaemon.php:312), [native batch validation](../../../memories/src/tokmem.c:5235).

`expireStale()` also scans canonical slots and up to 100 legacy working records during snapshots. Projection synchronization and recovery can issue further I/O. It is not a pure serialization helper. Any future timing of “render working memory” should split maintenance, SQLite loads, native fetches and rendering instead of attributing their sum to PHP text conversion. [Maintenance path](../../../src/Core/WorkingMemory.php:355).

### Rendering bounds the final string, not all preparation

`enqueueWork()` JSON-encodes working/background state for checksums, separately renders it, and sanitizes the assembled prompt. `PlainText::render()` recursively prepares lines, sanitizes and joins them, and only then applies the final character limit. List limits and scalar truncation reduce output, but associative fields and original scalar text can be processed before their contribution is dropped. `CodexSparkWorker` sanitizes ordinary stored prompts again. These passes are explicit in code; their fraction of model-call latency is unknown. [Work enqueue](../../../src/Core/ExecutiveCore.php:6483), [renderer](../../../src/Support/PlainText.php:49), [worker boundary](../../../src/Core/CodexSparkWorker.php:149).

An incremental renderer budget is preferable to converting the arrays into native vocabulary IDs: it can stop traversing content that cannot appear. Preserve key priority, truncation notices, sanitization, list limits and exact evidence checksums. Do not replace the checksum of captured state with a checksum of its shortened display. Another low-risk candidate is an idempotency preflight before expensive context preparation, retaining the authoritative queue-transaction check; its handling of invalid or changed retry inputs must be specified first.

## Which representations should remain compact

| Value/use | Appropriate internal form | Where text is still required |
|---|---|---|
| Native recall traversal, postings, content identity and duplicate detection | Existing token IDs, record IDs, typed scores, digests | Final selected content at LLM/UI output |
| Tier/status/source namespace filters | Compact stable enums or existing parsed fields | Debugging, external APIs and compatibility serialization |
| Timestamps, confidence, reference IDs and nullability | Native scalars with explicit formats | Human display and schema-bound model prompts |
| Evidence dependency | Record identity plus exact semantic revision/hash; full bytes only when computing/validating the current contract | Prompt evidence text and human review |
| Provider request | A bounded textual prompt plus provider JSON schema | Provider's own tokenizer accepts its vocabulary, not Navi's native IDs |

An ID-only response is beneficial only if the next operation can finish using IDs. Returning IDs and then immediately fetching each content record adds round trips. A “compact PHP token array” is also not automatically compact: ordinary PHP arrays have container/key/value overhead, and sharing Navi's dictionary with every PHP process would duplicate state and create coherence obligations. Prefer letting the resident daemon perform selection and return only the final necessary representation.

`ACTIVATE` budgets native resident token identities including separators. PlainText budgets characters. Worker completion budgets use model tokens. These are three different units. Native compression/vocabulary growth can alter the first without altering the model's tokenized prompt length. Use separate names and measurements; do not report one as the other. [Native budget contract](../../../memories/src/tokmem.c:4946), [PHP context limits](../../../src/Storage/TokenMemoryDaemon.php:12), [local provider request](../../../src/Core/LocalModelClient.php:64).

## Prioritized implementation candidates

These priorities rank invasiveness and demonstrated redundant work, **not measured speedup**.

| Priority | Narrow change | Expected resource reduction | Required verification before rollout |
|---|---|---|---|
| 1 | Reuse the exact queue-time source observation for workspace rendering and dependency capture | Duplicate sockets, full-record reconstruction and hashing inputs | Changed backing evidence still rejects at later gates; truncated claim and full fingerprint refer to the same source; privacy-safe slots remain redacted |
| Implemented alternative | Stream span scoring over resident segments | Full-candidate temporary buffers and copies removed | Build and source review passed; runtime span/ranking/packing equivalence and latency still require isolated verification |
| 2 | Budget-aware rendering and bounded source projections for callers needing small excerpts | Processing and transfer of discarded tails | Same visible ordering/escaping/truncation and unchanged full evidence identity |
| 2 | Existing neutral batch reads where missing-ID behavior is preserved | Connection setup and framing overhead | Counter neutrality, deduplication, missing/invisible IDs, byte cap and final gate freshness |
| 3 | Separate metadata-only update/receipt contract | Full immutable content upload/decode/echo for control changes | Explicit operation version, idempotent replay, immutability, stale metadata handling and canonical digests; existing `content_digest` hashes encoded blob bytes, not arbitrary PHP plaintext |
| 3 | Filter losing activation cohorts before final sort | Sorting and heap work on unreturnable candidates | Exact comparator, cohort, whole-trace packing and fallback equivalence; no shortlist cap |
| Defer | Binary metadata frames, persistent sockets, PHP token transport, decoded-corpus cache | Unestablished tradeoff | First show material cost after simpler reductions; negotiate protocol/registry versions and measure memory as well as latency |

## Bounded measurement proposal

After explicit approval for isolated verification, use synthetic stores and fixed workloads outside live services. Record connection/request count, request/response bytes, candidates touched, span bytes scanned, selected native tokens, returned bytes, time under the engine mutex, PHP peak allocation and request-to-final-context latency. Separate cold startup from a resident daemon; separate neutral, counted and cue-learning operations. Compare a few short records, many long candidates with short final output, repeated workspace references, and many tiny metadata-heavy records.

Keep selected content, ordering, budgets and accounting equal between variants. Measure process RSS as well as PHP's allocation counter. A win in encoded bytes that increases resident dictionary duplication, native lock time, or final model prompt tokens is not a demonstrated performance improvement. No part of this measurement proposal was executed in this audit.

## Current-code amendment: streaming span scorer

The reusable decoded scratch proposal above describes the audited earlier implementation. The native owner subsequently implemented a narrower allocation-free span scan over token segments (`MemorySpan`, `memory_span_matches`, `score_memory_span`, `score_memory_cue_spans`). It preserves complete token/size validation before matching and retains only span location/length/hash, not decoded candidate prose. Independent source review and the exact source digest are recorded in [03](03-operation-accounting.md#independent-native-follow-up-review). No behavioral or latency test is claimed. Candidate traversal, exact byte comparison, global mutex scope and all-candidate ranking remain; the historical per-candidate decoded Buffer is no longer the current scorer.
