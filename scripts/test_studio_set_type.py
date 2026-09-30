"""Studio: one headline size per set, and the Post button on both brands.

Runs the REAL Studio page in headless Chromium against a FAKE OmniSocials, twice:
  before = studio/index.html as it is on main
  after  = the working copy (this branch)
For each brand it builds the story set ("Build from this story") on the IG story
format, renders every slide exactly as posting does (slidesToBlobs), and records the
headline font size each slide used, by watching the canvas font on every fillText.
Then, on the working copy only, it presses Post for the set and for a single card,
and checks what leaves the browser. Nothing reaches OmniSocials.

  PHP_BIN=/path/to/php.exe PHP_INI=/path/to/php.ini python scripts/test_studio_set_type.py

Writes before/after slide strips to scripts/set_type_<brand>.png.
"""
import base64, io, json, os, shutil, struct, subprocess, sys, tempfile, threading, time
from http.server import BaseHTTPRequestHandler, HTTPServer
from playwright.sync_api import sync_playwright

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.dirname(os.path.abspath(__file__))
PHP = os.environ.get("PHP_BIN"); INI = os.environ.get("PHP_INI", "")
if not PHP: sys.exit("set PHP_BIN (and PHP_INI with extension=curl); this test does not skip")
CAP = 8997
captured = []

def png_size(b):
    i = b.find(b"\x89PNG\r\n\x1a\n")
    return None if i < 0 else struct.unpack(">II", b[i + 16:i + 24])

class Cap(BaseHTTPRequestHandler):
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get("Content-Length") or 0))
        rec = {"path": self.path, "auth": self.headers.get("Authorization")}
        if self.path.endswith("/posts/create"):
            rec["json"] = json.loads(body); out = {"data": {"id": "post-%d" % len(captured), "status": "scheduled"}}
        else:
            rec["png"] = png_size(body); out = {"data": {"id": "m%03d" % len(captured)}}
        captured.append(rec)
        d = json.dumps(out).encode()
        self.send_response(201); self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(d))); self.end_headers(); self.wfile.write(d)
    def log_message(self, *a): pass

def make_site(root, index_html):
    site = os.path.join(root, "site")
    shutil.copytree(REPO, site, ignore=shutil.ignore_patterns(".git", "*.png"))
    ip = os.path.join(site, "studio", "index.html")
    html = index_html if index_html is not None else open(ip, encoding="utf-8", newline="").read()
    # test-only hook: the page already exposes S as window.__ST; also expose the renderer posting uses
    assert html.count("window.__ST = S;") == 1
    html = html.replace("window.__ST = S;", "window.__ST = S; window.__T = {slidesToBlobs: slidesToBlobs, fmtNow: fmtNow};")
    open(ip, "w", encoding="utf-8", newline="").write(html)
    om = os.path.join(site, "studio", "omnisocials.php")
    src = open(om, encoding="utf-8").read()
    assert src.count("https://api.omnisocials.com/v1") == 1
    open(om, "w", encoding="utf-8").write(src.replace("https://api.omnisocials.com/v1", "http://127.0.0.1:%d/v1" % CAP))
    open(os.path.join(site, "studio", "omnisocials-key.txt"), "w").write("FAKE-GOUDE")
    open(os.path.join(site, "studio", "omnisocials-key-gabes.txt"), "w").write("FAKE-GABES")
    open(os.path.join(root, "router.php"), "w").write(
        "<?php $u = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);\n"
        "if (preg_match('#^/studio/(push|fetch|log)\\.php$#', $u, $m)) {\n"
        "  $_SERVER['REMOTE_USER'] = 'e2e'; chdir(__DIR__ . '/site/studio'); require __DIR__ . '/site/studio/' . $m[1] . '.php'; return true; }\n"
        "return false;\n")
    return site

fails = []
def check(cond, msg):
    print(("PASS  " if cond else "FAIL  ") + msg)
    if not cond: fails.append(msg)

# Records the headline size of every slide, as posting renders them.
MEASURE = """async (fam) => {
  /* only the 2x export canvases count: the stage, the strip and the lock's own measuring pass draw elsewhere */
  const P = CanvasRenderingContext2D.prototype, orig = P.fillText, big = new Map(), sizes = [], W2 = __T.fmtNow().w * 2;
  P.fillText = function(t, x, y){ if(this.canvas.width === W2 && this.font.indexOf(fam) >= 0){ const m = this.font.match(/([0-9.]+)px/); if(m) big.set(this.canvas, Math.max(big.get(this.canvas) || 0, +m[1])); } return orig.apply(this, arguments); };
  const tb = HTMLCanvasElement.prototype.toBlob;
  HTMLCanvasElement.prototype.toBlob = function(){ if(this.width === W2) sizes.push(big.get(this) || 0); return tb.apply(this, arguments); };
  const urls = await new Promise(res => __T.slidesToBlobs(__T.fmtNow(), bs => Promise.all(bs.map(b => new Promise(r => { const fr = new FileReader(); fr.onload = () => r(fr.result); fr.readAsDataURL(b); }))).then(res)));
  P.fillText = orig; HTMLCanvasElement.prototype.toBlob = tb;
  return {sizes: sizes.map(s => Math.round(s * 10) / 10), urls: urls, layouts: __ST.slides.map(s => s.layout)};
}"""

def open_brand(pg, brand, web):
    if brand == "gabes":
        pg.click("[data-brand-btn='gabes']")
        pg.wait_for_function("document.documentElement.getAttribute('data-brand') === 'gabes'", timeout=10000)
    pg.wait_for_function("__ST.items && __ST.items.length > 0", timeout=60000)
    pg.click("[data-format='ig_story']")
    pg.click("[data-mode='carousel']")
    pg.click("#slStory")
    pg.wait_for_function("__ST.slides && __ST.slides.length >= 2", timeout=15000)

def run(label, index_html, press_buttons):
    root = tempfile.mkdtemp(prefix="settype-")
    site = make_site(root, index_html)
    web = 8990 if label == "before" else 8991
    env = {k: v for k, v in os.environ.items() if not k.startswith("OMNISOCIALS_")}
    tmp = os.path.join(root, "tmp"); os.makedirs(tmp); env["TMP"] = env["TEMP"] = tmp
    srv = subprocess.Popen([PHP] + (["-c", INI] if INI else []) + ["-S", "127.0.0.1:%d" % web, "-t", site,
                           os.path.join(root, "router.php")], cwd=site, env=env,
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    time.sleep(1.5)
    res = {}
    try:
        with sync_playwright() as p:
            br = p.chromium.launch()
            for brand in ("goude", "gabes"):
                pg = br.new_page(viewport={"width": 1440, "height": 1000})
                errs = []; pg.on("pageerror", lambda e: errs.append(str(e)))
                def make_local(pg, web):
                    def local(route):
                        u = route.request.url.replace("https://goudegroup.com", "http://127.0.0.1:%d" % web)
                        r = pg.request.fetch(u)
                        route.fulfill(status=r.status, headers={"Content-Type": r.headers.get("content-type", "text/html"),
                                                                "Access-Control-Allow-Origin": "*"}, body=r.body())
                    return local
                pg.route("https://goudegroup.com/**", make_local(pg, web))
                pg.goto("http://127.0.0.1:%d/studio/" % web, wait_until="load")
                open_brand(pg, brand, web)
                fam = "Playfair" if brand == "goude" else "Outfit"
                m = pg.evaluate(MEASURE, fam)
                res[brand] = m
                print("%-6s %-5s slides %d  headline px %s  layouts %s" % (label, brand, len(m["sizes"]), m["sizes"], m["layouts"]))
                if press_buttons:
                    # the set: every slide uploads at story size, one story create, right key and account
                    pg.fill("#capText", "set type check " + brand)
                    captured.clear()
                    pg.click("#postOmniBtn")
                    pg.wait_for_function("/queued for|did not|not posted/i.test(document.getElementById('sendStatus').textContent)", timeout=120000)
                    st = pg.inner_text("#sendStatus")
                    ups = [c for c in captured if c["path"].endswith("/media/upload")]
                    crs = [c for c in captured if c["path"].endswith("/posts/create")]
                    acct = "1000120_instagram" if brand == "goude" else "1000551_instagram"
                    key = "Bearer FAKE-GOUDE" if brand == "goude" else "Bearer FAKE-GABES"
                    n = len(m["sizes"])
                    check("queued for" in st.lower(), "%s set: status says queued (%r)" % (brand, st[:90]))
                    check(len(ups) == n and all(tuple(c["png"] or ()) == (2160, 3840) for c in ups),
                          "%s set: %d uploads at story size 2x %s" % (brand, len(ups), sorted({str(c['png']) for c in ups})))
                    check(len(crs) == 1 and crs[0]["json"].get("type") == "story" and crs[0]["json"].get("channels") == [acct]
                          and len(crs[0]["json"].get("media_ids", [])) == n,
                          "%s set: one story create to %s with %d slides" % (brand, acct, n))
                    check(captured and all(c["auth"] == key for c in captured), "%s set: every request used the %s key" % (brand, brand))
                    # a single card on a feed format
                    pg.click("[data-mode='single']")
                    fmt = "linkedin" if brand == "goude" else "ig_feed"
                    pg.click("[data-format='%s']" % fmt)
                    pg.fill("#capText", "single card check " + brand + " " + str(time.time()))
                    pg.wait_for_timeout(6500)
                    captured.clear()
                    pg.click("#postOmniBtn")
                    pg.wait_for_function("/queued for|did not|not posted|already/i.test(document.getElementById('sendStatus').textContent)", timeout=60000)
                    crs = [c for c in captured if c["path"].endswith("/posts/create")]
                    acct = "1000120_linkedin_page" if brand == "goude" else "1000551_instagram"
                    check(len(crs) == 1 and crs[0]["json"].get("channels") == [acct] and crs[0]["json"].get("type") == "post",
                          "%s single card: one post to %s (%s)" % (brand, acct, pg.inner_text('#sendStatus')[:70]))
                check(not errs, "%s %s: no page errors %s" % (label, brand, errs[:2]))
                pg.close()
            br.close()
    finally:
        srv.terminate(); srv.wait(timeout=10)
        shutil.rmtree(root, ignore_errors=True)
    return res

cap = HTTPServer(("127.0.0.1", CAP), Cap); threading.Thread(target=cap.serve_forever, daemon=True).start()
main_html = subprocess.run(["git", "show", "main:studio/index.html"], cwd=REPO, capture_output=True).stdout.decode("utf-8")
before = run("before", main_html, press_buttons=False)
after = run("after", None, press_buttons=True)
cap.shutdown()

for brand in ("goude", "gabes"):
    b, a = before[brand], after[brand]
    locked = [s for s, l in zip(a["sizes"], a["layouts"]) if not (brand == "goude" and l == "figure")]
    check(len(set(b["sizes"])) > 1, "%s before: the sizes differ across the set %s (the bug is real)" % (brand, b["sizes"]))
    check(len(set(locked)) == 1, "%s after: one headline size across the set %s" % (brand, a["sizes"]))
    check(locked and abs(min(locked) - min(s for s in b["sizes"] if s)) < 1.5,
          "%s after: the set size is the smallest the set already used (%s vs %s)" % (brand, min(locked) if locked else None, min(b['sizes'])))

# before/after strips for the reviewer
try:
    from PIL import Image, ImageDraw
    for brand in ("goude", "gabes"):
        rows = []
        for tag, r in (("BEFORE (main)", before[brand]), ("AFTER (this branch)", after[brand])):
            ims = [Image.open(io.BytesIO(base64.b64decode(u.split(",", 1)[1]))).convert("RGB").resize((270, 480)) for u in r["urls"]]
            row = Image.new("RGB", (20 + len(ims) * 290, 540), "white")
            ImageDraw.Draw(row).text((20, 12), tag + "   headline px: " + ", ".join(str(s) for s in r["sizes"]), fill="black")
            for i, im in enumerate(ims): row.paste(im, (20 + i * 290, 44))
            rows.append(row)
        W = max(r.width for r in rows); out = Image.new("RGB", (W, sum(r.height for r in rows)), "white")
        y = 0
        for r in rows: out.paste(r, (0, y)); y += r.height
        out.save(os.path.join(OUT, "set_type_%s.png" % brand))
        print("wrote", os.path.join(OUT, "set_type_%s.png" % brand))
except ImportError:
    print("PIL not installed; skipped the before/after strips")

print("\n%d failed" % len(fails))
sys.exit(1 if fails else 0)
