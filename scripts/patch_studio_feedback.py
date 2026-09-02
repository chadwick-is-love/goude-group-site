#!/usr/bin/env python3
"""
Studio: make the post button's refusal VISIBLE, and un-clickable when it cannot
work.

THE BUG (diagnosed live 2026-09-02, not guessed):
  The button was never broken. On IG story (140 char limit) and Threads (500),
  the Briefing's own auto-loaded caption is ~785 characters, so postOmniSocials
  refuses client-side and writes the reason with setStatus(). But #status lives
  at the TOP of the page (line ~298) while the button is far below it, so the
  message renders off-screen. Clicking did nothing visible => "the button
  didn't fire".
  Verified live against goudegroup.com/studio with push.php intercepted:
    linkedin / ig_feed / facebook -> post fine
    x -> caption auto-swaps to the 137-char captionX variant, posts fine
    ig_story -> BLOCKED (785 over 140)
    threads  -> BLOCKED (785 over 500)

THE FIX, two parts:
  1. Every exit path from postOmniSocials also fires toast(), which is
     position:fixed at the bottom of the viewport and therefore always visible.
     That includes the SUCCESS path - a silent success is the same usability
     bug as a silent refusal.
  2. updateCapMeta() disables the button and relabels it with the overage
     whenever the caption exceeds the current format's limit, so the state is
     visible BEFORE the click instead of being explained after it.

Idempotent. --check reports without writing. Refuses on a partial patch.
"""
import argparse
import re
import subprocess
import sys
import tempfile
from pathlib import Path

SRC = Path.home() / "Documents" / "goude-group-site" / "studio" / "index.html"
MARK = "STUDIO_FEEDBACK_PATCH"

EDITS = [
    # --- 1. the two client-side guards ------------------------------------
    (
        "  if(!caption){ setStatus('write a caption first.', true); return; }",
        "  if(!caption){ setStatus('write a caption first.', true);\n"
        "    toast('write a caption first'); return; }   /* " + MARK + " */",
    ),
    (
        "    setStatus('caption is ' + caption.length + ' characters, over the ' + f.maxChars + ' limit for ' + f.name + ' - shorten it before posting.', true);\n"
        "    return;",
        "    setStatus('caption is ' + caption.length + ' characters, over the ' + f.maxChars + ' limit for ' + f.name + ' - shorten it before posting.', true);\n"
        "    toast('too long for ' + f.name + ': ' + caption.length + '/' + f.maxChars);\n"
        "    return;",
    ),
    # --- 2. the network outcomes ------------------------------------------
    (
        "        if(!r.ok){ setStatus('failed: ' + (r.error || 'unknown error'), true); return; }",
        "        if(!r.ok){ setStatus('failed: ' + (r.error || 'unknown error'), true);\n"
        "          toast('post failed'); return; }",
    ),
    (
        "        setStatus(r.label + ': scheduled for ' + when + ' (post ' + r.post_id + '). it WILL go live then unless you cancel it from your OmniSocials calendar first.', false);",
        "        setStatus(r.label + ': scheduled for ' + when + ' (post ' + r.post_id + '). it WILL go live then unless you cancel it from your OmniSocials calendar first.', false);\n"
        "        toast('scheduled on ' + r.label + ' for ' + when);",
    ),
    (
        "      }).catch(function(e){ setStatus('request failed: ' + e, true); });",
        "      }).catch(function(e){ setStatus('request failed: ' + e, true);\n"
        "        toast('request failed'); });",
    ),
    # --- 3. disable the button while it cannot succeed ---------------------
    (
        "  var fold = t.slice(0,210), cut = fold.lastIndexOf(' ');",
        "  /* " + MARK + ": the button must not look clickable when the caption\n"
        "     cannot pass the length gate - the refusal message renders far above\n"
        "     this point on the page and is missed. */\n"
        "  var pb = $('postOmniBtn');\n"
        "  if(pb){\n"
        "    var over = Boolean(cap) && t.length > cap;\n"
        "    pb.disabled = over;\n"
        "    pb.textContent = over\n"
        "      ? ('Too long for ' + f.name + ' (' + t.length + '/' + cap + ')')\n"
        "      : 'Post to OmniSocials (~30 min)';\n"
        "  }\n"
        "  var fold = t.slice(0,210), cut = fold.lastIndexOf(' ');",
    ),
]


def js_gate(html):
    blocks = re.findall(r"<script(?![^>]*src=)[^>]*>(.*?)</script>", html, re.S)
    if not blocks:
        sys.exit("PARSE GATE: no inline script found")
    for i, b in enumerate(blocks):
        with tempfile.NamedTemporaryFile("w", suffix=".js", delete=False,
                                         encoding="utf-8") as fh:
            fh.write(b)
            t = fh.name
        r = subprocess.run(["node", "--check", t], capture_output=True, text=True)
        Path(t).unlink(missing_ok=True)
        if r.returncode != 0:
            sys.exit(f"PARSE GATE FAILED, script block {i}:\n{r.stderr[:700]}")
    return len(blocks)


def ctrl_scan(s):
    bad = [(i, hex(ord(c))) for i, c in enumerate(s)
           if ord(c) < 9 or (11 <= ord(c) <= 12) or (14 <= ord(c) <= 31)]
    if bad:
        sys.exit(f"CONTROL-CHAR SCAN FAILED {bad[:5]}")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--check", action="store_true")
    a = ap.parse_args()

    html = SRC.read_text(encoding="utf-8")
    n = html.count(MARK)
    if n:
        print(f"ALREADY PATCHED ({n} markers)")
        return
    if a.check:
        print("NOT PATCHED. Run without --check to apply.")
        return

    out = html
    for i, (old, new) in enumerate(EDITS, 1):
        c = out.count(old)
        if c != 1:
            sys.exit(f"ANCHOR {i} matched {c} times, expected 1:\n{old[:160]}")
        out = out.replace(old, new)

    blocks = js_gate(out)
    ctrl_scan(out)
    SRC.write_text(out, encoding="utf-8")
    print(f"PATCHED {SRC}")
    print(f"  {len(EDITS)} anchors, {blocks} script block(s) parse OK, ctrl-char scan OK")


if __name__ == "__main__":
    main()
