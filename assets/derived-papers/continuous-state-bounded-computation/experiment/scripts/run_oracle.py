#!/usr/bin/env python3
"""Generate a tape and run deterministic condition E over it."""

from __future__ import annotations

from argparse import ArgumentParser
from pathlib import Path
from time import perf_counter_ns
import json
import math
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))

from csbc_ledger import (  # noqa: E402
    apply_oracle_transition,
    build_initial_snapshot,
    event_from_dict,
    generate_tape,
    node_bytes,
    snapshot_answers,
    snapshot_hash,
)


def percentile(values: list[float], fraction: float) -> float:
    if not values:
        return 0.0
    ordered = sorted(values)
    return ordered[max(0, math.ceil(fraction * len(ordered)) - 1)]


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n")


def write_jsonl(path: Path, rows: list[dict[str, object]]) -> None:
    with path.open("w") as handle:
        for row in rows:
            handle.write(json.dumps(row, sort_keys=True, separators=(",", ":")) + "\n")


def main() -> int:
    parser = ArgumentParser()
    parser.add_argument("--config", type=Path, required=True)
    parser.add_argument("--out", type=Path, required=True)
    parser.add_argument("--seed", type=int)
    args = parser.parse_args()

    config = json.loads(args.config.read_text())
    if args.seed is not None:
        if "seeds" in config and args.seed not in config["seeds"]:
            raise ValueError(f"seed {args.seed} is not preregistered in the config")
        config["seed"] = args.seed
    if "seed" not in config:
        raise ValueError("config requires seed or --seed")
    args.out.mkdir(parents=True, exist_ok=False)
    write_json(args.out / "config.json", config)

    tape = generate_tape(
        ticks=config["ticks"],
        event_probability=config["event_probability"],
        seed=config["seed"],
        evaluation_interval=config.get("evaluation_interval"),
        evaluation_ticks=config.get("evaluation_ticks"),
    )
    write_jsonl(args.out / "events.jsonl", tape)

    snapshot = build_initial_snapshot()
    ledger: list[dict[str, object]] = []
    latencies_ms: list[float] = []
    invariant_violations = 0
    answer_fields = 0
    correct_fields = 0
    false_clean_numerator = 0
    false_clean_denominator = 0
    unnecessary_dirty_numerator = 0
    unnecessary_dirty_denominator = 0
    total_allocated = 0
    total_reused = 0
    total_allocated_bytes = 0
    total_shared_bytes = 0
    event_count = 0

    for tape_row in tape:
        before = snapshot
        before_hash = snapshot_hash(before)
        started = perf_counter_ns()
        event_data = tape_row["event"]

        if event_data is None:
            after = before
            router_dirty: set[str] = set()
            oracle_dirty: set[str] = set()
            allocated = reused = allocated_bytes = shared_bytes = 0
            if after is not before or snapshot_hash(after) != before_hash or after.version != before.version:
                invariant_violations += 1
        else:
            event_count += 1
            event = event_from_dict(event_data)
            transition = apply_oracle_transition(before, event)
            after = transition.snapshot
            router_dirty = set(transition.router_dirty)
            oracle_dirty = set(transition.oracle_dirty)
            allocated = transition.nodes_allocated
            reused = transition.nodes_reused
            allocated_bytes = transition.bytes_allocated
            shared_bytes = transition.bytes_shared

            for key in before.nodes.keys() - router_dirty:
                if before.nodes[key] is not after.nodes[key]:
                    invariant_violations += 1

            false_clean_numerator += len(oracle_dirty - router_dirty)
            false_clean_denominator += len(oracle_dirty)
            unnecessary_dirty_numerator += len(router_dirty - oracle_dirty)
            unnecessary_dirty_denominator += len(router_dirty)
            total_allocated += allocated
            total_reused += reused
            total_allocated_bytes += allocated_bytes
            total_shared_bytes += shared_bytes

        elapsed_ms = (perf_counter_ns() - started) / 1_000_000
        latencies_ms.append(elapsed_ms)
        snapshot = after

        expected_answers = tape_row.get("oracle_answers")
        query_exact = None
        if expected_answers is not None:
            actual_answers = snapshot_answers(snapshot)
            answer_fields += len(expected_answers)
            correct_fields += sum(actual_answers[key] == value for key, value in expected_answers.items())
            query_exact = actual_answers == expected_answers

        ledger.append({
            "tick": tape_row["tick"],
            "event_id": event_data["event_id"] if event_data else None,
            "semantic_class": event_data["semantic_class"] if event_data else None,
            "snapshot_before_version": before.version,
            "snapshot_after_version": after.version,
            "snapshot_before_hash": before_hash,
            "snapshot_after_hash": snapshot_hash(after),
            "semantic_update_calls": 0,
            "oracle_update_calls": 1 if event_data else 0,
            "state_bytes_touched": allocated_bytes,
            "nodes_allocated": allocated,
            "nodes_reused": reused,
            "bytes_allocated": allocated_bytes,
            "bytes_structurally_shared": shared_bytes,
            "oracle_dirty": sorted(oracle_dirty),
            "router_dirty": sorted(router_dirty),
            "latency_ms": elapsed_ms,
            "query_exact": query_exact,
        })

    final_hash = snapshot_hash(snapshot)
    tape_hash_matches = final_hash == tape[-1]["oracle_snapshot_hash"]
    if not tape_hash_matches:
        invariant_violations += 1

    summary = {
        "condition": "E_ORACLE_SPARSE",
        "ticks": config["ticks"],
        "events": event_count,
        "empty_ticks": config["ticks"] - event_count,
        "semantic_update_calls": 0,
        "oracle_update_calls": event_count,
        "invariant_violations": invariant_violations,
        "tape_hash_matches": tape_hash_matches,
        "answer_field_accuracy": correct_fields / answer_fields if answer_fields else None,
        "false_clean_rate": false_clean_numerator / false_clean_denominator if false_clean_denominator else 0.0,
        "unnecessary_dirty_rate": unnecessary_dirty_numerator / unnecessary_dirty_denominator if unnecessary_dirty_denominator else 0.0,
        "nodes_allocated": total_allocated,
        "nodes_reused": total_reused,
        "bytes_allocated": total_allocated_bytes,
        "bytes_structurally_shared": total_shared_bytes,
        "latency_ms": {
            "p50": percentile(latencies_ms, 0.50),
            "p95": percentile(latencies_ms, 0.95),
            "p99": percentile(latencies_ms, 0.99),
            "max": max(latencies_ms, default=0.0),
        },
        "final_snapshot_hash": final_hash,
        "final_state_bytes": sum(node_bytes(node) for node in snapshot.nodes.values()),
    }
    write_jsonl(args.out / "ledger.jsonl", ledger)
    write_json(args.out / "summary.json", summary)
    print(json.dumps(summary, indent=2, sort_keys=True))
    return 0 if invariant_violations == 0 and summary["answer_field_accuracy"] == 1.0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
