# Developer guide

## Architecture

```
src/
  SupertextTranslationBundle.php   bundle + configuration tree (supertext_translation.*)
  ContaoManager/Plugin.php         Contao Manager: loads the bundle and config/routes.yaml
  Controller/TranslateController   back-end screen /contao/supertext/translate/{pageId} (GET form, POST run)
  EventListener/                   hides the page operation for users without the permission
  Translation/PageTranslator.php   copies a page (+ articles, content elements) into target roots and translates
  Translation/ContaoHooks.php      Contao-framework parts: text encoding per field, versions, aliases, cache tags
  Translation/FieldMap.php         translated fields per table (defaults + config overrides)
  Translation/Languages.php        Contao language → Supertext target/source code, politeness
  Translation/LanguageCodes.php    language code rules (the SDK's, after removing ".UTF-8@euro")
  Translation/TranslationReport    result per target root
  Html/SegmentDocument.php         builds/parses the HTML document sent to Supertext
  Html/Protector.php               hides insert tags and basic entities from the translator
  Html/FieldCodec.php              field value ⇄ segments (text, html, inputUnit, list, table)
  Supertext/SupertextClient.php    the bundle's Supertext client: wraps the SDK, maps its errors
  Supertext/SymfonyHttpTransport   sends the SDK's requests through Contao's Symfony HttpClient
lib/supertext-sdk/                 Supertext PHP SDK (namespace Supertext\ContaoTranslation\Sdk), copied by scripts/vendor-sdk.sh
contao/dca/                        new columns, page operation, permission field
contao/languages/{en,de,fr,it}/    back-end strings (screen, permission, messages)
templates/translate.html.twig      back-end screen (@SupertextTranslation)
public/supertext.svg               operation icon (bundles/supertexttranslation/)
config/services.php, routes.yaml
demo/                              Contao 6 demo site (Railway)
```

### Multilingual model

Contao has one page tree per language: a *website root* (`tl_page.type = root`) with a `language`, the fallback language marked `fallback`. Target roots for a page are the other roots with the **same `dns`** and a **different `language`**.

Each copied record stores its source id in `supertext_source` (`tl_page`, `tl_article`, `tl_content`). A target page is found by `supertext_source`, or, with terminal42/contao-changelanguage installed, by `languageMain` (so pages linked by hand are reused). New pages get `languageMain` = source id when the source root is the fallback.

### Flow of `PageTranslator::translatePage($pageId, $targetRootIds, $includeSubpages)`

1. Validate the targets (`targetRoots()`), collect pages: the page, plus descendants (always for a root), plus ancestors that have no translation in some target (top-down, so parents exist first).
2. Per source page: load articles (`tl_article.pid`) and content elements recursively (`ptable = tl_article`, then nested `ptable = tl_content`), build **one** `SegmentDocument`, **submit it for all targets first** (Supertext works in parallel), then collect each.
3. Write per target inside a DB transaction:
   - Page: new → full copy of the source row, `pid` = parent's translation, unpublished, `supertext_source`, alias from the translated title (`contao.slug`, unique per root, folder URL prefix respected). Existing → only translated fields.
   - Articles and elements: full copy of the source row (layout, images, settings stay in sync) with translated fields; existing records keep their own `published` / `invisible`. Orphans (target records with a `supertext_source` that no longer is a child of the source) are hidden, never deleted. Records with `supertext_source = 0` are not touched.
   - Updates go through Contao's `Versions` (restorable), cache tags `contao.db.<table>.<id>` are invalidated.
4. Per target: ok/error, created pages, counts, hidden, missing segments, warnings → `TranslationReport`. One failing language doesn't stop the others.

### Text encoding (Contao 5 vs 6)

Contao 6 stores plain-text fields **as typed**. Contao 5.7 encodes them on input (`Input::encodeInput`): `# < > ( ) \ = " '` → numeric entities (`encodeAll`), or only `<` for fields with `eval.decodeEntities` (`encodeLessThanSign`); `&` is left alone. Rich-text / `allowHtml` fields are HTML in both. `FieldCodec::textToHtml()` decodes whatever is stored and escapes it for the document; `htmlToText()` strips tags, decodes and re-encodes for the field (`ContaoHooks::textEncoding()` picks the mode by version and DCA).

### HTML document

```html
<!DOCTYPE html>
<html><head><meta charset="utf-8"></head><body>
<div data-st-id="p.12.title">About &amp; us</div>
<div data-st-id="c.40.text"><p>Read <a href="st-ph-0">our guide</a><span translate="no" data-st-ph="1">1</span></p></div>
<div data-st-id="c.41.listitems.2">Third item</div>
</body></html>
```

- Key = `<p|a|c>.<id>.<field>[.<index>…]`.
- Insert tags (`{{…}}`, one nesting level) and basic entities (`[nbsp]`, `[-]`, …) are protected: in text as `<span translate="no" data-st-ph="N">N</span>`, inside tags/attributes as the marker `st-ph-N`. A placeholder the translator drops is appended to the end of the value and reported as a warning.
- Parsing uses `DOMDocument` (`<?xml encoding="utf-8">` hint); segments missing in the response keep the source text and are reported.
- Serialized values are unserialized with `allowed_classes: false`.

### Interface strings

All back-end strings are Contao language files in `contao/languages/{en,de,fr,it}/` (`default.php` → `MSC.supertext.*`, plus the permission labels in `tl_user`/`tl_user_group` and the page operation in `tl_page`), used in the template with `|trans` (domain `contao_default`). Errors keep an English message for the system log; the screen shows `MSC.supertext.error_<errorCode>` (`SupertextException::$errorCode`, `TranslationException::$errorCode`) with the report's `errorParams`, then the untranslated API detail. New or changed strings need all four languages in the same commit (formal Sie/vous/Lei, Contao's own terms, "Supertext", placeholders and URLs unchanged); `LanguageFilesTest` checks this.

## Supertext API protocol

AI file translation API v1, same as the WordPress and Payload plugins. Base URLs `https://api.supertext.com/v1/` (live), `https://api.staging.supertext.com/v1/`, `https://api.testing.supertext.com/v1/`.

### Supertext SDK (`lib/supertext-sdk`)

The protocol is done by the [Supertext PHP SDK](https://github.com/Supertext/Supertext-API-SDK-and-Guide) (`sdk/php`), the shared client of all Supertext plugins. The bundle ships its own copy in `lib/supertext-sdk/src` with the namespace changed from `Supertext\Sdk` to `Supertext\ContaoTranslation\Sdk`, autoloaded through this bundle's `composer.json`. Sites install only the bundle (also through the Contao Manager), and another extension that ships or requires the SDK can never clash with this copy. `lib/supertext-sdk/COMMIT` names the SDK commit. Never edit the copy: fix the SDK repository and copy it again:

```bash
scripts/vendor-sdk.sh                  # latest main of the SDK repository
scripts/vendor-sdk.sh <commit-or-tag>
SDK_DIR=../Supertext-API-SDK-and-Guide scripts/vendor-sdk.sh   # a local clone as it is
```

Then run the unit tests and PHPStan (which analyses `src/` only), and note the update in `CHANGELOG.md`. This stays so after the SDK is published.

`Supertext\SupertextClient` wraps the SDK client: requests go through Contao's Symfony HttpClient (`SymfonyHttpTransport`, so proxy and TLS settings apply and ext-curl isn't needed), the User-Agent is `supertext-sdk-php Contao-Supertext-Translation`, and SDK errors are rethrown as the bundle's `SupertextException` with the same `errorCode`, the HTTP status and the API's detail. `PageTranslator` submits the documents for all target roots first and collects them afterwards, so Supertext works on all languages in parallel.

| Step | Request | Notes |
| --- | --- | --- |
| Upload | `POST translate/ai/file` multipart: `file` (`content.html`, type exactly `text/html`), `target_lang`, optional `source_lang` (bare language; see *Language codes*), optional `politeness` (`more`/`less`) | `SupertextClient::submit()` → file id |
| Poll | `GET translate/ai/file/{id}/status` | `translating`, `done`, `error`, `limit_exceeded`, `deleted` (`collect()`) |
| Download | `GET translate/ai/file/{id}/translation` | translated HTML (`collect()`) |
| Delete | `DELETE translate/ai/file/{id}` | always attempted, also after errors (expires after 24 h anyway) |
| Key check | `GET features` | `SupertextClient::validateApiKey()` (not wired to the UI yet) |

The SDK sends `Authorization: Supertext-Auth-Key <key>` (a pasted prefix is stripped), retries HTTP 429 (`RATE_LIMIT_EXCEEDED`, a per-second limit per key) up to 4 times (`Retry-After` if sent, else 1/2/4/8 s plus jitter, at most 30 s) and never puts the key into messages.

Error codes (`SupertextException::$errorCode`, the SDK's): `missing_api_key`, `authentication_failure` (401/403), `invalid_language_pair` (400 `INVALID_LANGUAGE_PAIR`), `not_found` (404), `payload_too_large` (413), `too_many_requests` (429 after the retries), `service_unavailable` (5xx), `unexpected_status`, `transport_error` (network), `no_file_id`, `translation_error`, `quota_exceeded`, `file_deleted`, `timeout`, `incomplete_response`. Each has an `MSC.supertext.error_<code>` message in `contao/languages/*/default.php`.

### Language codes

Learned from the live API: the **source** is the bare language (`en`, `de`), the **target** a regional code (`de-DE`, `fr-CH`, `en-US`). Supertext rejects a bare target such as `de` with `INVALID_LANGUAGE_PAIR`, and a regional source too. `Translation\LanguageCodes` applies the SDK's rule (the SDK's `LanguageCodes`, after removing an encoding or modifier such as `.UTF-8@euro`); `Translation\Languages` feeds it the website root's *Language* and `language_map`:

- Source: the source root's language (or its `language_map` code) reduced to the language: `de-CH` → `de`.
- Target: the `language_map` code if set, otherwise the root's language. A code with a region keeps it, normalized (`de_CH` / `de-ch` → `de-CH`; scripts and numeric regions stay: `zh-Hant`, `es-419`; variants and extensions are dropped: `de-DE-1996` → `de-DE`, `en-US-x-twain` → `en-US`). A bare code gets a regional default from the SDK's `DEFAULT_REGIONS` (de→de-DE, en→en-US, fr→fr-FR, it→it-IT, pt→pt-PT, zh→zh-CN, …; the same table in every Supertext plugin). Unknown bare codes are sent unchanged, so Supertext's error names them.
- Translating within one language (source `en`, target `en-GB`) is allowed.

`tests/FakeSupertext.php` and the docs stand-in reject bare targets and regional sources like the real API.

## Tests

- `tests/Unit/SupertextClientTest` — the bundle's client on top of the SDK through Symfony HttpClient's mock: protocol, multipart fields, status/HTTP error mapping, 429 retries, timeout, runtime environment selection (the SDK's own tests and conformance scenarios run in the SDK repository)
- `tests/Unit/SegmentDocumentTest` — HTML build/parse, umlauts, insert tags in text and attributes, lost placeholders, field codecs, text encodings of Contao 5 and 6, no object unserialization
- `tests/Unit/LanguageFilesTest` — `contao/languages/{de,fr,it}` have the same keys, placeholders, HTML tags and URLs as `en`; every error code (all of the SDK's, and the bundle's own) has an `MSC.supertext.error_<code>` message
- `tests/Unit/LanguagesTest` — language code rules: bare source, regional source → bare, bare target → default region, regional target normalized, `language_map` wins, unknown codes unchanged
- `tests/Unit/ConfigurationTest` — config keys like `de-CH` are kept (Symfony would turn dashes into underscores)
- `tests/Integration/PageTranslatorTest` — real Contao + database: translating into several roots, nested elements, insert tags, re-translation updates in place and hides removed elements, missing parents and whole trees, only roots of the same site, Supertext errors per language, field overrides

## CI

`.github/workflows/ci.yml`: unit tests on PHP 8.3 and 8.4; integration tests against Contao 5.7 (PHP 8.3) and Contao 6.0 (PHP 8.4) with a MySQL 8.4 service; PHPStan (see *Code quality and security checks*). `.github/workflows/checks.yml` and `links.yml` lint the workflows and check the links.

## Demo (`demo/`)

A Contao 6.0 site using the bundle from this repository (path repository, copied into the image), with terminal42/contao-changelanguage. `demo/src/Command/DemoSetupCommand.php` (`supertext:demo:setup`) runs on every start and is idempotent:

- **Accounts** (see the demo accounts rule in `CLAUDE.md`): `DEMO_ADMIN_EMAIL`/`DEMO_ADMIN_PASSWORD` → administrator; `DEMO_EDITOR_EMAIL`/`DEMO_EDITOR_PASSWORD` → member of the **Editors** group (pages and articles modules, all page types, all fields, all content elements, the language roots as pagemounts, edit permission via the roots' `chmod`, and the Supertext permission). Log in with the e-mail address. Since Contao 5 every field is permission-controlled unless it sets `'exclude' => false`, so the group is allowed every such field of pages, articles and content elements (including *Publish page*); on an existing group, missing field permissions are added (never removed). Missing accounts are created; existing ones are never changed; a password shorter than Contao's minimum (8) is skipped with a warning. Contao has no browser "create first admin" screen; without these variables no accounts are created (use `contao:user:create`).
- **Content** (only when there is no website root yet): theme, page layout with navigation and language switcher, website roots `en` (fallback, `/en/`), `de-CH` (`/de/`), `fr-CH` (`/fr/`), `it-CH` (`/it/`), and English pages with articles, text, list, table, element group, hyperlink and insert tags. The other roots start empty; translate *English* to fill them.

Variables (documented in `demo/.env.example`):

| Variable | |
| --- | --- |
| `DATABASE_URL` | `mysql://user:pass@host:3306/db` (MySQL 8.4 or MariaDB; not MySQL 9) (or Railway's `MYSQLHOST`, `MYSQLUSER`, … are combined by the entrypoint) |
| `APP_SECRET` | random string |
| `SUPERTEXT_API_KEY`, `SUPERTEXT_ENVIRONMENT`, `SUPERTEXT_API_URL` | Supertext |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD`, `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | demo accounts |
| `TRUSTED_PROXIES` | `REMOTE_ADDR` (set in the image): trust the hosting proxy's `X-Forwarded-*` headers so URLs are `https://` without the internal port |

Run locally:

```bash
docker build -f demo/Dockerfile -t contao-supertext-demo .
docker run -p 8080:8080 -e DATABASE_URL=mysql://… -e APP_SECRET=… \
  -e DEMO_ADMIN_EMAIL=admin@example.com -e DEMO_ADMIN_PASSWORD=change-me-123 contao-supertext-demo
```

The entrypoint waits for the database, runs `contao:migrate`, `supertext:demo:setup`, warms the cache and starts Apache on `$PORT`.

### Railway

Project **supertext-cms-demos-php** (region Amsterdam): service **Contao** from this repository's `main` (Dockerfile `demo/Dockerfile`, build context = repo root, healthcheck `/contao/login`) and **MySQL-8** (image `mysql:8.4`, volume `mysql8-volume` at `/var/lib/mysql`). `DATABASE_URL` references the MySQL-8 service's variables.

Use MySQL 8.4 (or MariaDB), **not MySQL 9**: MySQL 9.1 made `EXTERNAL` a reserved word and Contao's `tl_layout.external` column breaks `contao:migrate`. (The project still contains the first, unused `MySQL` service on MySQL 9.)

The entrypoint also makes sure only Apache's prefork MPM is enabled; on Railway the official `php:*-apache` image otherwise fails with "More than one MPM loaded".

To reset the demo: drop and recreate the `contao` database in the MySQL service, then redeploy.

## Docs screenshots

`docs/images/*.png` are made by `test/docs/screenshots.mjs` (Playwright) from a **fresh** demo whose `SUPERTEXT_API_URL` points at `test/docs/stand-in.mjs`: a stand-in for the Supertext API that returns real German, French and Italian for the demo content (`test/docs/translations.json`, keyed by language and by each segment's HTML as sent, insert-tag placeholders included). Unknown segments come back unchanged and are logged; if the demo content changes, add their translations. Regenerate the images in the same commit as UI changes:

```bash
cd test/docs && npm install
npm run stand-in &                          # http://127.0.0.1:8765/v1/
# fresh demo (new database) with SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/, e.g. on port 8080
CONTAO_URL=http://127.0.0.1:8080 EDITOR_EMAIL=… EDITOR_PASSWORD=… ADMIN_EMAIL=… ADMIN_PASSWORD=… npm run screenshots
```

The editor translates the English website, opens the translate screen again (retranslate warning), publishes the German home page through its settings and views it on the website; the administrator part shows the German root's language settings and the group permission. 1200 px wide at 1×, cropped to the relevant part. Locally (no TLS proxy) the script sends `X-Forwarded-Proto: https` for the front-end visit, because the demo's website roots require HTTPS.

## Code quality and security checks

- **Checks** workflow (`.github/workflows/checks.yml`): actionlint and zizmor lint the workflows on every push and pull request; dependency review fails a pull request that adds a package with a known vulnerability (moderate or worse). Third-party actions are pinned to commit SHAs (Dependabot keeps them current), the default token is read-only and checkouts don't keep credentials. Locally: `pip install actionlint-py zizmor`, then `actionlint` and `zizmor .github/workflows` in the repo root.
- **Links** workflow (`.github/workflows/links.yml`): lychee checks all Markdown links weekly and whenever docs change on `main`. Broken links open (or update) the issue "Broken links in the docs". Links that can't work from CI (local URLs, placeholders, pages behind a login) are excluded in `.lycheeignore`.
- **PHPStan** (job *PHPStan* in `.github/workflows/ci.yml`, config `phpstan.neon`): level 5 over `src/`, on PHP 8.4 with the newest Contao that `composer install` resolves (so Contao 6.0; Contao 5.7-only classes such as `EntityCacheTags` show up as unknown and are baselined). Locally: `composer install && vendor/bin/phpstan analyse`. Findings that were there when PHPStan was introduced and aren't simple to fix are listed, with a reason each, in `phpstan-baseline.neon`; new code must pass without additions to it (regenerate with `vendor/bin/phpstan analyse --generate-baseline` only after fixing entries).
- **GitHub settings** (set by Remy's setup script, not in the repo): secret scanning with push protection (a push containing a known token format is rejected; findings under *Security → Secret scanning*) and CodeQL default setup (findings under *Security → Code scanning* and as pull request comments). CodeQL doesn't cover PHP, which is why this repo runs PHPStan.

Before starting work in this repo, look at its open findings: code scanning alerts, secret scanning alerts, Dependabot pull requests and the issue "Broken links in the docs".

## Releasing

Releases are published by `.github/workflows/release.yml` when the version is officially bumped; nobody tags or creates releases by hand.

1. Move the *Unreleased* entries in `CHANGELOG.md` under a new `## [X.Y.Z] - YYYY-MM-DD` section, and keep an empty *Unreleased* above it.
2. There is no version field to change: Composer takes the version from the Git tag the workflow creates.
3. Push to `main`. The workflow tags `vX.Y.Z` and creates the GitHub release with the CHANGELOG section as notes (0.x versions as pre-releases). A push that adds no new version does nothing, and a version that is already released is skipped. After fixing a failed run, start it again with *Run workflow* on the *Release* workflow.

Packagist (once registered) picks up the tag; until then installs use the VCS repository.
## Known limitations / roadmap

- Runs in the request (like the WordPress plugin). Next: Contao's job queue (5.7+, `@experimental`) for whole-site translations with progress.
- News, events, FAQ, forms and other tables are not translated yet.
- Re-translating overwrites manual changes in the translated fields; no change detection or translation memory.
- Translating starts from pages; no separate operation in the article list.
- No API key check in the back end yet (`validateApiKey()` exists).
- Human (professional) translation orders, as in the WordPress plugin, are not implemented.
