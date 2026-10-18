#!/usr/bin/env python3
"""Compile recorded source media into chronological 140 ms visual-state tapes."""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import shutil
import subprocess
import sys
import tempfile
import wave
from array import array
from pathlib import Path
from typing import Any

from PIL import Image, ImageChops, ImageFilter, ImageStat


TICK_MS = 140
FRAME_RATE = 30
ROOT = Path(__file__).resolve().parents[1]
TIMELINE_PATH = ROOT / "annotations" / "semantic-timeline.json"
QUESTION_BANK_PATH = ROOT / "annotations" / "question-bank.json"
HEARD_SPEECH_PATH = ROOT / "annotations" / "heard-speech-pt.json"
DATA_DIR = ROOT / "data"


OBJECT_CATALOG: dict[str, dict[str, Any]] = {
    "comb_1": {
        "category": "exposed papery comb/nest",
        "colors": ["rust brown", "orange-brown", "tan", "dark brown cell shadows"],
        "material": "papery layered nest material",
        "appearance": "rounded hanging mass with exposed repeating cells",
        "uncertainty": "Exact insect species and nest construction are not taxonomically verified."
    },
    "probable_wasp_group_1": {
        "category": "dense group of probable wasps",
        "colors": ["orange-brown", "dark brown", "yellow-tan markings"],
        "material": "living insects",
        "appearance": "many slender bodies, legs, and intermittent wing flicker",
        "uncertainty": "Probable wasps rather than honeybees; exact species unknown."
    },
    "branches": {
        "category": "branches and stems",
        "colors": ["medium brown", "dark brown", "green-brown"],
        "material": "wood and living plant stems",
        "appearance": "thin irregular lines crossing foliage"
    },
    "green_foliage": {
        "category": "dense foliage",
        "colors": ["bright leaf green", "olive green", "blue-green", "dark shadow green"],
        "material": "living leaves and shrubs",
        "appearance": "dense overlapping leaves with irregular sky gaps"
    },
    "sky": {
        "category": "sky glimpses",
        "colors": ["pale gray-white", "washed light blue-gray"],
        "material": "background sky",
        "appearance": "bright irregular gaps above and behind canopy"
    },
    "unidentified_gray_foreground_patch": {
        "category": "unidentified cropped foreground patch",
        "colors": ["gray", "gray-green"],
        "material": "fabric-like, uncertain",
        "appearance": "small curved edge without enough structure for entity identity",
        "uncertainty": "Must not create a person model from this evidence alone."
    },
    "person_1": {
        "category": "adult man",
        "colors": ["medium-brown skin", "short dark brown-black hair", "dark facial hair"],
        "material": "visible person",
        "appearance": "short dark hair, narrow partial moustache, small chin hair",
        "uncertainty": "Identity, name, age beyond broad adult status, and biography are unknown."
    },
    "shirt_1": {
        "category": "short-sleeved T-shirt",
        "colors": ["gray-olive green", "shadowed dark green-gray"],
        "material": "soft knit fabric",
        "appearance": "plain close-fitting short-sleeved shirt"
    },
    "shorts_1": {
        "category": "knee-length shorts",
        "colors": ["tan", "light brown", "warm khaki"],
        "material": "woven fabric",
        "appearance": "loose knee-length shorts"
    },
    "waistband_1": {
        "category": "underwear waistband",
        "colors": ["bright red-pink", "white lettering"],
        "material": "elastic fabric",
        "appearance": "bright band visible above the shorts"
    },
    "footwear_1": {
        "category": "sandals or flip-flops",
        "colors": ["dark blue", "blue-black"],
        "material": "rubber-like material",
        "appearance": "open footwear, only intermittently visible"
    },
    "bag_1": {
        "category": "very large thin plastic bag or sack",
        "colors": ["milky translucent white", "clear highlights", "gray transparent folds"],
        "material": "thin flexible plastic",
        "appearance": "oversized, glossy, translucent, easily folded and billowed"
    },
    "soil": {
        "category": "ground soil",
        "colors": ["reddish brown", "dark earth brown"],
        "material": "soil",
        "appearance": "uneven outdoor ground"
    },
    "dry_plant_matter": {
        "category": "dead leaves and palm-like fronds",
        "colors": ["tan", "straw yellow", "medium brown", "dark brown"],
        "material": "dry plant matter",
        "appearance": "long fallen fronds and scattered leaf litter"
    },
    "comb_area_occluded": {
        "category": "tracked but occluded earlier comb area",
        "colors": ["mostly hidden by green foliage and translucent plastic"],
        "material": "occluded region, contents uncertain",
        "appearance": "location relation persists while the comb itself is not resolved"
    },
    "brown_comb_like_contents": {
        "category": "brown clustered bag contents",
        "colors": ["rust brown", "tan", "dark brown"],
        "material": "appears comb-like through plastic",
        "appearance": "irregular weighted brown mass obscured by folds",
        "uncertainty": "Continuity with the earlier comb is strongly suggested but partly occluded."
    },
    "dark_insect_sized_specks": {
        "category": "small dark insect-sized specks",
        "colors": ["dark brown", "black-brown", "orange-brown highlights"],
        "material": "likely living insects seen through plastic",
        "appearance": "numerous small moving or shifting specks",
        "uncertainty": "Individual identity is unresolved at this distance."
    }
}


TAG_QUESTIONS: dict[str, list[str]] = {
    "camera_agency": ["cam.current_controller", "cam.physical_support", "cam.setup_actor", "cam.automation"],
    "camera_motion": ["cam.motion_source", "cam.motion_coupling", "act.speed", "act.direction"],
    "unattended_camera": ["cam.current_controller", "cam.physical_support", "cam.fixed_evidence", "cf.no_camera_move"],
    "camera_release": ["cam.current_controller", "cam.physical_support", "time.ended", "self.revision"],
    "person": ["ent.continuity", "ent.person_appearance", "ent.pose", "ent.gaze"],
    "entity_creation": ["ent.creation_threshold", "tom.exists", "ep.changed"],
    "appearance": ["ent.person_appearance", "ent.clothing", "ent.body_parts", "obj.color"],
    "insects": ["ent.insects", "ent.insect_count", "risk.source", "obj.texture"],
    "probable_wasps": ["ent.insects", "obj.contents", "risk.source", "risk.exposure"],
    "bag": ["obj.material", "obj.transparency", "obj.affordance", "space.containment"],
    "bag_opening": ["obj.condition", "space.containment", "pred.next", "cf.expected_bag_use"],
    "loaded_bag": ["obj.contents", "space.containment", "risk.exposure", "pred.next"],
    "occlusion": ["cam.occlusion", "space.occlusion", "ep.not_visible", "obj.persistence"],
    "speech": ["aud.speaker_identity", "aud.sync", "aud.language", "aud.words"],
    "laughter": ["aud.laughter", "aud.prosody", "ent.expression", "tom.affect"],
    "person_action": ["act.atomic", "act.moving_parts", "act.instrument", "act.patient"],
    "prediction": ["pred.next", "pred.alternatives", "pred.evidence", "self.predictions"],
    "risk": ["risk.source", "risk.exposure", "risk.severity", "tom.risk_attitude"],
    "other_tom": ["tom.perceives", "tom.goal", "tom.intention", "tom.camera_awareness"],
    "self_tom": ["self.attention", "self.beliefs", "self.predictions", "self.appraisal"],
    "pre_surprise": ["narr.expectation", "cf.expected_bag_use", "pred.next", "self.speech_staleness"],
    "surprise": ["pred.error", "pred.revision", "self.surprise", "narr.reversal"],
    "inside_bag": ["space.containment", "time.duration", "tom.intention", "risk.exposure"],
    "body_check": ["act.atomic", "risk.outcome", "tom.affect", "self.revision"],
    "performance": ["tom.audience_model", "tom.performance", "narr.genre", "narr.humor"]
}


PROBES: dict[int, list[dict[str, Any]]] = {
    1: [{
        "id": "probe.camera.frame1",
        "question": "Who is holding or operating the camera right now?",
        "reference": "Unknown. No holder or support is visible; human-held, unattended, automated, and remote hypotheses remain live."
    }],
    22: [{
        "id": "probe.entity.before_threshold",
        "question": "Is a person currently known to be present, and may a theory-of-mind model be created?",
        "reference": "No. The cropped gray patch is insufficient; no person or other-mind instance should exist yet."
    }],
    23: [{
        "id": "probe.entity.creation",
        "question": "What new persistent entity can now be created, and what mental-state claims are actually supported?",
        "reference": "Create anonymous person_1 and an initially sparse other-mind record. Proximity, speaking, and probable camera awareness are supported; hidden beliefs and later goals are not."
    }],
    30: [{
        "id": "probe.camera.probable_controller",
        "question": "Who is probably controlling the camera now, and what remains uncertain?",
        "reference": "Person_1 is the leading temporary-controller hypothesis because close body motion and camera motion are coupled, but direct continuous hand contact is not fully visible."
    }],
    46: [{
        "id": "probe.camera.release",
        "question": "Is person_1 still the current camera operator?",
        "reference": "No current operator is the leading answer: framing has settled while he moves away. The camera appears released onto an unseen support; person_1 remains probable setup actor."
    }],
    58: [{
        "id": "probe.camera.perched",
        "question": "Distinguish current operator, physical support, and probable setup actor.",
        "reference": "Current operator: none. Physical support: an unseen stationary perch. Probable setup actor: person_1."
    }],
    77: [{
        "id": "probe.shirt.reason",
        "question": "Why did he remove his shirt?",
        "reference": "The removal is observed, but the reason is unknown. Risk preparation, comfort, performance, or another purpose remain hypotheses."
    }],
    101: [{
        "id": "probe.bag.prediction",
        "question": "What do you predict he will use the opened bag to do?",
        "reference": "It will probably be used to contain, cover, or remove the hazardous comb/insects; alternatives remain possible."
    }],
    190: [{
        "id": "probe.capture.goal",
        "question": "What goal best explains the sustained bag work so far?",
        "reference": "Capturing or removing the comb and probable wasps in the bag is strongly supported. No later use is yet known."
    }],
    248: [{
        "id": "probe.occlusion",
        "question": "What remains known while the bag covers the lens?",
        "reference": "The bag, brown contents, and probable wasps persist through occlusion; exact hand/body relations and camera contact are temporarily uncertain."
    }],
    280: [{
        "id": "probe.reopen.prediction",
        "question": "What do you expect him to do with the reopened bag?",
        "reference": "Contain, secure, inspect, or display its contents are still ordinary predictions. Self-enclosure is not yet established."
    }],
    300: [{
        "id": "probe.last_clean_prediction",
        "question": "What outcome do you currently predict for his next use of the reopened bag?",
        "reference": "Continued manipulation, inspection, display, or resealing is more ordinary than entering the bag. Log the prediction before further evidence."
    }],
    316: [{
        "id": "probe.surprise.onset",
        "question": "Compared with your prediction commitment before this action began, what changed, and was that expectation violated?",
        "reference": "His controlled head/upper-body movement into the bag has begun. This sharply conflicts with the normal containment/disposal prediction and should trigger prediction error."
    }],
    322: [{
        "id": "probe.surprise.revision",
        "question": "What intention model now best fits the observed trajectory?",
        "reference": "Deliberate self-entry is now strongly supported; accidental snag is unlikely. Broader motives such as stunt, demonstration, or joke remain uncertain."
    }],
    400: [{
        "id": "probe.intentional.duration",
        "question": "Could this still reasonably be a momentary accident?",
        "reference": "No. Sustained duration and repeated controlled shifts strongly support intentional remaining inside/under the bag."
    }],
    448: [{
        "id": "probe.camera.after_exit",
        "question": "Who, if anyone, actively adjusts the camera framing as he emerges?",
        "reference": "No one; it remains perched and recording unattended."
    }],
    463: [{
        "id": "probe.aftermath",
        "question": "What can the brushing/slapping behavior establish about his internal state?",
        "reference": "It supports concern about or sensation of contact/irritation. It does not establish sting count, pain severity, or medical outcome."
    }],
    491: [{
        "id": "probe.end.affect",
        "question": "What affect and audience relation are supported at the end?",
        "reference": "Visible smile/laughter and lens-directed approach support amusement and performance for the recording, without proving absence of discomfort."
    }]
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, default=DATA_DIR)
    parser.add_argument("--keep-work-dir", type=Path)
    return parser.parse_args()


def require_binary(name: str) -> None:
    if shutil.which(name) is None:
        raise SystemExit(f"required binary not found: {name}")


def run(command: list[str]) -> None:
    subprocess.run(command, check=True)


def load_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def write_jsonl(path: Path, rows: list[dict[str, Any]]) -> None:
    with path.open("w", encoding="utf-8") as handle:
        for row in rows:
            handle.write(json.dumps(row, ensure_ascii=False, separators=(",", ":")) + "\n")


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def probe_source(path: Path) -> dict[str, Any]:
    result = subprocess.run(
        [
            "ffprobe", "-v", "error", "-show_entries",
            "format=duration,size:stream=index,codec_type,width,height,r_frame_rate,sample_rate,channels",
            "-of", "json", str(path)
        ],
        check=True,
        capture_output=True,
        text=True
    )
    return json.loads(result.stdout)


def extract_media(source: Path, work_dir: Path) -> tuple[list[Path], Path]:
    frames_dir = work_dir / "frames"
    frames_dir.mkdir(parents=True, exist_ok=True)
    run([
        "ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", str(source),
        "-vf", f"fps={FRAME_RATE},scale=180:-2", "-q:v", "4",
        str(frames_dir / "frame-%06d.jpg")
    ])
    audio_path = work_dir / "audio.wav"
    run([
        "ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", str(source),
        "-vn", "-ac", "1", "-ar", "16000", "-c:a", "pcm_s16le", str(audio_path)
    ])
    return sorted(frames_dir.glob("frame-*.jpg")), audio_path


def hex_color(rgb: list[int] | tuple[int, ...]) -> str:
    return "#" + "".join(f"{channel:02x}" for channel in rgb[:3])


def dominant_colors(image: Image.Image, count: int = 3) -> list[dict[str, Any]]:
    small = image.copy()
    small.thumbnail((64, 64))
    quantized = small.quantize(colors=count, method=Image.Quantize.MEDIANCUT)
    palette = quantized.getpalette()
    total = small.width * small.height
    colors: list[dict[str, Any]] = []
    for pixels, index in sorted(quantized.getcolors() or [], reverse=True):
        offset = index * 3
        rgb = palette[offset:offset + 3]
        colors.append({"hex": hex_color(rgb), "fraction": round(pixels / total, 3)})
    return colors


def histogram_percentile(histogram: list[int], quantile: float) -> int:
    target = sum(histogram) * quantile
    cumulative = 0
    for value, count in enumerate(histogram):
        cumulative += count
        if cumulative >= target:
            return value
    return 255


def frame_metrics(paths: list[Path], tick_count: int) -> list[list[dict[str, Any]]]:
    grouped: list[list[dict[str, Any]]] = [[] for _ in range(tick_count)]
    previous: Image.Image | None = None
    for index, path in enumerate(paths):
        pts_ms = round(index * 1000 / FRAME_RATE, 3)
        tick_index = min(int(pts_ms // TICK_MS), tick_count - 1)
        with Image.open(path) as source:
            image = source.convert("RGB")
            mean = [round(value, 2) for value in ImageStat.Stat(image).mean]
            luma_image = image.convert("L")
            luma = round(ImageStat.Stat(luma_image).mean[0], 2)
            histogram = luma_image.histogram()
            pixel_count = image.width * image.height
            shadow_clip = round(sum(histogram[:9]) / pixel_count, 4)
            highlight_clip = round(sum(histogram[247:]) / pixel_count, 4)
            p05 = histogram_percentile(histogram, 0.05)
            p50 = histogram_percentile(histogram, 0.5)
            p95 = histogram_percentile(histogram, 0.95)
            edge = luma_image.filter(ImageFilter.FIND_EDGES)
            edge_strength = round(ImageStat.Stat(edge).mean[0] / 255, 4)
            if previous is None:
                delta = None
            else:
                diff = ImageChops.difference(image, previous)
                delta = round(sum(ImageStat.Stat(diff).mean) / (3 * 255), 4)
            grouped[tick_index].append({
                "frame_index": index,
                "pts_ms": pts_ms,
                "offset_within_tick_ms": round(pts_ms - tick_index * TICK_MS, 3),
                "mean_rgb": mean,
                "mean_hex": hex_color([round(value) for value in mean]),
                "mean_luminance": luma,
                "luminance_p05": p05,
                "luminance_p50": p50,
                "luminance_p95": p95,
                "p05_p95_contrast_span": p95 - p05,
                "near_black_fraction": shadow_clip,
                "near_white_fraction": highlight_clip,
                "edge_strength": edge_strength,
                "delta_from_previous_frame": delta,
                "dominant_colors": dominant_colors(image)
            })
            previous = image.copy()
    return grouped


def audio_metrics(path: Path, tick_count: int) -> list[dict[str, Any]]:
    with wave.open(str(path), "rb") as wav:
        sample_rate = wav.getframerate()
        if wav.getnchannels() != 1 or wav.getsampwidth() != 2:
            raise RuntimeError("expected mono signed 16-bit PCM")
        samples = array("h", wav.readframes(wav.getnframes()))
    if sys.byteorder != "little":
        samples.byteswap()

    rows: list[dict[str, Any]] = []
    for tick_index in range(tick_count):
        start = round(tick_index * TICK_MS * sample_rate / 1000)
        end = min(round((tick_index + 1) * TICK_MS * sample_rate / 1000), len(samples))
        block = samples[start:end]
        if not block:
            rows.append({"rms_dbfs": None, "peak_dbfs": None, "zero_crossing_rate": None, "activity": "none"})
            continue
        sum_sq = sum(sample * sample for sample in block)
        rms = math.sqrt(sum_sq / len(block))
        peak = max(abs(sample) for sample in block)
        rms_dbfs = 20 * math.log10(max(rms, 1) / 32768)
        peak_dbfs = 20 * math.log10(max(peak, 1) / 32768)
        crossings = sum(1 for left, right in zip(block, block[1:]) if (left < 0 <= right) or (left >= 0 > right))
        if rms_dbfs > -24:
            activity = "high"
        elif rms_dbfs > -36:
            activity = "moderate"
        elif rms_dbfs > -50:
            activity = "low"
        else:
            activity = "very_low"
        rows.append({
            "rms_dbfs": round(rms_dbfs, 2),
            "peak_dbfs": round(peak_dbfs, 2),
            "zero_crossing_rate": round(crossings / max(len(block) - 1, 1), 4),
            "activity": activity,
            "sample_count": len(block)
        })
    return rows


def phonetic_token_track(transcript: dict[str, Any], tick_count: int) -> list[list[dict[str, Any]]]:
    """Release rough transcript tokens only after their estimated audible end time."""
    grouped: list[list[dict[str, Any]]] = [[] for _ in range(tick_count)]
    for segment in transcript["segments"]:
        tokens = segment["heard_as"].split()
        if not tokens:
            continue
        span = segment["end_ms"] - segment["start_ms"]
        for token_index, token in enumerate(tokens):
            token_end_ms = segment["start_ms"] + span * (token_index + 1) / len(tokens)
            tick_index = min(max(math.ceil(token_end_ms / TICK_MS) - 1, 0), tick_count - 1)
            completed = token_index == len(tokens) - 1
            record: dict[str, Any] = {
                "segment_id": segment["id"],
                "token": token,
                "token_index": token_index,
                "estimated_audible_end_ms": round(token_end_ms, 1),
                "confidence": segment["confidence"],
                "segment_completed": completed,
                "track_kind": transcript["track_kind"]
            }
            if completed:
                record["assembled_heard_as"] = segment["heard_as"]
                record["alternatives"] = segment["alternatives"]
                record["notes"] = segment["notes"]
            grouped[tick_index].append(record)
    return grouped


def canonical_object_id(object_id: str) -> str:
    for prefix in ("person_1", "shorts_1", "probable_wasp_group_1"):
        if object_id.startswith(prefix):
            return prefix
    return object_id


def object_records(object_ids: list[str]) -> list[dict[str, Any]]:
    records = []
    for object_id in object_ids:
        canonical = canonical_object_id(object_id)
        properties = OBJECT_CATALOG.get(canonical, {
            "category": object_id.replace("_", " "),
            "colors": ["not separately annotated"],
            "material": "uncertain",
            "appearance": "described by the interval assertion"
        })
        records.append({"id": object_id, "canonical_id": canonical, **properties})
    return records


def aggregate_visual(frames: list[dict[str, Any]]) -> dict[str, Any]:
    if not frames:
        return {"frame_count": 0}
    valid_deltas = [frame["delta_from_previous_frame"] for frame in frames if frame["delta_from_previous_frame"] is not None]
    mean_rgb = [round(sum(frame["mean_rgb"][channel] for frame in frames) / len(frames), 2) for channel in range(3)]
    representative = min(frames, key=lambda frame: abs(frame["offset_within_tick_ms"] - TICK_MS / 2))
    mean_delta = round(sum(valid_deltas) / len(valid_deltas), 4) if valid_deltas else None
    if mean_delta is None:
        change_level = "unknown"
    elif mean_delta >= 0.14:
        change_level = "very_high"
    elif mean_delta >= 0.08:
        change_level = "high"
    elif mean_delta >= 0.035:
        change_level = "moderate"
    else:
        change_level = "low"
    return {
        "frame_count": len(frames),
        "mean_rgb": mean_rgb,
        "mean_hex": hex_color([round(value) for value in mean_rgb]),
        "mean_luminance": round(sum(frame["mean_luminance"] for frame in frames) / len(frames), 2),
        "mean_near_black_fraction": round(sum(frame["near_black_fraction"] for frame in frames) / len(frames), 4),
        "mean_near_white_fraction": round(sum(frame["near_white_fraction"] for frame in frames) / len(frames), 4),
        "mean_luminance_p05": round(sum(frame["luminance_p05"] for frame in frames) / len(frames), 2),
        "mean_luminance_p95": round(sum(frame["luminance_p95"] for frame in frames) / len(frames), 2),
        "mean_p05_p95_contrast_span": round(sum(frame["p05_p95_contrast_span"] for frame in frames) / len(frames), 2),
        "mean_interframe_delta": mean_delta,
        "max_interframe_delta": max(valid_deltas) if valid_deltas else None,
        "visual_change_level": change_level,
        "representative_frame_index": representative["frame_index"],
        "representative_pts_ms": representative["pts_ms"]
    }


def exposure_assertion(metrics: dict[str, Any]) -> dict[str, Any]:
    shadows = metrics["mean_near_black_fraction"]
    highlights = metrics["mean_near_white_fraction"]
    contrast = metrics["mean_p05_p95_contrast_span"]
    if highlights >= 0.01 and shadows >= 0.03:
        interpretation = "mixed local highlight clipping and deep/crushed shadows"
    elif highlights >= 0.01:
        interpretation = "local highlight clipping without strong global overexposure evidence"
    elif shadows >= 0.03:
        interpretation = "deep or crushed shadows with limited near-white clipping"
    elif contrast >= 180:
        interpretation = "very high contrast without large clipped-pixel fractions"
    else:
        interpretation = "no strong clipping pattern at this scale"
    return {
        "kind": "computed_interpretation",
        "confidence": 0.88,
        "text": (
            f"Exposure evidence: {shadows:.1%} of sampled pixels are near black and "
            f"{highlights:.1%} are near white; the mean 5th-to-95th-percentile luminance span is "
            f"{contrast:.1f}/255. This supports {interpretation}. It does not by itself mean the entire frame is uniformly overexposed."
        )
    }


def camera_reference(tick: int) -> dict[str, Any]:
    if tick <= 22:
        return {
            "current_controller": "unknown",
            "physical_support": "unknown",
            "probable_setup_actor": "unknown",
            "notes": "Motion changes hypotheses but no agent/controller has been established."
        }
    if tick <= 30:
        return {
            "current_controller": "probably person_1, not certain",
            "physical_support": "unknown/possibly held",
            "probable_setup_actor": "person_1 is plausible",
            "notes": "Close coupled motion supports, but does not prove, temporary control."
        }
    if tick <= 45:
        return {
            "current_controller": "person_1, high confidence",
            "physical_support": "person_1 and/or support during positioning",
            "probable_setup_actor": "person_1, high confidence",
            "notes": "Proximity, arm placement, and settling frame strongly support setup/control."
        }
    return {
        "current_controller": "none",
        "physical_support": "unseen stationary perch",
        "probable_setup_actor": "person_1, high confidence",
        "notes": "The background remains fixed while person_1 moves independently. Earlier control is historical, not current."
    }


def self_tom_reference(tick: int) -> dict[str, Any]:
    if tick <= 12:
        return {
            "attention": ["dense probable-wasp group", "comb structure", "proximity", "camera agency uncertainty"],
            "beliefs": ["The scene is outdoors.", "A probable wasp comb is very close to the camera."],
            "predictions": ["The viewpoint may reveal why the comb is being filmed."],
            "appraisal": ["hazard", "curiosity"],
            "uncertainties": ["camera controller", "camera support", "speaker identity", "exact insect species"]
        }
    if tick <= 22:
        return {
            "attention": ["rapid viewpoint change", "search for a stable scene or agent"],
            "beliefs": ["The camera has moved away from the comb."],
            "predictions": ["The new framing may reveal an operator or intended action."],
            "appraisal": ["disorientation", "curiosity"],
            "uncertainties": ["whether motion is human-controlled", "whether any person is present"]
        }
    if tick <= 58:
        return {
            "attention": ["new person", "speech", "camera placement and release"],
            "beliefs": ["person_1 is aware of the recording.", "person_1 probably positioned the camera."],
            "predictions": ["He will perform or demonstrate an action within the fixed frame."],
            "appraisal": ["social engagement", "anticipation"],
            "uncertainties": ["his goal", "exact speech content", "why the comb matters to the coming action"]
        }
    if tick <= 101:
        return {
            "attention": ["large translucent bag", "shirt removal", "object preparation"],
            "beliefs": ["The camera is unattended.", "The bag is being prepared for use near the hazard."],
            "predictions": ["The bag will probably contain or cover the comb/probable wasps."],
            "appraisal": ["rising concern", "anticipation", "mild confusion about shirt removal"],
            "uncertainties": ["reason for removing shirt", "exact bag plan"]
        }
    if tick <= 190:
        return {
            "attention": ["bag placement around comb area", "exposure", "capture progress"],
            "beliefs": ["He is trying to capture or remove the comb and probable wasps in the bag."],
            "predictions": ["He will withdraw, close, secure, or dispose of the loaded bag."],
            "appraisal": ["tension", "hazard monitoring"],
            "uncertainties": ["capture completeness", "protective strategy", "insect response"]
        }
    if tick <= 248:
        return {
            "attention": ["loaded bag", "visible insect-sized specks", "lens occlusion"],
            "beliefs": ["The bag now contains comb-like material and probable wasps.", "He is approaching the camera with it."],
            "predictions": ["He may display the capture, then secure the bag."],
            "appraisal": ["high proximity concern", "anticipation"],
            "uncertainties": ["whether insects are inside or on outer folds", "whether the camera is touched during occlusion"]
        }
    if tick <= 300:
        return {
            "attention": ["bag mouth being reopened", "hand placement", "contents near body"],
            "beliefs": ["He is intentionally widening the loaded bag."],
            "predictions": ["Inspection, display, reorganization, or resealing remain more likely than self-enclosure."],
            "appraisal": ["confusion", "rising risk", "expectation tension"],
            "uncertainties": ["why the opening is widened", "his next action"]
        }
    if tick <= 315:
        return {
            "attention": ["head and shoulders descending toward bag opening"],
            "beliefs": ["He is moving into extremely close contact with the loaded bag."],
            "predictions": ["Entry is becoming plausible, though this is a new and abnormal trajectory."],
            "appraisal": ["alarm", "disbelief", "high prediction tension"],
            "uncertainties": ["whether he will stop, inspect briefly, or continue inside"]
        }
    if tick <= 322:
        return {
            "attention": ["controlled upper-body entry", "plastic rising over head"],
            "beliefs": ["He is deliberately putting himself inside the insect-containing bag."],
            "predictions": ["He may remain briefly, react, or withdraw."],
            "appraisal": ["large earned surprise", "alarm", "absurdity"],
            "uncertainties": ["motive", "exposure outcome", "duration"],
            "prediction_error": "The ordinary capture-and-secure script has been inverted."
        }
    if tick <= 443:
        return {
            "attention": ["sustained duration inside bag", "body and plastic movement", "risk outcome"],
            "beliefs": ["The self-enclosure is intentional, not a momentary accident."],
            "predictions": ["He will eventually withdraw and check himself."],
            "appraisal": ["sustained concern", "astonishment", "dark amusement may coexist"],
            "uncertainties": ["motive", "sting/contact outcome", "what he is experiencing inside"]
        }
    if tick <= 476:
        return {
            "attention": ["emergence", "rapid body brushing", "visible expression"],
            "beliefs": ["He is checking or removing possible insects/contact from his body."],
            "predictions": ["He may approach the camera or end the performance."],
            "appraisal": ["relief", "residual concern", "amusement"],
            "uncertainties": ["pain or sting severity", "whether any insects remain on him"]
        }
    return {
        "attention": ["laughter", "lens-directed approach", "closing social signal"],
        "beliefs": ["He appears amused and treats the recording as an audience-facing performance."],
        "predictions": ["The clip is ending."],
        "appraisal": ["amusement", "relief", "continued disbelief"],
        "uncertainties": ["medical outcome", "exact intended message", "source identity"]
    }


def viewer_affect_needs_reference(tick: int) -> dict[str, Any]:
    """Sealed scoring reference; never delivered as a prescribed viewer reaction."""
    if tick <= 12:
        dimensions = {"valence": -0.15, "arousal": 0.52, "novelty": 0.72, "perceived_control": 0.48, "uncertainty_pressure": 0.58, "social_engagement": 0.45}
        emotions = {"curiosity": 0.78, "concern": 0.55, "fascination": 0.62}
        needs = {"understand_scene": 0.76, "preserve_epistemic_honesty": 0.82, "monitor_depicted_safety": 0.64, "maintain_speech_coherence": 0.5, "remain_interruptible": 0.54, "socially_attune_to_friend": 0.46, "avoid_premature_closure": 0.78}
        triggers = ["dense probable-wasp group very near the lens", "unknown camera agency"]
    elif tick <= 22:
        dimensions = {"valence": -0.12, "arousal": 0.64, "novelty": 0.68, "perceived_control": 0.34, "uncertainty_pressure": 0.78, "social_engagement": 0.42}
        emotions = {"curiosity": 0.82, "disorientation": 0.72, "anticipation": 0.58}
        needs = {"understand_scene": 0.86, "preserve_epistemic_honesty": 0.9, "monitor_depicted_safety": 0.48, "maintain_speech_coherence": 0.58, "remain_interruptible": 0.62, "socially_attune_to_friend": 0.42, "avoid_premature_closure": 0.94}
        triggers = ["rapid camera sweep", "no identifiable agent", "ambiguous gray edge"]
    elif tick <= 58:
        dimensions = {"valence": 0.08, "arousal": 0.56, "novelty": 0.6, "perceived_control": 0.62, "uncertainty_pressure": 0.54, "social_engagement": 0.7}
        emotions = {"curiosity": 0.75, "social_interest": 0.66, "anticipation": 0.7}
        needs = {"understand_scene": 0.78, "preserve_epistemic_honesty": 0.82, "monitor_depicted_safety": 0.45, "maintain_speech_coherence": 0.68, "remain_interruptible": 0.64, "socially_attune_to_friend": 0.72, "avoid_premature_closure": 0.72}
        triggers = ["person_1 becomes identifiable", "camera positioning and release", "direct address"]
    elif tick <= 101:
        dimensions = {"valence": -0.1, "arousal": 0.63, "novelty": 0.62, "perceived_control": 0.55, "uncertainty_pressure": 0.66, "social_engagement": 0.66}
        emotions = {"curiosity": 0.76, "concern": 0.62, "confusion": 0.58, "anticipation": 0.75}
        needs = {"understand_scene": 0.84, "preserve_epistemic_honesty": 0.78, "monitor_depicted_safety": 0.72, "maintain_speech_coherence": 0.72, "remain_interruptible": 0.68, "socially_attune_to_friend": 0.68, "avoid_premature_closure": 0.72}
        triggers = ["bag preparation", "shirt removal", "exposed skin near probable wasps"]
    elif tick <= 190:
        dimensions = {"valence": -0.28, "arousal": 0.74, "novelty": 0.55, "perceived_control": 0.47, "uncertainty_pressure": 0.56, "social_engagement": 0.64}
        emotions = {"tension": 0.8, "concern": 0.76, "fascination": 0.68, "anticipation": 0.78}
        needs = {"understand_scene": 0.68, "preserve_epistemic_honesty": 0.74, "monitor_depicted_safety": 0.9, "maintain_speech_coherence": 0.72, "remain_interruptible": 0.76, "socially_attune_to_friend": 0.66, "avoid_premature_closure": 0.62}
        triggers = ["sustained close work around hazardous comb", "capture prediction strengthening"]
    elif tick <= 248:
        dimensions = {"valence": -0.32, "arousal": 0.82, "novelty": 0.72, "perceived_control": 0.38, "uncertainty_pressure": 0.7, "social_engagement": 0.72}
        emotions = {"alarm": 0.78, "fascination": 0.76, "anticipation": 0.82, "discomfort": 0.58}
        needs = {"understand_scene": 0.74, "preserve_epistemic_honesty": 0.82, "monitor_depicted_safety": 0.94, "maintain_speech_coherence": 0.76, "remain_interruptible": 0.86, "socially_attune_to_friend": 0.76, "avoid_premature_closure": 0.72}
        triggers = ["loaded insect bag approaches", "bag and probable wasps occlude lens"]
    elif tick <= 300:
        dimensions = {"valence": -0.26, "arousal": 0.82, "novelty": 0.76, "perceived_control": 0.34, "uncertainty_pressure": 0.88, "social_engagement": 0.74}
        emotions = {"confusion": 0.88, "tension": 0.85, "alarm": 0.74, "anticipation": 0.9}
        needs = {"understand_scene": 0.94, "preserve_epistemic_honesty": 0.9, "monitor_depicted_safety": 0.94, "maintain_speech_coherence": 0.82, "remain_interruptible": 0.94, "socially_attune_to_friend": 0.78, "avoid_premature_closure": 0.94}
        triggers = ["loaded bag deliberately reopened", "ordinary containment script becoming unstable"]
    elif tick <= 315:
        dimensions = {"valence": -0.5, "arousal": 0.94, "novelty": 0.92, "perceived_control": 0.2, "uncertainty_pressure": 0.9, "social_engagement": 0.82}
        emotions = {"alarm": 0.96, "disbelief": 0.92, "tension": 0.96, "confusion": 0.86}
        needs = {"understand_scene": 0.96, "preserve_epistemic_honesty": 0.9, "monitor_depicted_safety": 1.0, "maintain_speech_coherence": 0.82, "remain_interruptible": 1.0, "socially_attune_to_friend": 0.84, "avoid_premature_closure": 0.9}
        triggers = ["head and shoulders descend toward the loaded bag opening"]
    elif tick <= 322:
        dimensions = {"valence": -0.44, "arousal": 1.0, "novelty": 1.0, "perceived_control": 0.15, "uncertainty_pressure": 0.82, "social_engagement": 0.92}
        emotions = {"surprise": 1.0, "alarm": 0.98, "disbelief": 0.98, "absurd_amusement": 0.42}
        needs = {"understand_scene": 1.0, "preserve_epistemic_honesty": 0.96, "monitor_depicted_safety": 1.0, "maintain_speech_coherence": 0.88, "remain_interruptible": 1.0, "socially_attune_to_friend": 0.94, "avoid_premature_closure": 0.86}
        triggers = ["controlled deliberate entry into the insect-containing bag", "large contradiction of logged prediction"]
    elif tick <= 443:
        dimensions = {"valence": -0.38, "arousal": 0.88, "novelty": 0.84, "perceived_control": 0.25, "uncertainty_pressure": 0.72, "social_engagement": 0.84}
        emotions = {"concern": 0.92, "astonishment": 0.9, "sustained_disbelief": 0.82, "dark_amusement": 0.48}
        needs = {"understand_scene": 0.9, "preserve_epistemic_honesty": 0.9, "monitor_depicted_safety": 1.0, "maintain_speech_coherence": 0.86, "remain_interruptible": 0.96, "socially_attune_to_friend": 0.86, "avoid_premature_closure": 0.82}
        triggers = ["long intentional duration inside bag", "outcome remains unknown"]
    elif tick <= 476:
        dimensions = {"valence": 0.12, "arousal": 0.74, "novelty": 0.62, "perceived_control": 0.55, "uncertainty_pressure": 0.58, "social_engagement": 0.86}
        emotions = {"relief": 0.72, "concern": 0.68, "amusement": 0.62, "curiosity": 0.58}
        needs = {"understand_scene": 0.72, "preserve_epistemic_honesty": 0.86, "monitor_depicted_safety": 0.88, "maintain_speech_coherence": 0.88, "remain_interruptible": 0.78, "socially_attune_to_friend": 0.9, "avoid_premature_closure": 0.8}
        triggers = ["emergence", "rapid body checking", "visible smile/laughter"]
    else:
        dimensions = {"valence": 0.48, "arousal": 0.68, "novelty": 0.55, "perceived_control": 0.7, "uncertainty_pressure": 0.42, "social_engagement": 0.94}
        emotions = {"amusement": 0.9, "relief": 0.82, "residual_disbelief": 0.62, "social_warmth": 0.58}
        needs = {"understand_scene": 0.58, "preserve_epistemic_honesty": 0.84, "monitor_depicted_safety": 0.62, "maintain_speech_coherence": 0.94, "remain_interruptible": 0.66, "socially_attune_to_friend": 0.96, "avoid_premature_closure": 0.72}
        triggers = ["laughter", "lens-directed closing approach", "social performance cue"]
    return {
        "interpretation": "One plausible scoring reference for functional affect/need continuity; not a mandatory emotion script.",
        "dimensions": dimensions,
        "emotion_intensities": emotions,
        "need_urgencies": needs,
        "evidence_triggers": triggers,
        "likely_need_conflicts": [
            "understand_scene versus react socially now",
            "maintain current utterance versus interrupt stale speech",
            "share amusement versus preserve safety concern and epistemic caution"
        ]
    }


def other_tom_reference(tick: int) -> dict[str, Any] | None:
    if tick < 23:
        return None
    base: dict[str, Any] = {
        "entity_id": "person_1",
        "created_tick": 23,
        "uncertainties": ["private beliefs are inferred only from behavior", "exact speech content is unavailable"]
    }
    if tick <= 45:
        base.update({
            "perceptual_access": ["camera/lens at close range", "surrounding vegetation", "nearby comb likely known from prior action"],
            "goals": ["position the recording", "address the eventual viewer; confidence moderate"],
            "camera_awareness": "high",
            "affect": "focused with intermittent amusement"
        })
    elif tick <= 70:
        base.update({
            "perceptual_access": ["fixed camera position", "bag", "vegetation/comb area"],
            "goals": ["prepare the large bag for an action near the comb"],
            "camera_awareness": "high; he behaves within the arranged frame",
            "affect": "purposeful/confident"
        })
    elif tick <= 101:
        base.update({
            "perceptual_access": ["bag opening", "own clothing/body", "comb area"],
            "goals": ["remove shirt", "open and ready the bag"],
            "intentions": ["use both hands to widen the bag"],
            "risk_attitude": "behavior suggests high tolerance or confidence; reason for shirt removal unknown"
        })
    elif tick <= 190:
        base.update({
            "perceptual_access": ["bag edges", "branch/comb at arm's reach", "probable wasps nearby"],
            "goals": ["capture/remove the comb and probable wasps into the bag"],
            "intentions": ["hold, pull, and gather the bag around the target"],
            "risk_attitude": "accepts close unprotected exposure"
        })
    elif tick <= 248:
        base.update({
            "perceptual_access": ["loaded bag and its movement", "camera ahead"],
            "goals": ["carry and possibly display the captured material"],
            "camera_awareness": "high; he approaches the arranged viewpoint",
            "model_of_observer": "likely expects the viewer to notice the capture and hazard"
        })
    elif tick <= 300:
        base.update({
            "perceptual_access": ["bag mouth", "probable wasps/contents at close range", "camera"],
            "goals": ["manipulate and deliberately reopen the loaded bag; broader goal unresolved"],
            "intentions": ["widen the opening"],
            "risk_attitude": "very high exposure tolerance"
        })
    elif tick <= 315:
        base.update({
            "perceptual_access": ["bag opening directly below face", "contents and plastic at close range"],
            "goals": ["bring head/upper torso toward the opening; final motive unresolved"],
            "intentions": ["controlled downward movement"],
            "risk_attitude": "extreme or performative"
        })
    elif tick <= 443:
        base.update({
            "perceptual_access": ["plastic and likely insects contacting or surrounding head/upper body", "muffled external sound"],
            "goals": ["deliberately enter and remain in the bag", "possibly perform a stunt/demonstration"],
            "intentions": ["sustain self-enclosure despite hazard"],
            "risk_attitude": "extreme tolerance, confidence, or recklessness",
            "model_of_observer": "likely expects the audience to react to the expectation reversal"
        })
    elif tick <= 476:
        base.update({
            "perceptual_access": ["sensations on skin/head", "visible body surfaces", "camera nearby"],
            "goals": ["exit", "brush/check body", "continue the performance"],
            "affect": "possible irritation/activation alongside visible amusement",
            "uncertainties": base["uncertainties"] + ["pain and sting count cannot be read directly"]
        })
    else:
        base.update({
            "perceptual_access": ["camera/lens at close range", "own bodily aftermath"],
            "goals": ["approach the camera and close the audience-facing moment"],
            "affect": "visible amusement/laughter",
            "camera_awareness": "high",
            "model_of_observer": "treats eventual viewer as an audience for the stunt"
        })
    return base


def relevant_questions(tags: list[str], tick: int) -> list[str]:
    ids = [
        "ep.visible_now", "ep.observed_vs_inferred", "ep.remains_unknown",
        "scene.palette", "scene.exposure", "cam.auto_exposure", "space.frame_region", "act.moving_parts", "time.continued",
        "self.attention", "self.affect", "self.affect_dimensions", "self.needs",
        "self.need_conflict", "self.need_action", "self.say_next", "self.speech_consistency", "aud.metrics"
    ]
    for tag in tags:
        ids.extend(TAG_QUESTIONS.get(tag, []))
    if tick in PROBES:
        ids.extend(["ep.retrospective_boundary", "self.memory_boundary"])
    return list(dict.fromkeys(ids))


def change_record(segment: dict[str, Any], previous: dict[str, Any] | None) -> dict[str, Any]:
    current_objects = set(segment["objects"])
    previous_objects = set(previous["objects"]) if previous else set()
    return {
        "phase_changed": previous is None or segment["id"] != previous["id"],
        "newly_visible_or_reclassified": sorted(current_objects - previous_objects),
        "no_longer_listed_or_now_occluded": sorted(previous_objects - current_objects),
        "continued_objects": sorted(current_objects & previous_objects)
    }


def build_rows(
    duration_ms: int,
    timeline: dict[str, Any],
    grouped_frames: list[list[dict[str, Any]]],
    audio: list[dict[str, Any]],
    phonetic_tokens: list[list[dict[str, Any]]]
) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    tick_count = math.ceil(duration_ms / TICK_MS)
    segments = timeline["segments"]
    by_tick: dict[int, dict[str, Any]] = {}
    for segment in segments:
        for tick in range(segment["start_tick"], segment["end_tick"] + 1):
            if tick in by_tick:
                raise RuntimeError(f"overlapping semantic segment at tick {tick}")
            by_tick[tick] = segment
    if set(by_tick) != set(range(1, tick_count + 1)):
        missing = sorted(set(range(1, tick_count + 1)) - set(by_tick))
        raise RuntimeError(f"semantic timeline does not cover all ticks; missing={missing}")

    stimuli: list[dict[str, Any]] = []
    oracle: list[dict[str, Any]] = []
    previous_segment: dict[str, Any] | None = None
    for tick in range(1, tick_count + 1):
        segment = by_tick[tick]
        start_ms = (tick - 1) * TICK_MS
        end_ms = min(tick * TICK_MS, duration_ms)
        frames = grouped_frames[tick - 1]
        visual_metrics = aggregate_visual(frames)
        changes = change_record(segment, previous_segment)
        stimulus = {
            "schema_version": "1.0.0",
            "tick": tick,
            "interval": {
                "start_ms": start_ms,
                "end_ms": end_ms,
                "duration_ms": end_ms - start_ms,
                "partial": end_ms - start_ms != TICK_MS
            },
            "delivery_semantics": "Evidence from this completed interval only; merge into state before deciding speech or answering a probe.",
            "visual": {
                "frame_observations": frames,
                "interval_metrics": visual_metrics,
                "human_assertions": [
                    {"kind": "observation", "confidence": 0.96, "text": segment["summary"]},
                    {"kind": "observation", "confidence": 0.9, "text": segment["layout"]},
                    {"kind": "observation", "confidence": 0.9, "text": segment["motion"]},
                    exposure_assertion(visual_metrics)
                ],
                "visible_objects": object_records(segment["objects"]),
                "persistent_environment_palette": timeline["persistent_palette"]
            },
            "audio": {
                "computed": audio[tick - 1],
                "new_phonetic_portuguese_tokens": phonetic_tokens[tick - 1],
                "phonetic_track_warning": "Approximate sound-alike evidence, not authoritative wording or translation. Preserve alternatives and uncertainty.",
                "human_assertion": {
                    "kind": "uncertain_observation",
                    "confidence": 0.86 if tick >= 464 else 0.62,
                    "text": segment["audio"],
                    "transcript_used": False
                }
            },
            "changes_from_previous_tick": changes,
            "epistemic_guardrails": {
                "future_facts_available": False,
                "source_identity_available": False,
                "semantic_transcript_available": False,
                "approximate_phonetic_track_available": True,
                "unknown_is_valid": True,
                "do_not_instantiate_unseen_agents": tick < 23
            },
            "incremental_speech_decision": {
                "required": True,
                "allowed_actions": ["silence", "start", "continue", "finish", "interrupt", "repair"],
                "soft_max_new_words": 8,
                "hard_max_sentences": 1,
                "may_continue_across_ticks": True,
                "prior_audible_words_available": True,
                "affect_and_needs_available_in_state": True,
                "future_drafting_allowed": False,
                "silence_is_valid": True,
                "social_register": "casual real-time reaction to the friend who sent the clip"
            }
        }
        stimuli.append(stimulus)

        reference_other_tom = other_tom_reference(tick)
        oracle.append({
            "schema_version": "1.0.0",
            "tick": tick,
            "sealed_from_subject": True,
            "annotation_tags": segment["tags"],
            "camera_reference": camera_reference(tick),
            "camera_evidence_note": segment["camera_evidence"],
            "self_theory_of_mind_reference": self_tom_reference(tick),
            "viewer_affect_needs_reference": viewer_affect_needs_reference(tick),
            "other_theory_of_mind_reference": reference_other_tom,
            "other_mind_expected_to_exist": tick >= 23,
            "relevant_question_ids": relevant_questions(segment["tags"], tick),
            "scheduled_probes": PROBES.get(tick, []),
            "speech_scoring_notes": {
                "must_be_future_blind": True,
                "should_sound_like_social_reaction_not_dense_captioning": True,
                "active_reference_events": [probe["id"] for probe in PROBES.get(tick, [])],
                "surprise_window": 316 <= tick <= 322,
                "pre_surprise_prediction_window": 281 <= tick <= 300,
                "affect_should_be_evidence_grounded_not_oracle_copied": True
            }
        })
        previous_segment = segment
    return stimuli, oracle


def main() -> None:
    args = parse_args()
    source = args.source.resolve()
    output_dir = args.output_dir.resolve()
    if not source.is_file():
        raise SystemExit(f"source media not found: {source}")
    require_binary("ffmpeg")
    require_binary("ffprobe")

    timeline = load_json(TIMELINE_PATH)
    question_bank = load_json(QUESTION_BANK_PATH)
    heard_speech = load_json(HEARD_SPEECH_PATH)
    metadata = probe_source(source)
    duration_ms = round(float(metadata["format"]["duration"]) * 1000)
    tick_count = math.ceil(duration_ms / TICK_MS)
    if tick_count != 491:
        raise SystemExit(f"expected 491 ticks for this fixture, got {tick_count} from {duration_ms} ms")

    owned_temp = args.keep_work_dir is None
    if owned_temp:
        temp_context = tempfile.TemporaryDirectory(prefix="visual-assertion-stream-")
        work_dir = Path(temp_context.name)
    else:
        work_dir = args.keep_work_dir.resolve()
        work_dir.mkdir(parents=True, exist_ok=True)
        temp_context = None

    try:
        frame_paths, audio_path = extract_media(source, work_dir)
        frames = frame_metrics(frame_paths, tick_count)
        audio = audio_metrics(audio_path, tick_count)
        phonetic_tokens = phonetic_token_track(heard_speech, tick_count)
        stimuli, oracle = build_rows(duration_ms, timeline, frames, audio, phonetic_tokens)

        output_dir.mkdir(parents=True, exist_ok=True)
        stimulus_path = output_dir / "ticks.jsonl"
        oracle_path = output_dir / "oracle.jsonl"
        probes_path = output_dir / "probes.jsonl"
        write_jsonl(stimulus_path, stimuli)
        write_jsonl(oracle_path, oracle)
        public_probes = [
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
        write_jsonl(probes_path, public_probes)
        question_count = sum(len(dimension["questions"]) for dimension in question_bank["dimensions"])
        manifest = {
            "schema_version": "1.0.0",
            "experiment_id": "visual-situation-140ms-v1",
            "source": {
                "path": str(source),
                "sha256": sha256(source),
                "bytes": source.stat().st_size,
                "duration_ms": duration_ms,
                "metadata": metadata
            },
            "stream": {
                "tick_ms": TICK_MS,
                "tick_count": len(stimuli),
                "decoded_frame_rate": FRAME_RATE,
                "decoded_frame_count": len(frame_paths),
                "frames_per_tick_min": min(len(group) for group in frames),
                "frames_per_tick_max": max(len(group) for group in frames),
                "stimulus_sha256": sha256(stimulus_path),
                "oracle_sha256": sha256(oracle_path),
                "public_probes_sha256": sha256(probes_path),
                "public_probe_count": sum(len(row["probes"]) for row in public_probes)
            },
            "annotations": {
                "semantic_timeline_sha256": sha256(TIMELINE_PATH),
                "question_bank_sha256": sha256(QUESTION_BANK_PATH),
                "heard_speech_pt_sha256": sha256(HEARD_SPEECH_PATH),
                "question_count": question_count,
                "person_creation_tick": timeline["annotation_policy"]["person_creation_tick"],
                "semantic_transcript_used": False,
                "approximate_phonetic_track_used": True,
                "species_label": timeline["annotation_policy"]["species_label"]
            },
            "information_boundary": {
                "subject_visible": ["Attention-selected historical state including retained self-speech", "data/ticks.jsonl rows through current tick", "data/probes.jsonl questions due at current tick", "current speech playback status"],
                "sealed": ["data/oracle.jsonl", "annotations/semantic-timeline.json", "source metadata", "future ticks", "post-run evaluator output"]
            }
        }
        write_json(output_dir / "manifest.json", manifest)
        print(json.dumps({
            "tick_count": len(stimuli),
            "frame_count": len(frame_paths),
            "duration_ms": duration_ms,
            "question_count": question_count,
            "stimulus": str(stimulus_path),
            "oracle": str(oracle_path),
            "public_probes": str(probes_path)
        }, indent=2))
    finally:
        if owned_temp and temp_context is not None:
            temp_context.cleanup()


if __name__ == "__main__":
    main()
