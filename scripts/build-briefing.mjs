#!/usr/bin/env node
// Builds the next issue of The Briefing, writes files, and leaves committing to the workflow.
// Every model output is validated before it touches the page. Structure is never generated.
import fs from 'node:fs';
import path from 'node:path';
import { ask, resolveModel, extractJson } from './lib/api.mjs';
import { buildIssue, archiveIssue, updateArchiveIndex, retrofitBackIssue } from './lib/assemble.mjs';

const ROOT = process.cwd();
const ISSUE = path.join(ROOT, 'briefing/index.html');
const ARCHIVE = path.join(ROOT, 'briefing/archive/index.html');
const pad = (n) => String(n).padStart(3, '0');
const readPrompt = (f) =>
  fs.readFileSync(path.join(import.meta.dirname, 'prompts', f), 'utf8');

const BANNED = [
  'honestly', 'frankly', 'truthfully', 'at the end of the day', 'that being said',
  'it is worth noting', "it's worth noting", 'seamless', 'game-chang', 'unlock',
  'supercharge', 'cutting-edge', 'future-proof', 'ai-powered', 'revolutioniz',
  'delve', 'navigate the', 'in today', 'robust',
];

function nyNow() {
  const fmt = new Intl.DateTimeFormat('en-US', {
    timeZone: 'America/New_York',
    year: 'numeric', month: 'long', day: 'numeric', weekday: 'short', hour: '2-digit', hour12: false,
  });
  const parts = Object.fromEntries(fmt.formatToParts(new Date()).map((p) => [p.type, p.value]));
  return {
    long: `${parts.month} ${Number(parts.day)}, ${parts.year}`,
    iso: new Date().toISOString().slice(0, 10),
    weekday: parts.weekday,
    hour: Number(parts.hour),
  };
}

function currentNumber(html) {
  const m = html.match(/<div class="mast-meta">\s*No\. (\d{3})/);
  if (!m) throw new Error('could not read the current issue number from the masthead');
  return Number(m[1]);
}

function recentHeadlines(archiveHtml, howMany = 10) {
  const out = [];
  const re = /<a class="ah" href="[^"]*">([\s\S]*?)<\/a>/g;
  let m;
  while ((m = re.exec(archiveHtml)) && out.length < howMany) {
    out.push('- ' + m[1].replace(/<[^>]+>/g, '').replace(/&#x27;/g, "'").replace(/&quot;/g, '"').trim());
  }
  return out.join('\n');
}

function voiceSample(issueHtml) {
  const m = issueHtml.match(/<section class="topic" id="t1">[\s\S]*?<\/section>/);
  return m ? m[0].replace(/\s+/g, ' ').slice(0, 6000) : '';
}

function scrubEmDash(obj, found = { n: 0 }) {
  if (typeof obj === 'string') {
    if (obj.includes('—')) found.n++;
    return obj.replace(/\s*—\s*/g, ', ');
  }
  if (Array.isArray(obj)) return obj.map((v) => scrubEmDash(v, found));
  if (obj && typeof obj === 'object') {
    const o = {};
    for (const [k, v] of Object.entries(obj)) o[k] = scrubEmDash(v, found);
    return o;
  }
  return obj;
}

function validateTopic(t, n) {
  const errs = [];
  const need = (cond, msg) => { if (!cond) errs.push(`topic ${n}: ${msg}`); };
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
  (t.sources || []).forEach((s) =>
    need(/^https?:\/\//.test(s.url || ''), `source url looks wrong: ${s.url}`)
  );
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
  BANNED.forEach((w) => need(!blob.includes(w), `banned language present: "${w}"`));
  return errs;
}

async function main() {
  const now = nyNow();
  const canon = fs.readFileSync(ISSUE, 'utf8');
  const archive = fs.readFileSync(ARCHIVE, 'utf8');
  const prevNum = currentNumber(canon);
  const num = prevNum + 1;

  console.log(`The Briefing: building No. ${pad(num)} for ${now.long} (current live issue is No. ${pad(prevNum)})`);

  const model = await resolveModel();
  console.log(`model: ${model}`);

  // ---------- research ----------
  const research = extractJson(
    await ask({
      model,
      search: true,
      maxTokens: 20000,
      system:
        'You are a rigorous business editor. You verify before you assert, you never invent a source, and you rank by consequence to the reader.',
      prompt: readPrompt('research.md')
        .replace('{{DATE}}', now.long)
        .replace('{{NUM}}', pad(num))
        .replace('{{RECENT}}', recentHeadlines(archive)),
    })
  );

  if (!Array.isArray(research.topics) || research.topics.length !== 5) {
    throw new Error(`research returned ${research.topics?.length} topics, need exactly 5`);
  }
  const domains = research.topics.map((t) => t.domain);
  console.log('domains chosen:', domains.join(', '));
  if (new Set(domains).size <= 2) {
    console.log(`::warning::only ${new Set(domains).size} distinct domains across five topics. Check for single-subject drift.`);
  }
  research.topics.forEach((t, i) => console.log(`  ${i + 1}. [${t.domain}] ${t.headline}`));

  // ---------- one call per article ----------
  const sample = voiceSample(canon);
  const topics = [];
  const usedPrefixes = new Set();

  for (const [i, spec] of research.topics.entries()) {
    const n = i + 1;
    console.log(`\nwriting article ${n}: ${spec.headline}`);
    let topic, errs;
    for (let attempt = 1; attempt <= 3; attempt++) {
      const extra =
        attempt === 1
          ? ''
          : `\n\n# CORRECTIONS REQUIRED\nYour previous attempt failed validation:\n${errs.map((e) => '- ' + e).join('\n')}\nFix every one and return the whole object again.`;
      const raw = await ask({
        model,
        maxTokens: 16000,
        system:
          'You write for busy operators. Concrete, commercial, no hype. You never use em dashes. You return only valid JSON.',
        prompt:
          readPrompt('topic.md')
            .replaceAll('{{NUM}}', pad(num))
            .replaceAll('{{DATE}}', now.long)
            .replaceAll('{{N}}', String(n))
            .replaceAll('{{HEADLINE}}', spec.headline)
            .replaceAll('{{KICKER}}', spec.kicker)
            .replaceAll('{{GO}}', spec.go)
            .replaceAll('{{DOMAIN}}', spec.domain)
            .replaceAll('{{WHY}}', spec.why_it_ranks || '')
            .replaceAll(
              '{{FACTS}}',
              (spec.facts || []).map((f) => `- ${f.claim} (${f.url})`).join('\n')
            )
            .replaceAll(
              '{{SOURCES}}',
              (spec.sources || []).map((s) => `- ${s.label}: ${s.url}`).join('\n')
            )
            .replaceAll('{{VOICE_SAMPLE}}', sample) + extra,
      });
      const found = { n: 0 };
      topic = scrubEmDash(extractJson(raw), found);
      if (found.n) console.log(`  replaced ${found.n} em dash(es)`);
      errs = validateTopic(topic, n);
      if (usedPrefixes.has(topic.tool?.idPrefix)) errs.push(`topic ${n}: idPrefix "${topic.tool.idPrefix}" already used`);
      if (!errs.length) break;
      console.log(`  attempt ${attempt} failed validation:\n${errs.map((e) => '   - ' + e).join('\n')}`);
      if (attempt === 3) throw new Error(`article ${n} failed validation three times:\n${errs.join('\n')}`);
    }
    usedPrefixes.add(topic.tool.idPrefix);
    topic.headline = spec.headline;
    topic.go = spec.go;
    topic.kicker = spec.kicker;
    topics.push(topic);
    console.log(`  ok: ${topic.tool.name} (${topic.tool.kind})`);
  }

  // at most one email capture
  let seenCapture = false;
  topics.forEach((t) => {
    if (t.tool.emailCapture && t.tool.kind === 'calc' && !seenCapture) seenCapture = true;
    else delete t.tool.emailCapture;
  });

  // ---------- assemble ----------
  console.log('\nassembling');
  const newIssue = buildIssue({
    canon,
    num,
    dateLong: now.long,
    standfirst: scrubEmDash(research.standfirst || ''),
    topics,
  });

  const archiveDir = path.join(ROOT, 'briefing/archive', `no-${pad(prevNum)}`);
  fs.mkdirSync(archiveDir, { recursive: true });
  fs.writeFileSync(path.join(archiveDir, 'index.html'), archiveIssue({ prevIssueHtml: canon, prevNum }));
  console.log(`archived No. ${pad(prevNum)} to briefing/archive/no-${pad(prevNum)}/`);

  fs.writeFileSync(ISSUE, newIssue);
  fs.writeFileSync(
    ARCHIVE,
    updateArchiveIndex({ archiveHtml: archive, num, dateLong: now.long, topics })
  );

  // idempotent retrofit of older back issues
  for (const dir of fs.readdirSync(path.join(ROOT, 'briefing/archive'))) {
    const m = dir.match(/^no-(\d{3})$/);
    if (!m || Number(m[1]) === prevNum) continue;
    const p = path.join(ROOT, 'briefing/archive', dir, 'index.html');
    if (!fs.existsSync(p)) continue;
    const before = fs.readFileSync(p, 'utf8');
    const after = retrofitBackIssue({ html: before, issueNum: Number(m[1]) });
    if (after !== before) {
      fs.writeFileSync(p, after);
      console.log(`retrofitted share controls onto ${dir}`);
    }
  }

  fs.writeFileSync(
    path.join(ROOT, '.briefing-build.json'),
    JSON.stringify(
      {
        issue: num,
        date: now.long,
        model,
        headlines: topics.map((t, i) => ({ n: i + 1, domain: research.topics[i].domain, headline: t.headline, tool: t.tool.name })),
      },
      null,
      2
    )
  );

  console.log(`\nbuilt No. ${pad(num)}. Gates run next; nothing is committed until they pass.`);
}

main().catch((e) => {
  console.error(`::error::build failed: ${e.message}`);
  process.exit(1);
});
