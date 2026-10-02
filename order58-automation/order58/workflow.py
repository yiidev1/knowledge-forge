"""The whole demo order, in one pass, with the writes behind gates.

## Reuse, not reimplementation

Every page interaction here comes from a module that was built and verified one step at a time:
{@see order58.order_form} for the reservation, {@see order58.product_menu} for the typeahead,
{@see order58.product_popup} for the modal, {@see order58.cart} for the cart,
{@see order58.payment} for the payment stage. This module decides the ORDER of those steps and what
must be true between them. It contains no new selector and no new browser technique.

## Gates are structural, not conditional

A gate is not an `if` around a click. {@see Gate} is passed in, and a stage that writes calls
{@see _require} first, which raises `PermissionError`. Under `Gate.PREFLIGHT` the create stage is
never entered at all, so `order_form.continue_once` is not reached by any path — which is what the
tests assert, rather than asserting that a flag was false.

Five gates, because "may create an order" and "may submit an order" are different permissions and a
single flag for both is one typo away from an order nobody wanted:

    PREFLIGHT          read only; proves the ground is good, writes nothing
    CREATE             create, fill, check out, select Cash, then STOP as `prepared`
    CREATE_AND_SUBMIT  all of the above and one submission
    RESUME_VERIFY      re-check an already-prepared order, read only
    RESUME_SUBMIT      submit an already-prepared order; CANNOT create one

## Writes are clicked once and never repeated

Four stages write: Continue, Add to Cart, the Timing & Info POST, the payment POST. Each is attempted
exactly once. An outcome that cannot be established is {@see Outcome.UNKNOWN}, which is reported and
never retried — a retry on an unclear write is how one order becomes two.

Before each write the journal records that a write is about to be attempted, durably. An attempt that
vanishes into a crash still leaves a record saying so.
"""

from __future__ import annotations

import logging
import re
from dataclasses import dataclass, field
from enum import Enum
from urllib.parse import parse_qs, urlparse

from order58 import audit, cart, checkout, order_form, payment, product_menu, product_popup
from order58 import journal as journal_mod
from order58.guard import (
    ALLOWED_HOST,
    ApprovedDemoSource,
    NavigationRefused,
    UnknownSourceOrder,
    approved_source,
    paths_for,
)

LOGGER = logging.getLogger("order58")

_MAKE_PATH = re.compile(r"^/admin/demo/order/make/(\d+)-(\d+)$")

#: Where the checkout stages live, relative to the approved segment.
_CHECKOUT_STAGES = ("customer", "info", "shipping", "payment")

CASH_RADIO = "input[name='CheckoutPaymentMethod[payment_method]'][value='cash']"
CARD_RADIO = "input[name='CheckoutPaymentMethod[payment_method]'][value='credit card']"
SUBMIT_BUTTON = "#checkout-payment-form button[type=submit].submit-btn"
DINING_FORM_SUBMIT = "#checkout-dining-form button[type=submit]"
DEMO_PATH_MARKER = "/admin/demo/"


class Gate(Enum):
    """What this run is permitted to do."""

    PREFLIGHT = "preflight"
    CREATE = "create"
    CREATE_AND_SUBMIT = "create+submit"
    RESUME_VERIFY = "resume-verify"
    RESUME_SUBMIT = "resume+submit"

    @property
    def may_create(self) -> bool:
        return self in (Gate.CREATE, Gate.CREATE_AND_SUBMIT)

    @property
    def may_submit(self) -> bool:
        return self in (Gate.CREATE_AND_SUBMIT, Gate.RESUME_SUBMIT)

    @property
    def is_resume(self) -> bool:
        return self in (Gate.RESUME_VERIFY, Gate.RESUME_SUBMIT)


class Outcome(Enum):
    """How a run ended."""

    PREFLIGHT_OK = "PREFLIGHT OK"
    PREPARED = "PREPARED"
    SUCCESS = "SUCCESS"
    VALIDATION_FAILED = "FAILED (validation)"
    UNKNOWN = "UNKNOWN"
    ABORTED = "ABORTED"


@dataclass(frozen=True)
class DemoTarget:
    """One approved demo order, and the only URLs this run may use."""

    source: ApprovedDemoSource
    order_id: str | None = None
    customer_id: str | None = None

    @property
    def segment(self) -> str:
        return self.source.segment

    @property
    def allowed_paths(self) -> frozenset[str]:
        """Narrowed to this source order alone."""
        return paths_for(self.source)

    def _query(self) -> str:
        if not self.order_id or not self.customer_id:
            return ""

        return f"?order_id={self.order_id}&customer_id={self.customer_id}"

    @property
    def make_url(self) -> str:
        return f"https://{self.source.host}/admin/demo/order/make/{self.segment}"

    @property
    def menu_url(self) -> str:
        return (
            f"https://{self.source.host}/admin/demo/product/menu/{self.segment}{self._query()}"
        )

    def checkout_url(self, stage: str) -> str:
        if stage not in _CHECKOUT_STAGES:
            raise ValueError(f"{stage!r} is not a checkout stage this tool knows.")

        return (
            f"https://{self.source.host}/admin/demo/order/checkout-{stage}/"
            f"{self.segment}{self._query()}"
        )

    def with_ids(self, order_id: str | None, customer_id: str | None) -> "DemoTarget":
        return DemoTarget(source=self.source, order_id=order_id, customer_id=customer_id)


@dataclass
class WorkflowResult:
    """What happened, in enough detail to report without re-reading anything."""

    outcome: Outcome
    target: DemoTarget
    stages: list[tuple[str, bool, str]] = field(default_factory=list)
    subtotal: str | None = None
    tax: str | None = None
    total: str | None = None
    landed_on: str | None = None
    audit_report: audit.AuditReport | None = None
    writes: list[str] = field(default_factory=list)
    detail: str = ""

    @property
    def order_id(self) -> str | None:
        return self.target.order_id

    @property
    def customer_id(self) -> str | None:
        return self.target.customer_id

    def stage(self, name: str, ok: bool, detail: str = "") -> None:
        self.stages.append((name, ok, detail))
        LOGGER.info("Stage %s: %s %s", name, "OK" if ok else "STOPPED", detail)

    def describe(self) -> str:
        lines = ["", "  " + "=" * 74, "  WORKFLOW RESULT", "  " + "=" * 74]

        for name, ok, detail in self.stages:
            lines.append(f"    {'[ok]' if ok else '[--]'} {name:34s} {detail}")

        lines += [
            "",
            f"    Order ID    : {self.order_id or '<none created>'}",
            f"    Customer ID : {self.customer_id or '<none>'}",
            f"    Subtotal    : {self.subtotal or '-'}",
            f"    Tax         : {self.tax or '-'}",
            f"    Total       : {self.total or '-'}",
        ]

        if self.landed_on:
            lines.append(f"    Landed on   : {self.landed_on}")

        if self.writes:
            lines.append(f"    Writes made : {', '.join(self.writes)}")
        else:
            lines.append("    Writes made : NONE")

        if self.audit_report is not None:
            lines.append(self.audit_report.describe())

        lines += ["", f"    OUTCOME: {self.outcome.value}"]

        if self.detail:
            lines.append(f"    {self.detail}")

        return "\n".join(lines)


class _Stop(RuntimeError):
    """Stop the run cleanly, carrying the outcome to record."""

    def __init__(self, outcome: Outcome, detail: str) -> None:
        super().__init__(detail)
        self.outcome = outcome
        self.detail = detail


def _require(allowed: bool, what: str) -> None:
    """A write stage may not be entered without its gate."""
    if not allowed:
        raise PermissionError(
            f"{what} requires a gate this run does not hold. Nothing was attempted."
        )


def resolve_target(make_url: str) -> DemoTarget:
    """Validate a demo URL as a whole tuple — host, source order and phone — or refuse.

    Checked before a browser starts. The path must BE the make page for an approved source, not
    merely contain it, and the host must be the one that source was approved for.
    """
    parsed = urlparse(make_url)

    if parsed.scheme != "https":
        raise UnknownSourceOrder(f"Refusing a non-HTTPS demo URL: {make_url}")

    if parsed.hostname != ALLOWED_HOST:
        raise UnknownSourceOrder(
            f"Refusing host {parsed.hostname!r}: this tool only drives {ALLOWED_HOST}."
        )

    match = _MAKE_PATH.match(parsed.path.rstrip("/"))

    if match is None:
        raise UnknownSourceOrder(
            f"{parsed.path!r} is not a demo make page. Expected exactly "
            "/admin/demo/order/make/<sourceOrderId>-<customerPhone>."
        )

    source_order_id, customer_phone = match.groups()

    # Raises UnknownSourceOrder unless all three parts match one approved record.
    source = approved_source(parsed.hostname, source_order_id, customer_phone)

    return DemoTarget(source=source)


def _visit(session, url: str) -> None:  # noqa: ANN001 - browser.Session
    """Commit, then settle. This site has stalled on `domcontentloaded` more than once; committing
    is enough to know where the browser is, and a slow settle must not fail a run."""
    session.page.goto(url, wait_until="commit", timeout=60000)
    session.guard.raise_if_refused()

    try:
        session.page.wait_for_load_state("domcontentloaded", timeout=30000)
    except Exception:  # noqa: BLE001
        pass

    session.page.wait_for_timeout(3000)


def _ids_on_page(session) -> dict:  # noqa: ANN001
    parsed = urlparse(session.page.url)
    query = parse_qs(parsed.query)

    return {
        "segment": parsed.path.rstrip("/").split("/")[-1],
        "order_id": (query.get("order_id") or [""])[0],
        "customer_id": (query.get("customer_id") or [""])[0],
    }


def _checked_addons(page) -> set[str]:  # noqa: ANN001
    """Which add-on inputs are selected right now, by name and value."""
    return set(
        page.evaluate(
            """
            () => Array.from(document.querySelectorAll(
                "#product-detail-modal input[name*='additional_ids'], "
                + "#product-detail-modal input[name*='more_sauce']"))
              .filter(el => el.checked)
              .map(el => `${el.getAttribute('name')}=${el.getAttribute('value')}`)
            """
        )
    )


def _incomplete_banner(page) -> str | None:  # noqa: ANN001
    """The application's own statement about this order, rather than an inference from the cart."""
    return page.evaluate(
        r"""
        () => Array.from(document.querySelectorAll('*'))
          .filter(e => e.children.length <= 3)
          .map(e => (e.innerText || '').replace(/\s+/g, ' ').trim())
          .find(t => /order incomplete/i.test(t) && t.length < 160) || null
        """
    )


def run_workflow(
    session,  # noqa: ANN001 - browser.Session
    *,
    target: DemoTarget,
    product: str,
    qty: int,
    customer_name: str,
    gate: Gate,
    journal: journal_mod.RunJournal,
    state_dir,  # noqa: ANN001 - Path
    expect_total: str | None = None,
    on_order_created=None,  # noqa: ANN001 - callable(order_id, customer_id)
) -> WorkflowResult:
    """Drive the whole demo order, as far as `gate` permits."""
    result = WorkflowResult(outcome=Outcome.ABORTED, target=target)

    try:
        return _run(
            session,
            target=target,
            product=product,
            qty=qty,
            customer_name=customer_name,
            gate=gate,
            journal=journal,
            state_dir=state_dir,
            expect_total=expect_total,
            on_order_created=on_order_created,
            result=result,
        )
    except _Stop as stop:
        result.outcome = stop.outcome
        result.detail = stop.detail

        return result
    except NavigationRefused as refused:
        # The browser ended up somewhere the allowlist does not admit. If a write had already been
        # attempted this is genuinely unresolved, so it is UNKNOWN rather than a clean abort — and it
        # is returned as a result rather than raised, so the caller still files the journal and
        # reports instead of crashing.
        result.outcome = Outcome.UNKNOWN if result.writes else Outcome.ABORTED
        result.detail = (
            f"Navigation was refused ({refused}). "
            + ("A write had already been attempted, so the outcome is unresolved. NOT retrying."
               if result.writes else "Nothing had been written.")
        )

        return result
    except PermissionError as denied:
        # A gate was missing. This is a programming error, not a site problem, and it must never be
        # reported as anything that happened to the order.
        result.outcome = Outcome.ABORTED
        result.detail = f"Refused by a gate: {denied}"

        return result


def _run(  # noqa: C901 - a linear workflow reads better in one piece than split across helpers
    session,  # noqa: ANN001
    *,
    target: DemoTarget,
    product: str,
    qty: int,
    customer_name: str,
    gate: Gate,
    journal: journal_mod.RunJournal,
    state_dir,  # noqa: ANN001
    expect_total: str | None,
    on_order_created,  # noqa: ANN001
    result: WorkflowResult,
) -> WorkflowResult:
    # ===== session ==============================================================================
    if gate.is_resume:
        _visit(session, target.menu_url)
    else:
        _visit(session, target.make_url)

    if "/admin/site/login" in session.page.url:
        raise _Stop(
            Outcome.ABORTED,
            "The saved session has expired. Run `login` again. Nothing was attempted.",
        )

    result.stage("session reused", True, "signed in")

    # ===== preflight: the phone on the page must be the approved one =============================
    if not gate.is_resume:
        phone_on_page = session.page.locator(order_form.PHONE_INPUT).input_value().strip()
        digits = re.sub(r"\D", "", phone_on_page)

        if digits and digits != target.source.customer_phone:
            raise _Stop(
                Outcome.ABORTED,
                "The phone number on the make page is not the one this source order was approved "
                "for. Refusing before any write.",
            )

        result.stage(
            "approved tuple verified",
            True,
            f"host + source order + phone match (phone {'confirmed' if digits else 'empty'})",
        )

        existing = order_form.find_existing_orders(session.page)
        result.stage(
            "pre-existing orders listed",
            True,
            f"{len(existing)} already on the page — none touched",
        )

    if gate is Gate.PREFLIGHT:
        result.stage(
            "preflight complete",
            True,
            "product and totals need --create: the menu and checkout stages are addressed by "
            "order_id, which does not exist until an order is created",
        )
        result.outcome = Outcome.PREFLIGHT_OK

        return result

    # ===== create the order (WRITE) =============================================================
    if gate.is_resume:
        result.stage(
            "resuming a prepared order",
            True,
            f"order {target.order_id} — creation is not reachable on this gate",
        )
    else:
        _require(gate.may_create, "Creating a demo order")

        actions = order_form.prepare(session.page, customer_name=customer_name)
        result.stage("reservation form prepared", True, "; ".join(
            f"{k}: {v}" for k, v in actions.items()
        ))

        journal.mark_write_attempted("continue (create order)")
        result.writes.append("create order")
        session.screenshot("run-before-continue")

        try:
            created = order_form.continue_once(session.page)
        except Exception as failure:  # noqa: BLE001
            raise _Stop(
                Outcome.UNKNOWN,
                f"Continue did not complete cleanly ({failure}). An order may or may not have "
                "been created. NOT retrying.",
            ) from None

        if not created.order_id or not created.customer_id:
            raise _Stop(
                Outcome.UNKNOWN,
                f"Continue landed on {created.url} without issuing both identifiers. An order may "
                "exist. NOT retrying.",
            )

        target = target.with_ids(created.order_id, created.customer_id)
        result.target = target
        journal.record_ids(created.order_id, created.customer_id)

        if on_order_created is not None:
            on_order_created(created.order_id, created.customer_id)

        result.stage("demo order created", True, f"order_id={created.order_id}")

        if not created.is_product_menu():
            raise _Stop(
                Outcome.UNKNOWN,
                f"Continue landed on {created.url}, not the product menu. The order exists. "
                "NOT retrying.",
            )

    # ===== identity, from the application's own response ========================================
    _visit(session, target.menu_url)
    seen = _ids_on_page(session)

    if (
        seen["segment"] != target.segment
        or seen["order_id"] != target.order_id
        or seen["customer_id"] != target.customer_id
    ):
        raise _Stop(
            Outcome.ABORTED,
            f"The page is not the authorised demo order (page says {seen}). Stopping.",
        )

    result.stage("identity verified", True, "segment + order_id + customer_id all match")

    # ===== the product ==========================================================================
    before_cart = cart.read(session.page)

    if gate.is_resume:
        # The order already holds its line; the audit below re-checks it.
        result.stage("cart read for re-verification", True, before_cart.status.value)
    else:
        if not before_cart.is_definitely_empty():
            raise _Stop(
                Outcome.ABORTED,
                f"The cart is {before_cart.status.value!r}, not confirmed empty. Nothing added.",
            )

        search = product_menu.search(session.page, product)

        if not search.exact:
            near = [item.name for item in search.near]
            raise _Stop(
                Outcome.ABORTED,
                f"No product is named exactly {product!r}. "
                + (f"Similar, NOT substituted: {near}. " if near else "")
                + "Stopping rather than ordering something nobody asked for.",
            )

        result.stage(
            "exact product found",
            True,
            f"{search.exact[0].name!r} (code {search.exact[0].code})",
        )

        product_popup.open_for(session.page, product_menu.SUGGESTION)
        popup = product_popup.report_on(session.page)

        if re.sub(r"\s+", " ", popup.product_name).strip().lower() != product.strip().lower():
            raise _Stop(
                Outcome.ABORTED,
                f"The popup shows {popup.product_name!r}, not {product!r}. Nothing added.",
            )

        addons_before = _checked_addons(session.page)
        count_action = product_popup.set_count(session.page, qty)
        addons_after = _checked_addons(session.page)

        result.stage("quantity set", True, count_action)

        addon_audit = audit.addons_unchanged(addons_before, addons_after)

        if not addon_audit.ok:
            raise _Stop(
                Outcome.ABORTED,
                "The add-on selection changed while setting the quantity: "
                f"{addon_audit.failures[0].detail}. Nothing added.",
            )

        result.stage("add-ons untouched", True, addon_audit.findings[0].detail)

        # ===== add to cart (WRITE) ==============================================================
        _require(gate.may_create, "Adding to the cart")
        journal.mark_write_attempted("add to cart")
        result.writes.append("add to cart")
        session.screenshot("run-before-add")

        try:
            session.page.locator(product_popup.ADD_TO_CART).click()
        except Exception as failure:  # noqa: BLE001
            raise _Stop(
                Outcome.UNKNOWN,
                f"The Add to Cart click did not complete cleanly ({failure}). The cart may or may "
                "not have changed. NOT retrying.",
            ) from None

        session.page.wait_for_timeout(3500)
        session.guard.raise_if_refused()
        session.screenshot("run-cart-after", required=False)

    # ===== the audit ============================================================================
    after_cart = cart.read(session.page)
    report = audit.audit_cart(after_cart, product=product, qty=qty)

    result.subtotal = after_cart.subtotal
    result.tax = after_cart.tax
    result.total = after_cart.total
    result.audit_report = report

    journal.record(
        subtotal=after_cart.subtotal,
        tax=after_cart.tax,
        total=after_cart.total,
        charges=report.charges,
    )

    if not report.ok:
        raise _Stop(
            Outcome.ABORTED,
            "The cart audit failed: "
            + "; ".join(finding.detail for finding in report.failures),
        )

    result.stage("cart audit passed", True, f"{len(report.findings)} checks, total {result.total}")

    if expect_total is not None and audit.money(expect_total) != audit.money(after_cart.total):
        raise _Stop(
            Outcome.ABORTED,
            f"The cart total is {after_cart.total}, but {expect_total} was expected.",
        )

    # ===== checkout =============================================================================
    _visit(session, target.checkout_url("customer"))
    result.stage("checkout entered", True, urlparse(session.page.url).path)

    _visit(session, target.checkout_url("info"))

    banner = _incomplete_banner(session.page)

    if banner is None:
        raise _Stop(
            Outcome.ABORTED,
            "The order no longer states that it is incomplete, so it may already be placed. "
            "Stopping rather than submitting again.",
        )

    timing = session.page.evaluate(
        """
        () => {
          const d = document.querySelector('input[name="ReservationForm[dine_type]"]:checked');
          const c = document.querySelector('#reservationform-customer_count');
          const s = document.querySelector('#reservationform-schedule_datetime');
          return {dine: d && d.value, count: c && c.value, schedule: s && s.value};
        }
        """
    )

    if timing["dine"] != "take-out":
        raise _Stop(
            Outcome.ABORTED,
            f"The dining type is {timing['dine']!r}, not take-out. Stopping.",
        )

    result.stage(
        "timing stage read",
        True,
        f"take-out, count {timing['count']}, pickup {timing['schedule']!r} "
        "(empty is the application's automatic setting; schedule_datetime has no required rule)",
    )

    if not gate.is_resume:
        # ===== the Timing & Info POST (WRITE) ===================================================
        _require(gate.may_create, "Submitting the Timing & Info stage")
        journal.mark_write_attempted("timing & info POST")
        result.writes.append("timing POST")
        session.screenshot("run-timing-before")

        try:
            with session.page.expect_navigation(wait_until="commit", timeout=60000):
                session.page.locator(DINING_FORM_SUBMIT).click()
        except Exception as failure:  # noqa: BLE001
            raise _Stop(
                Outcome.UNKNOWN,
                f"The Timing & Info submission gave no clear result ({failure}). The stage may or "
                "may not have been saved. NOT retrying.",
            ) from None

        try:
            session.page.wait_for_load_state("domcontentloaded", timeout=30000)
        except Exception:  # noqa: BLE001
            pass

        session.page.wait_for_timeout(3000)

        try:
            session.guard.raise_if_refused()
        except NavigationRefused as refused:
            raise _Stop(
                Outcome.UNKNOWN,
                f"The Timing & Info submission landed off the approved routes ({refused}).",
            ) from None

        result.stage("timing & info submitted", True, urlparse(session.page.url).path)

    # ===== payment ==============================================================================
    _visit(session, target.checkout_url("payment"))

    form = payment.form_report(session.page)

    if not form.get("formFound") or not form.get("submits"):
        raise _Stop(Outcome.ABORTED, "The payment form or its submit control is not present.")

    session.page.locator(CASH_RADIO).check()

    # Selecting a method re-renders the summary. Read nothing until it settles, or both the total
    # and the radio states come back wrong.
    settled = payment.settled_total(session.page, after_cart.total or "")
    result.stage("payment summary settled", True, f"page states {settled!r}")

    methods = session.page.evaluate(
        """
        () => {
          const g = v => document.querySelector(
            `input[name='CheckoutPaymentMethod[payment_method]'][value='${v}']`);
          const vis = el => { if (!el) return null;
            const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
          return {
            cash: g('cash') ? g('cash').checked : null,
            card: g('credit card') ? g('credit card').checked : null,
            cardInputs: Array.from(document.querySelectorAll(
              "input[name*='credit_card']")).map(el => ({
                name: el.getAttribute('name'), disabled: !!el.disabled, visible: vis(el)}))
          };
        }
        """
    )

    if methods["cash"] is not True or methods["card"] is not False:
        raise _Stop(
            Outcome.ABORTED,
            f"Cash is not the single selected payment method ({methods}). Nothing submitted.",
        )

    exposed = [c for c in methods["cardInputs"] if c["visible"] and not c["disabled"]]

    if exposed:
        raise _Stop(
            Outcome.ABORTED,
            f"Credit card inputs are visible and enabled ({exposed}). Nothing submitted.",
        )

    pay_audit = audit.audit_payment(
        cart_total=after_cart.total,
        page_total=settled,
        form=payment.form_report(session.page),
    )
    report.merge(pay_audit)

    if not pay_audit.ok:
        raise _Stop(
            Outcome.ABORTED,
            "The payment audit failed: "
            + "; ".join(finding.detail for finding in pay_audit.failures),
        )

    result.stage("cash selected, payment audit passed", True, f"{len(pay_audit.findings)} checks")

    final = urlparse(session.page.url)
    final_query = parse_qs(final.query)

    if (
        DEMO_PATH_MARKER not in final.path
        or (final_query.get("order_id") or [""])[0] != target.order_id
    ):
        raise _Stop(
            Outcome.ABORTED,
            f"The page about to be submitted is not the authorised demo order ({final.path}).",
        )

    session.screenshot("run-cash-selected")

    if not gate.may_submit:
        result.outcome = Outcome.PREPARED
        result.detail = (
            "Prepared and NOT submitted. Resume it with:  "
            f"run --resume --submit   (order {target.order_id})"
        )

        return result

    # ===== submit (WRITE), exactly once =========================================================
    _require(gate.may_submit, "Submitting the order")

    marker = journal_mod.attempt_marker_path(state_dir, str(target.order_id))

    if marker.exists():
        raise _Stop(
            Outcome.ABORTED,
            f"A submission attempt is already recorded for order {target.order_id} "
            f"({marker.name}). Refusing a second attempt.",
        )

    import json as _json
    import time as _time

    marker.parent.mkdir(parents=True, exist_ok=True)
    marker.write_text(
        _json.dumps(
            {
                "order_id": target.order_id,
                "customer_id": target.customer_id,
                "attempted_at": _time.strftime("%Y-%m-%d %H:%M:%S %Z"),
                "total": after_cart.total,
                "payment_method": "cash",
                "outcome": "attempt recorded before the click; outcome unknown at this point",
                "by": "run",
            },
            indent=2,
        )
    )
    marker.chmod(0o600)

    journal.mark_write_attempted("payment POST")
    result.writes.append("payment POST")

    try:
        with session.page.expect_navigation(wait_until="commit", timeout=90000):
            session.page.locator(SUBMIT_BUTTON).first.click()
    except Exception as failure:  # noqa: BLE001
        result.landed_on = session.page.url
        raise _Stop(
            Outcome.UNKNOWN,
            f"The submission gave no clear result ({failure}). The order may or may not have been "
            "placed. NOT retrying.",
        ) from None

    try:
        session.page.wait_for_load_state("domcontentloaded", timeout=30000)
    except Exception:  # noqa: BLE001
        pass

    session.page.wait_for_timeout(4000)
    result.landed_on = session.page.url
    journal.record(landed_on=result.landed_on)

    # ===== classify =============================================================================
    if session.guard.refusal:
        # The application chose this page, so its ADDRESS is evidence; its CONTENT is not read.
        session.guard.refusal = None
        result.outcome = Outcome.UNKNOWN
        result.detail = (
            f"Submitted, and the application redirected to {result.landed_on}, which is not an "
            "approved route — so nothing was read from it. Verify by hand; do NOT re-submit."
        )
    else:
        session.screenshot("run-after-submit", required=False)
        messages = session.page.evaluate(
            """
            () => ({
              errors: Array.from(document.querySelectorAll('.invalid-feedback, .alert-danger'))
                .map(e => (e.innerText||'').replace(/\\s+/g,' ').trim()).filter(Boolean).slice(0,8),
              title: document.title
            })
            """
        )

        if messages["errors"]:
            result.outcome = Outcome.VALIDATION_FAILED
            result.detail = f"The application rejected it: {messages['errors']}"
        else:
            result.outcome = Outcome.UNKNOWN
            result.detail = "Submitted with no errors shown, but completion is not yet confirmed."

    # ===== verify, on approved pages only =======================================================
    try:
        _visit(session, target.checkout_url("payment"))
        banner_after = _incomplete_banner(session.page)
        _visit(session, target.menu_url)
        cart_after = cart.read(session.page)

        emptied = cart_after.is_definitely_empty()
        result.stage(
            "post-submit verification",
            True,
            f"incomplete banner {'gone' if banner_after is None else 'STILL PRESENT'}, "
            f"cart {'emptied' if emptied else cart_after.status.value}",
        )

        if (
            result.outcome is Outcome.UNKNOWN
            and banner_after is None
            and emptied
            and "completed" in (result.landed_on or "")
        ):
            result.outcome = Outcome.SUCCESS
            result.detail = (
                "Confirmed: the application redirected to its own completion route, the "
                "incomplete banner is gone, and the cart is empty."
            )
    except NavigationRefused as refused:
        session.guard.refusal = None
        result.stage("post-submit verification", False, f"could not re-read: {refused}")

    return result
