"""The demo order form: look before touching, fill the minimum, continue exactly once.

## Why "the minimum"

The phone field arrives already populated with the real customer's number and the dining type already
defaults to Take-Out. Rewriting either would be the automation asserting something the page had already
said — and overwriting a phone number is how a demo order ends up addressed to the wrong person. So
this **verifies** what is already correct and **types only what is missing**.

## Exactly once

`Continue` creates an order. There is no retry here, no loop and no second attempt on an unclear
outcome: a click whose result could not be read is reported, not repeated. Repeating it is how one
authorised order becomes two.
"""

from __future__ import annotations

import logging
import re
from dataclasses import dataclass
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import Page

LOGGER = logging.getLogger("order58")

# Read from the DOM in step 2, not guessed from a screenshot.
NAME_INPUT = "#reservationform-name"
PHONE_INPUT = "#reservationform-phone"
DINE_TYPE_RADIO = 'input[name="ReservationForm[dine_type]"]'
CONTINUE_BUTTON = '#reservation-form button[type="submit"]'


@dataclass
class ExistingOrder:
    """An order that was already on the page before this run touched anything."""

    label: str
    href: str
    order_id: str | None


@dataclass
class ContinueResult:
    """Where `Continue` led, and what identifiers the application issued."""

    url: str
    order_id: str | None
    customer_id: str | None

    def is_product_menu(self) -> bool:
        return "/admin/demo/product/menu/" in urlparse(self.url).path


def find_existing_orders(page: Page) -> list[ExistingOrder]:
    """Orders already listed on the page, before anything is clicked.

    Reported so that a pile-up from earlier runs is visible, and so it is on record that this run did
    not touch any of them. Nothing here opens, edits or reuses one.

    Looks for links carrying an `order_id`, which is how this application addresses an order, rather
    than for a class name that a redesign would change.
    """
    found: list[dict[str, str]] = page.evaluate(
        """
        () => {
          const rows = [];
          document.querySelectorAll('a[href]').forEach((a) => {
            const href = a.getAttribute('href') || '';
            if (!/order_id=/.test(href)) return;
            rows.push({ label: (a.innerText || '').trim().slice(0, 80), href });
          });
          return rows;
        }
        """
    )

    orders: list[ExistingOrder] = []

    for row in found:
        match = re.search(r"order_id=(\d+)", row["href"])
        orders.append(
            ExistingOrder(
                label=row["label"] or "<no text>",
                href=row["href"],
                order_id=match.group(1) if match else None,
            )
        )

    return orders


def prepare(page: Page, *, customer_name: str) -> dict[str, str]:
    """Fill what is missing, verify what is not, and report the state of every field.

    Returns a description of what was done to each field, so the report can say plainly which values
    the automation supplied and which it left exactly as the application had them.
    """
    actions: dict[str, str] = {}

    # --- name -------------------------------------------------------------------------------------
    name_field = page.locator(NAME_INPUT)
    name_field.wait_for(state="visible")
    current_name = name_field.input_value()

    if current_name.strip() == customer_name:
        actions["name"] = f"already {customer_name!r} — left alone"
    else:
        name_field.fill(customer_name)
        actions["name"] = (
            f"typed {customer_name!r}"
            + (f" (was {current_name!r})" if current_name.strip() else " (was empty)")
        )

    # --- phone ------------------------------------------------------------------------------------
    # Never written. The page arrives with the customer's own number and overwriting it would address
    # the order to somebody else.
    phone_value = page.locator(PHONE_INPUT).input_value()
    actions["phone"] = (
        "left untouched (field already populated)" if phone_value.strip()
        else "EMPTY — not filled, because this tool does not invent a phone number"
    )

    # --- dining type ------------------------------------------------------------------------------
    take_out = page.locator(f'{DINE_TYPE_RADIO}[value="take-out"]')

    if take_out.is_checked():
        actions["dine_type"] = "Take-Out already selected — verified, not clicked"
    else:
        take_out.check()
        actions["dine_type"] = "Take-Out selected"

    # Asserted rather than assumed: if this is not true the run must not continue.
    if not take_out.is_checked():
        raise RuntimeError("Take-Out could not be selected; stopping rather than ordering a delivery.")

    return actions


def continue_once(page: Page) -> ContinueResult:
    """Press Continue a single time and read where it led.

    No retry, by design. If the outcome is unclear it is reported as unclear — a second click would
    turn one authorised order into two.
    """
    button = page.locator(CONTINUE_BUTTON)
    button.wait_for(state="visible")

    LOGGER.info("Clicking Continue — once, and only once.")

    with page.expect_navigation(wait_until="domcontentloaded"):
        button.click()

    page.wait_for_load_state("networkidle")

    query = parse_qs(urlparse(page.url).query)

    result = ContinueResult(
        url=page.url,
        # Read from the application's own response. Never written down anywhere in this tool.
        order_id=(query.get("order_id") or [None])[0],
        customer_id=(query.get("customer_id") or [None])[0],
    )

    LOGGER.info("Continue landed on %s", result.url)

    return result
