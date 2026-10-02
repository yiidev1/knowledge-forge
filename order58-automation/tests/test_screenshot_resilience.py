"""Offline tests for screenshot resilience. No browser, no network, no live site.

## Why these exist

A full-page screenshot of the Order58 admin panel timed out after 30 s in a headed browser and took
a whole successful inspection down with it: the report had already been computed, and it was
discarded on the way to a diagnostic picture.

The fix has three parts, and all three are the kind of thing that quietly rots:

1. a viewport fallback when the full-page capture fails,
2. `required=False`, so a diagnostic or post-write capture cannot raise,
3. in `inspect`, the report is printed **before** the screenshot is attempted.

Point 3 is the one worth guarding hardest. It is an *ordering* property, invisible in a diff that
moves two lines back, and the only thing that proves it is a test where the screenshot fails and the
report still appears.

## Why unittest and not pytest

pytest is not installed in this project's virtual environment, and installing it was out of scope for
this change. These use the standard library only, so they run with no new dependency:

    ./venv/bin/python -m unittest discover -s tests -v
"""

from __future__ import annotations

import argparse
import contextlib
import io
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import create_demo_order  # noqa: E402
from order58.browser import Session  # noqa: E402
from order58.guard import Guard, Mode  # noqa: E402


class FakeTimeout(Exception):
    """Stands in for playwright's TimeoutError, which needs no import to be raised."""


def _session(screenshot_dir: Path, *, behaviour) -> Session:
    """A real Session wired to a mock page, so the real screenshot logic runs.

    `behaviour` is called with the keyword arguments Playwright would receive, so a test can decide
    per-attempt whether to succeed or raise.
    """
    page = MagicMock()
    page.screenshot.side_effect = behaviour

    context = MagicMock()
    context.new_page.return_value = page

    return Session(
        browser=MagicMock(),
        context=context,
        screenshot_dir=screenshot_dir,
        guard=Guard(mode=Mode.AUTOMATED),
    )


class FullPageCaptureSucceeds(unittest.TestCase):
    """The ordinary path must be untouched by the fallback logic."""

    def test_returns_the_full_page_path_and_does_not_fall_back(self) -> None:
        calls: list[dict] = []

        def behaviour(**kwargs):
            calls.append(kwargs)
            Path(kwargs["path"]).write_bytes(b"fake png")

        with tempfile.TemporaryDirectory() as tmp:
            session = _session(Path(tmp), behaviour=behaviour)
            result = session.screenshot("inspect")

            self.assertIsNotNone(result)
            self.assertTrue(result.name.endswith("-inspect.png"))
            self.assertNotIn("viewport", result.name)
            self.assertTrue(result.exists())

            # Exactly one attempt, and it was the full-page one.
            self.assertEqual(len(calls), 1)
            self.assertTrue(calls[0]["full_page"])
            self.assertEqual(calls[0]["timeout"], 20_000)


class FullPageFailsViewportSucceeds(unittest.TestCase):
    """The actual failure observed in the field."""

    def test_falls_back_and_names_the_file_so_it_cannot_be_mistaken(self) -> None:
        calls: list[dict] = []

        def behaviour(**kwargs):
            calls.append(kwargs)

            if kwargs.get("full_page"):
                raise FakeTimeout("Page.screenshot: Timeout 20000ms exceeded.")

            Path(kwargs["path"]).write_bytes(b"fake png")

        with tempfile.TemporaryDirectory() as tmp:
            session = _session(Path(tmp), behaviour=behaviour)
            result = session.screenshot("phase-b-after-submit", required=False)

            self.assertIsNotNone(result)
            # The suffix is the whole point: a partial capture must announce itself.
            self.assertTrue(result.name.endswith("-viewport.png"))
            self.assertTrue(result.exists())

            self.assertEqual(len(calls), 2)
            self.assertTrue(calls[0]["full_page"])
            self.assertFalse(calls[1]["full_page"])
            self.assertEqual(calls[1]["timeout"], 10_000)

    def test_falls_back_even_when_required(self) -> None:
        """A successful fallback satisfies a required capture — it does not raise."""

        def behaviour(**kwargs):
            if kwargs.get("full_page"):
                raise FakeTimeout("boom")

            Path(kwargs["path"]).write_bytes(b"fake png")

        with tempfile.TemporaryDirectory() as tmp:
            session = _session(Path(tmp), behaviour=behaviour)
            result = session.screenshot("step5-before-add", required=True)

            self.assertTrue(result.name.endswith("-viewport.png"))


class BothCapturesFail(unittest.TestCase):
    """The two halves of the `required` contract."""

    @staticmethod
    def _always_fails(**kwargs):
        raise FakeTimeout(f"Page.screenshot failed (full_page={kwargs.get('full_page')})")

    def test_required_false_returns_none_and_does_not_raise(self) -> None:
        """Post-write evidence: losing the picture must never lose the outcome report."""
        with tempfile.TemporaryDirectory() as tmp:
            session = _session(Path(tmp), behaviour=self._always_fails)

            result = session.screenshot("timing-after", required=False)

            self.assertIsNone(result)
            self.assertEqual(session.page.screenshot.call_count, 2)
            # Nothing was written.
            self.assertEqual(list(Path(tmp).iterdir()), [])

    def test_required_true_raises(self) -> None:
        """Pre-write captures stay fail-closed: no picture, no click."""
        with tempfile.TemporaryDirectory() as tmp:
            session = _session(Path(tmp), behaviour=self._always_fails)

            with self.assertRaises(FakeTimeout):
                session.screenshot("step3-before-continue", required=True)

            self.assertEqual(session.page.screenshot.call_count, 2)

    def test_no_screenshot_directory_is_not_an_error(self) -> None:
        session = _session(None, behaviour=self._always_fails)  # type: ignore[arg-type]

        self.assertIsNone(session.screenshot("anything", required=True))
        session.page.screenshot.assert_not_called()


class InspectPrintsItsReportRegardless(unittest.TestCase):
    """The regression this whole change exists to prevent.

    Under the old ordering the screenshot ran before `print(report.describe())`, so a timeout
    produced an exception and NO report. This test fails against that ordering and passes against
    the fixed one, which is exactly what makes it worth having.
    """

    def test_report_is_printed_even_when_both_captures_fail(self) -> None:
        marker = "PAGE REPORT BODY — this must survive a screenshot failure"

        report = MagicMock()
        report.describe.return_value = marker
        report.looks_like_sign_in = False
        report.__dict__["url"] = "https://example.invalid/"

        def always_fails(**kwargs):
            raise FakeTimeout("Page.screenshot: Timeout exceeded.")

        with tempfile.TemporaryDirectory() as tmp:
            session = _session(Path(tmp), behaviour=always_fails)

            @contextlib.contextmanager
            def fake_launch(**kwargs):
                yield session

            args = argparse.Namespace(
                verbose=False,
                url=create_demo_order.DEFAULT_URL,
                headless=True,
                json=False,
            )

            captured = io.StringIO()
            original_launch = create_demo_order.browser_mod.launch
            original_report_on = create_demo_order.report_on

            try:
                create_demo_order.browser_mod.launch = fake_launch
                create_demo_order.report_on = lambda page: report

                with contextlib.redirect_stdout(captured):
                    exit_code = create_demo_order.cmd_inspect(args)
            finally:
                create_demo_order.browser_mod.launch = original_launch
                create_demo_order.report_on = original_report_on

            output = captured.getvalue()

            self.assertEqual(exit_code, 0, "a failed diagnostic capture must not fail the command")
            self.assertIn(marker, output, "the inspection report was lost to a screenshot failure")
            self.assertIn("No sign-in wall", output)

            # It really did try, and really did fail — the report did not survive by luck.
            self.assertEqual(session.page.screenshot.call_count, 2)


class ScreenshotFailureCannotTriggerAnAction(unittest.TestCase):
    """A screenshot never clicks, navigates or submits — in either outcome."""

    def test_no_navigation_or_click_methods_are_touched(self) -> None:
        def always_fails(**kwargs):
            raise FakeTimeout("boom")

        with tempfile.TemporaryDirectory() as tmp:
            session = _session(Path(tmp), behaviour=always_fails)
            session.screenshot("timing-unknown", required=False)

            page = session.page
            page.goto.assert_not_called()
            page.click.assert_not_called()
            page.locator.assert_not_called()
            page.evaluate.assert_not_called()
            page.reload.assert_not_called()

            # And no write was recorded by the guard.
            self.assertEqual(session.guard.mutating_requests, [])
            self.assertIsNone(session.guard.refusal)


if __name__ == "__main__":
    unittest.main(verbosity=2)
