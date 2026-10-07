# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
