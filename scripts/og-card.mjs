#!/usr/bin/env node
// The share card. Every issue used to share the same URL, the same logo image and
// the same description, so LinkedIn, Slack and iMessage showed No. 010 as a copy of
// No. 009 (and served last week's cached card for the same URL). This renders a card
// per issue, from the built page, to briefing/og/no-NNN.png. Run after assembly.
// PW_CHROMIUM_PATH skips the browser download locally.
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const ROOT = process.cwd();
const html = fs.readFileSync(path.join(ROOT, 'briefing/index.html'), 'utf8');
const num = html.match(/<div class="mast-meta">\s*No\. (\d{3})/)[1];
const date = html.match(/<div class="mast-meta">\s*No\. \d{3}<br>\s*([^<]+)/)[1].trim();
const strip = (s) => s.replace(/<[^>]+>/g, '').replace(/&amp;/g, '&').replace(/&#39;|&rsquo;/g, "'").trim();
const heads = [...html.matchAll(/<section class="topic"[\s\S]*?<h2>([\s\S]*?)<\/h2>/g)].map((m) => strip(m[1]));
const logo = fs.readFileSync(path.join(ROOT, 'goude-logo.png')).toString('base64');
const e = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');

const page = `<!doctype html><html><head><meta charset="utf-8">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;700&family=Playfair+Display:wght@500&display=swap" rel="stylesheet">
<style>
*{margin:0;box-sizing:border-box}
body{width:1200px;height:630px;background:#d8f7c9;color:#06162b;font-family:Inter,sans-serif;padding:56px 72px;display:flex;flex-direction:column}
.top{display:flex;justify-content:space-between;align-items:center}
.top img{height:46px}
.meta{font-size:15px;font-weight:700;letter-spacing:.16em;text-transform:uppercase}
.rule{border-top:2px solid #06162b;margin:30px 0 30px}
h1{font-family:'Playfair Display',Georgia,serif;font-weight:500;font-size:54px;line-height:1.08;letter-spacing:-.02em;flex:1}
.more{font-size:15px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;border-top:1px solid rgba(6,22,43,.2);padding-top:18px;display:flex;justify-content:space-between}
</style></head><body>
<div class="top"><img src="data:image/png;base64,${logo}"><span class="meta">The Briefing &middot; No. ${num} &middot; ${e(date)}</span></div>
<div class="rule"></div>
<h1>${e(heads[0])}</h1>
<div class="more"><span>+ ${heads.length - 1} more, each with a free tool</span><span>goudegroup.com/briefing</span></div>
</body></html>`;

const b = await chromium.launch(process.env.PW_CHROMIUM_PATH ? { executablePath: process.env.PW_CHROMIUM_PATH } : {});
const p = await b.newPage({ viewport: { width: 1200, height: 630 } });
await p.setContent(page, { waitUntil: 'networkidle' });
await p.evaluate(() => document.fonts.ready);
fs.mkdirSync(path.join(ROOT, 'briefing/og'), { recursive: true });
const out = path.join(ROOT, `briefing/og/no-${num}.png`);
await p.screenshot({ path: out });
await b.close();
console.log(`share card written: ${out}`);
