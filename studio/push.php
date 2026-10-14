<?php
/*
 * push.php - posts the CURRENT card (whatever format/platform is on screen
 * in the studio) to OmniSocials, SCHEDULED 30 minutes out rather than a
 * permanent draft - Kemmet's explicit call, "the closest thing to posting
 * at the push of a button." This is a real behavior change from a plain
 * draft: a scheduled post PUBLISHES ITSELF when the time hits unless a
 * human actively cancels it first. The 30-minute window is the only safety
 * margin, not a guarantee - do not describe this to anyone as "nothing
 * goes live automatically," that stopped being true the moment scheduling
 * replaced publish_now:false.
 *
 * Unlike gabes-social-studio's push.php (which generates 5 platform-tuned
 * captions per signal via Claude), this app's caption is ONE shared field
 * across every format/platform - GABES.caption() in index.html builds it
 * once, independent of S.format, and the user is expected to check the
 * on-screen "N / max on <platform>" counter before switching formats. So
 * this endpoint posts ONE platform at a time, mirroring the existing
 * download() button (not downloadAll(), which iterates the queue, not
 * formats). Both brands now: GD_FORMATS (the Goude/Briefing side) carries
 * the same platform-named ids as GB_FORMATS plus threads, so one endpoint
 * serves both. The Goude side's X caption is a separate short-close variant
 * (GOUDE.captionX) - the full Briefing caption cannot fit 280.
 *
 * PROVEN 2026-08-28 against the real Goude Group / Gabes workspace:
 * - draft posts 100010052 (x) / 100010053 (ig_story), fired via a
 *   standalone script mirroring this exact upload->create shape:
 *   publish_now:false correctly returns status:"draft", published_urls:{}.
 *   type:"story" confirmed correct for Instagram stories the same way.
 * - post 100010067 (x, far-future scheduled_at, deleted immediately after):
 *   omitting publish_now and setting scheduled_at instead returns
 *   status:"scheduled" with schedule_at echoing the value sent - matches
 *   Brother Holiday's proven production card_upload.py pattern exactly
 *   (scheduled_at set, publish_now never sent). NOT separately proven: that
 *   a scheduled post actually fires and goes public at its time on THIS
 *   workspace - inherited from BH's own production history on a different
 *   workspace, not re-verified here (that would mean actually publishing
 *   something).
 */

require __DIR__ . '/lib_post.php';

header('Content-Type: application/json');

/*
 * This endpoint schedules posts to live social accounts and takes no
 * credential of its own - it relies entirely on the Basic auth block in
 * studio/.htaccess covering this whole folder. goude-group-site is a PUBLIC
 * repository, so the request shape below is readable by anyone; without that
 * auth block an anonymous POST here puts something on The Goude Group's real
 * feeds 30 minutes later. If REMOTE_USER is empty, the auth block is missing
 * or not being applied, and this must not run.
 */
$remoteUser = $_SERVER['REMOTE_USER'] ?? ($_SERVER['REDIRECT_REMOTE_USER'] ?? '');
if ($remoteUser === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'this endpoint is not authenticated - the studio .htaccess auth block is missing or not being applied. refusing to post.']);
    exit;
}

// STUDIO_AUTOPOST_2026-10-13: the checks and the send now live in
// lib_post.php (studio_post), shared with queue.php. Behaviour of this
// button is unchanged: 30 minutes out, every check, the dup guard, the log.
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
try {
    echo json_encode(studio_post($in, $remoteUser));
} catch (StudioFail $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
