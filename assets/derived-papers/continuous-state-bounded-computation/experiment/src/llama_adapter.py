"""Minimal OpenAI-compatible adapter for the experiment's semantic operator."""

from __future__ import annotations

from dataclasses import dataclass
from time import perf_counter
from urllib.request import Request, urlopen
from typing import Mapping, Sequence
import json

from csbc_ledger import allowed_values


SYSTEM = """You are a deterministic state-transition function.
You receive canonical state, target_keys, and one new event. Return one final
value for every target key, exactly once, and no other keys. Belief keys encode
the proposition that a server is online: true, false, or unknown. You update
semantic world and social state only. Tool execution, plans, and action
selection belong to a separate deterministic controller and will never appear
in target_keys. Obey the JSON schema and emit no prose."""


COMPACT_SYSTEM = """You are a deterministic semantic state-transition function.
Each numbered target has a canonical key, current value, and allowed values.
Return exactly one final value per target in the same order, separated by |.
For a belief key, true or false records what that named person believes about
the proposition that the server is online; it does not record objective truth.
Return no keys, labels, JSON, spaces, explanation, tool state, plan state, or
actions. Even when a value does not change, return it in its position."""


RECONSTRUCTION_SYSTEM = """You reconstruct the present semantic state from an
initial state at tick zero and an ordered natural-language event history.
Return exactly one final value per numbered target in the same order, separated
by |. Belief true or false records what the named person believes about the
proposition that the server is online, not objective truth. Apply only events
that person observed or was told to that person's belief. Return no keys,
labels, JSON, spaces, explanation, tool state, plan state, or actions."""


SCHEMA = {
    "type": "object",
    "properties": {
        "updates": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "key": {"type": "string"},
                    "value": {"type": "string"},
                },
                "required": ["key", "value"],
                "additionalProperties": False,
            },
        }
    },
    "required": ["updates"],
    "additionalProperties": False,
}


@dataclass(frozen=True, slots=True)
class ModelResult:
    updates: Mapping[str, str]
    prompt_tokens: int
    completion_tokens: int
    wall_ms: float
    raw_content: str
    timings: Mapping[str, object]


class LlamaSemanticAdapter:
    def __init__(
        self,
        base_url: str,
        model: str,
        seed: int = 42,
        protocol: str = "json",
        request_timeout_seconds: float = 180,
    ) -> None:
        if protocol not in {"json", "compact"}:
            raise ValueError(f"unsupported protocol: {protocol}")
        if request_timeout_seconds <= 0:
            raise ValueError("request timeout must be positive")
        self.url = f"{base_url.rstrip('/')}/v1/chat/completions"
        self.model = model
        self.seed = seed
        self.protocol = protocol
        self.request_timeout_seconds = request_timeout_seconds

    def transition(
        self,
        state: Mapping[str, str],
        target_keys: Sequence[str],
        event_text: str,
    ) -> ModelResult:
        if self.protocol == "compact":
            return self._compact_transition(state, target_keys, event_text)
        return self._json_transition(state, target_keys, event_text)

    def _post(self, payload: dict) -> tuple[dict, float]:
        request = Request(
            self.url,
            data=json.dumps(payload).encode(),
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        started = perf_counter()
        with urlopen(request, timeout=self.request_timeout_seconds) as response:
            result = json.load(response)
        return result, (perf_counter() - started) * 1000

    def _result(
        self,
        result: dict,
        wall_ms: float,
        updates: Mapping[str, str],
        content: str,
    ) -> ModelResult:
        usage = result.get("usage") or {}
        return ModelResult(
            updates=updates,
            prompt_tokens=usage.get("prompt_tokens", 0),
            completion_tokens=usage.get("completion_tokens", 0),
            wall_ms=wall_ms,
            raw_content=content,
            timings=result.get("timings") or {},
        )

    def _json_transition(
        self,
        state: Mapping[str, str],
        target_keys: Sequence[str],
        event_text: str,
    ) -> ModelResult:
        user_payload = {
            "canonical_state": dict(state),
            "target_keys": list(target_keys),
            "event": event_text,
        }
        payload = {
            "model": self.model,
            "messages": [
                {"role": "system", "content": SYSTEM},
                {"role": "user", "content": json.dumps(user_payload, sort_keys=True)},
            ],
            "temperature": 0,
            "top_k": 1,
            "seed": self.seed,
            "max_tokens": 192,
            "response_format": {
                "type": "json_schema",
                "json_schema": {"name": "state_delta", "strict": True, "schema": SCHEMA},
            },
        }
        result, wall_ms = self._post(payload)
        content = result["choices"][0]["message"].get("content", "")
        parsed = json.loads(content)
        raw_updates = parsed.get("updates") if isinstance(parsed, dict) else None
        if not isinstance(raw_updates, list):
            raise ValueError("model response is not an updates object")
        updates: dict[str, str] = {}
        for update in raw_updates:
            if not isinstance(update, dict) or set(update) != {"key", "value"}:
                raise ValueError("model update does not contain exactly key and value")
            if update["key"] in updates:
                raise ValueError(f"model returned duplicate key: {update['key']}")
            updates[update["key"]] = update["value"]
        return self._result(result, wall_ms, updates, content)

    def _compact_transition(
        self,
        state: Mapping[str, str],
        target_keys: Sequence[str],
        event_text: str,
    ) -> ModelResult:
        if not target_keys:
            raise ValueError("compact transition requires at least one target")
        lines = ["TARGETS"]
        rules = []
        symbols = []
        for index, key in enumerate(target_keys):
            choices = sorted(allowed_values(key))
            lines.append(f"{index}\t{key}\tcurrent={state[key]}\tallowed={','.join(choices)}")
            symbol = f"v{index}"
            symbols.append(symbol)
            literals = " | ".join(json.dumps(value) for value in choices)
            rules.append(f"{symbol} ::= {literals}")
        lines.extend(("EVENT", event_text, "OUTPUT"))
        grammar = "root ::= " + ' "|" '.join(symbols) + "\n" + "\n".join(rules)
        payload = {
            "model": self.model,
            "messages": [
                {"role": "system", "content": COMPACT_SYSTEM},
                {"role": "user", "content": "\n".join(lines)},
            ],
            "temperature": 0,
            "top_k": 1,
            "seed": self.seed,
            "max_tokens": max(8, len(target_keys) * 4),
            "grammar": grammar,
        }
        result, wall_ms = self._post(payload)
        content = result["choices"][0]["message"].get("content", "").strip()
        values = content.split("|")
        if len(values) != len(target_keys):
            raise ValueError(
                f"compact value count mismatch; expected={len(target_keys)}, actual={len(values)}"
            )
        updates = dict(zip(target_keys, values, strict=True))
        for key, value in updates.items():
            if value not in allowed_values(key):
                raise ValueError(f"invalid compact value for {key}: {value}")
        return self._result(result, wall_ms, updates, content)

    def reconstruct(
        self,
        initial_state: Mapping[str, str],
        target_keys: Sequence[str],
        events: Sequence[str],
    ) -> ModelResult:
        if self.protocol != "compact":
            raise ValueError("reconstruction currently requires the compact protocol")
        lines = ["TARGETS"]
        rules = []
        symbols = []
        for index, key in enumerate(target_keys):
            choices = sorted(allowed_values(key))
            lines.append(f"{index}\t{key}\tinitial={initial_state[key]}\tallowed={','.join(choices)}")
            symbol = f"v{index}"
            symbols.append(symbol)
            rules.append(f"{symbol} ::= " + " | ".join(json.dumps(value) for value in choices))
        lines.append("EVENTS")
        lines.extend(f"{index + 1}\t{text}" for index, text in enumerate(events))
        lines.append("OUTPUT")
        grammar = "root ::= " + ' "|" '.join(symbols) + "\n" + "\n".join(rules)
        payload = {
            "model": self.model,
            "messages": [
                {"role": "system", "content": RECONSTRUCTION_SYSTEM},
                {"role": "user", "content": "\n".join(lines)},
            ],
            "temperature": 0,
            "top_k": 1,
            "seed": self.seed,
            "max_tokens": max(16, len(target_keys) * 4),
            "grammar": grammar,
        }
        result, wall_ms = self._post(payload)
        content = result["choices"][0]["message"].get("content", "").strip()
        values = content.split("|")
        if len(values) != len(target_keys):
            raise ValueError(
                f"reconstruction value count mismatch; expected={len(target_keys)}, actual={len(values)}"
            )
        updates = dict(zip(target_keys, values, strict=True))
        for key, value in updates.items():
            if value not in allowed_values(key):
                raise ValueError(f"invalid reconstruction value for {key}: {value}")
        return self._result(result, wall_ms, updates, content)
