#!/usr/bin/env python3
"""Render the CSBC paper figures from frozen aggregate and run artifacts."""

from __future__ import annotations

from collections import defaultdict
from csv import DictWriter
from html import escape
from math import log10
from pathlib import Path
from statistics import mean, stdev
import json


EXPERIMENT = Path(__file__).resolve().parents[1]
ROOT = EXPERIMENT.parent
FIGURES = EXPERIMENT / "results" / "figures"
TABLES = EXPERIMENT / "results" / "tables"
CONFIRMATORY = EXPERIMENT / "results" / "raw" / "confirmatory-history-v1" / "checkpoints.jsonl"
ENDURANCE = EXPERIMENT / "results" / "raw" / "endurance-100k-v2"
VISUAL = (
    ROOT
    / "visual-stream-experiment"
    / "results"
    / "raw"
    / "visual-stream-attention-1000-qwen35-9b-seed7-dev-v5"
)

COLORS = {
    "A": "#2563EB",
    "B": "#D97706",
    "D": "#059669",
    "E": "#4B5563",
    "purple": "#7C3AED",
    "red": "#DC2626",
    "ink": "#172033",
    "muted": "#667085",
    "grid": "#D9E0EA",
    "panel": "#F7F9FC",
}

LABELS = {
    "A": "A  Full replay",
    "B": "B  Fixed window",
    "D": "D  Persistent sparse",
    "E": "E  Oracle",
}


def read_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def read_jsonl(path: Path):
    with path.open(encoding="utf-8") as handle:
        for line in handle:
            if line.strip():
                yield json.loads(line)


def write_csv(path: Path, rows: list[dict], fields: list[str]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)


def scale(value: float, low: float, high: float, start: float, end: float) -> float:
    return start + (value - low) * (end - start) / (high - low)


def log_scale(value: float, low: float, high: float, start: float, end: float) -> float:
    return scale(log10(value), log10(low), log10(high), start, end)


class SVG:
    def __init__(self, width: int, height: int, title: str, description: str):
        self.width = width
        self.height = height
        self.items = [
            f'<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}" '
            f'viewBox="0 0 {width} {height}" role="img" aria-labelledby="title desc">',
            f"<title id=\"title\">{escape(title)}</title>",
            f"<desc id=\"desc\">{escape(description)}</desc>",
            "<style>text{font-family:Inter,DejaVu Sans,Arial,sans-serif;fill:#172033}"
            ".small{font-size:13px;fill:#667085}.tick{font-size:12px;fill:#667085}"
            ".label{font-size:14px;font-weight:600}.title{font-size:24px;font-weight:700}"
            ".subtitle{font-size:14px;fill:#667085}</style>",
            f'<rect width="{width}" height="{height}" fill="#FFFFFF"/>',
        ]

    def rect(self, x, y, w, h, fill="none", stroke="none", sw=1, rx=0, opacity=1):
        self.items.append(
            f'<rect x="{x:.2f}" y="{y:.2f}" width="{w:.2f}" height="{h:.2f}" '
            f'fill="{fill}" stroke="{stroke}" stroke-width="{sw}" rx="{rx}" opacity="{opacity}"/>'
        )

    def line(self, x1, y1, x2, y2, stroke, sw=1, dash=None, opacity=1):
        dashed = f' stroke-dasharray="{dash}"' if dash else ""
        self.items.append(
            f'<line x1="{x1:.2f}" y1="{y1:.2f}" x2="{x2:.2f}" y2="{y2:.2f}" '
            f'stroke="{stroke}" stroke-width="{sw}" opacity="{opacity}"{dashed}/>'
        )

    def circle(self, x, y, r, fill, stroke="#FFFFFF", sw=1.5):
        self.items.append(
            f'<circle cx="{x:.2f}" cy="{y:.2f}" r="{r}" fill="{fill}" '
            f'stroke="{stroke}" stroke-width="{sw}"/>'
        )

    def polyline(self, points, stroke, sw=2.5, dash=None, opacity=1):
        coords = " ".join(f"{x:.2f},{y:.2f}" for x, y in points)
        dashed = f' stroke-dasharray="{dash}"' if dash else ""
        self.items.append(
            f'<polyline points="{coords}" fill="none" stroke="{stroke}" stroke-width="{sw}" '
            f'stroke-linejoin="round" stroke-linecap="round" opacity="{opacity}"{dashed}/>'
        )

    def text(self, x, y, value, size=14, anchor="start", weight=400, fill=None, klass=None, rotate=None):
        attrs = [f'x="{x:.2f}"', f'y="{y:.2f}"', f'text-anchor="{anchor}"', f'font-size="{size}"', f'font-weight="{weight}"']
        if fill:
            attrs.append(f'style="fill:{fill}"')
        if klass:
            attrs.append(f'class="{klass}"')
        if rotate is not None:
            attrs.append(f'transform="rotate({rotate} {x:.2f} {y:.2f})"')
        self.items.append(f"<text {' '.join(attrs)}>{escape(str(value))}</text>")

    def save(self, path: Path):
        self.items.append("</svg>")
        path.write_text("\n".join(self.items) + "\n", encoding="utf-8")


def chart_title(svg: SVG, title: str, subtitle: str) -> None:
    svg.text(52, 42, title, klass="title")
    svg.text(52, 66, subtitle, klass="subtitle")


def legend(svg: SVG, entries: list[tuple[str, str]], x: float, y: float, gap=170) -> None:
    for index, (label, color) in enumerate(entries):
        xx = x + gap * index
        svg.line(xx, y, xx + 24, y, color, 3)
        svg.circle(xx + 12, y, 4, color)
        svg.text(xx + 32, y + 5, label, size=13)


def panel(svg: SVG, x: float, y: float, w: float, h: float, title: str) -> None:
    svg.rect(x, y, w, h, fill=COLORS["panel"], stroke="#E4E9F0", rx=10)
    svg.text(x + 16, y + 26, title, size=15, weight=700)


def render_architecture() -> None:
    svg = SVG(1120, 650, "CSBC runtime architecture", "Persistent state is separate from executing cognition and temporal control.")
    chart_title(svg, "CSBC runtime: state persists while control recurs", "The temporal kernel arbitrates bounded work; model execution is a transition service, not the process itself.")

    svg.rect(55, 100, 1010, 470, fill="#F8FAFD", stroke="#D7DEE9", rx=18)
    boxes = {
        "events": (90, 190, 190, 90, "Event + delta bus", "timestamped evidence"),
        "kernel": (400, 105, 320, 105, "Temporal kernel", "admit · defer · cancel · arbitrate"),
        "state": (400, 280, 320, 120, "Persistent cognitive state", "self · world · social · goals · uncertainty"),
        "model": (805, 190, 220, 90, "Neural transition service", "bounded semantic jobs"),
        "action": (805, 390, 220, 90, "Action + speech", "small revocable commitments"),
        "memory": (90, 390, 190, 90, "Durable memory", "episodic log · consolidation"),
    }
    fills = {
        "kernel": "#EDE9FE", "state": "#DCFCE7", "events": "#DBEAFE",
        "model": "#FEF3C7", "action": "#FCE7F3", "memory": "#E5E7EB",
    }
    for key, (x, y, w, h, heading, sub) in boxes.items():
        svg.rect(x, y, w, h, fill=fills[key], stroke="#AAB4C3", sw=1.2, rx=12)
        svg.text(x + w / 2, y + 38, heading, size=17, anchor="middle", weight=700)
        svg.text(x + w / 2, y + 64, sub, size=13, anchor="middle", fill=COLORS["muted"])

    arrows = [
        (280, 235, 400, 330, "new evidence"),
        (560, 210, 560, 280, "control"),
        (720, 330, 805, 235, "dirty state"),
        (805, 265, 720, 350, "versioned delta"),
        (720, 375, 805, 435, "latest snapshot"),
        (400, 365, 280, 435, "commit log"),
        (280, 415, 400, 385, "retrieval"),
        (720, 155, 805, 205, "budget"),
        (915, 280, 915, 390, "bounded output"),
    ]
    for x1, y1, x2, y2, label in arrows:
        svg.line(x1, y1, x2, y2, "#5B6575", 2)
        angle = 7
        svg.line(x2, y2, x2 - angle, y2 - angle, "#5B6575", 2)
        svg.line(x2, y2, x2 - angle, y2 + angle, "#5B6575", 2)
        svg.text((x1 + x2) / 2, (y1 + y2) / 2 - 8, label, size=11, anchor="middle", fill=COLORS["muted"])

    svg.text(560, 530, "persistent state ≠ executing cognition ≠ temporal control", size=20, anchor="middle", weight=700)
    svg.save(FIGURES / "fig01-csbc-runtime.svg")


def render_confirmatory() -> None:
    grouped = defaultdict(list)
    for row in read_jsonl(CONFIRMATORY):
        grouped[(row["tick"], row["condition"])].append(row["semantic_accuracy"])

    rows = []
    for (tick, condition), values in sorted(grouped.items()):
        rows.append({
            "tick": tick,
            "condition": condition,
            "mean_semantic_accuracy": mean(values),
            "sample_sd": stdev(values),
            "n": len(values),
        })
    write_csv(
        TABLES / "paper-confirmatory-accuracy.csv",
        rows,
        ["tick", "condition", "mean_semantic_accuracy", "sample_sd", "n"],
    )

    svg = SVG(1040, 620, "Confirmatory semantic accuracy", "Three-seed mean semantic accuracy with sample standard deviation by condition and history length.")
    chart_title(svg, "Preregistered history sweep: semantic accuracy", "Mean ± sample SD across seeds 11, 29, and 47; eleven scored fields per checkpoint.")
    left, right, top, bottom = 90, 985, 115, 520
    for pct in range(0, 101, 20):
        y = scale(pct / 100, 0, 1, bottom, top)
        svg.line(left, y, right, y, COLORS["grid"])
        svg.text(left - 12, y + 4, f"{pct}%", anchor="end", klass="tick")
    ticks = [100, 500, 1000, 5000]
    for tick in ticks:
        x = log_scale(tick, 100, 5000, left, right)
        svg.line(x, bottom, x, bottom + 7, COLORS["muted"])
        svg.text(x, bottom + 25, f"{tick:,}", anchor="middle", klass="tick")
    svg.text((left + right) / 2, 565, "Elapsed environment ticks (log scale)", anchor="middle", size=14, weight=600)
    svg.text(28, (top + bottom) / 2, "Semantic accuracy", anchor="middle", size=14, weight=600, rotate=-90)

    by_condition = defaultdict(list)
    for row in rows:
        by_condition[row["condition"]].append(row)
    dashes = {"A": None, "B": "8 5", "D": None, "E": "3 4"}
    for condition in ["A", "B", "D", "E"]:
        points = []
        for row in sorted(by_condition[condition], key=lambda item: item["tick"]):
            x = log_scale(row["tick"], 100, 5000, left, right)
            y = scale(row["mean_semantic_accuracy"], 0, 1, bottom, top)
            err = row["sample_sd"]
            y1 = scale(max(0, row["mean_semantic_accuracy"] - err), 0, 1, bottom, top)
            y2 = scale(min(1, row["mean_semantic_accuracy"] + err), 0, 1, bottom, top)
            svg.line(x, y1, x, y2, COLORS[condition], 1.5, opacity=.75)
            svg.line(x - 5, y1, x + 5, y1, COLORS[condition], 1.5)
            svg.line(x - 5, y2, x + 5, y2, COLORS[condition], 1.5)
            points.append((x, y))
        svg.polyline(points, COLORS[condition], 2.7, dash=dashes[condition])
        for x, y in points:
            svg.circle(x, y, 5, COLORS[condition])
    legend(svg, [(LABELS[c], COLORS[c]) for c in ["A", "B", "D", "E"]], 125, 95, 220)
    svg.save(FIGURES / "fig02-confirmatory-accuracy.svg")


def endurance_rows() -> tuple[list[dict], dict[str, dict]]:
    checkpoints = defaultdict(dict)
    for condition in ["A", "B"]:
        for row in read_jsonl(ENDURANCE / condition / "ledger.jsonl"):
            checkpoints[row["tick"]][condition] = {
                "accuracy": row["field_accuracy"],
                "prompt": row["prompt_tokens"],
                "latency_ms": row["decision_wall_ms"],
                "cumulative_prompt": row["cumulative_prompt_tokens"],
            }
    wanted = {1000, 5000, 10000, 25000, 50000, 100000}
    for row in read_jsonl(ENDURANCE / "D" / "ledger.jsonl"):
        if row["tick"] in wanted:
            checkpoints[row["tick"]]["D"] = {
                "accuracy": row["query_semantic_field_accuracy"],
                "prompt": 0,
                "latency_ms": 0,
                "cumulative_prompt": row["cumulative_prompt_tokens"],
                "cumulative_events": row["cumulative_events"],
            }

    previous_prompt = previous_events = 0
    rows = []
    for tick in sorted(checkpoints):
        d = checkpoints[tick]["D"]
        interval_prompt = d["cumulative_prompt"] - previous_prompt
        interval_events = d["cumulative_events"] - previous_events
        rows.append({
            "tick": tick,
            "a_accuracy": checkpoints[tick]["A"]["accuracy"],
            "b_accuracy": checkpoints[tick]["B"]["accuracy"],
            "d_accuracy": d["accuracy"],
            "a_prompt": checkpoints[tick]["A"]["prompt"],
            "b_prompt": checkpoints[tick]["B"]["prompt"],
            "d_decision_prompt": 0,
            "a_latency_ms": checkpoints[tick]["A"]["latency_ms"],
            "b_latency_ms": checkpoints[tick]["B"]["latency_ms"],
            "d_decision_latency_ms": 0,
            "d_cumulative_maintenance_prompt": d["cumulative_prompt"],
            "d_interval_events": interval_events,
            "d_interval_prompt": interval_prompt,
            "d_prompt_per_event": interval_prompt / interval_events,
        })
        previous_prompt = d["cumulative_prompt"]
        previous_events = d["cumulative_events"]
    return rows, {c: read_json(ENDURANCE / c / "summary.json") for c in ["A", "B", "D"]}


def render_endurance_summary(rows: list[dict], summaries: dict[str, dict]) -> None:
    final = rows[-1]
    values = {
        "A": (summaries["A"]["semantic_field_accuracy"], final["a_accuracy"]),
        "B": (summaries["B"]["semantic_field_accuracy"], final["b_accuracy"]),
        "D": (summaries["D"]["semantic_field_accuracy"], final["d_accuracy"]),
        "E": (1.0, 1.0),
    }
    source = []
    for condition, (aggregate, last) in values.items():
        source.append({"condition": condition, "aggregate_accuracy": aggregate, "final_accuracy": last})
    write_csv(TABLES / "paper-endurance-summary.csv", source, ["condition", "aggregate_accuracy", "final_accuracy"])

    svg = SVG(1040, 610, "Endurance semantic accuracy", "Aggregate and final semantic accuracy across four conditions at 100,000 ticks.")
    chart_title(svg, "100,000-tick endurance: accuracy at a cost", "Aggregate pools six boundaries; final is the complete eleven-field state at tick 100,000.")
    left, right, top, bottom = 95, 980, 120, 510
    for pct in range(0, 101, 20):
        y = scale(pct / 100, 0, 1, bottom, top)
        svg.line(left, y, right, y, COLORS["grid"])
        svg.text(left - 12, y + 4, f"{pct}%", anchor="end", klass="tick")
    group_width = (right - left) / 4
    for index, condition in enumerate(["A", "B", "D", "E"]):
        center = left + group_width * (index + .5)
        for offset, value, alpha, label in [(-28, values[condition][0], 1, "Aggregate"), (28, values[condition][1], .48, "Final")]:
            x = center + offset - 22
            y = scale(value, 0, 1, bottom, top)
            svg.rect(x, y, 44, bottom - y, fill=COLORS[condition], opacity=alpha, rx=3)
            svg.text(center + offset, y - 8, f"{value * 100:.1f}%", anchor="middle", size=12, weight=700)
        svg.text(center, bottom + 28, LABELS[condition], anchor="middle", size=13, weight=600)
    svg.rect(730, 82, 16, 16, fill="#334155")
    svg.text(754, 95, "Aggregate", size=13)
    svg.rect(842, 82, 16, 16, fill="#334155", opacity=.48)
    svg.text(866, 95, "Final", size=13)
    svg.text(32, (top + bottom) / 2, "Semantic accuracy", anchor="middle", size=14, weight=600, rotate=-90)
    svg.text(95, 575, "D used 5.31× A's prompt tokens and 6.54× A's inference wall time under only six queries.", size=13, fill=COLORS["muted"])
    svg.save(FIGURES / "fig03-endurance-summary.svg")


def render_endurance_checkpoints(rows: list[dict]) -> None:
    write_csv(
        TABLES / "paper-endurance-checkpoints.csv",
        rows,
        list(rows[0].keys()),
    )
    svg = SVG(1180, 990, "Endurance checkpoint behavior", "Accuracy, submitted decision prompt, and decision latency over a 100,000-tick trajectory.")
    chart_title(svg, "Endurance trajectory: quality, prompt growth, and latency", "A and B reconstruct at each boundary; D reads already-maintained state with no neural boundary call.")
    x0, x1 = 100, 1115
    panels = [(110, 250, "A  Semantic accuracy"), (380, 250, "B  Submitted decision prompt"), (650, 250, "C  Decision-boundary latency")]
    for y, h, title in panels:
        panel(svg, 55, y, 1090, h, title)

    xticks = [1000, 5000, 10000, 25000, 50000, 100000]
    for y, _, _ in panels:
        for tick in xticks:
            x = log_scale(tick, 1000, 100000, x0, x1)
            svg.line(x, y + 38, x, y + 215, COLORS["grid"], opacity=.7)
            svg.text(x, y + 237, f"{tick // 1000}k", anchor="middle", klass="tick")

    # Accuracy panel
    for pct in [0, 25, 50, 75, 100]:
        y = scale(pct / 100, 0, 1, 110 + 215, 110 + 45)
        svg.line(x0, y, x1, y, COLORS["grid"])
        svg.text(x0 - 12, y + 4, f"{pct}%", anchor="end", klass="tick")
    for condition, key in [("A", "a_accuracy"), ("B", "b_accuracy"), ("D", "d_accuracy")]:
        pts = [(log_scale(r["tick"], 1000, 100000, x0, x1), scale(r[key], 0, 1, 325, 155)) for r in rows]
        svg.polyline(pts, COLORS[condition], 2.7, dash="8 5" if condition == "B" else None)
        for x, y in pts:
            svg.circle(x, y, 4.5, COLORS[condition])
    legend(svg, [(LABELS[c], COLORS[c]) for c in ["A", "B", "D"]], 510, 136, 210)

    # Prompt panel (log y)
    for value in [100, 1000, 10000, 100000]:
        y = log_scale(value, 100, 100000, 380 + 215, 380 + 45)
        svg.line(x0, y, x1, y, COLORS["grid"])
        svg.text(x0 - 12, y + 4, f"{value:,}", anchor="end", klass="tick")
    for condition, key in [("A", "a_prompt"), ("B", "b_prompt")]:
        pts = [(log_scale(r["tick"], 1000, 100000, x0, x1), log_scale(r[key], 100, 100000, 595, 425)) for r in rows]
        svg.polyline(pts, COLORS[condition], 2.7, dash="8 5" if condition == "B" else None)
        for x, y in pts:
            svg.circle(x, y, 4.5, COLORS[condition])
    svg.text(855, 426, "D = 0 tokens at decision boundary", size=13, weight=700, fill=COLORS["D"])

    # Latency panel (log y, seconds)
    for value in [.1, 1, 10, 100, 1000]:
        y = log_scale(value, .1, 1000, 650 + 215, 650 + 45)
        svg.line(x0, y, x1, y, COLORS["grid"])
        svg.text(x0 - 12, y + 4, f"{value:g}s", anchor="end", klass="tick")
    target_y = log_scale(.14, .1, 1000, 865, 695)
    svg.line(x0, target_y, x1, target_y, COLORS["red"], 1.8, dash="7 5")
    svg.text(x1 - 4, target_y - 7, "140 ms reference", anchor="end", size=12, fill=COLORS["red"], weight=700)
    for condition, key in [("A", "a_latency_ms"), ("B", "b_latency_ms")]:
        pts = [(log_scale(r["tick"], 1000, 100000, x0, x1), log_scale(r[key] / 1000, .1, 1000, 865, 695)) for r in rows]
        svg.polyline(pts, COLORS[condition], 2.7, dash="8 5" if condition == "B" else None)
        for x, y in pts:
            svg.circle(x, y, 4.5, COLORS[condition])
    svg.text(855, 696, "D = state read; no neural call", size=13, weight=700, fill=COLORS["D"])
    svg.text((x0 + x1) / 2, 940, "Elapsed environment ticks (log scale)", anchor="middle", size=14, weight=600)
    svg.save(FIGURES / "fig04-endurance-checkpoints.svg")


def render_flux(rows: list[dict]) -> None:
    source = [{
        "interval_end_tick": r["tick"],
        "new_events": r["d_interval_events"],
        "interval_prompt_tokens": r["d_interval_prompt"],
        "prompt_tokens_per_new_event": r["d_prompt_per_event"],
    } for r in rows]
    write_csv(
        TABLES / "paper-endurance-flux.csv",
        source,
        ["interval_end_tick", "new_events", "interval_prompt_tokens", "prompt_tokens_per_new_event"],
    )
    svg = SVG(1040, 580, "Sparse maintenance follows event flux", "Prompt tokens per new event remain nearly constant across elapsed history.")
    chart_title(svg, "Sparse transition size tracks incoming information", "D remains within 154.2–157.7 prompt tokens per new event across a 100× history span.")
    left, right, top, bottom = 95, 980, 120, 480
    for value in [152, 154, 156, 158, 160]:
        y = scale(value, 152, 160, bottom, top)
        svg.line(left, y, right, y, COLORS["grid"])
        svg.text(left - 12, y + 4, f"{value}", anchor="end", klass="tick")
    points = []
    for row in source:
        x = log_scale(row["interval_end_tick"], 1000, 100000, left, right)
        y = scale(row["prompt_tokens_per_new_event"], 152, 160, bottom, top)
        points.append((x, y))
        svg.circle(x, y, 5.5, COLORS["D"])
        svg.text(x, y - 13, f"{row['prompt_tokens_per_new_event']:.1f}", anchor="middle", size=12, weight=700)
        svg.text(x, y + 26, f"{row['new_events']:,} events", anchor="middle", size=11, fill=COLORS["muted"])
    svg.polyline(points, COLORS["D"], 3)
    for tick in [1000, 5000, 10000, 25000, 50000, 100000]:
        x = log_scale(tick, 1000, 100000, left, right)
        svg.text(x, bottom + 25, f"{tick // 1000}k", anchor="middle", klass="tick")
    svg.text((left + right) / 2, 535, "Interval ending tick (log scale)", anchor="middle", size=14, weight=600)
    svg.text(27, (top + bottom) / 2, "Prompt tokens / new event", anchor="middle", size=14, weight=600, rotate=-90)
    svg.save(FIGURES / "fig05-endurance-flux.svg")


def render_reliability(summary: dict) -> None:
    rows = [
        {"metric": "error_episodes", "value": summary["error_episodes"]},
        {"metric": "repaired_error_episodes", "value": summary["repaired_error_episodes"]},
        {"metric": "unresolved_error_episodes", "value": summary["unresolved_error_episodes"]},
        {"metric": "latency_p50_ms", "value": summary["model_latency_ms"]["p50"]},
        {"metric": "latency_p95_ms", "value": summary["model_latency_ms"]["p95"]},
        {"metric": "latency_p99_ms", "value": summary["model_latency_ms"]["p99"]},
    ]
    write_csv(TABLES / "paper-endurance-reliability.csv", rows, ["metric", "value"])
    svg = SVG(1100, 600, "Sparse maintenance repair and latency", "Error repair counts and semantic transition latency quantiles.")
    chart_title(svg, "Persistent state repairs most errors—but not in 140 ms", "Error episodes are lifecycle counts; latency is measured CPU model time per semantic transition.")
    panel(svg, 55, 105, 460, 410, "A  Semantic error lifecycle")
    panel(svg, 550, 105, 495, 410, "B  Transition latency")

    total = summary["error_episodes"]
    repaired = summary["repaired_error_episodes"]
    unresolved = summary["unresolved_error_episodes"]
    svg.text(285, 205, f"{repaired:,}", anchor="middle", size=54, weight=700, fill=COLORS["D"])
    svg.text(285, 235, f"of {total:,} observed episodes repaired", anchor="middle", size=15, weight=600)
    svg.rect(105, 285, 360, 34, fill="#E5E7EB", rx=7)
    svg.rect(105, 285, 360 * repaired / total, 34, fill=COLORS["D"], rx=7)
    svg.text(285, 307, f"{repaired / total * 100:.2f}%", anchor="middle", size=14, weight=700, fill="#FFFFFF")
    svg.text(105, 355, f"Unresolved at end: {unresolved}", size=15, weight=700, fill=COLORS["red"])
    svg.text(105, 383, f"Maximum observed error age: {summary['max_observed_error_age_ticks']:,} ticks", size=14)
    svg.text(105, 408, f"Error-state occupancy: {summary['error_state_occupancy'] * 100:.3f}%", size=14)
    svg.text(105, 433, "Final presence fields wrong: 2 of 2", size=14)

    values = [("p50", summary["model_latency_ms"]["p50"] / 1000), ("p95", summary["model_latency_ms"]["p95"] / 1000), ("p99", summary["model_latency_ms"]["p99"] / 1000)]
    base, ceiling = 475, 2.0
    for value in [0, .5, 1, 1.5, 2]:
        y = scale(value, 0, ceiling, base, 155)
        svg.line(610, y, 1005, y, COLORS["grid"])
        svg.text(598, y + 4, f"{value:.1f}s", anchor="end", klass="tick")
    target = scale(.14, 0, ceiling, base, 155)
    svg.line(610, target, 1005, target, COLORS["red"], 2, dash="7 5")
    svg.text(998, target - 8, "140 ms", anchor="end", size=12, weight=700, fill=COLORS["red"])
    for index, (label, value) in enumerate(values):
        x = 690 + index * 125
        y = scale(value, 0, ceiling, base, 155)
        svg.rect(x - 30, y, 60, base - y, fill=COLORS["purple"], opacity=.84, rx=4)
        svg.text(x, y - 9, f"{value:.3f}s", anchor="middle", size=12, weight=700)
        svg.text(x, base + 25, label, anchor="middle", size=14, weight=700)
    svg.save(FIGURES / "fig06-endurance-reliability.svg")


def rolling(values: list[float], window: int = 21) -> list[float]:
    result = []
    for index in range(len(values)):
        start = max(0, index - window // 2)
        end = min(len(values), index + window // 2 + 1)
        result.append(mean(values[start:end]))
    return result


def render_visual_stream() -> None:
    attention = {row["tick"]: row for row in read_jsonl(VISUAL / "attention-events.jsonl")}
    model = {row["tick"]: row for row in read_jsonl(VISUAL / "model-events.jsonl")}
    speech = {row["tick"]: row for row in read_jsonl(VISUAL / "speech-events.jsonl")}
    probes = {row["tick"] for row in read_jsonl(VISUAL / "probe-answers.jsonl")}
    rows = []
    for tick in sorted(model):
        row = model[tick]
        rows.append({
            "tick": tick,
            "attention_words": attention[tick]["context_words"],
            "ttft_ms": row["first_token_ms"],
            "generation_tokens_per_second": row["client_generation_tokens_per_second"],
            "prompt_tokens": row["prompt_tokens"],
            "completion_tokens": row["completion_tokens"],
            "speech_committed": int(bool(speech[tick].get("new_words"))),
            "probe_due": int(tick in probes),
        })
    write_csv(TABLES / "paper-visual-stream-runtime.csv", rows, list(rows[0].keys()))

    svg = SVG(1200, 1030, "Visual-stream runtime telemetry", "Attention size, time to first token, and generation throughput across 491 cognitive ticks.")
    chart_title(svg, "Exploratory continuous-situation run: bounded state, unbounded latency", "One model call at every 140 ms situation boundary; Qwen3.5 9B Q4 on Kaiju CPU, 1,000-word Attention cap.")
    panel_specs = [
        (110, "A  Attention summary", 500, 1000, "words"),
        (390, "B  Time to first token", 0, 40, "seconds"),
        (670, "C  Generation throughput", 10, 20, "tokens / second"),
    ]
    x0, x1 = 105, 1140
    milestones = [(1, "start"), (23, "person"), (46, "camera set down"), (300, "prediction"), (316, "reversal"), (491, "end")]
    for y0, title, lo, hi, unit in panel_specs:
        panel(svg, 55, y0, 1110, 245, title)
        plot_top, plot_bottom = y0 + 42, y0 + 205
        for fraction in [0, .25, .5, .75, 1]:
            value = lo + (hi - lo) * fraction
            y = scale(value, lo, hi, plot_bottom, plot_top)
            svg.line(x0, y, x1, y, COLORS["grid"])
            svg.text(x0 - 12, y + 4, f"{value:g}", anchor="end", klass="tick")
        svg.text(25, (plot_top + plot_bottom) / 2, unit, anchor="middle", size=12, weight=600, rotate=-90)
        for tick, _ in milestones:
            x = scale(tick, 1, 491, x0, x1)
            svg.line(x, plot_top, x, plot_bottom, "#AEB8C6", 1, dash="4 5", opacity=.65)
        for tick in [1, 100, 200, 300, 400, 491]:
            x = scale(tick, 1, 491, x0, x1)
            svg.text(x, plot_bottom + 23, str(tick), anchor="middle", klass="tick")

    # Context series
    context_values = [row["attention_words"] for row in rows]
    points = [(scale(row["tick"], 1, 491, x0, x1), scale(row["attention_words"], 500, 1000, 315, 152)) for row in rows]
    svg.polyline(points, COLORS["D"], 1.7)
    svg.line(x0, 152, x1, 152, COLORS["red"], 1.5, dash="7 5")
    svg.text(x1 - 4, 144, "1,000-word cap", anchor="end", size=12, weight=700, fill=COLORS["red"])

    # TTFT series with rolling mean
    ttft = [row["ttft_ms"] / 1000 for row in rows]
    raw = [(scale(r["tick"], 1, 491, x0, x1), scale(min(40, value), 0, 40, 595, 432)) for r, value in zip(rows, ttft)]
    smooth = [(scale(r["tick"], 1, 491, x0, x1), scale(min(40, value), 0, 40, 595, 432)) for r, value in zip(rows, rolling(ttft))]
    svg.polyline(raw, COLORS["A"], .8, opacity=.28)
    svg.polyline(smooth, COLORS["A"], 2.5)
    target_y = scale(.14, 0, 40, 595, 432)
    svg.line(x0, target_y, x1, target_y, COLORS["red"], 2, dash="7 5")
    svg.text(x1 - 4, 585, "140 ms reference lies at baseline", anchor="end", size=11, fill=COLORS["red"], weight=700)

    # Throughput series with rolling mean
    throughput = [row["generation_tokens_per_second"] for row in rows]
    raw = [(scale(r["tick"], 1, 491, x0, x1), scale(max(10, min(20, value)), 10, 20, 875, 712)) for r, value in zip(rows, throughput)]
    smooth = [(scale(r["tick"], 1, 491, x0, x1), scale(max(10, min(20, value)), 10, 20, 875, 712)) for r, value in zip(rows, rolling(throughput))]
    svg.polyline(raw, COLORS["purple"], .8, opacity=.28)
    svg.polyline(smooth, COLORS["purple"], 2.5)

    for tick, label in milestones:
        x = scale(tick, 1, 491, x0, x1)
        svg.text(x, 96, label, anchor="middle", size=11, weight=600, fill=COLORS["muted"], rotate=-32 if tick not in {1, 491} else None)
    svg.text((x0 + x1) / 2, 935, "Cognitive tick", anchor="middle", size=14, weight=600)
    svg.text(75, 984, "Thin lines: per-tick measurements. Thick lines: centered 21-tick rolling mean. All 491 neural calls missed the 140 ms boundary.", size=13, fill=COLORS["muted"])
    svg.save(FIGURES / "fig07-visual-stream-runtime.svg")


def main() -> int:
    FIGURES.mkdir(parents=True, exist_ok=True)
    TABLES.mkdir(parents=True, exist_ok=True)
    required = [
        CONFIRMATORY,
        ENDURANCE / "A" / "ledger.jsonl",
        ENDURANCE / "B" / "ledger.jsonl",
        ENDURANCE / "D" / "ledger.jsonl",
        VISUAL / "attention-events.jsonl",
        VISUAL / "model-events.jsonl",
        VISUAL / "speech-events.jsonl",
        VISUAL / "probe-answers.jsonl",
    ]
    missing = [str(path) for path in required if not path.is_file()]
    if missing:
        raise SystemExit("missing frozen source artifacts:\n" + "\n".join(missing))

    render_architecture()
    render_confirmatory()
    rows, summaries = endurance_rows()
    render_endurance_summary(rows, summaries)
    render_endurance_checkpoints(rows)
    render_flux(rows)
    render_reliability(summaries["D"])
    render_visual_stream()
    print(f"rendered 7 SVG figures in {FIGURES}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
