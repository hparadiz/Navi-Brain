#!/usr/bin/env python3
"""Run full-history or fixed-window reconstruction over an event tape."""

from __future__ import annotations

from argparse import ArgumentParser
from pathlib import Path
from time import perf_counter
import json
import math
import sys
from urllib.error import HTTPError, URLError

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))

from csbc_ledger import (  # noqa: E402
    apply_oracle_transition,
    build_initial_snapshot,
    event_from_dict,
    is_semantic_key,
    semantic_state,
)
from llama_adapter import LlamaSemanticAdapter  # noqa: E402


def percentile(values: list[float], fraction: float) -> float:
    if not values:
        return 0.0
    ordered = sorted(values)
    return ordered[max(0, math.ceil(fraction * len(ordered)) - 1)]


def read_jsonl(path: Path) -> list[dict]:
    with path.open() as handle:
        return [json.loads(line) for line in handle]


def main() -> int:
    parser = ArgumentParser()
    parser.add_argument("--tape", type=Path, required=True)
    parser.add_argument("--out", type=Path, required=True)
    parser.add_argument("--condition", choices=("A", "B"), required=True)
    parser.add_argument("--window", type=int, default=10)
    parser.add_argument("--base-url", default="http://127.0.0.1:8081")
    parser.add_argument("--model", default="qwen3.5-9b-heretic")
    parser.add_argument("--seed", type=int, default=42)
    parser.add_argument("--request-timeout-seconds", type=float, default=180)
    args = parser.parse_args()
    args.out.mkdir(parents=True, exist_ok=False)

    tape = read_jsonl(args.tape)
    oracle = build_initial_snapshot()
    initial = semantic_state(build_initial_snapshot())
    target_keys = sorted(initial)
    adapter = LlamaSemanticAdapter(
        args.base_url,
        args.model,
        args.seed,
        "compact",
        args.request_timeout_seconds,
    )
    event_history: list[str] = []
    ledger = []
    latencies: list[float] = []
    failed_latencies: list[float] = []
    prompt_tokens = completion_tokens = calls = failed = 0
    correct_fields = evaluated_fields = 0
    world_correct = world_fields = 0
    belief_correct = belief_fields = 0
    presence_correct = presence_fields = 0

    for row in tape:
        if row["event"] is not None:
            event = event_from_dict(row["event"])
            event_history.append(event.text)
            oracle = apply_oracle_transition(oracle, event).snapshot
        if "oracle_answers" not in row:
            continue

        visible_events = event_history if args.condition == "A" else event_history[-args.window:]
        error = None
        result = None
        calls += 1
        attempt_started = perf_counter()
        try:
            result = adapter.reconstruct(initial, target_keys, visible_events)
            reconstructed = result.updates
            prompt_tokens += result.prompt_tokens
            completion_tokens += result.completion_tokens
            attempt_wall_ms = result.wall_ms
        except (ValueError, TypeError, json.JSONDecodeError, HTTPError, URLError, TimeoutError) as exc:
            error = str(exc)
            failed += 1
            reconstructed = initial
            attempt_wall_ms = (perf_counter() - attempt_started) * 1000
            failed_latencies.append(attempt_wall_ms)
        latencies.append(attempt_wall_ms)

        expected = semantic_state(oracle)
        exact_fields = sum(reconstructed[key] == expected[key] for key in target_keys)
        correct_fields += exact_fields
        evaluated_fields += len(target_keys)
        query_counts = {
            "world": [0, 0],
            "belief": [0, 0],
            "presence": [0, 0],
        }
        for key in target_keys:
            matched = reconstructed[key] == expected[key]
            if key.startswith("world.server."):
                world_fields += 1
                world_correct += int(matched)
                bucket = "world"
            elif ".belief." in key:
                belief_fields += 1
                belief_correct += int(matched)
                bucket = "belief"
            else:
                presence_fields += 1
                presence_correct += int(matched)
                bucket = "presence"
            query_counts[bucket][0] += int(matched)
            query_counts[bucket][1] += 1

        ledger.append({
            "tick": row["tick"],
            "history_events_total": len(event_history),
            "history_events_visible": len(visible_events),
            "history_characters_visible": sum(len(text) for text in visible_events),
            "prompt_tokens": result.prompt_tokens if result else 0,
            "completion_tokens": result.completion_tokens if result else 0,
            "decision_wall_ms": attempt_wall_ms,
            "raw_content": result.raw_content if result else None,
            "error": error,
            "field_accuracy": exact_fields / len(target_keys),
            "world_accuracy": query_counts["world"][0] / query_counts["world"][1],
            "belief_accuracy": query_counts["belief"][0] / query_counts["belief"][1],
            "presence_accuracy": query_counts["presence"][0] / query_counts["presence"][1],
            "cumulative_query_calls": calls,
            "cumulative_prompt_tokens": prompt_tokens,
            "cumulative_completion_tokens": completion_tokens,
            "cumulative_inference_wall_ms": sum(latencies),
        })

    summary = {
        "condition": "A_FULL_HISTORY" if args.condition == "A" else "B_FIXED_WINDOW",
        "protocol": "compact",
        "request_timeout_seconds": args.request_timeout_seconds,
        "window": None if args.condition == "A" else args.window,
        "ticks": len(tape),
        "events": len(event_history),
        "query_calls": calls,
        "failed_queries": failed,
        "prompt_tokens": prompt_tokens,
        "completion_tokens": completion_tokens,
        "cumulative_inference_wall_ms": sum(latencies),
        "failed_inference_wall_ms": sum(failed_latencies),
        "semantic_field_accuracy": correct_fields / evaluated_fields if evaluated_fields else None,
        "world_accuracy": world_correct / world_fields if world_fields else None,
        "belief_accuracy": belief_correct / belief_fields if belief_fields else None,
        "presence_accuracy": presence_correct / presence_fields if presence_fields else None,
        "decision_latency_ms": {
            "p50": percentile(latencies, 0.50),
            "p95": percentile(latencies, 0.95),
            "p99": percentile(latencies, 0.99),
            "max": max(latencies, default=0.0),
        },
    }
    with (args.out / "ledger.jsonl").open("w") as handle:
        for row in ledger:
            handle.write(json.dumps(row, sort_keys=True, separators=(",", ":")) + "\n")
    (args.out / "summary.json").write_text(json.dumps(summary, indent=2, sort_keys=True) + "\n")
    print(json.dumps(summary, indent=2, sort_keys=True))
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
