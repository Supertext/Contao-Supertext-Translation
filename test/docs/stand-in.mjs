#!/usr/bin/env node
/**
 * Docs screenshots only: answers like the Supertext AI file translation API and returns
 * real German, French and Italian for the demo's sample content (translations.json,
 * keyed by target language, then by the segment's inner HTML as sent). Unknown
 * segments come back unchanged and are logged. Any API key.
 *
 *   node test/docs/stand-in.mjs     # http://127.0.0.1:8765/v1/
 */
import http from 'node:http';
import { randomBytes } from 'node:crypto';
import { readFileSync } from 'node:fs';

const translations = JSON.parse(readFileSync(new URL('translations.json', import.meta.url), 'utf8'));
const files = new Map();
const port = Number(process.env.PORT || 8765);

http
	.createServer(async (req, res) => {
		const path = new URL(req.url, 'http://x').pathname.replace(/^\/v1/, '');
		const send = (status, body, type = 'application/json') => {
			res.writeHead(status, { 'Content-Type': type });
			res.end(type === 'application/json' ? JSON.stringify(body) : body);
		};
		if (path === '/features') return send(200, {});
		if (req.method === 'POST' && path === '/translate/ai/file') {
			const chunks = [];
			for await (const chunk of req) chunks.push(chunk);
			const form = await new Request('http://x', { method: 'POST', headers: req.headers, body: Buffer.concat(chunks) }).formData();
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
