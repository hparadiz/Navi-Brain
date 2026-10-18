# Confirmatory History Sweep v1

This result was produced from the protocol frozen at
`2026-08-13T10:23:02-07:00`, before any confirmatory tape was generated or
model call was made. It uses seeds 11, 29, and 47; checkpoints at 100, 500,
1,000, and 5,000 ticks; event probability 0.05; fixed-window K=10; and the
same compact Qwen3.5 9B semantic operator for A, B, and D.

Values are arithmetic mean ± sample standard deviation across the three
preregistered seeds.

## Semantic accuracy

| Tick | Condition | Semantic | World | Belief | Presence |
|---:|---|---:|---:|---:|---:|
| 100 | A Full replay | 63.6 ± 9.1% | 66.7 ± 33.3% | 61.1 ± 9.6% | 66.7 ± 28.9% |
| 100 | B Fixed window | 63.6 ± 9.1% | 66.7 ± 33.3% | 61.1 ± 9.6% | 66.7 ± 28.9% |
| 100 | D CSBC sparse | 87.9 ± 10.5% | 77.8 ± 19.2% | 88.9 ± 9.6% | 100.0 ± 0.0% |
| 100 | E Oracle sparse | 100.0 ± 0.0% | 100.0 ± 0.0% | 100.0 ± 0.0% | 100.0 ± 0.0% |
| 500 | A Full replay | 54.5 ± 15.7% | 55.6 ± 38.5% | 50.0 ± 16.7% | 66.7 ± 28.9% |
| 500 | B Fixed window | 51.5 ± 10.5% | 66.7 ± 0.0% | 38.9 ± 25.5% | 66.7 ± 28.9% |
| 500 | D CSBC sparse | 81.8 ± 9.1% | 77.8 ± 19.2% | 77.8 ± 19.2% | 100.0 ± 0.0% |
| 500 | E Oracle sparse | 100.0 ± 0.0% | 100.0 ± 0.0% | 100.0 ± 0.0% | 100.0 ± 0.0% |
| 1,000 | A Full replay | 39.4 ± 13.9% | 66.7 ± 0.0% | 16.7 ± 28.9% | 66.7 ± 28.9% |
| 1,000 | B Fixed window | 48.5 ± 10.5% | 66.7 ± 0.0% | 33.3 ± 28.9% | 66.7 ± 28.9% |
| 1,000 | D CSBC sparse | 81.8 ± 15.7% | 100.0 ± 0.0% | 77.8 ± 25.5% | 66.7 ± 28.9% |
| 1,000 | E Oracle sparse | 100.0 ± 0.0% | 100.0 ± 0.0% | 100.0 ± 0.0% | 100.0 ± 0.0% |
| 5,000 | A Full replay | 66.7 ± 13.9% | 55.6 ± 19.2% | 61.1 ± 25.5% | 100.0 ± 0.0% |
| 5,000 | B Fixed window | 66.7 ± 5.2% | 66.7 ± 0.0% | 61.1 ± 9.6% | 83.3 ± 28.9% |
| 5,000 | D CSBC sparse | 84.8 ± 10.5% | 100.0 ± 0.0% | 72.2 ± 19.2% | 100.0 ± 0.0% |
| 5,000 | E Oracle sparse | 100.0 ± 0.0% | 100.0 ± 0.0% | 100.0 ± 0.0% | 100.0 ± 0.0% |

## Inference accounting

D performs no neural call at the evaluation boundary. Its cumulative and
interval columns include every prior semantic update, so maintenance is not
treated as free.

| Tick | Condition | Current prompt tok. | Cumulative prompt tok. | Cumulative completion tok. | Cumulative wall s | Interval events | Interval calls | Interval prompt/event |
|---:|---|---:|---:|---:|---:|---:|---:|---:|
| 100 | A | 388.0 ± 7.9 | 388.0 ± 7.9 | 23.0 ± 1.0 | 4.41 ± 0.09 | 4.7 ± 0.6 | 1.0 ± 0.0 | 83.9 ± 9.4 |
| 100 | B | 388.0 ± 7.9 | 388.0 ± 7.9 | 23.0 ± 1.0 | 4.53 ± 0.21 | 4.7 ± 0.6 | 1.0 ± 0.0 | 83.9 ± 9.4 |
| 100 | D | 0.0 ± 0.0 | 764.0 ± 94.4 | 9.3 ± 1.2 | 6.57 ± 0.80 | 4.7 ± 0.6 | 4.7 ± 0.6 | 163.7 ± 0.1 |
| 500 | A | 715.0 ± 58.9 | 1,103.0 ± 66.8 | 45.3 ± 1.2 | 11.16 ± 0.62 | 23.3 ± 3.1 | 1.0 ± 0.0 | 30.8 ± 1.6 |
| 500 | B | 460.3 ± 5.8 | 848.3 ± 12.5 | 45.3 ± 1.2 | 9.36 ± 0.32 | 23.3 ± 3.1 | 1.0 ± 0.0 | 20.0 ± 2.6 |
| 500 | D | 0.0 ± 0.0 | 4,448.0 ± 610.3 | 58.7 ± 9.2 | 37.76 ± 5.19 | 23.3 ± 3.1 | 22.3 ± 3.1 | 157.7 ± 2.0 |
| 1,000 | A | 993.7 ± 55.2 | 2,096.7 ± 120.0 | 67.3 ± 1.2 | 16.27 ± 1.15 | 20.3 ± 1.5 | 1.0 ± 0.0 | 49.0 ± 4.2 |
| 1,000 | B | 454.7 ± 6.4 | 1,303.0 ± 8.7 | 68.0 ± 1.0 | 14.13 ± 0.30 | 20.3 ± 1.5 | 1.0 ± 0.0 | 22.4 ± 1.8 |
| 1,000 | D | 0.0 ± 0.0 | 7,694.7 ± 550.0 | 104.0 ± 9.2 | 65.33 ± 4.65 | 20.3 ± 1.5 | 19.7 ± 1.5 | 159.7 ± 4.8 |
| 5,000 | A | 3,533.0 ± 205.3 | 5,629.7 ± 142.1 | 89.3 ± 1.2 | 39.92 ± 0.87 | 178.3 ± 18.0 | 1.0 ± 0.0 | 19.9 ± 0.9 |
| 5,000 | B | 455.3 ± 2.1 | 1,758.3 ± 10.2 | 90.3 ± 1.5 | 18.90 ± 0.31 | 178.3 ± 18.0 | 1.0 ± 0.0 | 2.6 ± 0.2 |
| 5,000 | D | 0.0 ± 0.0 | 35,718.7 ± 2,158.2 | 478.7 ± 24.1 | 302.27 ± 18.18 | 178.3 ± 18.0 | 171.0 ± 16.5 | 157.2 ± 0.9 |

Oracle E remained exactly correct, used zero neural tokens, and had zero
false-clean or quiescent invariant violations in all three runs.

## Primary interpretation

- Full replay's current prompt grew from 388 tokens at tick 100 to 3,533 at
  tick 5,000, a 9.11× increase as accumulated history grew.
- Sparse D's interval prompt cost per event remained within 157.2–163.7 tokens
  across the four history regions. Its work followed new information rather
  than accumulated tick history.
- At tick 5,000, D's semantic accuracy was 84.8%, 18.1 percentage points above
  both A and B at 66.7%.
- D had zero neural decision-boundary work, but cumulative maintenance cost was
  302.27 seconds because only four decisions were requested. A consumed 39.92
  seconds for those four reconstructions. The experiment therefore supports a
  response-latency and scaling-regime claim, not a universal lifetime-compute
  advantage under sparse query workloads.
- Across all D runs: malformed transitions 0, false-clean rate 0, spurious
  mutation rate 0, and quiescent invariant violations 0.

### Query-rate crossover

The cumulative-compute comparison depends on how often the environment needs a
decision. At tick 5,000, D had spent 35,719 prompt tokens maintaining the
present. One final full replay used 3,533 prompt tokens, while a uniformly timed
replay query over the trajectory would use roughly half the final history plus
fixed prompt overhead. On this trace, the rough prompt-token crossover is about
18–20 replay decisions across 5,000 ticks: approximately one decision every
250–280 ticks, or every 11–13 incoming events.

Below that decision rate, replay can consume less total inference while having
far worse response latency. Above it, repeatedly reconstructing history is
expected to overtake incremental maintenance. This crossover is an exploratory
derived estimate, not a separately preregistered hypothesis; a dedicated query-
rate sweep should measure it directly.

## Limits

This small confirmatory sweep has only three tapes and eleven semantic fields.
It does not establish behavior at large active-state sizes, non-oracle routing,
high fan-out, real-time deadlines, other models, or other hardware. Accuracy at
individual checkpoints is coarse because each seed contributes only eleven
field judgments. The planned 100,000-tick endurance study should focus on
history scaling, error lifetime, repair opportunities, and the context failure
boundary rather than treating this sweep as the final word.

## Provenance

- Frozen config SHA-256: `58bc843fbaa4130d45b762ab85eab829756e3a72ee106c70e7b96a2d2ee9ea48`
- Remote Markdown SHA-256: `09dfb08eaa0be1c464a43e84debfecf22ddd1c6011540e6d5e17fc0ba6cc7a20`
- Remote checkpoint JSONL SHA-256: `e188a067319ae199a3a0e5418aae6497af8d62b1076cc03fca3f1ec63c7d38e2`
- Raw root: `/home/akujin/Sources/csbc-sparse-belief-ledger/runs/confirmatory-history-v1`
