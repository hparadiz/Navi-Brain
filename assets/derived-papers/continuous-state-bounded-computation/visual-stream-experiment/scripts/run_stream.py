#!/usr/bin/env python3
"""Run the anonymous 140 ms stream without exposing the sealed oracle."""

from __future__ import annotations

from argparse import ArgumentParser
from collections import Counter
from copy import deepcopy
from hashlib import sha256
from pathlib import Path
from time import monotonic_ns, perf_counter, sleep
from typing import Any, Mapping
import fcntl
import json
import os
import platform
import socket
import sys


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))

from cognition_model_adapter import (  # noqa: E402
    JsonModelResult,
    ModelResponseError,
    OpenAIJsonAdapter,
    TextModelResult,
    CognitionAdapter,
    LegacyStructuredAdapter,
    validate_probe_answers,
    validate_structured_shape,
)
from attention_transition import (  # noqa: E402
    ATTENTION_POLICY_VERSION,
    COGNITION_SLOTS,
    AttentionFilter,
    AttentionSnapshot,
    parse_cognition_response,
    render_cognition_input,
)
from stream_runtime import (  # noqa: E402
    ALLOWED_STATE_ROOTS,
    SpeechRuntime,
    apply_updates,
    behavioral_state_violations,
    canonical_json,
    future_tick_references,
    jsonl_rows,
    object_hash,
    validate_state_shape,
    word_count,
)


def parse_args() -> Any:
    parser = ArgumentParser()
    parser.add_argument("--config", type=Path, required=True)
    parser.add_argument("--out", type=Path)
    parser.add_argument("--max-ticks", type=int)
    parser.add_argument("--mode", choices=("offline", "paced"))
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--progress-every", type=int, default=0)
    args = parser.parse_args()
    if args.max_ticks is not None and args.max_ticks <= 0:
        parser.error("--max-ticks must be positive")
    if args.progress_every < 0:
        parser.error("--progress-every must be non-negative")
    return args


def read_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def write_jsonl_row(handle: Any, value: object) -> None:
    handle.write(canonical_json(value) + "\n")
    handle.flush()


def file_hash(path: Path) -> str:
    digest = sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def acquire_exclusive_run_lock(path: Path) -> Any:
    """Prevent concurrent subject runs from contaminating host timing."""

    path.parent.mkdir(parents=True, exist_ok=True)
    handle = path.open("a+", encoding="utf-8")
    try:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError as exc:
        handle.seek(0)
        owner = handle.read().strip() or "unknown owner"
        handle.close()
        raise SystemExit(f"exclusive experiment host lock is already held by {owner}") from exc
    handle.seek(0)
    handle.truncate()
    handle.write(f"pid {os.getpid()} on {socket.gethostname()}\n")
    handle.flush()
    return handle


def percentile(values: list[float], quantile: float) -> float | None:
    if not values:
        return None
    ordered = sorted(values)
    position = (len(ordered) - 1) * quantile
    lower = int(position)
    upper = min(lower + 1, len(ordered) - 1)
    fraction = position - lower
    return ordered[lower] * (1 - fraction) + ordered[upper] * fraction


def resolve_input(path_value: str) -> Path:
    path = Path(path_value)
    return path if path.is_absolute() else ROOT / path


def probes_by_tick(rows: list[dict[str, Any]]) -> dict[int, list[dict[str, Any]]]:
    result: dict[int, list[dict[str, Any]]] = {}
    seen: set[str] = set()
    for row in rows:
        if set(row) != {"tick", "probes"}:
            raise ValueError("each public probe row must contain exactly tick and probes")
        tick = row["tick"]
        probes = row["probes"]
        if not isinstance(tick, int) or tick <= 0 or not isinstance(probes, list):
            raise ValueError("invalid public probe row")
        clean: list[dict[str, Any]] = []
        for probe in probes:
            if set(probe) != {"id", "question"}:
                raise ValueError("public probes may contain only id and question")
            if probe["id"] in seen:
                raise ValueError(f"duplicate public probe id: {probe['id']}")
            seen.add(probe["id"])
            clean.append(probe)
        result[tick] = clean
    return result


def social_messages_by_tick(rows: list[dict[str, Any]]) -> dict[int, list[dict[str, Any]]]:
    result: dict[int, list[dict[str, Any]]] = {}
    seen: set[str] = set()
    for row in rows:
        if set(row) != {"tick", "messages"}:
            raise ValueError("each social event row must contain exactly tick and messages")
        tick = row["tick"]
        messages = row["messages"]
        if not isinstance(tick, int) or tick <= 0 or not isinstance(messages, list):
            raise ValueError("invalid social event row")
        clean: list[dict[str, Any]] = []
        for message in messages:
            if set(message) != {"id", "sender", "text", "salience"}:
                raise ValueError("social messages require id, sender, text, and salience")
            if message["id"] in seen:
                raise ValueError(f"duplicate social message id: {message['id']}")
            if not all(isinstance(message[name], str) for name in ("id", "sender", "text")):
                raise ValueError("social message identity, sender, and text must be prose")
            if not isinstance(message["salience"], (int, float)):
                raise ValueError("social message salience must be numeric")
            seen.add(message["id"])
            clean.append(message)
        result[tick] = clean
    return result


def context_events_by_tick(rows: list[dict[str, Any]]) -> dict[int, list[dict[str, Any]]]:
    result: dict[int, list[dict[str, Any]]] = {}
    seen: set[str] = set()
    required = {"id", "text", "confidence", "attention_channel"}
    for row in rows:
        if set(row) != {"tick", "events"}:
            raise ValueError("each context event row must contain exactly tick and events")
        tick = row["tick"]
        events = row["events"]
        if not isinstance(tick, int) or tick <= 0 or not isinstance(events, list):
            raise ValueError("invalid context event row")
        clean: list[dict[str, Any]] = []
        for event in events:
            if set(event) not in (required, required | {"cognition_anchors"}):
                raise ValueError(
                    "context events require id, text, confidence, and attention_channel; "
                    "cognition_anchors is optional"
                )
            if event["id"] in seen:
                raise ValueError(f"duplicate context event id: {event['id']}")
            if not all(isinstance(event[name], str) for name in ("id", "text", "attention_channel")):
                raise ValueError("context event identity, text, and channel must be prose")
            if not isinstance(event["confidence"], (int, float)):
                raise ValueError("context event confidence must be numeric")
            anchors = event.get("cognition_anchors", [])
            if not isinstance(anchors, list):
                raise ValueError("context event cognition anchors must be a list")
            anchor_slots: set[str] = set()
            for anchor in anchors:
                if not isinstance(anchor, dict) or set(anchor) != {"slot", "text"}:
                    raise ValueError("each cognition anchor requires slot and text")
                if not all(isinstance(anchor[name], str) for name in ("slot", "text")):
                    raise ValueError("cognition anchor slot and text must be prose")
                if anchor["slot"] not in COGNITION_SLOTS or not anchor["text"].strip():
                    raise ValueError("cognition anchor names a missing slot or empty value")
                if anchor["slot"] in anchor_slots:
                    raise ValueError("duplicate cognition anchor slot in one context event")
                anchor_slots.add(anchor["slot"])
            seen.add(event["id"])
            clean.append(event)
        result[tick] = clean
    return result


def dry_decision(tick: int, probes: list[dict[str, Any]]) -> dict[str, Any]:
    return {
        "updates": [],
        "speech": {
            "action": "silence",
            "utterance_id": None,
            "new_words": "",
            "continues_after_tick": False,
            "reason_codes": ["dry_run"],
            "based_on_ticks": [tick],
        },
        "probe_answers": [
            {
                "id": probe["id"],
                "answer": "Unknown; deterministic dry-run did not call a semantic model.",
                "confidence": 0.0,
                "evidence_ticks": [tick],
                "alternatives": [],
                "what_remains_unknown": "All semantic interpretation is intentionally skipped.",
            }
            for probe in probes
        ],
    }


def scheduler_silence(tick: int, active_utterance_id: str | None) -> dict[str, Any]:
    return {
        "updates": [],
        "speech": {
            "action": "silence",
            "utterance_id": active_utterance_id,
            "new_words": "",
            "continues_after_tick": active_utterance_id is not None,
            "reason_codes": ["deterministic_preserve"],
            "based_on_ticks": [tick],
        },
        "probe_answers": [],
    }


def compress_evidence(rows: list[dict[str, Any]]) -> dict[str, Any]:
    if not rows:
        raise ValueError("cannot compress an empty evidence batch")
    assertion_spans: dict[tuple[str, str], dict[str, Any]] = {}
    object_events: list[dict[str, Any]] = []
    audio_tokens: list[dict[str, Any]] = []
    metric_names = [
        "mean_luminance",
        "mean_near_black_fraction",
        "mean_near_white_fraction",
        "mean_p05_p95_contrast_span",
        "mean_interframe_delta",
        "max_interframe_delta",
    ]
    metric_values: dict[str, list[float]] = {name: [] for name in metric_names}
    for row in rows:
        tick = row["tick"]
        for assertion in row["visual"]["human_assertions"]:
            key = (assertion["kind"], assertion["text"])
            span = assertion_spans.setdefault(key, {
                "kind": assertion["kind"],
                "confidence": assertion["confidence"],
                "text": assertion["text"],
                "first_tick": tick,
                "last_tick": tick,
            })
            span["last_tick"] = tick
        changes = row["changes_from_previous_tick"]
        if changes["phase_changed"] or changes["newly_visible_or_reclassified"] or changes["no_longer_listed_or_now_occluded"]:
            object_events.append({"tick": tick, **changes})
        for token in row["audio"]["new_phonetic_portuguese_tokens"]:
            audio_tokens.append({"tick": tick, **token})
        metrics = row["visual"]["interval_metrics"]
        for name in metric_names:
            metric_values[name].append(float(metrics[name]))
    latest = rows[-1]
    metrics_summary = {
        name: {
            "first": values[0],
            "last": values[-1],
            "min": min(values),
            "max": max(values),
        }
        for name, values in metric_values.items()
    }
    return {
        "from_tick": rows[0]["tick"],
        "to_tick": latest["tick"],
        "tick_count": len(rows),
        "interval": {
            "start_ms": rows[0]["interval"]["start_ms"],
            "end_ms": latest["interval"]["end_ms"],
        },
        "visual_assertion_spans": list(assertion_spans.values()),
        "object_events": object_events,
        "latest_visible_objects": latest["visual"]["visible_objects"],
        "latest_visible_object_ids": [value["id"] for value in latest["visual"]["visible_objects"]],
        "persistent_environment_palette": latest["visual"]["persistent_environment_palette"],
        "visual_metrics_over_batch": metrics_summary,
        "latest_visual_change_level": latest["visual"]["interval_metrics"]["visual_change_level"],
        "new_phonetic_portuguese_tokens": audio_tokens,
        "latest_audio_assertion": latest["audio"]["human_assertion"],
        "phonetic_track_warning": latest["audio"]["phonetic_track_warning"],
        "epistemic_guardrails": latest["epistemic_guardrails"],
        "incremental_speech_decision": latest["incremental_speech_decision"],
    }


def neural_route(
    row: dict[str, Any],
    probes: list[dict[str, Any]],
    pending_rows: list[dict[str, Any]],
    speech_runtime: SpeechRuntime,
    previous_row: dict[str, Any] | None,
    runtime_config: Mapping[str, Any],
    final_tick: bool,
    retry_pending_failure: bool,
) -> list[str]:
    if runtime_config.get("scheduler", "every_tick") == "every_tick":
        return ["every_tick_reference"]
    reasons: list[str] = []
    changes = row["changes_from_previous_tick"]
    if row["tick"] == 1:
        reasons.append("initial_orientation")
    if probes:
        reasons.append("probe_due")
    if changes["phase_changed"]:
        reasons.append("semantic_phase_change")
    if changes["newly_visible_or_reclassified"] or changes["no_longer_listed_or_now_occluded"]:
        reasons.append("object_set_change")
    tokens = row["audio"]["new_phonetic_portuguese_tokens"]
    if any(token.get("segment_completed") for token in tokens):
        reasons.append("heard_phrase_completed")
    if speech_runtime.active_utterance_id is not None and len(pending_rows) >= int(
        runtime_config.get("max_active_utterance_defer_ticks", 2)
    ):
        reasons.append("unfinished_utterance_due")
    if len(pending_rows) >= int(runtime_config.get("max_defer_ticks", 10)):
        reasons.append("maximum_defer_reached")
    if previous_row is not None:
        current_metrics = row["visual"]["interval_metrics"]
        previous_metrics = previous_row["visual"]["interval_metrics"]
        levels = {"low": 0, "moderate": 1, "high": 2}
        if levels[current_metrics["visual_change_level"]] > levels[previous_metrics["visual_change_level"]]:
            reasons.append("visual_change_level_rise")
        if abs(current_metrics["mean_luminance"] - previous_metrics["mean_luminance"]) >= float(
            runtime_config.get("luminance_jump_threshold", 18)
        ):
            reasons.append("luminance_jump")
    if retry_pending_failure:
        reasons.append("retry_pending_model_failure")
    if final_tick:
        reasons.append("final_boundary_flush")
    return list(dict.fromkeys(reasons))


def fallback_speech(tick: int, active_utterance_id: str | None) -> dict[str, Any]:
    return {
        "action": "silence",
        "utterance_id": active_utterance_id,
        "new_words": "",
        "continues_after_tick": active_utterance_id is not None,
        "reason_codes": ["model_or_protocol_failure"],
        "based_on_ticks": [tick],
    }


def timing_value(timings: Mapping[str, Any], *names: str) -> float:
    for name in names:
        value = timings.get(name)
        if isinstance(value, (int, float)):
            return float(value)
    return 0.0


def aligned_speech(events: list[dict[str, Any]]) -> list[dict[str, Any]]:
    aligned: list[dict[str, Any]] = []
    by_id: dict[str, dict[str, Any]] = {}
    for event in events:
        text = event["new_words"]
        utterance_id = event["utterance_id"]
        if not text or utterance_id is None:
            continue
        if event["action"] in {"say", "interrupt", "repair"}:
            aligned.append({
                "utterance_id": utterance_id,
                "start_tick": event["tick"],
                "end_tick": event["tick"],
                "text": text,
                "terminal_action": event["action"],
            })
            continue
        record = by_id.get(utterance_id)
        if record is None:
            record = {
                "utterance_id": utterance_id,
                "start_tick": event["tick"],
                "end_tick": event["tick"],
                "fragments": [],
                "terminal_action": None,
            }
            by_id[utterance_id] = record
            aligned.append(record)
        record["end_tick"] = event["tick"]
        record["fragments"].append(text)
        if event["action"] == "finish":
            record["terminal_action"] = "finish"
    for record in aligned:
        fragments = record.pop("fragments", None)
        if fragments is not None:
            record["text"] = " ".join(fragments)
            if record["terminal_action"] is None:
                record["terminal_action"] = "unfinished"
    return aligned


def main() -> int:
    args = parse_args()
    config_path = args.config.resolve()
    config = read_json(config_path)
    input_config = config["input"]
    bootstrap_path = resolve_input(input_config["bootstrap"])
    ticks_path = resolve_input(input_config["ticks"])
    probes_path = resolve_input(input_config["probes"])
    social_events_path = resolve_input(input_config["social_events"])
    context_events_path = (
        resolve_input(input_config["context_events"])
        if "context_events" in input_config
        else None
    )
    prompt_path = resolve_input(config["cognition_prompt"])
    attention_config = config.get("attention")
    attention_enabled = isinstance(attention_config, Mapping)
    prediction_checkpoint_probe_ids = frozenset(
        str(probe_id)
        for probe_id in (
            attention_config.get("prediction_checkpoint_probe_ids", [])
            if attention_enabled else []
        )
    )
    attention_bootstrap_path: Path | None = None
    personality_path: Path | None = None
    if attention_enabled:
        attention_bootstrap_path = resolve_input(attention_config["bootstrap"])
        personality_path = resolve_input(attention_config["personality"])
    elif not args.dry_run:
        raise SystemExit(
            "neural execution requires an attention config because structured model context is forbidden"
        )
    required_paths = [
        bootstrap_path,
        ticks_path,
        probes_path,
        social_events_path,
        prompt_path,
    ]
    if context_events_path is not None:
        required_paths.append(context_events_path)
    required_paths.extend(
        path for path in (attention_bootstrap_path, personality_path) if path is not None
    )
    for path in required_paths:
        if not path.is_file():
            raise SystemExit(f"required run input not found: {path}")

    bootstrap = read_json(bootstrap_path)
    tape = jsonl_rows(ticks_path)
    public_probes = probes_by_tick(jsonl_rows(probes_path))
    social_events = social_messages_by_tick(jsonl_rows(social_events_path))
    context_events = (
        context_events_by_tick(jsonl_rows(context_events_path))
        if context_events_path is not None
        else {}
    )
    configured_max = int(config["runtime"].get("max_ticks", len(tape)))
    max_ticks = args.max_ticks if args.max_ticks is not None else configured_max
    if max_ticks > len(tape):
        raise SystemExit(f"requested {max_ticks} ticks but tape has only {len(tape)}")
    mode = args.mode or config["runtime"].get("mode", "offline")
    run_id = config["run_id"]
    output_root = resolve_input(config["output_root"])
    out = args.out.resolve() if args.out else output_root / run_id
    out.mkdir(parents=True, exist_ok=False)

    write_json(out / "resolved-config.json", config)
    input_manifest = {
        "config": {"path": str(config_path), "sha256": file_hash(config_path)},
        "bootstrap": {"path": str(bootstrap_path), "sha256": file_hash(bootstrap_path)},
        "ticks": {"path": str(ticks_path), "sha256": file_hash(ticks_path)},
        "probes": {"path": str(probes_path), "sha256": file_hash(probes_path)},
        "social_events": {
            "path": str(social_events_path),
            "sha256": file_hash(social_events_path),
        },
        "cognition_prompt": {"path": str(prompt_path), "sha256": file_hash(prompt_path)},
        "oracle_opened_by_runner": False,
    }
    if context_events_path is not None:
        input_manifest["context_events"] = {
            "path": str(context_events_path),
            "sha256": file_hash(context_events_path),
        }
    if attention_enabled:
        assert attention_bootstrap_path is not None and personality_path is not None
        input_manifest["attention_bootstrap"] = {
            "path": str(attention_bootstrap_path),
            "sha256": file_hash(attention_bootstrap_path),
        }
        input_manifest["personality"] = {
            "path": str(personality_path),
            "sha256": file_hash(personality_path),
        }
    write_json(out / "input-manifest.json", input_manifest)

    runtime_config = config["runtime"]
    run_lock = acquire_exclusive_run_lock(
        Path(runtime_config.get("exclusive_lock_path", "/tmp/csbc-stream-run.lock"))
    )
    if attention_enabled and runtime_config.get("scheduler") != "every_tick":
        raise SystemExit("attention runs require exactly one admitted model call per tick")
    speech_runtime = SpeechRuntime(
        words_per_minute=float(runtime_config.get("speech_words_per_minute", 165)),
        max_pending_fragments=int(runtime_config.get("max_pending_speech_fragments", 1)),
    )
    state = deepcopy(bootstrap["state"])
    context_word_limit = int(attention_config["context_words"]) if attention_enabled else 0
    if attention_enabled and not 6 <= context_word_limit <= 1000:
        raise SystemExit("attention.context_words must be between 6 and 1000")
    attention_filter: AttentionFilter | None = None
    carried_context = ""
    if attention_enabled:
        assert attention_bootstrap_path is not None
        snapshot = AttentionSnapshot(
            version=str(attention_config["weight_version"]),
            weights=dict(attention_config["weights"]),
            forget_below=float(attention_config["forget_below"]),
            channel_overrides=dict(attention_config.get("channel_overrides", {})),
        )
        attention_filter = AttentionFilter(
            context_word_limit=context_word_limit,
            initial_context=attention_bootstrap_path.read_text(encoding="utf-8"),
            snapshot=snapshot,
        )
        carried_context = attention_filter.filter(1)
    initial_state_hash = object_hash(
        attention_filter.state_snapshot()
        if attention_filter is not None
        else state
    )
    state_version = 0
    adapter: LegacyStructuredAdapter | CognitionAdapter | None = None
    if not args.dry_run:
        model_config = config["model"]
        client = OpenAIJsonAdapter(
            base_url=model_config["base_url"],
            model=model_config["name"],
            seed=int(config.get("seed", 42)),
            temperature=float(model_config.get("temperature", 0.2)),
            top_k=int(model_config.get("top_k", 20)),
            request_timeout_seconds=float(model_config.get("request_timeout_seconds", 180)),
        )
        if attention_enabled:
            assert personality_path is not None
            adapter = CognitionAdapter(client, prompt_path, personality_path)
        else:
            adapter = LegacyStructuredAdapter(client, prompt_path)

    events: list[dict[str, Any]] = []
    action_counts: Counter[str] = Counter()
    proposed_action_counts: Counter[str] = Counter()
    motor_inhibition_counts: Counter[str] = Counter()
    cognition_inhibition_counts: Counter[str] = Counter()
    cognition_invalidation_counts: Counter[str] = Counter()
    cognition_expiration_counts: Counter[str] = Counter()
    violation_counts: Counter[str] = Counter()
    total_prompt_tokens = total_cached_tokens = total_completion_tokens = 0
    total_model_wall_ms = 0.0
    first_token_ms_values: list[float] = []
    total_client_generation_ms = 0.0
    total_client_generation_token_intervals = 0
    model_calls = failed_model_calls = 0
    consecutive_failures = 0
    aborted_reason: str | None = None
    max_consecutive_failures = int(runtime_config.get("max_consecutive_model_failures", 3))
    max_state_bytes = int(runtime_config.get("max_state_bytes", 32_768))
    max_output_tokens = int(config["model"].get("max_output_tokens", 768))
    routine_output_tokens = int(config["model"].get("routine_output_tokens", max_output_tokens))
    scheduler = runtime_config.get("scheduler", "every_tick")
    pending_rows: list[dict[str, Any]] = []
    pending_probes: list[dict[str, Any]] = []
    previous_row: dict[str, Any] | None = None
    admitted_ticks = skipped_neural_ticks = 0
    route_reason_counts: Counter[str] = Counter()
    context_word_counts: list[int] = []
    run_started_ns = monotonic_ns()
    previous_duration_ms = 0

    handles = {
        name: (out / filename).open("w", encoding="utf-8")
        for name, filename in {
            "runtime": "runtime-ticks.jsonl",
            "state": "state-events.jsonl",
            "speech": "speech-events.jsonl",
            "probe": "probe-answers.jsonl",
            "model": "model-events.jsonl",
            "input": "input-events.jsonl",
            "attention": "attention-events.jsonl",
        }.items()
    }
    if attention_enabled:
        handles["model_input"] = (out / "model-inputs.txt").open("w", encoding="utf-8")
    try:
        for row in tape[:max_ticks]:
            tick = row["tick"]
            if tick > 1:
                speech_runtime.advance(previous_duration_ms)
            previous_duration_ms = row["interval"]["duration_ms"]

            if mode == "paced":
                scheduled_ns = run_started_ns + int(row["interval"]["end_ms"] * 1_000_000)
                wait_ns = scheduled_ns - monotonic_ns()
                if wait_ns > 0:
                    sleep(wait_ns / 1_000_000_000)
            else:
                scheduled_ns = monotonic_ns()
            started_ns = monotonic_ns()
            decision_started = perf_counter()
            tick_probes = public_probes.get(tick, [])
            tick_social_messages = social_events.get(tick, [])
            tick_context_events = context_events.get(tick, [])
            pending_rows.append(row)
            pending_probes.extend(tick_probes)
            route_reasons = neural_route(
                row=row,
                probes=pending_probes,
                pending_rows=pending_rows,
                speech_runtime=speech_runtime,
                previous_row=previous_row,
                runtime_config=runtime_config,
                final_tick=tick == tape[max_ticks - 1]["tick"],
                retry_pending_failure=consecutive_failures > 0,
            )
            admit_neural = bool(route_reasons)
            route_reason_counts.update(route_reasons)
            if admit_neural:
                admitted_ticks += 1
            else:
                skipped_neural_ticks += 1
            due_probes = (
                list(tick_probes) if attention_enabled
                else list(pending_probes) if admit_neural
                else []
            )
            state_hash_before = object_hash(
                attention_filter.state_snapshot()
                if attention_filter is not None
                else state
            )
            input_version = state_version
            speech_view = speech_runtime.view()
            if attention_enabled:
                model_input: object = render_cognition_input(
                    attention_summary=carried_context,
                    row=row,
                    speech_runtime=speech_view,
                    probes=due_probes,
                    social_messages=tick_social_messages,
                    context_events=tick_context_events,
                )
            else:
                if scheduler == "every_tick":
                    evidence_input: dict[str, Any] = {"stimulus": row}
                else:
                    evidence_input = {"evidence_batch": compress_evidence(pending_rows)}
                model_input = {
                    "state_version": input_version,
                    "prior_state": state,
                    **evidence_input,
                    "speech_runtime": speech_view,
                    "probes_due_now": due_probes,
                    "scheduler": {
                        "condition": scheduler,
                        "neural_update_admitted": admit_neural,
                        "route_reasons": route_reasons,
                    },
                }
            input_hash = object_hash(model_input)
            if attention_enabled:
                assert isinstance(model_input, str)
                handles["model_input"].write(
                    f"Tick {tick}\n{model_input}\n\nEnd tick {tick}\n\n"
                )
                handles["model_input"].flush()
            write_jsonl_row(handles["input"], {
                "tick": tick,
                "input_hash": input_hash,
                "state_hash": state_hash_before,
                "stimulus_hash": object_hash(row),
                "probe_ids": [probe["id"] for probe in due_probes],
                "social_message_ids": [message["id"] for message in tick_social_messages],
                "context_event_ids": [event["id"] for event in tick_context_events],
                "neural_update_admitted": admit_neural,
                "route_reasons": route_reasons,
                "pending_evidence_from_tick": tick if attention_enabled else pending_rows[0]["tick"],
                "pending_evidence_tick_count": 1 if attention_enabled else len(pending_rows),
                "attention_summary_words": word_count(carried_context) if attention_enabled else None,
                "context_word_limit": context_word_limit if attention_enabled else None,
                "oracle_opened": False,
            })

            model_result: JsonModelResult | TextModelResult | None = None
            model_error: str | None = None
            if not admit_neural:
                decision = scheduler_silence(tick, speech_runtime.active_utterance_id)
            elif args.dry_run:
                decision = dry_decision(tick, due_probes)
            else:
                model_calls += 1
                try:
                    assert adapter is not None
                    salient_reasons = {
                        "probe_due",
                        "semantic_phase_change",
                        "object_set_change",
                        "initial_orientation",
                        "final_boundary_flush",
                    }
                    output_budget = (
                        max_output_tokens
                        if salient_reasons.intersection(route_reasons)
                        else routine_output_tokens
                    )
                    if attention_enabled:
                        assert isinstance(adapter, CognitionAdapter)
                        assert isinstance(model_input, str)
                        model_result = adapter.decide(model_input, output_budget)
                        parsed = parse_cognition_response(
                            model_result.text,
                            tick=tick,
                            probes=due_probes,
                        )
                        decision = {
                            "updates": parsed.cognitive_updates,
                            "speech": parsed.speech,
                            "probe_answers": parsed.probe_answers,
                        }
                    else:
                        assert isinstance(adapter, LegacyStructuredAdapter)
                        model_result = adapter.decide(model_input, output_budget)
                        decision = dict(model_result.value)
                        validate_structured_shape(decision)
                    consecutive_failures = 0
                except Exception as exc:  # preserve the partial run and stop repeated server failures
                    failed_model_calls += 1
                    consecutive_failures += 1
                    model_error = f"{type(exc).__name__}: {exc}"
                    if isinstance(exc, ModelResponseError):
                        model_result = exc.result
                    decision = {
                        "updates": [],
                        "speech": fallback_speech(tick, speech_runtime.active_utterance_id),
                        "probe_answers": [],
                    }

            if model_result is not None:
                total_prompt_tokens += model_result.prompt_tokens
                total_cached_tokens += model_result.cached_tokens
                total_completion_tokens += model_result.completion_tokens
                total_model_wall_ms += model_result.wall_ms
                if model_result.first_token_ms is not None:
                    first_token_ms_values.append(model_result.first_token_ms)
                if (
                    model_result.first_token_ms is not None
                    and model_result.last_token_ms is not None
                    and model_result.last_token_ms > model_result.first_token_ms
                ):
                    total_client_generation_ms += (
                        model_result.last_token_ms - model_result.first_token_ms
                    )
                    total_client_generation_token_intervals += max(
                        0, model_result.completion_tokens - 1
                    )
            client_generation_ms = (
                model_result.last_token_ms - model_result.first_token_ms
                if model_result is not None
                and model_result.first_token_ms is not None
                and model_result.last_token_ms is not None
                else None
            )
            client_generation_tps = (
                1000 * max(0, model_result.completion_tokens - 1) / client_generation_ms
                if model_result is not None
                and client_generation_ms is not None
                and client_generation_ms > 0
                else None
            )
            write_jsonl_row(handles["model"], {
                "tick": tick,
                "input_hash": input_hash,
                "admitted": admit_neural,
                "route_reasons": route_reasons,
                "error": model_error,
                "raw_content": model_result.raw_content if model_result else None,
                "prompt_tokens": model_result.prompt_tokens if model_result else 0,
                "cached_tokens": model_result.cached_tokens if model_result else 0,
                "completion_tokens": model_result.completion_tokens if model_result else 0,
                "wall_ms": model_result.wall_ms if model_result else 0.0,
                "first_token_ms": model_result.first_token_ms if model_result else None,
                "last_token_ms": model_result.last_token_ms if model_result else None,
                "client_generation_ms": client_generation_ms,
                "client_generation_tokens_per_second": client_generation_tps,
                "timings": dict(model_result.timings) if model_result else {},
            })

            tick_violations: list[str] = []
            update_error: str | None = None
            try:
                if attention_enabled:
                    cognitive_updates = decision.get("updates", [])
                    if cognitive_updates:
                        future_ticks = future_tick_references(
                            ({"value": update.get("text", "")} for update in cognitive_updates),
                            tick,
                        )
                        if future_ticks:
                            raise ValueError(
                                f"cognitive updates cite future ticks: {future_ticks}"
                            )
                    state_changed = False
                    changed_components = []
                    update_paths = []
                else:
                    future_ticks = future_tick_references(decision["updates"], tick)
                    if future_ticks:
                        raise ValueError(f"state updates cite future ticks: {future_ticks}")
                    candidate_state, update_paths, candidate_components = apply_updates(
                        state, decision["updates"]
                    )
                    validate_state_shape(candidate_state)
                    candidate_state_bytes = len(canonical_json(candidate_state).encode("utf-8"))
                    if candidate_state_bytes > max_state_bytes:
                        raise ValueError(
                            f"state byte bound exceeded: {candidate_state_bytes} > {max_state_bytes}"
                        )
                    state_changed = candidate_state != state
                    if state_changed:
                        state = candidate_state
                        state_version += 1
                        changed_components = candidate_components
                    else:
                        changed_components = []
                    tick_violations.extend(behavioral_state_violations(tick, update_paths, state))
            except (KeyError, TypeError, ValueError) as exc:
                update_error = str(exc)
                update_paths = []
                changed_components = []
                state_changed = False
                tick_violations.append(
                    "state_bound_exceeded"
                    if "bound exceeded" in update_error
                    else "invalid_state_update"
                )

            probe_answers = decision.get("probe_answers", [])
            if not isinstance(probe_answers, list):
                probe_answers = []
                tick_violations.append("probe_answers_not_array")
            tick_violations.extend(validate_probe_answers(probe_answers, due_probes, tick))
            for answer in probe_answers:
                write_jsonl_row(handles["probe"], {"tick": tick, **answer})

            for message in tick_social_messages:
                speech_runtime.observe_external_phrase(str(message["text"]))
            for token in row["audio"]["new_phonetic_portuguese_tokens"]:
                speech_runtime.observe_external_phrase(str(token["token"]))
                if token.get("assembled_heard_as"):
                    speech_runtime.observe_external_phrase(str(token["assembled_heard_as"]))
            try:
                speech_event, speech_violations = speech_runtime.commit(
                    decision["speech"],
                    tick,
                )
            except (KeyError, TypeError, ValueError) as exc:
                tick_violations.append(f"invalid_speech_decision:{exc}")
                speech_event, speech_violations = speech_runtime.commit(
                    fallback_speech(tick, speech_runtime.active_utterance_id), tick
                )
            tick_violations.extend(speech_violations)
            cognition_inhibitions: list[dict[str, str]] = []
            cognition_invalidations: list[str] = []
            cognition_expirations: list[str] = []
            cognition_grounded_changes: list[str] = []
            cognition_model_changes: list[str] = []
            prediction_commitment_ids: list[str] = []
            if attention_enabled:
                try:
                    assert attention_filter is not None
                    cognition_transition = attention_filter.observe_cognition(
                        tick,
                        decision.get("updates", []),
                        tick_context_events,
                    )
                    cognition_changed = cognition_transition.changed_slots
                    cognition_inhibitions = cognition_transition.inhibited_updates
                    cognition_invalidations = cognition_transition.invalidated_slots
                    cognition_expirations = cognition_transition.expired_slots
                    cognition_grounded_changes = cognition_transition.grounded_changed_slots
                    cognition_model_changes = cognition_transition.model_changed_slots
                    cognition_inhibition_counts.update(
                        item["reason"] for item in cognition_inhibitions
                    )
                    cognition_invalidation_counts.update(cognition_invalidations)
                    cognition_expiration_counts.update(cognition_expirations)
                    attention_filter.observe(row)
                    attention_filter.observe_context_events(tick, tick_context_events)
                    attention_filter.observe_social_messages(tick, tick_social_messages)
                    attention_filter.observe_speech(tick, speech_event["new_words"])
                    prediction_commitment_answers = [
                        answer
                        for answer in probe_answers
                        if answer.get("id") in prediction_checkpoint_probe_ids
                    ]
                    attention_filter.observe_prediction_commitments(
                        tick,
                        prediction_commitment_answers,
                    )
                    prediction_commitment_ids = [
                        str(answer["id"])
                        for answer in prediction_commitment_answers
                    ]
                    candidate_context = attention_filter.filter(tick + 1)
                    candidate_words = word_count(candidate_context)
                    if candidate_words > context_word_limit:
                        raise ValueError(
                            f"attention context word bound exceeded: {candidate_words} > {context_word_limit}"
                        )
                    future_ticks = future_tick_references(
                        [{"path": "/working_narrative", "value": candidate_context}], tick
                    )
                    if future_ticks:
                        raise ValueError(f"attention context cites future ticks: {future_ticks}")
                    context_changed = candidate_context != carried_context
                    state_changed = context_changed or bool(cognition_changed)
                    if state_changed:
                        carried_context = candidate_context
                        state_version += 1
                        changed_components = [
                            *(f"cognition:{slot}" for slot in cognition_changed),
                            *(["attention"] if context_changed else []),
                        ]
                        update_paths = [
                            *(f"/cognition/{slot.lower().replace(' ', '_')}" for slot in cognition_changed),
                            *(["/attention_summary"] if context_changed else []),
                        ]
                    else:
                        changed_components = []
                        update_paths = []
                except (KeyError, TypeError, ValueError) as exc:
                    update_error = str(exc)
                    update_paths = []
                    changed_components = []
                    state_changed = False
                    tick_violations.append(
                        "state_bound_exceeded"
                        if "bound exceeded" in update_error
                        else "invalid_state_update"
                    )
            action_counts[speech_event["action"]] += 1
            proposed_action_counts[speech_event["proposed_action"]] += 1
            if speech_event["motor_inhibited"]:
                motor_inhibition_counts[speech_event["motor_inhibition_reason"]] += 1
            violation_counts.update(tick_violations)
            speech_event.update({
                "state_version": state_version,
                "input_hash": input_hash,
                "violations": tick_violations,
            })
            events.append(speech_event)
            write_jsonl_row(handles["speech"], speech_event)

            state_hash_after = object_hash(
                attention_filter.state_snapshot()
                if attention_filter is not None
                else state
            )
            write_jsonl_row(handles["state"], {
                "tick": tick,
                "input_version": input_version,
                "output_version": state_version,
                "state_hash_before": state_hash_before,
                "state_hash_after": state_hash_after,
                "input_hash": input_hash,
                "update_paths": update_paths,
                "changed_components": changed_components,
                "model_updates": decision.get("updates", []),
                "model_changed_slots": cognition_model_changes,
                "grounded_changed_slots": cognition_grounded_changes,
                "cognition_inhibitions": cognition_inhibitions,
                "cognition_invalidations": cognition_invalidations,
                "cognition_expirations": cognition_expirations,
                "prediction_commitments_recorded": prediction_commitment_ids,
                "update_error": update_error,
                "violations": tick_violations,
            })
            if attention_enabled:
                assert attention_filter is not None
                current_words = word_count(carried_context)
                context_word_counts.append(current_words)
                write_jsonl_row(handles["attention"], {
                    "tick": tick,
                    "input_version": input_version,
                    "output_version": state_version,
                    "context_hash_before": state_hash_before,
                    "context_hash_after": state_hash_after,
                    "context_words": current_words,
                    "context_word_limit": context_word_limit,
                    "context": carried_context,
                    "candidate_facts": attention_filter.last_candidate_count,
                    "selected_facts": attention_filter.last_selected_count,
                    "candidate_facts_by_channel": attention_filter.last_candidate_by_channel,
                    "selected_facts_by_channel": attention_filter.last_selected_by_channel,
                    "weight_version": attention_filter.snapshot.version,
                    "attention_policy_version": ATTENTION_POLICY_VERSION,
                    "prediction_commitments_recorded": prediction_commitment_ids,
                    "weight_snapshot_hash": attention_filter.weight_snapshot_hash,
                    "weights_adjusted_inside_tick": False,
                    "changed": state_changed,
                    "error": update_error,
                })

            if attention_enabled:
                pending_rows.clear()
                pending_probes.clear()
            elif admit_neural and (args.dry_run or model_error is None):
                pending_rows.clear()
                pending_probes.clear()

            released_ns = monotonic_ns()
            kernel_ms = (released_ns - started_ns) / 1_000_000
            lateness_ms = (started_ns - scheduled_ns) / 1_000_000
            deadline_ns = scheduled_ns + int(bootstrap["clock"]["tick_ms"] * 1_000_000)
            completed_jobs = (
                ["state_speech_probe"]
                if admit_neural and not args.dry_run and model_error is None
                else []
            )
            cancelled_jobs = ["state_speech_probe"] if admit_neural and model_error else []
            skipped_jobs = ["state_speech_probe"] if not admit_neural or args.dry_run else []
            timings = model_result.timings if model_result else {}
            preserved = (
                [] if attention_enabled and state_changed
                else ["attention"] if attention_enabled
                else sorted(ALLOWED_STATE_ROOTS - set(changed_components))
            )
            runtime_row = {
                "run_id": run_id,
                "tick": tick,
                "mode": mode,
                "scheduler": {
                    "condition": scheduler,
                    "neural_update_admitted": admit_neural,
                    "route_reasons": route_reasons,
                },
                "boundary": {
                    "scheduled_monotonic_ns": scheduled_ns,
                    "started_monotonic_ns": started_ns,
                    "released_monotonic_ns": released_ns,
                    "kernel_duration_ms": kernel_ms,
                    "lateness_ms": lateness_ms,
                    "deadline_met": released_ns <= deadline_ns,
                },
                "state": {
                    "input_version": input_version,
                    "output_version": state_version,
                    "commit_latency_ms": (perf_counter() - decision_started) * 1000,
                    "changed_components": changed_components,
                    "preserved_components": preserved,
                    "no_semantic_change": not state_changed,
                    "affect_changed": any(
                        path.startswith("/self_theory_of_mind/affect_state")
                        or path == "/cognition/self_affect"
                        for path in update_paths
                    ),
                    "needs_changed": any(
                        path.startswith("/self_theory_of_mind/needs_state")
                        or path == "/cognition/active_needs"
                        for path in update_paths
                    ),
                    "attention_context_words": (
                        word_count(carried_context) if attention_enabled else None
                    ),
                    "attention_context_word_limit": (
                        context_word_limit if attention_enabled else None
                    ),
                },
                "neural_jobs": {
                    "admitted": ["state_speech_probe"] if admit_neural and not args.dry_run else [],
                    "active": [],
                    "completed": completed_jobs,
                    "cancelled": cancelled_jobs,
                    "skipped": skipped_jobs,
                    "backlog_depth": 0,
                    "totals": {
                        "calls": 1 if admit_neural and not args.dry_run else 0,
                        "input_tokens": model_result.prompt_tokens if model_result else 0,
                        "cached_tokens": model_result.cached_tokens if model_result else 0,
                        "output_tokens": model_result.completion_tokens if model_result else 0,
                        "queue_ms": timing_value(timings, "queue_ms"),
                        "prefill_ms": timing_value(timings, "prompt_ms", "prompt_time_ms"),
                        "decode_ms": timing_value(timings, "predicted_ms", "generation_ms"),
                        "wall_ms": model_result.wall_ms if model_result else 0.0,
                    },
                },
                "speech": {
                    "action": speech_event["action"],
                    "new_words": speech_event["new_words"],
                    "utterance_id": speech_event["utterance_id"],
                    "proposed_action": speech_event["proposed_action"],
                    "proposed_words": speech_event["proposed_words"],
                    "motor_inhibited": speech_event["motor_inhibited"],
                    "motor_inhibition_reason": speech_event["motor_inhibition_reason"],
                    "decision_latency_ms": (perf_counter() - decision_started) * 1000,
                    "queue_depth": speech_event["queue_depth"],
                    "queued_audio_ms": len(speech_runtime.queue) * 60_000 / speech_runtime.words_per_minute,
                    "currently_audible": speech_event["currently_audible"],
                    "audible_text_so_far": speech_event["audible_text_so_far"],
                    "interrupted_utterance_id": None,
                },
                "hardware": {
                    "sample_available": False,
                    "cpu_utilization": None,
                    "accelerator_utilization": None,
                    "memory_bytes": None,
                    "power_watts": None,
                    "energy_joules_since_previous_tick": None,
                },
                "violations": tick_violations,
            }
            write_jsonl_row(handles["runtime"], runtime_row)

            if args.progress_every and tick % args.progress_every == 0:
                print(
                    f"tick={tick} admitted={int(admit_neural)} state_version={state_version} action={speech_event['action']} "
                    f"model_wall_ms={runtime_row['neural_jobs']['totals']['wall_ms']:.1f}",
                    flush=True,
                )
            if not args.dry_run and consecutive_failures >= max_consecutive_failures:
                aborted_reason = f"aborted after {consecutive_failures} consecutive model failures at tick {tick}"
                break
            previous_row = row
    finally:
        for handle in handles.values():
            handle.close()

    aligned = aligned_speech(events)
    with (out / "speech-aligned.jsonl").open("w", encoding="utf-8") as handle:
        for row in aligned:
            write_jsonl_row(handle, row)
    transcript = speech_runtime.committed_transcript()
    (out / "speech-transcript.txt").write_text(transcript + ("\n" if transcript else ""), encoding="utf-8")
    if attention_enabled:
        assert attention_filter is not None
        (out / "attention-summary.txt").write_text(carried_context + "\n", encoding="utf-8")
        write_json(out / "final-state.json", {
            "transition": "non_neural_attention_filter",
            "attention_summary": carried_context,
            "summary_words": word_count(carried_context),
            "context_word_limit": context_word_limit,
            "attention_facts": attention_filter.state_snapshot(),
        })
    else:
        write_json(out / "final-state.json", state)

    processed_ticks = len(events)
    one_model_call_per_tick = args.dry_run or not attention_enabled or model_calls == processed_ticks
    if not one_model_call_per_tick:
        aborted_reason = (
            f"attention call-count invariant failed: {model_calls} calls for "
            f"{processed_ticks} processed ticks"
        )
    runtime_rows = jsonl_rows(out / "runtime-ticks.jsonl")
    deadlines_met = sum(row["boundary"]["deadline_met"] for row in runtime_rows)
    run_summary = {
        "schema_version": "1.0.0",
        "run_id": run_id,
        "status": "aborted" if aborted_reason else "complete",
        "aborted_reason": aborted_reason,
        "dry_run": args.dry_run,
        "mode": mode,
        "host": socket.gethostname(),
        "platform": platform.platform(),
        "ticks_requested": max_ticks,
        "ticks_processed": processed_ticks,
        "initial_state_hash": initial_state_hash,
        "final_state_hash": object_hash(
            attention_filter.state_snapshot()
            if attention_filter is not None
            else state
        ),
        "final_state_version": state_version,
        "model": {
            "name": config["model"]["name"],
            "calls": model_calls,
            "failed_calls": failed_model_calls,
            "prompt_tokens": total_prompt_tokens,
            "cached_tokens": total_cached_tokens,
            "completion_tokens": total_completion_tokens,
            "wall_ms": total_model_wall_ms,
            "first_token_ms_mean": (
                sum(first_token_ms_values) / len(first_token_ms_values)
                if first_token_ms_values else None
            ),
            "first_token_ms_p50": percentile(first_token_ms_values, 0.5),
            "first_token_ms_p95": percentile(first_token_ms_values, 0.95),
            "client_generation_tokens_per_second": (
                1000 * total_client_generation_token_intervals / total_client_generation_ms
                if total_client_generation_ms > 0 else None
            ),
            "wall_tokens_per_second": (
                1000 * total_completion_tokens / total_model_wall_ms
                if total_model_wall_ms > 0 else None
            ),
        },
        "speech": {
            "action_counts": dict(action_counts),
            "proposed_action_counts": dict(proposed_action_counts),
            "motor_inhibition_counts": dict(motor_inhibition_counts),
            "committed_words": len(transcript.split()),
            "aligned_utterances": len(aligned),
            "unfinished_utterance_id": speech_runtime.active_utterance_id,
            "queued_words_at_freeze": len(speech_runtime.queue),
        },
        "cognition_control": {
            "inhibition_counts": dict(cognition_inhibition_counts),
            "invalidation_counts": dict(cognition_invalidation_counts),
            "expiration_counts": dict(cognition_expiration_counts),
        },
        "runtime": {
            "deadlines_met": deadlines_met,
            "deadline_fraction": deadlines_met / processed_ticks if processed_ticks else 0,
        },
        "scheduler": {
            "condition": scheduler,
            "neural_admitted_ticks": admitted_ticks,
            "neural_skipped_ticks": skipped_neural_ticks,
            "neural_skip_fraction": skipped_neural_ticks / processed_ticks if processed_ticks else 0,
            "route_reason_counts": dict(route_reason_counts),
            "unflushed_evidence_ticks": len(pending_rows),
            "unanswered_pending_probes": [probe["id"] for probe in pending_probes],
        },
        "attention": (
            {
                "enabled": True,
                "one_model_call_per_processed_tick": one_model_call_per_tick,
                "model_calls": model_calls,
                "processed_ticks": processed_ticks,
                "context_word_limit": context_word_limit,
                "weight_version": attention_filter.snapshot.version if attention_filter else None,
                "attention_policy_version": ATTENTION_POLICY_VERSION,
                "weight_snapshot_hash": attention_filter.weight_snapshot_hash if attention_filter else None,
                "weights_adjusted_inside_tick": False,
                "initial_context_words": word_count(
                    attention_bootstrap_path.read_text(encoding="utf-8")
                ) if attention_bootstrap_path is not None else 0,
                "final_summary_words": word_count(carried_context),
                "maximum_summary_words": max(context_word_counts, default=word_count(carried_context)),
                "mean_summary_words": (
                    sum(context_word_counts) / len(context_word_counts)
                    if context_word_counts else word_count(carried_context)
                ),
            }
            if attention_enabled
            else {"enabled": False}
        ),
        "protocol_violations": dict(violation_counts),
        "information_boundary": {
            "oracle_opened_by_runner": False,
            "public_probe_file": str(probes_path),
        },
        "artifacts": {},
    }
    for path in sorted(out.iterdir()):
        if path.is_file() and path.name != "run.json":
            run_summary["artifacts"][path.name] = {
                "bytes": path.stat().st_size,
                "sha256": file_hash(path),
            }
    write_json(out / "run.json", run_summary)
    run_lock.close()
    print(json.dumps({
        "status": run_summary["status"],
        "run_dir": str(out),
        "ticks_processed": processed_ticks,
        "state_version": state_version,
        "committed_words": run_summary["speech"]["committed_words"],
        "protocol_violations": sum(violation_counts.values()),
        "oracle_opened_by_runner": False,
    }, indent=2))
    return 2 if aborted_reason else 0


if __name__ == "__main__":
    raise SystemExit(main())
