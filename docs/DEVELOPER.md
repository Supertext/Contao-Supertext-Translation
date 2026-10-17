# Developer guide

## Architecture

```
src/
  SupertextTranslationBundle.php   bundle + configuration tree (supertext_translation.*)
  ContaoManager/Plugin.php         Contao Manager: loads the bundle and config/routes.yaml
  Controller/TranslateController   back-end screen /contao/supertext/translate/{pageId} (GET form, POST run; status box for admins)
  Controller/PingController        public GET /_supertext/ping (Commando Center reachability check)
  Reporting/Reporting.php          Commando Center: version reports and API key check (Plugin Health's InstallationReporter)
  Reporting/ReportTransport        sends Plugin Health's reports through SymfonyHttpTransport
  Reporting/DatabaseStateStore     the reporter's state, one JSON row in tl_supertext_state
  EventListener/                   hides the page operation for users without the permission (SupertextOperationListener);
                                   the website root's Supertext language and politeness selects (RootLanguageListener)
  Translation/PageTranslator.php   copies a page (+ articles, content elements) into target roots and translates
  Translation/ContaoHooks.php      Contao-framework parts: text encoding per field, versions, aliases, cache tags
  Translation/FieldMap.php         translated fields per table (defaults + config overrides)
  Translation/Languages.php        Contao language → Supertext target/source code (SDK's V1\Languages: language_map, the root's choice, exact, default), politeness, the select's options
  Translation/TranslationReport    result per target root
  Html/SegmentDocument.php         builds/parses the HTML document sent to Supertext
  Html/Protector.php               hides insert tags and basic entities from the translator
  Html/FieldCodec.php              field value ⇄ segments (text, html, inputUnit, list, table)
  Supertext/SupertextClient.php    the bundle's Supertext client: wraps the SDK, maps its errors
  Supertext/SymfonyHttpTransport   sends the SDK's requests through Contao's Symfony HttpClient
lib/supertext-sdk/                 Supertext PHP SDK (namespace Supertext\ContaoTranslation\Sdk), copied by scripts/vendor-sdk.sh
lib/supertext-plugin-health/       Supertext Plugin Health (namespace Supertext\ContaoTranslation\PluginHealth), copied by scripts/vendor-plugin-health.sh
contao/dca/                        new columns (tl_page.supertext_source/_language/_politeness), page operation, permission field, tl_supertext_state
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

The protocol is done by the [Supertext PHP SDK](https://github.com/Supertext/Supertext-API-SDK-and-Guide) (`sdk/php`), the shared client of all Supertext plugins: it provides the API client and the API key check (`Client::checkApiKey()`, `Protocol::apiKeyFingerprint()`). Version reports are not in the SDK any more (since 0.3.0) but in *Supertext Plugin Health* (below). The bundle ships its own copy in `lib/supertext-sdk/src` with the namespace changed from `Supertext\Sdk` to `Supertext\ContaoTranslation\Sdk`, autoloaded through this bundle's `composer.json`. Sites install only the bundle (also through the Contao Manager), and another extension that ships or requires the SDK can never clash with this copy. `lib/supertext-sdk/COMMIT` names the SDK commit, `VERSION` its version (0.4.0). Everything that talks to the API is in the SDK's `V1` namespace (`Supertext\ContaoTranslation\Sdk\V1\Client`, `Languages`, `ApiKeyCheck`, …); only `Sdk\Http\*` and `Sdk\Supertext::SDK_VERSION` are version-neutral. The script copies `src/` with its subfolders and rewrites the namespace in code and in class names inside strings. Never edit the copy: fix the SDK repository and copy it again:

```bash
scripts/vendor-sdk.sh                  # latest main of the SDK repository
scripts/vendor-sdk.sh <commit-or-tag>
SDK_DIR=../Supertext-API-SDK-and-Guide scripts/vendor-sdk.sh   # a local clone as it is
```

Then run the unit tests and PHPStan (which analyses `src/` only), and note the update in `CHANGELOG.md`. This stays so after the SDK is published.

### Supertext Plugin Health (`lib/supertext-plugin-health`)

[Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) (`plugin-health/php`, lives in the Commando Center repository) sends the version reports to the Supertext Commando Center, reports the result of the SDK's API key check and builds the answer of the ping endpoint (see *Commando Center* below). It has no dependencies and doesn't need the SDK: `Reporting\Reporting` passes it the SDK's key check (`CallableKeyChecker`) and `Supertext::SDK_VERSION`. The bundle ships its own copy in `lib/supertext-plugin-health/src` with the namespace changed from `Supertext\PluginHealth` to `Supertext\ContaoTranslation\PluginHealth`, autoloaded through `composer.json` like the SDK copy. `COMMIT` names the commit, `VERSION` its version (0.1.0). Never edit the copy: fix it in Commando-Center-Supertext-Translation/plugin-health, then copy it again:

```bash
scripts/vendor-plugin-health.sh plugin-health-v0.1.0   # a release tag (default: main)
PLUGIN_HEALTH_DIR=../Commando-Center-Supertext-Translation scripts/vendor-plugin-health.sh   # a local clone as it is
```

Then run the unit tests and PHPStan, and note the update in `CHANGELOG.md`.

`Supertext\SupertextClient` wraps the SDK client: requests go through Contao's Symfony HttpClient (`SymfonyHttpTransport`, so proxy and TLS settings apply and ext-curl isn't needed), the User-Agent is `supertext-sdk-php Contao-Supertext-Translation`, and SDK errors are rethrown as the bundle's `SupertextException` with the same `errorCode`, the HTTP status and the API's detail. `PageTranslator` submits the documents for all target roots first and collects them afterwards, so Supertext works on all languages in parallel.

| Step | Request | Notes |
| --- | --- | --- |
| Upload | `POST translate/ai/file` multipart: `file` (`content.html`, type exactly `text/html`), `target_lang`, optional `source_lang` (bare language; see *Language codes*), optional `politeness` (`more`/`less`) | `SupertextClient::submit()` → file id |
| Poll | `GET translate/ai/file/{id}/status` | `translating`, `done`, `error`, `limit_exceeded`, `deleted` (`collect()`) |
| Download | `GET translate/ai/file/{id}/translation` | translated HTML (`collect()`) |
| Delete | `DELETE translate/ai/file/{id}` | always attempted, also after errors (expires after 24 h anyway) |
| Key check | `GET features` | the SDK's `checkApiKey()`, run by Plugin Health's `InstallationReporter` (see *Commando Center*) |

The SDK sends `Authorization: Supertext-Auth-Key <key>` (a pasted prefix is stripped), retries HTTP 429 (`RATE_LIMIT_EXCEEDED`, a per-second limit per key) up to 4 times (`Retry-After` if sent, else 1/2/4/8 s plus jitter, at most 30 s) and never puts the key into messages.

Error codes (`SupertextException::$errorCode`, the SDK's): `missing_api_key`, `authentication_failure` (401/403), `invalid_language_pair` (404 `INVALID_LANGUAGE_PAIR`), `unsupported_language` (no Supertext code for a root's language; raised by `Languages` before anything is sent; the message names the language), `unsupported_feature` (400 `FEATURE_ERROR`: formal/informal for a target without it), `not_found` (404), `payload_too_large` (413), `too_many_requests` (429 after the retries), `service_unavailable` (5xx), `unexpected_status`, `transport_error` (network), `no_file_id`, `translation_error`, `quota_exceeded`, `file_deleted`, `timeout`, `incomplete_response`. Each has an `MSC.supertext.error_<code>` message in `contao/languages/*/default.php`.

### Language codes

Supertext accepts exactly the codes its `GET features` lists (the SDK's `V1\Languages::TARGETS`/`SOURCES`, from the live API): bare sources (`en`, `de`); targets in one exact form each, regional for German, English, French, Italian and Portuguese (`de-CH`, `en-US`, `pt-BR`), with a script for Chinese and Serbian (`zh-Hans`, `sr-Latn`), a dialect for Swiss German (`gsw-u-sd-chzh`), bare for the others (`es`, `nl`, `sl`). Anything else is HTTP 404 `INVALID_LANGUAGE_PAIR`; formal/informal for a target without it (`en-US`, `rm`, …) is HTTP 400 `FEATURE_ERROR` (`unsupported_feature`). No pair within one language.

`Translation\Languages` turns a website root's *Language* into a Supertext code with the SDK's `Languages::target()` / `source()` (SDK 0.4.0), one mapping for both directions:

1. `language_map` in the bundle configuration wins (keys compared ignoring case and `_`/`-`; for automated setups); its value is sent as it is, so a language Supertext adds later works before the SDK lists it. For the source, a known value is sent in its source form (`en: en-GB` → source `en`).
2. Else the root's **Supertext language** (`tl_page.supertext_language`), the select in the root's settings (section *Supertext*). `Languages::mapping()` appends it to the configured map only when the configuration has no entry for that language, so the SDK's first match is the configuration.
3. Else an exact match: a root language that equals a Supertext code apart from case and `_`/`-` (`de-CH`, `fr_ch` → `fr-CH`, `es`); sources also by their first subtag (`de-CH` → `de`).
4. Else the SDK's default (`Languages::defaultTarget()`): `de` → `de-DE`, `en` → `en-US`, `fr` → `fr-FR`, `it` → `it-IT`, `pt` → `pt-PT`, `zh`/`zh-CN`/`zh-SG` → `zh-Hans`, `zh-TW`/`zh-HK`/`zh-MO` → `zh-Hant`, `sr` → `sr-Latn`, a region of a bare target → the bare target (`es-ES` → `es`).
5. Else `SupertextException('unsupported_language')` (the SDK's exception as previous, `messageParams()` = the language). `PageTranslator` resolves the codes before submitting, so that language fails with `MSC.supertext.error_unsupported_language` (which says where the select is) and nothing is sent; the others go on. Nothing else is guessed: `de-LI`, `en-IE`, `fr-BE`, `gsw`, `sr-RS` need a choice.

**The select** (`contao/dca/tl_page.php`, palettes `root` and `rootfallback`, legend `supertext_legend` after `language_legend`; callbacks in `EventListener\RootLanguageListener`, registered with `#[AsCallback]`, same API in Contao 5.7 and 6.0):

- Options: `Languages::targetLabels()` = the SDK's `targetOptions()` as "Name (code)", the name in the back-end user's language from PHP intl (`Locale::getDisplayName`, Supertext's English name for the `gsw-u-sd-*` dialects and when intl has none), sorted by that name (`Collator`). Contao's `chosen` select (searchable), blank option "— choose —" (`MSC.supertext.choose`), `submitOnChange` (so the politeness field appears or disappears).
- Preselection (`load` callback): the saved code, else `Languages::suggest()` = the SDK's `suggestTarget()` of the root's language (exact match, else default), else blank.
- Saving (`save` callback): only codes of `Languages::targetCodes()` (else `MSC.supertext.invalidChoice`); the suggestion of the root's (submitted) language is stored as `''`, so an untouched root keeps following its *Language* when that changes.
- **Formal or informal** (`supertext_politeness`: `default`, `less`, `more`): removed from the palette (`onload`) when the root's Supertext language doesn't offer it (`Languages::supportsPoliteness()`). `Languages::politeness()`: the configuration's `politeness` wins (sent as it is; the translate screen warns via `politenessUnsupported()` if the target has none), else the root's value only if its target offers it (a value left over from another language is not sent), else `default`.
- A `language_map` / `politeness` entry for the root's language replaces the field's help text with *Set to … by language_map in config/config.yaml* (`onload`).
- Both columns are in `PageTranslator::SKIP`: never copied to translated pages.
- Upgrade: no migration. The new columns start empty (= preselected), and an existing `language_map` keeps winning, so nothing configured changes.

The translate screen shows the code per language (when it differs from the root's language), marks languages without a Supertext language (no choice, no exact match, no default) *no Supertext language yet* and disables them, refuses to start when the source root's language has none, and warns when the configuration's `politeness` is set for a target without formal/informal.

`tests/FakeSupertext.php` and the docs stand-in accept only the SDK's codes and answer like the real API (404 `INVALID_LANGUAGE_PAIR`, 400 `FEATURE_ERROR`).

### Commando Center: version reports, key check, ping

Protocol: [Commando-Center-Supertext-Translation](https://github.com/Supertext/Commando-Center-Supertext-Translation) → `docs/DEVELOPER.md` → *Report protocol*; Supertext Plugin Health's `InstallationReporter` implements it (with the SDK's `checkApiKey()` as key checker, run against the configured API through Contao's HTTP client), `Reporting\Reporting` only supplies what Contao knows:

- **Setting:** `version_reports` (bundle configuration, default `true`), combined by Plugin Health's `reportsEnabled()` with `SUPERTEXT_VERSION_REPORTS` (read with `%env(default::…)%`, so `.env.local` works too; a recognised value wins). Off: no reports, ping 404, the key check still runs. `SUPERTEXT_REPORT_URL` sends reports elsewhere (tests, the docs stand-in).
- **Report fields:** `site_url` = the back-end request's scheme, host and base path; plugin `Contao-Supertext-Translation` with the version Composer installed (`InstalledVersions::getPrettyVersion('supertext/contao-supertext-translation')`, i.e. the Git tag; a leading `v` is dropped); system `Contao` with the version of `contao/core-bundle` (or `contao/contao`); runtime PHP; `callback.url` = the absolute URL of the route `supertext_translation_ping`. `sdk_version` = the SDK's `Supertext::SDK_VERSION`. Requests go through Contao's HTTP client (`SymfonyHttpTransport`, for the reports wrapped in `ReportTransport`); the key check uses the configured API (`environment`/`api_url`).
- **When:** the bundle has no settings form (settings are in `config/config.yaml`/the environment), so there is no `settings_saved` and no *Test connection*. The translate screen (GET and POST) queues `onRequest` (reports `installed`, `upgraded` or `api_key_check`; checks the key only when its fingerprint changed); a translation whose result has `authentication_failure` queues `authenticationFailed` instead (with the HTTP status). Queued actions run on `kernel.terminate`, after the response has been sent; nothing here ever throws into a request (a broken or missing state table means nothing runs, so nothing is sent twice).
- **State:** one row (`name = 'reporter'`, JSON in `data`) in `tl_supertext_state`, defined in `contao/dca/tl_supertext_state.php` and created by `contao:migrate` (`DatabaseStateStore`). It holds the reported version set, the last failure time and the latest key check with the key's SHA-256 fingerprint, never the key. It is the same JSON the SDK 0.2 reporter stored, so the switch to Plugin Health sent no new `installed` report.
- **Display:** administrators see the latest key check (`MSC.supertext.keycheck_<status>`, the center's wording in four languages, with the account and API key links for `invalid`/`missing`) with its time, and the reporting setting, at the bottom of the translate screen.
- **Ping:** `GET /_supertext/ping` (`PingController`, no `_scope`, so no Contao firewall, session or page cache) returns Plugin Health's `pingResponse()` as it is: 200 `{"ok":true,"plugin":"Contao-Supertext-Translation","challenge":"…"}` for a challenge matching `^[A-Za-z0-9_-]{16,64}$`, 400 otherwise, 404 with reports off; always `Cache-Control: no-store`.

## Tests

- `tests/Unit/SupertextClientTest` — the bundle's client on top of the SDK through Symfony HttpClient's mock: protocol, multipart fields, status/HTTP error mapping, 429 retries, timeout, runtime environment selection (the SDK's own tests and conformance scenarios run in the SDK repository)
- `tests/Unit/SegmentDocumentTest` — HTML build/parse, umlauts, insert tags in text and attributes, lost placeholders, field codecs, text encodings of Contao 5 and 6, no object unserialization
- `tests/Unit/LanguageFilesTest` — `contao/languages/{de,fr,it}` have the same keys, placeholders, HTML tags and URLs as `en`; every error code (all of the SDK's, and the bundle's own) has an `MSC.supertext.error_<code>` message, every key check result a `keycheck_<status>` message (with both links for `invalid`/`missing`)
- `tests/Unit/LanguagesTest` — language rules: exact Supertext codes (case and `_`/`-` ignored), bare source, regional source → its language, the SDK defaults (`de` → `de-DE`, `es-ES` → `es`, `zh-TW` → `zh-Hant`, also as the select's suggestion), no guessed regions (`en-IE`, `de-LI`, `gsw` are `unsupported_language` naming the language, blank suggestion), the root's choice and `language_map` overriding it (both directions), the root's politeness only where the target offers it, the select's options (all codes, localized names, code shown), the politeness warning
- `tests/Unit/ReportingTest` — the setting and `SUPERTEXT_VERSION_REPORTS`, the first `installed` report (site URL, plugin, system, callback URL, never the key), nothing sent when nothing changed, a changed key checked again, a 401/403 during translation reported once without calling Supertext, a missing key, reports off (key still checked), the one-hour back-off, broken storage never throws, queued actions run on `kernel.terminate`, ping answers (Supertext and the center are a `MockHttpClient`)
- `tests/Unit/ConfigurationTest` — config keys like `de-CH` are kept (Symfony would turn dashes into underscores); `version_reports` defaults to on
- `tests/Integration/PageTranslatorTest` — real Contao + database: translating into several roots, nested elements, insert tags, re-translation updates in place and hides removed elements, missing parents and whole trees, only roots of the same site, Supertext errors per language, field overrides, a root language without a Supertext language (`de-LI`: `unsupported_language`, nothing sent, other languages translated), a bare `de` sent as `de-DE`, the roots' *Supertext language* and *Formal or informal* (source root's choice in its source form, `language_map` overriding a root, not copied to pages), `language_map` (target `de-AT`, source `en` from `en-GB`), the reporter's state in `tl_supertext_state` with one `installed` report to a fake center (Contao version from Composer), and the public ping route through the kernel (no cookie)

## CI

`.github/workflows/ci.yml`: unit tests on PHP 8.3 and 8.4; integration tests against Contao 5.7 (PHP 8.3) and Contao 6.0 (PHP 8.4) with a MySQL 8.4 service; PHPStan (see *Code quality and security checks*). `.github/workflows/checks.yml` and `links.yml` lint the workflows and check the links. `.github/workflows/e2e.yml` runs the end-to-end test against the real Supertext API and regenerates the docs screenshots (see *End-to-end tests*).

## End-to-end tests

The unit and integration tests use fakes. `.github/workflows/e2e.yml` also tests against the **real Supertext API**, so changes on Supertext's side (language codes, regional variants, formatting, rate limits) show up. It runs on every push to `main` that touches the bundle, the demo or the tests, every Monday, and by hand (*Actions → E2E → Run workflow*). It builds the demo image once (Docker layer cache in GitHub Actions) and starts it twice, each with a new database and demo accounts made up for the run:

1. **Real API** (repository or organization secret `SUPERTEXT_API_KEY`; without it these steps are skipped with a warning). Version reports are off (`SUPERTEXT_VERSION_REPORTS=0`), so CI runs don't appear in the Commando Center.
   - `test/e2e/features.php` (in the container) compares the SDK's language lists with the live `GET features` (cost-free): a code the plugin offers that the API no longer lists, a demo pair (`en` → `de-CH`, `fr-CH`, `it-CH`) the API doesn't offer, or an SDK default target (`de` → `de-DE`, `zh-TW` → `zh-Hant`, `es-ES` → `es`, …) it doesn't list, fails the run; new codes and a different politeness support are warnings.
   - `test/e2e/live.mjs` (Playwright): the editor translates the whole English website into every other root through the back end, then the database is checked: every page, article and content element (nested ones included) has a copy, the copies are unpublished, every field is filled and at least 80 % of them changed (page titles like "Home" in German can stay the same), no `data-st-id`/placeholder is left, insert tags, links, bold and italics are kept, the text is in the target language (common words of each language counted) and de-CH has no "ß". Translating again must update the same records (no duplicates). Each run translates the demo site twice into three languages (around 10,000 characters on the key's account).
2. **Stand-in API**: the docs screenshots (see *Docs screenshots*), so the images don't change with each machine translation. If `docs/images/` changed, a second job pushes the branch `automation/docs-screenshots` and opens (or updates) the pull request "Docs: regenerate screenshots". This needs *Settings → Actions → General → Allow GitHub Actions to create and approve pull requests*.

If a run fails, the artifact `e2e-debug` has the result pages, the demo logs and the stand-in log. Locally:

```bash
cd test/e2e && npm install
# fresh demo (new database) with a real SUPERTEXT_API_KEY, e.g. on port 8081
docker cp features.php <container>:/tmp/features.php && docker exec <container> php /tmp/features.php
CONTAO_URL=http://127.0.0.1:8081 DATABASE_URL=mysql://user:pass@127.0.0.1:3306/db \
  EDITOR_EMAIL=… EDITOR_PASSWORD=… npm run live
```

`live.mjs` also passes against the stand-in API (the stand-in returns real translations), which is how to try changes to it without a key.

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
| `SUPERTEXT_VERSION_REPORTS` | `0` turns the version reports off (default on) |
| `SUPERTEXT_REPORT_URL` | only for tests and screenshots: where reports go instead of the Commando Center (e.g. the docs stand-in's `/report`) |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD`, `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | demo accounts |
| `TRUSTED_PROXIES` | `REMOTE_ADDR` (set in the image): trust the hosting proxy's `X-Forwarded-*` headers so URLs are `https://` without the internal port |

Run locally:

```bash
docker build -f demo/Dockerfile -t contao-supertext-demo .
docker run -p 8080:8080 -e DATABASE_URL=mysql://… -e APP_SECRET=… \
  -e DEMO_ADMIN_EMAIL=admin@example.com -e DEMO_ADMIN_PASSWORD=change-me-123 contao-supertext-demo
```

The entrypoint waits for the database, runs `contao:migrate`, `supertext:demo:setup`, warms the cache and starts Apache on `$PORT`. Apache listens only on `$PORT` (the image empties `ports.conf`), so several demos can share a host network, as in the E2E workflow.

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
# fresh demo (new database) with SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/ and
# SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report (the stand-in answers reports with 202), e.g. on port 8080
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
- No *Test connection* button and no settings form: the key check runs automatically (see *Commando Center*); settings other than the roots' Supertext language and tone stay in `config/config.yaml`.
- Human (professional) translation orders, as in the WordPress plugin, are not implemented.
