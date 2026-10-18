#!/usr/bin/env python3
"""Render one comparable A-E result table from immutable run summaries."""

from __future__ import annotations

from argparse import ArgumentParser
from pathlib import Path
import json


def load(path: Path) -> dict:
    with path.open() as handle:
        return json.load(handle)


def ratio(value: float | None) -> str:
    return "N/A" if value is None else f"{100.0 * value:.1f}%"


def integer(value: int | None) -> str:
    return "N/A" if value is None else f"{value:,}"


def seconds(value: float | None) -> str:
    return "N/A" if value is None else f"{value / 1000.0:.1f}"


def latency(summary: dict) -> float | None:
    for key in ("decision_latency_ms", "model_latency_ms", "latency_ms"):
        if key in summary:
            return summary[key]["p50"]
    return None


def main() -> int:
    parser = ArgumentParser()
    parser.add_argument("--full-history", type=Path, required=True)
    parser.add_argument("--fixed-window", type=Path, required=True)
    parser.add_argument("--all-dirty", type=Path, required=True)
    parser.add_argument("--sparse", type=Path, required=True)
    parser.add_argument("--oracle", type=Path, required=True)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()

    rows = [
        ("A Full replay", load(args.full_history)),
        ("B Fixed window", load(args.fixed_window)),
        ("C Persistent all-dirty", load(args.all_dirty)),
        ("D CSBC sparse", load(args.sparse)),
        ("E Oracle sparse", load(args.oracle)),
    ]
    header = (
        "| Condition | Semantic | World | Belief | Presence | Model calls | "
        "Prompt tok. | Completion tok. | Inference s | Latency p50 ms | "
        "Spurious mutation | False-clean | Unnecessary-dirty | Nodes allocated | Nodes reused |"
    )
    divider = (
        "|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|"
    )
    lines = [header, divider]
    for label, summary in rows:
        is_oracle = label.startswith("E ")
        calls = summary.get("model_calls", summary.get("query_calls", 0))
        lines.append(
            "| " + " | ".join([
                label,
                ratio(1.0 if is_oracle else summary.get("semantic_field_accuracy")),
                ratio(1.0 if is_oracle else summary.get("world_accuracy")),
                ratio(1.0 if is_oracle else summary.get("belief_accuracy")),
                ratio(1.0 if is_oracle else summary.get("presence_accuracy")),
                integer(calls),
                integer(summary.get("prompt_tokens", 0)),
                integer(summary.get("completion_tokens", 0)),
                seconds(summary.get("cumulative_inference_wall_ms", 0.0)),
                "N/A" if latency(summary) is None else f"{latency(summary):.3f}",
                ratio(summary.get("spurious_mutation_rate")),
                ratio(summary.get("false_clean_rate")),
                ratio(summary.get("unnecessary_dirty_rate")),
                integer(summary.get("nodes_allocated")),
                integer(summary.get("nodes_reused")),
            ]) + " |"
        )

    rendered = "\n".join(lines) + "\n"
    if args.output:
        args.output.write_text(rendered)
    else:
        print(rendered, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
