"""The durable record of a run that writes, and the thing that refuses to start a second one.

## The problem this solves

`run --create` performs up to four server writes. The old per-order submission marker cannot protect
the first of them, because it is keyed on an order id that does not exist until after the write that
creates it. So the record has to be opened *before* the first write, keyed on something already
known: the approved source order.

## Durability, and why `"x"` and `fsync`

The file is created with mode `"x"` — exclusive creation, which fails rather than overwrites — and
both the file and its directory are `fsync`ed before the caller is allowed to proceed. A record that
is still in a kernel buffer when the machine loses power is not a record. The directory matters as
much as the file: on most filesystems a new filename is not durable until the directory entry is.

## Crash semantics: unresolved means blocked

A run that dies leaves its journal saying `in_progress`, and `in_progress` blocks the next
`--create`. That is deliberate and it is the whole point: a crashed run may have created an order,
and the machine cannot tell the difference between "died before the write" and "died after it". So it
refuses, and a human decides.

There is **no** time-to-live, no age-based expiry, no startup sweeper and no automatic release. The
only way out of a blocking state is {@see resolve}, which records who decided and on what evidence.

## What may be called `completed`

`completed` is reachable only from a positively established confirmation. It is not the default and
it is not reachable from a timeout, an exception, or a redirect to a route the guard refused. Those
are `unknown`, which blocks and is never retried. {@see RunJournal.close} enforces this rather than
trusting the caller, and the `finally` path writes `unknown` whenever a write had been attempted and
no terminal state was reached.
"""

from __future__ import annotations

import json
import logging
import os
import time
from dataclasses import dataclass, field
from pathlib import Path

LOGGER = logging.getLogger("order58")

# --- the states ---------------------------------------------------------------------------------

IN_PROGRESS = "in_progress"
PREPARED = "prepared"
COMPLETED = "completed"
VALIDATION_FAILED = "validation_failed"
UNKNOWN = "unknown"
ABORTED_NO_WRITE = "aborted_no_write"
ABORTED_AFTER_WRITE = "aborted_after_write"
RESOLVED = "resolved_by_operator"

#: States that refuse a new `--create` against the same source order.
#:
#: `prepared` blocks because an unsubmitted order is sitting there and creating another would orphan
#: it — resume it instead. `validation_failed` blocks because the application rejected a submission
#: for a reason nobody has looked at yet, and running again would repeat whatever caused it.
BLOCKING = frozenset(
    {IN_PROGRESS, PREPARED, UNKNOWN, ABORTED_AFTER_WRITE, VALIDATION_FAILED}
)

#: The only state a later run may pick up and submit.
RESUMABLE = frozenset({PREPARED})

#: Terminal states a run may close itself into.
_CLOSEABLE = frozenset(
    {PREPARED, COMPLETED, VALIDATION_FAILED, UNKNOWN, ABORTED_NO_WRITE, ABORTED_AFTER_WRITE}
)


class RunBlocked(RuntimeError):
    """An unresolved run exists for this source order. Nothing was started."""


class NoResumableRun(RuntimeError):
    """`--resume` found no prepared order, or more than one."""


def _fsync_path(path: Path) -> None:
    """Flush a file and its directory entry to disk."""
    handle = os.open(str(path), os.O_RDONLY)

    try:
        os.fsync(handle)
    finally:
        os.close(handle)


def _write_durably(path: Path, data: dict) -> None:
    """Replace the journal's contents and make the change durable before returning."""
    payload = json.dumps(data, indent=2, sort_keys=True)

    with path.open("w", encoding="utf-8") as handle:
        handle.write(payload)
        handle.flush()
        os.fsync(handle.fileno())

    path.chmod(0o600)


def attempt_marker_path(state_dir: Path, order_id: str) -> Path:
    """The per-order submission marker.

    Defined here so the one-command workflow and `phase-b` compute the SAME filename. If they
    disagreed, each could submit an order the other had already submitted.
    """
    return state_dir / f"phase-b-attempt-{order_id}.json"


def read_all(runs_dir: Path) -> list[tuple[Path, dict]]:
    """Every journal on disk, newest first. Unreadable files are reported, never skipped silently."""
    if not runs_dir.exists():
        return []

    found: list[tuple[Path, dict]] = []

    for path in sorted(runs_dir.glob("*.json"), reverse=True):
        try:
            found.append((path, json.loads(path.read_text(encoding="utf-8"))))
        except Exception as failure:  # noqa: BLE001 - a corrupt journal must still be visible
            found.append((path, {"status": "UNREADABLE", "error": str(failure)}))

    return found


def unresolved(runs_dir: Path, segment: str) -> list[tuple[Path, dict]]:
    """Journals for this source order that block a new creation.

    A journal that cannot be parsed counts as blocking. An unreadable record of a run that may have
    written to a live admin panel is not a reason to carry on.
    """
    return [
        (path, data)
        for path, data in read_all(runs_dir)
        if data.get("segment") == segment or data.get("status") == "UNREADABLE"
        if data.get("status") in BLOCKING or data.get("status") == "UNREADABLE"
    ]


def resumable(runs_dir: Path, segment: str) -> list[tuple[Path, dict]]:
    """Journals for this source order that `--resume` may pick up."""
    return [
        (path, data)
        for path, data in read_all(runs_dir)
        if data.get("segment") == segment and data.get("status") in RESUMABLE
    ]


@dataclass
class RunJournal:
    """One run's durable record. Every mutation reaches the disk before it returns."""

    path: Path
    data: dict = field(default_factory=dict)

    #: Set the moment a server write is ATTEMPTED, not when one succeeds. An attempt whose outcome
    #: is unknown is exactly the case this flag exists for.
    _wrote: bool = False

    @property
    def status(self) -> str:
        return str(self.data.get("status", ""))

    @property
    def wrote(self) -> bool:
        return self._wrote

    def mark_write_attempted(self, what: str) -> None:
        """Record that a server write is about to happen, durably, BEFORE it happens."""
        self._wrote = True
        writes = list(self.data.get("writes_attempted", []))
        writes.append({"what": what, "at": time.strftime("%Y-%m-%dT%H:%M:%S%z")})
        self.data["writes_attempted"] = writes
        _write_durably(self.path, self.data)

    def record_ids(self, order_id: str | None, customer_id: str | None) -> None:
        """Persist the identifiers the instant the application issues them.

        These are what makes a crashed or ambiguous run recoverable by hand: without them an
        operator has an order somewhere and no way to name it.
        """
        self.data["order_id"] = order_id
        self.data["customer_id"] = customer_id
        _write_durably(self.path, self.data)
        LOGGER.info("Journal: recorded order_id=%s customer_id=%s", order_id, customer_id)

    def record(self, **fields: object) -> None:
        """Persist arbitrary progress — amounts, stage results, the landing URL."""
        self.data.update(fields)
        _write_durably(self.path, self.data)

    def close(self, status: str, *, detail: str = "") -> None:
        """Write the terminal state, refusing any claim the evidence does not support."""
        if status not in _CLOSEABLE:
            raise ValueError(f"{status!r} is not a state a run may close into.")

        # The guarantee that matters: a run that touched the server can never be filed as one that
        # did not. Without this, an exception after a write could be mistaken for a clean no-op.
        if status == ABORTED_NO_WRITE and self._wrote:
            status = ABORTED_AFTER_WRITE
            detail = (
                f"{detail} (reclassified: a server write had already been attempted, so this run "
                "cannot be recorded as having written nothing)"
            ).strip()

        self.data["status"] = status
        self.data["detail"] = detail
        self.data["closed_at"] = time.strftime("%Y-%m-%dT%H:%M:%S%z")
        _write_durably(self.path, self.data)

        LOGGER.info("Journal: closed as %s (%s)", status, self.path.name)

    def describe(self) -> str:
        rows = [f"  {self.path.name}"]

        for key in (
            "status", "gate", "segment", "order_id", "customer_id", "product", "qty",
            "subtotal", "tax", "total", "landed_on", "detail", "opened_at", "closed_at",
        ):
            if self.data.get(key) not in (None, ""):
                rows.append(f"    {key:12s}: {self.data[key]}")

        if self.data.get("writes_attempted"):
            rows.append(f"    {'writes':12s}: "
                        + ", ".join(w["what"] for w in self.data["writes_attempted"]))

        if self.data.get("charges"):
            rows.append(f"    {'charges':12s}: {self.data['charges']}")

        return "\n".join(rows)


def open_run(
    runs_dir: Path,
    segment: str,
    *,
    gate: str,
    product: str,
    qty: int,
    customer_name: str = "",
) -> RunJournal:
    """Open a journal, refusing if an unresolved one exists for this source order.

    Called BEFORE a browser starts, so a blocked run costs nothing and touches nothing.
    """
    runs_dir.mkdir(parents=True, exist_ok=True)

    blocking = unresolved(runs_dir, segment)

    if blocking:
        lines = [
            f"Refusing to start: {len(blocking)} unresolved run(s) exist for source order "
            f"{segment}.",
            "",
            "A run in one of these states may have created or submitted an order that nobody has "
            "reviewed. Creating another could duplicate it.",
            "",
        ]

        for path, data in blocking:
            lines.append(
                f"  {path.name}  status={data.get('status')}  "
                f"order_id={data.get('order_id')}  detail={data.get('detail', '')!r}"
            )

        lines += [
            "",
            "Inspect with:   python create_demo_order.py journal",
            "Then, once you have verified the real state of that order:",
            "                python create_demo_order.py journal --resolve <file> "
            '--reason "..." --evidence "..."',
        ]

        raise RunBlocked("\n".join(lines))

    stamp = time.strftime("%Y%m%dT%H%M%S")
    path = runs_dir / f"run-{segment}-{stamp}.json"

    data: dict = {}
    handle = None

    # Exclusive creation, with a disambiguating suffix when a name is taken. Two runs starting in the
    # same second is a real collision — the first version of this crashed on it with FileExistsError,
    # which would have turned an ordinary coincidence into a failed run. The `"x"` mode is kept, so
    # an existing journal is never overwritten; only the filename moves.
    for attempt in range(1, 100):
        try:
            handle = path.open("x", encoding="utf-8")
            break
        except FileExistsError:
            path = runs_dir / f"run-{segment}-{stamp}-{attempt + 1}.json"
    else:
        raise RunBlocked(
            f"Could not claim a journal filename for {segment} after 99 attempts. "
            "Something is wrong with state/runs/; inspect it by hand."
        )

    data = {
        "status": IN_PROGRESS,
        "segment": segment,
        "gate": gate,
        "product": product,
        "qty": qty,
        "customer_name": customer_name,
        "opened_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
        "pid": os.getpid(),
        "writes_attempted": [],
    }

    try:
        handle.write(json.dumps(data, indent=2, sort_keys=True))
        handle.flush()
        os.fsync(handle.fileno())
    finally:
        handle.close()

    path.chmod(0o600)

    # The directory entry too, or the filename itself is not durable.
    _fsync_path(runs_dir)

    LOGGER.info("Journal: opened %s (%s)", path.name, gate)

    return RunJournal(path=path, data=data)


def reopen(path: Path) -> RunJournal:
    """Pick up a `prepared` journal for a resumed submission."""
    data = json.loads(path.read_text(encoding="utf-8"))

    if data.get("status") not in RESUMABLE:
        raise NoResumableRun(
            f"{path.name} is {data.get('status')!r}, not {sorted(RESUMABLE)}. "
            "Only a deliberately prepared order may be resumed; an uncertain one never is."
        )

    journal = RunJournal(path=path, data=data)
    # A resumed run is about to submit, so it counts as having written from the outset: the order it
    # is resuming already exists on the server.
    journal._wrote = True
    journal.data["status"] = IN_PROGRESS
    journal.data["resumed_at"] = time.strftime("%Y-%m-%dT%H:%M:%S%z")
    _write_durably(journal.path, journal.data)

    return journal


def resolve(path: Path, *, reason: str, evidence: str) -> dict:
    """Clear a blocking journal. The only route out, and it records who decided and why.

    Both a reason and the supporting verification are required and must be substantive. "Resolved"
    with no statement of what was checked is indistinguishable from someone deleting the file.
    """
    if len(reason.strip()) < 10:
        raise ValueError("A resolution needs a real reason (at least 10 characters).")

    if len(evidence.strip()) < 10:
        raise ValueError(
            "A resolution needs the supporting verification — what you actually checked, and what "
            "it showed (at least 10 characters)."
        )

    data = json.loads(path.read_text(encoding="utf-8"))

    if data.get("status") not in BLOCKING and data.get("status") != "UNREADABLE":
        raise ValueError(
            f"{path.name} is {data.get('status')!r}, which does not block anything. "
            "Nothing to resolve."
        )

    data["resolved_from"] = data.get("status")
    data["status"] = RESOLVED
    data["resolution_reason"] = reason.strip()
    data["resolution_evidence"] = evidence.strip()
    data["resolved_at"] = time.strftime("%Y-%m-%dT%H:%M:%S%z")

    _write_durably(path, data)
    LOGGER.warning(
        "Journal: %s resolved by operator (was %s).", path.name, data["resolved_from"]
    )

    return data
