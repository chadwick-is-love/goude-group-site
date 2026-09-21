<?php
/*
 * omnisocials.php - shared server-side helpers for talking to OmniSocials.
 *
 * Same key-file idiom already proven in the gabes-social-studio repo: the
 * key never reaches the browser.
 *
 * GABES_KEY_PATCH 2026-09-21: keys are PER BRAND, because each brand is its own
 * OmniSocials workspace:
 *   goude -> "Goude Group / Gabes", workspace 1000120
 *   gabes -> "Gabes.AI / Gabes",   workspace 1000551 (0 accounts on 2026-09-21)
 * For each brand the key is read (in order) from:
 *   1) an environment variable  (OMNISOCIALS_API_KEY / OMNISOCIALS_API_KEY_GABES)
 *   2) its key file in THIS folder, next to push.php (blocked from the web by
 *      the .htaccess here, and gitignored)
 * There is deliberately NO one-level-up fallback: one level up is the
 * public_html root, which has no .htaccess, so a key file there would be
 * publicly downloadable. Removed on Chadwick's review, 2026-09-21.
 * Key files: omnisocials-key.txt (goude, unchanged) and omnisocials-key-gabes.txt.
 * A brand NEVER falls back to another brand's key: >gabes work posted with the
 * Goude key would land in the Goude workspace.
 *
 * channels.json maps brand -> platform key (matching GB_FORMATS and
 * GD_FORMATS ids in index.html) -> the OmniSocials channel/account id it
 * should post to. It is NOT secret - committed to the repo. Workspace:
 * "Goude Group / Gabes" (id 1000120), confirmed live 2026-08-28.
 *
 * All six connected accounts in that workspace are The Goude Group's
 * (instagram, facebook, linkedin_page, x, threads, pinterest - verified
 * against GET /accounts 2026-08-31). The >gabes accounts live in their own
 * workspace (1000551), which had none connected on 2026-09-21, so the "gabes"
 * map is empty on purpose until they are.
 *
 * Base API shape proven live in production, first by Brother Holiday's
 * card_upload.py, then again directly against this workspace 2026-08-28
 * (real draft posts 100010052/100010053):
 *   POST /media/upload  multipart 'file='          -> 201 {data:{id}}
 *   POST /posts/create  {content, channels:[id], type,
 *                        media_ids:[id], publish_now:false} -> 201 {data:{id,status:"draft"}}
 */

const OMNISOCIALS_BASE = 'https://api.omnisocials.com/v1';

const OMNISOCIALS_KEY_SOURCES = [
    'goude' => ['env' => 'OMNISOCIALS_API_KEY',       'file' => 'omnisocials-key.txt'],
    'gabes' => ['env' => 'OMNISOCIALS_API_KEY_GABES', 'file' => 'omnisocials-key-gabes.txt'],
];

// Defaults to goude so any caller written before brand-scoped keys keeps its
// old behaviour. An unknown brand gets no key at all.
function omnisocials_key($brand = 'goude') {
    if (!array_key_exists($brand, OMNISOCIALS_KEY_SOURCES)) return '';
    $src = OMNISOCIALS_KEY_SOURCES[$brand];
    $env = getenv($src['env']);
    if ($env) return trim($env);
    $p = __DIR__ . '/' . $src['file'];
    if (is_readable($p)) {
        $k = trim(file_get_contents($p));
        if ($k !== '') return $k;
    }
    return '';
}

function omnisocials_channels() {
    $p = __DIR__ . '/channels.json';
    if (!is_readable($p)) return [];
    $j = json_decode(file_get_contents($p), true);
    return is_array($j) ? $j : [];
}

// Backoff wrapper - the vendor's API occasionally 429/500/502/503s or drops
// the TLS socket mid-call. Up to 5 attempts, growing backoff.
function omnisocials_request($method, $path, $key, $body = null, $isMultipart = false) {
    $url = OMNISOCIALS_BASE . $path;
    $last = null;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $ch = curl_init($url);
        $headers = ['Authorization: Bearer ' . $key];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 60,
        ];
        if ($body !== null) {
            if ($isMultipart) {
                $opts[CURLOPT_POSTFIELDS] = $body; // array with a CURLFile
            } else {
                $headers[] = 'Content-Type: application/json';
                $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            }
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $last = ['ok' => false, 'error' => curl_error($ch)];
            curl_close($ch);
            usleep((int)((1.5 + $attempt * 1.5) * 1000000));
            continue;
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (in_array($status, [429, 500, 502, 503], true)) {
            $last = ['ok' => false, 'status' => $status, 'body' => $resp];
            usleep((int)((2 + $attempt * 2) * 1000000));
            continue;
        }
        $data = json_decode($resp, true);
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data, 'raw' => $resp];
    }
    return $last ?: ['ok' => false, 'error' => 'no attempts made'];
}
