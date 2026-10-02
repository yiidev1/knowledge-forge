"""Launching a browser, carrying an authorised session, and recording what was on screen.

## Why the system Chrome rather than Playwright's own Chromium

`channel="chrome"` drives the Google Chrome already installed at `/usr/bin/google-chrome` instead of
downloading a ~150 MB Chromium. The same choice the project's existing browser tests make — `tests/E2E`
uses `puppeteer-core` against that binary for the same reason — so one browser on the machine serves
both, and what this automation sees is what a person sees.

## The session is a credential

`storage_state` is a logged-in session for a live admin panel. It is written to a git-ignored directory
with owner-only permissions, never logged, and never printed. If it is missing or expired the tool stops
and asks for a fresh sign-in rather than carrying on anonymously — an automation that silently continues
unauthenticated is one that reports confusing failures three steps later.
"""

from __future__ import annotations

import logging
from contextlib import contextmanager
from datetime import datetime
from pathlib import Path
from typing import Iterator

from playwright.sync_api import Browser, BrowserContext, Page, sync_playwright

from order58.guard import Guard, Mode, NavigationRefused

LOGGER = logging.getLogger("order58")

# Generous, because this drives a third-party site over the public internet, but never infinite: a step
# that hangs for ever is a step nobody can diagnose.
DEFAULT_TIMEOUT_MS = 30_000


class SessionMissing(RuntimeError):
    """Raised when there is no saved session and one was required."""


@contextmanager
def launch(
    *,
    headless: bool,
    mode: Mode,
    session_file: Path | None = None,
    require_session: bool = False,
    screenshot_dir: Path | None = None,
) -> Iterator["Session"]:
    """Open a browser, yield a Session, and always close it.

    A context manager because a browser left running after a crash is a process somebody has to find
    and kill, and on a desktop it is a window that stays on screen.
    """
    if require_session and (session_file is None or not session_file.exists()):
        raise SessionMissing(
            "No saved sign-in was found. Run `python create_demo_order.py login` first — "
            "a browser will open for you to sign in by hand."
        )

    with sync_playwright() as playwright:
        LOGGER.info("Starting Chrome (%s).", "headless" if headless else "visible")

        browser = playwright.chromium.launch(
            # The installed Google Chrome, not a downloaded Chromium. See the module docstring.
            channel="chrome",
            headless=headless,
        )

        state = str(session_file) if session_file and session_file.exists() else None

        if state:
            LOGGER.info("Reusing the saved sign-in.")

        context = browser.new_context(
            storage_state=state,
            viewport={"width": 1440, "height": 900},
            # TLS verification is left ON. Never disable it here.
        )
        context.set_default_timeout(DEFAULT_TIMEOUT_MS)

        try:
            yield Session(
                browser=browser,
                context=context,
                screenshot_dir=screenshot_dir,
                guard=Guard(mode=mode),
            )
        finally:
            context.close()
            browser.close()
            LOGGER.info("Browser closed.")


class Session:
    """One browser context, plus the two things every step needs: a page and a way to record it."""

    def __init__(
        self,
        *,
        browser: Browser,
        context: BrowserContext,
        screenshot_dir: Path | None,
        guard: Guard,
    ) -> None:
        self._browser = browser
        self.context = context
        self._screenshot_dir = screenshot_dir
        self.guard = guard
        self.page: Page = context.new_page()

        # Watching from before the first navigation, so nothing can slip past during startup.
        self.guard.attach(self.page)

    def open(self, url: str) -> Page:
        """Navigate, and wait for the network to settle rather than for a fixed number of seconds.

        Refused before the request is made, and checked again afterwards — a server-side redirect can
        land the browser somewhere the original URL did not name.
        """
        allowed, why = self.guard.allows_navigation(url)

        if not allowed:
            raise NavigationRefused(f"Refusing to open {url} — {why}")

        LOGGER.info("Opening %s", url)
        self.page.goto(url, wait_until="domcontentloaded")
        self.guard.raise_if_refused()

        return self.page

    def screenshot(self, name: str, *, required: bool = True) -> Path | None:
        """Record what is on screen. Returns the path, or None when nothing could be captured.

        These are pictures of a live admin panel and contain customer data, which is why the directory
        they land in is git-ignored.

        ## Why there are two attempts

        A full-page capture of this site has been observed to time out after 30 s in a **headed**
        browser, hanging immediately after Playwright's "fonts loaded" step. The browser-level cause
        is **not proven** — the page is small (every capture so far is at most 1440x1780, well inside
        Chrome's limits), so page size is ruled out, but whether the blockage is frame compositing,
        a busy renderer or something else was never established. This is therefore resilience, not a
        root-cause fix.

        What *is* certain is that a full-page capture uses a different code path from a viewport one.
        So the full page is tried first, and a viewport capture is tried second. Worst case is
        20 s + 10 s, the same ceiling as the single 30 s attempt it replaces, so a slow-but-working
        capture cannot regress.

        Retrying is safe **here specifically** because a screenshot is a pure read. That makes this
        the one place in this project where a retry is defensible; every write is still clicked once
        and never repeated.

        ## `required`

        `required=True` (the default) keeps the old behaviour: a failure raises, which is what makes
        the screenshots taken *before* an irreversible click fail closed — no picture, no click.

        `required=False` is for diagnostics and for evidence captured *after* a write has already
        happened. There, raising would be actively harmful: it would discard the verification and
        outcome reporting for an action the server has already performed.
        """
        if self._screenshot_dir is None:
            return None

        self._screenshot_dir.mkdir(parents=True, exist_ok=True)
        stamp = datetime.now().strftime("%H%M%S")
        path = self._screenshot_dir / f"{stamp}-{name}.png"

        try:
            self.page.screenshot(path=str(path), full_page=True, timeout=20_000)
            LOGGER.info("Screenshot: %s", path.name)

            return path
        except Exception as full_page_failed:  # noqa: BLE001 - any failure falls back to viewport
            # Named so a partial capture can never be mistaken for a full-page one.
            fallback = path.with_name(f"{path.stem}-viewport.png")

            try:
                self.page.screenshot(path=str(fallback), full_page=False, timeout=10_000)
                LOGGER.warning(
                    "Full-page capture of %r failed (%s); saved the viewport only: %s",
                    name,
                    full_page_failed,
                    fallback.name,
                )

                return fallback
            except Exception as viewport_failed:  # noqa: BLE001
                if required:
                    raise

                LOGGER.warning(
                    "Screenshot %r could not be captured (full page: %s; viewport: %s). "
                    "Continuing — this capture is diagnostic, and the printed report is the evidence.",
                    name,
                    full_page_failed,
                    viewport_failed,
                )

                return None

    def save_session(self, session_file: Path) -> None:
        """Write the signed-in state, readable only by this user."""
        session_file.parent.mkdir(parents=True, exist_ok=True)
        self.context.storage_state(path=str(session_file))

        # The file is a live credential; nobody else on this machine needs to read it.
        session_file.chmod(0o600)

        # The path, never the contents.
        LOGGER.info("Sign-in saved to %s (owner-only).", session_file)
