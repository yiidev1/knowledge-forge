"""What this tool is allowed to open, enforced rather than intended.

## Why an allowlist and not a substring check

`"order58.com" in url` would happily accept `https://order58.com.evil.example/`, and
`"/admin/demo/" in path` would accept `/admin/live/order/make?x=/admin/demo/`. Both are the kind of
check that looks careful and is not. So this parses the URL and compares the **hostname exactly** and
the **path exactly**, against a short list written out in full.

## Two different questions, deliberately answered differently

**Top-level navigation** — the page the browser is actually on — is held to the exact list. This is the
one that matters: it is how an automation ends up driving a live order route instead of a demo one, and
it is checked on every navigation, not just the first.

**Subresources** — the stylesheets, scripts, fonts and XHR a page needs to work — are not blocked. A
login form stripped of its JavaScript may not submit at all, and an automation that breaks the page it
is trying to read is not safer, just broken in a way that invites workarounds. They are *recorded*
instead, so every host contacted can be reported and reviewed.

## Login is driven by a person, so it is scoped differently

Signing in means following redirects through pages nobody can predict — a dashboard, an interstitial, a
password-change prompt. Holding that to a two-path list would make signing in impossible. During
{@see Mode.LOGIN} navigation is therefore restricted to the **same host** and every hop is logged;
during ordinary use the exact path list applies. A person is at the keyboard for the first and not for
the second, which is the distinction that matters.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field
from enum import Enum
from urllib.parse import urlparse

from playwright.sync_api import Page

LOGGER = logging.getLogger("order58")

# The one host this tool will ever drive.
ALLOWED_HOST = "joymeal.order58.com"

LOGIN_PATH = "/admin/site/login"


@dataclass(frozen=True)
class ApprovedDemoSource:
    """One approved demo order, validated as a whole tuple rather than as a path fragment.

    A demo URL ends in `<sourceOrderId>-<customerPhone>`, and both halves matter. Checking only the
    combined segment, or only the source order id, would let an approved source order be driven
    against a different host, or paired with a phone number nobody approved. So all three parts are
    compared, and the host is part of the record rather than a global assumption.
    """

    host: str
    source_order_id: str
    customer_phone: str

    @property
    def segment(self) -> str:
        """The `<order>-<phone>` tail of a demo URL."""
        return f"{self.source_order_id}-{self.customer_phone}"


#: Every demo order this tool may drive. Adding one is a deliberate, reviewed act — which is the point.
APPROVED_DEMO_SOURCES: frozenset[ApprovedDemoSource] = frozenset(
    {
        ApprovedDemoSource(
            host="joymeal.order58.com",
            source_order_id="16655531",
            customer_phone="15163932150",
        ),
    }
)

# The six demo pages, as literal prefixes with one substitution. Nothing outside this module supplies
# a prefix, so no caller — however it is parameterised — can produce a non-demo route.
#
# Still one exact path per stage, NOT a `/admin/demo/order/checkout-*` pattern: a prefix would
# silently admit every future checkout stage, including whichever one submits.
_DEMO_PATH_TEMPLATES: tuple[str, ...] = (
    "/admin/demo/order/make/{segment}",
    # The `?order_id=…&customer_id=…` these pages carry are query parameters, which the path
    # comparison ignores and which are always read from the application's own response.
    "/admin/demo/product/menu/{segment}",
    # `checkout-customer` turned out to be a dispatcher that redirects to `checkout-info`.
    "/admin/demo/order/checkout-customer/{segment}",
    "/admin/demo/order/checkout-info/{segment}",
    "/admin/demo/order/checkout-shipping/{segment}",
    "/admin/demo/order/checkout-payment/{segment}",
)


class UnknownSourceOrder(RuntimeError):
    """A demo URL named a host/source-order/phone tuple that is not approved."""


def approved_source(host: str, source_order_id: str, customer_phone: str) -> ApprovedDemoSource:
    """The approved record for this tuple, or refuse. All three parts must match one entry."""
    for source in APPROVED_DEMO_SOURCES:
        if (
            source.host == host
            and source.source_order_id == source_order_id
            and source.customer_phone == customer_phone
        ):
            return source

    raise UnknownSourceOrder(
        f"host={host!r} source_order={source_order_id!r} phone=<not shown> is not an approved "
        f"demo source. {len(APPROVED_DEMO_SOURCES)} source(s) are approved; add one deliberately "
        "in order58/guard.py if that is intended."
    )


def paths_for(source: ApprovedDemoSource) -> frozenset[str]:
    """The exact demo pages for one approved source, plus the login page."""
    if source not in APPROVED_DEMO_SOURCES:
        raise UnknownSourceOrder(f"{source.host}/{source.source_order_id} is not approved.")

    return frozenset(
        [template.format(segment=source.segment) for template in _DEMO_PATH_TEMPLATES]
        + [LOGIN_PATH]
    )


# Exactly the pages the automation may navigate to. Derived rather than written out, but the result is
# identical to the seven paths this set has always held — there is a test asserting precisely that.
ALLOWED_PATHS: frozenset[str] = frozenset().union(
    *(paths_for(source) for source in APPROVED_DEMO_SOURCES)
)


class Mode(Enum):
    """Which rule applies."""

    #: A person is signing in. Same host only, every hop logged.
    LOGIN = "login"

    #: The automation is driving. The exact path list applies.
    AUTOMATED = "automated"


class NavigationRefused(RuntimeError):
    """A top-level navigation went somewhere it was not allowed to go."""


@dataclass
class Guard:
    """Watches where the browser goes, and what it talks to."""

    mode: Mode

    #: The pages this guard will admit. Defaults to every approved demo source's pages, which is what
    #: every command has always used. A caller may narrow it to ONE source order's paths — narrowing
    #: is always safe; there is no way to widen it, because `paths_for` only ever returns derived
    #: demo paths for an approved source.
    allowed_paths: frozenset[str] = ALLOWED_PATHS

    #: Every top-level page the browser landed on, in order.
    visited: list[str] = field(default_factory=list)

    #: Hosts contacted for subresources. Recorded, never blocked — see the module docstring.
    subresource_hosts: set[str] = field(default_factory=set)

    #: Every request that was not a GET, as (method, path).
    #:
    #: This is how "did merely opening that page change anything on the server?" gets an evidence-based
    #: answer instead of an assumption. A page that only reads makes only GETs; a POST or a PUT during
    #: a navigation nobody asked to be a write is exactly what needs reporting.
    mutating_requests: list[tuple[str, str]] = field(default_factory=list)

    #: Set when a navigation was refused, so the caller can stop cleanly.
    refusal: str | None = None

    def allows_navigation(self, url: str) -> tuple[bool, str]:
        """Whether the browser may be on this page. Returns (allowed, why not)."""
        parsed = urlparse(url)

        # about:blank is the page every context starts on.
        if parsed.scheme in ("about", "chrome", "chrome-error") or url in ("", "about:blank"):
            return True, ""

        if parsed.scheme != "https":
            return False, f"not HTTPS: {url}"

        if parsed.hostname != ALLOWED_HOST:
            return False, f"host {parsed.hostname!r} is not {ALLOWED_HOST!r}"

        if self.mode is Mode.LOGIN:
            # A person is driving; the host is the boundary.
            return True, ""

        # Exact path match. Query and fragment are ignored — the demo pages carry `?order_id=…` — but
        # the path itself must be one of the listed pages, not merely contain one.
        if parsed.path.rstrip("/") not in {p.rstrip("/") for p in self.allowed_paths}:
            return False, f"path {parsed.path!r} is not an approved page"

        return True, ""

    def attach(self, page: Page) -> None:
        """Start watching. Records subresource hosts and refuses stray navigations."""

        def on_navigated(frame) -> None:  # noqa: ANN001 - Playwright's Frame
            # Only the main frame: an embedded iframe is not "where the browser is".
            if frame.parent_frame is not None:
                return

            url = frame.url
            allowed, why = self.allows_navigation(url)

            if not allowed:
                self.refusal = why
                LOGGER.error("REFUSED navigation — %s", why)
                return

            if url and url != "about:blank" and (not self.visited or self.visited[-1] != url):
                self.visited.append(url)
                LOGGER.info("Navigated to %s", url)

        def on_request(request) -> None:  # noqa: ANN001 - Playwright's Request
            parsed = urlparse(request.url)

            # Recorded for every request, navigation included: a navigation that is a POST is the most
            # interesting case of all.
            if request.method.upper() != "GET":
                self.mutating_requests.append((request.method.upper(), parsed.path))

            if request.is_navigation_request():
                return

            if parsed.hostname and parsed.hostname != ALLOWED_HOST:
                self.subresource_hosts.add(parsed.hostname)

        page.on("framenavigated", on_navigated)
        page.on("request", on_request)

    def raise_if_refused(self) -> None:
        """Stop the run if anything went somewhere it should not have."""
        if self.refusal is not None:
            raise NavigationRefused(
                f"Stopped: {self.refusal}. Nothing further was attempted."
            )

    def describe(self) -> str:
        """What happened, for the report."""
        lines = ["", f"  Pages visited ({len(self.visited)}):"]
        lines += [f"    {index + 1}. {url}" for index, url in enumerate(self.visited)] or ["    <none>"]

        if self.subresource_hosts:
            lines.append("")
            lines.append("  Other hosts contacted for page resources (allowed, not blocked):")
            lines += [f"    - {host}" for host in sorted(self.subresource_hosts)]
        else:
            lines.append("")
            lines.append("  No third-party hosts were contacted.")

        lines.append("")

        if self.mutating_requests:
            lines.append(f"  Non-GET requests observed ({len(self.mutating_requests)}):")
            lines += [f"    - {method} {path}" for method, path in self.mutating_requests]
        else:
            lines.append("  Non-GET requests observed: NONE — every request was a read.")

        return "\n".join(lines)
