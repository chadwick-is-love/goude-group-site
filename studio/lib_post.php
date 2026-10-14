<?php
/*
 * lib_post.php - STUDIO_AUTOPOST_2026-10-13. The body of push.php, moved into
 * one function so the push button (push.php) and the approval queue
 * (queue.php) run the SAME checks and the SAME upload->create code. Nothing
 * in the checks changed in the move; see push.php's header for the history
 * of every rule here.
 *
 * studio_post($in, $by, $opt)
 *   $in   the push.php request body (brand, platform, caption, images[],
 *         x_mode, thread, slot, issue)
 *   $by   the logged-in user (REMOTE_USER)
 *   $opt  'scheduled_at' => unix time to publish (default: 30 minutes out).
 *                           Refused if under 10 minutes away or more than
 *                           10 days out (TikTok's ceiling, kept for every
 *                           network so one week fits and a month does not).
 *         'dry_run'      => true: every check, nothing sent.
 *         'via', 'queue_id' => written to the post log.
 * Returns the result array; throws StudioFail with the refusal text.
 */

require_once __DIR__ . '/omnisocials.php';

class StudioFail extends Exception {}
function studio_fail($msg) { throw new StudioFail($msg); }

// Superset of GB_FORMATS and GD_FORMATS in index.html - keep these in sync
// if either list ever changes. 'threads' is Goude-side only for now; gabes
// has no Threads format, which is harmless, the maps are supersets.
const PLATFORM_LABELS = [
    'x' => 'X', 'linkedin' => 'LinkedIn', 'ig_feed' => 'Instagram feed',
    'ig_story' => 'Instagram story', 'facebook' => 'Facebook',
    'threads' => 'Threads',
];
// STUDIO_PARITY_2026-09-26: these are now the PLATFORMS' own ceilings, read
// from docs.omnisocials.com 2026-09-26. The house ceilings (IG 900, LinkedIn
// 1300, Facebook 1000) moved into the Studio as the "standard" length; the
// operator can pick "long" up to these. The client's PLATFORM table in
// index.html carries the same numbers - keep the two in step.
const PLATFORM_MAX_CHARS = [
    'x' => 280, 'linkedin' => 3000, 'ig_feed' => 2200,
    'ig_story' => 140, 'facebook' => 63206, 'threads' => 500,
];
// Images one post may carry. X 4; Instagram, Facebook and Threads carousels
// 2 to 10; an Instagram story set posts each image as its own slide (up to
// 10); LinkedIn turns 2+ images into its native document carousel.
const PLATFORM_MAX_IMAGES = [
    'x' => 4, 'linkedin' => 10, 'ig_feed' => 10,
    'ig_story' => 10, 'facebook' => 10, 'threads' => 10,
];
const X_LONG_MAX = 25000;      // X Premium long post
const X_THREAD_MAX_PARTS = 25; // X threads: 2 to 25 parts of 280
// ig_feed and ig_story share ONE Instagram channel id (confirmed live: GET
// /accounts returns a single instagram account carrying
// content_types:["post","story","reel"]) - the channel id alone cannot tell
// them apart, so the post TYPE does. Both values PROVEN 2026-08-28.
const PLATFORM_POST_TYPE = [
    'x' => 'post', 'linkedin' => 'post', 'ig_feed' => 'post',
    'ig_story' => 'story', 'facebook' => 'post', 'threads' => 'post',
];
const BRANDS = ['goude', 'gabes'];
// GABES_KEY_PATCH 2026-09-21: each brand is its own OmniSocials workspace, and
// every channel id carries its workspace id as a prefix (1000120_linkedin_page).
const BRAND_WORKSPACE = ['goude' => '1000120', 'gabes' => '1000551'];

// X counts every link as 23 characters and wide characters as two. Same rule
// as xLen() in index.html.
function x_len($t) {
    $n = 0;
    $t = preg_replace_callback('~(https?://\S+|www\.\S+|\b[a-z0-9-]+\.(?:com|ai|org|net|io|co)(?:/\S*)?)~i',
        function ($m) use (&$n) { $n += 23; return ''; }, $t);
    foreach (preg_split('//u', $t, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $cp = function_exists('mb_ord') ? mb_ord($ch, 'UTF-8') : 0;
        $n += ($cp > 0x10FF) ? 2 : 1;
    }
    return $n;
}

function studio_log_append($row) {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    if (!is_dir($dir)) return;
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    $fh = @fopen($dir . '/posts.jsonl', 'ab');
    if (!$fh) return;
    if (@flock($fh, LOCK_EX)) { @fwrite($fh, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n"); @flock($fh, LOCK_UN); }
    @fclose($fh);
}

function studio_post(array $in, $by, array $opt = []) {
    $when = isset($opt['scheduled_at']) ? (int)$opt['scheduled_at'] : (time() + 1800);
    if (isset($opt['scheduled_at'])) {
        if ($when < time() + 600) studio_fail('that slot time has passed or is under 10 minutes away (' . gmdate('Y-m-d H:i', $when) . ' UTC).');
        if ($when > time() + 10 * 86400) studio_fail('that slot is more than 10 days out; OmniSocials will not hold it.');
    }
    $channels = omnisocials_channels();

    $brand = $in['brand'] ?? '';
    $k = $in['platform'] ?? '';
    $label = PLATFORM_LABELS[$k] ?? $k;
    $caption = trim($in['caption'] ?? '');
    // STUDIO_PARITY_2026-09-26: 'images' is the ordered carousel (1..N). The old
    // single 'image' field is still accepted so a cached page keeps working.
    $images = $in['images'] ?? (isset($in['image']) ? [$in['image']] : []);
    if (!is_array($images)) $images = [];
    $xMode = ($k === 'x') ? (string)($in['x_mode'] ?? 'short') : '';
    if ($xMode === '') $xMode = ($k === 'x') ? 'short' : '';
    $thread = $in['thread'] ?? null;
    // STUDIO_LOG_2026-09-26: where in the week this card sits, so the log can mark
    // the queue row. Free text from the client, capped, never trusted for anything
    // but display.
    $slot  = mb_substr(trim((string)($in['slot'] ?? '')), 0, 40);
    $issue = mb_substr(trim((string)($in['issue'] ?? '')), 0, 60);


    // ---- server-side checks gate. Never trust the client-side pass alone.
    if (!in_array($brand, BRANDS, true)) {
        studio_fail('unknown brand: ' . $brand);
    }
    // GABES_KEY_PATCH: the key is per brand, so it is read only once the brand is
    // known. No brand ever falls back to another brand's key.
    $key = omnisocials_key($brand);
    if ($key === '') {
        studio_fail('no OmniSocials key on the server for the ' . $brand . ' side. add '
            . OMNISOCIALS_KEY_SOURCES[$brand]['file'] . ' next to push.php.');
    }
    if (!array_key_exists($k, PLATFORM_LABELS)) {
        studio_fail('unknown platform: ' . $k);
    }
    // channels.json is brand-scoped: brand -> platform key -> channel id. Every
    // account in workspace 1000120 belongs to The Goude Group; the gabes map is
    // deliberately empty until gabes.ai's own accounts are connected, so the
    // gabes button fails here with a specific message rather than silently
    // posting gabes work to Goude Group's feeds.
    $channelId = trim($channels[$brand][$k] ?? '');
    if ($channelId === '') {
        studio_fail('no channel id configured for ' . $label . ' on the ' . $brand . ' side in channels.json');
    }
    // GABES_KEY_PATCH: refuse a channel id from the wrong workspace outright. This
    // is exactly how >gabes work could end up on Goude Group's feeds (a parked
    // branch in gabes-social-studio maps >gabes to the 1000120 accounts).
    if (strpos($channelId, BRAND_WORKSPACE[$brand] . '_') !== 0) {
        studio_fail('the ' . $label . ' channel for the ' . $brand . ' side belongs to a different '
            . 'OmniSocials workspace. check channels.json.');
    }
    if ($caption === '') {
        studio_fail('empty caption');
    }
    // Count CHARACTERS, not bytes (mb_strlen), so the server agrees with the
    // on-screen counter. X counts links as 23 (x_len).
    if (preg_match('/[\x{2013}\x{2014}]/u', $caption)) {
        studio_fail('the caption has an em or en dash. House rule: none. Press Fit to channel.');
    }
    if (preg_match('/(^|\s)#[A-Za-z0-9_]+/', $caption)) {
        studio_fail('the caption has a hashtag. House rule: no hashtags on any network.');
    }
    $threadParts = [];
    if ($k === 'x' && $xMode === 'thread') {
        if (!is_array($thread)) studio_fail('a thread was asked for but no parts arrived.');
        foreach ($thread as $i => $part) {
            $part = trim((string)$part);
            if ($part === '') continue;
            if (preg_match('/[\x{2013}\x{2014}]/u', $part) || preg_match('/(^|\s)#[A-Za-z0-9_]+/', $part)) {
                studio_fail('thread part ' . ($i + 1) . ' has a dash or a hashtag.');
            }
            $pl = x_len($part);
            if ($pl > 280) studio_fail('thread part ' . ($i + 1) . ' is ' . $pl . ' characters, over 280.');
            $threadParts[] = $part;
        }
        if (count($threadParts) < 2 || count($threadParts) > X_THREAD_MAX_PARTS) {
            studio_fail('an X thread takes 2 to ' . X_THREAD_MAX_PARTS . ' parts; this has ' . count($threadParts) . '.');
        }
        $caption = $threadParts[0];
    } elseif ($k === 'x') {
        $max = ($xMode === 'long') ? X_LONG_MAX : 280;
        $len = x_len($caption);
        if ($len > $max) {
            studio_fail('caption is ' . $len . ' characters on X (links count as 23), over the ' . $max . ' limit' . ($xMode === 'long' ? '' : '. Pick Long (Premium) or Thread.'));
        }
    } else {
        $max = PLATFORM_MAX_CHARS[$k];
        $len = function_exists('mb_strlen') ? mb_strlen($caption, 'UTF-8') : strlen($caption);
        if ($len > $max) {
            studio_fail('caption is ' . $len . ' characters, over the ' . $max . ' limit for ' . $label . ' - shorten it before posting.');
        }
    }
    if (count($images) < 1) studio_fail('no rendered card image');
    if (count($images) > PLATFORM_MAX_IMAGES[$k]) {
        studio_fail($label . ' takes up to ' . PLATFORM_MAX_IMAGES[$k] . ' images in one post; this has ' . count($images) . '.');
    }
    $bins = [];
    foreach ($images as $i => $dataUrl) {
        if (!is_string($dataUrl) || strpos($dataUrl, 'data:image/png;base64,') !== 0) {
            studio_fail('image ' . ($i + 1) . ' is not a rendered card');
        }
        $bin = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);
        if ($bin === false || strlen($bin) === 0) studio_fail('image ' . ($i + 1) . ' failed to decode');
        $bins[] = $bin;
    }

    // STUDIO_AUTOPOST_2026-10-13: a dry run stops here, after every check and
    // before anything touches OmniSocials. queue.php uses it so a queued post has
    // already passed the same gate it will meet at approval.
    $kindDry = $threadParts ? ('thread of ' . count($threadParts)) : ($k === 'x' && $xMode === 'long' ? 'long post' : (count($bins) > 1 ? ($k === 'ig_story' ? 'story set' : 'carousel') : 'single card'));
    if (!empty($opt['dry_run'])) {
        return ['ok' => true, 'dry_run' => true, 'brand' => $brand, 'label' => $label,
                'images' => count($bins), 'kind' => $kindDry, 'caption' => $caption];
    }

    /*
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
    $dupFile = sys_get_temp_dir() . '/studio-post-' . md5($brand . '|' . $k . '|' . $xMode . '|' . count($bins) . '|' . $caption . '|' . implode("\n", $threadParts)) . '.json';
    if (is_readable($dupFile)) {
        $prev = json_decode(@file_get_contents($dupFile), true);
        if (is_array($prev) && isset($prev['at']) && (time() - (int)$prev['at']) < $dupWindow) {
            $ago = time() - (int)$prev['at'];
            studio_fail('this exact card was already queued to ' . $label . ' ' . $ago . ' seconds ago as post '
                . ($prev['post_id'] ?? '?') . '. It is scheduled and will publish on time - it does not appear on '
                . $label . ' until then, so nothing is wrong. If you really want a second copy, cancel that one '
                . 'in your OmniSocials calendar first.');
        }
    }

    // ---- 1) upload every image, in order
    $mediaIds = [];
    foreach ($bins as $i => $bin) {
        $tmp = tempnam(sys_get_temp_dir(), 'card') . '.png';
        file_put_contents($tmp, $bin);
        $upload = omnisocials_request('POST', '/media/upload', $key, [
            'file' => new CURLFile($tmp, 'image/png', $k . '-' . ($i + 1) . '.png'),
        ], true);
        @unlink($tmp);
        if (!$upload['ok'] || !isset($upload['data']['data']['id'])) {
            studio_fail('upload of image ' . ($i + 1) . ' failed' . ($mediaIds ? ' (after ' . count($mediaIds) . ' uploaded)' : '') . ': '
                . ($upload['data']['error']['message'] ?? ($upload['error'] ?? 'unknown error')));
        }
        $mediaIds[] = $upload['data']['data']['id'];
    }
    $mediaId = $mediaIds[0];

    // ---- 2) create the post, scheduled 30 minutes out (not a permanent draft -
    // this WILL publish itself when the time hits unless someone cancels it from
    // the OmniSocials calendar first). publish_now is deliberately omitted, not
    // set to false - combining it with scheduled_at was never tested and BH's
    // proven pattern never sends it at all.
    $scheduledAt = gmdate('Y-m-d\TH:i:s.000\Z', $when);
    // STUDIO_PARITY_2026-09-26: several media ids make a carousel (or a story
    // set); OmniSocials infers it from the count. A thread rides in
    // x.thread_parts per the vendor docs, card on part one. NOT yet proven live on
    // this workspace: carousels, X long posts and X threads. The single-image
    // shape above is the proven one.
    $body = [
        'content' => $caption,
        'channels' => [$channelId],
        'type' => PLATFORM_POST_TYPE[$k],
        'media_ids' => $mediaIds,
        'scheduled_at' => $scheduledAt,
    ];
    if ($threadParts) {
        $parts = [];
        foreach ($threadParts as $i => $t) {
            $parts[] = ($i === 0) ? ['text' => $t, 'media_ids' => $mediaIds] : ['text' => $t];
        }
        $body['x'] = ['thread_parts' => $parts];
    }
    $create = omnisocials_request('POST', '/posts/create', $key, $body);
    if (!$create['ok'] || !isset($create['data']['data']['id'])) {
        studio_fail('post create failed (media uploaded, ids ' . implode(',', $mediaIds) . '): '
            . ($create['data']['error']['message'] ?? ($create['error'] ?? 'unknown error')));
    }

    // STUDIO_DUP_GUARD_PATCH: remember this one so an immediate repeat is refused.
    @file_put_contents($dupFile, json_encode([
        'at' => time(),
        'post_id' => $create['data']['data']['id'],
    ]));

    /*
     * STUDIO_LOG_2026-09-26 - the post log. One line per created post, appended
     * under studio/data/ (denied to the web by data/.htaccess, read back through
     * log.php behind the same login). Until this file existed the Studio had no
     * record of what went where, when, or by whom; the only memory was the
     * ten-minute dup guard in /tmp. Best effort: a log failure never fails a post.
     */
    $kind = $threadParts ? ('thread of ' . count($threadParts)) : ($k === 'x' && $xMode === 'long' ? 'long post' : (count($mediaIds) > 1 ? ($k === 'ig_story' ? 'story set' : 'carousel') : 'single card'));
    studio_log_append([
        'at'           => gmdate('c'),
        'by'           => $by,
        'brand'        => $brand,
        'platform'     => $k,
        'label'        => $label,
        'kind'         => $kind,
        'images'       => count($mediaIds),
        'post_id'      => $create['data']['data']['id'],
        'media_ids'    => $mediaIds,
        'scheduled_at' => $scheduledAt,
        'slot'         => $slot,
        'issue'        => $issue,
        'via'          => $opt['via'] ?? 'push',
        'queue_id'     => $opt['queue_id'] ?? '',
        'caption_hash' => substr(md5($caption), 0, 12),
        'caption_head' => mb_substr($caption, 0, 90),
    ]);

    return [
        'ok' => true,
        'by' => $by,
        'brand' => $brand,
        'label' => $label,
        'post_id' => $create['data']['data']['id'],
        'media_id' => $mediaId,
        'media_ids' => $mediaIds,
        'images' => count($mediaIds),
        'kind' => $threadParts ? ('thread of ' . count($threadParts)) : ($k === 'x' && $xMode === 'long' ? 'long post' : (count($mediaIds) > 1 ? ($k === 'ig_story' ? 'story set' : 'carousel') : 'single card')),
        'status' => $create['data']['data']['status'] ?? null,
        'scheduled_at' => $scheduledAt,
    ];
}
