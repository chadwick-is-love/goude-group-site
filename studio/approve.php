<?php
/* approve.php - STUDIO_AUTOPOST_2026-10-13. The approval page for the queue.
   One row per slot: the card, the statement, every network it goes to.
   Approve schedules the row at its slot time; nothing posts from here
   otherwise. Same login as the folder. */
$by = $_SERVER['REMOTE_USER'] ?? ($_SERVER['REDIRECT_REMOTE_USER'] ?? '');
if ($by === '') { http_response_code(401); exit('not authenticated'); }
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex, nofollow, noarchive" />
<title>Approve · The Studio</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@500;600&display=swap" rel="stylesheet" />
<style>
:root{
  --bg:#d8f7c9; --panel:#c7efb2; --ink:#06162b; --soft:rgba(6,22,43,.72); --hair:rgba(6,22,43,.28);
  --ok:#0f6b3f; --bad:#8f1d3a;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{background:var(--bg);color:var(--ink);font-family:"Inter",system-ui,sans-serif;-webkit-font-smoothing:antialiased}
body{padding:22px 16px 80px;max-width:760px;margin:0 auto}
button{font-family:inherit;cursor:pointer;border:none;background:none;color:inherit}
h1{font-family:"Playfair Display",Georgia,serif;font-weight:600;font-size:34px;letter-spacing:-.01em;line-height:1.1}
.top{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap}
.lede{font-size:15px;line-height:1.5;margin-top:10px;max-width:520px}
.rule{height:1.5px;background:var(--ink);margin:18px 0 6px}
.brands{display:flex;gap:6px}
.brands button{font-size:13px;font-weight:600;padding:9px 14px;box-shadow:inset 0 0 0 1.5px var(--ink)}
.brands button.on{background:var(--ink);color:var(--bg)}
.bar{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 0}
.count{font-size:14px;font-weight:600}
.btn{font-size:15px;font-weight:700;padding:13px 18px;background:var(--ink);color:var(--bg)}
.btn.ghost{background:transparent;color:var(--ink);box-shadow:inset 0 0 0 1.5px var(--ink)}
.btn[disabled]{opacity:.45;cursor:default}
.day{font-size:12px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;margin:26px 0 8px}
.row{background:var(--panel);padding:16px;margin-top:10px}
.row .grid{display:grid;grid-template-columns:132px 1fr;gap:14px;align-items:start}
.row img{width:132px;display:block;box-shadow:0 0 0 1px var(--hair)}
.when{font-size:13px;font-weight:700}
.statement{font-size:18px;font-weight:600;line-height:1.3;margin-top:6px}
.meta{font-size:13px;color:var(--soft);margin-top:6px}
.nets{margin-top:12px;border-top:1px solid var(--hair)}
.net{border-bottom:1px solid var(--hair)}
.net summary{list-style:none;display:flex;justify-content:space-between;gap:10px;padding:10px 0;font-size:14px;font-weight:600;cursor:pointer}
.net summary::-webkit-details-marker{display:none}
.net pre{white-space:pre-wrap;font-family:inherit;font-size:14px;line-height:1.5;padding:0 0 12px}
.net .frames{display:flex;gap:8px;overflow-x:auto;padding-bottom:12px}
.net .frames img{width:96px}
.st{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
.st.scheduled{color:var(--ok)} .st.failed,.st.missed,.st.rejected{color:var(--bad)}
.acts{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
.err{font-size:13px;color:var(--bad);margin-top:8px;font-weight:600}
.empty{font-size:16px;margin-top:30px;line-height:1.5}
.tabs{display:flex;gap:16px;font-size:13px;font-weight:600}
.tabs button{padding:4px 0}
.tabs button.on{box-shadow:inset 0 -2px 0 var(--ink)}
@media (max-width:520px){ .row .grid{grid-template-columns:96px 1fr} .row img{width:96px} h1{font-size:28px} }
</style>
</head>
<body>
<div class="top">
  <div>
    <h1>Approve the week.</h1>
    <p class="lede">Each row is one slot: the card and every network it goes to. Approve schedules it for its time. Nothing here posts until you approve it, and a slot nobody approves by its time is skipped.</p>
  </div>
  <div class="brands" id="brands">
    <button type="button" data-brand="goude">Goude</button>
    <button type="button" data-brand="gabes">&gt;gabes</button>
  </div>
</div>
<div class="rule"></div>
<div class="bar">
  <div class="tabs" id="tabs">
    <button type="button" data-st="pending" class="on">Waiting</button>
    <button type="button" data-st="scheduled">Scheduled</button>
    <button type="button" data-st="">Everything</button>
  </div>
  <button class="btn" type="button" id="all">Approve all</button>
</div>
<div class="count" id="count"></div>
<div id="list"></div>

<script>
var BRAND = (new URLSearchParams(location.search).get('brand') === 'gabes') ? 'gabes' : 'goude';
var STATUS = 'pending';
var ROWS = [];
function $(id){ return document.getElementById(id); }
function esc(t){ return String(t == null ? '' : t).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
function fmtWhen(ts){
  var d = new Date(ts * 1000);
  return d.toLocaleString([], {weekday:'long', month:'short', day:'numeric', hour:'numeric', minute:'2-digit', timeZone:'America/New_York'}) + ' ET';
}
function dayOf(ts){
  return new Date(ts * 1000).toLocaleDateString([], {weekday:'long', month:'long', day:'numeric', timeZone:'America/New_York'});
}
function load(){
  Array.prototype.forEach.call(document.querySelectorAll('#brands button'), function(b){ b.classList.toggle('on', b.getAttribute('data-brand') === BRAND); });
  Array.prototype.forEach.call(document.querySelectorAll('#tabs button'), function(b){ b.classList.toggle('on', b.getAttribute('data-st') === STATUS); });
  $('list').innerHTML = ''; $('count').textContent = 'Loading ...';
  fetch('queue.php?action=list&brand=' + BRAND + (STATUS ? '&status=' + STATUS : ''), {cache:'no-store', credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(j){
      if(!j.ok){ $('count').textContent = j.error || 'could not load the queue'; return; }
      ROWS = j.posts || []; render();
    }).catch(function(e){ $('count').textContent = 'could not load the queue: ' + e.message; });
}
function groups(){
  var g = [], by = {};
  ROWS.forEach(function(r){
    var k = r.date + '|' + r.slot_key + '|' + r.status;
    if(!by[k]){ by[k] = {key:k, date:r.date, slot:r.slot_key, at:r.publish_at, status:r.status, statement:r.statement, colour:r.colour, posts:[]}; g.push(by[k]); }
    by[k].posts.push(r);
  });
  return g;
}
function render(){
  var G = groups(), list = $('list'), lastDay = '';
  var pending = ROWS.filter(function(r){ return r.status === 'pending'; });
  $('all').disabled = !pending.length;
  $('all').textContent = pending.length ? ('Approve all ' + pending.length) : 'Approve all';
  $('count').textContent = ROWS.length ? (G.length + ' slot' + (G.length === 1 ? '' : 's') + ', ' + ROWS.length + ' post' + (ROWS.length === 1 ? '' : 's')) : '';
  if(!G.length){
    list.innerHTML = '<p class="empty">' + (STATUS === 'pending' ? 'Nothing waiting. Queue a week from the Studio and it lands here.' : 'Nothing here yet.') + '</p>';
    return;
  }
  G.forEach(function(g){
    var d = dayOf(g.at);
    if(d !== lastDay){ var h = document.createElement('div'); h.className = 'day'; h.textContent = d; list.appendChild(h); lastDay = d; }
    var row = document.createElement('div'); row.className = 'row';
    var lead = g.posts.filter(function(p){ return p.platform === 'ig_feed'; })[0] || g.posts[0];
    var thumb = (lead.status === 'pending') ? '<img alt="" src="queue.php?action=img&id=' + encodeURIComponent(lead.id) + '&n=0">' : '<div></div>';
    var nets = g.posts.map(function(p){
      var frames = '';
      if(p.status === 'pending'){
        for(var n = 0; n < p.images; n++) frames += '<img alt="' + (n + 1) + ' of ' + p.images + '" loading="lazy" src="queue.php?action=img&id=' + encodeURIComponent(p.id) + '&n=' + n + '">';
      }
      var res = p.result && p.result.post_id ? ' · post ' + esc(p.result.post_id) : '';
      return '<details class="net"><summary><span>' + esc(p.label) + ' · ' + esc(p.kind) + (p.images > 1 ? ' of ' + p.images : '') + '</span><span class="st ' + esc(p.status) + '">' + esc(p.status) + res + '</span></summary>'
        + (frames ? '<div class="frames">' + frames + '</div>' : '')
        + '<pre>' + esc(p.caption) + '</pre>'
        + (p.error ? '<p class="err">' + esc(p.error) + '</p>' : '') + '</details>';
    }).join('');
    row.innerHTML = '<div class="grid">' + thumb + '<div><div class="when">' + esc(fmtWhen(g.at)) + '</div>'
      + '<div class="statement">' + esc(g.statement || '') + '</div>'
      + '<div class="meta">' + esc(g.posts.length + ' network' + (g.posts.length === 1 ? '' : 's') + (g.colour ? ' · ' + g.colour : '') + (lead.issue ? ' · ' + lead.issue : '')) + '</div></div></div>'
      + '<div class="nets">' + nets + '</div>'
      + (g.status === 'pending' ? '<div class="acts"><button class="btn" type="button" data-act="approve">Approve</button><button class="btn ghost" type="button" data-act="reject">Reject</button></div>' : '')
      + '<p class="err" style="display:none"></p>';
    Array.prototype.forEach.call(row.querySelectorAll('[data-act]'), function(b){
      b.addEventListener('click', function(){ decide(b.getAttribute('data-act'), g.posts.map(function(p){ return p.id; }), row); });
    });
    list.appendChild(row);
  });
}
function decide(act, ids, row){
  var btns = row ? row.querySelectorAll('button') : [$('all')];
  Array.prototype.forEach.call(btns, function(b){ b.disabled = true; });
  if(row) row.querySelector('.acts .btn').textContent = (act === 'approve') ? 'Scheduling ...' : 'Rejecting ...';
  fetch('queue.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:act, ids:ids})})
    .then(function(r){ return r.text(); })
    .then(function(t){
      var j = null; try { j = JSON.parse(t); } catch(e){}
      if(!j || !j.ok){ throw new Error((j && j.error) || ('the server answered: ' + t.slice(0, 140) + '. Check the OmniSocials calendar before trying again.')); }
      var bad = j.results.filter(function(x){ return !x.ok; });
      if(bad.length && row){ var e = row.querySelector('.err:last-child'); e.style.display = 'block'; e.textContent = bad.length + ' did not schedule: ' + bad[0].error; }
      setTimeout(load, bad.length ? 2400 : 300);
    }).catch(function(e){
      Array.prototype.forEach.call(btns, function(b){ b.disabled = false; });
      var el = row ? row.querySelector('.err:last-child') : $('count');
      el.style.display = 'block'; el.textContent = e.message;
    });
}
$('all').addEventListener('click', function(){
  var ids = ROWS.filter(function(r){ return r.status === 'pending'; }).map(function(r){ return r.id; });
  if(!ids.length) return;
  if(!confirm('Schedule all ' + ids.length + ' posts at their slot times?')) return;
  /* one slot per request, in order, so no single call runs long on the host */
  var G = groups().filter(function(g){ return g.status === 'pending'; }), i = 0, bad = 0;
  $('all').disabled = true;
  function next(){
    if(i >= G.length){ $('count').textContent = bad ? (bad + ' did not schedule; see the rows below.') : 'All scheduled.'; setTimeout(load, 900); return; }
    $('all').textContent = 'Scheduling ' + (i + 1) + ' of ' + G.length + ' ...';
    fetch('queue.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action:'approve', ids:G[i].posts.map(function(p){ return p.id; })})})
      .then(function(r){ return r.json(); })
      .then(function(j){ if(!j.ok) bad += G[i].posts.length; else bad += j.results.filter(function(x){ return !x.ok; }).length; i++; next(); })
      .catch(function(){ $('count').textContent = 'Stopped at slot ' + (i + 1) + ': the server did not answer. Check the OmniSocials calendar, then reload.'; });
  }
  next();
});
Array.prototype.forEach.call(document.querySelectorAll('#brands button'), function(b){
  b.addEventListener('click', function(){ BRAND = b.getAttribute('data-brand'); history.replaceState(null, '', '?brand=' + BRAND); load(); });
});
Array.prototype.forEach.call(document.querySelectorAll('#tabs button'), function(b){
  b.addEventListener('click', function(){ STATUS = b.getAttribute('data-st'); load(); });
});
load();
</script>
</body>
</html>
