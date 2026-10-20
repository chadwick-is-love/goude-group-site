<?php
/**
 * fetch.php - same-origin reader for the Studio.
 *
 * The Studio lives on goudegroup.com and needs to read >gabes content on
 * gabes.ai. Browsers refuse that read and neither site sends CORS headers,
 * so this passes an ALLOWLISTED set of URLs through. It is not a general
 * proxy: anything outside the list below is refused.
 */

header('X-Robots-Tag: noindex, nofollow');

if (isset($_GET['probe'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'ok'      => true,
        'php'     => PHP_VERSION,
        'curl'    => function_exists('curl_init'),
        'fopen'   => (bool) ini_get('allow_url_fopen'),
    ));
    exit;
}

$EXACT = array(
    'https://gabes.ai/letter/signals/index.json',
    'https://gabes.ai/letter/issues/index.json',
);
$PREFIX = array(
    'https://gabes.ai/letter/issues/',
    'https://gabes.ai/letter/signals/',
    'https://gabes.ai/letter/studio/',   /* STUDIO_PACK_2026-10-20: issue packs and their photographs */
);

$u = isset($_GET['u']) ? (string) $_GET['u'] : '';

$ok = in_array($u, $EXACT, true);
if (!$ok) {
    foreach ($PREFIX as $p) {
        if (strncmp($u, $p, strlen($p)) === 0) { $ok = true; break; }
    }
}
if (strpos($u, '..') !== false || strpos($u, '?') !== false || strpos($u, "\n") !== false) {
    $ok = false;
}

if (!$ok) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not on the allowlist';
    exit;
}

$body = false;
if (function_exists('curl_init')) {
    $ch = curl_init($u);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => 'goude-studio',
    ));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) { $body = false; }
} 
if ($body === false && ini_get('allow_url_fopen')) {
    $ctx = stream_context_create(array('http' => array(
        'timeout' => 12,
        'header'  => "User-Agent: goude-studio\r\n",
    )));
    $body = @file_get_contents($u, false, $ctx);
}

if ($body === false) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'could not reach the source';
    exit;
}

/* STUDIO_PACK_2026-10-20: photographs pass through same-origin so the
   canvas that draws them can still export the slide. */
$ext = strtolower(pathinfo(parse_url($u, PHP_URL_PATH), PATHINFO_EXTENSION));
$IMG = array('webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg');
if (isset($IMG[$ext])) {
    header('Content-Type: ' . $IMG[$ext]);
    header('Cache-Control: private, max-age=3600');
    echo $body;
    exit;
}
$isJson = ($ext === 'json');
header('Content-Type: ' . ($isJson ? 'application/json' : 'text/html') . '; charset=utf-8');
header('Cache-Control: no-store');
echo $body;
