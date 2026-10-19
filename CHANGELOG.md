# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- **Reviewers' corrections come into Contao by themselves** (Translation review): every hour (Contao's cron) the bundle fetches the corrections made in Supertext Localization Review for each language with *Translation review* and writes them into the translated pages, articles and content elements, with version history. Only where the translated text is still what Supertext delivered: texts an editor changed are never overwritten (they get the correction with the next translation). `vendor/bin/contao-console supertext:review:apply` does it right away and lists what was written and skipped. Setting `review_corrections` (default on). Supertext SDK with `pendingCorrections()`/`acknowledgeCorrections()` (commit cbe8e97).
- **Translation review** on/off in each website root (Content → Pages → edit the website root → *Supertext*): translations into a root with *Translation review* ticked go through Supertext's review service (Supertext Localization Review), where customers and Supertext linguists correct them on the website with a Chrome extension; corrections come back into Contao with the next translation, and texts translated before keep their (reviewed) wording. Same API key, billing, languages and messages; other roots keep going directly to Supertext. New setting `review_url` (defaults to Supertext's review service; `''` turns review off everywhere). The database update adds the column `supertext_review` to `tl_page`. All four interface languages.
- Requests to Supertext name the bundle's version in the User-Agent (`Contao-Supertext-Translation/<version>`).

- Export for translation (XLIFF 1.2 or HTML) and import of translated files; Supertext SDK 0.5.0. On the translate screen of a page: *Export for translation* (file format, target languages, *Download file*) downloads the page's texts with its articles and content elements; *Import translation* uploads the translated file (at most 10 MB, read in memory only) and writes it like a Supertext translation (new pages unpublished, existing ones updated with version history; texts that weren't imported keep their current translation). Texts whose source changed since the export are skipped unless *Also import texts whose source changed since the export* is ticked; the summary lists imported, skipped, untranslated, broken-formatting and missing texts per page and language. Same permission as translating, plus edit rights on the target language. All four interface languages.

- Automatic API key check: when the translate screen is opened with a key that hasn't been checked yet, and when Supertext rejects the key during a translation. Administrators see the latest result with its time at the bottom of the translate screen (*the connection works*, *rejected*, *no key*, *Supertext can't be reached*, *error*; with the links to create an account and generate a key) in English, German, French and Italian.
- *Send version information to Supertext* (`version_reports`, on by default; `version_reports: false` or `SUPERTEXT_VERSION_REPORTS=0` turns it off): after an update and when the key check result changes, the bundle sends the site URL, the bundle, Contao, PHP and SDK versions, whether a key is set, the key check result and the ping address to Supertext, after the page has been sent. No content, no API key and no personal data. Shown on the translate screen for administrators.
- Public ping endpoint `/_supertext/ping` that only echoes Supertext's challenge, so Supertext can check the site is reachable (404 with version reports off).
- The database update adds the table `tl_supertext_state` for this state.
- French and Italian interface (and German where it was missing): the translate screen, the permission and all messages, including Supertext errors, follow the back-end user's language.
- **Supertext language select in each website root** (Content → Pages → edit the website root → *Supertext*): a list of all Supertext languages, "Name (code)" in the back-end language, preselected with the matching or usual Supertext language of the root's language. *Formal or informal* next to it, shown only where the chosen language offers it. Choices are validated against Supertext's list. The database update adds the columns `supertext_language` and `supertext_politeness` to `tl_page`. `language_map` and `politeness` in `config/config.yaml` keep working and override the roots' settings (the root's settings say so); nothing needs to be migrated.

### Changed

- **Languages are no longer guessed.** A website root language that is a Supertext code (`de-CH`, `fr-CH`, `it-CH`, `es`, `zh-Hans`, apart from case and `_`/`-`) is used as it is; every other language needs an entry in `language_map` (e.g. `de: de-CH`). The default regions are gone: they were wrong for most languages (Supertext wants `es`, `sl`, `nl` without region and `zh-Hans` instead of `zh-CN`), and a root with the language `de` is now *Germany, Switzerland or Austria?* for the administrator to decide. An unmapped language is marked *no Supertext language yet* on the translate screen and can't be selected; a translation into it stops with *The language … has no Supertext language yet* and nothing is sent. The screen shows the Supertext code a language is sent as and warns when formal/informal is set for a language Supertext offers no tone for. New messages for both cases in all four languages.
- The connection to Supertext now uses the shared [Supertext SDK](https://github.com/Supertext/Supertext-API-SDK-and-Guide) (PHP SDK 0.2.1, commit `eeba8fa`, shipped with the bundle in `lib/supertext-sdk` under the bundle's own namespace, so nothing else needs installing), still through Contao's HTTP client. It brings the key check, the version reports and Supertext's language list. An unsupported language pair has its own message (*Supertext does not support this language pair*) in all four languages; an unknown language (e.g. `xx-YY`) is reported as an unsupported language pair too, not as "resource not found".
- **Usual Supertext languages are used without setup** (Supertext SDK 0.4.0): a root language without exact match uses its obvious default, `de` → `de-DE`, `en` → `en-US`, `fr` → `fr-FR`, `it` → `it-IT`, `pt` → `pt-PT`, `zh`/`zh-CN` → `zh-Hans`, `zh-TW` → `zh-Hant`, `sr` → `sr-Latn`, `es-ES` → `es`. Only languages without a usual one (e.g. `de-LI`, `en-IE`, `gsw`) are marked *no Supertext language yet*; the message now says to choose the Supertext language in the website root's settings (all four languages).
- Version reports, the API key check result and the reachability ping now use [Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) 0.1.0 (bundled in `lib/supertext-plugin-health`); Supertext SDK updated to 0.3.0. No change for sites: settings and stored state stay as they are.

## [0.1.0] - 2026-10-07

### Fixed

- Requests that hit Supertext's per-second rate limit (HTTP 429), e.g. when translating into several languages, are retried up to 4 times instead of failing that language.
- The API key works with or without the `Supertext-Auth-Key ` prefix Supertext shows.
- Translating a website root no longer labels every language "translation exists, will be updated" (the other roots always exist); the note under the form is aligned with the form.
- Demo: editors could not publish the translated pages (*Publish page* was missing). The Editors group now gets every page, article and content element field; an existing group gets the missing ones on the next start.

### Changed

- When no API key is configured, the translate screen links to the Supertext account signup and to supertext.com → Integrations → API to generate a key (Admin role required); the installation guide, README and demo `.env.example` explain the same.
- Documentation screenshots show real German, French and Italian: made by `test/docs/screenshots.mjs` from the demo against a stand-in API, at 1×; new images for the retranslate warning, the translated site structure, the website after publishing and the language settings of a website root.

### Added

- Contao 5.7 and 6.0 bundle `supertext/contao-supertext-translation`.
- **Translate with Supertext** action in the site structure and a back-end screen to pick target languages (the other website roots of the same domain) and optionally all subpages; translating a website root translates the whole tree.
- Pages, articles and content elements (including nested elements) are copied into the target roots and translated: page title, title tag, description; article title and teaser; headlines, text, lists, tables, image texts, link texts, player captions. Formatting, links, insert tags and basic entities are kept.
- New pages are created unpublished; re-translating updates the same records (tracked in `supertext_source`), keeps their publish state, records versions and hides elements removed in the source.
- Missing parent pages are translated first; aliases are generated from the translated titles.
- Integration with terminal42/contao-changelanguage: new pages are linked via `languageMain`.
- Text stored the way each Contao version expects (Contao 6 raw, Contao 5 input encoding).
- Permission "Translate with Supertext" for users and user groups; target languages limited to roots the user may edit; failures logged to the system log.
- Settings: `api_key` (default `SUPERTEXT_API_KEY`), `environment`, `api_url`, `language_map`, `politeness`, `poll_interval`, `timeout`, `fields`.
- Installation, user and developer guides with screenshots; unit and integration tests; CI for Contao 5.7 and 6.0.
- Contao 6 demo site (`demo/`) with accounts from `DEMO_ADMIN_*` / `DEMO_EDITOR_*`, four Swiss language roots and sample content, deployed on Railway.
