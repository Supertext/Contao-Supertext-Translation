#!/usr/bin/env node
/**
 * Docs screenshots only: answers like the Supertext AI file translation API and returns
 * real German, French and Italian for the demo's sample content (translations.json,
 * keyed by target language, then by the segment's inner HTML as sent). Unknown
 * segments come back unchanged and are logged. Any API key. Also answers the Commando
 * Center's POST /report (202), so a demo pointed here with SUPERTEXT_REPORT_URL never
 * reports to the live center.
 *
 *   node test/docs/stand-in.mjs     # http://127.0.0.1:8765/v1/
 */
import http from 'node:http';
import { randomBytes } from 'node:crypto';
import { readFileSync } from 'node:fs';

const translations = JSON.parse(readFileSync(new URL('translations.json', import.meta.url), 'utf8'));
const files = new Map();
// Like the live API (GET features, October 2026): only these exact codes.
const TARGETS = new Set(['bg', 'cs', 'da', 'de-AT', 'de-CH', 'de-DE', 'el', 'en-GB', 'en-US', 'es', 'fi', 'fr-CH', 'fr-FR', 'gsw-u-sd-chbe', 'gsw-u-sd-chzh', 'hr', 'hu', 'it-CH', 'it-IT', 'ja', 'ko', 'nb', 'nl', 'pl', 'pt-BR', 'pt-PT', 'rm', 'ru', 'sk', 'sl', 'sq', 'sr-Cyrl', 'sr-Latn', 'sv', 'tr', 'zh-Hans', 'zh-Hant']);
const SOURCES = new Set(['bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'fi', 'fr', 'gsw', 'hr', 'hu', 'it', 'ja', 'ko', 'nb', 'nl', 'pl', 'pt', 'rm', 'ru', 'sk', 'sl', 'sq', 'sr', 'sv', 'tr', 'zh']);
const port = Number(process.env.PORT || 8765);

http
	.createServer(async (req, res) => {
		const path = new URL(req.url, 'http://x').pathname.replace(/^\/v1/, '');
		const send = (status, body, type = 'application/json') => {
			res.writeHead(status, { 'Content-Type': type });
			res.end(type === 'application/json' ? JSON.stringify(body) : body);
		};
		if (path === '/features') return send(200, { supported_features: [] });
		if (req.method === 'POST' && /\/report$/.test(path)) return send(202, { ok: true });
		if (req.method === 'POST' && path === '/translate/ai/file') {
			const chunks = [];
			for await (const chunk of req) chunks.push(chunk);
			const form = await new Request('http://x', { method: 'POST', headers: req.headers, body: Buffer.concat(chunks) }).formData();
			const source = form.get('source_lang') ?? '';
			if (!TARGETS.has(form.get('target_lang') ?? '') || (source !== '' && !SOURCES.has(source))) {
				return send(404, { error_code: 'INVALID_LANGUAGE_PAIR', message: `Invalid language pair ${source} -> ${form.get('target_lang')}` });
			}
			const id = randomBytes(6).toString('hex');
			files.set(id, { html: await form.get('file').text(), target: String(form.get('target_lang')) });
			return send(200, { file_id: id });
		}
		const match = path.match(/file\/([a-f0-9]+)(\/status|\/translation)?$/);
		const file = match && files.get(match[1]);
		if (!file) return send(404, {});
		if (req.method === 'DELETE') return send(204, '');
		if (match[2] === '/status') return send(200, { status: 'done' });
		const dict = translations[file.target] ?? {};
		const html = file.html.replace(/(<div data-st-id="[^"]+">)([\s\S]*?)(<\/div>)/g, (all, open, inner, close) => {
			if (dict[inner] !== undefined) return open + dict[inner] + close;
			console.error(JSON.stringify({ unknown: inner, target: file.target }));
			return all;
		});
		send(200, html, 'text/html');
	})
	.listen(port, () => console.log(`Stand-in API on http://127.0.0.1:${port}/v1/`));
