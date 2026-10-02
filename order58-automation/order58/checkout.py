"""Reading the first checkout screen. Nothing on it is filled, chosen or pressed.

## Why this is only a reader

Checkout is where an order stops being a draft. Every control on it either advances a step or commits
something, so this module has no `fill`, no `select` and no `submit` — the inspection cannot accidentally
become a submission, because the code to do it does not exist here.

## What it looks for, and why each

- **Fields**, with their names and whether the page marks them required — the only reliable statement of
  what checkout will refuse to proceed without.
- **Buttons and links**, so the next step's control can be reported rather than guessed at later.
- **Radio and select groups**, because dining type, payment and pickup timing are all expressed that way
  and their *current* value matters as much as the available ones.
- **Step indicators**, so a multi-stage checkout is reported as multi-stage rather than discovered
  halfway through.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field
from typing import Any

from playwright.sync_api import Page

LOGGER = logging.getLogger("order58")

# Words that, in a wizard, usually name the stages.
_STEP_HINTS = ("cart", "customer", "timing", "info", "shipping", "payment", "confirm", "review")


@dataclass
class CheckoutReport:
    """Everything the first checkout screen says about itself."""

    url: str
    title: str
    headings: list[str] = field(default_factory=list)
    steps: list[dict[str, Any]] = field(default_factory=list)
    fields: list[dict[str, Any]] = field(default_factory=list)
    choices: list[dict[str, Any]] = field(default_factory=list)
    buttons: list[dict[str, Any]] = field(default_factory=list)
    forms: list[dict[str, Any]] = field(default_factory=list)
    required_names: list[str] = field(default_factory=list)
    money_lines: list[str] = field(default_factory=list)

    def describe(self) -> str:
        lines = ["", f"  Checkout URL : {self.url}", f"  Page title   : {self.title or '<none>'}"]

        if self.headings:
            lines.append("  Headings     : " + " | ".join(self.headings[:8]))

        def block(label: str, rows: list[dict[str, Any]], limit: int = 30) -> None:
            lines.append("")
            lines.append(f"  {label} ({len(rows)}):")

            if not rows:
                lines.append("    <none found>")
                return

            for row in rows[:limit]:
                shown = {k: v for k, v in row.items() if v not in (None, "", [], False)}
                lines.append("    - " + ", ".join(f"{k}={v!r}" for k, v in shown.items()))

        block("Step / stage indicators", self.steps)
        block("Input fields", self.fields)
        block("Radio & select groups (current value shown)", self.choices)
        block("Buttons and links", self.buttons)
        block("Forms", self.forms)

        lines.append("")
        lines.append(f"  Required fields: {', '.join(self.required_names) or '<none marked required>'}")

        if self.money_lines:
            lines.append("")
            lines.append("  Money shown on this screen:")
            lines += [f"    | {line}" for line in self.money_lines[:12]]

        return "\n".join(lines)


def report_on(page: Page) -> CheckoutReport:
    """Read the checkout screen. Fills nothing, selects nothing, submits nothing."""
    data: dict[str, Any] = page.evaluate(
        """
        (stepHints) => {
          const vis = el => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
          const label = (el) => {
            if (el.labels && el.labels.length) return el.labels[0].innerText.trim().slice(0, 50);
            if (el.getAttribute('aria-label')) return el.getAttribute('aria-label').trim().slice(0, 50);
            const wrap = el.closest('label');
            return wrap ? wrap.innerText.trim().slice(0, 50) : null;
          };
          const isRequired = (el) =>
            el.required || el.getAttribute('required') !== null
            || el.getAttribute('aria-required') === 'true';

          const fields = [];
          const groups = {};

          document.querySelectorAll('input, select, textarea').forEach((el) => {
            const type = (el.getAttribute('type') || el.tagName.toLowerCase()).toLowerCase();
            if (type === 'hidden') return;

            const name = el.getAttribute('name');

            if (type === 'radio' || type === 'checkbox') {
              const key = name || el.id || 'ungrouped';
              groups[key] = groups[key] || { name: key, type, options: [], current: null };
              groups[key].options.push({ value: el.getAttribute('value'), label: label(el) });
              if (el.checked) groups[key].current = el.getAttribute('value') || label(el);
              return;
            }

            if (el.tagName.toLowerCase() === 'select') {
              const opts = Array.from(el.options).slice(0, 12).map(o => o.text.trim().slice(0, 40));
              groups[name || el.id || 'select'] = {
                name: name || el.id, type: 'select', options: opts.map(t => ({ label: t })),
                current: el.selectedIndex >= 0 ? el.options[el.selectedIndex].text.trim().slice(0,40) : null,
                required: isRequired(el), visible: vis(el)
              };
              return;
            }

            fields.push({
              name, id: el.id || null, type,
              label: label(el),
              placeholder: el.getAttribute('placeholder'),
              required: isRequired(el),
              hasValue: el.value !== '',
              visible: vis(el)
            });
          });

          const buttons = [];
          document.querySelectorAll('button, a.btn, input[type=submit], [role=button]').forEach((el) => {
            const text = (el.innerText || el.value || '').replace(/\\s+/g, ' ').trim();
            if (!text) return;
            buttons.push({
              tag: el.tagName.toLowerCase(), text: text.slice(0, 40),
              type: el.getAttribute('type'), id: el.id || null,
              class: (el.className || '').toString().slice(0, 50) || null,
              href: (el.getAttribute('href') || '').slice(0, 100) || null,
              visible: vis(el)
            });
          });

          const forms = [];
          document.querySelectorAll('form').forEach((el) => {
            forms.push({
              id: el.id || null,
              action: el.getAttribute('action'),
              method: (el.getAttribute('method') || 'get').toLowerCase(),
              fields: el.querySelectorAll('input, select, textarea').length
            });
          });

          const headings = [];
          document.querySelectorAll('h1, h2, h3, h4, .card-title, .modal-title').forEach((el) => {
            const t = (el.innerText || '').replace(/\\s+/g, ' ').trim();
            if (t && t.length < 70) headings.push(t);
          });

          // Stage indicators: short labels that look like wizard steps.
          const steps = [];
          document.querySelectorAll('li, .nav-link, .step, [class*=step], [class*=tab]').forEach((el) => {
            const t = (el.innerText || '').replace(/\\s+/g, ' ').trim();
            if (!t || t.length > 30) return;
            if (!stepHints.some(h => t.toLowerCase().includes(h))) return;
            steps.push({
              text: t,
              class: (el.className || '').toString().slice(0, 50) || null,
              active: /active|current/i.test((el.className || '').toString()),
              visible: vis(el)
            });
          });

          const money = (document.body.innerText || '')
            .split('\\n').map(l => l.trim())
            .filter(l => /\\$\\s?\\d/.test(l) && l.length < 60);

          return {
            title: document.title,
            headings: [...new Set(headings)].slice(0, 12),
            steps: steps.slice(0, 16),
            fields,
            choices: Object.values(groups),
            buttons,
            forms,
            money: [...new Set(money)].slice(0, 16)
          };
        }
        """,
        list(_STEP_HINTS),
    )

    required = [
        str(field_row.get("name") or field_row.get("id"))
        for field_row in data["fields"]
        if field_row.get("required")
    ]
    required += [
        str(choice.get("name"))
        for choice in data["choices"]
        if choice.get("required")
    ]

    return CheckoutReport(
        url=page.url,
        title=data["title"],
        headings=data["headings"],
        steps=data["steps"],
        fields=data["fields"],
        choices=data["choices"],
        buttons=data["buttons"],
        forms=data["forms"],
        required_names=[name for name in required if name and name != "None"],
        money_lines=data["money"],
    )
