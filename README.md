# Contao Supertext Translation

AI translation for [Contao](https://contao.org) 5.7 and 6.0 by [Supertext](https://www.supertext.com). Adds a **Translate with Supertext** action to the site structure: pick a page (or a whole website root) and the languages, and the bundle copies the pages with their articles and content elements into the other language roots and translates the texts. New pages are created unpublished for review; translating again updates them in place.

![Site structure with the translate action](docs/images/01-site-structure.png)

```bash
composer require supertext/contao-supertext-translation
```

Formatting, links and insert tags are kept; translated pages are linked for the language switcher (terminal42/contao-changelanguage); previous versions stay restorable.

**Live demo:** <https://contao-production.up.railway.app/contao> (credentials from the Supertext team)

## Guides

- [Installation guide](docs/INSTALLATION.md) — requirements, install, API key, permissions, languages, all settings, troubleshooting
- [User guide](docs/USER_GUIDE.md) — translating, reviewing and publishing in the back end
- [Developer guide](docs/DEVELOPER.md) — architecture, Supertext API protocol, tests, demo, roadmap

Part of Supertext's translation plugins for open source CMSs. See also the [WordPress plugin](https://github.com/Supertext/supertext-wordpress-polylang), the [Drupal module](https://www.drupal.org/project/tmgmt_supertext_ai) and the [Payload plugin](https://github.com/Supertext/Payload-Supertext-Translation).

[Changelog](CHANGELOG.md) · MIT licensed
