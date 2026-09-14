# Token architecture validation series

Static review of the current Navi-Brain working tree. No live profiling, memory access or provider requests were used for these reports. Native build/layout checks are labeled in the relevant report; they are not behavioral benchmarks. Test creation remains subject to explicit approval.

| Report | Scope | State |
|---|---|---|
| [00 — Representation map](00-map.md) | End-to-end boundaries, invariants, compatibility and inspection priorities | Shared map |
| [01 — Native representation](01-native-representation.md) | Registry, blobs, resident structures, storage and decoded-content identity | Complete static audit; authorized identity fix documented |
| [02 — Boundary performance](02-boundary-performance.md) | PHP/native/provider conversions, batching and allocation opportunities | Complete static audit |
| [03 — Operation accounting](03-operation-accounting.md) | Counted/neutral semantics, query learning, receipts and backup limits | Complete static audit |
| [04 — Compiler analysis](04-static-analysis.md) | Baseline attribution, ownership, cleanup and blocking-open findings | Compiler analysis; remaining diagnostics qualified |
| [05 — Publication and protocol](05-native-publication-capabilities.md) | Cached digest reuse, typed provenance compatibility and indexed ID allocation | Source review and native build |
| [06 — Bounded queue references](06-bounded-queue-references.md) | Versioned dream manifests, ephemeral prompt reuse and restart reconstruction costs | Source review and PHP syntax checks |
| [07 — Neutral hydration bounds](07-neutral-hydration-bounds.md) | Decoded-byte costs, incremental prompt sizing and whole-prefix page progress | Source review, native build and PHP syntax checks |

Specialized reports qualify the initial map. No optimization is accepted merely because it increases the fraction of bytes represented as token IDs: evaluate correctness, resident footprint, work performed and the external boundary that still needs text. SQLite record export is not an exact vocabulary/graph checkpoint; native token budgets are not provider token budgets.

The storage design already uses token IDs for both content and metadata. Keep token IDs through native selection and indexing, parsed scalars for control predicates, and reconstruct text where an external consumer requires it. Sharing the native vocabulary with every PHP process or substituting native IDs for a model's tokens is not justified by this review.

Implemented native changes include representation-independent content digests, span matching directly across token segments, in-place posting expansion sorting, cached immutable content length, segmented exact comparison, smaller final ACTIVATE sorting cohorts, cached manifest digests and fieldwise exact metadata comparison. Content digest and length caches add 40 resident bytes per memory; the removed expanded-node copy saves 8 bytes per expanded node, up to 32 MiB at the existing limit. These are structural allocation results, not measured latency improvements. Native builds and independent source reviews pass; runtime equivalence and benchmarks remain pending explicit test approval.

ID allocation now reads the existing sorted resident tail and has a SQLite index for the receipt maximum, preserving sequence and reservation checks. This removes the resident history scan without another RAM cache. The receipt index adds persistent storage and a one-time build for existing catalogs; its entries also carry the receipt's `op_key` primary key. [Report 05](05-native-publication-capabilities.md) records the planner inference and startup tradeoff without claiming a measured speedup.

The optional [dream lane](../../../docs/dream-consolidation.md) keeps source selection and maintenance local, with a separately budgeted text boundary for the chosen model. Its [direct transport](../../../docs/opencode-dream-transport.md) avoids agent-CLI retry amplification. Source IDs, digests and typed references govern internal accounting; provider requests still require the provider's text/token boundary. See the [implementation checkpoint](../../../docs/cognition-implementation-2026-09.md) for the broader work and deployment limits.
