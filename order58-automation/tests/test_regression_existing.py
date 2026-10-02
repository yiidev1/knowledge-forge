"""Guarding the commands that were already working before the one-command workflow existed.

`step3`, `step5`, `timing` and `phase-b` each write to a live admin panel, and `phase-b` submits
money. None of their code was edited — but their *dependencies* moved: the payment readers now live
in `order58/payment.py`, and the route allowlist is derived rather than written out. A pure move and a
pure derivation are exactly the kind of change that looks safe and is not, so this file checks the
seams rather than trusting them.
"""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import create_demo_order as cli  # noqa: E402
from order58 import guard, journal as J, payment  # noqa: E402

SOURCE = Path(__file__).resolve().parent.parent / "create_demo_order.py"


class PaymentHelpersStillResolve(unittest.TestCase):
    """`timing` and `phase-b` call these through the old private names."""

    def test_the_aliases_are_the_moved_functions(self) -> None:
        self.assertIs(cli._payment_form_report, payment.form_report)
        self.assertIs(cli._payment_page_total, payment.page_total)
        self.assertIs(cli._settled_payment_total, payment.settled_total)

    def test_all_three_are_callable(self) -> None:
        for function in (payment.form_report, payment.page_total, payment.settled_total):
            self.assertTrue(callable(function), function)

    def test_the_old_private_names_are_gone_from_the_payment_module(self) -> None:
        text = (Path(__file__).resolve().parent.parent / "order58" / "payment.py").read_text()
        self.assertNotIn("_payment_", text)

    def test_settled_total_still_polls_and_gives_up(self) -> None:
        """The AJAX re-render fix. Pure logic, so it is testable without a page."""
        from unittest.mock import MagicMock

        page = MagicMock()
        page.evaluate.side_effect = [None, None, "$2.04"]

        self.assertEqual(payment.settled_total(page, "$2.04", attempts=5), "$2.04")
        self.assertEqual(page.evaluate.call_count, 3)

    def test_settled_total_returns_what_it_last_saw_when_it_never_matches(self) -> None:
        from unittest.mock import MagicMock

        page = MagicMock()
        page.evaluate.return_value = "$5.53"

        self.assertEqual(payment.settled_total(page, "$2.04", attempts=2), "$5.53")


class ScreenshotContractUnchanged(unittest.TestCase):
    """Pre-write captures stay fail-closed; post-write ones stay non-fatal."""

    PRE_WRITE = (
        "step3-before-continue", "step5-before-add", "timing-before", "phase-b-cash-selected",
    )
    POST_WRITE = (
        "step3-unexpected", "step5-add-failed", "step5-cart-after", "timing-unknown",
        "timing-refused", "timing-after", "phase-b-after-submit",
    )

    def setUp(self) -> None:
        self.text = SOURCE.read_text()

    def test_pre_write_captures_are_still_mandatory(self) -> None:
        for name in self.PRE_WRITE:
            with self.subTest(name=name):
                self.assertIn(f'session.screenshot("{name}")', self.text)
                self.assertNotIn(f'session.screenshot("{name}", required=False)', self.text)

    def test_post_write_captures_are_still_non_fatal(self) -> None:
        for name in self.POST_WRITE:
            with self.subTest(name=name):
                self.assertIn(f'session.screenshot("{name}", required=False)', self.text)


class SubmissionMarkersPreserved(unittest.TestCase):
    def test_phase_b_computes_the_shared_marker_path(self) -> None:
        state = SOURCE.parent / "state"
        self.assertEqual(
            cli._attempt_marker("16630311"), J.attempt_marker_path(state, "16630311")
        )

    def test_the_real_submitted_order_still_has_its_marker_on_disk(self) -> None:
        """Order 16630311 was really submitted. Nothing in this work may have removed its marker."""
        marker = cli._attempt_marker("16630311")

        self.assertTrue(
            marker.exists(),
            f"{marker} is missing — the record of a real submission must never be removed",
        )

    def test_phase_b_still_refuses_before_launching_a_browser(self) -> None:
        self.assertIn("if marker.exists():", self.text_of_phase_b())
        self.assertIn("REFUSING", self.text_of_phase_b())

    @staticmethod
    def text_of_phase_b() -> str:
        text = SOURCE.read_text()
        start = text.index("def cmd_phase_b(")

        return text[start : text.index("\ndef ", start + 10)]


class LegacyCommandsUntouched(unittest.TestCase):
    """Their bodies must still contain the guards they were approved with."""

    def setUp(self) -> None:
        self.text = SOURCE.read_text()

    def _body(self, name: str) -> str:
        start = self.text.index(f"def {name}(")

        return self.text[start : self.text.index("\ndef ", start + 10)]

    def test_every_command_is_still_registered(self) -> None:
        for name in (
            "inspect", "login", "step3", "step4", "step5", "step6", "step6b",
            "phase-a", "timing", "phase-b", "run", "journal",
        ):
            with self.subTest(command=name):
                self.assertIn(f'"{name}"', self.text)

    def test_step5_still_refuses_a_cart_that_is_not_definitely_empty(self) -> None:
        self.assertIn("is_definitely_empty()", self._body("cmd_step5"))

    def test_step5_still_verifies_all_three_identifiers(self) -> None:
        body = self._body("cmd_step5")
        self.assertIn("source_order", body)
        self.assertIn("order_id", body)
        self.assertIn("customer_id", body)
        self.assertIn("Refusing to continue", body)

    def test_timing_still_submits_without_inventing_a_pickup_time(self) -> None:
        body = self._body("cmd_timing")
        self.assertIn("checkout-dining-form", body)
        self.assertNotIn("schedule_datetime\").fill", body)

    def test_no_command_retries_a_write(self) -> None:
        for name in ("cmd_step3", "cmd_step5", "cmd_timing", "cmd_phase_b"):
            with self.subTest(command=name):
                body = self._body(name)
                self.assertNotIn("for attempt in range", body)
                self.assertNotIn("while True", body)


class GuardStillLocked(unittest.TestCase):
    def test_only_one_source_order_is_approved(self) -> None:
        self.assertEqual(len(guard.APPROVED_DEMO_SOURCES), 1)

    def test_the_allowlist_is_the_same_seven_paths(self) -> None:
        self.assertEqual(len(guard.ALLOWED_PATHS), 7)

    def test_no_template_can_produce_a_non_demo_route(self) -> None:
        for template in guard._DEMO_PATH_TEMPLATES:
            self.assertTrue(template.startswith("/admin/demo/"), template)

    def test_the_completion_route_is_still_not_allowlisted(self) -> None:
        self.assertNotIn(
            "/admin/demo/order/checkout-completed", guard.ALLOWED_PATHS
        )


if __name__ == "__main__":
    unittest.main(verbosity=2)
