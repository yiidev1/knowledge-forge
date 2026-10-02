"""The pre-submission audit, against the cart text this site really produces.

The fixture below is the verbatim cart panel from the order that was actually submitted, delivery row
and all. Tests that invent a tidier cart would pass while the real one failed.
"""

from __future__ import annotations

import sys
import unittest
from decimal import Decimal
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from order58 import audit  # noqa: E402
from order58.cart import CartLine, CartState, CartStatus  # noqa: E402

#: Verbatim from the live cart, including the delivery row that is printed but NOT in the total.
REAL_CART_TEXT = """Order CartHide Order Cart
Order Summary
1 items
Click dish name to edit
Pack 1:
1 [1]Spring Roll
$1.85
Subtotal:
$1.85
Delivery
$3.49
TAX(10.00%):
$0.19
TOTAL:
$2.04
Manage Cart
Go to Checkout"""


def good_cart(**overrides) -> CartState:
    defaults = dict(
        status=CartStatus.HAS_ITEMS,
        lines=[CartLine(text="1 [1]Spring Roll", name="Spring Roll", quantity=1, price="$1.85")],
        subtotal="$1.85",
        tax="$0.19",
        total="$2.04",
        delivery="$3.49",
        stated_item_count=1,
        raw_text=REAL_CART_TEXT,
    )
    defaults.update(overrides)

    return CartState(**defaults)


class MoneyParsing(unittest.TestCase):
    def test_parses_what_the_page_prints(self) -> None:
        self.assertEqual(audit.money("$1.85"), Decimal("1.85"))
        self.assertEqual(audit.money("$ 2.04"), Decimal("2.04"))
        self.assertEqual(audit.money("$1,234.50"), Decimal("1234.50"))

    def test_returns_none_rather_than_guessing(self) -> None:
        for value in (None, "", "free", "<not shown>"):
            self.assertIsNone(audit.money(value), value)


class ChargeRows(unittest.TestCase):
    def test_reads_the_real_cart(self) -> None:
        rows = dict(audit.charge_rows(REAL_CART_TEXT))

        self.assertEqual(rows["subtotal"], Decimal("1.85"))
        self.assertEqual(rows["tax"], Decimal("0.19"))
        self.assertEqual(rows["total"], Decimal("2.04"))
        self.assertEqual(rows["delivery"], Decimal("3.49"))

    def test_the_product_line_price_is_not_counted_as_a_charge(self) -> None:
        labels = [label for label, _ in audit.charge_rows(REAL_CART_TEXT)]

        self.assertNotIn("spring roll", labels)
        self.assertEqual(len(labels), 4)

    def test_the_tax_rate_in_the_label_is_stripped(self) -> None:
        labels = [label for label, _ in audit.charge_rows(REAL_CART_TEXT)]

        self.assertIn("tax", labels)
        self.assertNotIn("tax1000", labels)


class UnexpectedCharges(unittest.TestCase):
    def test_the_real_cart_passes_despite_the_delivery_row(self) -> None:
        """A naive "sum every money row" check would abort every correct take-out order."""
        self.assertTrue(audit.unexpected_charges(REAL_CART_TEXT).ok)

    def test_a_surprise_fee_is_caught(self) -> None:
        text = REAL_CART_TEXT.replace(
            "TAX(10.00%):\n$0.19", "Service Fee:\n$4.00\nTAX(10.00%):\n$0.19"
        )
        report = audit.unexpected_charges(text)

        self.assertFalse(report.ok)
        self.assertIn("service fee", report.findings[0].detail)


class AddonChecks(unittest.TestCase):
    def test_an_unchanged_selection_passes(self) -> None:
        same = {"CartForm[more_sauce][]=7"}
        self.assertTrue(audit.addons_unchanged(same, set(same)).ok)

    def test_an_added_addon_is_caught(self) -> None:
        report = audit.addons_unchanged(set(), {"CartForm[additional_ids][]=99"})

        self.assertFalse(report.ok)
        self.assertIn("ADDED", report.findings[0].detail)

    def test_a_removed_default_is_caught(self) -> None:
        report = audit.addons_unchanged({"CartForm[more_sauce][]=7"}, set())

        self.assertFalse(report.ok)
        self.assertIn("REMOVED", report.findings[0].detail)


class CartAudit(unittest.TestCase):
    def test_the_real_order_passes_every_check(self) -> None:
        report = audit.audit_cart(good_cart(), product="Spring Roll", qty=1)

        self.assertTrue(report.ok, report.describe())

    def test_a_substituted_product_is_caught(self) -> None:
        cart = good_cart(
            lines=[CartLine(text="x", name="Shrimp Spring Roll", quantity=1, price="$1.85")]
        )
        report = audit.audit_cart(cart, product="Spring Roll", qty=1)

        self.assertFalse(report.ok)

    def test_a_wrong_quantity_is_caught(self) -> None:
        cart = good_cart(
            lines=[CartLine(text="x", name="Spring Roll", quantity=2, price="$1.85")]
        )
        report = audit.audit_cart(cart, product="Spring Roll", qty=1)

        self.assertFalse(report.ok)

    def test_two_lines_are_caught(self) -> None:
        cart = good_cart(
            lines=[
                CartLine(text="a", name="Spring Roll", quantity=1, price="$1.85"),
                CartLine(text="b", name="Spring Roll", quantity=1, price="$1.85"),
            ]
        )
        report = audit.audit_cart(cart, product="Spring Roll", qty=1)

        self.assertFalse(report.ok)

    def test_an_unknown_cart_is_caught(self) -> None:
        cart = good_cart(status=CartStatus.UNKNOWN, why_unknown="panel rendered no text")
        report = audit.audit_cart(cart, product="Spring Roll", qty=1)

        self.assertFalse(report.ok)

    def test_a_charge_folded_into_the_total_is_caught(self) -> None:
        """The arithmetic check is the one that catches money nobody asked for."""
        cart = good_cart(total="$5.53")  # 1.85 + 0.19 + an unexplained 3.49
        report = audit.audit_cart(cart, product="Spring Roll", qty=1)

        self.assertFalse(report.ok)
        self.assertTrue(
            any("not subtotal or tax" in finding.detail for finding in report.failures),
            report.describe(),
        )

    def test_a_wrong_unit_price_is_caught(self) -> None:
        cart = good_cart(
            lines=[CartLine(text="x", name="Spring Roll", quantity=1, price="$9.99")]
        )
        report = audit.audit_cart(cart, product="Spring Roll", qty=1)

        self.assertFalse(report.ok)

    def test_quantity_two_is_audited_arithmetically(self) -> None:
        cart = good_cart(
            lines=[CartLine(text="x", name="Spring Roll", quantity=2, price="$1.85")],
            subtotal="$3.70",
            tax="$0.37",
            total="$4.07",
        )
        report = audit.audit_cart(cart, product="Spring Roll", qty=2)

        self.assertTrue(report.ok, report.describe())

    def test_a_missing_amount_fails_rather_than_being_skipped(self) -> None:
        """A number the page did not print is not a passing number."""
        report = audit.audit_cart(good_cart(tax=None), product="Spring Roll", qty=1)

        self.assertFalse(report.ok)
        self.assertTrue(any("cannot check" in f.detail for f in report.failures))

    def test_an_empty_report_is_not_a_pass(self) -> None:
        self.assertFalse(audit.AuditReport().ok)


class PaymentAudit(unittest.TestCase):
    REAL_FORM = {
        "formFound": True,
        "errors": [],
        "fields": [
            {"name": "_csrf-backend", "required": False, "disabled": False, "visible": False,
             "value": "<withheld>"},
            {"name": "CheckoutPaymentMethod[payment_method]", "required": False,
             "disabled": False, "visible": True, "value": "cash", "checked": True},
            {"name": "PurchaseBillingForm[credit_card_num]", "required": True, "disabled": True,
             "visible": False, "value": ""},
            {"name": "PurchaseBillingForm[credit_card_cvv]", "required": True, "disabled": True,
             "visible": False, "value": ""},
            {"name": "ReservationForm[name]", "required": False, "disabled": False,
             "visible": True, "value": "Jignesh"},
        ],
    }

    def test_the_real_payment_page_passes(self) -> None:
        report = audit.audit_payment(
            cart_total="$2.04", page_total="$2.04", form=self.REAL_FORM
        )

        self.assertTrue(report.ok, report.describe())

    def test_disabled_hidden_card_fields_do_not_block(self) -> None:
        """They are marked required, but a disabled field is neither submitted nor validated."""
        report = audit.audit_payment(
            cart_total="$2.04", page_total="$2.04", form=self.REAL_FORM
        )

        self.assertTrue(report.ok)

    def test_a_new_enabled_required_field_is_caught(self) -> None:
        form = dict(self.REAL_FORM)
        form["fields"] = self.REAL_FORM["fields"] + [
            {"name": "Tip[amount]", "required": True, "disabled": False, "visible": True,
             "value": ""}
        ]
        report = audit.audit_payment(cart_total="$2.04", page_total="$2.04", form=form)

        self.assertFalse(report.ok)

    def test_disagreeing_totals_are_caught(self) -> None:
        report = audit.audit_payment(
            cart_total="$2.04", page_total="$5.53", form=self.REAL_FORM
        )

        self.assertFalse(report.ok)

    def test_a_total_the_page_never_stated_fails(self) -> None:
        """The AJAX re-render returns None mid-rebuild; that is not a pass."""
        report = audit.audit_payment(cart_total="$2.04", page_total=None, form=self.REAL_FORM)

        self.assertFalse(report.ok)

    def test_validation_errors_are_caught(self) -> None:
        form = dict(self.REAL_FORM)
        form["errors"] = ["Payment Method cannot be blank."]
        report = audit.audit_payment(cart_total="$2.04", page_total="$2.04", form=form)

        self.assertFalse(report.ok)


if __name__ == "__main__":
    unittest.main(verbosity=2)
