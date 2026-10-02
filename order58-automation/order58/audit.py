"""Checking that the order about to be paid for is the order that was asked for.

## Why matching totals are not enough

The obvious check is "the cart says $2.04 and the payment page says $2.04, so we are fine". That
proves the two stages agree. It does not prove either is *right*. If a paid add-on were selected by
accident, or a surcharge appeared, both stages would agree on a total that is still wrong.

So the amounts are checked against each other **arithmetically**, and against the line item:

    line_price x qty == subtotal
    subtotal + tax   == total

An unintended charge then cannot hide, because to affect what is paid it must appear in that sum.

## The delivery trap

A take-out cart on this site prints a delivery row and excludes it from the total:

    Subtotal:      $1.85
    Delivery       $3.49      <- printed, NOT in the total
    TAX(10.00%):   $0.19
    TOTAL:         $2.04

So "add up every money row and compare" is wrong, and a check written that way would abort every
correct order. What {@see unexpected_charges} does instead is assert that every money row is one of
the rows this page is known to print, and leave the arithmetic to the subtotal/tax/total identity.
A row nobody has seen before is reported and stops the run — it might be harmless, but nothing here
guesses about money.

## Add-ons

The popup arrives with a default sauce selected, which is the page's business. What matters is that
the automation did not change it. So the set of checked add-on inputs is captured before the
quantity is touched and compared afterwards: identical, or stop.
"""

from __future__ import annotations

import logging
import re
from dataclasses import dataclass, field
from decimal import Decimal, InvalidOperation

LOGGER = logging.getLogger("order58")

#: Money rows this page is known to print. Anything else stops the run.
KNOWN_CHARGE_LABELS = frozenset({"subtotal", "tax", "total", "delivery", "grand total"})

#: Half a cent, so a displayed-rounding difference is not read as a discrepancy.
TOLERANCE = Decimal("0.005")

_MONEY_LINE = re.compile(r"^\$\s?([\d,]+(?:\.\d{1,2})?)$")
_CART_LINE = re.compile(r"^\d+\s*\[[^\]]+\]")


def money(text: str | None) -> Decimal | None:
    """`"$1.85"` to `Decimal("1.85")`, or None. Never raises, never guesses."""
    if not text:
        return None

    cleaned = re.sub(r"[^\d.]", "", str(text))

    if not cleaned:
        return None

    try:
        return Decimal(cleaned)
    except InvalidOperation:
        return None


def _normalise_label(text: str) -> str:
    """`"TAX(10.00%):"` to `"tax"`. The rate lives between the word and the colon on this page."""
    without_parens = re.sub(r"\([^)]*\)", "", text)

    return re.sub(r"[^a-z ]", "", without_parens.lower()).strip()


@dataclass
class Finding:
    """One check, and what it found."""

    check: str
    ok: bool
    detail: str

    def describe(self) -> str:
        return f"    [{'PASS' if self.ok else 'FAIL'}] {self.check}: {self.detail}"


@dataclass
class AuditReport:
    """Every check that ran."""

    findings: list[Finding] = field(default_factory=list)
    charges: list[str] = field(default_factory=list)

    @property
    def ok(self) -> bool:
        """True only when every check passed. An empty report is not a pass."""
        return bool(self.findings) and all(finding.ok for finding in self.findings)

    @property
    def failures(self) -> list[Finding]:
        return [finding for finding in self.findings if not finding.ok]

    def add(self, check: str, ok: bool, detail: str) -> None:
        self.findings.append(Finding(check=check, ok=ok, detail=detail))

    def merge(self, other: "AuditReport") -> "AuditReport":
        self.findings.extend(other.findings)
        self.charges.extend(other.charges)

        return self

    def describe(self) -> str:
        lines = ["", f"  Pre-submission audit — {len(self.findings)} check(s):"]
        lines += [finding.describe() for finding in self.findings]

        if self.charges:
            lines.append(f"    charge rows seen: {', '.join(self.charges)}")

        lines.append(f"  AUDIT: {'PASS' if self.ok else 'FAIL'}")

        return "\n".join(lines)


def charge_rows(raw_text: str) -> list[tuple[str, Decimal]]:
    """Every (label, amount) the cart prints, read from the panel's own text.

    The panel renders a label and its amount on separate lines, so each money line is attributed to
    the nearest preceding non-money line. A line that parses as a product line — `1 [1]Spring Roll` —
    is the item, not a charge, and is excluded.
    """
    lines = [line.strip() for line in raw_text.splitlines() if line.strip()]
    rows: list[tuple[str, Decimal]] = []

    for index, line in enumerate(lines):
        match = _MONEY_LINE.match(line)

        if not match:
            continue

        label_line = ""

        for candidate in reversed(lines[:index]):
            if _MONEY_LINE.match(candidate):
                continue

            label_line = candidate
            break

        if _CART_LINE.match(label_line):
            continue  # the product's own price

        amount = money(line)

        if amount is not None:
            rows.append((_normalise_label(label_line), amount))

    return rows


def unexpected_charges(raw_text: str) -> AuditReport:
    """Check 7 — every money row is one this page is known to print."""
    report = AuditReport()
    rows = charge_rows(raw_text)
    report.charges = [f"{label}={amount}" for label, amount in rows]

    unknown = [label for label, _ in rows if label and label not in KNOWN_CHARGE_LABELS]

    report.add(
        "no unexpected charge rows",
        not unknown,
        f"rows: {report.charges or '<none>'}"
        + (f" — UNRECOGNISED: {unknown}" if unknown else ""),
    )

    return report


def addons_unchanged(before: set[str], after: set[str]) -> AuditReport:
    """Check 6 — the automation selected no add-on of its own."""
    report = AuditReport()
    added = sorted(after - before)
    removed = sorted(before - after)

    report.add(
        "add-on selection untouched",
        not added and not removed,
        f"{len(before)} selected before, {len(after)} after"
        + (f" — ADDED: {added}" if added else "")
        + (f" — REMOVED: {removed}" if removed else ""),
    )

    return report


def audit_cart(state, *, product: str, qty: int) -> AuditReport:  # noqa: ANN001 - cart.CartState
    """Checks 1-5 and 7, over a cart reading.

    Nothing is inferred: if the page did not print an amount, the check that needs it FAILS rather
    than being skipped. A missing number is not a passing number.
    """
    from order58.cart import CartStatus  # local, to keep this module import-light for tests

    report = AuditReport()

    report.add(
        "cart is readable",
        state.status is CartStatus.HAS_ITEMS,
        f"status={state.status.value}"
        + (f" ({state.why_unknown})" if state.why_unknown else ""),
    )

    report.add("exactly one cart line", len(state.lines) == 1, f"{len(state.lines)} line(s)")

    if len(state.lines) != 1:
        return report.merge(unexpected_charges(state.raw_text))

    line = state.lines[0]
    wanted = re.sub(r"\s+", " ", product).strip().lower()
    actual = re.sub(r"\s+", " ", line.name).strip().lower()

    report.add(
        "product name matches exactly",
        actual == wanted,
        f"cart has {line.name!r}, asked for {product!r}",
    )

    report.add("quantity matches", line.quantity == qty, f"cart says {line.quantity}, asked {qty}")

    unit = money(line.price)
    subtotal = money(state.subtotal)
    tax = money(state.tax)
    total = money(state.total)

    if unit is None or subtotal is None:
        report.add(
            "line price x quantity == subtotal",
            False,
            f"cannot check: line price={state.lines[0].price!r} subtotal={state.subtotal!r}",
        )
    else:
        expected = unit * qty
        report.add(
            "line price x quantity == subtotal",
            abs(expected - subtotal) <= TOLERANCE,
            f"{unit} x {qty} = {expected}, cart subtotal {subtotal}",
        )

    if subtotal is None or tax is None or total is None:
        report.add(
            "subtotal + tax == total",
            False,
            f"cannot check: subtotal={state.subtotal!r} tax={state.tax!r} total={state.total!r}",
        )
    else:
        expected = subtotal + tax
        report.add(
            "subtotal + tax == total",
            abs(expected - total) <= TOLERANCE,
            f"{subtotal} + {tax} = {expected}, cart total {total}"
            + ("" if abs(expected - total) <= TOLERANCE
               else " — an amount is in the total that is not subtotal or tax"),
        )

    return report.merge(unexpected_charges(state.raw_text))


def audit_payment(*, cart_total: str | None, page_total: str | None, form: dict) -> AuditReport:
    """Checks 8-9, over the Payment stage.

    `form` is {@see order58.payment.form_report}'s output.
    """
    report = AuditReport()

    cart_amount = money(cart_total)
    page_amount = money(page_total)

    if cart_amount is None or page_amount is None:
        report.add(
            "payment total == cart total",
            False,
            f"cannot check: cart={cart_total!r} payment page={page_total!r}",
        )
    else:
        report.add(
            "payment total == cart total",
            abs(cart_amount - page_amount) <= TOLERANCE,
            f"cart {cart_amount}, payment page {page_amount}",
        )

    # A field that is required but disabled or hidden cannot block or carry a value — the two credit
    # card inputs are exactly that. One that is required, enabled, visible AND empty is new, and new
    # is a reason to stop.
    blocking = [
        field_row for field_row in form.get("fields", [])
        if field_row.get("required")
        and not field_row.get("disabled")
        and field_row.get("visible")
        and not field_row.get("value")
        and field_row.get("name") != "CheckoutPaymentMethod[payment_method]"
    ]

    report.add(
        "no new required field on the payment form",
        not blocking,
        f"{[f.get('name') for f in blocking]}" if blocking else "none",
    )

    errors = form.get("errors") or []
    report.add("no validation errors showing", not errors, f"{errors}" if errors else "none")

    return report
