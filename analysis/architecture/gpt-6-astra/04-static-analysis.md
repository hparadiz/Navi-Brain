# Native compiler static analysis

Latest follow-up: the final whole-file Clang pass includes the ID-allocation and whole-prefix PAGE/LIST changes and completed with the same nine previously qualified warning sites. No new warning site was reported. The sections below preserve the original close-failure leak investigation, the later pathname-replacement hang repair, their source identities and the analyzer's important nonblocking-I/O modeling limitation.

Clang's bounded whole-file analysis reported no warning sites introduced by the current native changes. It reported eleven existing sites; the same analysis of `HEAD` reported those eleven plus one warning in the removed decoded-content comparison. One existing warning identified a concrete allocation leak on a failed `close()`. That path is now repaired, and a focused before/after analysis reproduces the warning before the repair and emits no diagnostics afterward.

GCC's whole-file `-fanalyzer` run did **not** complete: it reached the imposed 2 GiB address-space ceiling. A separate normal GCC build of the repaired source completed without diagnostics. These are static checks, not runtime equivalence tests or latency measurements. No daemon, native executable, live store, inference request, or test fixture was executed.

## Source and tool identity

All analyzer inputs were isolated source copies under `/tmp/navi-native-static-qb_gq17v`; analyzer logs and compiled output also stayed there. The working source is [tokmem.c](../../../memories/src/tokmem.c).

| Input | Identity |
|---|---|
| Baseline | Git `HEAD` commit `ee1590009d6fd4c48035c63e15b3896a585757d7` |
| Baseline C SHA-256 | `4296962e70ce46c127defafafda8334ad3c9dafa8bf3e1e9313d773b3dba7cf4` |
| Frozen implementation before leak repair | `a9dc6afdad14d0afc05ebf2719e209b5d88bc2ec6020f34499c6efbd6dff0293` |
| Source after leak repair | `79974b2240fdfdf8f000bd083f6cb6783870c663af2ea6297b67ab7351102419` |
| GCC | Gentoo `15.2.1_p20260214 p5`, GCC `15.2.1 20260214` |
| Clang | `22.1.8`, target `x86_64-pc-linux-gnu` |
| Clang configuration | `/etc/clang/22/x86_64-pc-linux-gnu-clang.cfg` |

The snapshot names are `tokmem-baseline.c`, `tokmem.c` for the frozen implementation, and `tokmem-fixed.c` for the repaired implementation. The installed compilers, `timeout`, `nice`, and `prlimit` were used without installing anything.

## Commands and resource limits

The whole-file Clang command completed with exit status 0 and eleven warning sites:

```sh
timeout --signal=TERM --kill-after=5s 90s nice -n 10 \
  prlimit --as=1610612736 --cpu=60 --fsize=33554432 -- \
  clang --analyze -std=c17 -Wall -Wextra -Wpedantic \
    -Xanalyzer -analyzer-output=text \
    -Xanalyzer -analyzer-config -Xanalyzer max-nodes=75000 \
    /tmp/navi-native-static-qb_gq17v/tokmem.c \
    > /tmp/navi-native-static-qb_gq17v/clang-analysis.txt 2>&1
```

The exact baseline comparison used the same command and limits, substituting `tokmem-baseline.c` and `clang-baseline-analysis.txt`; it completed with twelve warning sites. Clang received a 1.5 GiB address-space ceiling, 60 CPU seconds, 90 seconds wall time, a 32 MiB per-file output limit, and a 75,000-node exploration limit per top-level function. Process priority was reduced with `nice -n 10`.

The whole-file GCC analyzer command was:

```sh
timeout --signal=TERM --kill-after=5s 120s nice -n 10 \
  prlimit --as=2147483648 --cpu=90 --fsize=33554432 -- \
  gcc -std=c17 -O0 -g0 -Wall -Wextra -Wpedantic -fanalyzer \
    -c /tmp/navi-native-static-qb_gq17v/tokmem.c \
    -o /tmp/navi-native-static-qb_gq17v/tokmem-analyzer.o \
    > /tmp/navi-native-static-qb_gq17v/gcc-analysis.txt 2>&1
```

It exited 1 with `cc1: out of memory allocating 208 bytes after a total of 2063446016 bytes`. The 2 GiB address-space ceiling, 90 CPU seconds, 120 seconds wall time, and 32 MiB file cap bounded machine impact. This is an incomplete analysis, not a successful GCC analyzer check or a source compilation failure. Its limits were not raised.

## Finding attribution

Locations below refer to the frozen pre-repair snapshot so they correspond exactly to `clang-analysis.txt`. The five added repair lines shift subsequent working-source locations. Warning sites and their relevant paths were compared with `HEAD`, rather than attributing every diagnostic to the current changes.

| Frozen source location | Clang finding | Source review and disposition |
|---|---|---|
| `read_exact`, line 611 | Blocking `read` inside a critical section | Existing `reserve_operation_receipt` holds the engine mutex while `next_memory_id` reads the sequence file. This is real serialization of file I/O, but the diagnostic does not measure delay or prove deadlock. Unchanged. |
| Lines 3139, 3188, 3246, 3298 | Four unused assignments to `transaction` | Existing cleanup bookkeeping after successful commits. No new incorrect branch was identified; left unchanged. |
| `engine_open`, line 3759 | Potential `catalog_path` allocation leak | The cited path retains the allocation in `engine->catalog_path`. `engine_close` frees it, both on `engine_open` failure and after the traced successful `verify` command. The reported path does not establish a leak; likely an analyzer ownership-model limitation. Unchanged. |
| Lines 4393, 4878 | Two unused assignments to `neutral_intent_pending` | Existing final cleanup assignments. Left unchanged. |
| `show_tokens`, line 5928 | Potential uninitialized `ordered[i]` | The reported path assumes a zero-byte allocation while entering a loop whose limit is clamped to the same count. For a valid resident array, count zero makes the loop empty; a nonzero count is copied with `memcpy` before iteration. The report does not establish a reachable uninitialized read under those invariants. Unchanged. |
| `read_sequence_file`, line 6230 | Allocated `bytes` leaked on an error return | Confirmed by the explicit `safe_read_regular` close-failure path. Repaired in the callee as described below. |
| Line 7987 | Unused final assignment to `pending_count` | Existing shutdown bookkeeping. Left unchanged. |

The extra baseline diagnostic was `core.NonNullParamChecker` at baseline line 4742: zero-token decoding could leave `existing_content == NULL` before a zero-length `memcmp`. The current segment-based equality path removes that call, and this warning is absent from the frozen implementation. That comparison is evidence about the specific diagnostic, not proof that all other null-pointer paths are absent.

## Repaired close-failure ownership

`safe_read_regular` allocated the result and completed the read, then changed success to failure if `close(fd)` failed. Its caller `read_sequence_file` returned immediately on failure, leaking the successful read's allocation. The repair frees that buffer and resets the output pointer and length when close fails after a successful read:

```c
if (close(fd) != 0 && rc == 0) {
    free(*result);
    *result = NULL;
    *result_size = 0;
    rc = -1;
}
```

The `rc == 0` guard is essential: a failed read already frees and clears the pointer and leaves `rc == -1`. The new cleanup therefore cannot free that result twice. Successful reads with successful close retain the existing outputs. The other callers in `read_record_files` use ordinary failure cleanup, which safely accepts the cleared pointer. No caller or publication behavior was otherwise changed.

Focused analysis first reproduced the warning on the frozen source:

```sh
timeout --signal=TERM --kill-after=5s 60s nice -n 10 \
  prlimit --as=1073741824 --cpu=30 --fsize=16777216 -- \
  clang --analyze -std=c17 -Wall -Wextra -Wpedantic \
    -Xanalyzer -analyzer-output=text \
    -Xanalyzer -analyzer-config -Xanalyzer max-nodes=75000 \
    -Xanalyzer -analyze-function -Xanalyzer read_sequence_file \
    /tmp/navi-native-static-qb_gq17v/tokmem.c \
    > /tmp/navi-native-static-qb_gq17v/clang-sequence-before.txt 2>&1
```

The exact same command using `tokmem-fixed.c` and `clang-sequence-after.txt` completed with exit status 0 and an empty diagnostic log. Both focused runs completed successfully; the first emitted the expected leak warning. These runs used 1 GiB address space, 30 CPU seconds, 60 seconds wall time, and 16 MiB file limits.

The repaired source was also compiled and linked using the repository's normal optimization and warning flags:

```sh
timeout --signal=TERM --kill-after=5s 60s nice -n 10 \
  prlimit --as=1073741824 --cpu=30 --fsize=33554432 -- \
  gcc -O2 -g -std=c17 -Wall -Wextra -Wpedantic \
    -o /tmp/navi-native-static-qb_gq17v/tokmem-fixed-build \
    /tmp/navi-native-static-qb_gq17v/tokmem-fixed.c -lsqlite3 -pthread \
    > /tmp/navi-native-static-qb_gq17v/gcc-fixed-build.txt 2>&1
```

The build and `git diff --check -- memories/src/tokmem.c` passed. The resulting executable was not run. Whole-file analysis was not repeated after this local ownership repair; its effect was checked with the reproducing focused analysis and a full normal build.

## Limits

Static analysis explores a bounded model of the program. No-new-diagnostic attribution does not establish runtime equivalence, correct concurrency under every schedule, allocation performance, or complete absence of memory errors. The remaining warnings were recorded rather than suppressed or broadly refactored. Runtime equivalence, failure injection, and performance work still require the pending test approval; no tests or test infrastructure were created during this check.

## Follow-up: remaining-warning triage and regular-file open

The second audit examined every remaining warning's source path against the actual callers, then made one additional two-line native change. It did not remove bookkeeping assignments merely to reduce the diagnostic count.

| Current location | Caller-contract assessment |
|---|---|
| `flush_dirty_locked:3155`, `flush_dirty_pairs_locked:3204`, `flush_dirty_links_locked:3262`, `flush_dirty_memories_locked:3314` | Each reported `transaction = false` follows a successful COMMIT. The remaining path clears only the committed queue batch, sets success and jumps to finalization; it cannot reach the rollback label. Every failing prepare/bind/step/commit path still retains transaction ownership until rollback. These four dead stores are harmless, pre-existing bookkeeping. |
| `engine_open:3775` | `catalog_path` is allocated into an owned Engine field. All subsequent failure branches reach `engine_close`; the analyzer's successful `verify` caller sets `opened` and also calls `engine_close`. That function frees `catalog_path` after closing SQLite. Neither `sqlite3_open_v2` nor `sqlite3_busy_timeout` transfers ownership of the allocated path. This reported path does not establish a leak. |
| `add_record:4409`, `update_record_metadata:4885` | The warned assignments clear `neutral_intent_pending` inside final unpublished-intent cleanup. There is no subsequent read of the flag on those paths. Intent deletion and token rollback remain explicit and unaffected; removing these assignments would be cosmetic. |
| `show_tokens:5935` | Its sole call site follows successful `engine_open(..., false)`. Registry loading rejects fewer than 256 foundational tokens, and every successful append initializes the token-order element before incrementing its count. While holding the mutex, `show_tokens` clamps the requested limit to that initialized count and copies the array before sorting/reading it. The diagnostic path assumes a zero-byte allocation and then enters the loop; it is inconsistent with this successful caller and initialized-resident-array contract. |
| Daemon cleanup, line 8003 | `pending_count = 0` follows closure/release of every pending entry. The rest of shutdown does not consult that local count. It is harmless final bookkeeping, not a missing cleanup branch. |

The earlier close-failure leak is still absent. Focused `read_sequence_file` analysis of the final source emitted no diagnostics.

### Concrete pre-fstat hang

`safe_read_regular` first uses `lstat` to reject nonregular paths, then opens the pathname and uses `fstat` to verify the opened file's type, device, inode and size. Previously that open used blocking `O_RDONLY`. Replacing a regular pathname with a FIFO between `lstat` and `open` could therefore make `open` wait for a writer before reaching the very check intended to reject the replacement. The receipt allocation path calls this helper for the sequence file while holding the engine mutex, so that wait could stall all native state work.

The open flags now include `O_NONBLOCK`. A read-only FIFO open can return immediately and reach the unchanged nonregular-file rejection. Existing `O_NOFOLLOW`, device/inode checks, size caps, exact read and close-failure ownership cleanup remain intact. No file is newly accepted by this change, and regular-file byte handling is unchanged on this Linux platform. The defect and its original blocking-open flags also exist in the recorded HEAD baseline; it was not introduced by the token representation patches.

This finding came from reviewing the blocking-read diagnostic's file-opening path, not from an analyzer-generated FIFO interleaving. The installed primary reference, `/usr/share/man/man2/open.2.bz2`, states that Linux ignores `O_NONBLOCK` for ordinary regular-file I/O. Consequently this change **does not** eliminate disk waits under the engine mutex or provide a hard I/O deadline. Clang stops reporting `unix.BlockInCriticalSection` after the flag is added because its descriptor model treats this open as nonblocking; the reduced diagnostic count must not be mistaken for stronger Linux I/O behavior. No FIFO fixture or native executable was run.

### Follow-up identities and verification

Artifacts are under `/tmp/navi-native-triage-hs5wo6c2`:

| Artifact | Identity/result |
|---|---|
| `tokmem-before.c` | SHA-256 `9624981f9b834273ef24201e2c94593e9ddb9fc521b82cbed85ae269585f06a7`, including the separately reviewed exact metadata comparison |
| `tokmem-after.c` and final working C | SHA-256 `f2b8c5d2a0c94e453ce98de36f6f3b58f7ef6dbf7a42a1521afeaec2c5392b30` |
| `receipt-before.txt` | Focused `receipt_begin` analysis: exit 0, one blocking-read diagnostic |
| `receipt-after.txt` | Same focused analysis after the flag change: exit 0, empty log; descriptor-model limitation explained above |
| `sequence-after.txt` | Focused `read_sequence_file` analysis: exit 0, empty log |
| `clang-after.txt` | Whole-file Clang analysis: exit 0, the nine baseline sites listed above |
| `gcc-build.txt`, `tokmem-build` | Warning-enabled GCC build/link: exit 0, empty diagnostic log; executable not run |

Commands used the same installed tools and bounds as the original audit. Focused Clang runs selected their named function with `-Xanalyzer -analyze-function -Xanalyzer FUNCTION`, with 1 GiB address space, 30 CPU seconds, 60 seconds wall time, 16 MiB output and 75,000 analysis nodes. Whole-file Clang used 1.5 GiB address space, 60 CPU seconds, 90 seconds wall time and 32 MiB output. The GCC build used 1 GiB address space, 30 CPU seconds, 60 seconds wall time and 32 MiB output, with `-O2 -g -std=c17 -Wall -Wextra -Wpedantic -lsqlite3 -pthread`. All source inputs and compiler outputs stayed in `/tmp`; `git diff --check` passed. No runtime/test or throughput claim follows from these checks.

## Final whole-file checkpoint after allocation and page changes

The final native source is SHA-256 `fe926d4d1aebe2bde594a5966f466d7368d27c002a189d3dbc19d368d92786e9`. It includes the sorted-tail and receipt-index allocation changes from [report 05](05-native-publication-capabilities.md), plus the capacity-specific return and whole-record prefix behavior from [report 07](07-neutral-hydration-bounds.md). The latter crosses shared record-framing callers, so the final verification repeated whole-file analysis rather than relying only on its focused `list_memory_records` check.

The final command was:

```sh
timeout --signal=TERM --kill-after=5s 90s nice -n 10 \
  prlimit --as=1610612736 --cpu=60 --fsize=33554432 -- \
  clang --analyze -std=c17 -Wall -Wextra -Wpedantic \
    -Xanalyzer -analyzer-output=text \
    -Xanalyzer -analyzer-config -Xanalyzer max-nodes=75000 \
    /tmp/navi-native-record-prefix-wjn0qih2/tokmem-final.c \
    > /tmp/navi-native-record-prefix-wjn0qih2/clang-whole-final.txt 2>&1
```

It exited 0 with nine warnings. Comparison with `/tmp/navi-native-triage-hs5wo6c2/clang-after.txt` found the same diagnostic messages and source statements at all nine sites; only source line numbers shifted. No warning was suppressed or removed for cosmetic reasons.

| Previously qualified site | Final source line | Result |
|---|---|---|
| Four successful-commit `transaction` assignments | 3157, 3206, 3264, 3316 | Same `deadcode.DeadStores` diagnostics |
| `engine_open` owned `catalog_path` path | 3777 | Same `unix.Malloc` diagnostic and ownership assessment |
| Final `neutral_intent_pending` cleanup assignments | 4414, 4889 | Same `deadcode.DeadStores` diagnostics |
| `show_tokens` initialized/clamped token order | 5975 | Same `core.uninitialized.Assign` diagnostic and caller invariant |
| Final daemon `pending_count` assignment | 8043 | Same `deadcode.DeadStores` diagnostic |

`diagnostic-comparison.txt` in the final artifact directory records the old/new line mapping and exact diagnostic/source-statement comparison. `source.sha256` identifies the analyzed snapshot. The same directory's `gcc-build.txt` and `clang-list-memory-records.txt` are empty successful diagnostic logs for the warning-enabled full build and focused analysis at this exact source. The resulting `tokmem-build` executable was not run. Final source hashing and `git diff --check` also passed.

These results close the bounded compiler pass; they do not claim an analyzer-clean program or remove the nine caller-qualified warnings. The earlier GCC `-fanalyzer` resource-limit failure remains an incomplete check. There were no further native edits, behavioral tests, fixtures, live catalog operations, provider calls or performance measurements in this final verification.
