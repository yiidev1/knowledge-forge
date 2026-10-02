"""Finding a product on the menu, through the search the page actually uses.

## What the page turned out to be

The first version of this scanned the rendered product grid for name-and-price pairs. That was wrong
twice over, and it reported a false negative before anyone relied on it:

1. **The grid shows one category at a time.** Most of the menu is not in the DOM on load, so a product
   can be entirely absent from the page and still be on the menu.
2. **Search is a Twitter typeahead**, not a filter over the visible list. Results arrive in
   `.tt-menu .tt-dataset-ProductSearch` as `.tt-suggestion` elements, asynchronously.

So this drives the search box the way a person does and reads the suggestions it returns.

## Suggestion format

    "2 - Roast Pork Egg Roll (1) - Appetizer"
     ^   ^                         ^
     |   |                         category
     |   product name
     menu code

The code is split off at the **first** ` - ` and the category at the **last**, so a product name
containing a hyphen survives intact.

## Exact means exact

`Egg Roll` matches a product called `Egg Roll`. It does not match `Shrimp Egg Roll`, and it does not
match `Roast Pork Egg Roll (1)`. Those are recorded as near matches and **never** promoted: a
substitution nobody asked for is worse than a failure, because the order goes through, looks right and
is wrong.
"""

from __future__ import annotations

import logging
import re
from dataclasses import dataclass, field

from playwright.sync_api import Page

LOGGER = logging.getLogger("order58")

# Read from the live DOM, not guessed.
SEARCH_INPUT = "#product-search"
SUGGESTION = ".tt-menu .tt-dataset-ProductSearch .tt-suggestion"

# The typeahead answers over the network; this is how long to let it.
SUGGEST_TIMEOUT_MS = 4000


def _normalise(text: str) -> str:
    """Case and spacing folded. Punctuation is kept, because it distinguishes products."""
    return re.sub(r"\s+", " ", text).strip().lower()


@dataclass
class Suggestion:
    """One row the typeahead offered."""

    raw: str
    code: str
    name: str
    category: str

    @classmethod
    def parse(cls, raw: str) -> "Suggestion":
        text = re.sub(r"\s+", " ", raw).strip()

        code, _, rest = text.partition(" - ")

        if not rest:
            # A shape this page has not produced; kept whole rather than invented into parts.
            return cls(raw=text, code="", name=text, category="")

        name, _, category = rest.rpartition(" - ")

        if not name:
            name, category = category, ""

        return cls(raw=text, code=code.strip(), name=name.strip(), category=category.strip())


@dataclass
class SearchResult:
    """What searching for one product turned up."""

    term: str
    suggestions: list[Suggestion] = field(default_factory=list)
    exact: list[Suggestion] = field(default_factory=list)
    near: list[Suggestion] = field(default_factory=list)

    def describe(self) -> str:
        lines = ["", f"  Search {self.term!r} → {len(self.suggestions)} suggestion(s):"]

        for item in self.suggestions:
            lines.append(f"    · {item.raw}")
            lines.append(f"        code={item.code!r}  name={item.name!r}  category={item.category!r}")

        lines.append("")

        if self.exact:
            lines.append(f"  EXACT MATCH for {self.term!r}:")
            lines += [f"    ✓ {item.name!r}  (code {item.code}, {item.category})" for item in self.exact]
        else:
            lines.append(f"  NO EXACT MATCH for {self.term!r}.")

            if self.near:
                lines.append("  Products containing that phrase — NOT used, and not substituted:")
                lines += [f"    · {item.name!r}  (code {item.code}, {item.category})" for item in self.near]

        return "\n".join(lines)


def search(page: Page, term: str) -> SearchResult:
    """Type a term into the product search and read what it offers back.

    Read-only with respect to the order: this fills a search box and reads suggestions. Nothing is
    selected, opened, added or submitted.
    """
    box = page.locator(SEARCH_INPUT)
    box.wait_for(state="visible")

    # Cleared first, so a previous term cannot leave its suggestions on screen to be misread as these.
    box.fill("")
    page.wait_for_timeout(300)
    box.fill(term)

    LOGGER.info("Searching the product menu for %r.", term)

    try:
        page.wait_for_selector(SUGGESTION, timeout=SUGGEST_TIMEOUT_MS, state="attached")
    except Exception:
        # No suggestions at all is a real answer, not an error.
        LOGGER.info("The search returned nothing for %r.", term)

    raw_items: list[str] = page.eval_on_selector_all(
        SUGGESTION,
        "els => els.map(e => (e.innerText || '').replace(/\\s+/g, ' ').trim()).filter(Boolean)",
    )

    suggestions = [Suggestion.parse(raw) for raw in raw_items]
    target = _normalise(term)

    result = SearchResult(term=term, suggestions=suggestions)

    for item in suggestions:
        if _normalise(item.name) == target:
            result.exact.append(item)
        elif target in _normalise(item.name):
            result.near.append(item)

    return result
