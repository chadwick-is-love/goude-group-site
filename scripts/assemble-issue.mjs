#!/usr/bin/env node
// Deterministic half of the build. Reads build/topics.json (written by the research
// and writing step) and produces the issue, the archived back issue, and the archive
// index. No network, no model, no API key. Runs the same whoever wrote the JSON.
import fs from 'node:fs';
import path from 'node:path';
import { buildIssue, archiveIssue, updateArchiveIndex, retrofitBackIssue } from './lib/assemble.mjs';

const ROOT = process.cwd();
const INPUT = path.join(ROOT, 'build/topics.json');
const pad = (n) => String(n).padStart(3, '0');

const BANNED = [
  'honestly', 'frankly', 'truthfully', 'at the end of the day', 'that being said',
  'it is worth noting', "it's worth noting", 'seamless', 'game-chang', 'unlock',
  'supercharge', 'cutting-edge', 'future-proof', 'ai-powered', 'revolutioniz',
  'delve', 'navigate the', 'robust',
];

function nyDate() {
  const p = Object.fromEntries(
    new Intl.DateTimeFormat('en-US', {
      timeZone: 'America/New_York', year: 'numeric', month: 'long', day: 'numeric',
    }).formatToParts(new Date()).map((x) => [x.type, x.value])
  );
  return `${p.month} ${Number(p.day)}, ${p.year}`;
}

function scrubEmDash(obj, found = { n: 0 }) {
  if (typeof obj === 'string') {
    if (obj.includes('—')) found.n++;
    return obj.replace(/\s*—\s*/g, ', ');
  }
  if (Array.isArray(obj)) return obj.map((v) => scrubEmDash(v, found));
  if (obj && typeof obj === 'object') {
    return Object.fromEntries(Object.entries(obj).map(([k, v]) => [k, scrubEmDash(v, found)]));
  }
  return obj;
}

function validateTopic(t, n) {
  const errs = [];
  const need = (c, m) => { if (!c) errs.push(`topic ${n}: ${m}`); };
  need(t.headline && t.headline.length > 20, 'headline missing or too short');
  need(t.briefs && t.briefs.moved && t.briefs.changes && t.briefs.avoid, 'a brief column is missing');
  for (const k of ['moved', 'changes', 'avoid']) {
    const w = (t.briefs?.[k] || '').split(/\s+/).length;
    need(w >= 35 && w <= 110, `brief "${k}" is ${w} words, want 35 to 110`);
  }
  need(Array.isArray(t.tape) && t.tape.length >= 3, 'tape needs at least 3 rows');
  need(Array.isArray(t.prose) && t.prose.length >= 2, 'deep read needs 2 paragraphs');
  need(Array.isArray(t.play) && t.play.length >= 3, 'the play needs at least 3 steps');
  need(Array.isArray(t.sources) && t.sources.length >= 2, 'needs at least 2 sources');
  (t.sources || []).forEach((s) => need(/^https?:\/\//.test(s.url || ''), `bad source url: ${s.url}`));
  need(t.pull && t.pull.split(/\s+/).length <= 35, 'pull quote missing or too long');
  const tool = t.tool || {};
  need(['calc', 'gen'].includes(tool.kind), 'tool.kind must be calc or gen');
  need(/^[a-z]{2,4}$/.test(tool.idPrefix || ''), `tool.idPrefix "${tool.idPrefix}" must be 2 to 4 lowercase letters`);
  need(tool.name && tool.sub, 'tool needs a name and sub');
  need(Array.isArray(tool.fields) && tool.fields.length >= 2, 'tool needs at least 2 fields');
  need(typeof tool.js === 'string' && tool.js.length > 80, 'tool.js missing or too short');
  if (tool.kind === 'calc') {
    need(tool.js.includes(`function calc_${tool.idPrefix}`), `tool.js must define function calc_${tool.idPrefix}`);
    need(tool.js.includes(`${tool.idPrefix}-big`) && tool.js.includes(`${tool.idPrefix}-cap`), 'calc must write both big and cap');
  } else {
    need(/\bout\s*=/.test(tool.js), 'gen tool.js must assign to out');
    need(!tool.js.includes('function '), 'gen tool.js is a branch body, not a function');
  }
  need(!/`/.test(tool.js), 'tool.js must not use template literals');
  need(!/localStorage|sessionStorage|fetch\(/.test(tool.js), 'tool.js must not use storage or fetch');
  (tool.fields || []).forEach((f) =>
    need(/^[a-z0-9]{1,10}$/.test(f.id || ''), `field id "${f.id}" must be short and lowercase`)
  );
  const blob = JSON.stringify(t).toLowerCase();
  BANNED.forEach((w) => need(!blob.includes(w), `banned language: "${w}"`));
  return errs;
}

function main() {
  if (!fs.existsSync(INPUT)) {
    throw new Error(
      'build/topics.json does not exist. The research and writing step did not produce it, ' +
      'so there is nothing to assemble. Nothing has been changed.'
    );
  }
  const raw = JSON.parse(fs.readFileSync(INPUT, 'utf8'));
  const found = { n: 0 };
  const data = scrubEmDash(raw, found);
  if (found.n) console.log(`replaced ${found.n} em dash(es) in the copy`);

  if (!Array.isArray(data.topics) || data.topics.length !== 5) {
    throw new Error(`topics.json has ${data.topics?.length} topics, need exactly 5`);
  }
  if (!data.standfirst) throw new Error('topics.json has no standfirst');

  const errs = [];
  const prefixes = new Set();
  data.topics.forEach((t, i) => {
    errs.push(...validateTopic(t, i + 1));
    if (prefixes.has(t.tool?.idPrefix)) errs.push(`topic ${i + 1}: idPrefix "${t.tool.idPrefix}" is used twice`);
    prefixes.add(t.tool?.idPrefix);
  });
  if (errs.length) {
    throw new Error(`the written articles failed validation:\n${errs.map((e) => ' - ' + e).join('\n')}`);
  }

  // at most one email capture, and only on a calculator
  let seen = false;
  data.topics.forEach((t) => {
    if (t.tool.emailCapture && t.tool.kind === 'calc' && !seen) seen = true;
    else delete t.tool.emailCapture;
  });

  const canon = fs.readFileSync(path.join(ROOT, 'briefing/index.html'), 'utf8');
  const archive = fs.readFileSync(path.join(ROOT, 'briefing/archive/index.html'), 'utf8');
  const prevNum = Number(canon.match(/<div class="mast-meta">\s*No\. (\d{3})/)[1]);
  const num = prevNum + 1;
  const dateLong = nyDate();

  console.log(`assembling No. ${pad(num)} for ${dateLong}, from No. ${pad(prevNum)}`);
  data.topics.forEach((t, i) => console.log(`  ${i + 1}. [${t.domain || '?'}] ${t.headline}`));

  const domains = data.topics.map((t) => t.domain).filter(Boolean);
  if (domains.length === 5 && new Set(domains).size <= 2) {
    console.log(`::warning::only ${new Set(domains).size} distinct domains across five topics. Check for single-subject drift.`);
  }

  const dir = path.join(ROOT, 'briefing/archive', `no-${pad(prevNum)}`);
  fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(path.join(dir, 'index.html'), archiveIssue({ prevIssueHtml: canon, prevNum }));
  console.log(`archived No. ${pad(prevNum)}`);

  fs.writeFileSync(
    path.join(ROOT, 'briefing/index.html'),
    buildIssue({ canon, num, dateLong, standfirst: data.standfirst, topics: data.topics })
  );
  fs.writeFileSync(
    path.join(ROOT, 'briefing/archive/index.html'),
    updateArchiveIndex({ archiveHtml: archive, num, dateLong, topics: data.topics })
  );

  for (const d of fs.readdirSync(path.join(ROOT, 'briefing/archive'))) {
    const m = d.match(/^no-(\d{3})$/);
    if (!m || Number(m[1]) === prevNum) continue;
    const p = path.join(ROOT, 'briefing/archive', d, 'index.html');
    if (!fs.existsSync(p)) continue;
    const before = fs.readFileSync(p, 'utf8');
    const after = retrofitBackIssue({ html: before, issueNum: Number(m[1]) });
    if (after !== before) { fs.writeFileSync(p, after); console.log(`retrofitted ${d}`); }
  }

  fs.writeFileSync(
    path.join(ROOT, '.briefing-build.json'),
    JSON.stringify({
      issue: num,
      date: dateLong,
      headlines: data.topics.map((t, i) => ({ n: i + 1, domain: t.domain, headline: t.headline, tool: t.tool.name })),
    }, null, 2)
  );

  console.log(`\nNo. ${pad(num)} assembled. Gates run next; nothing is committed until they pass.`);
}

try {
  main();
} catch (e) {
  console.error(`::error::assembly failed: ${e.message}`);
  process.exit(1);
}
