# Token-memory cutover, backup, and recovery

The token store is the live memory payload. A SQLite-only backup is not a
memory backup after cutover: it omits the binary token streams, their adjacent
checksums, the learned token/link/access state, operation receipts, and the
reserved memory-ID sequence.

All commands below require explicit paths. None selects the live database or
token store by default.

## Atomic backup bundle

Create a bundle in a pre-existing backup directory:

~~~sh
bin/token-memory-bundle create \
  --database /path/to/navi-brain.sqlite \
  --store /path/to/token-store \
  --tokmem /path/to/memories/build/tokmem \
  --destination /path/to/backups/navi-20260831.bundle \
  --close-daemon \
  --deep-verify
~~~

`--close-daemon` sends the daemon's barriered `CLOSE`, which drains admitted
requests and all dirty learned-state queues. The backup process then holds the
store's exclusive `.lock` for the complete snapshot. It also holds a SQLite
`BEGIN IMMEDIATE` guard while using SQLite's online-backup API, so application
database writers cannot cross either individual snapshot. These two locks
cannot repair a cross-store operation that is already between its token-store
and application-database commits. A recovery-grade bundle therefore requires
all application memory writers to be stopped and their in-flight operations to
have drained before `create` starts. `bin/replicate` enforces that lifecycle
through its mandatory quiescence helper; an interactive operator must establish
the same stopped-writer condition explicitly.

The destination is assembled as a sibling staging directory. Every copied file
and directory is fsynced, the inventory is verified, and Linux `renameat2` with
`RENAME_NOREPLACE` publishes it. An existing destination is never overwritten.
The bundle contains:

~~~text
FORMAT
MANIFEST.sha256
application.sqlite3
token-store/catalog.sqlite3
token-store/sqlite-sequence
token-store/memories/TIER/ID/content.memory
token-store/memories/TIER/ID/metadata.memory
token-store/memories/TIER/ID/manifest.sha256
~~~

`catalog.sqlite3` includes all token translations, the independent write/read/
usage counters, pair observations, link reinforcement, access state, accounting
state, and operation receipts. `MANIFEST.sha256` is a byte-sorted, complete
inventory containing the SHA-256 and byte length of every other regular file.
Creation rejects symlinks, special files, unexpected record members, malformed
adjacent manifests, bad blob hashes, a noncanonical sequence, failed SQLite
`quick_check`, and a failed `tokmem verify`. Verification also enforces the
exact four-member bundle root and the exact token-store topology, so an
unmanifested file or empty tier directory cannot hide outside the inventory.
`FORMAT` records whether the catalog is the exact pre-receipt `core5` schema or
the current `current7` schema. Both are valid rollback states. Creation never
opens the bundled catalog through `tokmem`: it deep-verifies an exact disposable
copy, because `tokmem` startup legitimately adds the two current control-plane
tables to an older catalog. The manifest therefore authenticates the source
catalog's original schema and learned data, not a silently normalized variant.
Schema classification is fail-closed: every canonical table DDL and implicit
index is matched exactly, `user_version` and `application_id` must both be zero,
and any extra table, index, trigger, or view rejects the bundle.

Verify a received bundle without changing it:

~~~sh
bin/token-memory-bundle verify \
  --bundle /path/to/backups/navi-20260831.bundle \
  --tokmem /path/to/memories/build/tokmem \
  --deep
~~~

Deep verification copies the bundled token store to a disposable directory
before opening it with `tokmem`; startup reconciliation can therefore never
mutate the backup being authenticated.

### Backup failure recovery

- Before publication, failure removes only the uniquely named sibling staging
  directory and leaves the destination absent. Stages come from `mkdtemp`; their
  parent and stage device/inode identities are retained, and cleanup refuses a
  replaced path. Recursive memory and bundle walks retain root, tier, record,
  and directory file descriptors; every child is opened relative to its pinned
  parent with `O_NOFOLLOW`, and its visible inode is re-proved after the read,
  copy, hash, or fsync. Locking and no-clobber publication use the same anchored
  identity checks.
- If publication succeeds but the destination-parent fsync fails, the command
  reports failure and deliberately leaves the no-clobber destination in place.
  Run `verify --deep`; retain it only if that succeeds, otherwise quarantine
  that exact bundle path. Never overwrite it with another attempt.
- `--close-daemon` leaves the daemon stopped. Normal application memory access
  may start it again after the backup releases `.lock`; for a cutover, restart
  writers only after the fence/replica decision is complete.

## Application schema upgrade

Schema upgrades that introduce the exact procedure and asynchronous ledgers
require a hard application-writer boundary. Stop every old PHP worker and wait
for its in-flight SQLite transaction to finish before running `Schema::ensure`
from the new release. Keep those workers stopped until the upgrade transaction
has committed and the installed migration checksums have been verified. Restart
only processes using the new code.

This is required even though SQLite applies each migration transactionally. A
pre-upgrade process that survives the DDL commit can later write an old half of
a cross-table protocol (for example a waiting look action without its new look
claim), leaving state that no migration snapshot could have backfilled. Never
perform this upgrade as a rolling restart across mixed application versions.

## Restore and rollback

Restoration is deliberately new-target-only. It will not silently replace a
live database or store:

~~~sh
bin/token-memory-bundle restore-new \
  --bundle /path/to/backups/navi-20260831.bundle \
  --database /new/path/navi-brain.sqlite \
  --store /new/path/token-store \
  --tokmem /path/to/memories/build/tokmem
~~~

Both parents must exist and both targets must be absent. The command deep-
verifies the bundle, stages and fsyncs each target beside its destination, and
publishes each with atomic no-clobber rename. If the second publication fails
during the running process, it moves the first publication back and removes
the staging copies. A power loss can occur between the two filesystem renames;
with all writers still stopped, rerun the identical command with
`--resume-partial`. It hashes the existing half against the bundle and only
publishes the missing half. It refuses any non-identical existing target.
The database target's `-wal`, `-shm`, and `-journal` siblings must be absent on
both a fresh and resumed restore; their presence is rejected before staging and
again at publication. When a partial database main file already exists, restore
authenticates it under SQLite `BEGIN EXCLUSIVE` and holds that guard through
publication and authentication of the missing token-store counterpart. A fresh
database publication is likewise authenticated under an exclusive guard before
success. Restore staging uses inode-owned private directories, and a failure
rolls back every counterpart published by that invocation without recursively
removing an unowned replacement.
Restoring a `core5` catalog preserves its exact bundled bytes and logical state; the first later
daemon start performs its normal forward-compatible table creation in the live
restored copy, never in the backup bundle.

For an in-place rollback, keep the old database and token-store directory under
new names, stop every local writer and the token daemon, restore the bundle to
two absent versioned paths, verify it again, then change both service paths as
one operator-controlled cutover and restart. Do not recursively copy over a
live store: a process waiting on the old `.lock` inode could otherwise enter a
different directory than the one being replaced.

## Legacy SQLite write fence

The fence is not a schema migration. Fresh installs, token-store import, and
token-store export are unaffected unless an operator explicitly activates it.
Installation first takes the original stopped store's exclusive `.lock`. While
holding that lock, it copies the blobs and sequence plus a SQLite-online-backup
snapshot of the catalog to a private disposable store and exports that copy.
It retains the original lock while taking a SQLite immediate transaction,
proving exact bidirectional parity across all eleven memory columns and
`sqlite_sequence`, and committing three `BEFORE` triggers that abort legacy
`INSERT`, `UPDATE`, and `DELETE` statements. The original store can therefore
neither change nor become resident anywhere in the proof-to-trigger window:

~~~sh
bin/token-memory-legacy-fence install \
  --database /path/to/navi-brain.sqlite \
  --store /path/to/token-store \
  --tokmem /path/to/memories/build/tokmem \
  --confirm-post-parity

bin/token-memory-legacy-fence status \
  --database /path/to/navi-brain.sqlite
~~~

`status` reports `absent`, `installed`, or `partial-or-drifted`, together with
the expected and installed SHA-256 fingerprint of the canonical trigger SQL. A
partial or modified trigger set fails closed; do not repair it by hand. Re-establish the
stopped-writer parity window, remove the fence explicitly, and reinstall it.

Rollback to legacy memory writes is a conscious loss of the token store as the
sole writer. First stop application workers and the token daemon, export the
token store, verify parity with the intended legacy database, and only then:

~~~sh
bin/token-memory-legacy-fence remove \
  --database /path/to/navi-brain.sqlite \
  --confirm-legacy-writes
~~~

The removal transaction drops only the three named triggers. It never copies,
deletes, or rewrites memory rows.

Fence installation pins the store directory and visible `.lock` inode for the
whole proof. Catalog, sequence, tier, record, and blob copies use retained
directory descriptors and `openat`-style `O_NOFOLLOW` opens. The visible store
and lock identities are re-proved before the parity transaction and immediately
before its trigger commit; replacement fails closed.

## Mesh replication

`bin/replicate` now requires `NAVI_REPLICATION_QUIESCE_HELPER` to be an absolute,
non-symlink executable. The helper protocol is:

~~~text
HELPER quiesce --database DATABASE --store STORE
HELPER verify  --database DATABASE --store STORE
HELPER resume  --database DATABASE --store STORE
~~~

`quiesce` must disable every process capable of committing memory state and wait
for its in-flight operation to finish; `verify` must fail unless those writers
remain disabled. Replication verifies quiescence before and after local bundle
creation, resumes writers before network upload, and retries `resume` from its
exit trap after any local failure. It therefore refuses periodic replication
when no site-specific writer coordinator is configured instead of advertising
a potentially split cross-store snapshot.

It creates the complete bundle and uploads it under a unique
`.incoming` directory. The remote `publish-received` command deep-verifies it,
fsyncs it, publishes it with `renameat2(RENAME_NOREPLACE)`, fsyncs the parent,
and deep-verifies it again. It does not overwrite the
remote live store or restart a daemon. Replica activation is the stopped-writer
`restore-new`/path-switch procedure above; this makes receipt state and learned
counters part of the promoted state and avoids an unreviewed split-brain
cutover.

An interrupted upload or a verification failure before publication leaves only
its uniquely named `.incoming` directory; the final bundle name remains absent.
Once the no-clobber rename succeeds, however, a parent-fsync failure or the
post-publication deep verification can report failure while the final bundle
remains in place. Diagnose the exact incoming or final path reported by the
command, run `verify --deep` on a final path, and quarantine it if verification
does not pass. The publisher intentionally never deletes an ambiguously durable
or already-published final bundle.
