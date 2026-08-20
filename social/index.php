<?php
/**
 * The Social Engine moved to The Studio on 2026-08-20.
 * Permanent redirect so old links and bookmarks land in the right place.
 */
header('HTTP/1.1 301 Moved Permanently');
header('Location: /studio/');
header('X-Robots-Tag: noindex, nofollow');
exit;
