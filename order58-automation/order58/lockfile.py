"""One run at a time, per demo order.

## Why this exists

Adding to a cart is not idempotent. Two instances pointed at the same demo order would each read an
empty cart, each decide to add, and produce two lines — and both would report success, because each one
was right about what it saw. The guards in `step5` check the cart *before* acting, and a lock is what
makes that check mean anything afterwards.

## How

`flock(LOCK_EX | LOCK_NB)` on a file named after the order. The same mechanism the PHP side of this
project already uses for its workers, and for the same reason: the kernel releases it when the process
dies, so a crash cannot strand a lock the way a database flag or a PID file can.

Non-blocking on purpose. A second run should be told immediately that another is working on this order,
not queue behind it and act on a cart that changed while it waited.

The file is never deleted. Removing a lock file another process holds is how two processes end up each
believing they are alone — the same note the PHP worker carries.
"""

from __future__ import annotations

import fcntl
import logging
import os
from contextlib import contextmanager
from pathlib import Path
from typing import Iterator

LOGGER = logging.getLogger("order58")


class AlreadyRunning(RuntimeError):
    """Another run holds this order's lock."""


@contextmanager
def for_order(lock_dir: Path, order_id: str) -> Iterator[None]:
    """Hold an exclusive lock on one demo order for the duration of the block."""
    lock_dir.mkdir(parents=True, exist_ok=True)
    path = lock_dir / f"order-{order_id}.lock"

    # `a+` so the file is created without being truncated — truncating would briefly empty a file
    # another process is holding.
    handle = path.open("a+")

    try:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        handle.close()
        raise AlreadyRunning(
            f"Another run already holds demo order {order_id} ({path}). "
            "Stopping rather than acting on a cart it may be changing."
        ) from None

    try:
        handle.seek(0)
        handle.truncate()
        handle.write(f"{os.getpid()}\n")
        handle.flush()

        LOGGER.info("Holding the execution lock for demo order %s.", order_id)

        yield
    finally:
        fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
        handle.close()
        LOGGER.info("Execution lock released.")
