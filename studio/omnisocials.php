<?php
/*
 * omnisocials.php - shared server-side helpers for talking to OmniSocials.
 *
 * Same key-file idiom already proven in the gabes-social-studio repo: the
 * key never reaches the browser. Read (in order) from:
 *   1) environment variable OMNISOCIALS_API_KEY
 *   2) omnisocials-key.txt one level ABOVE this folder (outside the web folder)
 *   3) omnisocials-key.txt in this folder (blocked from the web by .htaccess)
 *
 * channels.json maps brand -> platform key (matching GB_FORMATS and
 * GD_FORMATS ids in index.html) -> the OmniSocials channel/account id it
 * should post to. It is NOT secret - committed to the repo. Workspace:
 * "Goude Group / Gabes" (id 1000120), confirmed live 2026-08-28.
 *
 * All six connected accounts in that workspace are The Goude Group's
 * (instagram, facebook, linkedin_page, x, threads, pinterest - verified
 * against GET /accounts 2026-08-31). There is no gabes.ai account yet, so
 * the "gabes" map is empty on purpose.
 *
 * Base API shape proven live in production, first by Brother Holiday's
 * card_upload.py, then again directly against this workspace 2026-08-28
 * (real draft posts 100010052/100010053):
 *   POST /media/upload  multipart 'file='          -> 201 {data:{id}}
 *   POST /posts/create  {content, channels:[id], type,
 *                        media_ids:[id], publish_now:false} -> 201 {data:{id,status:"draft"}}
 */

const OMNISOCIALS_BASE = 'https://api.omnisocials.com/v1';

function omnisocials_key() {
    $env = getenv('OMNISOCIALS_API_KEY');
    if ($env) return trim($env);
    foreach ([__DIR__ . '/../omnisocials-key.txt', __DIR__ . '/omnisocials-key.txt'] as $p) {
        if (is_readable($p)) {
            $k = trim(file_get_contents($p));
            if ($k !== '') return $k;
        }
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
