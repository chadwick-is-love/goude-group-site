#!/usr/bin/env node
// The gates. Nothing is committed unless every one of these passes.
// Runs against the built files over a local static server, exactly as the browser will see them.
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import { chromium } from 'playwright';

const ROOT = process.cwd();
const fails = [];
const notes = [];
let mark = 0;
const fail = (m) => { fails.push(m); console.log(`  FAIL  ${m}`); };
const pass = (m) => console.log(`  ok    ${m}`);
const section = () => { mark = fails.length; };
const clean = () => fails.length === mark; // did THIS section pass

// Em dashes are banned in anything that renders. Comments are not output, so strip
// HTML comments and /* */ blocks first; otherwise a CSS note blocks a good issue.
const renderable = (s) =>
  s.replace(/<!--[\s\S]*?-->/g, '').replace(/\/\*[\s\S]*?\*\//g, '');

const MIME = { '.html': 'text/html', '.png': 'image/png', '.json': 'application/json', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };

function serve(root) {
  return new Promise((resolve) => {
    const s = http.createServer((req, res) => {
      let p = decodeURIComponent(req.url.split('?')[0]);
      if (p.endsWith('/')) p += 'index.html';
      const f = path.join(root, p);
      if (!f.startsWith(root) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) {
        res.writeHead(404); return res.end('nope');
      }
      res.writeHead(200, { 'content-type': MIME[path.extname(f)] || 'application/octet-stream' });
      fs.createReadStream(f).pipe(res);
    });
    s.listen(0, () => resolve({ server: s, port: s.address().port }));
  });
}

function pagePaths() {
  const out = ['/', '/briefing/', '/briefing/archive/'];
  for (const d of fs.readdirSync(path.join(ROOT, 'briefing/archive'))) {
    if (/^no-\d{3}$/.test(d)) out.push(`/briefing/archive/${d}/`);
  }
  return out;
}

async function main() {
  const { server, port } = await serve(ROOT);
  const base = `http://127.0.0.1:${port}`;
  // PW_CHROMIUM_PATH lets a sandbox with a preinstalled browser skip the download.
  const browser = await chromium.launch(
    process.env.PW_CHROMIUM_PATH ? { executablePath: process.env.PW_CHROMIUM_PATH } : {}
  );
  const issueHtml = fs.readFileSync(path.join(ROOT, 'briefing/index.html'), 'utf8');
  const num = (issueHtml.match(/<div class="mast-meta">\s*No\. (\d{3})/) || [])[1];

  console.log('\n1. source checks');
  section();
  for (const p of pagePaths()) {
    const f = path.join(ROOT, p === '/' ? 'index.html' : p.slice(1) + 'index.html');
    const s = renderable(fs.readFileSync(f, 'utf8'));
    if (s.includes('—')) {
      const at = s.slice(Math.max(0, s.indexOf('—') - 60), s.indexOf('—') + 60).replace(/\s+/g, ' ');
      fail(`${p} renders an em dash: ...${at}...`);
    }
    if (/nterpolar/i.test(s)) fail(`${p} mentions Interpolar`);
  }
  if (clean()) pass('no em dashes, no cross-venture mentions');

  console.log('\n2. inline script parses');
  section();
  const script = issueHtml.slice(issueHtml.lastIndexOf('<script>') + 8, issueHtml.lastIndexOf('</script>'));
  const tmp = path.join(ROOT, '.briefing-script-check.mjs');
  fs.writeFileSync(tmp, script);
  const { spawnSync } = await import('node:child_process');
  const r = spawnSync(process.execPath, ['--check', tmp], { encoding: 'utf8' });
  fs.unlinkSync(tmp);
  if (r.status !== 0) fail(`inline JS does not parse: ${(r.stderr || '').split('\n').slice(0, 4).join(' ')}`);
  else pass('inline JS parses');

  console.log('\n3. every page at 390 and 1440');
  section();
  for (const p of pagePaths()) {
    for (const w of [390, 1440]) {
      const pg = await browser.newPage({ viewport: { width: w, height: 900 } });
      const errors = [];
      pg.on('pageerror', (e) => errors.push(String(e)));
      pg.on('response', (res) => {
        if (res.status() >= 400 && new URL(res.url()).host === `127.0.0.1:${port}`) {
          errors.push(`${res.status()} ${new URL(res.url()).pathname}`);
        }
      });
      await pg.goto(base + p, { waitUntil: 'networkidle' });
      const sw = await pg.evaluate(() => document.documentElement.scrollWidth);
      if (w === 390 && sw !== 390) fail(`${p} overflows at 390 (scrollWidth ${sw})`);
      if (errors.length) fail(`${p} at ${w}: ${errors.slice(0, 3).join('; ')}`);
      await pg.close();
    }
  }
  if (clean()) pass('no overflow, no page errors, no 404s');

  console.log('\n4. the issue works');
  section();
  const pg = await browser.newPage({ viewport: { width: 1440, height: 950 } });
  await pg.addInitScript(() => {
    navigator.clipboard.writeText = (t) => { window.__copied = t; return Promise.resolve(); };
    Object.defineProperty(navigator, 'share', { value: undefined, configurable: true });
  });
  await pg.goto(base + '/briefing/', { waitUntil: 'networkidle' });

  if (!(await pg.title()).includes(`No. ${num}`)) fail('title does not carry the issue number');
  if ((await pg.locator('section.topic').count()) !== 5) fail('there are not exactly 5 topics');

  for (let i = 1; i <= 5; i++) await pg.click(`button.deep-toggle[data-deep="t${i}"]`);
  const opened = await pg.evaluate(() => document.querySelectorAll('.topic.open').length);
  if (opened !== 5) fail(`only ${opened} of 5 topics expand`);
  else pass('all five expand');

  // every tool must produce output from plausible input
  const tools = await pg.evaluate(() =>
    [...document.querySelectorAll('.tool')].map((el) => ({
      prefix: el.dataset.tool,
      kind: el.querySelector('[data-build]') ? 'gen' : 'calc',
      fields: [...el.querySelectorAll('input,textarea,select')]
        .filter((f) => f.id)
        .map((f) => ({ id: f.id, tag: f.tagName, type: f.type })),
    }))
  );
  for (const t of tools) {
    for (const f of t.fields) {
      if (f.type === 'email') continue;
      if (f.tag === 'SELECT') {
        const vals = await pg.$eval(`#${f.id}`, (s) => [...s.options].map((o) => o.value).filter(Boolean));
        if (vals.length) await pg.selectOption(`#${f.id}`, vals[vals.length - 1]);
      } else if (f.type === 'number') {
        await pg.fill(`#${f.id}`, '1200');
      } else {
        await pg.fill(`#${f.id}`, 'Test Company');
      }
    }
    if (t.kind === 'gen') {
      await pg.click(`[data-build="${t.prefix}"]`);
      const out = (await pg.locator(`#${t.prefix}-out`).innerText()).trim();
      if (out.length < 200) fail(`tool ${t.prefix} produced ${out.length} chars, expected a real document`);
      else pass(`tool ${t.prefix} generated ${out.length} chars`);
    } else {
      const visible = await pg.locator(`#${t.prefix}-readout`).isVisible();
      const big = visible ? (await pg.locator(`#${t.prefix}-big`).innerText()).trim() : '';
      const cap = visible ? (await pg.locator(`#${t.prefix}-cap`).innerText()).trim() : '';
      if (!visible) fail(`tool ${t.prefix} readout stayed hidden with all fields filled`);
      else if (!big || /^\$?0(\/|$)/.test(big)) fail(`tool ${t.prefix} computed "${big}"`);
      else if (cap.length < 60) fail(`tool ${t.prefix} caption is ${cap.length} chars, too thin`);
      else pass(`tool ${t.prefix} computed ${big}`);
    }
  }

  // share links
  for (let i = 1; i <= 5; i++) {
    await pg.click(`button[data-share="t${i}"]`);
    const got = await pg.evaluate(() => window.__copied);
    const want = `https://goudegroup.com/briefing/#t${i}`;
    if (got !== want) fail(`share t${i} copied "${got}", expected "${want}"`);
  }
  await pg.click('.passrow button[data-share="issue"]');
  if ((await pg.evaluate(() => window.__copied)) !== 'https://goudegroup.com/briefing/') fail('issue share link wrong');
  if (clean()) pass('every share button copies the right link');
  await pg.close();

  // deep link
  const dp = await browser.newPage({ viewport: { width: 1440, height: 950 } });
  await dp.goto(base + '/briefing/#t3', { waitUntil: 'networkidle' });
  await dp.waitForTimeout(400);
  if (!(await dp.evaluate(() => document.getElementById('t3').classList.contains('open')))) fail('#t3 deep link does not open topic 3');
  else pass('#t3 deep link opens');
  await dp.close();

  console.log('\n5. the archive');
  section();
  const ap = await browser.newPage({ viewport: { width: 1440, height: 950 } });
  await ap.goto(base + '/briefing/archive/', { waitUntil: 'networkidle' });
  const rows = await ap.locator('.arow').count();
  const expected = Number(num) * 5;
  if (rows !== expected) fail(`archive lists ${rows} articles, expected ${expected}`);
  const count = await ap.locator('#fcount').innerText();
  if (!count.toLowerCase().includes(`${expected} of ${expected}`)) fail(`archive count reads "${count}"`);
  const firstGroup = await ap.locator('.agroup').first().innerText();
  if (!firstGroup.includes(`No. ${num}`)) fail('the new issue is not the first archive group');
  if (!firstGroup.toLowerCase().includes('this week')) fail('the This week tag is not on the new issue');
  const tagCount = await ap.locator('.gtag').count();
  if (tagCount !== 1) fail(`${tagCount} "This week" tags, expected 1`);
  if (clean()) pass(`archive: ${rows} articles, tag on No. ${num}`);
  await ap.close();

  await browser.close();
  server.close();

  console.log('\n' + '='.repeat(60));
  if (fails.length) {
    console.log(`${fails.length} gate failure(s). NOT publishing.`);
    fails.forEach((f) => console.log(` - ${f}`));
    process.exit(1);
  }
  notes.forEach((n) => console.log(`note: ${n}`));
  console.log('all gates passed');
}

main().catch((e) => {
  console.error(`::error::gates crashed: ${e.stack}`);
  process.exit(1);
});
