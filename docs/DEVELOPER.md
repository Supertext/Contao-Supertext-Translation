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
  Translation/TranslationReport    result per target root
  Html/SegmentDocument.php         builds/parses the HTML document sent to Supertext
  Html/Protector.php               hides insert tags and basic entities from the translator
  Html/FieldCodec.php              field value ⇄ segments (text, html, inputUnit, list, table)
  Supertext/SupertextClient.php    Supertext AI file API v1 (Symfony HttpClient)
contao/dca/                        new columns, page operation, permission field
contao/languages/{en,de}/          labels
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

## Supertext API protocol

AI file translation API v1, same as the WordPress and Payload plugins. Base URLs `https://api.supertext.com/v1/` (live), `https://api.staging.supertext.com/v1/`, `https://api.testing.supertext.com/v1/`; header `Authorization: Supertext-Auth-Key <key>`.

| Step | Request | Notes |
| --- | --- | --- |
| Upload | `POST translate/ai/file` multipart: `file` (`content.html`, type exactly `text/html`), `target_lang`, optional `source_lang` (primary subtag), optional `politeness` (`more`/`less`) | → `{ file_id }` |
| Poll | `GET translate/ai/file/{id}/status` | `translating`, `done`, `error`, `limit_exceeded`, `deleted` |
| Download | `GET translate/ai/file/{id}/translation` | translated HTML |
| Delete | `DELETE translate/ai/file/{id}` | always attempted (expires after 24 h anyway) |
| Key check | `GET features` | `SupertextClient::validateApiKey()` (not wired to the UI yet) |

HTTP errors → `SupertextException::$errorCode`: 401/403 `authentication_failure`, 404 `not_found`, 413 `payload_too_large`, 429 `too_many_requests`, 500/502/503 `service_unavailable`, else `unexpected_status`; network failures `transport_error`.

## Local setup

```bash
git clone https://github.com/Supertext/Contao-Supertext-Translation.git
cd Contao-Supertext-Translation
composer install
vendor/bin/phpunit --testsuite unit
```

Integration tests need a Contao installation with this bundle installed and a migrated MySQL/MariaDB database:

```bash
composer create-project contao/managed-edition:5.7.* ../contao-dev
cd ../contao-dev
composer config repositories.supertext path ../Contao-Supertext-Translation
composer require -W supertext/contao-supertext-translation:@dev terminal42/contao-changelanguage
composer require --dev phpunit/phpunit:^12.4 symfony/dotenv
echo 'DATABASE_URL=mysql://user:pass@127.0.0.1:3306/contao_test' > .env.local
vendor/bin/contao-console contao:migrate --no-interaction

CONTAO_PROJECT_DIR=$PWD CONTAO_TEST_DATABASE_URL='mysql://user:pass@127.0.0.1:3306/contao_test' \
  vendor/bin/phpunit -c ../Contao-Supertext-Translation/phpunit.xml.dist
```

The integration tests **empty** `tl_page`, `tl_article`, `tl_content` and `tl_version` of that database. They boot the real Contao kernel (`tests/bootstrap.php` uses the installation's autoloader) and replace Supertext with `tests/FakeSupertext.php` (Symfony `MockHttpClient`; "translates" by prefixing `[<target_lang>] `).

## Tests

- `tests/Unit/SupertextClientTest` — protocol, multipart fields, status/HTTP error mapping, timeout, runtime environment selection
- `tests/Unit/SegmentDocumentTest` — HTML build/parse, umlauts, insert tags in text and attributes, lost placeholders, field codecs, text encodings of Contao 5 and 6, no object unserialization
- `tests/Unit/ConfigurationTest` — config keys like `de-CH` are kept (Symfony would turn dashes into underscores)
- `tests/Integration/PageTranslatorTest` — real Contao + database: translating into several roots, nested elements, insert tags, re-translation updates in place and hides removed elements, missing parents and whole trees, only roots of the same site, Supertext errors per language, field overrides

## CI

`.github/workflows/ci.yml`: unit tests on PHP 8.3 and 8.4; integration tests against Contao 5.7 (PHP 8.3) and Contao 6.0 (PHP 8.4) with a MySQL 8.4 service.

## Demo (`demo/`)

A Contao 6.0 site using the bundle from this repository (path repository, copied into the image), with terminal42/contao-changelanguage. `demo/src/Command/DemoSetupCommand.php` (`supertext:demo:setup`) runs on every start and is idempotent:

- **Accounts** (see the demo accounts rule in `CLAUDE.md`): `DEMO_ADMIN_EMAIL`/`DEMO_ADMIN_PASSWORD` → administrator; `DEMO_EDITOR_EMAIL`/`DEMO_EDITOR_PASSWORD` → member of the **Editors** group (pages and articles modules, all page types, all fields, all content elements, the language roots as pagemounts, edit permission via the roots' `chmod`, and the Supertext permission). Log in with the e-mail address. Missing accounts are created; existing ones are never changed; a password shorter than Contao's minimum (8) is skipped with a warning. Contao has no browser "create first admin" screen; without these variables no accounts are created (use `contao:user:create`).
- **Content** (only when there is no website root yet): theme, page layout with navigation and language switcher, website roots `en` (fallback, `/en/`), `de-CH` (`/de/`), `fr-CH` (`/fr/`), `it-CH` (`/it/`), and English pages with articles, text, list, table, element group, hyperlink and insert tags. The other roots start empty; translate *English* to fill them.

Variables (documented in `demo/.env.example`):

| Variable | |
| --- | --- |
| `DATABASE_URL` | `mysql://user:pass@host:3306/db` (or Railway's `MYSQLHOST`, `MYSQLUSER`, … are combined by the entrypoint) |
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

Project **supertext-cms-demos-php** (region Amsterdam): service **Contao** from this repository's `main` (Dockerfile `demo/Dockerfile`, build context = repo root, healthcheck `/contao/login`) and **MySQL** (Railway template, MySQL 9, volume). `DATABASE_URL` references the MySQL service's variables. Live: <https://contao-production.up.railway.app> (admin at `/contao`). Pushes to `main` deploy automatically (Railway's GitHub app needs access to this repository).

To reset the demo: drop and recreate the `contao` database in the MySQL service, then redeploy.

## Releasing

1. Move *Unreleased* in `CHANGELOG.md` under a new version.
2. Tag `vX.Y.Z` and push the tag.
3. Packagist (once registered) picks up the tag; until then installs use the VCS repository.

## Known limitations / roadmap

- Runs in the request (like the WordPress plugin). Next: Contao's job queue (5.7+, `@experimental`) for whole-site translations with progress.
- News, events, FAQ, forms and other tables are not translated yet.
- Re-translating overwrites manual changes in the translated fields; no change detection or translation memory.
- Translating starts from pages; no separate operation in the article list.
- No API key check in the back end yet (`validateApiKey()` exists).
- Human (professional) translation orders, as in the WordPress plugin, are not implemented.
