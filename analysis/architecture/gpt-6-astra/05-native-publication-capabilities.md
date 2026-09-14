# Native publication reuse and format capabilities

2026-09-06, resumed implementation pass. This is a source-derived design review, not a crash test or filesystem benchmark. No live store or daemon was used. Inspected C SHA-256: `79974b2240fdfdf8f000bd083f6cb6783870c663af2ea6297b67ab7351102419`, including the separately reviewed close-failure cleanup in `safe_read_regular`.

## Immediate conclusion

Reusing the already computed **blob digests** when constructing replacement manifests is a narrow optimization with the same publication behavior. Reusing the existing **content file itself** would change the trust and failure model and needs a separate verified design. The latter must not be implemented by simply replacing a write with `link()` and trusting the resident hash.

Typed event provenance now has a distinct command tag that old daemons reject, while a small read-only capability endpoint reports operator readiness. This does not change the existing ABI response, record format, or ordinary legacy reads. Unsupported typed operations fail without dropping their namespace.

## What metadata replacement actually does

`update_record_metadata` already proves supplied content equals the immutable resident token segments. It encodes the new metadata and also re-encodes the unchanged content token stream into `content_blob`. Before this patch, `publish_metadata_replacement` hashed both supplied blobs again to construct the manifest. It then writes and fsyncs three new staged files, fsyncs the staging directory, exchanges staging and canonical directories, then fsyncs their parent tier directory. Resident metadata and accounting change only after this publication succeeds. [Update path](../../../memories/src/tokmem.c), function `update_record_metadata`; [publication path](../../../memories/src/tokmem.c), functions `publish_metadata_replacement`, `manifest_for_blobs`, `write_file_sync`.

The unchanged content is regenerated from validated resident token IDs. This has a useful integrity consequence: replacement does not need to trust that the on-disk old content file still equals the resident record after startup. A hard-link implementation that does no verification would discard that property.

The manifest consists of exactly two hex SHA-256 lines, naming `content.memory` and `metadata.memory`. The resident `content_digest` is the encoded-blob digest, not the newer decoded-content digest used by ACTIVATE. Metadata's encoded-blob digest is computed in the caller before publication because the neutral intent and accounting require it. Both exact inputs to the replacement manifest therefore already exist.

## Existing failure and recovery boundaries

| Failure point | Current publication result and cleanup |
|---|---|
| Staging creation, file writes/fsyncs, or staging-directory fsync | Canonical record remains unchanged. Staged files are removed by the existing cleanup path. |
| Directory exchange fails | Canonical record remains the old generation; staging is cleaned. |
| Exchange succeeds, parent fsync succeeds | New three-file generation is canonical. The exchanged old generation is removed from staging. |
| Exchange succeeds, parent fsync fails, rollback exchange and parent fsync succeed | Old generation is restored; candidate generation is cleaned. |
| Exchange succeeds but rollback or its fsync fails | Return `PUBLISH_AMBIGUOUS_FAILURE`, poison the engine and retain the ambiguous staging state rather than guessing which generation is durable. |
| Durable publication succeeds but resident metadata/accounting update fails | Engine is poisoned. Startup reconstructs canonical records from disk, validates manifests, and reconciles accounting/operation state. |

Neutral publications commit a digest-bound intent before the rename. Startup distinguishes a matching newly published metadata digest from a pre-publication record with an exact old accounting marker; inconsistent content, namespace/tier identity or ambiguous metadata/accounting combinations fail closed. The content digest must stay the same through a metadata-only update. Hidden staging directories are not loaded as canonical memories. [Startup ordering](../../../memories/src/tokmem.c), `engine_open`; [neutral reconciliation](../../../memories/src/tokmem.c), `reconcile_neutral_accounting_intents`; [directory scan](../../../memories/src/tokmem.c), `load_all_memories`. See [operation/accounting review](03-operation-accounting.md) for the broader journal model.

These are source-level branch descriptions. Actual durability on this filesystem, power-loss behavior, and injected-failure recovery have not been exercised in this pass.

## Narrow optimization: digest-based manifest formatting

Implemented `manifest_for_digests`, which accepts the two already computed 32-byte encoded-blob digests and emits the exact existing manifest bytes. `manifest_for_blobs` remains the verification and creation entry point, hashing its input before calling the formatter. Metadata replacement passes `memory->content_digest` and its freshly calculated `metadata_digest` to the formatter.

Why this preserves the current result:

1. A resident record owns an immutable token sequence, and token IDs do not change their byte meaning within the catalog.
2. Loading a blob requires canonical ULEB128, the current magic/header, exact token count and no trailing bytes. Re-encoding that same sequence produces the same blob bytes.
3. Therefore its stored encoded-blob digest is the digest the old formatter would calculate from the re-encoded content. The new metadata digest was calculated from the exact new blob immediately before publication.
4. Only manifest formatting changes. File allocation, writes, fsync order, directory exchange, rollback, cleanup, neutral intents and receipt semantics stay intact.

This removes redundant hashing of unchanged encoded content and a second hash of new metadata. It does **not** remove content re-encoding, its temporary blob allocation, disk writes, fsyncs or the caller's full-content upload. No speedup is claimed without measurement.

## Why storage reuse requires more work

A hard link from the old `content.memory` to staging could eliminate content re-encoding and copying. Under the code's immutable-file discipline, both old and new directories would reference the same content inode while their metadata/manifest files remain distinct. Existing exchange/rollback cleanup could then unlink the staging reference without removing the canonical reference.

However, a correct implementation needs all of these additional obligations:

- Open a real regular file without following a symlink and bind the staged link to the same verified device/inode, rather than validating one pathname and linking a replacement.
- Verify the on-disk encoded content against the resident blob digest before accepting it. Otherwise disk drift can be incorporated into a supposedly successful metadata publication, leaving a manifest that fails after restart. Streaming verification avoids a full buffer but retains an encoded-content read/hash pass.
- Preserve the durability of the new link and directory entries, including the filesystem assumptions behind file and directory fsync.
- Account for clean failure, successful exchange, successful rollback, ambiguous rollback and crash-left staging references. An extra link must never cause cleanup to remove the last canonical reference.
- State the write-exclusion assumption. A hard link shares an inode; external in-place modification after validation would affect both generations. Current code never modifies canonical content in place, but private permissions and the daemon lock are not a proof against every same-user external writer.

A consistent immutable store under an exclusive writer can support this design, but it is a larger filesystem change than digest reuse. It should wait for explicit isolated verification permission and an implementation review of the above paths. Reflinks or streaming reconstruction offer different memory/I/O tradeoffs; neither should be presumed faster on this machine without measurement.

## Explicit typed command and operator capability contract

Typed event lookup now uses this bodyless request:

```text
TOKMEM/1 PROVENANCE_TYPED EVENT|SENSE_EVENT SOURCE_ID TIER|- STATUS|-\n
```

It returns the same bounded record frame as existing provenance lookup and participates in the same 64 MiB response reservation. EVENT and SENSE_EVENT match only their explicit metadata namespace; unknown legacy kinds remain excluded. MEMORY is rejected on this command. Existing `PROVENANCE MEMORY|EVENT|SENSE_EVENT` keeps its current semantics as a compatibility alias, and source-memory queries continue using its MEMORY form.

The command tag is the compatibility boundary. An old daemon cannot interpret it as its older untyped EVENT request; it returns `invalid command`. A capability cache alone could not guarantee this because a daemon can downgrade between the cache fill and a new one-request connection. Therefore no capability preflight gates hot-path queries, and clients must not fall back from the new event command to old EVENT semantics. Typed metadata already fails closed on old decoders through its eleventh line.

Operator request:

```text
TOKMEM/1 CAPABILITIES\n
```

Successful payload, inside the existing bounded `OK <length>` response:

```text
TOKMEM/1
metadata-source-kind-1
provenance-source-kind-1
```

This is control-protocol text, not cognitive memory that benefits from registry tokenization. The fixed response fits the existing small-response reservation and requires no body, corpus scan, memory counters or inference. The current `ABI` payload remains exactly `TOKMEM/1\n`, so existing compatible clients keep their handshake.

`metadata-source-kind-1` means the optional eleventh `event|sense_event` line is accepted and preserved. `provenance-source-kind-1` declares the new PROVENANCE_TYPED command, namespace-specific EVENT/SENSE_EVENT matching and exclusion of unknown legacy kinds. Native CLI `tokmem client SOCKET capabilities` exposes this endpoint without opening the store directly.

For operator tooling querying an old daemon, only the exact valid-protocol `invalid command` rejection establishes that this endpoint is absent. Timeout, disconnected socket, malformed framing, incompatible ABI, engine poisoning or closing remain errors, not a reason to assume a harmless legacy capability set. Readiness reporting does not remove the coordinated-rollout requirement or authorize retrying with `source_event_kind` stripped.

This proposal does not retag old records, change prior operation receipt hashes, add cross-store transactions, or turn an old untyped receipt into a newly typed one. Those remain separate migration/recovery concerns.

## Implementation checkpoint

Native source is frozen for independent review at SHA-256 `4a1d7106746d5f6be9ef30168345ad54121b86cad3aff3bde883c7435b97ffa0`. The patch preserves the prior close-failure cleanup, streaming comparisons, recall cohort filtering, typed codec and accounting changes. Only the metadata manifest formatter's repeated hashes are removed; storage reuse remains a deferred design. No behavioral tests, live requests, fault injection or timing measurements were run.

Independent source review passed for that checkpoint: the metadata digest is calculated from the exact supplied blob; canonical ULEB128/header/count validation proves unchanged token-vector re-encoding retains its stored blob digest; the new provenance tag rejects MEMORY and is selected by PHP only for typed event namespaces. The warning-enabled C build also passed under `/tmp`. These checks do not establish executed recovery or mixed-version behavior.

## Follow-up: exact metadata comparison without encoding buffers

`metadata_exactly_equal` now combines the existing semantic field comparison with exact `created_at` and `updated_at` byte equality. `record_matches` no longer encodes both metadata records merely to compare them; `update_record_metadata` no longer encodes old metadata merely to identify a no-op. New metadata is still encoded for publication, and `operation_request_digest` still hashes its canonical encoded representation.

For accepted `Metadata` values this is the same equality as `append_metadata`'s canonical bytes. Confidence is encoded as the hexadecimal representation of its raw 64-bit storage, so the field comparison retains signed zero and distinct NaN bit patterns. Byte fields compare length and raw bytes, including embedded NUL; terminators are outside both representations. Nullable IDs and expiry compare presence separately, and the validated source-kind enum distinguishes omitted/event/sense_event forms. The byte helper also skips zero-length `memcmp`, allowing empty fields without dereferencing their data pointer.

The follow-up source freeze is SHA-256 `9624981f9b834273ef24201e2c94593e9ddb9fc521b82cbed85ae269585f06a7`. This removes temporary allocation and hexadecimal formatting only from equality checks. No latency or memory-peak measurement is claimed.

## Follow-up: allocating IDs without scanning resident history

`next_memory_id` now reads the last member of `memory_id_order` instead of walking every member of `memory_order`. This changes the resident maximum lookup from O(M) to O(1), where M includes visible and hidden resident records. It creates no additional resident index or cache. The receipt maximum, sequence-file fallback and maximum, and `INT64_MAX` refusal remain unchanged.

The sorted-tail proof depends on the existing publication invariant: `memory_publish_resident` calls `memory_index_insert` before incrementing `memory_count`, and every startup or newly created resident record uses that publication path. The global ID index retains hidden pending records; visibility changes affect filtered/source indexes without removing them from the global order. Metadata replacement preserves record ID. There is no live deletion path that shrinks the global order, and its sole allocation caller, `receipt_begin`, holds the engine mutex. Higher reserved IDs that have not become resident remain covered by the unchanged receipt and sequence checks.

The same allocation path asks SQLite for `COALESCE(MAX(memory_id),0)` over `operation_receipt`. That table's `WITHOUT ROWID` primary key is `op_key`, so its former schema did not provide an index beginning with `memory_id`. New catalogs now create `operation_receipt_memory_id ON operation_receipt(memory_id)`; existing catalogs create it with `IF NOT EXISTS` during `engine_open`, after taking the store lock and before loading records or serving requests. Both DDL failures take the existing initialization failure path. The table contents, operation-key identity, request hashes, states and MAX query are unchanged.

SQLite documents a MIN/MAX optimization for a single aggregate whose argument begins an index. The new index therefore makes this receipt maximum eligible for an index lookup instead of scanning receipt history; that is an inference from the query and documented planner rule, not an observed query plan or timing result. [SQLite query planner overview](https://www.sqlite.org/optoverview.html#the_min_max_optimization).

There is a deliberate storage/startup tradeoff. An existing catalog must build the index once before the daemon serves requests, and future receipt inserts maintain it. The build can require sorting, I/O and additional disk space; the SQLite busy timeout does not impose an execution deadline on that work. A `WITHOUT ROWID` secondary index carries the table's primary-key columns, so each entry includes the `op_key` identity as well as `memory_id`, with B-tree/record overhead. This is not an eight-byte-per-receipt estimate. Old binaries can ignore this additive index. [SQLite index file representation](https://www.sqlite.org/fileformat.html#representation_of_sql_indices).

Final native source for these two allocation changes is SHA-256 `55241f451dce909f2bab162d3a885f7e9fde37e4a49b2de0b49120fa2df41d61`. A warning-enabled GCC build and focused Clang analysis of `engine_open` both exited successfully with empty diagnostic logs in `/tmp/navi-native-receipt-index-aptknvlx`. The preceding tail-only checkpoint also passed focused analysis of `receipt_begin` in `/tmp/navi-native-id-tail-gpoikg33`. Compilers were bounded to 60 seconds wall time, 30 seconds CPU and 1 GiB address space. No generated binary was executed, no live catalog was opened, and no index was installed in the running store. The broader baseline analyzer warnings remain qualified in [report 04](04-static-analysis.md).
