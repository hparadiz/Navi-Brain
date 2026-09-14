# Native token representation and decoding costs

2026-09-06. Architecture audit of the working tree, including the new typed-lineage metadata extension, followed by the narrowly authorized content-identity fix recorded below. No live store, daemon requests, inference, or behavioral tests were used. Complexity statements below are source-derived; they are not latency measurements.

Reviewed base HEAD: `ee1590009d6fd4c48035c63e15b3896a585757d7`, with uncommitted changes. Source SHA-256 at inspection:

- `memories/src/tokmem.c`: `f8c6ee7d841f55cbb7c21652fe730e2d33b2c863acbe052e62a776bd3b21f70f`
- `src/Storage/TokenMemoryDaemon.php`: `f89a258bc1c812fbc5fdab8906b6229dd5045b5e725117a1522195bcbf260c58`

**Implementation status:** the follow-through sections below record separate content identity, streaming span scoring, in-place posting sort, cached content size/exact segment comparison, and early activation-cohort filtering. The original cost inventory is an audit baseline, not a claim that all those redundant allocations remain in the current code.

## Finding

The native store already keeps both content and metadata as token-ID streams on disk and keeps content as token IDs in resident memory. There is no persistent, per-memory decoded prose cache in `Memory`. The meaningful performance opportunities are repeated temporary decoding during candidate scoring and metadata updates, expanded postings and tokenizer structures, and the text-based control protocol. Replacing every decoded value with a token ID would also remove useful native scalars and could make comparisons slower.

One correctness defect was directly connected to representation: ACTIVATE deduplicated using the digest of the encoded token stream, although equal text can have different historical tokenizations. The authorized fix now uses a separate digest of decoded content; build and static verification are recorded below.

## Actual representations and boundaries

| Boundary | Representation | Consequence |
|---|---|---|
| `content.memory` and `metadata.memory` | Eight-byte `NAVIMEM1` magic, eight-byte little-endian token count, canonical ULEB128 token IDs | Already tokenized; no JSON/serialized PHP memory document is stored in these blobs. |
| `catalog.sqlite3/token_translation` | Token ID → exact byte segment, optional composite children, counters | Dictionary bytes are necessary to recover text and tokenize new input. IDs are local to this registry. |
| Resident content | `uint64_t[]` token IDs per memory | No full decoded prose retained per memory. RAM uses eight bytes per occurrence even when the disk ID needs one to three bytes. |
| Resident metadata | Both metadata token IDs and a decoded `Metadata` struct | Decoded IDs/confidence/flags support native operations. Text timestamps/tier/status and duplicate tier/key strings add storage and comparisons. |
| Resident vocabulary | Flat bytes for every token, segment hash table, byte trie, composite parent IDs | Composite strings overlap their children. This purchases direct matching and decoding at a potentially substantial RAM cost. |
| Native GET/QUERY | Decimal token-ID text | Preserves native token identity, but decimal formatting is not a compact binary transport. |
| FETCH/LIST/BATCH/PROVENANCE/record recall | Positional metadata text plus decoded content bytes | PHP receives application records and hydrates objects; these paths cross the text boundary. |
| ACTIVATE | Cue bytes → native token graph → decoded selected content | Final text is required by the current PHP/model consumers. Full candidate decoding before selection is separately avoidable. |

Sources: [`Metadata`, `Memory`, `Token`](../../../memories/src/tokmem.c#L118), [`encode_memory_blob` / `decode_memory_blob`](../../../memories/src/tokmem.c#L1635), [`load_record`](../../../memories/src/tokmem.c#L3364), [`append_record_wire`](../../../memories/src/tokmem.c#L4883), [`TokenMemoryDaemon::decodeRecords`](../../../src/Storage/TokenMemoryDaemon.php#L785).

Metadata is tokenized **after** conversion to a ten-line positional text record, now optionally eleven lines. Byte fields use a decimal length followed by hex: an `n`-byte nonempty field occupies roughly `2n + digits(n) + 2` bytes before tokenization. Confidence is sixteen hex digits; nullable IDs are decimal or `-`. The optional `event`/`sense_event` line carries typed lineage. Thus metadata is tokenized on disk, but its intermediate representation contains avoidable hex expansion. New metadata does not call `crystallize_online`; it uses the already available content vocabulary. [`append_metadata`](../../../memories/src/tokmem.c#L1799), [`add_record`](../../../memories/src/tokmem.c#L4251), [`encodeMetadata`](../../../src/Storage/TokenMemoryDaemon.php#L710).

The update path sends immutable content back from PHP, expands stored token IDs into a second content buffer, compares the two, tokenizes new metadata, and re-encodes the unchanged content blob for publication. This is real content-sized work for a status/confidence/expiry change. It protects immutable-content and receipt invariants, so replacing it requires an explicit metadata-only operation contract, not simply deleting the comparison. [`update_record_metadata`](../../../memories/src/tokmem.c#L4723).

## Token formation is lossless, but it is not word tokenization

`initialize_store` installs all 256 byte tokens. `encode_greedy` walks a trie from each input position and selects the longest registered segment. `crystallize_online` observes adjacent token pairs, forms composites at threshold four, and can grow a composite repeatedly within one pass. Segments can cross spaces, words, punctuation, and UTF-8 character boundaries; there is no linguistic word-boundary invariant. This is a store-owned byte/composite vocabulary, not an LLM tokenizer. [`initialize_store`](../../../memories/src/tokmem.c#L1484), [`encode_greedy` / `crystallize_online`](../../../memories/src/tokmem.c#L1581).

Consequences:

- Full decoding preserves exact input bytes, including arbitrary binary bytes. Vocabulary strings are not an accidental second prose database; they are the decoder dictionary.
- A previously unseen word is representable immediately as smaller segments/bytes. It need not receive one token ID.
- Partial ACTIVATE output stops at a native token boundary, which does **not** guarantee a word or UTF-8 character boundary. A multibyte character can remain split into foundational byte tokens.
- The same text may be stored with different token sequences as formation history changes. Existing published records are not automatically retokenized.
- A native token budget therefore measures this store's historical representation cost. It is neither a fixed byte budget nor a provider token budget. A frequently formed long phrase can cost fewer native tokens than identical earlier text.

`index_postings` expands composite ancestry to make older small-token memories discoverable from newer composite cues; `collect_cue_constituents` expands and deduplicates cue descendants. This is useful compatibility across vocabulary growth, but it also means abundant byte constituents can touch many memories before exact span filtering. [`index_postings`](../../../memories/src/tokmem.c#L2479), [`collect_cue_constituents`](../../../memories/src/tokmem.c#L5480).

## Identity and restart guarantees

Within one valid catalog, `get_or_create_token` assigns the next contiguous ID and reuses an existing ID for equal bytes. `load_registry` requires contiguous IDs, exact foundational byte identities, child IDs below their parent, and composite bytes equal to concatenated child bytes. Reordering token hotness changes ordering structures, not token IDs. These are strong local invariants. [`get_or_create_token`](../../../memories/src/tokmem.c#L1341), [`load_registry`](../../../memories/src/tokmem.c#L1425).

Published records keep their token sequences across restart. Catch-up retains the destination registry and existing content representation; it does not renumber existing tokens. Failed unpublished writes can remove newly appended trailing dictionary rows before poisoning/stopping the resident engine. Thus “append-only IDs” applies to committed identities, not every ID briefly allocated inside a failed operation. [`delete_trailing_tokens_locked`](../../../memories/src/tokmem.c#L4227).

SQLite export preserves decoded memory bytes, metadata fields and memory IDs. It does **not** export the token registry, learned pair history, links, token counters, or native operation receipts. Import into a fresh native store retokenizes rows in ID order; token IDs and segmentation are not promised to match the original store. An export/re-import is an application-record migration, not an exact token-graph checkpoint. [`import_sqlite_into`](../../../memories/src/tokmem.c#L6218), [`export_sqlite_into`](../../../memories/src/tokmem.c#L6351).

Neither the `NAVIMEM1` record header nor its two-blob SHA-256 manifest binds a record to a registry UUID or vocabulary fingerprint. Structural and accounting checks catch many invalid combinations, but they do not establish that an arbitrary foreign record's IDs name the same byte segments. Copying individual `.memory` directories between independent catalogs is therefore not a supported identity-preserving operation. A consistent complete native store and its dictionary must travel together. This is an integrity-boundary observation, not a demonstrated live corruption incident. [`read_record_blobs`](../../../memories/src/tokmem.c#L2108), [`decode_memory_blob`](../../../memories/src/tokmem.c#L1657).

Typed lineage retains `TOKMEM/1` and accepts original ten-line metadata bytes unchanged. Updated PHP reads legacy records; old daemons reject typed writes, and old PHP cannot decode typed records. Updating the daemon without every writer/reader is not a transparent migration. A prior operation receipt with untyped metadata also conflicts with a newly typed replay request. Those compatibility limits require coordinated rollout; they must not be hidden by retries that remove the new namespace.

## Source-derived costs

Let `T` be stored content-token occurrences, `M` metadata-token occurrences, `B` decoded content bytes, `E` expanded composite ancestry nodes, `V` vocabulary size, `K` touched eligible memories, and `S` distinct cue spans (`S ≤ 256`). Costs omit allocator and SQLite constants.

| Operation | Work and temporary storage |
|---|---|
| Blob encoding/decoding | `O(T)` IDs, disk size `16 + Σ ULEB128(id)`; decoded resident sequence `8T` bytes. |
| Registry load | Stored segment-byte volume plus trie insertion work; retains segment bytes, trie, segment hash and token records. |
| Greedy tokenization | Trie walks for each emitted segment; `trie_child` linearly scans outgoing edges. Loose bound `O(B × longest_segment × max_branch_degree)`; no linear-time guarantee. |
| Online formation | Pair observations plus allocation/copy/hash/trie insertion of formed composite bytes. Repeated growing prefixes can have quadratic cumulative copied bytes. |
| Posting construction | Expand `E`, allocate expanded and sorted copies, sort `O(E log E)`, then one posting per distinct token per memory. `E` is capped at 4,194,304 nodes per record. |
| Associations | At most sixteen following token positions per occurrence, adding both directions: `O(16T)` hash/index operations. |
| ACTIVATE span scoring | Per eligible touched record, allocate/append `B` bytes and scan spans; worst comparison loop scales with `S`. Total at least all visited candidate bytes, before selected output is decoded again. |
| Record response | First sum segment sizes, append metadata text, shift it for the record header, then append content segments: `O(T + M_text + B)`. No disk read in the warm resident path. |
| Metadata update | Content decode/compare `O(T+B)`, metadata encoding, unchanged content blob encoding `O(T)`, and durable publication. |

ACTIVATE currently sets its candidate limit to the full memory count, so it can sort all touched eligible candidates rather than a small top-k. Its engine mutex covers tokenization, posting scans, candidate decoding, sorting, output construction and learning. Extra daemon worker threads therefore do not make these graph operations execute concurrently. This makes avoided candidate materialization more valuable than merely increasing worker count. [`query_memories`](../../../memories/src/tokmem.c#L5661), [`score_memory_cue_spans`](../../../memories/src/tokmem.c#L5320).

On the existing x86-64 debug binary `/tmp/navi-tokmem-lineage-review`, GDB `sizeof` inspection without running an inferior reported: Token 176 bytes, Memory 328, Metadata 152 (already included in Memory), TrieNode 32, TrieEdge 16, Posting 16, Link 16, SegmentSlot 24, PairSlot 32. These are compiler layout measurements, **not** live memory usage. They exclude dynamic arrays, duplicated segment bytes, allocator headers, capacity slack, SQLite cache and thread buffers. At the expansion cap, `expanded` plus `sorted` alone can occupy 64 MiB, before the stack and permanent postings. Trie nodes with a single outgoing edge initially allocate capacity four: 32 bytes of node plus 64 bytes of edge storage, excluding allocator overhead. [`trie_child`](../../../memories/src/tokmem.c#L770), [`index_postings`](../../../memories/src/tokmem.c#L2479).

## Surgical priorities

1. **Separate content identity from blob identity.** `content_digest` is SHA-256 of encoded `content.memory`, but `append_activation_context` compares it to remove byte-identical content. Identical text with different historical segmentation can evade this check. Add a separate decoded-content digest and byte count, computed by streaming registry segments once during load/publication. Preserve the existing blob digest for manifests/accounting. This costs approximately 40 bytes per record before alignment and removes a representation-dependent equality assumption. Hash equality can be followed by streaming byte equality if exact collision-independent deduplication is required.
2. **Score spans across token segments without assembling whole candidates.** A state machine can carry the current word/span across segment boundaries, case-fold/hash as bytes arrive, and retain only the current span or bounded comparison state. It must exactly preserve current delimiter, distinct-hit and byte-match semantics, including words crossing token boundaries. Do not substitute token-set overlap for contiguous text matching. This removes an `O(B)` temporary allocation/copy per candidate while retaining necessary byte inspection.
3. **Remove the duplicate expanded posting array.** `expanded` is dead after sorting; sorting it in place can remove the second `8E` array and full copy without changing occurrence counts or ordering. This is a smaller, clearer first memory optimization than redesigning the vocabulary.
4. **Cache immutable decoded byte count and compare content through segments.** Record response size calculation then avoids its preliminary token walk; metadata update can compare supplied bytes incrementally without allocating a second full decoded buffer. A byte digest alone must not replace exact immutable-content validation unless that integrity tradeoff is explicit.
5. **Address metadata only after profiling the larger loops.** A versioned binary control frame could remove hex/decimal expansion, while resident tier/status enums and parsed timestamps could reduce string comparisons and allocations. Preserve null distinctions, confidence bits, typed source namespaces, exact old receipt semantics and explicit timestamp interpretation. Metadata token IDs still serve accounting; discarding them is not automatically free.
6. **Consider a compact trie before deleting dictionary strings.** A root byte lookup table and compact edge storage can target linear scans and four-edge minimum allocation. Full segment strings permit fast contiguous comparison/decoding; replacing them with recursive composite traversal trades RAM for more pointer chasing. Measure the actual trie/segment/posting shares before choosing an arena, radix trie or indirect dictionary.

No recommendation here claims measured speedup. Before deployment, execute isolated equivalence cases once test permission exists: identical text across vocabulary growth; byte/UTF-8 token boundaries; cross-token span matching; empty and oversized records; old/new metadata round trips; same-store restart versus export/re-import; and exact receipt replays. Collect candidate bytes visited, allocation volume, peak resident memory, lock hold time and latency percentiles from isolated fixtures rather than probing live cognitive memory.

## Implemented after the audit: independent content identity

Root authorized priority 1 after reviewing the finding. `Memory` now has a separate 32-byte `content_bytes_digest`. `memory_publish_resident` computes it once by feeding each stored token's registry segment into SHA-256. Both startup loading and successful creates use this shared publication path. It allocates no decoded content buffer; metadata-only updates retain the digest because content is immutable. ACTIVATE's duplicate comparison is the only consumer of the new field.

The original `content_digest` remains the exact encoded-blob hash everywhere: record manifests, accounting markers, neutral publication intents and recovery comparisons. `record_matches` and `record_semantically_matches` already compare decoded content explicitly and do not need this new digest. Receipt hashes, record format, vocabulary, ranking order and historical token-budget cost are unchanged. The new digest is derived resident data and requires no store migration. As with the prior SHA-based duplicate comparison, cryptographic collision resistance rather than an additional exact-byte comparison underlies equality.

Post-fix C source SHA-256: `f8df92e729e5134b505b4660754a5dff3d9ffaee28d0e85dc5b0edb55087de97`. It builds cleanly with `cc -O2 -g -std=c17 -Wall -Wextra -Wpedantic` into `/tmp/navi-tokmem-content-identity-review`. GDB static layout inspection reports `sizeof(Memory) = 360`, exactly 32 bytes above the audited 328-byte baseline. `git diff --check` passes. This is build/source verification; behavioral equivalence, latency and peak-memory measurements remain pending explicit test permission. No daemon was restarted or deployed.

## Implemented after the audit: streaming cue-span scoring

Root subsequently authorized priority 2. `score_memory_cue_spans` now scans stored token segments directly, carrying a `MemorySpan` with start token/byte offset, length and incremental folded hash across segment boundaries. On a delimiter or end of content, `score_memory_span` applies the original hash finalization and cue ordering. A length/hash match is verified by `memory_span_matches`, which rereads the candidate span across segments and compares exact ASCII-folded bytes. Hash collisions cannot create a match.

The scorer still validates every token ID and the entire decoded byte count before matching, so an invalid later token or oversized tail cannot be hidden by early success. Empty segments add no bytes and do not reset an in-progress span. The span-byte predicate is unchanged: ASCII letters/digits and all bytes at least `0x80`; only ASCII uppercase letters fold. There is no UTF-8 decoding or Unicode normalization. Distinct matched flags, first matching cue, saturating hit/byte scores, empty-cue behavior and early completion are unchanged by construction.

The candidate-sized heap buffer and its full-content copy are removed. GDB static layout reports a 32-byte `MemorySpan`; together with the existing 256 matched flags and scalar locals, scratch storage is independent of candidate text length. The complete token-validation pass and necessary byte inspection remain. Hash matches require a segmented reread, so iterator branching and cache effects still require measurement; removing allocations does not establish lower elapsed time. Final selected text is still decoded for its external consumer, and no cross-query decoded cache or new registry was added.

The native binary builds cleanly under the same warning flags into `/tmp/navi-tokmem-streaming-span-review`. The implementation master independently reviewed digest lifecycle and streaming equivalence against the original buffer-based scorer and found no blockers; the final zero-length comparison guard was relayed afterward. Final C source SHA-256 is `f77b2b2af7a549a2801b88e231dc03810a213a340b450a662b8aba165f6fcfbf`. No runtime equivalence tests, live-store queries, benchmarks or deployment were performed. The cost table above records the audited pre-optimization path; this amendment supersedes its candidate-allocation statement for the current implementation.

## Implemented after the audit: in-place posting expansion sort

Root authorized priority 3 after independently inspecting `index_postings` lifetime. The function now sorts `expanded.items` in place, then groups equal IDs directly from that array. The separate `sorted` allocation, copy and free are removed. The comparator, grouping order, occurrence counts, posting publication, expansion cap, empty-input return and error cleanup are unchanged.

This structurally eliminates one allocation and `8E` bytes of temporary storage for `E` expanded nodes, up to 33,554,432 bytes (32 MiB) at the existing 4,194,304-node cap. It also removes the corresponding full-array copy. These quantities exclude allocator overhead and any internal `qsort` scratch storage; they are not a measured RSS or latency improvement. The original expansion array and traversal stack remain.

C is frozen for final integration review at SHA-256 `a1d762e491ec93bdad7f090c9c1760318b22c4e77f07c963b563275d1430e6b2`. The exact lifetime change was sent to the implementation master for independent source review. No behavioral tests, live-store operations or deployment were performed. This amendment supersedes the pre-optimization two-array cost described above.

## Sustained implementation pass: cached content size and exact segment comparison

Priority 4 is now implemented. `Memory.content_byte_count` is computed in the existing decoded-content digest pass in `memory_publish_resident`. This pass explicitly validates every token ID and the full expanded-byte bound before the memory enters resident indexes. Token-array/count assignment occurs only at this publication seam; registry segment bytes and stored memory token sequences are immutable thereafter. Metadata updates retain both the cached count and the decoded-content digest.

`memory_content_equals` replaces decoded comparison buffers in `record_matches`, `record_semantically_matches`, `update_record_metadata` and catch-up's immutable-content check. It compares supplied bytes directly against each token's registry segment, with exact lengths and `memcmp`; no hash substitutes for byte equality. A wrong supplied length or differing segment can return false early against the previously validated immutable record. A successful comparison checks every token ID/range and requires an exact final byte count. This removes content-sized allocation/copy work from these checks; it does not remove the caller's existing full-content upload or the unchanged content-blob encoding needed by publication.

`append_memory_content` uses the cached count to reserve a bounded response and checks token IDs, remaining byte range and exact final count while copying segments once. Invalid output resets the buffer to its entry position. `append_record_wire` uses the cached count in its original frame and restores the whole frame on failure. Whole-trace ACTIVATE output uses the same helper; its caller still restores any separator on failure. Prefix and separator decoding retain the original path. Thus the response-size prewalk is removed for whole memories while output caps and accounting admission remain in force.

Build/source checkpoint: C SHA-256 `3cce760557e9f1552e0fc6a7851fbc755cce7b0431e1d73e9fd299a24f566e2a`, warning-clean build `/tmp/navi-tokmem-content-length-review`, static GDB `sizeof(Memory) = 368` (eight bytes above the preceding checkpoint), and passing `git diff --check`. The implementation master independently reviewed initialization/lifetime, exact comparisons, frame/separator rollback, bounds and unchanged prefix behavior and found no blockers. No behavioral tests, benchmarks or live operations were performed. This amendment supersedes the audited content-buffer and full-response prewalk costs above; latency remains unmeasured.

## Sustained implementation pass: filter unreturnable activation cohorts

ACTIVATE now filters permanently losing cohorts before the final sort. `activation_cohort_compare` compares distinct span hits and matched span bytes; when there are no hits, the scorer also has zero matched bytes and the cohort comparison uses direct cue-match score. This is exactly the cohort that `append_activation_context` was already permitted to pack. Ordinary QUERY/RECALL_RECORDS/RANK_RECORDS selection is unchanged.

Every eligible candidate still runs complete span scoring and token/size validation before cohort filtering. The retained heap contains only members of the best cohort seen so far. A stronger cohort resets its count to zero, a weaker candidate is skipped, and an equal candidate enters the original `topk_offer`. The heap capacity remains the memory count, so no member of the eventual winning cohort can be excluded by a shortlist limit. The existing sorter receives a valid heap.

The equivalence argument is monotonic: once a cohort loses to a stronger one, no later candidate can make it the winning cohort again. All retained members share the fields relevant to cohort admission, so comparing against the heap's worst-ranked root still compares the right cohort. Within the final winner, every member remains and the original full comparator still determines order. Therefore duplicate selection, whole-trace fitting, first nonempty candidate and prefix fallback encounter the same ordered candidates as before. Failed validation of a losing candidate is not skipped; query learning and delivered-read accounting keep their prior paths.

The final sort now handles only the winning cohort, and already weaker candidates avoid heap insertion. Temporary earlier cohorts may still have incurred heap work before a better one appears. If all candidates share the winning cohort, there is no asymptotic gain. This change does not reduce full candidate scoring, the existing memory-count-sized result capacity, byte-bound checks or the global mutex scope; no latency improvement is claimed.

Frozen C SHA-256: `a9dc6afdad14d0afc05ebf2719e209b5d88bc2ec6020f34499c6efbd6dff0293`. The warning-clean build is `/tmp/navi-tokmem-cohort-review`; `git diff --check` passes. The implementation master independently reviewed this snapshot's cohort invariant, validation ordering, capacity, deterministic output order, packing and accounting and found no blockers. The boundary specialist received the same snapshot for compiler static analysis. Behavioral testing and benchmarks remain unperformed pending explicit permission.
