#!/usr/bin/env python3
"""Freeze, audit, and optionally model-score a completed visual-stream run."""

from __future__ import annotations

from argparse import ArgumentParser
from collections import Counter
from hashlib import sha256
from pathlib import Path
from typing import Any
import json
import re
import sys


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))

from cognition_model_adapter import (  # noqa: E402
    FrozenRunJudgeAdapter,
    JUDGE_DIMENSIONS,
    OpenAIJsonAdapter,
)
from stream_runtime import jsonl_rows, word_count  # noqa: E402


DIMENSION_DESCRIPTIONS = {
    "whole_stream_coherence": "One persistent speaker rather than unrelated captions.",
    "referential_continuity": "Pronouns and phrases keep understandable, temporally valid referents.",
    "temporal_honesty": "Claims were supportable when spoken and preserve uncertainty.",
    "natural_social_voice": "A casual reaction to a friend, with useful silence, not a lab narration.",
    "responsiveness": "Salient changes redirect or interrupt speech promptly.",
    "belief_continuity_and_repair": "Predictions persist, then revise audibly when contradicted.",
    "surprise_calibration": "Reaction rises at the reversal because a real prior prediction existed.",
    "emotional_continuity": "Evidence-grounded affect has inertia rather than resetting each tick.",
    "need_guided_control": "Attention and speech reflect epistemic, safety, social, and interruptibility needs.",
    "compression_and_rhythm": "Selective, speakable fragments and pauses rather than frantic description.",
}
KEY_REFERENCE_TICKS = {1, 22, 23, 30, 46, 58, 77, 101, 190, 248, 280, 300, 316, 322, 400, 448, 463, 491}
PREMATURE_REVEAL_RE = re.compile(
    r"\b(?:put(?:s|ting)?\s+(?:his\s+head|himself)|climb(?:s|ing)?|go(?:es|ing)?)\b.{0,28}\b(?:in|inside|into)\b.{0,12}\bbag\b",
    re.IGNORECASE,
)


def parse_args() -> Any:
    parser = ArgumentParser()
    parser.add_argument("--run", type=Path, required=True)
    parser.add_argument("--judge", action="store_true")
    parser.add_argument("--recognition", action="store_true")
    parser.add_argument("--base-url")
    parser.add_argument("--model")
    parser.add_argument("--seed", type=int)
    parser.add_argument("--request-timeout-seconds", type=float)
    return parser.parse_args()


def read_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def file_hash(path: Path) -> str:
    digest = sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def verify_frozen_artifacts(run_dir: Path, run: dict[str, Any]) -> None:
    for name, expected in run.get("artifacts", {}).items():
        path = run_dir / name
        if not path.is_file():
            raise ValueError(f"frozen run artifact is missing: {name}")
        if path.stat().st_size != expected["bytes"] or file_hash(path) != expected["sha256"]:
            raise ValueError(f"frozen run artifact changed after run.json was written: {name}")


def deterministic_audit(
    run: dict[str, Any],
    speech: list[dict[str, Any]],
    probes: list[dict[str, Any]],
    oracle: list[dict[str, Any]],
) -> dict[str, Any]:
    flags: list[str] = []
    protocol_counts = Counter(run.get("protocol_violations", {}))
    flag_violation_names = {
        "person_or_other_mind_created_before_tick_23",
        "speech_mentions_person_before_creation_threshold",
        "speech_more_than_8_words",
        "speech_more_than_1_sentence",
        "speech_queue_exceeds_fragment_limit",
    }
    flags.extend(sorted(name for name in flag_violation_names if protocol_counts[name]))

    expected_probes = {
        probe["id"]: row["tick"]
        for row in oracle
        if row["tick"] <= run["ticks_processed"]
        for probe in row["scheduled_probes"]
    }
    actual_probes = {answer["id"]: answer["tick"] for answer in probes}
    missing = sorted(set(expected_probes) - set(actual_probes))
    extra = sorted(set(actual_probes) - set(expected_probes))
    wrong_tick = sorted(
        probe_id
        for probe_id in set(expected_probes) & set(actual_probes)
        if expected_probes[probe_id] != actual_probes[probe_id]
    )
    if missing:
        flags.append("missing_required_probe_answers")
    if extra:
        flags.append("answered_unscheduled_probes")
    if wrong_tick:
        flags.append("probe_answered_at_wrong_tick")

    tick1 = next((answer for answer in probes if answer["id"] == "probe.camera.frame1"), None)
    if tick1 and "unknown" not in tick1.get("answer", "").lower():
        flags.append("definite_camera_operator_at_tick_1")

    early_speech = " ".join(event["new_words"] for event in speech if event["tick"] < 316)
    if PREMATURE_REVEAL_RE.search(early_speech):
        flags.append("bag_entry_outcome_spoken_before_supporting_motion")

    non_silent = [event for event in speech if event["new_words"]]
    queue_peak = max((event.get("queue_depth", 0) for event in speech), default=0)
    transcript = " ".join(event["new_words"] for event in non_silent).strip()
    processed_ticks = run["ticks_processed"]
    return {
        "hard_failure_flags": list(dict.fromkeys(flags)),
        "probe_coverage": {
            "expected": len(expected_probes),
            "answered": len(set(expected_probes) & set(actual_probes)),
            "missing_ids": missing,
            "extra_ids": extra,
            "wrong_tick_ids": wrong_tick,
        },
        "speech_metrics": {
            "processed_ticks": processed_ticks,
            "non_silent_ticks": len(non_silent),
            "proposed_non_silent_ticks": sum(
                1 for event in speech if event.get("proposed_words")
            ),
            "motor_inhibited_ticks": sum(
                1 for event in speech if event.get("motor_inhibited")
            ),
            "silent_tick_fraction": 1 - len(non_silent) / processed_ticks if processed_ticks else 0,
            "committed_words": word_count(transcript),
            "words_per_clip_minute": word_count(transcript) / (68.733333 / 60) if processed_ticks == 491 else None,
            "queue_depth_peak": queue_peak,
            "action_counts": dict(Counter(event["action"] for event in speech)),
        },
        "runtime_metrics": run.get("runtime", {}),
        "protocol_violation_counts": dict(protocol_counts),
    }


def judge_payload(
    run: dict[str, Any],
    transcript: str,
    speech: list[dict[str, Any]],
    probes: list[dict[str, Any]],
    oracle: list[dict[str, Any]],
    audit: dict[str, Any],
) -> dict[str, Any]:
    reference_rows = []
    for row in oracle:
        if row["tick"] not in KEY_REFERENCE_TICKS:
            continue
        reference_rows.append({
            "tick": row["tick"],
            "camera_reference": row["camera_reference"],
            "self_theory_of_mind_reference": row["self_theory_of_mind_reference"],
            "viewer_affect_needs_reference": row["viewer_affect_needs_reference"],
            "other_theory_of_mind_reference": row["other_theory_of_mind_reference"],
            "scheduled_probes": row["scheduled_probes"],
            "speech_scoring_notes": row["speech_scoring_notes"],
        })
    return {
        "rubric": [
            {"dimension": name, "description": DIMENSION_DESCRIPTIONS[name]}
            for name in JUDGE_DIMENSIONS
        ],
        "run_summary": run,
        "deterministic_audit": audit,
        "frozen_transcript": transcript,
        "non_silent_speech_events": [
            {
                "tick": event["tick"],
                "action": event["action"],
                "utterance_id": event["utterance_id"],
                "new_words": event["new_words"],
                "continues_after_tick": event["continues_after_tick"],
                "violations": event.get("violations", []),
            }
            for event in speech
            if event["new_words"] or event.get("violations")
        ],
        "probe_answers": probes,
        "selected_sealed_references": reference_rows,
    }


def validate_judgment(value: dict[str, Any]) -> None:
    scores = value.get("scores")
    if not isinstance(scores, list):
        raise ValueError("judge scores missing")
    dimensions = [score.get("dimension") for score in scores if isinstance(score, dict)]
    if sorted(dimensions) != sorted(JUDGE_DIMENSIONS):
        raise ValueError("judge must return every rubric dimension exactly once")


def main() -> int:
    args = parse_args()
    if args.judge or args.recognition:
        raise SystemExit(
            "model judging is disabled until its causal input has a dedicated prose renderer"
        )
    run_dir = args.run.resolve()
    run = read_json(run_dir / "run.json")
    if run["status"] != "complete":
        raise SystemExit(f"refusing to score non-complete run: {run['status']}")
    verify_frozen_artifacts(run_dir, run)
    speech = jsonl_rows(run_dir / "speech-events.jsonl")
    probe_answers = jsonl_rows(run_dir / "probe-answers.jsonl")
    transcript = (run_dir / "speech-transcript.txt").read_text(encoding="utf-8").strip()
    oracle_path = ROOT / "data" / "oracle.jsonl"
    oracle = jsonl_rows(oracle_path)
    audit = deterministic_audit(run, speech, probe_answers, oracle)

    evaluation: dict[str, Any] = {
        "schema_version": "1.0.0",
        "run_id": run["run_id"],
        "run_status_at_freeze": run["status"],
        "oracle_opened_only_after_freeze": True,
        "oracle_path": str(oracle_path),
        "deterministic_audit": audit,
        "rubric_scores": None,
        "judge": None,
        "source_recognition": None,
    }

    judge_adapter: FrozenRunJudgeAdapter | None = None
    if args.judge or args.recognition:
        config = read_json(run_dir / "resolved-config.json")
        model_config = config.get("evaluator_model", config["model"])
        client = OpenAIJsonAdapter(
            base_url=args.base_url or model_config["base_url"],
            model=args.model or model_config["name"],
            seed=args.seed if args.seed is not None else int(config.get("seed", 42)) + 10_000,
            temperature=float(model_config.get("temperature", 0.0)),
            top_k=int(model_config.get("top_k", 1)),
            request_timeout_seconds=(
                args.request_timeout_seconds
                if args.request_timeout_seconds is not None
                else float(model_config.get("request_timeout_seconds", 180))
            ),
        )
        judge_adapter = FrozenRunJudgeAdapter(client, ROOT / "prompts" / "judge-system.txt")

    if args.judge:
        assert judge_adapter is not None
        result = judge_adapter.judge(
            judge_payload(run, transcript, speech, probe_answers, oracle, audit)
        )
        judgment = dict(result.value)
        validate_judgment(judgment)
        judgment["hard_failure_flags"] = list(dict.fromkeys(
            audit["hard_failure_flags"] + judgment["hard_failure_flags"]
        ))
        evaluation["rubric_scores"] = judgment["scores"]
        evaluation["judge"] = {
            "output": judgment,
            "prompt_tokens": result.prompt_tokens,
            "cached_tokens": result.cached_tokens,
            "completion_tokens": result.completion_tokens,
            "wall_ms": result.wall_ms,
        }

    if args.recognition:
        assert judge_adapter is not None
        recognition = judge_adapter.recognize({
            "final_state": read_json(run_dir / "final-state.json"),
            "frozen_transcript": transcript,
            "behavioral_scores_already_frozen": True,
        })
        evaluation["source_recognition"] = dict(recognition.value)
        write_json(run_dir / "source-recognition.json", {
            "isolated_after_behavioral_freeze": True,
            "result": recognition.value,
            "prompt_tokens": recognition.prompt_tokens,
            "completion_tokens": recognition.completion_tokens,
            "wall_ms": recognition.wall_ms,
        })

    write_json(run_dir / "speech-evaluation.json", evaluation)
    print(json.dumps({
        "run_id": run["run_id"],
        "evaluation": "model_judged" if args.judge else "deterministic_only",
        "hard_failure_flags": audit["hard_failure_flags"],
        "probe_coverage": audit["probe_coverage"],
        "speech_evaluation": str(run_dir / "speech-evaluation.json"),
    }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
