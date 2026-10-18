# Sparse Belief Ledger Experiment Workspace

This directory is the implementation and measurement workspace for the
[Sparse Belief Ledger experiment](../sparse-belief-ledger-experiment.md).
The manuscript-level changes motivated by the review are tracked separately in
[the peer-review response plan](../peer-review-response-plan.md).
The proposed offline repair extension is specified in the
[sleep-like maintenance experiment](../sleep-maintenance-experiment.md).

The parent document defines the scientific design. This workspace contains the
actual code, run configurations, machine and model manifests, raw measurements,
profiling artifacts, figures, and final tabulations.

## Execution boundary

The current workstation is documentation-only for this experiment.

Do not run the simulator, model inference, benchmark sweeps, or profilers on the
current workstation. Kaiju is the selected execution host. Its CPU and memory
inventory is recorded in [EXPERIMENT_LOG.md](EXPERIMENT_LOG.md); the remaining
software, storage, affinity, and model checks must be completed on Kaiju before
measurement begins.

## Does the experiment require an LLM?

The experiment has two distinct layers:

1. **Deterministic layer — no LLM required**

   The event generator, oracle reducer, dependency oracle, state snapshots,
   structural sharing, scoring, and condition E are ordinary deterministic
   software. They should be built and verified first.

2. **Neural comparison — an LLM is required**

   Conditions A through D test full-history reasoning, fixed-window reasoning,
   whole-state neural transitions, and sparse neural transitions. Without a
   semantic model, the experiment can validate runtime invariants but cannot
   test the central neural reasoning and state-drift claims.

The primary performance experiment will use a model running locally on Kaiju's
CPUs. A remote API may be added as a secondary logic-quality replication, but
its network latency, provider queueing, batching, and undisclosed serving
hardware must not be used for the primary latency or throughput claims.

## Selected Kaiju profile

Kaiju is a dual-socket Broadwell system:

```text
CPU:        2x Intel Xeon E5-2687W v4
Cores:      24 physical / 48 logical
RAM:        approximately 256 GiB
NUMA:       2 nodes, approximately 128 GiB each
ISA:        AVX2, FMA, F16C; no AVX-512, VNNI, or BF16
Accelerator:not required for the primary CPU experiment
```

The selected development model is Qwen3.5 9B Heretic, 8.95B parameters, Q4_K_S,
4.96 GiB. A user-supplied 50k-context measurement reported 144.11 prompt
tokens/s and 17.15 generated tokens/s for 96 generated tokens. Short tests
reported `pp256 141.90 +/- 2.20` and `tg32 17.00 +/- 0.20`.

This is sufficient for the experiment's first empirical claim. The conditions
are comparative and can run offline; they do not require GPU latency. Kaiju can
hold the quantized weights, KV cache, state ledger, and raw traces comfortably,
while the deterministic condition provides a non-neural ceiling.

It is not sufficient evidence for a 140 ms control deadline: decode alone is
about 58 ms/token at the reported rate, before prompt evaluation and runtime
overhead. This experiment therefore reports offline comparative latency,
tokens, cumulative compute, state mutation, and logical accuracy. It makes no
hard- or soft-real-time claim.

### CPU and NUMA policy

- Use one fixed inference-engine build and record all build flags.
- Benchmark one-socket-local, two-socket-local, and interleaved-memory policies
  before locking the primary policy. Do not mix them within a result table.
- Pin worker threads and record physical-core versus SMT usage.
- Keep model weights and KV-cache allocation policy fixed across A–D.
- Use narrow structured outputs so generation time does not bury the state
  transition being measured.
- Treat 50k as a measured context point, not an assumed universal limit. Record
  the first context failure or forced truncation as a result.

A GPU can be used later as a separately labeled replication. It is not needed
to establish the first paper's architecture-level comparison.

## Hardware rules for publishable measurements

- Run all primary conditions on the same host, CPU/NUMA policy, model build, and
  inference-engine build.
- Pin the exact model file and record its checksum.
- Record weight and KV-cache quantization separately.
- Record CPU, RAM, NUMA policy, affinity, storage, kernel, runtime, inference
  engine, compiler, and power-management state. Record GPU fields only for a
  later accelerator replication.
- Separate cold-start, warmup, and steady-state measurements.
- Record host memory use, CPU time, wall time, tokens, and model queue time where
  available.
- Keep unrelated CPU, memory-bandwidth, and storage workloads off Kaiju during
  measured runs.
- Randomize or counterbalance condition order to reduce thermal and cache-order
  bias.
- Repeat seeded runs and report distributions, not a single favorable trace.
- Never silently truncate the full-history condition. If it reaches the model's
  context or memory limit, record that failure boundary as a result.

## Workspace layout

```text
experiment/
├── README.md                 workspace contract and hardware policy
├── EXPERIMENT_LOG.md         append-only implementation and results ledger
├── configs/                  preregistered experiment configurations
├── manifests/                immutable machine, model, build, and run manifests
├── src/                      implementation selected after language decision
├── scripts/                  runners, profilers, validation, and plotting tools
├── data/
│   ├── tapes/                immutable generated event tapes
│   └── oracles/              oracle states, dirty sets, actions, and labels
├── profiles/
│   └── raw/                  raw CPU, GPU, memory, and inference profiles
└── results/
    ├── raw/                  per-run machine-readable measurements
    ├── tables/               final aggregate tables
    └── figures/              final plots
```

Generated tapes, raw profiles, and raw results are ignored by the local
`.gitignore` until a data-publication policy is chosen. Manifests, aggregate
tables, figures, configs, source, scripts, and the log are intended to be
tracked.

## Build order

1. ~~Inventory Kaiju and choose the execution profile.~~
2. ~~Choose an implementation language and local inference engine.~~
3. ~~Implement the deterministic event generator and oracle reducer.~~
4. ~~Implement immutable state snapshots and condition E.~~
5. ~~Implement persistent conditions C and D.~~
6. ~~Add a local model adapter and implement conditions A and B.~~
7. ~~Run one complete A-E development pilot and normalize metrics.~~
8. Lock configs, seeds, schemas, metrics, and exclusions.
9. Run repeated history-length and event-rate sweeps.
10. Add fault injection, then produce paper tables and figures from immutable raw results.

## Immediate next action

Freeze the development implementation and preregister the history-length sweep.
The seed-7 development pilot is tabulated in
[results/tables/development-pilot-seed7.md](results/tables/development-pilot-seed7.md).
It is strong feasibility evidence, but repeated seeds and controlled history
lengths are required before any result moves into the paper.
