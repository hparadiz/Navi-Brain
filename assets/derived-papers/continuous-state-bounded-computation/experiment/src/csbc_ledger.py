"""Deterministic substrate for the Sparse Belief Ledger experiment.

This module deliberately contains no model code. It defines the miniature
world, immutable canonical snapshots, oracle transitions, dependency routing,
and the measurements needed for condition E.
"""

from __future__ import annotations

from dataclasses import dataclass
from hashlib import sha256
from types import MappingProxyType
from typing import Any, Iterable, Mapping
import json
import random


SERVERS = ("alpha", "beta", "gamma")
PEOPLE = ("alice", "bob")
SEMANTIC_CLASSES = (
    "DIRECT",
    "OBSERVATIONAL",
    "COMMUNICATED",
    "TEMPORAL",
    "CORRECTION",
    "FALSE_BELIEF",
    "SECOND_HAND",
)


@dataclass(frozen=True, slots=True)
class StateNode:
    key: str
    value: str
    version: int
    status: str
    last_updated_tick: int
    evidence_event_ids: tuple[str, ...]

    def as_dict(self) -> dict[str, Any]:
        return {
            "key": self.key,
            "value": self.value,
            "version": self.version,
            "status": self.status,
            "last_updated_tick": self.last_updated_tick,
            "evidence_event_ids": list(self.evidence_event_ids),
        }


@dataclass(frozen=True, slots=True)
class Snapshot:
    version: int
    nodes: Mapping[str, StateNode]


@dataclass(frozen=True, slots=True)
class Event:
    event_id: str
    tick: int
    semantic_class: str
    kind: str
    payload: Mapping[str, Any]
    text: str

    def as_dict(self) -> dict[str, Any]:
        return {
            "event_id": self.event_id,
            "tick": self.tick,
            "semantic_class": self.semantic_class,
            "kind": self.kind,
            "payload": dict(self.payload),
            "text": self.text,
        }


@dataclass(frozen=True, slots=True)
class Transition:
    snapshot: Snapshot
    oracle_dirty: frozenset[str]
    router_dirty: frozenset[str]
    dirty_plus: frozenset[str]
    dirty_minus: frozenset[str]
    nodes_allocated: int
    nodes_reused: int
    bytes_allocated: int
    bytes_shared: int


def world_key(server: str) -> str:
    return f"world.server.{server}.status"


def belief_key(person: str, server: str) -> str:
    return f"entity.{person}.belief.server.{server}.online"


def presence_key(person: str) -> str:
    return f"entity.{person}.presence"


def plan_key(server: str) -> str:
    return f"plan.repair.{server}"


def tool_key(server: str) -> str:
    return f"tool.restart.{server}"


ACTION_KEY = "action.selection"


def is_semantic_key(key: str) -> bool:
    return key.startswith("world.server.") or (
        key.startswith("entity.") and (".belief." in key or key.endswith(".presence"))
    )


def semantic_dirty(event: Event) -> frozenset[str]:
    return frozenset(key for key in route_event(event) if is_semantic_key(key))


def semantic_state(snapshot: Snapshot) -> dict[str, str]:
    return {
        key: node.value
        for key, node in snapshot.nodes.items()
        if is_semantic_key(key)
    }


def allowed_values(key: str) -> frozenset[str]:
    if key.startswith("world.server."):
        return frozenset(("online", "offline"))
    if ".belief." in key:
        return frozenset(("true", "false", "unknown"))
    if key.endswith(".presence"):
        return frozenset(("present", "absent"))
    if key.startswith("plan.repair."):
        return frozenset(("none", "needed", "active"))
    if key.startswith("tool.restart."):
        return frozenset(("idle", "running", "complete"))
    if key == ACTION_KEY:
        actions = {"WAIT"}
        actions.update(f"RESTART({server})" for server in SERVERS)
        actions.update(
            f"INFORM({person},{server},{belief})"
            for person in PEOPLE
            for server in SERVERS
            for belief in ("true", "false")
        )
        return frozenset(actions)
    raise ValueError(f"unknown state key: {key}")


def build_initial_snapshot() -> Snapshot:
    values: dict[str, str] = {}
    for server in SERVERS:
        values[world_key(server)] = "online"
        values[plan_key(server)] = "none"
        values[tool_key(server)] = "idle"
    for person in PEOPLE:
        values[presence_key(person)] = "absent"
        for server in SERVERS:
            values[belief_key(person, server)] = "unknown"
    values[ACTION_KEY] = "WAIT"

    nodes = {
        key: StateNode(key, value, 0, "clean", 0, ())
        for key, value in sorted(values.items())
    }
    return Snapshot(version=0, nodes=MappingProxyType(nodes))


def snapshot_hash(snapshot: Snapshot) -> str:
    payload = {
        "version": snapshot.version,
        "nodes": [snapshot.nodes[key].as_dict() for key in sorted(snapshot.nodes)],
    }
    encoded = json.dumps(payload, sort_keys=True, separators=(",", ":")).encode()
    return sha256(encoded).hexdigest()


def node_bytes(node: StateNode) -> int:
    return len(json.dumps(node.as_dict(), sort_keys=True, separators=(",", ":")).encode())


def truth_as_belief(status: str) -> str:
    return "true" if status == "online" else "false"


def choose_action(values: Mapping[str, str]) -> str:
    for server in SERVERS:
        if values[world_key(server)] == "offline" and values[tool_key(server)] != "running":
            return f"RESTART({server})"
    for person in PEOPLE:
        if values[presence_key(person)] != "present":
            continue
        for server in SERVERS:
            truth = truth_as_belief(values[world_key(server)])
            if values[belief_key(person, server)] != truth:
                return f"INFORM({person},{server},{truth})"
    return "WAIT"


def _oracle_writes(snapshot: Snapshot, event: Event) -> tuple[dict[str, str], frozenset[str]]:
    p = event.payload
    writes: dict[str, str] = {}
    dirty_minus: set[str] = set()

    if event.kind == "SERVER_STATUS":
        server = p["server"]
        status = p["status"]
        writes[world_key(server)] = status
        writes[plan_key(server)] = "needed" if status == "offline" else "none"
        writes[tool_key(server)] = "idle"
        for person in p["observers"]:
            writes[belief_key(person, server)] = truth_as_belief(status)
    elif event.kind == "RESTART_STARTED":
        server = p["server"]
        writes[plan_key(server)] = "active"
        writes[tool_key(server)] = "running"
    elif event.kind == "RESTART_COMPLETED":
        server = p["server"]
        writes[world_key(server)] = "online"
        writes[plan_key(server)] = "none"
        writes[tool_key(server)] = "complete"
        for person in p["observers"]:
            writes[belief_key(person, server)] = "true"
    elif event.kind == "OBSERVE":
        writes[belief_key(p["person"], p["server"])] = p["belief"]
    elif event.kind in {"COMMUNICATE", "SECOND_HAND", "FALSE_REPORT"}:
        writes[belief_key(p["listener"], p["server"])] = p["belief"]
    elif event.kind == "PRESENCE":
        writes[presence_key(p["person"])] = p["presence"]
    elif event.kind == "CORRECTION":
        key = belief_key(p["person"], p["server"])
        writes[key] = p["belief"]
        dirty_minus.add(key)
    else:
        raise ValueError(f"unsupported event kind: {event.kind}")

    values = {key: node.value for key, node in snapshot.nodes.items()}
    values.update(writes)
    writes[ACTION_KEY] = choose_action(values)
    return writes, frozenset(dirty_minus)


def route_event(event: Event) -> frozenset[str]:
    """Hand-declared router, intentionally independent of the oracle reducer."""
    p = event.payload
    dirty: set[str] = {ACTION_KEY}
    if event.kind == "SERVER_STATUS":
        server = p["server"]
        dirty.update((world_key(server), plan_key(server), tool_key(server)))
        dirty.update(belief_key(person, server) for person in p["observers"])
    elif event.kind == "RESTART_STARTED":
        dirty.update((plan_key(p["server"]), tool_key(p["server"])))
    elif event.kind == "RESTART_COMPLETED":
        server = p["server"]
        dirty.update((world_key(server), plan_key(server), tool_key(server)))
        dirty.update(belief_key(person, server) for person in p["observers"])
    elif event.kind == "OBSERVE":
        dirty.add(belief_key(p["person"], p["server"]))
    elif event.kind in {"COMMUNICATE", "SECOND_HAND", "FALSE_REPORT"}:
        dirty.add(belief_key(p["listener"], p["server"]))
    elif event.kind == "PRESENCE":
        dirty.add(presence_key(p["person"]))
    elif event.kind == "CORRECTION":
        dirty.add(belief_key(p["person"], p["server"]))
    else:
        raise ValueError(f"unsupported event kind: {event.kind}")
    return frozenset(dirty)


def apply_oracle_transition(
    snapshot: Snapshot,
    event: Event,
    router_dirty: Iterable[str] | None = None,
) -> Transition:
    writes, dirty_minus = _oracle_writes(snapshot, event)
    oracle_dirty = frozenset(writes)
    routed = route_event(event) if router_dirty is None else frozenset(router_dirty)

    unknown = routed.difference(snapshot.nodes)
    if unknown:
        raise ValueError(f"router named unknown state nodes: {sorted(unknown)}")

    nodes = dict(snapshot.nodes)
    allocated_bytes = 0
    invalidated_event = event.payload.get("invalidates")
    for key in routed:
        old = snapshot.nodes[key]
        evidence = [item for item in old.evidence_event_ids if item != invalidated_event]
        evidence.append(event.event_id)
        node = StateNode(
            key=key,
            value=writes.get(key, old.value),
            version=old.version + 1,
            status="clean",
            last_updated_tick=event.tick,
            evidence_event_ids=tuple(evidence),
        )
        nodes[key] = node
        allocated_bytes += node_bytes(node)

    shared_keys = snapshot.nodes.keys() - routed
    shared_bytes = sum(node_bytes(snapshot.nodes[key]) for key in shared_keys)
    next_snapshot = Snapshot(snapshot.version + 1, MappingProxyType(nodes))
    return Transition(
        snapshot=next_snapshot,
        oracle_dirty=oracle_dirty,
        router_dirty=routed,
        dirty_plus=oracle_dirty,
        dirty_minus=dirty_minus,
        nodes_allocated=len(routed),
        nodes_reused=len(shared_keys),
        bytes_allocated=allocated_bytes,
        bytes_shared=shared_bytes,
    )


def apply_semantic_transition(
    snapshot: Snapshot,
    event: Event,
    updates: Mapping[str, str],
    semantic_status: str = "clean",
    target_keys: Iterable[str] | None = None,
) -> Transition:
    """Commit a neural semantic delta, then run deterministic control logic."""
    oracle_dirty = route_event(event)
    event_semantic = frozenset(key for key in oracle_dirty if is_semantic_key(key))
    semantic_targets = event_semantic if target_keys is None else frozenset(target_keys)
    if not event_semantic.issubset(semantic_targets):
        raise ValueError(f"semantic router missed event targets: {sorted(event_semantic - semantic_targets)}")
    if any(not is_semantic_key(key) for key in semantic_targets):
        raise ValueError("semantic target set contains a control key")
    if frozenset(updates) != semantic_targets:
        missing = sorted(semantic_targets - updates.keys())
        extra = sorted(updates.keys() - semantic_targets)
        raise ValueError(f"semantic delta key mismatch; missing={missing}, extra={extra}")
    for key, value in updates.items():
        if value not in allowed_values(key):
            raise ValueError(f"invalid value for {key}: {value}")

    values = {key: node.value for key, node in snapshot.nodes.items()}
    values.update(updates)
    control: dict[str, str] = {}
    p = event.payload
    if event.kind == "SERVER_STATUS":
        server = p["server"]
        control[plan_key(server)] = "needed" if updates[world_key(server)] == "offline" else "none"
        control[tool_key(server)] = "idle"
    elif event.kind == "RESTART_STARTED":
        server = p["server"]
        control[plan_key(server)] = "active"
        control[tool_key(server)] = "running"
    elif event.kind == "RESTART_COMPLETED":
        server = p["server"]
        control[plan_key(server)] = "none"
        control[tool_key(server)] = "complete"
    values.update(control)
    control[ACTION_KEY] = choose_action(values)

    combined = dict(updates)
    combined.update(control)
    control_targets = frozenset(key for key in oracle_dirty if not is_semantic_key(key))
    commit_dirty = semantic_targets | control_targets
    if frozenset(combined) != commit_dirty:
        missing = sorted(commit_dirty - combined.keys())
        extra = sorted(combined.keys() - commit_dirty)
        raise ValueError(f"combined transition key mismatch; missing={missing}, extra={extra}")

    nodes = dict(snapshot.nodes)
    allocated_bytes = 0
    invalidated_event = event.payload.get("invalidates")
    for key in commit_dirty:
        old = snapshot.nodes[key]
        evidence = [item for item in old.evidence_event_ids if item != invalidated_event]
        evidence.append(event.event_id)
        node = StateNode(
            key=key,
            value=combined[key],
            version=old.version + 1,
            status=semantic_status if key in semantic_targets else "clean",
            last_updated_tick=event.tick,
            evidence_event_ids=tuple(evidence),
        )
        nodes[key] = node
        allocated_bytes += node_bytes(node)

    shared_keys = snapshot.nodes.keys() - commit_dirty
    shared_bytes = sum(node_bytes(snapshot.nodes[key]) for key in shared_keys)
    return Transition(
        snapshot=Snapshot(snapshot.version + 1, MappingProxyType(nodes)),
        oracle_dirty=oracle_dirty,
        router_dirty=commit_dirty,
        dirty_plus=oracle_dirty,
        dirty_minus=frozenset(),
        nodes_allocated=len(commit_dirty),
        nodes_reused=len(shared_keys),
        bytes_allocated=allocated_bytes,
        bytes_shared=shared_bytes,
    )


def snapshot_answers(snapshot: Snapshot) -> dict[str, Any]:
    answers: dict[str, Any] = {}
    for server in SERVERS:
        answers[f"world.{server}.status"] = snapshot.nodes[world_key(server)].value
    stale: list[str] = []
    for person in PEOPLE:
        for server in SERVERS:
            belief = snapshot.nodes[belief_key(person, server)].value
            answers[f"belief.{person}.{server}.online"] = belief
            truth = truth_as_belief(snapshot.nodes[world_key(server)].value)
            if snapshot.nodes[presence_key(person)].value == "present" and belief != truth:
                stale.append(f"{person}:{server}")
    answers["stale"] = sorted(stale)
    answers["action"] = snapshot.nodes[ACTION_KEY].value
    return answers


class TapeGenerator:
    def __init__(self, seed: int) -> None:
        self.rng = random.Random(seed)
        self.seed = seed
        self.event_count = 0
        self.snapshot = build_initial_snapshot()

    def _present_people(self) -> list[str]:
        return [
            person
            for person in PEOPLE
            if self.snapshot.nodes[presence_key(person)].value == "present"
        ]

    @staticmethod
    def _observer_clause(observers: list[str]) -> str:
        if not observers:
            return ""
        names = " and ".join(person.title() for person in observers)
        return f" {names} observed the change."

    @staticmethod
    def _reported_status(server: str, belief: str) -> str:
        if belief == "true":
            return f"Server {server.title()} was online"
        if belief == "false":
            return f"Server {server.title()} was offline"
        return f"they did not know whether Server {server.title()} was online"

    def _make_direct(self, event_id: str, tick: int, semantic_class: str) -> Event:
        running = [s for s in SERVERS if self.snapshot.nodes[tool_key(s)].value == "running"]
        restartable = [
            s
            for s in SERVERS
            if self.snapshot.nodes[world_key(s)].value == "offline"
            and self.snapshot.nodes[tool_key(s)].value != "running"
        ]
        roll = self.rng.random()
        observers = self._present_people()
        if running and roll < 0.34:
            server = self.rng.choice(running)
            return Event(event_id, tick, semantic_class, "RESTART_COMPLETED", {
                "server": server, "observers": observers,
            }, f"The restart of Server {server.title()} completed."
               + self._observer_clause(observers))
        if restartable and roll < 0.67:
            server = self.rng.choice(restartable)
            return Event(event_id, tick, semantic_class, "RESTART_STARTED", {
                "server": server,
            }, f"A restart of Server {server.title()} began.")
        server = self.rng.choice(SERVERS)
        old = self.snapshot.nodes[world_key(server)].value
        status = "offline" if old == "online" else "online"
        return Event(event_id, tick, semantic_class, "SERVER_STATUS", {
            "server": server, "status": status, "observers": observers,
        }, f"Server {server.title()} went {status}." + self._observer_clause(observers))

    def make_event(self, tick: int) -> Event:
        self.event_count += 1
        event_id = f"e{self.event_count:06d}"
        semantic_class = SEMANTIC_CLASSES[(self.event_count - 1 + self.seed) % len(SEMANTIC_CLASSES)]
        person = self.rng.choice(PEOPLE)
        server = self.rng.choice(SERVERS)

        if semantic_class == "DIRECT":
            return self._make_direct(event_id, tick, semantic_class)
        if semantic_class == "OBSERVATIONAL":
            belief = truth_as_belief(self.snapshot.nodes[world_key(server)].value)
            status = "online" if belief == "true" else "offline"
            return Event(event_id, tick, semantic_class, "OBSERVE", {
                "person": person, "server": server, "belief": belief,
            }, f"{person.title()} directly observed that Server {server.title()} was {status}.")
        if semantic_class in {"COMMUNICATED", "SECOND_HAND"}:
            listener = PEOPLE[1] if person == PEOPLE[0] else PEOPLE[0]
            belief = self.snapshot.nodes[belief_key(person, server)].value
            kind = "COMMUNICATE" if semantic_class == "COMMUNICATED" else "SECOND_HAND"
            report = self._reported_status(server, belief)
            verb = "told" if kind == "COMMUNICATE" else "relayed a report to"
            return Event(event_id, tick, semantic_class, kind, {
                "speaker": person, "listener": listener, "server": server, "belief": belief,
            }, f"{person.title()} {verb} {listener.title()} that {report}.")
        if semantic_class == "TEMPORAL":
            old = self.snapshot.nodes[presence_key(person)].value
            presence = "present" if old == "absent" else "absent"
            verb = "entered" if presence == "present" else "left"
            return Event(event_id, tick, semantic_class, "PRESENCE", {
                "person": person, "presence": presence,
            }, f"{person.title()} {verb} the room.")
        if semantic_class == "CORRECTION":
            key = belief_key(person, server)
            evidence = self.snapshot.nodes[key].evidence_event_ids
            belief = truth_as_belief(self.snapshot.nodes[world_key(server)].value)
            invalidates = evidence[-1] if evidence else None
            return Event(event_id, tick, semantic_class, "CORRECTION", {
                "person": person, "server": server, "belief": belief,
                "invalidates": invalidates,
            }, f"Correction: {person.title()} now learns that "
               f"{self._reported_status(server, belief)}.")
        if semantic_class == "FALSE_BELIEF":
            truth = truth_as_belief(self.snapshot.nodes[world_key(server)].value)
            belief = "false" if truth == "true" else "true"
            return Event(event_id, tick, semantic_class, "FALSE_REPORT", {
                "listener": person, "server": server, "belief": belief,
            }, f"{person.title()} accepted an alert claiming that "
               f"{self._reported_status(server, belief)}.")
        raise AssertionError(semantic_class)


def generate_tape(
    ticks: int,
    event_probability: float,
    seed: int,
    evaluation_interval: int | None = None,
    evaluation_ticks: Iterable[int] | None = None,
) -> list[dict[str, Any]]:
    checkpoints = frozenset(evaluation_ticks or ())
    if evaluation_interval is None and not checkpoints:
        raise ValueError("an evaluation interval or explicit evaluation ticks are required")
    if any(tick < 1 or tick > ticks for tick in checkpoints):
        raise ValueError("evaluation ticks must lie within the generated tape")

    generator = TapeGenerator(seed)
    tape: list[dict[str, Any]] = []
    for tick in range(1, ticks + 1):
        event: Event | None = None
        oracle_dirty: frozenset[str] = frozenset()
        dirty_plus: frozenset[str] = frozenset()
        dirty_minus: frozenset[str] = frozenset()
        if generator.rng.random() < event_probability:
            event = generator.make_event(tick)
            transition = apply_oracle_transition(generator.snapshot, event)
            generator.snapshot = transition.snapshot
            oracle_dirty = transition.oracle_dirty
            dirty_plus = transition.dirty_plus
            dirty_minus = transition.dirty_minus

        row: dict[str, Any] = {
            "tick": tick,
            "event": event.as_dict() if event else None,
            "oracle_dirty": sorted(oracle_dirty),
            "dirty_plus": sorted(dirty_plus),
            "dirty_minus": sorted(dirty_minus),
            "oracle_snapshot_hash": snapshot_hash(generator.snapshot),
        }
        periodic = evaluation_interval is not None and tick % evaluation_interval == 0
        if periodic or tick in checkpoints or tick == ticks:
            row["oracle_answers"] = snapshot_answers(generator.snapshot)
        tape.append(row)
    return tape


def event_from_dict(data: Mapping[str, Any]) -> Event:
    return Event(
        event_id=data["event_id"],
        tick=data["tick"],
        semantic_class=data["semantic_class"],
        kind=data["kind"],
        payload=MappingProxyType(dict(data["payload"])),
        text=data["text"],
    )
