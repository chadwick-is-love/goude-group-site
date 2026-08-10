// Deterministic HTML surgery. No model output touches structure.
// Ports exactly what shipped by hand for No. 005 on 2026-08-10.
import { topicSection, tocRow, SHARE_SVG, attr } from './templates.mjs';

const pad = (n) => String(n).padStart(3, '0');

function replaceBetween(src, startMarker, endMarker, replacement, label) {
  const i = src.indexOf(startMarker);
  if (i < 0) throw new Error(`assemble: start marker not found (${label})`);
  const j = src.indexOf(endMarker, i + startMarker.length);
  if (j < 0) throw new Error(`assemble: end marker not found (${label})`);
  return src.slice(0, i) + replacement + src.slice(j);
}

/** Build the new current issue from the previous issue (the canon) plus topics. */
export function buildIssue({ canon, num, dateLong, standfirst, topics }) {
  const prev = num - 1;
  let s = canon;

  // 1. head: title, og, description
  s = s.replace(
    new RegExp(`The Briefing, No\\. ${pad(prev)}:`, 'g'),
    `The Briefing, No. ${pad(num)}:`
  );

  // 2. nav tag + masthead meta + share titles: every remaining "No. 00prev" becomes num.
  //    Safe because the archived copy is written from the untouched canon before this runs.
  s = s.split(`No. ${pad(prev)}`).join(`No. ${pad(num)}`);

  // 3. standfirst lede (first paragraph of .standfirst)
  s = replaceBetween(
    s,
    '<span class="lede-tag">This week\'s news:</span>',
    '</p>',
    `<span class="lede-tag">This week's news:</span> ${standfirst}`,
    'standfirst'
  );

  // 4. masthead date (second line of .mast-meta)
  s = s.replace(
    /(<div class="mast-meta">\s*No\. \d{3}<br>\s*)[^<]+/,
    `$1${dateLong}\n      `
  );

  // 5. contents list
  s = replaceBetween(
    s,
    '<div class="toc">\n',
    '    </div>\n    <p class="passnote">',
    '<div class="toc">\n' + topics.map((t, i) => tocRow(t, i + 1)).join('\n') + '\n',
    'toc'
  );

  // 6. topic sections: from the first topic comment to the close section
  const firstTopic = s.indexOf('<!-- ============ 01');
  const close = s.indexOf('<!-- ============ CLOSE ============ -->');
  if (firstTopic < 0 || close < 0) throw new Error('assemble: topic span not found');
  const sections = topics.map((t, i) => topicSection(t, i + 1)).join('\n\n');
  s = s.slice(0, firstTopic) + sections + '\n\n' + s.slice(close);

  // 7. tool JS: replace the calculator/generator bodies between the fence comments
  const js = buildToolJs(topics);
  s = replaceBetween(
    s,
    '/* live calculators */',
    '/* email-me capture',
    js,
    'tool js'
  );

  // 8. subscribe promise and Formspree sources
  s = s.replace(
    /No\. \d{3} comes your way next week/,
    `No. ${pad(num + 1)} comes your way next week`
  );

  const emailTopic = topics.find((t) => t.tool.emailCapture);
  s = s.replace(
    /source:'Briefing No\. \d{3} tool: [^']*'/,
    `source:'Briefing No. ${pad(num)} tool: ${emailTopic ? emailTopic.tool.name : 'none'}'`
  );
  s = s.replace(/source:'Briefing No\. \d{3}'/, `source:'Briefing No. ${pad(num)}'`);

  return s;
}

function buildToolJs(topics) {
  const calcs = topics.filter((t) => t.tool.kind === 'calc');
  const gens = topics.filter((t) => t.tool.kind === 'gen');

  const inputDispatch = calcs
    .map((t) => `  if(el.dataset.live==='${t.tool.idPrefix}') calc_${t.tool.idPrefix}();`)
    .join('\n');

  const changeDispatch = calcs
    .map((t) => `  if(el.dataset.live==='${t.tool.idPrefix}') calc_${t.tool.idPrefix}();`)
    .join('\n');

  const calcFns = calcs.map((t) => t.tool.js.trim()).join('\n\n');

  const genBranches = gens
    .map(
      (t) =>
        `  if(kind==='${t.tool.idPrefix}'){\n${t.tool.js.trim()}\n    show('${t.tool.idPrefix}-out','${t.tool.idPrefix}-copy',out); return;\n  }`
    )
    .join('\n');

  const emailTopic = topics.find((t) => t.tool.emailCapture);
  const emailResult = emailTopic
    ? `  if(kind==='${emailTopic.tool.idPrefix}') result=$('#${emailTopic.tool.idPrefix}-big').textContent+': '+$('#${emailTopic.tool.idPrefix}-cap').textContent;`
    : '';

  return `/* live calculators */
document.addEventListener('input',e=>{
  const el=e.target.closest('[data-live]');
  if(!el) return;
${inputDispatch}
});
document.addEventListener('change',e=>{
  const el=e.target.closest('select[data-live]');
  if(!el) return;
${changeDispatch}
});

${calcFns}

/* generators */
function build(kind){
  const v=id=>($('#'+id).value||'').trim();
${genBranches}
}
function show(outId,copyId,text){
  const o=document.getElementById(outId); o.textContent=text; o.classList.add('show');
  const c=document.getElementById(copyId); if(c) c.style.display='';
}

`;
}

/** Turn the outgoing issue into a back issue at /briefing/archive/no-00N/. */
export function archiveIssue({ prevIssueHtml, prevNum }) {
  let a = prevIssueHtml;
  const base = `https://goudegroup.com/briefing/archive/no-${pad(prevNum)}/`;

  const i = a.indexOf('  <div class="pass-it">');
  if (i < 0) throw new Error('archive: pass-it block not found');
  const j = a.indexOf('\n  </div>\n', i);
  if (j < 0) throw new Error('archive: pass-it end not found');
  const back = `  <div class="pass-it">
    <span class="label">Back issue</span>
    <p>This is back issue No. ${pad(prevNum)}. Every tool in it still runs. <a href="/briefing/">Read the current issue</a> &middot; <a href="/briefing/archive/">Open the archive</a></p>`;
  a = a.slice(0, i) + back + a.slice(j);

  a = a.replace(
    "const ISSUE_URL='https://goudegroup.com/briefing/';",
    `const ISSUE_URL='${base}';`
  );
  a = a.replace(
    '<meta property="og:url" content="https://goudegroup.com/briefing/" />',
    `<meta property="og:url" content="${base}" />`
  );
  // relative asset paths 404 one directory deeper
  a = a.split('src="../goude-logo.png"').join('src="/goude-logo.png"');
  a = a.split('href="../favicon.png"').join('href="/favicon.png"');
  return a;
}

/** Add the new issue group to the archive index and demote the previous one. */
export function updateArchiveIndex({ archiveHtml, num, dateLong, topics }) {
  let a = archiveHtml;
  const prev = num - 1;
  const articles = num * 5;

  const rows = topics
    .map((t, idx) => {
      const n = idx + 1;
      const url = `https://goudegroup.com/briefing/#t${n}`;
      const dtext = [
        t.headline,
        t.tool.name,
        t.tool.sub,
        t.briefs.moved,
        t.briefs.changes,
        t.briefs.avoid,
      ]
        .join(' ')
        .toLowerCase()
        .replace(/<[^>]+>/g, '')
        .replace(/&/g, '&amp;')
        .replace(/'/g, '&#x27;')
        .replace(/"/g, '&quot;');
      return `      <div class="arow" data-text="${dtext}">
        <a class="ah" href="${url}">${t.headline}</a>
        <div class="atool"><span class="atn">${t.tool.name}</span><span class="ats">${t.tool.sub}</span></div>
        <div class="aact"><a class="alink" href="${url}">Open the tool</a><button class="share" type="button" data-url="${url}" data-title="${attr(t.headline)}">${SHARE_SVG}<span class="sl">Send</span></button></div>
      </div>`;
    })
    .join('\n');

  const group = `    <section class="agroup">
      <div class="ghead">
        <span class="gno">No. ${pad(num)}</span><span class="gdate">${dateLong}</span><span class="gtag">This week</span>
        <span class="gspace"></span>
        <a class="glink" href="https://goudegroup.com/briefing/">Read the whole issue</a>
        <button class="share gsend" type="button" data-url="https://goudegroup.com/briefing/" data-title="The Briefing, No. ${pad(num)}: Goude Group Weekly Intelligence">${SHARE_SVG}<span class="sl">Send the issue</span></button>
      </div>
${rows}
    </section>
`;

  // counts
  a = a.replace(/\d+ articles &middot; \d+ tools/, `${articles} articles &middot; ${articles} tools`);
  a = a.replace(/No\. 001 to No\. \d{3}/, `No. 001 to No. ${pad(num)}`);
  a = a.replace(/Showing \d+ of \d+ articles/, `Showing ${articles} of ${articles} articles`);
  a = a.replace(/No matches in \d+ articles\./, `No matches in ${articles} articles.`);

  // demote previous issue: drop the tag, repoint links at its archive folder
  a = a.replace(
    new RegExp(
      `(<span class="gno">No\\. ${pad(prev)}</span><span class="gdate">[^<]*</span>)<span class="gtag">This week</span>`
    ),
    '$1'
  );
  const gStart = a.indexOf(`<span class="gno">No. ${pad(prev)}</span>`);
  if (gStart < 0) throw new Error('archive index: previous group not found');
  const secStart = a.lastIndexOf('    <section class="agroup">', gStart);
  const secEnd = a.indexOf('    </section>', gStart) + '    </section>'.length;
  const oldGroup = a.slice(secStart, secEnd);
  const newGroup = oldGroup
    .split('https://goudegroup.com/briefing/#t')
    .join(`https://goudegroup.com/briefing/archive/no-${pad(prev)}/#t`)
    .split('data-url="https://goudegroup.com/briefing/"')
    .join(`data-url="https://goudegroup.com/briefing/archive/no-${pad(prev)}/"`)
    .split('<a class="glink" href="https://goudegroup.com/briefing/">')
    .join(`<a class="glink" href="https://goudegroup.com/briefing/archive/no-${pad(prev)}/">`);
  a = a.slice(0, secStart) + newGroup + a.slice(secEnd);

  // insert the new group at the top of the stack
  const anchor = '<section class="stack">\n  <div class="stack-inner">\n';
  if (!a.includes(anchor)) throw new Error('archive index: stack anchor not found');
  a = a.replace(anchor, anchor + group, 1);

  a = a.replace(/No\. \d{3} comes your way next week/, `No. ${pad(num + 1)} comes your way next week`);
  return a;
}

/** Retrofit share controls onto a back issue that predates them. Idempotent. */
export function retrofitBackIssue({ html, issueNum }) {
  if (html.includes('data-share=')) return html; // already done
  let s = html;
  const base = `https://goudegroup.com/briefing/archive/no-${pad(issueNum)}/`;

  const cssAdd = `
/* ---------- sharing retrofit ---------- */
.nav-right{display:flex;align-items:center;gap:24px}
.nav-arch{font-size:11px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;border-bottom:1px solid var(--navy-hair);padding-bottom:2px;transition:border-color .2s ease}
.nav-arch:hover{border-color:var(--navy)}
.actions{margin-top:38px;display:flex;align-items:stretch;gap:12px;flex-wrap:wrap}
.actions .deep-toggle{margin-top:0}
.share{display:inline-flex;align-items:center;gap:10px;font-size:11.5px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;padding:15px 22px;border:1.5px solid var(--navy);border-radius:2px;transition:background .25s ease,color .25s ease}
.share:hover{background:var(--navy);color:var(--mint)}
.share svg{width:14px;height:14px;display:block;flex:none}
@media(max-width:620px){
  .nav-right{gap:14px}
  .nav-tag{display:none}
  .actions{flex-direction:column;align-items:stretch}
  .actions .deep-toggle,.actions .share{width:100%;justify-content:center}
  .close-inner{grid-template-columns:minmax(0,1fr)}
  .signup{flex-wrap:wrap;max-width:100%}
  .signup input{flex:1 1 100%;min-width:0}
  .signup button{width:100%}
}
`;
  s = s.replace('@media(prefers-reduced-motion:reduce)', cssAdd + '@media(prefers-reduced-motion:reduce)');

  const navTag = s.match(/<span class="nav-tag">Weekly Intelligence [^<]*<\/span>/);
  if (navTag) {
    s = s.replace(
      navTag[0],
      `<div class="nav-right"><a class="nav-arch" href="/briefing/archive/">Archive</a>${navTag[0]}</div>`
    );
  }

  for (let n = 1; n <= 5; n++) {
    const secRe = new RegExp(
      `<section class="topic" id="t${n}">([\\s\\S]*?)(<button class="deep-toggle" data-deep="t${n}"[^>]*>[\\s\\S]*?<\\/button>)`
    );
    const m = s.match(secRe);
    if (!m) continue;
    const h2 = m[1].match(/<h2>([\s\S]*?)<\/h2>/);
    const title = h2 ? attr(h2[1]) : `The Briefing No. ${pad(issueNum)}`;
    const wrapped = `<div class="actions">\n      ${m[2]}\n      <button class="share" type="button" data-share="t${n}" data-title="${title}">${SHARE_SVG}<span class="sl">Send this one</span></button>\n    </div>`;
    s = s.replace(m[2], wrapped);
  }

  const js = `<script>
(function(){
var BASE='${base}';
function shareLink(id){return id==='issue'?BASE:BASE+'#'+id;}
function flash(btn,msg){var sl=btn.querySelector('.sl');if(!sl)return;var o=sl.textContent;sl.textContent=msg||'Link copied';setTimeout(function(){sl.textContent=o;},1600);}
function legacyCopy(text,done){var ta=document.createElement('textarea');ta.value=text;ta.setAttribute('readonly','');ta.style.position='fixed';ta.style.left='-9999px';document.body.appendChild(ta);ta.select();try{document.execCommand('copy');}catch(err){}document.body.removeChild(ta);if(done)done();}
function copyText(text,done){if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(text).then(done,function(){legacyCopy(text,done);});}else{legacyCopy(text,done);}}
function doShare(btn){var url=shareLink(btn.dataset.share);var title=btn.dataset.title||document.title;if(navigator.share){navigator.share({title:title,url:url}).catch(function(){});}else{copyText(url,function(){flash(btn,'Link copied');});}}
document.addEventListener('click',function(e){var t=e.target.closest('[data-share]');if(t)doShare(t);});
})();
</script>
</body>`;
  s = s.replace('</body>', js);
  return s;
}
