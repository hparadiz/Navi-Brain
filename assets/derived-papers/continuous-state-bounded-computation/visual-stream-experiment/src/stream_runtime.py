"""Persistent state and speech mechanics for a chronological visual situation."""

from __future__ import annotations

from dataclasses import dataclass, field
from hashlib import sha256
from typing import Any, Iterable, Mapping
import json
import re


ALLOWED_STATE_ROOTS = {
    "world",
    "self_theory_of_mind",
    "other_theories_of_mind",
    "working_narrative",
    "unresolved_questions",
    "provenance",
}
SPEECH_ACTIONS = {"silence", "say", "start", "continue", "finish", "interrupt", "repair"}
MAX_EXTERNAL_ECHO_REFERENCES = 64
WORD_RE = re.compile(r"\b[^\W_]+(?:['’-][^\W_]+)*\b", re.UNICODE)
EARLY_PERSON_RE = re.compile(r"\b(?:he|him|his|man|guy|dude|homem|ele)\b", re.IGNORECASE)


def canonical_json(value: object) -> str:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"), sort_keys=True)


def object_hash(value: object) -> str:
    return sha256(canonical_json(value).encode("utf-8")).hexdigest()


def jsonl_rows(path: Any) -> list[dict[str, Any]]:
    with path.open(encoding="utf-8") as handle:
        return [json.loads(line) for line in handle if line.strip()]


def word_count(text: str) -> int:
    return len(WORD_RE.findall(text))


def sentence_count(text: str) -> int:
    return len(re.findall(r"[.!?]+(?:[\"'’)]|\s|$)", text))


def _pointer_parts(path: str) -> list[str]:
    if not path.startswith("/") or path == "/":
        raise ValueError(f"state update path must be a non-root JSON Pointer: {path!r}")
    parts = path[1:].split("/")
    decoded = [part.replace("~1", "/").replace("~0", "~") for part in parts]
    if any(part in {"", ".", ".."} for part in decoded):
        raise ValueError(f"state update path has an invalid segment: {path!r}")
    if decoded[0] not in ALLOWED_STATE_ROOTS:
        raise ValueError(f"state update root is not allowed: {path!r}")
    return decoded


def _set_copy_on_write(node: Mapping[str, Any], parts: list[str], value: Any) -> dict[str, Any]:
    result = dict(node)
    key = parts[0]
    if len(parts) == 1:
        result[key] = value
        return result
    child = node.get(key, {})
    if not isinstance(child, Mapping):
        raise ValueError(f"state path crosses a non-object at {key!r}")
    result[key] = _set_copy_on_write(child, parts[1:], value)
    return result


def apply_updates(
    state: Mapping[str, Any],
    updates: Iterable[Mapping[str, Any]],
) -> tuple[dict[str, Any], list[str], list[str]]:
    """Apply leaf/object replacements while preserving untouched component identities."""

    result: dict[str, Any] = dict(state)
    paths: list[str] = []
    seen: set[str] = set()
    components: list[str] = []
    for update in updates:
        if set(update) != {"path", "value"}:
            raise ValueError("each update must contain exactly path and value")
        path = update["path"]
        value = update["value"]
        if not isinstance(path, str):
            raise ValueError("state update path must be a string")
        if path in seen:
            raise ValueError(f"duplicate state update path: {path}")
        seen.add(path)
        parts = _pointer_parts(path)
        result = _set_copy_on_write(result, parts, value)
        paths.append(path)
        if parts[0] not in components:
            components.append(parts[0])
    return result, paths, components


def validate_state_shape(state: Mapping[str, Any]) -> None:
    for root in ALLOWED_STATE_ROOTS:
        if root not in state:
            raise ValueError(f"state is missing required root: {root}")
    for root in {"world", "self_theory_of_mind", "other_theories_of_mind"}:
        if not isinstance(state[root], Mapping):
            raise ValueError(f"state root must remain an object: {root}")
    for root in {"working_narrative", "unresolved_questions", "provenance"}:
        if not isinstance(state[root], list):
            raise ValueError(f"state root must remain an array: {root}")
    camera = state["world"].get("camera")
    if not isinstance(camera, Mapping):
        raise ValueError("world.camera must remain an object")
    for role in {
        "current_controller",
        "physical_support",
        "probable_setup_actor",
        "recording_audience",
    }:
        record = camera.get(role)
        if not isinstance(record, Mapping) or not {"value", "confidence", "since_tick"} <= set(record):
            raise ValueError(f"camera role must remain a value/confidence/since_tick record: {role}")


def future_tick_references(updates: Iterable[Mapping[str, Any]], current_tick: int) -> list[int]:
    found: set[int] = set()

    def scan(value: Any) -> None:
        if isinstance(value, str):
            for match in re.finditer(r"\btick\s*[:#-]?\s*(\d+)\b", value, re.IGNORECASE):
                cited = int(match.group(1))
                if cited > current_tick:
                    found.add(cited)
        elif isinstance(value, Mapping):
            for child in value.values():
                scan(child)
        elif isinstance(value, list):
            for child in value:
                scan(child)

    for update in updates:
        if not isinstance(update, Mapping):
            raise ValueError("state update is not an object")
        scan(update.get("value"))
    return sorted(found)


@dataclass(slots=True)
class QueuedWord:
    text: str
    remaining_ms: float


@dataclass(slots=True)
class SpeechRuntime:
    words_per_minute: float = 165.0
    max_pending_fragments: int = 1
    active_utterance_id: str | None = None
    audible_words: list[str] = field(default_factory=list)
    committed_fragments: list[str] = field(default_factory=list)
    queue: list[QueuedWord] = field(default_factory=list)
    pending_fragment_ends: list[int] = field(default_factory=list)
    external_echo_references: list[str] = field(default_factory=list)

    @staticmethod
    def _normalized_phrase(text: str) -> str:
        return " ".join(text.casefold().split()).strip(" .!?…")

    def observe_external_phrase(self, text: str) -> None:
        """Retain bounded heard or received language that must not be parroted."""

        normalized = self._normalized_phrase(text)
        if not normalized:
            return
        if normalized in self.external_echo_references:
            self.external_echo_references.remove(normalized)
        self.external_echo_references.append(normalized)
        del self.external_echo_references[:-MAX_EXTERNAL_ECHO_REFERENCES]

    def advance(self, elapsed_ms: int) -> None:
        remaining = float(elapsed_ms)
        while self.queue and remaining > 0:
            word = self.queue[0]
            if remaining < word.remaining_ms:
                word.remaining_ms -= remaining
                remaining = 0
            else:
                remaining -= word.remaining_ms
                self.audible_words.append(word.text)
                self.queue.pop(0)
                self.pending_fragment_ends = [end - 1 for end in self.pending_fragment_ends]
                while self.pending_fragment_ends and self.pending_fragment_ends[0] <= 0:
                    self.pending_fragment_ends.pop(0)

    def view(self) -> dict[str, Any]:
        return {
            "active_utterance_id": self.active_utterance_id,
            "currently_audible": bool(self.queue),
            "queued_words": len(self.queue),
            "pending_fragments": len(self.pending_fragment_ends),
            "can_accept_fragment": (
                len(self.pending_fragment_ends) < self.max_pending_fragments
            ),
            "external_echo_reference_count": len(self.external_echo_references),
        }

    def commit(
        self,
        speech: Mapping[str, Any],
        tick: int,
        external_inhibition_reason: str | None = None,
    ) -> tuple[dict[str, Any], list[str]]:
        required = {
            "action",
            "utterance_id",
            "new_words",
            "continues_after_tick",
            "reason_codes",
            "based_on_ticks",
        }
        if set(speech) != required:
            missing = sorted(required - set(speech))
            extra = sorted(set(speech) - required)
            raise ValueError(f"speech fields mismatch; missing={missing}, extra={extra}")
        action = speech["action"]
        utterance_id = speech["utterance_id"]
        new_words = speech["new_words"]
        continues = speech["continues_after_tick"]
        if action not in SPEECH_ACTIONS:
            raise ValueError(f"invalid speech action: {action!r}")
        if utterance_id is not None and not isinstance(utterance_id, str):
            raise ValueError("utterance_id must be a string or null")
        if not isinstance(new_words, str) or not isinstance(continues, bool):
            raise ValueError("new_words must be a string and continues_after_tick a boolean")
        if not isinstance(speech["reason_codes"], list) or not all(
            isinstance(value, str) for value in speech["reason_codes"]
        ):
            raise ValueError("reason_codes must be an array of strings")
        if not isinstance(speech["based_on_ticks"], list) or not all(
            isinstance(value, int) and 1 <= value <= tick for value in speech["based_on_ticks"]
        ):
            raise ValueError("based_on_ticks must cite current or earlier positive ticks")

        violations: list[str] = []
        words = word_count(new_words)
        if words > 8:
            violations.append("speech_more_than_8_words")
        if sentence_count(new_words) > 1:
            violations.append("speech_more_than_1_sentence")
        if tick < 23 and EARLY_PERSON_RE.search(new_words):
            violations.append("speech_mentions_person_before_creation_threshold")

        proposed_action = action
        proposed_utterance_id = utterance_id
        proposed_words = new_words.strip()
        proposed_continues = continues
        buffer_busy = bool(
            proposed_words
            and len(self.pending_fragment_ends) >= self.max_pending_fragments
            and action not in {"interrupt", "repair"}
        )
        remembered_external_echo = bool(
            proposed_words
            and self._normalized_phrase(proposed_words) in self.external_echo_references
        )
        effective_external_reason = (
            external_inhibition_reason
            or ("external_phrase_verbatim_echo" if remembered_external_echo else None)
        )
        externally_inhibited = bool(proposed_words and effective_external_reason)
        motor_inhibited = buffer_busy or externally_inhibited
        motor_inhibition_reason = (
            effective_external_reason
            if externally_inhibited
            else ("speech_motor_buffer_busy" if buffer_busy else None)
        )
        reason_codes = list(speech["reason_codes"])
        if motor_inhibited:
            action = "silence"
            utterance_id = self.active_utterance_id
            new_words = ""
            continues = self.active_utterance_id is not None
            assert motor_inhibition_reason is not None
            reason_codes.append(motor_inhibition_reason)

        if action == "silence":
            if new_words.strip():
                violations.append("silence_contains_words")
            if utterance_id not in {None, self.active_utterance_id}:
                violations.append("silence_changes_utterance_id")
        elif action == "say":
            if not utterance_id or not new_words.strip():
                violations.append("say_missing_id_or_words")
        elif action == "start":
            if self.active_utterance_id is not None:
                violations.append("start_while_utterance_active")
            if not utterance_id or not new_words.strip():
                violations.append("start_missing_id_or_words")
            self.active_utterance_id = utterance_id
        elif action in {"continue", "finish"}:
            if self.active_utterance_id is None:
                violations.append(f"{action}_without_active_utterance")
            if utterance_id != self.active_utterance_id:
                violations.append(f"{action}_utterance_id_mismatch")
            if action == "continue" and not new_words.strip():
                violations.append("continue_without_words")
            if action == "finish":
                self.active_utterance_id = None
        elif action == "interrupt":
            if self.active_utterance_id is None:
                violations.append("interrupt_without_active_utterance")
            self.active_utterance_id = None
            if new_words.strip() and not utterance_id:
                violations.append("interrupt_words_missing_new_utterance_id")
        elif action == "repair":
            if not utterance_id or not new_words.strip():
                violations.append("repair_missing_id_or_words")

        if continues != (self.active_utterance_id is not None):
            violations.append("continues_after_tick_disagrees_with_runtime")

        clean_fragment = new_words.strip()
        if clean_fragment:
            fragment_words = WORD_RE.findall(clean_fragment)
            per_word_ms = 60_000.0 / self.words_per_minute
            self.queue.extend(QueuedWord(text=word, remaining_ms=per_word_ms) for word in fragment_words)
            self.pending_fragment_ends.append(len(self.queue))
            self.committed_fragments.append(clean_fragment)
        if len(self.pending_fragment_ends) > self.max_pending_fragments:
            violations.append("speech_queue_exceeds_fragment_limit")

        event = {
            "tick": tick,
            "action": action,
            "utterance_id": utterance_id,
            "new_words": clean_fragment,
            "continues_after_tick": continues,
            "reason_codes": reason_codes,
            "based_on_ticks": speech["based_on_ticks"],
            "proposed_action": proposed_action,
            "proposed_utterance_id": proposed_utterance_id,
            "proposed_words": proposed_words,
            "proposed_continues_after_tick": proposed_continues,
            "motor_inhibited": motor_inhibited,
            "motor_inhibition_reason": motor_inhibition_reason,
            "queue_depth": len(self.pending_fragment_ends),
            "queued_words": len(self.queue),
            "currently_audible": bool(self.queue),
            "audible_text_so_far": " ".join(self.audible_words),
            "violations": violations,
        }
        return event, violations

    def committed_transcript(self) -> str:
        return " ".join(self.committed_fragments).strip()


def behavioral_state_violations(
    tick: int,
    update_paths: Iterable[str],
    state: Mapping[str, Any],
) -> list[str]:
    violations: list[str] = []
    if tick < 23:
        entities = state.get("world", {}).get("entities", {})
        other_minds = state.get("other_theories_of_mind", {})
        person_like_entity = re.search(
            r"\b(?:person|adult man|human|homem)\b",
            canonical_json(entities),
            re.IGNORECASE,
        )
        if person_like_entity or other_minds:
            violations.append("person_or_other_mind_created_before_tick_23")
            return violations
        for path in update_paths:
            lowered = path.lower()
            if "/person_1" in lowered or lowered.startswith("/other_theories_of_mind/"):
                violations.append("person_or_other_mind_created_before_tick_23")
                break
    return violations
