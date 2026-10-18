"""OpenAI-compatible adapters for cognition and frozen-run evaluation."""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from time import perf_counter
from typing import Any, Mapping, Sequence
from urllib.request import Request, urlopen
import json


FORBIDDEN_MODEL_CONTEXT_DELIMITERS = "{}[]"


def validate_prose_model_context(system: str, user_text: str) -> None:
    for label, value in (("system", system), ("user", user_text)):
        if not isinstance(value, str):
            raise TypeError(f"{label} model context must already be rendered as prose")
        if any(character in value for character in FORBIDDEN_MODEL_CONTEXT_DELIMITERS):
            raise ValueError(f"{label} model context contains a forbidden JSON delimiter")


STRUCTURED_RESPONSE_SCHEMA: dict[str, Any] = {
    "type": "object",
    "properties": {
        "updates": {
            "type": "array",
            "maxItems": 4,
            "items": {
                "type": "object",
                "properties": {
                    "path": {
                        "type": "string",
                        "maxLength": 160,
                        "pattern": "^/(world|self_theory_of_mind|other_theories_of_mind|working_narrative|unresolved_questions|provenance)(/.*)?$",
                    },
                    "value": {
                        "type": ["string", "number", "boolean", "array", "object", "null"]
                    },
                },
                "required": ["path", "value"],
                "additionalProperties": False,
            },
        },
        "speech": {
            "type": "object",
            "properties": {
                "action": {
                    "enum": ["silence", "start", "continue", "finish", "interrupt", "repair"]
                },
                "utterance_id": {"type": ["string", "null"]},
                "new_words": {"type": "string", "maxLength": 100},
                "continues_after_tick": {"type": "boolean"},
                "reason_codes": {
                    "type": "array",
                    "maxItems": 4,
                    "items": {"type": "string"},
                },
                "based_on_ticks": {
                    "type": "array",
                    "maxItems": 8,
                    "items": {"type": "integer"},
                },
            },
            "required": [
                "action",
                "utterance_id",
                "new_words",
                "continues_after_tick",
                "reason_codes",
                "based_on_ticks",
            ],
            "additionalProperties": False,
        },
        "probe_answers": {
            "type": "array",
            "maxItems": 4,
            "items": {
                "type": "object",
                "properties": {
                    "id": {"type": "string"},
                    "answer": {"type": "string"},
                    "confidence": {"type": "number", "minimum": 0, "maximum": 1},
                    "evidence_ticks": {
                        "type": "array",
                        "maxItems": 12,
                        "items": {"type": "integer"},
                    },
                    "alternatives": {
                        "type": "array",
                        "maxItems": 6,
                        "items": {"type": "string"},
                    },
                    "what_remains_unknown": {"type": "string"},
                },
                "required": [
                    "id",
                    "answer",
                    "confidence",
                    "evidence_ticks",
                    "alternatives",
                    "what_remains_unknown",
                ],
                "additionalProperties": False,
            },
        },
    },
    "required": ["updates", "speech", "probe_answers"],
    "additionalProperties": False,
}


JUDGE_DIMENSIONS = [
    "whole_stream_coherence",
    "referential_continuity",
    "temporal_honesty",
    "natural_social_voice",
    "responsiveness",
    "belief_continuity_and_repair",
    "surprise_calibration",
    "emotional_continuity",
    "need_guided_control",
    "compression_and_rhythm",
]


JUDGE_RESPONSE_SCHEMA: dict[str, Any] = {
    "type": "object",
    "properties": {
        "scores": {
            "type": "array",
            "minItems": len(JUDGE_DIMENSIONS),
            "maxItems": len(JUDGE_DIMENSIONS),
            "items": {
                "type": "object",
                "properties": {
                    "dimension": {"enum": JUDGE_DIMENSIONS},
                    "score": {"type": "integer", "minimum": 0, "maximum": 4},
                    "rationale": {"type": "string"},
                    "evidence_ticks": {
                        "type": "array",
                        "maxItems": 12,
                        "items": {"type": "integer"},
                    },
                },
                "required": ["dimension", "score", "rationale", "evidence_ticks"],
                "additionalProperties": False,
            },
        },
        "probe_assessments": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "id": {"type": "string"},
                    "score": {"type": "integer", "minimum": 0, "maximum": 4},
                    "rationale": {"type": "string"},
                },
                "required": ["id", "score", "rationale"],
                "additionalProperties": False,
            },
        },
        "hard_failure_flags": {"type": "array", "items": {"type": "string"}},
        "summary": {"type": "string"},
    },
    "required": ["scores", "probe_assessments", "hard_failure_flags", "summary"],
    "additionalProperties": False,
}


RECOGNITION_RESPONSE_SCHEMA: dict[str, Any] = {
    "type": "object",
    "properties": {
        "recognizes_source": {"enum": ["yes", "no", "unsure"]},
        "candidate_source": {"type": "string"},
        "confidence": {"type": "number", "minimum": 0, "maximum": 1},
        "reason": {"type": "string"},
    },
    "required": ["recognizes_source", "candidate_source", "confidence", "reason"],
    "additionalProperties": False,
}


@dataclass(frozen=True, slots=True)
class JsonModelResult:
    value: Mapping[str, Any]
    prompt_tokens: int
    cached_tokens: int
    completion_tokens: int
    wall_ms: float
    first_token_ms: float | None
    last_token_ms: float | None
    raw_content: str
    timings: Mapping[str, Any]


@dataclass(frozen=True, slots=True)
class TextModelResult:
    text: str
    prompt_tokens: int
    cached_tokens: int
    completion_tokens: int
    wall_ms: float
    first_token_ms: float | None
    last_token_ms: float | None
    raw_content: str
    timings: Mapping[str, Any]


class ModelResponseError(ValueError):
    """A server response arrived with useful accounting but invalid content JSON."""

    def __init__(self, message: str, result: JsonModelResult) -> None:
        super().__init__(message)
        self.result = result


class OpenAIJsonAdapter:
    def __init__(
        self,
        base_url: str,
        model: str,
        seed: int = 42,
        temperature: float = 0.2,
        top_k: int = 20,
        request_timeout_seconds: float = 180,
    ) -> None:
        if request_timeout_seconds <= 0:
            raise ValueError("request timeout must be positive")
        self.url = f"{base_url.rstrip('/')}/v1/chat/completions"
        self.model = model
        self.seed = seed
        self.temperature = temperature
        self.top_k = top_k
        self.request_timeout_seconds = request_timeout_seconds

    def _post(self, payload: dict[str, Any]) -> tuple[dict[str, Any], float]:
        request = Request(
            self.url,
            data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        started = perf_counter()
        with urlopen(request, timeout=self.request_timeout_seconds) as response:
            result = json.load(response)
        return result, (perf_counter() - started) * 1000

    def _post_stream(self, payload: dict[str, Any]) -> tuple[dict[str, Any], float, float | None, float | None]:
        stream_payload = {
            **payload,
            "stream": True,
            "stream_options": {"include_usage": True},
        }
        request = Request(
            self.url,
            data=json.dumps(stream_payload, ensure_ascii=False).encode("utf-8"),
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        started = perf_counter()
        first_token_ms: float | None = None
        last_token_ms: float | None = None
        pieces: list[str] = []
        usage: Mapping[str, Any] = {}
        timings: Mapping[str, Any] = {}
        with urlopen(request, timeout=self.request_timeout_seconds) as response:
            for raw_line in response:
                line = raw_line.decode("utf-8").strip()
                if not line.startswith("data:"):
                    continue
                data = line[5:].strip()
                if data == "[DONE]":
                    break
                chunk = json.loads(data)
                if isinstance(chunk.get("usage"), Mapping):
                    usage = chunk["usage"]
                if isinstance(chunk.get("timings"), Mapping):
                    timings = chunk["timings"]
                choices = chunk.get("choices") or []
                for choice in choices:
                    delta = choice.get("delta") or {}
                    piece = delta.get("content")
                    if not isinstance(piece, str) or not piece:
                        continue
                    elapsed_ms = (perf_counter() - started) * 1000
                    if first_token_ms is None:
                        first_token_ms = elapsed_ms
                    last_token_ms = elapsed_ms
                    pieces.append(piece)
        wall_ms = (perf_counter() - started) * 1000
        content = "".join(pieces)
        return (
            {
                "choices": [{"message": {"content": content}}],
                "usage": dict(usage),
                "timings": dict(timings),
            },
            wall_ms,
            first_token_ms,
            last_token_ms,
        )

    def complete_json(
        self,
        system: str,
        user_value: str,
        schema_name: str,
        schema: Mapping[str, Any],
        max_tokens: int,
    ) -> JsonModelResult:
        validate_prose_model_context(system, user_value)
        payload = {
            "model": self.model,
            "messages": [
                {"role": "system", "content": system},
                {"role": "user", "content": user_value},
            ],
            "temperature": self.temperature,
            "top_k": self.top_k,
            "seed": self.seed,
            "max_tokens": max_tokens,
            "response_format": {
                "type": "json_schema",
                "json_schema": {"name": schema_name, "strict": True, "schema": schema},
            },
        }
        result, wall_ms = self._post(payload)
        choices = result.get("choices")
        if not isinstance(choices, list) or not choices:
            raise ValueError("model response has no choices")
        content = choices[0].get("message", {}).get("content", "")
        if not isinstance(content, str):
            raise ValueError("model response content is not text")
        usage = result.get("usage") or {}
        details = usage.get("prompt_tokens_details") or {}
        cached_tokens = details.get("cached_tokens", usage.get("cached_tokens", 0))
        common = dict(
            prompt_tokens=int(usage.get("prompt_tokens", 0) or 0),
            cached_tokens=int(cached_tokens or 0),
            completion_tokens=int(usage.get("completion_tokens", 0) or 0),
            wall_ms=wall_ms,
            first_token_ms=None,
            last_token_ms=None,
            raw_content=content,
            timings=result.get("timings") or {},
        )
        try:
            parsed = json.loads(content)
        except json.JSONDecodeError as exc:
            failed = JsonModelResult(value={}, **common)
            raise ModelResponseError(
                f"invalid content JSON at line {exc.lineno} column {exc.colno}: {exc.msg}",
                failed,
            ) from exc
        if not isinstance(parsed, dict):
            failed = JsonModelResult(value={}, **common)
            raise ModelResponseError("model response is not a JSON object", failed)
        return JsonModelResult(value=parsed, **common)

    def complete_text(
        self,
        system: str,
        user_text: str,
        max_tokens: int,
    ) -> TextModelResult:
        validate_prose_model_context(system, user_text)
        payload = {
            "model": self.model,
            "messages": [
                {"role": "system", "content": system},
                {"role": "user", "content": user_text},
            ],
            "temperature": self.temperature,
            "top_k": self.top_k,
            "seed": self.seed,
            "max_tokens": max_tokens,
        }
        result, wall_ms, first_token_ms, last_token_ms = self._post_stream(payload)
        choices = result.get("choices")
        if not isinstance(choices, list) or not choices:
            raise ValueError("model response has no choices")
        content = choices[0].get("message", {}).get("content", "")
        if not isinstance(content, str):
            raise ValueError("model response content is not text")
        usage = result.get("usage") or {}
        details = usage.get("prompt_tokens_details") or {}
        cached_tokens = details.get("cached_tokens", usage.get("cached_tokens", 0))
        return TextModelResult(
            text=content,
            prompt_tokens=int(usage.get("prompt_tokens", 0) or 0),
            cached_tokens=int(cached_tokens or 0),
            completion_tokens=int(usage.get("completion_tokens", 0) or 0),
            wall_ms=wall_ms,
            first_token_ms=first_token_ms,
            last_token_ms=last_token_ms,
            raw_content=content,
            timings=result.get("timings") or {},
        )


class LegacyStructuredAdapter:
    def __init__(self, client: OpenAIJsonAdapter, system_prompt_path: Path) -> None:
        self.client = client
        self.system = system_prompt_path.read_text(encoding="utf-8")

    def decide(self, payload: str, max_tokens: int) -> JsonModelResult:
        return self.client.complete_json(
            self.system,
            payload,
            "visual_tick_decision",
            STRUCTURED_RESPONSE_SCHEMA,
            max_tokens,
        )


class CognitionAdapter:
    def __init__(
        self,
        client: OpenAIJsonAdapter,
        system_prompt_path: Path,
        personality_path: Path,
    ) -> None:
        instructions = system_prompt_path.read_text(encoding="utf-8").strip()
        personality = personality_path.read_text(encoding="utf-8").strip()
        self.client = client
        self.system = f"{instructions}\n\nViewer personality\n{personality}"

    def decide(self, payload: str, max_tokens: int) -> TextModelResult:
        return self.client.complete_text(self.system, payload, max_tokens)


class FrozenRunJudgeAdapter:
    def __init__(self, client: OpenAIJsonAdapter, system_prompt_path: Path) -> None:
        self.client = client
        self.system = system_prompt_path.read_text(encoding="utf-8")

    def judge(self, payload: str, max_tokens: int = 4096) -> JsonModelResult:
        return self.client.complete_json(
            self.system,
            payload,
            "frozen_visual_stream_evaluation",
            JUDGE_RESPONSE_SCHEMA,
            max_tokens,
        )

    def recognize(self, payload: str, max_tokens: int = 384) -> JsonModelResult:
        system = (
            "This is an isolated post-run source-recognition probe. The behavioral run is frozen "
            "and this answer must not alter it. Based only on the supplied anonymous final state "
            "and transcript, say whether the scene resembles media you know. Do not invent a source. "
            "Emit only the required JSON object."
        )
        return self.client.complete_json(
            system,
            payload,
            "isolated_source_recognition",
            RECOGNITION_RESPONSE_SCHEMA,
            max_tokens,
        )


def validate_structured_shape(value: Mapping[str, Any]) -> None:
    if set(value) != {"updates", "speech", "probe_answers"}:
        raise ValueError("response must contain exactly updates, speech, and probe_answers")
    if not isinstance(value["updates"], list) or len(value["updates"]) > 4:
        raise ValueError("updates must be an array with at most 4 items")
    if not isinstance(value["speech"], dict):
        raise ValueError("speech must be an object")
    if not isinstance(value["probe_answers"], list):
        raise ValueError("probe_answers must be an array")


def validate_probe_answers(
    answers: Sequence[Mapping[str, Any]],
    probes: Sequence[Mapping[str, Any]],
    tick: int,
) -> list[str]:
    violations: list[str] = []
    expected = [probe["id"] for probe in probes]
    actual = [answer.get("id") if isinstance(answer, Mapping) else None for answer in answers]
    if actual != expected:
        violations.append(f"probe_id_sequence_mismatch:expected={expected},actual={actual}")
    for answer in answers:
        if not isinstance(answer, Mapping):
            violations.append("probe_answer_not_object")
            continue
        evidence_ticks = answer.get("evidence_ticks")
        if not isinstance(evidence_ticks, list) or not all(
            isinstance(value, int) and 1 <= value <= tick for value in evidence_ticks
        ):
            violations.append(f"probe_{answer.get('id', 'unknown')}_uses_invalid_evidence_tick")
        confidence = answer.get("confidence")
        if not isinstance(confidence, (int, float)) or not 0 <= confidence <= 1:
            violations.append(f"probe_{answer.get('id', 'unknown')}_invalid_confidence")
    return violations
