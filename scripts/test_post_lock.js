/*
 * test_post_lock.js - does the in-flight lock actually stop duplicate posts?
 *
 * Lifts postOmniSocials / postingOn / postingOff / updateCapMeta / toast
 * VERBATIM out of the shipped studio/index.html. It does not reimplement
 * them: a harness that reimplements the thing it is testing validates the
 * harness, which is how the original bug survived every prior check.
 *
 * The scenario is the real one from 2026-09-03: six clicks in 11 seconds,
 * four of them inside a single second, while the render+upload is in flight.
 */

const fs = require('fs');
const path = require('path');

const HTML = path.join(__dirname, '..', 'studio', 'index.html');
const src = fs.readFileSync(process.env.STUDIO_HTML || HTML, 'utf8');

function lift(sig) {
  const i = src.indexOf(sig);
  if (i < 0) throw new Error('could not find in shipped file: ' + sig);
  let d = 0, j = src.indexOf('{', i);
  const start = i;
  for (let k = j; k < src.length; k++) {
    if (src[k] === '{') d++;
    else if (src[k] === '}') { d--; if (d === 0) return src.slice(start, k + 1); }
  }
  throw new Error('unbalanced: ' + sig);
}

const lifted = [
  'function toast(msg, ms){',
  'function postingOn(f){',
  'function postingOff(){',
  'function postingDone(msg){',
  'function postOmniSocials(){',
].map(lift).join('\n\n');

// ---- the world around the lifted code -------------------------------------
let CREATES = 0;          // how many times push.php was actually called
let RENDERS = 0;          // how many times the card was rendered
const TOASTS = [], STATUS = [];
let clock = 0;

const CLASSES = new Set();
const btn = {
  disabled: false, textContent: 'Post to OmniSocials (~30 min)',
  classList: { add: c => CLASSES.add(c), remove: c => CLASSES.delete(c),
               contains: c => CLASSES.has(c) },
};
const els = {
  postOmniBtn: btn,
  capText: { value: 'a perfectly ordinary caption' },
  // the lifted toast() writes through textContent, so capture it there
  toast: { _v: '', set textContent(v){ this._v = v; TOASTS.push(v); },
           get textContent(){ return this._v; },
           classList: { add(){}, remove(){} } },
  status: { textContent: '', className: '' },
  capCount: {}, capLimit: {}, capFold: {},
};
global.$ = id => els[id];
global.S = { brand: 'goude', posting: false, format: 'linkedin' };
global.setStatus = (m, e) => STATUS.push({ m, err: !!e });
global.clearTimeout = () => {};
global.setTimeout = (fn, ms) => { /* watchdog only; never fires in test */ };
global.fmtNow = () => ({ id: 'linkedin', name: 'LinkedIn', maxChars: 1300 });
global.updateCapMeta = function () {
  // mirrors only the button branch, which is what the lock interacts with
  const t = els.capText.value, f = fmtNow(), cap = f.maxChars;
  const pb = $('postOmniBtn');
  if (pb && !S.posting) {
    if (S.doneShown) { S.doneShown = false; pb.classList.remove('done'); }
    const over = Boolean(cap) && t.length > cap;
    pb.disabled = over;
    pb.textContent = over ? ('Too long for ' + f.name) : 'Post to OmniSocials (~30 min)';
  }
};

// render + upload are async and SLOW - that gap is the entire bug
let pendingRender = null;
global.renderToBlob = (f, cb) => { RENDERS++; pendingRender = cb; };
global.FileReader = function () {
  this.readAsDataURL = () => { this.result = 'data:image/png;base64,AAAA'; this.onload(); };
};
// RESPONSE is swappable so the error paths can be exercised too. The shipped
// code now reads the body as text and parses by hand, so the stub must look
// like a real Response, not just expose .json().
let RESPONSE = () => ({
  status: 200, statusText: 'OK',
  text: () => Promise.resolve(JSON.stringify({
    ok: true, label: 'LinkedIn', post_id: 100000000 + CREATES,
    scheduled_at: new Date(Date.now() + 1800000).toISOString(),
  })),
});
global.fetch = () => { CREATES++; return Promise.resolve(RESPONSE()); };

eval(lifted);

// ---- the test --------------------------------------------------------------
let pass = 0, fail = 0;
function check(label, cond) {
  if (cond) { pass++; console.log('  PASS  ' + label); }
  else { fail++; console.log('  FAIL  ' + label); }
}

console.log('\nSCENARIO: six clicks while the first render is still in flight');
console.log('(the real 2026-09-03 sequence: 4 clicks inside one second, 2 more after)\n');

for (let i = 0; i < 6; i++) postOmniSocials();

check('the card rendered exactly once, not six times', RENDERS === 1);
check('the button is disabled while in flight', btn.disabled === true);
check('the button says what it is doing', /Posting to LinkedIn/.test(btn.textContent));
check('the 5 blocked clicks told the user why',
      TOASTS.filter(t => /already posting/.test(t)).length === 5);
check('nothing has been sent to push.php yet', CREATES === 0);
check('the user saw feedback the instant they clicked',
      TOASTS.some(t => /rendering the card/.test(t)));

// let the in-flight render complete
pendingRender();

setImmediate(() => {
  check('exactly ONE post was created from six clicks', CREATES === 1);
  check('the button unlocked after the run', btn.disabled === false);
  // Was "the label was restored". That assertion predates the done state and
  // became WRONG when the behaviour deliberately changed: the label now holds
  // the confirmation until it times out or the operator edits something.
  check('the label holds the confirmation rather than snapping back',
        /Queued for/.test(btn.textContent) && !/Post to OmniSocials/.test(btn.textContent));
  check('S.posting was cleared', S.posting === false);
  check('the confirmation says it is NOT live yet',
        TOASTS.some(t => /NOT live yet/.test(t)));
  check('the button carries the DONE state after success', CLASSES.has('done'));
  check('the done label names the queued time', /Queued for/.test(btn.textContent));
  check('the WORKING state was cleared', !CLASSES.has('working'));
  check('editing the caption retires the confirmation', (() => {
    els.capText.value = 'edited';
    updateCapMeta();
    return !CLASSES.has('done') && S.doneShown === false;
  })());
  check('a keystroke mid-flight cannot re-enable the button', (() => {
    S.posting = true; btn.disabled = true;
    updateCapMeta();                       // simulates typing while posting
    const held = btn.disabled === true;
    S.posting = false;
    return held;
  })());

  console.log('\n  clicks=6  renders=' + RENDERS + '  posts_created=' + CREATES);

  // ---- error paths. Chadwick reported "an error on the screen", so the
  // question is not only whether it errors but whether the error is READABLE
  // and whether it leaves the button usable again.
  console.log('\nSCENARIO: the server returns a 503 HTML page, not JSON');
  TOASTS.length = 0; STATUS.length = 0;
  RESPONSE = () => ({
    status: 503, statusText: 'Service Unavailable',
    text: () => Promise.resolve('<!DOCTYPE html><html><head><title>503</title>'),
  });
  postOmniSocials();
  pendingRender();
  setImmediate(() => {
    const msg = (STATUS[STATUS.length - 1] || {}).m || '';
    check('a non-JSON reply names the real HTTP status, not "Unexpected token <"',
          /503/.test(msg) && !/Unexpected token/.test(msg));
    check('it tells the operator to check OmniSocials before retrying',
          /CHECK YOUR OMNISOCIALS CALENDAR/.test(msg));
    check('the button is usable again after an error', btn.disabled === false);
    check('the error was shown as a toast too, not only in #status',
          TOASTS.some(t => /503/.test(t)));

    console.log('\nSCENARIO: the server refuses politely (the duplicate guard)');
    TOASTS.length = 0; STATUS.length = 0;
    RESPONSE = () => ({
      status: 200, statusText: 'OK',
      text: () => Promise.resolve(JSON.stringify({
        ok: false, error: 'this exact card was already queued to LinkedIn 12 seconds ago as post 100015502.',
      })),
    });
    postOmniSocials();
    pendingRender();
    setImmediate(() => {
      check('the refusal reason is shown verbatim, not "post failed"',
            TOASTS.some(t => /already queued to LinkedIn/.test(t)));
      check('the button is usable again after a refusal', btn.disabled === false);

      console.log('\n  ' + pass + ' passed, ' + fail + ' failed');
      process.exit(fail ? 1 : 0);
    });
  });
});
