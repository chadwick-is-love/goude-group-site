// studio-autoqueue.mjs - STUDIO_AUTOPOST_2026-10-13
// Opens the Studio headless, lets it load the newest source for one brand,
// and fills the open slots (?auto=queue): each slot and network not queued
// yet is scheduled in OmniSocials for its time (2026-10-15); anything already
// scheduled, rejected or cancelled is left alone. Stop a post by cancelling
// it on goudegroup.com/studio/approve.php.
//
//   STUDIO_USER=... STUDIO_PASS=... node scripts/studio-autoqueue.mjs goude
//   STUDIO_URL overrides https://goudegroup.com/studio/ (the test rig uses it)
//
// Exit 0 when the run finished (even with zero posts, e.g. every slot passed),
// 1 when the Studio could not load a source or the queue refused everything
// for a reason other than a passed slot.
import { chromium } from 'playwright';

const brand = process.argv[2] === 'gabes' ? 'gabes' : 'goude';
const base = process.env.STUDIO_URL || 'https://goudegroup.com/studio/';
const user = process.env.STUDIO_USER, pass = process.env.STUDIO_PASS;
if (!user || !pass) { console.error('STUDIO_USER and STUDIO_PASS are required (the Studio login).'); process.exit(1); }

const browser = await chromium.launch();
const page = await browser.newPage({ httpCredentials: { username: user, password: pass }, viewport: { width: 1400, height: 1000 } });
const errors = [];
page.on('pageerror', e => errors.push(String(e)));
await page.goto(base + '?brand=' + brand + '&auto=queue', { waitUntil: 'domcontentloaded' });
await page.waitForFunction(() => window.__STUDIO_AUTO && window.__STUDIO_AUTO.done, null, { timeout: 300000 });
const out = await page.evaluate(() => ({ result: window.__STUDIO_AUTO.result, status: (document.getElementById('autoStatus') || {}).textContent || '' }));
await browser.close();

const r = out.result || {};
console.log(out.status || JSON.stringify(r));
if (errors.length) console.log('page errors: ' + errors.slice(0, 3).join(' | '));
const hard = (r.failed || []).filter(m => !/has passed|already scheduled/.test(m));
if (r.error || (!r.queued && hard.length)) { console.error(r.error || hard[0]); process.exit(1); }
process.exit(0);
