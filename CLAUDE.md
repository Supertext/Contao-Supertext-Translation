# Working on this repository

Part of Supertext's "translation plugins for the top 20 open source CMS" project. Each CMS has its own repo named `Supertext/<CMS>-Supertext-Translation`. This one is the **Contao 5.7 / 6.0** bundle (PHP, Composer package `supertext/contao-supertext-translation`).

## Documentation rule (always)

Every plugin repo keeps three guides, and **every change that affects behaviour, settings, installation or the code structure updates them in the same commit**:

| File | Audience | Must cover |
| --- | --- | --- |
| `docs/INSTALLATION.md` | Administrators | Requirements, install/update/uninstall, API key, language setup, all settings, troubleshooting |
| `docs/USER_GUIDE.md` | Editors | How to translate and review in the CMS's own UI, what is and isn't translated, what errors mean |
| `docs/DEVELOPER.md` | Developers | Architecture, Supertext API protocol, local setup, tests, CI/deploy, releasing, known limitations/roadmap |

Also: `README.md` stays a short overview linking the three guides, and `CHANGELOG.md` gets an entry under *Unreleased* for every user-visible change. Before finishing any task, check the docs still match the code.

## Supertext account and API key links (always)

Everywhere an administrator enters or is told about the API key — the settings field's help text, the "no API key" / "authentication failed" messages, `docs/INSTALLATION.md`, `README.md` and the demo's `.env.example` — show both links (same as the WordPress plugin):

- Create a Supertext account (or log in): https://www.supertext.com/person/en/account/signin
- Generate the AI API key: https://www.supertext.com/en/integrations/api (supertext.com → Integrations → API; requires the **Admin** role)

Wording: "No Supertext account yet? Create one at supertext.com. Generate your API key at supertext.com → Integrations → API (requires the Admin role)." In the UI, links open in a new tab (`target="_blank" rel="noopener"`); where the CMS shows plain text only, use the bare URLs. New screens or messages that mention the key get the links too.

## Plugin list (always)

`README.md` ends with the shared list of all Supertext plugins (between the `<!-- supertext-plugins:start -->` and `<!-- supertext-plugins:end -->` markers). It is identical in every Supertext plugin repo: when a plugin is added, renamed or its description changes, update the list in **all** repos, not just this one.

## Releases (always)

Releases are published by `.github/workflows/release.yml`: never tag or create a GitHub release by hand. To release, follow `docs/DEVELOPER.md` → *Releasing* (new version section in `CHANGELOG.md`, same number in the version files) and push to `main`. A push without a new version releases nothing.

## Repo setup (always)

Every Supertext plugin repo has, and a new one gets from the start:

- `LICENSE` matching the license its manifest declares (`composer.json`, `package.json`, `pyproject.toml`, `.csproj`, plugin header).
- `SECURITY.md`: report vulnerabilities privately through GitHub's private vulnerability reporting or support@supertext.com, never in public issues.
- `.github/dependabot.yml`: weekly updates for its package ecosystem and GitHub Actions, minor and patch updates grouped into one pull request.
- On GitHub: the About box filled in (one-sentence description, website https://www.supertext.com, topics), `main` protected against force-pushes and deletion, Wiki and Projects off, Dependabot alerts and private vulnerability reporting on, and the Supertext social preview image.
- A row in the plugin list (see *Plugin list*) and in the org profile (`Supertext/.github` → `profile/README.md`).

Claude sessions can't change GitHub repo settings (HTTP 403): add a new repo to Remy's setup script (`set-github-about`) instead of trying.

## Demo accounts rule (always)

Every demo must be usable right after deployment, without anyone registering in a browser. On **every start**, the demo creates these accounts if they don't exist yet:

| Variables | Account |
| --- | --- |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Full administrator (for Supertext staff) |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Editor-level account that can translate content in every demo language; used for automated tests and screenshots. Where the CMS has no editor role that works out of the box, use the closest role and document it. |

- Existing accounts are never modified: no password resets from variables, no duplicates on restart.
- A password that doesn't meet the CMS's own password rules skips that account with a clear warning in the log. The demo still starts.
- Values live only in the hosting platform's variables (Railway). Never in the repo, in chat or in logs. Log the variable name, never the password.
- If the CMS has a first-run "create admin" screen, these accounts replace it. Document that once `DEMO_*` is set, the screen no longer appears.
- If a demo already used CMS-specific names (e.g. `TYPO3_ADMIN_*`, `PAYLOAD_ADMIN_*`), keep them as fallbacks for `DEMO_ADMIN_*`.
- The demo also seeds its target languages and at least one sample entry in the source language, and makes sure the editor account can access every target language.
- Document the variables in `docs/DEVELOPER.md` (demo section) and in the demo's `.env.example`.

## Screenshots rule (always)

The user guide and installation guide of every plugin include screenshots of the real UI: at least the translate action before and after translating, a translated result, the overwrite or retranslate warning if there is one, the plugin's settings or configuration screen, and the CMS's language setup. Screenshots are taken from the repo's own demo with the headless browser, by a committed script (e.g. `npm run docs:screenshots`), against a stand-in API that returns real translations for the sample content, so the guides never show placeholder text. Use no real customer data, no secrets, no local URLs (show the live API endpoint). Keep the images small (1× scale, cropped to the relevant part), store them in `docs/images/`, give each one descriptive alt text, and regenerate them in the same commit whenever the UI they show changes.

## Shared Supertext protocol

AI file translation API v1, same as the WordPress plugin: POST HTML file → poll status → GET translation → DELETE. Details in `docs/DEVELOPER.md`. Never commit API keys; use the `SUPERTEXT_API_KEY` environment variable or the bundle's `api_key` setting.

Lessons from testing against the live API (October 2026), to apply in every plugin:

- **Auth header:** `Authorization: Supertext-Auth-Key <key>`. The header name must be `Authorization` (`Authentication` gets 403; no prefix gets 400). Supertext shows the key with the prefix, so strip a pasted `Supertext-Auth-Key ` and always send exactly one.
- **Rate limit:** the API limits requests per second per key (HTTP 429, `RATE_LIMIT_EXCEEDED`). Translating into several languages at once hits it. Retry a 429 up to 4 times (`Retry-After`, else 1/2/4/8 s with jitter).
- **Rich text:** each element carrying `data-st-id` is translated on its own. Send a whole paragraph (heading, list item) as **one** `data-st-id` element with formatting and links as inline tags (`<b>`, `<i>`, `<a href>`), and map them back to the CMS's rich-text nodes. Never give each formatted run its own `data-st-id`: sentences break at the formatting (lower-case starts, words moved outside the tags).

## This repo

- Unit tests: `vendor/bin/phpunit --testsuite unit`. Integration tests run inside a real Contao installation with a database (see `docs/DEVELOPER.md`); CI runs them for Contao 5.7 and 6.0. Unit and integration tests must pass before committing.
- Support both Contao 5.7 and 6.0: check APIs in both versions (e.g. plain text is stored encoded in 5.7, raw in 6.0 — see "Text encoding" in `docs/DEVELOPER.md`). Never write Contao 4 conventions (e.g. `published = ''`; flags are booleans).
- New settings go in `SupertextTranslationBundle::configure()` **and** the settings table in `docs/INSTALLATION.md`. Translated fields live in `src/Translation/FieldMap.php`; keep the field table in the installation guide in sync.
- UI changes: regenerate `docs/images/` with `test/docs/screenshots.mjs` against a fresh demo and the stand-in API (see "Docs screenshots" in `docs/DEVELOPER.md`); new demo content needs entries in `test/docs/translations.json`.
- `demo/` is the Railway demo (Dockerfile `demo/Dockerfile`, context = repo root). The demo setup command must stay idempotent. Demo secrets live only in Railway variables.
