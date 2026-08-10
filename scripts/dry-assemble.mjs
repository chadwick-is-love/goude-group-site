#!/usr/bin/env node
// Offline rehearsal of the assembly path with fixture content.
// Proves the deterministic half of the pipeline without spending an API call.
import fs from 'node:fs';
import path from 'node:path';
import { buildIssue, archiveIssue, updateArchiveIndex, retrofitBackIssue } from './lib/assemble.mjs';

const ROOT = process.cwd();
const pad = (n) => String(n).padStart(3, '0');
const canon = fs.readFileSync(path.join(ROOT, 'briefing/index.html'), 'utf8');
const archive = fs.readFileSync(path.join(ROOT, 'briefing/archive/index.html'), 'utf8');
const prevNum = Number(canon.match(/<div class="mast-meta">\s*No\. (\d{3})/)[1]);
const num = prevNum + 1;

const words = (n, seed) =>
  Array.from({ length: n }, (_, i) => `${seed}${i % 7 === 0 ? ' figure' : ''} word`).join(' ');

const mkCalc = (p) => ({
  kind: 'calc',
  idPrefix: p,
  name: `The ${p.toUpperCase()} Meter`,
  sub: 'Two numbers. It prices the decision this article says you face, to the dollar.',
  kicker: 'The instrument &middot; live',
  fields: [
    { id: 'a', label: 'First number ($)', type: 'number', placeholder: 'e.g. 250000' },
    { id: 'b', label: 'Second number (%)', type: 'number', placeholder: 'e.g. 9.5' },
  ],
  js: `function calc_${p}(){
  const a=parseFloat($('#${p}-a').value)||0;
  const b=parseFloat($('#${p}-b').value)||0;
  const ro=$('#${p}-readout');
  if(!a||!b){ ro.style.display='none'; return; }
  ro.style.display='block';
  const yr=a*b/100;
  $('#${p}-big').textContent=money(yr)+'/yr';
  $('#${p}-cap').innerHTML='At <b>'+b+' percent</b> on <b>'+money(a)+'</b>, this costs you <b>'+money(yr)+' a year</b>, about <b>'+money(yr/12)+' a month</b>. That is the number to hold before the decision lands, not after it shows up on a statement you are already committed to.';
}`,
});

const mkGen = (p) => ({
  kind: 'gen',
  idPrefix: p,
  name: `The ${p.toUpperCase()} Letter`,
  sub: 'Three fields. It writes the document you send this week.',
  kicker: 'The instrument &middot; writes it',
  buttonLabel: 'Write it',
  fields: [
    { id: 'co', label: 'Company name', type: 'text', placeholder: 'Your company' },
    { id: 'list', label: 'One per line', type: 'textarea', rows: 4, placeholder: 'Item one' },
    { id: 'own', label: 'Who owns it', type: 'text', placeholder: 'Name and role' },
  ],
  js: `    const co=v('${p}-co')||'[Company]';
    const raw=($('#${p}-list').value||'').split(/\\n|,/).map(s=>s.trim()).filter(Boolean);
    const items=raw.length?raw:['[item]'];
    const own=v('${p}-own')||'[owner]';
    let rows='';
    items.forEach(s=>{ rows+='  '+s+'\\n    OWNER: [name]   DUE: [date]\\n'; });
    const out=
'THE DOCUMENT \\u00b7 '+co+'\\n\\n'+
'Why this exists, stated so a stranger on your team could follow it without asking you a question first. It names the decision, the deadline, and who carries it.\\n\\n'+
'1. THE LIST. Everything in scope, with an owner and a date against each line:\\n'+rows+'\\n'+
'2. THE RULE. Anything not on this list waits for the next review rather than jumping the queue on somebody being persuasive in a hallway.\\n\\n'+
'3. THE CHECK. '+own+' reviews this weekly and closes each line with a yes, a fix, or a deliberate drop.\\n\\n'+
'Owner: '+own+'. Review date: [date].';`,
});

const topics = [1, 2, 3, 4, 5].map((n) => ({
  kicker: ['The Number', 'The Reprice', 'The Refund', 'The Deadline', 'The Filing'][n - 1],
  readTime: '45 sec',
  headline: `Fixture headline number ${n} states what moved and what to do about it.`,
  go: 'The read',
  briefs: {
    moved: words(60, `moved${n} `),
    changes: words(60, `changes${n} `),
    avoid: words(60, `avoid${n} `),
  },
  proseHeading: 'A heading for the deep read',
  prose: [words(95, `prose${n}a `), words(95, `prose${n}b `)],
  tape: [
    { d: 'Aug 3', text: words(30, 'tape1 ') },
    { d: 'Aug 5', text: words(30, 'tape2 ') },
    { d: 'Aug 7', text: words(30, 'tape3 ') },
    { d: 'Next', text: words(30, 'tape4 ') },
  ],
  play: [words(22, 'play1 '), words(22, 'play2 '), words(22, 'play3 '), words(22, 'play4 ')],
  pull: 'A pull quote that is sharp enough to sit alone in the margin of the page.',
  sources: [
    { label: 'Source one: what it covers', url: 'https://example.com/one' },
    { label: 'Source two: what it covers', url: 'https://example.com/two' },
  ],
  tool: n % 2 === 1 ? mkCalc(['aa', 'bb', 'cc'][Math.floor(n / 2)]) : mkGen(['dd', 'ee'][n / 2 - 1]),
}));
topics[0].tool.emailCapture = true;

const dateLong = 'August 17, 2026';
fs.mkdirSync(path.join(ROOT, 'briefing/archive', `no-${pad(prevNum)}`), { recursive: true });
fs.writeFileSync(
  path.join(ROOT, 'briefing/archive', `no-${pad(prevNum)}`, 'index.html'),
  archiveIssue({ prevIssueHtml: canon, prevNum })
);
fs.writeFileSync(
  path.join(ROOT, 'briefing/index.html'),
  buildIssue({ canon, num, dateLong, standfirst: 'a fixture standfirst listing what moved this week in plain language.', topics })
);
fs.writeFileSync(
  path.join(ROOT, 'briefing/archive/index.html'),
  updateArchiveIndex({ archiveHtml: archive, num, dateLong, topics })
);
for (const dir of fs.readdirSync(path.join(ROOT, 'briefing/archive'))) {
  const m = dir.match(/^no-(\d{3})$/);
  if (!m || Number(m[1]) === prevNum) continue;
  const p = path.join(ROOT, 'briefing/archive', dir, 'index.html');
  const before = fs.readFileSync(p, 'utf8');
  const after = retrofitBackIssue({ html: before, issueNum: Number(m[1]) });
  if (after !== before) { fs.writeFileSync(p, after); console.log('retrofitted', dir); }
}
console.log(`fixture issue No. ${pad(num)} assembled from No. ${pad(prevNum)}`);
