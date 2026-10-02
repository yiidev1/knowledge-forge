"""Reading the cart, and refusing to guess.

## Fail closed

Every question this module answers has three possible answers, not two: yes, no, and **I could not
tell**. The third is the important one. A parser that returns "empty" when it simply failed to find the
panel would let the caller add a product to a cart that already had one — so an unreadable cart is
{@see CartStatus.UNKNOWN}, and the caller stops.

That is why `is_definitely_empty()` exists rather than `is_empty()`. The name is the contract.

## What is parsed, and what is only reported

Line items and money are read where the page states them, and the **raw panel text is always carried**
so a human can check the parse against what was really on screen. Nothing here infers a subtotal from a
price and a quantity: if the page did not say it, it is `None`, not arithmetic.
"""

from __future__ import annotations

import logging
import re
from dataclasses import dataclass, field
from enum import Enum

from playwright.sync_api import Page

LOGGER = logging.getLogger("order58")

# Read from the live DOM while the cart was empty.
PANEL = "#right-sidebar"
BODY = f"{PANEL} .menu-cart"
HIDE_CONTROL = f"{PANEL} a.close-menucart"
# The handle that slides the drawer in. Outside the panel, and inside the viewport.
TOGGLER = "div.right-sidebar-toggler-wrapper"

# The page's own words for an empty cart.
_EMPTY_PHRASES = ("your cart is empty", "cart is currently empty")

_MONEY = r"\$\s?\d[\d,]*\.?\d{0,2}"


class CartStatus(Enum):
    """What could be established about the cart."""

    EMPTY = "empty"
    HAS_ITEMS = "has items"

    #: The panel was missing, unreadable, or said two contradictory things. The caller must stop.
    UNKNOWN = "unknown"


@dataclass
class CartLine:
    """One line as the cart shows it."""

    text: str
    name: str
    quantity: int | None
    price: str | None


@dataclass
class CartState:
    """The cart at one moment, and how confident this is about it."""

    status: CartStatus
    lines: list[CartLine] = field(default_factory=list)
    subtotal: str | None = None
    tax: str | None = None
    total: str | None = None
    delivery: str | None = None
    #: The count the cart prints about itself, cross-checked against the lines parsed.
    stated_item_count: int | None = None
    raw_text: str = ""
    why_unknown: str | None = None

    def is_definitely_empty(self) -> bool:
        """True only when the page said so. Never true merely because nothing was found."""
        return self.status is CartStatus.EMPTY

    def count_of(self, product: str) -> int:
        wanted = re.sub(r"\s+", " ", product).strip().lower()

        return sum(1 for line in self.lines if wanted in line.name.lower())

    def describe(self) -> str:
        lines = ["", f"  Cart status : {self.status.value.upper()}"]

        if self.why_unknown:
            lines.append(f"  Why unknown : {self.why_unknown}")

        if self.lines:
            lines.append(f"  Lines ({len(self.lines)}):")
            for item in self.lines:
                lines.append(
                    f"    · name={item.name!r}  qty={item.quantity}  price={item.price}"
                )
                lines.append(f"      raw: {item.text[:90]!r}")
        else:
            lines.append("  Lines       : <none>")

        lines.append("")
        lines.append(f"  Cart says   : {self.stated_item_count} item(s)"
                     if self.stated_item_count is not None else "  Cart says   : <no count printed>")
        lines.append(f"  Subtotal    : {self.subtotal or '<not shown>'}")
        lines.append(f"  Delivery    : {self.delivery or '<not shown>'}")
        lines.append(f"  Tax         : {self.tax or '<not shown>'}")
        lines.append(f"  Total       : {self.total or '<not shown>'}")
        lines.append("")
        lines.append("  Cart panel text, verbatim (so the parse above can be checked against it):")

        for row in self.raw_text.splitlines():
            if row.strip():
                lines.append(f"    | {row.strip()[:100]}")

        return "\n".join(lines)


def _amount_for(lines: list[str], *patterns: str) -> str | None:
    """The amount the page prints for a label, or None. Never computed, never inferred.

    ## Why line-based, and why the patterns are anchored

    The cart renders a label and its amount on **separate lines**:

        Subtotal:
        $1.85
        TAX(10.00%):
        $0.19
        TOTAL:
        $2.04

    The first version of this searched the whole blob for ``total\s*:?\s*(\$...)`` and matched inside
    **Sub**total — so it reported the subtotal as the total, which is exactly the kind of plausible
    wrong number that gets believed. The patterns here are anchored to the start of a line, and `total`
    is written ``^total`` so it cannot match the tail of another word.

    Tax was missed for a different reason: the label is ``TAX(10.00%):``, with the rate between the word
    and the colon. The patterns allow for that rather than assuming a bare label.
    """
    for index, line in enumerate(lines):
        stripped = line.strip()

        for pattern in patterns:
            if not re.match(pattern, stripped, re.IGNORECASE):
                continue

            # The amount is on this line, or on the next one.
            here = re.search(_MONEY, stripped)

            if here:
                return here.group(0).replace(" ", "")

            for following in lines[index + 1 : index + 3]:
                nxt = re.search(_MONEY, following.strip())

                if nxt:
                    return nxt.group(0).replace(" ", "")

    return None


def _parse_lines(lines: list[str]) -> list[CartLine]:
    """Product lines, paired with the price that follows them.

    A cart line renders as ``1 [1]Spring Roll`` — quantity, then the menu code in brackets, then the
    name — with its price on the next line. Parsed from that shape rather than from the whole panel
    text, which the first version did: it produced a single "line" containing the entire summary, so a
    cart with two products would still have counted as one.
    """
    found: list[CartLine] = []

    for index, raw in enumerate(lines):
        stripped = raw.strip()
        match = re.match(r"^(\d+)\s*\[([^\]]+)\]\s*(.+)$", stripped)

        if not match:
            continue

        quantity, _code, name = match.groups()
        price = None

        for following in lines[index + 1 : index + 3]:
            hit = re.search(_MONEY, following.strip())

            if hit:
                price = hit.group(0).replace(" ", "")
                break

        found.append(
            CartLine(text=stripped, name=name.strip(), quantity=int(quantity), price=price)
        )

    return found


def _stated_item_count(lines: list[str]) -> int | None:
    """The count the cart states itself — "1 items" — as a cross-check on the parse."""
    for line in lines:
        match = re.match(r"^(\d+)\s+items?\b", line.strip(), re.IGNORECASE)

        if match:
            return int(match.group(1))

    return None


def read(page: Page) -> CartState:
    """Read the cart panel. Returns UNKNOWN rather than guessing."""
    panel = page.locator(PANEL)

    if panel.count() == 0:
        return CartState(
            status=CartStatus.UNKNOWN,
            why_unknown=f"the cart panel {PANEL!r} is not on the page",
        )

    raw = (panel.inner_text() or "").strip()

    if not raw:
        return CartState(status=CartStatus.UNKNOWN, why_unknown="the cart panel rendered no text")

    text_lines = [line for line in raw.splitlines() if line.strip()]
    lowered = raw.lower()
    says_empty = any(phrase in lowered for phrase in _EMPTY_PHRASES)

    lines = _parse_lines(text_lines)
    stated = _stated_item_count(text_lines)

    state = CartState(
        status=CartStatus.UNKNOWN,
        lines=lines,
        # `^sub` and `^total` are anchored so one cannot match inside the other. See _amount_for.
        subtotal=_amount_for(text_lines, r"^sub\s*-?\s*total\b"),
        tax=_amount_for(text_lines, r"^tax\b"),
        total=_amount_for(text_lines, r"^total\b", r"^grand\s+total\b"),
        delivery=_amount_for(text_lines, r"^delivery\b"),
        stated_item_count=stated,
        raw_text=raw,
    )

    # Every signal must agree. Any disagreement is UNKNOWN, and the caller stops.
    if says_empty and lines:
        state.why_unknown = (
            f"the panel says it is empty but {len(lines)} line(s) were parsed — contradictory"
        )
    elif says_empty:
        state.status = CartStatus.EMPTY
    elif lines:
        if stated is not None and stated != len(lines):
            state.why_unknown = (
                f"the cart states {stated} item(s) but {len(lines)} line(s) were parsed — "
                "refusing to guess which is right"
            )
        else:
            state.status = CartStatus.HAS_ITEMS
    else:
        state.why_unknown = (
            "the panel neither says it is empty nor shows a readable line — "
            "treating as unknown rather than assuming"
        )

    return state


def open_panel(page: Page) -> str:
    """Slide the cart drawer into view, so its controls can be clicked.

    ## Why this is needed at all

    `#right-sidebar` is an off-canvas drawer: `position: fixed` with `right: -800px`, parked just past
    the viewport's edge. Its text is readable from the DOM wherever it sits — which is why every cart
    reading above works without this — but a control at x=2064 on a 1440-wide viewport cannot be
    clicked, and Playwright correctly refuses to try.

    Widening the viewport does not help: the offset is relative to the right edge, so the drawer moves
    with it.

    ## It changes nothing but the view

    Measured: the toggle adds the class `open`, moves the panel from x=1440 to x=640, and issues **no
    non-GET request**. It is the same thing a person does when they open the cart to press a button in
    it.
    """
    panel = page.locator(PANEL)

    if "open" in (panel.get_attribute("class") or ""):
        return "already open"

    page.locator(TOGGLER).first.click()
    page.wait_for_timeout(900)

    classes = panel.get_attribute("class") or ""

    if "open" not in classes:
        raise RuntimeError(
            f"The cart drawer did not open (panel class is {classes!r}); refusing to click into it."
        )

    return "opened"


def find_controls(page: Page) -> list[dict[str, object]]:
    """Cart and checkout controls, reported for inspection. Nothing here is clicked."""
    return page.evaluate(
        """
        () => {
          const vis = el => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
          const out = [];
          document.querySelectorAll('a, button, input[type=submit]').forEach(el => {
            const text = (el.innerText || el.value || '').replace(/\\s+/g, ' ').trim();
            const blob = (text + ' ' + (el.className || '') + ' ' + (el.id || '')).toLowerCase();
            if (!/checkout|cart|manage|place\\s*order/.test(blob)) return;
            out.push({
              tag: el.tagName.toLowerCase(),
              text: text.slice(0, 40),
              id: el.id || null,
              class: (el.className || '').toString().slice(0, 60) || null,
              href: (el.getAttribute('href') || '').slice(0, 90) || null,
              visible: vis(el)
            });
          });
          return out;
        }
        """
    )
