# Direct PDO usage policy and inventory

This document describes the intentional exceptions to Navi-Brain's normal
ActiveRecord persistence rule. It is organized by invariant-bearing code path,
not by volatile source line number. The reproduction search at the end is the
authoritative exhaustive inventory for any particular revision.

## Persistence rule

Ordinary domain creates and updates hydrate a Divergence ActiveRecord and call
`save()`. Raw SQL may write domain tables only when correctness depends on an
atomic compare-and-set, an affected-row result, a lease/fencing predicate, or a
durability journal that cannot be expressed as load/edit/save.

The `memories` payload is not a PDO exception. After token-store cutover it is
read and written through `TokenMemoryDaemon`; SQLite retains only application
state, transition journals, and operation receipts. The optional legacy
`memories` table can be protected with the post-parity triggers documented in
[`token-memory-cutover.md`](token-memory-cutover.md).

## Intentional direct access

### Database and schema infrastructure

- `bootstrap/app.php` selects SQLite and applies connection PRAGMAs.
- `src/Storage/Schema.php` reads and advances `PRAGMA user_version`, executes
  versioned DDL, and brackets migration transactions. Migration-ledger rows are
  still ActiveRecords.
- `ExecutiveCore::quickCheck()` runs SQLite's integrity check.
- `ExecutiveCore::backupDatabase()` reads `PRAGMA database_list` only to locate
  the main database, then invokes the locked token-memory bundle tool. It no
  longer creates or advertises a SQLite-only memory backup.

### Working-memory projection journal

`src/Core/WorkingMemory.php` uses `BEGIN IMMEDIATE` and exact conditional
updates for the canonical-slot/token-projection handoff. The durable pending
value contains only a protocol kind and SHA-256 digest; it never contains the
claim, projected metadata, or memory content. Raw updates are limited to:

- atomically installing an intent only when none exists;
- attaching a discovered token-memory ID by compare-and-set;
- clearing the exact pending intent only while the expected pointer matches;
- converting a pre-cutover payload-bearing journal to the minimal digest form
  by exact compare-and-set before replay.

### Procedure generation guard

`src/Core/ProceduralMemory.php` directly accesses
`procedure_compile_guards`. Its transaction claims one monotonically increasing
observation generation, records only that generation and a digest, and commits
the generation after the procedure mutation and deduplicated event are durable.
The affected-row check prevents concurrent observers from silently replacing a
pending generation.

### Executive compare-and-set transitions

`src/Core/ExecutiveCore.php` retains raw conditional writes for operations
whose result is the state-machine fence:

- exact event deduplication;
- memory-store operation receipt reservation and completion;
- interrupt, cognitive-thread, work-item, and rhythm lease claims;
- fenced rhythm completion, failure, verification, and parent-lease extension.

These statements run inside the adjacent transaction wrapper and use
`rowCount()` or `RETURNING` where loss of the claim must be observable.

### Bounded scheduler queries

The consolidation scheduler uses direct, indexed, read-only SQL for grouped
ledger counts, active work counts, its episode cursor, distinct recovery work
IDs, and a bounded pending window. Memory payload/provenance/count reads remain
counter-neutral daemon operations. This avoids hydrating the whole ledger or
issuing one ranked query/fetch per episode.

Other direct reads in `ExecutiveCore` support event watermarks, bounded ID
windows, lease verification, and metrics aggregation. They do not bypass an
ActiveRecord domain write.

## Transaction boundary

`ExecutiveCore::transaction()` owns the normal SQLite transaction and also
raises `Memory`'s external-transaction fence. Token-memory writes are rejected
while that fence is active because SQLite and the token store cannot form one
atomic transaction. Working-memory and procedure handoffs therefore use
explicit replayable journals across the two durability boundaries.

## Reproduction search

Run this from the repository root to enumerate every current direct access;
review additions against the categories above:

```sh
rg -n --glob '*.php' -g '!vendor/**' \
  '\bPDO\b|Connections::(?:setConnection|getConnection)\(|->(?:query|prepare|exec|beginTransaction|commit|rollBack|inTransaction|quote|setAttribute|execute|fetch|fetchAll|fetchColumn|closeCursor|rowCount)\(' \
  bootstrap src bin config
```

`bin/opencode_thread_memory.php` separately invokes the `sqlite3` CLI against
OpenCode's own database; it does not access Navi-Brain's token-memory store.
