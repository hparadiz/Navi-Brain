# Visual Stream `control-core-v5`

This is an exploratory mechanism and behavioral run, not a confirmatory
comparison. The frozen run used one Qwen3.5 9B model call at each of 491 causal
situation boundaries and a deterministic 1,000-word personality-shaped
Attention filter. The live runner did not open the sealed oracle.

## Runtime and Attention

| Metric | Result |
|---|---:|
| Processed ticks / model calls | 491 / 491 |
| Failed model calls | 0 |
| Protocol violations | 0 |
| Attention mean / maximum / final | 969 / 1,000 / 941 words |
| Prompt / completion tokens | 2,317,268 / 17,970 |
| Mean TTFT | 20.448 s |
| TTFT p50 / p95 | 20.634 / 21.923 s |
| Client generation throughput | 15.706 tokens/s |
| Model wall time | 11,184.876 s |
| 140 ms boundaries met | 0 / 491 |

## Post-freeze deterministic audit

| Metric | Result |
|---|---:|
| Scheduled probes expected / answered | 18 / 18 |
| Missing / extra / wrong-tick probes | 0 / 0 / 0 |
| Deterministic hard-failure flags | 0 |
| Premature spoken reveal | No |
| Speech actions | 1 say / 490 silence |
| Committed words | 8 |
| Proposed non-silent ticks | 4 |
| Motor-inhibited exact echoes | 3 |
| Peak speech queue | 1 |

Coverage is not semantic probe accuracy. Model judging remained disabled.
Qualitative review found both successful causal transitions and incorrect or
overconfident answers; the complete speech stream did not react to the central
reversal.

## Frozen artifact provenance

| Artifact | SHA-256 |
|---|---|
| `attention-events.jsonl` | `8100891936bdadaae1b794b8b773260d5ecbec9b638c0bea17be5a58dd607b3c` |
| `model-events.jsonl` | `84e3afcc0819c5c3999643cda50f4ae71d988867fda793621547d33491e815df` |
| `probe-answers.jsonl` | `4b1c2e381f8b179143900473fe297a4119dc5a533e739b111ebe181c8001f234` |
| `runtime-ticks.jsonl` | `1f6a2d17d9b1d65ab0895b81abffa7f36deae2840a991ff43659c207c8a7852a` |
| `speech-events.jsonl` | `8286b955e651f36a80434a30b75b6e5bd992d5e6a17004a5832352cb5a0d372c` |
| `resolved-config.json` | `ea2968d6fc9946ee7799ee97c23b66e62b70aa8a745cca7129e93fdd59a2aca5` |

Figure 7 is regenerated from those artifacts by
`experiment/scripts/render_paper_figures.py`; its exact plotted values are in
`experiment/results/tables/paper-visual-stream-runtime.csv`.
