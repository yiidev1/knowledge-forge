"""Logging for the automation: readable on a terminal, and safe to paste into a ticket.

The second half is the point. This drives a real admin panel containing real customers, and the browser
session it holds is a live credential. A log that quietly carried a cookie, a token or a phone number
would be a log nobody could share — and the first time anybody noticed would be after it had been
pasted somewhere.

So redaction happens on the way out, in the formatter, rather than at each call site. A call site that
has to remember is a call site that will forget.
"""

from __future__ import annotations

import logging
import re
import sys
from pathlib import Path

# Patterns that must never reach a log line or a terminal.
#
# Deliberately broad. A false positive costs a few hidden characters in a message; a false negative
# costs a credential in a file somebody later attaches to an email.
_REDACTIONS: list[tuple[re.Pattern[str], str]] = [
    # Cookie and session material, however it is spelled. Applied everywhere, URLs included — a token
    # in a query string is exactly as sensitive as one in a header.
    (re.compile(r"(?i)\b(cookie|set-cookie|session|sessid|csrf|xsrf|token|bearer|authorization)\b\s*[:=]\s*\S+"),
     r"\1=<redacted>"),
]

# These two are too eager to run over a URL, and are applied only outside one. See `_redact`.
_OUTSIDE_URLS: list[tuple[re.Pattern[str], str]] = [
    # Anything that looks like a long opaque credential standing on its own.
    (re.compile(r"\b[A-Za-z0-9_\-]{32,}\b"), "<redacted-token>"),
    # Phone numbers: the demo form carries a real customer's. The last two digits survive so a person
    # can still tell two orders apart without the number being readable.
    (re.compile(r"\b(\+?\d[\d\s\-().]{7,}\d)(\d{2})\b"), r"<redacted-phone>\2"),
]

# Full URLs, and bare paths too. The demo order id `16655531-15163932150` is digits-dash-digits —
# indistinguishable from a phone number by shape — and it appears both as part of a URL and on its
# own in a path like `/admin/demo/order/checkout-info/16655531-15163932150`. Protecting only full
# URLs redacted the second form, which is precisely the detail a reader needs when a navigation is
# refused.
_URL = re.compile(r"https?://\S+|/[A-Za-z0-9_\-./]+")


def _redact(text: str) -> str:
    """Hide credentials without destroying the one thing a log is read for.

    The phone and long-token rules are held back from URLs on purpose. The demo order URL ends in
    `16655531-15163932150`, which is digits-dash-digits and looks exactly like a phone number — so the
    first version of this redacted the single most useful line in the log down to
    `.../make/<redacted-phone>50`. A log that hides the address being worked on is a log nobody can
    debug from, and the path segment it was hiding is an order id, not a customer's number.

    URLs are not exempt from everything: the `token=`/`session=` rule above still runs over them, so a
    credential carried in a query string is still removed.
    """
    for pattern, replacement in _REDACTIONS:
        text = pattern.sub(replacement, text)

    # Lift the URLs out, redact what is left, then put them back untouched.
    urls: list[str] = []

    def park(match: re.Match[str]) -> str:
        urls.append(match.group(0))
        return f"\x00URL{len(urls) - 1}\x00"

    text = _URL.sub(park, text)

    for pattern, replacement in _OUTSIDE_URLS:
        text = pattern.sub(replacement, text)

    for index, url in enumerate(urls):
        text = text.replace(f"\x00URL{index}\x00", url)

    return text


class _RedactingFormatter(logging.Formatter):
    """A formatter that cannot be bypassed by forgetting to redact at the call site."""

    def format(self, record: logging.LogRecord) -> str:
        return _redact(super().format(record))


def configure(log_dir: Path | None = None, verbose: bool = False) -> logging.Logger:
    """The one logger this tool uses.

    Writes to the terminal always, and to a dated file when a directory is given — that directory is
    git-ignored, because these lines describe a real admin panel.
    """
    logger = logging.getLogger("order58")
    logger.setLevel(logging.DEBUG if verbose else logging.INFO)

    # Called more than once in a long-running session otherwise, which would duplicate every line.
    if logger.handlers:
        return logger

    fmt = _RedactingFormatter("%(asctime)s  %(levelname)-7s %(message)s", datefmt="%H:%M:%S")

    console = logging.StreamHandler(sys.stdout)
    console.setFormatter(fmt)
    logger.addHandler(console)

    if log_dir is not None:
        log_dir.mkdir(parents=True, exist_ok=True)
        file_handler = logging.FileHandler(log_dir / "automation.log", encoding="utf-8")
        file_handler.setFormatter(fmt)
        logger.addHandler(file_handler)

    return logger
