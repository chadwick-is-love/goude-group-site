#!/usr/bin/env python3
"""
patch_studio_post_lock.py - stop the post button producing duplicate posts,
and make it obvious it is working while it works.

WHY THIS EXISTS (2026-09-03)
----------------------------
Chadwick pressed "Post to LinkedIn" and reported it did not work. It DID
work - six times. OmniSocials posts 100015502-100015507 were all created
between 17:47:33 and 17:47:44 UTC, all LinkedIn, all the same card, all
scheduled to publish simultaneously at 18:17 UTC. Four of the six landed
inside a single second, which is not a person pressing a button with intent
- it is a person clicking a control that feels dead. They were cancelled
with 11 minutes to spare.

TWO DEFECTS, both in postOmniSocials():

  1) NO IN-FLIGHT LOCK. The button stays enabled during renderToBlob() +
     /media/upload + /posts/create, which together take seconds. Every
     click in that window starts a whole new upload-and-create. Nothing
     anywhere - client or server - refused the duplicates.

  2) NO VISIBLE FEEDBACK WHILE IT WORKS. The in-flight states only called
     setStatus(), and #status renders near the TOP of the page while the
     button sits far below it, so the user sees nothing at all until the
     run finishes. This is the SAME defect class the 2026-09-02 patch
     fixed for the refusal paths (STUDIO_FEEDBACK_PATCH) - that pass added
     toasts to every exit path but left the in-flight path silent, which is
     the longest period and the one where a user decides it is broken.

A third, contributing factor is by design and is not a bug: posting
SCHEDULES 30 minutes out, so nothing appears on LinkedIn when you look.
The success message said so, but via a toast that vanished in 1.8 seconds.
That duration is now configurable and the success toast holds for 7 seconds
and leads with "NOT live yet".

THE SERVER GUARD IS THE ONE THAT ACTUALLY HOLDS
-----------------------------------------------
The client lock is defeated by a page reload, a second browser tab, or two
people posting the same card. push.php therefore refuses an identical
(brand, platform, caption) within 10 minutes and names the existing post id
in the refusal, so the message reads as "already queued" rather than as an
error. 10 minutes comfortably covers the real failure mode here, which is
someone re-pressing because the post still is not visible on the platform.

Idempotent. Run with --check to see whether it is applied.
"""

import re
import subprocess
import sys
import tempfile
import os

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
HTML = os.path.join(ROOT, "studio", "index.html")
PHP = os.path.join(ROOT, "studio", "push.php")


# --------------------------------------------------------------------------
# edits. each is (path, exact_old, new, marker_that_proves_this_edit_landed)
# --------------------------------------------------------------------------

JS_EDITS = []

# 1) toast() gains an optional duration so an important message can outlive
#    the default 1.8s. The scheduled-time confirmation is exactly that.
JS_EDITS.append((
    HTML,
    """function toast(msg){
  var t = $('toast'); t.textContent = msg; t.classList.add('on');
  clearTimeout(t._t); t._t = setTimeout(function(){ t.classList.remove('on'); }, 1800);
}""",
    """function toast(msg, ms){
  /* STUDIO_POST_LOCK_PATCH: ms is optional. The scheduled-post confirmation
     carries the one fact people miss - that it is NOT live yet - and 1.8s
     was not long enough to read it. */
  var t = $('toast'); t.textContent = msg; t.classList.add('on');
  clearTimeout(t._t); t._t = setTimeout(function(){ t.classList.remove('on'); }, ms || 1800);
}""",
    "STUDIO_POST_LOCK_PATCH: ms is optional",
))

# 2) updateCapMeta() must not fight the in-flight lock. It runs on every
#    keystroke and on format change, and it unconditionally rewrote
#    pb.disabled and pb.textContent - which would re-enable the button
#    mid-post and re-open the duplicate window.
JS_EDITS.append((
    HTML,
    """  var pb = $('postOmniBtn');
  if(pb){
    var over = Boolean(cap) && t.length > cap;
    pb.disabled = over;
    pb.textContent = over
      ? ('Too long for ' + f.name + ' (' + t.length + '/' + cap + ')')
      : 'Post to OmniSocials (~30 min)';
  }""",
    """  var pb = $('postOmniBtn');
  /* STUDIO_POST_LOCK_PATCH: skip entirely while a post is in flight. This
     runs on every keystroke and on format change, and it used to rewrite
     .disabled unconditionally - which re-enabled the button mid-post and
     re-opened the duplicate window the lock exists to close. */
  if(pb && !S.posting){
    var over = Boolean(cap) && t.length > cap;
    pb.disabled = over;
    pb.textContent = over
      ? ('Too long for ' + f.name + ' (' + t.length + '/' + cap + ')')
      : 'Post to OmniSocials (~30 min)';
  }""",
    "STUDIO_POST_LOCK_PATCH: skip entirely while a post is in flight",
))

# 3) the lock itself, plus a toast on every in-flight state.
JS_EDITS.append((
    HTML,
    """function postOmniSocials(){
  var f = fmtNow();
  var caption = ($('capText').value || '').trim();
  if(!caption){ setStatus('write a caption first.', true);
    toast('write a caption first'); return; }   /* STUDIO_FEEDBACK_PATCH */
  if(f.maxChars && caption.length > f.maxChars){
    setStatus('caption is ' + caption.length + ' characters, over the ' + f.maxChars + ' limit for ' + f.name + ' - shorten it before posting.', true);
    toast('too long for ' + f.name + ': ' + caption.length + '/' + f.maxChars);
    return;
  }
  setStatus('rendering the card ...', false);
  renderToBlob(f, function(blob){
    var reader = new FileReader();
    reader.onload = function(){
      setStatus('posting to omnisocials ...', false);
      fetch('push.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ brand: S.brand, platform: f.id, caption: caption, image: reader.result })
      }).then(function(r){ return r.json(); }).then(function(r){
        if(!r.ok){ setStatus('failed: ' + (r.error || 'unknown error'), true);
          toast('post failed'); return; }
        var when = new Date(r.scheduled_at).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
        setStatus(r.label + ': scheduled for ' + when + ' (post ' + r.post_id + '). it WILL go live then unless you cancel it from your OmniSocials calendar first.', false);
        toast('scheduled on ' + r.label + ' for ' + when);
      }).catch(function(e){ setStatus('request failed: ' + e, true);
        toast('request failed'); });
    };
    reader.readAsDataURL(blob);
  });
}""",
    """/* STUDIO_POST_LOCK_PATCH -------------------------------------------------
   postingOn/postingOff bracket the whole run. S.posting is the single
   source of truth and updateCapMeta() defers to it, so the button cannot
   be re-enabled underneath us by a keystroke or a format change.

   The watchdog matters: renderToBlob() takes a callback and has no failure
   path of its own, so if it ever fails to call back the button would stay
   locked for the rest of the session with no way out but a reload. 90s is
   far beyond a real run (a measured run is a few seconds) and only ever
   fires on something genuinely wrong. */
function postingOn(f){
  S.posting = true;
  var pb = $('postOmniBtn');
  if(pb){ pb.disabled = true; pb.textContent = 'Posting to ' + f.name + ' ...'; }
  clearTimeout(S._postWatchdog);
  S._postWatchdog = setTimeout(function(){
    if(!S.posting) return;
    postingOff();
    setStatus('the post never came back. Nothing was confirmed - check your OmniSocials calendar before pressing again, so you do not create a second copy.', true);
    toast('timed out - check OmniSocials before retrying', 7000);
  }, 90000);
}
function postingOff(){
  S.posting = false;
  clearTimeout(S._postWatchdog);
  updateCapMeta();   /* restores the real label and disabled state */
}

function postOmniSocials(){
  /* The duplicate guard. Chadwick's six identical LinkedIn posts on
     2026-09-03 were four clicks inside one second plus two more nine
     seconds later, because nothing on screen changed when he pressed. */
  if(S.posting){ toast('already posting, hold on'); return; }
  var f = fmtNow();
  var caption = ($('capText').value || '').trim();
  if(!caption){ setStatus('write a caption first.', true);
    toast('write a caption first'); return; }   /* STUDIO_FEEDBACK_PATCH */
  if(f.maxChars && caption.length > f.maxChars){
    setStatus('caption is ' + caption.length + ' characters, over the ' + f.maxChars + ' limit for ' + f.name + ' - shorten it before posting.', true);
    toast('too long for ' + f.name + ': ' + caption.length + '/' + f.maxChars);
    return;
  }
  postingOn(f);
  setStatus('rendering the card ...', false);
  toast('rendering the card ...');          /* #status is above the fold; the button is not */
  renderToBlob(f, function(blob){
    var reader = new FileReader();
    reader.onload = function(){
      setStatus('uploading to omnisocials ...', false);
      toast('uploading to omnisocials ...');
      fetch('push.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ brand: S.brand, platform: f.id, caption: caption, image: reader.result })
      }).then(function(res){
        /* Read the body as TEXT first and parse by hand. r.json() on a 502
           or a PHP error page rejects with "Unexpected token <", which is
           what puts an unreadable error on screen and tells the operator
           nothing about what actually happened. Reporting the real HTTP
           status is the difference between "it threw an error" and "the
           server returned 503". */
        return res.text().then(function(body){
          var data = null;
          try { data = JSON.parse(body); } catch(e){}
          if(!data){
            throw new Error('the server returned ' + res.status + ' ' +
              (res.statusText || '') + ' instead of a result. It may still have posted - ' +
              'CHECK YOUR OMNISOCIALS CALENDAR before pressing again. Response began: ' +
              body.slice(0,140).replace(/\\s+/g,' '));
          }
          return data;
        });
      }).then(function(r){
        postingOff();
        if(!r.ok){ setStatus('did not post: ' + (r.error || 'unknown error'), true);
          toast('did not post: ' + (r.error || 'unknown error'), 7000); return; }
        var when = new Date(r.scheduled_at).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
        setStatus(r.label + ': queued for ' + when + ' (post ' + r.post_id + '). It is NOT on ' + r.label + ' yet and will not appear there until ' + when + '. It WILL go live then unless you cancel it from your OmniSocials calendar first.', false);
        toast('queued for ' + when + ' - NOT live yet, do not press again', 7000);
      }).catch(function(e){ postingOff();
        var m = (e && e.message) ? e.message : String(e);
        setStatus(m, true);
        toast(m.slice(0,90), 7000); });
    };
    reader.onerror = function(){ postingOff();
      setStatus('could not read the rendered card.', true);
      toast('could not read the card'); };
    reader.readAsDataURL(blob);
  });
}""",
    "STUDIO_POST_LOCK_PATCH ---",
))

# 4) declare S.posting alongside the rest of the state, so the flag is
#    visible where every other piece of studio state is declared.
JS_EDITS.append((
    HTML,
    """  toggles:{},
  card:{kicker:'',statement:'',figure:'',support:'',foot:'',footR:'',stack:[]}
};""",
    """  toggles:{},
  posting:false,   /* STUDIO_POST_LOCK_PATCH: true while a post is in flight */
  card:{kicker:'',statement:'',figure:'',support:'',foot:'',footR:'',stack:[]}
};""",
    "STUDIO_POST_LOCK_PATCH: true while a post is in flight",
))

PHP_EDITS = [(
    PHP,
    """// ---- 1) upload the card image""",
    """/*
 * STUDIO_DUP_GUARD_PATCH - refuse an identical card to the same platform
 * inside a short window.
 *
 * The client-side in-flight lock added the same day is the first line of
 * defence, but it lives in one page's memory: a reload, a second tab, or a
 * second person clears it. On 2026-09-03 six identical LinkedIn posts were
 * created in 11 seconds and NOTHING refused them, because this endpoint had
 * no concept of having just done the same thing.
 *
 * Ten minutes, because the real failure mode is not a double-click - it is
 * someone pressing again several minutes later since the post still is not
 * visible on the platform, which is exactly what scheduling 30 minutes out
 * guarantees. The refusal names the existing post id and is phrased as
 * "already queued", because that is what it is.
 */
$dupWindow = 600;
$dupFile = sys_get_temp_dir() . '/studio-post-' . md5($brand . '|' . $k . '|' . $caption) . '.json';
if (is_readable($dupFile)) {
    $prev = json_decode(@file_get_contents($dupFile), true);
    if (is_array($prev) && isset($prev['at']) && (time() - (int)$prev['at']) < $dupWindow) {
        $ago = time() - (int)$prev['at'];
        fail('this exact card was already queued to ' . $label . ' ' . $ago . ' seconds ago as post '
            . ($prev['post_id'] ?? '?') . '. It is scheduled and will publish on time - it does not appear on '
            . $label . ' until then, so nothing is wrong. If you really want a second copy, cancel that one '
            . 'in your OmniSocials calendar first.');
    }
}

// ---- 1) upload the card image""",
    "STUDIO_DUP_GUARD_PATCH",
), (
    PHP,
    """echo json_encode([
    'ok' => true,
    'by' => $remoteUser,""",
    """// STUDIO_DUP_GUARD_PATCH: remember this one so an immediate repeat is refused.
@file_put_contents($dupFile, json_encode([
    'at' => time(),
    'post_id' => $create['data']['data']['id'],
]));

echo json_encode([
    'ok' => true,
    'by' => $remoteUser,""",
    "STUDIO_DUP_GUARD_PATCH: remember this one",
)]


# --------------------------------------------------------------------------
# gates
# --------------------------------------------------------------------------

def js_parse_gate(path):
    """Extract every <script> block and run node --check on it. A grep for a
    marker proves text landed; it does not prove the file still parses. The
    2026-08-07 role-list patch shipped a syntax error past a passing grep."""
    src = open(path, encoding="utf-8").read()
    blocks = re.findall(r"<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>", src, re.S)
    if not blocks:
        raise SystemExit("PARSE GATE: found no inline script blocks - refusing to continue")
    for i, b in enumerate(blocks, 1):
        tmp = os.path.join(tempfile.gettempdir(), "studio_gate_%d.js" % i)
        open(tmp, "w", encoding="utf-8").write(b)
        r = subprocess.run(["node", "--check", tmp], capture_output=True, text=True)
        os.unlink(tmp)
        if r.returncode != 0:
            raise SystemExit("PARSE GATE FAILED on script block %d:\n%s" % (i, r.stderr[:1200]))
    return len(blocks)


def php_binary():
    """php is not installed on this machine. Rather than skip the lint - a
    gate that cannot fail is not a gate - point PHP_BIN at any php binary (a
    portable unzipped php.exe is fine) and the lint runs for real."""
    import shutil
    return os.environ.get("PHP_BIN") or shutil.which("php")


def php_parse_gate(path):
    php = php_binary()
    if not php:
        raise SystemExit(
            "PHP LINT UNAVAILABLE and it is NOT being skipped.\n"
            "  push.php runs on the live server; shipping it unlinted is how a\n"
            "  syntax error reaches production behind a passing grep.\n"
            "  Fix: set PHP_BIN to a php binary, e.g.\n"
            "    PHP_BIN=/path/to/php.exe python scripts/patch_studio_post_lock.py"
        )
    r = subprocess.run([php, "-l", path], capture_output=True, text=True)
    if r.returncode != 0:
        raise SystemExit("PHP PARSE GATE FAILED:\n%s\n%s" % (r.stdout, r.stderr))
    return r.stdout.strip()


def control_char_scan(path):
    """A python heredoc writing JS has twice produced a real control byte
    where an escape was intended (0x08 for a word boundary, a literal newline
    inside a string). node --check accepts both."""
    raw = open(path, "rb").read()
    bad = [(i, hex(b)) for i, b in enumerate(raw)
           if b < 0x20 and b not in (0x09, 0x0A, 0x0D)]
    if bad:
        raise SystemExit("CONTROL-CHAR SCAN FAILED %s" % (bad[:12],))
    return True


def main():
    check_only = "--check" in sys.argv
    edits = JS_EDITS + PHP_EDITS

    applied = [m in open(p, encoding="utf-8").read() for p, _, _, m in edits]
    if all(applied):
        print("ALREADY APPLIED (%d/%d markers present)" % (len(edits), len(edits)))
        return
    if any(applied) and not check_only:
        raise SystemExit(
            "PARTIAL PATCH: %d/%d markers present. Refusing to run on a "
            "half-patched file - revert with git first." % (sum(applied), len(edits))
        )
    if check_only:
        print("NOT APPLIED (%d/%d markers present)" % (sum(applied), len(edits)))
        return

    touched = set()
    for path, old, new, marker in edits:
        src = open(path, encoding="utf-8").read()
        n = src.count(old)
        if n != 1:
            raise SystemExit("ANCHOR MATCHED %d TIMES in %s (need exactly 1):\n---\n%s\n---"
                             % (n, os.path.basename(path), old[:220]))
        open(path, "w", encoding="utf-8", newline="").write(src.replace(old, new, 1))
        touched.add(path)
        print("  applied: %-16s <- %s" % (os.path.basename(path), marker[:58]))

    for path in sorted(touched):
        control_char_scan(path)
        if path.endswith(".html"):
            print("  parse gate: %s %d script block(s) OK"
                  % (os.path.basename(path), js_parse_gate(path)))
        else:
            print("  parse gate: %s" % php_parse_gate(path))

    left = [m for p, _, _, m in edits if m not in open(p, encoding="utf-8").read()]
    if left:
        raise SystemExit("VERIFY FAILED, missing markers: %s" % left)
    print("\nPATCH APPLIED AND VERIFIED (%d/%d markers)" % (len(edits), len(edits)))


if __name__ == "__main__":
    main()
