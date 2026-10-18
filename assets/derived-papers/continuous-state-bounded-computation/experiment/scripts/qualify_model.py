#!/usr/bin/env python3
"""Run a small, exact structured-delta qualification against llama-server."""

from __future__ import annotations

from argparse import ArgumentParser
from pathlib import Path
import json
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))

from llama_adapter import LlamaSemanticAdapter  # noqa: E402


CASES = [
    {
        "id": "direct",
        "semantic_class": "DIRECT",
        "state": {
            "world.server.alpha.status": "online",
            "world.server.beta.status": "online",
            "world.server.gamma.status": "online",
        },
        "target_keys": ["world.server.alpha.status"],
        "event": "Server Alpha went offline.",
        "expected": {"world.server.alpha.status": "offline"},
    },
    {
        "id": "observational",
        "semantic_class": "OBSERVATIONAL",
        "state": {
            "world.server.alpha.status": "offline",
            "entity.alice.belief.server.alpha.online": "unknown",
        },
        "target_keys": ["entity.alice.belief.server.alpha.online"],
        "event": "Alice directly observed that Server Alpha was offline.",
        "expected": {"entity.alice.belief.server.alpha.online": "false"},
    },
    {
        "id": "communicated",
        "semantic_class": "COMMUNICATED",
        "state": {"entity.bob.belief.server.beta.online": "unknown"},
        "target_keys": ["entity.bob.belief.server.beta.online"],
        "event": "Alice told Bob that Server Beta was online.",
        "expected": {"entity.bob.belief.server.beta.online": "true"},
    },
    {
        "id": "temporal",
        "semantic_class": "TEMPORAL",
        "state": {"entity.bob.presence": "absent"},
        "target_keys": ["entity.bob.presence"],
        "event": "Bob entered the room.",
        "expected": {"entity.bob.presence": "present"},
    },
    {
        "id": "correction",
        "semantic_class": "CORRECTION",
        "state": {
            "world.server.alpha.status": "offline",
            "entity.alice.belief.server.alpha.online": "true",
        },
        "target_keys": ["entity.alice.belief.server.alpha.online"],
        "event": "Correction: the earlier online report was wrong. Alice now learns Server Alpha is offline.",
        "expected": {"entity.alice.belief.server.alpha.online": "false"},
    },
    {
        "id": "false-belief",
        "semantic_class": "FALSE_BELIEF",
        "state": {
            "world.server.gamma.status": "online",
            "entity.bob.belief.server.gamma.online": "unknown",
        },
        "target_keys": ["entity.bob.belief.server.gamma.online"],
        "event": "Bob accepted an alert claiming that Server Gamma was offline.",
        "expected": {"entity.bob.belief.server.gamma.online": "false"},
    },
    {
        "id": "second-hand",
        "semantic_class": "SECOND_HAND",
        "state": {"entity.bob.belief.server.alpha.online": "unknown"},
        "target_keys": ["entity.bob.belief.server.alpha.online"],
        "event": "Alice relayed to Bob that Carol had seen Server Alpha online.",
        "expected": {"entity.bob.belief.server.alpha.online": "true"},
    },
]


def main() -> int:
    parser = ArgumentParser()
    parser.add_argument("--base-url", default="http://127.0.0.1:8081")
    parser.add_argument("--model", default="qwen3.5-9b-heretic")
    parser.add_argument("--out", type=Path, required=True)
    parser.add_argument("--protocol", choices=("json", "compact"), default="json")
    args = parser.parse_args()
    args.out.mkdir(parents=True, exist_ok=False)
    adapter = LlamaSemanticAdapter(args.base_url, args.model, 42, args.protocol)

    results = []
    for case in CASES:
        error = None
        actual = None
        try:
            response = adapter.transition(
                case["state"],
                case["target_keys"],
                case["event"],
            )
            actual = dict(response.updates)
        except (ValueError, TypeError, json.JSONDecodeError) as exc:
            error = str(exc)
            response = None
        exact = actual == case["expected"]
        results.append({
            "id": case["id"],
            "semantic_class": case["semantic_class"],
            "expected": case["expected"],
            "actual": actual,
            "exact": exact,
            "parse_error": error,
            "raw_content": response.raw_content if response else None,
            "wall_ms": response.wall_ms if response else 0.0,
            "prompt_tokens": response.prompt_tokens if response else 0,
            "completion_tokens": response.completion_tokens if response else 0,
            "timings": response.timings if response else None,
        })

    summary = {
        "protocol": args.protocol,
        "cases": len(results),
        "schema_valid": sum(item["parse_error"] is None for item in results),
        "exact": sum(item["exact"] for item in results),
        "all_exact": all(item["exact"] for item in results),
        "total_prompt_tokens": sum(item["prompt_tokens"] for item in results),
        "total_completion_tokens": sum(item["completion_tokens"] for item in results),
        "total_wall_ms": sum(item["wall_ms"] for item in results),
    }
    (args.out / "results.json").write_text(json.dumps(results, indent=2, sort_keys=True) + "\n")
    (args.out / "summary.json").write_text(json.dumps(summary, indent=2, sort_keys=True) + "\n")
    print(json.dumps(summary, indent=2, sort_keys=True))
    return 0 if summary["all_exact"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
