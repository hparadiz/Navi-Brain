# Bounded references at the dream queue boundary

The optional dream lane now persists a versioned source manifest instead of another copy of its rendered evidence prompt. This is an internal storage change: the provider still requires text, and the native vocabulary is not substituted for the provider's token IDs. Evidence below comes from source inspection and independent peer review. No runtime measurements, native record probes or inference calls were performed.

## Representation and compatibility

[ExecutiveCore](../../../src/Core/ExecutiveCore.php) queues new work with the exact nonempty `dream-consolidation-v2` prompt marker. Its `input_refs` contains an ordered list of at most 12 unique positive episode IDs, a SHA-256 hash for each projected evidence string, the SHA-256 hash of the complete rendered prompt, and explicit renderer, projection and validator versions. Comparison and existing-memory lists remain empty for this lane. IDs retain selection order; the existing helper that sorts consolidation IDs would change the rendered prompt and is deliberately not used here.

The manifest freezes the evidence projection and renderer contract. The current identifiers are `consolidation-evidence-v1` and `dream-json-v1`, with consolidation validator version 7. Unknown or malformed contracts are retained for recovery and blocked from dispatch. Template or projection changes require deliberate version handling. Legacy `dream-consolidation-v1` work retains its stored prompt; queue admission, lane assignment, worker recognition and completed-result recovery support both versions.

The structural saving is the removal of the source prose and instructions from each new work prompt and downstream snapshots of that work. The marker and manifest still occupy space; the manifest includes a new full-prompt digest and version fields. No fixed database-size or latency improvement is claimed. Existing v1 rows are not rewritten, and generated proposals continue to be stored under the existing integration contract.

## Rendering once or reconstructing after restart

The actual new-row preparation branch returns a private `prepared_prompt` outside the persisted work. [DreamModelWorker](../../../src/Core/DreamModelWorker.php) may reuse it only after matching the exact claimed work ID, marker and complete reference manifest. Materialization checks its final SHA-256 hash and 16 KiB byte cap. A concurrent existing-work winner never receives the other candidate's ephemeral prompt. Worker summaries omit this value, and the actual prompt is passed separately to the client rather than inserted into `WorkItem`, `Event` or `ThoughtArtifact`.

This path avoids an immediate second set of native record reads merely to reconstruct text already rendered by the same preparation. It intentionally preserves the earlier preparation-to-dispatch source race: matching the work manifest does not prove that the native source remained unchanged afterward.

Existing or restarted v2 work instead performs at most 12 neutral `Memory::inspectByID` reads in the persisted order. Each source must be active, episodic and unexpired; its current projected evidence hash must match. The reconstructed prompt must match the final hash and byte cap. A source record may be much larger than its projected evidence, up to the native 64 MiB content limit. Full-record transport and PHP hydration remain costs even when the final provider prompt is small. Twelve records is a count bound, not a wire-byte, resident-memory or elapsed-time guarantee; the socket timeout cannot safely be multiplied by twelve to manufacture such a guarantee.

The current optimization therefore reduces durable text duplication and avoids redundant same-preparation hydration. It does not introduce a persistent prompt cache, a native partial-record protocol or new streaming guarantees. Those would require separate evidence and contracts.

Preparation and cold reconstruction also count candidate JSON bytes incrementally. The empty rendered list supplies the fixed instructions and brackets; each accepted row contributes its exact JSON-encoded length and one comma after the first row. Both paths use the renderer's existing JSON flags. This removes repeated encoding of previously accepted rows solely to check each candidate's size, while retaining the final renderer and prompt-hash check. The size arithmetic follows the current flat list format and must be revisited with any renderer change; no latency improvement was measured.

## Failure and ownership boundaries

Observed missing, inactive, expired or changed sources reject the old assignment before provider reservation. A fenced SQLite transaction cancels only the exact leased work owner/fence and returns only that work's queued ledger entries to pending with reset attempt history. Later bounded selection can consider the new evidence version. A transient native transport failure is not classified as changed evidence: the work remains recoverable and no inference allowance is consumed.

After either materialization path, a fresh canonical work query verifies leased status, owner, fence, prompt, references, action/parent restrictions, token budget and at least 100 seconds of remaining lease. Pause checks run before this admission and again after reservation. Insufficient headroom leaves the lease to normal expiry/recovery; it does not manufacture a provider rate-limit event or renew a lease. The 100-second requirement leaves nominal room for the client's bounded 90-second POST, but scheduling, local I/O and later SQL work can still exhaust it. Existing completion fencing remains authoritative.

Projected evidence hashes and the prompt hash attest exact bytes at their respective boundaries. They do not attest full native record identity, current world truth or continuous source freshness. Late consolidation integration still checks source eligibility and evidence. A source may change after the last check and before transmission; this implementation does not provide a transaction spanning SQLite, the native memory daemon and a remote provider.

## Verification

PHP syntax and scoped whitespace checks passed for the Core and worker changes. Independent source review covered ordering, PHP numeric hash-key coercion, concurrent preparation, immutable claim binding, v1/v2 gates, cancellation ownership, prompt exclusion from telemetry, lease headroom and pause checks. Runtime equivalence, crash behavior and provider compatibility remain unverified pending explicit test approval. See [transport limits](../../../docs/opencode-dream-transport.md) and [dream selection and integration](../../../docs/dream-consolidation.md) for the surrounding contracts.
