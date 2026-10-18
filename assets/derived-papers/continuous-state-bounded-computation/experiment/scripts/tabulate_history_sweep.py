#!/usr/bin/env python3
"""Aggregate preregistered A/B/D/E history sweeps without model calls."""

from __future__ import annotations

from argparse import ArgumentParser
from collections import defaultdict
from pathlib import Path
from statistics import mean, stdev
import json


def read_jsonl(path: Path) -> list[dict]:
    with path.open() as handle:
        return [json.loads(line) for line in handle]


def mean_sd(values: list[float], digits: int = 3) -> str:
    center = mean(values)
    spread = stdev(values) if len(values) > 1 else 0.0
    return f"{center:.{digits}f} ± {spread:.{digits}f}"


def checkpoint_rows(root: Path, seed: int, checkpoints: list[int]) -> list[dict]:
    output: list[dict] = []
    for condition in ("A", "B"):
        ledger = read_jsonl(root / f"seed-{seed}" / condition / "ledger.jsonl")
        by_tick = {row["tick"]: row for row in ledger}
        previous_events = 0
        for tick in checkpoints:
            row = by_tick[tick]
            events = row["history_events_total"]
            output.append({
                "seed": seed,
                "tick": tick,
                "condition": condition,
                "semantic_accuracy": row["field_accuracy"],
                "world_accuracy": row["world_accuracy"],
                "belief_accuracy": row["belief_accuracy"],
                "presence_accuracy": row["presence_accuracy"],
                "current_prompt_tokens": row["prompt_tokens"],
                "current_completion_tokens": row["completion_tokens"],
                "current_wall_ms": row["decision_wall_ms"],
                "cumulative_prompt_tokens": row["cumulative_prompt_tokens"],
                "cumulative_completion_tokens": row["cumulative_completion_tokens"],
                "cumulative_wall_ms": row["cumulative_inference_wall_ms"],
                "cumulative_model_calls": row["cumulative_query_calls"],
                "interval_events": events - previous_events,
                "interval_model_calls": 1,
                "interval_prompt_tokens": row["prompt_tokens"],
                "interval_completion_tokens": row["completion_tokens"],
                "interval_wall_ms": row["decision_wall_ms"],
            })
            previous_events = events

    sparse = read_jsonl(root / f"seed-{seed}" / "D" / "ledger.jsonl")
    sparse_by_tick = {row["tick"]: row for row in sparse}
    previous_tick = 0
    for tick in checkpoints:
        checkpoint = sparse_by_tick[tick]
        interval = [row for row in sparse if previous_tick < row["tick"] <= tick]
        output.append({
            "seed": seed,
            "tick": tick,
            "condition": "D",
            "semantic_accuracy": checkpoint["query_semantic_field_accuracy"],
            "world_accuracy": checkpoint["query_world_accuracy"],
            "belief_accuracy": checkpoint["query_belief_accuracy"],
            "presence_accuracy": checkpoint["query_presence_accuracy"],
            "current_prompt_tokens": 0,
            "current_completion_tokens": 0,
            "current_wall_ms": None,
            "cumulative_prompt_tokens": checkpoint["cumulative_prompt_tokens"],
            "cumulative_completion_tokens": checkpoint["cumulative_completion_tokens"],
            "cumulative_wall_ms": checkpoint["cumulative_inference_wall_ms"],
            "cumulative_model_calls": checkpoint["cumulative_model_calls"],
            "interval_events": sum(row["event_id"] is not None for row in interval),
            "interval_model_calls": sum(row["model_call"] for row in interval),
            "interval_prompt_tokens": sum(row["prompt_tokens"] for row in interval),
            "interval_completion_tokens": sum(row["completion_tokens"] for row in interval),
            "interval_wall_ms": sum(row["model_wall_ms"] for row in interval),
        })
        previous_tick = tick

    oracle = read_jsonl(root / f"seed-{seed}" / "E" / "ledger.jsonl")
    oracle_by_tick = {row["tick"]: row for row in oracle}
    previous_tick = 0
    for tick in checkpoints:
        interval = [row for row in oracle if previous_tick < row["tick"] <= tick]
        output.append({
            "seed": seed,
            "tick": tick,
            "condition": "E",
            "semantic_accuracy": 1.0,
            "world_accuracy": 1.0,
            "belief_accuracy": 1.0,
            "presence_accuracy": 1.0,
            "current_prompt_tokens": 0,
            "current_completion_tokens": 0,
            "current_wall_ms": None,
            "cumulative_prompt_tokens": 0,
            "cumulative_completion_tokens": 0,
            "cumulative_wall_ms": 0.0,
            "cumulative_model_calls": 0,
            "interval_events": sum(row["event_id"] is not None for row in interval),
            "interval_model_calls": 0,
            "interval_prompt_tokens": 0,
            "interval_completion_tokens": 0,
            "interval_wall_ms": 0.0,
        })
        if oracle_by_tick[tick]["query_exact"] is not True:
            raise ValueError(f"oracle query failed at seed={seed}, tick={tick}")
        previous_tick = tick
    return output


def render(rows: list[dict], checkpoints: list[int]) -> str:
    grouped: dict[tuple[int, str], list[dict]] = defaultdict(list)
    for row in rows:
        grouped[(row["tick"], row["condition"])].append(row)

    lines = [
        "# Confirmatory History Sweep",
        "",
        "## Semantic accuracy",
        "",
        "Values are mean ± sample standard deviation across preregistered seeds.",
        "",
        "| Tick | Condition | Semantic | World | Belief | Presence |",
        "|---:|---|---:|---:|---:|---:|",
    ]
    for tick in checkpoints:
        for condition in ("A", "B", "D", "E"):
            bucket = grouped[(tick, condition)]
            lines.append(
                f"| {tick:,} | {condition} | "
                f"{mean_sd([100 * row['semantic_accuracy'] for row in bucket], 1)}% | "
                f"{mean_sd([100 * row['world_accuracy'] for row in bucket], 1)}% | "
                f"{mean_sd([100 * row['belief_accuracy'] for row in bucket], 1)}% | "
                f"{mean_sd([100 * row['presence_accuracy'] for row in bucket], 1)}% |"
            )

    lines.extend([
        "",
        "## Inference accounting",
        "",
        "D has zero neural work at the evaluation boundary; its cumulative and interval columns include all prior semantic updates.",
        "",
        "| Tick | Condition | Current prompt tok. | Cumulative prompt tok. | Cumulative completion tok. | Cumulative wall s | Interval events | Interval calls | Interval prompt/event |",
        "|---:|---|---:|---:|---:|---:|---:|---:|---:|",
    ])
    for tick in checkpoints:
        for condition in ("A", "B", "D", "E"):
            bucket = grouped[(tick, condition)]
            prompt_per_event = [
                row["interval_prompt_tokens"] / row["interval_events"]
                if row["interval_events"] else 0.0
                for row in bucket
            ]
            lines.append(
                f"| {tick:,} | {condition} | "
                f"{mean_sd([row['current_prompt_tokens'] for row in bucket], 1)} | "
                f"{mean_sd([row['cumulative_prompt_tokens'] for row in bucket], 1)} | "
                f"{mean_sd([row['cumulative_completion_tokens'] for row in bucket], 1)} | "
                f"{mean_sd([row['cumulative_wall_ms'] / 1000 for row in bucket], 2)} | "
                f"{mean_sd([row['interval_events'] for row in bucket], 1)} | "
                f"{mean_sd([row['interval_model_calls'] for row in bucket], 1)} | "
                f"{mean_sd(prompt_per_event, 1)} |"
            )
    return "\n".join(lines) + "\n"


def main() -> int:
    parser = ArgumentParser()
    parser.add_argument("--root", type=Path, required=True)
    parser.add_argument("--seeds", type=int, nargs="+", required=True)
    parser.add_argument("--checkpoints", type=int, nargs="+", required=True)
    parser.add_argument("--output-markdown", type=Path, required=True)
    parser.add_argument("--output-jsonl", type=Path, required=True)
    args = parser.parse_args()

    rows: list[dict] = []
    for seed in args.seeds:
        rows.extend(checkpoint_rows(args.root, seed, args.checkpoints))
    with args.output_jsonl.open("w") as handle:
        for row in rows:
            handle.write(json.dumps(row, sort_keys=True, separators=(",", ":")) + "\n")
    args.output_markdown.write_text(render(rows, args.checkpoints))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
