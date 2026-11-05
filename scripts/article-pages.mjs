#!/usr/bin/env node
// Gives every Briefing article its own indexable page, and rebuilds the sitemap.
//
// Why: each issue carries five researched articles, but they only existed as #t1 to #t5
// on one page, so search engines and AI search could rank the issue and never the
// article. This writes /briefing/archive/no-NNN/<headline-slug>/ for every article in
// every archived issue and in the current issue, each with its own title, description,
// canonical and Article structured data, the Go deeper read open, and the working tool.
//
// Idempotent: run it after assemble-issue.mjs (the Briefing workflow does) or by hand.
// It rewrites only article pages and the Briefing lines of sitemap.xml.

import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const SITE = 'https://goudegroup.com';
const ARCH = path.join(ROOT, 'briefing/archive');
const pad = (n) => String(n).padStart(3, '0');

const decode = (s) => s
  .replace(/<[^>]+>/g, '')
  .replace(/&middot;/g, '·').replace(/&amp;/g, '&').replace(/&rarr;/g, '→')
  .replace(/&nbsp;/g, ' ').replace(/&#39;|&rsquo;/g, "'").replace(/&quot;/g, '"')
  .replace(/\s+/g, ' ').trim();
const attr = (s) => s.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
const slugify = (s) => decode(s).toLowerCase()
  .replace(/['’]/g, '').replace(/\$/g, '').replace(/%/g, '-percent')
  .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').split('-').slice(0, 9).join('-');
const clip = (s, n = 158) => {
  if (s.length <= n) return s;
  const cut = s.slice(0, n);
  const stop = cut.lastIndexOf('. ');
  return stop > 80 ? cut.slice(0, stop + 1) : cut.slice(0, cut.lastIndexOf(' ')) + '…';
};
const isoDate = (long) => {
  const d = new Date(`${long} 12:00:00 GMT-0500`);
  return isNaN(d) ? null : d.toISOString().slice(0, 10);
};

function articles(html) {
  const out = [];
  for (let i = 1; i <= 5; i++) {
    const start = html.indexOf(`<section class="topic" id="t${i}">`);
    if (start < 0) continue;
    const end = html.indexOf('</section>', html.indexOf('class="deep"', start));
    const sec = html.slice(start, html.indexOf('\n', end) + 1 || end + 10);
    const h2 = (sec.match(/<h2>([\s\S]*?)<\/h2>/) || [])[1];
    const moved = (sec.match(/What moved<\/span>\s*<p>([\s\S]*?)<\/p>/) || [])[1];
    const kicker = (sec.match(/<div class="kicker"><span>([\s\S]*?)<\/span>/) || [])[1];
    if (!h2) continue;
    out.push({ i, sec, headline: decode(h2), description: clip(decode(moved || h2)), kicker: decode(kicker || '') });
  }
  return out;
}

function articlePage({ html, num, dateLong, issueUrl, a, slug }) {
  const url = `${SITE}/briefing/archive/no-${pad(num)}/${slug}/`;
  const title = `${a.headline.replace(/\.$/, "")} | The Briefing No. ${pad(num)} | Goude Group`;
  const ld = {
    '@context': 'https://schema.org',
    '@type': 'NewsArticle',
    headline: a.headline,
    description: a.description,
    url,
    mainEntityOfPage: url,
    datePublished: isoDate(dateLong) || undefined,
    image: `${SITE}/briefing/og/no-${pad(num)}.png`,
    isPartOf: { '@type': 'PublicationIssue', issueNumber: String(num), name: `The Briefing, No. ${pad(num)}`, url: issueUrl },
    author: { '@type': 'Organization', name: 'The Goude Group', url: `${SITE}/` },
    publisher: { '@type': 'Organization', name: 'The Goude Group', url: `${SITE}/`, logo: { '@type': 'ImageObject', url: `${SITE}/goude-logo.png` } },
  };

  let head = html.slice(0, html.indexOf('</head>'));
  head = head
    .replace(/<title>[\s\S]*?<\/title>/, `<title>${attr(title)}</title>`)
    .replace(/<meta name="description" content="[^"]*"\s*\/?>/, `<meta name="description" content="${attr(a.description)}" />`)
    .replace(/<meta property="og:url" content="[^"]*"\s*\/?>/, `<meta property="og:url" content="${url}" />`)
    .replace(/<meta property="og:title" content="[^"]*"\s*\/?>/, `<meta property="og:title" content="${attr(a.headline)}" />`)
    .replace(/<meta property="og:description" content="[^"]*"\s*\/?>/, `<meta property="og:description" content="${attr(a.description)}" />`)
    .replace(/<link rel="canonical"[^>]*>\n?/g, '');
  head += `<link rel="canonical" href="${url}" />\n`
    + `<style>.art-mast{padding:120px 24px 8px;max-width:1080px;margin:0 auto}.art-mast .kicker{opacity:.75}.art-mast a{color:inherit}.art-back{padding:8px 24px 64px;max-width:1080px;margin:0 auto}</style>\n`
    + `<script type="application/ld+json">${JSON.stringify(ld)}</script>\n`;

  const navEnd = html.indexOf('</nav>') + '</nav>'.length;
  const nav = html.slice(html.indexOf('<body>'), navEnd);
  const tail = html.slice(html.indexOf('<section class="close-field">'));

  let sec = a.sec
    .replace(`<section class="topic" id="t${a.i}">`, `<section class="topic open" id="t${a.i}">`)
    .replace(/(<button class="deep-toggle"[^>]*?)aria-expanded="false">Go deeper /, '$1aria-expanded="true">Close ');

  const mast = `\n<header class="art-mast"><div class="kicker">The Briefing &middot; No. ${pad(num)} &middot; ${dateLong}${a.kicker ? ' &middot; ' + attr(a.kicker.replace(/^\d+\s*\/\s*/, '')) : ''}</div></header>\n`;
  const back = `<div class="art-back"><p>One of five articles in <a href="${issueUrl}">The Briefing, No. ${pad(num)}</a>. Every article carries a free tool that runs in the page. <a href="/briefing/archive/">Every issue</a></p></div>\n`;

  return `${head}</head>\n${nav}\n${mast}\n${sec}\n${back}\n${tail}`;
}

function issues() {
  const list = [];
  for (const d of fs.readdirSync(ARCH)) {
    const m = d.match(/^no-(\d{3})$/);
    const p = path.join(ARCH, d, 'index.html');
    if (m && fs.existsSync(p)) list.push({ num: Number(m[1]), file: p, issueUrl: `${SITE}/briefing/archive/${d}/` });
  }
  const cur = path.join(ROOT, 'briefing/index.html');
  const html = fs.readFileSync(cur, 'utf8');
  const n = Number((html.match(/No\. (\d{3})<br>/) || [])[1]);
  if (n && !list.some((x) => x.num === n)) list.push({ num: n, file: cur, issueUrl: `${SITE}/briefing/`, current: true });
  return list.sort((x, y) => x.num - y.num);
}

const urls = [];
for (const iss of issues()) {
  // the current issue uses ../ paths (the archiver rewrites them); article pages sit
  // deeper, so make every relative parent path absolute
  const html = fs.readFileSync(iss.file, 'utf8').replace(/(src|href)="\.\.\//g, '$1="/');
  const dateLong = ((html.match(/class="mast-meta">\s*No\. \d{3}<br>\s*([^<\n]+)/) || [])[1] || '').trim();
  const lastmod = isoDate(dateLong);
  if (!iss.current) urls.push({ loc: iss.issueUrl, lastmod });
  const seen = new Set();
  for (const a of articles(html)) {
    let slug = slugify(a.headline) || `article-${a.i}`;
    if (seen.has(slug)) slug += `-${a.i}`;
    seen.add(slug);
    const dir = path.join(ARCH, `no-${pad(iss.num)}`, slug);
    fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(path.join(dir, 'index.html'), articlePage({ html, num: iss.num, dateLong, issueUrl: iss.issueUrl, a, slug }));
    urls.push({ loc: `${SITE}/briefing/archive/no-${pad(iss.num)}/${slug}/`, lastmod });
  }
  console.log(`No. ${pad(iss.num)}: ${seen.size} article pages`);
}

// Sitemap: keep every non-archive line, replace the archive lines with the full set.
const smPath = path.join(ROOT, 'sitemap.xml');
const sm = fs.readFileSync(smPath, 'utf8');
const keep = sm.split('\n').filter((l) => l.includes('<url>') && !l.includes('/briefing/archive/no-'));
const lines = urls.map((u) => `  <url><loc>${u.loc}</loc>${u.lastmod ? `<lastmod>${u.lastmod}</lastmod>` : ''}</url>`);
const at = keep.findIndex((l) => l.includes('/briefing/archive/<'));
keep.splice(at >= 0 ? at + 1 : keep.length, 0, ...lines);
fs.writeFileSync(smPath, `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${keep.join('\n')}\n</urlset>\n`);
console.log(`sitemap.xml: ${keep.length} URLs`);
