#!/usr/bin/env python3
"""
patch_studio_button_state.py - make the post button LOOK like it went through.

WHY (Kemmet, 2026-09-03)
------------------------
"This worked, it did pop up a message that said it went through. Still the
action is misleading. We need to see it change colour on-click so it feels
like it went through."

Driven locally and measured (scratchpad/see_button.py), the button on click
went:

    background   rgba(0,0,0,0)  ->  rgba(0,0,0,0)     NO CHANGE
    text colour  rgb(6,22,43)   ->  rgb(6,22,43)      NO CHANGE
    opacity      1              ->  0.4               faded

`.btn.ghost` is a transparent button with an inset outline, and the only
feedback was `.btn:disabled{opacity:.4}`. On screen the "POSTING TO
LINKEDIN ..." button was the FAINTEST control in the panel - fainter than the
idle "DOWNLOAD PNG" beside it. It read as disabled, not as busy, which is
exactly backwards.

WHAT CHANGES
------------
Two real states, both a fill rather than a fade:

  .btn.working  solid ink fill, reversed text, gently pulsing. Overrides the
                :disabled fade, so pressing makes the button MORE prominent
                instead of less.
  .btn.done     a success fill held for six seconds, labelled with the time it
                is queued for. This is the part that makes it "feel like it
                went through".

Colours come from the brand tokens, not from new hex literals in the rules:
`--ok` / `--ok-text` are declared per brand, so the gabes theme reuses its own
live accent rather than inheriting a Goude green.

The done state clears itself after six seconds OR the moment the operator
touches anything (updateCapMeta runs on every keystroke and format change and
now clears it), so it can never go stale against a changed caption or format.
The button is NOT disabled during the done window - the server-side duplicate
guard already refuses a repeat, and disabling would block a legitimate switch
to the next channel.

Reduced-motion is respected: the pulse is dropped, the fill is not.

Idempotent. --check reports state. Gates: JS parse + control-char scan.
"""

import os
import re
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
HTML = os.path.join(ROOT, "studio", "index.html")

EDITS = [

    # --- success tokens, per brand -----------------------------------------
    (HTML,
     """  --ontag:var(--gd-mint);
}""",
     """  --ontag:var(--gd-mint);
  /* STUDIO_BUTTON_STATE_PATCH: the confirmed-post fill. A green reads as
     success against the mint ground and is not used anywhere else. */
  --ok:#0f6b3f; --ok-text:var(--gd-mint);
}""",
     "STUDIO_BUTTON_STATE_PATCH: the confirmed-post fill"),

    (HTML,
     """  --logo-mark:#03b0c0;""",
     """  --logo-mark:#03b0c0;
  /* STUDIO_BUTTON_STATE_PATCH: gabes reuses its own live accent rather than
     inheriting the Goude green - paintTheme() repaints --acc per trio. */
  --ok:var(--acc); --ok-text:var(--acc-text);""",
     "STUDIO_BUTTON_STATE_PATCH: gabes reuses its own live accent"),

    # --- the two states -----------------------------------------------------
    (HTML,
     """.btn:disabled{opacity:.4;cursor:default}""",
     """.btn:disabled{opacity:.4;cursor:default}
/* STUDIO_BUTTON_STATE_PATCH -------------------------------------------------
   A ghost button fading to 40% reads as broken, not busy. Both states below
   FILL the button instead, so pressing it makes it more prominent, not less.
   .working and .done both beat .btn:disabled on specificity (0,3,0 vs 0,2,0),
   which is what stops the fade winning. */
.btn.working{background:var(--ink);color:var(--bg);box-shadow:none;
  animation:studioWorking 1.05s ease-in-out infinite}
.btn.working:disabled{opacity:1;cursor:progress}
.btn.done{background:var(--ok);color:var(--ok-text);box-shadow:none;opacity:1}
.btn.done:disabled{opacity:1}
@keyframes studioWorking{0%,100%{opacity:1}50%{opacity:.68}}
@media (prefers-reduced-motion:reduce){
  .btn.working{animation:none}
}""",
     "STUDIO_BUTTON_STATE_PATCH ---"),

    # --- working state on ---------------------------------------------------
    (HTML,
     """  if(pb){ pb.disabled = true; pb.textContent = 'Posting to ' + f.name + ' ...'; }""",
     """  if(pb){
    pb.disabled = true;
    pb.textContent = 'Posting to ' + f.name + ' ...';
    /* STUDIO_BUTTON_STATE_PATCH: fill it. The fade alone was invisible. */
    pb.classList.remove('done');
    pb.classList.add('working');
  }
  clearTimeout(S._doneTimer);
  S.doneShown = false;""",
     "STUDIO_BUTTON_STATE_PATCH: fill it"),

    # --- working state off, plus the done state -----------------------------
    (HTML,
     """function postingOff(){
  S.posting = false;
  clearTimeout(S._postWatchdog);
  updateCapMeta();   /* restores the real label and disabled state */
}""",
     """function postingOff(){
  S.posting = false;
  clearTimeout(S._postWatchdog);
  var pb = $('postOmniBtn');
  if(pb) pb.classList.remove('working');   /* STUDIO_BUTTON_STATE_PATCH */
  updateCapMeta();   /* restores the real label and disabled state */
}

/* STUDIO_BUTTON_STATE_PATCH: the confirmation the operator actually feels.
   Called AFTER postingOff, because postingOff runs updateCapMeta and that is
   what clears a stale done state. Held six seconds, or until the operator
   touches the caption or the format - whichever comes first, so it can never
   sit there claiming something true of a card that has since changed.
   Deliberately does NOT disable the button: the server-side duplicate guard
   already refuses a repeat, and disabling would block a legitimate switch to
   the next channel. */
function postingDone(msg){
  var pb = $('postOmniBtn');
  if(!pb) return;
  S.doneShown = true;
  pb.classList.remove('working');
  pb.classList.add('done');
  pb.textContent = msg;
  clearTimeout(S._doneTimer);
  S._doneTimer = setTimeout(function(){
    S.doneShown = false;
    pb.classList.remove('done');
    updateCapMeta();
  }, 6000);
}""",
     "STUDIO_BUTTON_STATE_PATCH: the confirmation the operator actually feels"),

    # --- updateCapMeta clears a stale done state ----------------------------
    (HTML,
     """  if(pb && !S.posting){
    var over = Boolean(cap) && t.length > cap;""",
     """  if(pb && !S.posting){
    /* STUDIO_BUTTON_STATE_PATCH: any edit to the caption or a format switch
       retires the confirmation, so it cannot describe a card that has since
       changed underneath it. */
    if(S.doneShown){
      S.doneShown = false;
      clearTimeout(S._doneTimer);
      pb.classList.remove('done');
    }
    var over = Boolean(cap) && t.length > cap;""",
     "STUDIO_BUTTON_STATE_PATCH: any edit to the caption"),

    # --- fire the done state on success -------------------------------------
    (HTML,
     """        toast('queued for ' + when + ' - NOT live yet, do not press again', 7000);""",
     """        toast('queued for ' + when + ' - NOT live yet, do not press again', 7000);
        postingDone('Queued for ' + when);   /* STUDIO_BUTTON_STATE_PATCH */""",
     "STUDIO_BUTTON_STATE_PATCH */"),
]


def js_parse_gate(path):
    src = open(path, encoding="utf-8").read()
    blocks = re.findall(r"<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>", src, re.S)
    if not blocks:
        raise SystemExit("PARSE GATE: no inline script blocks found")
    for i, b in enumerate(blocks, 1):
        tmp = os.path.join(tempfile.gettempdir(), "btnstate_gate_%d.js" % i)
        open(tmp, "w", encoding="utf-8").write(b)
        r = subprocess.run(["node", "--check", tmp], capture_output=True, text=True)
        os.unlink(tmp)
        if r.returncode != 0:
            raise SystemExit("PARSE GATE FAILED block %d:\n%s" % (i, r.stderr[:1000]))
    return len(blocks)


def control_char_scan(path):
    raw = open(path, "rb").read()
    bad = [(i, hex(b)) for i, b in enumerate(raw)
           if b < 0x20 and b not in (0x09, 0x0A, 0x0D)]
    if bad:
        raise SystemExit("CONTROL-CHAR SCAN FAILED %s" % (bad[:12],))
    return True


def main():
    check_only = "--check" in sys.argv
    applied = [m in open(p, encoding="utf-8").read() for p, _, _, m in EDITS]
    if all(applied):
        print("ALREADY APPLIED (%d/%d)" % (len(EDITS), len(EDITS)))
        return
    if check_only:
        print("NOT APPLIED (%d/%d markers present)" % (sum(applied), len(EDITS)))
        return
    if any(applied):
        raise SystemExit("PARTIAL PATCH (%d/%d) - revert with git first"
                         % (sum(applied), len(EDITS)))

    for path, old, new, marker in EDITS:
        src = open(path, encoding="utf-8").read()
        n = src.count(old)
        if n != 1:
            raise SystemExit("ANCHOR MATCHED %d TIMES (need 1):\n---\n%s\n---" % (n, old[:200]))
        open(path, "w", encoding="utf-8", newline="").write(src.replace(old, new, 1))
        print("  applied: %s" % marker[:64])

    control_char_scan(HTML)
    print("  parse gate: %d script block(s) OK" % js_parse_gate(HTML))
    left = [m for p, _, _, m in EDITS if m not in open(p, encoding="utf-8").read()]
    if left:
        raise SystemExit("VERIFY FAILED: %s" % left)
    print("\nPATCH APPLIED AND VERIFIED (%d/%d)" % (len(EDITS), len(EDITS)))


if __name__ == "__main__":
    main()
