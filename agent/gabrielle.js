/* Gabrielle: the homepage agent for goudegroup.com.
 * An ElevenLabs conversational agent with her cloned voice. Visitors type or talk.
 * Build and agent config live in the vault: 02_PROJECTS/Goude Group, Website/gabrielle-agent/.
 * Empty AGENT_ID means nothing renders, so the file is safe on the page before the agent exists.
 */
(function () {
  'use strict';
  var AGENT_ID = '';
  var SDK = 'https://cdn.jsdelivr.net/npm/@elevenlabs/client@1.26.0/dist/lib.iife.js';
  var BOOK = 'https://calendly.com/gabrielle-goudegroup/30min';
  var LEADS = 'https://formspree.io/f/xrenkbwg';
  var EXAMPLE = 'https://roofing-company-review.vercel.app/';
  if (!AGENT_ID) return;

  var css = [
    '.gg-agent{--gg-navy:#071426;--gg-mint:#d8f7c9;--gg-bone:#f3efe4;--gg-blue:#a9d8ef;--gg-line:rgba(243,239,228,.16);font-family:"Inter",ui-sans-serif,system-ui,sans-serif;-webkit-font-smoothing:antialiased}',
    '.gg-launch{position:fixed;right:24px;bottom:24px;z-index:9998;display:flex;align-items:center;gap:10px;padding:13px 20px 13px 16px;border-radius:999px;background:var(--gg-navy);color:var(--gg-bone);border:1px solid rgba(216,247,201,.35);font:600 12px/1 "Inter",sans-serif;letter-spacing:.12em;text-transform:uppercase;cursor:pointer;box-shadow:0 10px 30px rgba(7,20,38,.28);transition:transform .2s ease,border-color .2s ease}',
    '.gg-launch:hover{transform:translateY(-2px);border-color:var(--gg-mint)}',
    '.gg-launch:focus-visible,.gg-agent button:focus-visible,.gg-agent input:focus-visible,.gg-agent a:focus-visible{outline:2px solid var(--gg-mint);outline-offset:3px}',
    '.gg-dot{width:8px;height:8px;border-radius:50%;background:var(--gg-mint);box-shadow:0 0 0 0 rgba(216,247,201,.6);animation:gg-pulse 2.4s ease-out infinite}',
    '@keyframes gg-pulse{0%{box-shadow:0 0 0 0 rgba(216,247,201,.55)}70%{box-shadow:0 0 0 9px rgba(216,247,201,0)}100%{box-shadow:0 0 0 0 rgba(216,247,201,0)}}',
    '.gg-panel{position:fixed;right:24px;bottom:24px;z-index:9999;width:400px;height:min(640px,calc(100vh - 48px));display:flex;flex-direction:column;background:var(--gg-navy);color:var(--gg-bone);border:1px solid var(--gg-line);border-radius:6px;box-shadow:0 24px 60px rgba(7,20,38,.38);overflow:hidden;opacity:0;transform:translateY(12px);pointer-events:none;transition:opacity .22s ease,transform .22s ease}',
    '.gg-agent.open .gg-panel{opacity:1;transform:none;pointer-events:auto}',
    '.gg-agent.open .gg-launch{opacity:0;pointer-events:none}',
    '.gg-head{display:flex;align-items:flex-start;justify-content:space-between;padding:22px 22px 16px;border-bottom:1px solid var(--gg-line)}',
    '.gg-name{font:500 28px/1 "Playfair Display",Georgia,serif;letter-spacing:-.01em}',
    '.gg-meta{margin-top:8px;font:600 10.5px/1.3 "Inter",sans-serif;letter-spacing:.16em;text-transform:uppercase;color:var(--gg-mint)}',
    '.gg-x{width:32px;height:32px;margin:-4px -6px 0 0;border:0;background:none;color:var(--gg-bone);font-size:22px;line-height:1;cursor:pointer;opacity:.75}',
    '.gg-x:hover{opacity:1}',
    '.gg-log{flex:1;overflow-y:auto;padding:18px 22px;display:flex;flex-direction:column;gap:14px;scroll-behavior:smooth}',
    '.gg-msg{max-width:88%;font-size:15px;line-height:1.55;white-space:pre-wrap;overflow-wrap:anywhere}',
    '.gg-msg.agent{align-self:flex-start;color:var(--gg-bone)}',
    '.gg-msg.user{align-self:flex-end;color:var(--gg-navy);background:var(--gg-mint);padding:9px 13px;border-radius:4px}',
    '.gg-msg.note{align-self:stretch;max-width:none;font-size:12.5px;color:var(--gg-blue)}',
    '.gg-msg a{color:var(--gg-mint);border-bottom:1px solid rgba(216,247,201,.5)}',
    '.gg-typing{align-self:flex-start;display:none;gap:5px;padding:4px 0}',
    '.gg-typing.on{display:flex}',
    '.gg-typing i{width:6px;height:6px;border-radius:50%;background:var(--gg-bone);opacity:.35;animation:gg-blink 1.2s infinite}',
    '.gg-typing i:nth-child(2){animation-delay:.2s}.gg-typing i:nth-child(3){animation-delay:.4s}',
    '@keyframes gg-blink{0%,100%{opacity:.25}50%{opacity:.9}}',
    '.gg-voice{display:none;align-items:center;gap:14px;padding:14px 22px;border-top:1px solid var(--gg-line)}',
    '.gg-agent.calling .gg-voice{display:flex}',
    '.gg-agent.calling .gg-form{display:none}',
    '.gg-level{flex:1;height:2px;background:var(--gg-line);position:relative;overflow:hidden}',
    '.gg-level span{position:absolute;left:0;top:0;bottom:0;width:0;background:var(--gg-mint);transition:width .08s linear}',
    '.gg-state{font:600 10.5px/1 "Inter",sans-serif;letter-spacing:.16em;text-transform:uppercase;min-width:74px}',
    '.gg-form{display:flex;gap:8px;padding:14px 16px;border-top:1px solid var(--gg-line)}',
    '.gg-in{flex:1;min-width:0;font:400 15px/1.3 "Inter",sans-serif;color:var(--gg-bone);background:rgba(243,239,228,.06);border:1px solid var(--gg-line);border-radius:3px;padding:12px 13px}',
    '.gg-in::placeholder{color:rgba(243,239,228,.5)}',
    '.gg-btn{font:600 11.5px/1 "Inter",sans-serif;letter-spacing:.12em;text-transform:uppercase;padding:0 14px;border-radius:3px;cursor:pointer;white-space:nowrap}',
    '.gg-send{background:var(--gg-mint);color:var(--gg-navy);border:1px solid var(--gg-mint)}',
    '.gg-talk,.gg-end{background:none;color:var(--gg-bone);border:1px solid rgba(243,239,228,.4)}',
    '.gg-talk:hover,.gg-end:hover{border-color:var(--gg-mint);color:var(--gg-mint)}',
    '.gg-end{height:38px}',
    '.gg-foot{padding:0 22px 16px;font-size:11.5px;line-height:1.5;color:rgba(243,239,228,.62)}',
    '.gg-foot a{color:var(--gg-bone);border-bottom:1px solid rgba(243,239,228,.35)}',
    '@media(max-width:560px){.gg-launch{right:16px;bottom:16px}.gg-panel{right:0;bottom:0;width:100%;height:100%;height:100dvh;border-radius:0;border:0}}',
    '@media(prefers-reduced-motion:reduce){.gg-dot,.gg-typing i{animation:none}.gg-panel,.gg-launch{transition:none}}'
  ].join('');

  var root = document.createElement('div');
  root.className = 'gg-agent';
  root.innerHTML =
    '<style>' + css + '</style>' +
    '<button class="gg-launch" type="button" aria-haspopup="dialog" aria-expanded="false"><span class="gg-dot" aria-hidden="true"></span>Talk to Gabrielle</button>' +
    '<section class="gg-panel" role="dialog" aria-modal="false" aria-label="Talk to Gabrielle">' +
      '<div class="gg-head"><div><div class="gg-name">Gabrielle</div><div class="gg-meta">AI voice &middot; The Goude Group</div></div>' +
      '<button class="gg-x" type="button" aria-label="Close">&times;</button></div>' +
      '<div class="gg-log" aria-live="polite"></div>' +
      '<div class="gg-voice"><span class="gg-state">Connecting</span><div class="gg-level"><span></span></div><button class="gg-btn gg-end" type="button">End call</button></div>' +
      '<form class="gg-form" autocomplete="off"><input class="gg-in" type="text" maxlength="800" placeholder="Type to Gabrielle" aria-label="Message" />' +
      '<button class="gg-btn gg-send" type="submit">Send</button><button class="gg-btn gg-talk" type="button" title="Talk out loud">Talk</button></form>' +
      '<div class="gg-foot">An AI voice built from Gabrielle\'s, with her permission. Conversations are kept for thirty days. <a href="' + BOOK + '" target="_blank" rel="noopener">Book the real one</a></div>' +
    '</section>';
  document.body.appendChild(root);

  var $ = function (s) { return root.querySelector(s); };
  var launch = $('.gg-launch'), panel = $('.gg-panel'), log = $('.gg-log'), form = $('.gg-form'),
      input = $('.gg-in'), stateEl = $('.gg-state'), level = $('.gg-level span');
  var typing = document.createElement('div');
  typing.className = 'gg-typing'; typing.innerHTML = '<i></i><i></i><i></i>';
  log.appendChild(typing);

  var sdkPromise = null, convo = null, mode = null, starting = null, pending = [], volTimer = null, history = [];

  function loadSdk() {
    if (window.ElevenLabsClient) return Promise.resolve(window.ElevenLabsClient);
    if (!sdkPromise) sdkPromise = new Promise(function (ok, fail) {
      var s = document.createElement('script');
      s.src = SDK; s.async = true;
      s.onload = function () { window.ElevenLabsClient ? ok(window.ElevenLabsClient) : fail(new Error('sdk')); };
      s.onerror = function () { sdkPromise = null; fail(new Error('sdk')); };
      document.head.appendChild(s);
    });
    return sdkPromise;
  }

  function add(role, text, html) {
    var d = document.createElement('div');
    d.className = 'gg-msg ' + role;
    if (html) d.innerHTML = html; else d.textContent = text;
    log.insertBefore(d, typing);
    if (role === 'agent' || role === 'user') history.push((role === 'agent' ? 'Gabrielle: ' : 'Visitor: ') + text);
    log.scrollTop = log.scrollHeight;
  }

  function track(name) { try { if (window.gtag) gtag('event', name, { event_category: 'gabrielle_agent' }); } catch (e) {} }

  var tools = {
    open_booking: function () {
      var w = null;
      try { w = window.open(BOOK, '_blank', 'noopener'); } catch (e) {}
      add('note', '', 'Booking page: <a href="' + BOOK + '" target="_blank" rel="noopener">pick a 30 minute time</a>');
      track('agent_booking_opened');
      return w ? 'The booking page opened in a new tab.' : 'A booking link is now showing in the chat for them to click.';
    },
    show_example: function () {
      add('note', '', 'An example Operating Review, for a fictional roofing company: <a href="' + EXAMPLE + '" target="_blank" rel="noopener">open the example</a>');
      track('agent_example_shown');
      return 'The example link is now showing in the chat.';
    },
    save_contact: function (p) {
      p = p || {};
      track('agent_contact_saved');
      return fetch(LEADS, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          source: 'Homepage agent (Gabrielle)', name: p.name || '', email: p.email || '',
          phone: p.phone || '', company: p.company || '', message: p.note || '', page: location.href
        })
      }).then(function (r) { return r.ok ? 'Saved. The team will follow up.' : 'Saving failed. Give them hello@goudegroup.com instead.'; })
        .catch(function () { return 'Saving failed. Give them hello@goudegroup.com instead.'; });
    }
  };

  function stopVolume() { if (volTimer) { cancelAnimationFrame(volTimer); volTimer = null; } level.style.width = '0'; }
  function watchVolume() {
    stopVolume();
    (function tick() {
      if (!convo || mode !== 'voice') return;
      var v = 0;
      try { v = Math.max(convo.getOutputVolume ? convo.getOutputVolume() : 0, convo.getInputVolume ? convo.getInputVolume() * 0.6 : 0); } catch (e) {}
      level.style.width = Math.min(100, Math.round(v * 140)) + '%';
      volTimer = requestAnimationFrame(tick);
    })();
  }

  function start(kind) {
    if (convo && mode === kind) return Promise.resolve(convo);
    if (starting) return starting;
    var prev = convo; convo = null; mode = null;
    var carry = history.slice(-20).join('\n');
    var ready = prev ? prev.endSession().catch(function () {}) : Promise.resolve();
    starting = ready.then(loadSdk).then(function (EL) {
      var opts = {
        agentId: AGENT_ID,
        clientTools: tools,
        onMessage: function (m) {
          var role = m.role || (m.source === 'ai' ? 'agent' : 'user');
          if (role === 'agent') typing.classList.remove('on');
          if (role === 'user' && kind === 'text') return; /* already shown when sent */
          add(role === 'agent' ? 'agent' : 'user', m.message);
        },
        onModeChange: function (m) { if (kind === 'voice') stateEl.textContent = m.mode === 'speaking' ? 'Speaking' : 'Listening'; },
        onDisconnect: function () {
          if (mode === kind) { convo = null; mode = null; }
          if (kind === 'voice') { root.classList.remove('calling'); stopVolume(); }
          typing.classList.remove('on');
        },
        onError: function () { typing.classList.remove('on'); }
      };
      if (kind === 'text') opts.textOnly = true; else { opts.textOnly = false; opts.connectionType = 'webrtc'; }
      /* A second session in the same visit should not greet from the top again. */
      if (history.length) opts.overrides = { agent: { firstMessage: kind === 'voice' ? 'Okay, I can hear you now. Go ahead.' : 'I\'m here. Go ahead.' } };
      return EL.Conversation.startSession(opts);
    }).then(function (c) {
      convo = c; mode = kind; starting = null;
      if (carry) { try { c.sendContextualUpdate('Earlier in this visit, by text:\n' + carry); } catch (e) {} }
      if (kind === 'voice') { stateEl.textContent = 'Listening'; watchVolume(); }
      while (pending.length && kind === 'text') { typing.classList.add('on'); c.sendUserMessage(pending.shift()); }
      return c;
    }).catch(function (err) {
      starting = null; typing.classList.remove('on'); root.classList.remove('calling');
      var mic = err && /permission|notallowed|denied/i.test(String(err.name || err.message || err));
      add('note', mic ? 'Your browser blocked the microphone. You can type to her instead.'
                      : 'She could not connect just now. Email hello@goudegroup.com or book a call below.');
      throw err;
    });
    return starting;
  }

  function open() {
    root.classList.add('open'); launch.setAttribute('aria-expanded', 'true');
    setTimeout(function () { input.focus(); }, 60);
    track('agent_opened');
    if (!convo && !starting) { typing.classList.add('on'); start('text').catch(function () {}); }
  }
  function close() {
    root.classList.remove('open'); launch.setAttribute('aria-expanded', 'false');
    if (mode === 'voice' && convo) convo.endSession().catch(function () {});
    launch.focus();
  }

  launch.addEventListener('click', function () { loadSdk().catch(function () {}); open(); });
  launch.addEventListener('pointerenter', function () { loadSdk().catch(function () {}); }, { once: true });
  $('.gg-x').addEventListener('click', close);
  root.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var t = input.value.trim(); if (!t) return;
    input.value = ''; add('user', t); typing.classList.add('on');
    if (convo && mode === 'text') convo.sendUserMessage(t);
    else { pending.push(t); start('text').catch(function () {}); }
  });

  $('.gg-talk').addEventListener('click', function () {
    root.classList.add('calling'); stateEl.textContent = 'Connecting';
    track('agent_call_started');
    start('voice').catch(function () {});
  });
  $('.gg-end').addEventListener('click', function () {
    if (convo) convo.endSession().catch(function () {});
    root.classList.remove('calling'); stopVolume();
  });
})();
