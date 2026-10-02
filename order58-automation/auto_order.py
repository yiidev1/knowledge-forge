#!/usr/bin/env python3
"""
================================================================================================
 ORDER58 DEMO ORDER — ONE-CLICK LAUNCHER
================================================================================================

 *** THIS FILE CREATES AND SUBMITS A REAL DEMO ORDER WHEN YOU RUN IT. ***

 Pressing Run in your IDE is the authorisation. There is no confirmation prompt, because the whole
 point of this file is that running it completes an order. Four things are written to the live
 Order58 admin panel, in this order:

     1. a new incomplete demo order          (Continue)
     2. one cart line                        (Add to Cart)
     3. the Timing & Info stage              (POST)
     4. the payment                          (POST, Cash)

 None of them can be undone by this tool. If you only want to look, run this instead, which writes
 nothing at all:

     ./venv/bin/python create_demo_order.py run

 DEMO ORDERS ONLY. The host, the source order id and the customer phone are all checked against a
 hardcoded approved list before a browser opens. A live-order route cannot be reached from here.

================================================================================================

 HOW TO RUN IT

   From an IDE:   open this file and press Run. The interpreter must be this project's virtual
                  environment — `order58-automation/venv/bin/python` — because Playwright is
                  installed there and nowhere else. In VS Code: Ctrl+Shift+P, "Python: Select
                  Interpreter", choose `./order58-automation/venv/bin/python`. In PyCharm:
                  Settings, Project, Python Interpreter, add that path as an existing environment.

   From a shell:  cd /var/www/html/knowledge-forge/order58-automation
                  ./venv/bin/python auto_order.py

 PREREQUISITE — a saved sign-in. This file never asks for a password and never stores one. Sign in
 by hand once:

     ./venv/bin/python create_demo_order.py login

================================================================================================
"""

from __future__ import annotations

# ================================================================================================
#  CONFIGURATION — edit these five lines, nothing else in this file
# ================================================================================================

DEMO_URL = "https://joymeal.order58.com/admin/demo/order/make/16655531-15163932150"
CUSTOMER_NAME = "Jignesh"
PRODUCT = "Spring Roll"
QUANTITY = 1
HEADLESS = False

# ================================================================================================
#  Nothing below here needs editing.
# ================================================================================================

import argparse  # noqa: E402
import sys  # noqa: E402
from pathlib import Path  # noqa: E402

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import create_demo_order  # noqa: E402

BANNER = r"""
  ============================================================================
   ORDER58 DEMO ORDER — THIS WILL CREATE AND SUBMIT ONE ORDER
  ============================================================================
   Demo URL  : {url}
   Customer  : {customer}
   Product   : {product} x {qty}
   Dining    : Take-Out        Payment: Cash
   Browser   : {mode}
  ----------------------------------------------------------------------------
   Four writes will be made to the live admin panel and none can be undone
   by this tool. To look without writing anything, run instead:
       ./venv/bin/python create_demo_order.py run
  ============================================================================
"""


def build_args() -> argparse.Namespace:
    """The configuration above, as the arguments `run --create --submit` expects.

    The launcher does not reimplement anything. It assembles the same `Namespace` the command line
    would produce and hands it to {@see create_demo_order.cmd_run}, so every guard, gate, lock,
    journal entry, audit and marker applies exactly as it does from a terminal. No subprocess, no
    shell, no second copy of the workflow.
    """
    return argparse.Namespace(
        command="run",
        verbose=False,
        url=DEMO_URL,
        product=PRODUCT,
        qty=QUANTITY,
        customer_name=CUSTOMER_NAME,
        expect_total=None,
        headless=bool(HEADLESS),
        # This is what makes the launcher a launcher: both write gates, because running the file IS
        # the request to create and submit one demo order.
        create=True,
        resume=False,
        submit=True,
        func=create_demo_order.cmd_run,
    )


def main() -> int:
    print(
        BANNER.format(
            url=DEMO_URL,
            customer=CUSTOMER_NAME,
            product=PRODUCT,
            qty=QUANTITY,
            mode="visible" if HEADLESS is False else "headless",
        )
    )

    if not isinstance(QUANTITY, int) or QUANTITY < 1:
        print(f"  Refusing: QUANTITY must be a whole number of at least 1, not {QUANTITY!r}.")

        return 2

    if not str(PRODUCT).strip():
        print("  Refusing: PRODUCT is empty. The product name must match a menu item exactly.")

        return 2

    try:
        # `cmd_run` raises SystemExit for every refusal it makes before a browser opens: an
        # unapproved demo URL, a blocking journal, a missing sign-in. Those messages are already
        # written for a person to read, so they are shown rather than reinterpreted here.
        return create_demo_order.cmd_run(build_args())
    except SystemExit as refused:
        message = str(refused)

        if message and message not in ("0", "None"):
            print(f"\n  STOPPED.\n\n{message}\n")

        return int(refused.code) if isinstance(refused.code, int) else 1
    except KeyboardInterrupt:
        print(
            "\n  INTERRUPTED. If this happened during or just after a write, the outcome is "
            "UNKNOWN.\n"
            "  Do NOT re-run this launcher. Inspect first:\n"
            "      ./venv/bin/python create_demo_order.py journal\n"
        )

        return 130


if __name__ == "__main__":
    code = main()

    # 0 success or prepared · 6 aborted by a guard · 7 UNKNOWN · 8 rejected by the application
    print(f"\n  exit code {code}")

    if code == 7:
        print(
            "  UNKNOWN means the result could not be proven, NOT that it failed. The order may\n"
            "  well have been placed. Do not re-run. Verify with:\n"
            "      ./venv/bin/python create_demo_order.py journal\n"
        )

    sys.exit(code)
