"""The product details popup: what it contains, and what it needs before Add to Cart.

## Selectors, all read from the live DOM

Opened by clicking a typeahead suggestion on the product menu. The popup is `#product-detail-modal`,
and every field inside it belongs to a `CartForm[...]` — so the page is building one cart entry, which
is what makes the quantity field authoritative rather than decorative.

## What is actually required

Exactly one field: `CartForm[count]`, which carries `aria-required="true"`, `min="1"` and `max="99"`,
and **arrives already set to 1**. Every add-on group is optional, and the sauce group arrives with a
default already selected — so a plain order of one needs nothing typed at all.

That is worth stating plainly, because the obvious implementation would set the quantity to 1 on a
field that already says 1. This verifies instead, and only writes when the value differs.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field

from playwright.sync_api import Page

LOGGER = logging.getLogger("order58")

MODAL = "#product-detail-modal"
NAME = f"{MODAL} span.view-product-name"
HEADING = f"{MODAL} h4.heading"
COUNT_INPUT = f"{MODAL} #cartform-count"
PACK_SELECT = f"{MODAL} #cartform-pack_idx"
COMMENT = f"{MODAL} #cartform-comment"
ADD_TO_CART = f"{MODAL} a.add-to-cart"
CLOSE = f"{MODAL} a.btn.pull-right[data-dismiss='modal']"

# Add-on inputs, named by the groups the page puts them in. All optional; see the module docstring.
ADDON_INPUTS = f"{MODAL} input[name*='additional_ids'], {MODAL} input[name*='more_sauce']"

OPEN_TIMEOUT_MS = 8000


@dataclass
class PopupReport:
    """What the popup says about one product."""

    heading: str
    product_name: str
    count_value: str
    count_min: str | None
    count_max: str | None
    count_required: bool
    addon_groups: list[dict[str, object]] = field(default_factory=list)
    required_fields: list[str] = field(default_factory=list)

    def describe(self) -> str:
        lines = [
            "",
            f"  Heading      : {self.heading}",
            f"  Product name : {self.product_name!r}",
            "",
            f"  Order Count  : value={self.count_value!r}  min={self.count_min}  max={self.count_max}"
            f"  required={'yes' if self.count_required else 'no'}",
            f"  Defaults to 1: {'YES' if self.count_value == '1' else 'NO — it is ' + self.count_value}",
            "",
            f"  Required fields in the whole popup: {', '.join(self.required_fields) or '<none>'}",
            "",
            "  Add-on groups:",
        ]

        for group in self.addon_groups:
            lines.append(
                f"    · {group['label']!r} — {group['count']} input(s), "
                f"{'has a default selected' if group['anyChecked'] else 'nothing selected'}"
            )

        return "\n".join(lines)


def open_for(page: Page, suggestion_selector: str) -> None:
    """Click a product suggestion and wait for its popup.

    Opening adds nothing to the cart — that needs Add to Cart, which this module never presses.
    """
    page.locator(suggestion_selector).first.click()
    page.wait_for_selector(MODAL, state="visible", timeout=OPEN_TIMEOUT_MS)
    page.wait_for_timeout(500)  # The popup fades in; give the fields a moment to settle.

    LOGGER.info("Product popup opened.")


def report_on(page: Page) -> PopupReport:
    """Read the popup. Changes nothing."""
    data = page.evaluate(
        """
        () => {
          const root = document.querySelector('#product-detail-modal');
          const text = (sel) => {
            const el = root.querySelector(sel);
            return el ? (el.innerText || '').replace(/\\s+/g, ' ').trim() : '';
          };

          const count = root.querySelector('#cartform-count');

          const required = [];
          root.querySelectorAll('input, select, textarea').forEach((el) => {
            if (el.required || el.getAttribute('required') !== null
                || el.getAttribute('aria-required') === 'true') {
              required.push(el.getAttribute('name') || el.id);
            }
          });

          const groups = [];
          root.querySelectorAll('[class*=additional], .form-group, .card, fieldset').forEach((el) => {
            const inputs = el.querySelectorAll(
              'input[name*="additional_ids"], input[name*="more_sauce"]');
            if (!inputs.length) return;
            const label = (el.querySelector('label, legend, h5, h6, strong, b') || {}).innerText || '';
            groups.push({
              label: label.replace(/\\s+/g, ' ').trim().slice(0, 50),
              count: inputs.length,
              anyChecked: Array.from(inputs).some((i) => i.checked)
            });
          });

          return {
            heading: text('h4.heading'),
            productName: text('span.view-product-name'),
            countValue: count ? count.value : '',
            countMin: count ? count.getAttribute('min') : null,
            countMax: count ? count.getAttribute('max') : null,
            countRequired: count ? count.getAttribute('aria-required') === 'true' : false,
            required,
            groups: groups.slice(0, 8)
          };
        }
        """
    )

    return PopupReport(
        heading=data["heading"],
        product_name=data["productName"],
        count_value=data["countValue"],
        count_min=data["countMin"],
        count_max=data["countMax"],
        count_required=data["countRequired"],
        addon_groups=data["groups"],
        required_fields=data["required"],
    )


def set_count(page: Page, quantity: int) -> str:
    """Set the Order Count, but only if it is not already right.

    The field arrives at 1. Writing 1 over 1 is a change that looks like one and is not, and it is the
    sort of thing that masks a page which did not load properly. Verifying says more.
    """
    field_locator = page.locator(COUNT_INPUT)
    current = field_locator.input_value()

    if current == str(quantity):
        return f"already {quantity} — verified, not retyped"

    field_locator.fill(str(quantity))

    return f"set to {quantity} (was {current!r})"
