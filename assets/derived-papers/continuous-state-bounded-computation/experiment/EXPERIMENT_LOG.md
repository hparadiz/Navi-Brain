# Sparse Belief Ledger Experiment Log

This is the append-only operating record for implementation, profiling,
hardware, runs, and final data tabulation.

The scientific design lives in
[../sparse-belief-ledger-experiment.md](../sparse-belief-ledger-experiment.md).
The workspace and hardware policy live in [README.md](README.md).

Do not rewrite old entries to make the path look cleaner. Add a dated correction
or superseding decision with the reason and evidence.

## Current status

```text
Stage:                single-seed A-E development pilot completed
Code:                 simulator, A-E runners, compact adapter, ledger, tabulator
Runs:                 one corrected 1,000-tick tape across A-E on Kaiju
Primary host:         Kaiju; CPU-only primary experiment
Current workstation:  explicitly excluded from experiment execution
Model candidate:      Qwen3.5 9B Heretic Q4_K_S; compact semantic adapter qualified
Model backend:        ik_llama.cpp build 4551; reasoning disabled
Language:             Python 3.14.3
Preregistration:      not locked
```

## Locked methodological decisions

| ID | Decision | Status |
|---|---|---|
| D001 | Build the deterministic simulator and oracle before model integration. | Locked |
| D002 | Conditions A–D require a semantic LLM; condition E is deterministic. | Locked |
| D003 | Charge every updater and query call to interval and cumulative cost. | Locked |
| D004 | Treat quiescent state preservation as a software invariant. | Locked |
| D005 | Use the same model build and primary machine for A–D. | Locked |
| D006 | Do not use remote API latency for primary systems claims. | Locked |
| D007 | Do not run experiments or profilers on the current workstation. | User requirement |
| D008 | Kaiju is the primary experiment host. | Locked |
| D009 | Treat the first study as an offline CPU experiment; make no 140 ms real-time claim. | Locked |
| D010 | Use Qwen3.5 9B for semantic-only structured transitions; deterministic runtime owns tool and action fields. | Locked after qualification |
| D011 | Run the neural semantic operator with reasoning disabled. | Locked after qualification |
| D012 | Use an ordered, pipe-delimited compact value protocol with a per-request GBNF grammar as the primary neural wire format; retain JSON as an ablation. | Locked after qualification |
| D013 | Compare all neural conditions on the same 11-field semantic metric: 3 world, 6 belief, and 2 presence fields at 40 evaluation points. | Locked after pilot metric correction |
| D014 | Treat all results collected before preregistration as development evidence only, including the complete seed-7 A-E table. | Locked |
| D015 | Confirmatory history sweep v1 uses seeds 11/29/47, ticks 100/500/1,000/5,000, conditions A/B/D/E, K=10, and the aggregation/exclusion rules in the frozen config. | Locked at 2026-08-13T10:23:02-07:00 before any confirmatory run |
| D016 | Treat sleep-like global maintenance as a separate falsifiable extension: no audit versus oracle repair ceiling versus model-based provenance replay. | Proposed after confirmatory design; not part of history-v1 |

## Hardware decision

An LLM is not required to implement or validate the simulator, oracle,
dependency labels, snapshot invariants, scoring, or condition E.

An LLM is required for the actual neural comparison in conditions A through D.
For primary performance data, it will run locally on Kaiju's CPUs. A remote API
may be used only for a separately labeled quality replication.

Selected Kaiju hardware:

```text
2x Intel Xeon E5-2687W v4 (Broadwell)
24 physical cores / 48 logical CPUs
approximately 256 GiB RAM
2 NUMA nodes, approximately 128 GiB per node
AVX2, FMA, F16C; no AVX-512, VNNI, or BF16
```

This is accepted for the first experiment. It is a controlled offline platform,
not a real-time target. Before primary runs, benchmark and lock thread affinity,
SMT use, and one-node versus two-node memory placement.

The selected development model is Qwen3.5 9B Heretic Q4_K_S. It is small enough
for Kaiju, has completed a 50k-context test, and its exact file checksum and
inference-engine build are recorded. Source and license provenance remain
pending before paper-scale publication.

## Machine manifest template

Create one immutable manifest per hardware/software configuration under
`manifests/`.

```yaml
manifest_id:
captured_at_utc:
hostname:
purpose: development | primary | replication

os:
kernel:
cpu_model:
physical_cores:
logical_cpus:
numa_layout:
cpu_isa:
system_ram_bytes:

gpu_model:
gpu_count:
gpu_vram_bytes:
gpu_driver:
compute_runtime:
gpu_power_limit:
gpu_clock_policy:

storage_model:
filesystem:
free_bytes_at_start:

inference_engine:
inference_engine_version:
engine_build_flags:
compiler:

background_workloads:
thermal_notes:
```

## Model manifest template

```yaml
model_manifest_id:
model_name:
source:
model_file:
sha256:
parameter_class:
weight_quantization:
kv_cache_quantization:
declared_context_length:
tested_context_length:
chat_template:
structured_output_mode:
temperature:
seed_support:
max_output_tokens:
batching_configuration:
```

## Provisional Kaiju manifest

The supplied inventory has been captured in
[`manifests/kaiju-cpu-provisional.yaml`](manifests/kaiju-cpu-provisional.yaml).
Unknown software, storage, and power fields remain visibly pending rather than
being guessed.

## Provisional model manifest

The supplied model and benchmark facts have been captured in
[`manifests/qwen35-9b-heretic-q4ks-provisional.yaml`](manifests/qwen35-9b-heretic-q4ks-provisional.yaml).
These measurements qualify feasibility only; they are not experiment results.

## Code change log

Add an entry for every meaningful implementation decision or behavior change.

| Date | Change ID | Files/components | What changed | Why | Verification | Commit |
|---|---|---|---|---|---|---|
| 2026-08-13 | C000 | Workspace only | Created language-neutral experiment structure and operating log. | Separate implementation evidence from the paper design. | Directory and document inspection. | Uncommitted |
| 2026-08-13 | C001 | `src/csbc_ledger.py`, `scripts/run_oracle.py`, `configs/dev-oracle.json` | Added deterministic seven-class event tapes, 17-node immutable snapshots, independent router, oracle condition E, structural-sharing metrics, quiescent checks, and JSONL ledger. | Establish the non-neural experimental ruler before adding a model. | Remote compile and 1,000-tick Kaiju development run; zero invariant violations and exact final tape hash. | Uncommitted; source SHA-256 `4972fc7d...`, runner `1be9d183...` |
| 2026-08-13 | C002 | `scripts/qualify_model.py`, model server contract | Added seven-class JSON-schema qualification and separated neural semantic writes from deterministic tool/action writes after the coupled contract exposed a control-state error. | Prevent semantic inference from executing tools or suppressing arbitration. | Q1 0/7 schema-valid with reasoning; Q2 7/7 schema-valid and 6/7 exact without reasoning; Q3 semantic-only 7/7 exact. | Uncommitted; script SHA-256 `7c93bd37...` |
| 2026-08-13 | C003 | `src/llama_adapter.py`, `scripts/qualify_model.py` | Added a low-token protocol that emits ordered enum values separated by `|`, with exact cardinality and value domains enforced by a generated GBNF grammar. JSON remains available as an ablation. | Remove repeated key and object syntax from every state transition while retaining syntactic validation and fixed write authority. | Compact qualification Q5: 6/7 exact, 14 completion tokens total, 9.770 seconds. | Uncommitted; adapter SHA-256 `803b43a9...` |
| 2026-08-13 | C004 | `src/csbc_ledger.py` | Repaired event prose so every model-visible event actually expresses its oracle payload, including status, observers, corrections, and false reports. | The first D pilot exposed a hidden-label confound: natural language omitted facts that only existed in the simulator payload. | Corrected tape retained the same 47 events, oracle final state, and final hash `82a1f5aa...`. | Uncommitted; source SHA-256 `50ceec76...` |
| 2026-08-13 | C005 | `scripts/run_sparse_neural.py`, `scripts/run_reconstruction.py` | Implemented conditions A-D, failure fallback, immutable ledgers, compact inference accounting, semantic accuracy, target accuracy, spurious mutation, routing, and structural-sharing metrics. | Complete the smallest end-to-end comparison on one immutable tape. | A-E development pilot completed on Kaiju without malformed compact outputs or invariant violations. | Uncommitted; sparse runner `84c3db19...`, reconstruction runner `a555b2e9...` |
| 2026-08-13 | C006 | `scripts/tabulate_results.py` | Added raw-summary-to-Markdown tabulation for the common A-E field set. | Prevent denominator drift and hand-selected rows in comparison tables. | Generated seed-7 pilot table SHA-256 `12fe628b...`. | Uncommitted; script SHA-256 `8bae934b...` |
| 2026-08-13 | C007 | Tape generator and A/B/D ledgers; `scripts/tabulate_history_sweep.py` | Added explicit evaluation checkpoints, per-checkpoint common accuracy, interval/cumulative inference accounting, and deterministic multi-seed aggregation. | Test history scaling without querying every 25 ticks or changing metrics after results. | Local compile and JSON validation only; frozen before remote execution. | Freeze manifest `manifests/confirmatory-history-v1-freeze.yaml` |
| 2026-08-13 | C008 | `scripts/run_sparse_neural.py`, `scripts/run_reconstruction.py` | Added long-run error lifetime and repair metrics; fixed future reconstruction ledgers to charge elapsed wall time for timeouts and HTTP failures. | Endurance analysis needs error survival, and failed replay calls still consume compute. | Local compile only; endurance D uses frozen pre-accounting-fix remote reconstruction code and is unaffected. | Sparse `38b9644b...`; future reconstruction `061d355e...` |

### Code entry template

```text
Date:
Change ID:
Files/components:
Previous behavior:
New behavior:
Reason:
Expected result:
Observed result:
Verification command or artifact:
Known limitations:
Commit:
```

## Profiling log

Never report profiler output without the machine, model, build, run config, and
warmup state that produced it.

### Profiling entry template

```text
Profile ID:
Date:
Machine manifest:
Model manifest:
Commit:
Condition:
Config:
Seed or tape IDs:
Warmup procedure:
Profiler/tool:
Command:
Raw artifact path:

wall time:
CPU time:
peak host RAM:
peak VRAM:
GPU utilization:
input tokens:
output tokens:
model queue time:
state bytes touched:
nodes allocated:
nodes reused:

Interpretation:
Confounds:
Follow-up:
```

### DEV-PROFILE-0001 — Complete seed-7 pilot

```text
Date:               2026-08-13
Machine manifest:   kaiju-cpu-provisional-2026-08-13
Model manifest:     qwen35-9b-heretic-q4ks-provisional-2026-08-13
Condition:          A-E development comparison
Config:             configs/dev-pilot-seed7.json
Seed/tape:          seed 7, 1,000 ticks, 47 events, 40 evaluations
Warmup:             resident llama-server already exercised by qualification
Raw table:          runs/dev-pilot-seed7-table.md

A model wall:       136.753 s
B model wall:       160.550 s
C model wall:       177.764 s
D model wall:        63.409 s
E transition p50:     0.065 ms

A prompt/output:    23,737 / 902 tokens
B prompt/output:    17,077 / 901 tokens
C prompt/output:    15,703 / 1,093 tokens
D prompt/output:     7,529 / 100 tokens
E prompt/output:         0 / 0 tokens
```

Host CPU time, peak RSS, temperature, and energy were not captured in this
development pass and must not be inferred from model wall time. Canonical run
order was not randomized, so between-condition wall-time differences remain
subject to thermal and order effects. The large C-D completion-token and state
allocation differences are direct consequences of the treatment, but their
paper-scale uncertainty still requires repeated, counterbalanced runs.

## Run ledger

Every row must resolve to an immutable run manifest and raw result artifact.

| Run ID | Date | Machine | Model | Commit | Condition | History | Event rate | Tapes | Status | Raw result |
|---|---|---|---|---|---|---:|---:|---:|---|---|
| DEV-E-0001 | 2026-08-13 | Kaiju CPU | Oracle reducer | Uncommitted C001 | E Oracle sparse | 1,000 | 0.05 | 1 | Passed; development only | `/home/akujin/Sources/csbc-sparse-belief-ledger/runs/dev-oracle-seed7-v1/summary.json` (`5ef9985d...`) |
| QUAL-Q1 | 2026-08-13 | Kaiju CPU | Qwen3.5 9B | Uncommitted C002 | Coupled structured transition, reasoning auto | 7 cases | N/A | 1 | Failed: 0/7 schema-valid; 1,344 completion tokens | `runs/qwen-qualification-v1/summary.json` (`7eac8b7b...`) |
| QUAL-Q2 | 2026-08-13 | Kaiju CPU | Qwen3.5 9B | Uncommitted C002 | Coupled structured transition, reasoning off | 7 cases | N/A | 1 | Partial: 7/7 schema-valid, 6/7 exact | `runs/qwen-qualification-v2-no-reasoning/summary.json` (`ac5c506f...`) |
| QUAL-Q3 | 2026-08-13 | Kaiju CPU | Qwen3.5 9B | Uncommitted C002 | Semantic-only structured transition, reasoning off | 7 cases | N/A | 1 | Passed: 7/7 schema-valid and exact | `runs/qwen-qualification-v3-semantic-only/summary.json` (`b70fcce0...`) |
| QUAL-Q4 | 2026-08-13 | Kaiju CPU | Qwen3.5 9B | Uncommitted C003 | Compact semantic transition, initial contract | 7 cases | N/A | 1 | Partial: 5/7 exact; belief semantics ambiguous | `runs/qwen-qualification-v4-compact/summary.json` (`b6103441...`) |
| QUAL-Q5 | 2026-08-13 | Kaiju CPU | Qwen3.5 9B | Uncommitted C003 | Compact semantic transition, explicit belief contract | 7 cases | N/A | 1 | Accepted: 6/7 exact; 14 completion tokens | `runs/qwen-qualification-v5-compact-belief-contract/summary.json` (`00287546...`) |
| DEV-E-0002 | 2026-08-13 | Kaiju CPU | Oracle reducer | Uncommitted C004 | E Oracle sparse, corrected stimulus | 1,000 | 0.05 | 1 | Passed; canonical development tape | `runs/dev-oracle-seed7-v2-stimulus/summary.json` (`4e04d8f0...`) |
| DEV-A-0001 | 2026-08-13 | Kaiju CPU | Qwen3.5 9B | Uncommitted C005 | A Full history, compact output | 1,000 | 0.05 | 1 | Passed; development only | `runs/dev-a-seed7-v1-compact/summary.json` (`8603fcd4...`) |
| DEV-B10-0001 | 2026-08-13 | Kaiju CPU | Qwen3.5 9B | Uncommitted C005 | B Fixed window, K=10, compact output | 1,000 | 0.05 | 1 | Passed; development only | `runs/dev-b10-seed7-v1-compact/summary.json` (`b95dcd0b...`) |
| DEV-C-0002 | 2026-08-13 | Kaiju CPU | Qwen3.5 9B | Uncommitted C005 | C Persistent all-dirty, compact output | 1,000 | 0.05 | 1 | Passed; common metric | `runs/dev-c-seed7-v2-compact-common-metrics/summary.json` (`9f0ede1e...`) |
| DEV-D-0005 | 2026-08-13 | Kaiju CPU | Qwen3.5 9B | Uncommitted C005 | D CSBC sparse, compact output | 1,000 | 0.05 | 1 | Passed; common metric | `runs/dev-d-seed7-v5-compact-common-metrics/summary.json` (`6703919a...`) |
| CONF-HIST-V1 | 2026-08-13 | Kaiju CPU | Qwen3.5 9B + oracle | Frozen history-v1 | A/B/D/E history sweep | 5,000 | 0.05 | 3 | Complete; confirmatory under frozen protocol | `runs/confirmatory-history-v1/results.md` (`09dfb08e...`) |
| ENDURANCE-100K-V1 | 2026-08-13 | Kaiju CPU | Qwen3.5 9B + oracle | Frozen endurance-v1 | A/B/D/E endurance | 100,000 | 0.05 | 1 | Running | `runs/endurance-100k-v1/` |

## Development performance and logic table

The first complete A-E table is in
[`results/tables/development-pilot-seed7.md`](results/tables/development-pilot-seed7.md).
It was generated from the immutable Kaiju summaries by
[`scripts/tabulate_results.py`](scripts/tabulate_results.py). It is deliberately
kept separate from the future preregistered final table.

The frozen three-seed history result is in
[`results/tables/confirmatory-history-v1.md`](results/tables/confirmatory-history-v1.md).

## Final performance and logic table

Not populated. Generate this from paper-scale raw results after seeds, workload
sweeps, exclusions, and aggregation rules are preregistered.

| Condition | History ticks | Event rate | Update calls | Query calls | Input tokens | Amortized compute/event | Decision p50 | Decision p95 | Decision p99 | Cumulative inference time | World accuracy | Belief accuracy | Action accuracy | Spurious mutation | False-clean | Nodes reused |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| A Full replay | | | | | | | | | | | | | | N/A | N/A | N/A |
| B Fixed window | | | | | | | | | | | | | | N/A | N/A | N/A |
| C Persistent all-dirty | | | | | | | | | | | | | | | N/A | |
| D CSBC sparse | | | | | | | | | | | | | | | | |
| E Oracle sparse | | | 0 | | 0 | | | | | | | | | 0 | | |

## Event-class accuracy table

| Condition | Direct | Observational | Communicated | Temporal | Correction | False belief | Second hand |
|---|---:|---:|---:|---:|---:|---:|---:|
| A Full replay | | | | | | | |
| B Fixed window | | | | | | | |
| C Persistent all-dirty | | | | | | | |
| D CSBC sparse | | | | | | | |
| E Oracle sparse | | | | | | | |

## Fault-injection table

| Fault | Condition | Injection tick | Detection time | Repair time | Propagation probability | Blast radius | Recovered |
|---|---|---:|---:|---:|---:|---:|---|
| Missing dependency edge | D CSBC sparse | | | | | | |

## Final figures

| Figure | Question answered | Source data | Script | Status |
|---|---|---|---|---|
| F1 | Does cost decouple from accumulated history? | | | Planned |
| F2 | Does accuracy survive long history? | | | Planned |
| F3 | What is the cost/accuracy frontier? | | | Planned |
| F4 | Does sparsity reduce spurious mutation? | | | Planned |
| F5 | How do router errors scale with fan-out? | | | Planned |

## Chronological entries

### 2026-08-13 — Workspace initialization

- Created the dedicated experiment workspace.
- Kept implementation language and model backend deliberately undecided.
- Established that the current workstation will not run the experiment.
- Identified Kaiju as the candidate execution host, pending inventory.
- Determined that simulator/oracle work is non-neural but conditions A–D require
  an LLM.
- Required local inference on Kaiju for primary performance results; remote APIs
  may only provide separately labeled quality replication.
- No code, model calls, profiles, benchmark runs, or result rows exist yet.

### 2026-08-13 — Kaiju selected as CPU execution host

- Recorded 2x Xeon E5-2687W v4, 24 physical cores, 48 logical CPUs,
  approximately 256 GiB RAM, and two approximately 128 GiB NUMA nodes.
- Recorded AVX2, FMA, and F16C availability and the absence of AVX-512, VNNI,
  and BF16.
- Accepted Kaiju for the primary offline comparison. A GPU is not required for
  the central scaling or logical-continuity claims.
- Recorded Qwen3.5 9B Heretic Q4_K_S, 4.96 GiB, as the leading model candidate.
- Recorded supplied measurements of `pp256 141.90 +/- 2.20`,
  `tg32 17.00 +/- 0.20`, and a 50k-context result of 144.11 prompt tokens/s and
  17.15 generated tokens/s over 96 generated tokens.
- Classified these numbers as pre-existing feasibility evidence, not as a row
  in the experiment's final data table.
- Added a NUMA/affinity qualification step and explicitly ruled out a 140 ms
  real-time claim from this experiment.

### 2026-08-13 — Existing Kaiju benchmark corpus adopted

- Read `~/llm-heretic-notes.md`, `~/llm-performance-switch-inventory.md`, and
  `~/llm-benchmark-baseline.md` rather than repeating the completed tuning work.
- Selected ik_llama.cpp commit `3f40e73c`, build `4551`, as the provisional
  inference runtime.
- Adopted its measured Qwen control shape: interleaved NUMA memory, 24 threads,
  batch 512, microbatch 128, q8_0 K cache, q4_0 V cache, flash attention,
  runtime repack, mlock, and merged QKV.
- The recorded deterministic CLI policy is temperature 0 and top-k 1.

### 2026-08-13 — Deterministic condition E development run

- Created the execution workspace at
  `/home/akujin/Sources/csbc-sparse-belief-ledger` on Kaiju.
- Implemented the event generator, immutable state nodes and snapshots, oracle
  reducer, independently declared router, snapshot hashes, structural-sharing
  counters, query answers, and condition-E ledger.
- Ran `DEV-E-0001`: 1,000 ticks, seed 7, event probability 0.05, and evaluation
  every 25 ticks.
- Observed 47 event ticks and 953 empty ticks, zero invariant violations, exact
  final tape hash, 1.0 answer-field accuracy, zero false-clean routing, zero
  unnecessary-dirty routing, 111 nodes allocated, and 735 nodes reused.
- Observed deterministic transition latency p50 0.065607 ms, p95 0.082845 ms,
  p99 0.165256 ms, and maximum 0.184867 ms. These are development measurements,
  not paper results and not a neural or real-time claim.
- Raw summary SHA-256: `5ef9985da7303021c35059e6eef77b2bd55ccadfd58a1afa11f030b42e96ea3d`.

### 2026-08-13 — Qwen structured-transition qualification

- `QUAL-Q1` left reasoning enabled. All seven responses consumed the full
  192-token allowance in reasoning, returned empty content, and failed schema
  parsing. Total wall time was 96.733 seconds.
- `QUAL-Q2` disabled reasoning in the server. All seven responses were valid
  JSON and six were semantically exact. On the direct offline event, the model
  incorrectly changed `tool.restart.alpha` to `running` and therefore selected
  `WAIT`; total wall time was 31.084 seconds.
- The failure exposed an authority error in the contract rather than a grammar
  failure. Tool execution and action selection were removed from the neural
  updater's authorized targets and assigned to deterministic runtime code.
- `QUAL-Q3` tested the semantic-only contract: seven of seven responses were
  schema-valid and exact, using 1,193 prompt tokens, 168 completion tokens, and
  20.661 seconds total wall time.
- The semantic-only Qwen configuration is accepted for development condition D.

### 2026-08-13 — Compact state-transition protocol

- Replaced the primary JSON response with a schema-derived ordered value list.
  If the authorized targets are `alpha.status`, `alice.alpha-belief`, and
  `alice.presence`, a valid response is `offline|false|present` in exactly that
  order.
- The prompt carries the ordered target list once. The response repeats neither
  keys nor object syntax. A request-specific GBNF grammar enforces the exact
  number of fields and each field's enum domain.
- `QUAL-Q4` produced only 14 completion tokens across seven cases but exposed an
  ambiguous belief contract. The prompt was corrected to define a belief value
  as the named person's belief rather than objective server truth.
- `QUAL-Q5` reached 6/7 exact with 1,141 prompt tokens, 14 completion tokens,
  and 9.770 seconds. The remaining miss was a semantic error on a direct status
  change, not a protocol parse error.
- JSON remains implemented as an ablation. The compact protocol is primary
  because it fixes write authority and output cardinality while sharply
  reducing decode work.

### 2026-08-13 — Stimulus correction after the first D pilot

- The first sparse neural tape encoded some facts only in hidden simulator
  payloads. For example, an observational sentence could omit the observed
  online/offline value even though the oracle transition included it.
- That pilot is invalid for condition comparison and remains preserved only as
  diagnostic evidence.
- Event text was repaired to explicitly state status values, named observers,
  communicated propositions, correction targets, and the content of false
  reports.
- Regenerating the tape preserved the exact 47-event oracle trajectory and
  final snapshot hash `82a1f5aa...`; only model-visible language changed.

### 2026-08-13 — Complete A-E development pilot

- Ran all conditions on the corrected 1,000-tick seed-7 tape with 47 event
  ticks, 953 quiescent ticks, and 40 evaluation points.
- Added a common 11-field semantic score covering three world fields, six
  belief fields, and two presence fields. Earlier `answer_field_accuracy`
  included derived stale/action fields and is retained but not used for the
  A-D comparison.
- Sparse D reached 89.3% common semantic accuracy with 7,529 prompt tokens,
  100 completion tokens, and 63.409 seconds of model time. It had zero failed
  transitions, zero invariant violations, zero false-clean routing, and zero
  spurious mutations.
- All-dirty C reached 53.9% with 15,703 prompt tokens, 1,093 completion tokens,
  and 177.764 seconds. Its spurious-mutation rate was 5.78%, and 80.8% of dirty
  work was unnecessary relative to the oracle dependency set.
- Full replay A reached 60.7% with 23,737 prompt tokens and 136.753 seconds.
  Fixed-window B at K=10 reached 57.3% with 17,077 prompt tokens and
  160.550 seconds.
- Oracle sparse E remained exact with zero semantic model work and confirmed
  all 953 empty ticks preserved state exactly.
- This is exploratory development evidence gathered after model/protocol and
  stimulus iteration. It is not a preregistered or statistically replicated
  paper result.

### 2026-08-13 — Confirmatory history sweep v1 frozen

- Locked seeds 11, 29, and 47 before generating or inspecting their tapes.
- Locked a single 5,000-tick tape per seed with checkpoints at ticks 100, 500,
  1,000, and 5,000, event probability 0.05, fixed active-state size, and
  fixed-window K=10.
- Locked conditions A, B, D, and E. Condition C is excluded because this sweep
  tests history scaling; the separate development ablation already isolates
  whole-state versus sparse mutation.
- Locked model seed 42, compact protocol, 11-field semantic metric, full
  inference accounting, three condition orders, and infrastructure-only rerun
  exclusions.
- Config SHA-256 is `58bc843f...`; all runner and aggregator hashes are recorded
  in `manifests/confirmatory-history-v1-freeze.yaml`.
- No confirmatory model call or tape generation occurred before this entry and
  freeze manifest were written.

### 2026-08-13 — Confirmatory history sweep v1 completed

- All three preregistered seeds and all A/B/D/E conditions completed. No run
  was excluded or rerun.
- Full replay's mean current prompt grew from 388 tokens at tick 100 to 3,533
  at tick 5,000, a 9.11× increase.
- Sparse D used 157.2–163.7 prompt tokens per incoming event across history
  intervals, supporting an information-flux rather than accumulated-history
  maintenance law on this workload.
- At tick 5,000, mean semantic accuracy was 84.8% for D and 66.7% for both A
  and B. Oracle E remained exact.
- D had zero neural decision-boundary work, but accumulated 302.27 seconds of
  maintenance inference versus A's 39.92 seconds for only four queries. This
  supports bounded response work and a different scaling regime, not a
  universal lifetime-compute win under sparse decisions.
- Across all D seeds: zero malformed transitions, false-clean errors, spurious
  mutations, and quiescent invariant violations.
- Result Markdown SHA-256: `09dfb08e...`; checkpoint JSONL SHA-256:
  `e188a067...`.

### 2026-08-13 — 100,000-tick endurance v1 frozen and started

- Locked one seed-83 trajectory with checkpoints at 1,000, 5,000, 10,000,
  25,000, 50,000, and 100,000 ticks. At the nominal paper clock this spans
  3.89 hours of environment time.
- Added error-episode age, error-state occupancy, repair attempts, successful
  repairs, unresolved error count, and maximum concurrent semantic errors.
- Full-history context exhaustion at the 50,000-token server limit is an
  outcome, not an exclusion.
- Config SHA-256: `82fac96f...`; freeze manifest:
  `manifests/endurance-100k-v1-freeze.yaml`.
- Oracle generation immediately exposed a hot-state scaling issue: each node
  stores its complete evidence-ID chain, so snapshot hashing revisits growing
  provenance. Preserve the measurement, then move full provenance to durable
  storage and retain bounded roots/heads in hot state in a separately versioned
  runtime.
- Fixed-window B completed all six checkpoints at 54.5% aggregate semantic
  accuracy and approximately 4.8 seconds median decision latency.
- Full replay A completed through tick 25,000. That decision used 19,693 prompt
  tokens and 107.681 seconds. Tick 50,000 hit the adapter's 180-second client
  timeout; tick 100,000 returned HTTP 500 after exceeding the server context.
- The frozen reconstruction runner recorded zero wall time for failed calls, so
  its 177.653-second summary is an undercount. Treat total A inference as at
  least 357.653 seconds plus the final HTTP-failure latency. The local follow-up
  runner now accounts failed-call elapsed time, but the frozen raw run is not
  rewritten or relabeled.
- Sparse D started as transient system service
  `csbc-endurance-d-v1.service`; raw output will appear under
  `runs/endurance-100k-v1/D` when all event transitions complete.
- The first D service attempt was invalidated before useful measurement. The
  timed-out A request continued decoding after the client disconnected, and
  D's first request queued behind that abandoned prompt. The empty attempt
  directory was preserved as `D-invalidated-stale-replay-queue`.
- Stopping the contaminated service canceled both its queued request and the
  abandoned replay task. After llama-server reported a released slot and true
  idle state, D restarted cleanly as `csbc-endurance-d-v1-clean.service`.
  Server logs confirmed the first measured prompt was a normal 164-token sparse
  transition rather than the stale 18,304-token replay prefix. This rerun is
  permitted by the frozen infrastructure-only exclusion rule.

### 2026-08-13 — 131,072-context full-replay replication frozen

- The model declares a context capacity larger than the 50,000-token value used
  by the original llama-server process. The original failures therefore measure
  that serving configuration and the adapter's 180-second timeout, not a proven
  model limit.
- Locked a posthoc A-only replication at 131,072 configured context and a
  1,200-second client timeout. It reuses the exact seed-83 endurance tape and
  evaluation ticks; no stimulus or answer changes are allowed.
- The replication will start only after the clean sparse D run finishes, so a
  server restart cannot alter D's runtime environment. Results go to
  `runs/endurance-100k-a-ctx131k-v1/A`; the original A output remains intact.
- This run is labeled an infrastructure replication, not part of the frozen
  primary endurance comparison. Its purpose is to recover full-history latency,
  token use, and accuracy at ticks 50,000 and 100,000 when the request fits.
- Added `--request-timeout-seconds` to the reconstruction runner without
  changing its default. The 131k replication locks it to 1,200 seconds.
- Synced and syntax-checked the versioned runner on Kaiju. The transient service
  `csbc-endurance-a-ctx131k-v1.service` is queued in a guarded wait loop. It will
  refuse to alter the model server unless clean D has produced `summary.json`,
  then run A at 131,072 context and restore the original 50,000-context server
  flags afterward.
- At queue time, clean D and the original PID-14839 50k llama-server remained
  active; the queued replication contained only its shell and a 30-second wait.

### 2026-08-13 — Endurance v1 cancelled; corrected v2 frozen

- At 11:34:37 PDT, the user requested cancellation rather than spending more
  compute on an endurance package whose first baseline had avoidable serving
  limits. Stopped both `csbc-endurance-d-v1-clean.service` and the waiting
  `csbc-endurance-a-ctx131k-v1.service`. The latter never changed the server or
  submitted a model request.
- Clean D had run for approximately 29 minutes but had not completed. Its runner
  writes the final ledger and summary only after the full tape, so no partial
  result exists. Preserved its empty output directory on Kaiju as
  `D-cancelled-user-request-20260813T113437`.
- Classify endurance-100k-v1 as an aborted infrastructure pilot. Retain A's
  completed 1k–25k observations and its 50k timeout/100k configured-context
  failures only as diagnostic data. They are not the confirmatory endurance
  comparison.
- Froze corrected `endurance-100k-v2` on the exact same seed-83 tape. All neural
  conditions use a common 131,072-token server configuration, with a fresh
  server process between conditions.
- Reordered conditions to A-gate, B, D. A must complete all six checkpoints,
  including tick 100,000, before any long sparse D run begins. Thus another
  full-replay infrastructure failure costs only six queries rather than hours of
  sparse maintenance.
- Added progress output every 5,000 environment ticks to D. This does not alter
  state or metrics, but makes the roughly 5,038-call long leg observable through
  the service journal.

### 2026-08-13 — Endurance v2 completed

- `csbc-endurance-v2.service` completed normally at 14:06:27 PDT with exit
  status zero. A and B completed six queries each; D completed 4,835 neural
  transitions over 5,038 events. There were no failed queries, failed
  transitions, invariant violations, false-clean updates, or spurious
  mutations.
- Aggregate semantic accuracy was 48.5% for A, 54.5% for B, and 75.8% for D.
  At tick 100,000 it was 36.4%, 63.6%, and 81.8%, respectively.
- A's current prompt grew from 1,006 tokens at tick 1,000 to 77,493 at tick
  100,000. Its final decision took 621.73 seconds despite llama.cpp prefix-cache
  reuse. B remained at 446–460 prompt tokens and 4.54–4.82 seconds.
- D's transition cost remained 154.2–157.7 prompt tokens per incoming event
  across all six history intervals. It used zero neural work at each decision
  boundary, but accumulated 792,525 prompt tokens and 6,709.86 seconds of
  maintenance inference. This is not a universal total-compute win under only
  six queries.
- D repaired 944 of 946 observed semantic error episodes. Two errors remained
  at the end; error-state occupancy was 20.153%, mean observed error age 234.3
  ticks, and maximum age 1,688 ticks. Final world and belief state were exact,
  while both presence fields were incorrect.
- Full results and interpretation are in
  `results/tables/endurance-100k-v2.md`. Raw ledgers and summaries were copied
  from Kaiju to the ignored local path `results/raw/endurance-100k-v2` and
  verified against their SHA-256 hashes.

### Next entry

Freeze the development implementation, preregister seeds and workload sweeps,
then run repeated history-length experiments. The next primary question is
whether D's update cost remains flat while A's reconstruction cost grows as
the same information flux is spread across 100, 1,000, 10,000, and longer
histories. Add the missing-dependency-edge fault injection only after that
central scaling sweep is reproducible.

### 2026-08-16 — Paper data integration and vector figures

- Replaced every empty empirical figure placeholder in `paper-raw.md` with a
  publication SVG generated from frozen artifacts. Added the previously
  missing runtime architecture diagram and six data figures covering the
  preregistered history sweep, endurance accuracy, checkpoint scaling,
  information-flux cost, repair/latency, and the exploratory 491-tick
  continuous-situation run.
- Added `scripts/render_paper_figures.py`. It uses only Python's standard
  library, reads immutable JSON or JSONL artifacts, and writes both SVG figures
  and the exact plotted CSVs. It performs no model inference, simulation,
  profiling, or experiment mutation. Generated publication artifacts and the
  renderer are frozen in `results/SHA256SUMS.paper`.
- Copied the confirmatory `checkpoints.jsonl` into the ignored local raw-data
  area. SHA-256 is
  `e188a067319ae199a3a0e5418aae6497af8d62b1076cc03fca3f1ec63c7d38e2`,
  matching the frozen confirmatory table. Existing endurance summary hashes
  also match their recorded provenance.
- Integrated the preregistered three-seed result into the manuscript rather
  than presenting the single endurance tape as the complete evidence base.
  The paper now separates development, confirmatory, endurance, and
  exploratory evidence tiers and preserves the missing-C ablation caveat.
- Added the completed visual-stream `control-core-v5` run as an exploratory
  behavioral/mechanism study. The manuscript reports both its causal-state
  successes and its failures: zero hard protocol flags and complete scheduled
  probe coverage, but zero deadlines met and only one eight-word utterance.
  Probe coverage is explicitly not relabeled as semantic accuracy.
- The stopped 27B partial run and its disposable qualification are excluded
  from the manuscript and all generated data tables.
