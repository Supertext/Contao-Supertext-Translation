#!/usr/bin/env node
/**
 * End-to-end test against the REAL Supertext API: a fresh demo (nothing translated yet)
 * with a real SUPERTEXT_API_KEY. The editor translates the whole English website into
 * every other website root through the back end, then the database is checked: every
 * page, article and content element has a translated copy, the copies are really in the
 * target language (de-CH without "ß"), formatting, links and insert tags survived, and
 * translating again updates the copies in place instead of duplicating them.
 * See "End-to-end tests" in docs/DEVELOPER.md.
 *
 *   CONTAO_URL=http://127.0.0.1:8081 DATABASE_URL=mysql://root:root@127.0.0.1:3306/contao_live \
 *   EDITOR_EMAIL=… EDITOR_PASSWORD=… node test/e2e/live.mjs
 */
import { chromium } from 'playwright';
import mysql from 'mysql2/promise';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const B = (process.env.CONTAO_URL ?? 'http://127.0.0.1:8081').replace(/\/$/, '');
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`); })();
const artifacts = fileURLToPath(new URL('./artifacts/', import.meta.url));
mkdirSync(artifacts, { recursive: true });

const failures = [];
function check(name, ok, detail = '') {
	console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`);
	if (!ok) failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
	return ok;
}

// Common words, to tell the target languages apart. Lower case, whole words.
const STOPWORDS = {
	en: ['the', 'and', 'is', 'are', 'with', 'for', 'you', 'your', 'our', 'of', 'to', 'we', 'who', 'what'],
	de: ['der', 'die', 'das', 'und', 'ist', 'sind', 'mit', 'für', 'sie', 'ihre', 'ihr', 'unser', 'unsere', 'wir', 'eine', 'ein', 'den', 'zu', 'von', 'was'],
	fr: ['le', 'les', 'et', 'est', 'sont', 'avec', 'pour', 'vous', 'votre', 'vos', 'notre', 'nos', 'nous', 'des', 'une', 'un', 'du', 'de', 'qui', 'que'],
	it: ['il', 'lo', 'gli', 'e', 'è', 'sono', 'con', 'per', 'lei', 'sua', 'suo', 'nostro', 'nostra', 'noi', 'una', 'un', 'del', 'della', 'di', 'che', 'chi'],
};
const words = (text) => text.toLowerCase().match(/[\p{L}]+/gu) ?? [];
const score = (text, lang) => words(text).filter((w) => STOPWORDS[lang].includes(w)).length;
/** Visible text of a field: HTML without tags; of serialized arrays (headline, list, table) only the texts. */
function plain(v) {
	let s = String(v ?? '');
	if (/^a:\d+:\{/.test(s)) s = [...s.matchAll(/s:\d+:"(.*?)";/gs)].map((m) => m[1]).filter((t) => !['unit', 'value'].includes(t) && !/^h[1-6]$/.test(t)).join(' ');
	return s.replace(/<[^>]+>/g, ' ');
}
const count = (haystack, needle) => String(haystack ?? '').split(needle).length - 1;

const FIELDS = ['headline', 'text', 'listitems', 'tableitems', 'linkTitle', 'titleText', 'summary'];
const LEFTOVERS = ['data-st-id', 'data-st-ph', 'st-ph-'];

const db = await mysql.createConnection(need('DATABASE_URL'));
const q = async (sql, params = []) => (await db.query(sql, params))[0];

/** IDs of a root's pages (not the root), all levels. */
async function pagesUnder(rootId) {
	const out = [];
	let level = [rootId];
	while (level.length) {
		const rows = await q('SELECT id FROM tl_page WHERE pid IN (?)', [level]);
		level = rows.map((r) => r.id);
		out.push(...level);
	}
	return out;
}

/** Articles and content elements (nested ones included) of these pages. */
async function contentOf(pageIds) {
	if (!pageIds.length) return { articles: [], elements: [] };
	const articles = await q('SELECT * FROM tl_article WHERE pid IN (?)', [pageIds]);
	const elements = [];
	let level = articles.length ? await q("SELECT * FROM tl_content WHERE ptable = 'tl_article' AND pid IN (?) AND invisible = 0", [articles.map((a) => a.id)]) : [];
	while (level.length) {
		elements.push(...level);
		level = await q("SELECT * FROM tl_content WHERE ptable = 'tl_content' AND pid IN (?) AND invisible = 0", [level.map((e) => e.id)]);
	}
	return { articles, elements };
}

/** What a translation run left in the database, per target root. */
async function snapshot(targets) {
	const out = {};
	for (const root of targets) {
		const pageIds = await pagesUnder(root.id);
		const pages = pageIds.length ? await q('SELECT * FROM tl_page WHERE id IN (?)', [pageIds]) : [];
		out[root.id] = { pages, ...(await contentOf(pageIds)) };
	}
	return out;
}

// --- Setup -------------------------------------------------------------------------------
const [english] = await q("SELECT id, title FROM tl_page WHERE type = 'root' AND language = 'en'");
if (!english) throw new Error('No English website root: is this a fresh demo?');
const targets = await q("SELECT id, title, language FROM tl_page WHERE type = 'root' AND id != ? ORDER BY sorting", [english.id]);
const sourcePages = await q('SELECT * FROM tl_page WHERE id IN (?)', [await pagesUnder(english.id)]);
const source = { pages: sourcePages, ...(await contentOf(sourcePages.map((p) => p.id))) };
const sourceElement = new Map(source.elements.map((e) => [e.id, e]));
console.log(`English website: ${source.pages.length} pages, ${source.articles.length} articles, ${source.elements.length} content elements; targets: ${targets.map((t) => t.language).join(', ')}`);
check('demo has target roots', targets.length > 0);
check('fresh demo (nothing translated yet)', (await q('SELECT COUNT(*) AS n FROM tl_page WHERE supertext_source > 0'))[0].n === 0);

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const page = await browser.newPage({ viewport: { width: 1200, height: 1000 }, locale: 'en-US' });
page.setDefaultTimeout(30_000);

async function translateEnglishSite(label) {
	await page.goto(`${B}/contao/supertext/translate/${english.id}`);
	const form = page.locator('form.supertext-translate');
	await form.waitFor();
	const errorsBefore = await form.locator('.tl_error').allTextContents();
	check(`${label}: translate screen without errors`, errorsBefore.length === 0, errorsBefore.join(' | '));
	for (const root of targets) {
		const box = form.locator(`#st_target_${root.id}`);
		const usable = (await box.count()) === 1 && !(await box.isDisabled());
		check(`${label}: ${root.language} is mapped to a Supertext language`, usable);
		if (usable) await box.check();
	}
	const started = Date.now();
	// The click waits for the navigation too: give it the translation's time, not the default 30 s.
	await Promise.all([page.waitForNavigation({ timeout: 900_000 }), form.locator('button.tl_submit').click({ timeout: 900_000 })]);
	await page.screenshot({ path: `${artifacts}${label}-result.png`, fullPage: true });
	const confirms = await form.locator('.tl_confirm').allTextContents();
	const errors = await form.locator('.tl_error').allTextContents();
	console.log(`${label}: ${Math.round((Date.now() - started) / 1000)} s`);
	check(`${label}: every language translated`, confirms.length === targets.length, `${confirms.length}/${targets.length}`);
	check(`${label}: no errors from Supertext`, errors.length === 0, errors.map((e) => e.replace(/\s+/g, ' ').trim()).join(' | '));
}

// --- Log in as the editor ----------------------------------------------------------------
await page.goto(`${B}/contao/login`);
await page.fill('input[name=username]', need('EDITOR_EMAIL'));
await page.fill('input[name=password]', need('EDITOR_PASSWORD'));
await Promise.all([page.waitForNavigation(), page.click('button[type=submit], input[type=submit]')]);
if (!check('editor can log in (DEMO_EDITOR_*)', !page.url().includes('/contao/login'))) {
	await page.screenshot({ path: `${artifacts}login.png` });
	throw new Error(failures.join('\n'));
}

// --- First translation -------------------------------------------------------------------
await translateEnglishSite('first-run');
const first = await snapshot(targets);

for (const root of targets) {
	const lang = root.language.split(/[-_]/)[0].toLowerCase();
	const t = first[root.id];
	const tag = `${root.title} (${root.language})`;
	check(`${tag}: every page translated`, t.pages.filter((p) => p.supertext_source > 0).length === source.pages.length, `${t.pages.length}/${source.pages.length}`);
	check(`${tag}: every article translated`, t.articles.filter((a) => a.supertext_source > 0).length === source.articles.length, `${t.articles.length}/${source.articles.length}`);
	check(`${tag}: every content element translated`, t.elements.filter((e) => e.supertext_source > 0).length === source.elements.length, `${t.elements.length}/${source.elements.length}`);
	check(`${tag}: new pages are unpublished`, t.pages.every((p) => !p.published));

	// Field by field: filled, changed, nothing of the HTML document left, tags kept.
	let fields = 0;
	let changed = 0;
	let text = '';
	for (const p of t.pages) {
		const s = sourcePages.find((x) => x.id === p.supertext_source);
		if (!s) continue;
		// A single word can be the same in the target language ("Home" in German, "Services"
		// in French), so a title only has to be filled; unchanged ones count against the ratio below.
		check(`${tag}: page title "${s.title}" filled`, String(p.title ?? '').trim() !== '', p.title);
		fields++;
		if (p.title !== s.title) changed++;
		else console.log(`note ${tag}: page title "${s.title}" unchanged`);
		text += ` ${p.title} ${p.description ?? ''}`;
	}
	for (const e of t.elements) {
		const s = sourceElement.get(e.supertext_source);
		if (!s) continue;
		for (const f of FIELDS) {
			if (!s[f] || !plain(s[f]).trim()) continue;
			fields++;
			const v = String(e[f] ?? '');
			if (!check(`${tag}: ${s.type}#${s.id}.${f} filled`, plain(v).trim() !== '')) continue;
			if (v !== String(s[f])) changed++;
			text += ` ${plain(v)}`;
			const left = LEFTOVERS.filter((l) => v.includes(l));
			check(`${tag}: ${s.type}#${s.id}.${f} has no placeholders left`, left.length === 0, left.join(', '));
			for (const [what, needle] of [['insert tags', '{{'], ['links', '<a '], ['bold', '<strong'], ['italics', '<em']]) {
				if (count(s[f], needle)) check(`${tag}: ${s.type}#${s.id}.${f} keeps its ${what}`, count(v, needle) === count(s[f], needle), `${count(v, needle)}/${count(s[f], needle)}`);
			}
		}
	}
	check(`${tag}: texts were changed by the translation`, fields > 0 && changed / fields >= 0.8, `${changed}/${fields} fields`);

	if (STOPWORDS[lang]) {
		const scores = Object.fromEntries(Object.keys(STOPWORDS).map((l) => [l, score(text, l)]));
		const best = Object.entries(scores).sort((a, b) => b[1] - a[1])[0][0];
		check(`${tag}: text is in the target language`, best === lang, JSON.stringify(scores));
	}
	if (/^de-ch$/i.test(root.language)) check(`${tag}: Swiss German spelling (no "ß")`, !text.includes('ß'));
}

// --- Translating again updates in place ---------------------------------------------------
await translateEnglishSite('second-run');
const second = await snapshot(targets);
for (const root of targets) {
	const tag = `${root.title} (${root.language})`;
	for (const kind of ['pages', 'articles', 'elements']) {
		const before = first[root.id][kind].map((r) => r.id).sort().join(',');
		const after = second[root.id][kind].map((r) => r.id).sort().join(',');
		check(`${tag}: translating again updates the ${kind} in place (no duplicates)`, before === after, `${first[root.id][kind].length} → ${second[root.id][kind].length}`);
	}
}

await browser.close();
await db.end();

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed:\n- ${failures.join('\n- ')}`);
	process.exit(1);
}
console.log('\nAll end-to-end checks passed.');
