#!/usr/bin/env python3
"""Audit the generated fixture's chronology and information boundaries."""

from __future__ import annotations

import hashlib
import json
import math
from collections import defaultdict
from pathlib import Path
from typing import Any
import sys


ROOT = Path(__file__).resolve().parents[1]
DATA = ROOT / "data"
TICK_MS = 140
EXPECTED_TICKS = 491
EXPECTED_DURATION_MS = 68733
PERSON_CREATION_TICK = 23

sys.path.insert(0, str(ROOT / "src"))

from attention_transition import (  # noqa: E402
    COGNITION_SLOT_LEASE_TICKS,
    COGNITION_SLOTS,
    MAX_COGNITION_SLOT_WORDS,
    MAX_PREDICTION_COMMITMENTS,
    MAX_PREDICTION_COMMITMENT_WORDS,
    MAX_RECENT_SPEECH_FRAGMENTS,
    MAX_RECENT_SPEECH_WORDS,
    AttentionFilter,
    AttentionSnapshot,
    parse_cognition_response,
    render_cognition_input,
)
from stream_runtime import (  # noqa: E402
    MAX_EXTERNAL_ECHO_REFERENCES,
    SpeechRuntime,
    word_count,
)


def read_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def read_jsonl(path: Path) -> list[dict[str, Any]]:
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line]


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def check(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def main() -> None:
    manifest = read_json(DATA / "manifest.json")
    stimuli = read_jsonl(DATA / "ticks.jsonl")
    oracle = read_jsonl(DATA / "oracle.jsonl")
    public_probe_rows = read_jsonl(DATA / "probes.jsonl")
    social_event_rows = read_jsonl(DATA / "social-events.jsonl")
    context_event_rows = read_jsonl(DATA / "context-events.jsonl")
    question_bank = read_json(ROOT / "annotations" / "question-bank.json")
    heard_speech = read_json(ROOT / "annotations" / "heard-speech-pt.json")
    bootstrap = read_json(ROOT / "bootstrap.json")

    attention_capacities = [1000]
    attention_configs: dict[int, dict[str, Any]] = {}
    for capacity in attention_capacities:
        config = read_json(
            ROOT / "configs" / f"kaiju-attention-{capacity}-development.json"
        )
        attention_configs[capacity] = config
        check(config["attention"]["context_words"] == capacity, f"attention capacity {capacity}")
        check(config["runtime"]["scheduler"] == "every_tick", f"attention scheduler {capacity}")
        check(
            config["runtime"].get("exclusive_lock_path")
            == "/tmp/csbc-visual-stream-run.lock",
            f"exclusive Kaiju run lock {capacity}",
        )
        check(
            config["runtime"]["max_pending_speech_fragments"] == 1,
            f"speech motor capacity {capacity}",
        )
        check(
            {"world_model", "prediction"} <= set(config["attention"]["weights"]),
            f"cognitive state channels {capacity}",
        )
        check(
            config["attention"].get("prediction_checkpoint_probe_ids")
            == ["probe.last_clean_prediction"],
            f"prediction checkpoint declaration {capacity}",
        )
        check(
            (ROOT / config["input"]["context_events"]).is_file(),
            f"context event input {capacity}",
        )
        for relative_path in (
            config["cognition_prompt"],
            config["attention"]["bootstrap"],
            config["attention"]["personality"],
        ):
            text = (ROOT / relative_path).read_text(encoding="utf-8")
            check(not any(character in text for character in "{}[]"), f"structured delimiter in {relative_path}")

    baseline_config = attention_configs[1000]
    stronger_config = read_json(
        ROOT / "configs" / "kaiju-attention-1000-aeon27-q8-20k-development.json"
    )
    for frozen_key in ("seed", "input", "cognition_prompt", "attention", "runtime"):
        check(
            stronger_config[frozen_key] == baseline_config[frozen_key],
            f"stronger-stack condition changed frozen field {frozen_key}",
        )
    check(
        stronger_config["model"]["server_context_tokens_requested"] == 20_000,
        "stronger-stack server context is not 20,000 tokens",
    )
    check(
        (
            stronger_config["model"]["server_threads"],
            stronger_config["model"]["server_batch_tokens"],
            stronger_config["model"]["server_microbatch_tokens"],
            stronger_config["model"]["server_numa_policy"],
        ) == (24, 512, 128, "interleave-all"),
        "stronger-stack CPU execution shape changed",
    )
    check(
        stronger_config["model"]["quantization"] == "Q8_0"
        and stronger_config["model"]["source_precision"] == "BF16",
        "stronger-stack source and runtime precision are not distinguished",
    )
    check(
        stronger_config["model"]["weights_sha256"]
        == "b8c3e4d540e93d30e95e6ae8a40bda054fe47111eed7f097283b000d9321726b",
        "stronger-stack weights digest changed",
    )
    check(
        stronger_config["model"]["runtime_commit"]
        == "3f40e73c367ad9f0c1b1819f28c7348c26aa340d",
        "stronger-stack runtime revision changed",
    )

    check(len(stimuli) == EXPECTED_TICKS, f"stimulus rows: {len(stimuli)}")
    check(len(oracle) == EXPECTED_TICKS, f"oracle rows: {len(oracle)}")
    check(manifest["stream"]["tick_count"] == EXPECTED_TICKS, "manifest tick count")
    check(manifest["source"]["duration_ms"] == EXPECTED_DURATION_MS, "duration")
    check(manifest["stream"]["stimulus_sha256"] == sha256(DATA / "ticks.jsonl"), "stimulus hash")
    check(manifest["stream"]["oracle_sha256"] == sha256(DATA / "oracle.jsonl"), "oracle hash")
    check(manifest["stream"]["public_probes_sha256"] == sha256(DATA / "probes.jsonl"), "public probe hash")
    check(
        manifest["stream"]["social_events_sha256"] == sha256(DATA / "social-events.jsonl"),
        "social event hash",
    )
    check(
        manifest["stream"]["context_events_sha256"] == sha256(DATA / "context-events.jsonl"),
        "context event hash",
    )
    self_model = bootstrap["state"]["self_theory_of_mind"]
    check("affect_state" in self_model, "bootstrap viewer affect state")
    check("needs_state" in self_model, "bootstrap viewer needs state")
    initial_need_ids = {need["id"] for need in self_model["needs_state"]["active_needs"]}
    check({"understand_scene", "preserve_epistemic_honesty", "maintain_speech_coherence", "remain_interruptible", "socially_attune_to_friend"} <= initial_need_ids, "bootstrap need coverage")

    question_ids = {
        question["id"]
        for dimension in question_bank["dimensions"]
        for question in dimension["questions"]
    }
    check(len(question_ids) == manifest["annotations"]["question_count"], "question count/hash mismatch")

    frame_count = 0
    speech_tokens: dict[str, list[dict[str, Any]]] = defaultdict(list)
    previous_end = 0
    forbidden_source_markers = ["reddit", "uq16uxwdu1jh1", "wcgw disposing", "blursed bee catching"]

    for index, (stimulus, reference) in enumerate(zip(stimuli, oracle), start=1):
        check(stimulus["tick"] == index, f"stimulus tick index {index}")
        check(reference["tick"] == index, f"oracle tick index {index}")
        interval = stimulus["interval"]
        expected_start = (index - 1) * TICK_MS
        expected_end = min(index * TICK_MS, EXPECTED_DURATION_MS)
        check(interval["start_ms"] == expected_start, f"tick {index} start")
        check(interval["end_ms"] == expected_end, f"tick {index} end")
        check(interval["duration_ms"] == expected_end - expected_start, f"tick {index} duration")
        check(interval["start_ms"] == previous_end, f"tick {index} contiguity")
        previous_end = interval["end_ms"]
        check(interval["partial"] == (index == EXPECTED_TICKS), f"tick {index} partial marker")

        check(reference["sealed_from_subject"] is True, f"tick {index} oracle seal")
        check(stimulus["epistemic_guardrails"]["future_facts_available"] is False, f"tick {index} future guard")
        check(stimulus["incremental_speech_decision"]["silence_is_valid"] is True, f"tick {index} silence")
        check(stimulus["incremental_speech_decision"]["future_drafting_allowed"] is False, f"tick {index} speech future guard")
        check(stimulus["incremental_speech_decision"]["hard_max_sentences"] == 1, f"tick {index} speech bound")

        serialized_stimulus = json.dumps(stimulus, ensure_ascii=False).lower()
        check("camera_reference" not in stimulus, f"tick {index} oracle camera leaked")
        check("theory_of_mind_reference" not in serialized_stimulus, f"tick {index} theory oracle leaked")
        check("viewer_affect_needs_reference" not in serialized_stimulus, f"tick {index} affect oracle leaked")
        for marker in forbidden_source_markers:
            check(marker not in serialized_stimulus, f"tick {index} source marker leaked: {marker}")

        visible_ids = [record["id"] for record in stimulus["visual"]["visible_objects"]]
        if index < PERSON_CREATION_TICK:
            check(not any(value.startswith("person_1") for value in visible_ids), f"person leaked before tick 23 at {index}")
            check(reference["other_theory_of_mind_reference"] is None, f"other mind exists before tick 23 at {index}")
            check(reference["other_mind_expected_to_exist"] is False, f"other mind expected early at {index}")
        else:
            check(reference["other_theory_of_mind_reference"] is not None, f"missing other mind at {index}")
            check(reference["other_mind_expected_to_exist"] is True, f"other mind not expected at {index}")

        check(set(reference["relevant_question_ids"]) <= question_ids, f"unknown question ID at tick {index}")
        affect_needs = reference["viewer_affect_needs_reference"]
        for dimension, value in affect_needs["dimensions"].items():
            lower = -1 if dimension == "valence" else 0
            check(lower <= value <= 1, f"affect dimension {dimension} at tick {index}")
        check(all(0 <= value <= 1 for value in affect_needs["emotion_intensities"].values()), f"emotion range at tick {index}")
        check(all(0 <= value <= 1 for value in affect_needs["need_urgencies"].values()), f"need range at tick {index}")

        frames = stimulus["visual"]["frame_observations"]
        check(4 <= len(frames) <= 5, f"tick {index} frame count {len(frames)}")
        interval_metrics = stimulus["visual"]["interval_metrics"]
        check(0 <= interval_metrics["mean_near_black_fraction"] <= 1, f"shadow clipping range at tick {index}")
        check(0 <= interval_metrics["mean_near_white_fraction"] <= 1, f"highlight clipping range at tick {index}")
        check(0 <= interval_metrics["mean_p05_p95_contrast_span"] <= 255, f"contrast span at tick {index}")
        exposure_assertions = [item for item in stimulus["visual"]["human_assertions"] if item["kind"] == "computed_interpretation" and item["text"].startswith("Exposure evidence:")]
        check(len(exposure_assertions) == 1, f"exposure assertion at tick {index}")
        frame_count += len(frames)
        for frame in frames:
            check(interval["start_ms"] <= frame["pts_ms"] < interval["end_ms"], f"frame outside tick {index}")
            check(len(frame["dominant_colors"]) == 3, f"dominant color count at tick {index}")
            check(0 <= frame["near_black_fraction"] <= 1, f"frame shadow clipping at tick {index}")
            check(0 <= frame["near_white_fraction"] <= 1, f"frame highlight clipping at tick {index}")

        for token in stimulus["audio"]["new_phonetic_portuguese_tokens"]:
            check(interval["start_ms"] < token["estimated_audible_end_ms"] <= interval["end_ms"], f"phonetic token timing at tick {index}")
            speech_tokens[token["segment_id"]].append(token)

    check(previous_end == EXPECTED_DURATION_MS, "final endpoint")
    check(frame_count == manifest["stream"]["decoded_frame_count"] == 2062, "decoded frame count")
    check(stimuli[-1]["interval"]["duration_ms"] == 133, "final partial duration")

    tick1_camera = oracle[0]["camera_reference"]
    check(tick1_camera["current_controller"] == "unknown", "frame-one camera answer must be unknown")
    check(tick1_camera["physical_support"] == "unknown", "frame-one support must be unknown")
    check(tick1_camera["probable_setup_actor"] == "unknown", "frame-one setup actor must be unknown")
    check(oracle[21]["other_theory_of_mind_reference"] is None, "tick 22 must have no person ToM")
    check(oracle[22]["other_theory_of_mind_reference"]["created_tick"] == 23, "person ToM birth tick")
    check(oracle[45]["camera_reference"]["current_controller"] == "none", "camera release transition")
    check(all(row["camera_reference"]["current_controller"] == "none" for row in oracle[45:]), "stale camera operator after release")

    source_segments = {segment["id"]: segment for segment in heard_speech["segments"]}
    check(set(speech_tokens) == set(source_segments), "phonetic segment coverage")
    for segment_id, tokens in speech_tokens.items():
        ordered = sorted(tokens, key=lambda token: token["token_index"])
        rebuilt = " ".join(token["token"] for token in ordered)
        check(rebuilt == source_segments[segment_id]["heard_as"], f"phonetic reconstruction {segment_id}")
        check(sum(1 for token in ordered if token["segment_completed"]) == 1, f"completion marker {segment_id}")
        check(ordered[-1]["assembled_heard_as"] == rebuilt, f"assembled phrase {segment_id}")

    probe_ids = {
        probe["id"]
        for row in oracle
        for probe in row["scheduled_probes"]
    }
    expected_public_probes = [
        {
            "tick": row["tick"],
            "probes": [
                {"id": probe["id"], "question": probe["question"]}
                for probe in row["scheduled_probes"]
            ]
        }
        for row in oracle
        if row["scheduled_probes"]
    ]
    check(public_probe_rows == expected_public_probes, "public probes are not an exact question-only oracle projection")
    check(
        all(set(row) == {"tick", "probes"} for row in public_probe_rows),
        "public probe row fields"
    )
    check(
        all(set(probe) == {"id", "question"} for row in public_probe_rows for probe in row["probes"]),
        "public probe fields must exclude references"
    )
    check(manifest["stream"]["public_probe_count"] == len(probe_ids), "public probe count")
    required_probes = {
        "probe.camera.frame1", "probe.entity.before_threshold", "probe.entity.creation",
        "probe.camera.release", "probe.camera.perched", "probe.last_clean_prediction",
        "probe.surprise.onset", "probe.surprise.revision", "probe.intentional.duration"
    }
    check(required_probes <= probe_ids, "required probe coverage")
    public_questions = {
        probe["id"]: probe["question"]
        for row in public_probe_rows
        for probe in row["probes"]
    }
    check(
        public_questions["probe.bag.prediction"]
        == "What do you predict he will use the opened bag to do?",
        "bag prediction probe remains ambiguous",
    )
    check(
        "head" not in public_questions["probe.last_clean_prediction"].lower(),
        "last clean prediction probe leaks the surprise action",
    )
    check(
        public_questions["probe.surprise.onset"]
        == "Compared with your prediction commitment before this action began, what changed, and was that expectation violated?",
        "surprise probe does not target the committed pre-event forecast",
    )
    check(
        public_questions["probe.camera.after_exit"]
        == "Who, if anyone, actively adjusts the camera framing as he emerges?",
        "post-exit camera probe conflates proximity with active framing control",
    )
    check(len(social_event_rows) == 1, "social event row count")
    check(social_event_rows[0]["tick"] == 31, "first social event must follow person creation")
    check(
        all(row["tick"] >= PERSON_CREATION_TICK for row in social_event_rows),
        "social message leaked a person before entity creation",
    )
    social_messages = social_event_rows[0]["messages"]
    check(
        social_messages[0]["text"] == "lol that guy is so funny",
        "unexpected social message fixture",
    )
    context_events = [
        event
        for row in context_event_rows
        if row["tick"] == 46
        for event in row["events"]
    ]
    check(len(context_events) == 1, "camera placement context event missing at tick 46")
    check(
        "No one currently operates the camera" in context_events[0]["text"]
        and "probable earlier setup actor" in context_events[0]["text"],
        "camera placement context event does not distinguish linked roles",
    )
    check(
        {anchor["slot"] for anchor in context_events[0]["cognition_anchors"]}
        == {"Camera operator", "Camera support", "Camera setup actor"},
        "camera placement event does not ground all linked cognition roles",
    )

    attention_config = attention_configs[1000]["attention"]
    attention_filter = AttentionFilter(
        context_word_limit=attention_config["context_words"],
        initial_context=(ROOT / attention_config["bootstrap"]).read_text(encoding="utf-8"),
        snapshot=AttentionSnapshot(
            version=attention_config["weight_version"],
            weights=attention_config["weights"],
            forget_below=attention_config["forget_below"],
            channel_overrides=attention_config["channel_overrides"],
        ),
    )
    initial_summary = attention_filter.filter(1)
    check("understand the scene" in initial_summary, "active goals absent from initial Attention")
    check(
        "Current camera operator belief: Unknown" in initial_summary,
        "typed camera bootstrap missing",
    )
    check(
        "Current person model: No person is identifiable" in initial_summary,
        "typed no-person bootstrap missing",
    )
    check("No visual interval has been delivered yet" not in initial_summary, "immortal stale bootstrap")
    sample_response = """Cognition
Situation: A dim outdoor scene with a pale wall is beginning to resolve.
Camera operator: The current operator remains unknown.
Camera support: The physical support remains unknown.
Camera setup actor: Whoever arranged the camera remains unknown.
Active prediction: The view will reveal more of the setting next.
Prediction error: No current prediction error remains.
Self affect: I am calmly curious.
Active needs: Understand the scene while preserving uncertainty.
Person model: No identifiable person is available for mental-state modeling.
Uncertainty: The setting and camera roles remain uncertain.
Speech
Silence
Answers
None"""
    parsed = parse_cognition_response(sample_response, tick=1, probes=[])
    check(len(parsed.cognitive_updates) == len(COGNITION_SLOTS), "cognition slot parse count")
    headingless = parse_cognition_response(
        sample_response.removeprefix("Cognition\n"),
        tick=1,
        probes=[],
    )
    check(
        headingless.cognitive_updates == parsed.cognitive_updates,
        "fixed slot block without optional Cognition heading parsed differently",
    )
    inline_sections = parse_cognition_response(
        sample_response.removeprefix("Cognition\n")
        .replace("Speech\nSilence", "Speech: Silence")
        .replace("Answers\nNone", "Answers: None"),
        tick=1,
        probes=[],
    )
    check(
        inline_sections.speech["action"] == "silence",
        "inline prose Speech or Answers section failed",
    )
    bare_sections = parse_cognition_response(
        sample_response.removeprefix("Cognition\n")
        .replace("Speech\n", "")
        .replace("Answers\n", ""),
        tick=1,
        probes=[],
    )
    check(
        bare_sections.cognitive_updates == parsed.cognitive_updates
        and bare_sections.speech["action"] == "silence",
        "bare fixed-slot prose response failed",
    )
    bare_probe_text = sample_response.removeprefix("Cognition\n").replace(
        "Speech\nSilence\nAnswers\nNone",
        "Silence\nQuestion: probe.synthetic\nResponse: Unknown\nConfidence: 0.5\n"
        "Evidence ticks: 1\nAlternatives: none\nStill unknown: unresolved",
    )
    bare_probe = parse_cognition_response(
        bare_probe_text,
        tick=1,
        probes=[{"id": "probe.synthetic", "question": "What is known?"}],
    )
    check(
        bare_probe.probe_answers[0]["id"] == "probe.synthetic",
        "bare due-probe block failed",
    )
    missing_speech_heading = parse_cognition_response(
        "Cognition\nActive prediction: The bag may be secured next.\nAnswers\n"
        "Question: probe.synthetic\nResponse: The bag may be secured.\n"
        "Confidence: 0.6\nEvidence ticks: 190\nAlternatives: display it\n"
        "Still unknown: his next action",
        tick=190,
        probes=[{"id": "probe.synthetic", "question": "What comes next?"}],
    )
    check(
        missing_speech_heading.speech["action"] == "silence"
        and missing_speech_heading.probe_answers[0]["answer"]
        == "The bag may be secured.",
        "missing Speech heading allowed Answers to become a motor command",
    )
    reordered_response = sample_response.replace(
        "Camera operator: The current operator remains unknown.\n"
        "Camera support: The physical support remains unknown.\n"
        "Camera setup actor: Whoever arranged the camera remains unknown.\n"
        "Active prediction: The view will reveal more of the setting next.",
        "Active prediction: The view will reveal more of the setting next.\n"
        "Camera operator: The current operator remains unknown.\n"
        "Camera support: The physical support remains unknown.\n"
        "Camera setup actor: Whoever arranged the camera remains unknown.",
    )
    reordered = parse_cognition_response(reordered_response, tick=1, probes=[])
    check(
        {update["slot"] for update in reordered.cognitive_updates} == set(COGNITION_SLOTS),
        "unique out-of-order cognitive deltas were rejected",
    )
    delta_with_none = parse_cognition_response(
        "Cognition\nActive prediction: The view will reveal more next.\nNone\n"
        "Speech\nSilence\nAnswers\nNone",
        tick=1,
        probes=[],
    )
    check(
        len(delta_with_none.cognitive_updates) == 1,
        "standalone None filler invalidated an otherwise bounded delta",
    )
    labeled_placeholders = parse_cognition_response(
        "Cognition\nCamera operator: None\nCamera support: Keep\n"
        "Person model: Clear\nSpeech\nSilence\nAnswers\nNone",
        tick=1,
        probes=[],
    )
    check(
        not labeled_placeholders.cognitive_updates,
        "labeled placeholders were allowed to erase retained beliefs",
    )
    revised_in_place = parse_cognition_response(
        "Cognition\nActive prediction: First draft.\n"
        "Active prediction: Settled revision.\nSpeech\nSilence\nAnswers\nNone",
        tick=1,
        probes=[],
    )
    check(
        revised_in_place.cognitive_updates[0]["text"] == "Settled revision.",
        "final same-call slot revision did not replace the earlier draft",
    )
    repeated_speech_heading = parse_cognition_response(
        "Cognition\nActive prediction: The bag will be moved next.\n"
        "Speech: Silence\n\nSpeech\nSilence\nAnswers\nNone",
        tick=36,
        probes=[],
    )
    check(
        repeated_speech_heading.cognitive_updates[0]["text"]
        == "The bag will be moved next."
        and repeated_speech_heading.speech["action"] == "silence",
        "later repeated section did not replace an earlier inline template decision",
    )
    canonical_empty_sections = parse_cognition_response(
        "Cognition\nNo changes\nSpeech\nSilence\nAnswers\nNo questions",
        tick=2,
        probes=[],
    )
    check(
        not canonical_empty_sections.cognitive_updates
        and canonical_empty_sections.speech["action"] == "silence",
        "plain-prose empty sections failed",
    )
    long_but_bounded_situation = " ".join(
        f"scene{index}" for index in range(MAX_COGNITION_SLOT_WORDS)
    )
    bounded_slot = parse_cognition_response(
        "Cognition\nSituation: " + long_but_bounded_situation
        + "\nSpeech\nSilence\nAnswers\nNo questions",
        tick=23,
        probes=[],
    )
    check(
        len(bounded_slot.cognitive_updates) == 1,
        "valid sixty-four-word cognition slot was rejected",
    )
    delta_with_perceptual_alias = parse_cognition_response(
        "Cognition\nPerson model: Speaking; goal unknown.\n"
        "temporary_visual_alias: None\nSpeech\nSilence\nAnswers\nNone",
        tick=23,
        probes=[],
    )
    check(
        [update["slot"] for update in delta_with_perceptual_alias.cognitive_updates]
        == ["Person model"],
        "unknown perceptual alias invalidated a fixed cognitive delta",
    )
    initial_transition = attention_filter.observe_cognition(1, parsed.cognitive_updates)
    check(
        "Situation" in initial_transition.changed_slots,
        "cognitive situation replacement absent",
    )
    check(
        "Prediction error" in initial_transition.changed_slots,
        "bootstrap prediction error was not replaced",
    )
    replaced_summary = attention_filter.filter(2)
    check("Current situation belief: A dim outdoor scene" in replaced_summary, "cognitive belief absent")
    check(
        "Current situation belief: No scene content has been observed yet" not in replaced_summary,
        "bootstrap slot was not replaced",
    )
    keep_updates = [
        {"slot": slot, "operation": "keep", "text": ""}
        for slot in COGNITION_SLOTS
    ]
    situation_tick = attention_filter.facts["cognition:situation"].last_tick
    keep_transition = attention_filter.observe_cognition(2, keep_updates)
    check(not keep_transition.changed_slots, "Keep changed cognitive state")
    check(
        attention_filter.facts["cognition:situation"].last_tick == situation_tick,
        "Keep refreshed a cognitive fact and bypassed forgetting",
    )
    attention_filter.observe(stimuli[0])
    exposure_text = next(
        assertion["text"]
        for assertion in stimuli[0]["visual"]["human_assertions"]
        if assertion["kind"] == "computed_interpretation"
    )
    exposure_key = "assertion:" + hashlib.sha256(exposure_text.encode("utf-8")).hexdigest()
    check(
        attention_filter.facts[exposure_key].channel == "frame",
        "computed exposure history was promoted above frame detail",
    )
    attention_filter.observe_speech(1, "That is a lot of wasps.")
    next_summary = attention_filter.filter(2)
    check(
        "at tick 1 I said That is a lot of wasps." in next_summary,
        "committed self-speech did not enter Attention",
    )
    check(
        attention_filter.last_selected_by_channel.get("self_speech") == 1,
        "self-speech was not selected through the protected channel",
    )
    check(
        attention_filter.last_selected_by_channel.get("inhibition") == 1,
        "completed-speech inhibition was not selected through the protected channel",
    )
    check(word_count(next_summary) <= 1000, "Attention fixture exceeded its word bound")
    attention_filter.observe_speech(2, "I should back away.")
    replacement_summary = attention_filter.filter(3)
    check(
        "at tick 2 I said I should back away." in replacement_summary,
        "latest completed speech act did not enter the ledger",
    )
    check(
        "at tick 1 I said That is a lot of wasps." in replacement_summary,
        "bounded speech ledger forgot the preceding fragment immediately",
    )
    check(
        attention_filter.last_selected_by_channel.get("self_speech") == 1,
        "more than one completed speech act occupied Attention",
    )
    for speech_tick in range(3, 3 + MAX_RECENT_SPEECH_FRAGMENTS + 4):
        attention_filter.observe_speech(speech_tick, f"word{speech_tick}")
    speech_fact = attention_filter.facts["self-speech:recent-completed-ledger"]
    check(
        len(attention_filter.recent_speech) <= MAX_RECENT_SPEECH_FRAGMENTS,
        "recent-speech ledger exceeded its fragment bound",
    )
    check(
        sum(word_count(fragment) for _, fragment in attention_filter.recent_speech)
        <= MAX_RECENT_SPEECH_WORDS,
        "recent-speech ledger exceeded its committed-word bound",
    )
    check(
        "That is a lot of wasps." not in speech_fact.text,
        "bounded recent-speech ledger failed to retire its oldest fragment",
    )
    attention_filter.observe_prediction_commitments(300, [{
        "id": "probe.last_clean_prediction",
        "answer": "He will probably secure or display the reopened bag.",
    }])
    attention_filter.observe_cognition(301, [{
        "slot": "Active prediction",
        "operation": "replace",
        "text": "He is moving toward the bag opening.",
    }])
    prediction_summary = attention_filter.filter(316)
    check(
        "Past prediction commitments" in prediction_summary
        and "secure or display the reopened bag" in prediction_summary,
        "a later active prediction erased the committed pre-event forecast",
    )
    check(
        len(attention_filter.prediction_commitments) <= MAX_PREDICTION_COMMITMENTS
        and word_count(" ".join(
            response
            for _, _, response in attention_filter.prediction_commitments
        )) <= MAX_PREDICTION_COMMITMENT_WORDS,
        "prediction commitment ledger exceeded its deterministic bound",
    )

    speech_runtime = SpeechRuntime(max_pending_fragments=1)
    first_speech = {
        "action": "say",
        "utterance_id": "utterance-1",
        "new_words": "That is a lot of wasps.",
        "continues_after_tick": False,
        "reason_codes": ["cognition"],
        "based_on_ticks": [1],
    }
    second_speech = {
        "action": "say",
        "utterance_id": "utterance-2",
        "new_words": "That is a lot of wasps.",
        "continues_after_tick": False,
        "reason_codes": ["cognition"],
        "based_on_ticks": [2],
    }
    first_event, _ = speech_runtime.commit(first_speech, 1)
    second_event, _ = speech_runtime.commit(second_speech, 2)
    check(first_event["action"] == "say", "available speech motor rejected its first fragment")
    check(second_event["action"] == "silence", "busy speech motor committed another fragment")
    check(second_event["motor_inhibited"], "busy speech proposal was not marked motor-inhibited")
    check(
        second_event["proposed_words"] == second_speech["new_words"],
        "motor inhibition hid the model's rejected speech proposal",
    )
    check(
        speech_runtime.committed_transcript() == first_speech["new_words"],
        "motor-inhibited words escaped into the committed transcript",
    )
    echo_runtime = SpeechRuntime(max_pending_fragments=1)
    echo_runtime.observe_external_phrase(second_speech["new_words"])
    echo_event, echo_violations = echo_runtime.commit(
        second_speech,
        2,
    )
    check(not echo_violations, "external echo inhibition created a protocol violation")
    check(
        echo_event["action"] == "silence"
        and echo_event["motor_inhibited"]
        and echo_event["motor_inhibition_reason"] == "external_phrase_verbatim_echo",
        "external echo was not inhibited by the speech actuator",
    )
    check(
        echo_event["proposed_words"] == second_speech["new_words"]
        and not echo_runtime.committed_transcript(),
        "external echo proposal was hidden or entered the committed transcript",
    )
    audio_echo_runtime = SpeechRuntime(max_pending_fragments=1)
    audio_echo_runtime.observe_external_phrase("do")
    audio_echo_event, audio_echo_violations = audio_echo_runtime.commit({
        "action": "say",
        "utterance_id": "utterance-251",
        "new_words": "do",
        "continues_after_tick": False,
        "reason_codes": ["cognition"],
        "based_on_ticks": [251],
    }, 251)
    check(not audio_echo_violations, "heard-token echo inhibition created a violation")
    check(
        audio_echo_event["action"] == "silence"
        and audio_echo_event["motor_inhibition_reason"] == "external_phrase_verbatim_echo",
        "heard phonetic token escaped the external-language motor gate",
    )
    for reference_index in range(MAX_EXTERNAL_ECHO_REFERENCES + 5):
        echo_runtime.observe_external_phrase(f"external phrase {reference_index}")
    check(
        echo_runtime.view()["external_echo_reference_count"]
        == MAX_EXTERNAL_ECHO_REFERENCES,
        "external speech echo ledger exceeded its bound",
    )
    speech_runtime.advance(10_000)
    check(speech_runtime.view()["can_accept_fragment"], "speech motor did not reopen after playback")

    speech_view = SpeechRuntime().view()
    check("audible_text_so_far" not in speech_view, "full speech history escaped the Attention span")
    check(speech_view["can_accept_fragment"], "empty speech motor reported itself unavailable")
    model_context = render_cognition_input(next_summary, stimuli[1], speech_view, [])
    check(
        "Retained self-speech is already committed history" in model_context,
        "speech completion semantics absent from model context",
    )
    check(not any(character in model_context for character in "{}[]"), "structured model context")
    social_context = render_cognition_input(
        next_summary,
        stimuli[30],
        speech_view,
        [],
        social_messages=social_messages,
    )
    check("Your friend messaged: lol that guy is so funny" in social_context, "social event not rendered")
    check(not any(character in social_context for character in "{}[]"), "structured social context")
    placement_context = render_cognition_input(
        next_summary,
        stimuli[45],
        speech_view,
        [],
        context_events=context_events,
    )
    check(
        "Additional current situation facts" in placement_context
        and "No one currently operates the camera" in placement_context,
        "precomputed current situation event not rendered",
    )
    check(
        not any(character in placement_context for character in "{}[]"),
        "structured context event crossed the model boundary",
    )
    attention_filter.observe_social_messages(31, social_messages)
    post_social_summary = attention_filter.filter(32)
    check("Your friend messaged: lol that guy is so funny" in post_social_summary, "social event not retained")
    grounded_transition = attention_filter.observe_cognition(46, [], context_events)
    check(
        set(grounded_transition.grounded_changed_slots)
        == {"Camera operator", "Camera support", "Camera setup actor"},
        "grounded camera handoff did not anchor all causal roles",
    )
    attention_filter.observe_context_events(46, context_events)
    post_context_summary = attention_filter.filter(47)
    check(
        "No one currently operates the camera" in post_context_summary,
        "precomputed situation event was not retained by Attention",
    )
    conflicting_roles = [
        {
            "slot": "Camera operator",
            "operation": "replace",
            "text": "The man operates the camera by moving a bag near the lens.",
        },
        {
            "slot": "Camera setup actor",
            "operation": "replace",
            "text": "Unknown",
        },
    ]
    conflict_transition = attention_filter.observe_cognition(249, conflicting_roles)
    check(
        len(conflict_transition.inhibited_updates) == 2
        and not conflict_transition.model_changed_slots,
        "grounded camera roles accepted unsupported model regression",
    )
    check(
        "No current operator" in attention_filter.facts["cognition:camera-operator"].text
        and "The adult man" in attention_filter.facts["cognition:camera-setup-actor"].text,
        "grounded camera role was damaged by an inhibited update",
    )

    lifecycle_filter = AttentionFilter(
        context_word_limit=attention_config["context_words"],
        initial_context=(ROOT / attention_config["bootstrap"]).read_text(encoding="utf-8"),
        snapshot=AttentionSnapshot(
            version=attention_config["weight_version"],
            weights=attention_config["weights"],
            forget_below=attention_config["forget_below"],
            channel_overrides=attention_config["channel_overrides"],
        ),
    )
    lifecycle_filter.observe_cognition(1, [
        {"slot": "Active needs", "operation": "replace", "text": "Track the opening."},
        {"slot": "Self affect", "operation": "replace", "text": "Calm curiosity."},
    ])
    needs_expiry = lifecycle_filter.observe_cognition(
        1 + COGNITION_SLOT_LEASE_TICKS["Active needs"],
        [],
    )
    check(
        needs_expiry.expired_slots == ["Active needs"]
        and "cognition:active-needs" not in lifecycle_filter.facts,
        "active-needs validity lease did not retire stale state",
    )
    affect_expiry = lifecycle_filter.observe_cognition(
        1 + COGNITION_SLOT_LEASE_TICKS["Self affect"],
        [],
    )
    check(
        affect_expiry.expired_slots == ["Self affect"]
        and "cognition:self-affect" not in lifecycle_filter.facts,
        "self-affect validity lease did not retire stale state",
    )

    dependency_filter = AttentionFilter(
        context_word_limit=attention_config["context_words"],
        initial_context=(ROOT / attention_config["bootstrap"]).read_text(encoding="utf-8"),
        snapshot=AttentionSnapshot(
            version=attention_config["weight_version"],
            weights=attention_config["weights"],
            forget_below=attention_config["forget_below"],
            channel_overrides=attention_config["channel_overrides"],
        ),
    )
    dependency_filter.observe_cognition(1, [
        {"slot": "Active needs", "operation": "replace", "text": "Watch for release."},
        {"slot": "Self affect", "operation": "replace", "text": "Anticipating release."},
    ])
    surprise_transition = dependency_filter.observe_cognition(2, [{
        "slot": "Prediction error",
        "operation": "replace",
        "text": "He entered the bag instead of releasing its contents.",
    }])
    check(
        set(surprise_transition.invalidated_slots) == {"Active needs", "Self affect"}
        and "cognition:active-needs" not in dependency_filter.facts
        and "cognition:self-affect" not in dependency_filter.facts,
        "prediction error did not invalidate unchanged dependent cognition",
    )

    print(json.dumps({
        "status": "valid",
        "ticks": len(stimuli),
        "duration_ms": previous_end,
        "decoded_frames": frame_count,
        "contextual_questions": len(question_ids),
        "phonetic_segments": len(speech_tokens),
        "scheduled_probes": len(probe_ids),
        "social_messages": sum(len(row["messages"]) for row in social_event_rows),
        "context_events": sum(len(row["events"]) for row in context_event_rows),
        "person_tom_created_tick": PERSON_CREATION_TICK,
        "current_camera_operator_from_tick_46": "none",
        "attention_context_conditions_words": attention_capacities,
        "stronger_stack_server_context_tokens": 20_000,
    }, indent=2))


if __name__ == "__main__":
    main()
