"""Test the per-brand OmniSocials key change in goude-group-site/studio.

Runs the REAL push.php under PHP's built-in server behind a router that supplies
REMOTE_USER, with OMNISOCIALS_BASE pointed at a local capture server - so nothing
can ever reach OmniSocials, and we can see exactly which key and channel each
request would have used. Fake keys only.

PHP is not installed on the dev machine, so point PHP_BIN at a php.exe/php
(8.x with the curl, mbstring and openssl extensions) and optionally PHP_INI at
its php.ini. The test refuses to run without it rather than skipping.

  PHP_BIN=... python scripts/test_studio_brand_keys.py patched   -> must pass every case
  PHP_BIN=... python scripts/test_studio_brand_keys.py original  -> teeth test against main;
                                                                    the new behaviours must FAIL
"""
import json, os, shutil, subprocess, sys, tempfile, threading, time, urllib.request, uuid
from http.server import BaseHTTPRequestHandler, HTTPServer

MODE = sys.argv[1] if len(sys.argv) > 1 else "patched"
REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHP = os.environ.get("PHP_BIN", "")
INI = os.environ.get("PHP_INI", "")
if not PHP or not os.path.exists(PHP):
    sys.exit("set PHP_BIN to a php executable (8.x, curl + mbstring + openssl). Refusing to skip.")
CAP_PORT, PHP_PORT = 8977, 8976
GOUDE_KEY, GABES_KEY = "FAKE-GOUDE-KEY", "FAKE-GABES-KEY"
PNG = ("data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8"
       "z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==")
RUN = uuid.uuid4().hex[:8]

captured = []

class Cap(BaseHTTPRequestHandler):
    def do_POST(self):
        n = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(n) if n else b""
        rec = {"path": self.path, "auth": self.headers.get("Authorization")}
        if self.path.endswith("/posts/create"):
            rec["json"] = json.loads(body or b"{}")
            out = {"data": {"id": "p-test", "status": "scheduled"}}
        else:
            out = {"data": {"id": "m-test"}}
        captured.append(rec)
        data = json.dumps(out).encode()
        self.send_response(201)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)
    def log_message(self, *a):
        pass

def build_sandbox():
    root = tempfile.mkdtemp(prefix="gabes-test-")
    studio = os.path.join(root, "studio")
    os.makedirs(studio)
    for f in ("push.php", "omnisocials.php", "channels.json"):
        if MODE == "patched":
            src = open(os.path.join(REPO, "studio", f), encoding="utf-8").read()
        else:
            src = subprocess.run(["git", "-C", REPO, "show", "main:studio/" + f],
                                 capture_output=True, text=True, encoding="utf-8", check=True).stdout
        if f == "omnisocials.php":
            real = "https://api.omnisocials.com/v1"
            assert src.count(real) == 1, "OMNISOCIALS_BASE not found exactly once"
            src = src.replace(real, "http://127.0.0.1:%d/v1" % CAP_PORT)
        open(os.path.join(studio, f), "w", encoding="utf-8").write(src)
    open(os.path.join(root, "router.php"), "w").write(
        "<?php $_SERVER['REMOTE_USER'] = 'harness'; require __DIR__ . '/studio/push.php';\n")
    os.makedirs(os.path.join(root, "tmp"))
    return root

def set_keys(studio, goude, gabes):
    for name, val in (("omnisocials-key.txt", goude), ("omnisocials-key-gabes.txt", gabes)):
        p = os.path.join(studio, name)
        if val:
            open(p, "w").write(val)
        elif os.path.exists(p):
            os.remove(p)

def set_channels(studio, gabes_map):
    goude = json.load(open(os.path.join(studio, "channels.json"), encoding="utf-8"))["goude"]
    json.dump({"goude": goude, "gabes": gabes_map}, open(os.path.join(studio, "channels.json"), "w"))

def post(brand, platform):
    body = json.dumps({"brand": brand, "platform": platform,
                       "caption": "harness %s %s %s" % (RUN, brand, uuid.uuid4().hex[:6]),
                       "image": PNG}).encode()
    req = urllib.request.Request("http://127.0.0.1:%d/studio/push.php" % PHP_PORT, data=body,
                                 headers={"Content-Type": "application/json"})
    return json.loads(urllib.request.urlopen(req, timeout=60).read())

# name, goude_key, gabes_key, gabes_map, brand, expect_ok, expect_error_substring, expect_auth, expect_channel
CASES = [
    ("goude posts with the goude key (unchanged behaviour)",
     GOUDE_KEY, None, {}, "goude", True, None, "Bearer " + GOUDE_KEY, "1000120_linkedin_page"),
    ("gabes with no gabes key is refused before any request",
     GOUDE_KEY, None, {}, "gabes", False, "no OmniSocials key on the server for the gabes side", None, None),
    ("gabes key but no gabes channels is refused",
     GOUDE_KEY, GABES_KEY, {}, "gabes", False, "no channel id configured for LinkedIn on the gabes side", None, None),
    ("gabes posts with the GABES key to a gabes channel",
     GOUDE_KEY, GABES_KEY, {"linkedin": "1000551_linkedin_page"}, "gabes", True, None,
     "Bearer " + GABES_KEY, "1000551_linkedin_page"),
    ("gabes pointed at a GOUDE channel is refused (the parked-branch hazard)",
     GOUDE_KEY, GABES_KEY, {"linkedin": "1000120_linkedin_page"}, "gabes", False,
     "belongs to a different OmniSocials workspace", None, None),
    ("goude with no goude key never borrows the gabes key",
     None, GABES_KEY, {}, "goude", False, "no OmniSocials key on the server for the goude side", None, None),
    ("unknown brand is refused",
     GOUDE_KEY, GABES_KEY, {}, "nobody", False, "unknown brand", None, None),
]

def main():
    cap = HTTPServer(("127.0.0.1", CAP_PORT), Cap)
    threading.Thread(target=cap.serve_forever, daemon=True).start()
    root = build_sandbox()
    studio = os.path.join(root, "studio")
    env = {k: v for k, v in os.environ.items() if not k.startswith("OMNISOCIALS_")}
    env["TMP"] = env["TEMP"] = os.path.join(root, "tmp")   # duplicate-guard markers stay in the sandbox
    srv = subprocess.Popen([PHP] + (["-c", INI] if INI else []) + ["-S", "127.0.0.1:%d" % PHP_PORT, "-t", root,
                            os.path.join(root, "router.php")],
                           cwd=root, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    time.sleep(1.5)
    fails = 0
    try:
        for (name, gk, bk, gmap, brand, ok, err, auth, chan) in CASES:
            set_keys(studio, gk, bk)
            set_channels(studio, gmap)
            captured.clear()
            r = post(brand, "linkedin")
            problems = []
            if bool(r.get("ok")) != ok:
                problems.append("ok=%s (want %s): %s" % (r.get("ok"), ok, r.get("error")))
            if err and err not in (r.get("error") or ""):
                problems.append("error was %r" % r.get("error"))
            if ok:
                if [c["path"] for c in captured] != ["/v1/media/upload", "/v1/posts/create"]:
                    problems.append("requests %s" % [c["path"] for c in captured])
                elif any(c["auth"] != auth for c in captured):
                    problems.append("key sent %s (want %s)" % ({c["auth"] for c in captured}, auth))
                elif captured[1]["json"].get("channels") != [chan]:
                    problems.append("channels %s (want %s)" % (captured[1]["json"].get("channels"), [chan]))
            elif captured:
                problems.append("%d request(s) went out on a refused post" % len(captured))
            status = "PASS" if not problems else "FAIL"
            fails += bool(problems)
            print("%s  %s%s" % (status, name, "" if not problems else "  <- " + "; ".join(problems)))
    finally:
        srv.terminate()
        srv.wait(timeout=10)
        cap.shutdown()
        shutil.rmtree(root, ignore_errors=True)
    print("\n%s: %d of %d failed" % (MODE, fails, len(CASES)))
    return fails

if __name__ == "__main__":
    sys.exit(1 if main() else 0)
