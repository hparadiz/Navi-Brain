"""Non-neural personality-shaped attention over whole visual fact streams."""

from __future__ import annotations

from dataclasses import dataclass
from hashlib import sha256
from math import exp
from typing import Any, Mapping, Sequence
import json
import re

from stream_runtime import word_count


FORBIDDEN_DELIMITERS = "{}[]"
PROSE_TRANSLATION = str.maketrans("", "", FORBIDDEN_DELIMITERS)
ATTENTION_POLICY_VERSION = "control-core-v5"
MAX_COGNITION_SLOT_WORDS = 64
MAX_RECENT_SPEECH_WORDS = 96
MAX_RECENT_SPEECH_FRAGMENTS = 12
MAX_PREDICTION_COMMITMENT_WORDS = 128
MAX_PREDICTION_COMMITMENTS = 4
NO_ACTIVE_PREDICTION_ERROR = "No active prediction error"
COGNITION_SLOT_LEASE_TICKS = {
    "Self affect": 96,
    "Active needs": 48,
}
CONTROL_CHANNELS = frozenset({
    "self_speech",
    "speech_buffer",
    "motor_control",
    "shell_control",
    "machine_telemetry",
    "active_goal",
    "safety",
    "inhibition",
})
EXECUTIVE_CHANNELS = frozenset({
    "self_model",
    "agent_model",
    "world_model",
    "prediction",
    "social_message",
    "prediction_error",
    "uncertainty",
    "change",
})
DETAIL_CHANNELS = frozenset({"color", "frame"})
BOOTSTRAP_CHANNELS = {
    "Situation": ("cognition:situation", "orientation"),
    "Camera operator": ("cognition:camera-operator", "world_model"),
    "Camera support": ("cognition:camera-support", "world_model"),
    "Camera setup actor": ("cognition:camera-setup-actor", "world_model"),
    "Active prediction": ("cognition:active-prediction", "prediction"),
    "Prediction error": ("cognition:prediction-error", "prediction_error"),
    "Self affect": ("cognition:self-affect", "self_model"),
    "Active needs": ("cognition:active-needs", "active_goal"),
    "Person model": ("cognition:person-model", "agent_model"),
    "Uncertainty": ("cognition:uncertainty", "uncertainty"),
    "Active goals": ("bootstrap:active-goals", "active_goal"),
}
COGNITION_SLOTS = {
    "Situation": ("cognition:situation", "orientation", "Current situation belief"),
    "Camera operator": (
        "cognition:camera-operator",
        "world_model",
        "Current camera operator belief",
    ),
    "Camera support": (
        "cognition:camera-support",
        "world_model",
        "Current camera support belief",
    ),
    "Camera setup actor": (
        "cognition:camera-setup-actor",
        "world_model",
        "Camera setup actor belief",
    ),
    "Active prediction": (
        "cognition:active-prediction",
        "prediction",
        "Active prediction",
    ),
    "Prediction error": (
        "cognition:prediction-error",
        "prediction_error",
        "Current prediction error",
    ),
    "Self affect": ("cognition:self-affect", "self_model", "Current self affect"),
    "Active needs": ("cognition:active-needs", "active_goal", "Current active needs"),
    "Person model": ("cognition:person-model", "agent_model", "Current person model"),
    "Uncertainty": ("cognition:uncertainty", "uncertainty", "Current uncertainty"),
}
DEFAULT_DECAY_TICKS = {
    "orientation": 1_000_000,
    "self_model": 240,
    "world_model": 240,
    "prediction": 100,
    "active_goal": 1_000_000,
    "self_speech": 80,
    "speech_buffer": 12,
    "motor_control": 12,
    "shell_control": 80,
    "machine_telemetry": 8,
    "safety": 40,
    "inhibition": 40,
    "agent_model": 120,
    "social_message": 120,
    "prediction_error": 48,
    "observation": 80,
    "interpretation": 40,
    "object": 60,
    "color": 12,
    "frame": 3,
    "change": 24,
    "audio": 30,
    "uncertainty": 50,
}


def plain_prose(value: object) -> str:
    """Remove source annotation delimiters before text crosses the model boundary."""

    return " ".join(str(value).translate(PROSE_TRANSLATION).split())


@dataclass(slots=True)
class AttentionFact:
    key: str
    text: str
    channel: str
    intrinsic_salience: float
    first_tick: int
    last_tick: int
    exposures: int = 1


@dataclass(frozen=True, slots=True)
class AttentionSnapshot:
    version: str
    weights: Mapping[str, float]
    forget_below: float
    channel_overrides: Mapping[str, str]


@dataclass(frozen=True, slots=True)
class CognitionDecision:
    cognitive_updates: list[dict[str, str]]
    speech: dict[str, Any]
    probe_answers: list[dict[str, Any]]


@dataclass(frozen=True, slots=True)
class CognitionTransition:
    changed_slots: list[str]
    model_changed_slots: list[str]
    grounded_changed_slots: list[str]
    invalidated_slots: list[str]
    expired_slots: list[str]
    inhibited_updates: list[dict[str, str]]


class AttentionFilter:
    """Select fact units for active context without invoking a model."""

    def __init__(
        self,
        context_word_limit: int,
        initial_context: str,
        snapshot: AttentionSnapshot,
    ) -> None:
        if context_word_limit <= 0:
            raise ValueError("context word limit must be positive")
        self.context_word_limit = context_word_limit
        self.snapshot = snapshot
        self.facts: dict[str, AttentionFact] = {}
        self.last_candidate_count = 0
        self.last_selected_count = 0
        self.last_candidate_by_channel: dict[str, int] = {}
        self.last_selected_by_channel: dict[str, int] = {}
        self.last_summary_words = 0
        self.recent_speech: list[tuple[int, str]] = []
        self.prediction_commitments: list[tuple[int, str, str]] = []
        self.cognition_anchors: dict[str, tuple[str, int, str]] = {}
        paragraphs = [
            paragraph.strip()
            for paragraph in re.split(r"\n\s*\n", initial_context.strip())
            if paragraph.strip()
        ]
        for index, paragraph in enumerate(paragraphs):
            lines = paragraph.splitlines()
            heading = lines[0].strip()
            explicit_slot = BOOTSTRAP_CHANNELS.get(heading)
            text = " ".join((lines[1:] if explicit_slot else lines))
            if heading in COGNITION_SLOTS:
                text = f"{COGNITION_SLOTS[heading][2]}: {text}"
            self._observe_fact(
                key=explicit_slot[0] if explicit_slot else f"bootstrap:{index}",
                text=text,
                channel=(
                    explicit_slot[1]
                    if explicit_slot
                    else ("orientation" if index == 0 else "self_model")
                ),
                intrinsic_salience=1.0,
                tick=0,
            )

    @property
    def weight_snapshot_hash(self) -> str:
        payload = json.dumps(
            {
                "policy_version": ATTENTION_POLICY_VERSION,
                "version": self.snapshot.version,
                "weights": dict(self.snapshot.weights),
                "forget_below": self.snapshot.forget_below,
                "channel_overrides": dict(self.snapshot.channel_overrides),
            },
            sort_keys=True,
            separators=(",", ":"),
        )
        return sha256(payload.encode("utf-8")).hexdigest()

    def state_snapshot(self) -> list[dict[str, object]]:
        """Return deterministic internal telemetry for the non-model state hash."""

        facts = [
            {
                "record_type": "attention_fact",
                "key": fact.key,
                "text": fact.text,
                "channel": fact.channel,
                "intrinsic_salience": fact.intrinsic_salience,
                "first_tick": fact.first_tick,
                "last_tick": fact.last_tick,
                "exposures": fact.exposures,
            }
            for fact in sorted(self.facts.values(), key=lambda item: item.key)
        ]
        anchors = [
            {
                "record_type": "cognition_anchor",
                "slot": slot,
                "text": value,
                "established_tick": established_tick,
                "source_event_id": source_event_id,
            }
            for slot, (value, established_tick, source_event_id)
            in sorted(self.cognition_anchors.items())
        ]
        return [*facts, *anchors]

    def _observe_fact(
        self,
        key: str,
        text: str,
        channel: str,
        intrinsic_salience: float,
        tick: int,
    ) -> None:
        clean = plain_prose(text)
        if not clean:
            return
        if any(character in clean for character in FORBIDDEN_DELIMITERS):
            raise ValueError("attention fact contains a forbidden structured-data delimiter")
        existing = self.facts.get(key)
        if existing is None:
            self.facts[key] = AttentionFact(
                key=key,
                text=clean,
                channel=channel,
                intrinsic_salience=intrinsic_salience,
                first_tick=tick,
                last_tick=tick,
            )
            return
        existing.text = clean
        existing.last_tick = tick
        existing.exposures += 1
        existing.intrinsic_salience = max(existing.intrinsic_salience, intrinsic_salience)

    def observe_control_fact(
        self,
        *,
        key: str,
        text: str,
        channel: str,
        intrinsic_salience: float,
        tick: int,
    ) -> None:
        """Admit externally computed control state without performing inference."""

        if channel not in CONTROL_CHANNELS and channel != "self_model":
            raise ValueError(f"{channel} is not a control or executive channel")
        self.observe_fact(
            key=key,
            text=text,
            channel=channel,
            intrinsic_salience=intrinsic_salience,
            tick=tick,
        )

    def observe_fact(
        self,
        *,
        key: str,
        text: str,
        channel: str,
        intrinsic_salience: float,
        tick: int,
    ) -> None:
        """Admit a fact already typed by the upstream non-neural source."""

        if channel not in self.snapshot.weights:
            raise ValueError(f"attention channel has no frozen personality weight: {channel}")
        self._observe_fact(key, text, channel, intrinsic_salience, tick)

    def retire_fact(self, key: str) -> None:
        """Remove control state that is no longer active."""

        self.facts.pop(key, None)

    @staticmethod
    def _same_cognition_value(left: str, right: str) -> bool:
        return plain_prose(left).rstrip(" .").casefold() == plain_prose(right).rstrip(" .").casefold()

    def _replace_cognition_slot(self, slot: str, value: str, tick: int) -> bool:
        key, channel, prefix = COGNITION_SLOTS[slot]
        clean = plain_prose(value)
        rendered = f"{prefix}: {clean}"
        existing = self.facts.get(key)
        if existing is not None and self._same_cognition_value(existing.text, rendered):
            return False
        self.retire_fact(key)
        self.observe_fact(
            key=key,
            text=rendered,
            channel=channel,
            intrinsic_salience=1.0,
            tick=tick,
        )
        return True

    def _establish_cognition_anchors(
        self,
        tick: int,
        events: Sequence[Mapping[str, Any]],
    ) -> list[str]:
        changed: list[str] = []
        for event in events:
            for anchor in event.get("cognition_anchors", []):
                slot = str(anchor["slot"])
                value = plain_prose(anchor["text"])
                if slot not in COGNITION_SLOTS or not value:
                    raise ValueError("invalid grounded cognition anchor")
                anchored_state = (value, tick, str(event["id"]))
                anchor_changed = self.cognition_anchors.get(slot) != anchored_state
                self.cognition_anchors[slot] = anchored_state
                fact_changed = self._replace_cognition_slot(slot, value, tick)
                if anchor_changed or fact_changed:
                    changed.append(slot)
        return changed

    def observe_cognition(
        self,
        tick: int,
        updates: Sequence[Mapping[str, str]],
        context_events: Sequence[Mapping[str, Any]] = (),
    ) -> CognitionTransition:
        """Apply one call under grounded anchors and deterministic slot lifecycles."""

        grounded_changed = self._establish_cognition_anchors(tick, context_events)
        model_changed: list[str] = []
        inhibited: list[dict[str, str]] = []
        seen: set[str] = set()
        for update in updates:
            if set(update) != {"slot", "operation", "text"}:
                raise ValueError("cognitive update fields do not match the slot contract")
            slot = update["slot"]
            operation = update["operation"]
            value = plain_prose(update["text"])
            if slot not in COGNITION_SLOTS:
                raise ValueError(f"unknown cognitive slot: {slot}")
            if slot in seen:
                raise ValueError(f"duplicate cognitive slot: {slot}")
            seen.add(slot)
            key, _, _ = COGNITION_SLOTS[slot]
            existing = self.facts.get(key)
            if operation == "keep":
                if value:
                    raise ValueError("Keep cognitive updates cannot contain replacement text")
                continue
            if operation == "clear":
                if value:
                    raise ValueError("Clear cognitive updates cannot contain replacement text")
                if slot in self.cognition_anchors:
                    anchored_value, _, source_event_id = self.cognition_anchors[slot]
                    inhibited.append({
                        "slot": slot,
                        "proposed_text": "Clear",
                        "anchored_text": anchored_value,
                        "source_event_id": source_event_id,
                        "reason": "grounded_cognition_anchor",
                    })
                    continue
                if existing is not None:
                    self.retire_fact(key)
                    model_changed.append(slot)
                continue
            if operation != "replace" or not value:
                raise ValueError(f"invalid cognitive update operation for {slot}")
            anchor = self.cognition_anchors.get(slot)
            if anchor is not None and not self._same_cognition_value(value, anchor[0]):
                inhibited.append({
                    "slot": slot,
                    "proposed_text": value,
                    "anchored_text": anchor[0],
                    "source_event_id": anchor[2],
                    "reason": "grounded_cognition_anchor",
                })
                continue
            accepted_value = anchor[0] if anchor is not None else value
            if self._replace_cognition_slot(slot, accepted_value, tick):
                model_changed.append(slot)

        invalidated: list[str] = []
        prediction_error_changed = "Prediction error" in model_changed
        prediction_error_key = COGNITION_SLOTS["Prediction error"][0]
        prediction_error = self.facts.get(prediction_error_key)
        active_prediction_error = bool(
            prediction_error
            and NO_ACTIVE_PREDICTION_ERROR.casefold() not in prediction_error.text.casefold()
        )
        if tick > 1 and prediction_error_changed and active_prediction_error:
            for dependent_slot in ("Active needs", "Self affect"):
                if dependent_slot in model_changed:
                    continue
                dependent_key = COGNITION_SLOTS[dependent_slot][0]
                if dependent_key in self.facts:
                    self.retire_fact(dependent_key)
                    invalidated.append(dependent_slot)

        expired: list[str] = []
        for slot, lease_ticks in COGNITION_SLOT_LEASE_TICKS.items():
            key = COGNITION_SLOTS[slot][0]
            fact = self.facts.get(key)
            if fact is None or slot in model_changed or slot in grounded_changed:
                continue
            if tick - fact.last_tick >= lease_ticks:
                self.retire_fact(key)
                expired.append(slot)

        changed_slots = list(dict.fromkeys([
            *grounded_changed,
            *model_changed,
            *invalidated,
            *expired,
        ]))
        return CognitionTransition(
            changed_slots=changed_slots,
            model_changed_slots=model_changed,
            grounded_changed_slots=grounded_changed,
            invalidated_slots=invalidated,
            expired_slots=expired,
            inhibited_updates=inhibited,
        )

    def observe_speech(self, tick: int, words: str) -> None:
        """Update one bounded ledger of completed speech inside Attention."""

        clean = " ".join(words.split())
        if clean:
            self.recent_speech.append((tick, clean))
            while (
                len(self.recent_speech) > MAX_RECENT_SPEECH_FRAGMENTS
                or sum(word_count(fragment) for _, fragment in self.recent_speech)
                > MAX_RECENT_SPEECH_WORDS
            ):
                self.recent_speech.pop(0)
            ledger = "; ".join(
                f"at tick {speech_tick} I said {fragment}"
                for speech_tick, fragment in self.recent_speech
            )
            self.observe_control_fact(
                key="self-speech:recent-completed-ledger",
                text="Recent completed self-speech in chronological order: "
                f"{ledger}. Every listed fragment was already spoken. Do not repeat it; "
                "commit only genuinely new words or choose Silence.",
                channel="self_speech",
                intrinsic_salience=1.0,
                tick=tick,
            )
            self.observe_control_fact(
                key="speech-control:repetition-inhibition",
                text="Speech control preserves the bounded recent-speech ledger as completed "
                "history, not an active instruction. Without a new communicative reason, the "
                "correct next act is silence.",
                channel="inhibition",
                intrinsic_salience=1.0,
                tick=tick,
            )

    def observe_prediction_commitments(
        self,
        tick: int,
        answers: Sequence[Mapping[str, Any]],
    ) -> None:
        """Retain bounded past forecasts selected by the frozen run design."""

        for answer in answers:
            probe_id = plain_prose(answer["id"])
            response = plain_prose(answer["answer"])
            if not probe_id or not response:
                raise ValueError("prediction commitment is missing an identifier or response")
            self.prediction_commitments = [
                commitment
                for commitment in self.prediction_commitments
                if commitment[1] != probe_id
            ]
            self.prediction_commitments.append((tick, probe_id, response))
            while len(self.prediction_commitments) > MAX_PREDICTION_COMMITMENTS:
                self.prediction_commitments.pop(0)

        if not answers:
            return
        while self.prediction_commitments:
            ledger = "; ".join(
                f"at tick {commitment_tick}, for {probe_id}, I predicted {response}"
                for commitment_tick, probe_id, response in self.prediction_commitments
            )
            if word_count(ledger) <= MAX_PREDICTION_COMMITMENT_WORDS:
                break
            self.prediction_commitments.pop(0)
        if not self.prediction_commitments:
            raise ValueError("a prediction commitment exceeded the bounded ledger")
        self.observe_fact(
            key="prediction:commitment-ledger",
            text="Past prediction commitments in chronological order: "
            f"{ledger}. These are past forecasts, not current observations or active "
            "predictions. Preserve them long enough to compare later outcomes and register "
            "prediction error when evidence contradicts them.",
            channel="prediction_error",
            intrinsic_salience=1.0,
            tick=tick,
        )

    def observe_social_messages(
        self,
        tick: int,
        messages: Sequence[Mapping[str, Any]],
    ) -> None:
        """Admit already delivered social events to historical Attention."""

        for message in messages:
            self.observe_fact(
                key=f"social-message:{message['id']}",
                text=f"At tick {tick}, {message['sender']} messaged: {message['text']}",
                channel="social_message",
                intrinsic_salience=float(message.get("salience", 0.8)),
                tick=tick,
            )

    def observe_context_events(
        self,
        tick: int,
        events: Sequence[Mapping[str, Any]],
    ) -> None:
        """Admit precomputed current-situation facts after their delivery tick."""

        for event in events:
            self.observe_fact(
                key=f"context-event:{event['id']}",
                text=f"At tick {tick}, {event['text']}",
                channel=str(event["attention_channel"]),
                intrinsic_salience=float(event["confidence"]),
                tick=tick,
            )

    def observe(self, row: Mapping[str, Any]) -> None:
        """Add the completed tick to the historical fact pool without changing weights."""

        tick = int(row["tick"])
        visual = row["visual"]
        for index, assertion in enumerate(visual["human_assertions"]):
            channel = assertion.get("attention_channel") or (
                "frame"
                if assertion["kind"] == "computed_interpretation"
                else "observation"
            )
            key = "assertion:" + sha256(assertion["text"].encode("utf-8")).hexdigest()
            self.observe_fact(
                key=key,
                text=f"At tick {tick}, {assertion['text']}",
                channel=channel,
                intrinsic_salience=float(assertion["confidence"]),
                tick=tick,
            )

        for obj in visual["visible_objects"]:
            canonical_id = obj["canonical_id"]
            object_key = f"object:{canonical_id}"
            self.observe_fact(
                key=object_key,
                text=f"At tick {tick}, {canonical_id} is visible as {obj['category']}. "
                f"Its appearance is {obj.get('appearance', 'uncertain')} and its material is "
                f"{obj.get('material', 'uncertain')}.",
                channel=(
                    obj.get("attention_channel")
                    or self.snapshot.channel_overrides.get(object_key)
                    or "object"
                ),
                intrinsic_salience=0.82,
                tick=tick,
            )
            self.observe_fact(
                key=f"color:{canonical_id}",
                text=f"At tick {tick}, the visible colors of {canonical_id} are "
                f"{'; '.join(obj.get('colors', [])) or 'uncertain'}.",
                channel="color",
                intrinsic_salience=0.58,
                tick=tick,
            )
            if obj.get("uncertainty"):
                self.observe_fact(
                    key=f"uncertainty:{canonical_id}",
                    text=f"At tick {tick}, uncertainty about {canonical_id}: {obj['uncertainty']}",
                    channel="uncertainty",
                    intrinsic_salience=0.78,
                    tick=tick,
                )

        for frame in visual["frame_observations"]:
            dominant = "; ".join(
                f"{color['hex']} at {100 * float(color['fraction']):.1f} percent"
                for color in frame["dominant_colors"]
            )
            self.observe_fact(
                key=f"frame:{frame['frame_index']}",
                text=f"Frame {frame['frame_index']} at {frame['pts_ms']} milliseconds averaged "
                f"{frame['mean_hex']} with luminance {frame['mean_luminance']}; dominant colors "
                f"were {dominant}.",
                channel="frame",
                intrinsic_salience=0.35,
                tick=tick,
            )

        changes = row["changes_from_previous_tick"]
        if changes["phase_changed"]:
            self.observe_fact(
                key=f"change:phase:{tick}",
                text=f"The scene phase changed at tick {tick}.",
                channel="change",
                intrinsic_salience=1.0,
                tick=tick,
            )
        if changes["newly_visible_or_reclassified"]:
            self.observe_fact(
                key=f"change:new:{tick}",
                text=f"At tick {tick}, newly visible or reclassified items were "
                f"{'; '.join(changes['newly_visible_or_reclassified'])}.",
                channel="change",
                intrinsic_salience=1.0,
                tick=tick,
            )
        if changes["no_longer_listed_or_now_occluded"]:
            self.observe_fact(
                key=f"change:gone:{tick}",
                text=f"At tick {tick}, no longer listed or newly occluded items were "
                f"{'; '.join(changes['no_longer_listed_or_now_occluded'])}.",
                channel="change",
                intrinsic_salience=1.0,
                tick=tick,
            )

        audio = row["audio"]
        audio_text = audio["human_assertion"]["text"]
        audio_key = "audio-assertion:" + sha256(audio_text.encode("utf-8")).hexdigest()
        self.observe_fact(
            key=audio_key,
            text=f"At tick {tick}, {audio_text}",
            channel=audio["human_assertion"].get("attention_channel", "audio"),
            intrinsic_salience=0.62,
            tick=tick,
        )
        for token in audio["new_phonetic_portuguese_tokens"]:
            self.observe_fact(
                key=f"audio-token:{token['segment_id']}:{token['token_index']}",
                text=f"At tick {tick}, the uncertain heard sound added {token['token']} to phrase "
                f"{token['segment_id']}.",
                channel="audio",
                intrinsic_salience=0.82,
                tick=tick,
            )

    def _score(self, fact: AttentionFact, tick: int) -> float:
        weight = float(self.snapshot.weights.get(fact.channel, 0.0))
        age = max(0, tick - fact.last_tick)
        decay_ticks = DEFAULT_DECAY_TICKS.get(fact.channel, 60)
        recency = exp(-age / decay_ticks)
        habituation = (
            1.0
            if fact.channel in CONTROL_CHANNELS
            else 1 / (1 + 0.015 * max(0, fact.exposures - 1))
        )
        novelty = 0.35 if tick - fact.first_tick <= 1 else 0.0
        return weight * (fact.intrinsic_salience * recency * habituation + novelty)

    @staticmethod
    def _priority_class(channel: str) -> int:
        if channel in CONTROL_CHANNELS:
            return 0
        if channel in EXECUTIVE_CHANNELS:
            return 1
        if channel in DETAIL_CHANNELS:
            return 3
        return 2

    def filter(self, tick: int) -> str:
        """Apply one frozen weight snapshot and return a whole-fact bounded summary."""

        scored = [
            (self._score(fact, tick), fact)
            for fact in self.facts.values()
        ]
        scored = [pair for pair in scored if pair[0] >= self.snapshot.forget_below]
        scored.sort(key=lambda pair: (
            self._priority_class(pair[1].channel),
            -pair[0],
            -pair[1].last_tick,
            pair[1].key,
        ))
        self.last_candidate_count = len(scored)
        self.last_candidate_by_channel = {}
        for _, fact in scored:
            self.last_candidate_by_channel[fact.channel] = (
                self.last_candidate_by_channel.get(fact.channel, 0) + 1
            )

        heading = f"Attention summary through tick {max(0, tick - 1)}."
        remaining = self.context_word_limit - word_count(heading)
        selected: list[AttentionFact] = []
        for _, fact in scored:
            fact_words = word_count(fact.text)
            if fact_words <= remaining:
                selected.append(fact)
                remaining -= fact_words
        selected.sort(key=lambda fact: (fact.first_tick, fact.last_tick, fact.key))
        summary = "\n".join([heading, *(fact.text for fact in selected)])
        self.last_selected_count = len(selected)
        self.last_selected_by_channel = {}
        for fact in selected:
            self.last_selected_by_channel[fact.channel] = (
                self.last_selected_by_channel.get(fact.channel, 0) + 1
            )
        self.last_summary_words = word_count(summary)
        return summary


def _percent(value: float) -> str:
    return f"{100 * value:.1f} percent"


def _joined(values: Sequence[object], empty: str = "none") -> str:
    return "; ".join(str(value) for value in values) if values else empty


def render_tick_update(row: Mapping[str, Any]) -> str:
    """Render the current tick as complete prose updates outside the filtered summary."""

    tick = int(row["tick"])
    interval = row["interval"]
    visual = row["visual"]
    lines = [
        f"Tick {tick} covers {interval['start_ms']} through {interval['end_ms']} milliseconds.",
        "Visual observations",
    ]
    for assertion in visual["human_assertions"]:
        lines.append(
            f"{assertion['text']} Confidence {round(100 * float(assertion['confidence']))} percent."
        )
    lines.append("Individual sampled frames")
    for frame in visual["frame_observations"]:
        dominant = "; ".join(
            f"{color['hex']} at {_percent(float(color['fraction']))}"
            for color in frame["dominant_colors"]
        )
        lines.append(
            f"Frame {frame['frame_index']} at {frame['pts_ms']} milliseconds has average color "
            f"{frame['mean_hex']}, luminance {frame['mean_luminance']}, dominant colors {dominant}, "
            f"near-black area {_percent(float(frame['near_black_fraction']))}, near-white area "
            f"{_percent(float(frame['near_white_fraction']))}, and change from the previous frame "
            f"{frame['delta_from_previous_frame']}.")
    lines.append("Visible objects")
    for obj in visual["visible_objects"]:
        lines.append(
            f"{obj['id']} is {obj['category']}. Colors: {_joined(obj.get('colors', []))}. "
            f"Material: {obj.get('material', 'unknown')}. Appearance: "
            f"{obj.get('appearance', 'unknown')}."
        )
    changes = row["changes_from_previous_tick"]
    lines.extend([
        "Changes since the previous tick",
        f"Scene phase changed: {'yes' if changes['phase_changed'] else 'no'}.",
        "Newly visible or reclassified: "
        + _joined(changes["newly_visible_or_reclassified"]) + ".",
        "No longer listed or now occluded: "
        + _joined(changes["no_longer_listed_or_now_occluded"]) + ".",
    ])
    audio = row["audio"]
    lines.extend(["Audio observations", audio["human_assertion"]["text"]])
    for token in audio["new_phonetic_portuguese_tokens"]:
        lines.append(
            f"Around {token['estimated_audible_end_ms']} milliseconds, the uncertain heard sound "
            f"adds {token['token']} to phrase {token['segment_id']}."
        )
    rendered = "\n".join(lines).translate(PROSE_TRANSLATION)
    if any(character in rendered for character in FORBIDDEN_DELIMITERS):
        raise ValueError("rendered tick update contains a forbidden structured-data delimiter")
    return rendered


def render_cognition_input(
    attention_summary: str,
    row: Mapping[str, Any],
    speech_runtime: Mapping[str, Any],
    probes: Sequence[Mapping[str, Any]],
    social_messages: Sequence[Mapping[str, Any]] = (),
    context_events: Sequence[Mapping[str, Any]] = (),
) -> str:
    probe_lines = (
        "\n".join(f"{probe['id']}: {probe['question']}" for probe in probes)
        if probes else "No questions are due."
    )
    social_lines = (
        "\n".join(
            f"{message['sender']} messaged: {message['text']}"
            for message in social_messages
        )
        if social_messages else "No new social message arrived."
    )
    context_lines = (
        "\n".join(
            f"With {round(100 * float(event['confidence']))} percent confidence: "
            f"{event['text']}"
            for event in context_events
        )
        if context_events else "No additional precomputed situation fact arrived."
    )
    text = (
        "Summary selected by Attention from earlier ticks\n"
        f"{attention_summary.strip()}\n\n"
        "Updates from the current tick\n"
        f"{render_tick_update(row)}\n\n"
        "Additional current situation facts\n"
        f"{context_lines}\n\n"
        "Current speech situation\n"
        "Retained self-speech is already committed history, never text awaiting repetition.\n"
        f"Speech is currently playing: {'yes' if speech_runtime.get('currently_audible') else 'no'}\n"
        f"Words still queued for playback: {speech_runtime.get('queued_words', 0)}\n"
        f"Speech fragments still queued: {speech_runtime.get('pending_fragments', 0)}\n"
        "Speech motor buffer can accept an ordinary new fragment: "
        f"{'yes' if speech_runtime.get('can_accept_fragment') else 'no'}\n\n"
        "New social messages\n"
        f"{social_lines}\n\n"
        "Questions due now\n"
        f"{probe_lines}"
    )
    text = text.translate(PROSE_TRANSLATION)
    if any(character in text for character in FORBIDDEN_DELIMITERS):
        raise ValueError("cognition input contains a forbidden structured-data delimiter")
    return text


def _response_sections(
    text: str,
    answers_required: bool = True,
) -> tuple[str, str, str]:
    if any(character in text for character in FORBIDDEN_DELIMITERS):
        raise ValueError("response contains a forbidden structured-data delimiter")
    names = {"cognition": None, "speech": None, "answers": None}
    inline = {"cognition": "", "speech": "", "answers": ""}
    lines = text.strip().splitlines()
    for index, line in enumerate(lines):
        stripped = line.strip()
        head, separator, tail = stripped.partition(":")
        key = (
            head.strip().lower()
            if separator and head.strip().lower() in names
            else stripped.rstrip(":").lower()
        )
        if key in names:
            # Small models sometimes emit an inline template decision and then
            # revise it in the canonical section below. Treat the later
            # occurrence as the committed decision. This is normalization of
            # one model response, not another cognitive or Attention pass.
            names[key] = index
            inline[key] = tail.strip() if separator else ""
    if names["speech"] is None:
        compact = [line.strip() for line in lines if line.strip()]
        if names["cognition"] == 0:
            compact = compact[1:]
        cognition_lines: list[str] = []
        if compact and compact[0].rstrip(".").lower() in {"none", "no changes"}:
            cognition_lines.append(compact.pop(0))
        else:
            while compact:
                head, separator, _ = compact[0].partition(":")
                if not separator or head.strip() not in COGNITION_SLOTS:
                    break
                cognition_lines.append(compact.pop(0))
        cognition = "\n".join(cognition_lines) or "None"
        tail = compact
        if tail:
            head, separator, value = tail[0].partition(":")
            is_answers_heading = (
                head.strip().lower() == "answers"
                if separator
                else tail[0].rstrip(":").strip().lower() == "answers"
            )
        else:
            is_answers_heading = False
            value = ""
        if is_answers_heading:
            # A missing Speech section cannot authorize a motor action. Some
            # small models jump directly from Cognition to due Answers; make
            # that omission deterministic silence and keep the answer intact.
            speech = "Silence"
            tail.pop(0)
            if value.strip():
                tail.insert(0, value.strip())
        elif tail:
            speech = tail.pop(0)
        else:
            raise ValueError("response has neither a speech decision nor answers")
        if tail:
            head, separator, value = tail[0].partition(":")
            if head.strip().lower() == "answers":
                tail.pop(0)
                if separator and value.strip():
                    tail.insert(0, value.strip())
        if answers_required and not tail:
            raise ValueError("response has no due probe answer after speech")
        return cognition, speech, "\n".join(tail).strip() or "None"
    speech_index = int(names["speech"])
    cognition_index = names["cognition"]
    if cognition_index is None:
        cognition = "\n".join(lines[:speech_index]).strip()
    else:
        cognition_index = int(cognition_index)
        if cognition_index != 0 or not cognition_index < speech_index:
            raise ValueError("response headings are out of order")
        if inline["cognition"]:
            raise ValueError("Cognition heading cannot contain inline slot text")
        cognition = "\n".join(lines[cognition_index + 1:speech_index]).strip()
    if not cognition:
        cognition = "None"
    if names["answers"] is None:
        if answers_required:
            raise ValueError("response must contain an Answers heading")
        speech = "\n".join([
            inline["speech"],
            *lines[speech_index + 1:],
        ]).strip()
        if not speech:
            raise ValueError("response has no speech decision")
        return cognition, speech, "None"
    answers_index = int(names["answers"])
    if not speech_index < answers_index:
        raise ValueError("response headings are out of order")
    return (
        cognition,
        "\n".join([
            inline["speech"],
            *lines[speech_index + 1:answers_index],
        ]).strip(),
        "\n".join([
            inline["answers"],
            *lines[answers_index + 1:],
        ]).strip(),
    )


def _cognitive_updates(text: str) -> list[dict[str, str]]:
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    if len(lines) == 1 and lines[0].rstrip(".").lower() in {"none", "no changes"}:
        return []
    lines = [
        line
        for line in lines
        if line.rstrip(".").lower() not in {"none", "no changes"}
    ]
    if not lines or len(lines) > 2 * len(COGNITION_SLOTS):
        raise ValueError("Cognition must contain only bounded fixed-slot deltas")
    updates: list[dict[str, str]] = []
    slot_positions: dict[str, int] = {}
    for line in lines:
        head = line.partition(":")[0].strip()
        if head not in COGNITION_SLOTS:
            continue
        slot = head
        value = _field(line, slot)
        if not value:
            raise ValueError(f"{slot} cognitive slot is empty")
        normalized = value.rstrip(".").lower()
        # Small models sometimes append a template placeholder to a labeled
        # slot even though omission is the no-change operation. A placeholder
        # is normalized to omission at the boundary. In particular, it must
        # never erase an unresolved belief such as Camera operator: Unknown.
        # Confirmed absence is an ordinary prose replacement.
        if normalized in {"keep", "clear", "none"}:
            continue
        operation = "replace"
        replacement = plain_prose(
            value.split(";", 1)[1]
            if normalized.startswith("clear;")
            else value
        )
        if word_count(replacement) > MAX_COGNITION_SLOT_WORDS:
            raise ValueError(
                f"{slot} cognitive slot exceeds {MAX_COGNITION_SLOT_WORDS} words"
            )
        update = {
            "slot": slot,
            "operation": operation,
            "text": replacement,
        }
        if slot in slot_positions:
            updates[slot_positions[slot]] = update
        else:
            slot_positions[slot] = len(updates)
            updates.append(update)
    return updates


def _speech_decision(text: str, tick: int) -> dict[str, Any]:
    words = " ".join(line.strip() for line in text.splitlines() if line.strip()).strip()
    if not words:
        raise ValueError("Speech section is empty")
    if words.rstrip(".").lower() in {"silence", "nothing", "none"}:
        action = "silence"
        words = ""
        utterance_id = None
    else:
        action = "say"
        utterance_id = f"utterance-{tick}"
    return {
        "action": action,
        "utterance_id": utterance_id,
        "new_words": words,
        "continues_after_tick": False,
        "reason_codes": ["cognition"],
        "based_on_ticks": [tick],
    }


def _field(line: str, label: str) -> str:
    head, separator, tail = line.partition(":")
    if not separator or head.strip().lower() != label.lower():
        raise ValueError(f"expected {label} field")
    return tail.strip()


def _probe_answers(text: str, probes: Sequence[Mapping[str, Any]]) -> list[dict[str, Any]]:
    if not probes:
        if text.strip().rstrip(".").lower() not in {
            "none",
            "no questions",
            "no questions are due",
        }:
            raise ValueError("Answers must say No questions when no question is due")
        return []
    blocks = re.split(r"(?im)^(?=Question\s*:?\s*[^\s:]+\s*$)", text.strip())
    blocks = [block.strip() for block in blocks if block.strip()]
    answers: list[dict[str, Any]] = []
    for block in blocks:
        lines = [line.strip() for line in block.splitlines() if line.strip()]
        question_match = (
            re.fullmatch(r"Question\s*:?\s*([^\s:]+)", lines[0], re.IGNORECASE)
            if lines else None
        )
        if len(lines) != 6 or question_match is None:
            raise ValueError("each probe answer must use the six-line question format")
        alternatives_text = _field(lines[4], "Alternatives")
        answers.append({
            "id": question_match.group(1),
            "answer": _field(lines[1], "Response"),
            "confidence": float(_field(lines[2], "Confidence")),
            "evidence_ticks": [int(value) for value in re.findall(r"\d+", _field(lines[3], "Evidence ticks"))],
            "alternatives": [] if alternatives_text.lower() in {
                "none",
                "no alternatives",
            } else [
                value.strip() for value in alternatives_text.split(";") if value.strip()
            ],
            "what_remains_unknown": _field(lines[5], "Still unknown"),
        })
    expected = [probe["id"] for probe in probes]
    actual = [answer["id"] for answer in answers]
    if actual != expected:
        raise ValueError(f"probe answer order differs from questions due: {actual}")
    return answers


def parse_cognition_response(
    text: str,
    tick: int,
    probes: Sequence[Mapping[str, Any]],
) -> CognitionDecision:
    cognition, speech, answers = _response_sections(text, answers_required=bool(probes))
    return CognitionDecision(
        cognitive_updates=_cognitive_updates(cognition),
        speech=_speech_decision(speech, tick),
        probe_answers=_probe_answers(answers, probes),
    )
