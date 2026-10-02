"""Reading a page and reporting what is actually on it.

## Why this exists as its own step

Every selector in the brief was inferred from screenshots. Screenshots show what a control looks like,
never what it is called — and a selector guessed from a picture fails at the worst moment, in the middle
of a workflow, against a site nobody can change. So the first thing this tool does is look, and report.

## It does not touch anything

No clicks, no typing, no submits. Opening the demo order page must not create or disturb an incomplete
order, so this reads the DOM and stops. Everything here is a question.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field
from typing import Any

from playwright.sync_api import Page

LOGGER = logging.getLogger("order58")

# Words that, on a page of this kind, mean "you are not signed in".
_SIGN_IN_HINTS = ("login", "log in", "sign in", "signin", "password", "unauthorized", "unauthorised")


@dataclass
class PageReport:
    """What one page turned out to contain."""

    url: str
    title: str
    looks_like_sign_in: bool
    inputs: list[dict[str, Any]] = field(default_factory=list)
    radios: list[dict[str, Any]] = field(default_factory=list)
    buttons: list[dict[str, Any]] = field(default_factory=list)
    forms: list[dict[str, Any]] = field(default_factory=list)
    headings: list[str] = field(default_factory=list)

    def describe(self) -> str:
        """A report meant to be read by a person and pasted into a message."""
        lines = [
            "",
            "  URL after load : " + self.url,
            "  Page title     : " + (self.title or "<none>"),
            "  Looks like a sign-in page: " + ("YES" if self.looks_like_sign_in else "no"),
        ]

        if self.headings:
            lines.append("  Headings       : " + " | ".join(self.headings[:6]))

        def block(label: str, rows: list[dict[str, Any]]) -> None:
            lines.append("")
            lines.append(f"  {label} ({len(rows)}):")

            if not rows:
                lines.append("    <none found>")
                return

            for row in rows[:25]:
                described = ", ".join(f"{k}={v!r}" for k, v in row.items() if v not in (None, "", []))
                lines.append("    - " + described)

        block("Text / tel / number inputs", self.inputs)
        block("Radio and checkbox inputs", self.radios)
        block("Buttons and submits", self.buttons)
        block("Forms", self.forms)

        return "\n".join(lines)


def report_on(page: Page) -> PageReport:
    """Read the page and return what is there. Changes nothing."""
    # One evaluate rather than many locator round trips: this is a snapshot of a moment, and twenty
    # separate queries against a live page can disagree with each other.
    found: dict[str, Any] = page.evaluate(
        """
        () => {
          const visible = (el) => {
            const rect = el.getBoundingClientRect();
            return rect.width > 0 && rect.height > 0;
          };

          const labelFor = (el) => {
            if (el.labels && el.labels.length) {
              return el.labels[0].innerText.trim().slice(0, 60);
            }
            if (el.getAttribute('aria-label')) {
              return el.getAttribute('aria-label').trim().slice(0, 60);
            }
            const wrapper = el.closest('label');
            return wrapper ? wrapper.innerText.trim().slice(0, 60) : null;
          };

          const inputs = [];
          const radios = [];

          document.querySelectorAll('input, textarea, select').forEach((el) => {
            const type = (el.getAttribute('type') || el.tagName.toLowerCase()).toLowerCase();
            const row = {
              tag: el.tagName.toLowerCase(),
              type,
              name: el.getAttribute('name'),
              id: el.id || null,
              placeholder: el.getAttribute('placeholder'),
              label: labelFor(el),
              visible: visible(el),
            };

            // The value is reported ONLY for radios and checkboxes, where it names the option. A text
            // field's value on this page is a customer's name or phone number.
            if (type === 'radio' || type === 'checkbox') {
              row.value = el.getAttribute('value');
              row.checked = el.checked;
              radios.push(row);
            } else if (type !== 'hidden') {
              row.hasValue = el.value !== '';
              inputs.push(row);
            }
          });

          const buttons = [];
          document.querySelectorAll('button, input[type=submit], a.btn, [role=button]').forEach((el) => {
            const text = (el.innerText || el.value || '').trim().slice(0, 50);
            if (!text) return;
            buttons.push({
              tag: el.tagName.toLowerCase(),
              text,
              type: el.getAttribute('type'),
              id: el.id || null,
              name: el.getAttribute('name'),
              visible: visible(el),
            });
          });

          const forms = [];
          document.querySelectorAll('form').forEach((el) => {
            forms.push({
              action: el.getAttribute('action'),
              method: (el.getAttribute('method') || 'get').toLowerCase(),
              id: el.id || null,
              fields: el.querySelectorAll('input, select, textarea').length,
            });
          });

          const headings = [];
          document.querySelectorAll('h1, h2, h3').forEach((el) => {
            const text = el.innerText.trim();
            if (text) headings.push(text.slice(0, 60));
          });

          return { inputs, radios, buttons, forms, headings, title: document.title };
        }
        """
    )

    body_text = (page.inner_text("body") or "").lower()[:4000]
    url = page.url.lower()

    looks_like_sign_in = any(hint in url for hint in ("login", "signin", "sign-in", "auth")) or (
        any(hint in body_text for hint in _SIGN_IN_HINTS)
        and any(row.get("type") == "password" for row in found["inputs"])
    )

    return PageReport(
        url=page.url,
        title=found["title"],
        looks_like_sign_in=looks_like_sign_in,
        inputs=found["inputs"],
        radios=found["radios"],
        buttons=found["buttons"],
        forms=found["forms"],
        headings=found["headings"],
    )
