#!/usr/bin/env python3
"""Create Order58 demo orders through the admin panel, the way a person does.

## Safety, which is most of the design

- **Dry run is the default.** `run` stops before submitting. Submitting needs `--submit` *and* a
  demo-route check, so a live order URL cannot be driven by accident.
- **Where it may go is enforced, not intended.** See `order58/guard.py`: hostname and path are compared
  exactly, on every navigation, not with a substring test that `evil.example` would pass.
- **Nothing is guessed.** Every selector was read from the live DOM, one step at a time, each reported
  before the next was built.
- **The session is a credential.** It lives in a git-ignored directory, is never logged, and expiry
  stops the tool rather than letting it carry on signed out.

## Commands

    inspect   Open a page, report what is on it, change nothing.
    login     Open a visible browser, wait while you sign in, save the session.
    step3     Create ONE incomplete demo order, then read the product menu.
    step4     Open a product's popup on an EXISTING order and report it. Adds nothing.
    run       The whole workflow. Not built yet.
"""

from __future__ import annotations

import argparse
import json
import sys
import time
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from order58 import browser as browser_mod
from order58 import cart, checkout, lockfile, logging_setup, order_form, product_menu, product_popup
from order58 import audit, payment, workflow
from order58 import journal as journal_mod
from order58.guard import ALLOWED_HOST, Mode, NavigationRefused, UnknownSourceOrder
from order58.inspect_page import report_on

HERE = Path(__file__).resolve().parent
SESSION_FILE = HERE / "state" / "order58.storage.json"
SCREENSHOT_DIR = HERE / "screenshots"
LOG_DIR = HERE / "logs"
LOCK_DIR = HERE / "state" / "locks"
RUNS_DIR = HERE / "state" / "runs"

DEFAULT_URL = "https://joymeal.order58.com/admin/demo/order/make/16655531-15163932150"
MENU_URL = "https://joymeal.order58.com/admin/demo/product/menu/16655531-15163932150"

# A route that is explicitly a demo. Checked before any submission.
DEMO_PATH_MARKER = "/admin/demo/"


def _bool(value: str) -> bool:
    """`--headless false` as well as a bare `--headless`, because the brief asks for the former."""
    return str(value).strip().lower() in ("1", "true", "yes", "y", "on")


def _check_url(url: str) -> str:
    """Refuse a URL that is not ours, before a browser is even started."""
    parsed = urlparse(url)

    if parsed.scheme != "https":
        raise SystemExit(f"Refusing a non-HTTPS URL: {url}")

    if parsed.hostname != ALLOWED_HOST:
        raise SystemExit(f"Refusing {parsed.hostname!r}: this tool only drives {ALLOWED_HOST}.")

    return url


def _require_live_session(session) -> None:  # noqa: ANN001 - browser.Session
    if "/admin/site/login" in session.page.url:
        raise SystemExit("The saved session has expired. Run `login` again.")


# ---------------------------------------------------------------------------------------- inspect


def cmd_inspect(args: argparse.Namespace) -> int:
    """Open a page and report what is actually on it. Touches nothing."""
    log = logging_setup.configure(LOG_DIR, verbose=args.verbose)
    url = _check_url(args.url)

    with browser_mod.launch(
        headless=args.headless,
        mode=Mode.AUTOMATED,
        session_file=SESSION_FILE,
        require_session=False,
        screenshot_dir=SCREENSHOT_DIR,
    ) as session:
        session.open(url)
        session.page.wait_for_load_state("networkidle")

        report = report_on(session.page)

        # The report is printed BEFORE the screenshot is attempted, and the screenshot is optional.
        # The previous order lost an entire successful inspection when a diagnostic PNG timed out:
        # `report_on` had already succeeded, and the one thing this command exists to produce was
        # discarded on the way to a picture nobody had asked for.
        log.info("Page inspected. Nothing was clicked, typed or submitted.")
        print(report.describe())

        if report.looks_like_sign_in:
            print("\n  → Needs an authorised session. Run:  python create_demo_order.py login")
        else:
            print("\n  → No sign-in wall on this URL with the current session.")

        if args.json:
            print("\n" + json.dumps(report.__dict__, indent=2, default=str))

        # Last, and optional. Nothing above depends on it.
        session.screenshot("inspect", required=False)

    return 0


# ------------------------------------------------------------------------------------------ login


def cmd_login(args: argparse.Namespace) -> int:
    """Open a visible browser, wait while you sign in by hand, then save the session.

    Your password never touches this tool: it opens a window and watches the address bar. Nothing here
    reads, fills, submits or logs the credential fields.

    It polls rather than asking you to press a key, because an `input()` prompt would read *this
    process's* terminal, which is not the one you are sitting at.

    While you are driving, navigation is scoped to the host rather than the two approved pages — signing
    in follows redirects nobody can predict. The strict path list applies to every other command.
    """
    log = logging_setup.configure(LOG_DIR, verbose=args.verbose)
    url = _check_url(args.url)

    with browser_mod.launch(
        headless=False,  # Signing in by hand needs a window.
        mode=Mode.LOGIN,
        session_file=None,
        require_session=False,
        screenshot_dir=None,  # A sign-in screen is the one thing never worth photographing.
    ) as session:
        session.open(url)

        print(
            "\n"
            "  ┌─────────────────────────────────────────────────────────────────────┐\n"
            "  │  A Chrome window is now open on the Order58 sign-in page.           │\n"
            "  │                                                                     │\n"
            "  │  1. Type your username and password INTO THAT WINDOW.               │\n"
            "  │  2. Press its own Login button.                                     │\n"
            "  │                                                                     │\n"
            "  │  Nothing here reads, fills, submits or logs those fields.           │\n"
            "  │  This is just watching the address bar for you to get past login.   │\n"
            "  └─────────────────────────────────────────────────────────────────────┘\n",
            flush=True,
        )

        deadline = time.monotonic() + args.wait
        signed_in = False

        while time.monotonic() < deadline:
            session.guard.raise_if_refused()

            if "/admin/site/login" not in session.page.url:
                signed_in = True
                break

            session.page.wait_for_timeout(1500)

        if not signed_in:
            log.error("Still on the sign-in page after %ds. Nothing was saved.", args.wait)
            return 1

        log.info("Signed in. Confirming the demo page opens before saving anything.")

        # Proof before persistence: a session saved without checking is one that fails later, on a step
        # where the cause is much harder to see.
        session.open(args.url)
        session.page.wait_for_load_state("networkidle")

        if "/admin/site/login" in session.page.url:
            log.error("The demo page bounced back to sign-in. Nothing was saved.")
            return 1

        session.save_session(SESSION_FILE)

        print("\n  Signed in, demo page confirmed, session saved.")
        print(session.guard.describe())

    return 0


# ------------------------------------------------------------------------------------------ step3


def cmd_step3(args: argparse.Namespace) -> int:
    """Create ONE incomplete demo order and read the product menu it leads to.

    A single Continue, no retry, nothing added. Everything after the click is reading.
    """
    logging_setup.configure(LOG_DIR, verbose=args.verbose)
    url = _check_url(args.url)

    with browser_mod.launch(
        headless=args.headless,
        mode=Mode.AUTOMATED,
        session_file=SESSION_FILE,
        require_session=True,
        screenshot_dir=SCREENSHOT_DIR,
    ) as session:
        session.open(url)
        session.page.wait_for_load_state("networkidle")
        _require_live_session(session)

        existing = order_form.find_existing_orders(session.page)

        print("\n  Orders already listed on this page, before anything was clicked:")

        if existing:
            for order in existing:
                print(f"    · order_id={order.order_id or '?'}  {order.label[:60]!r}")
            print(
                f"\n    {len(existing)} found. NONE was opened, edited or reused —\n"
                "    a new order is created instead, which is what was authorised."
            )
        else:
            print("    <none>")

        session.screenshot("step3-before-continue")

        actions = order_form.prepare(session.page, customer_name=args.customer_name)

        print("\n  Form state:")
        for name, what in actions.items():
            print(f"    {name:10s} {what}")

        if "EMPTY" in actions.get("phone", ""):
            raise SystemExit("The phone field is empty. Stopping rather than inventing a number.")

        result = order_form.continue_once(session.page)
        session.guard.raise_if_refused()

        if not result.is_product_menu():
            session.screenshot("step3-unexpected", required=False)
            raise SystemExit(
                f"Continue did not land on the product menu but on {result.url}.\n"
                "Stopping. Not retrying — a second click would create a second order."
            )

        print("\n  Continue clicked once. The application issued:")
        print(f"    order_id    : {result.order_id}")
        print(f"    customer_id : {result.customer_id}")
        print(f"    URL         : {result.url}")

        session.screenshot("step3-product-menu")

        search = product_menu.search(session.page, args.product)
        print(search.describe())
        session.screenshot("step3-search")

        print(session.guard.describe())

        if not search.exact:
            print(
                f"\n  → STOPPING: {args.product!r} is not on this menu as an exact match.\n"
                "    No substitute will be used, and nothing further was attempted."
            )
            return 4

    return 0


# ------------------------------------------------------------------------------------------ step4


def cmd_step4(args: argparse.Namespace) -> int:
    """Open a product's popup on an EXISTING demo order and report it.

    Reuses the authorised incomplete order rather than creating another: the ids are checked against
    the approved ones and the run stops if either differs. Add to Cart is never pressed.
    """
    logging_setup.configure(LOG_DIR, verbose=args.verbose)

    menu_url = f"{MENU_URL}?order_id={args.order_id}&customer_id={args.customer_id}"

    with browser_mod.launch(
        headless=args.headless,
        mode=Mode.AUTOMATED,
        session_file=SESSION_FILE,
        require_session=True,
        screenshot_dir=SCREENSHOT_DIR,
    ) as session:
        session.open(menu_url)
        session.page.wait_for_load_state("networkidle")
        _require_live_session(session)

        query = parse_qs(urlparse(session.page.url).query)
        on_page = ((query.get("order_id") or [""])[0], (query.get("customer_id") or [""])[0])

        if on_page != (args.order_id, args.customer_id):
            raise SystemExit(
                f"Refusing to continue: this page is order {on_page[0]!r}/customer {on_page[1]!r}, "
                f"not the authorised {args.order_id!r}/{args.customer_id!r}."
            )

        print(f"\n  Reusing authorised order {args.order_id} (customer {args.customer_id}).")
        print("  No new order was created.")

        search = product_menu.search(session.page, args.product)
        print(search.describe())

        if not search.exact:
            print(f"\n  → STOPPING: no exact match for {args.product!r}. No substitute will be used.")
            return 4

        product_popup.open_for(session.page, product_menu.SUGGESTION)
        session.screenshot("step4-popup")

        popup = product_popup.report_on(session.page)
        print(popup.describe())

        # The popup must be for the product that was asked for, not whatever the click landed on.
        if popup.product_name.strip().lower() != args.product.strip().lower():
            raise SystemExit(f"The popup is for {popup.product_name!r}, not {args.product!r}. Stopping.")

        print(f"\n  Popup confirmed for {popup.product_name!r}. Add to Cart was NOT pressed.")
        print(session.guard.describe())

    return 0


# ------------------------------------------------------------------------------------------ step5


def cmd_step5(args: argparse.Namespace) -> int:
    """Add one Spring Roll to the EXISTING demo order's cart, once, and verify what happened.

    ## The first irreversible action in this workflow

    Everything before this reads. This writes a cart line, and the tool has no way to take it back —
    so every guard runs *before* the click, and none of them runs again afterwards as a reason to
    click a second time.

    ## Fail closed, throughout

    An unreadable cart stops the run. A cart with anything already in it stops the run. A popup for the
    wrong product stops the run. An unclear result after the click is **reported as unclear** — never
    retried, because a retry on an unclear result is how one line becomes two.
    """
    logging_setup.configure(LOG_DIR, verbose=args.verbose)

    menu_url = f"{MENU_URL}?order_id={args.order_id}&customer_id={args.customer_id}"

    # One run at a time against this order. Held for the whole operation, released by the kernel even
    # if this process dies.
    with lockfile.for_order(LOCK_DIR, args.order_id):
        with browser_mod.launch(
            headless=args.headless,
            mode=Mode.AUTOMATED,
            session_file=SESSION_FILE,
            require_session=True,
            screenshot_dir=SCREENSHOT_DIR,
        ) as session:
            session.open(menu_url)
            session.page.wait_for_load_state("networkidle")

            # --- guard 1: the session is real ------------------------------------------------------
            _require_live_session(session)

            # --- guard 2: all three identifiers ----------------------------------------------------
            parsed = urlparse(session.page.url)
            query = parse_qs(parsed.query)
            on_page = {
                "source_order": parsed.path.rstrip("/").split("/")[-1].split("-")[0],
                "order_id": (query.get("order_id") or [""])[0],
                "customer_id": (query.get("customer_id") or [""])[0],
            }
            expected = {
                "source_order": args.source_order,
                "order_id": args.order_id,
                "customer_id": args.customer_id,
            }

            print("\n  Identifier check:")
            for key, want in expected.items():
                got = on_page[key]
                print(f"    {key:14s} expected={want!r}  on page={got!r}  {'OK' if got == want else 'MISMATCH'}")

            if on_page != expected:
                raise SystemExit("Refusing to continue: the page is not the authorised demo order.")

            # --- guard 3: the cart must be readable AND empty --------------------------------------
            before = cart.read(session.page)
            print("\n  CART BEFORE:")
            print(before.describe())

            if not before.is_definitely_empty():
                session.screenshot("step5-cart-not-empty")
                print(
                    "\n  → STOPPING before adding anything.\n"
                    f"    The cart is {before.status.value!r}, not confirmed empty.\n"
                    "    Nothing was clicked. Review the panel text above."
                )
                return 5

            # --- guard 4: the exact product --------------------------------------------------------
            search = product_menu.search(session.page, args.product)
            print(search.describe())

            if len(search.exact) != 1:
                print(
                    f"\n  → STOPPING: expected exactly one exact match for {args.product!r}, "
                    f"found {len(search.exact)}. No substitute will be used."
                )
                return 4

            product_popup.open_for(session.page, product_menu.SUGGESTION)
            popup = product_popup.report_on(session.page)

            if popup.product_name.strip().lower() != args.product.strip().lower():
                raise SystemExit(f"The popup is for {popup.product_name!r}, not {args.product!r}.")

            # --- guard 5: the quantity, verified rather than retyped -------------------------------
            count_action = product_popup.set_count(session.page, args.qty)
            print(f"\n  Order Count: {count_action}")
            print("  Add-ons and sauces: left exactly as the page set them.")

            session.screenshot("step5-before-add")

            # --- the single click ------------------------------------------------------------------
            LOGGER_NOTE = (
                "Clicking Add to Cart — once. No retry on timeout, navigation error or network failure."
            )
            print(f"\n  {LOGGER_NOTE}")

            try:
                session.page.locator(product_popup.ADD_TO_CART).click()
            except Exception as failure:  # noqa: BLE001 - any failure here means "stop", not "retry"
                session.screenshot("step5-add-failed", required=False)
                print(
                    f"\n  → The click did not complete cleanly: {failure}\n"
                    "    NOT retrying. The cart may or may not have been changed — read it in the UI."
                )
                return 6

            # Give the cart time to update, without asserting how long it should take.
            session.page.wait_for_timeout(3500)
            session.guard.raise_if_refused()
            session.screenshot("step5-cart-after", required=False)

            # --- read back, and report whatever is actually there ----------------------------------
            after = cart.read(session.page)
            print("\n  CART AFTER:")
            print(after.describe())

            matches = after.count_of(args.product)

            print("\n  Verification:")
            print(f"    cart readable      : {'yes' if after.status is not cart.CartStatus.UNKNOWN else 'NO'}")
            print(f"    {args.product!r} lines : {matches}")
            print(f"    total lines        : {len(after.lines)}")

            if after.status is cart.CartStatus.UNKNOWN:
                print(
                    "\n  → RESULT UNCERTAIN. The cart could not be read reliably after the click.\n"
                    "    NOT clicking again. Check the order in the UI before any further step."
                )
                return 7

            if matches != 1 or len(after.lines) != 1:
                print(
                    f"\n  → UNEXPECTED: {matches} line(s) matching {args.product!r} out of "
                    f"{len(after.lines)} total. Reporting rather than acting."
                )
                return 8

            # --- checkout controls: found and reported, never pressed ------------------------------
            controls = cart.find_controls(session.page)

            print("\n  Cart / checkout controls on the page (NOT clicked):")
            for control in controls:
                shown = {k: v for k, v in control.items() if v not in (None, "", False)}
                print(f"    · {json.dumps(shown)}")

            print("\n  Add to Cart pressed once. Checkout was NOT clicked.")
            print(session.guard.describe())

    return 0


# ------------------------------------------------------------------------------------------ step6


def cmd_step6(args: argparse.Namespace) -> int:
    """Open the first checkout screen for the EXISTING demo order and read it.

    One click of Go to Checkout, then reading. No field is filled, no option chosen, nothing submitted —
    `order58/checkout.py` has no code that could.

    Afterwards the menu page is re-opened and the cart re-read, so "the cart is unchanged" is a measured
    statement rather than an assumption.
    """
    logging_setup.configure(LOG_DIR, verbose=args.verbose)

    menu_url = f"{MENU_URL}?order_id={args.order_id}&customer_id={args.customer_id}"

    with lockfile.for_order(LOCK_DIR, args.order_id):
        with browser_mod.launch(
            headless=args.headless,
            mode=Mode.AUTOMATED,
            session_file=SESSION_FILE,
            require_session=True,
            screenshot_dir=SCREENSHOT_DIR,
        ) as session:
            session.open(menu_url)
            session.page.wait_for_load_state("networkidle")
            _require_live_session(session)

            # --- guard 1: all three identifiers ----------------------------------------------------
            parsed = urlparse(session.page.url)
            query = parse_qs(parsed.query)
            on_page = {
                "source_order": parsed.path.rstrip("/").split("/")[-1].split("-")[0],
                "order_id": (query.get("order_id") or [""])[0],
                "customer_id": (query.get("customer_id") or [""])[0],
            }
            expected = {
                "source_order": args.source_order,
                "order_id": args.order_id,
                "customer_id": args.customer_id,
            }

            print("\n  Identifier check:")
            for key, want in expected.items():
                got = on_page[key]
                print(f"    {key:14s} expected={want!r}  on page={got!r}  {'OK' if got == want else 'MISMATCH'}")

            if on_page != expected:
                raise SystemExit("Refusing to continue: the page is not the authorised demo order.")

            # --- guard 2: the cart is what was authorised ------------------------------------------
            before = cart.read(session.page)
            print("\n  CART BEFORE CHECKOUT:")
            print(before.describe())

            problems: list[str] = []

            if before.status is not cart.CartStatus.HAS_ITEMS:
                problems.append(f"cart status is {before.status.value!r}, expected items")
            if before.count_of(args.product) != 1:
                problems.append(f"{before.count_of(args.product)} line(s) of {args.product!r}, expected 1")
            if len(before.lines) != 1:
                problems.append(f"{len(before.lines)} line(s) in total, expected 1")
            if before.lines and before.lines[0].quantity != args.qty:
                problems.append(f"quantity is {before.lines[0].quantity}, expected {args.qty}")
            if before.total != args.expect_total:
                problems.append(f"total is {before.total!r}, expected {args.expect_total!r}")

            if problems:
                session.screenshot("step6-cart-mismatch")
                print("\n  → STOPPING before checkout. The cart is not what was authorised:")
                for problem in problems:
                    print(f"      · {problem}")
                return 5

            print(f"\n  Cart confirmed: 1 × {args.product}, total {before.total}.")

            # --- guard 3: the checkout link must carry OUR ids -------------------------------------
            link = session.page.locator("a", has_text="Go to Checkout").first
            href = link.get_attribute("href") or ""
            link_query = parse_qs(urlparse(href).query)

            print(f"\n  Go to Checkout href: {href}")

            if (link_query.get("order_id") or [""])[0] != args.order_id or (
                link_query.get("customer_id") or [""]
            )[0] != args.customer_id:
                raise SystemExit(
                    "The checkout link does not carry the authorised order/customer ids. Stopping."
                )

            # --- bring the drawer on screen so its control can be pressed --------------------------
            # Presentational only; see cart.open_panel. Done before the click is counted, so the
            # request record below describes the checkout navigation and nothing else.
            print(f"\n  Cart drawer: {cart.open_panel(session.page)}")

            # --- the single click ------------------------------------------------------------------
            before_requests = len(session.guard.mutating_requests)
            print("\n  Clicking Go to Checkout — once. No retry on failure.")

            try:
                with session.page.expect_navigation(wait_until="domcontentloaded"):
                    link.click()
            except Exception as failure:  # noqa: BLE001 - any failure means stop, not retry
                session.screenshot("step6-checkout-failed")
                print(f"\n  → The click did not complete cleanly: {failure}\n    NOT retrying.")
                return 6

            session.page.wait_for_load_state("networkidle")
            session.guard.raise_if_refused()
            session.screenshot("step6-checkout")

            # --- read the screen -------------------------------------------------------------------
            report = checkout.report_on(session.page)
            print(report.describe())

            # --- did opening it change anything? ---------------------------------------------------
            new_requests = session.guard.mutating_requests[before_requests:]

            print("\n  Did opening checkout write anything?")
            if new_requests:
                print(f"    {len(new_requests)} non-GET request(s) during this navigation:")
                for method, path in new_requests:
                    print(f"      · {method} {path}")
                print("    → Treat the order state as POSSIBLY CHANGED.")
            else:
                print("    No non-GET request was made. Every request during checkout was a read.")

            # --- is the cart still what it was? ----------------------------------------------------
            session.open(menu_url)
            session.page.wait_for_load_state("networkidle")
            after = cart.read(session.page)

            print("\n  CART AFTER VISITING CHECKOUT:")
            print(after.describe())

            unchanged = (
                after.status is before.status
                and len(after.lines) == len(before.lines)
                and after.count_of(args.product) == before.count_of(args.product)
                and after.total == before.total
            )
            print(f"\n  Cart unchanged by visiting checkout: {'YES' if unchanged else 'NO — see above'}")

            print(session.guard.describe())
            print("\n  Checkout was opened and read only. Nothing was filled, chosen or submitted.")

    return 0


# ----------------------------------------------------------------------------------------- step6b


def cmd_step6b(args: argparse.Namespace) -> int:
    """Read the checkout-info screen, and look for evidence that the earlier hop changed anything.

    ## Minimum requests

    `checkout-customer` is a dispatcher: visiting it redirects. Going through it again would be a second
    request against a URL whose side effects are unknown, so this navigates **straight to the approved
    `checkout-info` path** instead. One request to reach the screen.

    ## Evidence before, not assumption

    The cart being unchanged says nothing about the order's own stage. So before going to checkout this
    re-reads the demo order page, where the application lists its own incomplete orders — if the earlier
    hop moved this order along, that list is where it would show.
    """
    logging_setup.configure(LOG_DIR, verbose=args.verbose)

    menu_url = f"{MENU_URL}?order_id={args.order_id}&customer_id={args.customer_id}"
    info_url = (
        "https://joymeal.order58.com/admin/demo/order/checkout-info/16655531-15163932150"
        f"?customer_id={args.customer_id}&order_id={args.order_id}"
    )

    with lockfile.for_order(LOCK_DIR, args.order_id):
        with browser_mod.launch(
            headless=args.headless,
            mode=Mode.AUTOMATED,
            session_file=SESSION_FILE,
            require_session=True,
            screenshot_dir=SCREENSHOT_DIR,
        ) as session:
            # --- 1. identifiers and cart, on the approved menu page --------------------------------
            session.open(menu_url)
            session.page.wait_for_load_state("networkidle")
            _require_live_session(session)

            parsed = urlparse(session.page.url)
            query = parse_qs(parsed.query)
            on_page = {
                "source_order": parsed.path.rstrip("/").split("/")[-1].split("-")[0],
                "order_id": (query.get("order_id") or [""])[0],
                "customer_id": (query.get("customer_id") or [""])[0],
            }
            expected = {
                "source_order": args.source_order,
                "order_id": args.order_id,
                "customer_id": args.customer_id,
            }

            print("\n  Identifier check:")
            for key, want in expected.items():
                got = on_page[key]
                print(f"    {key:14s} expected={want!r}  on page={got!r}  {'OK' if got == want else 'MISMATCH'}")

            if on_page != expected:
                raise SystemExit("Refusing to continue: the page is not the authorised demo order.")

            before = cart.read(session.page)
            print("\n  CART BEFORE:")
            print(before.describe())

            if before.status is not cart.CartStatus.HAS_ITEMS or before.total != args.expect_total:
                print(
                    f"\n  → STOPPING: cart is {before.status.value!r} with total {before.total!r}, "
                    f"expected items totalling {args.expect_total!r}."
                )
                return 5

            # --- 2. evidence of a state change from the earlier checkout-customer hop ---------------
            print("\n  Looking for read-only evidence that the earlier hop changed the order's state.")
            session.open(args.url)
            session.page.wait_for_load_state("networkidle")

            listed = order_form.find_existing_orders(session.page)

            print(f"\n  Orders the demo page now lists ({len(listed)}):")

            if listed:
                for order in listed:
                    marker = "  ← THIS ORDER" if order.order_id == args.order_id else ""
                    print(f"    · order_id={order.order_id or '?'}{marker}")
                    print(f"        label: {order.label[:90]!r}")
            else:
                print("    <none listed>")

            session.screenshot("step6b-order-list")

            # --- 3. straight to the approved checkout screen ---------------------------------------
            print(f"\n  Navigating directly to the approved checkout-info path.")
            before_requests = len(session.guard.mutating_requests)

            try:
                session.open(info_url)
                session.page.wait_for_load_state("networkidle")
                session.guard.raise_if_refused()
            except NavigationRefused:
                session.screenshot("step6b-refused")
                raise

            session.screenshot("step6b-checkout-info")

            print(f"\n  Landed on: {session.page.url}")

            # --- 4. read it ------------------------------------------------------------------------
            report = checkout.report_on(session.page)
            print(report.describe())

            new_requests = session.guard.mutating_requests[before_requests:]
            print("\n  Non-GET requests while loading this screen:")

            if new_requests:
                for method, path in new_requests:
                    print(f"    · {method} {path}")
                print("    (/socket.io/ is the page's websocket transport, not an order action.)")
            else:
                print("    NONE.")

            print(session.guard.describe())
            print("\n  Read only. Nothing was filled, chosen, continued or submitted.")

    return 0


# ---------------------------------------------------------------------------------------- phase-a


def cmd_phase_a(args: argparse.Namespace) -> int:
    """Inspect the Shipping and Payment stages. Reads only.

    No field is filled, no payment method selected, nothing submitted. The point of this pass is to
    answer three questions with evidence rather than inference: what the final Submit control actually
    is, whether Shipping matters for Take-Out, and whether submitting would reach anything outside this
    application — a card processor, an SMS gateway, anyone's phone.
    """
    logging_setup.configure(LOG_DIR, verbose=args.verbose)

    base = "https://joymeal.order58.com/admin/demo/order"
    ids = f"?customer_id={args.customer_id}&order_id={args.order_id}"
    menu_url = f"{MENU_URL}?order_id={args.order_id}&customer_id={args.customer_id}"

    stages = [
        ("Timing & Info", f"{base}/checkout-info/16655531-15163932150{ids}"),
        ("Shipping", f"{base}/checkout-shipping/16655531-15163932150{ids}"),
        ("Payment", f"{base}/checkout-payment/16655531-15163932150{ids}"),
    ]

    with lockfile.for_order(LOCK_DIR, args.order_id):
        with browser_mod.launch(
            headless=args.headless,
            mode=Mode.AUTOMATED,
            session_file=SESSION_FILE,
            require_session=True,
            screenshot_dir=SCREENSHOT_DIR,
        ) as session:
            # --- identifiers and cart ---------------------------------------------------------------
            session.open(menu_url)
            session.page.wait_for_load_state("networkidle")
            _require_live_session(session)

            parsed = urlparse(session.page.url)
            query = parse_qs(parsed.query)
            on_page = {
                "source_order": parsed.path.rstrip("/").split("/")[-1].split("-")[0],
                "order_id": (query.get("order_id") or [""])[0],
                "customer_id": (query.get("customer_id") or [""])[0],
            }
            expected = {
                "source_order": args.source_order,
                "order_id": args.order_id,
                "customer_id": args.customer_id,
            }

            print("\n  Identifier check:")
            for key, want in expected.items():
                got = on_page[key]
                print(f"    {key:14s} expected={want!r}  on page={got!r}  {'OK' if got == want else 'MISMATCH'}")

            if on_page != expected:
                raise SystemExit("Refusing to continue: the page is not the authorised demo order.")

            current = cart.read(session.page)

            problems = []
            if current.status is not cart.CartStatus.HAS_ITEMS:
                problems.append(f"cart status {current.status.value!r}")
            if current.count_of(args.product) != 1 or len(current.lines) != 1:
                problems.append(f"{len(current.lines)} line(s), {current.count_of(args.product)} matching")
            if current.lines and current.lines[0].quantity != args.qty:
                problems.append(f"quantity {current.lines[0].quantity}")
            if current.total != args.expect_total:
                problems.append(f"total {current.total!r} != {args.expect_total!r}")

            if problems:
                print("\n  → STOPPING. Cart is not as authorised: " + "; ".join(problems))
                print(current.describe())
                return 5

            print(
                f"\n  Cart confirmed: {current.count_of(args.product)} × {args.product}, "
                f"qty {current.lines[0].quantity}, total {current.total}."
            )

            # --- each stage -------------------------------------------------------------------------
            for name, url in stages:
                print("\n" + "=" * 78)
                print(f"  STAGE: {name}")
                print("=" * 78)

                before_requests = len(session.guard.mutating_requests)

                try:
                    session.open(url)
                    session.page.wait_for_load_state("networkidle")
                    session.guard.raise_if_refused()
                except NavigationRefused:
                    session.screenshot(f"phasea-refused-{name.split()[0].lower()}")
                    raise

                session.screenshot(f"phasea-{name.split()[0].lower()}")

                if session.page.url.rstrip("/").split("?")[0] != url.split("?")[0]:
                    print(f"  → REDIRECTED to {session.page.url}. Reporting and moving on.")

                report = checkout.report_on(session.page)
                print(report.describe())

                writes = session.guard.mutating_requests[before_requests:]
                others = [(m, p) for m, p in writes if "/socket.io/" not in p]
                print(f"\n  Non-GET on load: {others if others else 'none beyond /socket.io/ (websocket transport)'}")

                # What this stage says about reaching the outside world.
                external = session.page.evaluate(
                    """
                    () => {
                      const body = (document.body.innerText || '').toLowerCase();
                      const hits = [];
                      [['sms','sms'], ['text message','text message'], ['notify','notif'],
                       ['card','credit card'], ['stripe','stripe'], ['paypal','paypal'],
                       ['charge','charge'], ['email','email']].forEach(([label, needle]) => {
                        if (body.includes(needle)) hits.push(label);
                      });
                      return hits;
                    }
                    """
                )
                print(f"  Words on this page hinting at external actions: {external or 'none'}")

            print(session.guard.describe())
            print("\n  PHASE A COMPLETE — read only. Nothing filled, selected or submitted.")

    return 0


# ---------------------------------------------------------------------------------------- timing


def cmd_timing(args: argparse.Namespace) -> int:
    """Submit the Timing & Info stage once, preserving everything the page already decided.

    ## Why this is safe to submit when Payment was not

    The stage's own Yii ActiveForm config says what it requires, and it was read before anything was
    pressed:

    - `dine_type`  — has `yii.validation.required`. Currently `take-out`. Preserved, not re-clicked.
    - `customer_count` — hidden, `1`. Untouched.
    - `schedule_datetime` — **only** a max-length check carrying `skipOnEmpty: 1`, and **no**
      `required` validator. An empty value is accepted, which is the page's "(AUTO) Pickup Time:
      15 minutes" path. The field is `readonly` and driven by a flatpickr calendar, so empty is the
      state a person gets unless they open the picker.

    Nothing is invented: no date, no time, no name, no instruction. The form is submitted exactly as the
    application prepared it.

    ## Once

    One click. No retry on timeout, redirect or failure — an unclear outcome is reported `UNKNOWN`,
    because the stage may have been saved even if the answer never arrived.
    """
    logging_setup.configure(LOG_DIR, verbose=args.verbose)

    menu_url = f"{MENU_URL}?order_id={args.order_id}&customer_id={args.customer_id}"
    info_url = (
        "https://joymeal.order58.com/admin/demo/order/checkout-info/16655531-15163932150"
        f"?customer_id={args.customer_id}&order_id={args.order_id}"
    )

    def visit(session, url: str) -> None:
        """Commit-then-settle. This site has stalled twice on `domcontentloaded`; committing is enough
        to know where we are, and the settle is allowed to fail without failing the run."""
        session.page.goto(url, wait_until="commit", timeout=60000)
        session.guard.raise_if_refused()
        try:
            session.page.wait_for_load_state("domcontentloaded", timeout=30000)
        except Exception:
            pass
        session.page.wait_for_timeout(3000)

    with lockfile.for_order(LOCK_DIR, args.order_id):
        with browser_mod.launch(
            headless=args.headless,
            mode=Mode.AUTOMATED,
            session_file=SESSION_FILE,
            require_session=True,
            screenshot_dir=SCREENSHOT_DIR,
        ) as session:
            session.page.set_default_timeout(90000)

            # --- verify ----------------------------------------------------------------------------
            visit(session, menu_url)
            _require_live_session(session)

            parsed = urlparse(session.page.url)
            query = parse_qs(parsed.query)
            on_page = {
                "source_order": parsed.path.rstrip("/").split("/")[-1].split("-")[0],
                "order_id": (query.get("order_id") or [""])[0],
                "customer_id": (query.get("customer_id") or [""])[0],
            }
            expected = {
                "source_order": args.source_order,
                "order_id": args.order_id,
                "customer_id": args.customer_id,
            }

            print("\n  Identifiers:", "ALL MATCH" if on_page == expected else f"MISMATCH {on_page}")

            if on_page != expected:
                raise SystemExit("Refusing to continue: not the authorised demo order.")

            before = cart.read(session.page)
            ok = (
                before.status is cart.CartStatus.HAS_ITEMS
                and len(before.lines) == 1
                and before.count_of(args.product) == 1
                and before.lines[0].quantity == args.qty
                and before.total == args.expect_total
            )
            print(
                f"  Cart: {len(before.lines)} line(s), {before.count_of(args.product)} × "
                f"{args.product}, qty {before.lines[0].quantity if before.lines else None}, "
                f"total {before.total}  →  {'OK' if ok else 'MISMATCH'}"
            )

            if not ok:
                print(before.describe())
                return 5

            # --- the stage, and its state before anything is pressed -------------------------------
            visit(session, info_url)

            state = session.page.evaluate(
                """
                () => {
                  const dine = document.querySelector('input[name="ReservationForm[dine_type]"]:checked');
                  const count = document.querySelector('#reservationform-customer_count');
                  const sched = document.querySelector('#reservationform-schedule_datetime');
                  const banner = Array.from(document.querySelectorAll('*'))
                    .filter(e => e.children.length <= 3)
                    .map(e => (e.innerText || '').replace(/\s+/g, ' ').trim())
                    .find(t => /order incomplete/i.test(t) && t.length < 160) || null;
                  return {
                    dine: dine ? dine.value : null,
                    count: count ? count.value : null,
                    schedule: sched ? sched.value : null,
                    scheduleReadonly: sched ? sched.readOnly : null,
                    banner
                  };
                }
                """
            )

            print("\n  Timing & Info, before submitting:")
            print(f"    dine_type        : {state['dine']!r}")
            print(f"    customer_count   : {state['count']!r}")
            print(f"    schedule_datetime: {state['schedule']!r} (readonly={state['scheduleReadonly']})")
            print(f"    status banner    : {state['banner']!r}")

            if state["dine"] != "take-out":
                raise SystemExit(f"Dining type is {state['dine']!r}, not take-out. Stopping.")

            if state["banner"] is None or "incomplete" not in (state["banner"] or "").lower():
                raise SystemExit("The order no longer reports itself incomplete. Stopping.")

            print(
                "\n  Leaving every field exactly as the application set it — no date, no time, "
                "no name, no instruction."
            )
            session.screenshot("timing-before")

            # --- one click -------------------------------------------------------------------------
            print("\n  Submitting Timing & Info — once. No retry on an unclear outcome.")
            outcome = "UNKNOWN"

            try:
                with session.page.expect_navigation(wait_until="commit", timeout=60000):
                    session.page.locator(
                        "#checkout-dining-form button[type=submit]"
                    ).click()
                outcome = "navigated"
            except Exception as failure:  # noqa: BLE001
                print(f"\n  → Outcome UNKNOWN: {failure}")
                print("    NOT retrying. The stage may or may not have been saved.")
                session.screenshot("timing-unknown", required=False)

            try:
                session.page.wait_for_load_state("domcontentloaded", timeout=30000)
            except Exception:
                pass

            session.page.wait_for_timeout(3000)

            try:
                session.guard.raise_if_refused()
            except NavigationRefused as refused:
                session.screenshot("timing-refused", required=False)
                print(f"\n  → Landed somewhere unapproved: {refused}")
                print("    Recording as UNKNOWN and stopping. Nothing was retried.")
                return 7

            print(f"\n  Landed on: {session.page.url}")
            session.screenshot("timing-after", required=False)

            # --- what happened ---------------------------------------------------------------------
            errors = session.page.evaluate(
                """
                () => Array.from(document.querySelectorAll('.invalid-feedback, .has-error, .alert-danger'))
                  .map(e => (e.innerText || '').replace(/\s+/g, ' ').trim())
                  .filter(Boolean).slice(0, 8)
                """
            )
            print(f"  Validation errors on the page: {errors or 'none'}")

            visit(session, menu_url)
            after = cart.read(session.page)
            print("\n  CART AFTER:")
            print(after.describe())

            same = (
                after.status is before.status
                and len(after.lines) == len(before.lines)
                and after.total == before.total
            )
            print(f"\n  Cart unchanged by the Timing & Info submission: {'YES' if same else 'NO'}")

            others = [r for r in session.guard.mutating_requests if "/socket.io/" not in r[1]]
            print(f"  Non-GET beyond the websocket: {others or 'NONE'}")
            print(f"\n  Outcome: {outcome}. Cash was NOT selected; Payment was NOT submitted.")

    return 0


# --------------------------------------------------------------------------------------- phase-b


#: Written before the submit click and never removed. Its presence refuses a second attempt — including
#: after an attempt whose outcome was never established, which is the case that matters. An order that
#: may already be placed must not be placed again on a guess.
def _attempt_marker(order_id: str) -> Path:
    # Delegated so the one-command workflow and `phase-b` compute the SAME filename. If they
    # disagreed, each could submit an order the other had already submitted.
    return journal_mod.attempt_marker_path(LOCK_DIR.parent, order_id)


# The Payment-stage readers now live in `order58/payment.py`, so the one-command workflow and the
# hand-driven `phase-b` read that page through the same code. Aliased rather than renamed at the call
# sites: `timing` and `phase-b` are the highest-stakes commands here, and a pure move with an alias
# touches neither.
_payment_form_report = payment.form_report
_payment_page_total = payment.page_total
_settled_payment_total = payment.settled_total



def cmd_phase_b(args: argparse.Namespace) -> int:
    """Final demo submission. Reads and reports by default; submits only with `--submit`.

    ## Three gates, in order

    1. **Verify** — identifiers, cart, totals, Take-Out, the incomplete banner, and the whole Payment
       form including its registered validators. Any surprise stops the run.
    2. **Cash** — selected by its read-from-DOM selector, then confirmed checked, with credit card
       confirmed unchecked and its inputs confirmed hidden or disabled.
    3. **Submit** — one click. Never a second, and never after an attempt marker exists, because an
       attempt whose outcome was never established might already have placed the order.
    """
    logging_setup.configure(LOG_DIR, verbose=args.verbose)

    q = f"?order_id={args.order_id}&customer_id={args.customer_id}"
    menu_url = f"{MENU_URL}{q}"
    base = "https://joymeal.order58.com/admin/demo/order"
    info_url = f"{base}/checkout-info/16655531-15163932150{q}"
    pay_url = f"{base}/checkout-payment/16655531-15163932150{q}"

    CASH = "input[name='CheckoutPaymentMethod[payment_method]'][value='cash']"
    CARD = "input[name='CheckoutPaymentMethod[payment_method]'][value='credit card']"
    SUBMIT = "#checkout-payment-form button[type=submit].submit-btn"

    def visit(session, url: str) -> None:
        session.page.goto(url, wait_until="commit", timeout=60000)
        session.guard.raise_if_refused()
        try:
            session.page.wait_for_load_state("domcontentloaded", timeout=30000)
        except Exception:
            pass
        session.page.wait_for_timeout(3000)

    marker = _attempt_marker(args.order_id)

    if marker.exists():
        print(f"\n  REFUSING: an attempt was already recorded for this order:\n    {marker}")
        print(f"    {marker.read_text()[:400]}")
        print("\n  A previous attempt — successful OR of unknown outcome — blocks another.")
        return 9

    with lockfile.for_order(LOCK_DIR, args.order_id):
        with browser_mod.launch(
            headless=args.headless, mode=Mode.AUTOMATED, session_file=SESSION_FILE,
            require_session=True, screenshot_dir=SCREENSHOT_DIR,
        ) as session:
            session.page.set_default_timeout(90000)

            # ===== STEP 1 — verification, entirely read-only ====================================
            print("\n" + "=" * 78)
            print("  STEP 1 — FINAL READ-ONLY VERIFICATION")
            print("=" * 78)

            visit(session, menu_url)
            _require_live_session(session)

            parsed = urlparse(session.page.url)
            query = parse_qs(parsed.query)
            seen = {
                "source_order": parsed.path.rstrip("/").split("/")[-1].split("-")[0],
                "order_id": (query.get("order_id") or [""])[0],
                "customer_id": (query.get("customer_id") or [""])[0],
            }
            want = {
                "source_order": args.source_order,
                "order_id": args.order_id,
                "customer_id": args.customer_id,
            }
            print(f"\n  Identifiers        : {'ALL MATCH' if seen == want else f'MISMATCH {seen}'}")

            if seen != want:
                return 5

            before = cart.read(session.page)
            checks = {
                "cart readable": before.status is cart.CartStatus.HAS_ITEMS,
                "exactly 1 line": len(before.lines) == 1,
                f"1 x {args.product}": before.count_of(args.product) == 1,
                "quantity 1": bool(before.lines) and before.lines[0].quantity == args.qty,
                "subtotal $1.85": before.subtotal == args.expect_subtotal,
                "tax $0.19": before.tax == args.expect_tax,
                "total $2.04": before.total == args.expect_total,
            }
            for name, passed in checks.items():
                print(f"  {name:19s}: {'PASS' if passed else 'FAIL'}")

            if not all(checks.values()):
                print(before.describe())
                return 5

            visit(session, info_url)
            timing = session.page.evaluate(
                """
                () => {
                  const d = document.querySelector('input[name="ReservationForm[dine_type]"]:checked');
                  const s = document.querySelector('#reservationform-schedule_datetime');
                  const c = document.querySelector('#reservationform-customer_count');
                  return {dine: d && d.value, schedule: s && s.value, count: c && c.value};
                }
                """
            )
            print(f"\n  Dining type        : {timing['dine']!r}")
            print(f"  Customer count     : {timing['count']!r}")
            print(f"  schedule_datetime  : {timing['schedule']!r}")

            if timing["dine"] != "take-out":
                print("  → Not Take-Out. Stopping.")
                return 5

            visit(session, pay_url)
            report = _payment_form_report(session.page)
            session.screenshot("phase-b-step1")

            print(f"\n  Order status       : {report['banner']!r}")
            print(f"  Payment form       : {'found' if report['formFound'] else 'MISSING'}")
            print(f"  Form action        : {report['action']}  [{report['method']}]")
            print(f"  Timing accepted    : reached the Payment stage with no errors "
                  f"({report['errors'] or 'none'})")

            print(f"\n  Payment form fields ({len(report['fields'])}):")
            for f in report["fields"]:
                bits = [f"name={f['name']!r}", f"type={f['type']}"]
                if f["label"]:
                    bits.append(f"label={f['label']!r}")
                if f["required"]:
                    bits.append("REQUIRED")
                if f["disabled"]:
                    bits.append("disabled")
                if not f["visible"]:
                    bits.append("hidden")
                if f["checked"] is not None:
                    bits.append(f"checked={f['checked']}")
                if f["value"]:
                    bits.append(f"value={f['value']!r}")
                print("    - " + ", ".join(bits))

            print(f"\n  Registered client validators ({len(report['validators'])}):")
            for v in report["validators"] or []:
                print(f"    - {v['id']}: required={v['required']}, rules={sorted(set(v['rules']))}")
            if not report["validators"]:
                print("    <none parsed from the page scripts>")

            print("\n  Pickup / name wording on the page:")
            for key, lines in report["hits"].items():
                print(f"    {key}:")
                for line in lines or ["<not present>"]:
                    print(f"      | {line}")

            print(f"\n  Submit controls on the form ({len(report['submits'])}):")
            for b in report["submits"]:
                print(f"    - {b['text']!r} type={b['type']} disabled={b['disabled']} "
                      f"visible={b['visible']} class={b['cls']!r}")

            print(f"\n  Order rows visible BEFORE submission ({len(report['rows'])}):")
            for row in report["rows"]:
                print(f"    | {row}")

            # Anything required that is not the payment method itself, and not already filled,
            # is a new condition and stops the run.
            html_required = [
                f for f in report["fields"]
                if f["required"] and not f["disabled"]
                and f["name"] != "CheckoutPaymentMethod[payment_method]"
                and not f["value"]
            ]
            validator_required = [
                v for v in (report["validators"] or [])
                if v["required"] and "payment_method" not in v["id"]
            ]

            print("\n  NEW REQUIRED CONDITIONS BEYOND PAYMENT METHOD:")
            if not html_required and not validator_required:
                print("    none — nothing new became required after Timing & Info")
            else:
                for f in html_required:
                    print(f"    - HTML required and empty: {f['name']!r} ({f['label']!r})")
                for v in validator_required:
                    print(f"    - validator requires: {v['id']} {v['messages']}")
                print("\n  → STOPPING. A newly required field must be reviewed before submitting.")
                return 6

            if not report["formFound"] or not report["submits"]:
                print("\n  → STOPPING: the payment form or its submit control is not present.")
                return 6

            if report["banner"] is None:
                print("\n  → STOPPING: the order no longer states that it is incomplete.")
                return 6

            others = [r for r in session.guard.mutating_requests if "/socket.io/" not in r[1]]
            print(f"\n  Non-GET so far     : {others or 'NONE'}")
            print("\n  STEP 1: PASS")

            if not args.submit:
                print("\n" + "=" * 78)
                print("  DRY RUN — Cash was NOT selected and nothing was submitted.")
                print("  Re-run with --submit to perform the single authorised submission.")
                print("=" * 78)
                return 0

            # ===== STEP 2 — select Cash ========================================================
            print("\n" + "=" * 78)
            print("  STEP 2 — SELECT CASH")
            print("=" * 78)

            session.page.locator(CASH).check()

            # The click re-renders the summary. Wait for it to settle before reading anything,
            # otherwise the radios and the total are both read mid-rebuild. See the helper.
            shown_total = _settled_payment_total(session.page, args.expect_total)
            print(f"  Summary settled    : total reads {shown_total!r}")

            state = session.page.evaluate(
                """
                () => {
                  const g = s => document.querySelector(s);
                  const vis = el => { if (!el) return null;
                    const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
                  const cash = g("input[name='CheckoutPaymentMethod[payment_method]'][value='cash']");
                  const card = g("input[name='CheckoutPaymentMethod[payment_method]'][value='credit card']");
                  const cardInputs = Array.from(document.querySelectorAll(
                    "input[name*='card'], input[name*='Card'], #card-element, [id*='card-number']"))
                    .map(el => ({name: el.getAttribute('name') || el.id, disabled: !!el.disabled,
                                 visible: vis(el)}));
                  return {
                    cashChecked: cash ? cash.checked : null,
                    cardChecked: card ? card.checked : null,
                    cardInputs,
                    newRequired: Array.from(document.querySelectorAll(
                      '#checkout-payment-form [required]:not([disabled])'))
                      .map(el => ({name: el.getAttribute('name'), value: (el.value||'').slice(0,20),
                                   visible: vis(el)})),
                    errors: Array.from(document.querySelectorAll('.invalid-feedback, .alert-danger'))
                      .map(e => (e.innerText||'').replace(/\\s+/g,' ').trim()).filter(Boolean).slice(0,5)
                  };
                }
                """
            )

            print(f"  Cash checked       : {state['cashChecked']}")
            print(f"  Credit card checked: {state['cardChecked']}")
            print(f"  Card inputs        : {state['cardInputs'] or 'none on the page'}")
            print(f"  Required & enabled : {state['newRequired'] or 'none'}")
            print(f"  Errors shown       : {state['errors'] or 'none'}")
            session.screenshot("phase-b-cash-selected")

            exposed_card = [c for c in state["cardInputs"] if c["visible"] and not c["disabled"]]
            unfilled = [
                r for r in state["newRequired"]
                if not r["value"] and r["name"] != "CheckoutPaymentMethod[payment_method]"
            ]

            if state["cashChecked"] is not True or state["cardChecked"] is not False:
                print("\n  → STOPPING: Cash is not the single selected method.")
                return 6

            if exposed_card:
                print(f"\n  → STOPPING: card inputs are visible and enabled: {exposed_card}")
                return 6

            if unfilled:
                print(f"\n  → STOPPING: a Cash-specific required field appeared: {unfilled}")
                return 6

            # Identifiers and total, one last time, on the page about to be submitted.
            final_url = urlparse(session.page.url)
            final_q = parse_qs(final_url.query)
            if (
                (final_q.get("order_id") or [""])[0] != args.order_id
                or (final_q.get("customer_id") or [""])[0] != args.customer_id
                or DEMO_PATH_MARKER not in final_url.path
            ):
                print(f"\n  → STOPPING: the page about to be submitted is not the authorised demo "
                      f"order: {final_url.path}")
                return 6

            # Re-read after the gates above, in case anything shifted meanwhile.
            shown_total = _settled_payment_total(session.page, args.expect_total)
            print(f"  Total on this page : {shown_total}")

            if shown_total != args.expect_total:
                print(f"\n  → STOPPING: the payment page states {shown_total}, "
                      f"expected {args.expect_total}.")
                return 6

            print("\n  STEP 2: PASS")

            # ===== STEP 3 — one submission =====================================================
            print("\n" + "=" * 78)
            print("  STEP 3 — SUBMIT, EXACTLY ONCE")
            print("=" * 78)

            # Written BEFORE the click. If the process dies mid-POST, the marker still refuses a
            # second attempt — which is the whole point of writing it first.
            marker.parent.mkdir(parents=True, exist_ok=True)
            marker.write_text(json.dumps({
                "order_id": args.order_id,
                "customer_id": args.customer_id,
                "attempted_at": time.strftime("%Y-%m-%d %H:%M:%S %Z"),
                "total": args.expect_total,
                "payment_method": "cash",
                "outcome": "attempt recorded before the click; outcome unknown at this point",
            }, indent=2))
            marker.chmod(0o600)
            print(f"  Attempt recorded   : {marker.name} (blocks any further attempt)")

            outcome = "UNKNOWN"
            landed = None

            try:
                with session.page.expect_navigation(wait_until="commit", timeout=90000):
                    session.page.locator(SUBMIT).first.click()
                landed = session.page.url
                outcome = "navigated"
                print(f"  Click              : one, and the browser navigated")
            except Exception as failure:  # noqa: BLE001
                landed = session.page.url
                print(f"  Click              : one. No navigation was observed: {failure}")
                print("  NOT retrying. The order may or may not have been placed.")

            try:
                session.page.wait_for_load_state("domcontentloaded", timeout=30000)
            except Exception:
                pass

            session.page.wait_for_timeout(4000)
            landed = session.page.url
            print(f"  Landed on          : {landed}")

            # The application chose this page, so its address is evidence. If it is not an approved
            # route the guard has already recorded a refusal: note it, read nothing from it, and go
            # back to approved pages for verification.
            refused = session.guard.refusal
            if refused:
                print(f"  Guard              : REFUSED that route — {refused}")
                print("  Reading nothing from it. Verifying via approved pages instead.")
                outcome = "UNKNOWN"
                session.guard.refusal = None
            else:
                session.screenshot("phase-b-after-submit", required=False)
                page_msgs = session.page.evaluate(
                    """
                    () => ({
                      errors: Array.from(document.querySelectorAll('.invalid-feedback, .alert-danger'))
                        .map(e => (e.innerText||'').replace(/\\s+/g,' ').trim()).filter(Boolean).slice(0,8),
                      notices: Array.from(document.querySelectorAll('.alert, .alert-success, .flash'))
                        .map(e => (e.innerText||'').replace(/\\s+/g,' ').trim()).filter(Boolean).slice(0,8),
                      title: document.title
                    })
                    """
                )
                print(f"  Page title         : {page_msgs['title']!r}")
                print(f"  Validation errors  : {page_msgs['errors'] or 'none'}")
                print(f"  Application notices: {page_msgs['notices'] or 'none'}")

                if page_msgs["errors"]:
                    outcome = "VALIDATION FAILED"

            # ===== STEP 4 — verification, approved read-only sources only ======================
            print("\n" + "=" * 78)
            print("  STEP 4 — VERIFICATION (approved read-only pages)")
            print("=" * 78)

            try:
                visit(session, pay_url)
                after = _payment_form_report(session.page)
                print(f"\n  Incomplete banner  : {after['banner']!r}")
                print(f"  Order rows AFTER ({len(after['rows'])}):")
                for row in after["rows"]:
                    print(f"    | {row}")

                new_rows = [r for r in after["rows"] if r not in report["rows"]]
                print(f"\n  Rows that are new since before the submission: {new_rows or 'none'}")
            except NavigationRefused as refusal:
                print(f"  Could not re-read the payment stage: {refusal}")
                after = {"banner": "<unread>", "rows": []}

            try:
                visit(session, menu_url)
                final_cart = cart.read(session.page)
                print(f"\n  Cart now           : {final_cart.status.value}, "
                      f"{len(final_cart.lines)} line(s), total {final_cart.total}")
            except NavigationRefused as refusal:
                print(f"  Could not re-read the cart: {refusal}")

            posts = [r for r in session.guard.mutating_requests if "/socket.io/" not in r[1]]
            print(f"\n  Non-GET requests made by this run:")
            for method, path in posts or []:
                print(f"    - {method} {path}")
            if not posts:
                print("    NONE")

            if outcome == "navigated":
                if after.get("banner") is None:
                    outcome = "SUCCESS (the incomplete banner is gone)"
                else:
                    outcome = "UNKNOWN (navigated, but the order still reports itself incomplete)"

            marker.write_text(json.dumps({
                "order_id": args.order_id,
                "customer_id": args.customer_id,
                "attempted_at": time.strftime("%Y-%m-%d %H:%M:%S %Z"),
                "total": args.expect_total,
                "payment_method": "cash",
                "landed_on": landed,
                "outcome": outcome,
            }, indent=2))
            marker.chmod(0o600)

            print("\n" + "=" * 78)
            print(f"  OUTCOME: {outcome}")
            print("=" * 78)

    return 0


# -------------------------------------------------------------------------------------------- run


def cmd_run(args: argparse.Namespace) -> int:
    """The whole demo order in one command, with the writes behind gates.

    ## Three permissions, not one flag

        run                      preflight. Reads only. Proves the ground is good.
        run --create             create, fill, check out, select Cash, then STOP as `prepared`.
        run --create --submit    all of that, and one submission.
        run --resume             re-verify a prepared order. Reads only.
        run --resume --submit    submit THAT order. Cannot create one.

    `--submit` on its own is refused, and so is `--create --resume`. A single flag that both created
    an order and submitted it would be one typo away from an order nobody wanted.

    ## The journal opens before the browser

    An unresolved run for this source order refuses here, before Chrome starts, so a blocked run
    costs nothing and touches nothing.
    """
    logging_setup.configure(LOG_DIR, verbose=args.verbose)

    if args.submit and not (args.create or args.resume):
        raise SystemExit(
            "Refusing: --submit needs --create (make and submit a new order) or --resume "
            "(submit an order a previous run prepared). It does nothing on its own."
        )

    if args.create and args.resume:
        raise SystemExit("Refusing: --create and --resume are contradictory. Choose one.")

    try:
        target = workflow.resolve_target(args.url)
    except UnknownSourceOrder as refused:
        raise SystemExit(f"Refusing: {refused}") from None

    if args.resume:
        gate = workflow.Gate.RESUME_SUBMIT if args.submit else workflow.Gate.RESUME_VERIFY
    elif args.create:
        gate = workflow.Gate.CREATE_AND_SUBMIT if args.submit else workflow.Gate.CREATE
    else:
        gate = workflow.Gate.PREFLIGHT

    print(f"\n  Source order : {target.segment}  (approved, host {target.source.host})")
    print(f"  Gate         : {gate.value}")
    print(f"  Product      : {args.product!r} x {args.qty}")
    print(f"  Writes       : {'ALLOWED' if gate.may_create or gate.may_submit else 'NONE'}")

    # --- the journal, before anything else -------------------------------------------------------
    if gate.is_resume:
        candidates = journal_mod.resumable(RUNS_DIR, target.segment)

        if not candidates:
            raise SystemExit(
                "Nothing to resume: no prepared order exists for this source. "
                "Run `run --create` first, or inspect with `journal`."
            )

        if len(candidates) > 1:
            listing = "\n".join(f"    {path.name}  order_id={data.get('order_id')}"
                                 for path, data in candidates)
            raise SystemExit(
                f"Refusing: {len(candidates)} prepared orders exist for this source. Resolve or "
                f"submit them one at a time rather than guessing which you meant:\n{listing}"
            )

        path, data = candidates[0]
        journal = journal_mod.reopen(path)
        target = target.with_ids(data.get("order_id"), data.get("customer_id"))
        print(f"  Resuming     : {path.name}  order_id={target.order_id}")
    elif gate is workflow.Gate.PREFLIGHT:
        # A read-only run records nothing: there is nothing to recover from a run that cannot write.
        journal = journal_mod.RunJournal(path=RUNS_DIR / "preflight-not-recorded.json", data={})
        journal.record = lambda **kwargs: None  # type: ignore[assignment]
        journal.record_ids = lambda *a, **k: None  # type: ignore[assignment]
        journal.mark_write_attempted = lambda what: None  # type: ignore[assignment]
        journal.close = lambda status, detail="": None  # type: ignore[assignment]
    else:
        try:
            journal = journal_mod.open_run(
                RUNS_DIR,
                target.segment,
                gate=gate.value,
                product=args.product,
                qty=args.qty,
                customer_name=args.customer_name,
            )
        except journal_mod.RunBlocked as blocked:
            raise SystemExit(f"\n{blocked}") from None

    # --- locks: the source order for the whole run, the demo order once it exists ----------------
    held_order_lock: list = []

    def take_order_lock(order_id: str, _customer_id: str) -> None:
        """Acquire the per-order lock the moment the application issues the id."""
        manager = lockfile.for_order(LOCK_DIR, order_id)
        manager.__enter__()
        held_order_lock.append(manager)

    result = None

    try:
        with lockfile.for_order(LOCK_DIR, f"source-{target.segment}"):
            with browser_mod.launch(
                headless=args.headless,
                mode=Mode.AUTOMATED,
                session_file=SESSION_FILE,
                require_session=True,
                screenshot_dir=SCREENSHOT_DIR,
            ) as session:
                session.page.set_default_timeout(90000)
                # Narrowed to this one source order's pages.
                session.guard.allowed_paths = target.allowed_paths

                if gate.is_resume and target.order_id:
                    take_order_lock(target.order_id, target.customer_id or "")

                try:
                    result = workflow.run_workflow(
                        session,
                        target=target,
                        product=args.product,
                        qty=args.qty,
                        customer_name=args.customer_name,
                        gate=gate,
                        journal=journal,
                        state_dir=LOCK_DIR.parent,
                        expect_total=args.expect_total,
                        on_order_created=None if gate.is_resume else take_order_lock,
                    )
                finally:
                    for manager in held_order_lock:
                        manager.__exit__(None, None, None)
    finally:
        _close_journal(journal, result, gate)

    print(result.describe() if result is not None else "\n  No result: the run stopped early.")

    if result is None:
        return 1

    return {
        workflow.Outcome.PREFLIGHT_OK: 0,
        workflow.Outcome.PREPARED: 0,
        workflow.Outcome.SUCCESS: 0,
        workflow.Outcome.VALIDATION_FAILED: 8,
        workflow.Outcome.UNKNOWN: 7,
        workflow.Outcome.ABORTED: 6,
    }[result.outcome]


def _close_journal(journal, result, gate) -> None:  # noqa: ANN001
    """File the run honestly, including when an exception got us here.

    The rule that matters: a run that touched the server is never filed as one that did not. If no
    terminal outcome was reached and a write had been attempted, this is `unknown` — which blocks the
    next creation and is never retried.
    """
    if gate is workflow.Gate.PREFLIGHT:
        return

    if result is None:
        journal.close(
            journal_mod.UNKNOWN if journal.wrote else journal_mod.ABORTED_NO_WRITE,
            detail="the run ended without producing a result",
        )

        return

    status = {
        workflow.Outcome.SUCCESS: journal_mod.COMPLETED,
        workflow.Outcome.PREPARED: journal_mod.PREPARED,
        workflow.Outcome.VALIDATION_FAILED: journal_mod.VALIDATION_FAILED,
        workflow.Outcome.UNKNOWN: journal_mod.UNKNOWN,
        workflow.Outcome.ABORTED: (
            journal_mod.ABORTED_AFTER_WRITE if journal.wrote else journal_mod.ABORTED_NO_WRITE
        ),
        workflow.Outcome.PREFLIGHT_OK: journal_mod.ABORTED_NO_WRITE,
    }[result.outcome]

    journal.close(status, detail=result.detail)


# ---------------------------------------------------------------------------------------- journal


def cmd_journal(args: argparse.Namespace) -> int:
    """Inspect run journals, and resolve a blocking one. Opens no browser, touches no order."""
    logging_setup.configure(LOG_DIR, verbose=args.verbose)

    if args.resolve:
        path = Path(args.resolve)

        if not path.is_absolute():
            path = RUNS_DIR / path

        if not path.exists():
            raise SystemExit(f"No such journal: {path}")

        if not args.reason or not args.evidence:
            raise SystemExit(
                "Resolving a run needs BOTH --reason (why you are clearing it) and --evidence "
                "(what you actually checked, and what it showed). A resolution with neither is "
                "indistinguishable from deleting the file."
            )

        try:
            data = journal_mod.resolve(path, reason=args.reason, evidence=args.evidence)
        except ValueError as refused:
            raise SystemExit(f"Refusing: {refused}") from None

        print(f"\n  Resolved {path.name}: {data['resolved_from']} -> {data['status']}")
        print(f"    reason   : {data['resolution_reason']}")
        print(f"    evidence : {data['resolution_evidence']}")
        print("\n  NOTE: this cleared a journal. It did NOT change any order, and it did NOT remove")
        print("        any submission marker.")

        return 0

    entries = journal_mod.read_all(RUNS_DIR)

    if args.show:
        wanted = [e for e in entries if e[0].name == args.show or str(e[0]) == args.show]

        if not wanted:
            raise SystemExit(f"No such journal: {args.show}")

        for path, data in wanted:
            print(journal_mod.RunJournal(path=path, data=data).describe())

        return 0

    print(f"\n  Run journals in {RUNS_DIR} ({len(entries)}):")

    if not entries:
        print("    <none — no run that can write has been started yet>")

        return 0

    for path, data in entries:
        status = data.get("status", "?")
        blocking = " [BLOCKS NEW CREATION]" if status in journal_mod.BLOCKING else ""
        print(f"\n    {path.name}")
        print(f"      status     : {status}{blocking}")
        print(f"      order_id   : {data.get('order_id')}   total: {data.get('total')}")
        print(f"      gate       : {data.get('gate')}   opened: {data.get('opened_at')}")

        if data.get("detail"):
            print(f"      detail     : {data['detail']}")

    print("\n  Resolve a blocking journal (records who decided and on what evidence):")
    print('    python create_demo_order.py journal --resolve <file> --reason "..." '
          '--evidence "..."')

    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="create_demo_order.py",
        description="Create Order58 demo orders through the admin panel. Dry run by default.",
    )
    parser.add_argument("--verbose", action="store_true", help="Log every step.")

    sub = parser.add_subparsers(dest="command", required=True)

    p_inspect = sub.add_parser("inspect", help="Open a page and report what is on it. Changes nothing.")
    p_inspect.add_argument("--url", default=DEFAULT_URL)
    p_inspect.add_argument("--headless", type=_bool, nargs="?", const=True, default=False)
    p_inspect.add_argument("--json", action="store_true")
    p_inspect.set_defaults(func=cmd_inspect)

    p_login = sub.add_parser("login", help="Sign in by hand once and save the session.")
    p_login.add_argument("--url", default=DEFAULT_URL)
    p_login.add_argument("--wait", type=int, default=420, help="Seconds to wait for you (default 420).")
    p_login.set_defaults(func=cmd_login)

    p_step3 = sub.add_parser("step3", help="Create ONE incomplete demo order and read the menu.")
    p_step3.add_argument("--url", default=DEFAULT_URL)
    p_step3.add_argument("--customer-name", default="Jignesh")
    p_step3.add_argument("--product", default="Spring Roll")
    p_step3.add_argument("--headless", type=_bool, nargs="?", const=True, default=True)
    p_step3.set_defaults(func=cmd_step3)

    p_step4 = sub.add_parser("step4", help="Open a product popup on an EXISTING order. Adds nothing.")
    p_step4.add_argument("--order-id", required=True)
    p_step4.add_argument("--customer-id", required=True)
    p_step4.add_argument("--product", default="Spring Roll")
    p_step4.add_argument("--headless", type=_bool, nargs="?", const=True, default=True)
    p_step4.set_defaults(func=cmd_step4)

    p_step5 = sub.add_parser(
        "step5",
        help="Add ONE product to an EXISTING demo order's cart and verify. No checkout.",
    )
    p_step5.add_argument("--source-order", required=True)
    p_step5.add_argument("--order-id", required=True)
    p_step5.add_argument("--customer-id", required=True)
    p_step5.add_argument("--product", default="Spring Roll")
    p_step5.add_argument("--qty", type=int, default=1)
    p_step5.add_argument("--headless", type=_bool, nargs="?", const=True, default=True)
    p_step5.set_defaults(func=cmd_step5)

    p_step6 = sub.add_parser(
        "step6",
        help="Open the first checkout screen and read it. Fills nothing, submits nothing.",
    )
    p_step6.add_argument("--source-order", required=True)
    p_step6.add_argument("--order-id", required=True)
    p_step6.add_argument("--customer-id", required=True)
    p_step6.add_argument("--product", default="Spring Roll")
    p_step6.add_argument("--qty", type=int, default=1)
    p_step6.add_argument("--expect-total", required=True,
                         help="The total the cart must show before checkout is opened, e.g. $2.04")
    p_step6.add_argument("--headless", type=_bool, nargs="?", const=True, default=True)
    p_step6.set_defaults(func=cmd_step6)

    p_step6b = sub.add_parser(
        "step6b", help="Read the approved checkout-info screen. Fills nothing, submits nothing.",
    )
    p_step6b.add_argument("--url", default=DEFAULT_URL)
    p_step6b.add_argument("--source-order", required=True)
    p_step6b.add_argument("--order-id", required=True)
    p_step6b.add_argument("--customer-id", required=True)
    p_step6b.add_argument("--expect-total", required=True)
    p_step6b.add_argument("--headless", type=_bool, nargs="?", const=True, default=True)
    p_step6b.set_defaults(func=cmd_step6b)

    p_phase_a = sub.add_parser(
        "phase-a", help="Inspect the Shipping and Payment stages. Reads only.",
    )
    p_phase_a.add_argument("--source-order", required=True)
    p_phase_a.add_argument("--order-id", required=True)
    p_phase_a.add_argument("--customer-id", required=True)
    p_phase_a.add_argument("--product", default="Spring Roll")
    p_phase_a.add_argument("--qty", type=int, default=1)
    p_phase_a.add_argument("--expect-total", required=True)
    p_phase_a.add_argument("--headless", type=_bool, nargs="?", const=True, default=True)
    p_phase_a.set_defaults(func=cmd_phase_a)

    p_timing = sub.add_parser(
        "timing", help="Submit the Timing & Info stage once. Does not touch payment.",
    )
    p_timing.add_argument("--source-order", required=True)
    p_timing.add_argument("--order-id", required=True)
    p_timing.add_argument("--customer-id", required=True)
    p_timing.add_argument("--product", default="Spring Roll")
    p_timing.add_argument("--qty", type=int, default=1)
    p_timing.add_argument("--expect-total", required=True)
    p_timing.add_argument("--headless", type=_bool, nargs="?", const=True, default=True)
    p_timing.set_defaults(func=cmd_timing)

    p_pb = sub.add_parser(
        "phase-b", help="Final demo submission. Reads and reports unless --submit is given.",
    )
    p_pb.add_argument("--source-order", required=True)
    p_pb.add_argument("--order-id", required=True)
    p_pb.add_argument("--customer-id", required=True)
    p_pb.add_argument("--product", default="Spring Roll")
    p_pb.add_argument("--qty", type=int, default=1)
    p_pb.add_argument("--expect-subtotal", required=True)
    p_pb.add_argument("--expect-tax", required=True)
    p_pb.add_argument("--expect-total", required=True)
    p_pb.add_argument("--submit", action="store_true",
                      help="Actually select Cash and submit, once. Omit for a dry run.")
    p_pb.add_argument("--headless", type=_bool, nargs="?", const=True, default=True)
    p_pb.set_defaults(func=cmd_phase_b)

    p_run = sub.add_parser(
        "run", help="The whole workflow. Preflight unless --create; submits only with --submit.",
    )
    p_run.add_argument("--url", default=DEFAULT_URL)
    p_run.add_argument("--product", default="Spring Roll")
    p_run.add_argument("--qty", type=int, default=1)
    p_run.add_argument("--customer-name", default="Jignesh")
    p_run.add_argument("--expect-total", default=None,
                       help="Optional. If given, the cart total must match it exactly.")
    p_run.add_argument("--headless", type=_bool, nargs="?", const=True, default=False)
    p_run.add_argument("--create", action="store_true",
                       help="Allow creating ONE demo order. Required before --submit.")
    p_run.add_argument("--resume", action="store_true",
                       help="Act on the order a previous --create prepared. Cannot create one.")
    p_run.add_argument("--submit", action="store_true",
                       help="Submit once. Needs --create or --resume. Off by default.")
    p_run.set_defaults(func=cmd_run)

    p_journal = sub.add_parser(
        "journal", help="Inspect run journals, or resolve a blocking one. Opens no browser.",
    )
    p_journal.add_argument("--show", default=None, help="Full detail of one journal file.")
    p_journal.add_argument("--resolve", default=None, help="Clear a blocking journal.")
    p_journal.add_argument("--reason", default=None, help="Why it is being cleared.")
    p_journal.add_argument("--evidence", default=None,
                           help="What you checked, and what it showed.")
    p_journal.set_defaults(func=cmd_journal)

    args = parser.parse_args(argv)

    try:
        return int(args.func(args))
    except lockfile.AlreadyRunning as running:
        print(f"\n  {running}\n", file=sys.stderr)
        return 9
    except NavigationRefused as refused:
        print(f"\n  {refused}\n", file=sys.stderr)
        return 3
    except browser_mod.SessionMissing as missing:
        print(f"\n  {missing}\n", file=sys.stderr)
        return 2
    except KeyboardInterrupt:
        print("\n  Stopped.", file=sys.stderr)
        return 130


if __name__ == "__main__":
    raise SystemExit(main())
