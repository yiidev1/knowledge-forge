"""The one-command workflow, driven entirely by mocks.

## No test in this file can reach a browser

`setUpModule` replaces `order58.browser.sync_playwright` with something that raises. A test that
tried to launch Chrome fails loudly instead of opening one. Every `Session` here is built from
`MagicMock`s, and every assertion about a write is about a mock's `call_count`.

## What is actually being asserted

The claims worth defending are negative ones: that under `Gate.PREFLIGHT` the create function is
**never called**, that under `Gate.CREATE` the submit button is **never clicked**, and that an
ambiguous write is **never retried**. Those are properties a reviewer cannot confirm by reading a
flag, so they are tested by watching what the mocks receive.
"""

from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from order58 import audit, journal as J, workflow  # noqa: E402
from order58.browser import Session  # noqa: E402
from order58.cart import CartLine, CartState, CartStatus  # noqa: E402
from order58.guard import ALLOWED_HOST, Guard, Mode, UnknownSourceOrder  # noqa: E402
from order58.order_form import ContinueResult  # noqa: E402
from order58.product_menu import SearchResult, Suggestion  # noqa: E402
from order58.product_popup import PopupReport  # noqa: E402

SEGMENT = "16655531-15163932150"
MAKE_URL = f"https://{ALLOWED_HOST}/admin/demo/order/make/{SEGMENT}"
ORDER_ID = "16630999"
CUSTOMER_ID = "6959001"

REAL_CART_TEXT = """Order Summary
1 items
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
$2.04"""


def setUpModule() -> None:
    """Make launching a real browser impossible for every test in this module."""
    global _patcher
    _patcher = patch(
        "order58.browser.sync_playwright",
        side_effect=AssertionError("a test tried to launch a real browser"),
    )
    _patcher.start()


def tearDownModule() -> None:
    _patcher.stop()


def full_cart() -> CartState:
    return CartState(
        status=CartStatus.HAS_ITEMS,
        lines=[CartLine(text="1 [1]Spring Roll", name="Spring Roll", quantity=1, price="$1.85")],
        subtotal="$1.85",
        tax="$0.19",
        total="$2.04",
        delivery="$3.49",
        stated_item_count=1,
        raw_text=REAL_CART_TEXT,
    )


def empty_cart() -> CartState:
    return CartState(status=CartStatus.EMPTY, raw_text="Your cart is empty")


PAYMENT_FORM = {
    "formFound": True,
    "submits": [{"text": "Submit", "disabled": False, "visible": True}],
    "errors": [],
    "fields": [
        {"name": "CheckoutPaymentMethod[payment_method]", "required": False, "disabled": False,
         "visible": True, "value": "cash"},
        {"name": "PurchaseBillingForm[credit_card_num]", "required": True, "disabled": True,
         "visible": False, "value": ""},
    ],
}


class _Harness:
    """A session whose page answers every `evaluate` the workflow makes."""

    def __init__(self, *, url: str = MAKE_URL) -> None:
        self.page = MagicMock()
        self.page.url = url
        self.page.evaluate.side_effect = self._evaluate
        self.phone = "15163932150"
        self.page.locator.return_value.input_value.return_value = self.phone

        context = MagicMock()
        context.new_page.return_value = self.page

        self.session = Session(
            browser=MagicMock(),
            context=context,
            screenshot_dir=None,
            guard=Guard(mode=Mode.AUTOMATED),
        )

        self.submit_clicks = 0
        self.dining_clicks = 0
        self.addon_sets = [set(), set()]
        self.banner = "Order incomplete, Dot not close this browser window!!!!"
        self.dine_type = "take-out"
        self.cash_checked = True
        self.card_checked = False

    def _evaluate(self, script, *args):  # noqa: ANN001
        text = str(script)

        if "additional_ids" in text and "checked" in text:
            return list(self.addon_sets.pop(0)) if self.addon_sets else []

        if "order incomplete" in text.lower():
            return self.banner

        if "dine_type" in text and "customer_count" in text:
            return {"dine": self.dine_type, "count": "1", "schedule": ""}

        if "cardInputs" in text:
            return {
                "cash": self.cash_checked,
                "card": self.card_checked,
                "cardInputs": [
                    {"name": "PurchaseBillingForm[credit_card_num]", "disabled": True,
                     "visible": False}
                ],
            }

        if "invalid-feedback" in text and "title" in text:
            return {"errors": [], "title": "Order placed"}

        return {}


def make_journal(tmp: Path) -> J.RunJournal:
    return J.open_run(tmp / "runs", SEGMENT, gate="test", product="Spring Roll", qty=1)


class ResolveTarget(unittest.TestCase):
    """Validation of the whole host/source/phone tuple, before a browser exists."""

    def test_the_approved_url_resolves(self) -> None:
        target = workflow.resolve_target(MAKE_URL)

        self.assertEqual(target.segment, SEGMENT)
        self.assertEqual(target.source.customer_phone, "15163932150")

    def test_derived_urls_carry_the_ids(self) -> None:
        target = workflow.resolve_target(MAKE_URL).with_ids(ORDER_ID, CUSTOMER_ID)

        self.assertIn(f"order_id={ORDER_ID}", target.menu_url)
        self.assertIn(f"customer_id={CUSTOMER_ID}", target.checkout_url("payment"))
        self.assertTrue(target.checkout_url("payment").startswith(
            f"https://{ALLOWED_HOST}/admin/demo/order/checkout-payment/"
        ))

    def test_an_unknown_checkout_stage_is_refused(self) -> None:
        target = workflow.resolve_target(MAKE_URL)

        with self.assertRaises(ValueError):
            target.checkout_url("completed")

    def test_http_is_refused(self) -> None:
        with self.assertRaises(UnknownSourceOrder):
            workflow.resolve_target(MAKE_URL.replace("https://", "http://"))

    def test_a_lookalike_host_is_refused(self) -> None:
        with self.assertRaises(UnknownSourceOrder):
            workflow.resolve_target(
                f"https://{ALLOWED_HOST}.evil.example/admin/demo/order/make/{SEGMENT}"
            )

    def test_a_live_order_route_is_refused(self) -> None:
        with self.assertRaises(UnknownSourceOrder):
            workflow.resolve_target(f"https://{ALLOWED_HOST}/admin/order/make/{SEGMENT}")

    def test_an_unapproved_source_order_is_refused(self) -> None:
        with self.assertRaises(UnknownSourceOrder):
            workflow.resolve_target(
                f"https://{ALLOWED_HOST}/admin/demo/order/make/99999999-88888888"
            )

    def test_an_approved_order_with_a_different_phone_is_refused(self) -> None:
        """The tuple, not just the order id."""
        with self.assertRaises(UnknownSourceOrder):
            workflow.resolve_target(
                f"https://{ALLOWED_HOST}/admin/demo/order/make/16655531-19998887777"
            )

    def test_a_path_that_merely_contains_the_template_is_refused(self) -> None:
        with self.assertRaises(UnknownSourceOrder):
            workflow.resolve_target(
                f"https://{ALLOWED_HOST}/admin/live/x?y=/admin/demo/order/make/{SEGMENT}"
            )


class GatePermissions(unittest.TestCase):
    def test_only_create_gates_may_create(self) -> None:
        self.assertTrue(workflow.Gate.CREATE.may_create)
        self.assertTrue(workflow.Gate.CREATE_AND_SUBMIT.may_create)

        for gate in (workflow.Gate.PREFLIGHT, workflow.Gate.RESUME_VERIFY,
                     workflow.Gate.RESUME_SUBMIT):
            self.assertFalse(gate.may_create, gate)

    def test_only_submit_gates_may_submit(self) -> None:
        self.assertTrue(workflow.Gate.CREATE_AND_SUBMIT.may_submit)
        self.assertTrue(workflow.Gate.RESUME_SUBMIT.may_submit)

        for gate in (workflow.Gate.PREFLIGHT, workflow.Gate.CREATE,
                     workflow.Gate.RESUME_VERIFY):
            self.assertFalse(gate.may_submit, gate)

    def test_resume_gates_can_never_create(self) -> None:
        """The property that makes resume safe."""
        for gate in (workflow.Gate.RESUME_VERIFY, workflow.Gate.RESUME_SUBMIT):
            self.assertTrue(gate.is_resume)
            self.assertFalse(gate.may_create)

    def test_require_raises_without_the_gate(self) -> None:
        with self.assertRaises(PermissionError):
            workflow._require(False, "Creating a demo order")


class _WorkflowCase(unittest.TestCase):
    """Shared plumbing: patch the page-interaction modules the workflow reuses."""

    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.tmp = Path(self._tmp.name)
        self.harness = _Harness()

        self.continue_once = MagicMock(
            return_value=ContinueResult(
                url=f"https://{ALLOWED_HOST}/admin/demo/product/menu/{SEGMENT}"
                    f"?order_id={ORDER_ID}&customer_id={CUSTOMER_ID}",
                order_id=ORDER_ID,
                customer_id=CUSTOMER_ID,
            )
        )
        self.add_to_cart_click = MagicMock()
        self.submit_click = MagicMock()

        exact = Suggestion(raw="1 - Spring Roll - Appetizer", code="1",
                           name="Spring Roll", category="Appetizer")

        self.patches = [
            patch("order58.workflow.order_form.prepare", return_value={"name": "typed"}),
            patch("order58.workflow.order_form.continue_once", self.continue_once),
            patch("order58.workflow.order_form.find_existing_orders", return_value=[]),
            patch("order58.workflow.product_menu.search",
                  return_value=SearchResult(term="Spring Roll", suggestions=[exact],
                                            exact=[exact])),
            patch("order58.workflow.product_popup.open_for"),
            patch("order58.workflow.product_popup.report_on",
                  return_value=PopupReport(heading="h", product_name="Spring Roll",
                                           count_value="1", count_min="1", count_max="99",
                                           count_required=True)),
            patch("order58.workflow.product_popup.set_count", return_value="already 1"),
            patch("order58.workflow.payment.form_report", return_value=PAYMENT_FORM),
            patch("order58.workflow.payment.settled_total", return_value="$2.04"),
            patch("order58.workflow._visit", side_effect=self._visit),
        ]

        for item in self.patches:
            item.start()

        self.addCleanup(self._stop)

    def _visit(self, session, url):  # noqa: ANN001
        """Stand in for a navigation: the thing that matters downstream is where the page now is."""
        session.page.url = url

    def _stop(self) -> None:
        for item in self.patches:
            item.stop()

        self._tmp.cleanup()

    def _ids_match(self) -> dict:
        return {"segment": SEGMENT, "order_id": ORDER_ID, "customer_id": CUSTOMER_ID}

    def run_it(self, gate: workflow.Gate, *, cart_reads=None, target=None, journal=None):
        """Drive run_workflow with cart.read answering a scripted sequence."""
        reads = list(cart_reads if cart_reads is not None else [empty_cart(), full_cart()])

        def next_read(_page):  # noqa: ANN001
            return reads.pop(0) if reads else full_cart()

        locators = {}

        def locator(selector):  # noqa: ANN001
            if selector not in locators:
                mock = MagicMock()

                if selector == workflow.SUBMIT_BUTTON:
                    mock.first.click = self.submit_click
                elif "add-to-cart" in selector:
                    mock.click = self.add_to_cart_click
                else:
                    mock.input_value.return_value = self.harness.phone

                locators[selector] = mock

            return locators[selector]

        self.harness.page.locator.side_effect = locator

        with patch("order58.workflow.cart.read", side_effect=next_read), \
             patch("order58.workflow._ids_on_page", return_value=self._ids_match()):
            return workflow.run_workflow(
                self.harness.session,
                target=target or workflow.resolve_target(MAKE_URL),
                product="Spring Roll",
                qty=1,
                customer_name="Jignesh",
                gate=gate,
                journal=journal or make_journal(self.tmp),
                state_dir=self.tmp,
            )


class PreflightWritesNothing(_WorkflowCase):
    def test_no_write_function_is_called_at_all(self) -> None:
        """Not "the flag was false" — the functions are never reached."""
        result = self.run_it(workflow.Gate.PREFLIGHT)

        self.assertEqual(result.outcome, workflow.Outcome.PREFLIGHT_OK)
        self.continue_once.assert_not_called()
        self.add_to_cart_click.assert_not_called()
        self.submit_click.assert_not_called()
        self.assertEqual(result.writes, [])

    def test_it_says_plainly_what_it_could_not_check(self) -> None:
        result = self.run_it(workflow.Gate.PREFLIGHT)

        self.assertTrue(
            any("need --create" in detail for _, _, detail in result.stages),
            result.describe(),
        )

    def test_a_wrong_phone_on_the_page_aborts_before_any_write(self) -> None:
        self.harness.phone = "19998887777"
        result = self.run_it(workflow.Gate.PREFLIGHT)

        self.assertEqual(result.outcome, workflow.Outcome.ABORTED)
        self.continue_once.assert_not_called()


class CreateStopsBeforeSubmitting(_WorkflowCase):
    def test_it_prepares_and_never_submits(self) -> None:
        result = self.run_it(workflow.Gate.CREATE)

        self.assertEqual(result.outcome, workflow.Outcome.PREPARED)
        self.continue_once.assert_called_once()
        self.add_to_cart_click.assert_called_once()
        self.submit_click.assert_not_called()

    def test_it_records_the_order_id_in_the_journal(self) -> None:
        journal = make_journal(self.tmp)
        self.run_it(workflow.Gate.CREATE, journal=journal)

        self.assertEqual(journal.data["order_id"], ORDER_ID)
        self.assertTrue(journal.wrote)

    def test_the_journal_records_the_write_before_the_cart_write(self) -> None:
        journal = make_journal(self.tmp)
        self.run_it(workflow.Gate.CREATE, journal=journal)

        attempted = [w["what"] for w in journal.data["writes_attempted"]]
        self.assertEqual(attempted[0], "continue (create order)")
        self.assertIn("add to cart", attempted)

    def test_it_tells_you_how_to_resume(self) -> None:
        result = self.run_it(workflow.Gate.CREATE)

        self.assertIn("--resume --submit", result.detail)


class SubmitHappensExactlyOnce(_WorkflowCase):
    def test_one_click_and_the_outcome_is_classified(self) -> None:
        result = self.run_it(workflow.Gate.CREATE_AND_SUBMIT)

        self.assertEqual(self.submit_click.call_count, 1)
        self.assertIn(result.outcome, (workflow.Outcome.SUCCESS, workflow.Outcome.UNKNOWN))

    def test_the_attempt_marker_is_written_before_the_click(self) -> None:
        self.run_it(workflow.Gate.CREATE_AND_SUBMIT)

        marker = J.attempt_marker_path(self.tmp, ORDER_ID)
        self.assertTrue(marker.exists())
        self.assertEqual(oct(marker.stat().st_mode & 0o777), "0o600")

    def test_an_existing_marker_refuses_a_second_submission(self) -> None:
        marker = J.attempt_marker_path(self.tmp, ORDER_ID)
        marker.parent.mkdir(parents=True, exist_ok=True)
        marker.write_text('{"outcome": "unknown"}')

        result = self.run_it(workflow.Gate.CREATE_AND_SUBMIT)

        self.assertEqual(result.outcome, workflow.Outcome.ABORTED)
        self.submit_click.assert_not_called()

    def test_a_failed_submit_is_unknown_and_not_retried(self) -> None:
        self.harness.page.expect_navigation.side_effect = TimeoutError("no navigation")

        result = self.run_it(workflow.Gate.CREATE_AND_SUBMIT)

        self.assertEqual(result.outcome, workflow.Outcome.UNKNOWN)
        self.assertIn("NOT retrying", result.detail)

    def test_a_refused_landing_route_is_unknown_and_nothing_is_read_from_it(self) -> None:
        """What really happened on the live site: the app redirects to its own completion page,
        which is deliberately not allowlisted, so the guard records a refusal AFTER the POST."""
        session = self.harness.session

        def submit_then_redirect_somewhere_unapproved():
            session.guard.refusal = "path '/admin/demo/order/checkout-completed' is not approved"
            session.page.url = (
                f"https://{ALLOWED_HOST}/admin/demo/order/checkout-completed?id={ORDER_ID}"
            )

        self.submit_click.side_effect = submit_then_redirect_somewhere_unapproved

        result = self.run_it(workflow.Gate.CREATE_AND_SUBMIT)

        self.assertEqual(result.outcome, workflow.Outcome.UNKNOWN)
        self.assertIn("not an approved route", result.detail)
        self.assertEqual(self.submit_click.call_count, 1)
        self.assertIn("checkout-completed", result.landed_on or "")


class ResumeCannotCreate(_WorkflowCase):
    def test_resume_submit_never_calls_continue(self) -> None:
        prepared = workflow.resolve_target(MAKE_URL).with_ids(ORDER_ID, CUSTOMER_ID)

        result = self.run_it(
            workflow.Gate.RESUME_SUBMIT,
            cart_reads=[full_cart(), full_cart()],
            target=prepared,
        )

        self.continue_once.assert_not_called()
        self.add_to_cart_click.assert_not_called()
        self.assertEqual(self.submit_click.call_count, 1)
        self.assertNotEqual(result.outcome, workflow.Outcome.ABORTED)

    def test_resume_verify_submits_nothing(self) -> None:
        prepared = workflow.resolve_target(MAKE_URL).with_ids(ORDER_ID, CUSTOMER_ID)

        result = self.run_it(
            workflow.Gate.RESUME_VERIFY,
            cart_reads=[full_cart(), full_cart()],
            target=prepared,
        )

        self.continue_once.assert_not_called()
        self.submit_click.assert_not_called()
        self.assertEqual(result.outcome, workflow.Outcome.PREPARED)


class AuditFailuresStopTheRun(_WorkflowCase):
    def test_a_non_empty_cart_stops_before_adding(self) -> None:
        result = self.run_it(workflow.Gate.CREATE, cart_reads=[full_cart(), full_cart()])

        self.assertEqual(result.outcome, workflow.Outcome.ABORTED)
        self.add_to_cart_click.assert_not_called()

    def test_an_unknown_cart_stops_before_adding(self) -> None:
        unknown = CartState(status=CartStatus.UNKNOWN, why_unknown="panel rendered no text")
        result = self.run_it(workflow.Gate.CREATE, cart_reads=[unknown, unknown])

        self.assertEqual(result.outcome, workflow.Outcome.ABORTED)
        self.add_to_cart_click.assert_not_called()

    def test_no_exact_product_stops_before_the_popup_opens(self) -> None:
        near = Suggestion(raw="x", code="9", name="Shrimp Spring Roll", category="Appetizer")

        with patch("order58.workflow.product_menu.search",
                   return_value=SearchResult(term="Spring Roll", suggestions=[near], near=[near])):
            result = self.run_it(workflow.Gate.CREATE)

        self.assertEqual(result.outcome, workflow.Outcome.ABORTED)
        self.assertIn("NOT substituted", result.detail)
        self.add_to_cart_click.assert_not_called()

    def test_an_order_created_then_product_missing_still_counts_as_having_written(self) -> None:
        """The scenario called out explicitly: the order exists, the product does not."""
        journal = make_journal(self.tmp)
        near = Suggestion(raw="x", code="9", name="Shrimp Spring Roll", category="Appetizer")

        with patch("order58.workflow.product_menu.search",
                   return_value=SearchResult(term="Spring Roll", suggestions=[near], near=[near])):
            self.run_it(workflow.Gate.CREATE, journal=journal)

        self.assertTrue(journal.wrote)
        journal.close(J.ABORTED_NO_WRITE, detail="no exact product")
        self.assertEqual(journal.status, J.ABORTED_AFTER_WRITE)
        self.assertIn(journal.status, J.BLOCKING)

    def test_a_charge_folded_into_the_total_stops_before_submitting(self) -> None:
        bad = full_cart()
        bad.total = "$5.53"

        result = self.run_it(workflow.Gate.CREATE_AND_SUBMIT, cart_reads=[empty_cart(), bad])

        self.assertEqual(result.outcome, workflow.Outcome.ABORTED)
        self.submit_click.assert_not_called()

    def test_an_added_addon_stops_before_adding_to_the_cart(self) -> None:
        self.harness.addon_sets = [set(), {"CartForm[additional_ids][]=99"}]

        result = self.run_it(workflow.Gate.CREATE)

        self.assertEqual(result.outcome, workflow.Outcome.ABORTED)
        self.assertIn("add-on", result.detail)
        self.add_to_cart_click.assert_not_called()

    def test_expect_total_mismatch_stops_the_run(self) -> None:
        with patch("order58.workflow.cart.read", side_effect=[empty_cart(), full_cart()]), \
             patch("order58.workflow._ids_on_page", return_value=self._ids_match()):
            result = workflow.run_workflow(
                self.harness.session,
                target=workflow.resolve_target(MAKE_URL),
                product="Spring Roll",
                qty=1,
                customer_name="Jignesh",
                gate=workflow.Gate.CREATE,
                journal=make_journal(self.tmp),
                state_dir=self.tmp,
                expect_total="$9.99",
            )

        self.assertEqual(result.outcome, workflow.Outcome.ABORTED)

    def test_a_missing_incomplete_banner_stops_before_submitting(self) -> None:
        self.harness.banner = None

        result = self.run_it(workflow.Gate.CREATE_AND_SUBMIT)

        self.assertEqual(result.outcome, workflow.Outcome.ABORTED)
        self.submit_click.assert_not_called()

    def test_a_non_take_out_dining_type_stops_the_run(self) -> None:
        self.harness.dine_type = "delivery"

        result = self.run_it(workflow.Gate.CREATE_AND_SUBMIT)

        self.assertEqual(result.outcome, workflow.Outcome.ABORTED)
        self.submit_click.assert_not_called()

    def test_cash_not_selected_stops_before_submitting(self) -> None:
        self.harness.cash_checked = False

        result = self.run_it(workflow.Gate.CREATE_AND_SUBMIT)

        self.assertEqual(result.outcome, workflow.Outcome.ABORTED)
        self.submit_click.assert_not_called()

    def test_credit_card_also_selected_stops_before_submitting(self) -> None:
        self.harness.card_checked = True

        result = self.run_it(workflow.Gate.CREATE_AND_SUBMIT)

        self.assertEqual(result.outcome, workflow.Outcome.ABORTED)
        self.submit_click.assert_not_called()

    def test_an_identity_mismatch_stops_the_run(self) -> None:
        with patch("order58.workflow.cart.read", return_value=empty_cart()), \
             patch("order58.workflow._ids_on_page",
                   return_value={"segment": SEGMENT, "order_id": "999", "customer_id": "888"}):
            result = workflow.run_workflow(
                self.harness.session,
                target=workflow.resolve_target(MAKE_URL),
                product="Spring Roll",
                qty=1,
                customer_name="Jignesh",
                gate=workflow.Gate.CREATE,
                journal=make_journal(self.tmp),
                state_dir=self.tmp,
            )

        self.assertEqual(result.outcome, workflow.Outcome.ABORTED)
        self.submit_click.assert_not_called()


class CreateFailuresAreUnknown(_WorkflowCase):
    def test_continue_without_identifiers_is_unknown(self) -> None:
        self.continue_once.return_value = ContinueResult(
            url=f"https://{ALLOWED_HOST}/admin/demo/order/make/{SEGMENT}",
            order_id=None,
            customer_id=None,
        )

        result = self.run_it(workflow.Gate.CREATE)

        self.assertEqual(result.outcome, workflow.Outcome.UNKNOWN)
        self.assertIn("NOT retrying", result.detail)
        self.assertEqual(self.continue_once.call_count, 1)

    def test_continue_raising_is_unknown_and_not_retried(self) -> None:
        self.continue_once.side_effect = TimeoutError("navigation never settled")

        result = self.run_it(workflow.Gate.CREATE)

        self.assertEqual(result.outcome, workflow.Outcome.UNKNOWN)
        self.assertEqual(self.continue_once.call_count, 1)

    def test_add_to_cart_raising_is_unknown_and_not_retried(self) -> None:
        self.add_to_cart_click.side_effect = TimeoutError("click never landed")

        result = self.run_it(workflow.Gate.CREATE)

        self.assertEqual(result.outcome, workflow.Outcome.UNKNOWN)
        self.assertEqual(self.add_to_cart_click.call_count, 1)


class NoBrowserWasLaunched(unittest.TestCase):
    def test_sync_playwright_is_patched_to_raise(self) -> None:
        """Proves the module-level guard is active, so no test here can open Chrome."""
        from order58 import browser

        with self.assertRaises(AssertionError):
            browser.sync_playwright()


if __name__ == "__main__":
    unittest.main(verbosity=2)
