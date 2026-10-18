#!/usr/bin/env python3
"""Run persistent all-dirty or sparse updates over an immutable event tape."""

from __future__ import annotations

from argparse import ArgumentParser
from pathlib import Path
import json
import math
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))

from csbc_ledger import (  # noqa: E402
    apply_oracle_transition,
    apply_semantic_transition,
    build_initial_snapshot,
    event_from_dict,
    is_semantic_key,
    semantic_dirty,
    semantic_state,
    snapshot_answers,
    snapshot_hash,
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


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n")


def main() -> int:
    parser = ArgumentParser()
    parser.add_argument("--tape", type=Path, required=True)
    parser.add_argument("--out", type=Path, required=True)
    parser.add_argument("--base-url", default="http://127.0.0.1:8081")
    parser.add_argument("--model", default="qwen3.5-9b-heretic")
    parser.add_argument("--seed", type=int, default=42)
    parser.add_argument("--protocol", choices=("json", "compact"), default="json")
    parser.add_argument("--condition", choices=("C", "D"), default="D")
    parser.add_argument("--request-timeout-seconds", type=float, default=180)
    parser.add_argument("--progress-every", type=int, default=0)
    args = parser.parse_args()
    if args.progress_every < 0:
        parser.error("--progress-every must be non-negative")
    args.out.mkdir(parents=True, exist_ok=False)

    tape = read_jsonl(args.tape)
    adapter = LlamaSemanticAdapter(
        args.base_url,
        args.model,
        args.seed,
        args.protocol,
        args.request_timeout_seconds,
    )
    snapshot = build_initial_snapshot()
    oracle_snapshot = build_initial_snapshot()
    all_semantic = sorted(key for key in snapshot.nodes if is_semantic_key(key))
    ledger = []
    update_latencies: list[float] = []
    model_calls = prompt_tokens = completion_tokens = 0
    events_seen = 0
    failed_transitions = 0
    invariant_violations = 0
    evaluated_fields = correct_fields = 0
    target_events = target_exact = 0
    target_fields = target_fields_correct = 0
    semantic_fields = semantic_fields_correct = 0
    world_fields = world_fields_correct = 0
    belief_fields = belief_fields_correct = 0
    presence_fields = presence_fields_correct = 0
    class_counts: dict[str, dict[str, int]] = {}
    nodes_allocated = nodes_reused = bytes_allocated = bytes_shared = 0
    false_clean_numerator = false_clean_denominator = 0
    unnecessary_dirty_numerator = unnecessary_dirty_denominator = 0
    spurious_numerator = spurious_denominator = 0
    error_started: dict[str, int] = {}
    error_episodes = repaired_error_episodes = 0
    error_durations: list[int] = []
    error_node_ticks = max_concurrent_errors = 0
    repair_attempts = successful_repairs = 0

    for row in tape:
        before = snapshot
        event_data = row["event"]
        model_result = None
        if event_data is None:
            after = before
            if after is not before or snapshot_hash(after) != snapshot_hash(before):
                invariant_violations += 1
        else:
            events_seen += 1
            event = event_from_dict(event_data)
            oracle_before = oracle_snapshot
            oracle_transition = apply_oracle_transition(oracle_snapshot, event)
            oracle_snapshot = oracle_transition.snapshot
            targets = all_semantic if args.condition == "C" else sorted(semantic_dirty(event))
            repair_targets = {
                key for key in targets
                if before.nodes[key].value != oracle_before.nodes[key].value
            }
            repair_attempts += len(repair_targets)
            transition_error = None
            if targets:
                state_slice = {key: before.nodes[key].value for key in targets}
                model_calls += 1
                try:
                    model_result = adapter.transition(state_slice, targets, event.text)
                    prompt_tokens += model_result.prompt_tokens
                    completion_tokens += model_result.completion_tokens
                    update_latencies.append(model_result.wall_ms)
                    transition = apply_semantic_transition(
                        before,
                        event,
                        model_result.updates,
                        target_keys=targets,
                    )
                except (ValueError, TypeError, json.JSONDecodeError) as exc:
                    transition_error = str(exc)
                    failed_transitions += 1
                    stale_updates = {key: before.nodes[key].value for key in targets}
                    transition = apply_semantic_transition(
                        before,
                        event,
                        stale_updates,
                        semantic_status="unknown",
                        target_keys=targets,
                    )
            else:
                transition = apply_semantic_transition(before, event, {})
            after = transition.snapshot
            successful_repairs += sum(
                after.nodes[key].value == oracle_snapshot.nodes[key].value
                for key in repair_targets
            )
            nodes_allocated += transition.nodes_allocated
            nodes_reused += transition.nodes_reused
            bytes_allocated += transition.bytes_allocated
            bytes_shared += transition.bytes_shared

            oracle_dirty = set(row["oracle_dirty"])
            routed = set(transition.router_dirty)
            false_clean_numerator += len(oracle_dirty - routed)
            false_clean_denominator += len(oracle_dirty)
            unnecessary_dirty_numerator += len(routed - oracle_dirty)
            unnecessary_dirty_denominator += len(routed)
            unaffected_semantic = set(all_semantic) - oracle_dirty
            spurious_numerator += sum(
                after.nodes[key].value != before.nodes[key].value
                for key in unaffected_semantic
            )
            spurious_denominator += len(unaffected_semantic)

            for key in before.nodes.keys() - transition.router_dirty:
                if before.nodes[key] is not after.nodes[key]:
                    invariant_violations += 1

            if targets:
                field_matches = [
                    after.nodes[key].value == oracle_snapshot.nodes[key].value
                    for key in targets
                ]
                exact = all(field_matches)
                target_events += 1
                target_exact += int(exact)
                target_fields += len(field_matches)
                target_fields_correct += sum(field_matches)
                class_row = class_counts.setdefault(
                    event.semantic_class,
                    {"events": 0, "exact": 0, "fields": 0, "fields_correct": 0},
                )
                class_row["events"] += 1
                class_row["exact"] += int(exact)
                class_row["fields"] += len(field_matches)
                class_row["fields_correct"] += sum(field_matches)

        snapshot = after
        concurrent_errors = 0
        for key in all_semantic:
            mismatched = snapshot.nodes[key].value != oracle_snapshot.nodes[key].value
            if mismatched:
                concurrent_errors += 1
                error_node_ticks += 1
                if key not in error_started:
                    error_started[key] = row["tick"]
                    error_episodes += 1
            elif key in error_started:
                error_durations.append(row["tick"] - error_started.pop(key))
                repaired_error_episodes += 1
        max_concurrent_errors = max(max_concurrent_errors, concurrent_errors)
        expected_answers = row.get("oracle_answers")
        query_exact = None
        query_semantic_accuracy = None
        query_world_accuracy = None
        query_belief_accuracy = None
        query_presence_accuracy = None
        if expected_answers is not None:
            actual_answers = snapshot_answers(snapshot)
            evaluated_fields += len(expected_answers)
            correct_fields += sum(actual_answers[key] == value for key, value in expected_answers.items())
            query_exact = actual_answers == expected_answers

            actual_semantic = semantic_state(snapshot)
            expected_semantic = semantic_state(oracle_snapshot)
            query_counts = {
                "semantic": [0, 0],
                "world": [0, 0],
                "belief": [0, 0],
                "presence": [0, 0],
            }
            for key, expected_value in expected_semantic.items():
                matched = actual_semantic[key] == expected_value
                semantic_fields += 1
                semantic_fields_correct += int(matched)
                query_counts["semantic"][0] += int(matched)
                query_counts["semantic"][1] += 1
                if key.startswith("world.server."):
                    world_fields += 1
                    world_fields_correct += int(matched)
                    bucket = "world"
                elif ".belief." in key:
                    belief_fields += 1
                    belief_fields_correct += int(matched)
                    bucket = "belief"
                else:
                    presence_fields += 1
                    presence_fields_correct += int(matched)
                    bucket = "presence"
                query_counts[bucket][0] += int(matched)
                query_counts[bucket][1] += 1
            query_semantic_accuracy = query_counts["semantic"][0] / query_counts["semantic"][1]
            query_world_accuracy = query_counts["world"][0] / query_counts["world"][1]
            query_belief_accuracy = query_counts["belief"][0] / query_counts["belief"][1]
            query_presence_accuracy = query_counts["presence"][0] / query_counts["presence"][1]

        ledger.append({
            "tick": row["tick"],
            "event_id": event_data["event_id"] if event_data else None,
            "semantic_class": event_data["semantic_class"] if event_data else None,
            "snapshot_before_version": before.version,
            "snapshot_after_version": after.version,
            "snapshot_before_hash": snapshot_hash(before),
            "snapshot_after_hash": snapshot_hash(after),
            "model_call": model_result is not None,
            "prompt_tokens": model_result.prompt_tokens if model_result else 0,
            "completion_tokens": model_result.completion_tokens if model_result else 0,
            "model_wall_ms": model_result.wall_ms if model_result else 0.0,
            "model_content": model_result.raw_content if model_result else None,
            "transition_error": transition_error if event_data else None,
            "oracle_dirty": row["oracle_dirty"],
            "router_dirty": sorted(transition.router_dirty) if event_data else [],
            "query_exact": query_exact,
            "query_semantic_field_accuracy": query_semantic_accuracy,
            "query_world_accuracy": query_world_accuracy,
            "query_belief_accuracy": query_belief_accuracy,
            "query_presence_accuracy": query_presence_accuracy,
            "cumulative_model_calls": model_calls,
            "cumulative_events": events_seen,
            "cumulative_prompt_tokens": prompt_tokens,
            "cumulative_completion_tokens": completion_tokens,
            "cumulative_inference_wall_ms": sum(update_latencies),
        })
        if args.progress_every and row["tick"] % args.progress_every == 0:
            print(
                json.dumps({
                    "progress_tick": row["tick"],
                    "ticks_total": len(tape),
                    "events_seen": events_seen,
                    "model_calls": model_calls,
                    "prompt_tokens": prompt_tokens,
                    "failed_transitions": failed_transitions,
                    "concurrent_semantic_errors": concurrent_errors,
                    "cumulative_inference_wall_ms": sum(update_latencies),
                }, sort_keys=True),
                file=sys.stderr,
                flush=True,
            )

    class_accuracy = {
        key: {
            "event_exact_accuracy": value["exact"] / value["events"],
            "field_accuracy": value["fields_correct"] / value["fields"],
        }
        for key, value in sorted(class_counts.items())
    }
    unresolved_error_ages = [len(tape) + 1 - tick for tick in error_started.values()]
    observed_error_ages = error_durations + unresolved_error_ages
    summary = {
        "condition": "C_PERSISTENT_ALL_DIRTY" if args.condition == "C" else "D_CSBC_SPARSE",
        "protocol": args.protocol,
        "request_timeout_seconds": args.request_timeout_seconds,
        "ticks": len(tape),
        "events": sum(row["event"] is not None for row in tape),
        "model_calls": model_calls,
        "prompt_tokens": prompt_tokens,
        "completion_tokens": completion_tokens,
        "failed_transitions": failed_transitions,
        "failed_transition_rate": failed_transitions / model_calls if model_calls else 0.0,
        "cumulative_inference_wall_ms": sum(update_latencies),
        "semantic_target_event_accuracy": target_exact / target_events if target_events else None,
        "semantic_target_field_accuracy": target_fields_correct / target_fields if target_fields else None,
        "semantic_field_accuracy": semantic_fields_correct / semantic_fields if semantic_fields else None,
        "world_accuracy": world_fields_correct / world_fields if world_fields else None,
        "belief_accuracy": belief_fields_correct / belief_fields if belief_fields else None,
        "presence_accuracy": presence_fields_correct / presence_fields if presence_fields else None,
        "answer_field_accuracy": correct_fields / evaluated_fields if evaluated_fields else None,
        "event_class_accuracy": class_accuracy,
        "invariant_violations": invariant_violations,
        "false_clean_rate": false_clean_numerator / false_clean_denominator if false_clean_denominator else 0.0,
        "unnecessary_dirty_rate": unnecessary_dirty_numerator / unnecessary_dirty_denominator if unnecessary_dirty_denominator else 0.0,
        "spurious_mutation_rate": spurious_numerator / spurious_denominator if spurious_denominator else 0.0,
        "error_episodes": error_episodes,
        "repaired_error_episodes": repaired_error_episodes,
        "unresolved_error_episodes": len(error_started),
        "error_state_occupancy": error_node_ticks / (len(tape) * len(all_semantic)),
        "mean_observed_error_age_ticks": (
            sum(observed_error_ages) / len(observed_error_ages)
            if observed_error_ages else 0.0
        ),
        "max_observed_error_age_ticks": max(observed_error_ages, default=0),
        "max_concurrent_semantic_errors": max_concurrent_errors,
        "repair_attempts": repair_attempts,
        "successful_repairs": successful_repairs,
        "repair_success_rate": successful_repairs / repair_attempts if repair_attempts else None,
        "nodes_allocated": nodes_allocated,
        "nodes_reused": nodes_reused,
        "bytes_allocated": bytes_allocated,
        "bytes_structurally_shared": bytes_shared,
        "model_latency_ms": {
            "p50": percentile(update_latencies, 0.50),
            "p95": percentile(update_latencies, 0.95),
            "p99": percentile(update_latencies, 0.99),
            "max": max(update_latencies, default=0.0),
        },
        "final_snapshot_hash": snapshot_hash(snapshot),
        "oracle_final_snapshot_hash": snapshot_hash(oracle_snapshot),
        "final_snapshot_exact": snapshot_hash(snapshot) == snapshot_hash(oracle_snapshot),
    }
    with (args.out / "ledger.jsonl").open("w") as handle:
        for row in ledger:
            handle.write(json.dumps(row, sort_keys=True, separators=(",", ":")) + "\n")
    write_json(args.out / "summary.json", summary)
    print(json.dumps(summary, indent=2, sort_keys=True))
    return 0 if invariant_violations == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
