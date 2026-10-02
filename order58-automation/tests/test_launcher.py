"""The one-click launcher: does it pass the right configuration, and can it bypass anything?

The launcher is the shortest path in this project to an irreversible action — one keypress in an IDE.
So what matters is not that it works, but that it cannot quietly do something other than what its
configuration says, and that it goes through the same gates as the command line rather than around
them.

Nothing here runs the launcher for real. `cmd_run` is replaced by a spy, so the assertions are about
the arguments it receives.
"""

from __future__ import annotations

import io
import contextlib
import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import auto_order  # noqa: E402
import create_demo_order  # noqa: E402
from order58 import workflow  # noqa: E402

LAUNCHER = Path(__file__).resolve().parent.parent / "auto_order.py"


class ConfigurationIsPassedThrough(unittest.TestCase):
    """What the five editable lines actually become."""

    def test_every_configured_value_reaches_the_workflow(self) -> None:
        args = auto_order.build_args()

        self.assertEqual(args.url, auto_order.DEMO_URL)
        self.assertEqual(args.customer_name, auto_order.CUSTOMER_NAME)
        self.assertEqual(args.product, auto_order.PRODUCT)
        self.assertEqual(args.qty, auto_order.QUANTITY)
        self.assertEqual(args.headless, bool(auto_order.HEADLESS))

    def test_it_requests_both_write_gates(self) -> None:
        """Running the file IS the request to create and submit. Both, or it would do nothing."""
        args = auto_order.build_args()

        self.assertTrue(args.create)
        self.assertTrue(args.submit)
        self.assertFalse(args.resume)

    def test_the_gate_those_flags_select_is_create_and_submit(self) -> None:
        args = auto_order.build_args()
        gate = (
            workflow.Gate.CREATE_AND_SUBMIT
            if args.create and args.submit
            else workflow.Gate.PREFLIGHT
        )

        self.assertIs(gate, workflow.Gate.CREATE_AND_SUBMIT)
        self.assertTrue(gate.may_create)
        self.assertTrue(gate.may_submit)

    def test_it_sets_no_total_expectation(self) -> None:
        """A new order's prices are not known in advance; the cart's own total is the expectation."""
        self.assertIsNone(auto_order.build_args().expect_total)

    def test_the_configured_url_is_an_approved_demo_url(self) -> None:
        target = workflow.resolve_target(auto_order.DEMO_URL)

        self.assertEqual(target.segment, "16655531-15163932150")

    def test_the_default_configuration_is_the_documented_one(self) -> None:
        self.assertEqual(auto_order.PRODUCT, "Spring Roll")
        self.assertEqual(auto_order.QUANTITY, 1)
        self.assertEqual(auto_order.CUSTOMER_NAME, "Jignesh")
        self.assertIs(auto_order.HEADLESS, False)


class ItCallsTheRealWorkflow(unittest.TestCase):
    def test_main_delegates_to_cmd_run_exactly_once(self) -> None:
        spy = MagicMock(return_value=0)

        with patch.object(create_demo_order, "cmd_run", spy):
            with contextlib.redirect_stdout(io.StringIO()):
                code = auto_order.main()

        self.assertEqual(code, 0)
        spy.assert_called_once()

        passed = spy.call_args[0][0]
        self.assertEqual(passed.url, auto_order.DEMO_URL)
        self.assertTrue(passed.create and passed.submit)

    def test_the_exit_code_from_cmd_run_is_returned_unchanged(self) -> None:
        for code in (0, 6, 7, 8):
            with self.subTest(code=code):
                with patch.object(create_demo_order, "cmd_run", MagicMock(return_value=code)):
                    with contextlib.redirect_stdout(io.StringIO()):
                        self.assertEqual(auto_order.main(), code)

    def test_a_refusal_is_shown_and_not_swallowed(self) -> None:
        """`cmd_run` refuses with SystemExit before a browser opens. The operator must see why."""
        refusal = SystemExit("Refusing: an unresolved run exists for this source order.")
        refusal.code = "Refusing: an unresolved run exists for this source order."

        with patch.object(create_demo_order, "cmd_run", MagicMock(side_effect=refusal)):
            captured = io.StringIO()

            with contextlib.redirect_stdout(captured):
                code = auto_order.main()

        self.assertIn("unresolved run", captured.getvalue())
        self.assertIn("STOPPED", captured.getvalue())
        self.assertNotEqual(code, 0)

    def test_an_interrupt_warns_against_re_running(self) -> None:
        with patch.object(create_demo_order, "cmd_run", MagicMock(side_effect=KeyboardInterrupt)):
            captured = io.StringIO()

            with contextlib.redirect_stdout(captured):
                code = auto_order.main()

        output = captured.getvalue()
        self.assertIn("UNKNOWN", output)
        self.assertIn("Do NOT re-run", output)
        self.assertEqual(code, 130)


def code_only(path: Path) -> str:
    """The launcher's executable code, with comments and string literals removed.

    The first version of these tests grepped the raw file and failed on its own documentation:
    "No subprocess, no shell" matched a search for `subprocess`, and "never asks for a password"
    matched a search for `password`. A test that cannot tell code from prose is a test that will be
    switched off. So the source is tokenised and only real code is examined.
    """
    import io
    import tokenize

    kept: list[str] = []

    with path.open("rb") as handle:
        for token in tokenize.tokenize(handle.readline):
            if token.type in (tokenize.COMMENT, tokenize.STRING, tokenize.NL, tokenize.NEWLINE):
                continue

            kept.append(token.string)

    return " ".join(kept)


class ItCannotBypassAnything(unittest.TestCase):
    """The launcher must be a thin caller, not a second implementation."""

    def setUp(self) -> None:
        self.code = code_only(LAUNCHER)
        self.prose = LAUNCHER.read_text()

    def _absent(self, needle: str) -> None:
        self.assertNotIn(
            needle, self.code, f"{needle!r} appears in the launcher's executable code"
        )

    def test_it_does_not_use_subprocess_or_a_shell(self) -> None:
        for forbidden in ("subprocess", "system", "popen", "Popen", "shell"):
            self._absent(forbidden)

    def test_it_contains_no_playwright_or_browser_logic_of_its_own(self) -> None:
        for forbidden in ("playwright", "sync_playwright", "goto", "new_context", "locator"):
            self._absent(forbidden)

    def test_it_defines_no_selector(self) -> None:
        """Selectors live in the modules that were verified against the live DOM."""
        for forbidden in ("reservationform", "product-search", "checkout-payment-form", "tt-"):
            self.assertNotIn(forbidden, self.code)

    def test_it_never_deletes_or_rewrites_state(self) -> None:
        for forbidden in ("unlink", "rmtree", "remove", "write_text", "chmod"):
            self._absent(forbidden)

    def test_it_does_not_resolve_journals_or_touch_markers(self) -> None:
        """`Path.resolve()` is fine; clearing a journal or a marker from here is not."""
        self._absent("attempt_marker_path")
        self._absent("journal_mod")
        self.assertNotIn("phase-b-attempt", self.code)

    def test_it_holds_no_credential(self) -> None:
        for forbidden in ("password", "passwd", "cookie", "token", "storage_state"):
            self._absent(forbidden)

    def test_it_does_not_widen_the_approved_source_list(self) -> None:
        for forbidden in (
            "APPROVED_DEMO_SOURCES", "ALLOWED_PATHS", "paths_for", "ApprovedDemoSource",
            "allowed_paths",
        ):
            self._absent(forbidden)

    def test_it_imports_only_the_cli_entry_point(self) -> None:
        """Its whole job is to call `cmd_run`. Anything else is a second implementation."""
        import ast

        tree = ast.parse(LAUNCHER.read_text())
        imported = {
            node.module or ""
            for node in ast.walk(tree)
            if isinstance(node, ast.ImportFrom)
        } | {
            alias.name
            for node in ast.walk(tree)
            if isinstance(node, ast.Import)
            for alias in node.names
        }

        self.assertIn("create_demo_order", imported)
        self.assertEqual(
            {name for name in imported if name.startswith("order58")},
            set(),
            "the launcher should reach the workflow through create_demo_order, not around it",
        )

    def test_it_never_sets_the_resume_gate(self) -> None:
        """Resuming someone else's prepared order from a launcher would be a surprise."""
        self.assertFalse(auto_order.build_args().resume)

    def test_it_states_plainly_that_it_writes(self) -> None:
        upper = self.prose.upper()

        self.assertIn("CREATES AND SUBMITS A REAL DEMO ORDER", upper)
        self.assertIn("DEMO ORDERS ONLY", upper)

    def test_it_points_at_the_read_only_alternative(self) -> None:
        self.assertIn("create_demo_order.py run", self.prose)


class ConfigurationValidation(unittest.TestCase):
    def test_a_zero_or_negative_quantity_is_refused_before_any_call(self) -> None:
        spy = MagicMock(return_value=0)

        for bad in (0, -1):
            with self.subTest(qty=bad):
                with patch.object(auto_order, "QUANTITY", bad), \
                     patch.object(create_demo_order, "cmd_run", spy):
                    with contextlib.redirect_stdout(io.StringIO()):
                        code = auto_order.main()

                self.assertEqual(code, 2)

        spy.assert_not_called()

    def test_a_non_integer_quantity_is_refused(self) -> None:
        spy = MagicMock(return_value=0)

        with patch.object(auto_order, "QUANTITY", 1.5), \
             patch.object(create_demo_order, "cmd_run", spy):
            with contextlib.redirect_stdout(io.StringIO()):
                code = auto_order.main()

        self.assertEqual(code, 2)
        spy.assert_not_called()

    def test_an_empty_product_is_refused(self) -> None:
        spy = MagicMock(return_value=0)

        with patch.object(auto_order, "PRODUCT", "   "), \
             patch.object(create_demo_order, "cmd_run", spy):
            with contextlib.redirect_stdout(io.StringIO()):
                code = auto_order.main()

        self.assertEqual(code, 2)
        spy.assert_not_called()

    def test_an_unapproved_url_is_refused_by_the_workflow_not_the_launcher(self) -> None:
        """The launcher does not police URLs itself — the guard does, which is the point."""
        from order58.guard import UnknownSourceOrder

        with self.assertRaises(UnknownSourceOrder):
            workflow.resolve_target(
                "https://joymeal.order58.com/admin/demo/order/make/99999999-88888888"
            )


class NoBrowserIsEverLaunchedByTheseTests(unittest.TestCase):
    def test_importing_the_launcher_opens_nothing(self) -> None:
        """Importing must be inert: an IDE may import a file while indexing it."""
        with patch("order58.browser.sync_playwright",
                   side_effect=AssertionError("launched a browser")):
            import importlib

            importlib.reload(auto_order)

        self.assertTrue(hasattr(auto_order, "build_args"))


if __name__ == "__main__":
    unittest.main(verbosity=2)
