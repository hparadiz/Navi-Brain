# Development Pilot — Seed 7

This is exploratory development evidence, not the preregistered paper result.
All conditions used the same corrected 1,000-tick tape: 47 natural-language
events, 953 quiescent ticks, and 40 evaluation points. Neural conditions used
the same Qwen3.5 9B Heretic Q4_K_S model, Kaiju inference service, compact
protocol, and 11-field semantic score: 3 world fields, 6 belief fields, and
2 presence fields. The table was rendered from immutable summaries by
`scripts/tabulate_results.py`.

| Condition | Semantic | World | Belief | Presence | Model calls | Prompt tok. | Completion tok. | Inference s | Latency p50 ms | Spurious mutation | False-clean | Unnecessary-dirty | Nodes allocated | Nodes reused |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| A Full replay | 60.7% | 80.0% | 57.5% | 41.2% | 40 | 23,737 | 902 | 136.8 | 3947.634 | N/A | N/A | N/A | N/A | N/A |
| B Fixed window | 57.3% | 77.5% | 50.4% | 47.5% | 40 | 17,077 | 901 | 160.6 | 4664.296 | N/A | N/A | N/A | N/A | N/A |
| C Persistent all-dirty | 53.9% | 68.3% | 53.3% | 33.8% | 47 | 15,703 | 1,093 | 177.8 | 3831.835 | 5.8% | 0.0% | 80.8% | 578 | 268 |
| D CSBC sparse | 89.3% | 85.8% | 92.1% | 86.2% | 46 | 7,529 | 100 | 63.4 | 1358.754 | 0.0% | 0.0% | 0.0% | 111 | 735 |
| E Oracle sparse | 100.0% | 100.0% | 100.0% | 100.0% | 0 | 0 | 0 | 0.0 | 0.065 | N/A | 0.0% | 0.0% | 111 | 735 |

## What this pilot says

Against persistent all-dirty C, sparse D used 52.1% fewer prompt tokens,
90.9% fewer completion tokens, 64.3% less model wall time, allocated 467 fewer
state nodes, and improved common semantic accuracy by 35.4 percentage points.
It also reduced measured spurious mutation from 5.78% to zero.

Against full replay A, sparse D used 68.3% fewer prompt tokens and 53.6% less
model wall time while improving common semantic accuracy by 28.6 percentage
points. Against fixed-window B, it used 55.9% fewer prompt tokens and 60.5%
less model wall time while improving accuracy by 32.0 percentage points.

These are results for one generated tape and one model seed after prompt and
stimulus development. They establish feasibility and identify a strong effect
worth preregistering; they do not yet establish history-length scaling,
uncertainty bounds, generalization across seeds, or a real-time deadline.

## Provenance

- Remote table:
  `/home/akujin/Sources/csbc-sparse-belief-ledger/runs/dev-pilot-seed7-table.md`
- Remote table SHA-256:
  `12fe628b282fba87fc333aee6e426aa9cfc58df32fc77fcbca0a7a7740997abc`
- A summary SHA-256: `8603fcd43d2050cdb1528b283a9cc838c234b5f313c8b416e2b844ebd450bf22`
- B summary SHA-256: `b95dcd0bae64f0f20187da42543603c9c005a102fd21e7b5a50201d3dbbfcde6`
- C summary SHA-256: `9f0ede1e8e45abc177536bae7087428a21c13eebce479946b1a35064b715c5a4`
- D summary SHA-256: `6703919a71f82425a373dc335f1fa01dbdc720e5f639c5eb241ebcf64c0ef90e`
- E summary SHA-256: `4e04d8f03a0831e38691f7fc890d844402babfa2283f8cbc9042300271cf6bf3`
