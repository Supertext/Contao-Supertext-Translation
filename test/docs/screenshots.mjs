#!/usr/bin/env node
/**
 * Regenerates docs/images/*.png from a FRESH demo (nothing translated yet) whose
 * SUPERTEXT_API_URL points at the stand-in API (stand-in.mjs), so the images show real
 * German, French and Italian. See "Docs screenshots" in docs/DEVELOPER.md.
 *
 *   CONTAO_URL=http://127.0.0.1:8080 EDITOR_EMAIL=… EDITOR_PASSWORD=… \
 *   ADMIN_EMAIL=… ADMIN_PASSWORD=… node test/docs/screenshots.mjs
 */
import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';

const B = (process.env.CONTAO_URL ?? 'http://127.0.0.1:8080').replace(/\/$/, '');
const out = fileURLToPath(new URL('../../docs/images/', import.meta.url));
const need = (name) => process.env[name] ?? (() => { throw new Error(`Set ${name}`); })();

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });

async function session(email, password) {
	const page = await browser.newPage({ viewport: { width: 1200, height: 1000 }, deviceScaleFactor: 1, locale: 'en-US' });
	await page.goto(`${B}/contao/login`);
	await page.fill('input[name=username]', email);
	await page.fill('input[name=password]', password);
	await Promise.all([page.waitForNavigation(), page.click('button[type=submit], input[type=submit]')]);
	return page;
}

/** Screenshot of an element (scrolled to the top of the viewport) with some padding. */
async function shot(page, locator, name, { pad = 8 } = {}) {
	await locator.evaluate((el) => el.scrollIntoView({ block: 'start' }));
	await page.waitForTimeout(200);
	const box = await locator.boundingBox();
	const top = Math.max(0, box.y - pad);
	const vh = page.viewportSize().height;
	await page.screenshot({
		path: out + name,
		clip: { x: Math.max(0, box.x - pad), y: top, width: box.width + 2 * pad, height: Math.min(vh - top, box.height + 2 * pad) },
	});
	console.log('saved', name);
}

/** The site structure with every node expanded ("Expand all" toggles). */
async function siteStructure(page) {
	await page.goto(`${B}/contao?do=page&ptg=all`);
	if (!(await page.getByText('Our team', { exact: true }).first().isVisible().catch(() => false))) await page.goto(`${B}/contao?do=page&ptg=all`);
	await page.getByText('Our team', { exact: true }).first().waitFor();
}

/** ID of the page whose row shows `title`, from its translate link. */
async function pageId(page, title) {
	const id = await page.evaluate((title) => {
		for (const a of document.querySelectorAll('a[href*="/supertext/translate/"]')) {
			// The tree row; the link itself sits in a nested operations menu.
			const row = a.closest('.tl_folder, .tl_file');
			const left = row?.querySelector('.tl_left');
			if (left && [...left.querySelectorAll('*')].some((el) => el.childElementCount === 0 && el.textContent.trim() === title)) return a.href.match(/translate\/(\d+)/)[1];
		}
		return null;
	}, title);
	if (!id) throw new Error(`No translate link for "${title}"`);
	return id;
}

// --- Editor ------------------------------------------------------------------------------
const editor = await session(need('EDITOR_EMAIL'), need('EDITOR_PASSWORD'));
await siteStructure(editor);
await shot(editor, editor.locator('.tl_listing').first(), '01-site-structure.png');

// Before: the translate screen of a page (nothing translated yet).
const englishRoot = await pageId(editor, 'English');
const aboutUs = await pageId(editor, 'About us');
await editor.goto(`${B}/contao/supertext/translate/${aboutUs}`);
await shot(editor, editor.locator('form.supertext-translate'), '02-translate-screen.png');

// Translate the whole English site (website root).
await editor.goto(`${B}/contao/supertext/translate/${englishRoot}`);
const form = editor.locator('form.supertext-translate');

await editor.locator('#st_subpages').check().catch(() => {});
await Promise.all([editor.waitForNavigation({ timeout: 300_000 }), form.locator('button.tl_submit, input.tl_submit').first().click()]);
await shot(editor, editor.locator('form.supertext-translate'), '03-translate-result.png');

// Translating again: existing translations are marked "will be updated".
await editor.goto(`${B}/contao/supertext/translate/${aboutUs}`);
await shot(editor, editor.locator('form.supertext-translate'), '04-retranslate-warning.png');

await siteStructure(editor);
await editor.setViewportSize({ width: 1200, height: 1400 });
await shot(editor, editor.locator('.tl_listing').first(), '05-site-structure-translated.png');
await editor.setViewportSize({ width: 1200, height: 1000 });

// Review and publish the German home page (page settings → Publish page), then view it.
const startseite = await pageId(editor, 'Startseite');
await editor.goto(`${B}/contao?do=page&act=edit&id=${startseite}`);
await editor.locator('input[name="published"][type="checkbox"]').check();
await Promise.all([editor.waitForNavigation(), editor.locator('button[name="save"], #save').first().click()]);
// The demo's website roots require HTTPS; locally there is no TLS proxy in front.
if (B.startsWith('http://')) await editor.setExtraHTTPHeaders({ 'X-Forwarded-Proto': 'https' });
await editor.goto(`${B}/de/startseite`);
await editor.waitForLoadState('networkidle');
await editor.screenshot({ path: out + '06-website-german.png', clip: { x: 0, y: 0, width: 1200, height: 620 } });
console.log('saved 06-website-german.png');

// --- Administrator -----------------------------------------------------------------------
const admin = await session(need('ADMIN_EMAIL'), need('ADMIN_PASSWORD'));

// Language setup: the German website root's settings.
await siteStructure(admin);
const germanRoot = await pageId(admin, 'Deutsch');
await admin.goto(`${B}/contao?do=page&act=edit&id=${germanRoot}`);
const language = admin.locator('#ctrl_language').locator('xpath=ancestor::fieldset[1]');
await shot(admin, language, '07-language-root.png');

// Permission in the user group.
await admin.goto(`${B}/contao?do=group`);
await admin.getByText('Editors', { exact: true }).first().waitFor();
const groupEdit = await admin.evaluate(() => {
	for (const a of document.querySelectorAll('a[href*="do=group&act=edit"]')) if (a.closest('.tl_file, .tl_folder, tr, .tl_content')?.textContent.includes('Editors')) return a.getAttribute('href');
	return null;
});
await admin.goto(new URL(groupEdit, `${B}/`).toString());
// Contao 6 shows the form's sections as tabs.
await admin.getByText('Supertext', { exact: true }).first().click().catch(() => {});
await admin.waitForTimeout(500);
const permission = admin.locator('[name="supertext"]').last().locator('xpath=ancestor::fieldset[1]');
await shot(admin, permission, '08-group-permission.png');

await browser.close();
console.log(`Saved screenshots to ${out}`);
