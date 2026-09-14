# Operation and accounting review

Static working-tree review, 2026-09-06. Read together with [00 map](00-map.md), [01 native representation](01-native-representation.md), and [02 boundary performance](02-boundary-performance.md). No live stores, benchmarks, provider calls, tests or production changes were made for this report. Concurrent native fixes can move source line numbers.

## Accounting is part of operation semantics

The useful distinction is not “text operation versus token operation.” It is whether an operation represents a cognitive write, a query, delivery of selected content, or mechanical reconstruction. Both a counted and a neutral fetch decode the same record and allocate transport bytes. Eliminating unnecessary counted reads changes learning as well as runtime work; eliminating duplicate neutral reads changes runtime work while preserving learning only if the freshness observation is equivalent.

| Operation | Read/access accounting | Query/formation accounting |
|---|---|---|
| FETCH/LIST/PAGE/BATCH COUNTED | Selected content-token occurrences plus memory access | No cue query training |
| Same commands NEUTRAL | No counted reads/access | No cue query training |
| COUNT / typed PROVENANCE | Counter-neutral indexed inspection | No query training |
| RANK_RECORDS | Candidate bytes returned without candidate read accounting | Cue usage and traversed directional links still reinforced |
| OBSERVE | Explicit selected record content reads/access | Does not repeat cue traversal |
| RECALL_RECORDS | Selected returned content reads/access | One query learning pass |
| ACTIVATE | Only emitted records/content-token prefix counted; access for selected record | Cue usage and traversed links, including candidates not ultimately emitted |
| Cognitive CREATE | Content and metadata top-level write occurrences counted after publication | Content pair formation/structural postings and links |
| Cognitive metadata UPDATE | New metadata stream counted; immutable content not recounted | Metadata tokenizes with existing registry, without content pair formation |
| Neutral import/catch-up | Neutral accounting marker instead of write counts | New records still tokenize, form vocabulary pairs, and construct structural graph state |
| Completed receipt retry | Existing completed semantic operation reused | No new logical cognitive write from replay |

Native references: [`query_memories`](../../../memories/src/tokmem.c#L5669), [`count_memory_read`](../../../memories/src/tokmem.c#L5059), [`account_memory_locked`](../../../memories/src/tokmem.c#L3980), [`add_record`](../../../memories/src/tokmem.c#L4262). PHP mode selection is explicit in [`TokenMemoryDaemon`](../../../src/Storage/TokenMemoryDaemon.php#L155); `Memory::inspectByID` calls neutral fetch while `getByID` is counted.

### Two consequential qualifications

**RANK_RECORDS is not a neutral search.** It avoids charging reads for rejected candidates, but it still changes usage and directional reinforcement. Repeating ranking to collect diagnostic information, or doing rank followed by another recall rather than OBSERVE of accepted IDs, changes experience. Existing neutral metadata/index lookup paths are preferable for integrity checks. There is no justification for inventing an unrequested zero-learning query API without a concrete consumer.

**Neutral import is counter-neutral, not a graph-identical no-op.** `add_record` calls `crystallize_online` and later `apply_pair_deltas` independently of `cognitive_write`; the flag chooses write accounting and associated publication handling. New imported content must be represented and indexed, and its structural presence changes recall. Exact existing catch-up rows are no-ops, but importing into a fresh store rebuilds tokenization/graph structure. Do not advertise neutral reconstruction as preserving the previous experiential graph or suppressing all pair observations. This agrees with 01: SQLite export/import is a record migration, not an exact native checkpoint.

## Success and delivery boundary

`query_memories` first prepares/cap-checks its response, then applies query reinforcement and selected read accounting under the engine mutex. This prevents an internal output-construction failure from counting a partial result. It does not acknowledge application receipt: socket transmission happens afterward. A client disconnect after response construction can still leave a counted exposure. Retrying a read/query is another semantic operation; the write receipt protocol does not deduplicate arbitrary read requests.

For ACTIVATE, partial output charges native content-token occurrences in the emitted prefix and increments the selected memory's access once. Separators consume output budget but are not a stored memory's content read. The historical native token sequence is not a provider token count or a Unicode boundary guarantee. These distinctions should remain explicit in any performance instrumentation or retry policy.

## Publication, receipts and recovery

Accounting markers bind exact encoded content/metadata digests. A first cognitive publication counts both streams; an exact marker is a no-op; a metadata change counts its new metadata stream. The encoded-blob digest is therefore a durability identity, distinct from the newly introduced decoded-content digest used only for ACTIVATE duplicate suppression.

Neutral publication journals intent before publishing and resolves it to a neutral accounting marker afterward. Restart distinguishes unrenamed intent, exact published state and ambiguous/conflicting state. Cognitive operation receipts reserve identity and bind semantic requests; timestamp drift is excluded, while content and other semantic fields remain significant. Adding `source_event_kind` to a previously untyped retry is a changed request, not a harmless formatting variation. Hidden pending records must remain invisible to ordinary reads until their receipt completes.

The PHP receipt helper retries a transport failure once with the same operation identity. It must not switch to an untyped frame to satisfy an older daemon, mint a new operation key on timeout, or silently convert the request to an ordinary create. Those changes would defeat the evidence/identity assumptions that make replay safe.

`WorkingMemory` has a separate application-SQLite projection journal. Both canonical reasoning roles and their sync intents now stage together; native replay follows commit. Pending-intent barriers prevent another canonical rewrite from invalidating an unreplayed projection digest. This is a two-store recovery protocol, not a distributed atomic transaction. A native failure after canonical commit means “projection pending,” not “publication never happened.”

## Cheapest improvements and verification requirements

1. Reuse the same neutral observation within one queue-construction boundary instead of decoding/fetching it again. Retain independent integration/dispatch freshness reads. Deduplicate IDs before batching; current BATCH is all-or-nothing for missing/invisible IDs, so blindly replacing per-ID reads changes omission behavior.
2. Reuse per-query ACTIVATE decode scratch rather than retaining decoded corpus text. Preserve selected IDs/order, whole-trace packing, partial-prefix accounting, and exactly one query-learning pass. See 02 for the allocation seam; no timing gain is measured here.
3. Label diagnostic operations explicitly in docs and future instrumentation: neutral inspection, query learning without candidate reads, counted delivery, cognitive write, reconstruction. “Read-only” alone is insufficient because normal recall updates learned state.
4. Validate receipt and failure boundaries with isolated fixtures only after approval: repeated identical write, semantic mismatch, timestamp-only retry, hidden publication, neutral recovery, response-construction failure, selected-read accounting and disconnected-response semantics. These are proposed cases, not executed tests.

No measured bottleneck ranking or behavioral verification is claimed. The strongest current result is a precise operation matrix that prevents an apparently cheaper path from changing learning, identity, or freshness semantics.

## Independent native follow-up review

Reviewed source SHA-256 `156c39b2296b48cd02c1cf720ca85d8f45bb899bc3047b0d144c87e30a8496c3` after the decoded-content identity and streaming span changes. This was a source review, not a test run. No blocking correctness discrepancy was found in the bounded comparison.

`Memory` has one allocation/publication path: startup `load_record` and all successful create/import paths reach `memory_publish_resident`. Startup validates token IDs and expanded size before publication; create paths use registry-generated token streams. The decoded-content SHA is initialized and finalized there for every resident record, including empty content, using segment bytes without C-string interpretation. Metadata-only updates validate unchanged content and retain this digest. Its only equality consumer is ACTIVATE deduplication; encoded-blob digests remain in manifests/accounting/receipts. Equality still relies on SHA-256 collision resistance.

Compared streaming `score_memory_cue_spans` with the prior contiguous-buffer scorer: both use identical byte classification, ASCII folding, FNV accumulation/final mixing, unique cue matching and saturating score increments. The streaming state retains a span across token boundaries; zero-length segments do not terminate it, and the final unterminated span is scored. Exact collision confirmation walks the same concatenated bytes, including arbitrary high bytes; NUL and ASCII punctuation retain their delimiter behavior. A separate full-sequence ID/expanded-size validation pass precedes early success, preserving the old decoder's validation even when all cue spans match near the beginning. Zero-cue early return remains unchanged.

Remaining performance tradeoffs are unmeasured: the scorer still scans candidate bytes under the engine mutex, exact matches reread their bytes through segment boundaries, and ACTIVATE still ranks all eligible touched candidates before packing. The improvement removes each candidate's decoded prose allocation/copy; it does not establish a latency number or alter read/query accounting.

### Final narrow follow-up

Reviewed the frozen native source SHA-256 `a1d762e491ec93bdad7f090c9c1760318b22c4e77f07c963b563275d1430e6b2`. The zero-count guard in `memory_span_matches` skips both pointer arithmetic and comparison for a hypothetical empty segment; positive-size comparisons are unchanged. `index_postings` now sorts its owned `expanded.items` in place instead of allocating and copying a second `sorted` array. Expansion order has no later consumer: grouping uses only sorted IDs and occurrence counts, and `append_posting` stores the memory pointer plus the count, not a pointer into the temporary array. The original record token sequence is untouched. Normal and pre-sort error paths still free both owned temporary vectors exactly once. No blocking lifetime or accounting change was found in source review. This removes one allocation/copy/free and one expanded-ID array's transient storage; elapsed-time benefit remains unmeasured. No code changes or tests were performed by this reviewer.
