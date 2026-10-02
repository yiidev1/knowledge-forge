"""Reading the Payment stage: its fields, its validators, and its stated total.

Moved out of the CLI module unchanged, so the one-command workflow and the hand-driven `phase-b` read
the payment page through exactly the same code. Two readers of a page that submits money would be two
chances to disagree about what it says.

Everything here READS. There is no fill, no check and no submit in this module.
"""

from __future__ import annotations

import logging

LOGGER = logging.getLogger("order58")


def form_report(page) -> dict:  # noqa: ANN001 - playwright Page
    """Everything the Payment stage says about itself. Reads only."""
    return page.evaluate(
        r"""
        () => {
          const form = document.querySelector('#checkout-payment-form');
          const vis = el => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
          const labelFor = (el) => {
            if (el.labels && el.labels.length) return el.labels[0].innerText.replace(/\s+/g,' ').trim().slice(0,60);
            if (el.getAttribute('aria-label')) return el.getAttribute('aria-label').trim().slice(0,60);
            const w = el.closest('label');
            if (w) return w.innerText.replace(/\s+/g,' ').trim().slice(0,60);
            const grp = el.closest('.form-group, .field, .form-row');
            const lb = grp && grp.querySelector('label');
            return lb ? lb.innerText.replace(/\s+/g,' ').trim().slice(0,60) : null;
          };

          // Every control on the payment form, hidden ones included: a hidden required field is
          // exactly the kind of thing that refuses a submission.
          const fields = [];
          (form ? form.querySelectorAll('input, select, textarea') : []).forEach(el => {
            const type = (el.getAttribute('type') || el.tagName.toLowerCase()).toLowerCase();
            fields.push({
              name: el.getAttribute('name'), id: el.id || null, type,
              label: labelFor(el),
              required: el.required || el.getAttribute('aria-required') === 'true',
              disabled: el.disabled,
              visible: vis(el),
              checked: (type === 'radio' || type === 'checkbox') ? el.checked : undefined,
              value: /password|csrf|token/i.test(el.getAttribute('name') || '') ? '<withheld>'
                   : (el.value || '').slice(0, 40)
            });
          });

          // The form's registered client validation — the authoritative statement of what it requires.
          let validators = [];
          Array.from(document.querySelectorAll('script')).forEach(s => {
            const t = s.textContent || '';
            if (!/checkout-payment-form/.test(t) || !/yiiActiveForm/.test(t)) return;
            const re = /"id"\s*:\s*"([^"]+)"[\s\S]{0,1200}?validate"?\s*:\s*function[\s\S]{0,1200}?\n\s*\}/g;
            let m;
            while ((m = re.exec(t)) !== null) {
              const body = m[0];
              validators.push({
                id: m[1],
                required: /yii\.validation\.required/.test(body),
                rules: (body.match(/yii\.validation\.(\w+)/g) || []).map(x => x.split('.').pop()),
                messages: (body.match(/"message"\s*:\s*"([^"]{0,70})"/g) || []).slice(0, 3)
              });
            }
          });

          // Pickup / name wording the user asked about, read from the rendered page.
          const bodyLines = (document.body.innerText || '').split('\n')
            .map(l => l.replace(/\s+/g,' ').trim()).filter(Boolean);
          const hits = {};
          [['pickUpAt','pick ?up at'], ['whoWillPickUp','who will pick up'],
           ['warnings','warning'], ['customerName','customer name']].forEach(([key, pat]) => {
            const re = new RegExp(pat, 'i');
            hits[key] = bodyLines.filter(l => re.test(l) && l.length < 160).slice(0, 4);
          });

          const banner = Array.from(document.querySelectorAll('*'))
            .filter(e => e.children.length <= 3)
            .map(e => (e.innerText || '').replace(/\s+/g, ' ').trim())
            .find(t => /order incomplete/i.test(t) && t.length < 160) || null;

          const submits = Array.from(form ? form.querySelectorAll('button, input[type=submit]') : [])
            .map(el => ({
              text: (el.innerText || el.value || '').replace(/\s+/g,' ').trim().slice(0,40),
              type: el.getAttribute('type'),
              cls: (el.className || '').toString().slice(0, 60),
              disabled: el.disabled, visible: vis(el)
            }));

          // The order table the panel renders, captured so a new row can be spotted afterwards.
          const rows = Array.from(document.querySelectorAll('tr'))
            .map(tr => (tr.innerText || '').replace(/\s+/g,' ').trim())
            .filter(t => /(incomplete|completed)/i.test(t) && t.length < 120).slice(0, 12);

          return {
            formFound: !!form,
            action: form ? form.getAttribute('action') : null,
            method: form ? (form.getAttribute('method') || 'get').toLowerCase() : null,
            fields, validators, hits, banner, submits, rows,
            errors: Array.from(document.querySelectorAll('.invalid-feedback, .alert-danger, .has-error'))
              .map(e => (e.innerText || '').replace(/\s+/g,' ').trim()).filter(Boolean).slice(0, 8)
          };
        }
        """
    )


def page_total(page) -> str | None:  # noqa: ANN001 - playwright Page
    """The total as the PAYMENT page states it, or None.

    `cart.read()` reads `#right-sidebar`, the off-canvas drawer on the product-menu page. That drawer
    is not on the payment stage, so asking it for a total there returns None — which is what stopped an
    earlier run at the final gate. Correct behaviour from the gate, wrong source for the question.

    The payment stage prints its own summary instead, as a label element with the amount in the next
    sibling:

        <div class="col-8 text-right"><small>TOTAL:</small></div>
        <div ...>$2.04</div>

    Read from the live DOM, and anchored to `^TOTAL` so it cannot match inside `Subtotal`.
    """
    return page.evaluate(
        r"""
        () => {
          const MONEY = /\$\s?\d[\d,]*\.?\d{0,2}/;
          let found = null;
          Array.from(document.querySelectorAll('div, td, span, small, strong')).forEach(el => {
            if (found || el.children.length > 1) return;
            const own = (el.innerText || '').replace(/\s+/g, ' ').trim();
            if (!/^total\s*:?\s*$/i.test(own)) return;
            // The amount sits in the next sibling, or in the parent's text alongside the label.
            const sib = el.nextElementSibling
              || (el.parentElement ? el.parentElement.nextElementSibling : null);
            let m = sib ? MONEY.exec((sib.innerText || '').replace(/\s+/g, ' ')) : null;
            if (!m && el.parentElement) {
              const up = (el.parentElement.innerText || '').replace(/\s+/g, ' ').trim();
              if (/^total\s*:?/i.test(up)) m = MONEY.exec(up);
            }
            if (m) found = m[0].replace(/\s/g, '');
          });
          return found;
        }
        """
    )


def settled_total(page, expected: str, attempts: int = 20) -> str | None:  # noqa: ANN001
    """Wait for the payment summary to finish re-rendering, then read its total.

    ## Why a wait is needed, and what it explains

    Selecting a payment method fires the page's own handler, which re-requests
    `/admin/demo/cart/list/…` and **replaces the order-summary block**. Read during that window and
    the summary is mid-refresh: the total reads as `None`, and — this is the part worth recording —
    the payment radios momentarily read as unchecked too.

    That race is the whole explanation for an earlier puzzle. Step 1 reported the cash radio as
    `checked=False` three seconds after load, while the server's own HTML plainly contains
    `value="cash" checked`. Nothing had persisted and nothing had changed: the reader had simply
    caught a transient snapshot of a block being rebuilt. A single fixed `wait_for_timeout` cannot fix
    that, because the refresh may start after it elapses.

    So this polls for the page's own stated total until it matches, rather than sampling once and
    trusting whatever came back.
    """
    for _ in range(attempts):
        total = page_total(page)

        if total == expected:
            return total

        page.wait_for_timeout(1000)

    return page_total(page)
