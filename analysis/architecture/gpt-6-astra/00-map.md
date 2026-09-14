# Representation and boundary map

Status: initial static audit, 2026-09-06. HEAD `ee1590009d6fd4c48035c63e15b3896a585757d7`; working tree is dirty with concurrent authorized implementation, including native metadata lineage and decision/workspace changes. References describe the inspected working tree, not necessarily HEAD or deployed services. No live store, provider, benchmark, or profiler was run. Line numbers can move during this series.

## Main finding

Current implementation follow-up: report [01](01-native-representation.md) records the separate decoded-content digest and streaming span matcher; report [03](03-operation-accounting.md) records independent source review. References to candidate prose materialization below describe the initial audit and are superseded by those implementation notes.

Persistent cognitive payloads already use the shared resident token identity space. The architecture is not end-to-end token-only: it deliberately reconstructs text at record transport, executive processing, and model boundaries, and it keeps decoded token segments plus structured control metadata in RAM. Whether another token representation improves performance depends on which copies, expansions, and traversals dominate. Token count alone does not establish resident footprint or latency.

The native token registry is independent of any model tokenizer. A native token ID cannot be sent as a provider token ID. Translation through bytes remains necessary unless a provider explicitly implements this registry. Common words may have native composite identities, but arbitrary bytes remain representable and word boundaries do not define the vocabulary.

## Boundary inventory

| Boundary | Actual representation | Ownership and validation | Performance consequence, unmeasured |
|---|---|---|---|
| Source text → native create | PHP string bytes plus positional metadata; private socket command | `TokenMemoryDaemon::encodeMetadata`, `exchange`; native `add_record` | String/framing allocation before native tokenization |
| Registry on disk | SQLite `token_translation`: stable IDs, byte segments, counters, parentage | Native registry loading/insertion | Catalog must accompany token-ID blobs; not a model vocabulary |
| Record files | `content.memory` and `metadata.memory`: `NAVIMEM1`, count, canonical ULEB128 IDs; SHA256 manifest | `encode_memory_blob`, `decode_memory_blob` | IDs are packed on disk; expansion bounds still matter |
| Content in daemon RAM | `Memory.tokens` as uint64 array, token count; metadata token array separately | `struct Memory` | RAM IDs occupy fixed-width elements rather than disk varints |
| Registry in daemon RAM | Token byte segments, trie, segment lookup, parentage, counters, graph/postings | `append_token_resident`, `Engine` | Composite tokens store byte expansions; tokenization does not remove segment/trie/index costs |
| Metadata in daemon RAM | `Metadata` with integer IDs, double confidence, byte strings, nullable/type flags | `metadata_decode` | Both tokenized metadata and parsed fields exist; this supports fast control predicates |
| Public cognitive GET/QUERY output | Token sequence ABI | `get_memory_tokens`, `append_token_sequence` | Separate from application full-record transport; inspect exact textual/binary wire encoding before assuming zero-copy |
| Private full-record output | Record count; per-record metadata/content byte lengths; positional metadata text and decoded content bytes | `append_record_wire`, PHP `decodeRecords` | Full content is reconstructed and copied even though store is token-native |
| ACTIVATE output | Decoded selected content under native-token budget | Native activation path; PHP `activate` | Budget is resident tokenization, not model context tokens; decoding is required for prompt text |
| PHP model representation | Associative arrays/objects containing content strings and typed metadata | `Memory::inspectByID` / counted `getByID` | Exact neutral inspection avoids learning side effects but still transfers full records |
| Executive canonical state | SQLite typed rows and serialized arrays, including intention contracts, work, cycles and slots | Executive models and transactions | Executive JSON/serialized state is not the native cognitive payload format |
| Workspace compatibility projection | SQLite canonical slot plus projection intent; separate native working-tier record | `WorkingMemory::persistSlot`, `replayProjection` | Cross-store operation requires journal/replay; it cannot become one SQLite/native transaction |
| Worker/provider input | Rendered textual prompts, structured request wrappers; provider-specific encoding | `LocalModelClient`, `CodexSparkWorker` | Native token identity ends before the model tokenizer; provider audit owns precise copies and request protocol |

### Exact source entry points

Paths are relative to the repository root:

- [Native structs and framing](../../../memories/src/tokmem.c:75): `Bytes`, `TokenVector`, `Metadata`, `Memory`, `Engine`.
- [Registry insertion](../../../memories/src/tokmem.c:1284): `append_token_resident`, `insert_token_row`, `get_or_create_token`; registry loading near line1432.
- [Binary codec](../../../memories/src/tokmem.c:1635): `encode_memory_blob`, `decode_memory_blob`, `decode_tokens_to_bytes`.
- [Metadata codec](../../../memories/src/tokmem.c:1799): `append_metadata`, `metadata_encode`, `metadata_decode`.
- [Full-record transport](../../../memories/src/tokmem.c:4883): `append_record_wire` walks content token segments to measure/reconstruct bytes.
- [PHP socket exchange](../../../src/Storage/TokenMemoryDaemon.php:589): `request`, `receiptRequest`, `exchange`; per-call connection, response-length bound, exact read.
- [PHP positional codec](../../../src/Storage/TokenMemoryDaemon.php:710): `encodeMetadata`, `decodeRecords`, `decodeMetadata`.
- [Read accounting distinction](../../../src/Model/Memory.php:25): `getByID` versus `inspectByID`.
- [Workspace publication](../../../src/Core/WorkingMemory.php:182): `publishDecisionReasoning`; `persistSlot` and `replayProjection` retain their journal protocol.

## Contracts to validate before optimizing

1. **Store identity and registry coherence.** IDs are meaningful only with the corresponding registry. Foundation IDs0–255 represent exact bytes. Composite identity and parentage must remain append-only and decode consistently. A directory of record blobs without its catalog is not a complete recoverable store. Importing two stores cannot concatenate token IDs without remapping.
2. **Lossless framing.** Validate canonical ULEB128, unknown IDs, count/length overflow, trailing bytes, decoded expansion and posting-tree limits. These are distinct limits: a small encoded stream can expand heavily. Architecture specifies 64MiB blob bounds and 4194304 expanded posting nodes; validate actual enforcement in the native report.
3. **Content/control separation.** Metadata shares encoding but must not seed recall or graph learning. Optimizing common metadata words must not accidentally turn status, authority, or lineage labels into cognitive evidence.
4. **Counter neutrality.** Native read/usage/link updates are semantics, not incidental serialization telemetry. Inspection, backup and replay must not count as experience; a compact transport must preserve COUNTED/NEUTRAL distinctions and accepted-read attribution.
5. **Protocol compatibility.** The working tree supports optional eleventh metadata line `source_event_kind`; legacy null retains ten-line bytes. `TOKMEM/1` is unchanged. A new reader accepting old data does not imply an old reader accepts typed new data. Deployment/handshake capability negotiation and mixed-binary behavior need an explicit review; do not claim seamless bidirectional compatibility from byte preservation alone.
6. **Durability and backup.** Record manifests establish byte integrity, not a standalone catalog snapshot. Validate receipts, hidden pending records, monotonic reserved IDs, accounting markers and neutral recovery together. Import/export/catch-up are whole-store transformations with their own staging rules; application SQLite and native registry require coordinated ownership/quiescence for a consistent backup.
7. **Cross-store publication.** `WorkingMemory` canonical slots and projection journals commit before native projection replay. Its native-write-inside-application-transaction prohibition is essential. A recent failed wrapper design demonstrated that enforcing atomic executive publication requires staging both intents, not calling the ordinary projector from a larger transaction.
8. **Evidence identity.** Source-event namespace is now explicit when known; unknown legacy provenance stays unknown. Stable token IDs do not resolve Event/SenseEvent numeric-ID collisions or make semantic evidence immutable automatically.

## Performance triage

These are ranked inspection priorities, not measured bottleneck rankings:

1. **Full-record boundary traffic and repeated decoding.** Count neutral per-ID fetches, full result materialization, metadata hex expansion and re-rendering in common decision/workspace flows. Prefer existing neutral BATCH/PAGE seams when they preserve source identity and bounded working sets. Do not optimize by dropping freshness checks.
2. **Resident registry and index amplification.** Quantify token segment bytes, trie edges, uint64 streams, constituent postings and directed link structures separately. Report corpus/vocabulary shape with any future measurement. Compression on disk is not a resident compression ratio.
3. **Candidate expansion and activation text scans.** Native graph/frontier bounds, direct posting exhaustiveness, candidate decode/span matching and whole-trace packing need separate accounting. Avoid caching decoded corpus text indiscriminately before showing a favorable memory/latency tradeoff.
4. **Lock and response-buffer occupancy.** Eight workers do not imply eight concurrent state mutations under the engine mutex. Declared-body and response reservation caps bound admission but do not establish throughput. Separate state time from socket delivery in future measurements.
5. **Metadata tokenization and small-message setup.** Positional metadata is already compact semantically, but textual hex and repeated connections have costs. Binary typed control frames or connection reuse would require versioning, lifecycle and malformed-input review; do not undertake a broad wire rewrite without evidence.

## Bounded validation series

- **00 Map/coordinator (this document):** shared representation claims, task ownership, compatibility issues, synthesis.
- **01 Native representation/storage:** registry stability, resident duplication, binary decode/expansion bounds, import/export and backup invariants. Owner: source_lineage agent.
- **02 Conversion hot paths:** PHP/native request/response formats, copy/hex/decode stages, batching opportunities, provider text boundary. Owner: provider_client agent when available.
- **03 Semantic operation/accounting review:** completed by the implementation coordinator; cognitive versus maintenance reads/writes, receipts/replay, repeated retrieval effects, and independent native follow-up review.
- **04 Validation proposal:** after explicit test approval, isolated fixtures only: arbitrary-byte roundtrip, vocabulary-growth stability, malformed encodings, legacy/typed wire matrix, crash/replay and backing-source correction. No live memory or services. Root decides whether and when to authorize/run it.

Each report must separate inspected code, architecture-spec assertions, inferred cost, and measured evidence. Proposed changes should identify one owner, exact invariant, expected resource reduction and required verification. No benchmark number is justified by this static audit.
