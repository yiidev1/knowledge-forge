"""The route guard, including the refusals that look careful and are not.

The most important test in this file is {@see AllowlistIsUnchanged}: the seven paths were written out
by hand and are now derived, and "derived" is only acceptable if the result is identical. Everything
else here is an attempt to get the guard to admit something it should not.
"""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from order58 import guard  # noqa: E402
from order58.guard import (  # noqa: E402
    ALLOWED_HOST,
    APPROVED_DEMO_SOURCES,
    ApprovedDemoSource,
    Guard,
    Mode,
    UnknownSourceOrder,
    approved_source,
    paths_for,
)

SEGMENT = "16655531-15163932150"


class AllowlistIsUnchanged(unittest.TestCase):
    """The derived set must equal what was hardcoded before the refactor, exactly."""

    #: Copied from the version of guard.py that held them as literals.
    ORIGINAL = frozenset(
        {
            "/admin/demo/order/make/16655531-15163932150",
            "/admin/site/login",
            "/admin/demo/product/menu/16655531-15163932150",
            "/admin/demo/order/checkout-customer/16655531-15163932150",
            "/admin/demo/order/checkout-info/16655531-15163932150",
            "/admin/demo/order/checkout-shipping/16655531-15163932150",
            "/admin/demo/order/checkout-payment/16655531-15163932150",
        }
    )

    def test_derived_set_is_byte_identical(self) -> None:
        self.assertEqual(guard.ALLOWED_PATHS, self.ORIGINAL)
        self.assertEqual(len(guard.ALLOWED_PATHS), 7)

    def test_only_one_source_is_approved(self) -> None:
        """A silently widened allowlist is the failure this whole design exists to prevent."""
        self.assertEqual(len(APPROVED_DEMO_SOURCES), 1)

    def test_every_derived_demo_path_is_a_demo_route(self) -> None:
        for path in guard.ALLOWED_PATHS - {guard.LOGIN_PATH}:
            self.assertTrue(path.startswith("/admin/demo/"), path)


class ApprovedTupleValidation(unittest.TestCase):
    """All three parts must match — not just the source order id."""

    def test_the_approved_tuple_resolves(self) -> None:
        source = approved_source(ALLOWED_HOST, "16655531", "15163932150")
        self.assertEqual(source.segment, SEGMENT)

    def test_right_order_wrong_phone_is_refused(self) -> None:
        with self.assertRaises(UnknownSourceOrder):
            approved_source(ALLOWED_HOST, "16655531", "19998887777")

    def test_right_tuple_wrong_host_is_refused(self) -> None:
        with self.assertRaises(UnknownSourceOrder):
            approved_source("other.order58.com", "16655531", "15163932150")

    def test_unknown_order_is_refused(self) -> None:
        with self.assertRaises(UnknownSourceOrder):
            approved_source(ALLOWED_HOST, "99999999", "15163932150")

    def test_the_refusal_does_not_print_the_phone_number(self) -> None:
        """A refusal is logged; a customer's number should not be."""
        with self.assertRaises(UnknownSourceOrder) as caught:
            approved_source(ALLOWED_HOST, "99999999", "15163932150")

        self.assertNotIn("15163932150", str(caught.exception))

    def test_paths_for_an_unapproved_source_is_refused(self) -> None:
        rogue = ApprovedDemoSource(host=ALLOWED_HOST, source_order_id="1", customer_phone="2")

        with self.assertRaises(UnknownSourceOrder):
            paths_for(rogue)


class NavigationRefusals(unittest.TestCase):
    """Attempts to get the guard to admit something."""

    def setUp(self) -> None:
        self.guard = Guard(mode=Mode.AUTOMATED)

    def _refused(self, url: str) -> None:
        allowed, why = self.guard.allows_navigation(url)
        self.assertFalse(allowed, f"{url} should have been refused")
        self.assertTrue(why)

    def test_the_approved_make_page_is_allowed(self) -> None:
        allowed, _ = self.guard.allows_navigation(
            f"https://{ALLOWED_HOST}/admin/demo/order/make/{SEGMENT}"
        )
        self.assertTrue(allowed)

    def test_query_parameters_are_ignored(self) -> None:
        allowed, _ = self.guard.allows_navigation(
            f"https://{ALLOWED_HOST}/admin/demo/product/menu/{SEGMENT}"
            "?order_id=1&customer_id=2"
        )
        self.assertTrue(allowed)

    def test_a_lookalike_host_is_refused(self) -> None:
        """`"order58.com" in url` would have accepted this."""
        self._refused(f"https://{ALLOWED_HOST}.evil.example/admin/demo/order/make/{SEGMENT}")

    def test_a_subdomain_of_the_real_host_is_refused(self) -> None:
        self._refused(f"https://evil.{ALLOWED_HOST}/admin/demo/order/make/{SEGMENT}")

    def test_http_is_refused(self) -> None:
        self._refused(f"http://{ALLOWED_HOST}/admin/demo/order/make/{SEGMENT}")

    def test_a_live_order_route_is_refused(self) -> None:
        self._refused(f"https://{ALLOWED_HOST}/admin/order/make/{SEGMENT}")

    def test_a_path_that_merely_contains_an_approved_one_is_refused(self) -> None:
        """`"/admin/demo/" in path` would have accepted this."""
        self._refused(
            f"https://{ALLOWED_HOST}/admin/live/order/make?x=/admin/demo/order/make/{SEGMENT}"
        )

    def test_a_longer_path_under_an_approved_one_is_refused(self) -> None:
        self._refused(f"https://{ALLOWED_HOST}/admin/demo/order/make/{SEGMENT}/extra")

    def test_the_completion_route_is_refused(self) -> None:
        """Deliberately not allowlisted, which is why a real submission ends as UNKNOWN."""
        self._refused(f"https://{ALLOWED_HOST}/admin/demo/order/checkout-completed?id=16630311")

    def test_the_order_view_route_is_refused(self) -> None:
        self._refused(f"https://{ALLOWED_HOST}/admin/demo/order/view?id=16630311")

    def test_an_unapproved_source_order_is_refused(self) -> None:
        self._refused(f"https://{ALLOWED_HOST}/admin/demo/order/make/99999999-88888888")

    def test_a_trailing_slash_is_tolerated(self) -> None:
        allowed, _ = self.guard.allows_navigation(
            f"https://{ALLOWED_HOST}/admin/demo/order/make/{SEGMENT}/"
        )
        self.assertTrue(allowed)


class NarrowedGuard(unittest.TestCase):
    """A guard may be narrowed to one source order. It can never be widened."""

    def test_narrowing_to_one_source_still_admits_its_pages(self) -> None:
        source = approved_source(ALLOWED_HOST, "16655531", "15163932150")
        narrow = Guard(mode=Mode.AUTOMATED, allowed_paths=paths_for(source))

        allowed, _ = narrow.allows_navigation(
            f"https://{ALLOWED_HOST}/admin/demo/order/checkout-payment/{SEGMENT}"
        )
        self.assertTrue(allowed)

    def test_an_empty_allowlist_admits_nothing(self) -> None:
        sealed = Guard(mode=Mode.AUTOMATED, allowed_paths=frozenset())

        allowed, _ = sealed.allows_navigation(
            f"https://{ALLOWED_HOST}/admin/demo/order/make/{SEGMENT}"
        )
        self.assertFalse(allowed)

    def test_login_mode_is_host_scoped(self) -> None:
        """A person is driving; signing in follows redirects nobody can predict."""
        login = Guard(mode=Mode.LOGIN)

        allowed, _ = login.allows_navigation(f"https://{ALLOWED_HOST}/anything/at/all")
        self.assertTrue(allowed)

        refused, _ = login.allows_navigation("https://elsewhere.example/")
        self.assertFalse(refused)


if __name__ == "__main__":
    unittest.main(verbosity=2)
