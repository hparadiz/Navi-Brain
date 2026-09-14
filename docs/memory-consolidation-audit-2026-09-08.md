# Memory consolidation audit — September 8, 2026

Consolidation is useful when explicitly curated, but the automatic mechanism is currently incomplete and has spent substantial work on repeated evidence. This assessment combines a neutral native-memory census, the live consolidation/work ledger, source inspection, and a preview/apply/replay of the local repair. It does not establish downstream answer accuracy or semantic entailment.

## Observed state before repair

| Measurement | Result |
| --- | ---: |
| Active episodic memories | 6052 |
| Active semantic memories | 579 |
| Excess exact duplicate active episodes | 2926 (48.3%) |
| Active episodes marked consolidated | 1090 |
| Pending / queued ledger episodes | 577 / 15 |
| Last successful automatic consolidation event | August 18, 2026, 01:27:05 UTC |
| Completed consolidation work items / accepted events | 2037 / 484 |
| Rejected episodes: incomplete source partition | 1002 |
| Rejected episodes: insufficient lexical support | 666 |

The completion/event counts are historical aggregates, not a quality or acceptance-rate experiment: outcomes include earlier validator revisions and recovery. Recent semantic memories with direct session provenance contain useful project constraints and implementation findings. Their explicit writes continue even though automatic inference consolidation has stalled.

The configured profile is `native-maintenance`, with private-memory egress disabled. Both OpenRC model-worker services reported stopped. Before this repair, the native profile did not perform consolidation even when running. The heartbeat still creates consolidation work that may later expire without a model worker.

A separate quality gap remains: generic consolidation evidence is cut at 800 characters. Of 387 active `Session in` episodes, 330 exceed that limit. Later corrections and outcomes can therefore be absent from a consolidation prompt. Changing this safely also requires updating the frozen dream projection/hash contract; that change is outside this minimal repair.

## Zero-inference repair

`ExecutiveCore::reuseConsolidationEvidence()` associates unassigned pending/rejected episodes with an **existing** claim only when:

- The semantic claim directly references an active, unexpired source episode.
- The claim is active, unexpired, above the existing confidence floor, and passes the current grounding validator against that source alone.
- The full target episode is byte-identical to the source, including case, punctuation, quantities and negation.
- Fresh neutral reads confirm the match, and the ledger's previous status, timestamp and reason still match when updating it.

This deliberately reuses fewer records than the 1004 broad candidate matches found through the historical ledger. Direct provenance and current validation reduce that to 650 eligible unassigned episodes. It does not create, delete, rewrite or supersede native memory records, raise confidence, reinterpret repeated observations as corroboration, or touch assigned model work. Each reuse event stores the previous ledger row for audit and selective recovery. SQLite was backed up before applying the repair.

The default maintenance worker calls this local sweep at startup and then every 90 minutes. There is no new model, provider dependency, migration, hash table on disk, or inference expense. The sweep reads pages neutrally and costs linear local work; it is not a constant-work algorithm. Process restarts can repeat the sweep.

## Live result and limits

| Ledger outcome | Before | After |
| --- | ---: | ---: |
| Consolidated (includes inactive historical sources) | 1204 | 1854 |
| Pending | 577 | 399 |
| Rejected | 2844 | 2372 |
| Queued | 15 | 15 |

The apply command reused 650 claims in approximately 2.13 seconds: 178 pending episodes and 472 previously rejected episodes. All 650 changes had matching audit events. Repeating `--apply` reported zero matches and zero changes; replay plus status took approximately 0.11 seconds. A normal `navi-brain-model-worker --once` pass also completed successfully with the reuse hook, zero inference calls and no workspace errors. PHP lint and SQLite `quick_check` passed. No test suite or test infrastructure was created.

The repair removes duplicate inference demand from the ledger, not duplicate records from recall. Novel facts still need explicit consolidation or an authorized model lane. The current grounding validator only checks literals and lexical overlap; it does not prove a claim true. Full native source checks and executive commits are not one atomic transaction.

The recurring hook is implemented but service activation is pending: `sudo -n rc-service navi-brain-model-worker start` failed because sudo requires authentication. Start the configured local-only worker with:

```sh
sudo rc-service navi-brain-model-worker start
```

The repair already applied to memory does not depend on starting that service. Manual preview and apply remain available:

```sh
php bin/navi-brain memory:consolidation:reuse
php bin/navi-brain memory:consolidation:reuse --apply
```
