# Order58 demo-order automation

Creates **demo** orders in the Order58 admin panel by driving a browser the way a person does.

Completely isolated from the Knowledge Forge PHP application: no shared code, configuration, database,
dependencies or runtime. Nothing outside this directory is touched.

## Status

| Step | State |
|---|---|
| Browser launch, session handling, page inspection | **done and verified** |
| Customer details, Take-Out, Continue | not started |
| Product search, Order Count, Add to Cart | not started |
| Cart verification | not started |
| Checkout | not started |
| Submission | not started — and gated behind `--submit` plus a demo-route check |

`run` currently stops with a message saying so. Each step is added only after its real selectors have
been read off the page.

## Install (Ubuntu)

Verified on **Ubuntu 22.04.5 LTS** with **Python 3.10.12** — the actual machine, not the 24.04 the brief
assumed. Google Chrome must be installed; it already was, at `/usr/bin/google-chrome`.

```bash
cd /var/www/html/knowledge-forge/order58-automation

python3 -m venv venv
./venv/bin/pip install --upgrade pip
./venv/bin/pip install -r requirements.txt
```

No `playwright install` step. Playwright drives the Chrome already on the machine via
`channel="chrome"`, so no ~150 MB Chromium is downloaded — the same choice the project's existing
browser tests (`tests/E2E`, puppeteer-core) make against the same binary.

## Use

```bash
# Look at a page and report what is on it. Clicks nothing, types nothing, submits nothing.
./venv/bin/python create_demo_order.py inspect

# Sign in once by hand; the session is saved for later runs.
./venv/bin/python create_demo_order.py login

# The order workflow. Dry run — stops before submitting.
./venv/bin/python create_demo_order.py run --product "Egg Roll" --qty 1
```

`--headless false` is accepted as well as a bare `--headless`, and visible is the default while this is
being built.

## Safety

- **Dry run by default.** Submitting needs `--submit` *and* a URL whose path contains `/admin/demo/`.
  A live order URL cannot be driven by accident.
- **Host-locked.** Only `joymeal.order58.com` over HTTPS. Anything else is refused before a browser
  starts. TLS verification is never disabled.
- **Passwords are never handled.** `login` opens a window and waits; you sign in yourself.
- **The session is a credential.** Saved to `state/` with `0600`, git-ignored, never logged or printed.
- **Logs are redacted** by the formatter, not at the call site — cookies, tokens and phone numbers are
  removed on the way out. URLs survive intact, because a log that hides the address being worked on is
  one nobody can debug from.
- **Nothing is guessed.** Selectors come from `inspect`. A selector invented from a screenshot fails
  mid-workflow against a site nobody controls.

## What is deliberately git-ignored

`venv/`, `__pycache__/`, `state/` (the session), `screenshots/` and `logs/` (both can contain customer
data from a live admin panel), and `config.json`. See `.gitignore` here — the root `.gitignore` is not
modified, matching how `tests/E2E/` keeps its own generated files out.

## Relationship to the EC2 listener

None, by design. The existing `/root/order58/get-mix-order.py` on EC2 listens for `new-order-created`
and writes `/data/orders/demo_mix_orders/<source>-<demo>.json`. This tool creates the order through the
authorised UI; that listener does its own job. **Nothing here writes JSON into those folders, restarts
PM2, or touches the server.**
