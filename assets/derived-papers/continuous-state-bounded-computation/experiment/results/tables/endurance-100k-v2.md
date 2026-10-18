# Endurance 100k v2

This is the completed seed-83 endurance trajectory frozen in
`configs/endurance-100k-v2.json`. It contains 100,000 environment ticks, 5,038
events, and six evaluation boundaries. A, B, and D used the same Qwen3.5 9B
Heretic Q4_K_S model, compact protocol, and 131,072-token llama.cpp server
context. The service completed normally with exit status zero.

## Main result

Accuracy in the aggregate columns is pooled over all six evaluation boundaries.
Final accuracy is the complete 11-field semantic state at tick 100,000. D does
no neural inference at the decision boundary; its cost shown here is all
maintenance inference over the trajectory.

| Condition | Aggregate semantic accuracy | Final semantic accuracy | Final decision prompt | Final decision latency | Total prompt tokens | Total inference wall |
|---|---:|---:|---:|---:|---:|---:|
| A Full history | 48.5% | 36.4% | 77,493 | 621.73 s | 149,243 | 1,025.53 s |
| B Fixed window, K=10 | 54.5% | 63.6% | 447 | 4.82 s | 2,703 | 28.11 s |
| D Persistent sparse | 75.8% | 81.8% | 0 | 0 neural s | 792,525 | 6,709.86 s |
| E Oracle sparse reference | 100.0% | 100.0% | 0 | 0 | 0 | 0 |

D exceeded A by 27.3 percentage points and B by 21.2 points in aggregate
semantic accuracy. At the final boundary, D exceeded A by 45.5 points and B by
18.2 points.

This is a decision-latency and continuity win, not a lifetime-compute win under
this six-query workload. D used 5.31 times A's prompt tokens and 6.54 times A's
inference wall time because it maintained state after every relevant event.

## Checkpoint results

| Tick | A prompt | A latency | A accuracy | B prompt | B latency | B accuracy | D cumulative maintenance prompt | D accuracy |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 1,000 | 1,006 | 8.81 s | 63.6% | 449 | 4.59 s | 27.3% | 7,554 | 72.7% |
| 5,000 | 3,932 | 26.80 s | 54.5% | 460 | 4.79 s | 63.6% | 39,589 | 72.7% |
| 10,000 | 7,806 | 34.91 s | 63.6% | 449 | 4.65 s | 54.5% | 81,705 | 63.6% |
| 25,000 | 19,693 | 108.43 s | 45.5% | 452 | 4.54 s | 63.6% | 206,955 | 100.0% |
| 50,000 | 39,313 | 224.85 s | 27.3% | 446 | 4.71 s | 54.5% | 406,195 | 63.6% |
| 100,000 | 77,493 | 621.73 s | 36.4% | 447 | 4.82 s | 63.6% | 792,525 | 81.8% |

llama.cpp reused common prompt prefixes between A checkpoints. Thus A's wall
times already benefit from server-side KV/prompt caching even though usage
accounting reports the entire submitted prompt. The final request contained
77,493 prompt tokens while the server evaluated 38,581 tokens not already
covered by the retained prefix.

## Information-flux scaling

| Interval ending at tick | New events | D interval prompt tokens | Prompt tokens per new event |
|---:|---:|---:|---:|
| 1,000 | 49 | 7,554 | 154.2 |
| 5,000 | 204 | 32,035 | 157.0 |
| 10,000 | 267 | 42,116 | 157.7 |
| 25,000 | 796 | 125,250 | 157.3 |
| 50,000 | 1,264 | 199,240 | 157.6 |
| 100,000 | 2,458 | 386,330 | 157.2 |

D remained within 154.2–157.7 prompt tokens per new event across a 100-fold
increase in elapsed tick history. A's current prompt grew 77.0 times from tick
1,000 to tick 100,000, while B remained bounded at 446–460 tokens.

## D reliability and drift

| Metric | Result |
|---|---:|
| Model calls | 4,835 |
| Failed transitions | 0 |
| Invariant violations | 0 |
| False-clean rate | 0% |
| Unnecessary-dirty rate | 0% |
| Spurious-mutation rate | 0% |
| Semantic error episodes | 946 |
| Repaired episodes | 944 |
| Unresolved at end | 2 |
| Repair-attempt success | 93.1% |
| Error-state occupancy | 20.153% |
| Mean observed error age | 234.3 ticks |
| Maximum observed error age | 1,688 ticks |
| Maximum concurrent errors | 7 fields |
| Update latency p50 / p95 / p99 | 1.370 / 1.595 / 1.862 s |

D ended with all world and belief fields correct but both presence fields
incorrect: final world accuracy 100%, belief accuracy 100%, and presence
accuracy 0%. Across all checkpoints, D's world, belief, and presence accuracies
were 88.9%, 86.1%, and 25.0%, respectively. The result therefore supports
persistent world/belief continuity while exposing presence tracking as a clear
weakness.

The observed 1.370-second median semantic update does not support a 140 ms
hard-real-time claim on Kaiju. The experiment supports bounded decision work,
history-independent sparse transition size, and measured degradation/repair.

## Provenance

- Frozen config SHA-256: `ee6ead7807d64bc19f94244424db045ef5c4bec96a7518852444a4428264cfe9`
- Source tape SHA-256: `4ed765bee0f27334298c28966d0d41d9c2957d14ee214d6f096d11c6f62e9fe0`
- A ledger / summary: `e53ff090...` / `f8e36589...`
- B ledger / summary: `a6492692...` / `288c31c9...`
- D ledger / summary: `6c94fd12...` / `0b628516...`
- Kaiju raw root: `/home/akujin/Sources/csbc-sparse-belief-ledger/runs/endurance-100k-v2`
- Local raw copy: `experiment/results/raw/endurance-100k-v2`
