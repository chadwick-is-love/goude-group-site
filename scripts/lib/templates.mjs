// Fixed structural templates. The model fills slots; it never writes page structure.
// Any change to the issue's HTML grammar happens HERE, deliberately, not in a model output.

export const SHARE_SVG =
  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v9"/><path d="M8.5 8.5 12 5l3.5 3.5"/><path d="M5 12v6a1.5 1.5 0 0 0 1.5 1.5h11A1.5 1.5 0 0 0 19 18v-6"/></svg>';

const esc = (s) =>
  String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
export const attr = (s) =>
  String(s).replace(/<[^>]+>/g, '').replace(/&/g, '&amp;').replace(/"/g, '&quot;');

// The instrument panel. Two kinds only, both proven in the shipped issues.
function toolBlock(t, n) {
  const fields = t.fields
    .map((f) => {
      const id = `${t.idPrefix}-${f.id}`;
      const live = t.kind === 'calc' ? ` data-live="${t.idPrefix}"` : '';
      if (f.type === 'select') {
        const opts = f.options
          .map((o) => `<option value="${attr(o.value)}">${esc(o.label)}</option>`)
          .join('');
        return `<div><label class="fl" for="${id}">${esc(f.label)}</label>\n                <select id="${id}"${live}>${opts}</select>\n              </div>`;
      }
      if (f.type === 'textarea') {
        return `<div><label class="fl" for="${id}">${esc(f.label)}</label><textarea id="${id}" rows="${f.rows || 3}" placeholder="${attr(f.placeholder || '')}"></textarea></div>`;
      }
      const numeric =
        f.type === 'number'
          ? ' type="number" inputmode="numeric" min="0"'
          : ' type="text"';
      return `<div><label class="fl" for="${id}">${esc(f.label)}</label><input id="${id}"${numeric} placeholder="${attr(f.placeholder || '')}"${live} /></div>`;
    })
    .join('\n              ');

  const body =
    t.kind === 'calc'
      ? `<div class="readout" id="${t.idPrefix}-readout" style="display:none">
              <div class="big" id="${t.idPrefix}-big">$0</div>
              <div class="cap" id="${t.idPrefix}-cap"></div>
            </div>`
      : `<button class="tbtn" type="button" data-build="${t.idPrefix}">${esc(t.buttonLabel || 'Write it')}</button>
            <div class="out" id="${t.idPrefix}-out"></div>
            <button class="tbtn ghost" type="button" data-copy="${t.idPrefix}-out" style="display:none" id="${t.idPrefix}-copy">Copy to clipboard</button>`;

  return `<div class="tool" data-tool="${t.idPrefix}">
            <span class="tlabel">${esc(t.kicker || (t.kind === 'calc' ? 'The instrument · live' : 'The instrument · writes it'))}</span>
            <span class="tname">${esc(t.name)}</span>
            <span class="tsub">${esc(t.sub)}</span>
            <span class="tfree">Free to use, like every tool in every issue</span>
            <div class="fieldset">
              ${fields}
            </div>
            ${body}
          </div>`;
}

export function topicSection(t, n) {
  const tape = t.tape
    .map(
      (r) =>
        `            <div class="tape-row"><span class="d">${esc(r.d)}</span><span>${esc(r.text)}</span></div>`
    )
    .join('\n');
  const play = t.play
    .map((p) => `            <li>${esc(p)}</li>`)
    .join('\n');
  const sources = t.sources
    .map(
      (s) =>
        `<a href="${attr(s.url)}" target="_blank" rel="noopener">${esc(s.label)}</a>`
    )
    .join(' &middot; ');
  const prose = t.prose
    .map((p) => `          <p>${esc(p)}</p>`)
    .join('\n');

  return `<!-- ============ 0${n} ${t.kicker.toUpperCase()} ============ -->
<section class="topic" id="t${n}">
  <div class="topic-inner">
    <div class="kicker"><span>0${n} / ${esc(t.kicker)}</span><span class="rt">Brief &middot; ${esc(t.readTime || '45 sec')}</span></div>
    <h2>${esc(t.headline)}</h2>
    <div class="brief">
      <div>
        <span class="label">What moved</span>
        <p>${esc(t.briefs.moved)}</p>
      </div>
      <div>
        <span class="label">What it changes</span>
        <p>${esc(t.briefs.changes)}</p>
      </div>
      <div>
        <span class="label">What not to do</span>
        <p>${esc(t.briefs.avoid)}</p>
      </div>
    </div>
    <div class="actions">
      <button class="deep-toggle" data-deep="t${n}" aria-expanded="false">Go deeper <span class="arrow">+</span></button>
      <button class="share" type="button" data-share="t${n}" data-title="${attr(t.headline)}">${SHARE_SVG}<span class="sl">Send this one</span></button>
    </div>
    <div class="deep">
      <div class="deep-grid">
        <div class="deep-main">
          <h3>The tape</h3>
          <div class="tape">
${tape}
          </div>
          <h3>${esc(t.proseHeading)}</h3>
${prose}
          <h3>The play</h3>
          <ol>
${play}
          </ol>
          <div class="sources">
            <span class="label">Sources</span>
            <p>${sources}</p>
          </div>
        </div>
        <aside class="deep-aside">
          <div class="pull"><p>${esc(t.pull)}</p></div>
          ${toolBlock(t.tool, n)}
        </aside>
      </div>
    </div>
  </div>
</section>`;
}

export function tocRow(t, n) {
  return `      <a href="#t${n}"><span class="n">0${n}</span><span class="t">${esc(t.headline)}</span><span class="go">${esc(t.go)}</span><span class="tk">+ tool</span></a>`;
}
