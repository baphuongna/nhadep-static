<?php
/**
 * generate_nhadep_daily.php
 *
 * Static-site generator for nhadepnghean.vn
 * - Fetches posts from the WordPress REST API of noithatdatky.com
 * - Groups posts by publication date (Asia/Ho_Chi_Minh)
 * - Renders one static "tin tức ngày <date>" digest page per day
 * - Renders a landing page (out/index.html), sitemap.xml, robots.txt, feed.xml
 *
 * Requirements: PHP >= 8.0, ext-json, ext-simplexml optional (not required here),
 * curl extension preferred, falls back to file_get_contents.
 * No composer dependencies. No database.
 *
 * Usage:
 *   php generate_nhadep_daily.php [--date=YYYY-MM-DD] [--force] [--max-posts=N]
 *                                 [--out=DIR] [--verbose] [--help]
 *
 * Exit codes: 0 = OK, 1 = fatal error, 2 = invalid arguments.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
mb_internal_encoding('UTF-8');

const NHADEP_API_BASE      = 'https://noithatdatky.com/wp-json/wp/v2/posts';
const NHADEP_SOURCE_ORIGIN = 'https://noithatdatky.com/';
const NHADEP_CONTACT_URL   = 'https://noithatdatky.com/lien-he/';
const NHADEP_CANONICAL     = 'https://nhadepnghean.vn/';
const NHADEP_TZ            = 'Asia/Ho_Chi_Minh';
const NHADEP_PER_PAGE      = 100;
const NHADEP_MAX_PAGES     = 60;          // hard safety cap for pagination loops
const NHADEP_HTTP_TIMEOUT  = 25;          // seconds
const NHADEP_WEB_KEY       = 'datky-nhadep-gen-2026'; // webhook trigger: ?key=<NHADEP_WEB_KEY>

/* Brand facts (single source of truth for every rendered page). */
const NHADEP_BRAND_NAME    = 'NỘI THẤT ĐẠT KỲ — DK HOME DESIGN';
const NHADEP_BRAND_SHORT   = 'Nội thất Đạt Kỳ';
const NHADEP_TAGLINE       = 'Thiết kế & thi công nội thất trọn gói Nhà Phố, Villa, Căn hộ tại Nghệ An & Hà Tĩnh';
const NHADEP_HOTLINE_TEXT  = '0922 355 368';
const NHADEP_HOTLINE_TEL   = 'tel:+84922355368';
const NHADEP_EMAIL         = 'noithatdatky@gmail.com';
const NHADEP_ADDRESS       = 'Số 68 Quán Lề, Khu đô thị Vườn Xanh, Diễn Châu, Nghệ An';
const NHADEP_LOGO_URL      = 'https://noithatdatky.com/wp-content/uploads/2020/11/noi-that-dat-ky-logo.png';
const NHADEP_FEED_TITLE    = 'Tin tức nội thất — Nội thất Đạt Ký';
const NHADEP_FEED_URL      = NHADEP_CANONICAL . 'feed.xml';

/* -------------------------------------------------------------------------
 * PHP 7.4 polyfills (Hostinger CLI default is PHP 7.4; web is 8.x).
 * ---------------------------------------------------------------------- */
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

/* -------------------------------------------------------------------------
 * HTTP layer — named functions so tests can override behaviour via
 * $GLOBALS['NHADEP_HTTP_GET_OVERRIDE'] (fetch path) and
 * $GLOBALS['NHADEP_STATUS_OVERRIDE'] (contact-URL probe only).
 * ---------------------------------------------------------------------- */

/**
 * Raw HTTP GET.
 * Returns ['status' => int, 'body' => string|false, 'err' => string].
 *  - status 0 means the transport failed (body false, err set).
 *  - The body is preserved for 4xx/5xx so callers can inspect it
 *    (e.g. WordPress rest_post_invalid_page_number on HTTP 400).
 * Tests may set $GLOBALS['NHADEP_HTTP_GET_OVERRIDE'] = fn(string $url): array;
 */
function nhadep_http_get(string $url): array
{
    if (isset($GLOBALS['NHADEP_HTTP_GET_OVERRIDE']) && is_callable($GLOBALS['NHADEP_HTTP_GET_OVERRIDE'])) {
        return ($GLOBALS['NHADEP_HTTP_GET_OVERRIDE'])($url);
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => NHADEP_HTTP_TIMEOUT,
            CURLOPT_TIMEOUT        => NHADEP_HTTP_TIMEOUT,
            CURLOPT_USERAGENT      => 'nhadep-static-generator/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['status' => 0, 'body' => false, 'err' => $err !== '' ? $err : 'curl transport failure'];
        }
        return ['status' => $code, 'body' => (string) $raw, 'err' => $err];
    }

    // Fallback without curl. ignore_errors=true keeps the body on 4xx/5xx;
    // the status is then read from $http_response_header (last block wins).
    $ctx = stream_context_create([
        'http' => [
            'method'           => 'GET',
            'timeout'          => NHADEP_HTTP_TIMEOUT,
            'header'           => "User-Agent: nhadep-static-generator/1.0\r\n",
            'follow_location'  => 1,
            'max_redirects'    => 3,
            'ignore_errors'    => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw    = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $row) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $row, $m)) {
                $status = (int) $m[1];
            }
        }
    }
    if ($raw === false) {
        return ['status' => $status, 'body' => false, 'err' => 'file_get_contents failed'];
    }
    return ['status' => $status, 'body' => (string) $raw, 'err' => ''];
}

/**
 * Fetch and classify one REST API page.
 * Returns ['state' => 'ok'|'end'|'error', 'posts' => array, 'status' => int, 'err' => string].
 *  - 'ok'    : HTTP 200 and a non-empty decoded array
 *  - 'end'   : HTTP 200 + empty array, or HTTP 400 + rest_post_invalid_page_number
 *  - 'error' : anything else (retryable), including a failed JSON decode
 */
function nhadep_fetch_page(string $url): array
{
    $res    = nhadep_http_get($url);
    $status = (int) ($res['status'] ?? 0);
    $body   = $res['body'] ?? false;
    $err    = (string) ($res['err'] ?? '');

    if ($status === 400 && is_string($body) && str_contains($body, 'rest_post_invalid_page_number')) {
        return ['state' => 'end', 'posts' => [], 'status' => $status, 'err' => ''];
    }

    if ($status === 200) {
        $data = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($data)) {
            return ['state' => 'error', 'posts' => [], 'status' => $status, 'err' => $err !== '' ? $err : 'JSON decode failed'];
        }
        if ($data === []) {
            return ['state' => 'end', 'posts' => [], 'status' => $status, 'err' => ''];
        }
        return ['state' => 'ok', 'posts' => $data, 'status' => $status, 'err' => ''];
    }

    return ['state' => 'error', 'posts' => [], 'status' => $status, 'err' => $err];
}

/**
 * Fetch one page with bounded retry on retryable ('error') results.
 * Maximum 3 attempts total; sleeps 1s then 2s between attempts unless
 * $GLOBALS['NHADEP_RETRY_NO_SLEEP'] is truthy (tests).
 */
function nhadep_fetch_page_retry(string $url): array
{
    $delays   = [1, 2];
    $attempts = count($delays) + 1;
    $res      = ['state' => 'error', 'posts' => [], 'status' => 0, 'err' => ''];

    for ($i = 0; $i < $attempts; $i++) {
        $res = nhadep_fetch_page($url);
        if ($res['state'] !== 'error') {
            return $res;
        }
        if ($i < $attempts - 1) {
            $n = $i + 1;
            nhadep_log(
                "Lỗi tải trang (lần {$n}/{$attempts}, HTTP {$res['status']}"
                . ($res['err'] !== '' ? ", {$res['err']}" : '') . "), thử lại sau {$delays[$i]}s: {$url}",
                'WARN'
            );
            if (empty($GLOBALS['NHADEP_RETRY_NO_SLEEP'])) {
                sleep($delays[$i]);
            }
        }
    }
    return $res;
}

/**
 * Return the HTTP status code for $url (GET, small response), or 0 on failure.
 * Tests may set $GLOBALS['NHADEP_STATUS_OVERRIDE'] = fn(string $url): int;
 */
function nhadep_http_status(string $url): int
{
    if (isset($GLOBALS['NHADEP_STATUS_OVERRIDE']) && is_callable($GLOBALS['NHADEP_STATUS_OVERRIDE'])) {
        return (int) (($GLOBALS['NHADEP_STATUS_OVERRIDE'])($url));
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => NHADEP_HTTP_TIMEOUT,
            CURLOPT_TIMEOUT        => NHADEP_HTTP_TIMEOUT,
            CURLOPT_USERAGENT      => 'nhadep-static-generator/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $code;
    }

    $ctx  = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => NHADEP_HTTP_TIMEOUT,
        'header' => "User-Agent: nhadep-static-generator/1.0\r\n",
    ]]);
    $h    = @get_headers($url, false, $ctx);
    if (!is_array($h) || $h === []) {
        return 0;
    }
    $line = '';
    foreach ($h as $row) {                 // last header block wins after redirects
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $row, $m)) {
            $line = $m[1];
        }
    }
    return $line !== '' ? (int) $line : 0;
}

/* -------------------------------------------------------------------------
 * Logging helpers
 * ---------------------------------------------------------------------- */

function nhadep_verbose(): bool
{
    return !empty($GLOBALS['NHADEP_VERBOSE']);
}

/** @param 'INFO'|'WARN'|'ERROR' $level */
function nhadep_log(string $msg, string $level = 'INFO'): void
{
    if ($level === 'DEBUG' && !nhadep_verbose()) {
        return;
    }
    $line = '[' . gmdate('Y-m-d\TH:i:s\Z') . '] [' . $level . '] ' . $msg;
    echo $line, PHP_EOL;
    if ($level === 'ERROR') {
        fwrite(STDERR, $line . PHP_EOL);
    }
}

/* -------------------------------------------------------------------------
 * Sanitization / safety helpers (hard requirements)
 * ---------------------------------------------------------------------- */

/**
 * Sanitize any remote-derived text:
 * strip_tags -> html_entity_decode -> htmlspecialchars(ENT_QUOTES, 'UTF-8').
 */
function nhadep_san(string $raw): string
{
    $s = strip_tags((string) $raw);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Only allow URLs that start with https://noithatdatky.com/.
 * Returns the RAW (unescaped) URL, or '' when rejected. Emit points must
 * escape it exactly once via nhadep_safe_url() so a URL containing '&' is
 * never double-escaped.
 */
function nhadep_raw_url(?string $url): string
{
    if (!is_string($url) || $url === '') {
        return '';
    }
    $url = trim($url);
    if (!str_starts_with($url, NHADEP_SOURCE_ORIGIN)) {
        return '';
    }
    // Reject anything that could break out of an attribute / scheme tricks.
    if (preg_match('/[\s"\'<>\\\\]/', $url) || str_contains(strtolower($url), 'javascript:')) {
        return '';
    }
    return $url;
}

/**
 * Allowlisted URL escaped once for safe HTML embedding, or '' when rejected.
 */
function nhadep_safe_url(?string $url): string
{
    $raw = nhadep_raw_url($url);
    return $raw === '' ? '' : htmlspecialchars($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/* -------------------------------------------------------------------------
 * Date helpers
 * ---------------------------------------------------------------------- */

function nhadep_tz(): DateTimeZone
{
    return new DateTimeZone(NHADEP_TZ);
}

/**
 * Convert a WP date_gmt string ("YYYY-MM-DDTHH:MM:SS") to the local VN date key.
 */
function nhadep_day_key(string $date_gmt): ?string
{
    try {
        $dt = new DateTimeImmutable($date_gmt, new DateTimeZone('UTC'));
    } catch (Exception) {
        return null;
    }
    return $dt->setTimezone(nhadep_tz())->format('Y-m-d');
}

/** Slug of a post, derived from its source URL path (e.g. noithatdatky.com/<slug>/). */
function nhadep_post_slug(array $post): string
{
    $link = nhadep_safe_url($post['link']);
    if ($link === '') {
        return '';
    }
    $path = (string) parse_url($link, PHP_URL_PATH);
    $path = rtrim($path, '/');
    $seg  = basename($path);
    $seg  = preg_replace('/[^a-z0-9-]/u', '', strtolower($seg));
    return trim($seg, '-');
}

/** Day's landing-page slug: topic slug of its primary (newest) post, fallback to dayKey. */
function nhadep_day_slug(array $dayPosts, string $dayKey): string
{
    if (!empty($dayPosts)) {
        $s = nhadep_post_slug($dayPosts[0]);
        if ($s !== '') {
            return $s;
        }
    }
    return $dayKey;
}

/**
 * Resolve a STABLE landing slug per day (dayKey => slug), oldest-first.
 *
 * Stored slugs are authoritative: a day keeps the slug it was first
 * generated with, even when a newer post would change the natural choice
 * (topic slug of the newest post). Fresh days derive theirs via
 * nhadep_day_slug() and get a -2/-3/... suffix when clashing with any
 * reserved slug — stored slots are reserved even for days absent from this
 * run, so a slug is never reused by a different day.
 *
 * @param array $groups      dayKey => posts (newest-first order, any order works)
 * @param array $storedSlugs dayKey => slug persisted in .state.json
 */
function nhadep_resolve_day_slugs(array $groups, array $storedSlugs): array
{
    // Self-defensive: ignore malformed stored values wherever they come
    // from (read_state() sanitizes too, but callers may pass raw arrays).
    $isValid = static fn ($s): bool => is_string($s)
        && preg_match('/^[a-z0-9][a-z0-9-]*$/', $s) === 1;

    $used = [];
    foreach ($storedSlugs as $s) {
        if ($isValid($s)) {
            $used[$s] = true;
        }
    }

    $slugByKey = [];
    foreach (array_reverse(array_keys($groups)) as $key) { // oldest-first: stable suffixes
        if ($isValid($storedSlugs[$key] ?? null)) {
            $slugByKey[$key] = $storedSlugs[$key];
            continue;
        }
        $base = nhadep_day_slug($groups[$key], $key);
        $s    = $base;
        $i    = 2;
        while (isset($used[$s])) {
            $s = $base . '-' . $i++;
        }
        $used[$s]        = true;
        $slugByKey[$key] = $s;
    }
    return $slugByKey;
}

function nhadep_day_label(string $dayKey): string
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $dayKey, nhadep_tz());
    if (!$dt instanceof DateTimeImmutable) {
        return $dayKey;
    }
    $days = [1 => 'Thứ Hai', 2 => 'Thứ Ba', 3 => 'Thứ Tư', 4 => 'Thứ Năm', 5 => 'Thứ Sáu', 6 => 'Thứ Bảy', 7 => 'Chủ Nhật'];
    $months = [1 => '01', 2 => '02', 3 => '03', 4 => '04', 5 => '05', 6 => '06', 7 => '07', 8 => '08', 9 => '09', 10 => '10', 11 => '11', 12 => '12'];
    return $days[(int) $dt->format('N')] . ', ngày ' . $dt->format('j') . '/' . $months[(int) $dt->format('n')] . '/' . $dt->format('Y');
}

/** Format a WP local "date" string (site tz already applied by WP) for display. */
function nhadep_time_label(string $wpLocalDate): string
{
    try {
        // WP "date" is already site-local (Asia/Ho_Chi_Minh). Anchor it to the
        // site timezone instead of the server default, otherwise displayed
        // times shift by the server-default-vs-VN difference (e.g. +7h on UTC
        // servers — the Hostinger cron box). Explicit offsets in the string
        // still win, as DateTimeImmutable honors them over the constructor tz.
        $dt = new DateTimeImmutable($wpLocalDate, nhadep_tz());
    } catch (Exception) {
        return nhadep_san($wpLocalDate);
    }
    return $dt->format('H:i');
}

/* -------------------------------------------------------------------------
 * Fetching + grouping
 * ---------------------------------------------------------------------- */

/**
 * Fetch all posts from the REST API (paginated), up to $maxPosts (0 = all).
 * Returns list of normalized post arrays:
 *   id, link, title, excerpt, thumb, thumb_w, thumb_h, date_gmt, date_local
 */
function nhadep_fetch_all_posts(int $maxPosts, bool $verbose): array
{
    $posts   = [];
    $page    = 1;

    while (true) {
        if ($page > NHADEP_MAX_PAGES) {
            throw new RuntimeException('Vượt giới hạn phân trang ' . NHADEP_MAX_PAGES . ' trang mà chưa gặp kết thúc.');
        }

        $sep  = str_contains(NHADEP_API_BASE, '?') ? '&' : '?';
        $url  = NHADEP_API_BASE . $sep . http_build_query([
            'per_page' => NHADEP_PER_PAGE,
            'page'     => $page,
            '_embed'   => '',
            'orderby'  => 'date',
            'order'    => 'desc',
        ]);
        if ($verbose) {
            nhadep_log("Đang tải trang {$page}: {$url}", 'DEBUG');
        }

        $res = nhadep_fetch_page_retry($url);

        if ($page === 1 && $res['state'] !== 'ok') {
            throw new RuntimeException(
                "Không tải được dữ liệu từ REST API (trang 1, HTTP {$res['status']}"
                . ($res['err'] !== '' ? ", {$res['err']}" : '') . "): {$url}"
            );
        }
        if ($res['state'] === 'end') {
            nhadep_log("Kết thúc phân trang ở trang {$page}.", 'DEBUG');
            break;
        }
        if ($res['state'] === 'error') {
            throw new RuntimeException(
                "Lỗi tải trang {$page} sau retry (HTTP {$res['status']}"
                . ($res['err'] !== '' ? ", {$res['err']}" : '') . "): {$url}"
            );
        }

        foreach ($res['posts'] as $p) {
            if (!is_array($p)) {
                continue;
            }
            $id       = isset($p['id']) ? (int) $p['id'] : 0;
            $dateGmt  = isset($p['date_gmt']) && is_string($p['date_gmt']) ? $p['date_gmt'] : '';
            if ($id <= 0 || $dateGmt === '') {
                continue;
            }
            $link     = is_string($p['link'] ?? null) ? $p['link'] : '';
            $title    = $p['title']['rendered'] ?? '';
            $excerpt  = $p['excerpt']['rendered'] ?? '';

            // Featured image: prefer medium size, fall back to thumbnail/source_url.
            // Keep intrinsic width/height of the chosen size so cards can emit
            // real <img width/height> attributes (NOT part of nhadep_day_hash).
            $thumb = '';
            $tw    = 0;
            $th    = 0;
            // Large variant for hero backgrounds + og:image. Ladder:
            // large -> full -> medium_large -> medium (safe fallback).
            // NOT part of nhadep_day_hash; cards keep the medium $thumb.
            $thumbLarge = '';
            $tlw        = 0;
            $tlh        = 0;
            if (isset($p['_embedded']['wp:featuredmedia'][0]) && is_array($p['_embedded']['wp:featuredmedia'][0])) {
                $fm   = $p['_embedded']['wp:featuredmedia'][0];
                $size = $fm['media_details']['sizes']['medium']
                    ?? $fm['media_details']['sizes']['thumbnail']
                    ?? null;
                if (is_array($size)) {
                    $thumb = is_string($size['source_url'] ?? null) ? $size['source_url'] : '';
                    $tw    = (int) ($size['width'] ?? 0);
                    $th    = (int) ($size['height'] ?? 0);
                }
                if ($thumb === '') {
                    $thumb = is_string($fm['source_url'] ?? null) ? $fm['source_url'] : '';
                    $tw    = (int) ($fm['media_details']['width'] ?? 0);
                    $th    = (int) ($fm['media_details']['height'] ?? 0);
                }
                $sizeL = $fm['media_details']['sizes']['large']
                    ?? $fm['media_details']['sizes']['full']
                    ?? $fm['media_details']['sizes']['medium_large']
                    ?? null;
                if (is_array($sizeL)) {
                    $thumbLarge = is_string($sizeL['source_url'] ?? null) ? $sizeL['source_url'] : '';
                    $tlw        = (int) ($sizeL['width'] ?? 0);
                    $tlh        = (int) ($sizeL['height'] ?? 0);
                }
                if ($thumbLarge === '') {
                    $thumbLarge = $thumb;
                    $tlw        = $tw;
                    $tlh        = $th;
                }
            }

            $posts[] = [
                'id'            => $id,
                'link'          => $link,
                'title'         => is_string($title) ? $title : '',
                'excerpt'       => is_string($excerpt) ? $excerpt : '',
                'thumb'         => is_string($thumb) ? $thumb : '',
                'thumb_w'       => $tw,
                'thumb_h'       => $th,
                'thumb_large'   => is_string($thumbLarge) ? $thumbLarge : '',
                'thumb_large_w' => $tlw,
                'thumb_large_h' => $tlh,
                'date_gmt'      => $dateGmt,
                'date_local'    => is_string($p['date'] ?? null) ? $p['date'] : '',
            ];

            if ($maxPosts > 0 && count($posts) >= $maxPosts) {
                break 2;
            }
        }

        $page++;
    }

    if ($posts === []) {
        throw new RuntimeException('REST API không trả về bài viết nào.');
    }
    return $posts;
}

/**
 * Group normalized posts into day buckets keyed Y-m-d (Asia/Ho_Chi_Minh).
 * Days are sorted newest-first; posts inside a day newest-first.
 *
 * @return array<string, array<int, array>> e.g. ['2026-08-22' => [post, ...]]
 */
function nhadep_group_by_day(array $posts): array
{
    $groups = [];
    foreach ($posts as $post) {
        $key = nhadep_day_key($post['date_gmt']);
        if ($key === null) {
            nhadep_log('Bỏ qua bài có date_gmt không hợp lệ: id=' . $post['id'], 'WARN');
            continue;
        }
        $groups[$key][] = $post;
    }
    krsort($groups); // newest day first
    foreach ($groups as $k => $list) {
        usort($list, fn ($a, $b) => strcmp($b['date_gmt'], $a['date_gmt']));
        $groups[$k] = $list;
    }
    return $groups;
}

/** Stable content hash used for idempotency checks. */
function nhadep_day_hash(array $dayPosts): string
{
    $rows = array_map(
        static fn (array $p): array => [
            $p['id'], $p['date_gmt'], $p['title'], $p['excerpt'], $p['thumb'], $p['link'],
        ],
        $dayPosts
    );
    return sha1(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Per-day metadata record used by index/archive/sitemap rendering. */
function nhadep_day_meta(array $dayPosts, string $slug): array
{
    return [
        'count'       => count($dayPosts),
        'slug'        => $slug,
        'url'         => NHADEP_CANONICAL . $slug . '/',
        'first_title' => $dayPosts[0]['title'] ?? '',
        'thumb'       => $dayPosts[0]['thumb'] ?? '',
        'thumb_large' => $dayPosts[0]['thumb_large'] ?? '',
        'thumb_large_w' => (int) ($dayPosts[0]['thumb_large_w'] ?? 0),
        'thumb_large_h' => (int) ($dayPosts[0]['thumb_large_h'] ?? 0),
        'posts'       => array_map(static fn (array $p): array => [
            'title' => $p['title'], 'link' => $p['link'],
        ], $dayPosts),
    ];
}

/* -------------------------------------------------------------------------
 * Filesystem (containment-enforced writes)
 * ---------------------------------------------------------------------- */

/**
 * Resolve and create the output root; returns its absolute realpath.
 */
function nhadep_resolve_out_dir(string $outArg): string
{
    $dir = rtrim($outArg, '/\\');
    if ($dir === '') {
        throw new InvalidArgumentException('--out không được để trống.');
    }
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Không tạo được thư mục out: {$dir}");
    }
    $real = realpath($dir);
    if ($real === false) {
        throw new RuntimeException("Không xác thực được đường dẫn out: {$dir}");
    }
    return $real;
}

/**
 * Write $content into $outRoot/$relPath, refusing any path that escapes $outRoot.
 */
function nhadep_write_file(string $outRoot, string $relPath, string $content): void
{
    if (str_contains($relPath, "\0")) {
        throw new RuntimeException('Đường dẫn chứa ký tự null.');
    }
    $target = $outRoot . DIRECTORY_SEPARATOR . $relPath;
    $parent = dirname($target);

    if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
        throw new RuntimeException("Không tạo được thư mục: {$parent}");
    }
    $realParent = realpath($parent);
    if ($realParent === false || !str_starts_with($realParent, $outRoot . DIRECTORY_SEPARATOR)
        && $realParent !== $outRoot) {
        throw new RuntimeException("Đường dẫn ghi nằm ngoài thư mục out: {$relPath}");
    }
    $tmp   = $realParent . DIRECTORY_SEPARATOR . '.' . basename($target) . '.tmp-' . getmypid();
    if (file_put_contents($tmp, $content) === false) {
        throw new RuntimeException("Không ghi được file tạm: {$tmp}");
    }
    if (!rename($tmp, $realParent . DIRECTORY_SEPARATOR . basename($target))) {
        @unlink($tmp);
        throw new RuntimeException("Không hoàn tất ghi file: {$target}");
    }
}

function nhadep_read_state(string $outRoot): array
{
    $state = ['days' => [], 'slugs' => []];
    $f = $outRoot . '/.state.json';
    if (!is_file($f)) {
        return $state;
    }
    $decoded = json_decode((string) file_get_contents($f), true);
    if (!is_array($decoded)) {
        return $state;
    }
    if (isset($decoded['days']) && is_array($decoded['days'])) {
        $state['days'] = $decoded['days'];
    }
    if (isset($decoded['slugs']) && is_array($decoded['slugs'])) {
        foreach ($decoded['slugs'] as $key => $slug) {
            // Only keep well-formed dayKey => slug pairs; anything else (older
            // shape, hand edits, corruption) is ignored so a bad value can
            // never pin a day to a broken URL.
            if (is_string($key) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)
                && is_string($slug) && preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
                $state['slugs'][$key] = $slug;
            }
        }
    }
    return $state;
}

function nhadep_write_state(string $outRoot, array $state): void
{
    nhadep_write_file($outRoot, '.state.json', json_encode(
        $state,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . "\n");
}

/* -------------------------------------------------------------------------
 * Content resolution
 * ---------------------------------------------------------------------- */

/** Prefer /lien-he/, fall back to homepage when it does not answer 200. */
function nhadep_contact_url(bool $verbose): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }
    $status = nhadep_http_status(NHADEP_CONTACT_URL);
    if ($status >= 200 && $status < 400) {
        $resolved = NHADEP_CONTACT_URL;
        nhadep_log("Trang liên hệ hợp lệ (HTTP {$status}), dùng {$resolved}");
    } else {
        $resolved = NHADEP_SOURCE_ORIGIN;
        nhadep_log("Trang liên hệ trả về HTTP {$status}, dùng trang chủ thay thế.", 'WARN');
    }
    if ($verbose) {
        nhadep_log("contact URL = {$resolved}", 'DEBUG');
    }
    return $resolved;
}

/* -------------------------------------------------------------------------
 * Self-hosted webfonts (NHADEP-F1-FONTS)
 *
 * The site previously loaded Cormorant Garamond + Be Vietnam Pro from the
 * Google Fonts CDN (render-blocking CSS + 2 extra DNS/TLS hops). The exact
 * same faces (vietnamese + latin subsets only — the site is 100% Vietnamese
 * text + ASCII; missing glyphs fall back to the system fonts already in the
 * CSS stacks) are embedded below as base64 woff2 and written to
 * <out>/assets/fonts/ on every run; @font-face rules are prepended to the
 * inline <style>. Family names and unicode-range values are verbatim from
 * Google's CSS2 response, so the CSS variables (--font-sans/--font-display)
 * and their fallbacks are unchanged.
 * ---------------------------------------------------------------------- */

/**
 * Embedded webfont payloads: filename => [b64, family, style, weight, range].
 * Base64 woff2 (vietnamese + latin subsets of the two brand faces) keeps
 * the generator a single self-contained file; decoded at run time by
 * nhadep_write_font_assets(). Filenames: <family-slug>-<subset>-<weight>[-italic].woff2
 *
 * @return array<string, array{b64:string, family:string, style:string, weight:string, range:string}>
 */
function nhadep_font_assets(): array
{
    return [
        'be-vietnam-pro-vietnamese-300.woff2' => [
            'b64' => 'd09GMgABAAAAAC/cABEAAAAApPQAAC98AAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGkAbj04cKAZgAIMECGAJnAwRCAqCgTCB'
                . '7GsLgWoAATYCJAODRAQgBYQwB4h6DIE9G52YB8a429jtAFL9+M4BIxF2arMKbVRUa+YI/v9PSDrGcFBtgGrXG2gUSYbLVEgI'
                . 'SFmIJFpl9kqGETRYYQRiTmH2hgFeo6WlJc3LhvB103B/67nb3fzTLextwyWSKle6JalrXZq8tujPJWcTi87CX1kQ9B6X33FS'
                . 'xGfWQHhdOk/p23xWT9wOV0svHteOKXt4UHbM2LKyze2O7jsD20b+JCcvfB9jtLe7/1wRTXgiEc2baiLdUBIlmyZN0RpDKMS7'
                . 'Pzy/zd770DJULKJsdCaIAQa0DQZKpYpR6KpcRHi7RbKK0LEqLyL5KTf7NzUKCUkQm4koxAxKCPJK1637b67X84r+21doTEuO'
                . 'ikAjfEBSou/MKlwJkrWc3QP1gAVWBsgjxoOv0DWiqaslY0jpF6Zo3+vSfbozgmSgP/P9VOivO1eAa8QASnfx9ElTQdGUTtFd'
                . 'FJyzGaASMxaOhD2g8IXnUZfyMLWswld/geQCBVzSUcvOlaSilHNumDPM3qYnB/v/j/NHh7iQHhh9zpIQ5bv97xoh51woxoOI'
                . 'GF6MwuAp97NXyFLgg+pMemDPGGJ3bv/Saf+AFuwjy5tYp/g+ABYVFs2vlC2M1U2m96iJuiECC3SDAPX216XX9ff/f24TfXfL'
                . 'O6RGCiIWykTrf6FgoeAZrxCIzXmQrnSAPj765erX6/RxZvIHh8R5CotG4RHq2rWhLAkRiL6Yt9N0+T+bVmn9brXAS9aix0vk'
                . 'iRaCyJZ9iNkFGVG7GqTukmTLLT/LksdrHmB7eEnVXd0CazQ+WV4PaJl8RAN09gLAhcA5IATpXRBeOOGFl4QXxBjE2cHbb6v7'
                . 'mwkShhbPxiVMxSc8z987bdwT9YgSCbCAExlrUp9ai8YWGJuweApDNpZZBjCnTaVADdp9rh9L2/tgW2eRzJKhhJLgGsvhpr8s'
                . 'y2FHZaodJFAoQbyT71376feIqDcx5TcBNhWsFBsLe21FBEF28ezshkCBZCCFFlAHHxQIAVgEGIcY/b3A8ZAPfGKjeLSQjeN3'
                . 'ngD5+s7fvwIZGoAOku80w64e/PkrxAXZA8ybtisGkQnOCicPZq5qAlv4FxFWGIh41PT81xcqRoKVJgkXXSQyaFwhAPL7I7TF'
                . '76DuggAgVxEN9BwZwgADE4CBHmCgAcBADcAAQH2lpPoE4GdiQvTtQWiQ7T8CAvWUOKAfjuDB8np1gpRTIGkuzdVQ2iovhYbi'
                . 'VH7xVS0NkARcjd6LROoIGWIM+G0jqH/ze37M1xnJG4sleQaR4THcXft6LuZ0jma/XntXtmQwq7OUsI7MhxjDzPlTMi69WiR/'
                . 'e5o0O8C47fpUpzSyFCY3WYsIf2oSNDaAqlEya5NQWBRAvvwr4Pvjv8yP/iAyP6K9AgTgoXa7F7mqI+chxnBy7cPe6x3e5PUW'
                . 'S7ISIsNimLv2DE/yGHe7Va/tttV617qSsI6oIMYg2bbIOc4wz3FmOhpaGGtE3D00/F9/6md9q8/1Ti80rPu6uWiXoZ2Fdjyz'
                . '9sG0y59x2qbRxj5g0BrECE7LaJ8BG4BC4syEqhA2iR9JUZtCp8AHqK4MoS/IAEhJ8HE6z9qIODs+zi/xW6IShMTKS3gJL+H7'
                . 'eDEv9pcmxy8N9NdnIpFJ/7BX8rJqmSMM05rdPw0gs2PXpk1gM/rGiNyUuML+EAZ40McLTK2Nm15uACwgnFf7JYH+ukzwD3kF'
                . 'kUZ3iDR1ZKFCtprhJNHqDm7I7z7Awcw8l7z5tX7i9+Yw8Un/+ML4S/Il8VH9O/PyYB/A5xHSKg/uKowE4jWT9Uz8Jr6JX8/f'
                . '59fz61ObK9QOXvv3VYO+waIv0+PRtQGK19/Xmp7DPiA5pzAQGQuJj0g9gxrrYOjg+qqU/PuHYuKlvJQMgu8hLda8k+8nv9rp'
                . 'TpJo9qtEzlrVkarMr6r+qkz2AciaJHk3uFMCMkPzYKEMDtBqRwCHtUNM9szlfohNbIdUb1JmSZ4OFFKo8GvgeQSZ7X/rAMND'
                . '4lvskOCBBnSrfMiIQP/HxVA/699NB/S3Awf7HZjj/5cg9xX0g1jxUZJzjvRles4Qtw+cumjvn0Tgrap/fVBziT7SB9MYr9/2'
                . 'mZpQWMyRmySSCtcBqp9D+FTd83jrKvYqYwM6uP6lrYMggkKhhWNAMPGgpMiGxSeEl6sCVKkajoYJZOaG4dGCABEOCoUSJgwi'
                . 'HBUGDQOaE5CZUuGMloUkWzYcfyeBXJ01isILYHndtizwrFxCuEHwHnG0QLp9gFBAkBxUjfepfliSJIECQP1kB9x2pUJpYADf'
                . 'WQ/gjHrLuoAfxVz0bwcuFEwB/v3mjwouAtfCG/7LjT9QIxEAUB8A7nnk0Qh2AdHmDYFrPzq6z7gbFxraqEcntYn/IQqGK0Ve'
                . 'bdbBQBQaRUDFb/0r7Ik4jbwXBIaJpxZt0oEAVKPhwW/6Kt8o/9/3nZZC7/p1vcN8kB7gzyVA2NEqJjauOAn6yM7zvYfIFUaO'
                . 'oVo2t3gNRrN+0/i/LDKpUDIEhUIUIykxigRREbQ8WAVwRPDEMPJFKxOjHFWFcAoRVCKpRSlFoURXhaZSrHpxdJg02Gpw1OKq'
                . 'w6KVoFESg2RGPCaJ9FKYpbJIY5PBIZNTFpd0dgJN+DxyNBNqIbWzBcnx2NV4ZEprF9lY/x6FmLW3VxQKClc90cXOdYH+X1TB'
                . '1vYkapPrDijgJYyiyPtjjD+8xzz9DAyXqH5Mc+mefkB4/m/FILoz3TbWgo9AOuxhUPIOkmw9dwiqVeuQlHZPzp5a8AMCkpR8'
                . 'EbMDX0otIfR1V3BGhAa5Rd3v9iLLlDy51wvWuHzvzhePLoi3nPo+YDOZHSm4DzuxD1vmGKnlZCKkIOIsCisdH66RVtg3qPJn'
                . 'X7UxjWYjzEmwSI/TX2FoedO9CDy4/6YdXG1MNYumH6MU9OaWKitaMcd19a4F2i8jc+m2jYfsWuroWYpu13BjNE6kGEemQThO'
                . 'zcBjAFdrzcYhw1x6ePoqH3sxI271WKMTY5sjTFVJBMVnkbDUR4emjATluRUsagKW6LFGb6yNsHaYvIuC0oJi6XjphIcdNsPA'
                . 'isiz16MZurIPX9Jl/95ZKt0+R5VbITUmT2PQaKNw8BREM/+PGtLOHBZi050eWb2RdVlb9CqgniEjOnPYTWCpjqSNEvU6bs2O'
                . 'FTrC1MdBHbsaaSj2ZGdvG4dG/radQMhBT6iwk+32mRQI5UlGXaGRNuttWKrMUrJLHGRuBaRrHroqxNVPjOI0wHKB3Hhgz9ie'
                . 'axStkyZYozbetg4Pi8hO+NIMHV7CWgSP3vXU7IiDyYhXC4/gFRHzMZCy8ymXY4VuV2jwKUn3IeCLXh1+vv8aivoxjFdxvequ'
                . 'I8D3W+H9l1djxh58oG6QBdODbyG/mR42Hl+KQAj3xbMA75qwUEF68xzncTjKfbcN7nu9ldVxVRc2NCSatXRYNuLRBC+auyZV'
                . 'Y6nljAWY0SEU2Otptn/GQlwoZCdfA/7oZYQ7elwRWXtJR9H5RUwtnFjw3hcCBe8ybEpwMPE/LV2JjjAL8juOiOWIopxAMU6i'
                . 'OKdQgtMoyRmU4iwozVlRhrN1N6jjsF1M/f5rUKng16dG3LObnTxh18/6LU/Z9eu9luUCS0p3ADYHWwwqTchBgDwEKECAIgQo'
                . 'QYAyBKhAgCoEqEGAOoT+NqWeuKizfCJay0B9MqhtiC+93brzuqST2lBVj+tRZMIwqn2a1/Qdnp2omyKgvrzpK+7+k8bbEb2q'
                . 'aMtH4X5MlgzyZIPaP6Q8SWDOiAsY5cR3EqaYrM3TotgHABy/0LUytDzl98qYUsskjQTQ9i3kmLA6Uzumj/RCzcbWnLEIFNek'
                . 'WpMgPjXz3nbbs6gWZAnbZvZdM/p+apafvM3LzwkKIndMeSt133tx4giH7ux07ShSd9MlwCIuFe8QJnqrpPBhbpn24CHsqbAa'
                . 'zJ4qVxejn3EX2iVdwbJVx2dJo7etnmY9+dNpG0qpVbRye/YqZRPp6WRq25lVprZX6jivGcWu8SeXYZe3cZMsdIWmCT1V5bq1'
                . 'ZzR7ElUGw7kmjTKjpvkcio5lSTTptusSCa3XnnhWBnZaaAzsHI9mdE2RBiUXXOyc7jlUUqs1wa0u8UVUx5jxMOftdxi5mpZN'
                . 'r4yaHqd+dHPGo6Ae9PRFx3Toaq0TB9Q8Lqao9x5RaMw0BqljVbo2eyfV8PAVp6YZ/YiD2fIGH5N5crzsCVmkLnvhViuzmz6y'
                . 'HEYV3Ul0aqBo03A5AomM85RzgI8rq9RS6Zqf4Wcs9rb7R52e2vvEpJlxang+u2ata8a2TbuN8aLzbmzdKY52EYpXu9UlTorK'
                . 'BVyG87d/W48nPXSi7x1XTbmad80Cc2NMpPeGzD7R7e5QjFQqGXeFpmhx7z1O7MOt97vPHjhdeWDTw0HwyKbHCzjjiU1PB8Ez'
                . '2/859wCo8CKml5PgVUyvb8B4E9PbSfAutt/75sQfSujjIPhk0+cFnPPFpq+D4Jutv8fHcX6U7J8o8CvWv1HgT6z/osC/2Be/'
                . 'CXdkXcmeas9Bc79mWHue/UdgW+bBBXegoqUAfiUArTvIhh02rg1sXRuyg3SCvXJCBwHoKEA73Z4tnF1buLi2cHVtgSpANwHo'
                . 'LkB7wLODp2sHL9cO3q4dfBSgrwD0E9D/52f9G+4Df4VlfBYQlPKylWPEGgz74E7xfEQlXBz3ob2ddEDwP8jjomrNbfwD0CJg'
                . '7hNWH8jLD2oaOPYIAENBmbaWEExSUc92yIJosHuj9VhkRsg7TMySTMSBos2DzKXOZUQnL0w3E5iCmvOgTt8ajEaCaNFJSmpA'
                . 'oRcWDp1qRPtmPyT0bscuGgvRRovuvIW1eOlazRYXcal33g40i041IuGF6WYCU9DshwTtl4nVeepUI5LNviYRxV6IKP3piVK6'
                . 'DA0cNwpVbaXdbKMTXad7HCSg756azzWWVHMqbbcVUR3+/U8qbp9nOxnFuuMDfOa89y619nD5dw3VJte30d2m2Lsh9svFHvBu'
                . 'Kd8V1VCtjK7Xfnax/qre4PG7wk9ir0RsKIQfgfxs+dFLpzh9mkeDCbr1V74lTx2P4E9l5JvelaBxxmomdVRzKkbZwDq4yIDI'
                . 'lko9qtrYD90cBSAYRrcUxzyVZKiJ0JOmWsqH61KbFPDTarkIaORSZViku5LDXY8SvRapBIFOkZOFsLK2s6cfsrWnUQgCYi2G'
                . 'fZcmviqEVil0fdY8wcw9Flp+E4EINNVy8TRtPOI1rkUriahVyr8WKZKVZ/RmQNqHYDTMKTBb7rPPijc0mN0lTioNhRzVUEYl'
                . 'F3PuCUkllEEw82ryNfPJQZW2CaHxnHWnwxT0yCoULxSSkO7ESiUqq95WCKaoshbJh0TaJDmIeSM7imkZY0dk1J6dn9m6mWdY'
                . '6MN31RmabUyUg6kbWIujupX1QWUt6w7MXd33O63nJ5dKIaCRTJuLwxQ2kWpoRBAq4fXJosF+Z8Eyq022nGImq7C2U0mxzmrG'
                . 'FiOZlcFaYzNLZ1pjKopONGITy4jNI3acDBOsImbaoo7q5rwV2BKxKBJYulNi3hgRjg8CAIIwLcg46xRH51p6Yx8A2IxP7uTQ'
                . 'yMGDD4eNo7aIF9HGl21pzJKbHYRhvHESkPXGQVZshwUYD1MUMx1sZjiCdJZtvl+vbPPwik69eCdtYG6aDcxUKNllahg5yYQ2'
                . 'JyBshk/Gp33L8s9+yNhn1IX1IrSx8zmvIJ+hshfJDMFYC50Cs5sz9o+tnqX0oRUX0qti5dm79S6zBsmj+1jdSxeRiHxNco2d'
                . 'r66PY6LTGzvBjljBNuLZEVjtnXlGW7B1B6TQ9pp8KrCeNy/LPDysO+4o47YymmjFxaibFYu75oeTuI69da7bKsfsu8lf8ICh'
                . '+9zdW7xXD0eBdRnOJr6Hj3AFbcTGkGKr2f6E0aRsXWBuJR370Yb7WCs7r2j3akRH/pmVRpXnSXV+DWPow5OQHTU18vNyid29'
                . '/Oh4ftp4bEAEREAd2hgb28hRA3eKn/8+L1rAF/p2VE6VP5AAnV8zq5S7eA2yHr+T+mNMTJBKDcZQg6si/oFzj0bnj/skbd7v'
                . 'n/ugAKg5QAcXgNTA8X8umSMHrnMJ9pWBAIesp7FhSG6LupnZk0AvXsHl0FcA/rl0nC74QEvc0BdKeWML2V/wOfScDW5yeI+d'
                . 'w34qsB0wbS/BLda3X1rv1+xN7lsibz9MZY1T6yT7sr++bB3pSnb/EhniKu1GZoq+2Zfugf1Ov6beb54k+sfeyjqyjUtwbLv2'
                . 'lE3WUzmAlOsxgixHPKN4RixH3oux8gm6/Et6wiAR3/3nAg4jcX0/OazHEXI+j//jOBNclPEFjzajvyVyRfr+XkSAs9koQfq8'
                . '97nzNR6VR/OFfqC4wTt6QKnUohWjvelx1VuIa5VNfIUuti0/n0QSaerKtcQteRyX7RHhDkauKseiSpWllMlKI/EI6eZfucMP'
                . 'Adq7mJgfaDGhMfOhnzJ9hDOJVexhbLU2E/9FcJ0qoBkR0llFQc+oc4U+yoB9UVi6+LVWUQWF9DBvZSS4B5PDepeKjxwolDXc'
                . 'OFI1khSgqpPd6K/A+mb1rLz2OdB8wjb0oOWd6PiNuR14SfE7tMQ9fWRK5sT53DQib2q8eJ/4tG9bwi8n69OYfckmbxWDUjbO'
                . 'lOjb0+aoVVq0dWaWNDYOlMxPn6NSp8dbH5A0qGsmapUL9I3K+YjV1EaJ+Y16EnHJujC5GPB5EzFWG2nyd/plf9RJp1+j81sm'
                . 'Sem3xgr229D3BfsF2WGTL+9VtfJJ1pWfaQl7feANCPzC9R4pwNp/P54aeH7exF/9wf56gqWvWCs1Ii+mLhZ4RCptlDl6/mjh'
                . '8cszs7PUWRnbL8vVTQkTSrD62hlX8srJxYuXf4u5oWfoNVYbmfT952OO5EXoxYvEHypIzqNGVJCqlU98NPZQO2Yz0xuTuzVP'
                . '0hX7hnWksK1evJhfxuwuBLGNYBivLJ5Y+6LqhdHR7XJpzeiFajtid6zxrgUrNUehjlzeQB7aqOMV7UyRkckvzU7DZY/skBmn'
                . '1cXOBK98y7Xe3cg5NUbM2R4l9/4Et0ohKhwbtdVkLxJvD6nCxwy5/y4k1qdiVzCo07euXswQE+1rnE4iEZ7qXsxT16rHPyXF'
                . 'drz+xoiI5DPiJugh3ubLyJvxscfAg9bxm6Dr2CLXGkOLSKf1Yblciri1HE5AgN+aPn+BHZbY0gf/vh0CEUhFm+LjTehUiEDa'
                . '7b8HxfI8dcLmZVhbOaXchl2+SZ2QV2jIEFczHQIB0yGuNmSoJOKqokRbTk6irapILKosSopELdpvkmXOfyXq/ZqZpePpBhKp'
                . '2Od27nys8JArOn+UfyVV7489pR+2AiIU2uxeTQHs+AKJ59if+AuX0L3yem84DoYIW9TgMP83pvaGBZnU+LCx5e/MdsBP1xUc'
                . '+9vL/RA0ORIZ7DJ7/da+zfL2n4GypkvbJZV5ekGQXH/pqjljzJPODpmSUtm+U4+K0uTLemXdsCCHGh+d3NHfh+tMSvsHaM9m'
                . 'EL2ZE8lGYg5y3IbZKGtz79IIPOFCzF35lxQtsZZbrUVWC/bY0RLxbBHurZ/56oUE6HbH6dbrmiWWDLGG6eDzmQ77lwpjRoms'
                . 'qUg0zhS9gMLTlQuEaRu/vAfUYOkyWBH7vqAkLbVcHpusbjR7N1r6N8v6N6u03druerCxn9QNj8m/v71ohhvttkzWMiMSujr2'
                . 'Pao5L88mG3yMokmW+9RcoUCML6rE0KfyDaGTdHUHNwUEaiubn0Y6JiHaPX2PO8d4aQl7+smU/+wcTsAGF0OcyYe91aNTx8a8'
                . 'jYp+ExP9fXR7K6KxlApK4ohQWWuTsdrESoxTlXkveseS2EzvwwU7f3uJoEYLvJBL7U3TUmJNl1RxnDlCjkNSFSVKSmyvZau4'
                . 'DmEO12lFWrNYJtaIeVahkETSxATJkLyR3m/Ngg2jhRsrCv0bJXXn+stTEM4DVdMMyajMfOpx1510uU/RFTtOFcQERPvvJ3Lh'
                . '6nFpXTWyLv7LUoKNK2xNNo0gG8QzlZ5TqOW31tSMTL6cP3rL8mj+WBlOyu5wf7uYPMEi3CSOke5nbH5owqV2cd0/SCaP24ld'
                . '5uSkXjh5VLeMaV9Oa6UnDLpDKf/YAvbnsZw38ZngA47PDsSa+6bf8k8waSwek6lCX1wkzxX+yWzIZFRQKhoxHcW/RN4tr77V'
                . 'Jv3OYfG3T3kiN9Y4F/5QQ2Q7XZT+MYvwbrOxpCA6LiThCF1kogcgcA7rO2f86z/9PMH8ToAyT/zvzAlmuXEqDPL0xXmi/b5b'
                . '7yQnU7ctb6qP7t92kFC/HHUZSMGHvaha/On9mOrtx3cc1wyx480+/XcRdtnf+1dlZDmPs8zioD0qG3WFLV7mDAMJlVEi0tmn'
                . '4TqcUVInTrUIBKmWOlFJypy4aZgpnTmdIqWoKqfqqlKJmRI373SanyVLSGDJdqel737ng/sPnMsdyd2U2dJRs6/cpwRj3X99'
                . 'GxxLoFVG++j9y/ijtzZqsognStIbf4F+N5PlL+RSyb6VnvzvwoeWEcBXBERCZwD9u8c3IQ6mg2aAw+9R50TuPzgXjBH+BwH/'
                . 'gwM8wyBscmatDRqFmAfbz7GqUqS8GnU70qmMH7xuEhX87wQJn1mJIAsuBEzDzohs0GqkOvh+GRP6QB9r4//0/qC6Uju7QiOL'
                . 'LNsPPp6SZJqgw2rFn/ZnpJFM6EIhPMcZsw34Qe6D9Kt87D65ypZsmYauqcqw3xFzktFvRlJb7j7v97nq36s8eIGJto01z/9Q'
                . 'AapS71Laxz4xFNTq6cY5HRCG+57o2Jax74UvXSu2Y5O5Jlw2l9M8N2RsbOq3ywC4XIMYfDdpR+2aJcBb3wCBgV8dK7R1bkoN'
                . 'ht1YAGJWjV0o90wuDHLWfCZiA3FSSoMdwlucSeBlfeYSp3nOKKBe5VVeLc8W87II/ZljaDRjGTzDs9n1pMZK+6qWoQ9n1TQO'
                . 'AY+lYEBhvCDrBYHLXjceJuSwOhdqr6u3qm6nzaUM+Qd5+9UevMBzWAKyYEGc8MHl64w3QBlZcbkuIktfXmJkj6aQ6YaDXkco'
                . 'KUzgnqzGcbLTB00MA4lK/xGHcVBuzK8g1rJMwi05e1UfHtfJbAi9TpHNhBvn8UgS26pKlvwquj1edh9sFUR4RYQpKUgYhXe/'
                . 'LkLGZxwqrmeYN+AqidzbyIzeROx1Gd3j0zK3cYoD/zPheqLR6zYEYDLqKj1FEmXt61UtOQUY7B7l50r4RROjqesuIIiW4KwF'
                . 'fAPc46bAn2t9viTAZX+4aTJA3RW2/sbzVyY7ZQSsQToRbttvLa7n9UrqpZ7fg0aKks64byAkRQwkhDcwF7ws69YTbcOgFOB7'
                . 'hqtON5s6tmVSHRcJJRtAxPv3ilXVBP0ZVGBwmC9LJ0/geUsNtEa2xpWNUqtyezNXlXjOeUgZ4UZmwHIvXDrwekIwPa43C84H'
                . 'PbBH48TkDkbkfRdTojZXb2Z8404BZtqN6t73icpWzXobmx54tmp64pDPAF2Jsy3zFgjN9vd2qwBHwsqJHElE/MvS2LonRoXS'
                . 'vlgykUbDQsukBq3jwSjguBnLhkFBUIITZjR34JGgyrPpwjEwnVAGvboIHnBezJgqPm9iwIIhdMIRogcwUp/qiMw8N67BKTWv'
                . 'bPN8dpjVDmjr7xfVEWReB4TeocJc7YQIz/MCcI+5aJh20lFzcfXBEgenzbRWv9cQADruqX21MZZd0qWEE7gvW7CMeKrAs1ih'
                . 'Xi8JcKlWFLWt4huV5blaTIQeeITynNUpnwyfK8IdQXedF7UOpU+8VlUyB4jbrFxepmaERhj43iU14RuVRfd5POpaW4QXiUaV'
                . 'tnpyKpnTeXew93reSFsPrci4bzIjT/jgUhY+qDae1bl7XHb4iMVOFu1XDvgZPFhgIT5BBPPcXPt1NmPiNtytO7N9WFqmIpr3'
                . 'tuh2ENV9DZquXQB6HX5RF9Qo95OBloyf+II5cL4oJtgWKEacUJW5oqwMEWmuxjT3wkGAZKIcPUnqUFpMChLwcrjkZNEh1DqB'
                . 'VJGq3c1nditVZe6GMPcyHWjCg0tp3AWDc/fY95q1Uiaae9zTyMhhxpGWsg1m6gXRnpfOPnAUZ2ol7UeW6u6xG8GlvNAXhQKC'
                . '7EnBCijYB+0NXcNtM29PJfa9NEah3vGcNtCEwPYwmn4qKL5obCoEJVQaI+JZ7zHNPCutUHohjY8PehIkWG+E9f1u7MBx1x5U'
                . 'R9/vNX7d7ed3LdbjYZ/bl/1FQJ6PIUF6zaTvGNavyknmMeCHe2/c92bmBpxa9LccfgDQbfv5HYaBzE4FIE+DRbiwTebLfldq'
                . 'rHV9gqWZx6EFlnZcnm9QSwJc0jPddwJshQoNRb3orYq+z7CDICXvnfsoeaU2HOeRcKdJLebm5ddtu55fWVwJfaM0SxZQdNcw'
                . 'UG5Ywqp3Jvf9DnaQSC6pL1IaYyC798yQ5W9cOvBmkDM9FoMFNxWZkIaEgXlG99AEZuwQ7dBABF0AsOHOK/0HLrJ3faIGGigq'
                . 'B65HCW+JzLEHfUJfeXbiJArrWwOLi/CGTKJCcCSuRMiQ1lMciSGkb4eW+F/gREbl0aQoOSCIu0dnErNtl2fzeJNsHItGLGI8'
                . 'B3hSNixCeg+TKj+hio8QbdEgYljnROv5BOh5udeJI00l9JmjRlo06HckIcJEdmq6Czi7cUUl7+vN8lB21yc+Az/CvEXzDNQN'
                . '0cjRiHOi8n95XIkTyzx2X73MzMom40G/23Yf+NNIasmdJoUkh+abnMknByHUcqaSYFsYTzSn4XXmsAbQIWSTWhITOMVfirhK'
                . 'Kho9+fgEcASg6zHIuPItt0djQRE2pT7ihlNLAtSS3YMsXxfUQDB3t5Kx2vvgq+Oakj9Vt5upj6huwYuQXnt02jafM2TInISS'
                . 'GDJkjsM8jrxEvVoyCjh/Wl6vridx4LsWPWNnl5b2NPl+LSEEPJ5ILyU9LbWQ38sL3f6I+xaCnK13ZmMfguoVzFUuAUsJgx0i'
                . 'RNoaHQducOUP8g685lytr8WAL8jLS5wZLzAxcaqeQUhZrwtxA/jlVkR5p+9XrQp2pNsdJ31toImtJNPKo03xDLV9pG2o27cl'
                . 'hJ2nPq5u0XMdS9cqT6taAUEiruNgf//URHncsdy2k8Qeobthryt92pFvyVYAaKbbfGkO2NxkqzugSOuUdAsZnUqUbf/5g0wc'
                . 'HLu369Gw3xVrTvLOH0dS0ymO6n4Rbdly3R6S2RfaFTPQllBXW9aSBGapXlWZwsem4qqudjzpeBdwAiDJlZk3bo/ORESwHnGD'
                . 'aAmAWrJ1ngQlFyg8QbmXlt6LTF1c1hzCgGD2mzgu5C+4WEpszqTM6Gy4Kwr94XvYgi08/HsrgxLwckR6XztI209TW+bPoVA5'
                . 'BwCDJaKg46GtFysMtcE0uxYBLhIugZkYx5JBcxzmyunKc+vnjRau2lCxyJbGYnFr74y7FmnZxB2ZfAGU1T42Apm2XKkxMWnD'
                . '3yqiASDCXTDvbZ84ADFk4cOtOruk6VsRQBLiJk0pEE/jxrZp5ZJTZSHcCymitpMYp3ECOdjhOQCORmpObuMHZLWPRpodhvis'
                . 'YAfStmINbZxKEHRPdBw08/04LiOttUOmatW1t8KhKT29RVc0OXp7ogRWo8xIa89S7cjGZigtxEpj3IrMZ9x9PoWqr2xygjzs'
                . 'g5k8iMGBAy6sSlhiNxnZzVd2XyaJTIBM66qzfKnIIKg2OaPizQNtgE1hBD6dlWeTMaE/lxnm6E73WS4xTicpyqnXFztlmj3I'
                . 'OSvHdYMH2ZHKs4Ld6yazCdAd3bVV2Ep4ENwbgNFxKfLs7Sas2ERBzpAiH7sKx4MuJZdKiRCDaGI+VDQYlxBo4yeeJ/dFzvsU'
                . 'zCK1CtqcvNBYN1HVgUuQjzl9MJd8uBJJqsT35y3zXvzr8/zO/k77BB6SYkqiQSxeDbZLbOwUWEtLDS82Zbj8MgFqyQmgofg+'
                . 'ZT0+qDWyO+YdnixuJwkte7Ptdkip0f1ljWlPeCj3KiUGLsJ1dYOQ2SCNlfBk1QA7onS6xxSxam8yWeBDb3jndrH9HAOe/N9+'
                . 'eeflHutVtcxvFbcQc+hiDR04WOfUbgq9JB4pJTcqrQSD+9MjbZ17/XHKE+Cc2jseIdu69bxQNQjdDc7YCU/ODJCrrjp7j9UZ'
                . 'NnYLPZwHLo0PLWZGz9iXhwutN5WY2FqYGsjh8otFzolaIFxGOkfigAn4O+VGqD8sWFu0La8WyiLVlu/2blHXQtfig2DWIIyG'
                . 'Nw9HsUOnj/jYdIZj81nkm49dOAWbJK+GuEu57JvqQrqf54Y4Dn/nwYJj6rdJAO95nDv3s485FkEtCDDCEH8BClQPgkiDMRqO'
                . 'Fahxz7yjKkjOMg3o3MUMezTqwg7ZVQW3l8Oy1NBPJWa13bTOam+mJ0YyGmB9A77Xw64uJ3Ic3cYjM84gMX16EFHELRUWurex'
                . 'PXolq154oSnd5ZYEqOkYsT4gN/DcNW8HpZCDBEE9ISpEFiyxgaREtjHNed7tIUjTI5V6YEKUg7SrbhAiGqSx4LFMVvZO28fE'
                . 'kEymLAVmXX69ikGvc/GyulnfzJJU3mUZlVnFoaGDTeQTO7CtSOU2Wv5PbeKAJaGKT4b9hNkWXVJXqceXY5oA11V6Q7Sg63Wu'
                . 'aBCqGpixEd78Gwy4au3MjMVCum+oiifjIC+NN+2iejFQ/lxRfAhnhm4aMTnpk+XEKa0glbC9B7nw0AlgTCapnzYFltHPpSEQ'
                . 'tZilJCdJYCxGQ0xkO9CLd9q4ErvKeVS+RyeEvo+90i9NfeJSVxJiuMEdWc6Xn99OF5Iir4zzZnm6FLKUt7WExrZCnTs4C4Gj'
                . 'jFVlsdx6VezL/b6qaeg0ZznDy4NTZDdHgtmKhoRMp0lnZCIMdSZwIpKaVKrDtLBADNstRTYaO1MoQJx2ZJbCbXQHpkHoS9dI'
                . 'zZTRyfh//M7ORXhHjiWO36KHdIiEhgzZbaWHp1goeGY/21ej2+4rYSzckTZcbeOH/zBwLF1lFJu8yztEBjFF0BDyF6Fk98LV'
                . '1G0hoB56AmAKJEq3rMS9Y17C5451ANl4KxgQM0CbysrHFYzR19bAEk4a/kknTfzdT3lRJqt0FQXuzNBkOhp2pcZd7z/cj934'
                . 'X/NXQeO2o41eKmZu9FRvjAtQ0/6KxjKFO9HhF2BBgWL1zpxKXs4rDVLXNBCNvQOl4nFh5cboXT+TediuRPf8A7v+sT+vq0WZ'
                . 'paFvoIk/NNL5s7jROWW3bJ4XdteyqkspvFzpQtnr4paNc3ycEoZz96Sn+z47/MQfhksYqPsZ47XIcNz39ER3gQlj4BChZ34V'
                . 'AxGQYQoSpaep9uNczNAdQBLfHfljWSMqkW8JocH5pBTPJfRd5BZeMbUNnY4HPUnMCfbkkCP+wsZdbyHMLbwH1UlYJFtcpZ3Z'
                . 'W0p6i5JS2dEZUCRCV6tWfMTXXOZJ5HuORStWJYxg56yhIkVOC3bO+R4aUSVOEMWjjZlwofitloHWqN2fBlZiI8crVQmwQPiU'
                . 'Ex1GyUszITXnMk8MAPlgj/691dDAPODe1kK6ZdN1JL4gtIHEOowf1Pn7fIVCBTYzb54L7bnQWgy6TctF/2S3vDfLvXG7VxwS'
                . 'KFcaQxGqVSgx29Sb0Z76UbfLdn3/COczcEBjsmbQHAIM716bOLESd54nU6DJkdJ1meWbNWi5TT09TydhE61BSmj0Kii4Dcw7'
                . '0XbcW8cVOX+EdVRCiLCk60hBYf8Z01ybgNfTIHMobJ0ySrN5rk372vTz3J2eWxNuu9oeHcKF5cyQfhGc10k1J5ijZa5O6G24'
                . 'VyHOuZxwKndAZoRGzLpiqwl2ZC1590MjBn5O3ilhxjDL2G5rKLqpa6o0ry1w6wxTtmu1UuJI144dzjl6XantHvw+gRz8Okig'
                . 'GX/KqUz5F1x+2bAtZliS/q3FsVYammpB3YwtCqdESUUbKWDKmuXDuDTY8E235XUk1CZ4khknlwCoJVfDpWMiquaZE14ED16M'
                . 'CMo7aS4fRMWU4aILuouTIBPCUsGtKcI1LhXW71x1MWd26YN47tSiJStTyvgTq5LDImWWC577QKFCKdoYVdnwcrQUTEVT4fJu'
                . 'SSmG9Inyscxg5eFa0ZQcOT1f6RP+Ys4JRCzSxEved8m4XU+qh6bn6mCVzjo3Gu6CKdZOubtLZBAppTgu1D9+sRaS+vr2JuMR'
                . 'oQd5ZI7NP3pYN4cKbMk8vt3RIYOqeS7jfTl3tU0siahQeCwpY0JYPH9YSdsFZslLpBzn838I29RUmrKU1TKyAvKp+Siu1iEt'
                . '9gEkyuhku7k0GveVNjPsogGh3YRwIhm1dk8OWqvROaAwl4wjNDSkUonhUJQcZrEyMZDAmqSoKpiFRtf+DzvcNwfkI3+hNNJG'
                . 'bIoHw2ybpmLPksbsy0EKCzVsvRUknpi6AmQUJ7CzmVQMnTh6mXpgBC0UMGd7hCDBzZqGmeXSguzIlY2hSLCLPIHkkgC1FAa4'
                . 'XDtnSDwoQlGTqqt1xyLGk26cAgE/l1ZG1UhOXz9Ha1KS00V7Xm6ejbKg4ciugD5gbR2oJVJmKalbaHpVFsz1cbVkO1SqporO'
                . 'aUqOKoE+d6qumQEo36BpQnmytIh2TF0ZeFHQAdEWmJ6GR05DYr0HgEPIgeotrg/PvhbpZnvBWAWHcrg/yNqmdZVVrKNRO6Gi'
                . 'NJ5JU2ZhBMAGAYMOQSbGIBUtPv3OpCSIGsxbrSZq+xM1bWJ2v8Tm9HvkNyFFnKmWMHhNxq1Y34PKLLQqZEgWFSLtt5lA0f0M'
                . 'JQB6XUksWbZ+blNAh3+cFdVDb7UZ+RVZfas2a/urNry3KRRkb3bqmDoNWSi9SZUENDzLlIOgO9MXgZEhqC33Q6BejMqmdGnM'
                . 'Ban2txKmsubUz7Bgcz5QZ9oF/i1lIAzynhrZUxdeCXLABSeR/2CNH/gFKfRlARzi0uVW5FtLnCWJrhH6LlfxLN00pGEra4st'
                . 'OTyQfbdianuchyT3UXQlhADoOBBC4KgQNApANU0S/J1EeXeNYMUMoBAl/d6F3A/HAAYPJHhbRCBorc7TrhfBUW+QuzSb7SPc'
                . 'LlDHoEvgTCwC0Gq70W2mi7jTqCEvfZBCAWE6k+NMaJN2yhn6LmA/oACNALo0hABufp48JQQZ1XGomRwgx/OM8wBWM33AGgfW'
                . 'NW3xMfIANDtggoEYMmqhs2hLuIoHKL+A+gyCADIw+suz+lZo4f/BUNSXAH5+8/gMgF/F1tPBWRv/lqPWAQxCAEDAD9jQ7lNI'
                . 'jg30xbf3J/Er/EYrr9G4fjsXeJCYLRhnBQRliPtGGvwhQgpet3zGq1dci/zanc7F63sJ2zQjnxUAnETbXm/B7z2Liy1PpNic'
                . 'UqBDUkSFao1XLCHpJD2Av18h3kMAcF4UljDRKJJBgDVNcABgqRotAng+SQIAgGFNUGBJAIBvCtOdDPF8eTKC7MXJKCKbstHU'
                . 'kzFUjCdjJcjVyHU+4LE+NXs3H5FMsc536eXtaH2bllE7vdr52e3Sq1kmDZVKMh4NvDz6dHLooNWrS7pKXs3sWkOK9PJoXdmZ'
                . 'ubJlyJKFT4yrrwSdDv1W65rTvKz1bSmeMRZavt5UIMW6s5oGF15JHAfUnr1Zv3YOvZb5uOsFMe7amhuSx6Vu49l/rHDx46hn'
                . '46qH3rM1q18NtXLExRORJhtpKWXEd1hb/EozL7HUz1lDrolSh8wo4jm4y+lO6DSvzAJoKRAXDnuQnJP+NMBut47FuYWiaroh'
                . 'Tct2PF7NSxfePobpJulr2R4F6Qwmi80xNePy+AKhSCyRyuQKpbmFM0uVldraxtYOsgDKODi5uHl4+fgFBIWERUTFxCUkpaRl'
                . 'ZOXkFRSVlFVU1dQ1NLW0dT7RoqdvYGjEqGsSkrecWT5sXjb55+sBn5qgKitAEGEmg0OgMKAZYvzqARZP/UnIOQEQRJiyCiNO'
                . 'OTOuTOfpcF4nkfMIKAjM7xY3HrLdPTxcSWLiwRonIReQhO0IPWYxBxWPZEzMYTfkxiHfR2bm/5sX//2YEHvKLOpCRIKhcDge'
                . 'icaC/UeJOSf3YKhwZTVWvHJ2T5vDeIDRUDgSbxuzgIXiiWC4rdTPaDAWbxdzz3bcvCNrWrjBeRJ1DVJruO7uagOkilar/96X'
                . 'VnGxen46uXv2wEeeZtU9wGmxBAutsoan3wrm3r5IXP3j5iB8mMUR+2UST28fwPlvgCRR3Zv+q9BGq5RhFHFrkeEk0wcft9Z+'
                . 'EC8OX6yLSCGbrp0BeOVG6Kt5Co4+J2jupQ+vJL/10LkNvOonPFgOgKPHbGese/7DT4OU+bzruYP74pePaU5w5CCKnPoFwVkf'
                . '24s+ZdfgK8Y9CBQb',
            'family' => 'Be Vietnam Pro',
            'style' => 'normal',
            'weight' => '300',
            'range' => 'U+0102-0103, U+0110-0111, U+0128-0129, U+0168-0169, U+01A0-01A1, U+01AF-01B0, U+0300-0301, U+0303-0304, U+0308-0309, U+0323, U+0329, U+1EA0-1EF9, U+20AB',
        ],
        'be-vietnam-pro-latin-300.woff2' => [
            'b64' => 'd09GMgABAAAAAFWUABEAAAAA2ZgAAFUwAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGoEmG7M6HIUwBmAAhHoIYAmcDBEICoK5'
                . 'bIKVIQuDeAABNgIkA4dsBCAFhDAHh1oMgT0bK8QH2DaNiNntQMSfUXLEAXfY7VAoqoHNE+542DgYbvB+jOz//6TkRMYYXsZ+'
                . '1WtltUBiQibkKrjQBcPDuUGlvvdjDIxKlW1YYfcJBU1c6xu8jhOK9+uGSAVFRcUjO8z7WvxeYObmN9/wYUIx5WqeqW9Uuz1K'
                . 'TK2+5MGmJDOTCWKZudiZkOLU587QfyqSyIsIXcR/Y4vDbj5PiOx+k8eL1X4R4DbxIxp1xNOH5+f25943FkWkMPCRjqop0aMH'
                . 'jMgUZhRgdGNV88UKzP7wfP0Y7M/eh1smJELCG01cEonIkBimE7pKJeGJRJLvukT1e2O9M/uOaPEUTuCR4KL3KVqLvT+Acxrw'
                . 'MmMFKp6qpZ6mTZum9dSg4C1QGOZz2M7MmTnbhe0y+S7u2z+47GK7Sfr36/ifNjNYVEAKPOWnsmc3QAcb0hoOQT8onfdc1C7K'
                . 'G3XVk44N/Ln6mSQQSkJRlCKWht6iWyv3Sod3f1/X4ADe/Ydsy2ZNWHEBFrgjMhHXrsh3TeLtD5xzLhOYwEALdI+0zFi/G5LT'
                . 'oksJr0qoXzKX334algN1onKc28EK4F9Bu5J1b3vgiId4D9BMmcpJgSci3alyQL9sXyCwA7e3y9htwsE5bfXkgYy9cNf3vpgC'
                . '05CS8pDO13uJY59/aUEBrEp4pZGN4N2XU39GUJiRPlCRaMHxOlYdIL97y997la4lhhA4bgMCKAi0KtUqK6uqu2FILjmCIBLC'
                . 'OmcRYxxDLwzWQZjXmrt7MnCGen4OyhrqSl+6G7JHqcNDWJgBOh6+xlrdBx+LFyKReL0de9iCJ0tmySQRK6GJaMhMp4QOfP9N'
                . 'f3bfXmpCm+9oysdKzoY+0NXf0H1pwlYrMRqJVfz/pbN++15JCGF3ALv7HNqzsTvECXFPmhy3P8TVrJ7ue9KrqlcSkgpjqYRp'
                . 'kEybYLeFhGmhTipViSmE2gNu/iE0E7MnZQEONExOefXz9q//LsTl365+3n+TsZRLEP+2yGq2AUW++v9+v/7b2Ggzoz3OHKfb'
                . 'Im8pIhHtEIkI7bn6v/d+P61HQjRl29deaSlgAxRkBgaE7B3DWS0t7WrvXjWipDGyAZ8JpH1RKEDPXIhJqrzsX/9TQFBSAABM'
                . 'QBkgzBDmLBAsL4SCAtFPCEIpHBFFhYiXichWjKhQiajWgGjShGg2ADHIYNR1NxB33UV06UK89hrx1meML34jCAowAohRxFuf'
                . 'AATxGQEIyanRtS9VKZCefNtcBykOAD09GABBMSc+NtXB6vinITtVbcZgkG2g3XIacTdn0n2LNBDi0kEIs5LEWcc/I8Zlh2XK'
                . 'ICWkiIHDE7EijxANQwCieBgh7uUGLnAvA/cScC8C9zRwjwJ3Pw16FUBFRsboIYKDyG9jBLhL9MT5CcFHJrkcDTrxBbH+E0tO'
                . '3sk4SUflRAjmy4vLSfJjdWTH6OgcoXoJh/P//tzP+3Zf7KPt3Jt7WTmbjmeeQ3twd+/W7aArq3bJziMzTHrZmG3dwdu8dVux'
                . 'RZuzaaveGEUpUD9pPKac1k48Q33zZeyarsFKOXwOZf72ez+2u8/ggbvV13uxp3u0+0/e2c1d1xVdtDc7R5lmglGG3zywTa1p'
                . 'WQua1ZTGN0oJ5V+7c5OibnWoTS1qUr2KgZvQlt++/uw90C95kvu5nas5n5M5nL3dnO1L6UZljWU5suBlszIl49KeodGkIVUp'
                . 'SV4yIInq5IgExzdecYk8Vuo0WYyiEyHlkP9xZp3WzU8gP5NvfcpFH31I708AyEtES/N2V9WRp9qbR9ox1E5yK6pi40+klkSD'
                . 'uFmdWLqehcu1xZil5XFd9hNe/44UpHrxjhcAFFeB5oKMojfaCIWeeS+7Lau6MzNhFpwmgEk2ufV/I06IC5iWqRF8UqpC83iQ'
                . 'MsY0bupmZklToQKib4dIDnkEC6tBoEH5g6FS20tj89oqE9TWvMl7pFnvzE6MjdZWax0KpHDdlTXAbF/J/35TlDG0QriezQw6'
                . 'C67SNbmXaDa6yryolTop8vrjoMghqBXSZEsMRwg33CIDf/vzDgGgdzzTqF6sashnc2v0E3qGvTGqsqv1p6WuAi3ufy4GF3EN'
                . 'oWDceFJ9IO/LMNrXoTPZWUhPtLAHh/J+JmnLeZxl1yt9vnEG0VneYz8HDWI7da4T3veAVmsNgrzQM3F4W21Zv9HW2DUT30J6'
                . 'YLrnRZZqSYhuNJsQNPjixyI3AqX4auPF0dLrn2egqIMh7/XmAAddpIeK1tauKimKr4XGMBWB5yqmJEoGSm/wA5hhjgeeBBCE'
                . 'EjWZZFPoomGPMiqodNWA9/fVeIBQgD9AAXADOABsEBaIfADRFD1ER0wHoNCzf87cuHdIB9J/Byg+OjuNEJ3+CAckT556cGAD'
                . 'Pf/MbxWn/0baJ/fOJp7J/i6yKWOdy43s51oeHt42v2u5IfcZRT0cz7bfD2KUUXWQ4uBCZYMA8rk0g9xPQJzpUUcx1+RfdEk9'
                . '1QeNgHKltue39nRC5NtFfV5ybEUJYPg7AbeN6SSOgoWbWritDqxBVYpdpLar3ZEefjPngOINJ4o6a+LZAFeEsKO2LVB6Jg2T'
                . 'u1EndS0nW7oyR2JlU7S4Tyy8+Y6abszeaGZzpTTS0hYDV49ybM8VzXZhXLb9qHTnNkQXf990rZcGTyY2cXVMaM3a6XNEE+lk'
                . 'NsN8e9KfMVP63GxEZheuqU1bcaRcnbUr3/itMH6tYw9G96alzzOxcY+Z3tR9DVW7l11MkhsujT/R7uDUt3z54K3MFSEjLoS6'
                . 'C9dDgbtBxIlLqAxCgCw2zKwzMojZ1ifQ53bkZdRhuN24x4tY32QBNNNpkBbvbOEyablzsb0x2IiSmWKdm/rUjOWs3VmH9Imy'
                . 'JeBaHYj/YA508Vdk0MRsB57ftqSJMX35D/ON/YCpSmX/u7Sb88HpXiuj3NJZRcNpEunBQG3MdMl/tJz5KHU90bvfzSUHGHwr'
                . 'qwxdpdvEvOSiYpgKThEfKrh7hzZ2KFk6dy5YNuuYhzNHHNHkJ7PJyMhFYjybSA7kByotZ+LIVmoOIdcmFkm60tHyGxmwN+hJ'
                . '3jNpv5niiqwRsICdLSIooiRI/P+bRuwZcFZIrthguYYZpsUIY2iss95whxzS6qJL2lxzzUidOo1yX5fRur0xFhWCENAiJESJ'
                . 'iDEkJHrRZoAyZIhhxAwlI2PNnCXKij2GA0eUEycMZ84oFy5EXLnS58YLjzcFib76stKPDwFfvvT4CUGECsURJgylpELEiaMr'
                . 'XjwiQQJKLQ1funS9ZchEZclmKEcOvlyFiGIliFKlDJUpQ5UrR1SoYKBSJR1VqlDVqmmr0YCrUROx/voz1UxDaIABpAYaTNcw'
                . 'wzBGGMFMq1Yybdqw2rWzNNJIxCijmBttNCNjjGFhnfVMHHQQ46JLjF1zjUinTiL33SfSpYtAt25cb7zBoEIRfAyBLbDxWClK'
                . 'mz4tg2owYG8FOMH7jTk5CxaM9GaJw4ocZc+eFsEUcdcQ7W6fPQMchr3qHeuKsOKIvLYQZRHMkSKCG9INyEbmmCML06IoK1aA'
                . 'iwn6GKMDH0m3d+htkM1fKJtrCygsxthihyVW9MLaMk+AYobYYuiK0TF9MVzu8TgAm+GxOMmpVhmV4WlOlVLy0rSoEhi3OsUi'
                . 'IH/XaT7Oi3mkPrd/2DG1MSWWIGwQFPP+7Ux8QiIOnLlaJFvibUqR0pWrUq2/YVqNshr/S3faZbczujzDkLXKur5lvetoxq8Z'
                . '/gIEChIshFK4CJGiRIsRSyVerjz5ChQqVqJ8pbLfGik3E7t7ODuuHHnzp0BGndMVv07zJwECBQkWQilchEhRosWIpRIvV558'
                . 'BQoVK1HePqIvay1tvozE2aOkG9137erhuly5kEqhhMGhhYuHT3Ay8YwFyrtBCvVSajhdu/oawrUTePp1n4+qDu23mgEtNKUr'
                . 'XeaQEI6t/b2TaArTK1iDrlxAImvYT6UxsINxTrMWmjViV69hFI8/iyrD+tjA5dL9v/RB+hDqMDi0cPHwCaplbeDaS378BQgU'
                . 'JFjISmj/opQSDhEiRYkWI5ZKfEnvkytPvgKFipUoTyOz2U57QQek+39B7Ozvb21O3lv+NQtWHUpr1ZtwFlVrklJYZKwLiG6I'
                . '2BjS6LUxKVZYvdPBXOYdCwkIN1rmt8xrmVujdbIWsleIA0ktRooOvK9twPD8kAfr6msx8L8Ytw7qTlaWF6LiZH1xDYpGQ+SQ'
                . 'hvptzOmW2iYo3+H0AA8uWnBgQtuM31tctIB4djigUy7DWo/eML+9dRdiWngTVgYQ5OelUaO8CDFk9PCtwQnY5+ywGkfYW7fD'
                . 'WlyVqvmp0IFhQIpvMEptICrOIIw4aqSwtwMB2TsD+2ZAYacAky7CBSUdkLKJsaZBOZbV6LkkDdMtwMS5RGKC2683m+6aZ0DI'
                . '9MWYPvwwY6BsbgqtVERwYQgbZADZCpQBpyxI36shhkdUsNhbxpdFjRPlONECejUZgNjlocluHhVofqOtUA6IAf8bndcbR0mW'
                . 'PmNe3e/A+W4EAJkPAJ2XZwAWn0A8ZIBBogZOyj56FFgcRiRc+Riuh4S4T3JOpzP3SxgOI2CsnxuwpqwFa8Xasb6skt24fmGl'
                . 'Z2XY8wdIsNz4WiZSk7QNzGD4p+mzJqysuc8nyWxAHAA9pFn4fxlzst99E/69og3Ao2vGmC7r0Xit7PK0ABCAF0DtLgDkunkD'
                . 'ueq3IFdmkrf2SptctFOnN6645KBDdnhovT3W2aXDBs899cwWlxFvEJJbzmxDRozJXsfj3itbceDEmQtXbsOk0nf836Wvo3Y7'
                . '5rUDvvITRilClDjxEqily5ApS7ZcpcpVqFStRqMm/TUb4LDbjnhplc3uuu+eLndc88V1Q5z1yl43/HLLC4ss9k23q7b5baGh'
                . 'zllmqeW24qI4+LTwCIjp06FLjxkTvZiSsmTHmg05W4/14cWdB08+HFUJ4i9AiEDBQoVTiRYjVpokyVJEylEoT75iBZ4o0qBW'
                . 'nXotymjYK3Hor6vTzjjupFNOmCuqAYWBk+bQ76IHPQESub57Wbb1dpQW+4/e04EOtU/xQ3ZYzOrazrKaJ6VTgid+qMDqFRDC'
                . 'VZLXvzy3a6wewxTpyQNRj9e00Pg0kSNjLcELgksyKADBpeAqImBUphVRiAXBexADulNs/sEN11IlQnAZnW9dzpu/EuXeDOSN'
                . 'KNvYEb+YFbTRYHCMh89syfAiLNz85Rn+h4krahrnVxKC+/5Yi5HE3hA+DAvq9Jr0I8NfmW7Qgf8H+sXMv0qVLIeTIcldb4m5'
                . '7vz2jcNNeq68f/GpwDXKw2rP4VGRoTd2rNhfIDVpUicu9H8apkjf6j9ilZCoUbnKrqQd2sWCatsaRJ08JwQPPcGtRLGxbFgK'
                . 'MoPjeCXJhFn1ipCPql1UIiF+9EhfRBoUpxUJYAjiHjuQYiGNlDnSJm6sbU+VimRbE41iMFM9hwo4Vs0cOuSEz1mGExJd++oT'
                . 'Kfr6niJPo3vieduHLRN31ItaBeG116oxif8wDleRAhn2C0C7GNHpjDkBwXY9kWhvSqQfI9WdDQZAmi5wcoXLx1kB2oBFq/Uw'
                . 'iI7FojBzcmnoevakQZz5JNJ4CXEkJJdP3AOGKa0dSls6CtzOIJ6c7bC1YsS8qg12X8mqaYTZDQwsx0iAo0k7Xc0I5hRWt0as'
                . '9mxFERlt7PJzT29FCbMMGCJXGvt+SbOhlAr4IZYIIjL+aItUwxUun1JaKfOGnGYipIhkVQWrez7jg4G+5mooswSpu7b96u5n'
                . 'xNjZpZ5dQwYK92XlnUekOsqhxzOIusviF5ZyiCwKiPmm5rYLE/800jfdZ6/UtfJNgGqGhVCfo2m/UuR4IuTLFOkoO923bgZg'
                . 'vg2IkODcL5LS91tMx40s1NnzVMxhSMEcV0asOIRLa/kmZLknzjntkVadSM3aRMv/Yu2Vcq6BDPSoaIqEIEtCkS1hyJFw5EoE'
                . '8iQS+RKFAolGocR0txDTL0uyrO55QU3k1VPI5Owqag33R9O+tnB/Pski3OAKHXcRqeefsRmO6CYWSaiUyqicKqiSqqiaaihV'
                . 'qQFoTlY0Dg+0CloJRdudMrKrg7vmTOAYHLtm2wxgbG3JpMQ5uajbZ6pGc7Rmk2QWxnadbSXO3UykhaF/qxqKTduUwE5tgU+o'
                . 'dy64AZPsqjOIBA7rsjWB8Q8A1n6TubxNEz2fJDugwRh7jODSSKZn0x71o6bioR4RkvW15p4go2I4amMQpJ7Sv5y+7ZsdjPBH'
                . 'JuDmmiuHxIX7PR5+CgLj23xEcD9onVjmJazKsX+cBgOadEALxw7UssIkkwCnAb4PJ7nw0p/iendl3oP5bqUuyA6xREq5wzJa'
                . 'DkryXFVDoGMD0hI4QyckGLtFxk/FeDUEuaL88SjoTMsyinNmNliOBityUgVXGjBWBRTJqqEj4oRHV7SGQTwfgOQK9vYH0QI1'
                . 'sj5CZ8ygILOdyBCTZu669qxOliWPAHsEC4bOldMjYUYmyXU99jUasYZ/qsAUxCsjpEcTlm9bWAdaOrrfUqqd7qHzM2p4f6qG'
                . 'q5uixxvo3hiE9IABiVvdpN8yO+rRaJ2nlPUrzS/c/o7ZmvyimG3uR/sSeBp7S78MHiNFK7jXDNOh3jCSrDl1oys6MFxTy0aP'
                . 'RfILO27IZGnvQy1zsjOvIrMj7mC8T+d/E2MHBn0ghD18I3PGusp2qxjRtW5wGgZRrkdk3W7lAzQxHT++oennfLQA75bv5by7'
                . 'bltu9t9CBkgQ4V7Lt+vKyYnuHA0Yu4Iao5RWQ0Xv4mQmBd//sHsoXgBcM3kEmSlsiIgyU0KAF8nMlGqISGc+zyACYA2RhSnX'
                . 'EpGHqTACGEWYSi0RZbhfURdOrhaSag0R9czUEOBHMzO1GiLame5gCptuiVKvJaIPPYACMYRp1BIxho8TBBCd52khxxj1KWJG'
                . '/6zF64TZN79E+uAiYgws2U1Mq3FIidYhbUIObgkz7ZAee0YcmOk4DiXLKZRzKJdQrqHcQrmH8mjRPEPzCs07NJ/QfEPzC23u'
                . 'D4Rf7qb2WpJrBW80d71YY1Ud76SHxLa3Zj31b1QhgCDdQxsA/AGA/A7kOcgC2N4EAAAsKH8DUHuki6FNpAykK9N/y8SAuWhO'
                . 'mBhuKJENbiCFoG3CuXgpBy4MC1sKTSx7GdfJzOcKnnHadA9Vra85l23Caha0u5DCU5OBWI4XUn9cTBC3iPzbO1IUQeyhVAcq'
                . 'kNaNWVOSE1dXJ7MwvP537zQ1ymjzIrj+4NIopbQyVZLQtUEVZInakdaNmYXrICVantxZM4zDYpKEL16mMp0Z81vFGl/5QZmo'
                . 'shuXdKYkhKrILzmEL34f+ptk5akDad2Y71FlmxRG7RXgmTZpqI5FVcxSJy8pF7nZ/V2eq1znK/wS9IzV2Gw5weW1mZl1+KyW'
                . 'mXBzG8qrJhltOCGxtzrds4eliRbGLE16OD+rUFWIh/I05ETxMlGBia8pIOVVK8J9SVyr+Nqn083G82KXi1TaP4lHYfq87vns'
                . 'mCTWt54vc8SDv0zutPd4WSpznGO1Nqpy9bcoBvkkICNd/oh1Yio1N85wnutJBcKE6UOkeT9cbbz/AM4gW/L2a06qu2efD0Cc'
                . 'u9dYHdMsMVE06DHRWq0PbwCdqIkgVWQf8pMtHLUMubvHGL9xdtKNjS3F9cSmzo+8vHOHYgZupY+cFLdvc8IhF/h38X6cDyFw'
                . 'fsJIn2uyy36YwF7wbB0LmtM/axQpSw4nl6e4DdtTiXf+2j+WcjF61aOdFe6u/vkxi2TBk+4SxU/UA+jrGtPzlEO2zKNP0htO'
                . 'UcuJ+UbkoTUp1nGyLgn7dEyIm3HmCdiN7NeGA6MfitdCXvVo0SHZJWSXcTuGHIWCKezrS61nKjLbmGa2vk1s+DYVC3ZfunkG'
                . 'fyFhiHRJ9tkJXfbIDRyveXbTrYu9gzqpB/62owu9TXVIY0/Ga3ygVdaMLjuFIbNquaqaajiYthSXGfbIT1HaW6ur92fa4MGF'
                . 'PzbPxu9eXSW2hRlRTnUuWcY/p1Lfbc259H5gk1Jlzpe6+/7eRWtYdKGHfBdbvDJdM5bVtktp1T0Wu7yqzVYlG1QQdAWSogoN'
                . 'OvvQ4FG4p0jkRBAGTnLnM3DbFCxWZnClpN2v1D7iFLVDfpycxg/lT8DaMkqTM0ay+8sVQGOR+++dE64Y69WPcSkXXRhupB58'
                . 'aIama538Pkp26BP+DoQQirt2CEBRRITVLY4Ai3dbHreWAw9b3RobNnB5xHWc/YVTZoayGTZp8IdTZSaZCt2aX6OrnENK8/7D'
                . 'G8GYD/B3qAshn8DPgxjZI6RTfZHsG5Iu9FvssZInzUJQi2Z8YOTAdpwwTp0fOoqlELSbX8ZJmb3QGB8TSEyvpjOjBQ+ae/K3'
                . '9wgkyz39CsDEKH4I8SS0LosnDh/pTUbtHCzcPbgnm7j3rmjCEtrV1Gg5SPEVwFwJfXmWAvns8NkpPM2hxPC+GzxoJw13Xh5y'
                . 'yiarzmuld+v/VFy78mSo3hxsmgpePK59k16KSV/QdjFbv9njHAu36Vni9Vxsc7PDPA8T6fCKoJqPgfE4+yF8IMrfbZ3HpS+c'
                . 'D0RGjhYBLFWtkSDYlOIemkAOsWlPuGgCGQegyfIqEke5tP1UhsvGk90AjFxbiq5TFy0jmfHZ22CD0bbtzuLytkS4vGh3NZpn'
                . 'sVtiP8KQEpkw89cKaZpFNaU1imhWiJ3tOoo6nBUYhRqaflAZ10nzQ5vRWkmddMmVwG0grziOSmT2MFQqo/gpnXna61bjPG0b'
                . '1uM3TTIv3GWLnJx6brmSFZmtOINZT3ENAO2b/ehp0F22ytgFfAYyE5TkOhxDp1zwIZd92RGaQc4Ni7Ke7gx1PtaQgmDVG+HJ'
                . 'bxxfYiz9gfY8X734JARDJN47p6sH8YaOu5p58kXLVyN9G+F48dxRUMYOWESE37Bjo0fIzVK5GGGYC47sYxQWSYZgYxlXAerj'
                . 'ADSaPFa35iPSxnWOPet3u3CzFqUIs74e5Wx5ITKf9GRQOlWM1EkN9UBHrEZ4xzxHiuzUYItrtsyxEvsqM9YGGEJVCCnRsf9q'
                . 'V0mSfgEpXTK6vQZenft0sO7HxExGD689pr+i+JyGN2hWqdwJG3ycftgyzoQGqkJBd6NQXNNNXH8yrIjdmGAFqJ00xB+9sMgj'
                . 'X+CEn5cpAxh1D3ijny6F645fPQOmdooQileNQ8EKdo6r79oITWXvyhhgLTsMCipCN8PsEM9UzwFFx9i+I5BMCIHYQsBEbjY9'
                . 'gKXUHyrIOGfoSU+keMSpvz7UKasatbVkh8i55ml8rAbgifLDEztdzH/36h8QFe/H0NJP78DQpwWH3jJ6DLgOYg8jNTn45skR'
                . 'x+x3f6OSvUItYKjRuTqZgGjgqxqHXMIBN6shAwTqjp5LVA7kcDt1MEhmbJ3IIGN7w/9NyOYy2y0Z39S7e5+sx4Lm88VrnIeE'
                . 'moGBQDHKeP8A/Jd2nb7NZSrFj68yjwSfrDg5lbByawulc/xVU8B44fHR58nunnN1hQt2TQ4h5IYYtI7oiHd3HFHvjKm+Ef3a'
                . 'NGLcH6vKRIAs8VMN2V83SfoC8vTHYt7l6c+xmuJzYd3RsWoS+lIU0ya3NYnalr5JmebmJmBuQqa5aWJuQg9uQpHjkGuQSXy8'
                . 'PDzU1JfZfQZ958KFLesgSuas7Gi+HLJv5DzXWV0pX68sZ/miQmbXjptXJinDLLiW5qS7Ovg7BH2RqsyZFhx+p91BlQ5DJcz5'
                . 'YuwBaS+TbJydXM7KZDvFx7M0p01gCFqr42lIKaSSsfsAqWqXRhUqkv3Kf8x25CFnqp2ipmmZTH1nTpKTo6bBw+ssZ8RF3xn1'
                . 'Wl/NTMTlGu3c0/P1AvsInZ2fILGmebjh83/pyDVpQJM4zAV2GvU5lfH5C9qpw6ink2K1VrNdqzlSsdCzhNwqXZr0z0YWGwV5'
                . 'SSvm8kuSh3h/jg6ywRAg/jtIbs89yezW8O3FXve6Q71htFvgz2eQYv0Vi7COEMXdu1sZnmOwFey54AnEF8uH2LcGtoae9fZE'
                . 'C/BF2GyY7B8C9VmesH4Bcl/CDiy23gJVicR1A+QUl0hNawYwePQDEIlZ18yuVJqFDPJopX7hIAKQ86FMF0JTJSoo5HBk0WfD'
                . '4NI9nE4NMnQr3HD2Q0G4/N7TWZGnp09YC86y22La1f094Tz935lJ6inkQ5foXylJvRu5x/8d0CIg7jZox5DSZRddoRqLIblt'
                . 'A0zqIECsgX38merP7c3zL1etsRkZc346dQto0r1Eh9vSd2CTLKrpnedBFe2lprtisp1DzVfqdxEo4FFUk5TbPijrT0dOGvAr'
                . 'SRFoAhbA0VrX/OxxyBXjM+MELmEI5Cgl9bAFADjMy/MIEzTJ/tzP+OxlXHZyeA37a2T4Dj8PwVD7fOflpVsU6vhzFNIF2DXP'
                . 'fXYt6uX6C9o/tULePXDyr5eJz8CfKJeqVor3k7V7hFGUnIkR59+3rXP/a6jphjzwpH298+9zqDeUZ4qyL6N0i89qi5Tzcv5q'
                . 'kM/g9oeQh3noO4Jd1z1mZgjBKczXr3g8+8rP7WLJfUnF+JKJCBgsEnowENSY9RuVWdMY6DQbLBkegkbG2VwMXPSVipliPEqo'
                . 'TRyV8pmKjSPr5VqtkHqZkEtVrWAFJQBS2il+jI26N+8wrKzAHPx9gErYm6sJC63W+2xmuzwo20mINzp9fwR8Jax5ZXqeo+kY'
                . '8a6R4v0d/J/TEJp3oEAfPpXnuqxJMqU+TnDu/jVbDgmAGYCHVBb5yFsjDCEy6AED1CS7CvHAxR6gXvgP6KrS6me9rDSCMEtg'
                . 'YRKJ5XWwwOiq2o0WflSFs+mi2YT7UQ+THavW5ZdvXq+kYWZX1UyU+db1azmDxjj2KEbhxbNQBHrl8sJ3bYANPu1qEKzm+oDg'
                . 'CXzx22YKpOdMD85/Sje6080xb6xFL7hwWFdZ3cecgqnXKihXqZTvKeR8ii64aBjm/IqGyjFz60y0aqZi2ly+pIsi0PKIX3jg'
                . 'LwRPvCCv5zeGgH40MOjQgotnl3C/KHEGZmeIjowsIl/nGDlf6rlMYbBpYenPD1RAn7Snn94vq8Znv8OX5QXwAHk1/uJjPxc9'
                . 'uiH/1R3u5BwNTxv0lTqdwCNrL/yjW+YzQNqGDriq8MTzRc/rf/sIBD/6VYm/QnnF5pNy+QKOT4rtJqXWolU98g0+n3xjVc9a'
                . 'Z8qbFWDWE4FWgMDwbFFC4HS3Qu4asMdmBXvdNf6vnZ0JgWjfCEAgGyVDMWtWAG//31oD6HDFUH6zjPwwm3ETeK+tJApGsWiX'
                . 'eK1np2+CExY+JNBuDOeJ0AKKmiOkn5hoTs59WTPpSHUqJnFcMSmEVqvJOX6w7iDervdUgd0WiyNE6AlXbom2eduifPewiDhd'
                . 'cjn8YMlpc3LuGTMmNBTI1RBlzNzT+NI8P+7PzIVLLp04iFRYviiCPukyqKSwVleMDJ0uqLRw2wv2JufexjcuqMFrIrK9pJpb'
                . 'nN2wdKpnEq3FW/mYaXuqRb1Ia2eSEyCCt0NYDdhrhcE+rLrNv3qRHod1MHTDBek7owd2xhzJuQg+jEfmIrXDtZJhyTl6CWR2'
                . 'V81ES566PqSLclp1o6fcR8wb69fx3GldutOwYBi9l3p16tWnaTxnCi4uaSoUmFtmopUz+VPm50ukXEkCUwAOo1msLt7wV+Sv'
                . '4RVr9rN0PBptb28dHe9oyLaJ1p4Tkzj3jC354RVbbclceOyL+/xfPLBMnQz5jtbWRfKXVshstdNF50YWkn/mGDjv6XkMw5Hs'
                . 'wsVnQI+AesneoB6ph71VrxwmVa9Da3qUG31eR+Rs65zV1ZPOHtVGrw8E1SfRlC++MoZvranGt6hwPAGCxuoafGtSxXzLpf39'
                . 'pDj5W/P7s5ZicOffTwrK8J2Efdx7dsx8P8nPS5CYClCfcnXO/SRXEBK/rHOY6mh51n4s2eiI+ioKZk4imWcdR5MN162GUhRP'
                . 'wLnFqxzb5Prtgj/pKNsq7WvNqxrd7Pr94jT7Ka897yeFeWpsL8crVvIcPpDnwl3kMfxGZpuj/swmQvxqGcmghd/trxrQE4I6'
                . 'AbGQhYTVjMm53ESvx9RtszsiqyXunX/5+L3EvdaQRhSWy0Ega6vzIxvuTe7Ahsu0Cg0n3W9wULvt8/a2zMYdS/gOnzJM+23t'
                . 'hHrtGpqwF0X+phlPzv0VbrPrmowqYTKArHzMV/2g66D73PHzjPOQSwqiAgGIyqePBSO56njTsptagEDU5biPYwk3Rhy2IPxq'
                . 'E6WxSq0Lmp1IbafW46mW2bd3AmHAfMtHGiIahjSo+ViWtr0GguI2m625zwYzT/wYrsJ/PAFmSbXLccfKxIeRD+uaB1qzmYG6'
                . 'WU4kJPPlCmmXzK7KmWix4Fv2zbQ2p2sXQLqRU9jxLMPj/bH+AgzmWpSDeTOILC4fwpsMouXT5paguLjcvJAar4pJWTgb/3zL'
                . '3JZE0Sz4+3Oz0bNDdw6F7xw4MxANcmtuS9+elhh/HrT1DS+a6xtuDtJVjlg8n3Sn6sPN+LYqi9+bL6AKJChQ2REtuXmj7FXU'
                . 'uJHp5jN4HnKm+SyuUTLNPuGp3YXpIDmYLtxzMqhqtteqYYLVDEGsZiFLrheFI4gobTA4QgOwLYyIiRAhY0lLti1nZ+qm8JzV'
                . 'M00tPTsut4ydz+/vaHvoh5xP09nRhneYeAHMdtLs6PzN56xDG6+M0Bya89Ih3wEwOyXLdvoAR9Z20m+fWMihF6D7DdQX2eVn'
                . '4P+GjyRdgq/FoWBfPHVSVH7ixZBCM3HHBBDAYOETIdASacTezGtq8cWxDsS+soFycLG8koBMqrtIVwE/Ti4HCdCKViuaHi4A'
                . 'Zta972O8aIzkupVhHJSFGtO/hgx+nTimXXDS+Yxc51d3VpMO80ZPFaRDf4TwgtMNNoJ9ErvZxg/Nh4JDHrvPQUIzaifBbSOO'
                . 'yFs7rXY54kLKgREggOn3b0FIDJY2mYzSdAxGoLe+nwYCGJlDjQthWx7tLKC/cZPI6OfnFKg2WJiN65aiUn6ChpKfDkhaFXNk'
                . 'NUVE2zV+SEkyrmZv/5Px4B9TFWgbT7XBOiDrtiY1QnDez+y0LVKrhNU4N8tsacodQN+u1D5wGJhBwvrysHuuzvBYqsrbLV8T'
                . 'DpknB2JJ1B5BRBnIIE4TiM1O7K6RQJyGnEY1HGU16/WOyN8I1Kmd7nbEtqyespUsrQpCRuXxr14BfNg1PScEfmZ1KuVBDJT4'
                . 'qhsc1a0uoGp9tLiM3nhn8Hjj1ZY8YGehcskO8g9tXUK9Rqm4Rj2Hp157ms4GNigtSmm9Wbgf3MHuE/0QiG16u8nRFJKxQqjG'
                . 'wpAAdhSM9QxlaK1j7dsNrjfQKj5/chAI4cTr/00hPohJyAL5u+4JCMz2GhUcYaUhvSNCfNhuTl/Fe3blN4R+HRgfX7HYFoHF'
                . 'jQaDuCEM2+AILGqEDEzwq38k+UfIUdDnQAU6W9yoqLfFQmEEjTmgWXZcIYutXHrDPdy2lBwiq+p/dPqpC3G8ZLo0mCqqCKkR'
                . 'RVOymPp3OnqqKKOjapIJiVrm0hfxZYOYcsNYI6mBDQg+9D4h5QXQDYAbT5EtkzptSCtjhD06W/KP0OOff5D97/t8fuaHknmR'
                . 'SeD3f/7QhsihVP6A/Q2NPWEX5oTjHryycTgdCHpxJ2HVv8tMKgUh34AQtt5KhSDVu0ZNQZ/jpg6OG+X1tlhQ741O/eLCXZ03'
                . 'WjM1dDZa7X9SQ3I2qdAIt8Vg5DYL8TGnM+3SI7xmo4HXQoRyHW44CkubjEYipMCEJVgypGrNuoCb6XsLs/kc60zTOTErsY5d'
                . 'GMsPdFqL26+Azo5zWN5zCz/bci5aOQP2C1/WOajV1lD0S92N9nC7O1nIv9nMh5Xrl88XWtXsyk+ezHDl3x0X82X1qjmVr1zs'
                . 'F7qliy48363ed6EG4+G2pxaukfuFID2Ca5LXu/zlj3b7PznfHghj7Yh1WT1lS66sKggZVDv+8PqcMGghlgQe/qRl70WrU6nw'
                . '/pkUYdKf6NrEU++G0v2WZh/WEgLg5Yt8eVsvJrfcuys8HMIfdPsXT/KH9ZC951/MO3/82d/8r6QjRA7h+f8bNVz2FODHzZEM'
                . '5QkJDy/4/zVh8AldHIigaVyTBrokFhZtwhctd4HL5FhzMmr0cLYrm4AAukK/lhpAHvepDm+pe1HSjyqUvoQcRr6zd2rQGgeM'
                . 'VPHzp98pBl1aN9Y0pg+DJsANo6yutdKDNQb89Wi1I+5GVHfiPKnSL5JHkH/D/+Lm3g50kEEKhkJ4JOPIbAhtmHRMRmIpykYA'
                . 'xfPw76RoY1t9fajGgWAm4x+slIYZIoeqC3odN2NY9oF/ZklG9Zz2LU3PAWam7eCZ0W1LQ+TMuEMNErfnZh9GZ+bqq67S++PL'
                . 'i4nbrpy5EpUXCXzjzsWRzee3n/dvO7/lfOSuxbHN0/a6Jep7ii6QElMfv9WSl0kXixJ7Ptz9objyScpFwIXri5EofOJVKnH2'
                . 'yq1Xom9xhA1DNUpbsB8sb32r4sno9sgI8SQSNAzXWPo0WVxxmVJgzIja6D1udOx+L8bn0Ax7MvvzNGbIpTHv+jXTyfvCx1Lw'
                . 'bfbbffWHEuTQ20XJhD29TyW7hONxcrA1KK8nUd+1q0lvkf37QfVjCiWqNtXNm6e95Rkm78MVgAP949NqzOXVvu5W7VTQTFTx'
                . '5/L5iZKnfm+4ehTya4RhpcI1+jQGIxFyEyjJ9Gg9CoXr0KiXVRW+YiwLezrBTZEwCGJZOxZL9o3hSA7s9iZ7jh7cT0qS/ku7'
                . '/0D3MdVfs3PhqrWAavT8fROd0pB55vTfpRlbo0Wv8vj5EgiVyN5bHCq9g+2OVIoRRxfkavFjrr6eJd8sG1zmM6AkdFCHt0WC'
                . 'Zp0vyleqIwKNR+9KBn2tjlY4gSHKV32gXBrga/xmf8TT1q2D4U6Nu9ZpsybL8luwWpfH0zIIEccu9fgabjR8v9azBrhU/itz'
                . 'p8ZLcqR4QzdmNUAATos0xjUiMZM0pdZKq6Imu+Y70/dA2G/fGtwr+CFvqcvFhOI9AfMIquXWOf0ZRaSGhGcNrnreMsxEGdP3'
                . 'x2vGDFENvhDwQ5LOdtZLEUwUK43nBs8+i9O1sW6fYQBxGgd7/Um3LqJUxU0mEBg6HUFjZlUMn4LY/Xlp5T3uQ/7VaL1lv/dH'
                . 'GBgPrPatQA8o78pNi2wkot8GZ2A1F3v5jkv5U6aqAyKjsbrd4PO3GUzVRtGBKtNU/vQdH3MxNWzP9NsiFyGPWMHlKzwiCMJE'
                . 'Cj5XgYn1Q3BEJfFJxGIPoYQhKxEWS2KSXhx3f4e3GVz13GNx607Qes5eJ3VgwtjiJer2veuh62JdPqNymxBRFzNjoiaCImMe'
                . 'KlKjOjCudY2oVq2O82J6/i/Hjx+BD9mRh0DwvN1+TrE90+IqdEtOP1f+I0RDc9Wope8PPqueSm0CURl+GNQHrAYN7q977rhN'
                . 'f67I4oeNDvZqBguhUb9hqyA17UZFBYtOI1iIwQdD3HYFh0sx5t9O5YDI7eEnI7frDl6Lf2gymyJnJ7NT+nPFUsaVvSUIAJ45'
                . 'SQMZdEYJSN19CkD1n3vTMl1Un2sMgVLdGQ7nhXWhe4o1L7O8crAMfwkX6CreAawoGEnA04zuZqmodIrFuTJ8hm1gs4zs3Td9'
                . 'BriieX75qD5x86LlDzXAcI4J+3MxwZgxZFJzn/6Ml8s7sZMh9xggg8cgZ+y8uzS39NO3eeqQyfgm5zVy2VU262oZ+RrzJJhf'
                . 'QckFeWRKRbn9tnrtQ2yfjF+OP4gL9eXKr+uXkZQ3JevObODdMriX5WGxoSqb5XE936hGdby4bhCxto5oQE9A0tU+4p9D7Of5'
                . '4J0wck63/bnVXXCKQY22bO3/XcBsQOmT5fihel/d8/Xmc42n2A0OVqOdTvlW0nspzOMj7PPKuRyKKe+OrE3KFuJ27Qd5qQSc'
                . 'bjvXKKV7T5+6fSadjuqk4Hps7guTsXsaNS+xfDJ+mWd00tuA7Th6Vyb7vqFptpE1gTZf0Ee0GTujL9cjNzd9XA/v6U95ebzj'
                . 'azB07ZCcufOu0rz6tzivomvoWv1V5qkNlIrzVFDKu257AoH1D+ECUC//CgPcI4MNvEl5CpvYLJzMf0ug86Z4K13UkPTJPytn'
                . 'can/jMbvjO38hsGZoDOmhS5V50d8wUMeoaDocr/6Ttjwxglz3A6jvMdZnCEGncPRG/V0NoXexKSf4Dul+IN8/v0EyGFsynse'
                . '5gkTNwjRjoeCux6iW6uf89zOhwKp2uwjdeNfNVBoxBnGGZ4+eFXHbI9wT30t1w8em2cmW6de79gMZuPOhwI7ZVzvHbpD12If'
                . 'ms3mj/Hs1G+D76bcc1Lx6ZwdscYs4kVhMVb2gqDkc09IVxF9kE44VHUmxJYdhOPWnFucSVjeCEHyxqTNKdvIX1Mw3mfos+G2'
                . 'iCHyNI4XjPM3P6icYbuFQrb7DqXqjtWLNnP3I6Y508l39f5fn9n8bXFqNnX9O5Ii0avw7B7a+iLap1TqDzTa/1ST/xnxnUhr'
                . '5jEKbYF+MVkMmrzjGHf9OdpnFOqnNNqPVOqP1HsbAxBWbbtGP7l/PFhVmdA0Q7dU5ye06b9p2sI784yho4N33/24l/JD/Afl'
                . '5E4i/XvKvWH/wu/yjNjxjg7/cJ5tkATuO7NaqbwT54olOF/pNQcJd1uHzga3axz1bqMk9ODlbVK24Ar12iPlA1xFpPS3Scrv'
                . 'bVudDS6jFJ9Rg/Lqbrt8yiMersSjmNs3FcD4JuDi7+MXTrHYMSeVzhFiSfRzxsEmQfPkUZ38CARMBwLTJ5lX12Umy9LrWCdk'
                . 'bx5aiB8UXNy/9OyTv+GdGi/J3vDKB0P5wAXaV79X/iZ6s+dNM+3SPuZSwLC14tKTfmdQ7976eTn/4lEaOoJyxq6LOL066nrT'
                . 'JR57MjRvtDG+XXa+voHITjyJq+DbauOO5bypKMGE02sT2wpzvwReVRlrjG2Xn29okJ+DMtaCPFJiIxFF8arFx90+r7scw0AX'
                . '+FDL1+IdWOuUK5lA8pBBUblnzXZbSEkH5q2zie/iEx6LdbhbPZ5ycQa/4RlxOeZpxSyJJ3OY2GurXnMLKJccOUz366texwQR'
                . 'h1K+T1yxjVRluReZXarls7BtIY3KYnZsf4k7/B+NJGa+/DoTycYepdDFfYgtOf5ijDNAKHSqLkKQyu9l6Rh7I436QnZwv+15'
                . '67W3Xk+98fobr/lWOSpaz5uvvfl69UHOdj+JqeILruU3qmQmTVN4ErIM7zFZ4QM+5gN3F+QJoVuWfArSfx8dvM3Xt9x0KJEw'
                . 'HelZedrfkzpsXtaFH856RN0BxGjF4fsKMgQq0DuTTlnWbJZlE06nDTd8le/1PVdq/toCatQNlSnbZLb2FpW/bhNS36Ga9HlV'
                . 'tzQu2WZP+zuZ54DDuDrCGZ2v3MH0SyXbde1QKnaw/BLpf8127IFIngG9u9Edqm13C/mfOY6JzVo/IVLJg3yNR4cQ6ZDw2Wvb'
                . 'mCtirhbx3rL+8bTD+0C3pO611NnlsEeluUcs/1UnvTZWptW54wKdOsnN+fAvRpAYgw21qCOhkRJarSye0CE/PWxYEeC33MAz'
                . 'vKcYszogAi17MsJD9GHhXVJ51ff+teyGRoXFZ7LH43hx4RBT2GK313frvb4GeXSiCwgjIgr9C5l8IrvQGLB+P8RoaFBYvSZ7'
                . 'IuEpLtjhqXPakKZ+s/hEsAoxCMddrJoxpj+FGIU7XEx/3VFS7Tp7TbtyDeZRTqB3nb22FuntigkPplgDX/7FsAT7oUBK1G21'
                . 'CLuDqX59MEhyyoct1pTkSP69XpXA61BV1mRSZutRXO9ir7T3AghWjZon1f7HC8nlvNB/Wum8dasABL3+K13szvc32KOQJKFS'
                . 'ShJRyB69eEqVJB41XNZ+xXUJhVz0hlZ7g4uad7nrK+SJMBhnKsaVAbnUw8NFJ8SwuBSYb9HXur6JaHk2+2y4yVrSeMnQeHke'
                . 'Vzmgp+sHlIEbUxpCpSK0GtdIqDXaRjWh0ZK0+t881XUedxbhclWzXPm0UFlPf7LXu5d6+tZSbym+7rr3uoZaFfUXZ+KaUadT'
                . 'M5JJ+ItjXgL3Si4nmoEAdkwoHz0+Wr5lBxBAc9JlrwSPvNOk4ab++fPwn3+5KWfT6qpQaJ3X0xrwV/kD+36zwWvw16vinfIK'
                . 'Jyy+AB4IeN2Cd6ZWLJK4xGJUIhWjIAboBqnk/0ZBQWu9v6BOr8XKXTAQgerdoqfzObtbynvrUghS3aqbrEXr3o2pPw5WARG0'
                . 'zdxeVLp7U4TxkgA3wrb6dvOvda9uMPjM3kV5wQWRcHarPWFyLySvw0Tb/JJFtrqy8nJyna11cbhh4mCkSxEpKYEXDFWJNDlm'
                . 'CWRKPLYouby8LGrzlAQyY5akNFzCALwo6UmK7gORhonSUMSG73epRB457cWplYamhJCAXFh6KRSR+UoeBLyYUc6T1HQTXrUi'
                . 'mHrf8Gh8tEIvCrAoyzGk+2n4wflPHrOteqnrfRfhV3iV7djF+Q/ADzwTfgbT1s0Ga2epi8YPH0b/LKUcpXjWezhn9//XaOT/'
                . 'UT8w8B9dJQv9h+gDaj2dChwNuLHDcdDx9OP0T6kbY+CWTe5Z9/WPf65Ok1juE28Vb/OteUhT+NbygUyyLySfWYX8Q4cOu5SQ'
                . '9+t06NtDhiMGm8TBNDx8prQ8l2vk/JoAWZUP7y0lr30pvdE30B/+dx7hjrqeGaYkpmc/eKqwzWz4nVX9ltycCJOFAIo2ExOm'
                . 'PpVf/h95uUK18gigqyoOqZuMrz0vd4lEbjkaj6M/styTuN/3/KbZwFFFniO+LpFcF4t+nvt/fnAemigprg05+13OeOL3q68Z'
                . 'qeQnkfgnifTnDzUdCHs9OqCleIfObUQgFkef7Yzo+dN5sGK1TWoT19TxSM1nnnmXu7CGxgXmP4qokLZZwHTcAnLrG99V3jOT'
                . '0AZrxt1O8+Ase7EOJOZ4vLkoyIt+TcNs7JngT2b9s2vdqT/oMT1nI5c9xKb//P1ibTXL8DmypkhI3zlheSIGn/zH1b0Q0CAd'
                . 'CYrxEpD1xEmRigP4CRFxncqk3rZwiRgoHYIcQeBIwzTYJcGTnrqoWskhl0VphRXrVJElWjctvJbQM+kSoRyJTGDEhEXwFC5J'
                . 'O+nZRvfKGZ4FgidWVmj9pUQ/WRVpFUZHzTzUpy28TBCsqZUdEMc9KCpHBm7RL7Ge9EylWaWG1oAk1su4XJRFniUEhHXK6rMW'
                . 'XmdGziekLUktd5CVaZ12mCrzuc2TLYUUpTjnDrZ/JEMol1paB1owoloMDoWufV2PG+8WX2UEpeg0uaaGew0/allC8MzOS700'
                . 'iaMw8E1dcmWX0VjJyph345+MQZDu/b348RxWDhViKhPJggiGh3hIMU+XdDjp+c4YKz3Ev98H3qc9QhiMmPvMtBB+lG4HR0Vd'
                . '5tMVh1fTCxhXDoKnNo7oceRYr6H3CiZLDZJblpZtOz9ChGQGNFFHeyDLGpGgh+l+IM37YRkvDIIYq/UHrDNqt0KW6dFxEnO1'
                . 'sSH7ZZVLX8H8pf1Z6hrAdh3WUW0ZWqAHYIzR2jsv+QFCXekzZep2wCoDkp3i1wBDAMwwSLxwSGPbVCTOx2u80qajtx+9fvry'
                . 'LkRmItSO9OgWZyZ4DAcOlIwTygWC31CuA9ZdDzqkgU2NN1h889UFeHxZNm2dJb4rmBTwo/wFA6oLluK1uvOalw2ksd1GJQyK'
                . 'J9wlrLvVFBO4YEQH9uCE6RJ9B9CrhfUq9HVVkUGC+ImUoK3aOR5bMYdvr3gKIYpGpQLdeaCGWML1hT5iVdTQIdL1H8zFVuYn'
                . 'SBEqw9Rbke2Po+BFdawQdHYYSdM+tFcCE1M/pVR54R3WGzs/lcRXiVPuRCcXzFdsxBTiSLn1536wKeuXviyr/Bg3zrZWPFm9'
                . 'SgvtUrKB+La1KM+IAi0zh4e6FExSOFInpKpsgI9sTbRot94RQoMyzDTcghoT5Grj+ceyaLe8wrO3XAbCVGx0w2skBRVfKWFM'
                . 'rqF40UFHSToTD8LctuMozLBorlYJVDhnX7mLrg8hTr7n6zx6RKLw5qTIQFdhKi3NLnQ2s9wuZa7aldQUD+EFQuZcyRWZiKuD'
                . '985BnZQsixQoElsOTHxXlZiFOefc+EmyElkB8s1IXpzwXhC4gH3yKMOBr1N8Jplt6KkqqGUECOU8zolEiRmUWlO8+cBjT3Ky'
                . 'wJoztu9vMke9xN0sdueEgEIRR8Hvp+zWKpxbyM6sG1O19SuQtBSGd1sL56JcMo4Gl5CKyOq2JaoMQTvTUj2Nt1zj2A3uYIUl'
                . 'T3VhCl5yDhXZiOFVEOqJpVXapWitdvISAp05L0MND7b+Djagk7ueMQ6QGGp+hB8zWgO7jdt4jSJRlVdl/hflKkYyJoULJkz0'
                . 'ASR3PWe/wmTWqOHvZEPxK/7OXXfsj2lstXab98UZIx6+icoiXHSTNg1oC7L+S6oaGl49R6HI4LajcO3e3w2f7wLlNMINAMt4'
                . '3RXGek9iHnyOUyVeXxz1SlQWTaRObc121jtY31ztUAA0V+U4HA7MMDuqFa23LQJbanBcE5fFgYm7npdSGCzX2bhQbH2232mG'
                . '3QGT9l/B8J+m9GpwHQTvPQxnx7N56vRuX0estQZBFY4N4PSUI05cudN6yDd/Ubs2yDBek6PE5PdQEJqMQjxV06D159eGOsoT'
                . '5bNrakubwSgGZGqVDDpHcf+a9d9iYLyml4kNIixJkx2MTVXYcL4chZBWEcou/sJCk0ifBWVRRo1JDbx4Ic0O73ofCFaPdMfX'
                . 'voB6aSF4dmd1dqepnF9OSzpzCUiXTlcsEIT+JQbJZ4AV7glANpIc2SmsokVs8HLwAOXiJjN9p/JQt0wNVkRQsJwSeKJNvM9n'
                . 'lhYIAsQOSVbZDk8ZlBmx5G06iMcyVssiC04hktOk10tRUJg8iBlxbSTaPGskqpWjXhUBhaQBzSGmE0VamUZimp0VETZOkCpY'
                . 'A1vwQQP/RSqabEy6eloH0HJ6kLw5gWS24NkHdNI27KQsdHc6bjrUlowR7WdPdBpoE2t7QhSbF3W4EtWYVFWd5iFtRo+3Cz1X'
                . '+/oHIrx+6cnFSpvajGWJHbNzCRFRssPb+0BQ+zWHzEe0UNipbKEbNg1bM1HlSSUw78GBGcPSmS71doFBH/lKmRcFfC1Bs7IR'
                . 'PLmyK6eShAIsiTMWddGov0Iyhikc6SJ6TwgpyijbEnO7DQuaE10rsQdr3YvpHfexQ/0W8hI0ziBoL3xCPbvzu6DTlPovy86d'
                . 'OpU2HsF9ed0bihSpPFL5s9cNCtEZMO05MeqQB1XbHmzyocqGxIrtcpNsH0irO+gTHyyverAPwif05XRpspDv11hHtAa+HaIx'
                . 'Hk29tN4VH/OWHPOj/iY16icLirLG7gKHmM1dScmGsgjUyrepQz0hqq0B+xNHUVAdrOX1jftqhnqqjzZae1MdX5Is7LrK2zke'
                . '8z58nuG6q5q65QGsyZ6isvwSCc6svtfmpbXu7EToxcW2ZUjBQYGWxjgO5/4tVpYMtT4RDICWO7c5mAftNjlLHhuiE4+LjyJ8'
                . 'W5++L5d3v3UXeZYm3tbfIgn0cGa2QMN8aIBiaYPmTZDoUMj6V0iQ52FomyG0PfiKNAyWKS4m35XoAxPrYvpBffxQdHQ2cFnj'
                . 'GxKesw9lspQKrqS7UBYkNz1ccaiqpRS935gXeR+/bi5uf2mOPuIDcdbu2sSQ4rfdV5+SH1EndphClZkNMaQ4T7KQkCSMC3k8'
                . 'XNuLizFiyG4l/lr9dBOJWzduPrn15PrVSxcPj/PYNWWRRK6tKRq08Qv53qikPMF8nw/BU+FH9kLB2TnDMJaLM+wGkkqCskyQ'
                . 'ficrjxVeyFWbOElL7fU8vQl9U5fFzS/wGxIbvPCxlMAOxmzZCS75tjul8AcLSuJDlIEmT0TrDNHN2WWfKzycQVyvQ3z2sWym'
                . 'oSqSyHPwleTX8jMH+ZZqEZITJK0rz3CVwEqwmF7sk+tQFVni/MwzlirSwRSq+cvdDH8c5zK1A+Z9rjnHa+diKZfNz3C/5hGK'
                . 'StOZAs2JBhUwUYgs5D+7iyPH0lVpmhKPcUmc19y3aBsyseLMwEK0SzaNqF9dh4Zx8zxfPOZZ4NmmporWnnK2qlD9Q2FYse1d'
                . 'gmUEBrGZ8WlbfmbhMfkImEovZH5hRkhb5loiurUk1p49V7yUdQFD0Ndt39K5rco49BzTUCSq8ZoHpqRC21Ub42BQNm8fhdLS'
                . 'Xy7g/aoBH6kJrYv46kBuajAGitso0CWY66qMT99PDuuJqScwja29vScd8I4eF2iRgW9p9MyDAo8BvUOeaOVQztaleoTgwzcC'
                . '7IGXuKKpdyG8QquUF0lQDKBAkap5Jsu0Pm//SjfqxorrG9An2D7rUdHaULSzWmXo4ySW7yHchIbcVJvRIhVDd3Rt5iddJAHx'
                . '20dVrqz13VOL4O3b9rQ7LYs4NPUwQ/JFXgQY+hYMFQ/C5D4uyDi6o8av3hHieFjBVQA58q2WG+CjG0WAg/0LK5SSzL0E5ao0'
                . 'r+gM40BBuRszb702r6iDuY6p6LWGSCumnAXceeqFBdVnviaEc+FrgFqTDMKD6kzonumXFyvofhyvwbFCA9tcb90niDStVM07'
                . '2wWsziJ6jLI0MtcUvrZ1CziKf/hgSBaAWwGO78TPyXcp5l+YL8hDiKyki2fT6RNx8eyHPm4nO8H7/nIn1QnaaLh1NCLv6eYK'
                . '/+zpHABzpQhjjkhLGwSlZQ44bLVUzXMZWufcjE2hSngsE3NOhh+XSi7849PL6nFUYSWTWRYwUR+h7vlBW1B0eW8RIuQKGKgT'
                . '2vx4cGwsOFSMR7GDd/sSJrvPZqmQtqbCuxvL5LD1gWOeN4X2xm7NK3lDoZG5LQB+ttAuEF0KkVrnWKSg+xHWOQ7V/MIJd2om'
                . 'tmApNGaGVG3kQLrXfLwJtbuui4sngZ/hYIWANp/bRT+BW1vOA5fWWjPBeenFzKS13Pwy/gDo2k9XYnqBytlRaup2lhca1TGc'
                . 'FhAi5AtBI1SfPL1s79wj+ORte2n5dX3dd2UeBa5tWO7Jl9haA/lmDXmBB8cly1yQhlvjrdFF+EVu9lokU9F13c0u8ECB4jiX'
                . '0tva8QDF2zylLdxFieN0jYY+lETOjfy5ANXDS01tmQ7192mVDcx3aKNxUuU0E+8eCsWEwcKVeTDUfxcIVhz4RaZYomNYWBUT'
                . 'IGxLbdZz+zh0bfMm8RbvSDfbBFsmGscoVOESiHHycKRQLpakSMP3uGUqYAs0i4ASWk4nXj3XZRKF/qZkCtzy9uw9ZOsG6TD1'
                . 'WIiwz4Zvagd/Y8c63jQdNJxySvb9UShEcwlpdTJsmIPfQfNX9LSOFOLehkxsD3fkqS7JJeJUNq8ZR66pUTOFT0xptLcFoghM'
                . 'pQxHVZmqrLU+2zlVuEpi927pGJuZnnjCm6purfbHu1qn4PaLeRu5uiZWWcuMg3stcwAxXkCbRot8zR7CFLH15mT3n4Yux99/'
                . 'HIhM2IN81God09UnBT2zACfPMwFwQnNOWFoBPlBiJwcoW6YUoi8ckY4n0PeswR6IecJCwX4bpKZXT06SJPJXT0fWNOCRqxAT'
                . '8n7j5jeBvC352WsbmAq1tBq8EmxSqa+WYestG46ciy6p4KkaBI/U9GLOatvwxw1Josnt1Jlm/cs3HuYAndgvE2l1Zqf5vpmq'
                . 'VrfThgOaQwRSNLFbMAx63FNZGgVWYzfW+H9b6zMDK0SNxOXV4DK469lkWaMVPhr8ZNwGyHhflR7EJbWRTBgZeTjjfGqb2jOl'
                . 'z4mVfqyl2kWzIV+iXSwH/uU2uKnGPnHlalpqXat4UWLWnwDB6GYCeHqYjvMRXOHqsitIBke8NjWpU5yUkEgIInKvm0fMGdGV'
                . 'TjlOJeSCpf68ahdgs3ILrwAVqtRTX47XbfuvWnHSecJlvwchDuGbsJhU2GOLXmHEiyel0l7p0wUo4aMz73GWNCnpn7ewgqhh'
                . 'ZHkVgD6b0GbSVkokwe/blFqoh0bucL0g9oCCeSNqLwAT7OLw79LQZ0oNB16wf1hVEPot2H1z3jXWRa7ViVsN19fodvNgM64A'
                . 'ys8tLBTNAQgOa6oBUEt2qmtSb/DGXwOGldReahmpy8GIS0j2yewCU7BK7qhUwx03Y2Z9fzXV61jBTkM2YqKD92ahuV39uX3s'
                . 'UsIxdZg48SovDyOgSLm1+IRR2dCkqp07vNEMFGaEvqbwAVgoefEkICd//5q2Bk9LkabGwEXPFz5+Wg3hRp+mcQLdQvS9x8cR'
                . 'n33srux/b4kYODNGQVWKlUHyNCgoXSf2nJWNYYtR64XW2gzHLN0B5UcZynjGWKfodZQbueUJC4DEdD5viXKaKuWtcCPbf+Ah'
                . '+nQsM70lH60dGwMHsDlqaknTozFaikAVAhPQjQKOsbVUfovrLmX0x87xJWNu2Pv4Yh3LNDRF4oPgeJbNAzp3LuUJHR0FqnB6'
                . 'My8T7+PLvGd3YRM1lsHpKTzKBTku8g4lQAjG82kWi3UWOpkd/mrmjpf5pYswQPDGVbO0S54GdVhLAlrOu4Yy3RfkKBWimMOm'
                . 'Y8GbzaNzILsBcbFArpq4Rcu3EmavgPsUdzlf4IacqWjLNxeY6Z57FlC7QbU/lNl9FbULiIOahJg3L+RmR2nLDa5RhboS4crM'
                . 'ZdfSfkRlsKthcu03PNDyN5ffjXvHihF7XNj2y7sBdbEUF2e4Rd0lWHINvGI795GX84gbC5LtvAr6CwMWI61V/Aak40a3libN'
                . '+BYYadoXfwHd5dv6WTUGG+aVAiczqiX/Wus86eWm5zb6TV51vXL9NeoiVSrSb3PumZq8Kl1Nf2zOS3te5sn3Uew7Qhwv/oP8'
                . '/7/1Xfvx3D/aykeokogfuFVTUmhzhaNAE/tl/dZMg0RIDCNrlvZssn73Qi3Zjei2oEIF88NCnjGC49a1mmAcx0UBxM/b53Eo'
                . 'osB1LIOLiuWiHE/vSt/fDzK+ZlwSVjqnDhs6Ubv4Sa0GrqB2eWc3IkKcTy/V3K7SJAGXGHPRPe6nGP1B59FvRncMH6sq2uJh'
                . 'hgvtEkx70jOrxNKGiv/lhWmydGkqRKEgfS79CU3xFoNSLdDyd3jgO/+hRFHOjyiVfp5ddYLmah4hCmJaKfO83P+SoefBkZuv'
                . 'q5vRPQYbPSaf6zxQ0OqC4/XUdQg+fptO7Xfh5LDfLdv13I3dOPQiDo+hx4nPdxmg8NJU+BEoEUJGQpV75wSV9ak3LjcQnUez'
                . 'VMQCZRz9rr+OEdg3jtHcq7A95ysWhKIcjxRLIk+YcBn0tMzLi/6iKKpwe7DgkEkiwmKJmZZCCRvl1SgMwq/d2kvNU1WEQzTM'
                . 'KN39Hs2iaBCxRfNSKOLHLKMYfhDAz83oQZguJaY0Yy6apJonGmmaAM0EiLB8Wp+ZwjKoDXhIWhMyMbl79DgwuzT2F8WyC0wA'
                . 'GsiRd7YzUy5STPaOZLtZqUQGb5y5lU/wY3MrtdONIlTz6HkTM8coBQgzW/kq9MO4mF03Nr/rGSoIHtLoLDt+SVUJ5liHnEKn'
                . 'YvRylTrUD24E84pxQ43xuwKG+GHauAVLLLdqgyRjI7uAFBeNj8BCW8SBvkb5s5Kpe0yUTTMpuX2O8ffMwGKdaQ/z/CFLLJNv'
                . 'bcumlLDXDV5M93zBLdppirlR62XATB1KgvZNC2u6KaXEDwAZhlMCIvGrQt8cmROFYIyeNMTkWBrtIr6SkKzg1Sh4IYCjhyqx'
                . 'wz5hbu4OqOmECf8KbdYIKfxcJ/wadXESe6cD+RzIjax/V1NAGpaUeon4+v9syJvtl11wKfTx4Q2j2khAnD5zXR+00t5eVpUl'
                . 'IUjR8QTXfA9l/BLoBcAR+IhAE073u8xgKw+pCIC4orzsysWiaJWoRIOE8a+U6SWSS3zXJ7UT9A3lgvsoMlcPIG7j+B1NyijR'
                . 'vaUkeBKQ30osiifiwqsUiQNRnpnsSNIGcNaTs6lrnHU/H5fdEYq7YYFAB/Rf49pOqxVwC9uYJbi6kRHjmHG3r0hvGDmsIB2k'
                . 'eaRXEHIZNT0nmRDXjzLLTet2oyDKTHx7JO4zRd38W4tEIO9lrY3PoMIUOLGFIOE3TBwQ0CEs3iJaBzsp7ecDq5NlFlBDMN6P'
                . 'lnPn1s0Xt15chCZnGuoqSzzHZHE+fpDfTaW1pAYR9tFrElompZ0sYWNiGpEeiJKC+gPfUyDJnINyvFa9hQJ8j9TM9x+HLo48'
                . 'JZx4mjv+f3lC0GCCmYAJbUwc+FgRSwtqvtLPAmr70gR2MWFIkLCACcdEuGfi+hndXubO7Vs3t7d9W+aACvQWCBUOr8Vb0DGL'
                . 'IZu5FLN0QFAXnagGVpzYUozJpqVOsbxMVx594YhmvUw/fn/l0tm3/TKP1b36/3CulXqJbWHL7bK1P5V0M+l2owcG59yKZNm5'
                . 'kJRZ2luE3YokXz1HkU+b/8ZVvwxL1EWgo1rdL9YnhXIY6Rmwh8TV+5kkb3B678zMJvn/qwh37L9+vnD6xuE3zgbsibU+Arc6'
                . 'eTkXWC7Nyza1XhNsaYUKO/PMct08rzMni/dN8NLeRpTSfURYfyYU1ANQsQcQmytCbwAaNBeFQ98H16TIj6Y4TUlh1EdLE1FC'
                . '7a5J/HhG/gCQhE4ZRXiRgQ4xyi3N7DKjbwnx81FxxSH5XoNK+eSxFBX00xuBZrCOEB6xQBQzKIEdCrP/IdlxANzitpGDgkQD'
                . 'V1Ao7BUGGTvM+UchoIwNyoIpQxpJ2yBGefLlTVB+6Qc83E51KWuyWqY5gN5b4WmO8IVJALtMXBAEI9hsvkeT4ebZuayXhWwR'
                . 'L2QbuZaikA18eVyR69mFIu8sIqwxN2SFflYJr/mxK+obUKbs002qevAu0wXoN6ht1Y1c/ReujvMOEFHUidReNIm5/VYNHgAD'
                . 'O3nzaDLycDaevMba0n9hLTr2XVfT7gLcT6zvDvXuxVrj/cEscynx4HRvmwJcQYUarCSLbLr6RimVGU0YxAIqsQcgpNfYKA+k'
                . 'XCFY05Hf2MjWrtO7YQabzlS3i5CMom5dKteynew8SNYhomhEE05aZ+sOgCOOhzzR0qjAwE5yoUVm+8MA+4hdzwJi1mE/dn1K'
                . '2l0Lvd8Nh+27EgDz02JloREONy2LQmBfxc6tYURFBVNxqyIQklRbEOeBqIoQATk5tqn4qi+kNpyIvQidi5VGSFSSVjeMAoJx'
                . 'mH60cJO4bLcl73iS2SI24w+VFJzi/jl4OxbFkxRm81APaIk8xS+VXryWajXzjmNX87wp9S+B4fwfMKiCdjed72xZp7GdEOco'
                . 'UW6UmldbiuMDWf7+TFPp6wgO4wpM/2OAD1aA3CtFhZmI2Yg1EYLlwT9+VxLnw7l0eX/TBZDFth3pno9UPVGVnfzd56yH6v0Y'
                . '40QAxMiTJ7eQP9v/P1+beQXA/W+aoQB0LW/a3zP6/ncLdz6AiwIAAn/BN+VGIhUUtgBi/rX1p74CfcE5pzh+RsaTWuZsyQPw'
                . 'gUiu+S+XD9sUSk6FLFCq84qojnaEfaXGIsiq6+nG3+I99fwOniKXwztS6nLnj++ZrOHNB/23JQMkR4aDxzOut5EiIznmwvy7'
                . 'smC9PWzHB67ZyzvzyEZfzbFCM1Rg2oSNlhgn+Ge05BTWcTWhhsBolCS6vRxhw2oEhwzJHS5Msp8iC8iGup5u5Svj2Gr/F9Nz'
                . 'LUaSfcY5xgix8U3ESRWbP1oY+Df1CdxlMJn8+AOrVWy955ZJkmsx23iIjVLqaKsb65ExLHac0w9GEDtYpr5zyQsO4wlWgrPV'
                . 'XIF1ZFim8amavoEW0RmLgVXcM12WW0yVGF4ZIIT+lb4GclRM5HUfcpqjqz3aCY6VRW4Bbc97z2RxqNF8eF73PpxrgehaLzo6'
                . 'bAcPorwnGvockwF4Eb1CzGnBSec5gmSVBAllJiE6W24LLz7AlgTxfzX1IDcMNPWR49DhVl9513OSFNAkQ6ElkRELdLi31vDB'
                . 'Ntdawmybcx2W/Jqpy058HZBQude/0gjzl8J5HqkmHx1fg7fKhdnwFhm3+OQm//pJkrUUw5dPbWc7FBzrlsCabOWIupIS3rfc'
                . 'EIo8Ik8KX1dM1XNu2HAjcqvLKOWK2yyX/J4eS1NnRGUqdUxpUqLvUAgExAMMAeYAHYARQA/QpwSwdJ7M+e7h/zioUoHVxK5S'
                . 'GOkmQQB/lOEBAMpBusmANbPEAACArwyDTBAA2F869AxC7tUZlFTnGQxfy5wc9hlaImSdwWVDoUi3AaBCsz17g/58ufpkoFiT'
                . 'ykRpHhWXLV6pht+69ZqUc5UggkqoUmkqlWpWp1AttSb1nKlUKpeopFVEk6hSX1+P5c6FGzce/LAKlbJb8RbNRqkwSp0ZYslp'
                . 'uJR+br4n+ygeLwH3eTg/1ThpoFyLmppo4i7HJkE2acoqYXGswSDmWYLloXrAHSsFuNSuJL5UqlgUi4oQDUWF+UT/tKjBE+Uq'
                . 'mfEWRZtS3JVdy1WR0hhMCFosrpKr1ufbo6cUqa/UQ/7B+wOwca4oziiySbHNprFlp0Qfr8mVOuu8C+w5cOTkoksuu6JL+Mq7'
                . 'K+PhqmvK3TDdFlt5esdLpPDlu+mWCrf58OXH3xsBlPFLV6VOrWXCRagXqVuUhocRbJQUTKjcUTbdN6AYtMgXvj0TDZRkkCGG'
                . 'Gmy5YbZJ9l6KVGnGS5dhuFZtRkgaviI53tov98nWpQYLeFFB9GsUfn4bz4qSumFattvDk0yh0ugMJovN4fL4KDjoq2++lwWm'
                . 'eg87lmNFlzZzFjbgWMnKCieMJcGlJ4hOWdtuh1AiYnkKBAp20ikB08+8PdbrcNgRWoSsCYwzxkQTTDJSvldGOYRXNkabU7ak'
                . 'PvhoL5al3mYqtEpI2eFXn5GP/TiM4ziNsynCTHZXl3s63dTVQlfLUNtYG6lTrZiZV5kIFben29UymnLJLxldnaFiVNIXMgFt'
                . 'zV3F/X1tdIwsHYmSd0+gnkp6KQV/vb1zbJOThjuX0VdwYBF9owYWndTX2ccOD6qbAK0ACoMmFBpSKAANGTRhUCg0DBeNUp9W'
                . '67aWNfpdUC6WYJ5LW/53D4bSLem2tEVv3T+hZG0Bbtqhh9Mj6eb00KR/P1FP/uueQqdPr9cZj8PKB4S0FtI3TZTb9pG9LaTm'
                . '1tGR5BojnT1Z/waXNCzo6O/vbm7p3yg1P9s/8rYOEDwPtiD2Ac6uz9hGyJTXJM+StuzZbeCt/gaYDzg0l/zpsFar95ARBudx'
                . 'iV1Mb0sLV/8THn14dI9iiEYk0RFcAGv+BgPKmzYz7pXfGnySYaASSd61lw8nA8ptZtsOXtp+fisyoaezeG8S8hdlj1NsYnBj'
                . 'pA6/2DP6007+nDXztlbJDcq1AAA=',
            'family' => 'Be Vietnam Pro',
            'style' => 'normal',
            'weight' => '300',
            'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
        ],
        'be-vietnam-pro-vietnamese-400.woff2' => [
            'b64' => 'd09GMgABAAAAAC0MABEAAAAAmMwAACyqAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGkAbjyIcKAZgAIMECGAJnAwRCAqB6QyB'
                . '1DoLgWoAATYCJAODRAQgBYRYB4h6DIE9G2iMJXjzYAXOA0SK+HxU0UgdNg7gyTMXRVGuSZ8c/P8JCWrIGPzsHjZnZaawQZW7'
                . 'UndHd8aEo5KNk8NmVqp2s0jVcZZPwnKjzEQZRWIA9BUzvT9nMEYmk1npy7la18H363bqX3I0EskAHLz72sp+xN9M9UetqKN3'
                . 'BZtMwJM8diM6EGu85REa+yT35/lt/jn3PkAeJmIgKDmHCYLBBBHaqE2ijIh16TLcr+1H+7sSv09V+xN2ZgNAKgGQCFO2LoXI'
                . '666KfOtEQpWf64TG6QiyYZvdu28VYRAOoXB4NN03Y6tQGI9QSIwjqFLasLXkjPc20dCB8zR86vFNea9lqhTZALdz90ldhaxT'
                . 'DcYjUBZn8BoJByGt5RwHH8u5li2R8BVq06AQOI+guCBKs+aDCLwwIwZ/pcW4N4vTOfD3h5Ow0t/+BfEHIWwWtfYtj6ki0YCi'
                . 'Jh1rH0+PF47azNkYgSXkxCGiiMekmYj4vWr27PddK013MiGlmxH3DtqickhLSEF64+mhKempGiyc0Wa/J2mLcDiPR0iMxZx1'
                . 'I8INWwz2FHXT5PcDEEHnUh3CBmj0eBx08z/SPodCEItAElRLhQ58s2EbrHf233Lam/sK+qwDhzBuof8bemqxuPR9EUKVKnQT'
                . 'QufvdVal739ZtpeIcre9GO4ecHQpkfz8LflLsrot9cy67QYeIMMQWGB3891oPcuE4dSFFwFzfFF0aYzRBWlE8P3/oencee+n'
                . 'UoIUxdYoFCuNcwJYGsIChZdXZf93rz87t2MIg7FLKA5O5M+xHGlukrbpm6Q7j/n6kdNVVRbFwQrsnBphEP4LWfmhgrXVlx3a'
                . 'pUQr/61qQzSJVfGEl8ZHUoSIP0a2WO4fhKRod7+ffn+xxBRS9risIoyAMMKgxnjfeb+lLbJZwAU4f7HVJkeLdC2aJvB2ExCR'
                . '6OV71AgK2SGnQWjMDAoRAOkA4pFOToO/QTMQAEujBVn40eCFxO2fs+OQiAEAsRi60lzZ+jU9Dkorz0G6M9JXIArkZAhp8J8y'
                . 'je4uaxUWVzLEQigoCFnE4yqUSy6LSCK0rphD18zXkfPzeWSmJADqKLLQt+T9DHAAMF9hXsLcBeYiMOc+jUwCKCVF+luIgQb+'
                . 'AQTMkedgvoriYL3h+SO1v3gD1fksz6ihR+FR7pO3PQsggtMTV7M4kQjblyuY/gtP0N/pj/Rr+il9n75JX7Y9pmdZ9PE4Hkzv'
                . 'prfSG+hV9DKdLtAT9BCdozuwJ9ImFn2swTRGMY1+dGbhJxkn9dJ/aROtoXZqpnZgOdV5PkDtOZGHmVRI4ymXgi6Rvzu/ku/J'
                . 'l5HsY38X8Dpe9KdXs0dFup9FH3emN5PryBXkEnLG9kodY5FwAHvSbWQDWUUmybBOe0k7CZIVpAF7IrlZ9GHtVJJSUgwNlC5F'
                . 'T4evJROWMIAI//enn33rcx9626vkz6M/Cf5wJL3X7RZd44LOLroMI5sg61BRphXXBYdfUplo67hSEEojK3mryFXY8k1hcpNT'
                . 'HtC3CYcpQTGA6ItC8Zr7aqahez4NDWvug8rNtB7IbGZj/VbRuWvp3fVyw4X353QpaLomdcsc2TkX6JC7PnfR6/ZvgMb57MCy'
                . 'JmLFrpYHWkZQvjQnYeyVSFGji3TvGvp3HT+cU4eeEbYq25rvK5/ynt8KZPEQW/Y2+p+ALcGhZL8X9dXYMh9xcu/U5pI7XyLr'
                . '9idtLnnrfhA2B33kDqeCoi+lc8p23gaLl9XiujKfIpMLzxuue/5ecZnlOyzaChSy79fm4aIpBYeHX2LZK7fMl2o2Zj42vTq2'
                . 'v4aiWbYdX27nOigH5vsi26BVyjaRb95KvtrR5FvSytvLp7xHt97KEnvD5fvBOmDX5tMY2YgNNnY8IHQzoHyjA5C/ZRPlPB4A'
                . 'gGZTuBmaAkJZWnYM3xaXJpv6bxUAEYfEm2UkRDEAN+WXgPXSFXvzfsJ1/iH9bdexFu89uSRLP39HzhTwJD7gCH5ifkdC/Clq'
                . 'jmJKXRCc+hZvsRjpPZ3TkpiZq3Vy2ARzo8bMenpuQ9SlSLGd8HOpHkAzn3pRg1YgWCgJI4UEIaVB5SnBpVcmTrl6qEETnmYR'
                . 'KKoXR59BfEQylIRKloxIkYlDTIKBJITkX3oBHYESeO5Q+XhOI+gYweI2PO16Mu+lNM8Ux/g6DFhHCOd0QE00O/UGuXJFIQF5'
                . 'tybw4Ew2OxQHkMMJBlDfvnRcTx+WXg4oB80ACPw7mb8geBDa2T/0y/9/AMwtPgDOBABkOp8OTXMrQjUR1tjWmAXwxqnJMRgJ'
                . '5Gwu6SGkmCLPkEstLlKG8qnq66+7O4crpO+bYDKNQZe4cYEWGvcjXPJlRtX9f8+czUvXf4N/Y+ZaToIPHgLC7iiLVA45JbU4'
                . 'rXw+cQCqXDIHiSYleqn4FWg/PPxfViKbJHZ8ZgIW8aolsGJVYVTgWoanUhwTDqN0tTLUyVQvhVMqNxGPNDWEXLI0Emug4KXk'
                . 'I9Usx3IyK8i1ytZCLSBXyFJhGhFLBOWJytemUIdiXbS66fQo0smgn16fUgPKDCp5l0Arxu42yesIg1hVfn+/eympkSDDr0j9'
                . '3LddmXuvWPw3qsgsrFpB/PcsEOA+DKPI22NMX7/Fbr8HPthA85zm0vqeYMv/o4xKb6bbxlrgGVAHHZwweQcCeZw7CNSqdVBI'
                . 'syeLpxZ8n2NKSr7wYt+XFwcI8/OuEO8h7OcGvdrtuFBg8lz0L9jO4m6xtNi9ILMi9d2xW4jckQJXYSd2vSuMDEwRDaQxsHQL'
                . '6cmez9VIIzx48qzZ9+4VN8XhbIjtBwu1OP0RjopNG1Rarv7i1m5iFEw3PkcprCs3vEJJoRSjOlWnAA8WyFzauTQDlqeFKpdR'
                . 'WQEth/JsivHkfiBGPGfgJoDThls/ZYiel+ac5uMDmUne6nGQPMYmRyiMpCcQ30B6qo8ODmQoMJYbgcdJwL0nem+sjVB0cMMu'
                . 'MYSpxXMvvXDaupkGtiTaZRzOCBX7+lBa+e+iC0U3ZDDZVkgTk6cxaLRRsN4GKBh8kjbdmYMH+Ox1UJhviCvke68CjH1kOJ9D'
                . 'vSxeqTk+xOnCRM6OVfLKgXPfxNjXSoPynlh0/Gnh0N+2CQQCe0Lp8WAPjUEBopZk2D6fpAm6CRszI3kdG1gDl0UsqA/GGk2C'
                . 'ngwvXBO4VpCIE3vd9tpJGdifBGvUxtvWkYRCCvvcTANHMsAoQhLfaIpPpMNc5EBIbkiyBJkUOIng3KscM9Jrn08cMknbqUPa'
                . 'BL1XId5+DkW/BvEqCX3dXUBq71Z4++mSMbYKqTsXocB8/0vqyJRCyXMYcq8ENl+WV3IwP7SsArXJPoaxFDXf/q/jdx1UrJ7L'
                . 'It3IAcd1gk+OLDkuEsaXDrPmZS4fsEDudQARNDt07va6pWGhoLD/OXDkvQxZeE/rodFLOjpdXZS5hRML3nskQPSooVRORgPz'
                . 'YeNKdKSjwFLPkWFTGJVpIrGpssxIYo6k5irbPOU4TjLz1wXQOHzF4tvPQaUC3xdNt3wW9qr2+d1ZVH0pf35MjgWe4O8RSgeX'
                . 'PrvyEwWKEkWFokZZgpKLshRFg5KHko9i9xRD6sQFbXHiNEpf50gNnq3JW9SdTwe0AW0lbV0CkbGHqHZrXtMV3rukalAAPTt3'
                . 'l8XwPvHuiJJK4bilYbgbvdUNYfIu21MKeeRBWxkW2Aj7pnNQcMm9+35heR8EAHrPdK2UuiWfr5RySC1jP7KA+RvIUYHqTO0w'
                . 'DumCODubfxkKkeVrUqUc8Itm8uv2/0OvpmYJ22bzfQzed584t3TytqMSwDIZJaP2neh/76WRLoW9nfYtL1K3xp8opmapYPvh'
                . 'eq+UEzq3lvu1Xoc91VU1e0y2uh79hjuNpbQqxSYdNV9j3qpp02O7060wTI3CYW5yfJrJhtW04TmqtmSVqwfOdJTXjMJyBBss'
                . 'jR1lkkpVxjXAk+2CY1k79s6hmTXUODXJDbXCZH90mDHhspzAyVhW67WdUJUFK5caAY6O0TQsMolJMcSlxd7uQiW1WqNcVHEv'
                . 'zfTMmLlDXh9h2Gy6kj4ZNdWnvlH9xCjIXBrHey3Oa3mus1ZoHNGU9L0PDBMzjUHqWBWdW+yFGtoOPzWTke0QnhUvtANUhpEL'
                . 'ncAUWnlHqtXZfjKkCC4rdJfgsmCZpnoWgWymaSwEeJ9TpZZK18yUulHYQfNDa53afxVjMqNvuJ2du9Y1Y2kzwUZ3wdMO1R46'
                . 'vMaV+gSrtY+uhYLU6kv5b3ZY6KkT63B55cDUTa5HATfNUMFdDWDZb2i8O1geqlQyap9PqJlNSOwMf8yKmtHhAHCnsLQYtbyB'
                . 'wAqjWlsAHrxG+RoI+I2XgcJbAMAhBAMVaiIQDlSkDYBEA9XWRKA9cN5hGpc7+4W6Ggh0G9XTAqih16i+BgL9xsWAezhjsF9q'
                . 'qInAcOBihPUZo4EaayIwHniewIkC9ZlcTAqXWEtOYZtPB39QYD/NoKCsZgvGyoJatYIGOKuzwZpszNey4YN12WD98FFjQ0Ft'
                . 'XEHzwaZssjmbbMkmW0dM2wpqe8HZDqi22JktdmWL3dliz4hpb0HNFTzM419wwP07ttExhYH9oNbYGH2PuD6yO9l9a39xqIDw'
                . '/yR5acz65UsAzgepj8g6AV//lQyWzQEAQVFMDe1LjVkHeUa64DV73Ni1uF+8pxhe+5LMg7DMtukiIXxXbdcqa25R1QVBgwQJ'
                . 'iL21h27P+3YxSJMycomUJG4iuBgCYs6isuQWLpBSmO46E4c5IWWxdDonX9xLnfVVuu3Zie6uvr8ExFllmxuJwgg1ukiQgIjP'
                . 'U0b+j2SqNZVtbiKKz7kdiRUbM5nfKtvcxAVSCtOdO7EZjcY5znvPRa9C1Z9xuVRagxgeGr8ECQimagojgiHWH++NCMLr/QHB'
                . 'mp/f59zT9190BgVbT5++SzB/z9mzs/M5tXDvk2sUxxrsX2XfH1zu3P249QHB/H0w37veL/B5wf8Q49azGct/1tZr2LmrTkHe'
                . 'euMzgvmX4EHjAtDTMnf3NQ/wBQ6ff2DKXVdfLZjfPxu2XHKvNwCp3noaH+v3dm7L+g7o9Do7Jem9aVSitp7AAxDYAx4ZAzlG'
                . 'WRRQwfxrX1Xw6pVshAXB/BtVuPX1FnaKYbHOSYB4+FGw/ldiLxp4X8obPozqhnOBwCZKROhH9WAsRALGiLSAATcLmCAPr517'
                . '/asKt75xW1S8/BFpmZv3wIaO64cJQvXQ7DDTA54mS51ARFhyvbtCAJ8vxuaRjCAKHgnUOaxuuyUN5W+IHGOiUR6MqJM9Y43E'
                . 'NFIB8ZoFodpHzQGbvCNBeIStt2X0RZbtVR8paE84iMd1Dw4Idh+uAYwwf16rlHwC7ReEIoJe4ltjD7gfjb2hozfyrpG3b865'
                . 'gotf6Z1/7T6xAAUMi+ype19lydPvmyNI7duptiIeSdhq4+uMIHqc4ekYFGe+owDjw5d7rX6T6eJT7ehQYx707JGOThjuGDdC'
                . 'cg4pW+eYhwE8JV3SjO+jtUN7WDxfgALkdXT4B3Sw3Gtktn3b9sxZQPEcfO8zNybQyYXFF18NJ1z2+tP3OnYME7TNNTM94CtL'
                . 'ncArS653VwjA/ugyJrCJMAHy7nDMX/Sz90Y7sr+xjuyo7+qEqq3qK/v5Verp0NFfEEog6uHggLPQRZ0wcK5b1xTB4WdirvEY'
                . 'VlJ6H3mxi9eosIcmz5Mgqs72xgaKdMu9mJrX3+oPTFkvtb5S166Tp3Ps3me9M0TW48f360ZXxlmWyNXHg0JWS1eB5249/nA1'
                . 'Qn4X3kMFAV15+HM8hMtXIG69LY2+YWg/Vj4s+uqrPo8Fa/qCJ0kaBnSOxLsCWRj51F1pO2o2qTp255NFL9xSdPPzRc8uPv2m'
                . '57yYAF3H/bFeIC0ZEKylLR2PgFdiDJOfs4j5ZD3nk8GqxcqqxUjc/5uGC8XbS1vAd9ydrfpK5nWMGVSrvVf8lRnw/Hlk8or/'
                . 'J0kyfyW+N0OlVZqrE29/CzBEB9oH6+cf63MVkLqzJC6p1CWRuMKi82/139rtu6XwGk3Xpn9PUdEnL8vZmdtef12a6M4QyVu5'
                . 'dvFYQm7Z47nSlqMl2fLLdP4tDUkcX0scV88kpnwoscq+XqWStF41k5D8g9Qq+6a//+7JrcUGqtTFAC4/WbrmyPLvJP31kbpI'
                . '0nbdANet8b/oeuJhuhyQHV2UX7dF1W17ztGrkZEPrwEJeDgY5ri7eP4M5n/ARRNxa06jRRbapztA0jfsc/cGe90/hrZbAkMF'
                . '210ujSzy0oUSXAs/zrFOrbVF1ldWhggRvoht7seFWEHx/wyx53Wfvu6f/drtd/+51h9mP9HJmD/72OnJ9uztYQepOeuTbkec'
                . 'rMHplNgQ7Fp+1VGl2CPstHK18BeMI1N6igDctYoNJjsi0x4xDXnGx+FPuz/VGbzlPO4tRRwVulRcFv+LoSL+drT78AtLPrk7'
                . 'ZmmZW5zBndbQaNFuj6doV2jEaw2Kq0eKdlXtDo2mDoSrO7TW5YoBY4Wi3yJsry6G+yuMq1p2flqTHqnUbLEYsvFmyp67+Y+W'
                . 'eLui55aU4m808eGc1F9pGF7mEX5+YqIF/ly4rcp4pSJk95Sk2sYVNDj+FllcmmeOmHVPEk9Z7ZVhjePLpK3v5AyI++UXYENr'
                . 'bCSHdq4242gpuTOAvFI9jvumjFeKZusMVJhTDOakrjgZwJ1x8fRfzfADG+3Odd53u99tDA+0teuWxlmzd4GW5vCbAdVQ+q5T'
                . 'w1cq4IQOU61+u/eZCrMNKQuj9mfNJJZD/vlflDRP6vZp8GN8Ou2WhHBIt1frkF+d9vZxSB6pgJLdL92THPJULu4ytpc46iKu'
                . 'ART6VHdLeeTdkSfXPLFmxFp4hbvdnEH4SElAorip+t6uK1Jp/i5oac1XQhFJh/F9aLnFqPL/4mZ3VPe89N1ioPKjOoWi7qNK'
                . 'oPhd6fMuq94hO3MZ19sh7PByLzvryNGbQ8WmpuwugyG7y9QUKq5vKfMYlYESnTLoMZaVuY3KoK5EGXAbD6f/kjnIG+Qc5cxd'
                . '6bi5+PpoJHC1MJX3GwOD7Abv7g3q2DcIjoZP5cHMWzK/afCP1MNjqzPvHbXCU2qD3Xbd5W1rrlj+veavH64bhuM925NfvvDe'
                . 'wYq7q+rXP/D6BxX/0WojSv8hZVCZqXMQW4ckSKtuzg2Hxpjx0WKdKOAPaTdDHj8w3zDf2dEZaVAN0GZFi7p3GWzs3X++S9R/'
                . 'qKnf1l5sbs7uNOilXebmaLHVOVBlWhuVbi5U1zkKtXn5r1wDt44cFjYqX6lWpC61fqCqbm4ZOhElKQ8o84EofpM3PfguQbm6'
                . 'qD62+Bo5+Y6U+NqMRzKIaUDztTkWNZiPrGpTRigcoZf2GFJrWz+AQBi6p7z+IPhvlUQlzlJJsgxZYkPNKmGHUHtMkB4Yyq2e'
                . '5vCd2rLnBu3rOT64GftKzvs5kKp+0DfTZNro9pg22Bafh+CCx23amMq7orymXB0o0av99kJ5WW35BX2JOhARPN9OfsZ6zKUF'
                . '3k0efu79xOflmHr0vjcoz81E4oOZR8Xprm/D9fHFEod8FFWr1BH/QyrrG8XaYHXrzdizNM/sNRfefvPtRZMWgImusa7vn0Yo'
                . 'gCQ3ulxgFZrxtYquY5wRo6DSO5Yst3DIe/W/9MieIYnj6p7ElA+lVbKvepXT6pmE5CeR85Wvd4b/kO+R39zWFejpaC/drdUd'
                . 'FjfmKTqEHQ3cSOaLgm9b0oM836TcvdrsN/9spm25k+vFf+Dia7L9Bz0EcMa+olAJ8kaE9UmdQP+N/0Q1uLpR9TvcEfO14r+l'
                . '2RYaTZKv9Gyiwe2Y5oqxe906ex8g1N87luLDGb+AUyTVVKc7+ay459S50+d6nxXm9qxr+12GSVn1n2pJNHegtOXfYKglOnIA'
                . 'JGs7vVATni/kHsGr//i/u7ZaTfltBkN+tNVUrfEnjXKHxyxjxqDRY/HcGTRzR5L8ohqN+1qF/Fq3RuOyoLjWO1+wf22/o657'
                . '5xv8pqjZMDHlZlkfxZYTf27lYfrVN/KplC01uprcVqIvp+/fUf3uwyjDy78uVDmnoD8omJT3AB5++U8m6pwBNuHwb6A/0dlr'
                . 'KwrOmDYQ6Afw4BEcKqZ7mE+UaSzSNTZG22jRcjgwdPhC42sbJgkrbQbE9AhcBGVMkDCLtkf1RLnOIXTBLl4AXDAv9P4M+Gdk'
                . 'wIN4wABmQv1r22ZJMkfAu7aRvzyPoENsyhONGDnucT9RPtm9I1ulR//imUQkEpyFAXUsXZOkY1gN4IFOX9vRzWhT3odSTrfc'
                . 'FXnzKdVcCa/lURu3btMqnuOyl0eDhIRteBkZqgNo5oMG8lUa6n98voYxvgLivAkMBevsNEDtAQR4CBPYIfEgE4OUJe2JOFGh'
                . 'OMaO7atAiQTN27zO6/VyPi3Hti6yiJOJTtJKWmWFRqev+43UTJv//+nqhDXWOSI8ZyMgCE0oLxmuuIR8XZujTVw0s2j2dJ/o'
                . 'bQhL6cRq+r9dnbANTCDGDCjwCj9UfZVxBCYqQefLOKJ6oHZGMzdiBODz63gZsXg+81SXWcJDNODBsU1dzSma4gwshnN7pyuW'
                . 'oAMx8AbaNIMpOaWDJUhBwT05hc2ydPB4vF1rPlVPRvJQhgOo1bbRrHmZrK3Q6k52foRDQMKEyfXB9cpEIKiHPbUUU7yFyInS'
                . 'i1U7b8e7ZwJeXYOaFGq08EXblJwiDPYN0nvGlSXk8ONgEJjmRgKSB4Bejgv5V0fyvkDTE91CGtIC3x6v4AJ4ftMlkw0ChvA8'
                . 'mJCLPojGalCvided8E2PqVwggVFFpdpbkWdpEhOBxGc0JOoCG/c7stfdbTIZfwJVaOGwmJ/BnuD1IClhQa3goHRbWrhqzzc8'
                . '5tylIHrQKHC9lyofHKai23PMVEjETfQER9NeK3jOsdhZJBBDzIdgTgfDVVfuck8KFm6t4CJzDzGBOyhP83Qlp6/qeO3QXko4'
                . 'LgAyUeY1QyEdkk1kfVZpO2LRoW8PWGAcKSEQduDO272gHMxkK/aDDDjNUtscM6Re6n7boh7zaFjKIXGygchq1AxhFbOyqrwN'
                . 'IUNyTi69TCfVaTHRA73KW25yIV26wSLYbi23ZDvRcoiI27Ae3t/J/TMBb54ZWMHzkEzYs4qq9GMjZVRoRN6LDxHpAzT2pUVr'
                . '8G8jLytxo+cdJlLHtCPCtrdy6C7bjRWOZdjlT7VR29RVo6zxeJWZ0SHyiO/iEpfZQcPlzY7utE3ou4NzFts9qYuu5lnUcayX'
                . 'PFVqrgtXyy301u6OmRcwcxc1x8j7nQ5LG4AQ5VjfUZG53YII22s5qIatOBrcXy7gXldmZQaC8r2ob59BW6/CGMNqKZPUiZwB'
                . 'wzjmpIxYac9CIrOZG+LGSWw8kENImYkV2w4MWxUMsqit8cMdo6CmTu2rBPnnjD2tk7PQGLHOK5zlU4WnKKOly7BcOrTVSyhH'
                . 'nzCwRwasZx1SSLAMPTeIiRGtN1lvKhm5WFkxbUr+aOLk9Y6oCKI/RWjQNPJq0yRFrCvTankMK6XcQzUhjMHuYkq629fu4chG'
                . 'x5K3eXuiSuSOFNH7yyWPfgt3rahqXicE+U2LjI292QS/EFCau2t/TWPcktaAz0tefnTYt8lQEY5YhdMC38D9gMa9tK+QYhMH'
                . 'j4jbq/JKxEpDTN9BMBtb+yvHxVetg+VJZWIZ0RIvX44bY7BOLuSGLbZPbzdXuld6Q6SkupXSGB+hjrtOges/VPngOLncnuNB'
                . 'hatgvnQUzdftNx+KX0sjQ+OzmI8dK7MGIUmD3kKEKkQrZTCjBgCiZKpiWwDebvKpmEJqZXYmRxyDUaS+xiwlC2hIKwgnsAH5'
                . 'Mb47lEhQv/Qf3K6cIR3timdaAoQYy4QZbSHqlaFqUa8kAkjS2zMNrTYJK7Bka6w+JHsBfKKQsTNFCA1WxObLe1jHjJdgHtHu'
                . 'Yo9L6A5dxzTgwZ+H7QDy0KA5+n2gWCSwgSAumzQ4OVpGJTsFXMEjTj0mCVyE+gJNYolQgb0e3jNtQQPWaT+D3HoiU/Oq/Xqh'
                . 'LO6OvzJdFHdiW1WZJ2ShC+6NyjYp5Izca9aAw2w9MeMFEb0KJGwVM05mUN0wEcWsWpPUgyg/xxEV0h3kRnz6KphGkacRmvFM'
                . 'dNqBK4SUzk10onDG0+2OvhXaoVZLsnCifGDQDPjdgsFAbTxdil5mieAn7RVnopRHwBPpnaeLHS0iSrF69FFVcsZ7Aqepwazc'
                . '+ApYU9LAHcrgnB7BIX5n90ikWc9E5PCiJH57YVahbvupfjwNH/3+ytJ1LEMrOem1sB9Aj8SM/KnkZLsR9ExIV9P/q3NGMUoB'
                . 'vCBoHuSehtI2CkAAhM5fkFAdVEMeIoHXK97gzQdoEjlHUurOxJkTS1pAYPW7uh8hEQiTyDslHRSWK7QHng1wnmvEAik0vTB2'
                . 'bU2j9c/0HJIXcSERvO9uytuGaX9FHzEV8pOCpBTp8nDUcPZRYy6x4BUAvnaewSDGA031LoFtEen0aRwAJMSSiHt7r2/qMk+i'
                . 'd4EWvLCmDsmM25CJV2of6h9vJGOuB+1F1R/zU3N1bxid1Filv9qAI2hwQ3L55jCehDf6iziJyq1OavaJYu7QLIEbNZ6kMTDi'
                . 'r+HzmnwOykGUZ3ea4Zlc7pRl8B2OAfhWm5zcBjUhc2vFsuv7PKP7ILyZl9GmT6kxaxyWp2LVJPiHAXmZ538exXueNw2J2x0S'
                . 'sY0ZZmxtIzs8lgzlIuePlrU9v6GbV4m8bbwSSPhQUhEp+U9IuxivnLi9CmCnfE2vyRzTAvejdZqsulaosnoetWv34RyZPjAb'
                . '1CwH8QND56CPayRrmMoNvgfgs/Ow5VDJrnlO5fV+nyhWy5XtmMZ3kgflEG650KdgxSa5u182m60VZmJVa42uJn0qiBimR9QQ'
                . 'PzUVloUaIDFkboWSR//V//ix1Eu4NTsNmemMlRPNwX3gBHYxmIdyybesowtujLVHrMjK7hSEA5OteqddX4m/DNqzzsyt0Xst'
                . '7xmwIFIXTuAyEI51j66uzF6E8IHZBLQ6gTrhi7SndI6vzrtc+Nr9q6Elc7PdVD4bK3BZ/3i8ysPua4XdVE1K3XZhLG0TeNPp'
                . 'QZO1BfEHDN0ymGNyen4xL9ID39XDbv6+eO91mo31Ov8szigC0CXc8VbadYsh9ThgHCwZ9Q+HZ1Hxad+TusjVP0x5rfA8TU1N'
                . '0+XJaYxdXX0wM5izDgRyu53JudvKOWOJNrEm8NbesD+uA9KAvuGY1pOfTfMkxEJ/bqaX2aWDbMyZGr/nJ0QEHQu0X8Ochk64'
                . 'AJw+pR5hyP95iten/wDmXkNSCnVeHMJaodurpCxG25DvA/fBhBUgfoxmHn+sZI5sDROZWBHVN1/xlEzYxzf75VM+oQ5UpXTm'
                . 'oYzRb3lthyI6cbrM6JucNQG3u7W71NESubvyoc5jELYrCUdTUe8D1UGLFSB+zSbfNGP2kCI2LGupTh3XEo/T7Tl7IaB87Ndh'
                . 'LTLW8c61Udxev8q7JAjVKLV3TalLHbHwdStYccLButAysfbIVVX5XRAOTAYUX6C2oyq0C4bnePO2kyxXnqnwPE2FdcacG8iJ'
                . 'FukD5xhQR9XRs1Vt3nCahfrdxrQ5zbsJ2tOJ1njFagg0N1rOfP2yq63V1ToqfL6WzBM9Se3W3Xu9I6VN1V78wLrs3lJDLkeg'
                . 'KnX1QfdgztoTyMzQ2vc273x3zE66BT6aHgJb4fLpUwDAotWtX9Gghb5v18b1sWvj4WeCX8QF1NEEu+NbcFAjkQphCZBpZDBw'
                . 'L7VyHRas7YWq6KkgGrASFI8H2ZYnDbzAmFYgH8A7bTGXs2B+jnKoxuiGUzCX8JfgsAazkDK8lljXaTCJUf6Yk0pEAoHGNLw5'
                . 'nNJdqiRagiPhjkTAmIhcbsQkvEu75KEqcbaztVIx5pni8kSJqCuwO5zJGdyWeO1Ykt/NaznvRY+R7/13vDI54hrYrx70r+li'
                . 'e2AnCwb2YBXRU5zR8Mq+Dy3WTHOT7iIJIz58R+/+6iqJWICRY8nP/MzUO+DHJtxs8qEZFPQJhFlwm5EIEcOOubO9PIAPCZqc'
                . '5bVLzZWxVovPZIl6MeSuBWewn+Z0qemSpsozHm7eL9WAz1e+Rmj6UyIjIqxilYkIIdTMPjF3ERtfoHaJsMF/7bzJ0EguOHhZ'
                . 'Z4o5DUPqnX0Qu8RdqLR2F77J5TPZwshC4/dkPOx1m/XzMo1tHb1PdzJ78toc3UYKyJAv80xT2iayiQezX5SzKLvYYT3PQjA5'
                . 'sxx6wLKDSy/mBT56Xgc+eIhtrp/GGYATt25Ht+/Ln/uddr26zENfl3xLYvb0tikoC6vMMLAG3pFNisi+OVMElU16y9VtpDFn'
                . 'yLNNqL02vQp2EriIJt8fSuyRB7nT4A44Q2abmo2GfS6V4RGudG2s9yepia38dn2JpE5EVv1K2LhwE4rOo/MQrBgOtDEuHIvR'
                . 'Adh1egav3Xv9Oo9dUxbJEV7wBbEd8mbEWIm+GVDOBsyDMrsPRirBhv3jaCjeQiUTBhtiHStdM6+70QMKleQ9r3gQh0EJOtFm'
                . 'TVzGOTCLQeXMQ5PU/NGQsduYydWGWhxpP2YEi8XCNTLt/OKqq3iPLxxxSUrLkJd5mdkmCELXsliZY2QTwhruNqIRp0XPtGSU'
                . 'tQRTU0rmuZ9+jFadjfnIAjf1UqhSz9MscB6kS2vCBU1+Da5tjV0OqF2KiC+GBXxx7EtgBdMYZs1Krv6V/4ZpXKf3hY6hRWM8'
                . 'ZkdWhWjpRkey+LjGrisnGUQnjQKv0CGwHX5y+TRuAOQjV80NMlLG5jk1K6Ksf+En4joE8HLCKl75Lgz18JWwpGUWZb3qs3G3'
                . 'xalemzWJSEWrDO93MgwbIyWjDB0iRgQvmxCUwlS9hDM+v3oALkIv8zMdHnJ2DGDG1J9p9AZ5GWU4EYWa3GUL3MUvq1zDV6SK'
                . 'NW8229KhKohfRSNHJTOewUFubNSMrKiIs0bnYmpeszHUsfEhGYPJkAYH+WVkXU15UV5ZTqO+QK1Qqvod2DWNzSFWowzSZ5SW'
                . '7GUpYmicKnSOXjyIK6XWD8UuV1mBtBGFefAIBQ62ctSQqdAog14fx2hRqS4Uqm008aivTmwaulyB0l4+uTCyev6grZQWfpdv'
                . '61qWxhzjcnmYeTcI0qrKEA84moPhdwBgRvV1Hi4m1KranWowA08qC7sAzokrPLHvbpEcg6IH07BAuRlvgaB81qNMBwndwNC2'
                . 'JLf0qJTTWyM6FUPbWH2t8AgeLhdPRUY6RpKZd0ww0InDRYlgcZAbzG5Ti5Cn3k72x4SYn7tlJJn+SGDlk6roFJy9AuNos6p3'
                . 'ooxOYY/FATJRJte+Ng7ZoxwWXhUQF7LTsZLA8+rnG+wtl1ufD6cBdKKYlZfujpYams4UMtVJ1YequOLcENseSbDxx9HJUWWw'
                . 'ylyLan85/HWpQQp/XY1a3WjIyJZe67qB5AvUckhEX9mYHB/TSJS0zXsfKAsMrTq5lDmjJPtdG/UVxoJsa1WXWw6wQ9D3rNKR'
                . 'OGf+V7GNwnzLI+GSLvDnFZspx8hqqlKDpdvEm3PiGkUKvaZaSHqwVBlx4Ju5iRuuxaHjc+301YddQ/Z47t3YxHbewDV/MJHy'
                . 'ZyIWYZCf614Tdu71+D2X5le67bC2Rm2oTei+uaFhq0H5lRNMLXT4YVnZ7havBtAhNIFHihPOOIIe96Tc9dYIJQ6sRIE3B4/g'
                . '9trMw6ZEuA6vbQ/3YE+6BPtpSjQcgWGq/u5jESX7wUF1uyeeuAhfXzCfoU0B3BtQy1n7MPVryjBYlPFqyvY+8HwYQsgwWZoI'
                . 'LCD9RM4OLx0gz2JBWtoi9b+nLB3sKeryDotho61XiXHZCLvuzM5aKMKCrlLZD3OS9q5xlbbttvgo0kQwVOOapTHvlluUaOUg'
                . '8qa+S0hvp12aC16gfxO295SiN4ycmNK2tt33eNu29O5UODSU620Ibq2hbHcsBPD2qK5l6PM0bFmL9i6k1eQ4B1uPeom/n3zo'
                . 'vd9WDUUEwKVFROhqEIYCWj6IxyPoDNPQuHIWOGU1Ud+B6cFVwdAgq5yWTtxJMC+smiQTXuYJrK1BcuoAZmnteRcVupNLgLGi'
                . 'fYPKSplgWk0EX2veGpJ9GiHQlo63OXTekI9mEcn3MCAAADcGEXDx4+lLt35k82Q316mBCj03A2oTRlEcLJU2E+vmgLCK9DMI'
                . 'AaDtvyfXNHUkmf9KEv0yALz7zcv3AAB4z/Lf3bFNf3+5+9+NAXARAAAI/jxqfhoC7fGEXm4p4/FryFlvXj7GRZQmVxXeJNKr'
                . 'A/kH6H2/lVW8Z/lVfx3+dOK37v5s7+sTmbMr2yYhqhZk77P7bvZlO+FtmbvzzflYzpq77c4O70m1fTCAz+OxzwYtgGZ8abiE'
                . '+HIhgFnDeAAAiHwB+wO44jMeAAAA4vSjsPIB4G5G0Q5IGtIdiMRI3IGyeHEHRqELO3C4te/ApVHOE9MUgEGzapeTZlTSSmNK'
                . 'j2lDCz6befFY+UNGFZswbYBWM7cGdn38hvSZNa7LmBbTJhRZoc+AlUZ1mebXZxqKT8QfSK5EMR0dPRO5A7wf+ErbMmFwrDNu'
                . 'Nq+xSoEbdd4gz+hw8iYtb6LI7YZamjRr7nJq4rZd6N08d+j8pLWYaQNo5uX0JS9xuHdB+jwDye1pw/r0cM2GiDaIuTrDnaOU'
                . 'HzAk2bJS9+A9Fy1jtJ7WxxFz9OLEDdF63pOHeHmvsYX0/m2bBaD2FBKkyCAHucjDOOQjiwKMxwRMxCRMlkIolUiadBkyiWWR'
                . 'kMqWQ0ZOQUlFbYlcS2nkyVegUJFiWjol9AxKlSlXwWiZSiZmVSyqWdkg2jk4ubh51KhVp16DRk2atVhuhVZePn4BQSFhEVFt'
                . '2nXo1KVbj159+g1EY9CQYSNGjRn34401hKwyrs/rmdmgjRVT7UOMRyCIggJDEJHAQAcEOqtjU57qk2qJiIEgCownMAkTbDuB'
                . 'tdN2r+ZFSyJGAiHIxkVsK++4ZcYDKbGyUkZItRQqCbqR5wOJCiVlLQ0rCXat4KXNtzqAof9mi+znpaidS2DXvKCJwfiEGUMS'
                . 'k8LBGYx0S8QMjK/QeAqXNMFzKRyKB5gUn5AYGdSegeMj0WDCYOytJAXDkSENZ6EQriUepbbj7LmLEENurxk0m7GPkpzS5f/U'
                . 'xSRXrs6mgkhzzFLp/aA0aEy3XDEK42LNTP/aL9HlDdj/hifPbl4IHriF/IHED4cvwOPvAJsOaD/b/9JcpSvMUvZSZNm5dha8'
                . 'z/J/7sFchwNodhIaYP0hYk9/gAKQL2lrz2l4xr+nL+ezp3S+g1CvgLxdf5Gbhd/gFoBuvP3pc4/o5Z8vw1bbIkgT879JrGd3'
                . '/4vdV9/8vRD0JAAA',
            'family' => 'Be Vietnam Pro',
            'style' => 'normal',
            'weight' => '400',
            'range' => 'U+0102-0103, U+0110-0111, U+0128-0129, U+0168-0169, U+01A0-01A1, U+01AF-01B0, U+0300-0301, U+0303-0304, U+0308-0309, U+0323, U+0329, U+1EA0-1EF9, U+20AB',
        ],
        'be-vietnam-pro-latin-400.woff2' => [
            'b64' => 'd09GMgABAAAAAFKwABEAAAAA2LAAAFJMAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGoEmG7IaHIUwBmAAhHoIYAmcDBEICoK4'
                . 'fIKUTAuDeAABNgIkA4dsBCAFhFgHh1oMgT0bXsMHGLtaj90O2Pb1bg9dMN3mKXer0k1DJHBkINg4AKSxypX9/5+UVOSoJFza'
                . 'bsYN/vwCW5kiKJMq3XpRYSskjY5WRUHbnpEWcQhTJLoRVB/31aMF5qQBpwjT1vlEibZeOGG+fCM42Wx2p9vtbrqtdOnEo4wh'
                . '/Sl/+Pll6yBLW3jR6ZUePugXwtMhbTUtL6uCyzrQz4n57xSBuMTulqKegZNHtr72bhxij1eMd7HXP0Jjn+SSFvFz/uy9R0ge'
                . 'nqZUCCEECkE9eAhiqeHelhpmFRGDfuNX7KvA8/8/9v+ba+173/8fk84g44lkYskzicj4iUEJRPVslkgiEt85crMGDSxEDAtJ'
                . 'FiJmE9MTfdOuuO+aFOXV3fvRfgu8NRqR0AgdMiVsJv9M3jwZ8TyoD2IPseHOB/i1+Q/6bELgIt/Fuwwu6t3de5fFHVwABy1S'
                . 'QxsxerNqBtOVLnStzrVVf0Zt/8Mq9U/WDQ7g3R8M0Cs3F/ADGMb6odOvN2KQCCOQbRYwOIT4w17iUuJ+U1xRb1OslWpKORRV'
                . 'SHBY+Jx/SIekEEH+v2LAOQJ66otaRY2uWlDR6mTm9U2Q6RafXepUSQFHIo3FEE5lnHonyfZbftM7hONeWIZGADzy++3Pte2p'
                . 'AON70tJohDRf9W3OtcLUmiXLueK3bI8/0VtycNphGRnUWUohstAWGWXCI/R/2wmHaaYEImZLBl3g5LmIVeJ0iXem23Tv99qs'
                . 'qgQL9VsL5CBZSDlcTvHuBB7L4x7C+IBaqTo9ZHj5XunM2/ZunAqRRG89sYomUKXoH8kAKArIwIIGBFAQ6PelrfrxIyIzZYCG'
                . 'pkplqHbOMBpj63FSq51Qn2bWHXlaA8fL8vC1rNl+9Wc2OEKyJ1eucFdn1dAX+5qUovAIhVEUwoUUJJLyGOERBvgep7P9fax1'
                . 'OBDKkbgea6YKEFpiHX7ftOnN0qrDye5xMlvCv14cGiOYm20pLxaLcRIh+T+bVmlVy/Zq7CUveoEol2UfcZAAxhe1fpVU6qpW'
                . 'j91te+xuA85ogCQNaUmtbnnbbe+g9549S+SZo6Ho0DAoLwBGRxQxBtlFIUSbpodBnt3f6yzb93UkH8qHnqsYAnVKhqJOlepJ'
                . 'OvhfqwXtkWmy8hFKQTm8yBpbe1EAWceKN0TYIbepAIs2k75Ji0Xbxo/9fr1neJlgBqRsjYqIrJ/3ce+vmQNUPM7XRy+WpQgx'
                . 'xDiEIYwxsre/S7tvhzH3/58ezLM6vbHvqgRHSoECMgUcp/fHtSCMqd2/w0yMDLFCrCkQJV/Enz8SKBLRikHiJCEpMpFsxUiF'
                . 'SqRaLTLOOGSCeqRRE+ahR8hzz5EePch775FPfsb5xd8IYQBLgDGGfPITgJCfEUCgpsfOOS9pBEzvv3PCaJjiAej7xwEIw913'
                . '77jRUDX/EOQPdKgRyABsPacm8Gp+Z6vVaglEzBGhuAyjbOJfY0zEgdJgspYwRZpGg2zqG2T8mIDkjw3yqa+JQHQRiM4B0Wkg'
                . 'OhVEp4DoOAbMa4ClcnKWbyI8Ur4CCAy+NhP/oUSMtn9uI7DtfsbGb9236q0qkrNkTnIh8UP/P+hPd/DG1152H/LYXddddNpR'
                . '++202drOcguzyNWzTdWhKT09QbdWkpXM4i9O4+NgoRfImysHUBpMxpvpHjHP5DPk/Hv2b9q1Q73299r1V/W8hxi+eRIe0O2u'
                . 'dr6THe4evJe+59rY6pY2vzPTZG0aXjyuznKlqyuUJzRzR0vR3XmRZ87Zp2hg0ow5ERKG7G+/+vJj785k3Xu6+7u5yzu74ztI'
                . 'v8PbuvWdlRbn0PoHT1/XWja4nrWviU7nLbYAcbGNG6ee79ynpnqYfJYzn8B4jh/6H2c26bL8CfQ3+dX3OetLX6TPPwmgD5GG'
                . 'FUR29gNyglFLz4y+juRVlDn3eyn7W488zHPkQVegHDANZX09kV8B5I+agWItujhM+mh2Q45UunIIkpSe/aJ0HHW50OUwBOdK'
                . 'eJCoReq4KQtojIrG6nwVFNO3rfko0/jKr3FJB6+mMYUi2k8eII++QWmlIjrU0X80CBYpE/c1tx8pCBvPDDwTGjukF+eoKTMh'
                . 'ySqqyQmS0Cf2AMN5p6jdx+I4hQLbUx50WToEjRpjfORTCxUur3WGKXH9OBIci1qxM9JS4ojla2JpcVTQRlUqF72aMt1FD8mg'
                . '36zzB6SSVSVcFWNdJuqYcsLqsEvMAXG5AEku0pq52FbMP2l18lJ/RXo477UGtKZfqFPdHKemrrklkr7oVdr8tJw1+89TFeSS'
                . 'xfn56lKtTrTb5Upz2AyWGCe1vJsCSDDK5FFixolhEy0uByJ8gG6vJ9pLUJROSLKpPOGJa7GrduPoEJ117bgS1c4MtlAbtS0j'
                . 'HcYAVljjjQ+hhKNFTybZFLpoAKOMCipdNXT9spohIAqgAfgDPAHOAHtEgZQDyIRKnT5svSl1WMLi+LRjQ+JfA4wYO1QMATtw'
                . 'OR5MHj3wd07s7d59/XWjJZZsdGhFSnoFn33fIKjFL4DpmswSQ82XrfBAdOlp8UoxFKtjTlFcnQ4sxkFLpVVnzMi0wNIt6F5V'
                . 'vw5MuQETRt/dyDwI1LoKpGpsPN7woYbj27Hip2QAFLcryPRScr+WXcQQJcqMpVawl7aMllRm5wBHHEmNQpLW+cFinbAcvATq'
                . 'qrZIzcRHYPd3qAL7uV2hNd3qMBYz3K8XTKqV2QUBgpbsbhSMe1gX0GtDq8RIpgWOyRMJW1WBhhEJw7VChVY/7IKsYwgaoY/n'
                . '8tsVTRUq+mRk63BVkBhrZ3xVP+1aMtUVuA/yy8TwQVVwubRIyvrOdGh8XZr2ud1Es5ZJkwoaxxHw9BgHhkLkOS8FW8HTQwIM'
                . 'JMtqgT+1atuH3/FcAao9LzGpIyZ3NEBK8sRlOPNb8XbP8LzgvZDDABt3YKtV2wGY/4jgCP+zj+zA1KgqqsQ/9fhR6U4a5yAU'
                . 'j2EZfsqdQWy+w7oXCAMaRomqIDahz9TH6yEH33fJNAbBUARkMqxqGBvxNlbMrmJ+Z8Xz2viwIwnqASiiHksZRQl/l5BHb7TQ'
                . 'B5+RzwxfdSFJeQSbZH3VQ/+PZcwQGcCSExk3qdTSFUpUrEyaCg2ytGpVrd1UNbbbYYIzzqhz2x31Hnig0UsvNenWo9kHH7Vi'
                . 'IhEJAwIBY8QYx4SJQczIMP31x7FkhZGTs2PNFqPihOPMBePKFceNG8adOyMePPTjyZchP/5MBAigEiiIRLBgUiEikShReNGi'
                . 'MVpJSLJkFlKkIKlSMXppxNKls5EhE5MlW385cojlKkSKlSClSvVXpgxTrhypUEGmUiVzVaow1aqZqVFLZKxxjI033mAT1BHU'
                . 'q2eqQRMLrVpx2rWz0qGDXKdOSl262JpkEjLZZNammMLSVFMpbLfDQKedxrntjgEeeMDISy8Z6dbNSI8eEh98IPLRRxwmHBHj'
                . 'SGzBsppa/3Q/BmSoaisET/Qr1hwpKPRnwxZPRY1x4sQAJg8KWYjCNBxCXPphffRvXneb0/mlvNiD1uIsEvIwqYItmHS01E5m'
                . '4amjvrC+jPgYENLJlpMNQxESFssbyIOFTViGfQwJZdjGwFBVK94Hu5PBEOIwq+y3zPfHA1RjuR83HxGraVlzpD0nrmH1rGrM'
                . 'a56fx5x+l9d5jq87mIGR2tw0SxEVwnD3vR5BTGDEmQfxmfBP0YqVrlyVauN1mGwL7g897IijrunRiyNf1vbVtyOOWglZQiNU'
                . 'mHARImnF0IkVJ16CRElS5MqTr0ChYiXKT1cumJTeRI4uw4EHNT/ev8/YrRFEEyGrSiNUmHARImnF0IkVJ16CRElS5MqTr0Ch'
                . 'YiXKJ9oX6yidXCbhQybTm7L6jiyjh4YEFQoDDs+AiCExyYNJLwXGb0X5dw9m2sgcWX2GAk0JwnjUggmAiepKT36KZ0Iwg6Vr'
                . 'SV3hWqU76MkZQjqMMmlkDhWunZBM394jy5YU7091srczB7ROYhGHZ0DEkJgkW/12jyVCFmggVJhwESJpxdCJFSdegkRJUnan'
                . 'L3UA0EGhYiXK06RM0QC2AsrTihwuzNwVWXk8mnblc1sYnHlTjBJVZw8boUTGxatOLtVdE7nG95pAYlXdMyGacYK7SQi5qcVT'
                . 'G04tag1WGqAlAn427uNao7vvzhJEbJzc856QAtb9CG+vYOH9qBVz7ifTZ8YkvuIznq+9fbAV1Nn5PZJpCLTAMERhEHxwzCrn'
                . '3tU4WyXB5YB53KP0Pell/vbJc9T5PqyqCKj0+Cl6jC8BR05KbCveB4/utAUv+sE7bSNSqVqIis2vcWRMiTVh9BowyRpxkunR'
                . '5PxOBKr3hy0lsDpoQPuJO8pq6gGXTjM/F5O30jBZusBK9CzvWoWWaL3Phx4ZJSOURraYjW01FYpGjmCwEjohDGGPDCDdHyPj'
                . 'uk6w+1aIYyGRzHcX75Ho8U3NfAbA9jcDkPi8ZbqNd8LsLtsB5YAE8GlvnjXOMzn2aO7d7hvgf4sEoCMBYEe0F6DEJzAFVxf0'
                . 'XMm37NvHnxLPkgkPQdr0uUteGd6rfdnuIY7nJJzdMRFq0QR1aZYO6Yqy21blhtzo/3+ACSVPwdbLlA47Cdxw4lPC1KB29cH3'
                . 'yRJAMgB97qWE/0/6s/6T/34+wn8f2RqArx+33AZ1hF91u/T6BgjgC9B7DgA9YVdJ+30Aeiyzffk32eO2w1766J47TjvjkK/s'
                . 'cMx2R+y0y/e+02ufu4jAiAkzMv1ZGkD+W3JsM7WKM1du3HnwHBCVAPPlCnbeURe8d8qvQkTT0omTLEUqvXQZMmXJlqtUuQqV'
                . 'qtV8FyHG//58orOeOuetzfaaym4vWp554P4P0EPNrnvnuEf+8sQbq63xmw/uO+Bvq7S4Yb11NthPhOGJGTAkYawfcxakrAw0'
                . 'yGCmbDmwY09tiG848uXFm48gLqqE0wgVKUyEKDGSxEuQKM0ww40QK0ehPPmKFfhWkVqjjDbGRGXqOCnxyU+uuuaiy664ZKcU'
                . 'KjQcWDoh+69S6Iv0y1I+WL60XhrhxOWPrLDTK5T9FzU2WT32WbJSSkUZhCffV2DtGr6bNeq7n56PA+Zfwhjp6ZnK4/9AJPk1'
                . 'kmNjLeEDsZSmAyPL4EoyIC3jkizEii3JAe2Zt3mvcDV7RAguq83a5WX7Fyrun4KqkWUdW/L1aada7DI4TtdPbIFM5i7Zmvj5'
                . 'BF+TuK6XaHl1Qrj1J9rlaXEy4E1cMEovsR20SQfvqkELvl8T55/dJp2X/VGfuvGWnOvuL3/RbNKr8vZ6z9Dms/+6pP+iyJCI'
                . 'LTdiUgZrXF2pC8G7AWOkt/gFwkKm0GKFvHJd6IaD6rUMZJ0qR8S9J44xs1tqBDfae+IsXjsZlV7HtS8/VduopFBFD3ojmFOc'
                . 'lhSAPsh7bGfdw20vdXMMZMRYe312KSm2NUqIRdmsHA5ITVXNuUsJiuFLf0R/ae86kej/dSJyvZuRzzs+bJu4q17UKoh3XiqG'
                . 'kknnsaaSHlBmrwO2i0EyyuYEBDdepFqNSd9hmuUjKgNIzT6Orll+notjtCkKU0MTro+gkzdg2Xfvba8BpXAvUlUFESaktDay'
                . 'B/TTvKJt6XyIdQZywQGxtcL0vKo29dfEe6zGxQ2CYiPwSY3xO7MT4Jptb41YfW+VxY/Mttb8vyVRMoAHBaJ+rh4t/uDlqNJl'
                . 'yD0JILQgPnKL+OCal19pXtlhQAqY8BTEN4S1fZ/5p9GX9K7lNIPwnB2/tjfXjH20CQ8bQYZQfUC784FI1bET2pxFyl1OF8RZ'
                . 'SNsCcu6U3HZ55H00fcUpE25p5TeTU9WUgRKjvFqgTDnskcRCER1Vku5rNQNWaUgkph2TmOXDFkWRYZt9z6459JmYozVyxSFc'
                . 'lbsTIFjcKZxzxkPEm1RzNmj4r3fwQoVVQ4Zor6SeOAYMjgWT48DieIhxAticCHHOAwlOgiQn71WCUL9K6rK27wUVmQ9Hwb5i'
                . '+UCHvO3vJisdVm3/LjCKgk6YOIdUL+YSFDJtRg7KpVJUmspQWSpH5akCVaRHq8Bsb/0eqLZ0YFlQy1rU3RmnkZ/4mqyDtQjx'
                . 'pTupAWDobMluiZNyVXdfyo3hqEln3Tnprdk2FRvLLKRo339UA9VW27gv72kD31C3dVDAII0+kxQ4nIuKgnYOADzmZTK3Ezdz'
                . 'h3SBWQyxNxNLI/dfoB6qB7Ml+9XqSMXI1ZUHyKxor1Bpk0g5SnIlfR2anxfhj43HXdlc95Zg9O57sfQQBI36s549kCp7zyIL'
                . 'xuydpJMBs6nfhRk1oFJVRzGLCZrgd39SFG/8GevtyJzx+bZS52XnEyKlhOtkMKxX81LlwdPRAWkCnqEVCoztEOdFMcyDl8sr'
                . '3hIFjZNlGcRJIyQcDFbyQjRsMCDiAooUeeiIFsFjzFvBJOczkFzh5qgXzdET16c2pkFBoVHI0Cyzk8f7TQySsvgAcEKwoDYt'
                . 'Jg2hJMvZRR59hVmxJjbnwJTgD9Zr3wxY+b6FdqAj06OW4tbftdkWiI+tePjmJm9zY93bjJAWIFCOey/ot82uesxqleuJ9SrV'
                . '5++6a7YHX8i5Fv6kl+AwTrbkChxFohb4Ncz0Vm0piqxYb0ZrAzRuqpn05kgZaLNhHZfrfahkUnXqBpl0Mg76uz+/JoYMBnPA'
                . 'h42dlUljudJoFSPatsOwR4iy28i20cpD1BDWfLxXo5e83sG543dKwe6yNofkERmYVT17Mn6qK4MFnXcHWl9QYZDmlSj9gt4s'
                . 'n0S+S0GfyjsI6DTWS4SGlhPwMInQ8EogFo+QCI0sJ+A0iZZO5x0CEK1wRoBQZgUBZwUIZR8CEM4JEMqtIOC8APF8fThGKoAA'
                . 'FZYTcJFEqLgSiMMlEqHScgIuk0iUY8LaQAWEqLKCgKsCJKoBANcECI2qIODRAYp/cQzvADSdV2szm68RfTNW5OK4Fp8ORC2M'
                . 'P1xP5WlCSzGxpVS3E6EK1TehGprQ2gjTG02zm9TcUmppqbQ++2HamjDtTZiOJkznLKSultKklspk9MNNacJNbcJNa8JNn4U0'
                . 'o6U0s6Wjsw4Hjb6rfe5+kdVzPONibrCzjTkf4lHuRnHpM5z9FUM36j/BB6HVxwwA/AMA/Q/0NlgtF4Y8BQAAlKB9EgDD7dfz'
                . 'MVYI2OF2Lbg+AZM1MWQzDqGD8HuGhnxsCx8xCEFa7500kjK3NUyiYpIJCpQ/Jq37iweAQqKV0eC6z64N4ZUQZBORROQckhQ+'
                . 'oL6idAHyImhejwMqkTFEvlDcgqu9eVkcX/6xY6cjLEZeEVw8OgozG5ZxOrG2URVlGY/IGCLb+EDHKzXhJ2vjNC5LF78FhaDJ'
                . 'YLWylZDDqO84IGOIbBVHqKr/MVB1u19VcukmAQdkDJHlcv29K4VPCjCedGPelFXpHAtkDBHgo5dRVMnZu8D196vqPe5lwYUp'
                . 'RFYGKz3uib5x0ekitUxjJ5nEi1vsnVeuq4nDLV8f9uxyKMlMZCiWeHDkmOuYNO8gLUk6dhxJeiwDMM8TlHNdOuDpmHk+mwVB'
                . '2kYD+/2F0iTu/OFYtnHOBkpxTVrr2ozcw5iXY5+lmRZjhCttdppJL+5iFQ+eqXE4ffyhlwpZx6rExmeMbo8Jt8A11nJxZQFQ'
                . '5uczpM8tLuJYc0t57XA4IaCyP0IvQhGvnrKA5HFaKIBYeGaoK1DUhrHWZESqMr1oQkVc85ULVb6prNeL6xJ62RRJn/F4SL8I'
                . '6Nbq3TfWd1JD7lFj2WfSsK43DESvbidH7SWDjidC6BsxKNE6F3OWbg5U8yVAsRhp9jYiyyBlJaqYuOrQkL3YlKTGVgFlKQzj'
                . 'SE0Cl4T5sApB8eLqhjOsKZ1JaurUuedJX0PCVWKFJWTyAxhqvQQo5jkSLQxdsq0sxPCqANY1uBsj3WfL0s6xbV0tbeWwtGVZ'
                . '/T6qhQLyu1kIFrtZYXemExIL5TSM6EaxapC1wtyGSLn16woJKSYI5DRtqP+AiyNBegs0VKcybRkLi7IUAbaJMJVhxA5lZFYz'
                . 'Vu1w1wxfWLm9MVF6PQy1rEkEd7+IRU3rj6078/vnvN4uCB10+qvKgjsXif+zmnxaZd49Z+95sOz8t06MyN3RhwuOv23YL+hy'
                . 'HDJHWCwwxYCtwi7RVB2LakX1b4gOJhasMJQcOm3e4yA3fqB7s+fit/C6/hzlfc+nZDSnV4DUgWq2lejKda45UCgpOM56Gb+r'
                . 'GyK15cT1wbrMQFovwre1pNus8E4S/hBWzjT6p9C54lTEeOGwdccB57rfIsKqtjVMt1jBZIF56lgmY+TRpVE3nNLdQ6eXUmNB'
                . 'YYstnwd4veGdtOJovTcKe8AwwvJAXsct3EBXCmNt/2kRYoKvqPY1ePWEmt2QsJpoZG8fhKEKg08/aXN3gfQPBnlL3tUJ+Chf'
                . 'ydb8cyBlNab2v54DeLjTy7tRzaPVrUpo8RbCm7WN294rxS/pvHvKLK91nYOVWGoTMQYqqPhZkVgoPCy6KEQQQcRcKQ/bGb7W'
                . 'Rmj1ZM43vcKPS0r683P3r3lrsLOdCqgp0+4xPYwzX5l3PZM+KkMqxocU/K+GuI6c6UrYSVIFBy1cLNqDV8f01tWiX2RhsTrG'
                . 'YuQbBHqlKpIAKk5cwyiQKK8WjSCSwfqfjXbO1w0/e/txwpZD7hcbc9FElyKqrr3WjLndFgYx0d7USyZ4ujYt7sl7PG+bma9I'
                . 'fO9hyFEvwmh1a9KAf/kEPyWR0OpMEu4XK9Uu4wJe/yMYQtzMeQURRxNccgrrUMaRgaEogV0wHGGkQooZAxVwdM047qsAFd0X'
                . 'g/4P/NZJ+jsnNP4VQP3do9I8k51ApZFv4Nmhg0AFtZBLqGFeeVtpzbr6hsLyHcZRfoYhgdXY2iqrS9LVG78VCmoYA5rzBzP7'
                . 'FsLLNdUxkmjPe5RRtGQDSvFmTO7lRBrQA/MusOHNaozWzI2AgyrQJfM2/XZkChEagx2r/rUvLCz8F5kcECZLs6yBaoIgC4zd'
                . 'uq4gMlNAJU5XPY9avJOBpGy9J19lN+fTjMucj26ZyxmW8JTzaZ1M3Acx/oO8mDIaOhszQgKN31CdhqLBuoak/qNwHU65wscJ'
                . '7mdMeLlhRwuO5N6gTg0SD6RwxF5nROfem7tAqznD2bBeeuwedxrXtku+SlyCvXDrcB6yLbIiKOHYeRTa5tk/4+YFKK7KuCLv'
                . 'gITIkxLYyxr2exGMWM9HKtalAeHUESYCh0EsZR2bsU6dPZR3NBZVCIOvyniy8hyD1ttUcmyn5jlehgIJLTntHoduQAZtKXl5'
                . '1eyo8bg/V3nRdMxJxv+KEE9OKuJNdcN23y9KDJ38/xM/168tJa8jQcmQ5kYO5hHOYpRQmeH8FlDJyK6E3YqPuLQwEjG6mpQU'
                . 'SdIE6pHRRzgJDvFOg7HvHmkAwMEVczsw98grYeGBK3duQMHVzxt20Nw0elH/AgBS3u8DhUrveEZy3yV4FT4U7I48eYlXzOPk'
                . '+CNLu8HCCZSuEsGh1qN7e+FxsFCwa2sK5IwLiYGLwH2KXCCvVEWh5FrN/Rf403akoM5DU2Of7CO66lQtFw8J+VxKUJLOcr9D'
                . 'P0yHJ4CHYFJazUt/aeJ7XREdjZ2dQ/ekp9R6FEqs8eSyGrg910zt6SNbLe2vr1ydf53z64Wvzr9h8uulb++/lGjhQSp3bsBD'
                . '+rwTQiJLGmwpsnorqYOUhva3LUo6WccKyZWe9RBnyZ4XyU7ZfhuSQboqZiX04ubebw2MTLyQxSxx0JOehRPDjClldoANIGiZ'
                . 'jrTGCLhUXalGsaOUYthKCD7EMEVsTM99BYRae18+7eW+2D58uuFDZ0NJ6Lax7ARj/623T2slu1tMp2RanQ7P+5bl6ybNqoSq'
                . 'FiqAewevqKEzIHlzxFB5G8d6gN5lv6ExtjJ/JcUUIvz7Cj5cikiSQAV8hZegJCUf/ljhdtwyK7chs6OkZSeFNHM2kNnO0jcB'
                . 'lPYXt/+rRNsp/j7II146LKJwgg7ae52IpKJ14euUiKROArrdkjd6UtPhFsRu8XvRckuF7Y5ky7Bt2xrGVuEezPd1GOloqWeJ'
                . 'dxsWO0CyGnI1Z0g+H+MwQvO7Pp9CCqJJ4uvrHokpfVHaE0Uibv03dTaYFn7QSfLgntLPoF+b19cLnD9GJWfzKhCKptHgPPA6'
                . 'vCdqenAUe+Cl/rao8DpLJC8HfOMn7S37Bzkl6n5/d4Tr5LrqzkXdYRN97U2dESum3MeId3Ww/FPYK2FEdxNlMxoLfCUoB4Hb'
                . 'K8kV/Wa0SG6SQulGLJUJOnOccZDepZyLnH9wm3P+vG5E/D5SxLjIg7Ty9YCjUBKy81xQxrTt2amEvTGp0bJ7A6LMEeqINpXu'
                . 'rUVvx7ZsYcYaapaxH8My6Nv4ds9N9b7YJRLEGJdGnfFG/6CzZ5Kik8/i2dDAQ/qQTnuZ8OlwP7o1XD+mviH8gCkIsKg1K0yK'
                . 'L7McqN3QBwKMfq+u00s7EoapeAYUTbrWC/5GHynmS/re4hkPQdknDPLyhO4iNE1edtQ8AGXPIt5kITJpII5NMwO+1QVlZW8f'
                . '4xyWAgnTLoDk3vkFkqUdJQYfb6IRw0hRHJXdurAO8cyrZJ2y8o3fqKpuCumGkdficgaK2abcAI3PoC7F83M8S+9bzdPMbEPY'
                . 'D7bsa0ohqNquhIOIXuuDkAkSdoktIessc35NfZZlL+xbNlA9PpZbx8oktuc7wRC55135IhCqgGjH0NqJcbKPHbWqd3HXIKcZ'
                . 'lTllhCphr1tJt/chTVVYC/R2h9oGKAvoxCAhVNdEfAhunAvm+i97ffz0G3fDb5f3/aP0n7Z2HnZE5gPCh7JE8JvzD0BVXSAU'
                . 'nsB+owUQIP8jZIpy/CkCCLTvsSeqQSHiko4pDz2HNvrFZxlxJhyJ6SOii782Qngox4NYHIjHSXFY6a0vG50+Jn/6aTsrP5Tl'
                . 'XsuGj1UXnH/IpuoCHrifA9LZ+0FwA5sOcjbQYCwN+wuWbLR8tAfetnQ/HtbgW/h837Syz/FG8reDID74XN/UGT8RjOTvcrhR'
                . 'zcf/b1/gzx+bEun/1haxTcyJ3H31p8QrK0s/eMLsGyciTRJ5SKJkweVfFA9+yUfUUnGqQxuftP7N798U7T9EJB3cz1Kf+ld8'
                . 'mujfUijfmOjwZkx0xBjp5C5HEO6KSOeIIYrm6JZajsneBGTRU/uLm6I21Iv0AVJOoSDl9IF6kUHtpv7cMxvIoimBY7Soc3TN'
                . 'ivyaT4FpfFrJ/W9Z2fM47P+AxtPnKum3uNqYi+BG72TWsfuPepYpf2HlTCGXiTu6L+sb/zk+oo+2cUes1ogJ2S9esG61arUp'
                . 'LTT6yM1yOSEhnXfeWltTrMmGtrtTrbt/Poxp9c+7lb7xG3SMC6v7uM1ZA39zyqmZNMY6zkmjovOuPLgxrnFOLpBDxX3lxxim'
                . 'UTl46sVp841/HVn+KBaJWdg7MHXLDNFOznIE4ayIdS43JmxNIKx8OtoINGJR248OssqcERsC5JxCSW41BlIiQ8vQUGHunl8t'
                . '7Y1s2+DS+8azpowpOyecGSdrNWccO1l6N/yUAhyNjLxG0dAhf0iKONsd7XmjotyVbNoo9nwejKdxRXEf1vlFY1/fPN4SDG05'
                . 'EMWwucxd56z/Pvv94OI1V1q5x+BaHM+2LlrqSGcYVl+/IEJefIdvv3nrzeHxdOj8lvrz2yKCcWFsWCLNDmdQLKHjR7neWSPV'
                . 'wPm+sRsTW2aMd/JXIAh/ebyDldi3wQ7+cmFFvNM7WmdICY1+aotKSc0lJNWQDsgpVdSW2XxCUE+Ho7X04X/NHKN2+odqdjha'
                . 'C49ZajshCPlDwwAcrYVBe8Oz7dWb9P2X3vzrzRza0F5tbjmk2ekvbRH5K6j5HaZcTrZLNcjJ2sUF6tVqeifE+3TNOurrjLxY'
                . 'zVhs037CviNVWPv/aC3u82J6Yy+qaeOR9m+1Ee3ErMif6bX6ujbeiNUaMSH7pctnyynNsK9NiDJVTHTAAddect94abjHqurW'
                . '6uKN7LGFDwEH1x1SHZJb+TQbi0VDrDxRtWV28aF824sPTqW2VFMZr94Y3oqPu7vm09Y2k7V1kLni1YH+P8MJOU3MyvH/k1jo'
                . 'Gy/2tuikGQUfDNr09e8YZh1VrFAeWDdaMCp4/Q6Feud1FYIx1lFeq600Ak1IONX9KXaEbbVio/zdTLU2JpC51CZDoktks8TZ'
                . 'yd1zgDT6Xl+XoyjMg57itWHXJWTygFarbu7VairXfxURRb5aT23DRBeYLXND97L33HUt9Q2Wub80lGEhh26U3ZUeewjhFE15'
                . 'qGbcFT4MRqqh3vC6K9xkKJNj1ME1zYcwKsDCsdY7ff5GtBaNUX/4aKx5e3BfML3Pv83fvLL2bFftULGUP8yGMHAcEgZ+mi3/'
                . '7cUklOTf6zgzdHqoQy8pAatHxAq0kfTLcw+i0Ojcbt4RKXfuMO+MCLl4CUzeeaA4lCpPhYoP7IJJEm1coPEQM1JpRIHs5fTJ'
                . 'ERUtKhbRYgjJ5TZVSCSmRWMt3Zmm0/PMH+UdCw7WUzo9MlMTbs4czA5DegS2GmObaJlBqidzeddLbXGXKj35w42627W6Vfmk'
                . 'Z77r44/tv+1HGJ8gKEZkjq5QYhsJV4tKuLyB+UASvUX/c9PU3oTpfME5qoeisLTrdPOShHV4phMRSNiJL44DdTjwKs1H05hj'
                . 'PM7mIqBov5dxWitmQ5a/mXAk8IdOaOaCKHfqNqWPwTNz2lIYCUxe82pxKDWRUhcfXYNjBQVeiiKR8UUkdxgxpozA6KFc08lp'
                . 'o5d/uM3GIANaOQAk8dTbPQKlQ0GPSiT0qEOhFPS8/RSQxMB0KMjQKIstvquKT8iByfpFA4ZGVpUtecMO9Rd8OEPIdvBWtIOL'
                . '6bKqpX0vdWqiIdVH34pyur90OvQYc1pg8JAzUgkxpXHFeBqemeAvTkQ/m2Wgm1zcJ2wANR4Iqo4NwgxTRPxCx1zr002e2mW9'
                . '/ohPiahocaEIjCEqBfcAo+Ln0qDAmBoEWi8xLZUQMlpvUmC0tOg0c5KERTy6A+YJ2ZzrLwBxbNxQ7qZdN1ArWMbPQIPXp0+l'
                . 'VWPdU6o6HFuTgpx6T666mx1bM0KcerSjtliyIi3A9QCVfcv4ln4U9URNHKLPKtUSIcCJLysFjzmmeZ55HyUtV81RmmVTL5DC'
                . 'rg93idUmHgGBdEVPvWAlSbVxvtpNTEkkxJTaHedrpVbS8xuK/KmHsxYPL6TJbQpaWCSiRWxyuRyR0yL8fQgjipO+iZS0uANz'
                . 'Raj2yzhxVTjllautKv58HMrm8kcGSWEaLE+VexPvadhXZXBk8lj1piGjSplq+PrMVuJrtajNzJ41o1pVp9os9ZRE0WZ4615q'
                . 'GFhOsAIhbKqQPy3BprCUn8ImStY3kXrn2vX5D3+eoMy588+/lQ943DCBtZWn3IULa17ia3wqQ17CpzVFkv1JZ8qmUaJyQaba'
                . 'zmBzUIEqZjno7AhqiW7cpqtX1tk1tpby/ldGTLjPo1lgQzTzkxL+Ep6P2LYIr1BAgSroUbGEHhEhhdyuCEnE9Gic35kVgpSO'
                . '0co5akcbSJ9SZ2tZN3NGG4cppdauZmJOSnYA65Zb6eCGboE4ViUZNMGb1Q+/2ouixVu5TCvh2ocTOCVUY5931HgB3E0b/aUR'
                . '8eCCm/uq4YJzZVtPpbS5D5MqrvTeFWhv0z6+8UuHiO4lqe1Nui9/a9OdEGE7U0Q1n++498MbqZDZUbWaKVaTd8HYVC03QjR7'
                . 'bxc4kVIfv/reB9VGOUert918+u+Zi/8arbbylLlwqomvLTsKJPC/MqvDE8d6SjA6D/VawA+k0V40iS3UUonDMWTGHCv1BZ6l'
                . 'ORJQIqQj7gYgiUjSVlB0HIddX4LOgKfHUAuP7whzNcgPxi4hHDfoTKFfCvHW1udO8kyW1JDEQ4sAAVitInUenS4MW6LegMah'
                . 'V0KtRhLLGA/Ef0n/YlP2dltnMzGBQNAlSHlSy1LLhj3DAug1TtUA4MdNza+Y1PpMtDHVIFshFG3AutnUVHnKVZyoueLzKi5b'
                . 'Qerlhrnl8swWvQXG1rPLVZwtUif6tPkFh5oFCu901w9nyxp37tm2p+lcGfXSC2WZBRtGNtQt2bBwQ+bFstyC5d6XxT5ccgTT'
                . 'suLqyqstr2BeLvnc+yvLrzDjH1Q/BHaQ30/jEG27gG3cvmfHnqYL5czGufU8y+26mwneKbalmncj7krGmBU+Jw4uLF/M9OyC'
                . 'FYuVHqHeqsF0Q3YWpT6a/lE3fF68XGf9cai01TRkna5cIthKebgXwculpytsfVTxUS7PKHkzNLm0aguNcOgJwI3MzmGJ+NoN'
                . '8o0Ae1g289FM6rusyfZJm673j22TO4VMD4/PdDuECkXEFOLzmJ47L7dwTkLQSQ77IgRddPpLrW1aWyt9lctNX2lra9NYrUAg'
                . '57ldOZaw7GWJe3Mbn3wyt8Hrjf9wn1yf28QvHxtP61cDqmlGI+voashv3b8F1ucaauMK/hEzhS489c+DJNCAimsPWiKgwdgt'
                . 'g5vssLmzq8Woaa5vjcssGFO/GM1566UCk4MMcRHKKb7GbTE1uZsULoMSWmkmsxgWCtckTXhsuW6JTt8pgusMWk3gapEJDpus'
                . 'SGOfzLP/cBsQH35/aGw4PHw4BxTVCi0YQ4wyghkTAUloBfX3YrVTzHBzeQyfQ6Lk7iq4C4SgneujVuL1ghOu1/iBLoeq3ySi'
                . 'JExoI9edwiCtcriBMgdWVg9IukKRIamfYnsM/ISyjKCL0A/TbNMtpR07nFRNoMOmmKXXK3tnoZEghIICVKEUoCgIfemoVDxZ'
                . '8Ov5Ke5h0xZkyBhF10X/tgPzw4K2QeNm7uGCNEOL8fSqDGmdgmHj+IEi/DP6J6+2Nt4uvYWEyaOW9+foP8Bt+J1reQpdC7Y9'
                . 'uu43RA8fE8mPfxUJjbTHZOJjIyhoUaBsupkO6lFIqUQhPUinm1G2HvnhJjY1ZAmSo79tnqKc93duoEdnUM7uQcObQZRBpQDx'
                . 'OVUsgsWgTywBfbBIxMMKQb9EDPr/RQtRUpNC2UwiRRTKRu7uVNo7YREd+CqSJ61x56kW9HT8p6r6cWXlJKKHG71Dk7s1MqEN'
                . 'ffGtDTbJkWIdapC6qibV4M5UlG/H8XWCqm0zyt6qrvq22iFF9UrqVg2ZoqAXvj2TRPHcGhE7Dmk23mq6JjPLHM+sCS2UHC4R'
                . 'b1te6ABap+OIr82c+Rqhons94JGdv2c5UOLOp6upLNMzRPLtZ5xHS8R/kRC2gJUIaeHK/wADFFpIlss7kgzmjCUE0unNl3E/'
                . '1dR8jsP+a9b8Oz/Nt0E9Ys//XrAASpS6usDOfBj8BLyRpGIpS1hAAY7sI/DsUpncLuUR9p0ECsDqomCpyU/AT3G7ZszYhcPu'
                . 'LJuxq2YH8VRF+Uki4WJ5xWXtC0nJ961vN6i1BBiUfmdGT2H/Df2Mxf006yfTpTSht4JftC3x7tM7usrdvPMpj6RXtA5sznYG'
                . '9eHUzuU7mjqiLVuyMIeXVlu6nrJlKfU7l/mFj35HjFM+JI8Ep/klH6rfnGmFPLvURvmBV36vwWuqq2ZRAsLdZiKDZSTmmHKX'
                . 'xyiaMuqQ3dqsC5u0fvx8HPGfysrzBKlZWnOuovJbXLWD6IVa/mYwfl8E0uJPFHztotBHzHgP6eDx5KHj2MGaWn36x5q3CEh1'
                . 'zchN3c7rl7ps7VkbpekmXtT/lDa9XPXKPu7n41pdrU/FnO5iwhVnwUlfWJzimd53sB49v06hUzfN1vhr/1puCGo49VIpJxnU'
                . 'GKDI9M7i9i59lyqmQvTIWzFtccf0SCUK2V6kUl60QZA1AfW3/tZl87fmN29ITL+8u+6bqfEH8fvfTju51vTrJ2816zEEGw5v'
                . 'JRCseLwV556L9M0uwBFnYwkZMoGDCOA24vodhQ3xo1814m893XRTebVty/sxFW8PRRXzpm9TlDpVAKaKQOjM7dbEWzMTJ8N+'
                . 'MccpTK/8oQrhx9k1dgIUs8yj+4Yg6InvGXQjCTJJG3zWlk6JVt8uNtabZSzHm6f6qCD/EzxdTUKmY2GJx9IlZU1MfVytQmrJ'
                . '/0UXyJDxEr2W1i6JbjeC0Eiu/FmAcKFg5EE2hCPgK0yUx4D4K/ZE79Yv2CKoQjdL8na7gN2DOPgLkgvu1i0kjELnd5dHd5F2'
                . '7xj07dC5WJ+1B/Rd5dGdB7Bd2Aadh/P6eTW4Z5e0x87aOvPY5USMVp/YOj9Momxghq/fR4ravEuGbozXd83HXHU6NGVhKvQU'
                . '+0hdgn3YiOw1JXqyzq8dJK5zOCIS0pk+WZx/3VLFt+a00+t15JnNUAAym/McVne2hbLGWsDZ2FXYtYld4C7gbEiFqbCEnTdD'
                . '6/aensINnW86HwKrj1mgOhe7qvk3X/X4NM6eMwaBDI4QVbNA4eDIch//oWYLuJebIYul1k/+peXWtVvX229cv3Et8Rrm17S8'
                . '6Q3GB71VmPJawG+fBg4KqyU9yCdVmAtx+oWzyTS6Zv23HZu/qv+wY/YC1Z5ASPV0z6LnnLOie5XzO9Cncxao22NUK52SP4pz'
                . 'eb+K9UEju0mphBoDBr0EhvBFusSp6fRbVSQeO+wP1S5ujC7iIvHlurpW/rDFwl+cyK3W1tva8O8ApATgxwFSH5OFrTGDYI0R'
                . 'x2Kya4x0eo2J/bIWY+0TW+thNJSDmUN7uHa5SuLwM/h8DyiySrXOjJV27BxaPdvuT9E3POxbMlT/TAtYd+/F7G8KM8RxgYzF'
                . 'HNL/m2m8By6ykO3A3r34/WStfYvR4DMaQyK2XyTmBMNSfdEJQytCa/8KyVAfcR6JgSz0VmtRUi0atstr7XLnMH5emqdxqLRe'
                . 'nxlT7JK7lHp9skdmdzRwF2GNHUAGajHrHaHUTCuhiK3SI7NqRMF+Zzw+eFLREjhq1BrSfSr6IW1AIaE57iiHcJrPjZFSE2eV'
                . 'dbsx8WXaeI63GLbwFmlxnJgdsMgC8xb7xWC0Q4wEwRalAmxBAlBQgEBAoYTgkxtErEmjoEmh4DcljTapmTTf1QDYMafwbQbv'
                . 'ONEKQUTrcR7j7cwcwI6GpPlmUtutEZ1Pxgrw+VAghbQ6rwyJsLw/EX1NMoF0kvFrkXDC5dHB8UmfmP++l9pD5KTYqIBpJsLg'
                . 'LrqCPm3aFHz38mUN6fCb0TfrG5BH7n0m94HfZJQuPUXfSbF/v1rsFQq9YrHQJ5Coj4mPemmBCOHd54aOPO6dq917f3Nsc/Xe'
                . '0YpYRXT4QeyBcEbYZ5vUEBD06XQKCfmvmz/mVVjZW8wZIIk2bbpkbkna0gYkkYnaYmUrPJcaDJq2ff37NG2GVN89r3cZYm1y'
                . 'u+673B2ordXhOGl3LLMxZtU63SfcrgP/o9ha5xNZXzQwWQ1fsFj/b2AxG/7voDcm0OI6qdhSadIAjeAcv2PeUiT6Oy2UeXU6'
                . 'baxRsizuTdz0Ce85wkAjUrnfbP8t0+rGfsdAlRp1oqnWWJw4vYS74aevWkwwskACi08+itthLasQjZdVlJfF0eYKe8Pc7Y52'
                . 'CC29BXj8jpTSXV1iW7BchBrKyivKDKgw3xbsFjvpCGYCHjfRUqh9ezveeTW4NeGv/WH1zGeGhw1JA9Uuhq2ZeTIfx4Z5G4hh'
                . 'J4xhhnOIice2BK8Yjrq9ZWIGUjreGdLFj6IfTDl2CF10Kn1/CpPvQvTgsSnvo+8fyxyDaXVj9WGM6pKFGzf6VsYbmBp+Kkx6'
                . 'de07szQ9DqG7+wX51VkcXjO0nPOfG12HXedP55I0A/MD3wa+ufc2CL0DUeywN0fXHFcX3x2w2MtfOYtxu0/0nXq0W79Lrxbq'
                . 'CMGzm6ZX/EwwkCeWMQiNt0emlwGEcNLvy7q8H3h/KvS8Th7Q/o7iWCzBJ7dnD858aDOjQgb4sRWvtvMmivD2f9u4kGwpIN5W'
                . 'Ymb75TeOc6z0sEAoZMO2RLkcYMNgXYAeXzqWpEzafMp3TNa3FPIfLOaf76sVF1qEIg4spDAPFpFiUerS2NCWdYJYf945xA/6'
                . 'iRqks0IeDeTmKMQ62C8jq2+So/+1bi9bjIpQZrCOMqnv9Rs3CNNs1ZWUYLEZ2zWVKf+ESfBtEhMluf+Rd3gsYpulg6WInVrD'
                . 'PhFaJhtW7V/RX4bf+j0TyzqLQrIw2zxLc10G/Ozw0/+u5nxEBqkJKMZdgGwWHEVGtCBMiIjrDW/MtM+dhAKlhpB9oLCPhmmw'
                . 'XcRN9QSqjlyb/UpphRXrjWC0M/M+b10KTOqEUPZRJjCixyJ4CrvENpUkuo9CeykhjrciFP+/n9+vBJ3j6KhUVDPu80pAsC6j'
                . 'rAFhfy1FZZ+BW/RdrJsqR5qNfKsJcI5ZjasrZZFnCQFhvR3MpM+7zsTzHmk42U9Zz1nypSg+FWNhji6kKMWUHvRpM4NQ3vaT'
                . 'DCAHLMaLwaHQ9WwqU3HJ/owhlKJ9A21Klad4H2AEcXo01tgkjsLA9zjSWO8N1mQNm+R1QVGQX+7nNf94xbxPCoFKT1gQwfAN'
                . 'DynmaZcOm6pmxjhKrf197T9esQYlEGKoEuBwCwG7abrw1dRteLn2AOP6B3QqBXGyV7EXEWWDLu+6SJZ+0T1Vf0xOmnWhklRB'
                . 'E7UjB7K0DgoaNl0GYt7UhAmCOpB6vg6gn9HiRCSY7h4nMVfrFLJaWrk0Aps/3PcxZwDzLqyiSrgs4AHo0e0GZ7I+lLrSB+ou'
                . 'J9nDKi2UWctPAW0AqGnFnF/aWHoEHUNc1Tnh/u39bRlUKjMRsl4okO+IR7c4MMFj2ONAydjDXCD4gnJtsc4UOqSWTXNpg3nz'
                . 'ajEfvT/eN1Wa+PoiKeA7+RsNqJBzM0FrSS46jEDHsdOpbwTF8XgT07YNiQlc0KEDG+6E61lNBYfuE0zb0OeUYOBIjzdCU14m'
                . 'PbZ8JG8UQA9iFHu+HenevmpoDlwu8g5rS5UdIhn+udnbmv2niJTg+4QfRTsqSIkT6+ZCkOmhI6bN2J4KJqamIaWRjO86s+FH'
                . 'N+QcpKNyFocXzKdsxBRiR7k1r7uCTVnTkWWjLOTmZBAC5XSQVsTyKbBu7qqs1oGc4sPGulQGyVLSEJBW6QAhsnVFi2bFRlAa'
                . 'lGGg4RbasfNcWZxzbpln+dGXdJDJ0iCYinWR+lVRBeXvKaFdXWX5xQBdlJzXHUC4tUYeFWZaNFrzNVd4G0NVWtA/MjHl22Hy'
                . 'OCDSFrcOiwykF9aWkLQbmxSC7lIWKARCcCkTgoBm1JWiSMMEajg2tUql7JUyizgk5+ubwg21KiF1oM7ZeUdyZNMi5BsLvjth'
                . 'vyOYzQ+JohQfvt5WN5JaigNVhWrJAKEcmjlI8M2CUqpkMG/fCTxeDfqcJotvs+EikN3VnJJcJlChSAHmj6nsmvI4d0AdXlOT'
                . '1s4HUNJSsm42eHjNC6TBU+RLUEWyLbEJRhnE0YoVvNBHH1YvJrpshT2m6uo84KGzFci5ba/lmk8eikY2jrUqFC4EmnhXrIYH'
                . '22QdrCU2U5lmQIqh+Z/xXu8MsBp1b3qCtpVUhv+hbzgkY1K4oMdEbymfqUI6RYPZ3Lf/B/mecffefnffeORPxPh9bCMqdxGQ'
                . 'QnQdIM8x+k8nnAoN109RuPJthqJr/bySj1c0hRsLEGWGO3IRBUON6QIHJGMZ4AGEgjZUFAqAwswbb8QZYjW4DclD5HPDqAdI'
                . 'Lbb11yPFyLYE2Fyr+zQBNfWKljsz7ZwcrohjUVwWWybOVCWl0PZA0zyydM0N96WG2fR52/0BmLwrde61+Zndf/Y/aj9m5qrT'
                . 'nUVSrAkCVdgpgN/ILrFJpDZrkOLZo1JLkNHrpKy0z66AITTpoJjKB+GP6enKaiqs8syhjFyiAYpiQMbL9TDYwUeHIjmlQM8u'
                . '2V9JkGCVVExh7HGf4fpJyNqzEVDW9h1WqsP0GWAbrQSbnpaBb/Jlj326D8RRkeGA4jsY7QuIs5MY5MDoMXTLpZTaiYgudVex'
                . 'gDBH7zEh+hJTzA4g85HCykHTd2OMJ5v7YBc3Gai6Nvfcn7VF22qaC6s0CYxjU8Bdve72kFAEUANFOzUCb4UkpuPReVJLmaWs'
                . 'rRZZcFkkhZCKUVgXuUpI1hmHF8fm2V7a1jAyBGsiV/rAfgt5RNBZ9tK+2kREtvGCyqH7bTy7sHHg76UipGXrapk2ADmTM5KM'
                . 'SHikdXK02LEaYeOwdKzbIjRehJQ0kWU02e5UZEws74xRbLwIxRV6YVBt65QAaWPZajkOXG0iCTDMDYJ/bTFirA2M0j2WNUFE'
                . 'lKwZ7MUgatPnoEFlKUhycuTphj3LNKT+zh4JDGeIYiNM6YnK7GCQokiRDqIAFHPUKwlxopWlKtHFkuvGRKV71XlLhJvYNTyp'
                . 'u+g6QnBIdC5s1UZr72iENSWEBbBWCmdUgEhdU1P0EOahdTRH/prPnPht0DLiRqbZVhg0ZMCEMg1eaKgU2Xp9PpyeGRSiA0A+'
                . 'K8SobS5UXShs8tZZB8WKQ786ah/EqhJGA5hXPlJ4SgAGVOzywlDBbigyjXYG2C6jIR48bqP3xqMyBvi03G02WISJgCLnqreC'
                . '2jG7speUrC0DgRbxc7x1S/gIC58aUh+UV7BGB4i0aKrkrzBPrWPuNo2srAevaW42+ZUjcysxynF8+a8OqTft8KvNW03ZW4Yh'
                . 'bwFSm1pKrBfIyWjFdeRtNSGJVooMoLYkd0uWQVqIBZPGenn0Awu/0ZPf46/dX8q7LLWJefAfggskPX0xWsT5giqYpVOaCoq2'
                . 'BaX9mHzkeWh1ZAgLha9IbY2rivPBuxX6YGJVzugBkXYou3zWctk82wRlk7sYKaWGDTTzqqCCSXrtAWDnmLnHvaWQ9ehz/7P9'
                . 'U3HjNd6GutN31oI0v3J/bfL4EJNYYwpVBjbEkOLQICsSkoSuheSlnS3UPzBGtNmkj5/W9p4xP/2wfzw8bteLef9lGtq6yJNI'
                . 'CYoDAVXP5HkiKu9A88VxKI7lmDzmykCj5pCn6JZAGcnB4dakLgMi4RYXOdWHvsexc+h5bYv4F4LwqC1UMhG6u/jgWw+L/Mad'
                . 'UviTBSXxG8yCI9uxCK01Dfi4P4a4twd5Og3h6f/xfuzLPImMIt3Rr+S9eYtZguRASlhlRTcYrMkFKMHoGKaOLalIHWTg3un2'
                . 'PW1Tz6eaPgXPLM9yZZc95A+HntIiewSm0nRwtLCNoAMOuXGB7DNjHCnBKSK+dVTjxLudfOltcYWdDgwsRNtlE52oltRqm+Ie'
                . 'L1xkaWCkx6hj7WSYayB+BUAsHAc70SQCg9jAeG9MPrDwmLwDyBUNsqjkkUltrSTLmqbSVJ8Tlb+ScwnTMW5Qfq3jtizi0CjP'
                . 'JWi7ktWuaf4gXM0cNMpGdbbQFcbL1XBUgQ/oLa+UJ7/Xl7ux2W20sXiWz7qhYT5rQAHIQ7twXVmzXiCPyXl5QlZSnCvxewZb'
                . 'thKCW6tnuIGp9YNjUEGhU8EDcvQboKT0aJZivDNb/ZbaFzqy89wiSHlz5qC3xML6INiqIwcZJ9RX58OpVZGJ9iBlhgyY1ZSg'
                . 'd5ySgPhy5M422Fvs0EDcujRv7VuRx6Ehkf6jL2hXePEuDak5h6kWW2DlHAXFwDIqQcboTIDfolUhIq+drT6exDEsIijXSruE'
                . '9DAOJCj2OhY1V2xM4HB9Md/GClo10WXwMwJnpRV0yvkoOinjYrDACo2e/e3QxikYV9uq5+rIc8TxYZbtt07xbgWrp4WmXrsU'
                . 'fgx+J7m5zISR03AcYkJSkY3jacInN4RSxfKwNljep4tvsaNOFyo0PYMsU8xScqVQrwVgcBSpjBBZgErHwCRr1gOzNMtwMFtc'
                . 'mTdaowgrZSxawb9Aczg6cbZMPt7jouuQgqyZzefBLFwnZgNuUqla6GqpZdRwEZBiFuCABTruPS0HCRX9UW59Ta+C3a1yW0nz'
                . 'xaC62dlTyeucsihjmAU4rRnTBIU4dFJdpWEWwT3b6eKAWW7PsR0OvR0bu9gvzxpkJRYLN4ZZbMSaVFDSGWGeY6z0jj/2pPZy'
                . 'kaOLGUu+47uUjWzmL/gsIpP7f29+4a8fy+fqOQ5FIYtd9QW583WAPCet3HnLURfWRpeMCSlpEDAW/6s+nORrBjDb2ib02dZa'
                . 'kiImxdgvfM6YwB6Hc0pxXng5exI+d/sKwb/yff0zu3muX5tXG6tKV7s9PSvEdBZ21oHG1IF61G5JKiYXLDXTETQqs/u8eYN4'
                . '+L770fqxfmrqzFoCehadYj5an2+ce7Nt+K4qRkIsGCtNdYqTzPV0uEyk0YVXY2BASbniW9WX6NUf+WWt8/zPo28ZWcbwhiKz'
                . 'ijsAHOhTWeVcLANFSgG0EY8UCQXugjghClHyODsERRpovK5StTs/QNT416RqgK6/w1OK14HFcIu0s1Mcaul4WDd1T7pJEmX6'
                . 'RqUU6E6CYXGLrldwVKvWkZgyZ/Q7FoKXTWAEQKbJooQr+7q0cRR4b73gptt0KnI4bZAaU/cLImwyJJzTAR+d+Vkb7owit7gr'
                . 'pPw6R85MjPAsQhrvZAHdhH7GiGGSG8s1g4S50+w8uWGsPDZ1fE6sPvHYaGmFEmQwzHmLyN3myBhwH1J8Hm1SMob6bBaqpRyJ'
                . 'zJ7Kt35e1w3kLNGyhIUW6EfNlLHUohpCFql14gjWp5bIJeuIAIn5AU3wY5WJy7EjRVzIcDfnuiPN9Ld0IpWpZlB7amUMrKpS'
                . 'LV+/NZliiB4QKKMFtDo6Elw/v/Ljt9E3opd9Yu8FLbHCBGnO2xEfRfzdi+ztwI8VMtYBcibvbRHi17V4PRS1Kc5csJGpUE0c'
                . 'HqRgU/vx+UuURQrYUHfFTucSqFqoGHFTYRsGPtI4/WWQI7mNQ77kNjMzSz+YPDqVukcRq9tHY+qZxtw/ptRGgahl7Rx9rm01'
                . 'YmTVhM50NWV6ITOJMDPVM7PUytUHsk2jPjFI7nw5MmEt9chx19TV9I68WGdztKXACj5Uq/5kAbIrqy3/6kyV3OAc/5Crc+iu'
                . 'Qu3GDU0ZV7xSGCyMrt8KvY4Ar/fxfXoHZ86HW0AYJsFael0LC0mPQkpBN5inYh4vWy9GO+8MXYux3uQ8aJwKQIIum8ZAG6gr'
                . '6tzkoKLy/OrHezKL/vu1+HhF5aIq5zHgrO0bzKpfvJoNJS70JUhiFrDpR/o8/6We2GUtFgCy2IV8spkmld2EWx6cFg3uouJh'
                . 'M47n9DOKFyxjWwj3uoRsuf6jzjMW8tDM+CSmiAWgYFimWQjAJyZX0dUQRoOklYfJLhkOPhPStaxZ5XLOOfM0TyvrWCSxXr7d'
                . 'PNgQPQAzWiJ0rpaFGVhUMRloyYgheqvnuq5ZSE3rURPyQsmrwYhdUNaj9AIpuirf4dsrBw8Ojr1RURsBK9hyyDJ6OnjjONrb'
                . 'Nc5OG4Qs5fGlSOuKTmkGBsjjzCoUDopqvdH3mcUFJzm7gri6zx+Lx61WaacIp7X8qHIjAlMvtoWzUzp0PPfHGBki3Oj98A2Q'
                . '6tbvvuQZT/9Pv/V+vYbOyIFieBGLfDuE0i0VlK49Xt7KTXGZDpLnMlTnf8YxI+qDsq2yCa+Y650F/n41Ltk2AUT0+7NWVLEl'
                . 'JaxqLPcMywElTjEpf9v8iUKiZxje91XJR1BT+fnKGZaFbFMIR0olQ9YQG04zxUXtyXbRmiWKCghRjxwGc8Vbb3Fnsg1tUR6z'
                . 'cRZcGZyQVkaQRWad0iK9+FRIuYGvHE1oPYfZ+Uo/MhSMafwyKF2GLmHElgbJ/jTYEgEl6PanWKysb2qDXmXcZeepR3z1ZBOI'
                . '29f+dXitiqSzHUFgqiluqGt4mexGQBRzWP+U8+7yyVtN5gBqh7Gun/gZVA8nvuxr1Z5tpkveuqFqP4LXrQ64xTjGJH6ZiXDi'
                . 'L/forpksXC9DMNgBTQLL8g/q/xOzvR7OrCOBdmg/c1ztV3MA3mG8G2b4GZTkwLms5AGNtFz9OJrwI/vVOIM2Qiv3TFjzhIuI'
                . 'PV+wRlOGVTkedNZ00qXQYy3DkCuBy901dcJhPExqnSnrQ65I53WPViyy4JIOOYxQvwfCfso/ev3Aj7fQcwgv8o+2fSvzAE3M'
                . 'lHG0DjR5MB7pHaMal0zUppGMV9Ifx7ko1s57ssCS1NI7JuCTlbVp0/6PNq+uIH254hbaN+CgV+mTcF8gXwof+6jg+63kqqYH'
                . 'NcEsLGtCsA+KBfvpo3O+7/Q94b02ilEb1U7cJ5pXeYwUN4nKLXK+JxH97X8oUZTDknRcmGcHFeNvH4dAQXxbo8zzcrnIqvND'
                . 'T64TP62r636AjfNO12ZodjUCXmusq69Judtg89gg8Og19kZWB45Xu4xtC/HoO77538Hry/PT48Pd1A7t0HdyEG/A4FvPullw'
                . '4+EI2AZYhJAOqXI5/UJleuqPwwQkW1PdHKygXO9OF7CyySCZzECyz6NfYaP6pefaACtsWdAgFGXXk2JJ5Ak9LguPTfLyohko'
                . 'ilGdscxBTCWIsFhhuKUAWaycOw9RCHH14q80jWUe9lHP72T1enoWcUSj2WJ6GEzYziSK4b0AfNno7oS+lbmM1DeV1LAoXM1U'
                . 'gfUvsC6QLFcFi6yhO2SKRyMB4O/avFwpLGhaUj5ugQDrLtfS/1U1TDsX9N6zodokovK40C7RJh8ZHKiZTm/H0UpYabdyNzGt'
                . 'fbdjoxS6QJzZmq/RwAe9a2zRKbdW4owoQobe86lwaJqz0oaSOimoI3j3vJOTyq1VNDc/JsC9n6KJGzBmPNA6LJk+hCcuut1B'
                . 'kp0kCo1foeyNoci5k3PLNBHeaT/uW5UStttBO73XIfgZkLk5rNdWLY03Pm+C8At2UAHWj6QJ8+GCom5KKfEeQEY7ph5v2fhy'
                . 'npkThaDbnGj6hWQzNTAwZAWsYkod+DFgoLRc/tKf6JR83TnCUGwW1ONzuQIuPQaQYDltQXEE5EfYv9UC0A0FFV7BvPr/wciN'
                . '/nvr3Cm1+84No1qHIE6vvKkJ4afuYzXFbpAe3arACapEZdB9wBd4B0ET9p9MGcbUpT0UgLpiwSLf6SZVhRZUqJKoUKyvz2X3'
                . '4t2ddVAV6JEMFiQi4xLjDKQLb+xwKCni/AGQkoMbtEmbBwUE4nOHHESAisS0iWrkAEPsGWb0En2FOgTArppIsJBdp1LQgAgv'
                . 'IRHQha/KEvzjuxEhG+J8pzGmMKRqFYrKHMUKahEAtqcBTvMoc3zUZKZWcmBmgdEFg2l9Z0Q2Rudvgkla23chrjrMYy1IyKDC'
                . 'FNizhSDhCyYukje8fLXGH2BVWmvq8C03zwd0DoA489P9+8CYX38+fDh+WGD/ZeyrMk2MYuQY4r389PIHuw8hvITKHjwFnrvV'
                . 'zuPA8c17BnuqQw9MbRSPbY4CRWrbKDWQIhjaJOSdvU4fJty9d00UaoUuOKm52DyaJMhgggGCCa1LRBCa8xgNaP7TfrHJ2LNA'
                . 'YL0VDAkSOu8T9VgcfLApxfz8037XvDRVZkM5lW/li0qVp/EVOmYxZAOXYpb2INRFe+oKU/ZsKcZkfUuTovVXA/lf5PyGM4jt'
                . 'd+vl5Jvnx2ko/y789yAMHpl1W/xXjnQmdZfogcE5tCJZVlYkZZb6dUzYnZLiV45RhBHE9WP30D9EbRRYHVXaeDFcW7WkxOYT'
                . 'S5JXOr2rkcz67P8JhDuWf94r/EZx+I/9CNjN0T2JPcoUPGrocv/669C6K2MyxLJb5zjm7mm8F6fQBBcmCVnpmyJMPyAFdQ+Q'
                . 'YglA3k4J/RFgxWqTK8C3866vrClG7kYZdbHrQhZqPQixXRG9B/AI7TMU4U4mfDEN5ee3UZEFXdj19Tpg8gjfCi1WSp48lA6D'
                . 'T68vZAr12nsRuDCGA/eafefeR0rm6xuY6hAiZZeJMtbB9D+HgNIpKAumDKkjtmCN8gf38nlpAZ48L2NVYIZpYWd+NtKPbZhD'
                . 'UpEA1hlxQRB0MfGBs09eTg1w4JBgXuTY2wWRVHHdqdxu4N39i9zIHMhMBF1lVpW4mJBfhnJyErAWhYdB1lqmB08N8bOjG6H/'
                . 'CFzreaSN/1h3Z/WhoqgzLrx8EnP7Ii4TryKQTSfJba4fzvqTvWhbxn9ei54e7jytd8DNwOozn8FuoK2x+8EHGCN0KwOPPcJU'
                . 'IVYE4B1i+MhIWPToQgX4KupyJhmMYJBl1AOASMo8qxK0NK6Wt0GUg/RAsE/xsCIZr1774RVsMlDtGuEyMjOalbI5trGOc4EN'
                . 'IvpHzOfOppnGFmDLdplZZlcaxlaDCyMnZ35bH5AxUwKw6RzWVB+ckrbHQj9/huOZ3Qowq0DK+QLqFZpJVRRiKz3a2kIbPIbQ'
                . 'l3css2v/gKXrGEecIQRnpOjnE+isRx2ivoFgiRVYQ2sr75EtrieybremueP0ialzZxXHLuV1ntlJOpuVHgMLbIddMOyMQnnJ'
                . '425yYXBlXCvAUsiw2Q4eAEvdZoU72BRh01xKRJhP2pFa/PNJNfWyYsiF+EUA8/dD/cLF45owfP/4B/fyymZbKDORFTqYXZGC'
                . 'lWp/YwLj7Xsz4K3zxrk8PA6ZuYNBcsG6BD2FSGysleCrOHuLAGTSIx//9HPM0fwnNuPeAdD9y8NTAeg5Le+dPrP7V+Zs9gFE'
                . 'GAAgGIub8k4FeWwE2X2+/8mvwL7kNg/OvxfrRvoxREB9n/NPKqNuDX3rZAEB1lhmZnW4ooWRYCkrsA5wTgB9codso7XgZ2Sg'
                . '3Xvcj7ImWAC869vJ9Nr4M1k9fjP/zuTWJy3fy4LkixTzmbr3RWyFQfBeLhbRLprOETnR5mp54Wz6liY2BshBTTtYOrUoFPlo'
                . 'She3ThJriqWPGLrIZENg7dmJSZLXf45YrbrQ68Nw00rX0FiakRjI7EIwKT99Q8HE4JvXZDpd/uXdzfTZza7+dE01ctIvgt2U'
                . 'bawe1I1l55HfufRHbvN/x3f+MNlKk+u3qJsFpJO6syR3MV2vCqBm4XaJHcbdm2h+1qeDWiVB7yJnqD1Yxnbq5LGznKHuZZPH'
                . 'MfMEiNy0YOockpzTdPWgGCVhTMSGrambAXhzu7gHRTSdx4TLikJKPKQ8D9OntBkoQd+G8r6L+lw5dVKXn6jHkWf+oM73DFug'
                . 'rhkd7NSmBHA2kWMC0N8tO8kGqnOL/sY7SM43UjJubbOMLjZCveDdyXUJumRRvzAahzLOZwHtFZY/sR7mz9GMOchuorjlFU2W'
                . 'Ww8yl9Y8jHen8O8L6ubxcXa+p8JGWiA60OXsqejhSk+n8XB5TNd54LHSZvGYoTSAVBAJ6U9E2dKs1SESqNxkjvz1Fn8NpJEm'
                . '48gzgtQHHAL8o4ohAGCcpZsD2HqIMQAAECvDoY0EwMkEpgr5oUkwmDGN6WBOmPuDea42DzagkzdYRM2fmeqFQFUdTT98pKEi'
                . 'iW6hqQ4z1jQs4Z555EsgdVrSk0v3ToFPCDSFlMCzOnLsFdBafErdJ1CXZ9TSGpeWkJKSUcW1MhuslSLEccFQM7anLcRKMSUr'
                . 'a1+TH48TPA7D7cq/eB49eXShitWeca9TOYtWpEeT0ARaXCacm8a9ZxjcSrPn+S5grsQJCcKhWTRWeS1jaVgqt8w1JJimJZBk'
                . 'AQHQDMhvGEoa27EvPqbpDWbAsfv6/QOwdyMM1xTZo9he8w3hoISj99RKXXfTLU6cuXB12x133RsB4eP3UsbbfQ+Ue2SBffbz'
                . '8Znv2Agf3WNPVHgqSLAQGh+F0q4eXpXRRlkvhs4YsT6IU0tGYuy4A60kz8ZYurg+HCaOp/DVHKrBMI2atWiyQasDhvtihJHS'
                . 'zJAuQ5sOndrHXfhYcnxyUu47IxDNsqdVjO4wOjLCH2zNZIuQKVQGRiZmVphQxl1PSKWND4TTfvWb36PAxSbK2EbFghlrCrvw'
                . 'NlHZ6JJpTIhIhTOPnYMOiWLEWJ4CYSJcdmX8pP8Ejtlhp7POMSCwIzHdVLPMNNsk+d6Z7AzD2JtiaYYw9YMfHadky8YihTaL'
                . 'jANxHKOOU5zjEte4mSvaHM/1eOGl16UV50Yd2jCHCZ9M//31btYWdsJHreZ/DIGxNgt87p9KAiPR7J2lEKCh2cmJ+Y9rQGI+'
                . '9zkOoq+N2BY5rIFd8qkV6JJ8CAW6PCzEkKqH0WsCkBcAAhTImgBZECAAQBYUyJoCAQJkUTfTor5UalF+Ls30ZuNnrzBqnlr6'
                . 'jxjMF4YAERLTo++8syWskrUtN0RTKFF/N1qPfj75CUnhi75MptE5DX2+dbbel/8NiHEYMwNjTpmt5vqYJvpWVOCKba9D5CmD'
                . 'hHoWLWp+wAWY3wO7e8X/vf/Oeqf8fWiTPvxtxv1h24QPnN+u+LIzr9pI93LC/BzuzKGDdyYWznpDOL/3/OY8jpFBJgYi9gBF'
                . 'XwUH2mftafqVsf0RKzMmqERStx4X4yEF2hutDjjGS5wUd6BNS1Nx6F3YPZBjrsy+sh3O1PkV6pvyDM95VtnU5w1KdqA9BwAA',
            'family' => 'Be Vietnam Pro',
            'style' => 'normal',
            'weight' => '400',
            'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
        ],
        'be-vietnam-pro-vietnamese-500.woff2' => [
            'b64' => 'd09GMgABAAAAAC+MABEAAAAAnRAAAC8sAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGkAbj2IcKAZgAIMECGAJnAwRCAqB8TCB'
                . '3GULgWoAATYCJAODRAQgBYQ2B4h6DIE9G7GQB5hn/Qa3Awu63n9RIxHCxoEZyfC6UVGtOSf4//+e3Bii0AZqvbb/TAazDEO1'
                . 'cXasJG5HClvw0Ra2Yu7p2OVSdUEOlix2RD9tVicTVpRhKSz5hLAemigUSvCqIvm5QNDo0RpJlq+v3weOopniFclIsa8uPvNH'
                . 'tR9j5d+F+opN19X/Envvt4c2OXs9UVi8hnWHNZBMtzkD3MkRCTuPaK6q7h4CmZAQAh7FQsCTgAeN4EESSCBYFrFdnmWBZUXZ'
                . '3+WMM2H3bFVYM2fFYM0vB7pVM1GiqEWQYJ4QZIAVteq++/aLbr9rxNrjwX4M9rY/XBJHgpLEqzXT5KXgoZEgi9l1jtI0iQ9x'
                . 'cOuBccaRl1+le3sdprtnRoADQv+jj70pBY5be1UNDsCeXK47pU0W6bVTX9vEflB5WpkTTSCURXTroH1CWS+v9YzZrR48nzbT'
                . 'DhQVkAJf+WGPNkAHKFPCdywFpfOei9pFvV31tIeZZlPzYVfD3toJrZjyYuQIVIZ9ysOxghkwOxH/hfqhAkIwl5ci6QcSGmgk'
                . 'VI2cqWJzKK1aMs4Xn8Swc5RWkn/QDsvIygg84n75Pidt0yPI0UASCTM3oRIB5rfqV0nnqBw9s4v8Dud5WITkxzbf8ic1JIi+'
                . 'QATTIBU6YeKH22nuR3O9dt5OcpD8wrQsCYxpdaXZ28PZ+cQpEEWVSChyAgBkhflKVprOv02t9v8ZaWTad/IyHtBibcv0ruSO'
                . 'Xk80+pr5M0JbGsmrkxRwHJkSVgg0M39GaNnRyV7HT0F2cuRjJwqxDwCbApCre1dVacqt6sOirg4Mms625YJ7pXCmFRrjuhxz'
                . 'KN4ypmIbRyVKgmQbLTBZyjwyOhYJ7Nq3T/f3K2tgrsN5DIUQIyJEtC17/+997ZdvfQ+1yd3byC6HsMotxOlfp4tsFoVsvJ8x'
                . 'q8yuRYunCX27EBCMo/Nz/jACSYdoNEJGGeN4GQbgDsARdko7UDT09wgATaYAXr8x1wicI/s6WoGDAgC2Nj5ozjl8qL0VfEe5'
                . 'BwRzm03AFQAeIJwM/1Nqwnj/6kJj4EJoCGJmhiRzxBBKSsKbKw6kd6ghu/OZsDQfQd2DC7GOQMiHgXsEYLcY2M0FdtMB7CYC'
                . '2AGgfufUiQkQAQHcP4dQkLpGCFA3Mgf1vYgDVPurFa7JHDVUeZVUgUU2NEVyxZmqAEJRrtLWgMRm3uDLcH02h3KgrByTT/JG'
                . 'nsmjtKntDnii63W5WD5NjpL9ZCfZbMtryQqyiPxLZodgs1/Al/Hd0S/JDDLZ+iDdpF1HAvXPryZlxEj0JJOkE9WPh8SQSA0G'
                . '/FU0cqUn4RFHwiCAOv6w6hUP4wee+LZeBc7jpB5uDey12XbwZWwsr8HL8AL8N/4jDdp+Ak/oQ295OjmKJ5KduMuW23AzrsWV'
                . '2ByCzYrAl5H7fB1OxQk4CodjGfaFCkjd8X1cTGMK/Ufv6AU9QffQTXQZnU31eNCD0N0j5a1oPVqFlqB5lv7cBwjfFTIagWtm'
                . 'ZWwBmn0ggbYxG0KBZ8FY8zNiEGHMI8V+7Cd+AFJGuLPuAKrFUQewyDgWTONgUeMHlu02L1Yu4iIu4i85n/MtV4mW9IqR/GUZ'
                . 'ZHmIZ3PGQh12GdWXdO4n6cPvJQE/lBSSPhtPZqk6+dMSmOQa7kdCIMDZ9DEtE7jEnmBnszDPyEmTWzBmQ5tbNwagcEjMZPNs'
                . 'JcWDOsAiMXnbpIkZ35bRZKtNMqSHchMNeW+TdLaWLJoEG6jnHWvTi8aWWxNstNv6mfgfHsH9fIb7uV/mlljmVM3s0hqvA2Ff'
                . 'po0mGAXGaA7L578OahQImpWbD0E1+UwfmQ2SV6ViqTbTxpfno4q1rJe/gE202QQyd7qesfSiFV2VaLjurDEb/WM1S+NbW54Z'
                . 'd6GrYOAGzXA8nT2wqgqI0Uiy4FYuiMjqLsX05DVJDgTP0gzux4cB3OwVdIUDnER/NFQogE6FjhMaoA127wBAIn2+X9Af+39H'
                . '+TPreknvhom+aNrJHQ2P9WVo4WmP/+ABOkHhDX26fQisLXjYYow7ONp/JDTcS8w1/Nvr66vmAEk9sYx54U8j7vVqKQFDoZ8v'
                . 'i2iZFuUunU5LbYkhSCPouXVCccaHCcgQQeQYFKI5iJEDyaVnL58FUq6WnTqNmDAezyqCi/syZ57seOGjOIE1+IOXhYjEJidn'
                . 'z381k5oahyZKEsiAxeMtYeGoXAUHlWqw1KrlEBelDB3DACh+N6KGvnD7faa/Skpq5EHAn+2h01NZEq9MAPhcmgHkmCOj3RSV'
                . 'rPNgCcgHgKHf6fxDgp20pZfHg/8/oD4HEwDNAwB4bt4JdTM2kNhdEyfaV1PRdm9/EhQK+0NL87ddQZBzBEVT/BOrBiIUYRK/'
                . 'Jb+ulxOOQx8HI27IojH+jpUDSGR3AH1xkZ2y+2cf6SVetX1u/x1NRjkHN15CsLw3AREJ37Xg9r2PH4CIwaXGpydXy0+JEJVf'
                . 'ffxfFkcaJ+mYVFiSOUrBloqWhBKLIZ69BA4S2YnjLouHbJ5yONNwoeMqg5tMPFre8njJ5cPIVzGBfCKFxIpIGAgV8GciVSpQ'
                . 'GRmLAGZBygWrEKpKOKsII0SqEaaaUj2FOlEaRGuk7WMEWFHtjot/Jg/YVuRwv+H3KUjYklcNweIFd3eUAz2bGfZ/Uhej+39x'
                . 'u/uHNAW4DsMo8uUcdP8Lws3PwHAV5ylNpbl+hPH8PyAB+pzogbEWuAc2OmgBaZO8A4FRpg4GatU6WEh9xM5d6/g+UUpKvjBz'
                . 'fV/KvYThzkxQDYb9XMNdzlqmp4fJY37dsc45W32+u3NHcnOob6NpdqSUAmfhMLaJ1DMw4OZggzgDs29Bgvf8QI3Uws8VLv9s'
                . 'nTTO4WSIsBcsrMetX2FqTtPcKWeXNTsdPlzLdPcpSoG2XOMQb2XHqK6jSwA/Z5OpNN81B2R7ajgMjF0uVDAG42CKceYkMI7K'
                . 'CXARgMO93ZZFQ1Rh7sJJPg/JBHmGc14+YqxzBCPGTED8FJKg+ujgIUOB81wLIjcConkC5o21ESwOoewsg+gpYseLF5q2y0wD'
                . 'GSyJ5WmDb1t8ciiN8ftHitLtd7i8GdKGyVsxaLRRoJsAhpGHH1NJhwgk9lpgSHwQmF0yrwKc98ownSnYDSK2t5AESXp2nZNz'
                . 'Tj42O3CISvSV0kAfS0YrTchm6J/bBQQU+YJyqWxnxlBgA+dJho1dpS66D7IzMwtjFSrcPLCueTBUNlo/XkqvIahugI4m9kk7'
                . '0saElTeCNWrjc+sIojA9e3zVgSMEsIgQ5OeerBl2MhQJWeEFDLHwhlGwFefTLuecfDZ2JcwkzZsSNq+OCL7shMJ9DuJDtC+d'
                . 'OaL4shm+bL/qNXajo48dQ4FIf1eMJEuA1ROOOLUexn0JnmBoEXBBBdZPRynj3HDum28dvi3UtjqPbS6DA4zV2i5dkSDHKxus'
                . 'TYVZI5nLUxaAeToACSxaTOn8SYujU2DknUDEvAzRzaMZbPGSzlJLXQl3rnS890KA2HmDtcSGgt9j9UF0JFAg3nMkUwpBimJA'
                . 'mmJBhuJAluJBjhJAnhJBgQqAIiVNbEDrcEiE8mUnqFTg27uGXZObXqaE4btmy1Qx/LzBDHSYRb5TwXpyRCDLUwYTBiwobChK'
                . 'UJShqEBRhcKBogZFHbq/DVlauNQ5O5Eq0te89ee2h1dsr90ruqATdNq6qgeRcUz1qk7RPKcf8ORIhxEB9LnDlKIP7K28UvTa'
                . 'hU/zw/AyZusclEkDtn0IeZfBhBE6kGEvdgiMlNTdJ4XEMQDWr+hcGab5nN8gY8iSjPMIApvlm8gxQXVjyeGGtIGczC3qoTCS'
                . 'eCSVhgD7XdP1qfk+MNOTJRyY5nsB5ls3pfmrzx1TAAmSq5fz2OZ//KLKGoW9w6ZtIkuN+2PB90vF24FgnhQS3lrLSekqHHF6'
                . 'Wc0Rly+vRN9wp8mUxia26GjPK/Bt60hNj/vsdBCOqVZ45npns95s2JEaniJ1vFlF4LKO8pxRZI5gHJnRXmeSjUPBrgP2lLbB'
                . 'e7aK3iG4PBrK7WzCN9S4mB3RMZPELs0AnyGoS3M9prmEjBNHQIOjanoWGNukOMHZxr2uQiVLag0+OiQ9N+fpFbyY8ulrGG42'
                . 'VVc9XzWHLX3P8o6qgC+O9IWO4tTxXAcJOo7EZO3xkwwbZisGWYpV6aa53lDt1dNvmY3Maw5i4m5vwDQGnn0L+NEYB1prBTZu'
                . 'yBwIrOi+BNsSJNapK1cgmFF3rgToKupsVCqdixO7gyL3uQedl/YlMV7NKDfUzrZf0jlj103AxnTB7kSY9sSx7xXZI2B1y04r'
                . 'lALuu+Jvf18L73TxSrts3K7PQdchCmDWy4TbjoDnwfHRIDFUqWTU2FU03wkWthdzaI7xLBcthaLwHPIzBAKHQqFQETkUZwgk'
                . 'ztkZHFGQKMgyyuUI5BkVFlEARUalHIEyi5XYkF01KKplCNQdagiFhqZDrQyBtmOd9CBxN2jq5Qj0mQ0gAIaMRjkCY3Z6gkLv'
                . 'mbSpdQQul8zAx3nxmwrCwQUKsLRMwasUtJ6iDzs2yQdp8olbaN2wa99on4IOKfR4V79wSr5wTr5wSb5wbQPdUtA9hT5Q/cYz'
                . '+cYr+cY7+canDfRNQb8U+/9o2Ve4f04EYbXlenr3glpjY/Q9Al+wWxuzT9ovcEEhqNssnazDJx4AtAK4HPBeL/+KC5IeAEYQ'
                . '1BYkoDqwFb4ddIquh1uPdr09qLujWicjuK6enkzWHKJjs7GN2mok2kV0BEFiI98Ll/YdFKVkRShvLEUWzoYBnscaZWpEDa81'
                . 'Ze88MFmFFZmH9Wz2pupUz57f47MIJcVlfCpsg6NMTYBrYKQLBImNfC9BvyMjF/coU0Pk8FxT8Nwd40itcZSpITW81pQu4eRR'
                . 'MRhFtbKVn7p2pS+s9Re8srzcm9DS3K4KCTCi7Wb16MQxsrD8hCTD0ucn/B3e/ucfiP1//30T679laX9uJ7/N3nqwm9jbrL9G'
                . '3+8N47cdXXew/ttg3jqhhMXG0Iz97H+Ece336O91Cf1b9BuUPwaOsP6n4DG6Ynntb/OSX7CyedrcHd2bFi9m/fu3wvP3VmP+'
                . 'YnMT43yK0YnvZNm5q5GEk6Gm0edwkObUVU5ZsJJRjmtaKeulu1cQoRGLdLCRGeazj01baGCdv2BG087b6axMiOl2bHD0mGHC'
                . 'a5mNUsh9tZ3lJE1n+SkNKyHtcmiPqTDY4LCoQQpmUb33Z1ECB8d9eT4yo2kX4uakteSrati/LLbAXM/ZhKC0pVXRrRfSeunH'
                . 'R1gI/U45tWZOPkP6Di2yNjNCrMJ2asJsjVU9VO9I2uuqZ1TNhZEz6l1bvQsxGREWzomsEXSxGtxPHGTUBcMpNQhFWE6P0Ema'
                . 'noS3SX2iNhTFDEeOqJf6cn5iehN/RlxvPZvUs0otrFq8NDyBtOgKr5LKxufmlj2WmsP8dU7jV+e2ubEQi6IR/dPWs7Tr+Iu/'
                . '9A43y19UGw99wRKrTW/KmxZ9gUntnFgZOjU8+p6Txu5ZOUnjdVfx6U45bMllNU7vWopXH6cRqq9y+pNAmLT12Tj53onOXhrN'
                . 'R4GFhsCJ2U1f49c6taJUzgRMv3TOe2Jmrzp5tozNnbOqWWkrVlk3EFJa9MMjXF/9zrm1Gk7qojl4IoLMAB1wkquUSldezdaz'
                . 'Cj7VrwqWy70HMZ7ln886Wg6qpqcv9Rjt87NsSbB6IBF7ifZzrqwGkqqdkydxuNYqIGobMaj1UefRH1t9lXJSrhNhdaQPFNk6'
                . '6XL2F3/ZFoFTjhM9lxD7Hyw2jVxfdRQ5uLxK417jNoPsVe1Y8qhF5Gvh632VMeoQpGQn/iwnsGsBQtPma+iCsrJ63Fj52bPF'
                . 'C87QPZU0a6lxESqndm42BeAqhxYXTirLFh0sOzFQtuZ42dFVI68+ZkRsaFE8Hb0I1hZoYiVRe9UnpNslI2QMGRr8BQ9OiJuv'
                . 'jE9vYQ91mkO9lYoyMNxuE6nvv3Jj/Hx5/AXFeNSEKoZCPobCGd0/aVEkZcfkDjT0ceKQx8tQrvZsb+VVxEH6uXZ27kyi/uYX'
                . 'ipsEwkaxsFso7C5znXu1cW2bcUC+SjEU8lgF52I+S4ti6ecYOKd6HDLkMQj0p1OFGzkrYc3Yd2EfnxPt5srVUFkObRFBse9k'
                . 'fkGgXOi/EPJPpVH8PUP8ok3tHOcb3jrxf91SvmFjO8dpmK8VD3c371xc+17/muX0dQ1GTfipMg5as8w8PFcdZrOiHQfCJspJ'
                . 'us4kUS7ffMPAD1N5O/rFPXMtc6vWzYVrnNurOkUWmxrXr58m7OyEsaw8fit/4AHfsHEMx/kGXyf53BuA7kPtbKenWRaFu23G'
                . 'KNoWlhlySJWbX5AP/f995/fz2Rq+oMEuy/t/okefIszxC6akVtZWpn4um5Fsbgrt0Wotm7Kl34sydsovl3qSLaGqXGGlQiGs'
                . 'UuVYwpJ7Lv8S6zO4Ab+AWCTszmB965nUutSbY+oraWXYK/43D9WmREMrhQOWV+dbO0uEhPJV6JcIRXd6Ke1NdC/KVjOqXXwK'
                . 'by7JQ50HgyDWL/ZSJ+hR1i11bPJ5s0ArGepuSJizxG3jnc47CQ9FImnR5UGMgap19yok6sqIoMcqDJQL7V6ys29NIludwFdv'
                . '7GGLqr4u0JXRNWzvhvOkgxvW7byyzZlm6se00lmplpFhszIywmZaWkre0rKy2AIrqmdZRp6wuaBFHztRFyWuVuWVhaoSrZgX'
                . 'WqJ0Lba9nslJGWvHRIK26rOXjyLx6XwHNiRc0LKzfclyCWvGuvueVCHRxNsUdYKFmm7uplpFyhdrX/6UL3O9hMp7dNhis5nU'
                . 'b3aSx1yly607bEiSqaLyD7U946/QsMTGVOUlP220bp4JLlIt+LH0rnOm4TnuJdCtXkouod/Wuz38FE1rRxsGCEkSUvwUj55k'
                . 'sM6xUimuyDkil2yyzhlzNffzsI3GMIunpWZOtJzrPJdpGGEyW3tmTrQ01p+ear5Ryq6Jcj2Dmg5RjX7MKV1x2E+Jl1Erq1SF'
                . 'LCilPjUiKZr93eRvyjLvAYUFDLdH0+Q5V6/boN05ZlCi9nY3paLWWkMykARR/Fi3H42Gg5qwJv0sUBacx9FKhnoDBC1s7t+I'
                . 'Ct4a2qs1SJtpM/hj+kpGj7rtv5vNfArfmLq1cVvjVHKDitk1e5S8wYjGqFiN8fKdyRtEuxgC87JSXbkawy+Q6xA6r8E4ftHi'
                . '6Rxu43jtcG+AAGno4P90Nbonjuf40zNLlbaI6xFwJv5WvEQS5Ky5r5B4OMKW/1Gki8f1MfLbee35jL5x6WKFyhyeqBdYlUqB'
                . 'NVFvDjdWytOiJIVhYZLCtCi5PLVn0y1Y5FFDN+p9pBdyyW3WvH/IgrDagp3OZ+jGv4/yJG6lXTwlPiAkXvRWueX5kn+QSPxa'
                . 'bdyW6Hm57e2b7bCzbZ9TNmFJZRy0ZjXxJbB9q6M2/tzl5inhqm4VGzl8svbum+PSNnXfc9C9Tq8aFiT8/iiw/bTGJ7roX/xL'
                . '3gAembQEccNtkDLnuFSYR5rRa+ytaK8wG80Z2P09K/mT5bjD/Mm85Kliqfm7yatJt4Yn60VWpUJkTcqvDE/XtiSrxlvE41RZ'
                . 'prSAdyt/hb4ffvQr8D2UxsTanDR5fFZmXV+pk+qPFO2pEjeSN0PQvXr/JfdMpH5Fr99yYh2QY2I5ja7f2GSPl24+SR1EP7WP'
                . 'QwKlbFPbPIlp05DerSQ0ERFA1+ps6CrjAwdbXC1ouKop9W8tfEbd65KhcfVtC/6pMN+MFI8TCLvF4l6hsLdkJK+dF3+4LTqp'
                . 'uYR5y0smi0s803D1zm3onGOh+xULGM/J0eN/rorHtGaVZpZm1ZZ05SVNutmDTZNLfjVWcbU8Xe5bGB7hWwCHvLu1ICLct3A2'
                . 'Y3/ZhKWV8V62ZjdlNsH0j0xteKZ8f+ZKE+v6YdqEnytp8K3ZmoV+OXiqPkJWArqAj7qrfB/Yr7PL6MbNXnyU54AHP33TdPa/'
                . 'pzK5Abct6usvYhvZODomicg5HuxrkrBmrL+3TyBvJQbVzypksl7NNPXZVf1QdRLT/wODUqrTwtcP/BIySvU7RYRqtdynxacw'
                . 'DVd3VB0QVmOOEQtHSMBavX9ggQYd1kW3jomwSmLqG6VfUNW69UUzar8p4n3L43q4uEwW90j4VMNf4JyZjfz0xe0c7g1+us+T'
                . 'Ln++emMNxym7LyRP6mvar78dW/Q7ZkFpjaGu3RTpHxqS6K4JkLbz2nMZk2XH0AGr2C67knemgoqrwfL/+liOr1TmHNPlgQr6'
                . '5Kv+OQUusGaOLmKeAW5/h5tCLcC/7d26DszSzF/w/v9MhXotQMW96Da80Q3sp9HXIm1fmh72E9j6vGweFbu9XsIeL9pEJ5t+'
                . 'yLOj72szetcBVmD9BOt7KSUVnW93zzDgZPCfWbjqc9NVHKfHG6K4vIaGv70Wx3sZch9j/+d3iiExqDxKadn085wiM10vZdQ2'
                . 'q5uj66O1au26+nhG6VAGp0hKZoqFs7A0AM8UiWeS7Zc1LzQbKiyiRoGwQSQaKxSOfSDqLhnG+pVtJvulrU5WoORZiXurfguY'
                . 'ro/TSysLpdwNVomqLar5Au8rC0//ragmo4L+YGBRPgGIHfMpE/VoQCIc/p3pL+l6bAMdjWmPAIzgCAE9pidU1yrmLNJ767NF'
                . 'p1Xr7ZmhowONY9u1kJX2HWL6Hy2CKs5AmIedMFurXHJKfbR9SoJnDY94/+H9gzWQUjxhwE2hzdiuKpiN9gOBQmxWiGKvtSH+'
                . 'x8FnGpElTkSsVa3ZvSc75V6EF584xjFnlEShY+layTrGwwwpdDW2W0UtTWxfyiU/Utio+h7t8Q3wmhN3Sac41U0Pl5PSnFCE'
                . 'BCR1bU2tAGBcBA7k22nYbv0xxxr/8xEI6MxGkiB+mSRDigsKoOIM8ZCG6ZlFqa6cINcabDnnXuxzgGGHmT+t03q/XS/L3NZF'
                . 'ximMeNz9wI/NS7Tfjb+v9EL7/z+y9Y6mEjoCznkgAUHkjOmK4Ya/gB9MJezMg26W3YnutYY1V2tfzGkP/KgknVEBKWZQO0zr'
                . 'QF9ipwlq9UcIJPh8Ot5HEz6EqanylJFAgHBsU1drDlNc427uRkeRCHqUxHslelpZYL84n/qkFTyyn3Bfq1KxWZ8st6rrxXle'
                . '5jOk9UWnRYufm2XaXtgVj3AbSXgPEP83M8n9JUwk83SiliLHI85Zq1Kwah9sFdkTkE4aoRkijZa+XHS15IDBvjPvnC7XJKS1'
                . '7Z8hjJM6oCCwzFlZKJE8BbJbKShvVId16nIOIM19KMhacl5gbA+35IFUO8QvBkZ5A4uzFwI9gs3YgZ5LccYuKzi51Mj3c5BZ'
                . '84nMteoNR/Rs9xbADo06L81TFXmWJjEwFFK8C0LtSJu8R7LXsWX2w5+CBwGH5TwL9gyviz+CJWWzXdRe1L2s6rFDqiulzwse'
                . '/tGtd0RfCQBpLfQh1kq77kz26D1MjHBdw2TB9NdqrGBk3MYDGtA0cKVm6by8HRukZOlR/jaKxygtEAmd13Wdx6njdsdlZM0c'
                . 'QIG6batIUjO6Ti/ZwCEh4Q2izksNRSQlpD0hHDx9jQV3mcTFC6aDoW2eM3YfeH+r43uUyBZisY4JJoIdAQ5SuXtMVZkK2yFP'
                . 'bakbI64epbCkFOrRRSFpnKDDuL8dU7okU1zGQmZMhVeebB3dE5BJmZEV7eckQx6dUHX8ZW4KTMatUYwKgSEGG9bJgxiMNPeR'
                . 'oFKoYluo3ahO/ZqWAtVolmvJQplcjpA+XY4EM90003V9jdgWq2WKaOrqBpHjL8PlwrMOjYzzcDWugoOOY7sj3XmKkKPBvctp'
                . 'gX3Z97i1uF/XHL4agQuiswA2zfW7oUg2tELPNhlJ5DdqPs4ZKMRYjmSRuT1CBBOLBbM0iRAss479Ykl50zYwqN8nx6my8lwE'
                . 'sqWElgQYc8Rdp2iOBTJPWclHGxnOVkBJeUxDmVu7lN5GGbvHInJHNmdaeURMd53Mbsx3jBu/MnC14bCwvNTvhrbBYQ8Tt+XA'
                . 'QGXyqb5MBY4TC+dMicPKhixGKV2lVQRRyePSbHFEtn1YlB+HZAtApulQq2gMpL5PUudh6uqqGCnjKoRaRKnfEC10Nn8+oIPK'
                . 'aa7SMFXAakQbaW29gK0q3FNLUhqj3YqyHvXVDxno4Fjncz0PlFzzChbUk3HJwx8RoQ0z4OqU5MMVGb29tDAcCBj221t3S2No'
                . 'cGOg/Z73ideiwvSoRJtREnSnVUMe1qnPOxCbe5RqOZPzAKNKO6T6acywgKjB8XdHg9yqLmdvxn0k+x3H3deNFuMvk91Wgy/x'
                . '8kWeYxkfVlBqt3Hx3u2WRvdWQ1hqbjM5j3Jn1qqQuIOX8MxezD7ZIxcwlE7D44WP37Bfz5fP451mnc18rom+eFNwkiZ9xDnU'
                . 'ENmqiBlNAGThb7ViWxK82chELiJspXZ6FtzFhYB4DoPdLesmdMgjRHjKVmX++2Eo1THYWwfDDt6k37pUKUGHu90WUefrbYGg'
                . 'eHdzoML/Y0YTImsViKDxukWylJm2f4Ee0vPwUd5TvBBK8HI57KJOR6rgBo9Em/HXl0TOKFUwARniNhBR16AC4uNmjQMenTmV'
                . 'OfZ/YC52Hn4HgpIWexFe/7GRj7ZlKOGZeJ2hI12ZZyj5oypPwUw6wIi1LTwPT+bNDeW7/XgAHw37dVjNtpiBcysIKgGI8Stc'
                . 'aImR9JU5+MJfcUfl8gCjLcBBZp+bB5wOOgaBgWImTpz3on4TIOs1lDWBch9r5DQE2L8jMsV56AkqeDePW3RN7r6332yUy1VX'
                . 'fKXk63vZYTZ/Hygg7iU0A1oP5IHuhu6YmpytFWZj2rmtHwAenWcUpLgADWSIQSGUbyW8nAAbHJoj7T9Ryst5+PmL+3squ1cI'
                . 'JPh2Ofnq7jXqRRxc4NIAtPfAM2H4Oo6bDLkCDYcc0xaxidZrtjRQXK+1JKrN41MEGkas17KE0cF5qgug/HXJgwnpE9xpUqQr'
                . 'Szmwvvr8X+KeoghXCdNAauLEwEkYpQIhkHh6fOpJmQYBMhTMmKM6Y/MpMPfo89hxPtzsXccytFZyQuDlussG4eZVSXVPwDC6'
                . 'pGV66J6yqeAAXrDCIioMNWVQrQBJCMS18oVR4NwKggn5HHQBmTzAiFMA//iejkAQIM7wpHiSInj9qjT5gyEPdRrzW7ccXAaU'
                . 'l5AvTR/0urb2J1no426pHrTA+2nEujrkjV3ZjE/1ttbGbR84WcCGrRMC7mBbKHTDJcWJyxNKj1wAQ3HusIfJ73XbZt2MNKak'
                . '40xNKbdBoECPB0/Ny8vUbMBBk/AWRIM2XP2Gl+GjInO9asAzaKGFNXbrioGEbQrqLBUkKo8aOPwnG3PvLNVASJd1XgIRv+Z7'
                . 'uhpbX+EkyrQHvBCD/ncSSRBeLNlnALfalezWtCGNvG2sIn6WAkCWJh9vhfNEAWZ96rIhZnTjAR4X+s928IxnoimG5UGGdq3I'
                . 'zCXjKkU6td0HSlESzQ5s6xfRMi2UPArgLBUO3ZLyBlmfw5R574WG5IrzpjqgjeT/BlhEG1VFaBtaVt0pVNlhgpPKZaLgPqhJ'
                . 'GVgDkGrYD8zYTx5k94XvSfCZK7Y+OZVF53re4A/4f1J73HsdMNtLyNVWSDsWSV0Sw7Ccz3pvdKVYm3o22ynMxEHdmo0rG4XM'
                . 'QV0yYNXIRDgfmGOzDMpjhPhF+Op/+TjeUrRg3gDP4UyJQE27o0HXsMvAvFRqeWRHl+7wuvqMrix2PK3XxyKN9Sp/A/6yszpa'
                . 'H1VAo0w7egCVjUm53w1B02BqmT1V/J0OWqhMM9QLBHJbmwVT/HKvG9b4EvPCVkhnLDzUntPWYUaKl/Lh5yrvTRhrF00nx7FT'
                . 'uLe0HIbMxZ6uKFQN5qQ5Qqi7NzlXbcyMbEx78/hoSCZ9z68vjx6PHzermb/epx/DQ0IJGPhJ73XFXr7AurIV0hWLFL+P8dzi'
                . 'lwxfrs/timJfFim5uFx2Cu/bHSxgice+Xm50RWFmMCctEUK7vcnZFwfuHRsXSOc9GfjobRqND+2Do8MBgfUD9cfF4cPRw5r8'
                . '6f02fh8ckFjNywZ+Tr0mwUpy6pGK7P0IbUJHVcPWw6qbiXhXamPHsFXoRG8SasrGlY1CxKAuFWAN9GhAwzH23iczy4lcVN8c'
                . 'yZRO4GPTHedHfaBEQMWfomROhCoutzFGwh8xqCACjhtphH6Qex8gvdVdWl8uMoNMbcTk2Pcwro8bBbNBi1SAlcwN2m5G+pQR'
                . 'a4C1aFJHVXqx1d7A6IGAyWh4iEeZ0571ro3NT/X7fAlnQS2uGi1SbaJOasQCEga9jp7v4WSsgmYNKFVXxQ2Ay7IwwYrByQvk'
                . '8pAZmGA3IORsiZew+BsoncBH6JB5OqBmmy1Gk0qaaYtaGjbbIEld3mRk2DpcOLeEeXOeTruuKJCDOWkGvFWaD1V5xwyR+uyB'
                . '1C2xtfYPZgf9bqh/Y3yNV7QO6Ph5EWa3xIvY8utYlgIfoQsWDp96CFo2USLUcWh3nBuNYFvklmYneafwPLUVAvs7jbqi0D2Y'
                . 'kxYIoZne5IJdY3esk2PmQEk3rYP0Esg8qMpL7gHbuiDS81ekcInvV9N9f7/XIV02g935HZIWM4N7ZaQowslWSBMW7SZkL1nR'
                . 'wj4WDSsG2n2EWzcnn+hUu3Q6IoNKSAFgRfoUwttO4DcdVKyXgS/BO+1yrmZO/SIokCrhhivczmjhnnkR7mu9mFWP7lGe2ppa'
                . 'Vp4q4RyXaSczRjOGHeb+/GG0adQ4lShMeGrkJgX5yW66QYjx40JAWByqyqMYMQkfOxq6FaAji+xUEYEmNowXZNdQQD6Hl/PI'
                . 'QQNz0WJHec1hAx8g8L3/ABlzFjyD12WV64PgSCrExgiV4TnEfA2v4v2cNOPR+TEFGhiRXxPf5q+uSjgJIXCs86/+RrSZf18Q'
                . 'YS0UnauO/c14SLfkNkOkjHRknvluGMATAuWdH5FmNEjYaTr8wIVKYkhdC5ra92u3JMoZ6zLPaAS+6xho/+LXAKRIpLTONQIF'
                . '2wjPb8m/G+pEp4WZQbZ5hwgWV2Wv6mj5sJFn+41i8dTeHM5AgFh4quRa5p/zLfJ0uUFJ945W/KAH52DkenNxR75+K9+7zKNo'
                . 'a77ZJOSUi28v7MNAAWW/d7q66Hyu8da8D+pd1qNXk953GOEHp9Ql/8KckpindXA+CK1043UJX3IMoHHe1fG74o/yjUdTd5nE'
                . '0FTsLX8rEL1yq6mwHg0HW+IjbC4Qt3LECHgkqgbvD5JwSnzXMpB2vf4engvzxOQRGG7hlf9YBWO2Gd2pMVsZoq0gtHMt5491'
                . 'HmveiFkCd3z3a5hSm7eZo+SZQKP8oIPoGkdEpCmfbCxk6zF5zwsrHBUpNUHnYF6CAdKONq97PyjNEkD4ONqP9dVsvU+ia6oy'
                . 'xTO4wS11wqO1j4Cd42ZQiwwqRvGMexGSK2GII9BBnnSr5cITqtSMmCxv6JBkcuQyKw0XMdDljLP5MJJafmrKyGyH5XThyw3n'
                . 'Kjw519NdRsrJ8opT9pgv3KHClWWc93WfyEYRyB7TljBhzo0aVRGYNcffMzY4dhKluYNsJ/fcl18g7WY+FiON3MzLkEobXieZ'
                . '2vS6/JkVOfaUDreuURER7JybVgFyldTCD08eBYxFPpcX1iZpdvx61Wf/m8r3xneDVaPGRSyShz3dh6oR65zXrNpVZ9cZUtJI'
                . '64AugWyLocpLzgDErFf0BUYuuVmUbqmF3a8MpqEjCV4u05rVvouITo6ElawSC7uKp8Y8w369Nmu9kIn66Y04ONKx5ayWyv1c'
                . 'qcUx4CZtsN00gyO/+ZzV2DqsdaSWQCuXNUxvgsFXKz7xAbzdKqZy+tzGrDVtogH9zAPkcWljN5fEkLiGm91Znf/9cO1UUx6a'
                . 'FLKaNTPkCXXZuAO2PKmd1cCty01wA5FxrcEMytIuGPG1ZkPGCqKZJkylK2lUYdYrBiPnOzdRrSQsKMn0xf41AMveTRDwMtCT'
                . 'jUz2der0g0AVB67kdMWLImb9uUJzZtclXQnu5Vzu8QjXlUskxQbXcvPrW8FuabE1N2Kb1Vh7eogepOHm1JuOUoTsZYW65Mjs'
                . '0SZPxOqtDwILn8XdnxusciVIUE4tbAEQXhiBewpnnjYnOzs/mZfli7AfK9PjCNs0nW5z3S338cLXE04BoRXKWVWjCOjBfJDa'
                . 'tsMEG44l0KRWGDVlsJVRinl5VkCy6t4JNbe3tz4fHzN0oisbGyt4maHoxNTa1nRZm1RhVLtcsL1Jmpg36aCoWAYNKM3O9gaj'
                . 'pPi9duOpbXLQKFk4tVSESjAlkMsL5LYeHBbuxX1pfWwKMk7TjKMl6pNxN6xMHf5s6SYxE/roGSzyDUS6e+EubTp0T4WdNBpG'
                . 'XXDd+qoVrybl+kWKWxY13MOtGHjJKF+TCBsa6KH9UAbe8Z/bM4rG0FULzBrHi+z2hhSWqmmX2zHQN930FUYbQ/K1Frmx1rhf'
                . 'pvo/GMR3vNUJJ1Fwh7vgy8PYXN7I2CcNb1qdEe/Ep01d1k21QPejKozpSYM1JRg9dOSjibLcSaQGkHsoE3EMNtc8gwEGuoFu'
                . 'zv+VokK8PwwpfeHEShTSht2SaRGV9M/Ogr4UjwgHAZCeMPPxcK7HFzUDoCyajJnSH8GU0npXw3AH5/y7R+zdxiJ6NUOQHtD/'
                . 'wWL/Q4Bz+DBhvnY2ZEA0grRadBPXvQjkUbYDp0yeRUuBAEuOobfcbEV3PSjV6211D8mzmEGLWxSJ8mT5JXpG4apG/KSNMiqP'
                . 'WDnTDpnZdcnWaIPWqJyP+pLyKlWVlkX/vAJZymnQQGP9IQBC00FXRVtcyAvIRuqBHeg/pPxYJrxjFOKMl3O/D0FX9NgyXL0H'
                . 'Dk3lQjuCsYKqnZ4SCd7um1HEUGSko12IfYrWs4fk4OQj/Pny4R85aWkgBIC2DYQQWCdgigBSOMMR/bkKFyZyMuQk0MiOiw9Q'
                . 'gAkA2pMIAdoE6gkcwsWP5QYBdA4UUAWQ/Dmz097zMq7kAX/9AGrbAv0IBSOSgYFSVNqMb7wMk9JEBHlW/xba+CUDcG7bhqlQ'
                . 'lTme9Ck9Qa0A1K1kBsy8DV7OCrBC0NThBEFVEnC4A/gS7f0BdjaSryAEAOl59/r62uOk+sZO5AEAuPrx/WMAcG1l+bWl/39V'
                . 'f20AwIABACDwv+vtH7ohMHMYSOddpTVegT9yHl/J7cInExivC6EsIHox7aGRSPHc5aGrp1/K8zn7naQX9hzxqaXqKKGMnDYv'
                . '+6hg+K/Eu+xf44oi+SGtdFMdnj5XwJ1mAPfGhXdTAtBz4IIm5UxS3Ts6tLA/DRbM5BsAC2Y6QudMDuoRqJgAsDncbXchwZ7c'
                . 'hXFcv4tQ+TeZwt71Mrwdz3cJKkIF1c+jfX05Zzbu6Nqx3UiPfrGKhrpFt865LtS2GFZm2xnlGKPdKmhO60PjnGVsOqF9K3iN'
                . 'W5plUjP8sRnQFgBAds3E1/SLGc+p5iQtV6z4IWhsH8VWyTOnzqCycAcSwaVm0HrQLPVBM/uBmf22M8obRce/IJZJVcxAqRyF'
                . 'ZlwT9C3zdkwtR+yLx2jGlgtq3csNa1BkCuSM93nq1wttmsUnfQmShpyerrAtwhy7akCnaG825G9z+voW6HiIpTK5QqlSa7Q6'
                . 'vcFo0g3Tsh3X8wPAYUQo43GSZnlRVnXTdv0gxmleLtfbfX28ev3m7YYHf0QSmUKl0RlMFpvD5fEFQpFYIiMrJ6+gqKSsoqqm'
                . 'rqGppa0T8q+nb2BoZGxClc4I/bT6R4/6toA/UmkaFgJAInkKJySGAvaQiS9rPlx/ki0eAgAkYlgMFTecbsZ24Ir6O4uWAEAM'
                . 'gqTDwjfl8FlubIsSLUsyXLIlgYb0w3TDkijFZYWGljhcypGbMV8rYg//r4/0eSk0M7F9zAuKCAgmSAFBRAIDpjxSLR4BEKzA'
                . 'sAQmYTjLJFbUA0gCJiBC+NWwgYERKCCBP0qeBAhDBNSIDwT2TzRKmF/et8E7FOi3abO9fR/E46Tzf/nHePzjy7tb2HMECXqX'
                . 'TOfGVB+fhmCnvo37/YHC44u+kOQ8gINbw2qE+ratQj+Z/O6J1WC/9g6AsCH42e79YPUt3mRCHwvCWZreE2/Ta5/QOyfvrJ1Y'
                . 'rMVhNyD1IYSHDxGguelPvVvMq1J7zS9OrScbbnSA7RJotvFvLt/PbXaYCtV4u8boEXZ+2RDK/uk6kXCCH4TtJPrNTT6Wb9vV'
                . 'TgTNeg==',
            'family' => 'Be Vietnam Pro',
            'style' => 'normal',
            'weight' => '500',
            'range' => 'U+0102-0103, U+0110-0111, U+0128-0129, U+0168-0169, U+01A0-01A1, U+01AF-01B0, U+0300-0301, U+0303-0304, U+0308-0309, U+0323, U+0329, U+1EA0-1EF9, U+20AB',
        ],
        'be-vietnam-pro-latin-500.woff2' => [
            'b64' => 'd09GMgABAAAAAFWEABEAAAAA29AAAFUhAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGoEmG7MoHIUwBmAAhHoIYAmcDBEICoK+'
                . 'MIKaEQuDeAABNgIkA4dsBCAFhDYHh1oMgT0bj8YX0Nt2oXA7YO+l9JY7GhHBxgEUfmB1ZKCUjWJk//+fllTiyDRHmroD+L1t'
                . 'EE0xp6uWPjr6QoZteEWhtC0Fh2G4l2EIo/ZNB7TAyFNXOHzVggHdFc5g78XqNHbimurQlT0+sxuhK364mpPnBMVz/cEWvxAU'
                . 'MyoqKo70XdzT3sbLhM6kuWT4/YvJE+ISUcOh1EQPOfAkec+kcjKJphq+Cr5jx4oDO4w9w4O9SEJMahFRu1sWDPKK5f/szsC2'
                . 'kT/JycvzPWv87rzZs4hLwhNJrUnDQ4JMplTLpilqIjTS/7BHNGczexdFLFAkwRuClyBiQZ4GCHhwCWLSd6HUzHmoKf9VI/p+'
                . 'DHTvfhCEi1ARKuyQyqgrVCeqU6UYdVlFVTG4BwLMbRdRRCMpZDao8z/lhB3HlmUiyYqJAvhAH5C34WhbjudrO24d2SpTq8ys'
                . 'qu4eQXDBJTALgHNKScSKUwha2OHH/nDOfGUycIqm8W+5P8Bvs38WGyurgEdI6Xt0v0eVogKCKGKhU7Bira7Cddy1267C3S53'
                . 'tdtuF6mri/JiP5f+/T1Hawu8F0ogUhIhmcu77R9ig5grOGci1Bg65l/YWuS0qp5ppjENDKDFCq2V1hjH3b2xfJJsEH8SnNtE'
                . 'TbPa4RBPdrw7t9CBH1YlJC9qiiaHbtb2XceMr5hO77oGO+hhKWJQ0VrWEn6A58MKjYs7oToICuDff9RpPEw91sHT/QOyD6iA'
                . 'spMUQf9QatwMc4ZZ2/Tksm+OvckfgSLKXS4VzTPuDQjasd2MnB/q/JqR7IV51gKlKJforqWiQkmTHyXxRygPyGrl9pBhUKdK'
                . 'r7TuIoCLyRQooY9n8LwB5toOtV0IDA0IoCAwAnh4uFS9l2w8iwYPD/SUMtbvACkVM6hjk9VsjGlapQJw4XtcNrs/767TBtSi'
                . 'UHKtbIoMdLUJdOEkQp48K/n70lVd/8uUQFBtUJsIhpndrWpbPb5mzRk33p7W+tPX+5n500lISiiEEiiQKOOFYKoptSOVCZ0I'
                . 'inJsNVSr28fGjGecV0EZyvRE1Xi71tm473Fvs3va69W5+x6OV7+slZkQQLyRajoDnhB9bR/v9X/7en3HedtiCINt7WBkEGvt'
                . 'rYiIiMn+a/ZjOG1X04nn8x0aKEEDTYlMh7FW/0Nh3dO+4253K8EjJUFBQEDBtN7x/z9FIVQBeIrgQQaQgCFkzAly4QK58YeC'
                . 'BENhJFC0JChFNlSgECpWAVWpgmrUQQ0aEWedgy67DM2Zgx54AM17hvLcOwgRYDWwEIHmPQUKegaB+URikRNnJFJQffx2TRmo'
                . 'ogHTZyigENRj96vKwPT/Xwf+E8UmAzwgq5SbMvwlw6bTfqfAxaQOcSEkI4N8KGOyIWRMH48q1L8Cob19Ai3rQ6h+qUPyZQG9'
                . '611MMD4JxofBeC8YbwfjFTCeC2PsB6R8fDoPIRrKHTwIjLoULPqlhAKmM/tFxf8p/hn/0s/0Hjx6cOXEno2O7GBtvY3DSpr+'
                . 'BC95Mx+2oYA+n28rqSTSMzlCr57+82c5+3ZyGPVqP62xp7OlIeh2g89lAQYqEj68NSi276GvvoxoqyLNpmMpQn1Yr9aT9bBv'
                . 'g6su58/28T7Yu3vryet7ZU8Nh/v3/rG/rowb1K315vqu6pLO68xObmlHd1glgNfNU+3Si9qqzduwdYfJ1qwoYybQzLufvQD5'
                . 'NfdzM7O5mNM5mv3Zmc1Zm+X5u/Kr73vw5ctG05/ONEeRihQlJ+mRJQ5IhOT94hGn2EXE9DR+dKIeLqHxE81eSBvqDag/0c++'
                . '7U1941bytQV/UJ9CTUMFrc4odmj7ynBBXlEWp4+L2ZcG6EwuQaf6Hcwq/TA/ZBH0HCivqw5javqS1LJuA0poegPShhdPne5j'
                . 'VU7kB2yB5RYIEJjFOC0eyGq6vSYfhULyqKpQIhn39CA/KhSotTUIUmC0cJBDHmJuc3f0qKfvZARewruGaLJvHR8Jjw4eJZ3E'
                . 'vw4udWW0ZiAPzP9EBMZt31fw//OGgOWRO8zhQSUuconGnDeRwGGBSTKdHCQdTlfhnV3UKAtMJJr5EdPgdkcONpU/kH9ua7yv'
                . 'FxkYN7l5XQDTTHXrtySazW15XaoK8DzVol0K9ikskWDI0RFIINpZTb52t1MO66c+WHMGCs3etv5dWC70vPICxm3+vywDk0X2'
                . 'G/ZGU/Qu0S/iKe6MbiIe8QoSMAZDCCH6EfhnZl5gKbrN05VBMD14E8IvbgYlhGv9xNDP0yd/TaL0gnEKlRNcLIreju3gbC+f'
                . 'SbGvnhQ9xgoUoaiEZsMDMECAGEe88SWIWJJIQe6sVkceBRS6qMkdsxgLOAEcB44BB4D9wE7IEMoFSmc12w0Wo7T/yA/x7p40'
                . 'z8NAsJHdCbjI9utosOidhWJWzIHBFfBPQLWG2DkcrsMpXMNrEODnsRsPz/PWfAuuXNOxCvsxe3IMaaGKG8fqo3RabU7LJyiC'
                . '3/6hPnDrNCVb9HMFsBLB2ChoomMq890dvG8zuyMN8Oo7Sk51zPycnvrck3vZnXqihO9C92ObTTmEEhkkygMYfxfclfg8rQ2V'
                . 'pIoU9LV1eCVK89r9bh5Ivi8TCUKLFdsS6KzEe/Woj3k9Nu6e8nWGtjKQ53fpmHPMVZeUaq2CvhRO3UFc69KZ10IcJ8zxCT8p'
                . 'tqtt21g+eoPnC9/VnsvL0uiZsB9Nq5lp+abwxyDi35nxWSF6bjsKJzoHIIydvp6C9UlSj5YCKs5Y3VbPbjlPWzFV5zr0WSLm'
                . 'UL3lK/fnrof7GPmE3UWR7Fmva3dtrPXjydH2egLWOZYoakVLYd61X4RS4xllX78Xad2t7u7AbMjWm5hYcsGppJERnU8wkvUE'
                . 'IX1vQxOy6vC0NpH/PYU5X4k5snZP333HKkwu7nBbKIYQlspr+wWJp/dWMvbBWVq8JDip8PummrPxy+AIySG56ms2OtcWeCD4'
                . '/TPTfae/i7r86rQd6dKJHi7j1CD938rd9mW+a30fO91dhfkHynG9pfx6LkfJ2nGnBqsmyxf7dSqLunOuQrpGOQDMjEHbhYtN'
                . 'd/VUMGA/mSp3GlNdVsclRsFto/8tZ8K23fEqZnZnxg5zLGC+dgrGxMiEvtg7LUxuKIRzrzwmvG7EoDGOl5uJqJ3q98fVfh7b'
                . 'Q8P4iom5qH403vPgoJ89JSC5dKHH48Jd/CeqPK5IhArEA1azxGNLTiRbozQtWtRq00thylKtdtih3XEndDjjjC4zZnSbNafH'
                . 'Q4/0IfwhDgYuLkKJMooKFXrU8BDatFF0GCD4+MwImCBMWaJYsUbYsEGxZYuwY0eJPXtaFnHC4syFCleuTLlxx+HBgyZP/lCA'
                . 'ALRAgYggEihKFA3RoqEYMYhYCdgSJTIik4RIlkJbqlRsaeRQthwoVy5tefIQ+fKhAgV4ChVSV6QIUayYmhIVmCpVUVatmr4a'
                . 'Clx16qiq10hDixaUNm0MtGvH16GDsU6dTHTpgrp1E+jRQ0evXoamLKVru+0ox52wwBlnKJkxQ8msWUrmzOF46CGmRx6hEAEQ'
                . 'G4WTCliwsypCjRaGAao8lkmADtYhAiKGDOkwYoJmSoSwZInB0Ee5q41a1+KWB/eBT+/OGkK8aKGfhsISggBVlGCGag8ylzTT'
                . 'kmGtFHljEmCiixYLUIeNStcoNHu04ElkM1MBwZgFLESICaboYWZ+IUAwQNnK6CqjXsrvESgWDexYNld5YfPTmpoUydmQEEl8'
                . 'cnVD9OtDXi1hzdfNmoXPbSx+6Zhqju8hM4igXnA7AxuXEiv2yegl4W1BQiXKV6RYtRbtuv1F/aXrbbDRIXNuo/DHYWouWxqy'
                . 'BjynxIs3H778+AsSLESoMOEiRJKIliZdhkxy2XLkzwqnrYvvQxunESF7Is7ET5D0aXdU23O+efHmw5cff0GChQgVJlyESBLR'
                . '0qTLkEkuW478dtvktEdHjC54TbcIPXPdhmlkjhsOfNjUBRQaAxMLG+dkdBsf4Tw0XKqXEq3UbZjzkP3cY3EbjHuVD5ixGuCR'
                . 'x/HjOKWpYNvGbLtIEbRUrAOzROrmOKgD6KC5LJGAZ11QbzQ7QLFAG+bXmbD7GExb5v7gYRZwNiOIQmNgYmHjnMwfE/tp85w+'
                . 'r4o3H778+M8CZi+oi2AQIlSYcBEiSUTPEmcrDSIdZMgkly1Hvq6UcObSI9978np5/bgTzLc5Xi/RVL+/thnj6K1gYyg6Hic1'
                . 'BtnJZGYMIZf8qeV0KS5y2fTuJeagMI7j4CrF7GJWMfObNci4kAG3ctFgUw3Dle67W1oBreO895eVl0L9G4o7CZQ9Xpgvh4LH'
                . 'y7NLIGshpooJYuzwiCdrS6sg6KUaBpElMkWGSIsUqBld8oby1OCwwWBp7MrYV2kO8s68y2CMVzbDNAIGXr8xsQgnXBQ+TWx/'
                . 'o2u1Pe2vJvrOhJn2D6ZCxTwVmEbhUcXWuDlZPSJKA0qUWDjZOg2B6x6fWUfQEHoAtJHdnAGtaCjqMw70wA9qiovCE76H8v/s'
                . '0GbOA7Xxhjk83NnIFsgztugFutlSjKMRYgRwsTkuCrPQ5rExiOblM6AUYszS+ZIiq+ynbEK9wAC5GRlwUcxfdxLHB//kT8BD'
                . '4AJ87znvNuymcmBV//7/DTD8UhyA2g/IvtwGttIzYI0lb6AI08371nOMMZoOFfbctfqMITrU4hzMTGYbKZriUGZbVWxjH4c4'
                . 'xkd83Xi5yWMhCrX/XwCLGFvEw682wbgVQC2KfbGCTew2PXwn+Qq4DJhQY/D/IDzcP5+GOPj3GVhwwO/noBcmIPn6/M1LlZOV'
                . 'FxwCR4BrLgPqeTxcN30G9WwN+9p/ZoXj1pvxyCknbLfDOtcstcmUDaYtc8ctt61yEuJSokINjzYdC/CfiAejxU1ZsWHLjr1F'
                . 'TVJztf/PlYfdNtrjgW1e8BQoSIgwUaLFiJVIJkmyFGly5StQqFiJSlWq1aiz00W73POnlS6bdcWcS8547qwmh9232TlvXXDX'
                . 'j37y0kOnrfHOD5od8atf/GY1JoLGxsDCoUyLOg2aDOjSo0+VCSEz5kQWusGCEwdijtxZK+LLizd/PvwECCYRLkKkBHEWkwqV'
                . 'Si5dhmyZbspSoVSZcrXyKFjKMe+pgw7Za78D9hnwDlZaTp6lH5H8X00weff/6VXflj+tJ9myxYu3d5wcGpvp31HLun5o+1oy'
                . 'e5NSguCF7yiwcQPxfIPu8pfn3SrT+zBAenFHE/E2lGXx+3JqrCV4Q1yTugUnN8EV5EDcBgV7ECu2YB9ozu3c3RFXsSJCcNnM'
                . 'VS6vh67R8HAICs4eq9iQz4atGQwYHHc/H1usiMFtNj969TG+g8S1lSrXVHhV8NifaVtr72xkMKif1OiJyokuY3MNGvCvXTrD'
                . '8qs90jk7/Q7dtrfsh+7/9oUpk173icdne40Jk9y/bdnfKzL0x4ZHSi7RnNDqLRP5KwcDpB/7l/BaOOgYrfNvwpZsU0G17ATs'
                . 'BQX2iaeeuLfU7FY1Sljl+4fxxkufWPEmEE+1iUrKTtae6GmwpjgtOAQ64CBi0/WNueupzx1D6jLWlmdbwZGrKZHclOXNAYOm'
                . 'mqbu+ETVFVunz6Dat59I+P9nzdyORByUPR92TdxXL2oVZJeedOMTuR6HKzgGp3zfgl6IySR36k5A0K0Sad4GpPcUpbr0FNA0'
                . 'V2T/hsVzGqD01BRvhXp6LB7tdFZuOu6djRoyc0aRKUjo4lKujkSOgE7a72gcnQROMGikaxtzVoo2smYtfipz3gQPXgBcZ0RE'
                . 'Y3LO02mZ275Z2hqx+s4WlJB7g21+SfoLQjgVkJa4zh35pcVppS/BU4AUEjI+zCGVcDMQP+13FnyhJkeZ3hJZEmHj0Gf+qPUF'
                . '558yPBBlYM9vHKwTxjpRWVlFBqg6UsulgNQdi1RzNplwOd+AH0FaELAfnZ5dFyfv0+mnnbPfVytPlMI0JGIige02KHP2aoqR'
                . 'iyKB8hK+k2aA/VKTSFy0nOr2A6ZhJNPbPvQs3aHDzB1TZ785hOt2aRTsjEyNOOesJ2plUs+L8YI/5epzVSN1ZKC9L2iwwBFT'
                . '6BFL6BNbGBBHGBJXGBFPGBNfmJBAmPYWEN/n0LWNQ59mSnLPLYXW/hJTXQd+G1fVm8Dfiw1jhF3aOEDql2sEh21AAEMIBhQM'
                . 'FmAQgUEMBgkYpGCQgUEOlq2B+WglzeDAtqGS1KjCqVKvX6TriBZskk01KzoAPfeGzEiclqu6d6JuLCdLaZXJ2eh2si3Gruah'
                . 'znf8J7XQXLnGOzm7Br5r3bPBCEyyrc4mpR6uqBMC/QIAnFiU6bxWzc4XyxaYR0/3FHHN0vo1tEN5f77gni5OuoyUjjwg9+Tw'
                . 'JUqTJmhL2bCWfu663xThT03GHemufVJu/s47NfgIAp3GkOCYGU0qq6QVE74/SxcOzKc9NHNqRykrztFBYaSJ/jmfjGTn/pxt'
                . 'EptlU5m01WXZzTyRKHNKusGW5KWqh0wn9UgVBgyNcMjY2EiSiIb0kOWKsndFgTCnSDdOG5fm2e2t6MoU7DdgZko+VUcDBqJr'
                . 'GDEUpUiTSw2E0PqLnEVHqVF1iJ35QcNFx7BASuanz6vPLfIktQt4RmzA2AWaGSJ5mR5Wee5LzIs1ylqHTtEBNuFiM2Ft3CIF'
                . '0DXR5YbSPd5jl0f0vLLTw+CmqLlKeFMMIkEP0rB0Q79r9tVjXsvcqvo+0/TKY++b3cmvVvPM/BxfA495ttzr4CkTbjG9Zpie'
                . '6GiunyVbMblhAT01zeL1VKQ2ktm4V6V8LUqZlp1/E5kfDgfjHc9/I3oJjPZgDnvGeZk2NlU6rOpIbvWC43uPaLc3tO2wMpct'
                . '4ebDHVO75JMtWrc6dRXa1tbc3XAPGXBJgjtS7+e1w0oeHA3oHUGJbtrvnko+RDTTm8h30CO0MAA4YyBWQPJFLQLECUgWhwHO'
                . 'glRAEl+LAAkCDk60WQfgpAaZiCRpGQIki0hSzgMoSBWRpC1DgHQR9Qx1cFrIxAmR1yJAloAkOwxwDnIEJLm1CJAnoJGvE5wS'
                . 'C3BKCpchQJGIRjEgQImIpHQZApSJuL3cZg14Bc+oKCV+9qsDleCvVzmcTSBArT6NIpnUBFGsDSJRdIK3RKgLeEN9wNtoAHaA'
                . 'RvlAmoJImoOotQzVPtAa8IG2gA+0B3ygQwbSGUTSFUStG6p9oSfgC70BX+gL+EK/DGQgiGQwiNmh33+VRrVLrmYavKEJFXzD'
                . 'zMQhb5ICuh3Zua8o82v1HfXXhi0gnM+oAcwA6l9Q74MNt8CuZwDAVnD2E4Cg7qL0EoiGAETaA2UiOMt8yasKFJ96YH5MAk16'
                . 'yZk4kgOvjHeeFDpI3vbcJiebSADH6TSATPUWGuBDh1IXBV7vBMLfHB6SnoD2Ee8UqwhUEfmPxUCKapBaFmt5Ji0RS/Ufza/Q'
                . 'H88uouTq38YVhbKKThQeMD5XZhbWCkXi0riPFyX3ROiRvcWXCVBaaitNkzxpmzx6Che8uItQZB2ZasRR3HmeidAje9O8In3i'
                . 'R6bjJ7/pDWu/LTwTsVS3O/RnvlU+K0IlLRM+bvvWe1ZELFXoa+O4p+yx+O5uZHV30zbcSHOHzzmpVlyp+RM0vrrRpe7GXhea'
                . '7F8l9mLty436tfeaDOeJ3Yya7amOWqYm54ITHhJM2HMakeXbnmPNzxkQDvE2pbU+X/Livut6by8Mc9ERfgP+8t80Lv7o58WR'
                . '96mkoTfpiIcy+luRh/OO1cwRIsq9k2OjaR3Z2T0PL6p0dPyprzw/8AbWJDY+YXR7VNwCxy0dwWGByGZzGffbUSvcxmTjWviU'
                . 'IsBEz9EnwFwtfUYe4FDhmJDoXMUHDR1vDvXIfh2o9ai+ausFVLiJPM0vVyUSQcUEnr1V3JfDYdxbITqX2OHY2E8NuYcEy/5E'
                . 'FrXrJQkQexfo2f7rBhFNhR5uUmCWGYgPs3pDZrBY5KkOZkXD0spS2g4lWxKCjIoICWAfKU9Wkw5I2FI4YrXsQc5+v/uJKgpz'
                . 'BJUQvIqW+U9UG4nENffjvg+kerhmxpqxOVS6sqTTvNgKwxIeIwlpKFmD2tnLGs9RlprGcfS0dZBx6yhz8SWMh1pRz9DCrqaH'
                . 'xDWMs6jENiaalWaUzds00m4dPWnC0EwDykbwsr7bWD0WRuaQFSwuyfIMdUthJuEgqYcyqpKatXqm61W8cLLDGMW/HVTvWm4R'
                . '03ocmGS7xn70Zvl41OhRgLCG1L8otjw8D/8nFc3Vy88ujOybK5W9qeIq99Zi2WvjUC8eN759rMZibFioGdglKbXEDUKtGKsP'
                . '5+rgahYISzEJWZdG+hl6HuSqkkj1/y9HOrzO+P488z5iRkYUa9t7/mKRJ42MQ6XZcb79fXbyNn6XLUJjLM5djsSAhgH9N+Hb'
                . 'kgydqYX3k3ALE0GfxI2MN9yucU5wOXmrd/esHy7CzjPrmLRYxXTFbf5YpmOkkRzEhzMmM3R2+S9r0ZCm7N8L8EbD+2nV0UYv'
                . '8QfBqITlHk5E1O0gC/vkWn2esFHhEc35B56eazP2woRpekHYowZhqqyhGacMvLWN8TnQLbuYpGF2aElhKWaCkp3Z5nEzfxQP'
                . 'VwU8A2oexY92Owx9lFtqkDhUtjnGiRrH6zojNSNYvnkH+osZVaBXiaSGwf3V9okQobMlRccqPNaJcdzt4KulYOLGmg6NW5f4'
                . '+gwJY0dRyJyg9V6qN6Z99S35maf0ow7ULH3R4H99FO+QUUx7ks7ZjppqKhmshI4ZS+bfXZ+cPH2OQ0r83PUxFnMCqKDG2zxB'
                . 'HlIOoJICgcSoMxlSkgTB/4DYyfxksL4opaabiflE6G4EE5vVM/ZEsLYSG7U1KoRoa7oDU5i0plWuO5Lr7WMWu2b+FixKiSq8'
                . 'tDux1rFfVeFnJBJui4wSabdzE68rZXT8wZGKxXqU0ylsYCMtlY7AXdAUEfIcz4VnDCfPYuM9DdLRezx2+OJvfbh/etnArwgR'
                . 'NqSvpoBJwjKvDf0UGlMBoym1/7tviy5y4ep7X2iqkisE0qEZMpD+2tNYRclKetN3wyDtY9Fq3RhWx6WW7ytg7WEy/ZIk1bvs'
                . 'FsTDZSLVvsqjWNhTYDkk42Y1Z0UqhqK7fDGQYLJMGfqAsr/MpxmsWKt4rx4k/LtZRNVSFHYxSTYgu2o3QrF7hyzduzFuwYp6'
                . 'NFui5n6Z85j7yatguWfEOMr9M4mnboMx/pVt0zIaOhm1QbdEH0qgDbcr97HMfzStyyVP5LihjcC80rCjxdmD2pvcKULsaYwL'
                . '7oCrxK79N5eDp3TDc5T9jVi/J1RCZQKz8k45J1AJmW896VYUpEHCsQtz0O2z/yZJkNoktUFqjSavDz64sByXxFZSez7eVW4P'
                . 'wDpEXqkdnWnFbLa2yj4lmCW8I7Ey4CD6m4KH2JpY81X3E/rRpl2Y53kFBiS0DGtEamWPbneLFVnRW45JQXLUeNy/rSslAjnR'
                . 'LNoMKRf2bG/jRBv14nQk8f9f9Ov2p5pVBKOE+kgNt+EyqyA0WDS8xuUg5bLa8qvpYageTG98hcxbLbFUQ7kDWc9HdDJPKwHv'
                . 'zpd7c5xaY4SFB8Ht6wMPr/t8mLR7+sFz868jXODzEVAogwxEDTO7yBL+XUYKZYd6m0e6LtCz6xDxIgurrPdRBD9r7yR0DCUj'
                . 'yTkhny6S04W+yD3oMCnl4+opOTpxQ2y3UXdKClM0c1zYraipv7J0hPpkNIgSgQSx8v6wQj5IxR+OA1wrnZ/8K6YZdCmS2Dc9'
                . 'fU8pCXUydnZI3paq55mbffOUa+XoQZaaah749CDRNNU8eNKDZP4E/4xY4UFx+/ogQR3yZiqolFgDilbXIlEjN1ouS1gczFsT'
                . '8QZWqa5N0inOUb4olpO249cokzBJCxR91XvsW6lMabSYZC16qgfdibRGnqEdPgg2f2hiekItyhO3FCq9FJDuE+xINc7zhuBD'
                . 'DIq3l9TnzpBW/nTZz/zl+CyhRW881xg33v+M9BRj/7V/3mRt0LaYKNvKdKhk55RWTbc+06jHgZI1ZtG2Y7tsFTIQaY/qmnRV'
                . 'NBPEEpdabmUpTVS/cojwGjzlbhU9LoNf5iUkqqXTH1K4HbfMyW1I7Ah07aeg2uZ8lb007fjuet/c/q8e7SX4+xCj9XZOpTIx'
                . '0KE8xEHjTqAu9YDeTfMwDLq9lyJ2MSaibck+5vcyyQaFvY5kV7NxT8PYvS3WFEV2zcialniWdbej0AOAaUjp3BH1Hc+GFZpf'
                . 'kgsaRjAaoG6kqpwY+pfW3mgS8LfSW6Yazcs8p+QerodqCodaG6hzu+ORgSLB0cIuDLklqvuqoBNB4KJ8JziH5pfqWyP+r4U8'
                . 'UflOQDd+Is519av4eJpD+TAYE3/l67Ekzoi98yZXSS35dDQ8Pi5RAlnOvPonQ+q7JxyCrmmllFBXD0HtLtfqVpPRO4HzFCot'
                . '+oC8lTtdZkmi+UgCejCKiXb0awgwIsNM7P4tbqL8BwFFIfXgUUquW6uzzwh7m6Z9Vm3vR4wxIlMyIaO7GpN7sSfVqMYWZo5L'
                . 'v51m0Lf+62eoXDLPb3stzoDUnfTB/oDoG/Urzq/iTUG9IV/0Bv8lSV0J3+MvUr5qPzR4SCYejX62uszup7On+w/5mEdx4ru7'
                . 'bBUe0dE8094Fgua6/hv+5ns1D97Sf0XDeAjm48JgrZ53pwR5pxuOmnuAo6vxqvBgb8HIWTYpCvyKvkg4DmmFa/gS2bfSTZDc'
                . 'az6EyB1wpAyOIilAiN0l09lJ4c7Dt6CMmZYwVNFIG/+KQpq7wE6vIR5+P1h5YZ7n6Oc2C7QwlxP4Ka30MDFtjxHuyewZ6g0O'
                . 'iyBo+NrXz2A2xVqoVCLwiF0CNTZ2Q/6pL6/kpjCMvu6pD4LDH/RxTK0lk8zHqW0HrIcHH3eIkbz9ULIb4ppDHCHzNLY7SA87'
                . '1dYfOXWWhMnC86fV2IfbKlxhv17mplPnhiWn+QnNpw4s/7m3Birh8/yAd10XQX8XfQ/paa70d6iosBHi/VzxHwhwAfqRBhJo'
                . 'y0WAC6Af5T8XLkCMuZZlM/Dw7hCmIuYaOmiWHsaehhF/kkVoweGbCYR+PL7/HmEgBvoPmtXIYvwRtebN8loudnlnChf46KXU'
                . 'hY106gl5WZnsBJXydDkPrniabCrKLpr14vVBc71d9L9j3aWzslLvs8kV2d+UosT/DdBKPc8lVmT+gkOIvwyU7pKtvlu/x+iY'
                . 'Y8Y6r2uaNN8mY/fO3PGcXr9spllnm+cYozyBlyek6XILMBv+ZplkAm44rqjJHn/y5pMaqZbJlGpF3Od+eI774SUC4eJH+j3Y'
                . '2nWamji0wWiEJmri69S15hYqKivXWnuAXugsfc9EEMtrYLkdF+HzcfVyR1TFxwrXOjqBXuiNKNfJqPoDt3x9WjEG0EDDsxX9'
                . 'r1lZe4uKvgfOs222RUnE1kob04l9GxvcVv47zNClif7MZYfpuOvfNdjm/5e0XlXTCq1FkECZxt/q2vTRtYVrVQFIbiOEeDxC'
                . 'WG4NwNC4JhQNaf7nX8dGYZN/uXr6MwgrbPgwPqy4jmrt59Ni2/ycG7vZhTPRNrs47e5Hs6VL+Ikze2kajC7LUUqJGnVpN5qq'
                . '25WjT5fZOTP8j75pGmzzf+SNzjmbnCLmAWxwg7o2XrHBaKyY8McnNUFTEwWRHK1NAr0wWvQCihOogiyFHR/hCwgNCocfVoZX'
                . 'tmcErocMwoRv/267yjbfK7FKepd+g9VATwbmi5fOWhNankv7XErKLDx8ITSnIuZW6gIW4OOtBZl8lsbJe6AlItgFXGfdHlKE'
                . '8fJaL3Z7ZzCz/HSp8gks9SzQBIJSS7UxMGc3OaX9CZ2iQ5sgdI+twT9+QFjrpzZ9Y30xcF3y4Fun3j21fz4pen604/lxCavv'
                . 'a2vLzXuyqb1tRWZZ0j8N/bNrl4e/xqGzi+ZkdEtePoStndAE4vCE0QhvCLTHRK1/G26HN4QnAvHjtrnaHeIRVADWK+x+SCEP'
                . 'qh3jAvRIr7IwbkbhhFsMm/yPjFuJM/niNLjF0ndpBd0ZI5Eicfstlp7USc2aC/WK5rrF0ihGGXFlVG9rPSPf5+bcieXKZn89'
                . 'oGs+Rl3cd+lnObOJ3/I2CsHIneJnP5HcdPyByKRncPvO/UjgG8J8i2FmHRq+Gdfk5PlY7LqkSfJte2xpZKsqeNlTpvFrKJLe'
                . 'zSOE1dZGGGEUMpBGSG0jhIS2+YLqJCrtVCqlK5No9fH/HRs9Xnqc8wDUUMpAzQNO7OrHiResVKjOGzv2//5/Pfw6TVEQi7+L'
                . 'UWjiSvvKqWuDjXh5G+WVrcl+sy7Rt664llPzvy3GbPO5zrhC3CBDHd3Wgqdka8/CY6yDo4d+OaRWm1gstUliw+pCpE+seW6g'
                . 'H/w66Dts1KHlQ8c2RwragyyJU6nThBI8i6mufPTxISABXceO8RC9mk47tKSl6FCIL65UqhSxfoU8e+xOTBO7M0Zqx1at1phG'
                . 'Ajd6b5i8DdU13gbTVw0cfo6t0ZQVL/ACgHddN1b/O0UBa1kSHroQHsohz5mpNX1WzDSgwjeyYtolvmd2UsKT1uQlM+VzVjmT'
                . 'uvAhxdsiwKXMDX8c+yg2nDpHuv3qXP929T517z7VNlV/ktq8pdDX04U/dNdteqKkbtNP3XRQS+G3gVaanvrNqhdbXmpZlfZN'
                . 'OqWokR0AKtd1LdfPoz5IsLx6I21TjODbRN8cY9NKeTpwcCvGmchJODFbB3UgT1HDkjv6LYhUYFWGuFoB0Q3DgXIPl6spKNhj'
                . 'veGrzg0z84B/1vbD/oy9e14WcYLvHwuALThcC4ifxOEnuqkQbgBzz+45Ks++QAj3aDeMw8Bf9l5T5WDphklfLbW8p7c2j6lu'
                . 'mEnr4R+Hhy9PsUdlGRcVmjuqwsBWXBElDaJ/FmkFOiBR/aeDrJBYwZdjaXv+7yBKkLhSNRwE1xVSDFomi7b4qXNAG+yc4XvI'
                . 'Sr6tRPxpMANI2+WkfKIuTLPb1EKF0/KQrzeJWvqKjcRlJMbVy21Yvg5sPYJxJh4khJgjrTqQL69lyWoZljlqfbya+Xmw8xod'
                . 'Vt/I0jqIZTRQpvH09DofTbq/GYjDlr1wBV/PI7vZbKRYiV8B790CxKH5O2n26nIRpvgv4Ao8g7naieJXzHNasBEPt0cl5rx+'
                . 'oCNULgASkGoF5b+iOqAx/lMLc/2XT6/gWBi4698GaVjnmgrvXyhMPNizKFRzP9fz7Wu0RhDwq5CHD8mtNZC8/J7rO6/r5U41'
                . '02guj4kBmheoNrvRjzBNlZyDLQO6AxGPbKLXXRPiawVEDwST3Jzm8XTiVGhLMa6W4UX1LJWD8AZGlM4wS4u2qRRDAXBAYa7W'
                . 'Uh8cPRukN+Fkrpv8lhabhli0PInZpIqFRhBrKQRdpCIQLg6xdEh7YLD2ib3Ld1Fc5LdXSkWsWq50lmlrbRs+0PbGymv2H/aP'
                . 'NM/CDUjFgbZGRdnBzFDLPdBQK+dXmWPVZV/QJwUf/vsTefgawAdv5EB/MqV9wb7L69CPkVpK5e4k0A0Dv9ZAgnslOroi49ik'
                . 'Hs+BkbDMhg/zePgQ03jaCo4et29HhiPxh1Ha0a2s4XO1PNAJQaFey+XyZPjq4H1ie5DgYzqKPmDLnUJGrciXsHAEWgGTUaih'
                . 'wvBkHBzE4QdAcBKPn/TFcxI5sK9tL37hytu1bGGx2ZNSmkMTWf5DOYE/AoOvxIrA3X4kW0Ra0fCgKtFan8TLd+TI1HmTIOEv'
                . 'IOxaDqEKCMPabEEHpyBRCK7QtSBkme1B4rmrH2++8/t/4Jb3/vUuswH/dD8o1uUkrOnb6IcguV0MpxqdSp3ELqDVSHwJVMDV'
                . 'chlL8zUkVqH5xVOy276XrS/LhSap4bcTy2lyZ/8/YGvmqvRwhdjqPrti1GgMlPeo9hWVquq5Oi7ZzWKTXUCnEXouycVmkdxw'
                . 'KDhZHwAq13ZhG8FSUIIy5iuK8yFEgG4p5ogP+ocfCc0vrRFLq6nVHOXtHrwQ7L1YwPz3igNqjMn5CdRaWt8y0+19mtNe6erK'
                . 'Nohbg+H1aN11ddF3s5UcXwGvt7y4T/L7lWwhhc04VTZZemyf/lZIFyVPc/LGTlk3tZR6zq9ZkfVNCUr8eZKK8zyXWJ41CL5Z'
                . 'J1sTNdn6oRbr620uytf2GWOShe8jUiSPPbbS7MuRGxul36cV5M/qsWkGi6YNzyQLFPsiP26dvZlwdjemvD+ycq9tS3P7HjX2'
                . '7En/YkJ4x8qPX8/pi2OKzrqcG+mFfcS/O8Z1OQk0/RcNR5V7EuiE67moyVGzyLEcq3aR/2j0ACuhJeMWjfV2aUkd3Zi530R+'
                . 'h40019ZILeDLoU6gHbweURLcX1jU9jeaiWYGTAiLZamF5M7f9d0c1K/RIN4PMoC3pvdeg3RoaIjvpKBABIQlHo9ZLLartZ6g'
                . 'TYzIeWKHSqyudPt+SP6AiAeTlm4mVm2FiTa4qC5Yty6xbiQ4UmD15guBOljk+SPWVdvobUpUc8qgCnmBgUpL5CRsmDHGtVB1'
                . 'eBBe1rK1IH5W17h/N6PCKE05z28NHGaU38Rub3zYHmbU0EbM7b9zNbt32+apct+b2aS3zmUnk2ODY22Wrmfccz57MNmeaKcz'
                . 'zy06jx0cfWPsjcFHsY8scqc38gat7vXiBYDCH7lilLHm7aKerZv91fveXEqPDUcgx7W6vPyTt8jliNYHvm0pb9EsWCFlqXCu'
                . 'XSXBU1QOI8jutUzg//O4BFNdNjz/2tQb7L2cuAxcPkgfD+cY4+f9hL09w/4fNvwKa5TaywiEr8r76W8IqyIEzusQpOXddS5Z'
                . 'nL+KULyuEqiGyPLjAv7CTcKnQUafOPf1PMIAZakeS+uZmxbZOHQ7DIccK1sstrJYZxULcER22iSVOkmj7kZUVC02xuXm1jZu'
                . 't2vKNJYylv+rHG2xRDZOTkSmLJbI1MRE/SaYPjefZG8CEEWXgTk/xTC7pyeN6taQzCdgDn5NZlrufc5J71o6hUMl+mAFqu8W'
                . 'GmJmvS7e1WKQeyz+FoEZ6+1VK1s1naoqs56hww8yhUa1KhqIClAFt8zwJVXhsinjanlbl8ah7uKgAY1K434yg4t6taipoV/g'
                . 'OHi6GWiJurHti5HoyOnY4j92bDPWGGI8TtCexHYAlnsFB2ARCpONDAbZgrD4DPO3bwNJr2MHmo2lV9KmFFc57k6TpEfDIQV0'
                . '5liFvQFrbhciIdKgXlLUK+qo9g3zvCz9cqAVqGgpGuZKbSXenNH9ZqLM3Y6KOpUcYq3GFCnXReh6MlPP5TL1ejIdvur0j6bV'
                . 'Mc+rtiO96irxmmZMFeD3ciNJ1Y6KC6n1VC3W3ifSh9UiGsqIABggnFwKyZW1nXyTOVBeUgYvnVkBYCAioqEitT4sy7U/r0UM'
                . 'FTCGtJoQXFFZXcfX0yASsURPFfCrt76ESIL0NJXlZ0ubEAmRB/WS4l5hR7VvxALigxAp/bK2TFmIUnccFQYNzRhyX83gGjhl'
                . '7g1FyvVcnoGt8eK/WxEOx47nCgQ8PI7CF3ArToXCvj8QwYm7/WQyIwAoGNs3fZJfcCMn9x6uEmr+kSJ2KAUs1Hy/cpuF91gG'
                . '++krpZ6c54uLz2ZnNZdUaKD89ZlZZwoKL+Q4S6/sqCBftoGkqrz0ST2R4xLbPjSdUW37tPNDPso3nd3iGuA9imFKb1xe7gJ8'
                . 'ecWlx/Pyjpfk2LcAXt61b00nMObUnAoKnXMKD352ZfVFDE8DEYxMFiNQpUDyrgAW+DOHtRNpD1Jp4Boc4e0ZSF90raDw/aLi'
                . '9wsLrml1aQi9k2P7/rw5Qw4ssrZQ2lP1lN6y23FSMXGNOCUNePQYHrbxBQI7H8a/8hKQhvSdxGJS/Db5ZtFgVuZg0e6EhXtL'
                . 'HsvJfrSk5MXsnMcUF+rUh00MNpHD7L0uZLN3IMO2FeBbFyHD2QUf7Oe9sLcfF6/M9RjcU1eeYFYg+qQTPxY+mBsfnT8pZI+G'
                . 'nvArF0MPA3zrxozapvktV04Pz590uguzf7lolu+q2F6X45XMBm6JEIbxgM4ifwKVUUqKCwrsYJD7CgrSyw3gFE3scaOc6bNe'
                . 'wdc7BQgNRwwUsPD4L3Pzni0RmYRFz+XkflZc8r/CWmKplsahG4p3llEv+NLvjlCop42lDsKpFxpPv1DcaFbxldOp17aWyV5i'
                . '3td1IDzT+WGA97M4LOOr7MwW58DfQxcLnjxW8d2cQil1SWiZNpo+923KktsGKzff+XyxQwX7RUpZtFvuDvxvQu2VM4MCfqD8'
                . 'g0XNqP66FhNt07cJY0JEj1yKSTG1PxtXeGhpG0D8RCqNmrqBAG5Ie/lzwx+GZ27y5D8/u+NOdvDz4K27uZe3yX99cy1mGgtu'
                . 'xBOmQHCKQJgqca62Dj2F4olnCOAZMgjZ+cZW/PQm4q3xGwl7aOPw4XMyFBhMxJe9I0bhtEDItgmnsFhp6v+Sz5+DOmNOw1uL'
                . 'SvqWlLA/IlTjcNUEfDMOH4P25LKNa8afxnE/ZUnjB9g2/waZQ9OuOdxEFdnkAtJLQfZKN9rWxVdp4zxdWCegm/YcWQmSxR8Q'
                . 'akrxUnbG45qVcDG+pfQbx/e7gf7sfzR0QCPjekLYsp1bfop88ks2dpfb2LaSrzwi1W5nsPB7jglimqFQbyrnsCIR4NOpEJ30'
                . 'IFz/wHao2LONkzpdnTI9RhoV2p8eyvUN4o7RXtuXH50mTO/Ji+6mvdo0m9Nrmqp7TlbanR/dSXuNvHZmr8loe/HTe4eox/Jn'
                . 'vmjPn/2i8Nqxtu912rJ7sHW9dYSp+CYMnY6v+ptq2o4PfEvXhqu3Q08E66DHLY10W6FlU7VdniRMmc2BKqqe602Y1JM6mhMx'
                . '3/VU+zy/abXMCFOr/W2bamsM25ELrJkjGYcvQwvdiyncKMFSzNNLLx7OOHLp6NZ382WNV51XGylFc2bQHalFRMHpZ8MzDuJX'
                . 'nJb+KwFt5ta5xPKDk+hfizo8eLkOtvNmV/znNgBBNQkOYhk6PklWfCyHiEcro0vp2ywjwZSGWzdu3Ry5OSVvjL3Fh2/Qhhsw'
                . 'o+EG0gvq1FvA3lKf3vwUagczheE1RFrD8qrEDzOx15sY1794ai+Tl/5tXpyxq+8JS3JcetRbJT2eXH3Blqg9LhmNm47GUEaX'
                . 'R6tQWcSvLZKVLHA1Hg2zSSxiNHrUKqGm/HaGtPn5XOH170uZdLe9SjreUDVWjtSuV9Q2w6sMemjM3zSlCCKtpc8DZC/wSz+h'
                . 'S0C9M0oijdylUv4eIZNG/74jw6K9PCRsQB1RPTXlEMsmkQpslTQ2y13GNwpU9jot+dQzhbnNunCQvHlueGpr0Ykm4hYHceDl'
                . '1EtxBmMxiWzT7lH6ET1Vn//M6189x9ftcZpNGl0Vt9zD41VU+QSawhfNNQg5ftsUJWkEtr+0QB8oFrfqS0WIXc/h69glq0s2'
                . 'RWGlVaawubTYRQq+TazShJNCu62hQqIt0YwASTDdzpuo4P56HQfrWCPxgoONECuxvi1LMPVan0apaeiXUh4lnrYQWRaWbKgY'
                . '3GMhfZaEZf592NoNitoYtEpvgMZNpE/615eXjxv00KoInNiKtrIRNyUqElKiiKuFbYT7d5GjQhE5irq/qt0oElDCEaEQqg+o'
                . 'UL4OPwJ1AlXgi+4jMnxHy8qO+hjEPocPqIJOmxEdvvXWaqVbQPewYIbXJVAqAuU4zEIKG66y74MaKQ0b77M59wkfQvgq3vK6'
                . 'm9xNKHcwTHyKtkRH2k/mk3HFJZ1T60Nx+2Oux1pDrrvoNIruu68viqN0tL3I/ON6rpvDdvG4bLeKc3kY57htguOKGQ9jH+Me'
                . 'Bt426eH5w02HCw+fKGgqiE7MN82zc2s8piURDzupUiktgv/R9/o8MRPzbKUHiEOzhuNq/VVHdjMQB4/0rIkZc78SkQvqNX6N'
                . 'oF4da9N7PBuMaMzlNDhdQ2Z0wOEoszs2GKlhmcNFcTl7DcaBMSb1ExuNav+ESpu3U2m2eQu1wW/OCPL5SJ7OBiTBkB38Z4Fd'
                . 'a4BlLo1aWdvEv1Tj9P/jZv/s9wEJsLtfqr29usZerIHoJqlcVtcs02H8L4/TQ0duil/m97K+6vieoVWbKBhzFZCdBVSFN+SY'
                . 'wyP7LO104+K3gGa4hSymoDFIb88kmblgdnYW10zK1NtiMEpBFt8CmuEt4yJ6+z5LeDjHbNc2PnA5xHknxtdr65SgkWNAG8eE'
                . 'leUo9lmgGfZrsVRPg1bFpGt172gfVdiy2FTdjZn6RpX/su3VFLL2sLXnsuevKMq5Seshijbl1dMRz/Q8o8L75zrnKxYu7tu1'
                . 'u2730rvqdu2y92RED0QJL6xFkHiHiaOoS8TjMRCfjQkc6FcWyjhQ/XL1zY/AQQL+hOB3au3pqf+zfuHrn9Ng27ujOUM5g9E9'
                . 'Lxowc917XjD80p06aHfAFB7atatO3dv48JHtU0hUoMA1vH0iM+fXUhT87zk6Lnlv14rs/+OQ5Ie0d5/zYt2vi4OPgD2SH1xv'
                . 'Q4ffuPUzwvs4YbFlU4A6uFOqtML/YPDca1ImbSEBcO8tMUBG3qcXYSOdibJZTMRIg1mBcpzF3tLjBu/FLXOxuYI6lfh8Wdlz'
                . 'RPBtd6J3ri6DTTQGymIzEBMd5iIIYdDYZQPfyekgW1nPf17300E7v+tvrHZa57cu5RF/a/6z+n23j7rGIXHQQj7SkvWX7lwt'
                . 'XY7k5xL1GWbsOJspfsCQu+hcvCB6n9Da1bcQCAO3P0AXBH62lP6Ey/BHwi3fJ3fQQiqdzup+wVX2xxgNEhAKIcmGv5uptH5B'
                . 'euSJypB//mUFn87MIf/Jd7+O/NN3Hw738+nvLf+dLv9Ra3z5p2p6NiYHtxii6xJgIYda5IIZRAUZed7JI1kdslVGSdxCwBAj'
                . 'Arfkl5ROZWxlttBzNEetN97MOyTDiWwO2a2QQuc2QPEvqiJcmLBIUdIl9lPhvSIWdn5oOTyamoH5fwj6nRXBeZIDHZssy0N2'
                . 'fQgxp5i2gPBXMaLxLwbhOS6RncrUyX1h5uIBr8gsz47apq4KEtK84+XWIbtdGrWuiTXH6tk+5roepMhCUzW2oWHTRs+Y8cJA'
                . 'SIFR9cQC1khCpZwCBptH6eyyh/GPMoSJNnUYE5y9g+elDDk8WbjUpSHBge8ZGnRkZyo45jEpX1V/fBknbo+f47uvWRqQgTBO'
                . 'WI2QHL/ioeS6XJJ0KkJUzgs/mxTq+gaGtnBII2qmDeFRpi0Ccws/bHcdsJLeMNLbHB7PbbYIZmtI1/pFrcqDzIpdBVZclpxh'
                . 'fsGKbKQH8SEjJPY8+nTAHvtGmRCIg9zrc4i1TPrljMft6KjQw3wsSOmGdHCf4fE39mXUVIDdxEv8xNRVV3NBhTJrnIX2MBam'
                . 'ONCVXOAKGQfIdcHvAIYEMGCmeramxDIQnEV4mZee9G/dn64jYyonpe01hfweRw7PByZFTlccxEoTrAniATpsQLYWkFQGtmzC'
                . '7NTXX167evayXZd5RH1XSquMLX/KAslGPUxIFogbdgUUosJV3SlF86h7iKushehEyKydtN/g/ex9RUCpOc5uGniagmTgkT/a'
                . 'GbLSS9qDF6N5RwZmkaBZ6WM5b9vqhZQRtC1GZMWEkzJr/E3R+LHXEyoIfQr0e+3aZJsOVos2Uo40Yrf9fjEwuew7pSyCbMfl'
                . 'xM/toHQUZtoDdWGjetEgl5RHOny/OxLYUu0nVbWILdy+Jcz25HIUjrJXAMKlpypVtSSwlojhci6kSWImWkPU1FCACNVcw5L1'
                . 'z9wJxpIpHQjCU4ziVpXXP1PH0brhBZ6FhRYguNFHT/bmSP8UryVDv7vZExULlFHQMdx47ME6BmivtGdnsz4UB3/XTIFgdUOR'
                . 'a901UufGEMawuiArsRrYc4KCPditwGS5UJFEZImQqmEIiDljBacvcCxu4Q6AxGAMKmybknCD3mS2MYycGVlbx5h7HmhJi2JI'
                . 'jPqkl6tmuAcC1ul+IC4vQMy7wWOcukhCMwO3CILpkM+5Q5NStGSihrx9ElrdTalvCWz/8933QpXdhARqiqg7yQz5XtHVLaVQ'
                . 'u+DObGqUtPsRtbMQxNfC464oFEpfLpKog2gb8L7FgoO4X420qGu4ycrBmmAPjBruHRvjriEjbYr5sR3yY2tmZmVS6p1t3AZy'
                . 'y3+dzyCDb0jyAcqsZUo+qLrI5pfwPEwEWAundEsEx33ojf3p3OzDSsUQxITJMQCZtcxYLtJx35j578ziwxFvHeV5dU7Jk31b'
                . 'hal/+UfAkQZWQZw0KnsB61GG/p1RcpXj5QkHg8DXGIVtj5/R3dfMatVApElNBNERjc0MyhYC4rLt+4CgYSwZtQhEAjXm5eIJ'
                . 'YRr0nGgT+ZQnu8bqKmZNbypTjxKAwyVtKQEDhkZJJ7I6FOLmcsgVcVUemLwWAZdMA/1Xbxw/t9x5TzTDaS/W3o/I4f30snbs'
                . 'j9o7V33ZXEbUrpzKCjnREgQZNRaAYK+0jFMq9fge5V24Ch0LLFBlylWYFlZgUM4Riet6bPlK1xzu6sZun1pXjjkdKNOBuN7u'
                . 'hqURXW6KDRc5ULW5XWuBDDM0ZQaDoYvm6oeDhtYXwLS1b8CYQllrgl1SGTHZQVZ7WuxzL68FpUWcFcATnzOtMzk8XZq1VavK'
                . 'LHLEBZ+/iUjBbVmB2JblKpqjoYNC8HlNa4TnswTtqZU4GE5Mqwq4FM0GJZB5rlmE1s6QkvA2H0GObwgg6CS7pQ4wOGEgTSxd'
                . 'Z5lUed7xrKlSqCHBVhVWYFGM1kDUDLvZS2ldrYRxCyc0cZLbJFyQDaqdeWoLwbmuhGmTcyNzjR/oG2n1Qj5dUeJ7K6Gp6hwl'
                . 'rSrtwJqrGCHM8i11NrzzwhKZJ+CCReJ6PDJajI4SoCSDF5nG4vR1X4LmZEKQYLhgUivq4hDlpJ83T8Iw7zXigjs++I8fRqw9'
                . 'yHBxzNIRSJq0pZm3gZLta5t1m5Q60ZD/C3lDR00F7+OZQM73tiWkEO/ZCRglkMtomBo0um4IgHGM/xVxTGx0V2f0VsFjqZXa'
                . 'KRQduo24xTYoueBVApNm9MzOBOMgRiq8YEVKGkKQ1NuxHT5Unr6IZfFbIOsEyarASDYmxuoN2NhZzFp5Q35qyyv9UkUuZp6f'
                . '2WUHTZysDFBYCxtmmxMUA/I3oitAT23sdw0G2gGgljNytiEmmfUCtsTg3qNeBlqqxV0L7F0CqwM3dSwELKPACZUtPzZctDhO'
                . 'sIsnAhzXuCGNodn1A/nhsA2JoDB7lSVCfwxXsTMhODASu78bNCiCkXNK8SGVAtKr1+mDpaMvSjexwUJioh2qNYERSUy6jqtt'
                . '/CyW3HQttuC9p2PLKd3YRrcxW6ttt9MyE4u2cEhh9n1LMZYjSHuRb00T/H0Ca6YZSFbKtG2N0J+iHqw1eSpWeCPICNReKTjJ'
                . 'CnPMVLQfNl4o46f10X9zfftLeRItaOiuvXU4Y/RilRXi9ARhw86EzMHWGqzWv5EhbdCUuUsRqeukj14ppV7A15Sh4TxDPPk2'
                . 'xVowuUtmLWBEjkl3rAau2kRLper8KxlumcF+pitpEx2vcLHrQLS+0sndyn4p+OBbXV1/z48GsLdjL52lDSM0H3J+GasPkaLt'
                . '8TO5+5ptYEUZD2zKqeRDJx4xFaZxIPHf0XqRSuWMoapdhWlgaXxgtV1KtG1ybPh34O+7nLp35+6Ley9u37x+7fC4bKsiTSix'
                . 'TZIpPQM+4/PFYpXEMCdcOSNUwHYPRkt93dmLrpdLW4rE3RqQC30/0wEK3qP/p7l+lSfywDM0WTr/WR7/jL5RKEwf9j1TnJRt'
                . '4U8/JVK5YiEW/QorL1Cit0PeJx89rLvGt2R5zuFWX/KtU4XUZ/FUOZvMAlZaBTRrPK5sIANpBcl+BgXkn6Vk5NZfohuXe6f0'
                . 'HVtKdP3B0FPrK7w0ROt4ff4TPLj/E1gmt0Macmt3jyQSQhg4VfNxYBkqguuGh7gKmRsgHhhpO/QqFxl4yj6yhXGykY81S+mU'
                . 'z+wsqOeYuqpIqT9m97pL8w/bTtIFyU3cvREnnpyon4UPLCKXGAFq5UVc1mUUzWH52TYtTRzU4EVTuSdIi3lGTj3VP7QY+45l'
                . '6AiO12ENVrM3kpXiYEg1ZxaNmbJerfFlQzFhWZMFH3z7XdeUeWu6rbm1tmYJKVd9z0JkW3raV/V9AmulO6EjaQUDUfe8YjDY'
                . 'ZzJ005MRQVA6ClrQBLa89J4BcxFer6SADp2mRJEsT+RMEowtCydpE8AzITi4bg4yGXUwrUbvZrGHNRc9BijJDqWEIFZjs86M'
                . 'JZkysHMqkF98VQjhwUnXVTi5c15yeHMvT9UpiUlgaGMdDc/zNFm5lS3Gd1zhOEJM99agEWq0OWcCbutn5MQ6T1n+LIn2uHWi'
                . 'WNNDYoG/B6ppueOtBei+nMAdDMliI6ullBkcrOpuXEzZGhvA+RudxbH6CMez2s5wYqu75TvPWfenjJGYbiDrLZIVOw13VCSM'
                . '1Zi/kI3Yx8ibrRyy8zOCPmldC0N4TUejxyVo6U4lp4Xqbe3Eh3lpBmwATNWYmUX2MCwG1SMfEmo6iuQge/KPSNblDA1FyQLb'
                . 'GpFK22Rs3YTtIGLz7bY/boxSPs86BauxpLid+EydCEmWl1niNpcRS7ESJ/T4UPtW9hJpxiPZvqZdCqeV5TVVTKNU8fXqJdvE'
                . 'fcG9oM306NgOWzkwkANH1sOimT50uzhsv6hSjc4Tv2mrDy5xQGpjIR5wJRjaTD/XJhoydcf4JZ7FnNaYCLATG7EZQ1GWoT4Z'
                . 'txeKPPKeRonec3rW19GP21fndJftSGAmVvJR3fNq7wWsRy3Ct+fpbs+1rhNYboHgXyFKxT/ZdFzgcw9gr6dF4PGsXaPEZ8Z5'
                . 'r/mac4DTT+i2gHrsyZyg+drrDkOTtaf5j8rra34oDpTYmZN9RP4tNHskX1yegoNkoz5DAHVbESA92sAi29gpRVnYQpS9F8su'
                . 'YckNpg9YDnh1mrfknJHrlsO7d3vZXdZlni1CayGvyniGR4SiVrs8sKRZ5yIIBTQb2L3QwsrCuKi4py63mYa7/03hu6BF2+hj'
                . 'pbrlaClYAMqH57RFN4olscpIQBLOzgRxYHi5Ec9t6jUAjpJdXdW5x1MgahDgveutNJow5ujS+1Vah2AEYgPvvKmz5KyScjEs'
                . 'Jry4S5TlQh76WpvrhzxdksCxrA/7dd6COO8B5Uy3UoCXCcCw142xviAzjy0wQ25QN66x2PNnKAaQ4iaN8OIwnEliv7c3AK55'
                . '3c96A7c4JG6R7K9EpPYRqUvdi4t82PNEfba6pmPjh9vpD0SIW2PCSiPpitZiTFqDC/d4G8YVFjyRLvNQd1omjChfty7hWLJ2'
                . 'OSMvIaBMnAImOIFkscteZK9ork1BDGOCdFvSdugE0TazGBXUnrO+tr2qV4QvYjJY0S6VMyLKcu8w7AJY9292Pul0iYoUv90U'
                . 'YiwK5gK1ROssAsUVA0H6DbPgMVtH12RJm9yH8UNmUkNOTpRJqIBbk9hURgR1NGPGZr+oWQbkxm2K4tHnwZCSA4hKRupqCp5t'
                . 'kjjEnvtcwgnG0FxZF5BOyLmvCiXxLb6vqsTcC1jzTsrhe39gmwdB05pm5SyN3SgztJIQCS501vFs5LvhhKVdeVWIoZkDBnOk'
                . '4sqKF9LoxyeDkta+tHXurWjP7QfJFrECJHcu0ib9wvz7ViMZX64Fxb6ZW7m7X7+V3a3+fM7MkDIdOoqSiSjNB0XpYhTZQtYs'
                . 'DrZ3YrVqOdasUMQX0gFc4nKqyqKfybb2XB4uV/VRoEtvq2g9pPTobK9ZzHWdVNbmyJaCiZosW7vcDU5hnVNdWRn9OZsN/LMV'
                . 'ur0/I/h2CfB8L89X5+Aa1wsViIz9/uoiFlajDL2U8pdv1H/nk/yw8k70xstWLafVKcCaMswYkHlN3XfsYU9pji7AfuYmXgIG'
                . 'DFpoISxID0nhbC+b0CNAG/OvTN5Dch0XhkHF82dmAMGtcyiVorey9hcl3gCk6ujTrXERZnQUnR2y1Ks+akAROHeEx2nZWOZh'
                . 'fK4bTw20IBsFxCdxggKHrQDYwsBUnuTS3tHUuVGDe0ZHfa0t9vD9xKV73cOeGy0y+15K2mXHhEfygy8EHYIA5XF4HRAYKEkq'
                . 'BY5OQB4Uoxw+fU6IdWVqM+Q2Meo6cOES5CSjAaBNWdNIHt85dBdDHrDRrB3BfsYG5tkmkmJPx+nOeqqY8rWYpaui5oQXeQ4W'
                . 'ZxKmWwSmLfL7xib3xb8OjzoDm3meoyIhDm4K4UphTCAVnePJ6MUFCk3/IZyEhHDF1HwTJlm/ya/fJDGH99/lldW36jTukk5F'
                . 'YM01gcRsiGNqvoYpbCLL3ztcnhbuPNbC4EloHhL7YNVckyygl5oO99qjtylKH/6dGKf1khSdRKfE1GiLPBI7tqkjKKUCc/4L'
                . 'PEs3KRmNBIQ433iU4HjhmsQfJ6EKA17Peac3+T+gMgNRESBeOAv1cTHzjMn96JsID0eW1aQqGucj8DoMXWiDTgikiSaxieOc'
                . 'fBZ6aCx4IvdSP9VVOcnijEuwDIf86To/XJziaD7GDk3Muc3JY5mXL2joiTxpfWgOWRJWtEIQ7HIX1FyUYnuUDDI94NNy1MEj'
                . 'af4arAfMuZjduHAPNl+sI7QTtrkGd2Cr1uvcbcdzBzO/Ax8kD7G8OlAs3viuud2wTA6/rq64q71vU7vLfzCdeitQbqSYG74N'
                . 'NuOTZJwLmfK5ALf++nqAzzm5PMc9GEjQhQ7rmg7azZedS7jDzy1eYhGNq3eubvm8sEDxAbIwV1nR6Z27Nqx5QYLnUE8xMqON'
                . 'Xoq9eCROOhlFoNVx1dTopckuj2jKzCjc/3gIfw38kn79hxR1vhrrbsBlqGnYGQIgsdUD7gCIsGshH8yQJKSiNLLuZccW38/Y'
                . 'mBe/Y9MaZDDiUJMSVY0JZFq2SmIkz5sGyJ/1p9hLSOA6ljHLmtlR2+JhfqtfUnxRckG81hmSTgjyjJc2+0Z5gOrN2x1kpHxY'
                . 'UWsefh0SCXDCWJMj8m6lOnG/Ptwed206Pe/WeWz2aoaDYLcYrcjmwZe6RmpuoyoyPDYWk0xs8oKP0OYN4nZ6KKilH/DAz/yH'
                . 'Fk17uJoVOqqr60oa6zg3QCNMx7R13e5WqlP3F66gvmvTc7D52eQuHfKcLmBlsea6qyoOH/67k/9ZOB72u+1mtazaqm1qdQkD'
                . 'Vhh9axFC6TO/AxO0EZoa2/ORDn5tnZl2MSnZHTwZyjIgO6uHTsIR2nceZKG+15IV10A+iwnAKacb2eA7Q9LxaBoXJbdCXTA5'
                . 'QtnruVOz3zTNIivfEjMfI8Nzipm2hJXEkssGBxxe3vw1ujaNgxrXvMDeYUhWeWwbmg2/xeBrYBjT2KskDaMFs2waDeDybP8H'
                . 'zArFw+hnYaRXBnzm5kmVMiDnyy3LlEyv2BDoB+D7M62pRtA+9brsR1RfBVZHxFBMVgpgQN//hX44MqlFn86RjL/x4CDmGMkA'
                . 'SeXHcTzmJtW+VGnwzkYehZWtwtS272+xOkNo7iN9saOyvU4mC1hc9WCY36JmKGSTftsGfYDFcpyDFVZCySbhYx2RYZM+BA52'
                . 'plx/d9Khd+Q8d3OYaQgn1UmhQgG3F3Ree2uIe5CH6/cUChbTvrSMvuWczYBFJFjU8nqeNNzEFt8CWGBeEs3A60qz6HR4LTTE'
                . 'OEc1LQWxjr2bt7UTmBru1ePv8Mbt03SZhCNtEvOB3tE2MGDwFZfyBT0AezNVAPn4pSGOzeQD/D/3PUM91AGI2PWFiJ8F3kH9'
                . 'WI1BfTqm2Ay++B8vbdIv3WzTGvTDCy7zkQJDA/mafTI7E4tD1XhcNDoSxGMaRYe9HgREjBByanp9aQYrvbgVBqDpIJZczWrW'
                . '8IUJVSNL0b463PauDzKx50xM0hPh2E6kzzk1QN57a1ZDEhykZ4OB67JOmdchgiG86uC1J6AHSLikJLLgGnPjR+mWtMMsDKBT'
                . 'mbEwKL9PmNijzOdkB7jG11cFcWBPHGK7xxVVuMJiVyjGVWmJKMzEAHQsJ5D8ycJ1DkGbnhAWnOek9oqGvjD2Ja7+yqmpuuyb'
                . '6kAA3pubaaRkVEma2IYoeIDJ3oJu8OrZLMkuecmAvCZbBF571IdEJnD1VKO/mjHq5PV0kt7hDP1991348d7dN/feXGX7h7bO'
                . '0kXo2iqaRfiWX2OToJ+BcsgZf+fWN/SxCz0K4m8WvwLWFPJE+kKCMmENNbZj8tpEjGHZHrAD3+mK279oU+HAsaGIx7kvbQUB'
                . 'yOFEP5F41seADyNvXsQEDl/e+FF45ZL4J46kKXuEhNKzNO4Z4v0k8WLxH6ju3b1ze3lVZBF9g6H+a340SRe9Ex9BcpVTdeBK'
                . 'rsoVhAVtou+w6MS2dBWfBnkaY6gs1slnj3x6b9J339y4dv6623RN+ij7LxjVREs8fFBxS/LE3xJyFbfTK5JS6FCI4AU+Yqm8'
                . 'THPLINzAL9tL5xjLkMOrc7WqV7jEZZFnKSUzGmIzC864+U6cEOFQ9Pew8rrwz0xCBHa/3SWCfSEQd1YFpKeV06O8LheaOSzz'
                . '+IYKsHBPndAUS4vRta2e6uzUFCInnistjk3DWIKTHyiD7AqQYQdASy8KuwOwwaaLbajfcm0/tG5YqCPjsrM5nWPKtjsI33VE'
                . '3wJYwaaISJ1HfCcp8R5Jc7bTVT40lWd0Y7exjYHhhzeJyNBH53NyatvOJQjB6iaChg2OGSkiC29wkwWWGQ8fZoG7iIy/SFzJ'
                . 'i3ryREIcC2gblQrl5VoY9PUsBFKf2as3aQa4x/3WZomsykpCFxz3WMFONUeHdSR+H2EQ6Z0FyaZyvuK7OMAZzhxZS2LZ+Aid'
                . 'YiJeBrg8q6mtyCcbeckAtE8XEzL57whupyJYGcRJuqJTt+82ZxMRkd75/w3n1STf7paN0rvLL4Tijpi49mxHECfZ5JJu/eX0'
                . 'YDItZCNd6OEPsiNH6PyJEDEhqU7GudXJsK91T8wpoKC87LBdoRpPoZUI2+O3iDwfqXBboYjjbWUcn3YwJSK4TVKMsVRt0VUB'
                . 'sjibgJd63hDP74AJP3/X7wiOCznB8cO8mVg4nURCmxS47yl7j4+fqpRMQONcqReZCBPzOuBKLki8DkxPbF7elXSL332lC4wJ'
                . 'U69Duk2wpemUHltB7CluhvSL7tXYPPfsXrl5Xl2qjkI7egKqXUP0eduvk0OGrYUHeA0CJ/PXlWK9rrHXMYG0bVPgSAxLVhKY'
                . 'mCFmaUlSZhb1rJWmFE1hSu6WBI32qYvrlmBNhZDD22d76k4v3EgjRXGo+HYka1bxmHzZqaWhSTIQZaJwW7sOJ5F2Y3e4JYyS'
                . 'WniPDRVY3Hjyl1Ng9pGIrv/FOFcslvU/SdzT/vSm6oCSvy8twsxscHzvPUeG8le1McYuOddHB/7Xf2Pnzaa+Z1xpR+W5uzoG'
                . 'm8z/Qf/NP9WtGN2bidyemQ4Rm5XEp9L19hzp9fUYg0OgdN3F+n79Xs3r72I16j7g+0+/SRzw41Bo2+f6/9v4h2ETMCIABY53'
                . 'lKd9nSGqB4H4q3smL0B+ItqFNlAP0JUkiwPiRK/HH64Wk1aTtIkUWsu1ppEnJxJRwToBac3UhMbgQ2aIuu6NqoDQktf05hfe'
                . 'M9LlSUxeNXScJLFcIyxbtYdDv2bdK3MfkOJQp+ivT8x7nbPM0AWFV9Wzfdw7zhuHWPu4doKkRfmuSl4x7N5eLQ0fK4fXoAyy'
                . 'av/VYjEk0RIQgQTwEC2hYS43mFA9asLd2CqgwWgni6EwZ5QXFDW7PnJnEutyz5fyQzPBoatJsopJvbdz48Sw5SGMVvGnqC9R'
                . 'JRZWyAfWfc9qg1YV6m8rZYp7Dp9RlxNnnGXHhPQHgT3Ckbt8uwrzF3NoDw/55/p0ESrDNTO/203o5NSznjCZzExguDQ/J5N5'
                . 'xohNzsyfZbYKyQGBdWG4THCXTvikFoSVAX/eon6SX3M5Ll/JsRMTb7GiYNEQGRtxaGgUx7cz6mS1U5tXRMuJfWkQ5gVOM9VW'
                . 'VpE1iZUD5Eh/thMDWIHOClFmSN4xaSzF5KPYDE1P8w/vLODsGtv+yWJxt/xGSOEDmcDLHl7rKXHnuVcFqG7lsMTEWcN0RbDO'
                . 'DPf85mewXKKwsLi9HHqMeat55OIh8pKg5WrbDbAYlsK188Wiv6ZH1eZfQR0TW3+1zWe3ZNyBK8BcYAUwH9gNLAO2JsRC9/Dc'
                . 'Q5l/O0g99zThJxPP0wGBGcVYAGEl0QjwcpcyAJgrDwU3cAB8iY68FFmZv5RQdfVSipff/fT8pQwhUi9lEnLRVLuN4BAYar/y'
                . 'TMXCR7qNvhIetNpqXPe1jVIcphThfbj36hr4h0At58p8KjGX3oAAamT+gdJaY4KTESKOQCCxEZ8bQ66Gu5gphnO1faI10oww'
                . 'V3Zq0fXuA26OO7Z3+L5qpJFylealyBAlm6ZEuznYM0MUjCg+NiH53ygifhWAV1dcaPG14dsaV0Ea0yga1bFo3O0IzlLJRg/H'
                . 't0MzPA8A44hhlIf4wLOnJvhneFG5B7JvvBlgpyMhcEiWFbKtNG4hoRwWHhDJddhRx1iyYs3GcSecdKpLx7feQR6x087Id86E'
                . 'VVZz9B+n/h3fvPMuKHCROw+evDziLej4xhUpU+pXwUKUC/VQmIpoRCt7Y7QlLvUIu70uFNT2/Pid4wv14jRo0qzRb1qssdj/'
                . 'pOIlGJBIplW7Dm2SJEuRat5WaaGRgUz4yc8+Q+lAUure8fv3hWswJhBJZEoqahpaGSmqphumZTuuB4LtXnjpVQxRMSrjMilT'
                . 'GtQIGFqG9gdTv9unjwomTb7UY2atdQIoUZYukw8/+x3o+vVvgU2WmrbTLgxcZjj69RoyaFiXDPd124EVcz2+zkKqHntiM2Mm'
                . 'jCwh9yf/CLFjUaKyLKuyLpuyNSrQiMvmXDHjxlQWr7wSWlhBLmV++vv9shzSkEsxcld/LYqb2DL0lU9hBbslHVhmOaC97hRj'
                . '9f1NsI2+8sYn5J8d2JpcUGHP3uaFdGDv2kI6XJBjLrnHDksR7AeCQQI7MbA9AwOC7SWwkwQGBraPLzW4VhCuTRGUBuQJvzHn'
                . 'Stj/Bhk2U48CHVjo8g2PGdAgcDx/RWvq0vJG+c8kb+ffUiz+tlaSRPXvSOK2smCsfG6OKvTDM2/F9Zv6dPca00A2uLqy8cNY'
                . '4nrMKLsesPodYeDuFKOXN7S+6ZetQRy9uRpfwdyCT68B1rF3AJsWWP26+kcrNx0051Lc3b3YSRj98dDcY9+4u+fuHnajKFmi'
                . 'YoHfFeKEn4ECp140Z/912Wq/ftmC5Aii7s1sWOXBqesGayw2OVvZ7XCDZHW29Ebkp7PJRubhjZSq1Sf83PNCPPI6bPNfZuSM'
                . 'EHQCAA==',
            'family' => 'Be Vietnam Pro',
            'style' => 'normal',
            'weight' => '500',
            'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
        ],
        'be-vietnam-pro-vietnamese-600.woff2' => [
            'b64' => 'd09GMgABAAAAAC+QABEAAAAAoDAAAC8wAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGkAbjzIcKAZgAIMECGAJnAwRCAqB93SB'
                . '4iILgWoAATYCJAODRAQgBYRCB4h6DIE9G0qTB/CcQa+7HbS3xt/r/0iEsHEAymbsjYp6wUlNK/j/PyupyGGaWdIC5vf/mGyL'
                . 'yk4XCq4wZ8ZcEkY6GOtWGascNoXEvkFtioqKUjg0I/k+jc+ZalTGyssV9RIT1MNofjfs7O7h0zpQvNgX6mP8+v2v0BOES9R4'
                . 'iKkaNcn1u3QSkVMNQ3QVuTFMXsKtI/0li1ufXs/AtpE/ycn78/w2/9z3noQiEiYgPMACbWxUyomJihUTE+yhc2sX0a7dL/1h'
                . '7Pei20U0BPVjv7177/+GR09FLEPGp3uIJG1NLGQSiYQnr+JQxdDt3n0CCiFLV4bBI1F0jRao1qxFkvy8m++fiwbiQoLHEI+f'
                . 'J6rtNrXb8nXv5Np+datIieLXCfEhvWZCsVTE7T1hMoGn15ZyUSWswCgTvrcbIAPqKGGbNUHJt3ZRu6i3q77WDiFA22JnmOQ3'
                . 'Cc1Ko/8BBgSuyrToqt8XwNN2EqkXwQkAm1/6axvEdGeWzUleUSH2EJPkHzc9NCW2d/E2EIABks3f0zXnfN/ZE7K+vpna+wCC'
                . 'AEjK9i+WtGutf++31OZjJlcN9YutHDP3HaV9GIsVVhcVe72ZbLPKGlIhiCUkkBBUqzflzB6/Z6cQok3vn0W2k5RVoLHT1mFS'
                . 'A8imzFZxLtF6wgD97RlgA47limQQ2BzL1CIxif5cRTsJ0ZAvYZmW3o74zvebM2k5v7WiwvMQnwc+m7Njt0m9BAQ4/XtTtfa/'
                . 'DSBpm2PSzvQFqruQnHoSpKlLqary4mH3b0AgiSUsEIACRYFD09RZFBXSnLDA30WiIAoGoTBwDpSdK1FQpJ3pGMoLcfqQchuL'
                . '6orSbX2pqLuzaObszo66pC3sKYpQKuz1H/5zicJ9qsIYhEEDzz/7zYq8C55IdLHSCAlvJCqHtIS/qaf9NLOkFpJ4ShbnpS+v'
                . 'lLbH0MXduYTk0P5lS2sasMlBCFNEg4TUultCtjGQ4zvn7rZUM2RIB5c+PcCLaSExen12GS67UO46k06waamGKpZt/6sCgmEa'
                . 'zNULBodoIYnMkCZtcNFmANcYAzbZbAU6Sj13BMCenyzYxFl6LjCXr9ubgYkAADYb/EYTWbq3bobEyiUgWGkwveC8cZiCycM/'
                . 'U6KRymY62KNgQewhSKFCSAIGigA+SDzOmJAVTQG5gr1HW/OcMLE4g1SVYFBQsec0AfEA4h7EJYgLEOcgzkKiJgGaBQTgel8I'
                . 'Aan++0pAbAQOxENFaFBdMQlg1QBDXQ0YnyEt8MA5xiRo43kY4dOvELD8/dDwlyzfY+in4Xdh5hu91DM91gPd1f8AwbcVpBh+'
                . 'h5/X39y1XdFFnWPXn9aJHV1rGxFZvlqQYqgYW9zcGpoLzcnV6nEAkWuHVF6vCt1FF8Ad3dA5dmYGLiwFB+wrb/P8Bg/n3cm1'
                . 'XJA8f0o/ArAPduj/1AIDLN+vIMXw4/obeyxrsiwLPNbGLPAQTIHx63ekNfWpTrldvzDGpCcpakSWLxakGMJnDopfJBHENazY'
                . 'QyuBCfz+EP3oa59631te8ZwnPOQet3n9Lwt9ek9GqsEWWC8h6VsJFEfQll9WXyMgz+mjgCLSw5KRj1D1CacAl4qC6sFniT/N'
                . 'gFNYd1Z71KxDnWfUSwwczuEcztUsY9mS0VRprIzc7mVyvMsGJt/qw+2WFngsBeZVTWGlxNbSUqUMyTRZ9a7AvuyBsvpWH2V9'
                . 'M6wsUaYyXx+FkJrTmgiXXfXDZ5LlhzpQ6Qw12DHXjkdVgCodaHJRkTuyImncKOIHqRnm0cNnNYmw5i7XaMO0SkFrhOq53n8H'
                . 'Rq2K7vGOdWONnlnv67eZVrOtT2hknZa8OqucuLdy+BMmNDylG6Yx0scchbNMSvPxueVUMj26AE1gabI0x/nMnjK5qaKskS+Z'
                . 'XpfQu6X2NZea0Z+BcRzGLQlbcQk/WlTAPMcBIn9CAMjX5kCEqg5rWRTOqsdAcJQ48k0lqE/Y6n0GvaPB0uz/GHEQAG2ahBN7'
                . 'AAQMjP8HgP2f8yZsX3z13dsAALBtsd0O6+kS6MzZXnbbNdiJ20HLpRfkaYAVvK/ExyBsewKFOCK+PSN9lUbu2KJJ+O/KdnMe'
                . 'V6LEuxdETg8gWSxIgSMgxEt3LFpqtYiBPCsMpPVYxPyZt4c4IbDxYQT84GRCUYSJQBMpDZLOgCpTCaRUNTs1zOgwHJx5OBZW'
                . 'LTZ3djzwEZiA3ZMclb8QjkKFouJvTqejw5RoBCiQN9d8JRhKlaEpV8VBtWo0s+gyMGIAyPw5iA56RUC4cR/vw0fkANDNG2Am'
                . 'IktTiB3Adv1xAGk3jxYIiwQGrSTI1IjBP9H8acEuaBl47MPVBxCv0gFQFwCAdebu3AClAVx3dj7UNhPRemovJALB8elpbGQ3'
                . 'whFbmRY32VeEEzgdl25/IscOJwkfHkMs/TS70T8KeMFpN37j11gq9f+PozQMBr5df5vcnfTAzd0QoBaPgBBJwosdkLzOHQAX'
                . 'iUWHzyBUNal8/soPE/+XxaThRIsujoMEDCqO1OzFI0ShiEEVi0bJTjRXKdykcpeGLRFXEmd6LpJxDMOTwUM6sVwSeQQyCWUT'
                . 'MSLl8JTFSwEfRXwV81PCWyGZUnJlAgwXxCRYpRBVAlVQqBWmRrg6EcxqfYaQFszdIbGPcABbfD5fvz9gPW5AtBaE4WuetIU5'
                . 'a5Oz/0WVKFdX6WL2GIEAj3FoIh/+wcjtB7j7r5FgEfM1nUh79wLl+X9K+qQ10SPnPfAKUAcdBGEKFQT080kFBfXqK2hIc0ZP'
                . 'XZoKfcqUlEKhpvqhaJ2E4t6pkDEo9nMDc3PaUR0dTIFB+4afHNvlpqf3b0hpjIauHCYztRS4icfW1UKHZsKMoRNVRpZ6SU3h'
                . 'fLJOGuHPDhO/dmYY5nAyhDuMHtpt51tsGuvaR+Xy5pGOS4iEMZq8mhQYyA0mKUwpxzmGI0H+rCAn0v4qJSLqqUL6ml/wzEUk'
                . 'iiSzllmgnAwnwF0Ezlvdx5ojrrdy7zz/K8hE8D7+sVKZNdkgySwQYi8jNWqwChYyFBjPjcBzK8JbIGXBeW+QXMHFXWKSRgpf'
                . 'BeFCv/3ODxxJpOVjALbp4aWhtP3/v0IqTrfDxO2YtlzesajmTcD7CEmOHsWmmhU8ENjroEPIJ6kVBQsqwHqqDFWdQFoR+OYU'
                . 'FSBOb5Dj5B8jlSAtBbPxmmlQHUxEJ4wIh+GxX0AkbV1Q/gzafZOkAGE8ybBVijRRD8FiYs6jvwhGLgLtSf9A10SyanpRuwHS'
                . 'NiSHnr1kZ9kh6Tzcit6pt8e+Io9CdRzyswwqCoBkEfJ8r8hTGLFgFJhw5yvkjRRNCtF5cPKPkVarFIpM0n4qRQsKj7yED3ux'
                . 'MD8Du0u2racVZXzYjh92f9jr/MSXX7eCAhP9fa3kNoCUQDGZbyOUh5J/gsI6kBkVaF+NEseVYTy0v1T/QKHptZ2y/E6MUB7r'
                . '7VY08hxTAE81RdJYUnmPBaRLBxAC2x0J4fklq2KqoONwL1KyIENyFsgadA6S/jbmpmV86sJUCIEIUnnQkFIqfYnfafGOVdRQ'
                . 'YLpXAVL3HCENjiJNjiYtjiFtjiUdjiNdjic9LkUkTlikEY6hedgrXP6wF1VqyOdVI43ZE666mvDbdlqnC3+3Wh9T3BTuMkj5'
                . '/YhgCRMGoCCDggIKKihooKCDggEKJigMQWEEOmhBVhYusccmGln6Wvb1U6uRzbBKdx0H2Am7qfNciIzTZ3Wp5gW9wbMLGo4E'
                . '4skPS0uu57DwauE2Cxumh/GpBevz42RDdDCAvApgYYgpKHBoOg5JLqWbnhVmzkEwsaYLJQjTId8qEWRFxmH8OAHMCxUx6isr'
                . 'FcmcAQgnbevOUCiZuSe1RgG7aobftb+OzYxkiUdu9V3XQpeEMH3xMY2dA5ihvvcyfvbyH7pkZJ80e8etZSIrbXJzs+iTGncI'
                . 'zoJUVPhcW2bF23jG6Ly6MybOL1hYcc8VKa0iGdFRGvLysjXSqidvTjMwTI3CMjfzAMPsIiP1KWX7s0rX3qKO8oJTRI42LlOk'
                . 'sfNN0vh1bdW4HZPSz3q2KbmjMNEaznXaKStqkknN9uCSEl2qYTZCRFcWrtStTEQxbQSoHbMZXIt4vZIJlyqneoi1rKh39NEQ'
                . '9wIMZy/nYc67I4yczLRFr2TNsKUf3LxiFmhnpJ1OSt9pqiMIzSOZkj10nXHL7ViUFauLE6Z6qfZbH7jjtgpvYzBLXu8dME+a'
                . 'V9wFi9T2Fyy1Mttkzhg41jidZM+EmTL1xwxEMplZjgG+aNqakFoXzIzdRxG7PIDjS/sVMS5mDA3Xs6uv6ILzZRP25i45WATr'
                . 'PXL0w1Bawl5PSlBqLODIP335hzq40rUL9fqo2ZnjoM8TFAjFN2L+OAWzezv7DWaGKrWMWqWQ9n6Ohf3GWenqAt0ywL7CuuYG'
                . 'Au9aCHCA6FoaCLJ7XXAAO4KKWj0SCDWtgKCh1o4EHb30prHnQ1Y2DgSTa3OAQyyurQPB5mp3D7s8srZzJLiobkjAQ+0dCT7q'
                . 'nJ73E40ilkifZfaiRsGBNnz0vRWYREBBlEQRPomQvECADkUAqoDRgdxDr/SkiZBBRB/3OmEShFkQFkFYFSCbCNlF9APqglMI'
                . 'LiG4heBRgLwi5BMZ/JE84D72WOAWO6aj8zCqd94s9Ei1ode2Tu/ae6nsAwT92zgZHNHuXh4CNAhwz+FNwLcfFguojwAAg8MR'
                . 'J8BRiEIbzI6wiKzAbszKxiozlTxAwSxUh46N48t4j/PVydBJjxg9RcgRCCR8m2coz/h+BkRp14J6i6QS9wg8Fg1wezRU6xG9'
                . 'wVPWw/1G7gydmuLWd1UL6alIrLWZeU9hZvasnzF42NBYD0LXgwangEBCT1kL6v9M78vZ0FgPkca39QQSqzYScwaDm2032dVc'
                . 'LlKkmsqn9Vzc+zY3t2+f9TtfWb1eQwIaEc30fXSlTPI2WoeywrY+rFKjVgIwxeIR2XO5XLqWQkXDGcktrflt8v74BmoAYyKr'
                . 'c1ctX6LI0f8ssyl7vY+BGUEdFDj5rN/IEo4Wd4XTr6fRCzzcZJh5znE4RPb6lXDFGk+ANpm0opyTT9JXtvC6B4L2/aHzbDuf'
                . 'pp8A3GE3bFGzgisMN01IpyaCl0hbiL4OxthQKkKgYtRPKaHvCxx+VHhAhpXX7rNVztaztS8Al+wSYt9oCn1Vw8v1tDdlw1M/'
                . 'GR7NkZ3Sk8aXJehGWoJlSjSNesjDIh3mFG7yYl8Fe2hO4THGwKm+qMOQ2RPjpGYNTU2AUZaVqHBdtTPTvhH7ubRv0P6LTUT7'
                . 'L87GYAm7cEoaSng7aS1OfsWmU5JKseJG9AOFi2aIjZMdpUCeAcZZ7B6wIzATnPkepe5+6s7QtDFxvKMc7J6owqzGab/SWqnz'
                . 'q22uXJWrP65Kw8K5JbobgScsNw3D5RMt9FMi2rfJ+JfMRH+HDoeUZdaZInQOLA4pZo90Zo0wJiizfdZmmypKJhvoyzlOOqSL'
                . 'OcDZ0vX+YitnlDprn+M0sIfkTPasWyc+9Qq3JwgkKgKpGQHpktOd271XbkzpS5A9j68lbdEVbeF3Yy+a/FRATLRDSth75xAZ'
                . 'e+f64qqhs/tShJPBFoIDONmyXfmEvWynZXjn+iBYSwgbpTEcBLg3eXGPCjz7/AWasrE9nz3eTmAxl4r9vljDENksB6so10j3'
                . 'X2WEdXLwOMQuw45tP1FF6bjEXHDOKEtKVbt2RRpa31foUYnYg+YauOvqlfTSLslLNz2OVb117yaLyRlhErWPZXXVJ2fAhlwx'
                . '5HDSK2G9+WS+t1I4vANsuhplPmlPucvOH/tcYMvLHmQ6OUb70vXd1YW4zXV4L+Wc+sGl/ryuXxn12uLml0eeU1rbng/6Idky'
                . 'K9xQdfkl7EFZSPnD5KKQfh8qtqEF4V3y8K4S9rWmpADeUEg1ZNcparsr8gcb6TYoD9d4ZjwNBBHx1EUodPkaASLwa4bG032v'
                . 'XG4wlTd0C7Ni6y3zi1mgb64p4i6hqEtM9oqEvcXO3bfqBzuzByL+UnV8PCh52qLin7of/EtYmYviAslYfuB+cb6TYziXy1bg'
                . 'WXRrtjxWq5RJdhrDBH69ah/xXwLFC77x0BRH9nF+uthJvcaPX3nQynRiXmhdzfv8PJRN8x7sqYbsd9rmiSsbzne7MblAX8DG'
                . 'ZfTFGW0yTkl9ycAasW/1AvJsT/3WicbB3smyl91Ftq0jiNORykmL26x756yhh/S3QHpfYPxnCpN1g5dGMjU9vvzK+WtwusFP'
                . 'EzPVXZJtTjSy3lgomLDUm2VxLjofK0/KYzkq2rDurly8sypU4M71wTn0LGtsYWthrEPxjIQiS8DUYcOs5KVoz8WMmEV7Jhiq'
                . 'k8OsqgSU8JYYJuxdFCXZm4HvzaFKdvdlh3mbsk15uKmjnD41+qlEwtQXLpOuoa5yz6HIM8ldSD2eUynfxkGn4kI54KGI9zYO'
                . 'OV1Vttzg+AVQcXJI3pHT5oOxbLmHLmbxv8EJXjrppIFDwokdXc/GzJrmjuHurLs6o+8vowye5MwF8wY8q8Y/VtuaoxFQ9kq9'
                . 'K1kcu2P4lR4rxnb0FRih30ERc3m2Lb5DW2vA+InfwwkrGyeELw2HJKbGl8xWlzYGzdTrg2aUNkRGCVtrAA80zSxt3FPx2LTq'
                . 'lPCRWk14u7/PtLSaFEW7RqsYWZNSwOxWZCCDCienVgT+3b5Aim9t2NqZPdgzWcvF1kq6f1pGOzhzEZF4M7u4VrCojZT50315'
                . '/xHqXiZ8snhbUPNn6VVvM9fR4335493aQhbYRYJNjdVYYKarP1Z0+2UhobEdiRFfpNpI3cY8Vd9VKwRnQ2rA2ER693PMrTza'
                . 'rpykGA6Odu0O2VQvUlyq1HABOt0U1/lFiulCTFV9VSi9SVyklEFd97u2zW104/SEjE7TiVknEtNLso0ZJYknZp4wZXROSyi6'
                . 't0V96HDnQSVsElpeWwhiwTHGalX7ynTWpLCmSPo07lgIb6iArmCnfdVzqS2OVT05/V4RHK92HpziWaWKC6B20YXdgOO20KIL'
                . 'BYq7iSWTHmh0ZF1AapUO68O+wO9uoJxlqdb5j4/wO8sGCo223G+CNC/Ec4WiuXn9uhRcblDDYKdxgLo3mPtiEdylXqsvz22k'
                . 'T8G7906UIS+5Wh28qVF84yErk328OL1HxMYpjpeY65ATJfWQBlIKIjRk6EsWCL/bHiKRx+3o0meHvBWqJYuVCg2Z30BJnc6Z'
                . 'nkppKNCIFMqCoFiDwKRQCEyxhoKgssbAXZ5pMpln2q7AwL7mBvX97HaWeTjfCkU6XmH/Of3jJVsvnisk54jJHlLYu4u9lHnI'
                . '3wqF5q6k3Rwp8UEg87pvApvCQ+r5fmFrFD3tekF+8VhbfJ7KFXYsz5/4R8P5DtyoNyebeSm+dJvd1Yv75AzgXzk2/PyR/fRW'
                . 'iNV1ZcR4cJ2P3VtB/nQRL4v45Fa/QpJzkK+8IcrUrj4InV8py+Fx00umF5tEXkmeDneBU6N0dyBiaD1vOZLaTH+j6RWJVUFq'
                . 'g9CkUAgrEjJNQbrkJlX82BLJCG+tIU7y5/hp0EzZhBulOxNfTXXKIIVOXTPj7LiisaOqxGuFojXrsEjU23+MyWC7AceVV/3X'
                . 'gSPRty7DYhvM7HTBXdo3qntlphWKnaHnoryjokc0ZOAWX/3+B3gdJsORcbIKS8s07fjTlNqPHo3qLoVZYUsr8s9l65Iq6XWS'
                . 'vC6VPCPJZ1VVnOkc7TvT6uMbcppeS05ntZYrtV1vX+3VS/7ke6lm0e8xX5+fbCocnZEwPjnZSj67kDVjQXPAdVGqf4Ao5XqA'
                . 'RlIC/Lex13OXFkz4o/Hcqzezcyom++cGFlt86dImGu6Z/HFQzzfOqd1pxZmjGe6GIw+CssRpqF2XevG/3IdcCG1Ud5HrN0My'
                . 'NWvE22zvuuSLZo+eHrmLx4tyxH4fj7QEUd7O+ipDPX3LdCyfOYxeMIaXjNn/FD+ZrkkffHBrh8yilD4q8PjRxwNjFWPLdvs7'
                . '0d+8au2Z4vsSaG7YN4PR9IU9Wk+XztFGIz7arezoVWwzYqjT42I68sKFDriVFpcpP+TVaXV87T9WR9YNfpLkxSyf4qsOVTo6'
                . 'Vdo0QvHv49XLdJ9bk1U7Lcv/jNz7fBoZMJ0zPZ2yWN/LfGdUfQeR80GnnKoytJLH75kJmXwXwagiJZx6hLfPWpjuC+SoE8yZ'
                . '2rSXqDk0+/vmvEvklOrN86cw97unUGYDqdqnTbXvwabU3dpVbVO2neCX9ET/rm1upj08HJqc2Qot2XjAfea0MVPGzNxN+Fom'
                . '1HydkNJiev/WUJ1Tt2TmFOK+75+qY551w/BNIYLpU7LquAo5whfVge+z7kalrFShkJUZY1V++uORlHJzsjmsPUyXrBtsj6JE'
                . 'HkumlkluGvl84ymJ5FS2gG+8ue9+0uf9v0fkSrYLyW0SyTZStO2j/had9KtUfCx+TDHC7z6fjjIrm/xy57rTVbHTcxNyfcxx'
                . 'sKz2CmxTUrVdlvKFwf8qXJ+nIgroGwOL8AlA5YyNTNSiAYEw2DvTPuh8rH4OlemAAJTgCAFZKidUt8pnDqH1tqZVI0XK1Zmu'
                . 'AYmGsR7IhIUOC2L6Hy2CKGYg1F1PmG1VrDnGNtpTQ4DbfTdwf+X6U9XBvlhETzaFurHe6iQrHTYBgmCLRRx9kg3xPw6eaXhm'
                . 'PxGx1UzLZi3Za+c93H/6kR8xGgYE26amliRDPMzAns7G+mCpuxnfh1JOHy0WM6Ffpb0VnphRwxvLuV73fAwJjUVaKICko902'
                . 'WwbQfrXCQng79eOxUZ0O/39J+yOuQBBAvyFEBL88REWMSxQgihniIXGVmY1cTT5BbjXfc0qtWOIaZzus5fRSF+rhTPAG69V8'
                . 'm2/Xy/l0XNq6yDjDE5k2X/ClsAKIu/FY6tl50PcyF9DgMC6pJfQPRGZMNwwz/An8YMquMw+aWTcnurdazLpS2mJ58pe1P+IW'
                . 'qIAUCyKvU77UfIPxEHxJiLI8FhHmMFs94dQbEBTgs/lwHnzxtOZprIo0pgHsUW9bhnaoyVVwTnbzi30AQUBLJPLhECNqHBCL'
                . 'pDR/yQoWyU64bzXdsGqbzHqR7WI7L/MZUPuqkSwZe0072j20WxbhKqHAB0DwvypT2D+DiWgWT9QmT/6RtbaaLizSBruVRAKa'
                . 'dR0bGKi0tvWqKTk5FPrOEDMqthTo0N7/WJgj2qAgsCxmHChS+AuIFz3F7sXMLhOdhVOIyxAtlM9cEpCDbhpRAW1/q3jDGF5g'
                . 'Mc4MWIL0Y+scvvTrPRyFo0mbnNsgsaQTmVvNdezesiWyiFTeFjORQvW0DsYbTCe8lLcp8ixNYhwhqpWsAHV/q3hG0qfYEmPj'
                . '5zCDgEFz6WRLsLrECFlzusJB6VWZ66vespBxObfZhQwaeS33ISiBB8hI995iAPPi5PGAwpEnQ5pRXwzziMHQIGmmyApulm9W'
                . 'k8YHKWr8WJtE/iDeA6XCdl3XNcqQ+aXFwAKDwIQViRAJWMxYas+ixCkJ2l8WLWxF+E51DXPMIxZZbrtRwCMJIdbJWPD4Gg/u'
                . 'YTx9rQOkHkaWoaObD9y/5witL4TQTWTQgUUSzoZrJ7HBfVxVHp3LESs9pTPa6kjFC9yee/ETJ4gWcShP1mOZM8NIDOMh00eX'
                . '0LPdlUUCWg2DmESc0xZWeoCD8YAAMYMraeNimsESIXaZONNRgbgMiDuZK6gr0inoxgNo6cyzRxRiuG81C3lSPkLa+Lw/mGnG'
                . 'rmnaaon7jJE5jVhKZeroUW1TRVNXyvvET3oPbudowcEb4iYyaHh8c6Q7jVg9EJyzHi+kr9tOIG6POhpwbWouCy+ZpG44p5BS'
                . 'hGn89oT6iFVx8zkuQRGMw5EsUtOPiGCE8WAWxxOuZ4sBKBd3m7aBQXl/LFNoGIQihu4Uz0U47oi7jCyxOpBpNG2VK3JQQ7Sw'
                . 'cxHiXROf00IysJNDn+NGWmxeh0jtqSlTGyKm6S5jaJpcSXJgYds0Uxo3d+cUxtFQxKKdBu1LHT2ub6LDcuLA5G8yoMaWgHTJ'
                . 'Jm4wDUmOk6i43dHDYR7Pu1TG8UjvXGWHbh/7vk5qJ4mOpw0JOnfq6Y50kZZFpXHqV1aFXuTbpbvQe1zOc1tFOCHaJgZIreND'
                . '2GmaWawqxiHap7TTAz56B03Y2Ob2XM+C27W9BQGZjZvM7SMRUjHT3BxjeKYhDN7+xj6SCPCccqkWzlDhFzoQ97yvG273LpdT'
                . 'XkVBPTqfEWyXid7SqegyYKWQbS4JSPXhtrlZiwZOvA0Hur2Os+w0JyWrwWyg7FFLhPOGsDbEZqdYfsO2NsnP2YE95cf4eHer'
                . 'uhbQSG7f4mpg71zH+9lpgZSSapfSIAnt2mbQyWv5h6AsPKoG6rsYAJaBWDQz+S375XL+Fu1ORnRiU0/5uZyDkyTKR9aiishO'
                . 'ecyoHKAN/zTVMgV4OuRt3GJkRla0FVwnC6GOZESLnCS2cbxeGrx0p6JsOIAQGYIl2nzjwEKk/X6vJ8N+50m/emjquCPd3dgG'
                . 't6UqELiPtAMq/D9mNCKyVU4IqOyp4m1sjZteIItkFs/xCas9LMADFVek0sBBcEGORN14NKWwzZ0YYAIyUEc4ojHEihni6sXd'
                . 'HpwlagumX1APOos/gMK6TN/Dky/tWGeZ+j7citcZkOnMPkE1o1r+JDv5lItvL9N5ymhqb7Rr1XI33nn1qTnZ7TYTsxUEkTB4'
                . 'ClG/eMF15tfn0C/lm965TglAejmGyy0ejrTDMPROgmheuThhst9F3gaRr5psgz/SUslYhilhoGE6h2f0WSAKo73F5bquu7M1'
                . 'P1ocWdNyOP5MztyuxZYN9NU9oIBYDHmGtfy5E+7ugQ+alZJWpzqkRsXzAsJLeUGf2ZeapjIE4KrdSipzRQGoDK4MTZ/IZXkW'
                . 'v3z19IRackRQgK/X8Y9kfzzKIrigpeEZdMGTp0KLtM8GikKx16FpBlag9pI0ijruzNDk/T6yMpNrZGsWkpLREDc7GWdJxAQo'
                . '34R1CoaoEuddjyfVWc3QwY4pwUxaYzLZAGZq8ya/FoMZfE1butg7tqmrLSeKOHtdbUi79+SNhRRifdp7Ms5IdgC8oxkTqVxN'
                . 'uweXlAAkfSiuBg+caMSji2vBZpeExY1B8zn4xKfOZTF1PtcZDf2urQNA88vS+PNTsSmiNkBhLrO4zN7uJzdy6s4D0EZWLFrK'
                . '3wSIBNeCvosEYLMP7bd549RVkcVR+hxQrOM1AUJONp1rVhWaNNikWZaWRr8DsafQCk7yP7bgg9Sohjqn1eEDBQZ9Y0FBwseJ'
                . 'niInDxdz60xboJCTbV4C4X/mYs9+WF6aYxCmA2CFGPSzAJPjf625JoDZ0uRk2h2B1nyqV/KaZEEKKoR8xz4mxDqGU0KsQW3U'
                . 's4vHCmsbHk0zPRE7FA7yWs/BCb3ycjKn31AkgZ7EU/WJXiLaRh0ZpNLIlUareZivsWUauq2TdjJkJbB/8pUeBfWa4gFZ7mBK'
                . 'fMhKIfrrdctM0I7tPHYaUacZ7lK3nkX2ClU6HZbM58gkwL1XMw33NKDqQptYqa+leL3nCks5XJ9fzO28LiKU1T7vqS6Y9QFy'
                . '8VbyRiHLiWsJGzHssXWak7PWY2TVvcKMT1KzE0lkE5DZq5uBewhNxNLEwlpiog1h/z2ccHd+CX/CU5QMVPuWJo6wN4G5lEv+'
                . 'WI/+nMU7GgsmstTyfK4zER9vb5W34s/7W6fbpy3QJdMhF5BJz+WcSyHf0VR4pkov+XxnmjAqEyi9mECm9PZUf6z+FeaVt5JP'
                . 'FAIbpMjb1nVm3OSddwp6jJ0WSW28yuLY9wrn5MmkEEAqwoG+BFT1Js1BeLS7NTuuTMwMqTWKSZkskA1OLs6jKOMP/OHu/O3i'
                . 'bW9nvbpd5//RWaSR6NRup/pgz59j3Xgr+UIhZ4FxwStpR+HFKeLRdVomfZ2rbS7nvcK9zRSBX9lJbuGOrgTM9ObMK+HRvVuT'
                . 'a64mzhkaknZ0TD7OGy6NV642o9e4uh3xT098r9zg9+uT59PnbYz1Xq7hj3+c5OxejsEHzLUUsJEUW6QcDvmMoNSVpIt9xFS7'
                . 'hfpwrPvA4O0li6ye3rSJJLIJiOjVTcc9jUENtRhDnC460HhQSHu+jozxiLzYwdD2UR9BMHKg32LGTIQiFq5i9Jv6jCAqhB33'
                . '/Dj9RFZCANZqFuraEMJyMtY9OCmvOCeSqCfArNdiOu7plpctAdY6OWFSh2qxpY7j6pkabkdDiYA47K79NU/DlraOBdS+spt8'
                . '8diBmtSObqk9cWK6yA+CkOtgVFywJeljWwMyJlXsAUoxgQBJBLMkUFq9gmRC7YC+wQ0eYbEXKA3QZ3i3YDEIiYhEEd+SBmon'
                . 'MbPOvzVs9ZwZvCMunqqmAFQstq8vAWRv0ux4TxXRIFp4yEBke9pSIMRH60/+yA32d1en69PAS1NdGr/FN040aj30TG5wH1t6'
                . 'imUN9BkhU0TGyNAt1KSWlvRVTwSuWyJtlaquDsh+9HlK9dQCZTZ1JaC7N2deCO+doY/8eZrK3kNNpZvXCl3iFpbmtvhwL4u7'
                . 'i4s/VA7JhFw7JnY56JmdedZiKXSqRIIsHL2VPFEYHED2vvtthAgqaXxwInGuyyZnUeJQdBSRXiXMFO5JhhTSAT7477pQGuXQ'
                . 'E+CVlU/FxEIvgxk4iJvijGyX8HB1PYRru4cTcdJwqZfyYqX4yqLOuK7nK7oYuoJx1ASy2frxQvAGm9XyPd6bjlN5CTyTmZM9'
                . '/fqnmijRBYLTSkIU9mWwGJSYAh+Hqlnly36dvaa5x/iOj9W1cayADlCRC4ph0oQkUyFIOYSg5/5f03FbwS1y3ef6vyNKWR5J'
                . 'uplKt+SwWsMqG+6aM/OLMsVBkAGdrPaSv76CswAjaJvbX/1NsP5HcklusaFVxnG0ZA7pGk0XiJgej8yzQYgB+IJElPqPhxpx'
                . 'p1jifDYEB2ExlF4L7oWWW7VECBvfZ5mnSUiQ59g6EF/8mg9TRq4WrLIRK1pHJjKM/ZJvSLijZiqtyxEZ2rk0UFpnMUtMCwbi'
                . 'abDNCdCJE197KqeSl+vVNtI0qVuZ58ygykQMgxuCZ3NM5s3GC81r7VC+eTurxWmZxrbmX7RtsEa8QodFbCWZ5GnPNGXVTAXi'
                . 'lVUflLMuR7Q1753flEk4eE1Zub1P8xaWBbUjV/FCG7e1H+RbE+fJPPZdVdA7u8Oh1hMwDSxhwcEGQ0fYqVc6yDH+IQjPx9AF'
                . 'eD5O+rTnLAw8x9SBWhJukHMJHYsrl90Mw9StsV6noEx6bnesLKAwEsC7JU/UeU0eMHu9VObw6rZMvdx8lSX4Sq74SCOYqzaT'
                . '57TEKznb78C7+BGRUDfa5t7Yq8wA0GA4G/ebMMlYiUMct55egxODIz742vHG4ct57BvZnTyO4AmdGix3Emsc3AFH42JGjPfK'
                . 'E77mfCnsKqGvAqSh3aTZrFcWUaQUtmgMXdB3QZ89yF+gFpWXzamIDC7z8mMktdI4kbHYmco5kssgXKXJYZBRsZG00hiOeG3L'
                . '+QbTmPp2X/egTjggHW0JlMogTzCNEUpGA70sOM5/YFFauVAxU9e5/RViTdIhG0LiJG4CDtTx+lDIIj5Yv3LK/IcsO+/BvmK8'
                . 'ULVTSaBUJwy3TVy5THTr5kHdWuXd5z641s5rwoujwmmV1WL6Gu5s1mzBglnOaafLeZDddOPeqcgKbg+STGZT2LvCLHCjR33q'
                . 'FZYXXI7lKrjZ/8Y06GtAgIdaWNLSc0CgBTJhI5tD1+7d7zG1foYNoqC+NTcLdK7YhT16du/htuSmXrqv2dCTDZzl6vzp0R9b'
                . '4/7DL3GpgUPxf8vsrBXnQx8aZt94N15OszEfv7eY7bL51ON1CaBMYl1szb/+ldEfMFRMA+uJ0TDl4WG1EMdguQJKULBOd5Qk'
                . '0L6n3zDf+obmBlhmQWZjZeFSE6UWpNKYKtdAYjkg+034bDZ89rcMndMgrKuv938JsBzSk0D3mcE2wT606M9nAlVMTM35JKG4'
                . 'WftatTNL44aOADc6DnMZ4Xrz7FHQkbW8e4st6GttrEpWwvn9Cuf7wensGIKJc+yKcbKVz6/MNkOIPmGLI+36TtKYUTySEZQv'
                . 'FWjX5Qe0JZUYoUIbLpVQyQgYVyWwazo0Bm89vvYmuXCLxAmR6qSgQq3g+8R0towIBQ1hagGU6RYXKGnsxgnlQ2AHWiJ344NA'
                . 'Yf32LoVuTSxWcLWFDGGrNVzCou4gnOhSWuUslKC6FEp/6eqC5uvZLl1KOiWB0usgoci3gaR/v18igw8+UaN/0I7+jjoUI9YR'
                . '7u6/6s4smJBOHC0QM/EUi6OJfOLt/sffyK1u3xJK/tpQdeKU0BURJRlBcc3vtfGndPKJ0RP0LQ7U43u3WnIbBaR7kZNyodoh'
                . 'XWXixogVb9MbotQuPMXe+T295SPgLCDwjM7dk24bmNjv/SYymGJJb7d31GGiEzVsWqW2rfYi/rECEsemjtIYK1vsw2gAgRvE'
                . 'n45y23yQDnXQ1nxmWwLxgn3DJo9C8sdiu1yLRYSJAzTXiZQYJwt1A8ciUa8Ywu+Raqm9Ka64Rub84zFpH+AQZGsCJyOgn83+'
                . 'eATW4mnFR85xiNcol4Bas3RxPbiBxzxHb/oax9QEO6Jto0m1b3GXqf25TSkVkGB0cdySFtZ8Gg/ySl5PfiMnKq+3YYP75pk6'
                . 'GdXzlm9jkbapXAo9SWujp5W25Ed9FEnMQlihyvMX6N0mQyPt5EoKoPvcbPis/57rrzW4NYxMzP5r/PEI3/BzW/zcIzBIKpfb'
                . 'EN9ToWG3YSDAS8/09R3pPynkvVTtyUJkMHmWFob+ZfWp2o9GCABNFyEEpgZG4IBktzDQr4Nk342PImdCouyc+AAooAAAWgxC'
                . 'gKaAvgVVhV/HBRBA8q+PNkMyOwoY1V1Mrh3CJ5E9vXndT4XKpGBAMDa2S5Is40qziXgJlG1m6W0B+G5MF6m5BMK5LO5GkPKF'
                . 'IwrItOLTwDRNg326CWCyu9HzWkkvtnxazQC4JMBOtcQUQGIMmlCic19ANkT8cwgBQKbeLF/2u3SK+6M54Q8BcO3Jmf8BwPUE'
                . '6V+bdfWp/jw7AaDAAAAg8GcXaNep+xz/z68AGTyV5ngL2BsHj21cOPByEgZ/eKE3Ccp0/fRxBBaWj5hM3Y5bfpcRU+ofiMw6'
                . 'ov3hg9bkWbL1sy0i+uplRE7pXPDlkzKRRsL4QEjNQhQoBOD+H8FWiAZgQMHGpMTjAQFo14QKAAAjV2A+AD9uMQAAAICmFg5V'
                . 'OgD8p4QVRIK8DWKY7gdxaj+eJkSDdpJUBCnkIpW5TgOYtZvoVm1iBS9vU8XKUtH2QYPW5RaNgrRsv1cnWKYk6bRq5LOo0a6Z'
                . 'SZMsVi0C5ajRxEKrRaNq+WpY0bSl4z1SqCAhQoRRIl2YrmUy0i9vYWbRPMyS/HTUGm0vK8ikxwwy4a6GUTpjNo7VGRlNmFjl'
                . 'zyPbLrJNTKrOT7QaQ05HHW0TpDCXVoci5TKjHhq7Mdb6TEcVTocm8A4zuR1t3b75mKhjERwbqXLVqo6xJsFRR01XobYS1s0s'
                . 'gskwZDCDora1EObf1g7Ay2GEIRwRyA5REBXREB3ZIwfEQI6IiZwQS7YcuTrroqtuuushT74CPRUqklSsRKleeuujr37KlOtv'
                . 'gIEGGWyIoYapMNwII40y2hhjVRpnvAmqVKsRIa06Ex1mknqTTTHVNNPN0GCmWWZrNMdc88y3wEKLLLbEUsssd7gVmqy0ympr'
                . 'rLUOPMxarLfBRptstsXWrwu4fG6+m9bnepAZ89hLDoESY0MYRwARWxSk1fO2eKuTPMQOECgxXgqgsJdxfYDO+3BeBzGUBBBQ'
                . 'mIZeeH1udve351VIsDtLw5KHsqgxE645RqlVErqLMNjFcDHD2ObHxcD8P5Kk8XaXG18KsEgLBY9EYxtAwhMhZANHyhB7QKLF'
                . 'eilCkrxMlyIc8oEkorF4UnJp3iA0iYLEJvvmh4iESCkFPikW/vFTscaD141CoGisb9dda2gR2lJ7/y9K5tqL1efdVxVXoC68'
                . '2YjR0fzQ0mCqbJEwGNUP7b/ZUuXxA0g7hUPG2I/Qgos+9DP2X13vB+rYK4BnAflbmz/kIxnOJm7/GBcUn/CWL9uP/drv2tw1'
                . 'uygcBpPjAKi/q8CbX+Agcc/LWeeFfepZhd9M1Ra/yX9ocFoBidP8v0/aJf/RJoFqfrgql6My+MnfAfGK8xJnyj+1Te9L85/C'
                . 'svLArnoJiRM=',
            'family' => 'Be Vietnam Pro',
            'style' => 'normal',
            'weight' => '600',
            'range' => 'U+0102-0103, U+0110-0111, U+0128-0129, U+0168-0169, U+01A0-01A1, U+01AF-01B0, U+0300-0301, U+0303-0304, U+0308-0309, U+0323, U+0329, U+1EA0-1EF9, U+20AB',
        ],
        'be-vietnam-pro-latin-600.woff2' => [
            'b64' => 'd09GMgABAAAAAFYQABEAAAAA2qgAAFWsAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGoEmG7MQHIUwBmAAhHoIYAmcDBEICoK8'
                . 'FIKXBQuDeAABNgIkA4dsBCAFhEIHh1oMgT0b98Ql7Ngj4DwQUTg+QeBIRB4HJB/kjUQIGwcINFwusv8/JTmRMYYK2/drWhVE'
                . 'rYRC5eiaveac1afQY1y1Lpzda050d1c4E6bq9RLuIPhxLD92LCRTjaS9l3BD8R0k4qCoKIWlRHLvIPrZ6i1mPso58WVCMcHH'
                . '+f3jkxLJ8DaziA5g8sU9Mi6IvtJMEEtZUZLoDcPxCI19kkvCf/x+7cz7j1mxzyIiyTSplAahQCZTqpVAymuJUlbuz/Nz+3Pu'
                . 'e0tSnMmAEb2gHFkDRubI2gYYET/aqEDsIkqkjKI+Fp+PWDz//2P/+7n2uTzMKomQiCQ8iUnzEhkkBqmphCb6TWQP8HPrnzWc'
                . 'QeTYYO/tLeNtgwWLemtW1MaGMHpESaSiI9XDvhMvMT7qJTY2npdy6bXnKVx4kZ/Ta1Th8gnSAaxEwRI3t3tJaBaPUBiJB1dK'
                . 'kVjdmrXY/KDOUnYMgLFsmTB8yYEOngrA2/TdFrzOnbfa96/zq7tPuFcSdsYCbCalyITIbOThCbLmb+Oniw12AvT7+702k4vq'
                . 'wgqMMqnstxugA5ylhPcc0gSl856L2kWZp6760jEUlUt7hHwAZMS1k59LEDGzshoaGtiZdeY440z20b/kRy4iSGL5vjobrBfF'
                . '9nejALlAuPcAhwmn4SByNWTQhROtK69q/iab1Mq9vwul1ZsJF5Fb4XAsoZSa98t4MBKHxDjmgwJzNtaPhRufGPxeXTZjeZPc'
                . 'fOsAixKhPX49WZrNYgjrA7JauT3qEPAnN/17693vn1QNQhpqnmH5sqx1izp4rG3iBzQggIJAqGpZ0s6tmbNo30n0Q+jgArZx'
                . 'FO2dnxs5a5CYADQACVpArFs5i7goEgBYgAj6x1mzbWY5suV6deAQcr39UeRxJqpZB8ZdXq2Qkv8z1SqtapBQy9yMOKPdx+U5'
                . 'ae0Yx7Mp3XqTn40vKtQvdDWqGwDRTY7ABi1ASnTSgCClgzCOjW5wGiBFmeHpkRLWeq3zICnjbh7XWp9teCYJLpu76NLU2SAN'
                . 'fHbHmhlTwL4rEiUKVhh95kr1P3+vliNiY79xMwzNUgbLoMiyuAghq6xKXx5+abKP/2Wu/jduFPu88vu4OyqhKZEEbkiHRNA5'
                . '/3QMoW317/CJ4EH6kAFDSMAJkpJCLnyhAIGQjByKkAylyoEKFUElKiEVFVRlJjTbHESzFqhDB9SjB+rXDw35iPLJOIQIYBhQ'
                . 'RKAhHwAGfYSAPCtRyLob8jjQPv24qhy00QD0AwUwBHXquaocTNq/HfhnIroFeICwhFIj+JvEqTo71eBi0oW4EEqSBHnRxCRk'
                . 'QWA6Hm3ozxECddYodLqGoQUjGcoZfBgWGcYEvhv4VuDrga8DvgL4cjNGeoCUj8/kVyEayv3/KwR93xsLXdaw4RTNkCPTLtKk'
                . 'niY/EZ/wHAxgMkhdEp/faw2Dpr/v2H7cISjzZp9t57Zu/d7d63txa/e44qDt7omcunX/3l93HTuwYhftHKSK8nKFW7bFm7+u'
                . '/WRD17CqzV6RggMNaIkKG/GbLN9lvXK5WJpNE+prR/u+A33V3na0GXjI7ezVnu/pHu3+e+/chB7sn/25a7pMsYBZS1R5cf6s'
                . '0lZ2Q/311bmnK0P88Hi2tIKySimxuCZ2HcVUBnUyzgjDX9cHwsfpTnsacz83czl1OZnD2duv2a7YaL/3ymwotypLMi/taUpt'
                . 'yhNKYSnxAHZMWU3kEYcXBvD9MpKa+KwhNH6n2TXphBkD9jPyrX3eK/Ihb9vLoj7BHvtPbpZrPYrrujjKPc+vUk3znHef9sxV'
                . 'Qx4f1tffMEes1h+x1yegsdK1hWjNlP7KfVCaE0cdSCNvhpUuEd49ydaefFGMMU7srl2dpsA6kbdY7AYoOD8duZRdPv+o/7LN'
                . 'ixURpKAkTY6RluphBVAi1mptjcscnA7sQeiPwQIUb/z4bbBYkas+RC89ilIDUVHBBm0YrEkYyP1mlZ23lmp/M1ju+YF+qnHF'
                . '9a74oHtCcoUf3xXa5lWTkhT901ggAy+TJphg+WSxbWy6ZoLL3HsoNkCpkNMu21S9spNRm1QOtsN9AyoELhUa0SAN6cvIAgXS'
                . 'B3Vy3uuKUhqVuWu1tfcV1urR0felBtJCfcwECM09Sonqg+6X3IlIiU0cxb1fL+MdLLD38LJCIi1gu7dFVVtiiXyMN8PeGXiF'
                . 'YcwgiE2ez6wEMao7WN7icfozL1nndaZyi3bNXb09taW5S1lrbvdwhrnz3COhN7IB6GOAA4544k0AUSSTSpazxzTyKaTIxaPZ'
                . 'VoJl5tPDE8AeYONxHbB42hDKBZifS29cQRS5A9K0I4f1QkbJfwMQsJH5CbjI7GdoULp/xYENM0BEAAARELq1T0BDvQAaARoF'
                . 'agQEyW3WN33pr/29x8Uz/N3dc6C+GuSfxlw1eG/kK6hyqoUtA+DtiHhBVrMiwvlV9+2iaNzUk1h0UpX13lQ3J/yeyo53Guhm'
                . 'B8VCrYC2Omn1jOWcasHJWUW5biqg2iMZgJufj+6NnN3l0QYN8lQNcMZdnzB1HbOaUjH0tzf0drDdVrHSYfWq+3gR8n113kHg'
                . 'uLrG++fVWjHTpxrLTnv46Nrl/TpanweCrzrsq+N03S5FJSoZNKfjC3br+2jzaykbI6aUAt8rspv1WoV2Vxue7xim50rrDkuP'
                . 'Jk0+jHiRA192nXideasy2r0A0XW1owMnPGiAlG7W7X31i2mKGk39SsVuqzYddg8fzrPW66qzNeTZazFb2VkOP+T6nH25ZdMz'
                . 'wTYlxr1nq/b3aqmN8qcyuX9pmKGtvnqhjra749neJ+vrT7Cb7JmJ293MrI2N8/qBQUnYjS4AeubzChVD+ZYzdXLwKXp2JzMw'
                . '+LC73VTjYLC7+o707Cy9a1NrxFK7XUKpHNM4MlbKQ/vB86ZGe+qqTUt9L+r1dMDS2pvA1DHcR8dNcuHPVIyPdE5joMxy4cls'
                . 'T9P3beSWdK6ODlTNMG9+4SOkVxC4o7PMMx5qCkwKDgng1TdCBuZex8ORSSvhShl8MAYmbnfZi7pemJ7BC837gUEGCI0IEyM9'
                . '/DggATotjzbnD70NmW7wh7cSacZlw4/HnOpxT+14xb38jzwgmS4dN3kG0C6iuBgaULcSX5dWLh5Z4g5vpxdxLYWrKW4cT7Wb'
                . 'YT1Sv+uvt/YQVZiKVXfRfWKI2V+HXcBV2kIDJ2tX8aD/qNqA0IJ4gGHWeESyWMkxR7r55qu20Ao19jtggUsuWeShRxZr0mSp'
                . 'Tp2W6dZjuQGDViJ8IQ4GLi5CgyaKFi3T6OAhJpmEMpk+go/PlAFjhAlrFBu2CCEhiogIISamQUJiIjtOWGaQ0uLMmQkXrjjc'
                . 'uNHjzhfy44fmzx8RQA6FCzdBhAgoUiQiSgK2RImMJElGpEg1SZo0bOmyoBy5UJ48k+TLRxQogAoV4ilSRFexYkSJEjpKVWJS'
                . 'UtGkpjZdlRpcM82kbZY5JphvPspCC+lbZBG+xRYTWGIJY0sthZZZxsByy022wgqG9jtgqosuojz0yBRNmmjo1ElDt24aevTg'
                . 'GDCAadAgCuEHsVFMNa6gf9E+hAOcEHDKkhsnSLHvOeCaQw5Zc8QJivu4xuFRbgjQnrr8SpP8VVjCkvcf42444iebOj7edhe/'
                . 'OERL7atTsl9A+gBtg+OPUO3st54TlJrpRL0OVbWXv5vjAuPyYXFMV0i6R70e1EO6V/dprvvjxUCQ1BQikIfkz0EBBIpCAxYG'
                . '55SlNSP9aUst5Sfkxx5Vzp4R/Pw9o7WY7+fFdMOn1Q5/Uu2vXBshU4igJtYegI1Lgw2JM7pOeL8AwRIVKFZCbb5FltlDvexp'
                . 'Z9S6o8crFH4ftb87HHCt+ty7nQeeeOGND74EEEgQwcgIIZQw5ESQTgYKMskih1wKDhT1M0v9q1Bt17AgYWUGhwckG5y44rx7'
                . 'd/PAEy+88cGXAAIJIhgZIYQShpwI0slAQSZZ5JBLwfzCfmFRWBzCUii7TIDl3edM1+jhhAP7LqKgoGHAhAUbzr3RK9MRM1pO'
                . 'qsoSC2g6042QpOuxuHQrV5X16/eqAG+8h/p+P00Ls6j1eAepCbSU2gIOJBLo8YHa0VsgPa1EAp5TgXrCmwKoOYTO9MaKYDOz'
                . 'JJ3f9RdeZwm7FkEoaBgwYcGGc29+7yTpAe7dx0PhiRfe+OCb9esRAd0nkCCCkRFCKGHIicgm9rj07pOBgkyyyCGXAlvatVoN'
                . 'vQpN/swB6HSPTMXM1sXDb3zqPIzVdwIFULw2Jk4ASRuigtOgnb4LnXZ6wqLJRA3M/sC4Hge31J7dntWe6RmzDHiEiwZxFU7X'
                . 'OPG0rBIWpDc9WV5RBrPeOdwLoPx0UUEWFJ6uyCmF7CelKUFRp4eerS5TQcCUTA9iiSmGaFHoTBJ9q2gWI4edCKThDWQriScY'
                . 'N6QD4s+BLZpYIOLL+0QhnHBR+PSw7UWH6taD9qD53/ugfZiKlHBXuPo+Co82tjmIKLMQ4WajhIuCT0cOQqB6i1ePWjBaaAC3'
                . 'IDHJB5URaKWfAR++11Mp/cVG7MqtCjcxu7+cP3MND7eys4bcON8KwLs6DmMXg4SARWa4qEulCB4hZP1pMqMkcGIgmW/qcPEo'
                . 'dKWlEAOEi34DcHd4O8whKsmv/wAvgFv0I+a83nCV1tKrq2/OH4D43XAAbDGAsCiLgFl6ApYw5DXMaujK/+jZI0CbTIuEqwV+'
                . '4NB+vLmRznQXKZriUKbXLBjANcxgCc/wCTq8uC8zMvv/H4ASATtutstUezYBNSj2LUzowajx1SfJH4AHAMIEoLdsg/n92/0e'
                . '6D5p7QWgdam1ovVLK+XZ9Z3cqO+9BwjYATzrAGCXJTV20W9hF2ad9/6XIx46rdOgBo9cdMkpTx1w1n5nHHTIay+9ckw9xKVB'
                . 'iw6eSSabgv8gHhstb8KGkIiYhN0oGXIu/p8bN1fVuqbfBSPc+QsQRCZchEhREiVJliJVujwFChUpUUpJRa3KTJe1u+Kt3Y7q'
                . '0O2JHo81+aTZXHf1qdPiszZvbLbFfwY0OmHcJvPcs902OxzHRNDYGFg4NE2kawI9+qaaZjptxiyYMmPF3HOWnNhz4MiVrWLe'
                . 'PHjy5cWHn0ByIUKFSRAjVpxgabJkUMiR6YVslcqUq1AtXw1ruYZ8cNsd1910yw0R/EhkVajw0pco/Bk9IM/9eIwOIEuO0Xbh'
                . 'xyjszg3toKX3S3Pxb6nl4+Lf8u7iL6RXgM9do0TvL4Fy/R7M7U+H3TrqZz+j+PkTSAtXnHDy65SPjLUAXwM8cp0BIY9eChAA'
                . 'rLMCJNiyLbACDSerhesLUsEkeS+JXKgkFf0Eya9zghRBsgoDqKt5Jvv66AVp967ljM3K4uLeXVrGsGQzzJ8vxwAfuWPNTui7'
                . 'GaLGrBw2HRM5coIXX11DA+G/HlT6meswyWbagCnOYmU6+eUyx5r4leKjq20INwZOr6OnZ6WEvjCgpmCBpKRZzELovzSaUXzb'
                . 'LEgI+Z2AfCG8Sgd0iHvVvFOANEicAnziAN6NQ3atGpLWmevTcKnJtLL3kpZfpFcbgoJwVfJIVwCHLFqABjUEysIw9rLsHfdG'
                . 'Accdxtr8VC3AdDVh5MOJbQldoG3VTdNpSuj8tZmCzvbVJ0X480fwNA8aqHTofG/CRB2rVQK+dSyMTYLNOFIBFuA5zoDsY8Sl'
                . '81GYANp6AWRrBqIdB5sriwcRTVc4vaTklyoE2MJVL5u+jo4nIPNT8tjIyN41SLTfRbqkoINwPssFZZ6aiJoehY42QNgbJPSs'
                . 'A3dWHCtL6vX1ZyK/NYb8AQaOz0ihrVn5iTwhq9I7a9jqyBZQkci+gt8kfQU0VCqBpfLdmwOfJNtSaAX8CdYIxHTM0CGV6JKS'
                . 'XyNqoPOBGiviySXiExp4/9UlqlPrI8hdnu+Q2X/o3n9Zx4ytfOZ5LSRg8JtqjDkgGoHu2p5h0iSpG6xlgVhiwspakbouE5RJ'
                . 'fKu37NOy5TtKrpsqRAYc7AYVKruSYOAkT0+56b/TJgDMWIMgwG2PCGH7FrOxkCDLVwcjCjVQokCOWFUhf+GtLIouayIixiOr'
                . 'MogmWSOO3339oVK2gwRsL+B6iUCeRCJfWqFAolAo0SiSGBRLLFpLHEokvrsD235JMPX9V8dUgngfKKT09hcAVcd95Ydq6b7v'
                . 'GsQCcjdxN4jmbN3FZaaRAslQEEqOUqCUKBVKjdKgtOiuEbR9t9p0oodXqeI4Vv3JRyU6Mfpr1gxMjimbTQuIOmdHJjms89/q'
                . '7iuFMRxt2CqZlKHibcthyYkOi417ogbq265xX53agV6S7tpjAdMUfQkQTsORZwLnQWkueNrl9bQLi2Y+XQ6gbeqoxwEeC1K+'
                . 'gI4ob24XONUjnpwGcrOvkEhxjEpjAaCBMvg2vj2MdjiQOzIBNztKPoWweG1EY88AcP7dV4yyAerEkyRxRZHj4zhGou14giYu'
                . 'bVSq2gImiiCJxE/hJBtfuxNKV9WMw2l1TSXIjgikiIRbua33y/BrVfhAx0akRSj6gUEzDDWJYsGw8EEur3hHYBKmJW7Duqks'
                . 'ge1oRSbQcL2ennUOpyrzoj3RQVtweWtYoFIDJAutxmAQnaRH1Vfk2g4hOmmnE+K8vX686saEUKW2QG9IHRi55UNLmJXJrt5n'
                . 'rqRttkZZC6IUpi7GGFsVvf2xhfWgo6cHHSXi/R65O1LLKzvhv7vJ216lf+MYLKEWJO7ELl1vJupoW8s0h4VxovH9PU9MP/2D'
                . 'nGvhJ/gROPvN0p+Ai0fYUP+aYzzVI6k+lpjF6IUJHN/Uk9XjAVLN2k33KucPUMnrqie+RBIGTDC19/Nfi86DqTk0hD3mNq8b'
                . '6yvtVimiV93g7BYJvV6Rpt3yImgIbz9+o9tf80YGg1tdO5MPuSXvDL6LBIgohiOr76VDVxN6/1jAKUwltRE1ljI+wN1MLgIL'
                . 'xRM+RAbgpeGjxFrMbyCCBAtT6ZAoweIGIkk4McWJJNRkhGWbiJyw/JQUKAgrNhElMSnroySVLrNqA1FLsHoqHdFIsGYD0UqI'
                . 'bUrsq9ONrNtE9ETsowsGwoZNxEgcGyPJ6L04KayAU7op7mTW4T2GN54fLqHaFh4tPbZaJ1Mha2eycWbcondgNzjY3mMHTzi+'
                . 'qhYnZ3F2FhdncR3Abh67e8IDqjZPZ/NyNm9n8xnAvh77eXb9kdATf6h9Kpj1TPX1F8fWWFVpz+a6WO3082/Uv1/EDAj7Bx0A'
                . 'tAHY78AegYk7wOJbk8ApXP69KaBOS+gYEhAg/T2UV0xH2Is85rI+SolcCN8jgTodcyHO5eDScHKj0Vblbs9Nsov5CMYpKlch'
                . '6DZpCVdto2qXdHvyEWVnsxBLMe0h1skniak86u+JgeQlEP9lomhAFWIT+CIkE/YnU5nWu0+HOAKB0RP4lWYFImJChdgGzniT'
                . 'CXWITeDY2zLG80uSO2cjW+WRvoUZZZwBUQUHTdrUQrUY1xCFBJGP3xm6dfGkByfSZRoQm8B2J7+UCnRTimwkluaVr+SNBAug'
                . 't9Lac2rOUp+WSk+nuqSSS2CX5pBSCvPmMrsDWugZQQY7OFq1GSOZQMYQWL+2atJCY4R03mKWbe7JkrcYU2vOakRdIYPoGIBI'
                . 'pi7hAYlatLxkUQwGYRj5AkQvwYdEmPhZFrKJiGMXSpuOOuWuHJlPq5LQzixmUB3wwoRQBjYJT+1M5Za8v/WO/bNN45F/ofYv'
                . 'h3jFLpw0mtL8NCBMTaVImZmZgYeanBXo5bjCQXH+hl4Jgpz7mCIi4NzGkLev0ioaBL1aZJdVRCnZSrmolXAUisU6P660243j'
                . 'JPpiSyZlyuUi5QygoNediPFplIF9iaFsswwqtjQMJEmVztDZz4MUt4Rubm9CaNgR745mvx6CFSLCykZoDiRM+yUTRwoxZEvO'
                . 'LEsOtD82G8Ss/pgHDmux6huhB4Ibx01Yx5lvjzNL9m/idSRA82ABtFDyJVsWBNMkRhiGf9O6UAgvgSfcUHCbrcicYju62tqq'
                . '6dZW5NffHWIdeE5ywpbkoqSCxBqkYrIk6GnDOpHuaVBo9+KqIcCHvlCDBFLHYDXH2ywwSKocN4yBQRnCtG6LobuOVCpVqaWR'
                . 'PGgCF27FREm3NYUzN2JjrB4fB2guOEuwSYV+uGty4XW5q+F6WP11fdvd5STuJ3TIvfi9JY6UdXU4nklvLVDutYEUqwqmiAPF'
                . 'MzWjVhV1A4aAiTaTOkuonaRVIxDClbN5ICQN6g4t822+cLIuSW1l4bzn3PWb87LHMd1SkyQJW/JsaeF55ZXwiVtfU5V3/l2p'
                . 'FIfr4qtu88QaUr7rcvXIaakITyOhiwLoPIbwgTA4XeKd7/DhrrOdy76zcDktLTTbaOBfsMmQWhX1GvBj1e5wjjVD5kBCm7J7'
                . 'I8DjGU+jEUvjpSHuBSIVlkMCxay12FjjX7JOyiY/n0kaTq/ICfXOB56djSW74cIsvaTsbgM3U6dNmaIS3PWLqdmTNZw9Nacc'
                . '/Gkck5uTIGEh0oTfiHgQm2TxTkNlW9Ty3JbxGAdRqyxe8e/LG/EKlIegZ/SVFnBe+9Zy94Uq8XMEYSDBUVky2R3DmAIuRTZh'
                . 'R2lRSqH38geczVnhxXoU+saO2X5S5lRT7mv1Um2dvWY+ym36aBs0xIyHyuZfm+IeA8P22WFgy4FTTNKcElpwpiB8d7/54eo4'
                . 'BQXpcfcbmcDmBwRYXzoGFNZxDKIAYrD0hjYzXCm8gOUY7JgwaaguLEdZ5Vq00lYMJtLOl47GNUHDUQiiKR4i2VZBalTDbVtd'
                . '1cLNlrSVy7b8ot3IfJesPzEuWIRjMpwmCqoY8ssq8m7xRBRgCLRNtD2Mqzhm1dH2XyByegiX/XQGQ5kpJxU7wICWj10AoULS'
                . 'OXgK+HANG/dFKBsLSe9i9VkaNfT4K8Wtj1awdbW+TqCxxGVeq66xp6DqJ7Pp+l9eBy6FYP+tF5SHb8k++oD9NiWlYe3VRmpa'
                . 'kvfwR57tREK0n26jciNEF5caUhcMVGqT9bV+hKi9D1GTMCZ9zsdN7UVXmk/JITfVsp2ps46GqHMEMRLWUmIomeSbjgQTC3/Q'
                . 'hifRuGY3rjua2jJIcjoIIFBUONGZZaN5R6olR0A/UinWi2BzPs9FzPnDLbScSwKBcj4apOU3aI1/0Y3iZaxx2u1zJ8Nt1vVM'
                . '+h9N63DOFT1OZFmEhzO2NDC4UXvOivBRIkaSrIalV/Tg3VkPXtApm63uscFDFWokrPdqFpn5jgWqnBNCCfrCBaGHzVvu8DJk'
                . '6KBqHhEFUUcbABd6PIkzlYrw/B3r+TyCWgQeqdt6qOSdRX8QbZbYz2y1VNLDRhEqQ88sBbMCZsKqf/Rpl+Y+HkYCEhiCEf8a'
                . 'bfmQMedzjIN9VRSbNVi3lMWBnNkJYk43LEeAQjzv1mtH+qnTgxjk/x/1Lmc4jYJAw4z6eDbVDOEYlzQOTVSEKFBIKS2vo7ja'
                . 'Wgt9JACPMyBUdu6ZQkgCh5jckV47rgS460UxY0hptiOwzNy/E/CJsPC8RObyPIzr06xVVcQh/QtTvAhDBFyshAE/WeYPmcL/'
                . 'CRLJkarYgkbHGTo7TaCEhVXGJ3qwi9t+9aJk71sdBUkJ4SphuRzaHCxRp2gmo3TkijBw1HYfg4uVycOCdkVM7dqxbWkw4NnA'
                . 'LFfdNTVrlIovy7sKFNZcPkQoFvSyn2GeD6TT1/tgsnr8UJKVqRCpsqmM1pnyfGvQ2TLH7azemL/t67cLvjF/+6rfLjqT/xMx'
                . 'vEE7v8CDfsdJYKVQd2g2pSI03zTtw16xUCuaLeHd3MOfwyqUjGOE7aH2eGaW9XmJ7Mydum6Zxp5X1eSwvHLorUGRhQfqEhg+'
                . 'NnLejLVNrthy7QUaPkHGAPJmUWSG93QQRnt0VophS6p+OKxzufNO43azeu6EMssbOO+1f9qOJdrIG5Y2JA13PybXQiP/1dtp'
                . 'rAbb2miqj1nN/0FUIT1EUUHvD49VKwmofA2d1KmDbUoGBqGmqCRjR/e1imiNS/VKWZz3as/rPHJ1OfGdKnpYi3xtLrJoqH74'
                . 'Jbm92NUre1BnS6hjGjmK9sYjuznKhiC73+79q0MTDL+3MQGB+a5XnYIYIOi1CKg6M6oI3qG7IVRBQHtL4z9wNkLJRiP3uTac'
                . 'b4ybYkmY9VBXxuhc5+uKPNsm1vWUMGd59zAsbt56NAuBYs6EojGiYbrsn0a/BhC0DKnjsSoHmn6G2heqOPj3NJNW+svEcVs6'
                . 'JL97SxjatFs7l+ZcpqaEGed9TjvgQpE6SlnUw6IJ3Ie71N3JL+64JRJHvOHwpie0o1AvPm4GKjQ7YP7wZZ9UWsTcV1g3wTG2'
                . 'VX2v5z1/qloYvqwx7zfs27xI+W2Mq4uhLWsb25F16zt/hYQ5GW+Donkbx2Wcusv+KUT5iEBMhi3yAbvUt0GBeY1bKPOOw5uE'
                . 'NGxH2Bg0rmZvIpwXFu2i1Pb+hdgiiNlD2O6Pq7NT0FWvIG0YJL1c96txDHprqCFPPnEu2UcRi1UsQ47CuGtOU257Kwy+9jvc'
                . '9yr5Vbflmjl5wf3wrWxeUu9D0qj3ppVLFia7J8z5ynd5MqMDOgH6exuIhw/cozkxrr0ODHUU5bd8p9YfKu8o0eQZRyDsFwRv'
                . 'RcyNwuYujKXsd2TvBMChQwQt6tFYNjDfY9P7bnDA8I8cRL0KkH9cqLF7LCjDS1hUEGzlMAkIWo1IXQkjEKpwQcWwtXdYFS3s'
                . 'YqvRqC6hX8cq9/dxL308Rj919fYrdUjxDA8r/ZEAi2DerkR46LK3q6zJjESx0cQAf8eOyeJJbxHHtBtm+wxUW4MB8raidyWH'
                . 'oTD5sqtRBx3y0LDGzNpD4MFOttTWAzP7523YwkCG2LgLGbjgwWxkmwq7BWvuTNsxyJk7qZjOReMuJy5rNFG2CuR/F078t7BD'
                . 'PMkCh+9sIOGzehMHvyAoB9wnB8DWRX5cmjaz/CxqMx3fcwE/SL5PIRJT/pYAfuD+7dBm4uZYounWda3M/v0Rei7r0AJzQcIK'
                . '5sUVoMQwHb5IhC7A8AUIvPCQ/GyoPUEmvfkw34xfoAt+RW3Jc4LIzID7JdyCkL8SddoMTJ2xUyn2l6jUl5QUqnJmiafF4wac'
                . '9jhx8clJjfX/BHRnIDKC59XB9QnzeDsUpz3AIJS92hIbV69vJ8VpJjNaZc//t/ab5NCCqb36gqJV8Vp9+9LBz6yTg/GHCnXm'
                . 'RT0aFoq9IoSipZhi9mnofLNcLNwQURbhmqavTstldJqMz5i5NaO7zGZd1u/F+oc0/jrOEIpyhv11Q2q/LUJFFSx9oAIYhkjo'
                . 'YwYCku1lZVnwAYEA78+yeNnZiCHjMUsdMAwVaSwdqoxQFVtW4mmAGcr0YtmfxcW3pab9CjS2dmyL0V5D69Ne91X3utA1GsUG'
                . 'pn7FDy3xgjAz80sdqcy8uMY/pCqq4WwxGh3xJFT2r6rrXep1VpiFLWoVEnwuOS3ywqZC+VqR+utkE9f6nOVmEUGC13L2zU1k'
                . 'mRd/7r5Oftpvd9r89PcWz82XYbf4zPy1YSa/LCeh9X6Zv1ZPr9XpSnjPU+Yn6YXnbthgXvyrvfVdU5uJwZzEBkfVLnjVAnXj'
                . 'mqClmmKSsTRFbcAI1CYOa/FCRT5bZiGoV0mLdFTa7IWVJZjc1+ucWa0FB/Y5VebFEYFEMDL2qSQqerT4Yvo6r6AC8JzsjyUv'
                . 'Funk5WRm//ORYZc6czVaDeit9w8pjnvPssHehy3pAMKWl8NSDFlQ22g5ZorELyFrO5b5CtAKlAzUZyz4dPi2M6un3aRpcW26'
                . '+pimzDHwAuT2UJvehqZ2sKnphKfOTlyYeGlxKG063P+/inReN9TrXfp9uD7q9z9QCL/JCGVnB2Pj4W7QM8CfFterbyPFwvz0'
                . 'LTLCE55+HBsY1RTX8YZRlDdUHAki8KsagQCsHy6uq+TvtpVbxBt1WnGbr2CzVViQNq0O2ahS3MwkOQseLNiFseL9WbFTCACI'
                . 'Id2DZeWWnFIeERTaLWT2g2XllKLClCV1Qd5BD5YVYWPxa+aE0gy1RxSPunIty3HGEWej+prDiv2unIG9zHUs9yZrjI8v3IU5'
                . 'MMA/tC3qKlN5B+iZJ7TpO2JY36YPFsxvDupWelMJL7aHL0haJa9VtxPKJlTBGu4Wo9ERT0Llst9b+BmFCjTE1DGX8MQhlsKU'
                . 'USQxL1KKNprkTTk5agUb0aKDfxyon/xvEnteB6HX9goS1kjfwWRDY47qnN4DPz89974Gga/vEYvbz7CJE71e07y/umMDXtZN'
                . 'VAZauvIsDR3aFBeL4r93+s2LkKtJLi9Xo7YGFDct2f84dSNtb/2+j/fJFBoO53K2B2ssJWkkxVQ3MAYoj/w/jsGk5NKKraXJ'
                . '42V8hVuj15VuFOfaStnPXx4HotB/tp+vf4NM6fi2OuVEKSLzqTXqqh6lYn3DYrurfbGBVI/1RFWOgdL5kXmDPeD2OAKG+eH5'
                . 'UsfAVhUFrw8esQHse8ME83lrF7REeSunkExdqsz3lB9A2fsAlbdyzj5PuyZZo5S3Qq8gSBen/Luw4bUi+6rt+G+BdPvCwugI'
                . 'Z4Iz9ghrlDUyuzC9+cHgAzrydbPKfuaZ9VPrn77f/O1MDAlZNMKyFS9+f6mQ1L2YgeR0Pa9CoS+I1d+79CbkSst61/a1s8v6'
                . '1wx08CECooXyIxhrNDFqxUQKtCCiKODJnRmlCOIIkZAVrONeybQxmdh/hct9YZ96ob83aCwO/PWlvJ0Te/M5TAzpZh+ff4d7'
                . 'J/PgWVB7mLMPP6EdDvwbsqoSx/BBE3XgA7q8Ze+35Po8Bz52U4XlvrSz0i068Hus238Q/rMNY6U+ihAVW+vzyoGtkI7LvEgn'
                . '73PkA/0QXrbSTc5R2MFx7YqmNxyQ3FSfo+kJwj2J60+y4Jcf3wt0w66bBi9ZLXEQpC/LVgGJF5zwXd0/y2mUiySodiUs01kl'
                . '8b3L+GeJ5PDpNiyihdDaGGv0YVQQE0G1IKIs4Mnow7LuUB2PqigP7TpowBoqeHonlENcq84ZVmQ0FNFtBzqAPuhWf0fn/Qja'
                . 'OBx43X7k0b9r7Qb6oGOjrYiukGIINACFFhJr+0NcnB/23E3vLGtK3YJWVSmoH+Ws438uf85H4Z+sfggnfGRn7zn6zHpBhJm5'
                . 'pCUFISxDdQXHbyKIPtwcV0dzlMvr3kED1tgKHfXvDxExqLAWcuSMU6E3HZa+Rg3HaWJW0gB2EHgyiDTdaeI5vYKxinbNo8E8'
                . 'xViX21/Hr+qmV3i8y6pzsIYynsYJvrsQ4gZVbilPb65X53QH4Gaq3qmAT/XuAXpg126xl3zZ8AtXr+Aheo2qvrgccJ8awE5l'
                . 'GMI3myPW6JxKvPUBB0+LPXubh3roAbZvyD342GEffKFFs9qse1h7AoSO4zcOsDNU0Tfg4rXFkZMD7hlBVbF3vbP3aF4kT0iM'
                . '09WYyFJiNVAKe3TYvzCEzZHmoxL0PbSY4tvbCgzCxnVLDFNOrkyBeWpYQ+ApCrgKW0ZIJHKvisJewFHwNIShpzDW6C9GWUOb'
                . 'JmDgXso0M5iq9yKXd7NoZTLgzT//vvlhVITpw13i5TiltCJxQdTI/kdAfd8C8bnbw+SbEHSTTL4NQbfLw4nRRK5bd6a/HHRU'
                . 'Yyv0VvPIUP42tip/V07gH98+FuO7OmX2rjLKPES6SYFvk6DPw2VlmmVBAxPrCEX7sPbc8sMO+56QuBGohTJdkZ2bFE0B1+t7'
                . 'LFS5+WH07Pl3Hv/0l3tGT17+7f9MM/Bg2cN0VWLUumqndIylsGURlkucObpsq4iSJyqNGnmvc8iH6Jnil3ye28fIEOcLZ2Bv'
                . '9LC9uzLD4xR9iZ/jbVCs9hidUPZjboWVuSr9xynYgi6HqtdsdsR3FRR2fqLOyg3cm6CVzQEtN7mc220rh92N3OZ2ByvAvveC'
                . 'AVlxYR7SMXT2bU5m1u6Cvufr+mAesLNWmm9B14t5AGefgA6YtaSKb2C2tGDk5IBnRlJd4Ko5lSxh0ZKoVcOaI4jnnysR5KUi'
                . 'bdq8S0m/eHE1kvPGnhhKCrk5+ha7TqX5fh/WZNtRRfC82hKbMN8eGzSTDOORvpF5kI01NFOwbY8Om9/xk/jl8g3lCHBng1hv'
                . 'bpDJ2hapzuE5rJt+qnJMpeIvgHKn102/cPQyLqJXpcR149O8iM59ELXaC11pUBKx+FDh5jEeauWT++xCVHCXmCIPP+fVa+st'
                . 'aERhYg91C0vV8v9KGFAlbrWs+vyTJnkPsBnmUkyoo2DJmYTVeuGVrWVAP1TIT1HYvrTUNb+bY8/a4L8FaKQ4qLCDPzVuALrB'
                . 'Y6kDWeTk1IJbhlhLbMhi4gvsxRxFwZKpXWAOaLmwznZuFfDqvqM3OTo01Iu4qFKgCVLwDrteJDLJVPaIQfINR2iSS1Qul/de'
                . '9J4YLNXmVvKaOVipEQz/Q1wWiAS2Rrd2RDr+RV1JK4FqYCi+xHp9Fa7KrS72uyzqLRvEifa/cTvR46FQ0EEuZ/1URureTR26'
                . 'aS7rNmQo5/w+n2c8l9gQedvYXwprLk3VV1fY8cX1hOGtXYNdwy8nkK4eTojWRJoiXQOZXpXokYTtVa5qF515KOYwdlvLubZz'
                . '49PY6ZhtrefCGWml1/ArgHp4NxnRQXU3cPmvcnUlvaavglPow5aXitxKL0c64YtylBczhSvwPWTlFKfZ1tc+V4yzpdfybY/4'
                . '+ev+u4z26EkWPfrzUV2O1spEo909FO3X9NMHinN60SJY7D1tB651vcgSXONw9cgD6+rVvAg+1eMGSqH00jWpVEu5kfFxFa1G'
                . 'kTCRiEfANR+eaVvYl+XgM5xcHsPh4GflGx08LsP5qkjzYB2ZrINJHjLZwy5abW5S2KrpI04HfdQT0M3F+KjDuaeogHaa/Js3'
                . 'b/JvMnl506Z/Zg5ylQuLQ9B2d+VTFvaqEYYuNNZt09SHFF4RzTgJsrXPH1w7mBqrIRsvBqhqU1uWqcqi19W3VlsUH9o7xE6s'
                . 'vYqrQglu8ibiN7/y5U6lVKBTKMprKkQPOAKzXMLe8zHYR8G7leYyHqprE5iLNWxIyzyHEVrcOpO1rAdx7nuiEugIunn0vc7W'
                . 'zieq4u+q+LlYXZD8sfr+sgEge2GxgCnSMEEtlQrq/hJQYy6eAYa8jhztsOBPr6zK/FDkbkBlLRoBHDBYq9iOCqytXmoOwl16'
                . 'Gb5dVufPHxD6eNq1QDdQqXiNnyk3ZOZSdjxmBrNdEaOkIUcA+fWWcra+SeayarUuLMO7Tj+1ophxWLlN36z0oF0d8WWA2c2p'
                . 'b1LuYE0vL6GiWEe3GC3RimkmRvMyDBrOELhKrb9FZLHACjR+BXfdnCGAodmFZhJr0RJZuv2iTJ/DZkNIRjKHVTnbw78XQyQu'
                . '/cIX3F1SV3/wq8r5g61BipI+J7sucBZXVz/AlBmIuck7Rqvc94Lk12E3cle6tlKECiguoYjihgoikUlAdYuEVJfqFXoJxLI3'
                . 'noAH1/+xzmQfD5UEvzdm71q9H0YYtZpFjUAnBlOmEhLP4AOcrr8oslyVmG806xnLtjlFz66UvLoxpSC2h4Brj4sLTGfqWSm+'
                . '2NjqFFxtbF5ydL+Q8ooNIlWuWJnQDAtc2fY002Hd+K2ut4QWoWl6h7VVdBzDKT67F5sP+ALT0h9JShxIT+D0Av7Jby3Yn44x'
                . 'Lk8gUGmCqUzw/subT2MQLYdoZvCZgQKFMXkOcMJvE9gnjJEAJTtbzwI/vs414w4mJz+OSzuRknxCq1lhoNfxbb/8z7lGDaw6'
                . 'Wci1y/XkFPLSOJwO7aIvWwEnrhBB6w2YfMMKZr7wJrAC+i4oHR5fgj9Kq4qPrU5LLYyLS0zZmz6eED+WjjsQnzCunA4iWg6I'
                . 'MvlMf4HSlKS/wrPGugA1O8vAIqr6HHPa8eSUE2m45HHtG6HuvivcgnsQHr/2QPBqwov7XdazoYO5U8OL+4X8ePH9bvx08aFQ'
                . 'dXgRZ5GC+i7eRrpLSdvud7rpq3/RaGJbs56oxuWp5nrWFBgYmkdtXk2PUUbCPUxJJRCrEZ7eArK4ZtJxenahzy4gnSgWL04I'
                . 'vg/8m1iZcBXKvJCQdBqXnZuF25+QeDY946mEcOIPtgtcJkqYoRF8S+/R31yk0m7YMpzEQ7M1h2bTHfcCwF+bmq09POvov+ku'
                . '1i8130Oz+XYfm6tDRP42njcd0W271fmWyCIyTe+wtf7efS71hafYdxeUOTJXNi3OTtMnvUZe82WATZTiPJ/uVHH90hx5uFnp'
                . 'Dvw7ovYomMUIwgx65GoGelOKCVWbq0VtIr1ZP9OWhZG+aY4Jwnc8BILnbRh+251B8Ny5/pXpT9OZD0SS+y/tXUwN3Qq9/1Xa'
                . 'C7vE39/oxTyBhS9B0EUYvgmRbuLdI85Nd3sg+BMS+VsaxPdK7V3KJ/rhixCpspUEDd6Ruknu4X7y+fVk+OLBL27EyCWLn8SS'
                . 'mUaW9n7+Ni/MyxZUvLMuYUdcgrRefpIITpKg4yB4jPNkYkZa9UAbn6R+n0TN7wL7l/MkHlXbFPHAfIOUD9amcTZ7zXWtYo2+'
                . 'SazfoEdoprbx4kyS9iPSJAgeIEHHIHk8R5ReAJUEUmkP9I31NlJNW3vqxNar3cGGnppBr2V7w9Ns8qV2nfgSmH5LYOUwbVwu'
                . '02plC/iulmXl8sh+hfhpuyM7v22MmVE8xl++s3TZzlF+ZnFM6hM5sz3tGY9Tzu5Oa9qVMTEx9j5COdNcognUou4idecBekTd'
                . 'cdnbQj27c1xcwZzInNiJko+lzH3dZSze58fWV+mmtS2PbIwQGWr1vY16TtgWKvmuhPboh4VrhkOBnbwX41beCx0JZF2h0+q1'
                . 'KBrBYRR1hAjtZMUsV2uK6qghosBFDX3YF5e/5b9apOiORsNqZmnUd6KX1B9pDao1C8IP5jHzj/mLGnWwpQKL5HLNO+roHM2d'
                . 'ItyCFtHNmg7+wolaK9C3QEvrVfnVFhSflLvgFrMV9S08/nMwfIgaJbFcOsaL4s6pBdh4rweEP931OyqJ75E6ujAGj6+H4b+w'
                . 'IKOG/V+jc7Oa2m9J8J9YYNRiYENNrfP9oBQFxVqQbhfpUyYEP+rj29//SGtPvbbe+ggsbnbwEN/x4Ucgnj4vbU/njsbdenzr'
                . '46B+fNQDUYOLK0OfYLFPVLyPv0/bKIrCp216zBOdL9rbN8me9hXIpzZuec7R5p+SDdRbnqxCWS0+vYb7PVoak/3HgqPap2FW'
                . 'ZGUxwh6Vmmr4uxYjaT2Fk/x4EUenOMz5soEyby9DXxhVFlZyB/Q6Tl9R+bA8YKjBvw5Qg8C/s4h1Ovh6EREsvA7Dc4VQ9zfv'
                . 'bRnW3I6YNhiNljIdtYdty5JLnQV0IT+PKjZLNI7it/dMLcYHFZFCcOTa4MSh9yfKiYNWYl53K0qnfEAE/1CNZ7n1Wuja7tNv'
                . 'Vc/uDXo1an2hiO1DEE5BoUTHvOA1G+CGRWsFrJMUpwqBEZCtMv4h0htVLM7sH5tw5yt5GodSaXH9iZl0sEs12g2dWS5nBUeh'
                . 'JWh7gSHYcOFphPf4c+nM+/LalPNVXHVmttmlWYPxUfvU+q1Z3iuHZnDCLJClZzN7cDiRFGIZWOn+PdiiIaW/krNJb+AM+CuH'
                . 'lEX+4i0GDHrOpooeYyXPkEsqEyOkUiLBNBpYvBQR7yn+5ZtrMvoV3BCCcEIBhRHRZdaxQ0AJ+KZRMiWIVShErJJMSroPKIGQ'
                . 'bZ0us+bD/hyXmOHjcRlelyQnxy2he7k8us8tfkVwn6ilUIia+wL+fVBDoYDa+5eveskTIEtNM2XxUtXEvSAPJKTiGrZFQ13G'
                . 'KctUW6joU9U2h2rHbU9CxMFz1CZYvtuEuIUCNyISeNwCROQRCDwiROD2CIVBip5K1VGoMJVKlk1+f7TlaNrkM7gWXPMj37V8'
                . 'x08N5FnXhN3CNpVK0BZ229bk+XxRG/vVvDDQBz7ttX+a9q2NjQP6IBz/qo0d9b1QLuUV3lHd4RWq60vP5eWxkrUUU6HXM+vx'
                . 'PWZG97s9k273CEpN0co1OfobL2oia5OfGZBSZxQUqmKGSp1TUCmKOSuttMi6KoSITal6O7AFdBnYM3fd45UctdugVgWqxF8W'
                . '5BZpMF7Bv7UFwGawTmrFvfcM6sDpuEyrLEceqlEYMUWneil4x8sPqqF6Zjfn7W/Om8HVNk9snI5WjC2UaC3pfcxWRzOu/hA6'
                . '4CP9alJZeyBNm2gjZ8bFGZJtiThtu7+UpL9C7UPGGFrdY2Ly4H3vQFu0GKcFSXq+f1hXzCEa+UZzxaC0kGVcPQ20Q5fPaqoz'
                . 'pJDTQ8LruiPVBK5zatDbnBOYdZ6nmvY4a541TAQNPmeoce7evSLnnJcuDF9Q4fwLA4GFtLblkT17Pi3qaPunbaqN+HK3yxUx'
                . 'LF2f66pdX+t2z8eUTY8lh497uuztsruffePcRdsWafy38Z8vPk7Gt9U3r2lZ09z85Gwu5rsyIiXZBIp5mOSDuk65Z81B+wl7'
                . 'tlyR0fLNM3EJPz6Iyj3PzBh8uCsWkudfSn3u0dqf1tdMgW3Iw7rHoLOvfxj9XcGbsoa0kh4HAzVg4msc/CWMTJ/A0slXPYDO'
                . 'ah3KzxF9/JzQwmSYuVwG6uNCoZkwqprhJQw+t3ehad+Cbjk0SoJHIXAUJo2+sa7mHaO8VroZ+wQecEywGoTKGvuk+fFAG9I8'
                . 'pdRGa/+tSrnQX5XTw2+/R+VRn8pHq/ZC6/be+MqfF78eSUoC9JQLO6hly3UKFulyWJgRCP/048RlJkHiJN2WRw7q2vuNhxNi'
                . 'BrJFL/JUt9k7LHvWTp1TUinKa05cm6yS58gIUIGSfpq7DEvRl3pM698EGmCBFhJne3uqpf/W5z8/cfLE/K6FX+0aRPKPlbmJ'
                . 'xMEVDtF1BWha2omcMoCwoqAcTTpj84q3maS4goBFDCTc0q+QTpUNMpu6Qf3exDvvjiZD1zg7r/hVpuRceSjeQA3hwkhFRs0r'
                . '3E9VSIqYhmHbJ7h37iXen9H5yWpo8qgEem0aO6v4iYY4kplWQLhJEo03KAgvcYXZqfJR7lM/WAKK3PbL/t6ka5uKRB5Ngs0q'
                . 'ft0YtS2RNcVV4n7kLh5j6tKm2xR1HbtJ+NoaTw18UuBiFWcCMaVQqWTAYEfZ+mLtB+VXGcJEGwuWqRZfw/2xrhE8uI5QhLIU'
                . 'JuvYtbVQD3nDAQ+ovBufR5Tk6vc/yv+vcO2KgTCOuDohHb/Topa2XpF5qlJWKdM01L8/RumVJxxBYSfj01w7nnd8DHMgmFv4'
                . 'brP74Irta5wOA4L7l0FqNchX2bH7lK6pN5Jz7Tq06a4iHDMFq7KBHcjHDZDYQHQH4B6bzBgZDIMJDo7+oK2MlhuxgNuxsUoP'
                . '88GQio2L4CYC8Zf2cGJbgMUsLtaF51iRHYEF80XnLHXCWZhih2jkEteYcQu5uPNrgG0EGDGIeXSHUt81NDHjaT0l2j+6f3wy'
                . 'LNc4Ka0WSvwcjxJedigZJa9pIDYaYUsQvxJhW8wWCjLrllrPZRDz8Y/Hh+u3w65vC7SO1L4pWMmfLKBm0sPIZlU6OO6AUpTb'
                . '9xvTobs3PsSVdr7QiZDZRGqzprdLbBICSsxJZtM4tE1DB1Mm904GzUXPeuz5WN4ewSlidCvBynn0oBfijuDtMWB2n5rMAp0/'
                . 'pN4P/McECkLwaMK7NAT7dHWOB5LH54C7beR6OVBK3RRqnYpst/rHduGkqQdJDL1PN+nULpsoNctAhG+2RwO1NpuOppnmHp6Y'
                . 'rbz+5HCQ9tR78gWumuTPBpAwaricS2WakosmjZoaDwjRHGlYtPjOXXCWptwhEZ4xiJvfdnh4W+QZTOoe150slSNwow+O8nVG'
                . '/+RvJc25uU6KignqKLkI7Dx4sHYK7WV5cT4LWhy8SzdVBWgbglzr9vht6QyJxdsNRQktsDeHgj3YbOTxXGpQ5XMYJX5aBiLO'
                . 'WSmKPIvJFd4JkNh6DuVOupo41NSXNpSF0YzNlXDmgpSTNLXjIrTHs940LdojsEV3PGkZLuJooh4js4uTmUGnTIBg2lVzNu3j'
                . 'CSasGx35sNPA62ZKezN0xb+DAzKp7EZpVUuQ6k4kUtgrurq5GrcOWedmJiXtfKDaWUqSi1WAcF4stZ5WJKkOIk2VLrJqCJYj'
                . 'C9nI1J1oEfEOYS8MGu41Y3HXNiKd22HXp/XeI6/yK7X3Pmz8BjTzPsEMG/bBTN/CyYXyVENVFzr/Ee6POAPWy7CNWkNj92TP'
                . '2r/kL1ewUTUEMVJKbIlYqMhimYL7uR8ezv4+68X7/jJcUHpn/36Hhs/gkdWTm+DjGtg+QDLJ0r82Zt7J8dQFJlTwBU5hq9//'
                . 'MP6/wn6vxkJZsjoCuYCm3YzN0RThWd/3QBDCmMr0CEqErUhciSuCWXAqInVgLk92m6nDDq1/WbG1KwHb62pCKajUvVlzNq9C'
                . '3FSLS0WapmwpZaESlZFb9l9+HuHc/OI+1xzNSazdHyjA+9D1GBZftZcfx5vppkDBEA5OyKk2CGTUYADuRmlpp0zq8g3KO3c6'
                . 'Dn2wZLFQrhKfO6CgnAMR1/XQ8+2mOTF56EQ5pi1IoQNyEf6GqYE82BWD0xzUTfpu9MEY+6jrHMauUOzVl2PQ3qdgWtXfsNMb'
                . 'yYCHuqQCNamtC9qRbE90fgBI0zQzgE/dsD3wCB5ae73fW6aYPXEpZe5DUXBVV0zal4NN1Jmxg0LxOZvRrTnaSqk7dR4NhpMW'
                . 'VQVciuYDCSUf2+eZszOuJl7WF0pbWwIINpNmqR2MTgBZqS2XoRS/Zhzsd02GGpKCvzMDDsVkDUTMhJNZ7dtmJbHNXKiNY36T'
                . 'dInOqdo5TLdjaHK3kvhGN5qBxgv0DVs9lQ9vUbqOV1LnX2w19VsbkHAVI8m8tS3GhhsvWiP9GG3I49jlkNUCNjJEOIieZhSZ'
                . '05PeGN1xT1EMw4xp7V7xALUGdNg2TmE+aKQE3POh/+/AQJFYdG1x3N4TkDRpRTdvB5JtWhvMJuHNBg3/XMHT0VIBfXoikcM+'
                . '4fuiEC8YBUxaEKyjVtWg1pshDJoG8FfEMXbu2vXu0M+5b+7XQa0pHt0gZtoKKXeuJ0zt0wucSMZRJG3wkplMbb1IWrqx7b5t'
                . 'PHkQadJHMeuVyEzgQDKi1uolOA9Dw/40pvWh87hf95bhY2b4RdifNBsXVAZUWLM7FjsiVAP+m88VJGoX+3WDgbYD6LYipdhW'
                . 'i8yWCmqNrX9P9TJOS81ZB4B7n8Ms4OGIqYKfYXDR4MoXlBUcjlMsIGfAYQendHJtt76/3C1HGEkKt+vtk/w+YgMnUnCkVW7/'
                . 'MLT16LNsmVp9G1oBWiabJreOjpjWrnjKRKkJ96RmBw5IImo6rnKehoaru6XH5rz2suCNqbeu0UN5sz/321nZF4toNqbQy665'
                . 'HGkJ2M4ntCRL6/0CCWgG1Ch12n5M8oeYh48bPRTpghFoVNW6ZeA024wFMzHxtI9jGd/WWx/7p8P//ljkKIt28S6eMXlR75CS'
                . 'D5A6OJEKR9tGVJPwRpK0raZMP1OFtk197E6ZSwVdV7cZ7xmX0x8KDIBS+nxmDw7Iad6dmy1Nc54dFf/NoxifsITrMfdFYxGP'
                . '6GL3wdT5sqqHrfuS88bb9mH32+5HsA8j2IQbF0YajiQc4PxNrGrFsNd1jxdl3KFmyVp2LbnHrMxhhAS/Yy1VbVUKtrn3FWYB'
                . 'F/GA1RWtymSSn3b8a/D3DmG+Ht757N3PXr98erx63sxDV1coDTyaCT8G3pOX6aJeYZgTrpwgKuiKGKczfb33UnU91LZUmvaQ'
                . 'gJzq+7mOUPAuwz9NLes9sExi19bVcx/VzXf0G1Mp35xJV52UrfCPsaayaoeEmBphEwWKLUOb8c63p90yxZbMONw/xIfyXriC'
                . '+nU8VU6m8wioVVCTnqeVDcyQZRp6nEFBmdepGLnyNYV7vpuXzxhToh8Pylfb8z19KffV/twHdXHZCyqT286C3NvdK5OGyUGO'
                . 'QKkmWrU05WviLh3uki9AemgkuhtVHqPAs/hArV1MzXwke0pnfOQyR3HoOZap9n5fOLWX+rNvx+zbmn2EHLlRonvQbglEqTEA'
                . 'utoLeVCZddp25PklqtkYOVyw5y3knMNqODZz6kl/aSVch77rGBp7IRfocvx134qjgUHNgs52hspsW5UfNBVFvTeZ88bb6bhM'
                . 'fStND+0d/INhooxb9a6J0Ld0tfOX/QIJtycjeJMJY6yajQsnXCgNEIls79VG3BBgwK40i0LXObuAbr0zzA/xCSdScHTtFjEZ'
                . 'tbOG2tIzAur0qYuPkzFDIeM2MmYG8wN5ZgzebDrip58qIfz6IZx3cvrw3BG8cNed+3NZwLVjPZ2oe0y3xWzt3gBiwlWELSp0'
                . 'kR6blVWMtI4A18UFPZE+tJXZtcTkYOL3QGvhhFjdbdVrvrcL9/5C14OTzSKMm1HKi+f5+0M3bG60PeLiCP5dgUG9qWy2PQLY'
                . '4n7jsSC8e0LJQA0Fpbgc3qG7oUYTe5YhHXQH1UtFK4OCvNvKIfvAIHam67bCjv9ak4Nm1XQ8/Wk51GYE28NCKv0BwNEQE/PI'
                . 'Ja5pWFP3Vl5ZVlHoMfGtz4pWomZqRCzRnjhZ1bgbJ0E7SMPyZvtzm5zVROrpILvHwp26x+pVKnKT9D5rU4VmUly6omV2PHq2'
                . 'zg3qpiLf/syxgN0lclso0p4ptS5uLXMknBc8ZNnEkvqhE+Y9LdETv9PFoomlvGpYnHOCPPKTsEBc2nCypvSRV7kSu/QvFQPi'
                . 'Q1XmJsOPtuGw73IGzKdu6ZWaYmeyDnKK12sui3xYUGIZ5lx3s5/w7HV9bI6BykLRqiVv9j5AMmlR81ouN3tkFwZVZ6MQvIko'
                . 'KsHExpMlTheAxSFr1hFkEzujCnBx6DXTcgDYg3p28Wm6dVuItH3o1IaY9vxte9VdwcMLmrBBRxEaVj4ELpUa7QKQmQUWxYYJ'
                . 'KkW6E1KWM2U2WgalqeSe3AMuC7RtzpjBT1BzWzXdNA3epwX5qYzIt/MxX32e75a7sW+bPLPpE5qCR3TdvAs7Bs7ksIuiSKie'
                . '+cA9WCzNTchZxY10uA667bLb9ToCW7Z7warS0bMtwTuAZLgtmnetuA/1VgJJOJxI4kiLK0OOe749gAPzfv29czoBZFUDeuva'
                . 'Tl1wwJ1LCB1Nv2TgFKGC6LrDk3WScramYenpXTHf5DodEm1qOe7hTZqEvsuRel5vqZ02Cs17yXCYTFB41drQXtj07t1RTPAc'
                . 'ReOQCgN0INHABIdt8dQlBktvZ1jGSpLLnLoEyRWG3TA+pY3Cwq2ZuHTZ9zopn4Law2W7PBsgrqpHqnSSMPUVcKkHlxqY1twL'
                . 'EOVg53lM2tLFk99rtzUcZ1rHiimUKCIeRQUVhRFfX5+6jEnLhdUkKcAFdp3pOer7kCY5XU1DCaR8EfaUvCJ89QKJuXwoJ8gU'
                . 'tG8WKH8w/HofOrYsa6mE12ZQZiKgT0lLZDNRlZwyALK+xj4+br/n2LradWUpBz+CXKpr0SQeJQU9RBq5xn6AdgZujPhlamQ7'
                . 'DllNkQ6hCSAyR+DOhlMj82U/uq/KDMaRN/rjjwK6KxWBb37sO7+UhNf4zp+Qtw+QcPNkC5Hftr3bQa8lvfM2aXGjdN9LZBKr'
                . 'NCAGnlDXHWsezrstlWTmGM0BpN2D1edPKX6dpnatn4V1+qtiGa1CFnnMP/PkAo3hl5bfM3/i8AfKEVx7rd86h99b2A/ju7ni'
                . 'hn5GZGkElpYgWlrmtUxIjV6zWAJwzdPV0HdjQNgcR7a7K/4KZm6vsDUOWm7V1O+d7CPmqlJq25q/9514uwO8Pm9utjfghZeb'
                . 'ciV9yxuFOiw1M+V0U+unhxmj8tfPznQwBqwWURVXoFLL0WqNZEtcLSJeFRe6G0LzX7Dq7eq1IhyDiilI0K5YzQuAzXst5f6k'
                . 'pmtgQsfmz9c8azImX1RcB1Yn+alVF1uMlOQVMhX/+54PrMROUoUrnIyRVLXbBkCHsU5wpoO5aP6Uh/EiGGSxEMS9fDN75cIL'
                . 'TvqF1STe7SS4O2d4pO8G19TaVJE9i1VG4TzCU7uOAw/r4zPwnF6zINamXjJWgVPXggtXkDNN+kHS1ZU8EeyN5XcgLv1uNEMq'
                . '2XeswLzYSGZsiDjdmg+t5/0s5zuW0pXEk3pAy2MV04xgWmGhP320jUyqJi9eqssNwRPHYi7nOLQMqeABnZtJOYsQlV5kmxy7'
                . 'RcmsdL6Eiy9CuGLsviKubOPtLnr2Mrz+urnf/gyncqkWywAXuqAQeXVxQB0uYAob6fIOrbmrhTPEmum4Epo7xKa6aWoyBPJS'
                . '4/7CA3YbZFR3/k1J53aiDI+iEx07Terk3DMNA88xNL91XDie0D2qP6UiGqHA7LkRHDegiT17puM1LIgWOt3pf8fKS2iKAHGx'
                . 'gegRFzPXGXkkdVjcPfENsm6CnLehfJvU6ZjAZhtERsDZYSYfjJlyWsaxNCUXnKuitTDgE6x8f4sUe0dr5KqLOdjsEEu+3BT5'
                . 'XIgK82W5dE0+FZNJgTOdLVbfVr7JMSIo9ICPYJu0d0mbd0RbAesPYnZo4RvcdLeF0FrYFlr4Crdot8Xd1pA7mPlX+HbxAdRX'
                . 'gH3JHeyaOk5IOvy2ub71mjt+5l/Dr8WTU13mVzOLYOHkroHY8qetgPMHcSzP8Q2uqqibVaNbtGs993Gh8JVQNncH92iY3Itm'
                . '8zdC/E/9ilkQZE69g6azUlv9hFZfcDvDbA4bPOV7Mjlkb3sQGbW48Y8NnpKuF71vHoW+a+qaUqoafUbPOQeC741w0/9n8Mv3'
                . 'sJsQmrHlEK3DEWEngPAY2T7gTsAX1mL+1vRFQlblQHWva2r1TZc78+pfVbNDDEbsCpXLP8Qw07xVajjNJ10H5I/YtqtCXlrF'
                . 'MbTtTeQWyw/xZ4APai4p17jA8phQZi9E7TflAVbt39mJgiy7vWZiHn5rnQR4wlAoEWVdKCXuJKLE19Vcnl0K5m3yeo4dcWfD'
                . 'qCEjo6G6G6RGtXSR1pSGioEVxZLzFl33AmXyoh92Sr8Pge98hwm6ye64n7ncNreViuckh9AJ44RJ207WE9G9d3pak/l1NZ+B'
                . 'WiaZdmtX5WwBV254ul2GgeDNj+Vivwvnq9PxsN9uhnmYp1HroQUaTKMvAabSp38W+yyyI/erbcOj4PzSFjOtU+Kyr/D0RjYG'
                . 'sou+Tip7mNwb5y1IZb2WDRdQlRNkFOHy5Atq8HXAkqJpMGoph9BWjFqJKy3Rtt1mTNdNm4m7SRkzcRR4KTDXCTGh7Op6ggnB'
                . '03f2astcl8kIRyjAfHSjKU8p8TD4N4HPgea+kOx3bKiFjal607Btg/a3DGG8ioiuM/nXjobFdlOG/Qcm2xQKWipDL/18gsM2'
                . 'povmTZ672BOX/G8hV5gBtYjSrt9vMY0GweTM9kW7txqJVGoH1+tK2CZflEEnG2M+1XdPPOSjpz+o4XPgbbU+TvBA3PhBHEzo'
                . 'sB7DeIc1OA6NhOgdsgAynp9ThYI1ztNIkbPKHkxaYPBej2Y2RfjW0LlxPIs0HjdpYQsOOVyZDZd0lHit2VwATLgjjp5JtEjc'
                . 'zcWSiCjkx/NcvxHTOkbcl/8meK50293WU6o46ob+IDILiG9wD3at9qFgYi6TZGE/4nbKcBMn+BTQMKwV8RBP39HSDt6a6tPV'
                . 'OKcmtkJHDDMaE1rqRNsAY6HtoFKHCo7/0c4etUdUMkFo3lONcGr8u62g9VY5TYz9DwEMyIzwv5A6+Y8+kmI8PgQR61EScvPA'
                . 'r4rfWFNARxeSmsQH/wsSzP65/blCNFf/TBEeLvMBgaGh/upNlAbTpNe2AO6a4AiBrxo2xT1TA49Kx706+WuiNzjbkVZ1bJeI'
                . 'AUJOjW/EsPi107UBM18TzMntBqzih7AA5YxVmiO/JLhai6Q5t0oZgQsdGjTYr40BP/m5fL6fw+K60oKs2Ijjh7TEUJbtnqWw'
                . 'z9KDfYtaZj4B8uUwa0fXKsKcheui0W1cvWpMn9IE3nuZISNdaQ9ppnw31bxEIt2XC2S75BZZkQQrIKRHE9zI5+zcoUcNzBf8'
                . 'q5OdgCAlJmtKJzZOT6bFCHidrW0qQhw3aAoJHzp2RBT7eprmdzIRcfp+355QUVo9ahyf60ednLixKRYcJ5VryuRHKj8R87or'
                . '/c0fHL2iTZKVRtXUSO2Iit8oZeZ7W9vOMOnvy1CrDFXx0xltejsWot+DRONGbz3agonhCZzcvnfKH/5779v3v316OL9s5q4t'
                . 'UEwmQ8z4XH4R05oxc9ss/tJT7NhsDN0D8eXyX8Beb8TFaDa/8qoXNW7KJ0Qh7RlAk+jTPZ/3JcxJ0GWHLlkHvtrjPp3a+Zcj'
                . 'QRxO7BCc8sGHiobLMtXoNx++wl9GMn8bSc132yEZyevpLR5Q48NS3+Xy34r5+q/Xr5brrinQy//WR/JxBEX9WvwAsjQlmx2a'
                . 'Wpp6DWFBG4kbLDtSJ3RVH0dUqY1iDL5i3OxDoS/TJz893t+8H/fLVL/ftMnEquwqMIo77rmf+tdKseGqS4pUhnZ3kTzH91gb'
                . 'r+O8LBxOKPl4gTCi8W09ez1sxy3sYd+1TY3S1wsi+x6z9vL7ZFLUhKKvrY2Xyf8n1R5o/XIIcDdGIL7qOyA97dxM5V0zWYxQ'
                . '5ymNscDdPZbltpnNGfYeuqOEnun65EnZicc2PqGmwAHC6XeQQXYNZFgDzAhCLmDL5bEM1gZ+ZZretm44K1nnsrt2XEIpW02y'
                . '8NlW8inAFmxUROoPSshpVPzmeOaAW0NHK7pX+sVxG7iU45vPlaLQW3eyuMFWswJk2MJEUDNzBLT4mHvH6yLxvZJ+mNf2HUPO'
                . 'H6c0WgWUJMTBwKRTbVDfTMVbsZhPgNoXr23PywC45+1mrArd0o0KjTDF8TKcu4QYmdBKYRA53585n/Zvxy4POHHX1e2q1N0f'
                . '8iVE4hTgq3VdaypH5QnLaJd1x/pmbaAX0m2d2hZFNjpbyrEEGTKHNYU97aETfE8pROBcX1tPuuEc05dQvk/q2sVCMTgEQrc1'
                . 'SeSMqNBCUwEp9/iD543SzuHfzAqpE1Cb846bqQQj3JGMkpqH9/b7z89BlZGK4Zu7hNqAe/EmIWFyROXwIUr4H95p+8o7Ll0U'
                . 'HN9W07yFBhlDsF9GBINS3Txt5pRsmWmbZFBEghy9jagX9948ZbLtjd5Hwh7as9tDXkcYnYYWqb4LTGwmSb+0atr5VOC3OI8D'
                . '4MDNrkAWmouZGDF9Gv19xsSEhQoAc/jpTPT1tVp37OxdQpxGDwXoEyTIVxIcExFDUZTKHJ2uXSKv5sxgzO+Uh1rNa3a3cwpt'
                . 'S9PigOxtEfaEq1UzbZuhazbfAfolUdsykDI8y2SwbonTCBlYV/hwgGUoaTu0dCBjC2btVikyDFYlsuN+AsupFS5Y5nlpaXZT'
                . 'w1jb6tFwWfxdwXj/H0DWCTlH4tG0cxrz7883Zn+VtbzcAka2QPKXtdUK72HXgZ39qx8Uy2BxYyadO/naE7auClGT+d8Z6HnH'
                . 'tvUFtUqwXZXOWbvl7julFVoNnRHao4uZbd1LHXA64P0YloIAs/QznSnvSnSU/9k6VB+A2jfNFwCorwid+6H682T/XgE4AgAD'
                . 's5hT30/IDR8ESp7f7YyA8DvTtTxZ8yvqPNlYA6JYfR2+UommVzdZbRKBDtlITqNbseBgPUXE6qMXd4z9Py9Y1eUnah/Io56q'
                . 'eT7VbyKGkgjyZGVdTZLFhizgc/UnCOsZ1nV74X0RON1nJk/iQFC/cKyLpjxtepS0tt3bPVzHjCscah+FmtIdrVyoRlrbQ2G6'
                . 'pyouY7KTZe2gIxWSJZNXaKKx+ngaekLYb+xYzI62ydJqCp5vDPAqZ6JECIUFE1yglFPjT1LGWIc0T8kXjwNJXSTLS4zKuF6Y'
                . 'L4RttNMfjb+pclmVi9Fll4NwYMBywpBr5jw5RppXX6w6SZQn2dQeXvUMn3qTxJOQeYpv+dhQoPhzfYaYUeNq6N/dYOZ+q1xh'
                . 'ljKm1qA195+XzTiPEIm8eBY2ucYr83wqh+GAcYeR+7X2zKoQ8B9LzqZuqIXLUEuJWnTsYrLVQ2hpxLO6CnJ6NQOJLbHq/M1q'
                . 'aEkqnlmtAG4pVFeh2nhW1SivV++rzlWiTZaZN3WTVe6TnTcpHDGFO0EOHV9SxymxIPIKkzXHAjfLC8zOa5FGHhpfrPE7Uf3G'
                . 'qWBUXSUcbCJY2nh4WOcj0hyzGeBLiw1yqBHCukTgNS9f3HUVTK0+1bWdcDk+j785N5v8Pb1XdRb41SOiyqO6WglHCSngEeCA'
                . 'fkAZcAwYB4xlC8ushKlZCTrifekg3b8/+akzxef+DAFtZVgACGwkWg/4nacJAEBWPgpOOAD8xSS/FRIbuRWh7c2tKD72eunq'
                . 'WzEEybwVkw2ppk2941WVPX8lNTeS/5yq5VApUqmKmphakVJiFVQKSEQKIucnT4IieaqUy1ImikoFkVh5yhTxU6FUrgR5VNSK'
                . 'VCgnYE/Mjh0H7gSqVMmXpVqVCoWKlKsiYKWGPTEXYo6sJV64IkSKEMidAI7pKFAdEllUMqUEcVgQNy0I+ftEpdkW0QpslRBw'
                . 'oDFkTyBuh/PcquNZqPhcy5HVfI3VCi2qqXd5XZAoUFTZUS27DeWIMstIZMtbUnuCKeUVkVggZY1Ifktt5uCD1wYsuhcCd2Q7'
                . 'IsdRPzNnIZelflby3HXfA9Zs2BJ66JF6DbNEfOXt5XPQqEmBFr845jhH/3KaP+LL16pNoXau3LjzMMhTwO6lK1auzHaBglQI'
                . 'NkCm0hph5dwMjy/3WJUaM7MCquec+MkRbZYYs801zxw7zHdCrGFx4iVYLVGSBRZZbKFkKVKlGXJeelYiA5mwxVYTZ8DIZo/4'
                . '+a2PnxhkIwe5qIGaqIXaqIO6kzCJkzTJkzKpkza4SR/8ECZjMl004j+jMUTFaKAhDWwCHQYMHULbxcRON6ykhUmPN92QnXSK'
                . 'Hw2aMmTy4uOmW7NO/RvgrAMOuuwKBi5THKussNYa6yyl0GeZS1gxs9yfMaftnffqCBgz8pssu/mGhh3LYQxzWMMeznBt4G+9'
                . 'Dj2e6NQ75ftElVeTx6m5XtqYUxoXE8aufJgvLq0pyOJ+moqiOMKG4Pxrn9DZevazGz/koa3tU0njfowGiAXnHTYEX9tf14hs'
                . 'aH09C95PBNvCh4Ng2w0Nr8uZejrDTwr4TAFggD0ANgAABWwYYM8AAMCmlbURB+zsHmSralE/QvS3sOBewPufShhRZCvyFBGY'
                . 'Hjyj5trJqJp6eLWiSpGlUJ0NXscw0O9dlX4x4OCw7G/Dsod2nE/8v8+imtdNLMvmlD6trtr/fNvr0tygwFV3cvXHipKs7Aqh'
                . 'lGb5x6oPHkHXZ4Cw23B3e9IJxA23gX9A25LnTwBrzTeAqQHY/HXVnzYOb+KFFdz1hcSiBX+6OHfNP+7VC1f3KopGgbRM8DlR'
                . 'nPI3FDi/YsaRm+64z/+kJ5QrWXXWsWGRAef79E/s4rTz7EVwIrk85+tZmqzkrFCUJJ+u0Lb5pR/L39d/VpN5jZF7CgSsAwAA',
            'family' => 'Be Vietnam Pro',
            'style' => 'normal',
            'weight' => '600',
            'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
        ],
        'be-vietnam-pro-vietnamese-700.woff2' => [
            'b64' => 'd09GMgABAAAAADC0ABEAAAAAo8gAADBRAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGkAbj2IcKAZgAIMECGAJnAwRCAqB/mCB'
                . '6S4LgWoAATYCJAODRAQgBYRAB4h6DIE9GwSXNXjToQW6gweXYnkhYBQCGwdhZMYDRqQaBYL//4zkZAwHFmxqVdbP5FRSlR1p'
                . 'R2YSkwxZmCGre/SKlLogy/M4JyhKMaGjUSjBpboKMgrUmPRQ3kR+lnGlRi3wEJvuWyf25rfhYu+58bHgHuAvxATa29t0q6VE'
                . 'b5GrwqSDXSEi0fzIb40VhcWrWXv48+Nh3s05GMcOQk3dn+e3+ee+96gQjA2Ye4ABioCiiIFTyqhAFDsWuIof5SLS/epl+Ctd'
                . 'RONbqb4PWCyAXVKSiyja4tdvrSqt3Dhk4pGUY+buYUolSoZr9igKRZcog8NoHKoIia1GIhTxSFN1+Xq1prcJ35bAVVjg8RXy'
                . 'A3jJTB27kHp7L0yBQAWAH5woVHGge7fHAYxhxANxilnJO1Pqz9tmfVMzQg0CxCCYBHzK7LDf5HA7WF+P8vyfrRJXSFxIUwjv'
                . 'cLh/wtNr2kLsIYswYsh+u8QsJ8g+kzRECS9XU15Rb1d9rQmGh1V6t3WZkZ4B7H8u2/+5pZSemZAVmyfUlfYorexeGQ9CIUtT'
                . 'lkEoJNYBj9/yfzdTk0zbpbR50CVG4qrkjFD47w6xGST2v5QlxJDIi2ib67B1WAlBtLade5WMSUhaCYkSaQBA2VmWzXbPJNtn'
                . 'CEvSb0mmj/T4OoymwiM8RMD0+zNtbuGB0XdLQvz06FdrvbZf9+yh6v/D7ioqFRXhSEaaqZmjuf7EGyBaFSIfo7E8IAiTivb5'
                . 'P1Wtdj5AUNJG6aLWmzvtRXnfXi9R2tTF6t5VBTgYYkCQhCRA8jFZDrQcNygkZ4KYAUWRpmkuxTvruCEHmY7aCzkUdUixjU11'
                . '5ZVX1rGou4PnY8zVyX8ziYtiLQ5tJI62o2/+UfuNJcySePIq3jIpckRKaUDv9WEnYfWzK54VEVtWnaP3WKll6m1xxCCdn2dP'
                . '35UvBxfotM0F7DGOObin/rUP/pe57Mcor4Xb3Aj5UesRdhZmdo1jAcFwvT/WDwaHmCAWTkiLLnhWAbxzNmypnSCh1J8jAEwK'
                . 'WbDZKy0PuKt3dytwEQDA7ab/0xyx/HS2guwrN4FkvTstA54AsEG8U+D+RGuQDfRiouI7mLNIkf1/kDhsVCoBSCKeuJBNZoE8'
                . 'wF4SX/UBMVsfkLqGARSvPqYNKGOA0gUoHQBKG4ACgPg6qbMToBISeM8ihGvN37P1QJwPB/G+CB1qHlkrWHMGbPXNshUsa0YK'
                . 'LHncDDGFAVXIpYDVIKlMhNfwvDeXoBMY/son3vNGjG6X8E5/V/21eMFTHvE7v5iLB93jNje4yoKyD/Eab+9e5BxnzN60387U'
                . 'DOruX2WJeWaYpMnYl0f1alMQ8Eu+mVMnKZCNU3Ggir1oPOqdXvMu/6c/wc84l06sBg8p+wav8VnxQHd1S9d1RQxu7+Mdy7Cg'
                . 'ONLh9ra9jXOxthUtak7TLCiz4TXiD49ueNUNRxWVJQnwfj9+mRiB3uRZHuRWruTf/J5x0pkZx9IPmeJX+ST7siOboDVbQAm+'
                . 'IWPR3pnF+Q3MXccNyl9xTEFg6qwpQ09dGHfVRClKVQLq0GeOBKzZEVWfzqxYE7XG9r5Z16ZslSbb2iRN0mQZWU1yi+Z6tZdc'
                . 'WFyAnSSZk8d9dNv1I6C7uhxU8MU+0tOMZ5ipVWIliY31PMqNIINAsiWVC8cLz3bTPIS67/Nvvikq/r0GcLXBH/taJ60CfQGj'
                . 'x5o2dEK7PGQDrjMmm4sccxGz+rHX+waAai9pC2D9BWvButY22iRrpEBGZVxGZTTfNJVjRLfvR16zpQp0MgaESu8s8YGHwmKJ'
                . 'lZN5D8Q+S90sWXnK+vfjcw5dXBheIaGSGq9nPNOVzSvLLCzxNTHkrtUF3un7AjV3yaqusK8PExdcOcMeaIG9JjQgUyzQJ603'
                . 'ggD47fWF1Ix8bQWPpOggsMTA26rxGsQe+wRjER1WxPxniYUAaBsbPEwAVDgAACoA/P/nHsH98l7c63sGAAD3mpsAavDXaOOD'
                . 'Hoy7/6aZp5O+4Wo+ouHJjXgEk+5rXI+dsa9C+nDM3Lo144z+tbn/nfWSRPiZqpWCjLMYZogsfKlWjLSNdJJUk+UxCOIE8+/y'
                . 'EDyIYSQUcEqhqMJEoNNLhaTJQJOpFFKmFoWDEwNGcCZMA8fHf5iHSSgmEyMggb0sCE0wLY5QoWjwpzOYmXFZhICK/I8tUIqt'
                . 'TDm6CnYstWrRxQaVwc4BkPjDETMkA5sb/fwAAZkA0N7sQV5NlL1fQQHYsUUApL7fHM0JSxwvXRKQOYR1h//T+U/BOjiTr9f+'
                . 'XyCeYQCgHgCAdXtp6iC1Ap48G0OtEmlf0Q+JQOD8XIJ1fI9w5BFlGrI++0Q4gTNw+fM526nwGaOfgSF+FHFmXfYKeJHoIOuK'
                . 'pnml/Ofu5KX0YtXr1tctTdssgH++J0RJPREJXyQZv+6I7PmOA+D0+MzEMoSqJVcgWMXh4/+yuBLwmDDEYonDNg1HPCYjQiSq'
                . 'KDTR6GJQGHhL5iPFJKk8WAjZeErkJYmAlUi6ydJI5ZHJJ5HJV7apcpByTZHFT6EAxQKVUCjlr4hSmSDlVCqpVdOooWUXoopO'
                . 'nTAO4epFcCqDywUNY98dFntXALiNdeeeSMckzarTGUZwTv7DHepvt6L7X1TDnFFt4Ya/EAhwGocm8myBwcNn6PGHyO05FJdp'
                . 'R9r8C9jnvy50sc/0m/MeuATUQQcVcwoVCJDpTgUG9eorcEgzD+P7lkKfXUpKofB4P5QzOTPMN7sC24GxnxvkYLdjY5gCJ9pH'
                . 'fO/IbjyxbDoivREaul0VeuopcBC/WzdTjDMiRuABL0aWo8g7MPB9ddII302Q+EN3XjkczoboavTg3qYf49JI1xZZsg5eBeMP'
                . 'E0PHpYZLk0JW5YbzFOiUfYJW11Kg74bJjrSLsh5iBtMEkZo/cCPokT6azFYWAfOwOQNHEWy3QT/WHAlHR/tu58WWzEa+iUWU'
                . 'GrMmGxTMDEH2CWRGDVZBgQwFwudGoHESMRdwF5z3BqUVVNxlRmFjsUaQQVjvsNMDv8l0xnQ4Iyb+40Npydd9Goq2wx8i3o5p'
                . '4vLUopo3If44QsHZJyDT/ipogMFeB8bdiD6c4YIKcC6V4cYO1IxiyRZnQJTegsvZQqRGgFbzPLWhVhoUh2KiYwyKeRjO+wBC'
                . 'LHtAhdlgbxoFBYj4JMP2VCYN6ibMOeYKjs8Rj9w4gtc84FUIh00fp8MAxwpxsWZv/XzUL/jqJHqn3s77Ch4KZlb52b+qgo9N'
                . 'E4jz+JSRvyMvxo2tTjgCvgx9Hhgo6HzSZUGIvT2Vsc0kbZXFwwWtEODZm1ikNbCTcNq6WyHEs+347PW3fc5vorCtJwqM9t9q'
                . 'O80WUBbYDqbbRvahTC6wNQ2aVAH3p15wHI340P5v+a/aSryuq11hNw6x3yq34caEY7bAP83gNBZXPmeBeHUAEZQ7pHr9uHlh'
                . 'qWBW30R2XJAhYxcYOfA0SPqzN70MHkt7lkIIlpAhz3eGsjLtDmZxmDthFd4oMNGrQNFaBqTznBAvyOAlmbwii9eEeUOEt0R5'
                . 't0H4gqF+eCCaPnsTVWrQbdb4fKVqUHnmHlYOKZ91j3eNxBIrmLsNyn6dAznLTEhhIYOFHBZ+sPCHRQAsAmGhgIUSFkGw9Sk1'
                . '5G7gIs3IxF4qfe2H+q7V6NKvxu40LdAENImeHgqRsbNXV2u+qTM8+0jdWQGFkdPqMu5cjbx6hCaFohPDeNGMDelw4hibCodc'
                . 'M2B5BJZInlXRCSio5Ni/KEz+BAGA6X29WcLVy+QHRQ+5K2MzooBJzk9RJOoDdysMU1ZBnK1Nj0LBxPuU1BoHLmvWP2n/X3dm'
                . 'Y5b4zS2+013o7iua2HueRkYDmMTn7hNfKsOjpZT+sxbsfW+vOpG7rQF8xPRLDbsWqgtUcaFqaVk0/jDOxa6Tbk7E6yILC+4m'
                . 'JqWVU/bqaC+rTFs9LXpkc1qAYGoUCnOzrxJmN9LTIyNtLKuE9hXrKN90CpOjjctiUtiVJqnCurRq1Bs75fYpPhPB40gsjeCD'
                . 'eBNYUNNEanYbsTYJynSAEpOo3r35ToPRgjlOGwHmjr1pxI1RtCIWlyftTmItd9U7el4hUFwSQ/0w58kRhjjTMeoDXtNd6BvT'
                . 'ntgL6k1PO52MuquujsZD/diasj18zThxU4ty1+qiVc+eq3PChadukt8EwUx5p80gOnAebgExoSUPHGsVtvenjIDKGm0QWS2Y'
                . 'jNNw7IFoptwJBviu6eqQ1HpTzEgbCjPyAVwP7X+IcTRj0/Byduu7etP5uBnwpi5KHIR8zzrBpKF8DHity+hNLCA5PJH+ZodM'
                . '1/bMG/VJd1LWp6KAdvuI5FVpIKpG6QeDyaFKLaP2VGY1MwOB3cdM1hvBEMVcacEZiRoCkpFSigU0o7SGQMYOzzEFTASFoLKO'
                . 'QCWo7qMgaAS1dQQ60etFY8ZDKGisITAxmpViiYXRWkNgY7arh6kcoaSzjsAl7CaB4BH01hH4xEE521Cg6Ehr7xwlPrsOJPQ6'
                . '0Y8FTNu7UNBY6s6Q9GSIeqehBI4+V0K/K9kAiQ8MuhKGmo8KwxlK0+9yGTNcGTNdGbNcGa4WoZEM0ewMpTlQrmCuq2Ceq2C+'
                . 'q2BBi9DCDNGiDG1djB/WAffL0bmOMLnVqN55s9CD3iYsT3ZX7QdUrkLQ/xn3Uuv2xGsANAWEF0D0O5kGH1gWs5ijrD/WZIA+'
                . 'jGqTlXQXMxpwHnLBws7uDsXlKd5b8hK642gZtVRIcNlCS62zDkr06EMmGBgYsnZNpZr6uK+ikXerjNGWFIMYiQBs8KzzPSgJ'
                . 'Bvj7QXHMoUpn3SyP4DDVREFNZNbCLDy0RKs22iUcyc8634NCiEaYbn1gYJg0OxU8PH2b7TrfgyIU+NdBDWSmMiIXHBy8xd2b'
                . '3ZeWxRcZkLQnaTOeNu/Y93a79thjHn7jg4YYNoIEAmJ/oo/aRwQPumN2B4Z/fUSuZi5PAa+TkzhKybOz3u0cn6fkMOUuOYfz'
                . 'A/7wmngGxCxKaTKkYna0CeB/lEE8m5//vYH4SvnzMcniXxclhZjbmkkwMZ54/QJcNWrZbwXxlxeUAD9ySkBAO9o0Wz9H6KVP'
                . 'Jv2MwEhGYzqe0mmc0jtMKQDlYedsYzNM3+W+3hxy0UyUV1IPJfRVaBqIqkppQiwuNY6mEfJVudq/q4AErnZqzqudjudqp8mD'
                . 'MnEiws81g7CaeryE+D6uFAFeikA5GuFqoYFXhh70iWH3h8ZHvcSXRYpNye9yCj8Ldt3cguNxIj9UUYM6s7vFTc7a7BYD6L+i'
                . 'gu3+JGVB5ZnIL4TflfwC+e98gPx3dy2wEvR96WnoUG7yWbCQrZ1EJyYZGRM14j1KB6kOch7IB5JHcADjJH4NnAEYEc4+NZ70'
                . '+EfhjS4P7hDd1AHsiyQdzfagI0ftcrVwe7mqChfC1Xt0fp2TBUlJz2Lu0eXum5Jp7MRtvBUgWntJ7wZMoZ2jhkvMsqtMEbzH'
                . 'FJcYs4dJdoWw+Se7AmaENqiiLNMajM5t1GG3mAGYXd09VOzeZUzdVc51HNh1csd59k0TX/q6vD6UkFAWEfuECL2lezvXt0pu'
                . 'TdgLBaXkWyYN/+7JbrwdhKvWY5Py+PzsEhP2h3uAbA/3wIob1J3NEEgVwYsGkw4usIkyPvBqQ729PrHn3cF2m4G3Wyd47QGk'
                . 'VKSS38sca0Vgw/O4goZ7OjvabkCxBgo/TDGCOvIHBz9H9dBVwVoaHbwP4qdxhub3U1G8KjHHmzvMnDwVOqtdATJshySUTiX/'
                . 'QD+r1vyXbS6SRCnnCGP/3n5kU8ZRjzCM5YayqxpiHuCgKwYzx5kJ+8KTfQbUgusfAa6HHG8u+veSplM+nGcwOF94bMFse3Ca'
                . 'yCih46QeRybuUX/ZRgVd43NNK7JW4Ho5Yl6A8bTLC5KjdmlvpAtrIdvKHKjCjWcwmgyyOX/lwj/xYaJdvrO36rdlmrdLvE85'
                . 'wlXijSHtkPVS8r3/x8hZk0Jzsffpt00/SRgYaZla/qYItUTcIPAU4oZ/73Fq3pSbp07eGEiXShFvnICs2ub26cUs5PfhmuVn'
                . 'pNLTctlpqfRMiefYRMvBpVlj0V9FWqamvymCnVVLBNgb0y2+3/tTWLlX7q9Szuafrybn8zgygQB0WKF4jj0k1hYXLP/XlCAJ'
                . '3sf2rf9EMvZAqv51hMv/WpQt5Zq/VkpqfhnhSAzdu41n3t15wsjDHAoUbm2HrJaKwmctHygfd581z5bn8WOA9ZJsc8BAWTUr'
                . 'wEVGvL7RC6VdW1vGlmUe+MFFe70rpfaP7NPMnNMlWFFY6a526Xy/N/ZTyexL4pqjiLTkSHlnEnf+4uKII3NK1F9L4WRYxYd9'
                . 'dfnOYr3sMilny09XU/J4HLlAgGR8vEF+/4aaJJMeymAOv6AuPHc4N9yndH5cSYNqttVq5VYe/dyUcnSLd/YmV5pDmqKjQxor'
                . 'Lcm9uxdHysfsj1jf4aU381pb9C79/oZ5FfQJ7BErfUzp5CcqD1b4CnFnoruadvjiFkesdBZXw5HJcrQz6VHReJLaV2GaALcx'
                . 'EqRD3J8PJucaxDW/hD1yiZGv72M30Ox3fdKlFZfSnkS/kUq/5npBJXXj8z2E959vDzGuTAiT0Hz+aN0GzsNeXHP0XQ7/tAg+'
                . 'J8jYGFtogMV6FBP72yCzZeJw1/J+l/ZyOizRFV22OL6iWT0/MVE9r6Jp1KasfHF8eZN/3DK/vPlZFtkqrZpWo1HT0kZttgqE'
                . 'Fq0Vhgq5Qq31McNzx15q7dP8232h3Ly/eWxp1oEfXVyutUquWx9Cgx7Ce0gq5NamUMnudqucOxtv3Ge+9jvupvG2G63YX/NH'
                . 'VjPQvvFGH50quq4R7KKeY575qv4wsfctjtBHjyQZXlue4KpcoG+bxinCkDbIfBlTeIvl7k2RKmea/VLp3rqQlfSaxHMN8Fch'
                . 'y/tigl+TWJeKV60bfOWzwzVJnTA93uq93YzsBcYcl/PIiiPxiQWpqbaC+CMrjzgn115oLLy1cF5UuOdsupPAJiNTZRtVgHkH'
                . 'hNVrUigSh9PChyMp/YMTWrE8dG7iUubGW1S7HM06J9WQ5fGe5gCY48OehRzahQmzm3CcC2zetyQ6N7bU/PKOMs9cRVqXidUl'
                . 'Xi6+CEIW60+bK3qNX9m+Nclo29uB16lrkyD/qnRCnar54NLMMfp7IYNvKEI4IV2tj1MZ96KNSemm9op1kZLsXzfoDMfBvdjy'
                . 'yEmfxZHtPINFZnpoQi3Kf+nEl35OCbUIHuZYZLUGXQLpr6dbRgWjFrreP4HUxRSoozIk1TqdpLp9CwXqukFls8jq7y+yNivb'
                . 'm/TfpFyPnXg2ePyTuZHcpQEfxeVdchMedo89/e6wa3FzMQQ49diffdE3yzxkJz8Osu+p+Uef4zvZ3TCf5TDKstxZYwPlI7rP'
                . 'Vm+t73Py/JT/xnfGWYC/edxJ/x7aQe+GT3nd9MVIJzhoZ8XO1/BqLEa39g/JzN179Yt1ouZ7KjGGh2PgDtQ+UjtSMFqQU5sT'
                . 'B5uXCmNeUIUjukPEX2Rquc2hTsggq8PDplbFZ9rV1pS2adOGi/2bNLFW/dSaotmwBN7/b82Xf2z7zfuwSnVs/8xILG4Km+lN'
                . 'dfIzpPSMXHZ9FeSZ/RfGWw4uyxwLQpdZ32g3fv6Q3e0BWOQMPvNqJ5mDr6njVzB0thQ59XbNrfnOwNLM8Ii5xp+vHMVnN4bh'
                . 'TXM7TzMG+90Nq2pAMZ/E1nmOj4zjeKebqn8Fw81r2d3SZuhPJefD4kCB3F8QGCjzl8s6iwWjgtTPz9odTZlVX39msMaGfx23'
                . 'vm8dzuvuihxvi/LefJuZVl48lBY/nJyc5HZmcdeCRUOKAYlFoZSYBxQaNSsV1xYD7yzKdY31uyJcTi6yKA10zgOJd/4eXzcH'
                . 'fnT96HNXoTd0/q9Dzj1WZcSBTU0955LfWTeajAvENcfe4fBPibNlPNPuIHHHLyNBfS3zHs3XshcxkcM1U5XEsNx1jVOySLNP'
                . 'xwoZc7uEuJstQRJcwmOW+QodfGagInEwTXv8kENRH0Xd1DDlAlPMDckqV8nhfk+7CAQpYKDpYD6jaNjhclma7MyHZL+w5LBV'
                . 'bAegecAzmVoPP42u7UpXbVkx83f1D5dDZPrMxeGdFqX4cU2LFKKco50cbme1q9puvyW38SqjT9LIs3TOj1IClgVI2zwkoaOC'
                . '0VQq1xZB/xyqvRfL/HlTWUcxXOnpv0UdPSPQbFBEw6URg5HE4cH9ndV+35vbJWLoPCl44M/zDc7B9ehf9NCrcxxyMJjDF691'
                . 'hmYuqBpvdTp+hM1xrO1Rqxv7aviMxeq/cbdc+4P4IQyy3qf9ve5H79HBur665V+/CiclVTao/XXoLKl+9G6ukS+cSjkHh/sQ'
                . 'VSNPLmSybKR7FXP6DVmNwveUAB/nk28WlnKiFeU6naIsJ8aoTF7pRyuuy6hTj6gTMhL2jYTT5LsMqF5zx9//jkb99hP98v4C'
                . 'S/x0bkbAPZncnfDnyWVA7+rXdWjdHzAz4ColKWvZE2zJmF9iKwnowuA4lXGP0uoVtFxgf7xe/ldhmkUB/cJhEV4D8P1tz5io'
                . 'wgEYhWH3TF3Tfp+qIirTkgMsghUkElXWWJgaVXAIlR8pH5VSSLE9u6hwS02fFiZhoWVETH9hRpCADRDNrms8TY2tOMYqjjch'
                . 'ACeYTvV/f/39vcBTtogabDLN+nRNEFVaZgFCSINEgrXVG/HfDt4gvGZfk2VqfMNmFY2HvApnnpZjOTZnlODHTVVykiYeZvBE'
                . 'e316SOqrjdWglNOBxBJvfou2D8LFwMlEJrnQGt7PI6GR4IQCSLrqHYUKgPbzAZFwN6r760txWv78xX9P7IMQoKchRIR8WEVF'
                . 'LIcYIFq15iT5IGTDkq3Ma6hTc3acUiVj4wDmdRCH+uN9MFFvCJ4x+GzGZnx+DH3X5mkUCBvXpJ49Zg/HjuBm119PLSsP3j46'
                . 'DBoc08G1iL6yssHDhGEJvwAvTNl1w0fZxuUa+dTcgiuKKo+NS4goIcqACShgYBqExTDXRdUn+GC+LA0E4OEge9l3bVNXZRx6'
                . 'ghFUGMX9dlXPJbkK9sB2Ho8HYQRUQCIvpdgo4QALpgXnx62xkmxN5KlxNatWabROZLo4zPN8BpU2KiVLTpjmI83ftqsdYRtQ'
                . '4CUg/CVmCvcXsIjJ4pru5MkPuHFqXMkiVRjPPRsCwtoFXzFUaGzjUVnk5FD0noX5pWxDgZRW/ujBO4lFCBAsQxMOFCn8Ctj4'
                . 'tEsv8fNuLFHOIQ51/KCpc1mgTblpDGWoB5rYE8ZwFbNxPEMPJIbHcuCwR+X6UXI06VDfsSFpkdZUnZrYsnvFY6cDkuqnASZ6'
                . 'MFZMw3hGPhGxtIvCwPdc7OBUDMQyKAea2B2SXsSaOKB/Cm0oGFqH5WwJZoeBjcaCDnsUPir2xrYzuniXc6U+F5kh0+IaTbbb'
                . 'sx/M6gwhABcC5pt2niscmyOKZ6gMwSzMZmuvAGNTKWo8WAkk6hC9AzzwsC7rFQF21sufOYnrIAchbsLsTLF2nukHN+YXHH66'
                . '+MGIYzYmXCywWWkOEQ5b1SgkIcRVKVG4PY2FyKFV12lAI9i8X/XS2TVbn/WYOn0YAZTDXcyows2cOYpNkce05bazOI7w3mJm'
                . 'tNWTKZ2Fk/AJUypLEw2US3W1pVihzeexUNXbLg7NF96c3jaiX8mNbOYkIv0OZ/1VRSihva0OXeIYDPGMeTe2WVQhDjXOFpLi'
                . 'FAuHOzT9VanBMsssv6Xhe9Ztckt5BdXa8i3BtLJdyrIqY+M80bPGEG9bJON7Y7mpIUuTu1Lii0qMcYrmvdJdmkSHkseWK+Sp'
                . 'zdQfJHhj3N6kjas2Nh7zvosLFhdrvI2RGSBxPGYaMd1p+Q72IBAShxVlpKYHHEF7GQueYquScLHRA171uKkbaBT3y5o9Nd2z'
                . 'UIcwm6czNgrpmBWRpc3piUBNbXPg8DZcZBA/KPttUAmwmWn+giNxVE7ZL1LZhiT31FxVm7JMkBftcJzwOUxzA3Q+IBn0aLN8'
                . 'XyDlHNAX2jYJiJk48EJr2VR1cg5wJpM4yR9IBGcp6rNghAYos6o3xjw8cdCHYQnD2DVsO2mSY1MtxpEUL9xsSIlqp5MxJOFS'
                . '3kJ4CDrLdQkWGmmHsoqqXX4b5sYxi52LsYnjk3bUg55/04xlj9vhXM6NcOx4hQZZkJ3M7YAs0mXMuCXGsEAIjR/fs5a5Pd+J'
                . 'ShG3SStsM7KiC9zsed/4PHnLgosqMVR1kfe0eTdWaOeiQ42XWWJyWaClhc/qYyA16Mg76Cq5d3mRuYlSsm4waygb6AzrPhBe'
                . 'WBPt3m2/ZqNh69f2opgXndsRn4q3CxPlzi6NVm3PXd733FwkF6lbUmqWlEJWcyoFTScw2G54KU3Ua95+wz551j/WJ9k0q3i1'
                . 'j/pSdiArSZQDbqSOI3OjMKNzAKTZn3jsfgNwKRS5m2Pz5tydg2AXLIRmqS4VIoo5JTgoNHj13FghfBAj0oSxsZj1LlDipfOn'
                . 'UJEEz+j0cqpgKoELUuyjFuz4QSRaciX89TlrqAvMOV5ERvlc4KzACUlUeC56whFw5BHTIkwofMq9hoAy9lAf3xq6JIS5MVS5'
                . 'U8yNG1+MVbj45YVd3G8XqFQ5Hq8zuP9QyVnLY5VvTOlyiYyHNgv59n7eLtM+6+UTsnd3GkQUC3TW2a4i28HKAq2SbUI+pSQR'
                . 'GoKhQjaru6OxzSzqVp670TwITWTVqwMA9uTz9O7Hm5yMOqvuqpCPw8DDAxm8qZ/y6pRMtm2qYAIyfFh7TCq3mGadMkvhlpw1'
                . 'rtp85vVgPrVpYUo81aCAQ4CqvFP1s3Mw2WwNBODZZ/tzc5ZJ+66wUWM0CSRMUFmsst6aaGpwws0+G3Sjd6q0ImlsGrg7aYuy'
                . 'V7WnjDfDNQnbWg8195qLA8+xM0XYP5E8JGKQuJFa1k3143qBDY5CgYxmOuAFCaW+TX6UwxW9yNrb7L9AvmXuz6kVdkZfoHo0'
                . 'Sxc8g07J+3HaxgEJFBDU6Ee849VrJUBL4x2UYA0VcORRjy7eAe+FULHhSIMcqsjnrgO1ir4gb6pVRZ46gAa3bV8n5htcsHqi'
                . '0FxsjNleMT2eG7ptzJEDKJs5dKrwfg4i7a7nSRz6wqZhbCdEQjHuwZPTWYsNY8S4h0LOQC2jrkkUPi/NRSRGAmXsVKICBQ7k'
                . 'Kh5bgKlwIPy3by/myg1vANdZproExZ/NN9Ps7apwHIMwLYFdiEH/I7Ac/El1Uw7YupQ5mY4OoClvcSSLdMUvxXxV087qHKA5'
                . 'jYz4EmSnTxwMWKeaa3od+wkQ2E15s62xYFMhRxc5SqCtFNUBlvunwEJ3I8vcMnFQMVU1bnopeTKQUO5t0OqyG2Q5iSnxcjd6'
                . 'Yvi8LZWC4B5jRUZEZiv4gXPHIguDNu2rYOQY5L5K14LItdTHrP4QRlhuPcaZUXJNS87VdufzwmhFIh2ofmyEXUsvUOGgnlEv'
                . 'dKkDqp5FLpRGwSjMKLnZPR1LlshMA9SvA6suDKZ5b+JVL7iQFvu1oGqtPjAPfxgjLU89hl2+rotf6jxcsAgWizcabK3C0//m'
                . 'p8Y0l9kuXseqce34B4W/n1HJ07eBWZSLfNB/YbG/uzXka7HTfhfF7gyplRGJCwN3QTI7Ho2AB/H9fLQdb8fBOTJdWwb4oEXO'
                . 'qDzObo152wi0Ep3dmdBrISEHNB4cPBGOwUyFQKlIOTRV75b6YPFnqCOlUUgUIkPZN+u03KxccRFFxy0yMzcppXc2O/aFk2Z5'
                . '7pvHjayhRVjgDUFbzdO6CInLK76Ti55pTe5qY7nWZrcog2tMxwiQtLhutFNswtxh34gIfc8ft/3P4Wc2GfTHZ/N3YxcVQhe1'
                . 'L5Z6YMuPsEyURqGgkNNVBMZWkEi8FYX+rs3MS9o4d211mhcG/yr74TkkumQXJ1hDMK3mZz0jJL694jl51PNGU/ugwJPOQZf7'
                . 'R5gDXkNazkfhw8eDkqpu1pUQ1xbfnNavzWs0DNDdKPl7ZRXmgiURfpPYHGoiKVZIOSz3C6LS9dLJiY7NC0uwtUn1HOOIIdsH'
                . 's6LuBFlIi30IstQoVkf8YcOcD2bDyoLRf8/FBReLGXFxzCJXuqjcykSO7LWHR3l4ldBZFcSCBREKsbCN0T/oCx6hMOB4LiXR'
                . 'dPPHAAFUrjxlaAqhn7rRM6sIAmVhHwJPNYrVEX+YWIoRzMuceoyBovCwYxG8vclywdmWgGZTPOUz9FnOc+3uyF9jx/jiAAMl'
                . 'bSr4pM4k7J3CEBg8YpcDaHnKt5wIILvVVqaBKHY5sKZhDYDw4eClFU5No7JAaffUoYnamdhft8V5zHYVpQT1BdFtCgcEjahE'
                . 'kdAQ2pCE+nYtF9mKpyY2m/vJ1LmjnCocfrwhoNY8rYX0tpHqJDMbBUkq7sHs8q0KrkI4fDzxYCQzg0+v4xbzaX872NYqwZzr'
                . 'E6M7EqSqw4H9j1ucxpouYh6D+oKQKejdcMztalJLyQnU4/0OeUgdpc4XJ2lh8M2i60DVOpxYQyCv+Vk3IX3T6MzfLDu2N6VL'
                . 'SY9hz3Zwra4yXgJRfLe4b5LlzuBJ3eIltpedZakQkroRrOc9mbEwSJfKSZCFo9IoEAXD8A0Sh+AmUjMg5ZLcxY2vHDbStNfi'
                . 'SO05xPR+p2ng/9cheJttZsLyZ+h6FtZRbTMUGAE8i5tuA+HoPQfHTucKKcXBMm8HO3TA87bY2a/xxA7HblWTzVUeWAVM0Jst'
                . 'wTNHnZnPxtKGcEMagk7q5S81UqIjCK42IlLhOZJ2DEpMgVd4sjQVL3ibhXHc41TLe9VqKijbjqyxhLWfRWxM6S2u35O1gC9t'
                . 'SDM58OCuNQ2k/8TOrQ+C4+BaaBbGEQCypM+HnO6lq1ty82GCMMumS6xpmjOgvI0R0jFw3vDyc8ayDoZ3Frj2F0fCptg00l4/'
                . '5ccASb1WRGcu0mWO185hlTWaQhsSq8f+mVeIFkC0SAJ1MJAy3WUIdawFWBAmTs4o2x2MF9ItweH+JuPQ9xgxkfa4wM2LX/ZC'
                . 'xkDZsr4QIKMdZIEv2AUtTdJfxgANg9vVMhwpet47Z154jOmJAbtSoLSrenswTtoaU2fCQo586lhORR5uRi2plWlpKDF9xc4I'
                . 'mDxwNTTGTnv+HaCXw702lqZSdRvOyNguIvpYW8rXb9Lv9m1d5an4IT4GewTQXLo1Gsoo3+drZTEqlaa0Nf6jGONipeOsf5kC'
                . 'AQMInokNZMZhnrLwF0O1LiQgjbvSZDxO0SEV4VMbG3cMk/M0zsfAKjlcPGiv5Rtz2O7qssjjkH3j3+DdmzbgIbCEgYBT5BX0'
                . 'KLq6Il96IzpGCi+aTAC4EnvSl8JmFGkBtKk54Sg457iN1Pe2e2ExdZtgbRrVpM3yGDC7B6Xr8QWam5oFJBksVY1Kr++PbS3l'
                . 'vpPAw0/yVHwkFD3eNXlOQ+BZsAMO7oWvOBI6H5a1XakGdDtMsYGsZzyUFLO6gwjkZ3OO/TsKbmEQh+xCekcnlcMRnDniwopH'
                . 'wuOrh74sApEPQNioM7oIH3knhhYfTxXAXj9iiyVrozLzRm9UQAqdn7FpY6N7Ow5KbngshPoOTdU8TspN6qaIQooQOleWzDlQ'
                . 'nwP1TCM5hvalst2keTLk8o3cLU2OsfUYZXUvt6ah5tnb7Z08hZrozWOoan6NWhVczslxZt14UJHNO5I9cx+3y2Ff9mC3Z44v'
                . 'MDQ1R7Ffjsmi5ueoVd5sNg8L0+iD0ZSva3e/QAqJXwYlI5qne/BMM15/yFNI5MkkKJGXGhFvpMiMgb/iTwLytfAL6eIyQMlc'
                . 'SH2j2QpSt2/WqUMjd5HCQw0w1UJwnZv7qUS+Fn45pl6TZBziaeEqXtsWVbNTEhFRxsdst2f/qOu4F22ItvWS9xNIKnsd+O4y'
                . 'JPsJTr4WwVc6plyMe9AVX/LL++ca+Fj8ymToKgRwzmUxj5EGqUp3hIlMPpyzC98L/z+CzopnNOp0zMZ0uPylCiQZ68kQhP/C'
                . 'E7VuglaHEAz71Wc48rb7Pkgk2RziL/TH1hY7QXUf+aeSDTXYkORdcpcCPOqmIQkoYQDgWIqMUxK9V9dGc1hAhYacmZFsSeUt'
                . 'S10e4wz3ddEcdHPAC8gMKl/tJOv4GMcqPOegJ+zsAvHE+tNS/qlgRNpLdTbVdvTNREBxJR2U9+NaGlRhJSifijKY65AMlTKO'
                . 'bV0lMp3VSDEmASU5meqymGhDO0utVRDkMkCprK2CnpLhCHXQGBnLsoc2Pk+gxjZUK0gQDOpFhBso1SzDfk5YQ7ivwGv0zQFf'
                . 'oK7G3tTg57UzaV/E2+C47hXTXLGWGqipMHzFwm7jWHK3C4mngNrs1ODgKxCnZYrdnjeCWT3s3EDS1OuMXKdeSANtuDPhVQf7'
                . 'nKYW2SxvDtS4HVYtTQOAvIXye3WUTAhuSFM+7nDiPtg1pG32/wpYlrtFQXWJGdKXzpjRUnoVsIDeVvFsGlXSU/VWOpPkvKUG'
                . '4Kil2br9tRRKVte6GVjLyZcUgp5SYFUz3K7syYhYp3ptGYy9KrMzJqlV+9m94CvoSNQjB/wVFUpO1wsrUsGBXFTE6soLWRJN'
                . 's6PQ0ZGBqmwW0Pw6vjF07XZVD6ceAxdewjhBTBR4GPEsuTSZjae/0lOXc68Nr0ag5Ylm9Tm0qQCT1KS7cDHDpLWsOPMopWv3'
                . 'ayZe9FR8EOxZReWNqlfmNpF5m0KS1FR0XmZQMZgSzyjCs5G9iR6T5vaqc2vEbF7nytsaqbezVQ2O5LJASVN2yV1kmD0XGChU'
                . 'wQtsmYp0EZ3f+2I0MQbks4P6MYxWfqSru8hxYURbXaP9Rba0BkcyFhSWNJKf2iiOSmXCKGcyfZ03GVL1LEDVvo+sqgde9uT2'
                . 'CiV3Fj3DxMu9fRCWTT+w4lWImMLpczjvqMMieruS2phEOI1y/t7oi0AnSskFEgopqXDuvPN7X3CObUB+rhl0qdE07p2VKCtO'
                . 'RdjyHlCODhi0kwQjeSkKO+giciOHOvp9yhKIDQY4bxmmUEi+j9w09eyI0DsAYeOZZnMrVD1A8C4nmNB9Slm4Tn6rI4cg5uEe'
                . 'imNFaRJpvLGx0vdlBJKQA8SB6rkPBuwB8F6gUrLUcSMPgEe8oztfxJw5fRxpx9KsWi+JLL2Pfb5WFF5dGLjO5Ld3xgtywyuc'
                . 'TBae1UdLk7tMU7VvVPenibei7PI4OHDgyjgGd2DuOdVr4ApOyTuZxA6UaB4cVBpVAC9UvvKf8S+Kd6jBrWRkxSagR754TpzY'
                . 'SWKLEG7EkMpcaUnkJ3MwmY0ZBXApi7M08QSNWBT/W620ZJ5omLT7Z/3zD0xZ8V0COREROnuSAOIroaevbiTexZC2s/C6bUwC'
                . 'JPSDkYDCJtBfMMLAU+o2fhBAyCjeifCF2zWnPTrSOUhCMvO4Jr62Cb2lZAhfS5geNsIUhL3BdCC380IvgR4qLp07mROSjNJp'
                . '4U+M5oPt2AEsfCWhsgic6S57IW9QAKMdKPRMdkxLCHsIMlXoMBPoYvBfhAAgs/8qD+zK8djfLQ+/BgD+vDlIAoC/4vzJa37z'
                . 'fJ/sKIQKAwCAwO9A0bq6fK9PPwNyz1rzyAR7GN+7UvMR1jySEgnC5hAN0rtpLIq+1Q3edzzDrHc/ImIAu9BVYyJq4hySkt3t'
                . 'vovuptWvORdbMWawLKKfXgod8xFligC48kewDbEA0lHwCWSSE0IAurWhAQDABCm0FIAtDzYAAADQ1cGhJgMAvgj5XBaRhFDM'
                . 'y6sszuBQlhBgTZbCpihLJaNX7rkC4NRtCtt1iaYpnyt2nRom2p1Uff5kg2ZqbTrV08hkk8bEoUADh26tqrXI0qlNCJM2zWoV'
                . 'cOjkom3O70ih1LS0wsQgT6118eEeQajTHW/VbQq9DGqwpqM83XVkOUqWHvL8KRYzZNq3WsV8rs2nVz2ZmsnaDYhmuF5oRgo7'
                . '5d5QpDxOHlfl1Hy61sjBXg8nvP9hp2iHu+oLB8PqNVDu7FFDzU7JFho1Ffphx5Boj/d2mrqthLnXr3sVYf5t3QD8/MZ6ZXKF'
                . 'UqXWaHV6g9FkVi/X2/2h6cgwLUwo47YjXM8PwihO0iwvZFnVTdv1w8dz/Pz2NTHAl8lic7g8vkAoEkswnCApWiqTK5QqtUar'
                . '0xuMJrPFarOHf6fL7fH6/BjSz1U4Jp9k8W4L+Ea5p2HNACAhHoWBIVCAY0htNHbBC0/crGEAAAkJa4ESEzbqoNDdq6jjdzFr'
                . 'ASBAkMZxMQfdbJ93Y0slaswxY8TNOrCDGItXLrVCxJinRo0aeOzm13P+6ok+/wUsjeZR9vSJ0KBf8HAgGNoALByJBjb4iJ81'
                . 'HIBgoWEt0WLDRj+RWgwHEAmGwrHFftahwVg8EFrM4xsJRGM7+Pm7A9U/fFmyOd0dBtdHQOKuzcr2rVKLYTr/X2RZXwXR+3Vb'
                . 'Qa5CnRrdtibkXVcFYceQ2sbd61fSwh/fSFoshQv8TWMh/bRv+mUX9vmjoKv+E6KsQlDTtn8uRrI1wRbe+/GlrpqAFdHGwr31'
                . '//lXcymHwkpowEL1AYbg32afMC2ndcH2Bc+ffwRUK5xPLlz2o2BqrgSWw/7505JG/mU/nbwB33tr/lw2fiueG/hgskD/fxF9'
                . 'FHOMnsfWhlgGAAAA',
            'family' => 'Be Vietnam Pro',
            'style' => 'normal',
            'weight' => '700',
            'range' => 'U+0102-0103, U+0110-0111, U+0128-0129, U+0168-0169, U+01A0-01A1, U+01AF-01B0, U+0300-0301, U+0303-0304, U+0308-0309, U+0323, U+0329, U+1EA0-1EF9, U+20AB',
        ],
        'be-vietnam-pro-latin-700.woff2' => [
            'b64' => 'd09GMgABAAAAAFaIABEAAAAA3iAAAFYlAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGoEmG7MKHIUwBmAAhHoIYAmcDBEICoLD'
                . 'FIKeCguDeAABNgIkA4dsBCAFhEAHh1oMgT0bdMgl45iVwMYBoBn+t7Uogo2DkdmDl4wMBBsHIAO1lez/z0hQY8geXPegc7Wq'
                . 'RGoyuyo0LqommnOWVofueZ7n0lrVwu3UnktEtkV0JBKJl0uVP4/Z8TU6Rzrz4Xf+1idDO+NhrC+/SG4xyhjH9PUBIYiIAUjQ'
                . 'GwcwIybsLd+9Y/9YieW/4/Ilx/pWhgjz0DOwbeRPcvLyPLRfvzOzu++LafoJT66lQSiQyTQi2bJJyialrNwjWrOqZ2ZFgWWR'
                . 'wEKGBZbFFrdFAgQ2BovoYhZYJAJBPOpEjBAHIpcQEd9gMT4PhBgh4jz//dj/1j73fUxDIySGRMKTmDQvkfmJoQQ6Q/PQ8IQn'
                . 'l8QhCpbMud2DbvE4hQZXXEm8b9XaWH6A5vZ/IjUeldtY3W7ZxPJukYwca2qDEamEVGkjBsOoeFHmizK+mK/F//2+TvvXlq4D'
                . 'RQWkwCg/QLsBMqCOjKAflHzrK2oXZZ66aiSz/5Wq9d4P3Y0gyZRFUiRFpxQxdh4WTzA4AcIcNx1d2CAdL/uTm19vCGIaEjwQ'
                . '4t9nxbUr9rpG/157psrSLtFEZMlyHnzLdgWC5EF7u4yFqpbaeykoIalWyeYmzOwBGjBwroswY2j3ACgcgQDr2mo/h6QX6I0h'
                . 'qX7ZO/sEAkHSjd26UioTPZXP977E6odtfVUBPVIPxlZExtf95Emqm7R8vhgrR1FyheuK6Jjo4FyZ2NY+a9hYAUlyk/wkR0JO'
                . 'zahjKvMhBRoQQEEghCxXDBpoWZcT6+/Ev2KB//9f9su+XefHKtIlJBeijL75ymJMUUV6fSGr6iangixTWj57JMI2n5Sa5FBI'
                . '5CQ7a6waIfQI7efvTbVK32+AJEiNkdZK2vXnJK3l2ZSkuN6HZ2x00cd/H/3bAQS6SRFqUBQFUGYpB0IYLUU5NLrBApsYygxH'
                . 'RWo4xpmQRoZy46Qx/ox12j2raE12kbFB5LK5iy5NfWSz82vtLV93g/8Ywig7AUcROlbtbfc0O/9NT3aybzhJ2xvTPIQxQjFC'
                . 'CGPc/b7d/vibeQ85KjpvHYgSIQkJ2k5/5z9jrj86qr9HtxTUUksNZD2Qofn/6QoMV/jv8BiGD9GHGDCEkBwhzpwhrvwgUoGQ'
                . 'EOGQCDGQOApIlmxIriJIsWKIUjlkjrkwDzyEqKggAwYgI0YgY8bhPvsJQTBgERiHQcZ8AgpkHAJGiclgG+7CZwL37IuyALgI'
                . 'QP7BgQKDn/lYXADCz78DBOeazDzAB+paPI/g36Q9o9N2ZZio1CFMCCInh3hjo7IiQtLDx4XsHDLIeP2C9NQ4pG7oQDKGKxSt'
                . 'fEEFzXbQbATNBtCsAs1K0KwoYxUNYAICtN+HEJDU/79BoPOuWIhuCzo09fckCDZ1DFvJlOOJ5ETsESJAG3WYgei4f/f7ju/Y'
                . 'vtkXTTe9+2jv7LW9sD17bA9vx+5saAvrSsypK3bRNm718eDcVW4BkUVqd/EbtbINXel6r+var9WKlmzQgw8Xq0BfRVlAcd0d'
                . 'hv/u137saIc6WFUf9FavgHOcPNjdg23Pxe3ovKlruqytrW9VKxqKySODZIvtzHkjGlL/euKMLRb5nVeZ1HA7qlte2TgVBwxi'
                . 'P5ngPcOdX4H6PE9yLzdyKVx4JifSmf3ZDbaygVV0sqS75tRmfspSlJykJTHyTE94QxC+87vHMZciiRjh0wTRjnqYGIH+8ixK'
                . 'qs13UH4Qn8Kb8pzXPA1PhqHcg9Q2zUr88iuGslNjvi+1VVI54FO+Ts5Iqqa6nScDtXZK9mtpLYudUcqHU7+XBhRLbJVZHbZn'
                . 'oOfkpvAsWOTLImm9yfbjIPAcrEzNNDynOw2YHtTUjWUa/eF0cwrfzaH6PIavmwvAGqprU4CHoFqO4GuJdcscWmbL6odb+uF9'
                . 'ewWyfvAhs8r2tUdfyzSJ3OQhx9Ta1NrMKls1+GbXYQt9X/BfjBKvLBM+pzzzguRVFR4UWOTxNwnblfCMfx1slTOTm+HEmwsH'
                . 'CwfenMNCgULNF5hvhnwX/ZjsvN5VGuBrGTO7ZVpJr1aWVZLlAzvUEcsGXohqRUO+SB5IRAElGAnc4GhtvTMufvnh/pSKalar'
                . 't5+vNgE/mPdjy8FgL40tAaVv+vjM6iQ5TfImMzimVg9kagEFpO6nFZZVmO0Evg2cUZjCDMMTZacmWSvY14IqVap4O3UwWxvh'
                . '00XZqeWp5Wmdzuqp15RhNStzH0rVyNVqukGnVUNNG8PraQLpy0D2cpCXfCSVTDGKU4qndjNlKEvZntPHfmYuGnAeOAmcAA4B'
                . 'B8A+xBCSChRbitdR5ADPg7+G9nUIFFGsP/7yABUdtjAKEzb3BQJM3Lq2Z8EEaOAANECrH2QCZCyvQL6DfAV5ARAOFxiX9Muv'
                . '/Myf/MbXsrQtr9HxJtfSMWNyN1DIjmXnDxy/e1QgztryCMrH4sEHTiz8ttL/uPRJbgHGrQxGouNc8m9mJJeyH8fSQ9ngRlo2'
                . 'cRRcSw7nDLPb1IV5eX+nLpfvwvf57d/9xt4yrCMMu8N73vGC9BjWOS5W1hguuT9tjovELPfxuYYP7b3mpEKJqCap1Uw13ivh'
                . 'uCd7lLdnzVelPW8NKOzt158dpXTTVB1n8oaXo/86D3x02Foaj2tK4kys5NQlGaKzcuNB3xfhhrV7lK7T6Zjy9+llj3iLazjn'
                . 'PVQyUuv6Ja2WnUln5UTnC5Am98iFCkcYYpbQygouTqla06GqqOdhe6nVuRkr2y2Y49o9jdwTdO5WXuym91YIv505OnbmBTfm'
                . 'BqqhuLekgU7UNrS7lelMW/X0b/eJvMk6UGvCUNotTb8WWZXfaePMcalVmY+3TVGfWppw8RxQF9JAsmeRxSN8bs83Up+P9YQ0'
                . 'wgsTi+lZeTF4VJbXTHYvaqAhjV+7te9/ijpcOmdA8Kt5Wu6w0q+1AUU1J/LRO0qqqL8F7zWPvOA9HNuwbzcFoZunximEx5X/'
                . 'VJz8GaAOWS7QqmXdkWUdlB4+kvRDUz9PYRfXeuTJBe1Kbpr0QFcr50zUMUZ3yz/YsRvzDkFdVPb+ngkCnFY/lj5oU1/xbo+a'
                . 'cZ/JrUiNPus5Vb/LunMKk1/o98Lgyb4Zu7Z1lu384QP7Qsvgw668JbLv95oK5Ulh2xuu4TyDb/VT+1JGdZGX3enP0I+opeWo'
                . 'uUGu3dCL3ElHLYicLq1hLxn0/4+aRTAcCB9YZI7PWgoxhbkSVKpUaoFGZdrtVeW006rdcluN++6r06tXvX4DGox6qwnGD8JA'
                . 'wcSEYWHDcXBMooYPo0ULTps+jICAMQNGMELmcBYsYaxYwVmzhpGQYLFhQ5MtRzROnHG4cCHkyg2DO3c8Hvwg/vwRAgTASIVD'
                . 'pppKQ4QISKRIGJkodNGiTSYXAxMrjpZ48egSpEAU0iDp0mnJkAGTKROSJQtftmzqcuTA5MqlJk8RqtmKsZUooUepDFO5clwV'
                . '5tJQqRJugQX0VasmUKMGqVYtI3XqIPXqGWjQQFujRoba7aWrRw/cLbfpuO8+ll69WPr1YxkwgGHUKKq33sJh/CF0OIYW9qLz'
                . 'Ni6MGk0UfHzmKoiAlrdfLmbIkLbJjBCExDDmzFGc9MRu1JJao1nk09qAP+BWGoKcEYK3GwpRRAbiiiUq3BxmIqYixKKfFTJK'
                . 'FUSVrjSlI3XRxWmcDC8nN/gzsVO1ECZSOjKVSEYSapKMXVAJwqQvtrOhkS31fw6DyBDA3rB6m19UQaqiTA5ptaiExzu510Rv'
                . 'fs/XimZsXk0/vu/YCEZbe6XZADGGYPA5tWHQMbFYsFGjUOHLpIJFy5QjV4lK1ert4t5tt6OOuWrAEJygO7W3yl4/rz6PvscT'
                . 'L7zxwRc/pAQSRDAhTCGUMMKJIIFEkkgmBQVpZB7M7l514X3IsS4mYkPMif0fSCpbbjTNo4d54oU3Pvjih5RAgggmhCmEEkY4'
                . 'ESSQSBLJpKAgjcxpC3pMdayJsQ7d1vPW0B8d7WIDbDGgvgFrwCGgQIUGHUZnZIgujFOHc266xVShO9q3IDatQuPaD7g18/v3'
                . 'OCXwgIfx2/EAgYNoB/r3MqIsElq6c1TRbMt5iPiB/pwZmBcTha8r4ncYZjjHYeRor5qIom/mWmWrLuxhBnU3jMAhoECFBh1G'
                . 'Z0FvZdPvefQbng1eeOODL37Rv4SUQIIIJoQphMYwEE7Ewej+LQEkkkQyKShIi5kIdYVoA6ibUdmfOQTp7u+zMBNOxLN3wyvn'
                . 'o6x9DiQhZ/30mSTINyW5WNBuv8mOu0vCXDhHA3V5ZdyPgXk1NH1o2tDUXsoECnSUiQCP/XA669RbfhFUfX3i6YLCfKj4l+He'
                . 'AgVnszNTIOtsoSIPUheKV5Rkp4eeL80vBulsTAmiiSqKCOGoeavmnX3uDFcH05EM6fp4NT+NUcGXNTYXCuDo40tkMI6YcAI8'
                . 'dLsRrXreDrsQAn5uhz2osuXykKUDjo+Lbi6MTAXMVHPgppJBZ0c7IJB7q3d/FdBFBKA2Iply6EWnIk2WgTiCXp4mbLkNlBvb'
                . 'MrapPXLdePQePubksj3Y45UaQezumShLD0IB0eSm8MoZZrHaCuoMWcBXMSv9pWYNv0aGKEV6UECdjhy4sX1yOco+3sLij8A9'
                . '4Lriv8v5YdiKs+wGH37/BtqfhwFQlgLqkgwB2/wcHoqad7Q1ojJ+OyeQCNo4bLip8g9VZDczciW96S/CCZyBG9/yST3SkBSS'
                . 'ItKdlBL2GwmFPKHWv3/ABJItd1tTeqdPA3zg9BmapC4pWNLtL7IauAUICgkGy/8a/BX8edcfA/37P3cDfl7+OfVz40/Xi+HT'
                . '8v3G4HVAwDHgLhWgXBGsTFtKuTSL/PpnDrilW6+37rqtx2ldntvruHZHddjnf14bcsgdCBMLhxo+Ldp0CP4Qz+ThhSxYsSZh'
                . 'w7ZLRpf2f67cnXPMeSNOmeAhgFSQEFNFiCQTTS5GrDgJ0mXKki1XntmKlVAqd8YTZ/3fTgep9HtmwFP3ffbAPNcMO+GhHx57'
                . 'Y5PNvhh1zxE/bTTfdVttsc1hVBgCHQUNA5smdRp49OmaRA+XERFjJsRMvWTGkR17DtxYyuHDkxc/3nz5CxRuilBhokw3w0zB'
                . '4qVIlEQh2SupiuQrUKhUhjLm0oz55IqrLrjksov8UPWpgfnMpDPWf+GBeOX98bVFxwSrGYc4/qC2eV/bGWzx+b9SI2DWNWiW'
                . 'SlBKB8IL31Ng5RLkrRXSDz89Nzus3sII6cUriYhXrLDVt6EcGmsJbwjXpFsAVdeCq4nAtI5qEhArtuYEaE8mq3cq16FBhOAy'
                . 'sdpxeT55jQR3j0HRSbATW5K3x4WYmGBwzMaPrDhyehd5bezqI7x7iSuGGvnphPDYH2mxlagMyF6TpEUvVA92pDDboAX//8tD'
                . 'LD9LpRJ7wx7pI285Cd3+5St7m/Ta+Pj2p4pHk4fDusObIsOK2LLiyAUtSUttiQX/Z2GE9Ku9gVfDQUvzNf4g7WCXDKp1RyER'
                . 'FDkkPPWEe5lq94phKc6NL+KlKsPSE1xS8lW1jUqUqsqDPgjaFKc1KaAHkhHbtYvhppcud2RlqbG2PlNr0qamAtmTmYFDAdPV'
                . 'MLKz95QXrr0hqb594kRK///UzMvpOcmy5cOmidvqRa2C+MGLav4lshwHqsmAHN8VIFwMbDbnTkCY6kUSgxHRkNYo/cXmAEWz'
                . 'ZQ4vSfnaqyA2rlpshW58OB6FW5FrPffWeg0ITi8yRA4nRimEayQjoJfyhtbQJgTOoJFuLdhYSU6iaHTZVyKRJji7QcA1AZlp'
                . 'k3UiLcmkdzGzNWL1ra2pIBMTR/yuWVFTRa8AtsJl4vAfWm0r1QvylKgUCQ3faJAZcEnKt5Q3agxILxroJciMOlZ2fSZHXX1O'
                . 'aSzHBzC6t/zKzjxhbB8wFjSRQe/sKfraj6g5aqn69BDhsrIgah+idQEnMWnZdHGSP+30y6pcofatfCKfDFMGRNjjao1S4aCj'
                . 'FM4UcZQX9x0zA8xJl4iE84JUlzNbqDJEb9dTd4ce5fXhUXJOqkO4cDfHQKtuVc652oP5oIlaNuda/pE7z7SmjQzWu5pOkBC4'
                . 'EgGeNAFfIiGQKAglGiKJgVhiIZG4zhZi+yzSdWXXp+mG6Kuj0K68K6ixg78NFzVF8O8uS1HxnHXsJmpncwaOutHJSJli5Bgz'
                . 'jDlGgVFiVBg1RoPt7oFpbw1DPdCt6EjqHXeOoYFesXCNLzF0JYf+9JoRwMC9IdMSx6VX916qmZrDis/SWZ45CrbpOPYzpWs9'
                . '/1FraKxN4708swG+Kb1siwqGOBJnE6Ua7nlHBfsUABdnZTxv1KrnXbIDpjFQPUm41v4eqrlHc2+65gGNBpoN1/Y9QibEfoHG'
                . '2oS8o6xZSr9O3PVE+EMz4PZ1Vw6K1m6/5d7HINh0HgkyXoiSzKKkEwp8d5R6B6bTAU2ctKLhtYq0O0yaaJ+Hk774zp+QdjM1'
                . 'a0+56asbZNfyRMqx49If9kqzrWphoOMT0iQkQyukGNuBJoloRAuDXFH8pVGgzCrSj+Omt3n2Jyu6oYALDEg0AVWyWnDEUiqi'
                . 'L4pgkywSSKFO1sggWiBG0Ufs1A4qMm1UgaRMj5/XnFnky5Q+WIWsAWPn+agJtzI91HvuG0yLNfJcg0pRsjBBx27Q0nMLd6Br'
                . 'oEcaSnN/j11smeXllRYeborqK7s3iSEaZkHqZ3boN822ekxrk5dqvis0vvKB22Zz6Kt0nrFv8jXwOCvLvg6eZEpHFl4jTAe0'
                . 'XqTZcKmGGxbYoWkUqycjxYHrzdaK1K9EI+O8828i81PhYGD9+Z/FIIBZfdgQdt1pGTc2VNqsqgi3OuH4nUXU2zs6tllJIzUR'
                . '+pM7htXmzQKdW7mVC+36zbm75h4yoCTBvSj3y+rRBg8OBXZP0KCf8mYp8yG8mV6E3+jaIxQKoLPkiaFIhTIxVAZ6aomhngqN'
                . 'xHoTJwDAhpYM7UzoyNAdC0voydDPhIFsDsWhTUZFFMapMEkMUwMDs8QwT4VFoi9VAklWZRzWmbCRvoUC2Mmwz4SDfLFtPCIT'
                . 'OE8/XSSYdMEzSbWLwTEimXI9jVoOtyoD9yrh0U7IkTyDIXkFQ/0NuCGfyAa+VcKvSvi3U8Z7Q6qC3mRB0JtUB71JTQSgtgpB'
                . 'XRVS61HjQxqCPqQx6EOagj6kOQLQUoWgtQplFl7oZD3VzniYqv+FwPDAR2MjfaMYp/+0OT39E25y3ztSn5cNCOp/PzCWuico'
                . 'f0C5C9auAuxfe4Ht2us3UhW+jxIZwVQEgvE1wXUELpeYCVoJF7EH7PfHIIPIBJbIFSCoce9FoKPM21rUyYkXwAgaoNsgQtHr'
                . 'CIANHUJdBHjdS5g4OCzE35fP2w0hRIhI2E7cBNrB2/kxuZpe0AJbImdq2kiZSdfeP/6tsJgLCcszjK8eboWImGSCRbRVKKFO'
                . 'NANbItv4EI5UZlrFKs5ju5z7L1dTfUWFfVhTiSc/JxMVNSl4UBNDSX5nmPjtD7PmnDZML2BLZLlZd5daoUoZ9JWrSDdtadMC'
                . 'iSSErtb7IpkbTmU/Vf323mXKnEVWavX3mQoPOTzLqwzbkKSWuPuI+v4g6SxppftQefpunweZHUWGPrF7pUglDgn9JL1m841Q'
                . 'UOgCQCFaU1mZ5gO9vnHT7PfOpYAFmuhfyiUsfiazPmYWqrhySQ564k16Z35/yCR6HoBZqFi+TcSfJhaNHxWe6fKHX+sk7AA9'
                . 'canxyD90ivfgiDXZUcxChEzGZhs3l8Pl0nkPFScVFoMNQVfCIay97pJs0pMjAuecqSuT6J80fXaT6Jhreu3cLlO3B3dQEk43'
                . '83lMgwe6k23sTodtICq0rXxTlHMppezt0jksP/vEaGUNPk+azLFbqfFpzTnpC93r6XNI2gD7rl1Rk+dsDlSFcsLzqwtQQlYI'
                . 'iNRmLsHnbbpTrHsyLyHecaweMYeGzip5jcHX9QLxNGbRl2yzJdE52TBFlSCK04vmPskFxMKI81/ihVR5h++IWqLLHJfcIY6t'
                . 't6WTxTvAtqFlyCEjy+xRTJK0CmcqTooWoixGInEaRSORiwbjA3nYtogcsKtaI2odrG6NqwxlPeUYEc9NJjA6JuMJk8xGx4uw'
                . '25Jn2liF9MKjh8jFDk2EZQ1nVVEblLlkHK7T+X1/60nnkunQJKXiLLd6pnj9/UthTocN/Sz5wKK8ZC/2G7CMDsU4l7TXW6pk'
                . 'iQo6WQGm1vYFE3E5BjXvarbQIIuv11JcmeVGgZDV+CFWjXJ6T1l5wkKueevU7q1YML4905FcokEX3nrPmiJEKPDL60zw0R3g'
                . 'yBPeJZWZ5G/I8n67X4V+WUt4LklCisFWhv0Ow8AtTzS7y9ZbM7uzfpew/bI+egN08QHnk0rehs9hFe1Oa2c8HvzGaMeXz54D'
                . 'eCLluTSaUdng7YdgJMLymYfErratF+ZLH0ezbsr4iZL2CQyiMdB4eJYz9jLkDNNyxd47sJWWk8gAMfDUIgZmQ/cs7yRUOSRS'
                . 'W+00lFBS9xdBa34ZflrgPJV+j1vd3nSgbVglT6w4Uj1jzkMev+yk3ow2e2azzgOWRU12HAlJCxGxaJG8uqhU72w/0dMTULvD'
                . 'SBd1V/6b6StglUy3PDr33NglREVqFost41mqk5retoI8YpxumbEGGz+s1hyvi9fE3V5GbiS97JwlY0V89/z3triBfnArTsPR'
                . 'k+gjh+0iFXo+rCB7p9YhhSZTcyCQ6Cn7zJoi+CIdk3Kv7jGRXsxsPwXLCr+81wywiTTP0CRmw7QENpBFxv4s0t0WEkMaHWjo'
                . 'YJ90m1OSpGhxbXCRxV6y36sNzqKSCGE2VaFdxntRhemIIzQA2r990rEY33KzaOIg7jcSuZ2FtzFawS9i1pFCB7jymcxBnvMF'
                . '75RSDe8Fd67xXob1Yin5y6zuyGPGnRxnmEpPXD3RLwh0Szy+p+bz3imp+sJeWvL4Srb1/VlRtSyRUr5DzaNTE6SL6to9yiUk'
                . 'NvPiz0REtJciVrYxyOEhxll+HuFb0WgIEaAiApQlwvDRbThEzs7YreJ0R0N02Be2DS2lu7D2f8P25T2qyrAAzexEGFgwW4Tx'
                . 'F2tP76Ime36BnD0xg0BClc82LUhhYHU7e9ThqlA9Wn8hZvPwfITHKkg+ehqZFfRI8aOH5030IYPe/YGvnQBjnTO2bo5ha740'
                . 'u6N9DjXZ7SVPet6VDiGKlHmRfO6yUBHeI/pQwgxEOTNLdux9w3fJpCe6tP/MYjAz39BFbOVK6qTfapCwqxom84r5uXVX75VQ'
                . 'lICvigf4kwxkGfbhCGUiLcN3zOmTV4C1TXNIjii8zjx4CYZT5rGMgUMXftl/JS/ZL7FYpTVr1LqQWdaYQtrpeZgnEIGExhE3'
                . 'hVvFsrUdgSY7+oxSgy9789qcJj1nyBSMkdWt36ik3S5CBZsXF1qdZti4thHl0XVyK+TPdguSzsmPtmmQGltmleeljU5rYHbe'
                . 'ySpAXpa9JUYdcwTti7d8pQAE5MLTYgX+dghABAG4mfirSFj4CtN9dIXjR8GHUwHCrjpOHw4GsvVsRRGi6kAhU/iXTKSUfk2Y'
                . 'me0NeOfUsNXr7mBhFfCUAxdE1SIK0FJY7kcMvky12Ga5GpAWzRNyKbO49kv35IMlkMr6sesa4UxUTuA22DGn12vFm4JEw1ac'
                . 'FleHFBqdDuChkuWf/8auJliy8JnrG3gaSIe6tvUgZaVXHdfgrln5W9E39m+5f2v4xldvK//WuEv/iyTuHJi/3LOeJBbXLfLb'
                . 'OrJniMxW4qJ9uiRnHYy6HxIaXWKy39VeWtFI3xelcfbtQA7MEO3Ciha7w9rZ/p4wR70IKtfv+LZFr8llpn8fQvNp0QQg1J7o'
                . 'fQfo8xmR2PBcUnWq1hrrLCRaZXVDoMGuK6GnLvUvl7f/Ol7Su/Q1+4ZFQzSc00v76Jq6h5Cdr373XWee9FwP0NNpY+Lkzl9W'
                . 'po7KLgzXO3VsG6jUS/42cbQP6NSzg8mYcIYrE7a/SczOGnSuvX4jIpb0Tt2C1sGoNTR0qOj7WTCzjIREVX+abT/Ee4V80I7i'
                . 'jDZL55INLYXvsVtpHwqPrDr//IfnJWjK8EHTElaoL3CKOTABwC4KUP+oQJX0jHQZVLGgDxtMVc/GENk69SVvUIr1srMzkhnD'
                . 'HZ2VMmYKD59f8bCp9MwNzPqYLKRecVDps1dkd8M5UdbP52nveDQsm343RzR8oKdIKVNFTnT9qw4uVPHsp+9belqGodf/rG1N'
                . 'hMHZALfi/3UOeqP4lmmh/kfZhsm5vAn1GASSF/U14R76z8CnXrI/Moe+LLiV16/iu7buQYP2zZztNF7oNkWZxKe9XpZISwJb'
                . 'AXz26oHOF3lL1fppe5YHuHaf/Ldd9hztN7kJM/ws9YKrPQsVPR8MLXpxZcq79nY+U31blzG/ei8tAFxRM+L3Mji37mHhyX1l'
                . '5Rkh9dlRMX/GYLMrEjatTWksu0csEag6wefZmOVbSLoxooIbvl4ZtN+W37woXEZ/iiVfxTSUV/j+eB1SNwDKuy1uTy7Kp0Ez'
                . 'qlm5k5PuGDOn79ZmLatYO9l7/Xi1+gXNdJu8/5wvN2ppOR8cWR9c0qHeZSsM9SvVqazbjIXVA2TuPeMLGI6FsdHWwyhCtliW'
                . 'UfoZcLhpq7lG3Xiqa7DGduBOT2SPPkBuER/J6CgkvgskXwMS1z4Q/QRclZ6DatSYPSQkbDhiqoaBUE6gouGvyicUkjqUH2QU'
                . '7AqeS2/63sojw1yQue4IDRUjTg2jrmjCsTzPFWeRsPOyu225G/Tb4J2hkMacseslpDmLqZ0uvJTH1FCjCSrvD3elv1nB06SD'
                . 'OT0srOHNCV5zxxkNsNKzZmNW1mF9GC7P3tB1k8HUy8nSgVnqHidZHmuInG+l/vCj90YMVe6NW/KcysvWhfvz9QeV9TrVfZIZ'
                . 'EnfGlfl+QMTC7Ge3A/audYRNj/NppxAx25n5wR9bSXYaVYrgvf9wgTQQfxFpkBv5BQSkAfefhAB8zn4q7pk1xcOUkfm+kfke'
                . 'XZm2LOUOpfAPrceDRMp3QvDfMZQEkgD6ab8atUTMz6o5/Ty7+cI6+/mcRU7+TMrsNBzljhyiFC5QoAWLn8p6h2zP/Ecn6x/t'
                . 'rGy//Gtt6A+mGHtehqm5P8ZNfg1dTEyEX6Njd12jHKxU7mASfDK3Vob8M3EzrmixbqrukGRUcjY0tXTjA/XMYPpWgwZ5UmGo'
                . '4QtLBHxQRemOPehH5RoLRDx/s9xBrNl3Yp/oexPwBzPiqcunPAw0qh7eivKsVLmb2dN6nafhb2GuVHqszRRDIQvxBIF5Oc4B'
                . 'FZqn9eg4LYWFnFaPTstT5QQlIWBeRjwLMRQ2UwrGIrEVgA3GZpOYHyZyi9OzfgCtlcYS16kz11MG1Swfv8KNqlYXVtORyAPn'
                . 'E20nmfhMLdGDPMl2rVQ4Q+xxrZYo0fh4XNGenoUeow/mNOXncxp9CCsoKh0oFWW5p/OJEEtDPQ00TuJOEK453m30w2w+LLvR'
                . 'jxi7T66RIk/+MVsHNxsOL0AX3hudF7voK/BcYf64bJhrQQKsQqQOe0qph0Ba4b0HjyBPohpzr8pH5Rj6dpR3jdLTzJ7S6znT'
                . '4UdoncpvaiTr8xnqxjpgXtchhCwzVul4rYUKfnuVzmj3FMdqL/dV5i2r3BG2KZAn28wI1G12jQtBSkXM5EM58YvsNqCoa30S'
                . '/DjNBiPGRSPn+15b9GjrdfVAl+g4RpRcq/hsmggIJoXAoJc9kuil7BaFQ7HjNH2YjFkU8zcYxc/VFqvL/zP/uEg61GsSNLlb'
                . 'rOx1UY1y5I34ECM0diU2TuG3GW8dmX9y4J0n4e9zpg1hyx+c+STXed9/MD+0wfPd98jYNBmm5q1ZTvIH6KDekoHpvD/KwUhL'
                . 'ksu+hgZleDNObkZ51qh8zZxpvZ4z5WuKC3i8a1TepvB+zrS3eYLVOr+W2yqXc1tCUqfzEWlRWpFh53ZJLXCpxHX9Qt8tTUzj'
                . 'OZ9aOl8qkbX5TK6KnvCjpa0vFeKimGPGmLlJ6qBLZel5f/od5ZVZSNPWgjk70UGZ5kRseCPceCh/axGh6j1OG/8SuZqeYVP9'
                . 'OCCgrVsXONzoSchqvwut/s1w4xqXXioxQotyj2CTnPRkKumQYFRwtm6KEdio8Hd/Gmh8XIFnnfncxnqklLHAKK2HuU1i5Anf'
                . '3asvbJcrPaUZ3XO/bPXMfT5nxtuXi92v5BVS2+bNZWcpFC/q3/r5TcTm7uOJQu2LXcucvB7sPBC53JctXc3vbO/3FzV2s6yy'
                . '/pszhDxhlSzLUwRgo7EZzt7DPz6Lbcnd4Ju5PuMwR2eEZQ6UoY6kkQxQNMAONV9vidX3KKSnqNTUl4NcZRmMwHUrJKW2IKtQ'
                . 'hVWvAdbJXWnmKLsJxK63G9JfrhXmV6g06obhwoI43+JU8tSij9iOKl4pLxlteHPbFZW+wmzWVaje3H6loWR0lZyMvtYPXLV1'
                . 'cfBXCKM5z9X2DuOLHLfFGqPAwX1GHd00AI2P+Mwk6VClLfYk41u6yRVhAlHin4v7zlkuWfaF7xHiwguvt9Vmr8zeuTJrNGv7'
                . 'i4vXe+5136PyFzsed7yw9d9UrP8dbuIX54A1ROi/8TdH9xga5Shy/gbRaay2XnxMUunned+DbH5r6zdt09KWtm/bp7kYjFBN'
                . 'gCSxSDg1jMRKIDVBWFDBybNhq4VCT5nGPsEV9Ga0FoKovZm+Sg1NuEaIfX/nR9KOV9eXWphaOg9/D/l4Hr8aMd3sgle8rAzX'
                . 'uLTvG5Sk475uNj/e7HvtEY7jPLcl8AS07/cYV79m/pWNoWY2ldqMLRUNSwaXZJK28PFlOWXADGUDVEJWyS2EBkakcaWVWKBv'
                . 'l6v6vVAHR6mXEcad40CrVe/LK0ClrAgjnYWigOR3rYRvtY8ZCxzub3QtwU1+ctuSPSVoPHB9GCVZSBfzLfJbmBGj+/49oUPp'
                . '6jmwlVgjFBKrYVs9R6d305p3NADrZfmGQeZqjJZOx2hXM8Gw6k58U3PpORaIo7E6oAg6hUnMdxO5JemZEYBWApviq4u5nXIm'
                . 'czw0D6xrQqbZoy+mmdGPbMyLHXuf4/czcdFakgeDktYoMPWLGeHfho5Wfq4BpW/4sbIRq4V8nLfAVMksgGblh3WIuVXFL4ep'
                . '5VTArh44c4348go9v7yY21+zTL7Z7ShcO1DiXsHg8MuKgkGa6YwBNEKB0NoBpRqlC3DUNkK1SICrUhXVcbTGdqVywAk1cQu1'
                . 'EnxNZRewVv17bTl4UfeQcpHFeqBY7m4H7J3r4tLQZAQPte9oaiPjVatCmW+Mfnt8QavcpyYvqTV/oEXCd0FAnPbpNDmf30e3'
                . 'U93N59fZz4mDVP/era6YL+srY+QmweuMlHzCSiAXujj0f8eMpr6meY/xjskHdm+pAzZLle8jK/K1ebKY3aUPWQUOVr4Z6xXw'
                . 'sb58s4NVwHo0UR2jD3+ncWvrgX3ljBBGQ4Yw6hCDHsKoITJGE7qL/BYWRG0wO8lRWGVgJd8ZVlJWsUnDKVi+fZOTlgpCqTSK'
                . 'AAIFHc7UcKrVnBpOzbnpehZNCJN3oxCodJGIKj2Mxx1jW6mRha29W51UEplMor7XOzla1a6pJDHlThKWcKkOhL4XIZAUl0Yl'
                . 'TAFKKOEIMMzkcHpugnbYRq1Cfgs/f+rmybvfP8CfP/v1N7blT8hvvPzUsCEqHhYVFBqksRFvnvDFsqR6PsnOaQprqTVUnDUx'
                . 'J++gVtRWYNF6E8tgAFPzVn+FTzrl9Mee8Z2sC9BM9K9jQDn7LaoBo9HTUO48nXxlPy2Ui9DouXCIRuvDwnQaFuljj7jbPHyE'
                . 'tWiS2j7s+2Hhvtza1aWj53tHvTvshs1LZve9ZHPFEIBnU+2A8TD7U736Gis6lxek2LaxmiZw/szvUKEegP/qmhCvHBItU19O'
                . 'If5kpz1PPPHsP4lba+CStZ6cuHvq65/MKwLYkgej3ORXMSWkJM1rdGz1/TEOVjZ9XGKnOtvEb/EvH0FV9lmURD0NJ12pruYm'
                . 'fOXjqc3tKkW/E2okFCISfI2hNYZelJ+SCq7i/cVO4d02PUg/x2Dfy/530ufEc/uod/oJfPVuWDo+GAXTx6iYiPe24qAE73yU'
                . 'X1nHuJONl67G0CWv8lh6UXeTHHr1n83D+alz1qiJb31WmZuBNS5n6bUWx6ItG6UpB8kTtcA6M6Z0kRifp5CP6xMe2EmJAh7f'
                . '7GGq5XbCd0XJwEonr1Oon6SkgxeRBHNitUnP41v9rIKq3w1zNb8+NRwy7DoSDTw4tOd9lsbgHxYWQ1SgEn5GG2Eli/XffEOv'
                . 'QjBJY6okQrnJXvRRuE+ID2hKmwXbeSiFkVx5CfOFq881Hh7v6Ov4HxGkp94FSqHS4xOUq4EL2oUNcybKEJXYloLlh1PD5uhE'
                . 'ndgXdD0Kj0q8hi5/x/WU3be2D02V+bbjeXmX7TF4ZFQ5QeuLv/VaIxsJFM+06+GVlHB/sDs4fzWF+Mr+mO1zBB2rg45NCY/J'
                . 'vV6xm5lK2xOzh7Er61TjqV2HUAdpO8PTrz9FqbuK+RFWwN553qPgtczwimBPcP61v3IMBn+Iw6p9ah1n5rU7CCPybX+wHCdt'
                . 'JY9ki25WA0oAlB9bxxXXw52A/D6/bWV8OyndZ3j+8/mC/FnX4H1JicdcDOLNqVZfWI++z3UmI/nEGXPknx3H/ry89haFe5vt'
                . 'mk7UxsSmOTLSCSqgCaiPI9ZKZBroHuZhJ9knT2xLyfpHP667btniFlcbl27jcuhWG0cmK+LQbex93yKu1IvLJRGwXzNiCUQc'
                . '04sydRYUNdBWWW1CQ7Iz38RS/iybNavAuFJT1vekHNXE/xfALll8so23HkjI+NzKFjfQ8huHm4s0Hd6CYi452ZALPjtyKnkz'
                . 'A+9S2+r5BmOPzBAyIJrWngZr4ax8WlSGMtSAD0mqd9NmMzonRRKdlMt4U1bXE+BtptLVEj5HH0jfkPoeUf2yhwzDvTyzR8MG'
                . 'VXnh6BSLDdZZagaFtpm5ADB17o1LNzvHOucasm9A3DKUOcTVGHF20gYgZWMxh/P8h0TSR4esSBe2HQDWnT1+YcqccyQS+foP'
                . 'sb1NL2tX8klurSnEsoZQ1g6JyQf2aoyszVUDc+Zm1h+wCqGVY62XS/zITFiXK7E3IeKWQh7Jg5jqWdohmV4vy9PrZFKDLk9m'
                . '0MPbIzvzrhZts7bubJ1CtwI0c5Nlm/RqZDFkQVmGBBYfLKJqZSMAFbjXsCwF4uoWGE2gOKKczbrGBaioXUbVimCzTzfBFYFG'
                . 'QmeoxUIBqQz6c4FvZO25gkVfPm3F2n0Ljb29x7v4a2uHxOgj92qM7M3+gbC5Sdp3R1pE9KCf6NpSBwMPsvMFUDH6CkV6HqVY'
                . 'wKfE/+pzBZU5f0o4f0/K/orL/YJ53uute6JTbIx9W0ii9QDaNpgPi3LTRpNStuRUc1arMFCBXSnmwAYNuKpUcGopp/+tLUwv'
                . 'qgCTVZYYD2bRdLR0OCGhNCOHEed67sBxEwN66sGTeiIixbvJXHueBUB2aVcujN3mW/nI8Rldi+B4FK/12LJoN1Bqn5HdnJLa'
                . 'kpkTC/i1bz8t2hsNRwCf/JNDJWzKxT/87+h/USINi6Cnc5luZ4Eu7RowHX5Mob5YPmCGpDKEgftigW3JXJ2atjoza0Va6gq1'
                . 'YilCaeQafjhckqQBfmYawIYIGLwOqtO3gzncWQqAw6uvcgnueyB4rxGPv/w+gANllpsDblelkx5k6BMTdRkZ+sQEffpsVig5'
                . 'KZSVFUpKDhUe9Yk0bLyexmO4nAW6dAWORnsRaXKTZTKYgX92l2MRndWfldmfmoYL/Y7X5u/HP3/pI+D01Hw0pae8+ytOrkk/'
                . 'GvnH3R+9Nf+5L0ovr04vTQbWk370NEcv/8OjSoKREx1YJ3Jth0evliabK/XppFoJMfNpRnocrlPK01qJLJ4FfI0mczrsnFMB'
                . '0bP1LghJ/UH87Jgty7Nyj6WkXMjML5dlTyWnHMomaOJb428QVUQxy4y5R8FNZZyLevoxhXa/KHclbv8rHQdfyWm+NZhLB15p'
                . 'f50fRecZKoTv+1b3GN4OV5uxXmUYl/o7tdML47d5Vt7PZW3LLyvOZp7Zw/zsSaEizy6lEFQWCE57B4z7L2LmZ9heQdsUbJdY'
                . 'nl/XUVDs/meVvCSf5hUKaZ6SAjnduJ0c4wzagpwxjtqmPjMmigFPyJbUc7+BoG+4nPc/0q980mP6F+4JOP87vvZzTPXt6puL'
                . '2GNbuU/fWh59mEH5QUj+XkReQgaXoMtmiqdTb5IgPAQp6VllPFOwkXy4vVhnDFKMjgBXoKbZJ9qh+3WmkDUA0M0iJ0N5TqCR'
                . 'dwEFRXWmUPnjRbkw72HzQo9SYg/wYwsXyTeJxJtk8FMi6RPW0ZSP8U/BI5Di7K+LMPg9xvjfPSK7yO+y4elXWEy1rHBTuam1'
                . 'RwwjXWKkBhaQdTUhJ5po+kxPb829QQY/yfpULkODt3Z1n4C9b2F+CywWa+sIBMayMKsQQ0NmH1e3uHU/DvremPydKQSYgEvw'
                . '2LcEZjbDwuEyzCEu4PksppnLYVrwPYc+o3coytomaYTgOCdiTV7EmnoOIThJK2tzKIo6sPPgqQ3ooXWYlWtt12rSyeEuDBhS'
                . '1qW0j69cTV2rSKeGOljdVchehfnRW0+8kHHth1Wjzfmue77TSkrXn73lvbgkoCLzZw4Efw2K9nzmjluT4J/lna+qIhpopPsJ'
                . 'VtOLdflNhHGdjilTPkhsdESp0hW3NsGHQomS6qLX2HUFTXjqKat2ajMv/NWPJrmhULBGWQrljapkhuYxl1JxV/Tb3/z7/W3h'
                . 'pJdrPICCy+iFVTeUL1DcqCbj8xbEv/6rfb078VgAXqBXjr1Gfm1cj6mmbzL+pTKmL50pmSZdIkFJEEVJz6rjmoIryOxb/6zg'
                . 'S/JfWMTLUi6TyHpmUCnZd+YRz5DIKvcA3BUrGEtfZc4HuKSsrS87ilFgdR/7/iJVGFoAZmGJTpzp8UTn/Uf3P9z96KOFh9cP'
                . '2Q8/uvOQbXR+0SFrl+rDB4+undILZTcostjh5DuP7hQZynIC2Zm3NeFJBOOzIcFn/7xvJRdc/Fc6J+7I3IuWnrH8/RWOwkM9'
                . 'k2dty70H80bajHvqDYzlDhgWqHDUfFQailNGVZWp6HUyCa22RKmkSd7TRwmHz1DitO9hQaIRrpQNVZf0UzWOicLKAHNIo2H2'
                . 'V9ZO5rvhBvQ7gDgA/NNnGLITNiK5WGQjAb+xNDe3bONjKcrUI9LX6GBtlRpqpBnFhXl2F00gcFCkRqnG5jw9seFMQpm4rxw3'
                . 'sXf11pdPr6/B9uqxAh0hFSLtQ2OvF4zyjWoF1ld17FIUsqXZK1UhThGrQiRiO10STeGrXqmG1PbY1ECCJS0UDbBD8YCKlKe+'
                . 'D1HEb4/qvNvIUdvlhdqiL2P+8CwSFVzbLyvX+PB3DCQVEFa5poXLMq3KhK7/3sB7r5GN9z35VbREUapUI8HhAvzLyR+DeOhB'
                . 'bGVm8gcgnnI/dt1ylGu60BVkjcAIaziEo+maunz1MAKzRigmdrrgWpbGSvQLhEQfU9gwXBe/2icUEP0Ufa2I1pnPcgkEnobv'
                . 'Qa1QnTuA8QJNsKNL5LBVTCZbxSH+tRHIBa/+gDo3dH9AXiyilXE4SBaL5Vwl7iN6i/sMryZDeLNnXN4znBoi/4g+u3ylHNxD'
                . 'oBMgbUFCuiJ3FsfAZWdktM5M+ibkYU14xFdzQzpWIp28XY1qLJeUh1DGLwaEJTx+qVDYxEv4bWspP+pTyhPUE78hkh6UFBIe'
                . 'X978j2eGzuDm96KH0INbvhv6yEX7Km2oQLFgmVJpaKDxoVTaHW/YmA8byoD1lv37lfXr/2bsf6lnZj20Md9w7K7nU4uGScPU'
                . 'ImWXQ612VKLTNWR9yOFQqxwVYaNhrqJcpSqvCBmgdDWzwqFSOxwHdWRN2rkxNbiPQQYZ+0DyPgZIZuyzUv0V5qgqkViXhViA'
                . 'WREeb/Tcquq1YzRsml6iVngaxeqocrtDFV3Gww3UAzOCMcL9K1ezbNkIm2HJV+RXNRboox0ne4n3/rrwQlp8AIr58PV2sQGD'
                . 'shbFJybEF1ndKSb/4DZzC1kb8zbQ6MS5GIKrtaQp0poZn5AYn2mN7G8pcRHUsS9Ck6vaGHLLNrNvMNVotY2ooy0afsraoTWw'
                . 'l4HVcHXG+impm6GLDUODrGHI7BZLIAv3ErzXkU2wfz7rZxiRey6VnKMY15ZUHRR/34fyo/aXrKYaz5W8dnnbOUWqZ3FD3GJW'
                . '15+9a7a+mZna9cvYyTHcrU63q6EhFPJ4DCUa1dAz7nZjdHTDSxPJbecca3nc8tXTMXMLdVftQOxAzOOvBbnG0FBEU0SvI69W'
                . 'Rv8QMrTbaWhJaElTdMp2URRPLmN72YUymTIPO/b/s9zUD9ClxGTNx8zcsV/PJebKzn5HH7QUzfd+m9Izj+/ifs3uOlTG9fvh'
                . 'b2V8z/txb1AT04Ay2LqO2Pg/0CH85BmEn1EBQkeSSSgUPDrsaGLRjGwW1WhmisQmZkiw2PFBsOoP71rs27moXYJ34XBOPME5'
                . '9XLdes7RzLqUWLqISGV5GpIiJ+Ga9iECnr5G9GjyWwOtDh3fQ2qlDH9jZXyEqMAPgf/GnmyxTbt0LkpXETHh0OOnprKkBDA1'
                . 'dYkwwhE3UcKSaxUM8md8DdZd/Qv4OpH4Gkj6gEi8Qa22rQfRqpVWQVHVGqe9+r5shO5YIYplwWvXfzQ1/xpkz+KLEbslyRjI'
                . 'Ppzhb6wOKIZOXhLrOyw5vr6qGxu9P+T7JcBemNuw+8/q+1keDawwhCa7AOoP5YQcMgGfEBEHQ43mSYfXMgZhBQFFtAhMg+0i'
                . '2VImiOrQTcmvlVZYMRjqmsryrMO3psgZqwiFeygjTNBhETyFXWxbyidxH4bp7iI42rmJ+3Nw/nLVVSlGx5jc8KjD1ymIAaxS'
                . 'AcReE6Fyj4Fb9F1Kt5SLYjb0U01Acnk9rq+VRZ4lBITBkOftDt+WRsk70KZYI+xFkqyXoYm55nZ+waKMnlrjjoBJAdMa1gIy'
                . 'psJEMTgUOsg8bmc9LD7HECrUbmCZKuFJPkNNRXByCPMwn2ZpEkeOpQZawDE2uKHKz7PLnBBYHX8W/9e4olBBKDsshSAY/sJD'
                . 'innaJWFLxSwxDtPkeAdGTd16RFDQU6BQjbBg0kc48zwEx1Ylddw8ccs0ilxSkz69TVuj11zoSDFX0CTa6gVGQ4ZE7WvnAZvX'
                . 'XcMQiMPcaYOEOQvmr8Y8poemEs3V2ooI3SbtrAu+erLvyDIBdm00j+eubYZWCEtd9Vae6gBKXcV7WsspflLKBnIq+EmgCQC9'
                . 'JprToyzxHF0VPC7ykFT/OfvGQC5sopKRohX26BZ7JngMP9kgIUlPIAzSwYwg/kS7NpROw2Tm5fPjw2bVNfM8DpUqi3jH38F3'
                . '95rSI1QaTGtOFVio+o972QiKo1H+rTYM0gjvdKkg9YxVkaobIUbtETZNFFiGrkHS/OjQwkTrjE6smLHbCkkGIopXjemoLbuO'
                . 'nTRcNnhLqZg5CRHd/unkbcP+Q9jGNQ7y7lEQbJVVPdkIsmlosWndMaeDiakepDSUzhnnTTd2qCprSVAf8IsLyacNYgqxpd3q'
                . 'xcFgU1YvsmyY26i9bd0W4baW1jJXAC72VKfqbgIZV0Z0VKkMUVjRouhpNgXgkQ2GSJj2OwGlQSV4hO0WxDgDrTpudXLIp2ns'
                . '2tn8s9zIVDNAmNLaAHuDHkCKO0m3KzeIULGAjJKLpY2HmrWRDD+2RbOzRoVD+dhMwYyuoSSGzeWcx8aQGLJ+UZSADtidpY/c'
                . '33bp2lzKoAwQV6SaCgExs6wUtRmOxSrZDEg0aqH+ZZECDpA8s09POasqVmWWWZR0IjK0mwTkmyOtbnq4AlglPyJlOQY+GJJ6'
                . '5LaCU1UMJnkQqPR8zqV8UlCKoTekklX6S2Wyuzls/J1VhOt1G2XBnBLrNUFAvx+n42GwhdwF2xZO+tn4xDi2KJpIbVnjY6xP'
                . 'oWPQaN0WCOalmVv5NP8gy0g2gHa0vdSTVAYzaQoiMzs9XIqO7t3SK5XKxiGP5LBdfj1oSCW2JsEaJD1VDj5sIILZ5/EZsAwc'
                . 'TdCEja4ynXTj78O/OYGZJIUTHSZ6Q/tUBaJpBmYzP/113qrPzUN3sjjJs0/27rVw38cAllSwDgG2Qd0DyArG3qdiKCKGS6s0'
                . 'IY1NUeJaHX/q/9fY66KEzCS17uBYqvglMdZlgBx15DIgqFhL6fBmSMSMRVw8IQyLzYkMapaOR3QfFVVjjJaBe0ju5Zp2p4rN'
                . 'sgA4b8ohz6BXPzUqlied/zbVkDghLosNE6cq0puhcdRdPIuT40MYeyb70hLO+4XvIbh1XJz2p7Op1/nd08PRVoAQpbQVwNRD'
                . 'jr5llGVWM/T2H0vfhVrLfmiUuP4LIFCMLZLoq7HbOifWVHnqOLRljYCRBjCtHxRMadFl26+5KIDlmF0GHsywToZmJ+Jo8mRz'
                . 'OqS24YgAlcqviDKEstIFso6JymQaCbKk1WXh/ZUgGZZZjmPGR19iy3MRnDBu4zWmIXgoLKXcLYfirJpKyMZu5BINGrzLLX5B'
                . 'HjhzBvOM2UELkNONrKwiEEIzGiToGGcneRYFsqSAW3zeHSYmEMAa0qi0AfcAkZ1N8GWspNpyNtaLLLjckUJ1azn2QKHCoTbv'
                . '4Eip8mwnMYs5YTqKwx5pBTMmZ26jiXVVkp3ENTrTI9OUg3rhuzt4epxncbSTiurR0dDq2grshUyRZFQ8QVMhTA9rkLGJXjQT'
                . '7LKAaB3sRo6oRNCdznk22r4roth8Ym+racWQWlfSIQ2IbrMRnKtN2hiBAC7sP/CF29RYUzeH7WgESRWpuM4bQKL1YKPRo4Kn'
                . '5nzdn/c05BRnfXYgUbB9ymNQAi4aBRg/wJvIFZXFsd4hYHnI/jI/JmZuMh1/gkFtT+VXqgzImFIAkWuuJwyyTot4ICkiYsfy'
                . 'c+g1Uo4QJhBT5nZomzWXb/hRYlnneCRaguv8lSgdH81q4HAxpprs4czCpDfYiyOKTp1FXdyZOpzMsZ0wDFXzF5EcSwPJ2VEH'
                . 'mKQQjwImI62ypp8eFNQeQPMRiFGbVER1rrDJGyjPzoIkfkvalWAbF7AGOKxkhZaFBgU+aGG1l0TLLutoSZayDNxM0iEbHMuC'
                . '746HZYNBlK9MvQ3OjmEzPAi4fFF6aeH3AJvI1ekrkZI1uRWEkb4sNDbQkmHUfj4D6UpoH9NbCxyujKmu0WbTWif1WzZlF9VR'
                . 'rkap0O/edcQrXBVTisu5S2+Jh2Wd95bNWJwq0DHINyu3TF0Uj1TtuCFA9uObijqS4r0Ce5RV0Mjt33DA2akrRA6NTsVa/wTM'
                . 'YO7xKl0AXlOW2OVUUrttFNzo9+i5vb673jUTo8uFm2gT4Ci8r7clhOO4Ag+kKFq7vgeS0uPx0EhtMqojlcnz0NQqISx5v0hN'
                . 'xxrnNs/QuxLhR3RczNrgsJ4V3TxruGyWGbPlCvjpcMkKEu3MjjIM4BHhq0BKHK+0CjSxr7leScwt799WV9ZX6nI6XIE3+iOw'
                . '0vv3krnoGmX3APnAEETM9Ba0NOBUezbEkGI/yDWGxNBORD4eaq5KKzGiyXqgZhhwmTLYbfwolGVxxhF/Mm7fQuaX97ee3H5y'
                . '49oVvNsMi6Yq5lni2poieXQ85tvgrl6hqxEmHwBltDECJSPNsvtctVJ7L4BdjOCyQA6Nk9VrU5B1JRLW+RUuTZZHX1RT83on'
                . 'TBI5lqZ8rM9yI83znjzabcYB2iGa85IfcvdBjxJH8sFQGmFjZbR4FQQXDaUIsxCBvvulb2Lomir43FMJlaxCPZrzfCeH73rQ'
                . 'Zzz1DZ0uFrq8Krbl9oUnOb35CZComPb3BTGXloSGhtCI7s9NE88xdXU/7xBr4VI9vQRrvp376hlYiNaCjE7YxBnM0iFdmsV6'
                . 'chn+imKuxAz2nAJFZy5meRS4tmkolR0Le+hgXEJiruvrtjwYmDnOBNBPAY/JWwCNigWLyNwObSZgTjlemXsyiBUt5V7AFo4M'
                . 'vnJnTq9I48BzbF1lTnJCZIhthD3JwUI2ZtnCZFsv108RvUY2mZVQpDnTDuG5rlYemM/Nfhy6pirzzN15O0Rs33HIYt00F39b'
                . 'PWoGFcpxKfi2ekC4V2Ava1y6gCwpGbTID0ma1I5SrpWZ4F+hS2cHoEIUt5xHIawYQFHxjflc01jeEeB6p7AdQAc8kFygnRtA'
                . 'FaX0sEKf27F4GcZw6kaSvhnPZIz11KjHsUjF4CW4Iw4Xk1D2Pl0pBykb57ODrp32xr497o6LeRrbZtpn3jlui3U5+cg7S3eu'
                . 'k1CXwmunelY8swEAqkbTCksGkJWtOXesIpVkSARoYYdSbByapHwaFqARQnQv0amfIQpRbpRAF3mKBAzDLi5zZiuzVQJWFCNP'
                . 'AKYGHGirycGip9Y5yvcWUDZ9qDRGg0I/15tbNWZtdv3IO0D334txyWC/7Nf18YzG0/EKB37f50vvYkUBWwhpcSR8zE7kq39k'
                . 'YvdKFW3tIdU2fmZMDHNXBZHD9twzgM5gd/EMSrq1y09NXRzr2E4S9f+C04JRAzoapCWk41Hd40z+0PkyG3pnGJtXIIkyc+l+'
                . 'eDbWVF3Os9va37yK8QpxLJs2pbYgzLmSTa6dBb4BrKGyMIqc4znCqnLtQta0i5mW2GFjT1SoG9qRcHAed+xhgeh522y4DPHh'
                . 'OLEqLekE28KVuN5YxSLg3Ohom4qMSnYkBs3pyVC2h+RQNBG9FvUe1AwWXoq16U1aND7ZujvdNWUxTZHw1zcxZy5Q3iRVEy1x'
                . 'eQr4vzyTJAJ+OoP2nY4Ty8I9F9m22zIpSgfkSdEOhqVv36lm6goGLGhyiL+J+VjHeSLAnQIlxfEdloFt4xRuoco8kkYJ1fAf'
                . 'N+dUElNYn3lKwMdpmjVdJ5zXFSjIu7qp9vU+AuCMH4gu3X1KyxXxNsBWV/800lAzj7yV/D2AfUGqYc5tqcw7Mwn84MjNU994'
                . 'PPZvBrCbTes4hKw80cugsxgJTeeCE52edrYhfYM1yUsu5iZCTecuRQc+OdDUXH3T1jV13rVtfWgOboAb2oRvmHhqwmIffwb+'
                . 'ClHfFgP1qO2YZElH7+kyxWhpJiwk892Sl4HHBS8vmMD4DmRvUdZJluE9PlCcKYrQuMy+e1lejBd931nPpjZjXhZxhuvLe11H'
                . 'xwhFHAhjroRHgy1eBBPk1d0gT8BcslHOH+c8xCG0aqdzjeFJDdWEAatoZoO1uObcRADoGskgtd4EQxKGB5IIpEdHhSDczFEA'
                . '1Ipvqsfu+fnCpu8BcA07tRKbKHuWiwhKqhBH161RwkKAZtgdeJak8H2PASwFuSumEipNzTc5NWRJ4Nm1qWu8oh15odoYpXyb'
                . 'ToDgmOrttSXRaGqH0skZo3GonARDakhngE2cPbvBGLwjLZbPWK+/heDSqqlm04blmTq88eal6OMgCKwoaHtDlJE60dmrE7Ca'
                . 'HtPqgtkS3ZhQNuBscWkAwsoDkHw9NUNlY38ux8oSYLrzAvov+fMi+mVrCBaIYARp9YgmHnm+gzIawDJE7LLIODKFxoVi+aqk'
                . '1C1xYoF+dM3eEyc52J98I6t4aroMika9biR+UtPQmU6Zm+sFElO0ywfAMtpzyppUBGDGOJGVqlm2UTe+Hqg6Jg5Jffom2DxQ'
                . 'YCKCad9MyRw7SjZ6LiPCvpzfQoEGcHJbL5PxKjPIaJawO9bZqjVijCO625UIPZNIGZv3TprmTFEdcsOdOTeryujc2N7rUQk8'
                . '0B4z+tz5trF0hwD81jV/rzjNEmxQ08DdA9gLY++IA76l+a2Qm5MXrKVgSjGieIZIYlKhli2hkSOw6/7THKcukGQ7xMWJqiYa'
                . 'sH+bwQSTRWwpdpMc7fDf3IQTE6g9ith0HNSHR9NWMA0mPp69uGmncBKp/VSzPI3d45bNHF/md+ooD4w9pXFrZrY2L7K1q5dt'
                . 'SmxklhkdN0kplMC9h2y6EDv3SU/rRdf6Y/D1ISQ+3P3uCgJ8fpA9smissORn7KgCcGBLjbTW7lF4bQdVFs3zYREvrS8E1okZ'
                . 'rphdAjd14akXzDvYXQUlOkLGxnrteCsP3ZTvJ8dHh/1uu1kv+9hBWxwm97CnyWDUpc+lmC1gM4EJL/81Wd6ZBRwT5pryN2YN'
                . 'WAMHeW/E6/EcCM0P5m407EMgUU477SJyJFrsUBVQToaFy6xcDgNO4gEhQ5lZISg6ewFS0bgSMx/EA8WzMraLmq8DUz40dzaZ'
                . 'T6sp7Xc6XKXCCqZ3wQdHXIvWlbJ869uc5WZiJZk5hUzoSxLs1wNgpaVwkaVurSNaOlsQNELrDKZs/kzB8sXX3WqbzczaurcI'
                . 'kOxE3DxY7/nrNpTWBl/MIwfwhGUTSuLpLUNXxRADbvCehXKupEXMKvfUTjDBLsilht1AiqbausacOHQ3JrHbSrWmJD1jA7Wo'
                . 'HQle0771xjphln0yb8ymITjOc7eZHsuojDAqFZVqiXV2D5MyhZtTO5EjODfN+1kfeIYmeBxjLzMOL4sgl0IIOY7zlPUp+h2c'
                . 'MChwE++u3yC26lXXV2URj+u3aIuxHE09FcFbCPZ8X6fcVkPFtWtXeWepedq4s9hiVp645u1RV2VNDaZA7tO9Q3s6leslejfu'
                . 'VOsGHiL8BLZ076RV8lgXyvSOoalySMFxiUdkDxIqZxsirdyNe5zjhWkSl54wdUR4rWGdZcH+huWZyDIHOfimc4ZbPiuV8ihL'
                . '7CA4XLuObZm6BklbbAhD5+J82cSYRDWxjuGcbBaah8c83odlVNqmKvuIGf3drJQVi3TEuy+rsDaYxI6usWC2AhaD1UlfXc5n'
                . 'CG6flifjSVvPhvlgaHDgcFd7rgdKu4DZbp2wblPAYB7bVt6j1YBa8tlFCz7jpttVhCy4bazCR9yszSozXbDc/tQ+4rfGQ2yu'
                . 'Bg4kb2zX1D6v0GD7lxtY1P3Qyj+xX7MHt6aMnuElD5ZO7pkRm399NUBLfhyf4TMOlgzGzpF9OnCBVT0W8ZErF3eBdTRs3nlz'
                . '8Wea+r/7D0pDfBU51nSXarUXtOqB5BV6fdjgUuBiuqgk78G3XTYpAyJW0mvF6q7BBXg6nzPixpdwSN/geB+FvpvvuMpL9omm'
                . 'D4HYy/mL/v0ffzz7zAEoMe2hojD2wAgWYvA9E4sIQpLQsmZpwSarVy7Ukn0894sCmJ92LP6WFfiuLczQulYKJ5Eyf9F4cRLp'
                . '5847cNjERO3tBAGVH6B+Z6Wa234sRAAraHOie1wMYvQfDY/+6VR0iCvFIfeOMTT1ZI1kTRbv7Z9Yi1uEUv01/g+69Mx/gRTo'
                . 'b87C+TzbbxT/IxwASkG3QZnn5WKjpuvHJre5nxw/h42J7/PVH3O4wCcQHtbrYUDw+L0+W9vVVOLDyl3AuJQ+MMYLZoGwsLBR'
                . 'YLxLvzpjXO1ax74a+qtUZZFzkqIfhXV5Bsx2ev1QgTWUR2NUUiu8FHMrM86CxA8IR+WgPEsghS0yGihUaTcploI8ocNlob4p'
                . 'Xl7UJ4pi2CwmKKDMxYiwWGKkJTHlvmXbpgmC82u33aIrZkmTNqyANwaZWVxyQfpr/iHBa9CdtSHVwi17op04QzOFO4llhKwD'
                . '/AjS9FNrY2obUm4DTvf4JJ45lg53UgqZhYS6IgV7suy3YpaZ3Axl5vaY6LQqJVsTTfJRgjeOuawon/efe/namJY6njm4bsgE'
                . '2QMxsw3fIHkfK1uAH4hddGZKRceOVdp9FqpD/Sfcl11HZYbjf2KAk43rpIZRo9XruAKJzAICwLrOTiLE05EQHb1gcnLUNydS'
                . 'fT7K5E9iDUnGzv8Ap0Z55jriTb3ZSWnzEBDA5dUcS3zGuztEb7fS2BSSRr1gY8KNpirxFoBa0wj1knZ9Jaq+cWvoCtk95VJ+'
                . 'LiiIdtF4SpUU15ngVrEk9Opl2SM+5TCb7t6fedxVKFptglr4Q59/veOUqiALcw9AUYx7QIf0v5pH6Ec94MtGC1H+eZqDZqGk'
                . '3DRe/J8XLf7dunSNKPdGv1kIGiZq6wS6TIVr6yCVIbKsVYBZEBzA8XGJDQRHGhkHce5Rdomd4FxFdqxDG8JbCDFKd9MgD/K7'
                . 'Za1UtQXBiExPwI98GRMA5CZJ0gQ8X8BVaZC4rkGuInxhQLVFRq1ZdC+eKwaOim9KV0/pXkOKwApsTWHLIlPsfdZFBUits1yb'
                . 'WIqfj3KCMBL8s4agIlt2IjF17d46MVy9pTeZqyH7MUQzkqkhxJ5hFVqRXSt4WSKjIRhkR9DBziy98VcSYpu7iVqUVVaJgC3c'
                . '3CzB9c2mDxzh6ohEccUhmByfFvOCTu44VokO7LgflB0fateViC12QVI7BFyLtGa9a6K/MZPhgRQQkWjbBcToxbIwpaJD/SLR'
                . 'KFkUtVRZ+CM5CLSPJKhngSmytCPyloWBI4YpS6I+jAf3kfn+9f67B++uXz0+rJZdG49j81c+U/B4z7/TbOBaozjjtx4i22Ks'
                . 'yxHAtfIXiDZEPDhfvyTPBmW6Rv7LvWaCQiz3ZO/EGxy/U1zfLdok9j2lCmtICZITGYzoEYxibdz3aEWmUmu9duTXsD0GQyLP'
                . 'd5wXu1nogT9jcwV+oHaslqsNw45eOGCBVeDLNJ8+3r41Dm09m94uZb/iJ+KA/OQhMYsh67kUs/QTQp3a0XVM27ElTZJ1k2rN'
                . 'yTaQm2ersN8pvXpxFZ8e77fjUN2r7yWRWVplEJBUYR8f/VuJbMZqJfEgwaWvRbKJvMaUWeo45MZkoUWBpufzpUWauu9/pKub'
                . 'xbpfp13atU1d5dksFLJOCaDfXD8r+RwXXzFSZl3//xUK3LH4eUKYuuLwj7sC1VYFc/KttSHBYpMH0qOAtZdiCCvs4LCrqB/v'
                . 'tHZEb8QZ6yJScZYB0/ZIIfoToNaDhgGwNYCjjtPCV+f6XX3TzxWHcsdGTf+xqioqFHl/zoJ32wjfAtAC7RJCyjIhwgYKP3+T'
                . 'qkj6tF+0lNJ8zDcOZeCSio+eKpkgj28lNIVWMyd6oAllSF21DDYnwD5vejOp7xWA8XR3daTsPCGTKmVxLELYVlAWkjKkW/JJ'
                . 'o01nbUC+7uWzMlWQ3PYlrzE1vcxXjF2cCj62ESoZg1QJOhFuf+KQ0vl/ASYAcK/7sWaVXndQ6IQDES0gu2cVuZX4gLGTNIAm'
                . 'icb/p0D4qYmnLTPhlvwK7JR4RiCCixr0PHisx9evkffKImDtf3hzJuA8FpYhajFqy7zbGTNfWsrQNnf7fFVF4UIH2CbZQHIa'
                . 'ujB0lSaj8HbyLx22UqIH8bYng717tUBd4pKAR5HhLhoEwPLlat3PvmElUWD4MsP1vBukVJFqMQMY5W6RRTWxJGZayKMt+z5i'
                . 'n3wzPYpvebhGk0QcqE6LkA3IGnyTIJpoiIVS2LbKbgim+sxJrPA+b3QzmedmPo851QOrzJa/5oHo03gRv42IrYOZnZJ2xkJv'
                . 'kyZJy64ES4hgMV9wQkCNZSHIM5WWbURckFRBV9ytCDOsO7bjMkstU1U9W3xVHE/YxFXDteqa8gpswO+paoKOjxnZpt2a7t2m'
                . 'oNh1pcN3TuFrAVB0PHje859nBra5fPaVEU5Ztb+pg/L/+TkNeEZjkUvrnsdB9kKDBjttRLUluJdI8o/1SpQuNAOuIf/JutIm'
                . 'PlsXnzYjjexrrKLGnadAJy13XjkgvtAsguucM777XD+JrQIQUNRtO/guSUry/E9Xw4cBXz8+OAX4tsr3yr+8we97bK9CqDCA'
                . 'AgYJVN4XQv0P9gMJqW4mumvFhpvQhPfV6jLpYIM4UR/i6arTrFrSahQCan1e1S5DfyJCmm0hpPZQkdtLx8tbgluhqouPCtTW'
                . 'u++qL0KGJ3FiwtOTPWSP6h7JtfobiOsgs3o1dlSIO9YI3sSIYCCwqzu0F+pG3NkVHGotJ5hk9LCuTaRVWlbLQDaRlYvJyWQ9'
                . 'k/iBkkkj5h6JMKg9etW7kXj1nijWx5MaXVZIWmhUc68oWyGAgycIja8IHJY65IB+8kkkdZ00xxhU+H5sjAD36ul0hvIbVDJx'
                . 'zaL35gywGjwmE69QXVogBzjk+kOoHuIcYlHruFQ7z7oXX9PBu3oWjWJSmzgdIS2EyjFd7kZXmvpXO89NwigWroe53UtuvkGW'
                . 'EmMemoxFrnOJimcFMhxCzGFGX9udsOTA/0fVOs+3qnkOH7GJND2xZm3zRhJ5ZsH8yuf4BGInstwoC4F4TCUpA8LSxK6koyoH'
                . 'nEuMdIYXK3KZGK8xzQt6rxHnO+mTi4+tmeVoVnpQco1FqGBilLBeM13MyxlCiu/LJp4GeY6fxPWLpFwQdYV4GBPnMHLEMstT'
                . 'Dum0AdBKazayr5YS1FpBtfGz4pZ8vFpEWauZKMkN8vGFXkIn30yZUb61lKzS6Pu6muXI5ADcAjrANDAL3AH2gLmcZxGSQUgC'
                . '/Awp45BFBKkwKcCYsvJndBWi/a3KQrTFwMsFY3w/PwMOTRgAvoQ8zkIEIWZh6H7Pwrm6NIsg0jaLIoh8FpWQc+Tag+A+pZu3'
                . 'SAl3NvUSCsWyD1HJJlHy6/JIFCqWyUakIOH8pYuSLZ1SgRT5ZIoVsuavUJ40UdIVJ8bC4ttIdiRs2bLngaSrZ0hRSjnFLIUV'
                . 'ULbFylDR1bcdmFPcinxcSz72kRfnrSJE+kcM5MFv1faKCWIAt4vMkcMzhWqT7GlOtUOaKSvtSpNXbQ/nSKdYul/JniWnlywv'
                . 'qUA7U/ZkvVQqCQVPy2cjtcB4ug6xtHxnNsvejDlM+Eazib2fvi6w73owuCrVAQoHLWNKJI2ZEWLprrnhJnMWLFm55bY77o5p'
                . '+djtZLB3z32ZHlrukMMcvOM4wOUje+SxLE+4cefB01tepMuTy1Eg31aBghQKNipEkRjR2cNRHj7cU0plyoND6dCXvzymqTDd'
                . 'HPPMN9c2lY6Y4b2ZZonSIppclWo1FogRK068MSclhEAURIXN2miOoMnHt/zNrU8QGqIjBmIiFmIjDuIiNaQ+GsMbzeGP1miP'
                . 'zujOpNEb/RGMgR4TvvgaQ3gmDzlGI6RBjQFD+xB2ENruoiYcVDw+1GOsUxd/LGyJknnzdcnlsW//BThurw5nnEXBZIyhWaOF'
                . 'Wi1SJ8mweqfRYqLBmpji+uCjE0hGJlspxU5+EaHHbMRjPhZjOVZjbYkAi6kMeKbXNQaj/Wo6A90BVIjT3FbbXFZijg36g5zm'
                . 'UH113B/LH4JlsVjQUH9rF/brsnbHtrUGoHVZz40e91tsYKah/riNyKEt1I3zoq5QL/4pH3yJv5sHX7a1hphx/uxXbGWAnxgg'
                . 'cMA1Ai4QEBjgggOuOSAg4CIes5xW8niavI9Vgm5hBFwCY9S89P8TxFp/jT/gj7HukTtqnSuAYPZtd/mX+av9nXcjbEwk8s/G'
                . 'ZWcrBQK+uBr+8fJCngz115FUAnN+Sw2qunb5svXLloWa6yIY24oSGtramqpr2rAy8XVty37vDzY/DmA+AVwrCS8/gtCSJ6Kf'
                . 'Ym/Ki0eAtu4bwMYAi+O2Py0I4fLOceb8dSSYKHqnkzPX/WOeOz+35+BYLXBM8N0jHPU34ODiNRPU/OAP+/6Xv0CaJO44QYc0'
                . 'By4e0D9ilmWdpFdDE80Sxe5dtnswx60wk/BPca7FL/Sv4ZUt/ixj7nVK2ilINwAA',
            'family' => 'Be Vietnam Pro',
            'style' => 'normal',
            'weight' => '700',
            'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
        ],
        'cormorant-garamond-vietnamese-600-italic.woff2' => [
            'b64' => 'd09GMgABAAAAABroABAAAAAAeRAAABqFAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGkAbkQIcgQYGYD9TVEFUQACCfBEICoHC'
                . 'KIGVZguBcgABNgIkA4NSBCAFhkgHiVgMBxvEYBXs2COwcQDAnd2X4v8PCdwYEq+h1j3ColALS7em7BWFEEtR3aPO7SSq8rAr'
                . '2GArcWHD+9KOo8ZdYR6n/cuYPzMuB7eeY0NY7o6yIySZHZ62+e+9OzA4JI44EBEQA6a0UblhNUaDvbWLavd7nf3l+f/uffe5'
                . '8z5/DcWoFWvemlZKsJIEKMsTwYiVPv0Pmq99Uqclq3XdQn2n6AekCdh00SOM3R6dVVptyxQqOSVgOTrULTQF3qelER8v9iaS'
                . 'R2zeOkyv7tMTMUzv7RUnNUsL20J0M7uYJLhVTH8tpVcd/CPgpjS8a82c2/+tZdnuqa6hJHvpRT4kjyzMCnUke7oGuuaHe/Pe'
                . 'zs4yTZZoZtJdtcGbwKI6RAc4yTEqoOeBFCBLQHuuZM6tjJAnz5hzJO3JyMg18oQ9KCk3Zi8VRcKR1t887Rqzals2ZcvjXJSm'
                . 'zZAdnHQhfpgVQIiT10ezFIJAtaAWnTAQAoAEAAQEadEOgE6d6CCoAwSAowgN8KmZlAHAY3NuawAeuwNLPRAfPbY1AQ84EGpC'
                . 'nLwWfkocbtuagLLrI4Dk+LtrBPAAgOBolXYI9uSBAKCPskdeLdcYVZzQsUGOINh0wsB6hkDnzYOMGI8TaEPNBZ1Fi0C78wt0'
                . 'EPmAttdN0KbyB9G1sGnVpl0nugQKhg3yefqXekM4uLEu4cBV24O1YxgKlg2htkqLK8qvAZVWCRVVIeVXukpVl/KKpUUVtwhE'
                . 'R0Bl+A0/wTcX9RN8ttEH8Ba8As/52PgJeAjugduMDRDUAKSBq+BfcBlcAGcFkSlwXA89ZXUcHDyxA7bAOqWvhMUwF2bBFBgH'
                . 'IwYaRMQENY565VQxhyQRAWRLK9M5Qhz875sPXqGx8SfgHvjfDXqJKAwxd9lvYI830qIo+V2Sjm4CvRZcBFEfoiGGw8/rdRen'
                . 'UVvkQ+VJ/Vj58F9bNAnoHDjF1LuoE96LR5/BHkbzBViBTI6f3jC9cTUjAzUOUSCAyDIsAbUQK1etWIE6GgtTH6PVMEcEukEt'
                . 'QFZ1AyaB9WXYUqh/YwI0H7byYQEH7YLVBVqCVqGpUEOLhqAZ6ANa4XDI8Ao6GOQxMLRF8agA7UKNkC5IRzLRDgBRTg6R/WSd'
                . 'rJaVk6UAapo3WF9GO9FK1IIWSPuT+3PIrjvnAFzlzRYTLniDWVYHU8BfifTAweiWBx2gM1pHu0pYRyyR3EBWAbk2qYO8AILK'
                . 'wWrwOtdkrWVNBbFfX0klQ76ioj+F2YVvyHFkJvjfmuF/0G0anlzDk4K+Ai69TYL2AjBDnj5f+FhWvwFoM6zjw58A/wL6D9Zw'
                . 'oBmkbIIj69V9fb/RFbhTG37IMsKAz4Dehf8vdwkCAPGAxQc5gDsgHZjaQk8BQwGkAAoAPsisKALjdQ6IjyZoCzKkHQJ7rUYR'
                . 'noazFUA8FgLEQm+kJtTGm3kxaAXuAE7aWVB73ycuKY/1y3NmozU7gay+2zqN8SmKAx8TjnnjqrMhEKrJf1FOoKAPmbijn7Qp'
                . '0IgsIMzDP8ajKQFC3BAndC34axSxV7Q1qA8AbNG3NFaKABnMIljUioNQoN7/eDA0bBKIC3cYDyqO1PzQ+ItkJ0oUumjR7MSI'
                . 'QRcrEZQkiYNkKaAMA0BmZg5y5HCQqxBUpAiuRAlcmTK4KlUYqlUj1KjBVKsWAeGAIHrPKH4H2D8EkR221wvAeoMckCNyQMhP'
                . 'h4gdMnIZeUi/MLkw5AVeGUhjPBQ4t5om4whylYVZSWPo6KS9EfCOckWD5SPo9xAQexeAJ4AcGZZtqkG0WBs8byt3GATQTda1'
                . 'f5R81sJSzOAZeN2+DEgHAKL/PevXDEYAsse26P8+gM/HWW9Gw+uyNszHtBwFgFeWlAud7ta0QQYA3+ZrQmyOARcGwAsiqWOU'
                . 'C6AtYChERI8QZmpu2sngd55JZlkwyIkqtVmedQ1iOOaAuU0+aOsQnAdBdrxSkzx2bQNYw+xBneWhIin9tNeRv6+/f7+EA9w9'
                . 'CIJrFpOQklFQAgAARemfIwBq/nAxWNK5sxDK4apEi3a+oviJphPKIJxRBJNIemH6CeAjiEYwrRDeAjlKwpCMkIImlp149hI4'
                . 'SEQXx0kaplR8ZgLZ2DJwZSH1xzMARyZKLrF8zgpIFBLJ46KIVDGZUgrl3FRQqiRXxlM1D1W81FCpBcHOGAAAdAEAgHcAwEtA'
                . 'dgV4vgAAAADYAADAEYLDYPBrhCGUtC7EJSK5UDSxRSIkolitJ+VE+kpgvbDBJSz46u9rGfhwuQMDxwm6I04nFnbMHXMRaR7a'
                . 'rHppeZQm/ElDoQGTNC/XWGvNenWBJhNe0VBowCTJNRrkgslKwyuuSpJIwNblqdlqtyBXZY3kMddMRgBgPhSGvQa80KTQgHXR'
                . 'utI0AgDzoTDsNeCFJoUGLJ1KteDGYwP3Gg5eLjbacASMNOpVqx7JDfd4KDSAJLlGjVxUZI3guAt1UXPcpaZcFLIolT2tQzJJ'
                . 'd4RDk5m1hyib0YT4/Pv3iZG9lFa4v/Cyx2hBT21kDEJGGdC7Jx/U+b6R3gK9PkIbOdqduzxzjigNMFO+EiP62rabkqHFxmID'
                . '/XdLtVWm7qnt+ZHr4xjyQLZ7ubFuxtv2jpGmgabIMpV4HDDvLdI1BvDAAMB+nBiR9mEODQ5yqBEik4AybOicikocmpc1k+/y'
                . 'MNf6ZCyk2CWAg0Ckt43KmDGloXQOODScVL0O0OSiynDEynsR3Qyn122VQ41WQvVawAh2jhjzE2hS5+QqO56bNPgp71RVHy9h'
                . 'eakGFIKjkqHHZGXAytxaMEzVPC0VtYbTbQC3hLRfhMINYobWzasm8W+/uWjPGUNnaRy3Le42c0iPTM7jDwHcktCL0iLhKCsp'
                . 'm49YCyTCINJoeszJuS7vtm4NzpQxMt882sfbn+3PTMiF8jWCgGemzEtjZ9Kwnm+N0bFBstj/mPeoVFLYLeTx0lFTYZcGDY0g'
                . 'VfKtr9qUURnJD5kqT/Qxu8y+/1SDhmffN9hNiM0mQd3vXNgmxgw/Y6yKmD117bS2E946bfjC/kE342PsGhkYaIpQOWW+jZrt'
                . 'c1p5tSSb/tBQdCS48t5Zs4SosBM6h0mxZACD6hpmPSXzmowhGXowYrtapbcwp4r4506RIhok8/g4yAOnsilTqXLd/TKQfJ41'
                . 'nphO3G1rpczpPj68Xy/eJTEl3nLjTYv3cxJT481CpiegXgQzRasr27SvMaWrPrkgfzC9/Yl7hkNtvKKIq8xomMpOivtaYH04'
                . 'QrFcerVgXCXOCAdXVy+apO3tR1smjvBFtfrMlcDqvDLM4h3K/Gg3nPZ9HLJnC8d41oJuilVGTueskeNjWNP83dVA8leGRBi+'
                . 'nhPsLBFCjTBmYHWjxFimpU0R42erGyAOzO4uG1d8G9rhNKGsO2c1CE2W0KzQkJ9MHugof0Asq4SaB7jtWLel9Xdbp6Aco5Qq'
                . 'eGuO8blernGbVud7oDlykmgjZnCRH78b3Dzeos1O24seXJn9EMKbynYc4QIJ7tCUni7prL8lTY/JoiLe3UwWVvgP3Fps4a/E'
                . 'xrLxnGQeOFYyHUcI4KGQ2TTuExrtJShaqdgbSiPgLcb43CQ3bwv3SCgrwFzr5QFPNyKvLJt6YQntloQc22PRyxjfX3A/buEq'
                . 'hVowIpkPdtrw0se1V5VM2R8bpMqIFsfm2WrSYsXsWxUHuTbsiPFksWlZWXiMidF4rA5Bt1GjQYXFwysLprZs1Y6kaEzLvO14'
                . '/KbBTlMrPUchGxJC/TVGzNlGRzeREec0HBaQq4195hZyjsmj94KeCWNGA7YkJLNhV5iZ4iTrmi1gYbG20ewbKquJsNWgnlA7'
                . 'smEPIemhG7bNQB72cesP4YiEeCtmrDxWNv2BppD4XtiywKzt7ryN1WysoriOfzm6JlWL+R5weigcXYMuHXVWQRdgI6R5CTFJ'
                . 'pY6vGwAXSZVP7bMvQMameOI4/OESqeP46B4f/0QmDGJ+ypw4YNubUnG6XCynjVAc9iswFs/yGiSY5EkqGuVzn48c8tlNC6JZ'
                . 'x9GM1AG0mF1hJQ4VI2qNgGXFxWMuJR/GCOb3lsMqc1SrMzT7+8ZeAeYcZW6FTDUiqmd/OP/daWdJ9genCStHTmdnxyJarZm5'
                . '905Fi9vrUDRfqrrezKXq9QJsyEtHq/mmS/iwKVe/ih4Qs1GOHOSkxyTR+DNvpJoS0iY2H7prpeEZ8/PYcQHAtsXl1vGZe1l4'
                . '9v6J108OuAzymQsDe3Y6ZlZGQazGJXNWZnichl01h2yZ45Q+rHBq8cTZZEv+XGm8dFgBM10aN5td7sKI6HTPZ8SGZMzOCO4f'
                . 'YurJkpa/nxMZwojJT5pTEmIOTZ9VEhgbAhQ4gYsT/NwiqM/kfWRRJi1d5DwRIzK6Sj+Knj88qRq7M7fc/HZoYnSsB07QBB+l'
                . 'LtHXDM93itp/aRpXrQ9U3PUdRl5UhVTa53rnS+WJhrO+zW4X1cmV/tk++E+0sUOgBU0+EChg1Z8SCCad5lRxbqUKeZNWCsVh'
                . 'IEKFSSQYHSMILMOHiqO5yiKFGIG5XH+dknlmYKIKEUhYLpMcEpf0pAaMY+USiXFdFnOHTwQnsjkqdRirYJ5dg0dusilETVjS'
                . '91e3A/KMtpLrtx2B/+0zxp5SWJs0WC/yD44wqFy2nUjKOF7j7+lvKk18ze3lDOKkZmiY+D1sHTY4V71UtaLf/rH4Ojyt1eBf'
                . 'smYBsHBaGkwMZXoDJ1ue7x1L6hMUeTMoty+hYdZ7Se6Ub8PyA9QOsWAHRS0Q+GIPa9gWeQfEea5t7UyKltgU5pVZLVnPT35r'
                . 'xdB6bOzTDpvX6SQS7F9qF5mUQVuu18fLRUIm1SXXjLtcRYDp9LVtnqnFdZrNqaASTd4fyGcNOiUQDDnFsXBvhVDkpJWUKFSj'
                . '4shft5MyBS1DvjN+n4YQsuywkfJqPTGY5+ptR7uYtY5u1oMboQUt3uC8GcLBfUMpctJO4qntjr8O8QUlBOVSRTm/D/JN6ZSs'
                . 'blSQKZeWhR2mTogFJ+Yj0JgAgb0TZnn4ZQsemQjXIfee37dHK9t7cuRemaS6lqFdafI6YIGLNyTyWINOCfmTTutdSkETv++L'
                . 'GP0bstUzWXmM2PD2omybNiy7KbvfdHYekRTTWdLf6uOQYushbUScwxB1WVKAIdU6krQyoh2GeFUkBirHhwgpkZ+SwcyLIjty'
                . 'iGqFK2ICR4La3XqCgHQElbu5y0V5cldxKpfg9SsRLldYp0uPm2ctXo8m4xRBtMIRr3jMEAPP5HL2UK3nqKBVzBtnpjpXiCYN'
                . '0/h7cIR9nIzwOAVuK5hytC5I1hVptNMLi5JHRLGr3YlSMh8HD8EoWsFKxnjUuEeyRhSmeirsIo01OnVhbq+P197Xtz32H3vL'
                . 'iUmkzM0/KJjS+uqm5VqPFO2PHroHxkCCC87UBQ7OwJREj1HFiI4vrTdGRRX6elYwooMzB8WpcWdI2aRuPXyMwKS/+lKkD7c5'
                . 'oTo5vbaCmf1r9som/8cft86cnf6Q6epdlPjpPjuhtG7P0QWHJeyRKmbwEYG8iZlFJnXfPM6wVU1lEoqbMiSm9SzBe49Eckvi'
                . '5d78p9lEY3VBshqPQFbBVKVUPoxAoikpNo/ziawioWgsdW3jQxs7LLD7Ihjp7Kp/gQVOPrAeZAw+DcL03wRSbbi+57/uYFyx'
                . 'xuHN8o+jzytxAhfaZPxe8nljrlEGU/6v+1wmYoZ4PjLbq14PTNRjBEZNV7hNtzrZnD5xhDiBkw/40gjEwxm4Qjemrq+54X1u'
                . '0QJh0Fs21QPN7I35Zcdu2q9QjNv8U9GlogY/LQa2wVo9Qpow6gaWDqPfXvPZ1Z+25/plaMenZ9cp+9WqCG9a3EE+pgoVkf43'
                . 'TZdhRrQetTNrqzqyoeyNAkztI+IF3FReLGhwNusTFLkr7PNGd9k7TiQpvTviRLOnj6wdQkuKZz4yy5p5/aSDDWHLyLlKzoif'
                . 'DF4FSbW7jv+mCFGNTNv1OFsCpxNOGt/BsXAtqROr/tb+SZGmrTcbLnWb4PZJawpJi74mQNZk3ZzQa2tdKDE8eXuAsrLRcpIP'
                . 'fs22hfm6vjVQwaugBC1rcAXoHkbitmAKu5I7ef8CUz4kGai8SQsF0AInrHqz5yTGR0cmIc/oqPCrTyvI81eRQ/aJiu8Hz+T2'
                . 'wsDgHoHjJY6iU/GoRuwS2kpN1InSU1vNYcwIkf9dWULJwAOycwd8sudOKF39p81vWhwqIhNOmrjjqyuXqvRAI6tRwMwxBipX'
                . 'H+8W1cywVDPnTZdS8D33nEwtdR+iG9WLer8Qilt7wnQYZcTrL1LWL50vAgTynAPd72g5IpdhY6fvzHlm9i+1VLufnCXTikl9'
                . 'EqOGAAs8zkN2lAjwZrUlz3HJFtZxfYJb7gxK+SUs1HYv0Ug+jNMeLhJ/Q8ug96CCIdyZ0PaNx74tN3MwJXpcj8yyloXG0x2R'
                . 'y7ExEO5PtMPmG/md/StIKnMh8OWRshydgxdBdcs0481id19WbtrIajTlHT5xDHGdEklb3FFvzNpeNA35jXh9B6Cw7PCZ7GxO'
                . 'BE0vofBLGevBvg+4AHd1/wPb+sQ7Umxz/MpSks391DJXrsbskhTXlh7l5NLoHeOSGDRolTwipdDJ7BCx81iQa5/17vegFX/o'
                . 'g3XT4yM7obU1ZbYD4zDbbazxUy5YzAj4xs2bblGlvE5XajzrVV5vUpRFIr4kwbHcMDg+hxTPk8TWjXIawFXxXUo51ZysVInx'
                . 'SlpN5ghSEnPZV0XtonaIBDspaqXARDMutXzZByPm9+X4dFc3dekKyszGlpPMIcX7SuoP2ras1u/PQBcvE1FMYK+mHF3fiTEn'
                . 'P8yfkSI8fFNnnL2cOTQP4DLkYa79eCPZVXLZrvqX45rJj4J3aP824LBo4leizl4qneF1dqbY8ZPS2OUWOMpZ6jxJdS1ltiPj'
                . 'ENvYafyUowTH11Lm785m7EowmXdHVIrruXmH7KIUme/3f8EDgVxODSUYPn+JvrmFpCBeTGWARUzfb7yiv1eGF+aZTP4R/rzC'
                . 'bYzI2KJ8zTY3v8S13TCdjIwVy/1iiZiBKCMsVrDXvgLetaleeyVdk8oSNYU4NzUmvX8y2QPyYSoV2J1Mkjh0n8rYaV+tc4ra'
                . 'nPYGbfr8HynBnbIJUW24G6p1GgGQPS4lIbgTeYk7zu6VQm6zyxTBTDkZ6Nv9vsMh/kOOERJgKbPE6DhmmnP/A6cd4oRc7kgv'
                . '6oXdaAUn8y121E2kRE3ZpTx9yB1e3IsG+f7Erfg4nP0WjbgamNupm7XxDLiYdrGbbeFsySV+wAjViQuphocT/VjMaMX61FGO'
                . 'jM2h5Wv6OV4iKdwkXdILKGlEasAcbsM7sj12TV7xbMyFMdjoqe/H3YTwRYled0aKHD8p3cYZVW1Hd2Uk/HFrLbw7NSQ8EtD4'
                . 'sN3p3vB1JQ132vwXW25jUzJ+wKPTH4ggclEVzJZ3H63gZJi3oMpSRu5ah0SFf6/5D/1+0qx4F856i6Zf0ezPJIlcDoB5GgRE'
                . 'JTfP3z/mbSEz4G/oRVfvO6cvVcSGnw3WhJAe1uHND44oKg5KCC8KKPXKjB8++P8XHN4/QqpFJpeV+9sS2jMas7KT9Qvn0R4E'
                . 'fvRlDp9K9mLuN50VhkhZKzEbN8euSRkiSC5mvQ+CMkeEh/rav16dRCCqr/VtKHzQkSBgnTUgKKvAyd5vi26wbkykDqdPIOPn'
                . '9C9ysq+5qq+PzB6ESbwRiUkk2KEob2PSotGBcQlymSTII/jPGE+JKuLvkaudXUunFXgqZoKmDy/AxSQEBrilHEfWL8YRL0zC'
                . 'wHRYPwm28sKf60Yk9rbwrfoG2cToyC489+eooVP1+XJ1uFsYaV61wJAVY0wQyqTGdGnyjl2ePpEpTmaW2b1Y5R7h01c9/SNW'
                . 'CXouCbdTgu1C4XiBYajBIJhH6bQGsPRGkjI7rkUeN7n1mPJA4LWTLK/hkOu0iAjaT7pORwc7r2wIU52ew8wrn3REn2C4S1+i'
                . 'fBTUb5TQrIm+1wceAgAX9frDdm9XQt4Ahj0DALhb+U8BAHj45xNV/T+tX20MhA619f8UtEL0sEZa6lPZdehLW3MArIJ+o3RQ'
                . 't3EoJmD3TBzegK0ptO3qpe5sQ0BgNb4YID1kCQM22zEO6k3YKUV28RWwHfwBm9lIfG1nHwXYavt7BRvxAgC0kQlePCDwCZnz'
                . 'mADIUKuegdHEPlEgALo0cbSE5I/XxNQLAwcHAMAfhTjrQ1HZtz5CZNP6mNIMWB+XEXx9GrEX69MlWba+Ez9167PpBPXnkJIU'
                . 'Q4xEB4mRhU1Z5ScbgP4sGtWK0qxBpRwWtarVaBOIyCTObb1Mg1oVoqM2jeVImab5yTLxyng6g6Z2+SmYekkrN96iVRAtbQ6o'
                . 'ZS1Utchtw9b5tNWDBr2Caqqk1/HX5fr0O/FazWaJtqptHStjoqG7V69+PFWQzU88/sRgadJdc4kVzOI5vEcA0fpYtZtNEAxj'
                . 'oL45HRBLltLJaAZa2a+ORUU0Uru2dUHaqsleqtXeTGxXTrN24WaNtKKV3aAyWuty4ywBWkWS0sWqIKDvSzraRQxe/+x2Aacr'
                . 'UZIVVdMN07Id1wMhGEExnCApmmF5fIFQJJZIZXKFUsWpNVqd3mA0mS1Wm93hdLk9Xp8fghEU9OEEIolModLoDCaLzeHy+AKh'
                . 'SCyRyuQKpUqt0er0BqPJbAnN/Da7w+lye7w+v0gE5XBI2ML475MQQQknbgHuR58/L6/P+fcJhedBfhwV479PGl2sZHmFCWaK'
                . 'yHIGg+KzPEQN2qTxf7I4kAIbzLAIKsV+9tje/8iDPxEF8mJb7JVYHAnDYhXVPsXIjCYviWLI9bBqRKcdPnIS6PIf9hf8ZK4q'
                . 'xhoET/8PL9CFMRBLWl3PKRdxMafYAbi8S7mMC1xEYiZ67IRS5KRKltOYYq5IJ+CANbbYYRpEUVKx14Ap5orMchY7JWR5t5pF'
                . 'n8aAKbaYY4k1DgdM7Ywd5jjghCmWB9TtMbaYYofDgRIaH0iiOdaTbMMftf7zSKBwqgWAtF7y33vxPlr+vH1uNCemK0H3sSnd'
                . 'x+fxqBryu4DoomLpI+fvx6csPnrxJY9+6YF+l7/WOFz+0N4GNk73Kn2ju1uTAQAA',
            'family' => 'Cormorant Garamond',
            'style' => 'italic',
            'weight' => '600',
            'range' => 'U+0102-0103, U+0110-0111, U+0128-0129, U+0168-0169, U+01A0-01A1, U+01AF-01B0, U+0300-0301, U+0303-0304, U+0308-0309, U+0323, U+0329, U+1EA0-1EF9, U+20AB',
        ],
        'cormorant-garamond-latin-600-italic.woff2' => [
            'b64' => 'd09GMgABAAAAAFzoABAAAAABNqQAAFyGAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGoQGG70aHJJIBmA/U1RBVEAAhTwRCAqD'
                . '43SC8TMLhW4AATYCJAOLWAQgBYZIB5M/DAcbXvkV7NiLuB0gKNK9LcwO1G4HCSqip0ckJa1JSP7/lMANGac39FYJh7CpNGfp'
                . 'nlFanV5KKc55oir01qg+i55jgh9fuU3GvOLyz+4smL6SG5fCv/ILg3Bgs8TAx+7WODZ8zzI7xp6BbSN/kpOXiFqrsnoO9vYB'
                . 'UDEoIIUeyZH9eImgkIxH4Vl9vAXuAX5t/qs7Lt5VclHAHXl3VN0dl9wd3B0RKQhSEipYGCgtNmL2tGf2nHPTbeqcc8650s3/'
                . '55SquufL6n0g/oHlCC9iqKr18KvsOQgePwPh8Q5hFNqyQ9v+3epfFQt9oWylzluBCvUOfWJwyOMQe2Jy0tfg/uHf/X5q2bBB'
                . 'HzGMAlrJ4zuQLoHSxVg6vd4uC0/mtOw7ltqfDKnUlWccbg+YLauJOpflA+Bv+0VMCpj0WpchUFDjS50VIAWovHuqgENQAUDp'
                . 'IUWWQZuR1gd19wfzGmJeXU/M8yN9JS49iXtz+n3AzQLFi0SSpcRFePcFsq8zV1maRkrkXxfY/UB0KvjKVG1vEcR/QKAAUiGH'
                . 'FnqFkDpXHhcV8c/0APOD6emUQEWQjqQSCOFPys93SLF0pQAqhlQ5pFx53NGd3DFULF2qKNXUsiu1Lit1cufSVlO5qTyuXCb4'
                . '/35pSfX/UZjpv7v2ddQYWQ4RNWCSQ0IuIxeP2ExhQvcbTWqn3nZGG5JGzkEzaI1tAkKGu3KEIQW8yBFAQ0MDSPx/qpbtDKFA'
                . 'bUja2J1jLhpFOqWuMPAH1AAzIITA1ZKglC8obOBKF3SSA0AQGJDKjlqHHBUcQqh8bgrX2zmGys9VacqV3jWVm9JFUV55befS'
                . '8MGvrbpiFWLaGmHefFSqaYhwGgnxQjt/LPPLQ9R2j/UbYhCZ3Oe2bOveXmtoya725jHkeshjFmLMityIdyi7vrYff6pZCbQ9'
                . 'p+vLG6QHCBBooolZPQvZlIMOapmDx5CKylc2mYASFaAvIJ8Lw02Gjw4B/vbSN244bZ9tJk0Y1G+ODtNVh1KLeRhB8TNkArxw'
                . 'LmNNgAXmEwUCgH4J9g4ICY1XrBxU9Gr8p3ijCV2Z7Z9OUf4VUF2BKILwkDPQ7hIHzZmijfkU+B6HgUCLA8gFhEX+pmr+FbH8'
                . 'gUcohAAQAUpn2r8yJMsuYBdsID/vEiU3Shx02x2gB3HQ174BehIH/eA50KsC9GvTyVvEQf94D/RxKxgIhBthQIxwIF7+AhQs'
                . 'RDpJSpYsB4dSpMjFrTRpb69MbTAQBDAaoDcIpGytDsgB3WEbYGoCf4g2HZGH2Hi6Poe+7nyXXujrXknd0NMhHUXoq1jI28Vs'
                . 'unBvIatcKkXo+4/fjaEPh90yhu0ZE6DA8io4OQSGwFjh+Ou0+sX84fuj9s5N0WK16LCoQAQgsCehBGDr71BYgXxJ8DGRgQ5s'
                . 'RaDbWxPoVB8CnV9QoGN7FOjQIgNhU6ZCpSrVarlLQL33rt93Tfae7GmpkBwh69cIHLB15fiBrTmhGHWqlimODA54477jJi3T'
                . 'Z7pCDjGUjwaJ3AaWLn3jbDczWcqanoVcGt1Dw2f0FsDwV6JW8N5ZSiDBPTC4Iwmu7y9mOYq27AhNFB9az6DmfcvnoQww1rI2'
                . 'atJCIa29VKan+zJ9Ek347oGAE/zohVaIGr2f9c+ikKJmfLJRzQ6n3n5rTmi/7IRhI39sEFy0M7YYVWTAkVsAJvFN60L6mmt5'
                . '2ryY283zsDZn8X838oAOqTdALQEHVwKubDWgvF17tIDXHKKr0ofNuzf0KENDsassh5J7jL7kCQqZQ4Pn4ugcybwdHewP6/zV'
                . 'qPvvrPxIWIQt3h277Umfo0AGVO8c/LZNdlqLO9/4Y2PwW/zNc7PosOZouP6r/Iq/XLB0GEz5rJ/hi4OLaKg//0+Riz4uEjoS'
                . 'FAk2OUD0+/++396be3nP7vE9uDu09dpduNPt4NE7cLtuC95q8lbd6C27/uu77mtzrx6/6Vd75fJuusLLPiH3nKM+IDTMp7sY'
                . 'hV1wokQPTi47wbGP6ghVavRTx9Qh0Ef3vvHnXu/FNU/2zbNTfJvdP+VucS3vSn0753Zyn23fdmzT1m1iw1u6zIRjF2zOOvfX'
                . 'soZVr3T5y6z2tc215BmXsKjzTLvA+U4y/pgjQzgIAj/42+9eeU72Kv4k33nonhsuOeO4Q/bY5tI37x6b2lwmStOU97lv2kjB'
                . 'O6IVS9ucIVwFXZr96XKZ79Czq7vO/ZSBxncknXzXGZsfJ0xuPB2DLFifbslGoUXuhNxAzAvrKOQH8ydAyK+sAVq8JiVtRRoU'
                . 'Re6EBPrcKUfrtDeQ/gMh7cbZHOwTxlFPzBx33OAvfvevs7II+C19tcmsRLO9ZCGkuFMcIaSJo/ONJtxpYcrJrbdNAvzSLIjX'
                . 'g3psa9YA4CWwPU89GFKj4AXBtpReaPUJ8rbzhX7c7oOHjl2SNsnu0nT8ZLY3AmUmG60MLA9o4GfabICd//z2LKscJUTaiCMf'
                . 'D97025/lGtovto6F5TaYZQ3LOMlVfzzzDGx+au9a8y9Wksqbee1sbcR+EtOThJAcUtnkC7MS69zcAsKf2QyY1ix9TmMHe9jL'
                . 'fg5wiMMc4SjHOcEpTnOGs5x3F7fqu1wSuMo1rnODm3Z7VkqWV7fMLXCz3BQ3eftYoAal2P5bTrCT6axyZx9yxpu16nqz5wIn'
                . 'm6ni1OM/NphHhnEl6JzQh8v+e7/v1Z677/Rw9yo3qpfO83DL0Jm85fgObY942m3b1Na4k49vcIs3b3DW8fdhz+1za5rldbjX'
                . '8P3ymk9Yrx4alSte7vnTl3L5rDMsbhFT4y/FRJVLNe7p0/dEISwEgL9dL+utN156Bqd+7IE7ru15YfVLmRrvy7NFzN9pRx2w'
                . 'awD40CS821VGLdO/Pfr29+GgbboDDQ/FpqKDz5fd9icorCBbLGoYfHgXbAzQ4VZ+zbYD+Dw9CFE4wUkO081lGtjHLr89KzOB'
                . 'PfYUKGI3OTSS23/I4uqt3OnMfe0BsJpTfPA/hCRbewqJdx4I6QFnTOBvvrI3wHNLBvOJiCYV0AcGeb4vJMZnRe6GyGBZGMbv'
                . 'FDvKDQXDLlzZYIVdSpwv2u6Sdrijnb7RLs+12xvt8d722gL7dCD88MF0DB0yAY5wlGMc10lgowtI0TsxKyC49bsfyD4IIHdl'
                . 'xxIAFl4goC57yGos8tBNnvP0XeJw3UInt9XuIVDrwiMsnYUHw/oLw4EXfkA3PPAwEMSDBJAIMrFweRMSEZOS8+FHSUVNQytU'
                . 'mHARIkWJFiNWPCMTK5sCA98KglwpwroqnGvCuiGsP6BpfDwiANbbIiRsnDDhwgmpbAdnXWw4LiXV5OFsSBRnw8THCRZkB2dP'
                . 'FSLLDsnF8EB40cHBDoQXn5c0J3CBlatkHQ4BBQ346G4I8UCIhwdjA2YARpbBgobhePPOCwySYrAIICBgg+sbVNmgag6S8o8B'
                . 'B+7b8Wd+KKgnOwTQe+2vuhSkAIb64tYLIZwMycD7jN2tB4iqmAjsUR/EN6JCRdm3q5Z3eYDCc6hQOQfYoaStXoxE5tzbPWOy'
                . 'TkBTyFax/rz6ela4rK5GgBDfw+oN7uLmwFv2IccRiSeZw25hNUe8fao4RGW2xCqJVQPuG9crpp9LFi1xQlEbkHjR51xd5uAT'
                . 'w6wRQLYDHjqU+GQs61UKdYQ9ZNvgkEQhu++gYASkF7o8JCy9z96WZi+WG5kE6AW+4C7fRbWDNc5mENxMWnJ+FrOoVdB6WU0+'
                . 'w7OHseeKI8xAK9QqtH1n41PGYRRlxORVvnCv7548TcgKhDxtQLcE0GYiBgFNRKwK64X0aonuEMIs0lgKgCiygQUrwgDqyIlO'
                . 'DaPQ9QtPbCDZbngFRcNWwW9DZidCUYle25uly41ogw+uIQH8dCz1ZxivL9FZopNUw5upveobJ+GFMJLofGzYUh1lpcPsWl8o'
                . 'wkElhAujHVeXxn9YenojI52XTmTE301VqY3tgq5KGacAztfBgy14MGqJY/AIYfn9togCaaBChaOLZMRiYSWXrJSPctMkaNHC'
                . 'ql0Hmx7j7FaaVGHKJtNssdV02+3QbLd9Whx0UIfDDpvpqKM6nXRG11zLHJs7QzLfLSjELZxoWWxLJsnS0ja5gSK2wenR1sys'
                . 'rGX9NRtqKbGmQDKB1xNAvwowBUKLQ/xoQcIFiRBD5FtRRYkHS5CITrfEOfQMSIzCsAhiFcwehzh4YKT3dgpzqizEsbIV8lIk'
                . 'rGKhSkRUKlCZsMoFqogTVaoFqhNFvXCmCdUoRIu8tccFOnVD9QjRK8SCONFCy4CWi2hQoCERDUtiRKhRYY0JMS6WlXHEepNo'
                . 'NggxFRfaaBOmzUJskZetAm0XaNewwG4nUC6ZD+OccQ7lBAY+YvjGHzN4Y5+6wIMNGTxs2PAgQ4YNGzxk8IjAgzeyFmpch3dw'
                . 'R/mATrqVj8u7rKYSEGKuggB04F1DCYQJB2okoECABj8nSqJRb6UA5gTJEJe9D+olSYMY0gXKEEWmsLIEKxJJsRAlIik1FIAg'
                . 'gSKEY1ilmGRVOq9iPqw6QIBAgBypGIHoAhkseMA9RSA2ugpAt0J22xOSvUVC+1LsGg8QsCWkDXGcioud9jm6MyZURAXX1UJ3'
                . '1Y/KHbgK7hG7Zhw4yOCaceNFQCgEIOi3Lvzk14S4JcLC8CcbovmxXx2vLYUCxNLTeD+BggQLoaZRzE4Lu/TLAvPyywDLgWTy'
                . 'k/xBpRvigGIe2J0+RQoXFxwlKkvUvvTbYaas2nh9rkkCEpL06RLg9yRPU39gzUipn7Trc+tTKQ5jvd/lsK80pNPy9HSPtJgd'
                . '7Cq6BtggAxSA+jDoGSOdMfSMEc8Y4YzhzxjujHmVsbP79WszQi/8k/SykvoW4GFZ2bTpwK3yhsZpwJV/qPFdAefqa6pKgJP1'
                . 'Da3TgM/qG8vqgX0RO9jEOiYYZikLkjnzNzcDndNZVlpaXO8SJEwYRgFweEGEABY8GFAQ6HCgOao1iUHw+iyAi72HmYWJwCgx'
                . 'zMd/CXmAHEJWIdlIFMJFAPhPuA/WQa+hYTACVALaC+Yx4DkAA/coe8FvjRBgoyL4HGST06SNTmnKyW+rHt0ao5aap1uLeuXy'
                . 'ZXJJZpQgilYg3/3TfMJRaMQRdfpM7Q5rmjOqs1vFTqjL8a0NDoG1YsLBW5L2Eefauxxo77S/vd3Z9gb72qfZ215nT654n5dn'
                . '1sMZPHLK0SP14Xpg4xPJT9z7er1//eG5F2iQKYqoy+cTZRMlwNCeD6UZQzuLOLIZgFmqhM7AIMiAPSkvm/Wd71yCBFqydPXZ'
                . 'KT8LulpzClXVVd8pgoquqk855XSVcsohq6uwUzFWd8UfirLSUvu6rUNPzt1j+ko/euEnL/3slV+83oP6Th557Fvf+d4TTz1L'
                . 'TmHY3xkEcdFDQAZjxoyNHcvQ67HZYVfrLrnrni/c96UHvvLQRZdcdsVV11x3w0234gGOhRDY114D6rkxlwDvs1WgwCXsNh6e'
                . 'znL8oltwoRsTNqdGXFN6vnz0OL74yMDHCQ6AI+1ms5cO6SVskDB0NSyyGMZFhQu0DF0OpiQwLF/AJVdccwNYczOTMsOxE60B'
                . 'ivQL4/QYWB5SCIowcGkBpbX7TBcsJ5wuAFGHvLyLLAEQMFjbJy8Jkn27Cyzt9mZsDmwauhsZwibZTrtu83h6Bp4G7aRhASIT'
                . '8+mHGU8q/LasWjMdRrEEitHzK1dptsTqKvoiL+BoQ97MmAFD7xMUPRwBwhS49JvdiS7abahP/+SgQ8tUGyS1gvy3wfhHiQS7'
                . 'D5454zO7TJmwzDy1XIIpgBCrQKk0XFuUSxY3qmqhyutfrPjlFpuH0tc6lznMYnanBtM7Sa2pVlnLrsHrRYvCnwCUrSp8kLcq'
                . 'kCKuSWrMCnE8BQGDiIyfKLv5+xUaNOrRa4GV6tyqgMEvUDs8izO5+/ykc9pqyTPv4C5Izi+7ew1QTrVHvXP2ly+hwrReepg7'
                . 'Abd34gDXSv23bRN6P/f/iq/5/ykAdKzjHSAAcnJNAcaDgbosAIOj0uS7Hf9335EQAeCvkGeBN2lDqCmQmeVRPyjbFFrD0SF0'
                . 'YiFlW7GabqI5kAF9hIg2yyGfUI20ndylfbPHB8IIjIfl/0CSH5LfJH9L+BKRRCbxlYRKYiTG0O1l0qF+ZKB/fP8X9CGhFmPS'
                . 'Z2l4doLrRABuYNyxf0m4EsEFaiXRm5HBpX/1coYn9/H/cWONVp4p77r6fZ7+ePrL07/hXb//9+fpPg3+08/ane9vf28cCQCv'
                . '3PKjt3gR+UK5aXLv3ICndMEofOvpjyeGhEOxeLH8BRDLwOKDSYqNI0+OXN7icnT0WhzCFNi1luu8CZTXuayRp4nIFrV9fxod'
                . 'vaSCyYQoolJflmIlylTMDzZtvV/XqUun44aNGjNuwsqtP9lA/383F8gsSAEavmQp7FJZJKqj63wRCvnSa5IkHwEkgtMkEGqG'
                . 'By4UCQYhEAEFuTil8EjlhvBCgSIh42Jh48DzFSJQEI3gslKLFyNWHIMwK+TLkatQngJFStWqUq1Gm2YztCg33zJLLDVooOyW'
                . 'W2e1NdbaaMQmWkO1alGmWphwalqhNIHA+q8fgBwLoHwNaBYFrP8D7PgBwCwA2iAIGAwGgkBgUM6Q1CNkgChdwFE6DeSRZimH'
                . '6mhZHZCNUiWwD8Gg6gzkOVA1iEGQQL9MiA5CJJC6YAN1YdlVAeWpD8rIyqVtSFA2JtrmWHXIhDEcCmoJxxG4lwgJAzUqPq4C'
                . 'lIgrrwe2rd5WeqDtBzsCLVUrQgJpAY0DgzBBpfmfj0OwBuL9Sy2oErXAxNQIbz+IbbnAlFYq30Jt81UViyhvLT+VG5mWJZww'
                . '+IZrd+0EETIWgB+SV/ceVYxRIkUTdRbZe0q1m2f95gIn58KFHHmMgpQcOHkkHAfYKBEyivLD3rH7TKEq+fXH46M80qN51Nkj'
                . 'LYs0F03jihIX0u2q6NpRZJN1O/o7fPsKa+bq6iyxSMet5M0WJf//EaItmkQEZFzA26T7P6WSz+eLi1CQHJt9SpKQTAISbuzM'
                . 'lNfBa+8lFODNVs+uXwqDHga4uhVIaLuPiRvLt8mEs4UTB5hygA3csD2OpHxsw7cQrb3s6s+xDW3RJCIgYwOx/PFvqOuhJB1k'
                . '4Xn40SiRJ2bahrwe9GLSUjy1NXp8Yw/ToXBuTq69quzeOYfwWZ1UCBAgKMm68ShrZ6Ypr3beb3j90cCNDqduK/LK6A93Acsw'
                . 'bPv7p+OU+teF+fT6bunbdXhbZeuZ/3XXZfN+uh2PmvH8YYRR9Jg+6fFWbtBD4eGoiehcGfJ0p73MuMDjJs24RjqFR67raRLp'
                . 'JINQ9tMO7TzqX+BnVVzT61dt6cuL+1mRvHts/9Z8kcMT3GG/V022ZK8Hxt+VowHlvhZ8l5R7uex2BiYvaQtbZ7vyONe8BUNa'
                . 'bh/Qdf88oPx9069uhQJeCwQ616s5YLRC3PhgFsSQADkd58Ahr4Os8tLfloAWMaBVN5XvGIAIkfNBA7X2MT0NUQCwAZAtL+Bf'
                . 'qn8mFxqL8GYW91UCRb9sUinG/LPGWHNpdEmyjADG79SEyxDzOYjIMBAt9AAU8wA617OP5y8NWjGTXn0TvqVGF5oqjjNgKW8z'
                . 'JelpaLP5Q7NziBD1cmOtMtr5RN8pOyPACkh/8hxmk34CbrgvtU1pgSrmZ/4n9yG9sDIiBzUGtFqsQkTjOPLeobaGtYWmdJYw'
                . '93yqHGNPTuY8afvWUv1Xm5e++VnwKce5V4CtbXapzNw5lELEYklfH5kk0lmtErBFN8q7TwF0SZaYXwcDbBBEPXyPEL9YHG/U'
                . '8WuwrTqqzQYmdQ9Y47HY1NkqFi+cVcx2kfs8Z7X8Tu4R6QIBSiu8CWETkAQSsERwWI6oyUbnZkuGrG8AoGr5p0EqGT3KnzL8'
                . 'H5JGqxL2v4EwssZToIfZFxLwOvEhukp8w1UjgP82HjgcParVYW9Ymx3HUgB8jPQ5Q5CpJa/9BgWo4dlpJamQzRrOUl+bl8E/'
                . '6/P0NYRAgz7Laf8iBdA5CfnVFFrVp13QSrupF0fx/QSLriQlmNHJgOg118SGSYUrnvM68PhGFiSLBb/eFwLCnaYvzctqq2uU'
                . 'V6Q/VvxUy/OkWRe9uH/2SrBbOnL3hoKvVScfzUg3B0/WEKJS+cqTp7tAVYlo4bEVT0RMNG8qu/i5IaXoHQ1v/ie3KHlMPVBj'
                . 'o/YN5uTPhh7pUWK9HmJpR0/IDAsKNxbRe2BfXKm5GnmeNkufSEEVwXFsMGSB4VLm57SB7s+XBgRbc091rt6s98S7cuXVKE9e'
                . 'J49eljeLhBkyMnVYJMHaHrYJRQ3BpGX2GDJqxKmjYMWRpVL1YiIWqk2JYlScYE9FRcLa0H97T8bSuMzVUE0dtUzY9B4Vs5J2'
                . 'Ro/I8UP2u4HNaAi/Mdh7AVY8hKpcdsjHSRxLr9vEbkC45xCmlREn/jvkV9u6NrUUHrzLvp2zov2WeL3MbTE4OcjwOXHy1907'
                . 'XJ31FfRv44pFenrY/ldtIrvaa5MM2JP6+uAikZVda4Udj+vUJ2fxuUeZ5mXddcgVcvQW9Z+zN2G6uBA7fL8gbiokLLIMQN5J'
                . 'EvZr7XUBNhApC+4PGLLUWKpBDLHPZsXxCw7pGt54P+QLtyqr5CnAJPOAfhrxBCdlEoqIKFutcy4ENGgCRBGi6ifkHPSZ4EqG'
                . 'CHK1kRm8+VpHy9PE0vb96VXX2GCBjfOcbKV2SNklgs+4hrpNunaxU4juke/L4l5R1CZmA9Eo+7K2+2a93prgNvGbA7Vm33Y4'
                . 'pALdhr8k7mEz8gYyc/MngSgUjXpg7PknZitrRrk+Dr9GCOa/V6g6BdaUQ9Sp62qZCw0Aut6oUQxP8WClNhGnToP3YzLE/JC6'
                . '4txcvSZYDFIlTct9ciiApLCrdyk4oJiR7VQQR4SAC2hulDghOv2T6sYEeSnGUcM8ZqwKukkABDVKMZ9DNVLMQLXCo42dEvSY'
                . 'nB5DPMfRhQMd87EEwEu5Ofo90qwOLq+xRiZVlXcyJ8C7r9mq0MuTpd9gAVvTvNJyQ3oN7hal3/a5cv/P79o2YuDXvBXZFk88'
                . 'No1a81WCk/NGAoiPrhZmrHhJzcWxecMQKP6JfAWKmtRxp7HxKwU0upGLBmZxG/2Ami3yltPFg45Pxg4j/dbm18xdODFY2Pmz'
                . 'Wflk4FszJHRBZq/I3r8zsC2eW5WS43Xbxz4BEzdpZXG2TDHuokA/IoRBXiMicusUj4EeMgkVAbPUJ967kC49Z4QazaUVFoNN'
                . 'Ai5K2nGhYYdtph+gyMRHU0wxMFfvGOPiJG8RmDSSzl3TvPR2jQ5lgzmb0YiBSFvA4EE7vff4OADn7uLGEK8+962x78a99RCJ'
                . 'VeznLzl3ejIfNckxFT1dW8tGMySjp4kfycbxFXXW/fwg105nX01DwcbrzkLsJ+AccVbtEpaMGh9UrXZWtr1zgBmYq57Uaqt2'
                . 'OZqO3iHftsroZlIZRq/R0Rx6eJ6fsxT6bFS+fFf5YpbyJnlwWxPPy9Xq2XhgAlIWR7mgXMVUWZgkxUMAZEdKw7Aetv6g6XM7'
                . 'gtRBH5fiqvKEKog7y9VKPqWLPXBf43bfIdxVOQlgW9jz96oTIdrP4hDiVLX46GhpCtvpU9UmDZF7eLg6SdxNJuFSpn6kSRg3'
                . 'qwqXtmUhrtyZak7yYF69rd7Wpk+xV0w6FRhinltx909CEZltJzpnnbziiQLiT+lGKRzlo1peaUxsH29FeTnIUZGaFJbkboye'
                . 'BD7rZg7Xlcn+7xnwVeIP4IglIhbqT/VYCC/ZksWdB/eGDTso+KILEem3Ravuxn2+ji2nOx8W4jXutiMyocvCeiv3ScMhq+7F'
                . 'lXzlUxhXG1mJlcOS3jxL1spbc+feWESH/oWJPmgw8nQsGgsl/85FprFNzpl6UkBZP8OEEKFsEYKEdn4l/yydkfv72NKqOG37'
                . '1Nqb5Gh2tYhP7aMVpn0RCa1Rssk+TmmQJgVNbKQSoXY6JjZ+agJKMQaNhSSW+J/d32uvOBXo9A4fvsvjpVyvo12mUSfuTND4'
                . 'uOGa2u5jX8sFFPpj6b2VPbeHAHH9bTMQJSEqxU38bUJX4Y9fhxJgR0/t1AceO6XCqqNvZ5onD+8jDgndozwo+r2sQ7RGkXIA'
                . '2BkAXSnl+zKz2Rot3223O5Z+wMXqaO4KvtGYxWtrP+FA4JDbO0hobBcHUbC6OmnSzEp1MqozJevIzV8xKd7czBIH2WdMOANe'
                . '/bohtsnWVo76dHuW2tqYx7WIQTFFhTf8MvtJvydgs8kADh1B1R9r8m7LYMGFNbqAi2Jgsf9pwWoVVo05pgDTyNovK7EzWUDz'
                . 'Mjl2wq1WuISRVZVRCMjlj3kMVCUV05Qw5RUD5zeMzzXJUc/vM0oaA2Y/vHAZDiBbkCmZnes2Bwzq2qjY6SfCrsz2vExCSULd'
                . 'tOlothAShpHCYLJs+3SZeHxzzhXwLoUt8e3j+dFq3rReRIrZMS4lomxZzfxtqIaoshlniAdi3h+GODYlKK20OB0k8sxzghOZ'
                . 'jhtkma91VD4RKPHqXxLBkZpwAZFd7Hg+Qx0/QNiwsqqeZXeqsb3ZY2Duwr3hcGg/fQSRHnH6AtW317sMtz6Dsu4QmGLlcAom'
                . 'uCkwppAtWDaSZx8sEdH6Bb7E8HkQKKY4QkB4EBauQnZlr7HWIlJlU1meRzKYNYENjJF+R6rmgTTExh/6FwP3wMN6Uwb3NcqR'
                . 'aR8/Ut/I5kQP3eEWXsrXrqJNb8OsEp5qo9B0wZ5kcP0bEUi3czoI+5UaC7lxZpVvoq+BWFKdzUNCCRd2qcLeZF8wYGbfsop9'
                . 'NsToVwRNUuyBrz1JuVnvWQKdsfwW0GRhKsI/cfDg1Y9/ioGNKEculrw8Y0qJOhni1SCTsXhB3quiuPeFSbw4SJGYB4l9IgYV'
                . 'BiZwFmZjj2LbDyprFsvdS0v/qQSxRMdwMkmsZb1f1G+cRmeaX+yMZiOfB0dTyauTSDrnbniVpzxcPPHY2P6omOvOX1k/NsCC'
                . 'bhbisBZqLzfBF3nt5caEOuoCDPO/J89FvKKKb5J4NdeENk09Py8tbDrvtkaPQwpzYWR6prfAoOKpZZJ3DtekKrcOG4fTs9gu'
                . 'MG7hqgImA9QvS3jbcT9xs5e16SEIjDci2WpBXyyDssKTeI28RPBmMLsSAVI45v9zScGNAJrxKJ3P4vetufuLwFYmdJw9J252'
                . 'Ip++M873x/G2JqxgIxQkqLRy5dTIux10h3GYrsj4OGu6BJmxK+qzmfoQ/dSnQqdZr6HWBg4g5S960H8D6i0wRKljH4RRb0Ne'
                . 'q1H3Jdbwlt3bzTyD9RdQmXLjTlhYXNp5sDtBmCuqBaD2qLldszNirtnKfnfoLQNBGmc9Vj2QllttSVaEyquWU9iTE6EL3uvB'
                . '/AwEDA5stZRXlv3MUgw4+pmqnIBWHRQZYbS75VHGfnsIoRpyepM9GTya5TEWVMkOFcR6bi/6BVSabb/gZQ+CUSnYVryEa6kR'
                . 'UZ410cGCbHEDg59a1Wb3qQ/YTOKCmkw5X26RSO1FWRil9P4Fymhj+j8aqkq5b/MVLJAn7q0F58ARN5mG2YZmXhKUwXxCiDiE'
                . 'PqGjqF+K05YjF6v5kGc5oXLmjwlYla7KI9lRDSkA8rnnfqS6QJzRmHfcpp5tnW09UzmIjnxjJ/kWkEZspdPTzRr0VwhfdblX'
                . 'HH12x9lW42lK86ZtnSzvyW7Gb5kWeyd0HLu7zE7SaeaYUO2Z8df2X+K0v2qnu4vESeM9mAiBtkR+1cvAanWLqGwZqjUEG2qy'
                . 'dXy82DZY+FAsVxL7sF5buJ1BG6VZPt+Rhbb1EzYpDk/iG99FMJqpn4WrtKqlpM5P6bpSy59+dfRIYf5IE2R2nmNwrhcZGpKp'
                . '/MJhhuzxjMFXsgqjlOGxXwSmYUqI953Cqp4w5NWA7GHZwq/YICkOkrsA7btJQkL3+xAAGHBG1OqY221Cpifz1+Nv+9y8wGqo'
                . 'CSmXwqNvJXXS5IXa6XqaGeoSFy+sOHjMO3WGXtp6Po2Cwbhp2NoVfFUnWOyRbUcHIVCMg0/ftq1DZRoFA2hcpvJez/sewl3L'
                . 'FEPVK5EhkWGRGZFhkZmo9yLzIjOCOTt7UqPRRkZPjEvqu6ENPhTPMYoNOJ6xiXvgeV1XY6wqNvrDhuoJlsa6PwnsigDhiJmv'
                . '17RWbXRuj1ATsDMCahjNkuFUkm34AkXXuG2BoT/V7gDAkSR4AUnysdJ6mQ6iA1+6cmkIKzqXJyXEHM2s0ed90/t0nAEoCjLx'
                . 'xJkBhW77a45hPy3Z5ZKGVJTtYTv09H5By3VXgsdmRAlaChyiZbPUdKP84UKqjkI0DWNm5Sb1dgCwMBquR06G92pEXau8Nj9E'
                . 'AQ45ydKVAbtklXj5QKhiaWzQGHdsCzx1Vs0VxVpvZ84DJphFd7K97yFveKfbTiy2M2pnq5VGcvXKsZtQ6GcfTV1YR00HjL+v'
                . 'g/+VoLTyZ9rJjFb4oGjiW5z9D4rWJLqiZAlK3dLP7ru86PaSjOxBnGEpSF33WciRdRIUzHe1ymAz5dsdO/fo2zo4VQZzvn44'
                . 'xZpZadFG/vHD437p7KydSuF6Uh7Yk4+M2PtG9zxZ2aK/69Iu/e3SU0cmAz4/dDvNQDH+dsa5TG44GzF9+YL0BYbcH96m6sDm'
                . 'Ojn5xZMdQa3qYY8clppcFhGTdO3xDl8llJF3akE955/75JN6liWwsOBsX8zraNXovQESYwhhuwagro8gXu1HT50oEb9bpAL8'
                . 'it4n+48Pq0laETwAOKEDF3wSwMC8T/OzDlXjqryaxImuIvzDFvzUTteki8P1WWxrwaVt2QrQ2bDkUh5ircFac1Jq1OdPrK7n'
                . 'rgZH+S1qYmTe7aue8jlbTZbangoQVakrIwgha4vlPIBD8QgAwPsYa1diYrNdyLfEvuSwM6H2H5/yTkQCO4V+DBWZh0ybk4sQ'
                . 'h+5acb3GvOeqI5Hp4tWVZZOaUzyYT/FAag1YkM+1f1Tjqvrd64BDisORrMp9SG6SbfZHlTgXsSvAPvjMb49/1b9G7IXKOAZz'
                . '0x5MDeT8R9Keu0/cST563+6xXXQI8wQQwMUOEJj71S66GkpVKfeDB/FRX5JK0sOGvfdxHAb420Gx/yAkN4k1CbmK8o5VIqBy'
                . 'JRmm3mt42pCCpigDj2EYGalKF9LtsxZApCDyh59gmZsCLiIrvzuASG3UzMiTFqcXx28smjmeIyJTeo3CSq9a3W2Z6mTUv1tR'
                . 'GkXxkopa/wjid40+SqexUJuyU/9+SBpPW65jge8bTqdMNeRX/PnZ38Sn733CP3n/U/HbMz+z9TpqDO+4W9JbQWpKI/RVMkdQ'
                . 'sBiaFCoA4/ou6BOq0mRD17q0Fp7iMvIBCHZEEuSNeBzPVmrOhBpWNh4aH1FanSvjo8DfiEFMGD4pdofd4ElDTTJKPTgX4+wx'
                . '65ONZAqSK8VR9NnkTtNjl7apIp2b7Re7Tlvyg7dahK55DFp7zLi7q1MXTtCNFulvhcX9LNx5m9vm4wHerSY2CPZj+3+g+hmm'
                . 'oN3kXKhgxRF27NdbTRia6LlSm0Z7dsK2cke/xmOgVLYkfvTpLPW8/B8t651QW8nauh7Su1dm+ehLLNcIWgciHOaVKvRMmoUh'
                . 'vfqAQkPczcJDvj5bz5g8pDcnScX+F5vsjqBZ0evRpm3YrA3pWOEeha9If9dV+r2mSaN3g6Evs7TuVECDbobVtXErk6YfjIjG'
                . 'W4WX7KEJrF7ZdBni+rCawy1o1uYIhlPh1Aa2EW6YMcXRUBwnuY/tMnshwaGW0XBSutwNU8sykg9LjopZS7CFZEsvSzQntZX1'
                . 'JiQdRf+mcZsDk/f5FjMSdqzUt2bXt0Gt2k3Wvg3cWuSa0Rv1zk7Xu62D0tNluq+sX5nIK2zRCjs8F05FcKNydfvcotO6ktqx'
                . 'r35xsHjZkavzpCa6YOL+R5HSFzR0IuOlkAi/vGkcdeWZgoXuUXeiOZhaOc6YPk5OnZ2/vHDJGGN67gqRRTQ7j5QqMo9RS4VE'
                . 'XbtPLtEU5xpzxabFhfV5RKV/jOvjiMbc5PGiuIz41NGiaFMcIENQhG+NkOu47xhPoQpF8vo13giK8ELFor94CIqops10f/bo'
                . '/YUlSSZfBMWw/xIJkx5qfz7Bw/6nqb9KEy37Pnw24wtVXDkuOzBXJLVpb4c3yr/wt5dHZgaB/5gLe8AKaOBcNJtSd4PNXnqT'
                . 'Vkl77OQwl+7k8BOAKDJ3glqpEcfOUf703nXIAJ4ra2oIhjaAuLikcB7329fK6KsBcYu8Jeps+yn6MLiE5nQFk1pr/T/jryYb'
                . 'GavSvqNOi8Z4w/Q/aBxxmSJSdYx3LQOehLuqA8aiJwKetcP7kJQyraUICE09Y8gK8k0ZHja7a0Li+TaMSDLEgYig8F+R3X2r'
                . 'LlOFeEPcUjF/zDveIfl30WdlafpPDHxjl555cMYF6lLSTsz485cet92dgiaRrXE56ZR9o48C/3PFAQnE7GkxkUVo8wQp2dJW'
                . '5ekISFCl0erLOARSD0KASck5qUmxJYm6NtRNMJI6/QvM4eqwGluYQz4P9J4ea+0FClBC/5pegBUfjxQVkGh326dIPAftlt+Z'
                . 'ys3XKVshweccdqhkJQq8g7lmjFii58AoLPz6V4f7VqdNBaEQp1QiuMAv6nNG9VOyUZu5oyKjLUhH0zcanLMpeRNe9b7Z9rA4'
                . 'f7Qi9WxVK6BTwQIBjIVRFHYFEdUZJ5wQfP1tKtpdgBOFnwCegiJjdVqV8Oi1ZNfV6ki/yLBi26/0KVpXdi8P8gTeB3dn+69X'
                . 'bQw4uxDZh6TM0EYWEbz1+YNgBbT2AA8f5HTfJ57LWHqicSq7F2fhdk2yukMXhtVyCu1NBkqVTxX3fHZvolZIp9eHERWp9bRM'
                . 'aW5g9gXJl7Ra9jYOp5+tvXHVOLV8h8gB0eaNuU1XHnE30vsP/yurBsgQCnEdfLbDKHxolug+oX6iK8fHnMtW+1u/F5YFDBe5'
                . 'G76E98Fd2QEDqqGAe9rh5zSyAbARq3ozNaOUPNRsaalOnxNsCXDR6tM5eFIPTEBQa6bHbKhISGohpZCrfFeVZdjlB4cLG/N7'
                . 'gjEUZGMJUAoLMb9/NzRPJ6ZwMEluYhUFQNXh4MzkXdhog0g8zKK56PU3dvcNp+wmicg9J1I1W7U58rHvVc+Frhs1AhTNKhWJ'
                . '+pDW+5tfc92qjVbGYjSJRb2ArLlf/TWOBvIY52WlrN6GR+gG+Hdlu6WiEmRQvFCjOB6SVTJO1z5aBX3ggI1FqXtsphlOv7F4'
                . 'KbWGLNnCxzp92N/qvF6BCDyiYgNQQeNSoc4THzjPOcZlH+NwFrG1pRptiIYHhWLBRLh7RWMHc5sePsJtlPVv5rDRss9Qc2i/'
                . 'hDaS3dn/ACC2q9wznKR9Q3KGlDhii76dWEaqOkbSoQ/10nCceb0GapUPpzSs00b86rjAQZopiVruU8UMnRfs/++3JcuLTxii'
                . 'S0/LjYGp73YyQmWKIDeZC+tTYzANkEze9+QaPiUB/3I/AobPgwVXq8gOszwftq99m++6Wu0lDTOVWBVmaAPUw3B7gklYSdO4'
                . 'x3ns41zudk4YcLtNE/w2LvntLE0BYxKeziq6iaMsM0l6SuPqCcSDjcVXwp08zRU2Tkpmn30aSxqVY7yTy+FWrBKJ2P2ARyX7'
                . 'ZzTniT7j8fx+eT5W6b18Ti/5X1H/3RJR7aOnNOU7Odp/DKHV6pgKQyM2i4FG6pda0czwrmB0Osy/WLUaC/yEVyqzu1mPJ3qX'
                . 'StFjiJmv/ziH6UUMP1FExomsKetlU+aLpj99RGmUGwTcpz97IWn35vzga4imnRPw7nHC0eEjn0Msqm6Lb9+X4ijgyLz0GcOf'
                . 'vCmkp406aXMvsDelio+M5ujX64jb52JonJzkRR4xrT7Tf4SSQzQlthZkNockZDZkBgxRc9BkY3tRWlMQ3tHcx2hGzfge/5Lk'
                . 'KK2zaS6jiZiE71GW2aIBJJ9OSAdGSEnBCvHHiDjKFOvsWy2MKPDEQGOEkrRPTIgYIaV8Rpw4jOc4PxwO1bK3cdleG9LIJzCS'
                . 'FT3cYElVHYdfun6hba2l1TTRtvRLZ7eAp1ZxWRQTXDTHHbmUnEWymtrK0toC4vlWUPwBx5WV+JMEVomEk7qWnHrVHrdS4B9m'
                . 'tv9KnwpFF9urEocTGeE/xO1xKSlnOktC9I0Jzi5KHiVHNOD23LrnypaygLck4G7VwxNmyCIbPVdVV9TqZnnETCNGEpPlE+A4'
                . '0RdmsQQ5WTJUOizRqEueesn0YrWhZYLntEq52x7nu264g7T7ONASJ5gmd4FKD/HF+aqO/4rsQY6CSPuREQZQeT9C7EUvqu2V'
                . '6CTnaTKz99rNG/npZm2U8CXLfcWMlZgrVSGem4PRRoe/hwnUXArKDU7LkercfuIw1/X1bDxULFHRwtuLj4/jceMkUW4ml2qj'
                . '2LxFGCJmntze4SfEiZK6bnt+fNEk0HVv0iflStX8VY8f8EidMAGukegwMumppeFVR52XvA5LTLOWxTpLg4AuCIXkNqHDdav8'
                . 'hYgq/zEk6VtoCow/8/VnEvokJPqSqCsFQ+LckEzwspE+CCdcuj4sgPbBS+gGxJcqVqX1DslTbCzeU1/5H8Dl//uwvjARYt/z'
                . '5t6jIURYgfaFqohJluK6UIMhP9yvjJgU6+4y+yPeILdZJO/T/KcEog+f1uFB9EZrlT21poyUCZTo1hNubbD7W/y1SJ/9Uhfj'
                . 'h5Y8eldD4T+L1v3l25tmxZZB6hDgJ2+GHkxyWRwFMRFglmDBer4yWZ6xfEs5PEoxbkbNpZgU4N20W0F7M8575suncxEUoTuy'
                . 'c4mQQge8BnoE46lsvRTGFJ0nI0cw0vp9pUZ5wP9ZGcqc7D6Jt2FDI0DFL+GqIqX3GLeFZG0vrb0U7dmb/dMup0J3z/2TZ3J2'
                . 'oBhCIeo5DudcDaXQPnBzAV1cysJ9BSdefUkVPIjloSoMEDs8itFy4ucHjdImWN3lS01ggxi10KkeXXn+97Ez6813+L2JsXWg'
                . 'lCk1x3wa+Xwwc7/39xn7U8aUMTAoRuTUbgaC8W82/DX/ruIwmtMsYU1RZyU2aMNyP5BzCY8U5/c8A6f6tdOmiU2cDcnkQ03k'
                . 'ZvJbGqfmU5hnLJEOYiJERKZeUPsJLtRneDLNZuCkJDPdB03AOmC9CeEy7iUbIaytAmOmBC4z0IcZpr3gAbruvSxmUw+GR79p'
                . 'DYMwwVZ2XCbHELmPMQX5LIIz5zNPebR1mmzZwI1Yxo9xBc1vU9SzHCNv2KDJlq09GSv/Mq6gdH9oqWPYoE3r5svUDpy0M34M'
                . 'xGgzQ8sHG+MNiF/qJGbeAhYqxBck0sbY9hf7wfXgE6QTeVA2FSEkT9Hxty+/5mI/fec+dYykWh+8YIITN+3SyUQlzc65sfcu'
                . 'deX8YM6+pYeVXHKCi4k6zro/+svSm4/p6ysQYypYIuaDAFc5IOM+A3cK1LI/2NwgxtFcGlfk4ntABThwLppF6brBZnffpFXQ'
                . 'Hzs4jCU7uTwnQEdQRMT/z+6+5YjbIUIKKXafhPOOWeQwEQ0rnGmNQfFYicTRPIw3mBOI1lHRcRG74rrLfcMev0OIoDCnSSre'
                . 'v5OsTtIt82kwcbTdzqGkBDmMwhEGQPfk1J5MSvBMetXqpnrvNOP+4r2aFD4rXR319x6x7tthKtTyt5gqy3UTOkcrdEkC7aOv'
                . 'FlcxqIlLAYHivm/8oTMQ/5tmVFcIhiS4IJngfSM0CCc8OOFDAaMAWaIqI/h3e9aBPBiF5dFBtcwQWRlDjpmEzkKu2ISrShLL'
                . 'tsrqnlGoCD/ZqwPIvegHYWRXdCEmgHhIzCtzyfrn09xvDl6K9mgP+HSyQPUz/Sw0+5rmS0D8qVewru8JT6hzRo3T639nthl3'
                . '5xZqA94QjZL9jvkE9DBV3i5/awXcqNTVVhZRl5KXE6li9JzhFT6NHaFPgdGxfWzCfZqsXfa8mi+Mn8FdoualOmdkJJB0vMjv'
                . 'JdaiznOSO+eCMlcsLoaZXOgmoAJce8DGpHQ9ZrOWchpw73IOP76Ol/Sdm6CNNNdvjCNyHhPyI/jrDfz30CQY2JXXQx8Bm98z'
                . 'qcAcYVKnJBCFYif+0v7ZlvB/kSakA6H+BvUS/Q378PpgsZgPAVzlLCn32R+7BMGyP9ncIOaRHIH4O5EZqCK9tERdm5yfHR0i'
                . 'eBl45c5Dvs3a6jKQkugvvpVaSpacFHsvDMhflV1f8slgCZnJKpcXJBQEh4a8tfjcrFMXC4n/2sTTnP6W36nyyGwvMzE3qCCQ'
                . '2SCWtcs8eiGwAo0a6S6C6jaOamLVQxZNO9hQn9YjE+8NycfrppsaOb6mOO00kmVuf2BlojM5UBkS4CHqiLq4Upc+TQqsQwPn'
                . '30LsFSdNWQUhQZzdO1lgJYsdFRLvxeKVrakaRws4wXwfIa2dXu7CGEM6RnI8UjSNRH36PhMiy35E3kpHP/fbRLUo/7ijeTyi'
                . 'xGHPCPCXiOnBGcJkc0uqgSycFmgU2mK6dkl1jnxyBl534kqM+FNT4X9dGzd1Fen/fL5phmMMT7xIlS8MfZsNrETDdBHs3Hkk'
                . 'q60gP8hXtuF77Ry8Db9SsSRmWrxs4SZZUfq08MWUCTSZZDdVFGgr/OJzKVUEjyAvzNeYTkypJBZkE36UTGlovdKDemAtMeo9'
                . 'PWeoQuX4NVUR7FenUr5xKAp4LIGVUKrttmQx+BMCU+08cjpdxRIW06poHqegJGd/zkE2Nst0AxFcnuBEuYDn5oOALw99BZEP'
                . '8dXP+O/hSSiwM6+HNgw1vVdQSV3XoieLisL/6SniRcS/SO/bfp3wk62gq9bLpc1U1lt7OYn4hpKDZmmLo0xtfIQES+9FkXNl'
                . '7WqjwTvY/3aErI/KYdsXUOTqhYy5huukIr+GdX9UqtNZXC5nIwOH8YU50UA9N8IiRlKWFJieVMXO/ipW9+ykX+gbIfEFKfub'
                . 'QZP1Ea5t9AXR51t+5x979VmPCmSETpWKZmjr1OisPv7+Sqq+bSBcb5Ef4q7xBGL0A1yuv64ofPnhimfYe1a2AANb3NLZRixw'
                . 'eeIUF6NCPJGec/ovARGlV7Bu+HeuyP5lp9UDyo/LgXgRCG0PfZ4N1OJEomHl7RE+4a0itEMePc9b5L1U9dAxNkZeoL5B32Yp'
                . 'AO41Pvsal7uKHV7GURRe/1S/aW5wfAQ0dZMQEZ5OhY6W4ezfWbrsQV25jpaUCvh28ZFgT6Qydidi/TCtrWwWXbU+VNZEZj2P'
                . 'a0HRP2nZJFdc/lwup0beH6oa9RZ6L41yUPKVdcsISUTucf4i3Z20gx1eVqDCV1r0C7SR5jYYCYvpvqRZAOV9lJ7X3wV4+RXR'
                . 'JRs4pjKFsclwnkT8ThzaodXbXx6lnnRK6kMUX9ECTWlkU3mXxZShChBt6vJPSUpsDQXycWJKb8F/XSOc3336jPrNeM5+YWBC'
                . 'OSu57AmOJkT7ddzrq0WBE39qEq7ru8Pmx9ujxuiNTxgdSbuz8tV+b4hG8X7HfDx6+BTyG9CaPndGKZ/M5RKfh3XbEuqnOUBT'
                . 'f5/YHSXeM/cj2zshJWKcVvc7vS1pd3ahxpdENAaDtLpOLnjPIv7FNL3tRyi/QUMVi/hPJog0cQ6peboc4/lWhnPjdF7rMsq0'
                . 'pHyhWQhwpITyu2qPAq2fHmjAoTHAT3aAYQbjP2coEvuug/8kRA6IM8rftK4hhb9nFmzdmZifExYWqYtk5h8l6k0FucFH5RG2'
                . 'vTPBVIbexJdGmFBjJ+RKMOFwlttmUN4vKAGR9aa2EvTcOegmtYLW8v7qnPTzOcwlq1FXnSczsbcwajXmXy+12qsd3ILrjln2'
                . 'oM5cQUGktl71yYAt6GdA2jDKaC92+6Qf5fMH6QL5u0Gg1PN7BsdIYT8zCsZPJOTnhoWG/80s2IYakvIZZKgmJ10fzludZmJS'
                . 'LehKVCDsqJeM7DltSueHVJ8gZR4vWpSqyONdED5wcJU6cxTTu98HJSB/cH1nGFIEQCJdEqA2xu5xVsTAcedqEhhD8PSeqrA0'
                . 'tb+O+1Z48d6qBIufr0ycmaFeGeYXWBC0I7RG9rzfoo/VWvBJpcF5enOqHyEnuC+oS+Zu0tjVwxHbreL/mg3mRCPNqNJdLVcE'
                . 'FZ5Wv/b+YoshJiIZr2/R5JuNKT7ABjrCl5TVnS5MbOSzdaZ8p9rH71rHTHWtzZMbomHGsbBxo9aay3s2S2N1d4J8DvlnzN75'
                . 'cs4deyZaRlP2mYBu/OAwv1ZS5pdyi0v5FPOSwEKjhFAI6GOxRZDBSvcYkbinonzuWgNW44ix8AmLnDPDm2WMS9fyaEANIeBI'
                . 'nKSUTPNFF7BZmZGJJ384vK4oufuRIiBBSesjMeXH5WxGaJDjxPPLe1NtC/4CIt1/mUJczmC+jW0h4X+g5xLdloek7IT5ZY4k'
                . 'xT+2WG0FqdAek+YTFZKe5KO7olK1Mmk2JovV9SWdqw1LsPhyYvWenyq+jVQtamdr9QuYc4w3yUW+DXuALiJ7Nr/K7xv3RiFC'
                . 'ggP7nkI0OD63vFnM2eShLZRdMylmFELpJm++eOQ8HuMLeyUDc9AACilJtt85j0A87KON8Z1FIMg/JFo3BXBFaaiFYrSa9uQU'
                . 'jsFCYneonyaAfghC1tiU383lEd4qtGoGaAEK+eopf3fqgI1JxV+Lufip4dEekfPxgzqVfc9/D++DgzrzekBz9ANg0qDN4Lem'
                . 'acAJI4WffKKMH0zXBj6BSn6olkSH5KRMQ4ka3tG/XIz9zFez5cJ8XGhkom991kyl3ldYtr6rsBK/s/8dhfsFg91d56z2L0+0'
                . 'mVXf4rmQH6Lqsivl6aYeQQ4+B4ifwZf310qBSZ/6mkpWRrm9rLewUh6s0NmdFcSTi+6TuT8wWN1dzmxleZRNrxKLr3DNcOH4'
                . 'rNkUOp85e1lQfUK6ORjg4rnXL9t+HhpEyfveyPu14fN53NdHba8mZ6GUyTehHbvz+5FTFikwUKnEm0Svn9emti11dgUtyx09'
                . 'tya1O3ggl2zQ+qLpf6zw32MiFr8UoHA52IS2QFasrqAwxppYEFWsdFvmdH98RWNu43CnS6SS0shma6trmifTrlk9gXkW/Vc4'
                . 'ac5yxhTs88hbptVLZqBjSAYpEfKbRUwNZK+z7JdpLpkdS10NxJfm3pjBYnXF0Oie+YyNTMix0yXNY5dQu55IV9LiyvmPnrP4'
                . '/7/OoIW2ghvguw1dzH0GYhJ6h9qVJHxL6B3WWLXfY9cpnvvHzuNkBs8w3eKeJoqQo4GnhOh23YLa3jDRCYiCMQEtfhn41VFi'
                . '8W1gIQNuHAG0Q3NHc0SriV9YrDcqB38Nzu3+nzTMMpe5MG3k5PfKsoVyrtyFnZ7NYfLaoqiRpdMLCDcmJPABsGVh2jSZ+PMZ'
                . '1zK5vklxYdNQXYLT7u7vIWhT9en9TsecnrNSXAmNedFxCjNjWlTWX5A0JLGaVpMvVK5R4vKj/TFoBuSYiILBPzYo7k32+i+X'
                . '0/qoLLsaTvgIeJveiLBTzVvnMnKukHFlFHPOUr+EMQXOcDfGheYIqEz0mUMO+Z5Oh4GMCCrnDTWHmBXKoPHmJ8zK1cfLLazC'
                . 'yKyR7yLYMdtMWleG0fZITy/iVJ82gIOmuyFP9/cR3mRhfT6cdGkAzea8BTT179y8jPonjCh/MhrJM/JqgNoq+gVyMrqpinCj'
                . 'Xw1PgXO/pXWMBpxzskfTmmh1SYPDqvxlzTRO9H4eU+2cz5gPGu+Oar1c1kRhKjd6dloS2RWfFTmzKx2yMVy1sB5hof7N5TE2'
                . 'Sr5f3boxCdXnzZflzChXmJ7Fg2R2grxfa7DLRqDXErn0GA09Qr2RRBuFKMYneW1hl9WUvrgfyuOnSx0dY3CFJEAdFrungMCo'
                . '6xJPSFsVzShjNCmRZ3ys3CewSGsypqaNFtqX+vbz2qiy5Po6LKcxpkr5jXTjNuagE4hgxmTcBbd/CYFwfG56qZg7Wkprp18z'
                . 'K5oLDf9I+exSxu9IBMxxTC4OKLMm4GN29DCANsp3GSfM9hDMQ/bUr7HYNtZxAvcnFaktizTuGk16LP5ueylMVY6KmJIhP1MZ'
                . '4hAdsoHKO2ksRYhU08msKV2WbHX8Quy0+Yi4zr7HyVDBIFW7sW5x8E1DjFCq2/00c1J0xy7SmSA3eLQZ5Demgp5/JHxWnQNv'
                . 'E9kT3BpndUgDw9pjZM1Utu0snhloFoaFsOLFn5p8rkXO7E6DLPLUKtgAM4n+zem+X7519XLLUkl/X7koT0zpbcxmdHDbsh9a'
                . 'Pgu7SdZww83itykFdy2BYwt0XxpS7631C95NknWd3q2wW6/x2ua9Q1mf/y/57mxP9bVsCZwtXMpydZL4h9TOtKQvqoiJK2J1'
                . 'hsSw0DdC9AWa/fXQsh9NOsL3OxVmd72h+lW+hNOIRdwcKQJHFUv2OLGvwZpK0eosPqrAggsGwvTWYrylnjgsb9q7+T1gDgtb'
                . 'uBiruFxsSmUQs9X8bFBt90+ROXgWR9+aeBw90Hle+0nboxfZFqY2OJNSliWbheXxaWor36B1frs1nZF6IcNXLJuSXbSoRL46'
                . 'Q2iLYpzgoVEU/NuCYLpGryjklx0aPnFpWp/y0r6Pa88MtQbHtp9cVN9qRmp1uuQg8YV5IqPm5RsN2Ip2+oJqtseqkxl5M/gM'
                . 'I1c25MqnlUC+d1wUL/1N2gJabh2dcUpHKebjQ53z7Q5IRIKewUIRPNBC8X7lCc8NYTNdRzy1XwArKuRbvivvWLVPohj90FJ7'
                . 'Y998GywSQt+W+Dm9OJ+buLQSpzvMYUfPXs1PB46hEc4waeGunYbc4tDISEeoJP/kLkN+YWiJlCxJLcbryNkD4iBTMZraiuaM'
                . 'AzgERWQ2oUN2OsY/9IImWbs7nZYGRUU6pqnkCIpwz8pkdwHR/Uxn971o96hM1jgOTsHRE/QppDEXS2bKtZe7XWNPkyNKqCAU'
                . 'YYdif5oHTTF8WmA5ohtMQpIBMUOSLE0VlmZo8tPLLhVL8aEb1Sm6JJrNTtAvCxP7GaOvMDbBMQP0EUbhQizVJPPdgClIC0hK'
                . 'CjnWUHKNkVRen1y8MBP4/MQ8QvpzNo5TSTSUA7kQAHvnwX9WQLOBLMQ3tKqbtp9ZHMAvBdh0tU2T6l+Upk6vQ5324ixCbOx+'
                . 'tna6C5+DTjy20Ufo0sz8Q8S9CLFA0ULLr1L7pI2tsBXMzQTOobkZTYxaNMnYXzNzUbFLoPaX5gUsXOKZRJ27KCXRKvGh+e0C'
                . 'ZyD1dTy9EmIYNTyKrrgr2pSmBHB0wATmGSO8CmphZ9cWeCMUPwkdBF1XROQos0WAUTL8XNNfAGfQOFeQ1FbTo3dlx3ZbsfEZ'
                . 'C7MZjlSVb7y+YzpPL5rvO5lWxTDypEEV6RHltkRb+FZYHXv7OzY/Ozj0nmWLcPnAmMGZpwJYdHWAxChpLHW3hLaPjVFKRf6i'
                . '432ZJH2UN/UUWdpCr6uVqqczzEUdMboMhSbKESouRU2Ezo10cTFwCY2zpDGGqYUlrnWowhMbb06n31kFRdbBcvddRZjFby4p'
                . 'm1YNLpdZpbLIlICFpHRyJrBMlg7smZz567PsupZTQqJug9pKu7iECiHhcJHUvqvjdCLlbQF9fG0gACIZYfHGqhkpleqi6Yvf'
                . 'VgmCdeE45kEmO6kyJTF4JsPcPUuTY3LbHeakASkVseQiKXXhyH6Eu9QLH1RbDwwSs4Kc9DGQXcML3CYzVFWR9O0xCAqH/hBS'
                . 'SdC1KGXJChNzs/xQiS4yYP7szvIgs0X7NceoC2TbOxdEuzOCgA4ERWQWUqFj/zAUUU1MKAcKIQ0E+kqhZ1VQL5CJmEOnHZBt'
                . 'QIoktYvEGBRDd3tL9SAwQgw4kg6PwfgokWqp3L5skO1aH0h+e5A6Bpz/6OPxlDOKUaOhMDek2Ks5LK1v2WFeGYXAodfJtA0M'
                . 'fmrsBeIZhFgQtAe4ihqsifQ61ICa9f2lzbPsFoLEXz1VbLdVqdvXpYNukjlAonyzvQdx3RWRf/lV2gYqVwlDyynugfF4R6Yf'
                . 'EEGIrbUSzbiCWiiubiFjBIrbCO6Hih4KxergU9Fl6ZGZBOBzNMwZ6ONYlu5vOmpQfW17mJBD0M2mpH4v8xYqM2bxzC+K/1pW'
                . 'PX3cypYmGMzHN6blLMvSyFOzqued4EjbLdneOqT3b66hu0+fUhAAfFATNMNFVlu5ugNvyAA8JJNd7PPbtgHE9ayhXwP6Anpk'
                . 'niw0g+Tp2ppgz/bTJppjmTUkC9mZtKRsWpcZuMVfJWxD5Z645m2DZQBVLZFFpfgvJKeT5tABoVBQ1y17YssOAT74m2FtrvTN'
                . 'NkkeeYOrHelBmg4GBGYgmxypQBpt5/P2a7zjIueZnKSK4B4yk16x0e1wmJLW0oDceUhCl5JXqyPlRCMkJPQLnU2R0XgXimEz'
                . 'yN3s+6fI+IrnSArRfTXQvWpozkleojibm/E/q5BcN6d4ABnZKHGnF5NkRS6W7Lm0IS2hILiePqkL06nuuV5JrExAispo4Jr/'
                . 'Lm3OAhtbkojSVRaprAWysjKbsKjwpixC0VA8M7nVcW49WlfvfZoG8KDecOvR/brAyxAnT/UQ+qdTqjFh6kLUtNTftkOquE5J'
                . 'Xtujsu7o5Qt1eAsxnWLk8kQGvI2QgpoBPEJEvFecSmG2lqr5NzXJ6jk6TTpd+Xuh0MJDiAitl8d9CsA4m/d/f4YEvw8MZuEE'
                . 'fQr+jaRUvtYVDNYJroKQknHsXgoyhdAKIdinEKjbhOemGe3h1z/IfP9Rwhvg2HN5ZKa+Ou17EYIi9PtSWbKSxhLNg6aQ7/g+'
                . 'fm9SI+7NB/5AUETED5VdXTVjXIuV0v6chAAp5CmATCCDduo57QIczNF2eWNQDPksg10CGDDKUGgBvXM5XXBWn8EXEZpngFJA'
                . '78UIYS4bsoUl7oaZgM9CBBOiNVT5gkPwAyWxAvTyYruAYox4vZdreVReRrwMwUmqtslcXUrt73RaRGIuh0XUhR4G5gPOpTzh'
                . 'LysU0/VRABWcA8FambG6Ja7A3ypDNKXtA8nwCCwsBjfAvgiVoIlmeCkW8Ue/BLIwSpLBGv9neHTeNqqGymFs8LMl/YfKou2i'
                . 'tXRJWn7ef7cyy/8nTnnH9PpnhIJvjkXltuQqI3AR9qLuHJTaW2zAEyrVo8sWcIiJSWNACxBfzxN/WCxPjVPxA4OM81ZEZmZE'
                . 'AGuCB/gGx4XlN5o6LG/a67++Mcu0eTNpC26Ko8E3UlJY3nSWBNf/fvj6u4Lzhr1e9aLgRoD7lwF3Df3Ly/SBtEqJBi5cBl2z'
                . '9OMR+oYynM2b9EySwvnAYBRugw6MKfnBpUa/zvws4U6GfIqqkJhfMZ6tkv45qFrCf/D3M9luoREJnjAh4UqH4BkHCdyu1W+q'
                . 'UZE8Uc5n4dF5ExINlcMciowOJX2QRceKdtMlafV20q3Mlp/FKT8ycT9tpOD7AsYWb+foummRYpAn8oA9lPhHltbApCAiv9cN'
                . 'aOvmGmXfUtOoebEkk6JmYq/TP7pKH/Wh2eyAhnzC7WFAyrAZa2qG4aa9PzKGDce5zuUsrX/SnDCAfFBbo/leYSho3EF7gzDp'
                . '3i1y2Ta+DLN+bUK2FveNQeJKhoSsL6mucWy1DPqI92MFpp9GNsAYPWHUawTQI1lMloXB0weNKkf98ZzOGqaQSQTRE0dwo8Yk'
                . 'GOxIBlcfPOo3FoDjnDBg1dd90XFvkkmRtsxnqMq3OprJehXMm6llcIVSU89pUZwpdmfxx3mJ59IN3kIxfIH5eW7Dj01Jl87O'
                . 'tjFY/xeNjCfUhA/sEccZY1cvhUZDzmXosyFfMEjeKnHtVP17ZesiUeBdsehukEhEeioSP0WBiw2+7OJJ2XAmrRWM3K7YDFbQ'
                . 'irJNpO9nJF3ppRZrkhu0c6ZTVQ9Y28mQUtKUkFaTfvKKktPH+bDyD6ZhHYQHLB44FAcdC4R8ONDPAxCLBdlgNgaesyYqV/xl'
                . 'iL22IAwbBnDbICMP5MIzGSY8A/hsCpnbhgnXYZCRNtikA+w47okc2waISNzSswNINsF+POh/wRKllwRjkm6B2SzoDMxiw5D/'
                . 'q8KmyBOX45WWQ0k95I2UAmqBY2PjLKNcaqGZLblXOYuZ3bSrqgwwE+i5f14/i7Jx6EJKvmOTo5aYS2YvZnXTryq/TVnAvpbg'
                . 'BBvs2EM0Y7Pn4CASxFojyEOT9/Cc1gEljMKiqiZyKq+S5De4v7qRWoGmNGVxYRTovi9KLsdrmGsbnt2TjVJBWjOMFgyDjWeE'
                . 'Y2UY33QugiLSWJKjbWNnnQaDYngZAOk+hs75VKfxSFFscJda3pOXCVAGChXCeJ4e5clYewcSs4GJBME35DyS3+SGaUWrpB8R'
                . '5oLKldsigdMzQX6nUK2U5RGUh0n5tQlZwozJN26nD4Z5BC1h0li747MApEYzk6kXBk4HXSBboATlNVOBxSLQKqRHScz66wvk'
                . 'YU6Bf+qk7bT2VfqV9Db66KgCTc9ZQVsoDdSOjIadDxxVSLZB7SvvWqgN6iGUVJ69gkpZ21bqbkNOStd0S8BIlXv56UC6ShCB'
                . '+9B/l9Hshu6sgQJuAZhUH//zU1PpWaBL6c62RbQVZHQEG9ytusSahJjw4pzoKL/PF9GzblTqs8waCS+iIUflvGVQiJkRqntn'
                . '3wbRfoeU39v3CzihclNYkq6+QWdNabBH9dg9SR1d+rQgB+MGLYs2q8a78h61PvV4ffR9He9fmbGtrhCIIiaUqHUeTtXhz/Rp'
                . 'RSFh/H+DrcYrK+k8Q797Lbcs0uz0CYwpSYxvhzJMXlXJS9tNuOs3iOmGLFuyLdgkYv4V8Aed2cEQnJQHB2ba3YWpZfZHKCmR'
                . 'JUzfL/EeH4hgk/+HIzy+S0JJFFIQ2/tdqMi7f3Ihj/ovnjA5ScCfB96e8cnzlZZQGecsB+hUHtWO2pgt2oBAD/+DdzmazO6Y'
                . '6mgT/+jH17IxKMZPVel5zO4tagVmnWCPv5BZMNQmiAAnPKxUcdgUnad0tddAclW9zv5FtntBSkPRwN8TprZqhplrv5StH49v'
                . 'TF3zz4SprYNp5ii5C19ILRhaM0SAgh+2qTjsj2592qr3a21VNXF2oKM6rsEny6adZjFHNhd7ZgbqXTN08dUJcfrqGTqHISVY'
                . 'bvGJCLWn+vrzXv/DpCkYVGbXrGqOz+pIf4FNFxjuZ/YLcEQmxdXV6pJS6iyRHdakgBq7tdwnRnb5MF2aKT09XQCMLYqs9Um3'
                . 'aWsMpvDG/JRWf332HKupy6lXN2VmzAj5v71cF9qcnGWc1ZGU4q42ahv1hsBah71M2dcE+YO8j07Fg+RBHugNUoppjD+p6tNl'
                . '6UWpzHuS9uT6DgOHFevxue/8TgjxIYqaTRv8niDJZtl63goM7BoqG5KFAClZIAryhSzuiT824BhU2PGk/xQBREHcfgxuCMEd'
                . 'T9OfSxj53e8yBtYQqBoihEKsddG8g6zdXAiFyGoGi/oziILCIB/JsfAldF7xAIiCfk19BPok7AXhGXd4QgmVlhZNI4C81lIv'
                . 'EAUdvRzGzTDx0fBvhRAKUYu5rOm3QRRkf2Xzv6n1v7ZfAaEQ3kVFnwBHWtROmr6gYJNerAyJpeqKptJ1Z7mrrvgO0I0y2as0'
                . 'nX8fEKYJC8SR+4W+S4zQGeCyIR4GMb2AfCWiViD4g82ILgwBesox6jBE19xHxlf4RSuiV2ASmxBdEgJEKJXdID5OoKUU+07o'
                . '7lW0rsS6vVomsCVdDqmDWpBm4qCVCA5eZjGGBiVHhBafrIPT+abqAK09ztfMPADza/x8m9c6CCJTRoi/A59VRTR66jrjgeqc'
                . '6vw2720VCZbuswtcMb4Pj+dd7X+o/B9V5+mKMvCF4dGpq2r8DcJ33sOzXDMv8L1n96TYjwKLy1pWYEu9WsaxpcxXsdWnZ+95'
                . 'MbcyI9AuD/HVcf+TKb3QengQcQmSSBKG65DDNXBg+exDTZ7KcAvfV9CAn2HFkAvgXoS4qb1O4LMa2HpMzXWXBmozYuV6+hRT'
                . 'UMhRzFtrJ4js2TE+uWpB7mwkbgBpRoICccmVXsg5xOo+Zpa2Rxp9TDRdRUd2aC60Ed6kospV9tCInGsABkF11uU8XzN7mtA+'
                . 'ti5FoKItVL4VEKogAjOV9bu3oLDImu6VhDFWz/luSNLvu2PkAOMgs5Sit2M9uD+/QoICkduYQCEGeHf2r6YdrdRzdFj65HQD'
                . '6WER7nslrGNn4n/J9l7XuN1xRTRhOyLaLRFvoHVguVjQi4t9l4T18gKxHK+txBg3Ls2IdZ758SMHRmFyH5GtOUOCUVhw9IRp'
                . 'KyCsx87F4hmxyJ1NSOPXp+YOuKL0GDhzCFnz9WnMjoOn4CThjASzWlvtUKfReyFJT4CleY8blZqKgiKd7GKZDSE5kGWYwB6k'
                . '04mgTmQLEtSNdKHUJEt9iaNRGaO0Mg60WGxxM8BN9CbdmVQ/bWGssY2UCUIgQIQJ1F2KivR2wATrdB7UlpiX4R/Aals8SmDP'
                . 'gYlIYIhWJL7OS0prQDKRrIamPRb32F5Q0T3jADQFllP03QkkgbSrPZ//QgRCBIiXfBj57mxOXbmDGbzBUoxemWID7p6Ca/Ax'
                . 'gUEYkg1pIhBvTO+5xhwhCjJw4SletR9O9OQ1QyezW5YNwO7i7S1K3HDU9APFUSDpCfDzKyn5e5kXQHvyEXz1M+0OSTqI9gIT'
                . 'MUWbjs5KhoTq/OgY2s4D0tfKZEPhy5dULwesGoXfZ/zdJEWPRumjdY4YhHpgeonhQG7Iq1cq9FFUmJHOBQzY9txt2HJsVmGh'
                . 'hCh2zW/QEFgB9YRJWJy9k2yx5SStgp7KEHPWT/IF5wGfpz7EKikwYOh04Kn17gaK2qTzwLXvmTTE9B6aC+X2AgYccGk7sEtM'
                . 'v7Kdtsw7JMQ+5skpFYcEO0bHLDY3v9be+ZjhvS6vmGg4IDUQyeOoKXeQfWy0pJy8xa8ZfNS4QADX/9hLGQJpgCMv5ZnaI8PH'
                . 'hmltD3NhtxsSfDkfBia2PpG8ASkN5bi7YG+JvvsnXlNNcBOt/+YIPI+pvneouL7RDw/VFsV9fU7NhT/xABqEInp//Q6AQIoX'
                . 'MkLNpKFLGRIBF7nv2g+QL/J2udbxuKtEyZf29Cbncc6h3g/dGcLmjCF+OwtWqw7p5w8Oe7seh784l8cN2FrTHK+zym8wGg3m'
                . 'JVajwQIQ/Sz7aj+sqOn0uaQSF9fM75J0gc8SrtOzWzTV2vwfwiIO9n6awVZlyJ/cDWUz8MC3J6HHVWPYdfOZuYgS9w4Hwy8B'
                . 'gHflbcsAgE+b41mf5n38CUmEY5wASNLrhuBZnAH1GGjAfap7b0u3OBdY8l7rz+OZxKfI5BGLvTC4t4RvJQmgvuY9Dy0dhWwq'
                . 'fMwxDTf1G1a2qvD5O2KH5YfQVi0LqNMQ8E4q5CkLHgFAi0jfcx/SFymmyra6nCiYkfklVDHjG4+VkfcP2x7oZqOLTuui+b2b'
                . 'gw6jefrtj2+JzPe8/nCA9e3I1rGISLLEheZUvuXGNZ7rM1EbipziGMaUCJ84vS/nQmL/YBlKBjUr+99Vqcy6xN4K6mA7ojeq'
                . 'h202FoOfeE+MYiVRMv7AKkzfxkgpjFpkcO49SkCBPYR9oOPEmmpo57s5+3xR2e/anvyKm83cmIx3c1ntyuPuZgUm+pLYC/J2'
                . 'wiHkT+wO4UdyW2Av8iE9Qv8/k4M+5U4aPAqxT4A+rSDMlJGAwE7FTB6SpoBseQ173lHbG9gaowVyRR6N3rY39lUhFYdm6N31'
                . 'R8jOMFkdu8sFNpSyc3EXS2y/FAlIBi5oIBSMkASlEAB54A2ZEAUiwAEVfICJyBN4N6wQv8N4eddF9+33uG9gveflmduq/uEB'
                . 'nhWiUDRBRdCQNBADsTTYUJTsVHL8ooMXUC2yNLMR6C+3FLNYVOJ3di/Fxk1adoAvAHD55vc0/V6Gx/MW208QC31HlsmIR2Q0'
                . 'n7fhl6HEN++WyGKL7kxFHYuZ5uWX0mp0G9cgjd4oaTAgfAkVVfcuxMVM7I1gAUlenxb9i4Sw14/VqpvKfkfZxZZTgROsTnK3'
                . 'p4D25J2ggPh5Zy9DP/MeZNj7dPNkajjlHpS0v6rf4/IKSG9EeD6Fm7hPE/duXGQhxfRbfvUkaHHSsZM2792cKHjuwrqRYilF'
                . 'diivwsrYsoSwLE/XwgD3+RWJwMTNzIl8G01aSekjA/4djI1cABtgPxyEOTAXnSZqa5tZHqMkW7S6r85vivxPZrS0OSt04Xw9'
                . 'VWqtTF8rBgJyrFMI05AEq1PqYwoM7MADgPuD/MVAhs4Ug6A5VAxWXHoxhCukGAbfq2JYySaLkUWoLUalFlOURkTAgXAWnkBE'
                . 'DSpLSZ9uwGayUT5uCHgsNxTH2DA+8ouFUqnOw5lGUZLvitQJNfqLGT2EsUxCKmCtkf4zpFIflJ5/LEiWGk13fn/UTIeyqjH9'
                . '1T0IllGCdt/2QP8U2tbWXVFWSVrW+ouGvypl6FabZgi+tJ2+tVv6tnb1KJnoWzZ+3MU3jbrGzd9JPlOtCdimYM9doHazYvfw'
                . 'SEUclMkPBN2a4WMVdf6O4Y4lYPhrjA98/4zojlxnefWb9gcGMSimKyQzaxUIUG/htvVpZ97QsdWWAsIgAgzy4aucn58pwwAV'
                . 'rrjmOhV/AQLDEj6ZjNuC8gKChYQD1NT8ZgnQhIIkkAxU0kYBqWjoVE+ganRD9ton1K/CSEFwMeifsNXZP5pcQ+xfxMfEohlR'
                . 'kIuHPwspXHVZqd3K7BGegLwQMnLV0IaA2s7TTDK1h/gWr1iTNL3tmJpojyy+ncNXSclq0aY9ea2cKfiUIjXfXPQBudx0JxY9'
                . 'sA6eggQLoW6mLt06bdBjvzRvpJOPgkXv/ew0zTJHr9ly5MqT77XjCkgIsnGZT5BI5JJkSMrI1BlnM7NQExxzMjtHa63DwKQh'
                . 'YeKSZnomb4KcUqSSZIknQ6Ys2VQs5iVPvgKFihQrUapMOW3dv+hr1KpTb5oGjaZr0myGFq3atOswU6cu3XrM6qS33vk7s83R'
                . 'q89c88y3wEL9FgVnsSWWRkMhJLITYiOZKectRIJFl9iAZVFb3gEHGRChChVLoHPBRYcc9pkjttvhtM9hEMjh9VtgicWWmtug'
                . 'oYq8bNhI85zildHmG2/MuBUmrIzsN787SkJKbESJTfRwWWW1NdZaZ71JG0zZ2DJJBjz0ra9947s22WzLz7ep/6tUpZYJBK9U'
                . 'GtVKvTLMDDTknkVN8I3MxK+/KGOp6WaAo42tLcDMRmNSf0mgt/FSDuObNzJbPuqtfKK/09+ZdjmwkZFP+7vEcflB1fiXKtGj'
                . 'zC/b/CSIYkGxq/nCkYWv+HY9JksbKct+vUoc0INgqbN2Zh1nutwWZ/T89kf9F5YtQJLS/fMkfYMjXPuF5+Mq2YwPr9XbGadF'
                . 'yzx9n9JXtPQFpVC09EEfKEHR0g8odApKwSmAInDhmZyLUnfNaJO+6Y2t89javWbXnNRN8v/QBiliU4xSE9ssnNR9y4pNU/aG'
                . 'acxM3+McTUFIXPxrcfpMtm39LmiNp94gSbC7xY97AgT938BXGKZIa7fzIPko25SW/IWnqfgbj+wtEwLeqi0FV2KdGWZYYI45'
                . 'ZlhggSkKI4IZqLcYHjFhmGawIWFUxcwrBmD2wpxYDJjpkagEPAIwYIAqAAJVA2YAQNXQg1IJeARgwABVAASqBswAwNUeMHFL'
                . 'O6vV/oXKaAsYZtWE2jA5RK2HXXdeS+pP08l0GhkdY++ISmG92mHgWQ8q5rjrXg0zNMbdknlJUakIIqtxggL6KPEY5KNMCPVR'
                . 'Jhj/IPYEfg5Jk4o+akQeSF2OotrtLKN8M+nND1iP2Hs3KrrS5fE1eBC3CI6T6bVe6ZCRyZTYU2IHzo+NnA3g6gZwH7MWIN1y'
                . 'VaYl0c6o4T03qvUIcT0cuZ+G/32iIzUA',
            'family' => 'Cormorant Garamond',
            'style' => 'italic',
            'weight' => '600',
            'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
        ],
        'cormorant-garamond-vietnamese-600.woff2' => [
            'b64' => 'd09GMgABAAAAACvUABQAAAAAvXgAACtjAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGoEdG5oWHIEGP0hWQVKBNAZgP1NUQVSB'
                . 'ACciAIJsL1YRCAqBvSiBlEwLgW4AMP9EATYCJAODSgQgBYdEB4l1DAcbzKYF7NgjbgdCEdGtm42wYONAIOyNRkXNopxMov+P'
                . 'yY0hgu2gVtUHpFC2s7pLjUYiIUpM52LBwj7kaUHC2d7XDhtC0UqYK1JQUo7Z5kU25pUgVit4hn9v4ZGihIZPeVCUJjsqTiia'
                . '/5yb31mRpwfy0frihosAd3YUHXL+PL/NP/feRz9piRJQsBGQoT1UDDBym67S6unWrtvFd+5XuP2qVYTrlPiHcd/PuU+Im7cF'
                . 'x63gl6KVoK7Q5bipf6cF1Ofkt3v3kqYgPPOdQjiEZHAI77B00q3/2HUICQQLETTga/Z0/0l3RXXTX9N/l6Y572prxSwAhurS'
                . 'mcQzgfauFBaHmK9CO613vosIPe7Fah3w8Hi2+1P7QhKSAIFhwk+bQ1H06/2zqbp3XXFdHUKY5LH949rPd4iGdhHdvYlBmqKW'
                . 'v4jlxuC8nAdf4DCd30OInqhOfxjGtt9923w+tdbz0lDw4A0IIBAwAghqW+yAPz4pmW1ZimPwivOv01z/tw7solPU3RXc1+cC'
                . 'OoXd5sIEOAO/Xda3cvqSnSgpSfW1VexjF+zAe05RZ+vLDlklBDkpJGldABg2QN52d+vYYewwdpnbdanf/36tvnl313FfHRJL'
                . 'T19sELUBb6Q95ErJmvD/f5mzsxNK1+CQSISsVmxLqbTaJsN7786Gn3N+hk8kB4UuTW8cpapeZISiFonwzSOfXNziEFIiV0Yi'
                . 'JPDwf/50W91deifUAPfM+n6oP25Zmv3BwsJI54Y4ULdn+sh1pcqQNei0nKuFHOQ6iJBZOt9jElRtmUG8io7NAif2+UQWf7Mf'
                . 'V866k19RIKmROrAGnvoxtnpw8SLn6ode9PDARmzc6goIIRY+IEEAvDAC8YOYlEGqNSEQDEADgIZh1RoANGnChCCNEAAPBIDW'
                . 'CwACgh+sW/qfVMY6H2KCSJ5FW0xTs3kD+JSLMJIZA9YcGGKLdEYlAoKAiwsgAFwulaipz6wiMO9rra8E86H6URWgOj6ssRrM'
                . 'KNDAptJm0tQ6HPuur4aAiqigOVFRDANSABiFgYti5O2CADC7X5/m9Y4NTMLHJIRwIah1nIFQqxYaU5p4dkF0+IjxtQ4uAIDQ'
                . 'K4D4yecAbxViR5kA3iJEQ114zwOAGOOogs6fJ7z3rv+MY1uyyQ4A3em+uh2di/eMUJDMPRscAAibEQBHbcVfX4Ka56GNbO6d'
                . '/7oX75fZMLNn0pRO8eSMe2LHOubRjHjY5FNf9F6v9nSP9q+EYfVv29HFndkJrW8pOg5u0fxXN7yodTSg0nLxu9zL1fkvp3Mw'
                . 'v+X7dGZ1FqYlpSlOTtyJjRXV9ZGFRh98ZrdnYfrtYXf61166RL6U7nXO/8sJVjrcYnN0G6tVvTJp+OCZbpedtB9xo0yufjM6'
                . 'frPW5SpQupY2p/O7VZOLXfzfz2q5h50CCcEVcDMTAPMVQGK1p6y/D0NC4wzruxTi9H6CgJ9I23oGvL1QNVvVRLvJ5TtES/3L'
                . 'quiPOhpitCcbeLe9/VQZdTZNa7/kbhzOO0BP1eT1ZTcd2T1srVy0WN0/ut35UFCb+uJ08bdlWu23PXl3W7w17GRduJ2sxN+F'
                . 'eUNHr1GZPNCNUjXZTJsEJv60TOfkuWqeFc/O5YM6ljUt6lxYOczjtJ0vffHWEnLFKX31umrgVs7z8rQdwI+7QMkfqCgVCLuE'
                . '9gH82ZeJ9kDnyXL1qmR4NcIGc23rf0BpQdahOpyfvGQYweUItU08oEX1na+B92+cq237bESHwM1sAALFO4117vOdZwi53ZfV'
                . 'PrjfI5kYyWc5grRPCz1rNm/zpaJWjTcd3p03sbB2xJe3di0tR+s7VjiWujEDvLk8COo0msZiY5OWd0WkpjmsN3xkyutOYM28'
                . 'ams+3E5587LI4cL61AAq4ivKlNRJ132vfex11/c7wmc3Lgdp4Us6hM+ZxDvrZFz7EX+d1fsmc5PvzgZaycoZviiPRO/9s3xV'
                . '31qA89Q1QE+Mtx1F+/t8Aha8ziVcFyGN2/sb9t7IDgEHeu6uk5Z29eGarJ4jGBqTHbKIgEETUpcOEylwtJUjX6cW1lcHAugL'
                . 'IA+AF4AbgAOADpGethkCrN+2lrOAwm6RH2ybsA3pmEsbP3z8WqJa62lVp0DmhrfU03LF2nF9hGFiYYinvvIBJ2571dshyHl2'
                . 'n/S81/rk22qvw5XOU3HUu2kmPj6zzXpLO78hSjEDL252x+qetGpW0y1WUzKL4Q11z1+e+aTJ+21UTmrk3hO392xzGGhAw4D6'
                . 'nlw5KyI1l8LKEVNJooBIhSTmsVneqrf74Ims9BpAmr4CEE/kzS3syVMWp6D0GFBzy+/tsm+tdNtcyfIKmCeZEIKdhs79CPlY'
                . 'bXk+djt0O/WKlt7OoAKyQ0jYidJ2hjIFigUL886HKKHdVyQ5ItHl3R6NVW/H1YU2O5h8FfnaqUeaIn7guRT3gwAwjSd81kS7'
                . 'JMY+9mpiCm4AZjoIFpAogZeI1yTMV103jzyvFBpfOGLPC0wCGdP+78q00KHsnrfFgDAp8gFQ6oaUz0L/hMP1PAJ6tuYVPY7/'
                . 'coBsS2i34T3vmmJGhd1Jt5a27u5AWyGOxj+Rk5cT+AXKd/eeG7Tjj4R7/s+9GELnKuJzkOzEDzWplLw8RO25aVg4Mv4JkC52'
                . 'DDl6tsp382rhSrkCuoFBTgZjW/CLV0VIoxkTJ9HzfMVfRtc4QL4iJkPvOdhwZUIAiK4AAPENpaQUFIOiKAzZrClG0uADN7AQ'
                . 'kWGnYNpkNMIsGFcIJ4YoacwWpzfE1cCW3JktRTqUk0LxsoGS1UDJH56jQBFaH0Mwc1V4hrEx03CjIWOSbKWvnJbD+JDIy4ai'
                . 'FMwkIsqDYovYWiR2IsuCYopcdZGlSzEoToLxpmj5RMUIFSQGvR0aTkoBzX3l6HEB4hY0hOir4+O/TVUgPV4zohL4AFCbcaow'
                . 'm54MEYWodyVHMUAt8nDWHlJh3URNk6Jpng9g+VATmJDlQ9sJ+LAW5XI5Di7yYYxnZyiKj6nNSPi/6Rwn6Pji/xABbr/FjFq5'
                . '+e3kvVOlMQwGwPdSDEAmXjFaxa5bWLce5ALA2J8b8LRgK/3kd411/1OAmlf9AvQMAEDXUrfONAI1ZlQTyBwuAD79U9qIeADv'
                . 'wvCqIhoQmkIQB5csCACgoDAACAAcghYD+D0Q27eFXtAlO7hY7EnWTSA4PgAJNJvM/MzWoUGI3npptsBysAJcSZBkpAiEbVR0'
                . 'Xy4dIYJPr0OCRAZb5ka7ighFOMT/4+1L30sQz8NIaJClOvWnAmkxW6iOM3r3DgB3+vHUzgE+LoArX7KrraKho2cUAADAuPzv'
                . 'AxCiD0oUgVgmeeSS+fGq1qCXoZyGsRrAbpBIgzkMYTNQqL7CFbMoEaG/MP1wjcFTilaGYTiWkdhG4RiNaQS+GD6i+XKRSSIU'
                . 'RyyBRCKp3kTiKaRQSaWWRiOdkptWBh0PvUxG2fzlCJDLIEugAmb5ghQKVgQBEMIQKQbukQDCWEB7rzMgKADcygsRAHS/oYkY'
                . '8G+mjVgAx+e0LuKktKF+f+5eEghis6/1AKAzgH4bML8GO747AAAXaTwt1AVFKFyI1XZFnHcZ9lphfxSMJwSqMUE5ceOxPldY'
                . 'bVEAOBGG3EhNmrkTEoLCokJCcKJr92fogRDaYixcmOwGIDogA5wHxguz4g1oRMMdExq6G4DeARngPFBeKMY9RpIAfSiq8AM4'
                . 'iFevxFzBdeM1V1af4J5HAERQePD+zOrPTCMBwG4yu8UFyMUhYEgIeWCPRGhRXACwTRQUIxgbj0C0c8BVYDUPyAvDuMdaSACE'
                . 'QuVBBEQW08B4jSIYAxE9tuTn4Fw7F+c8Z5ZIphESAO0mw5YKcBSHDkNCyAOVRdrP7Jv6Me+Aq8BqHkhUZnOuBVpLG0KgMX5U'
                . 'AZA5PDrsOQYoPAQbyHaFiIMYsCzGOxTFbLkoQAMmTiKwSyhYWeFWBaYikuw9+MoTXTUnygbe7RYpHExhOAHsuyb/R8AmOkbM'
                . 'ta7G9PUX/K9a7PnqRvV9lt2mjkn84Rfx8Pf9Vo0y5s7JwQlftVBWjfr4bs1mU5IGZrh3I/XMWCVVw9FUkZFJ8j8p/rjv3F8D'
                . 'g0l132v+58gHxtk+H+DF83V7ABL/w6v2B9xodNTgrGgKOFF6wL6EWnsqg1rVi/Err+ZmooBKL8CTMYQgpsOQUCBWArEOiJJF'
                . '0CjDKbKBZ2VVMC6uXJdegWyS3Edax9TV67muOrPeOss9d0zVNIfGuEZ3Xqo3evKra3RI7EhK2ieNWv+c/n5OZyWjqxrVjZqS'
                . '5BlAUp+KTpWSrrd/qd7esNSB8cMrsqG6QdchcSNJtlZ0oJ/+HqfzfoCphGDhP1d+OkoI/BZFTWXJszZo1EB51wxC0RtQOUPy'
                . 'c0ISYEs9dj3xlOIFDpvbNLVO2dNNuDLk66ZWGV+wi0gaCklpkBL2z03T0oA9fWSRYTtOVVW2/sjMqNUzZdU4HTh9+UjeLw8R'
                . 'LAL5pwX8lJ9Xl8UOZtB3M2rSs1DlS0M+FSY/IWPF6PWW9W8wAoJ4vUiaCJW6/yA+TANwhYRAkBfF5j+RkWDNOcMzPsY9A1tk'
                . 'pwcjJKHgiMH8gj+FpBteaA6LtmSkXvzm5Qnt9Zhlju0GRnawSZa2KU3Z60N0ruSuXAcbVsQcMTRabpqEP1MlHJ4o96h1dTTb'
                . 'zystbVI1pmoW0/nVTas3LmDk8H2Ml9/uhQGj6Zu/OvtCWgK0oHEEeEAKR4g3gZehA9UIGGAXibNBZ6kmOkw+5w/jqJQ2i7ri'
                . 'BI7sXn5FozL3784N77OTqtmTtgP7mTcXvGkhYiumDpe6sfKCNhhK6nPk1y3NCEFrRivYktEQftolaXUgNgLAZVqiL5I2j+DG'
                . 'KjDV1OqGhPZASHKG+jGD3NyHL/92osUuytDIv1bgjA225fVpALVGNI5GasJzOv4uXxCFusYIuc8ZSI4ua2wofxYdXssHZZ9q'
                . 'Dpo0MZjEoudVLCq12QDFsuZqocqYD23dUkEN39Ino3ZyPUg2/axBoqvr9pCBQb/AYMgzrc0YXv7NBEnF/6lCuWsEgEdyVTaN'
                . '7FGCNFxIJCWYkRQqlZyHW5NPi/P6g1WAQsJS66A2j4dPWGPyqkGx8Bx5bDZMxwMCsLRZNdlXHnEWrzTvsTZ8mOHwwTqAwQzP'
                . 'DRdMkqsJhkv1XUXcvbERA57LVZXZVjUk49PTmE6CGorq43acdGkMEn34uMCwX52Vx+k4pd/M6EklWu8trrIMiQLEnSUSsBnW'
                . '3QgSvdGvJXVbHtIUgAX/yAJWJLaDQT6T2zYkNKmtQ8KS2z49ZKgMr0jbqIR+JBK37UzZVouAjFDLEouahOnwZeTwDngtBgHw'
                . 'WSaAYGJKJgn91JyREOzUAIARM0CWGgn12HiaWv5WuZfcBoPbhikKCMqkTIZVHKP78tLct2zvs4mJZJNYnj4xY/nZcO5V18Ja'
                . 'E/7YoRM3s0MM3r7b4c68oo9a99HlL5PN8DS+MNzKSE2LflGUtsDH9HIIaEqjjtUD/wVwXG9Kwv+z/2MP37q47dIG+4ZL0022'
                . 'gX/2TZeuZm+4snmjfCn10pZLm7KvXdpo//Dy4yZ71obLnZtWpIs3HmyssFdku52StE3yparc4wrWGcVpqZa7FhhGuS36/iIg'
                . '9Zv/eT/rtIRLWBBNC9mES7RbeSRPdPmCiZSHuZgtZDBpwvBtX/M1XrKPfKHT3C2EaJ6etToPHSwXXD88/GMaRVOaTWq/HWqc'
                . 'V/Sh4Gu39FuPzF/iZtAMncTsv0lXfWbUDf69j75K4OA7TzOckSNPv3mtvUS08ptUY+HYs0mLb+TL3rbPyLF7kyafBB5OFArC'
                . 'JGw2Edk0+Qx//WYFprG57JD2iPZgfzOmsaJTr8+gVBWf5RbP6ZvZq2Vw0fjwHLpoSrKnbXCxe/7Ewn4J0RHbQ2fBvvXnQUpu'
                . '63wOB7UQHnH0D0s1eQ5qd2kOeEwDg2JSS0kXiaEy/ATp98ILre2S9ZbE4f+QaAVimUqJ6Wu7Rw9w8x2jBuj7CisSKhMg9zed'
                . 'LU08erJcdF3OHm2t+VXXu2zolKSIxSkScaTYzEkpV6zWqb9aP9bpX2uszW8CryP5wr6Rfa86Yrcb8+7HCYXVNP8L4Vj9WzaQ'
                . 'lwuE0TNfxou61HYI7l4odbWKRos6ZSL5/LUqqX6NcKR43DfPReKKv2OoBLVjpGIjdMCRG+N4QulOEetEknCW9e0bGD2W+i7+'
                . 'mKvG1cCkaPH/D7T/lhXhVkv8DRsVhCaV2b/cTGgyy+DfKbfDG2Apz5AgNs1VXummGJCr7ZgjP3JVvIxGe0FJWGNyfmLrmLzx'
                . 'gQneRkd+SUhdfFrU2KElk+z+aXWu5EkDhmQsnlZQ0jh10YS0oHFZA8siU75aCzDYVuw2yuK5hbUPM5pV62Bsfm3D1fBrGR8D'
                . 'EIqrrS2JQbClHC4v3OMXxGtfxxdN9ZGohH5DloGVcPrPsMbqZXmWGYY+5X+4koVjTPD2m3O+VQNcwlk501xqSha49panGWbm'
                . 'W5jW2Kv9oUZRFx7ZEc0+Y/UF9OHk+v2RnL4PcSzr/DBCE9l1jfy6BNM4qNKqy42pKo1J81bFxpVn9GZIi5YajJ2ytfHJB/yr'
                . 'VTVXTRwxcGTDqEsXJSiW+fZL0Yh81vQixFWVx3g8ZTEx5Z7TAK014wYOLBvuZ0hJJny5ij9hLlfU5iNRi2T15cVtQ31FbGJL'
                . 'dqtBty/fllpwY8e1SjXidzg/KJBFunh7Zru2MLGPG2RYe//vd2GYxqpnZuV1meZVvvgqrPPVzSH+Sj+TXKtouEh9dYkI0zg4'
                . '/+XmnsQfaf8tMROayJcYDZvlHMOSceOrAzR2ep8nHdCuATFPOrS4Vm94Onr36KaSYefFR8Qvoewvgt1TSAea4PHTeSagDjLF'
                . 'gTXw2iLxKLwRT8gYmTFhlTyJmVXcsGGwLdcu+eeSyEqj4/yKhRbhODS0g6iJ9y6ho9j07bnivHUTH8eHNyOHMgJkMktiofkS'
                . 'poUve64hcP+D8tB40jI4auuLXzf5fj8rHQpOjwgpiokOKUhx5qpN/ntXLprhcvZy5fQvjPYvvGS2pEc+b05vTAv44fzwAGC4'
                . 'O99e9CaNttoon6wwZsBRKacf05ZpbY0TEpwCMZjGwXNC/w04KuGMfm312sbH6BFOISxdxH+hZSGEJqJjKuWxROKipf73tch0'
                . '16grzFQEZHwjJ3h5ZMVKqXUNjMRz7WssGgO36Li+MZah0Q5T5lWPPCC+T1z4IIcrZsBg27boFG+/hOAh3ih7TO8cb1zQKE98'
                . '0eVaHIJTEtAK+o+Sz592hagTp2xxb8GdKOUN5UiNy1TdEEJxIlNrw2bBYjvuXr54orwqOdZVdj7fJLHiW3P2P2CE1/L4VtyJ'
                . 'oh/jV68KJGhWi9JT4O61fLKZ8N1MEvVY1IlPxTtlcihcw77Jt8f1c5h1QsLa4C0igceWaS21CDEuJM8vVocE51vveX1KHBvn'
                . 'qo18KW+VOSnWVhMpxKyMwD3B23/ux3nIWuCiFo7n3FlMx5hm1pQbtWt9WVfrKB7lZuT6K9hV1TzpYh+xSiDdNN5TwKR0tKjo'
                . 'p30nEdYpQ7RaCzJXGXp0At3KOkufm9nKul+oMbRFvLct841z8Ed9Tw5Yl63unpDlPySpOi08XLJNQtNcqTdUqLzkqPIfLGuP'
                . 'GzB3j3xBgqdGjKSYxn65v83TOUYa7n840e9no9F3yfC4aGuAtKRFIdbQuW4dFFcNOlwrZ5H9H4Noho2iY4K2JdZolUyUqGyX'
                . 'Cq5xRfaqzcrgIsu/AcBIEpNik+Q2U6laJPhh0ASLpiksvaCRJ9uh4TVzks1qr2XRoIgmqgNbEoPMORbcQXkivXa7wwu9m87r'
                . 'R1e8Ijtpyx8di3VasEO8Y60+kdnlDmMYXucuYg4deAt6c/Y5xN1ruZVlCJWJq3CF1aAr0sPn7ZpU8tt5ZRHTsNIZ316vf4RO'
                . '8+JynIH9bAlRQwdHR8dMdPhksiKy2SHRCUXeaP/iVLvdf2eWqFQcnqb8lsu7QQS7bqSuMKaV86fTTQakKcBERkqCMPnph4Ln'
                . 'pxrQw8KTi6fkSgq5MjiutatBr/+x2wOOioWfbJnWCfE2N7UoABju17Kk2KTCBqJUEBZRKMmBOH0tNzDKlpHpb9J2z+S4mlxR'
                . 'vYyVUbEx7lD/4Q3NrBPL05oG+7pJIJSY83+/lhtsc5mfhKT0y2k9/kq3/9BLftuWaZ0Yb+hDOM5RqUnepPgoc1JEYFFMdGBB'
                . 'kjNbbdKdnbmoZI9Ln1WUPsqhTgA3r+Zr+xxxofjjCgPNZth3eth1xCvnpggDjrb9s2+5ScLCyPu+0FBQnue9POX5C47Za/PY'
                . '96YljzGmlu1L/rXYGR4S0fl/IQRQQTMAQE0AAAjWx3p2x2yb15OdP8smY7q7j8Y8Q2bZ7JjXy7ma15p1cL3cgXvAXrRxPcGS'
                . 'snJZ7axz7AB2NftPjoRTxfmD80rbcKdyj/BoXjFvI+8h7abX07d8gn3qfHbxVfx8/mr+HYFJUCb4UnBDKBcWCdcJb4lEomzR'
                . 'ZNFvoufiEHGLeJdELKmVbJcqpc3SQ76xvl/JKJlL1iTbJveRD5fvVAQoZiqeK0cot6sUqrGqa+osdbv6jSZJM0WzT6vV1mu/'
                . '0T7VxZy8/HW6v3SndFd0d/sCu1zfJdFJ9Jpf5QIdwHZhihNcQUAPAOARCRgQUi8/ICO/gQXXHGjUCgySqVHnhHN94BzVKOFa'
                . 'Wd/uxPhp/8Q3cbud/CDIURRdPKIL3yeukaO/Tun0NWtaWA5pEZzsK2ZClmIzU5kGyvJa78V0+uIbRumLv2znsYry+i/rLCDa'
                . 'gPYz62dDuxxrwJ5Z2dc7VS7AM7rpTVVuXsnJdFk4vQpL90JyGU8EkXBRKxxT48M+fJzknJOzvgD3uSyEgDi1eySIlxvoDb2e'
                . 'yYACPLbu6DK7zXDw5fPNpetS4h6ze/9Nf18YrR51Sgcb+FvPI/vX+6Bwpw2r/1vPsmjMqfrvtD6b5Z4p/uev9f5znAF6MMa6'
                . '2yb+B6+jIaYf7zWWQB+szGq3s3aVhkR3kmsvqJCcW2Xl0haOY9GSSrEcqV8I4oDcqrOcTdF9HARHOAn/xavR+lIQx7Ku26bK'
                . '4jDiN/Aj/iMVyogPQRfdZeozJkIPPJnTdRpTdWINfzXjTzzJ9x/rLW3n71eSGwajlkrUuprEiSzb6WwyyVOZiIgf7GcaqSvX'
                . 'UJMtN5u2HBJeSwEnNrZNCfEsu9KgLBw47KKrU6MIQXFWRfxwgFkqihjhnOlUrCTUlLvUEZkV4rl8vy0+lEkbnMMOnlRMUfxs'
                . 'ItHaFfHlfWInIdJ9UFHD4OAi/guVosKV/UmxLoLSFFJM+1YOd6p8C+gb2MmfkMxrtCSxUkYEFPuHaSD5OcCPS3Yjqi7RTGsK'
                . 'KaFqNO2ItA88z99vYpZtlKDgdc8UZcizhMGDYEXwThwMgQjJ0wb1xzYYm27KxMcWHh5d19rBlv/f+UhpKBtj+udq1xVm3Cnb'
                . 'aFF+hWldRhQ6MS3PX41q6/j/cz2SWN/MorGUfUILHoUFEoaTCz7UizS6imvLM1VH9+/lfiVL+fYCEMKaaQ4SErNZNHJzKR3d'
                . 'v8VyDAzOVl60Yff0P65nDG+ebWWTQQ/oQeLAuk7pWcjs8fULULCH2TvXKqQ+yLhnE/ry+C1l97pDa5fWPv2CHIAS9EDEdnxb'
                . '//TL969nFHLRA6dMkPYUQqMc+I5HrQtAJmvIle6aza4iJbZR6m/CyjiPLjSzB79PMaISDpmWqDGhSkjSFfx9WBvoeWE8eKGz'
                . 'FAv+BtCLeMj+B5YV20RV794w2dDTbI2bb2pZGyHEQboBHTJPJmRc+Y1bj4Z90HQqrB2dOn937R44KZTzRc5ZqFfwD4aj/60t'
                . '7O2jx4uB38sbG6KYSxJ4X4/JOApkOcn10YCsDGxDBje7rgYBcGxuzxe5sSPPq4MPoVixRO8j26TQzvP2hRizunfSTTbx323l'
                . 'fLR0qrmLWFFtmD9lQMuR5pwnsq0lNPm7Gp37uAOHUS9lDm8GCdIMzD6WPPO0sEo+/v8sXYdBW0DaPmCzMnXvvWZVpc+JvOS6'
                . '8rkG2U48YkPmhI7YZan4ixEU6Tst/jbhlh5i4uGt/xl7XR1pTwsaKdMWsErBq5Yc70/2vZ+jrbOkgsmWcbJGRD3GD/U2TqRg'
                . 'Ez5eumTBezwgOEMMNOhSbOww0SsaZWMxa7CC0RyzV9uXrflYdrf8OSypBv/bmWz8+dJrzi9LdXGoQMPggxXza9Pns9tsrDwO'
                . 'i5evupTVQJSCjm/6PCibKU+yRcupXuq8CXRbW3vqKqfYcWCub9luP/F2hkZcm8LJiH9nSyT2aKVBDpWr4OhjiM2yvz4NCdYQ'
                . 'iWp86ZGpWGcHr/Mi0Gu9bVbJfcde1iZqPaGUdIQzTKkR4DQUbTvGJ2RQtoY7cn8xViulQ9FRlRWYzLmPwp9MrZwnHw7dDuc0'
                . '2737SVGEA2OMxDANUimJD68Wi4F/LP7FMyX3XfFy+a2wd48pfaU/6+4ts181dzC9nhse4xs9Ei/y/WFKdsV/v68/a73pS27g'
                . 'Fd2zSh0gJHXb9GVg8LLeGaOOEhocqy2w7kT6t23IM7TNQzBH2jA2x9p83zRqozTQuez/Dx7876Xmv0UHr3xC5AQvM+1TuGEO'
                . 'sDDEY+9/lSQ1QvQXNzkcupUnzAAMrzrffQwnPceJK3ycI3ruv3ertp1tNrvtet7WwY1DKj4bSx7PHJ3qpSDNHC+33LlQ36OM'
                . '+pMosbUJaLSg3m3anOBTIyXTZtXtfL3djftMmzYy/XOsQkuA5XpcWMdgN9kfLVfL+WIOr3bRVVmuFxN7wcGG/ZuHCL0vXVfY'
                . 'lzMX5lzOPROWJWE7M9hYW05KxbaLB/1O4K9RnX01/VDXq0yAnA+DKUrUrsVXrhlgVoiX80hgfBqdTlpnq6QpIjyvYuBukQKp'
                . 'fibjVO7UMeahwijeqa7FjLrIl+/Df4Z2axzKJbUHc4hC96IHkpXf4AbzEM0DYkxeOT7/5JmICvVJkcuIU37jvTQwOGJuB2YK'
                . 'o25F2EnVcm23EbWk5Fn7wpp6r4wMo/Qz6rJi05pmiMeNlV/Fp9cnCRV00/1kfbAZJqyiSy6kUxwz2w8McDNY0Abvq//CKBP7'
                . 'uwWyk3WYWQ0+xHo2n/ZftqwkMbXJeu/cSdUxCO6j8+nN2/ezlQe16XqStkryA9m6M2UaqYh+2LLxiNQPIuCU+K1RLRQ9cB0M'
                . 'ajVX8ZrZOu1aGwj7wboS3ArmFErvPVHew8H63Ivv+h4vpuGaLVZ7u9Xhpg7W4MADT0aRIDa0GKuF88fNG3pDh8LCvRjd9JB7'
                . 'xamVMSmVhDc8WOk1oE9u2RQJycBJlThyd3beJ8v7iblihOhVENPCOUcUQIdWS8RcNbgIiryG0UpqNx7kczScYgDv3CJXEGgO'
                . 'ISD0TVLxoBfmJz+d6PF3sHgxMI1WHRFHOoSYYOReUaYLp7q/1fdbHuWILcYMarOU8xFNzkoD8JLqwoViPLx1zThfzDZSkhGQ'
                . 'p8CTxNikyyTwgKP7h9MUcIRkm1XeC1dhjsg5hzqGHYcEQRQKv2/Tho8HdWCWJoaO1YKzXmeqZFkDiHkL+Pj484GF3+f/yWb2'
                . 'fil8bdpJCSlqnXQdl/AoSeOYesQVcFbljnkrBzyaHBMSmjUtOo4aSuIFIpRDznHbsbH1CnZ20U/Fdq2JvYh5pYrOwUkF5kTY'
                . 'MhVzyv+vJKRoJbIahXIi362zD7WpuQ1avBtMUby0UVbvd1Ghtg4QFzuKBl1zk80i0Y/a4csYaIopgkkZGwXIdDfkqeHqwSx5'
                . 'W3/YseOelgMUca5ggdL8MDDKB5PFYAeniWQbHFnRMw5cuI78jkO9JdjKv3EeY6V0Imhf1CsUkf6etgxLZiezu0267lYwIGYJ'
                . 'l4b3uD95r7iY1Q4nf3CcVi+srY+sBqFuCXsHsPg4VRZoKdTTgiRxY9orayLjJXj2noO9ACMMsNuWKDpPFOdBnZGHWVaOu/La'
                . 'aHSwTX2qugyZa3QN/64VHwyptIMIThtEadm4m+u4c+115/JpHVzCOzmTx84eIXO+ttf5YPdbF46c4HCE3D521OZGYKZuAcv3'
                . 'PUIoc1FgfcGoY9RmLHiWT5xOtRS6HsuVK2/Y3nxEmmMGlzL2W64dQ/qMJ+UZ27YqDdCa1WRz2f54htL1RwxpMNhV7ZTLdnw9'
                . 'RYLBHJxDTK1Ga60pTVbG8/gI7v1MvcSIXifx0D/OH7vYggB23DcR7/I6pe2TSn9ZrBrXYz7/j/sO+3o7HHoREfFjXre7DT1z'
                . 'oIVTBcfQa7YWlByfuFEiFfGjXrd07EiCVL0YdixvOh8e0c1pz7Z9wrSkCfNZQiWUk64ELSWh7kjBamE4p7GA/9moTomzY1k1'
                . 'mEvJMlu9gds+g53zca+mj3pJtOMW0/cIhankUMp2dLMze/VJqfJeaVIc+VSYId//SOcHXy6+SC5pylBeAD/j3y+5rN1/XQgq'
                . '/htTRBaBSOeE9ZgH4CaQXPi4FFggTA3DEAjHTPsUUkL1EZw6Ar1I962CHTnHofgJs4K5z2vidUfWWRkb65TuWKQxH7LIjmx5'
                . 'pk7/IK9PV+knWwpqqnvtU0DLHo3ocH+jV9p/cgwGoaZzlutenNJY4H9Ag5Hf0ZivCTKYj5GAwrhmjVFkBIeh7x++/bZSox9C'
                . 'PImEB434X2Pe/QF5T4NFZoRI5xn5eZPb5ZJAvXQg6h3ULu85359TOg/xXo6Y7d/LYZJkTTNp6zyJgrMh0r+p6uK306B75VKF'
                . 'KeODyrRO+wcqA7k3qbvzOC6D0Nf8aZ1Etc5Sn/JI5kWWBYxTYfoxG/y9KBqG4BSROWE5sisV8mK5gNe76OpcbZbRpbmzrcLs'
                . 'M5ZJYLGoAMAdblkQgutJIMZ5UYmMy+fZZSbTdLbHz/IcyMwHazHl/dj0X0ruiLd6BPDzCEe6tydlHVDLnKDWpME70ztd3+dM'
                . 'pXyzDzVFAcB6zOkfo7+3i3evp8RZlj0jVJAGEU3fD+/zVEU/KZOtSxkNJOVS5iToeoygLg1ZiOSO/ii5TT7P9I6nS9SsjVnM'
                . '5EJIJXcJr/2lbwZcZP7PN9kdRHZvuVTGfl5/avE7DJNtgmrIRpvC7CvVQThwhbxr0Vri+Pstqi/LSZWM5I4lHONxSCA+sQB7'
                . 'Sv3Bm1ncu0LOp7/WTwBdz1jqORkeYoxQ1LwA4FbNmd3bkTDer0JNQLLhFp3ZWbSsliyRa3NUwLRopcuPH4w975azjdalDWeD'
                . 'doMg9VGmbCgh2Xg0CaLElXQxpLK+1m0Mo0btYGmY3zGjAzNlIRTCXWZyraWm1Nw1fSDXFFbG+RI4qrKrGmZ0pUJnYZqlc79x'
                . 'vteSR8fONg4fpplFt1HF1LHHrM+x5GZeCUcnr7r6oKCeW6x2Shwol1ZpQ+NrtbVdX+lDrTyX9XyVDAT8NDztoTy5dkM/ENVu'
                . '+XGeuTB/6mrtJl2n9Y/90sx484x2jzHxJoCSN6crMyofv18T+lV4ZV9eh6Cv0Ld5K13eWx3QWj7fRDO7SIL96Jth2m5MzNgn'
                . '4+Eg/It7/l+jEIyu0wpMXcefiERR8Q9AQ+oUh4pDCAB4F20fdIGBMQdrdW+ZAaX8uQPSdeLKbqV8CfCGVUY/bVS88JXpk0cv'
                . 'Qe6hbww27ZAuyI2wIXQMoHskAOmsH/qOcbUbXBh1kdCmK2VmMt+xugTj8nmWSlkkzMFkWx4PxmcH5WJ5cC638+pGuAOqjnhV'
                . 'PQKkWFAI6l/L0HsABeYW5GkIr2GlLHXBhaCe65g3Eh0gAJTki/UT1WuC+L9DSDcAcO3WJAoAbrxKcHvap+X6oesAYMIAABAI'
                . '+kYzEmPi1jHuP4puwvA1ecngZGGqv5443qW7MJYvIUvHS/h2q6tP2Dbbn6g2MuR5j3IzFFaPwBQJhu1WnUuYFr0HDviIi534'
                . '1+cdbL6biMcrVC9Mdg+sBP3AVrxnBcDIIkasYAmMmBCAZt2E+77IopYsfCBQkwMAn0dM3Yg1190YPyfdhDuLyymJm0GVZjeT'
                . 'JRkz+JzR7IYA6DCdGwHXhzIs3w0RKwRuCuz2uxlA+8/NBKuFbhYITXKzwcHr5gBbspsLYWTlvBzcNNC42MeNBlCgRpVhqnk0'
                . 'LkNXKjNCihr1qgYo5myUzlLE5VYbKdz740rY5ZA8Rfmq7VpawM2N04AvsIpnZ1pKngo/cSOK3Qh+Zm6jOf0rOYqumLjy4eSm'
                . 'wAPJ1Pp1lcS0QiyDJ5OI7QbdMNcC/GUqMEGxj5pzDECI5+3r22s0UL1AkcVS+86H3rnoC953NWaSOc0IC4e/BdaKquJ3MnH5'
                . 'dq2StLN6shI8kcxZozXsPCvjyrGXKXyJTZRqMm2Sah11/RVG6fONwtb2rAP676x5DV1WdHqD0WS2qFab3SESS6S+MrlCqVJr'
                . 'tDo/vcHoH2AyBwYFh4SGhVsirDZ7pKOXMwq5YmLj4hMSe7sAEIIRFMMJkqLRGUwWm8Pl8QVCkVgilckVSpVao9XpDUaTOW3u'
                . 'tdrsDqfL7fH66v+1KKj05xj1QOCcTIDun40KwAkwcAi38aA0zWy1ItFFDF4C0CYPTmObPnh7o5Hnb3WrtdVNAEBD4aP7w1/H'
                . 'bfMZjPvRpl3FJPwy8ktA+Jfc7pO8jJarJMIOVTrtJ/I39T9xVPLXLY1WlxLPGiXd/7TufBcqGzEhLScFac7brF6p1goJHW6Z'
                . 'pfM/BpXVF4KBIVCEYto+cTACCYIoqu0mDARHiBL6I4FaUZFBw0wqiUzx4g0qjU7yopennVcgka1FwtqALglpVgNgYWtk9BvZ'
                . '9Ql363eWDPRuBd7/qabLbt1mU9/bSn0Yim75YWkMOqXyQFgedDH92pu98fp0vRn+FmM19z+v/+lD13wD',
            'family' => 'Cormorant Garamond',
            'style' => 'normal',
            'weight' => '600',
            'range' => 'U+0102-0103, U+0110-0111, U+0128-0129, U+0168-0169, U+01A0-01A1, U+01AF-01B0, U+0300-0301, U+0303-0304, U+0308-0309, U+0323, U+0329, U+1EA0-1EF9, U+20AB',
        ],
        'cormorant-garamond-latin-600.woff2' => [
            'b64' => 'd09GMgABAAAAAJMIABQAAAAB1xwAAJKTAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGoVWG9okHJNMP0hWQVKDWQZgP1NUQVSB'
                . 'ACciAIUwL1YRCAqD0WyC7wQLhWwAMIKsXgE2AiQDi1QEIAWHRAeTQQwHW2qhcUEcN7mA2wbQwVzm2TDvAnZsC9Adcqf6BORW'
                . 'sFvg3Q4SUaM82eT//7OSjiEbWANQ9fr/VTrNHBS0cKNI8yob3BHI6JkxOnwSfmRHNHrrq++dJ+Iibhfm7jRkDMfz6cJFFY1y'
                . 'yqA4ZFLntBxKxTKjTCrGmzCwhKBOCiKf757IwrNZ5BK/tzNMBgw0kpKkRhCkMEn5N2GvecY8ftuRyi0/RLtOjOL6gqpff/WZ'
                . 'Ja7xAJVbrYWrYGC3BfLEMBekzqDppBxfc0rHNuFUk2ITVEY3pHSEK0a94vmI/YqrwPfACbeFhcA3i38stvLlQDw2jiox1nnS'
                . 'jfj+PK2t9/+fne3Zna2cTSKXEhsQERERC6voQ8VoxCgsTDAKEDGKssBK7ELUOysvvDv1++a+71ZXU9Lp0CMaIM7XMhnEL8wS'
                . 'Cwmk+Pj1K/QS/7C/53/WPvcBzCI/iCSogUlmkgZobd7f/1N/Dx/Ud93ffUTz8AE8tIggKEiU3eC0F6Irt5mLZNWudC6cc7o2'
                . 'C4N/6Kf9zn3Z8DeULn/JUrpxzQg8g1A4hwbpGMYjVKsJgEWdqui4Ln/7cjzla/tKA2CHnTpWFyuD+1v7hweBP9JlVWrR4C4f'
                . 'cfREEcXpa2R7D71+5Cj6j4/JvzbLI6nVWNXVvbhWfaDtzAGBe6FIqNcvlGRP6BD3FugG2yRDc1prO/sgzAs/pR5Y2dQm9iDJ'
                . 'gEJUggAtJTgYmGduo3TOOydfPyC6Q2Hx+ZN6n8lZ4rsRtZw02ECBt0GZ19UjvzOfWCViFZc9fq36Cn6YUFk40zO9jIqNXSVX'
                . 'TmV1fwS/coU5Ie/UsVzmgeLMfPM6s0+eyUIICaGMJEAwwETba2+yfwpXNKlqr7p/vpBff3UZQv7Rm3zQ7Y0oRIAgXvX19vnV'
                . 'hBs7ryeq3/Cr/fR/TtiFnauwB2XSwG8r96o6V2Aaz76pmacA7Lzj0ShXKIVKkVFoFGlh93drKDcIbN53atIdy82hoUQpD5gt'
                . 'K4na4LrsgL4A69/6FxQobYtTSIHxXZb+7h1sHKpnOtgo4Sb9/5b9svXm/qYJqU5DLnISrqZQINwfRTCGrj89qaZ/TyD17xmi'
                . 'CjH0H2JUKA4KHNi8ZLUqZOFWhqRWEtyqFUqvcAs9tddZblZS1wWJjPzepfRHa0e3f3DBBW+qcE3iV3udffvX7L9NdYmmHoog'
                . 'JCpBqqTvpTRKaap0oZCJ1AzSolCa/99U89v3AIUBd7UfWDmAjpB89P/QEVqnlktR5KYfYqjy4A6gmXkDCMCAlAYAtQsCCgS4'
                . 'tkFQskDQAXEGIECCArkhOibtOiUQ1NqktPYRtcEp5M0OKXffLqp/QFfaH1X6dy5/U+fY1b8oXboof/mbLgWoZDn/hXhwCKVg'
                . '9vZrkajqkQRhgNoU7CmNU6AO8kiw7ob1/cTOsXs7FJEgIQQJEsSVvNJ3d69/EFFNzSNYkHJTXpIB6X73TPNjrS9KGrfmYI++'
                . 'WZoikqLiTYiC5vjeLtO5GLRODQ1lenw71tTQYsfKrxu4c0SQKQJC0mFlbrMVP8RSd+zzicCvABCSSgAKgLcAfKxA6mwov+4a'
                . 'CySN5ItGVxy2w3IF8o2Qqq9k8drAsN76AKh2GoZQSA6UAqGgmNC6iTPr3ay8PkUFH4AGIDsBw8p7/CrbYAo0UkOITOe+uEdf'
                . 'SMKnctq+IzXxNiqutYqhwVcuRE6gqIE2UBouZ/IsAU0wLR1tfAndoTG+gbHZgN+hr4/BNWb8CqRowbpkTQdVlwLbgNak3hMz'
                . 'QnpBxm+L9VUF2R+7nnGO+eUUuSZyt4k88BDSJPJS5G2v+MEn//Tnzj9+7AYQKcSTj/iKkEjfFAamNiqvCUVMLGZSFXYruTq0'
                . 'DgSC7VrTSmD4cd0Onv0o9B5KJnTUg1jkQfjyopwQDPI/Dsd/hEuttLFIgWNCYlFzD1OTv1j5pVTaOt/Ett/jlikyj8pBegKi'
                . '739zUYtenN6Ce4+/XIN7nzzcgOFASQbcKPDwN80P+SFx6YBXuQHvH6cnMB208U9ABQCj8RndGEHhBUuk428K+ry7ntuKzdzx'
                . 'qfXyKNDkEBEEdeGkQJ1Pk6BFieDPTodBTKnHH/gCALE3X4H3OiBOlQt4rwJiTJ18EAMAREadfJIMAER5BVzVsL0BQ8a2qgFy'
                . 'PT6xL6THx/oEnuv0P6r0NyECqPPqEQF1bkVCnvs1db6y8qoKKQ9eOKrcArlStGKHydFIrWvm5WsAiCeZeX8pKEoS5LqyAqwv'
                . 'kq2XMVC9ZqNhDa/+KfnkIllm3A6uQ+GkPIWHYerVKlyint2aQAGCS/9GXNwFDrbVTMkYokzFTpwjw0sZTAdX/ZSSR6bcB0vc'
                . 'xTIuO9k9aY/KFmBpCbNepnXrgtly5Mv5OFQqy595hW/L4u2YOXdAFE0zyYBNKhyPAJUVo26S8bUJ6cr8rGr8qivil8O9JSjC'
                . 'A2Xqr0wDrDHzojUsp1fWgyMpmaWC18nd5r9SrdY++x10SL3DjjjqmONOOE1eOQPltACSk5oB1wIBmPubAekaAVxTXGnQtdid'
                . 'GXWedGAQgDmwGWDaC+D8OQWQ/iyA83UBpJ+nAJJHBRAOFUB8322B9CZid+n33Z37X8C5DASQniWAdFYKIFkjAD1FAOe4FEA6'
                . 'TgDJXwI4/kgBJCUAf5gA0mXNgPhDASR3DQSS55s0Ld6qowna1ALAKCA7XAJIDnWFzmoVwgoUSF/YXsLXQO0qSdxYYP/Atzmu'
                . 'Gt27pRZvbj482Hh0NzvaBh6My8PGNpNahmUWn29cy4Lnnmm1YgKnmYZy35oXXfSuVLstN9lDZzcdxt3JxuFxY82svlieTFX2'
                . 'ru1Hv2dJpa3UXpuaW5Vuuw5bdtKrFx245ZKLLjhqi5mP3ULxYT4tBr/IrUmFAlJSKO/qzhM2mWeUm/BCvo0XNWFhwBgzaSFz'
                . '02HCOD4chJV8bUKuQxeh35n601lDAQllD8Z/41f4Cb6FGwiH38f78S5chlfjQjybaPAcnEu24WycivviHjgJZ+N4HINbYQNW'
                . 'Yj4uxfmYwszyI1BFFP3s7770rqYedadrne14lfPZKhAbatRh4OufutV58l2FLKtRD/ag06FwGIq7420VdWCzf+FIIeme/jAR'
                . 'AJA18wiZmIRuT+64MjQTURwC/1vhs5qL9LEpwulNXpyZ9D6fVcV6f8OLMpN/GsXmj9z8meA7sFDyyhCn19dvtds/vlSxCg83'
                . '1qj1Y26P0ILMmwBV/vhoJUgtcetxcVvPu2eO1sOFWvyiApbNy72CBPdo+X2dbtKBa/96Qvy6tnjlWkbXuTPXa9SywiqnDetI'
                . '8JG4UwH71mXd1rf3aHGsYf6vOrlMBDM3M3NJsmeiR4lo2Fp98fWzN83Ro12S2AUn456evfLflquXVozX3TzqYnm2nDlf2DXl'
                . 'Otsn6k982d7vtyQ6P/K/dVZXiz5+8rmv+7NnFcui4dq/c1a2LMZxcicCEJfcUUmVMd5vhFFkq90nZ2Ox5gMtz96wzhUKIEin'
                . 'fJymZXOsidi+TurA+qL8ZJpat2RmKi6zWvxBeLOVEyyznTikENPUCv4i7LKctYK2oE9aDu8ng7wTYfLsA64b93l4m+VSm7zm'
                . 'u+fYxfvV8jkgrwwGUj6Fh40+8pz97LqWXc3PN7DLUmBZCSQaj6JFMydlE7XF4x3PGjsZ868s280h83SY4bVuf6onMaFF/Nzt'
                . '6Byv5s8lduav/mAiBW/zf34hUfN0eLOtlhxK4gXrjtSj6TzqtMAol8Zr479H34KAZsqPD62E2yBOaRPd4mvorObpS8cTMW+t'
                . 'edcVHsVbynfm5eLfmHzKUNMrT0i69AgExnklzqk5G9OVnPRn4iazyZzyaDoRmwPN8LyQSM0RZh11y2xQkKdXmwP9ibiJLjQ7'
                . 'bTYXNG36pErpyaF0UN4TbbvJQyvTDdpE9pqTOVPTqPYS3Suwl0hND0wvfFeC80B0/AgAA8MebyFiU62pDsqoaUeYQlQifjre'
                . '4RXZCYH3n2l5pecvofU0mhinbJ82R0e70G1R1XJTaulW1r4m23AuYPqnd4EndulF/ZSOWx545IlnXnnjnTkxCbPMscAidWna'
                . 'K86aBthgky22s/fEp6mUD0AygATPeXksgDYAAgFYEB0mo1OrCVwwcVLIvyeyeyUkOU8k6IAFrPTwTxFbqf3C8llDHbE9RSBx'
                . 'lCqhlt6HzaXGU+lyyqxxVASl9Plbrr6lg/1B/Sr/JP74LdJAjpM6UkkqyHoYdzkpIBPJiE1TKQVVRfnPc+tLiaKEp8kLZTIw'
                . 'w94kSXatI20ITISkkL8qjwD+jT/hRnznhEv8JD5o7bHLp7/2jy/Fc3EeHo1z8FDc+zRhEm6DTRjeVIq/JoA/LAq9K7m3vW7p'
                . 'k0/gjXiHfqA3WIf+Qk/QFXT09E1r0R60DQeiJ9FqVEAqUT6QbIgA/T0AiHe7PrJHKOoC3aUdw5Ct6R0uSbKkfjgpmp/w20oE'
                . 'nYB1AwT6ZQiCYsXmk61CgT1JTZuvH7G5ecM3LYkPQEeC4DGD+DWkwd9qQOfpxUc+liWSrkWss+7JTv7EH+xEVfc1L7G3k7XF'
                . 'XRujAOgGAaC6BnTt1f8BO4A86s1pGc1KHJ2Aw+lP7E7TsHOwgBCsUAmK/jEzFGENe7FQN6VSFSZC8JIHhv0sarf34HoxIV+U'
                . 'DhhErEcn7dsRyvakdaOxVe3TGQXjwSCODSm7VTTSqDP2pQGmPLCYLtrjiF88YUPoDAQALBUAMFcA7Y4uZhvDLEzM+AIVm1kL'
                . '+WIF69UZ4hfZqM5oDaBN6M22VDX/QTCFBjb4iSXUPWKCyY6CxVbCSbVGtQQDi0yjPbxTZtgMDdx0sCr9PUi+xwe+1yde6Yep'
                . 'cmZUb1mbqDz2OTAO4hDq+BFoKA3p6L2AFmECn/zbOCViAb6qH0REIt9GOQdjNNg9rrDADuwtELhv6QUlUdOOAeJLPf3HTdc8'
                . 'tJ3LO0o7+45T1HC8F8qt7UMK6j7/1yHesHMaXHbX/RAkRhIkRaqKKo0RK3aceAkSJUmaLCQ0Tdp06TNkzJQ5S9Z8+QsVrh4A'
                . 'PgFgBhiGnHMO7YIL+Bo0ELjoIr7LLuP76ithxEgKJKqKUNFUghddpSgNZTgKHJ93YzEVR/ZDxdkPL64KrK0MR5sMpbCMilCP'
                . '0GaGrxUEKshSQsINrQ46Q3QZWpHh7WFCnvvZUxBSBb46C6d15YxWJQxAwBbWAUiNgIGAgYEJfeoCGE57wuC3Y/9Tt2IaqEei'
                . 'XaQXxY3HqOx6wpwvI1sCZDLX5fIYuNY/+ZBautLjQGat74K/zQPoaWTG2VFLhjYy+gvIJ9j53daKZ6ydVMrkSTgF17qjhMaZ'
                . 'vQKlf0rKzrIqVnz9JslRDdDOdHbkQK/b6FE/5t6yOv0W+7gP/fu8odkk1X9kXnwluO0s9wiSIFNakqCsWlR/YcewzIDca5Vi'
                . 'vSGzL9mPIvhQKW45VSYztEpymAOrj0KaE101uBSdwcHcGaqskZMFHNf/tQr1F7l+lRxexy2KROildpHH3pIgtSrckmLTNYW6'
                . 'vcxWuz194cEEAzfHyM4ByOTQZ0gBgMrWjlyReCrBpkOxjpZAGjUZc5TQXEGGWMtZ+nWnzit21RnKNTtUdyHX52Q4eirk4Luc'
                . 'NAkrAGw1REs5jPPF5sRxOJs4ulVLRl4GiRzHRikaWWuVksGgVdmvYdljeQcczKss2k0to6qbeDNdHPbmVPxEe9gvk1ky91iv'
                . '+7nc/S97Cuzz8x7MfGaawrH03fNYvYxsnnoXDjK3Oisyz0sqPYe9FKQcLP+D3AbZPvUpIqBqzzL3qAy5r1Sfqn5ts47enHew'
                . '6TCx77duuyq8FDN74/d3UX9EvuxQu08wG7Z0WLc4sG/NoR8g84UqkvGQeoORXOlrefUg86ADdv/6M41PQEZLR8/AyMXNizcf'
                . 'vvz4CxAoiEeIUOEiNBOpuRZaaqW1NtqK0l6ceB0l6CRRZ0m6SNZDTyl666Of/gYYbKgsI402xljjjDfZFLPNNU+B+RZYZLkV'
                . 'ihRbZa1S22y3w0677LbHXtVq7XdIvSOOOe60cy/3lXhSfxTJLYBfghEZCgiAEhUG9CDoIhwcHC1INdzyEbzsdgmGhYqFqRD+'
                . 'UiZCS8TUymhDdNZWqWg0aS8WJ9Gxp80EyXi66j7viTwpwYZXmb5I66dYfwUGiAzsuYMMng9FsWEapErJ0CBLi5zWaqRRs8FR'
                . '3EqZYk2BPLMgsxWYIzJXyjzFChSYL2VBU2OhRSiFofpbJVYipShcr6vSaqSsFVmnwXqRjRps0qpEgdImq8xWtG2ytjcFdtgN'
                . '2aPcXgWqpJ1W7px6FzRq0OyiRpdjfHkUUVQv3KJFhRARKlRoESJEhQoRQkSIEF0LM8aGg5FOI4K+UC0Fo00LWaxzWdX0RDSd'
                . 'VcyDJuFhhwaEDGOYQalIvEUNFetB5FCx3sjqI0+/BRmRdcoqMBgFhsqTpdnICIeg8kxGnikhEDK9IZ2HQ6vBogNpzwfdRrJQ'
                . '9XncyUjnO8ruIUBRlapTolZivzyHFKqXOCLPMYWOh/QZRTT9hB9dJ6N7qRsbNHz46KCh0aGDDw0fPvzQ8KyHSN4w/R6igCBZ'
                . 'UOQABAB411UAURIGFzkmTem0JD4gPRi5twjnv1y7fjzdbbFp4XWnYdndead65fy5s3bN6aozc93pyWn2JlrvzK81LY5SE1Cu'
                . 'OCh9uxgy8lJrpK2RtEbcGlFrhK0RtIavpVccEy+0Zbi+Ldk9pCa4tIefbeHkngYzOPz/Uj5NsP9AFUuoPNAUGew4EH0N5QkS'
                . 'G1nNchYxl+lMno/9/yaHEZ/PILN2lAsyBPARI4JGCA8JFAoMsEUekoGNereKho6xXd859nxiPj/X3v8N4+f0bNnd2d3bHdzN'
                . '3aVON6baqW9HWl1uLQkrFnmEYxqXEHU0un50Gp6nn8N6Ky0yW56xcqTqL0WyBLHaiBTCn9spWK6TsGFOwBY6DpvnGGyko7Ac'
                . 'R2BD1cCGqIb1cxgxxEL1qTNPnZPrkDPSQSfHAWeY/c5Q+5whav1+vyRi+tSg3qvCIaRsGGIP1OAG3f8/0EheBDm1jfV4m47g'
                . '+xmcOoAelZVZuSG8EZ/0yKCvEYBWlvP0KKDEoxpc10lREoUqEPlqTAoaUTpL3LLgnRvqlJktWwy7vB1D6s155luk0Bc9SrJ9'
                . 'T9oU05Uq2/VoHcUC6oyJ1I7uzy8mWwy1HWRrzBioIVGlmXlltU2+tg7zNX1R7e/tMkW1vrfYDtXM3kLbVZm9OylTJfVOUKoK'
                . '6x2pUGWIIixqLB7axR87gs5Lv/vDK6+98dY77z30yGNPPNXomSbPvfAhjoRvaD4W5ZwHAPWucHcQFVNvBHGN+A9nx7jmhptu'
                . 'ue2Ou+6573qSS5QsBSPOuQqOKNxbmMTKieb3i/VHidNCIAahIycIBSQ258NLnYbw730FQkWUW8JtMQYdmiEJHuiCiy57oPl9'
                . 'tyLy0WdffPXNd3/6y98+kcjwof7xr//876dfAVAQhh8w3MzBbfiKr1tSjHDMHTi5gWKPapQ715bbMcg1QNZXQu2lOAyNmCl1'
                . 'OqbvWt/MLO7LoHQQb6Nf3IelNQxpSxOxdmKfxLyop3XW26DK2mRB/n7FlHxF6iTDdIXK7Eiqq0Q0fkvxYbiJKxlwK180qFtG'
                . 'wFT8bz45YJIuBqYRjpNfOIoQrDVr5FeOL1m4iv2nyXH77VJmtXypYkQK/NW/EYPhpAV9MyxeQzHRHMs1+Vh+WuHp8vgwU03q'
                . 'prSGITCpiAnJEzEOYzBW1wjSqgXpmU01aqYA3vCBO9kLDtiS7bDCbFtshSXal89pkRFimXBcIiQYJnUPu/UpFim1fTjDTjyG'
                . '1MpBxjlC2qHLBvNoxcsxfIgsoujRufZRofEy7sAh5C5X7srSL7RUNqk8NabT8HnoqhtUK402ghoXgK2v1nuJqPTITblc1r1D'
                . 'ULWYOQeRKiunALFGJGWpEfPkVSOZUecTaIy5S2fbQExNpZkyh1HDWj2S+fyABXWiym1BK20EMQwjLSVClCKkXyLKoyYbYMpQ'
                . 'oVD+xCFnRdERddWKeFkiXIYgk0wQ/mQUNx9jnM9jDB839xubPZ82SA7yDOmHqdquiERVRiSene2ulRc9HwshGCbAv1hhnJ/N'
                . '2JDSWkQAlw+58fF481Vw6sRLlSklHsBnjd4AOl1uHe0sxCuInx6cQWXDAgL/S9onCRzH/Wf0+P9ngFoeYwV0OQBAJ41RADgE'
                . 'qnGg8GB6Mid8zOuESgSAreMeZAkCRELHW5goiYWtaZA/AHJKOAQtBfhBAFI+rA36ll+8He4tdJgBD6M1UgBwoIzQT5ogTion'
                . 'eWkF00vBELi3CWsAcD1RJQCpgar1L8oxovBgHs3lq/UraKnxzjg7HoxHEyIUERLHirkL9wL3JmfgLJydc3OhXAsu1r3tYLct'
                . 'O6y4pfhpZgCcEC2U2B9PyfG2zNcAaYjgcjc4HWf65kK45p5i0fBr4gQ/31tphWESfQ49b/7Z8mz/7CYAQOM//9z1xl9u2jje'
                . 'yH169fQ3p73lAkBiAAz1JgqAbqocjEbXgOGSVmeGEOoerXDyFxurKXvWn3rXjFO6okQVxksdoJrwJKi2TDUxsklA64pPSKY7'
                . 'OZaWniFQxXqE6iGswsbV1XYUHTh+6hqTNUNpFc0/0PYEWRwaH9aMlBvaHHMraI6Ft8JK+4RdHf8CqaqPHL1I8aKVF8xZZy5n'
                . 'ElFD4tz15K3u4M3JgTvujQBn0yw8ohCNTJKqeoZknU87TzcMMQkpDSUVNQGngGohH2R1qXK0rBGe0SghKm1RZrOtylXYZoDd'
                . 'Nb3H928yZMoyyGSzTDfDHDPTy2zLFFpiqWLzrBJsb8bWHxsD6dJPLNLrYSj0M829KjyiiUJKWNkpYLdZK9aICpGmFwDUQrzB'
                . 'vwDUFvEB5ryrPRLyJNTxEv5/S+JnQEc5Nfw9IDNA1+0BBt0TG4KtESHP3cBnB74BOKjcj2CAr/kIdZAwl+KAE2ADxxsCiXEL'
                . 'FOYTjJxYBFP5iDGPpMo5MJbhZuOkVZmN3kIsx5VTv3CKOU4aKKSwNRSUivQd8/x8vAxITl59h0B0KHQvgjO4ZQ/JVGVYqYbY'
                . 'viZHroMpRhHUQrIcgkx3jpGc5qObddTy0WrA6KGopRCNiZWOeQhn03hqaSoNFLSYwQWazRLG2eyYLoyjin1f8h3J2gzOl03n'
                . '/JxbhFHqTmDQs+Pb2dZVtVu8TbcIbnFfQbTkxyPiN6OIxQO5c+AeQ9kWhERMyIrQQylJhKzLcfaS07cWuDlUfeP9PZN9jB+n'
                . 'jxPYTXD0B7HhXAi5J+AHFT7nePs0/jGiSoQ/zhBKPQkMXDihWHs4xLeSQyIgCpIND8s3RCVW1svNJoZqD5uZvUhjtUk2G7v1'
                . 'gZt0gyBOB33iPWy4aBpUVXFcCcVXx1qsKFuKWYSArBailCQBgeUpbOui85a1NyF1gXhLCKLUnUCayX5vN4Lf3sLbBB48bGZ4'
                . '0xaNXcCbxJPedoaHhFJPAsmFE4m2+xnVzYwsLeG0mSWQBp4IpjStZl/VxV2mxB3IRXqwyIhM0gsJ7lNbJ+Ek6XSSueW07yG1'
                . 'pGySmlRpqWANZCwGmpg1fDqRlO4PHawVJCnB14/rcYyv7QmJp6uDDId4u99G5neczpJ7XVC9vxh56PvrPgzAGu53arxgLuha'
                . 'vuZBUG9R+ZBGxDihELw9GiIQEMLwyotit/Nea1ME5S9lX84O3n+phlL8ZmGN25xuP+shGN4KmEo+hqsVF3L2BpQSwoQBQmH7'
                . 'CJ8AIe+NLpfLNE2yUcg2IZl5a6UEe3zazcTCCLX+mkkXX0/6dQ6Qw7kIGiwz0ItdDsgFMF04BYqWSXRD5LDVEXnFpBp17Y5w'
                . 'YwTExo40vktX+BVAVDocUYJBUMAEHIQUR7po3or8rnLlJo9/J3GYv6fuQVelozfnMufFi4VDlEmCsy8fa7qg31okJdJKplsG'
                . 'P7rDiSN7cPdCpmoaiUG+c4p4PIzY4meZuYnZNxri9BF+LZgwblbLai0JEVJ/HEHUMAQ8KFWMqbkYyFMhWCMAnUzLDU2i0C/t'
                . 'ne29dYJglStLNUEpuOw0JAlABngAEINFjb4FIEnEzKDK9gK/qg6HfN27OKfDfQOVei0EnBJrFI9A6m9Xn2OYEq+KS7+ldN9T'
                . '2M8wMxNCm0XtWgE4T44pI8ZlNaijxq4ToIItI8Cpp/BCO3kRLfpNBuoDlejWoPpD9HdZJG3wcxa2ByQJ7D8JNJMEbABRkXVE'
                . 'XezEjC5nbMfNa4brAP1N9JcpJm5tFcboHQNwqMKUPfaS4YNJFQ0g5v9qX6hxClNZ4S+cJd6NdLs24PV5ihKYzAhKMWdpXAoB'
                . 'FSoLUko0xUyUekiyhTyrU2reqRp3fPeFHIAMgTkkTD49bAWhghCAJxp/7+bpk82EPKSlBoUWdpA6h+fo1JD1KRIRuG7UMe8P'
                . 'zKjP2slNJedlj0GPt5OZq4IC+U7J9iMtdkdL/OnKcTzZMv1h9JO4ua5DL3VRdKfeE0fRcetKjrPKw8ytkp8mv6jCyhBmoXsR'
                . '62qAwm+1WV8EopImfdu0b/eAfk9CH4D47fQG7PrKa+kHFT4FR5tJlHMJUaioUU3AQr/cVCV1oaTSlPUNsgnG6SFx2P6t3fyz'
                . 'XgKQyZmt0KPInIUK3fLKfRgVT8XplmkdjrHAHchz58oYyxWW+LkUW2RKvzLIv+RXWdUczTpJnicVJ8lBFa9pEtBXg3GkYod1'
                . 'kHwi1/SUoRswRBMLhs6NHYYv7GAHtJtZtQEP2dNy+nwwBa/gu3JxoEvBzMRUAhUb3qIsdZVX20a0Ln97ZuwajlVLTCQqJhbC'
                . 'vbk1K20X2GPOjrN5SMdL1HYD+sRI4MRRu14Pn+RYL1x9evNGVSHd4v5ORp5IDHvtc0sDSL7SbKAwgRSJHduvlHi/BLwpwcJl'
                . 'sxXDQGWBotkm5KrAFva7njpIKe6ozkNGF1UFME1yLf4aC6Wq6u0SHAF21RaxT2A3tw44qzzNzlBSsJH1mBEn3lmj6fE5T4N4'
                . 'LEXy/s57nut3uO+x/B7ZFsEPy3zRHVHsfDTrM2q71htPQ5V23fwQEeRO/5PraMEC6Yk5WlMmVcGhhcfNppNlMu8agpvt7czA'
                . '12+BnFVtmDSrePDWdwytON7RzvSSi7B0R0hvbXTzL3UYgKe+BcxjEhAAqM7qEaNstz5K0M5EWe9QrIhIFVVki+OE1HE2Vaac'
                . 'k3xzj1Wgy/tZ1Wy1eJplq0S1sS15wZXGSAlLSj9kDEgKQ6UpGhQfCypc4YSgfcAbFTXRF4hXP7Tfqt6KS/jO0DW7AYVKJKtz'
                . 'tgOj40kTHQgL+FViMxLlOGJrGu4tuxWfALvPRaYeho24NiV3V5AUdYVZ5ucoBz9GU7F9G7MdgPS4PKspPugtTUAnWguAYoDq'
                . 'wfwi+ZxZSjl1A8Ws51tjwpBuDAv4SIRjtUj5gOQYgVhpyab6pDdg0V+BjnzPaKw+fUT/Vx6HsM2jrdwVmpWu1D+TmJbeIXNz'
                . 't+Srb5HD147l23RZk2NEu00mqbAGWvYkqkSOE1It4VnOEmmldIUXMQgC2IXW7XBhqHYSL/vnXyOXCQNV4gKXo5+BI+7yWSsE'
                . 'Gle/AyHqxMuIDUjpzoYIuRr54VsGeW8dk+l3jghyzEKQTOltqcMcHOHQWVKXr78eYhNYnbjmx8RvAT0h9NXHovhlo2NgLQSB'
                . '9ABgpeqxTWXEjhiZL3eEM00lMxgFGId50okwttF16/L8brsiW/lDjNB2Sno+UAGbY4uyrEg4ZqfWHRY8YD2LR8NclHpoYsFm'
                . 'h9xYfbRcKX9QgBFbz6w2TiYASr0pJn/zukQCiuGV3LJ2qHHti1knIQNo6EDSSK1dnTIdZsjV+CkNAsgIzlD5gpZKY1PD4eyG'
                . 'YwGFzOOPCfDMmO/ZCnLnyv0qa7ObDSAi/fSWgVs1XsQWT4m0tSrAV9xVM61H1ce4VyTLAlGfp0VIqFbt3k55UkWT27g5fDsX'
                . 'KE6G6c2xBEZvPFM5hzP8m6aIZKJ1dAKr1TLAHJnyqexzCp4jksaSr4aNQv9Y7/04Pfqt9x6dwad6J07aJluZJWUqEJNwVFe3'
                . '2lfJK00qmf0KK/272M9GPm6z36JCS9ly+U5SOMrl20kKyoWUxzNep0tudTGd1GiQ0wQy7qSUUSC5kXFogsNw8Kl0JTpNxRbg'
                . 'OZKSfIDl3djmbFFXGjjqvEgdSZAh4quSo4fmVSnHw78KtVgfty1/WzGAGJYUR/OAZ8FRFRVnyomZzDIwiY3fkoxn0mH3qSOq'
                . 'Bn/Mbs26AKJNeCKMK0wEFWGhMR7jTJobCcZL4vNuSOUGZ0Z2o4VSwpWMi8vCd/K6czaSJvXsmRJpIeV8hascjakXLpyqFr9T'
                . 'l58awx76XgLuLM1K0zCrJp5t0qkeNOcg6iGleI96x4yupRqo0+V3bIPTYFU5jsXV7eQomw8znGW0eV4rHF6AyckhtkIRhDyx'
                . 'Pa/BmUq9kauWSiAjZ0oN6uTYED0sJFsdJi2oaMXWMKVsx1kHkJHdrMGuT/cJmAjS5zWjqmjVtmymeJKlqIZU1L/A8i1l6xoy'
                . 'mV1Pnae7HDofyid6bhpYVCJ7VWQfB4XuSl7nUKbASBbLKVKkcqjnG6loyhYbyhQP6gTuFa0KklANj9UWN1DidydOEU8I7ODF'
                . '0kc1IZLivJpgqWmAmsfMaYKauElphKonC19mrMGqiZ3LRd9uJcW6xwUN6bpwZ4IzgBLOCNO+rRFctKFtdSyy6nHiAsfASbRy'
                . '29Dl51++alGqAGNKIMldJMVqlDhmNA4F+JNimuSs5xlMhMHdLEBvLQ8TEpHYeDPQK7R5xtLur5emMjLkQvOO8pehkSxZoqW/'
                . 'CIVzNIOQrxGY2HIpjiSaESirbNlGC3zTZXiv/SJNgCW+BSN3bT0S3iLCuU2Xio6bsYOZAUiv01wpIWQo1DbzmNFue/suAG9y'
                . 'shOAlrfHfemdqo6ZGkwz1us8h3qqguRwzfF477JD7Tq7s1Ea7Fh1o3kjaW2rjuPaFM08HNxo7enu+gYj6vDJuDje/UmnRsys'
                . 'A462our917+aWmNbZnnZ0maW5/tIU1qAp62swutLytxd9ne2mOxhdyS1nZZTI6G+Jg0du2sTnd/jZDv1uSDkqvPTaydkL7N2'
                . 'hI7azCh1SpAyFbgVPgv1XLS2yVpi2uIXIRXYPZScH//wTrqGsbbszkKJams6Yaw6RdoZs2r9dvhqEtbx3/ylI03ieOHj1pB6'
                . '97TZ7yz1b131byD1H67VXyEcv+S3bRArd1o67GS0hQmnT2CZ+xYSSa38Nco6ivFZ6fqfECAptY80Lomep4py+g8PLh0wglvH'
                . '0j3hdkyDOTof+vtHm9oSZZ10KkvnqPbkCsKfyvb8NwDpEFt1OtxcQdBacAWj3eT9DwJ8JZE1WmssQCFdE/YdFAe2FgG8Q4rf'
                . 'O9iA5oRgUHn3eOmVzPXvKa2vaE8CAnwo1tt+aWMPjKOf0pQQemEOMk1RRrW6AhRalog0wgx38GEdIMEf5OBsob7Ezg8GZSbb'
                . 'TsjKG7cq05HUw/Etwlul9B0z4i0HQ7/Gs976gxPlL0WuswOIt35Ua1Nc1ylhFZq7og7T+Oped9V6bhP5cwrmxs+qGRRrGW0F'
                . 'a7Fdbazo2M569emO4ARj7NOyRG543qkYFcuE44K4QABcxMPkrIy2uI4yPbucqjJMwqwV7GzXbWArXwcfwICrNAPiXEZ1SCuU'
                . 'FQ0Y3EB+gUVuWejTgXIzqoU/U4aLtizC+VgRR7QrDJxd+l0eorKl7W05aOKgSWnfPtHy0ApXbsE5U0QIinIoD9ifbg0knYBe'
                . 'dHZ7B7dWlFUyBwj1mi1oRsac/W1aWNRhWe7ZjRt2tCLl69eF7F0OTeeFhux+DXdrp1jZ0xzQjmY/erNkQMrODRnBgLgjJqZA'
                . 'SE7rM+pUNFxYGGDeYbJ8Zlc0hbgYINudTa8b+ea+tXZls3Pt98LQXitNSjIXdKBYWAxW4eYmmiEFeMDzoQRwwbt2GGVCVxL6'
                . '1duMlJaoF2aNV0RzW1OUeiYxUmtSTS0hDB0NSMA5Qn1RnPAa0IDnmxCTA5zyFd0yoKcT9TIlhsHIFPQmtiSk9TG2hyXmLuwT'
                . '1uEqpvN1es1E4mBb3naRhEuMAckfGyulvFX+stDCmVIh5gk5UJDg6HFHkuhbFhNDXfFKGZufXu7ryq7XbkYejnHW8Q2taCYt'
                . 'F20r2qa/24S2oqXVdhvQVtLmsM1pG9AYBGTCEa2D3LLE6fQ+UtWZo1TYI1KTLcpmuNJklKJ9xJ15pEIyVCKpeFFW80eJIl7G'
                . 'akL1U5e06f8CPJ8brrN9bXfPm75XPBW2kJcUj8N2PdScNMelcE6PhxZAsy18lFwHTFh3+TV+eGc6FENsdLo8qZcMsEAH7tpI'
                . '9vewnK7L+rgJtNtkboKlZtlkBKAIBhAjbFzwJBI9kIpqzFHmTmJ3LIKUCEG8mc8tUjmVI6ni0sQYCuC1jeYd8QTJlDwmhOyE'
                . 'CUc2xwOj1BqOFT0IfpmJwobcpiuBV1WjlgdptnoG7fawMjMnupWXdREDN4YdBC2+xsHr7THP69UolxWXnN37t4u0RhxOr+oW'
                . '7/vDxK3C74YWY2522QRYqJ7V1TzBxzrEPGbO3dEiYLEWjMXxKeegQhpr2RUtc+BY8nq1g/f0b1xZvjKqd/L0WNx31/Fq4UV3'
                . 'dwrSM9xa948P/nR1L2d2LHAmL3hL+6bOjC57k3JLdpSanvB69/HHKi5Tth7TvIDti1oqRPQnBHKPwuiRB53wUGm4IGZ3s1WI'
                . 'nT6W+KGx3s2fAXzheIRfszmU0UMm9/BCRJldvrpJGRvgCk9HSeBzyVLYAsuHLL16qCp+Gxw6II4k92BAS70942CQ18wFn7hZ'
                . 'RgVzJaY/Y1To4cWcE8Kvc45BflOMtvWQWRzxskkX9vOiQfESSWY3PBAvUHTsF0UP4w5/sEVPGLKG+Th1cZuljkZeAQ8otFDG'
                . 'RQSPoqALcZpD3OkNZTZi4mMRjMeCFQr3obsgGWIExxU98OpL0AQSEBMDSMW2s71keUbCHGMEO8bHB76kra0Xr7X3kzLPT45R'
                . 'JbDuj2nacQ+TJ8WbM/JfmM0lTH2pACLBdqEOzBPZx3TSSdYQT/sGG1EgHexMKm18gWqIRWaEfTfhkjIQWptRTl1rMSSO4npI'
                . 'PVNO9dGp8hTysJW9uIdvZoxXhEvMVtWDe91BIFcIQl6JKDkZCGu73PIerAquTxyRMhUDsfWiEjH2CG8dZuAywvAKF7yW9IUh'
                . 'FTA7yh35MXJTc9/AoSTS0rt20sLJOeYrFI73Y4EIWhz72Jv/IdNUqhnc7wvVV9M7AJgeQxfFaQdZeMHI44pnytPKJ/hJ2VPy'
                . 'rPyxPISJ57RJq94BnDkyG7kQb9ktf8zQ+V6x5F1uzLeYmvRsSLZL4kXSRpEFSTdPjkX294FcxaZFNkOZesSwvera4Vw8tpp7'
                . 'mouTlE/IsO/imvN8g9LIohnSa//rSMFCotjXOoA0gDZPKlaUtDCIFWIkqz3sKLocGs9l3nhXJSPlJsnvlDnHxYFlcSfyLQcv'
                . '/k9RlEnyxwzGUSf9H0cXraTvNcAkuTbnKhZycxztL9b3QsDZ7eDSidgSwJ5ZeuBg+7a3ALzk25NgcTRZ5rJpMxkcxfHcbITm'
                . 'HXJnVVKb3SugxWEhT27HwdRaQ4J+E7MexmhdDJRFj6DYd9EiqmdZ4L362iMFgH8FewVidqU+QyARB2OGFHUVNc2CpcbKPkwz'
                . 'St52sFx0DQ0ab6q+3nfhwjCffiQe/OgWkGFQxJb/uTUkohdv3YtppQtuVrhcBcngJFtf9VjjPm6uMOlDSOTRAzqGVt5y9ayu'
                . 'Jn3dCeWjaD/DpKRlE0l6dtkuMEiaviD9REp+U8FaX7LehK2vh+qT0hNLo1OpTDxSdfWy02fLPbbD7DkXMWmOeHY9EDIoizfe'
                . 'BdOL1Kq1SSXlukrTz6gZoHjV+j9Z6Jxodwy3gou5PJa3wB8V9hh/KzdWYHNEN3qEMfzUgIdXMSdvrr1iGNfusF1nD06r4I16'
                . 'dlSqm8VMI98lFoXM6R3nh5U9tjQfzNnpof32Y+Ka23sLC5tH+ECM5DlelPl9Akkml3YZW0gARVggx2dJCE+9c8fz4NgF7BYL'
                . '0w4D0XHZOAMLcDN59puWBwEcRJn2MjgfMR4zijwqO/M39/1v14358BDPQhUL9rLntzLGfoHzu1sUIfjH5ykG7cMP+vAuTQ15'
                . '6z4/PzX8s3v0z0Txtbsj7fDn7oXHaXphf17omk1uI+8pJpes20Uv5O4gtVLm7LvOKma8DeClhDKXudo0b8piDhDC04W5aCKW'
                . 'QGyL32ZZhKEvGV8yvwoLE4yDP/uxh4gZF+IUkMZACLtrjrImm8r+GBl0EZ8BNwVaz37T8luFKoVsoI7BDjHVVj685gJKMZ0G'
                . 'QiCrft5cqYCjiAGtrEIMvHK8gHu4FOvzQscp1resr2vFWIho93DpPnyxNSCcFDavKGt/WHrnqPDjn66OTlPeuIW/eklObSwQ'
                . '90frmsqBysTeFzC0h78BbywzATjnqBGEsO5zkdwQjj7jqfLM6qqofqQxPVcVh9KLPOEljRnfuvmpartJ/aFiJaBTYUtLsVlE'
                . 'ICAsSqUvnhBuVuNYJNOBYbVKvyj8NesTvjJRvdTsn34vBM+pUshO6hNSqxO0cdqyLKBOBvoyyNUjqIVbXKFezqvqfMPlKWgX'
                . '4X8ZpPF8W70vXzetnltFmmGfaQfSD9Oe+Z3qpVC8VIqWIl5NSVH2LxKJWDzEu1TxEfd0kafA9XlngLciAWchFkAlf/j3qYEv'
                . 'abDk10UQiHAJGH+uKupJwN5mM5Vzw5a4kPtDtNl8pJ+X23TbuWqtN8id/bX5W/PRLF8KVnH20k9eW68+E+adb5QfKn95Y84+'
                . 'x3LtOFA/xnomsq7zZhj+oXAs+TGyzGEr1eFb3YFkEwYOtTnKMBRjHyd63RYVw6o4d5W1KYZQcd0Ke1KMdDQfNhOXMvTSETrZ'
                . 'eni3UwymcnCVUbgVufJw2cT7zVH16cEqfriceShlpUcYudu7x5Qo690Oq9l18xcHFwInYyIl8bteFeS+JL09O37H/Ci77+0Z'
                . 'yqxciAZDOKTeb+a8YBUhWNMTk46cqckWMM2f9ZsvblhS+yYq/lVLo9JsPyBCmj2dx/zTeRJ0Gn7hgnCuMJa6ylSKwfY+jNW/'
                . '7hy2RIoJppOV3ugfwe4mMvxjQcGl5P734u85kmuPCsXc4yjSFLLypyFTdMCEjNyriZzL2Fsz9tGcYZYeLuyCvtKDUpkvGudx'
                . '0KJ/HhvyBZ184WN8P2y+8nqxNRAEISxjCfmjsvI/X/3rXztuhiYQc3geIv0qBUPY83Ou4jvoch/8ig936r9uB6z44Q5jqFvy'
                . 'SJz+DOuvxE7imEwcMJhxLXFFq5NjIaJcTOuplv9/t5x4VP6RojTmIzNh0fz8GTSxOGf7JP9TZeMN7T9yiaiW0Ep+sLyj3k8G'
                . 'VPGxGVJXH7pidady1Sd70MB2OY533yur7ueOusShAU2yRjngSThmt1fMldgjA7pEjbzXFjAON9cs0AgCvS7Pgrqm0OjSZM3A'
                . '4o3zAtI5sfoOrXDFsSc1ohJeRfSJZVCJ7HFz/1XV6vhXGlXrVId4MuKpRip69sU/lfDGlHyWOtWPeZx/JNKYsRlyaxf/nMY3'
                . 'CF2PdhGzCjttZ0X1bqDmcGPlXKWfmcTwS/MyuTMKEVvv22y/KPQW40vGO2FRA/29n31YQpTbhpMfx9ZfUqXQHaniPEI1Qv5p'
                . 'mWwW5wMW9kzF677EE17cmPGtX5ACDqUTwiKlUfLJTA/jpcYani5o0UuiLqSsRExcXIYouYt5OcoygTpiNsqjQYlS2HZRCMzm'
                . 'iL6Rj3CVEZCLhUhEXT7n02EeKfLYf74strWZbQlAhZKYG4mXiIlLShEldxEf/qMXS3JqppxeGgDO4a/2iDTJFDnuZp6c1Poa'
                . 'VTVN2lRR82Dh/lCff9t3gUuh83lhpQuBlbEala/4mW8e90iz8mdQu4l97uJvZJytXhUqd7lhn9Ct2qDWaX1Jnoq+5kLfdHxO'
                . 'Fo5A1PnZ3kKFoBKoaXKuzYuBEDaW6bgkPMQTeS/JrQT7g0lQwguVY/MoxlPMhkAJ7mz1q//RmTfcUfOp7f4YwoYojNsZhTFi'
                . 'CPsdem/+lrv3DVY+ssifQETTZCppUoIQ9s5BLF3Mw6eb4yJdlpbZIaeSw8tt9jINn7+6+02YDeO75k+tb+2fhlHqSDmnKn4R'
                . 'cRGnJmoRY+mmJinOLNhURS7SUqa5N+Qqa1pfG05asFTlnya6yV8SmBPHpbpcjAxjG3+Tpx/Kb7M/PIzch/U32+FF7Mx2Ajdt'
                . 'e+FjdXefEVx/+dGwotNNLbRkCKFebphUu+EX3Q9vyfT7td2+viF2YgiLbcn5phB90NT6j6j3A9gU6xP1d+yb4rbf6asYSvaO'
                . 'AfWtCT6e2+1fr8r3ywcWfTZ4lW6Z0B4l3b/h3vYVV/b3DopNanjmRbvOWbctOvvW1EW+uPU3NKw12sE/QGpb97b+hxvAV+4m'
                . 'zTSh/ttWZdmff9UxxVaXiWWHko2Ny9hJTNL1ZtWjQPwl80Sf4tO4zG6rspZA9QFW2dHTPB/2/kUJYQp+5zyUJBwf9xSuRl7F'
                . 'qArZ+ohb7ZnFi1Quja94byEz3y+oHDKRCEsnkQheGMphSwIVv4+qk6YAKXG287K5W3raZh/4y7W0c3ABWSG2SlylLa4CfhJN'
                . 'VUcq1+2Z5awVlWb7Hm3werQMqyZhM2oUEXDOspgMeaCoo6rDXWRPIaYKXPD45vR+a+r8OnXImOWngqk6kEXe7G1VuX4Muq7z'
                . 'qrQaTpSo7VKernr5JC/jRCPwMQRI0c8vwtZPDfxzvlvnqlzZfZdTQogwCtxDipKtyXTN0nBoZb0b7k9Wd6C2qvZkUTk4jrOz'
                . 'CPpUtJbq6BhP1m2ZGtL1N6cWK0F+d0vM/9TVuCGKZRVZJgQ0N4VIFEt2zbhAEsnoodx/td3NAztWA5sJ6WXa2jr1kDdqGmhN'
                . 'rpDHTXVFf04tIhBGYscUz0PZ0YXK5FJ/bHGtRTgQaxo2pzT7tiiQr4nNskj4Y2nAaF4+b/qIhJ7nhHOyQqfQZxYACLmsrGvU'
                . '2/KwcmR4Q51NgYVIgJnSX1YRturtlW2oI7d39C83Bo8tYXHF/r0aTgK/8CmD2sWEhXfVN77DSFO8waWqtHDnKvJZEW9Cfzj3'
                . 'z1jFO+Un7D/zm9dUlJIsXJyH3EGMhV6j/qbHTa0wppk7fAFFJCJj8sZHxj2xMeSSK2De+9/aDtRtLEp6hi7t9DBRqq9/otjq'
                . 'NdQ8nIUnzoea+iVZA+oT59NKkLLLcHXY142hU/sevI0457YqlLhMBgQlGEdOT2tk235jHw2dKRko4t26uu3avLJk1itLz+eB'
                . 'njgoFJ/phHDbm2jz3kMVYFcgBUp4JuAIP+TBu7I7DYhUe12j4xHVY+AYXuo3W74pR76n076n0Ht1tQJPkKwJ+ud8VmxvZHgI'
                . '9g43ieOaL6MXK3Uqw5eODxUdGDsHROJRHNRc+ioqOEthMLK4uc+qiO5TXrbI/Qpd9Jf5sBBQUx1ui1uCcs8W00evzDunLUSq'
                . 'i8hFK6gEYX89TK+CL5eNkVz3V6LeKXG4xC5PKP50FdEd/pqqhmiTWRkyXR8Kpn1ChTwoVSYcMVfrNBSXE2zkHz8mvSK8RiVf'
                . 'RK3IsIULgu2we+BvQmzaIqlVa0Rlf0cZIqaDRoL+YBhPzSEc1+7MhLSCTMRgo3eneO08tp8uMtwvfPu/GH0f8gmq5WmzSZ70'
                . 'GsoZIsHnWzcudxn0rnhtyiRInRLDQe31oeBAQLj/1xYhoM77+B93xN2GoDhiojJL+F1hbnUWGkVmW0lYsB0wSfxXF6vRGK+s'
                . 'MImbAma1qJddEUwpnyhb3da1F82gZYK5XdXL4LFwpbmjETGbUwZlrd4oKv8oSOMJ3LTVVwtIdS6XvJgbzOUCjwitEwZpNWo3'
                . 'NjeZbPplMMGSg7rPyqz2VNgszAS1GnpTL3k6RRVQnlZpEv1Nyfh/6aymjUDEf7vfM/2K3x2vX4hPCagtwxenhf1UMSpJ7t+7'
                . 'bkw1tUhYVJhuvnzIH0PYdoKjo7+7ZgVw3mqvePws0kqUnQxGAhGfSQq+8JqC9NOTgE62IgBNoWS+3yQ5hrDihIH27D+2QZsn'
                . 'DJQkaRpaGiiqS37qgOY2/LY94rymzG37KzgBnWLmENiO8C1BRw15VBvsNqmImCKs41woVNeswTlPafULLkORH/GfsTLldWDx'
                . 'Q3ZPwVsdW33/jtLub0qluhmbbjyDUrc6mc54gjCxNGn2kd2DoWRWxRRtY0p6r1bJE0gq94DKymfMU2xFjrChtrx+cuXJ9Xuj'
                . 'HN2knM6GHZ1Wo9QH8ibWbKPrrWmVi6dVVxyxfFZDFH1gZ/PSqUtQ+Ibk/+ztagifLG3nJ1W+DeWwl5hjIWE98k41oDPDvsq2'
                . 'xafbCywrg4O8vkYU+cmtp5I6hW7NSqqniRkm7dFUYqeQM/E5/2Gh6HUNEka7tSRsdrzkHBJPm2dHb/lnTWF29Jxv9a/ZQUa9'
                . '91fl2W4VueDP2X9CxXH0K+vFSr3S+JbjEzpcNmxdYOX3LURd4OZ/pea6ILtZuRxLpofrz3D+DbWsMsPNJp0o+ke4RGirtKoa'
                . 'dC5zXSP6gckbqbbLmiJGjdkZj1il08I2nlG27yWvJP8oxnH1Kkn2jE7cnFc3ndbILJlDpZkn4pySGGOaTpbYqi0fIV4OuR9b'
                . 'vJ68rJJ/dANS3WjPwV2Fmv4svFIkeo0P2Whsvr/yOvP9ly3HJ7XhqkB/9ejcvVex7EO/yqwPNaRqYvUm2K/FDskbDIjMJ5cl'
                . 'rDZZpccQogNMqRGiC7+jRic0gsHwqOnBWzsBRHQGeTVsPoLDqIymCmivD9EpVNpMHjgKyfypakkSsURJet3opHHGh7sH3pMP'
                . 'Db72qMr6TKDFVCBIPrym0qdY9/3EfTieSE//LbbXfqt9MIQV1w1MGyxgmnf+Q0FSjyOJLjMSaStWGHFO0fu5bW9HdJN9hsCg'
                . 'aEb5tt/WPbrLcb83BpQ+GsWGU5gOpASf8H3Z6IgiSV5elb2w4YJNnPuZjRLBX8qXsVv5DtEZLeMtHBbr9iy3fNBXVbXL3pFe'
                . 'c9gC0se3wNs4/YIno+k46BiQET+9aujgPPs6n2pWFvZ0QjJhOQa7k6+tV/v6dEXeJEj7hJUuMNqZQvE2d7TFM1Irx9nxXud8'
                . 'PuTRtERIdZxxJi2xGtWHyN+QRamOWSkx7d+qA4/5pY/Tq44++s8uK4G0bUZ1oOa/grwgQlUWBLRao7iEEEaM7bOKa1mTnpth'
                . 'zBni3vAzfffyS+3qZbAfBCU6ZYQ28PUZpt8G5xsBimihb1ypeEs6g/WzZjqbbOsM05SZWY9HSokS6GR/bsTtisk3Z17pPsvg'
                . 'woSY/zHXnyjuocbM7mPfSBp1LG+Dqx8YbvO64wXxOVPmDU+UryL5r8KuGUyPsvFVXqJgkLs7oApQnlBCkEiVoJDrH4d1CQYW'
                . 'r23Zb02/e3Hohru8ttASlsp7+x9iPUCNqu9lePUAO7Z8oJn8cmT915YIYbxv3vMtuBpZJ2EqUds+LEd6ANdL+X5whDDSA3s8'
                . 'vRqJMBpCxVFBGR0aKlh6KLybf8uzaL+no+Z8XEw7v33HFzH7ekfs8oY6z63kBlRJfwpDdS+qdDMaWPGiU8kCsi42JFsr7cye'
                . 'aDOLpacxbfRrVbIzIjZk+GG9Tw/4CP1KQYnHlclgZfsH5MGmccoOyQGC5pSJMCSMcRsT4CUDkEaHNZqV+qgV5zStxupDj239'
                . '41l9/NGOx8/qoz74gAde71kNJ0vUdoanq16W6b7ksRvUD1HyrEbqK2KF2CRR+HjSctCLCebWPc5m0//VZW9Xkb6D2OOkzUwB'
                . 'VUnUWrKz6eiNnQj3ioadTnlwdy8kXCGyw9601K4qrV4NGwiWbE1krUBjrHpdLJvOILNfapgH0weVF2zk4lf4ucsNPlluVDM6'
                . 'E7hDaBk3SELRI9FoMpnn64jRbHVZjtxkT0dMgoxfoxF8HDvcDb+QR3hOZEL5PUpChRGGJNUthWHG3N5ofwpjFezeL/9+f6Qk'
                . 'EHHAuqrasOylggGKhPv+C6pwY9glWxrMQeBI1oSN/6Rj3zKEUvicO89SPLYwLJVJg35Dhp3/eLCHbxEII34BanRJ3mbxFF7R'
                . 'A8Ejnfw8whP8qkNs0ZYX+We2CkBVdl4c2kIvJab2AF9DEeVmZbNJJ4ju9pXwDGGdqFrrQVqShsWIOV3RlOhzayxlUZu41WsL'
                . 'k2wViCSpsenbGu02/QpHMUGD916TtXQ1RDKZljB9QXJu6BB6+S89QFwkep+ZM8oQHpcdKyMxpwuKiKd+GShY+EPjCWnJj+1i'
                . 'm3fd/C7FbAUIYd3uEjqpEAuRgB2K/2hDJwQ4iLK/PZ+WNzZwab2aV0ZlNmRn9aUJNa7L1Rv3sjmTr62ZQ12NGpka2O/6UceS'
                . 'WemdMZlRGSzx3V73llQq0tz5Fg3xNLE/xHvSiFHz8TEpELQX3MlgxQReaDOJw0UWRBHki89lZJrG25X0Ns+eZvfBoaKu/U/H'
                . 'lCpvYnhgDO9N16LZfdpD40kzxVoFVJ0u7W7oq8XXw2Rmu1yXxGEIBxhxEOU3I2qweWmr16cOCof2H4gZYW/F4oUPaZ8y8QVS'
                . 'knn+P7w6UrzwHK6ZL4h8m1PIuN7VHL4Xqq988Ouc/J3ceR3Z9yuWqj6NZMP6IVvbl+Xm9zrnbr7rMFfsl8OBx64twP5NnevX'
                . 'obg8c7zsvyBdXc7kHYeMsK5os3sNHhWLeEmsyOWHxUqfSTTUdEKnnmR15wlJAi8+hXXk5k3zlB0mmzBk/RMcQedZUH+iWP+j'
                . 'bmpb0WH3m/3o4c+KpumQwAGT5IGwKaQIFyeuqqycJKokgcnql37l8s5y52WtZEGr+kcu8zjnPbalruIyh8BaCUtKpBhPscQ5'
                . 'kC9ZR4r7Z2B8U2tSR0rS/572iGSyD3lDbbaCnAvywySwDlmnz51f+UKg+A7V1MLHX7P32jg3rDGdeqHShr85aMayg5LX130k'
                . 'Oth/XrTvaZCdWmFqpBnop1DEMYc+GQMYdbKDkDD3Fod/ew8GbCva7PPt2e+Ws99a69/QZk3EqeNw9RgEtN6gA9Yla4Oyly77'
                . 'QAn30BxFuCHskvXvNvbd4HhTs23B+KzjqGb/UabofNXfdcqTozQWJZy0nylx0GNIsaHLOtwhTaM46JabLEqxLe9r7ky8y4kD'
                . '9r+o5v1FYkIomnKRZxV24+g8veQFCWxWwjTiM8RoIprOjwmtE/hPMqWaxbH0cImwnhYX9Fws1z5zD/IXij2R7AmucDyJLRX3'
                . '4YRp7uVBkfhX1p+Kfira6JMFOMA01/9mfn6cZM5Hl2p7lMnqPf43h3jAnwMNcsk/iNlWrpaOq0V097/AR6hlnV7bYLdpa+u1'
                . 'VmtGp6m32TT1NbpJj2NaRVBW61VIXf72couo3q8HOkWhmXjjSqaiXCJ9EcJUYdEm8Ajr3JddYBdotylpLbREYEKjxcUbQEJi'
                . 'BrMbE4fgArjgU1FcNdCSgkQQL7XarPEVetmcCF4BLb7JtSLJQnvIik5oFJ+RXJjDWDhG2YNB3soucAnNFr9SxPOdfYOh80gK'
                . 'BGpr+/wMcADXrmUPUz7Auwl9j5aZSVujiK/FWTJARxTG7CaFhdjeGFgJ5ADwp1qG1PqU0Wyun1M1FUZq0Br51LQkrUj21ndD'
                . 'DM7hLE1PZW4T9MYlGbibOG2vQO8QooX2GCChoUu3cdT6eEZlTE3p62xIHnliQ5cIMfOmKpav84yX2QVTfVZU6/TadJqgkZn9'
                . 'q2IrZTzMBzaeNG9vfRKYhggDxDmV3RhXdv4f3cBxwllG3o1BSnkFjng5B5+hAvJkLt6O7FAJikXcpuOUZVE14tFoTPFGpdla'
                . 'pYLDco0uXWGfX/28q96qlJWFEVQ0IZXpEF8U7qd83MiTUkM0nvSpyzSB2R8Harp0/0+wYcSXkGjs1RakI+CFV9QMLalL0rhh'
                . 'NMLqqY/MEg4ahaSEKHRUiU6YteaohTW2mb+VIgV+lLU2qd3U3KQdq0varJkUGsZrxmoS55WmCGP6x6FZsjFvQKqwlrNXZxpE'
                . 'o74kkIQhHHSXZX4RKSX+4oPWaJ4Y8IB42sm6mOsBIWzyYqRiHwaQ4qp4r08ZqBzWDxgNtqBNF7SJMeO4IL4+6g0nUg4NPD3U'
                . 'Wl7rDmYFcmt7wMm1K1CM0RCcAa8bGQHOEPr7ItSVgndjDBHyJiOgUHIDCXkER8a5Q/yTIqvdbZLGxTLMXtyasVg1bjFRJOL5'
                . 'z7whSYUUohhiVKdTMBCfzNqfywMhrELZwjN0LEQCn5vg/K4832TIjfUB6bgXi6NIyCxwNTRSVQLZEf4HeChPhBgcQlZYTt1U'
                . '4MX5CGY3ec3vY+gSJTXTT4spTwYY4pi3MRCJNWaoaQlJB9zy5curWhvr3JqaaqVBHRbdoM4DBCnEreZFFTokXYWY3EmHeIYv'
                . 'JFufGBwIR7BYb8RNaIm4Z3DmOxSktMj3udKsjTkjjjpD8bVd9QrMklgesKUoNpLDw/qwk8aIXHvSYC9D2Q06dTWUV4YD0gTs'
                . 'CFhVUq1EvtOl1LcFtaqgwWiI1UlNjmolHFOjmuoy1/zgLutUs1paHlFrrBGvG4W9IbQt8GCmXHk/xBEprpnsxnBtN3CJq/Wr'
                . '+TGFAS2vVBo9Sbu40z/9xaEIjivH4YvNcyhPS8BeHmo0F1asoGylfA4UIqyJmv//L1rM5Y4VX6Gz4xTrD9bEYs0YlzPmctVk'
                . 'jlzguwXScwhEIneUTVc0lJ/3/0Wp6dC8uTqJybIG1Q4rzV6GUcPl7edv9w9tPvRXgbbDhfPWoT1vjdyfce2o6i/udoMZh7bx'
                . 'apdPu5jb5MTuIzm029mRerIK/V5WhBd0siNqFTs8QyCIscIwzApH3hLTKlwVsgqBhFnp8IpqAQ8IYe6z+ZwhWHRsD1qLdBnQ'
                . 'SuxFa5dB+rqFQIRdptNfB3XyV/bCHqXGK1pacG9sNS7c5euVyTK0lLw6hpBgz9qKVMzD0J1HuQdLKWB8OT9tpo/+t7c574NT'
                . 'SBXponYaEsVeuzlMwOqDJxndCX0J1F++8y95i1ZZ1bEWqPNW1tkRhKQlSk0yCUHI6aN6+z6zChOv6LPHsRH39sN2RKHyNY1s'
                . 'AoTwrTyamUfTATAkrUhOgSkC6QDE54vSNPPqP6oEJQ7XDx/xpjGIga5eKt9RavvlO8bi6O/ZEWDzTp+uHL/bvmhYe12wd7+o'
                . 'nWXF4IECfReF+Qw2m7sLfWosmh5ZMAakb2GEYWi2Fo1SOAT7WgjhYezOFMp0ff96P9QMiiQMn+Uvk4s0LdRYgIDlu2cJVZZ9'
                . 'ZmaBzvj84aWXZvpfLrP/EI5h99KIyPc1Rvtat5HVG5rqL70wMq4uZrmVRmPhXphdGFRr977uq2ndMtzWr8wUDYK4A5sVXRDz'
                . 'mS9sUzI3kBYqVOfkA8D/CigKXFLUaejEStDHR95c2NXZVZsSCg7DhA5NWHirLHhhCvhhLmGUrUX3Az29sFUEUQtD1mxd0IOa'
                . 'f7/hQB7SoM5oF4aMJ2Tak+x1MtEPUNbPIyDCfF/NGX1fP1AbhrCteqQvqGucV4qk+/NGw/Ma943VB+kVchGerzOdbaGHtksD'
                . 'IRqBDkv23uD7DQezNG7SmJEWPWrBpDfZ1yC59rnmitaATrbguLiogxfvyg04dyshGdcUIW0mpqwcLd2o0WEiR1rsMLdUdIAj'
                . 'q1WUSyptIkeF4clwip7Mke5X75oqzErNnlVyhKN0bv3winnHUWvMHnBV8LK4Q9QLeRN90OF6gpbXugG2VcmXbEXdS01hau/e'
                . 'YClZCM+IhyLQjDk5qBprKIkbgHAAAOzdG8Srb8IMQ0Ta9x8Iaa3NQoW/s9P1kBTK4NcsZnZHCnhkZLuc9fg+MhS6R0crrlpp'
                . '8JRy1/n+gq45YA0ryrNtRqJoaRF/JY5BUVEYFEOvlBTpwn3vUyLSi6MlxiIcjXnwUpFXBD7e1qeaf8r1g7+UMF/FxnaSIOch'
                . 'pw+XhAP7GQckD8LjuF7aXm1Oyf/VwvnX+I983LSdNv8K+/wqXrg93PtAZ+3UQ9lB+NVuMaTEZWDxOsOq+QZ9werjRbNAb5g/'
                . 'D2bODF92UOhZlsKOI0dud21B41HscP0q/rt25yYEDc2wQ+zTc7nIO+p9DNbBVMsQhO49552zUND/oEcZswsLYX5nwn7FQ/3J'
                . 'FQr39nFOAHwDMwqJQoG7R5mEz4rA+ojdVaB1GVLwQdmp0QolnwKqMlnNKPyX46qAuDCDH0rMyPPtWbcSBQGpQE6p4MC2g4T2'
                . 'fatzziRJiIcmujNA+6CB6s19810CeRibFPR/O2/ewVyrk8LlUcK+89Xl8CGowYCfA0KsTI4FkkyBaX7cgdWHEEkPR3Te4JC2'
                . 'orK7baQURLmLFdCFlx6wNbnr80G8aZCkKHaPZ41vm0OixLahomZHNPDjuyFQtqQCoy7UT5GahpJwxQwHZOn5fLo0xF+GlRDv'
                . 'KafMT5ueTPEOB8n8Q3S0AGr8HFxJqC5UW2pKRSqx6X6JEwJe5rd5Jv1BJH+a+dNKrs0ntzJ7nJCCNQ7eeO1fGcZoTz2KNPKM'
                . 'td2NoZSl2NU1fS1UYdOBu5l7VoYyOpoAp+EZa2asQ2nLlPp2TCx3yITt2CE20+cFjcjJ/ipI3BZNPAxmxkJ8Jc3tv+mATfNY'
                . 'Sqv7rc1e7UbZpcdCGWmFxXObrTLQPuz1scgAmjsda+Mw20RRvcs1vcsRiHdHrXOC5f7Bee6EOkj5n1xN7pxZIilf5IwOpwO6'
                . '/tp0H2yPdNltbQ6TekrcqubPmk0q/uM1rqc53Po+rUdRsdC7F3CuUSuCfkuZzm9UuGQw4CP9Tq+TT4+2TW2efWrAus1FxxZq'
                . 'S4jvRUv/kTY3xdsWffN3qUziC8rUJfGITHHQp4siPoPcByvkgbAURlNKe4RXj9JTaiyBugBURDmVP7k3ccrVZV5nncjqKJeL'
                . '/EDc8eLPCphGifzoJiK1sD8v7hxExujaNnrapm2Nzt4wMpsfwCuZS2gghP1+kQCPFkeHAHveyD9ilpIVezVAFsD7PAwBa9+d'
                . '8iR7AAhhedp5jM5cLOOTq15tYKsYX9tH/mLuy9a047P6YyEiTzs0rxTjMgG37ccuWMX8ys4a+UfMVqrqj1fxBPBbHjowPFXd'
                . 'yq8O6ruiPm1PTUWvwhHrtlvbHA5nx0yHz1kGC4MigzYaF0uL175CzHsqlkBgQz8NlQQtMobfLTfBCYEu7sifl4tEd8A0GIro'
                . 'Z6R9TVyZ6MfrSZKevGZWKTByvq5dmA5rZoZc2hk18V6Fo3p+1Dc34kF60+lu9XvLQAg7YdbPDK4QhDCTS6aeJSl+5/NdgVJ3'
                . '4Wp3CSLzcOg2wY8smH/+s5enmDy2vj3aY3f0lcdDCxaEKxLdPlOvJ6jpTAanCDa25+lrxOGy+7KEZgaYi01iID2N3xZ2ApRg'
                . 'LasQYyspgUJIvaZncXK2HQ0RsEitEIBi7NeeZ/zFZxozhyHMcGolx2AkiDNr6jT/P7xKX8Z0g02IISyKkkqTxUiCNPwyLYaw'
                . '5BOrljMYCXIQsr0o68ypwRyQVvT+8fkpCEswH/EE1ylBw4r271ydV0oO4LFIJEVCLMHaBbn4Gt5FqBhwRCNGQelZmSMcMRjC'
                . '4U7+5Fi1oIZHfJnTCf2AFc2HK8xJhELgIhh3GT0DY00InjWDeD4FP4in8FnTh/uk7P4j1Y+rBjjRAjg79gaKDbYcYxSOhLf/'
                . '6fGF07bhNy3Hb6NOjyd+WJDJnxgtys5Lx44TYoeprpChyqiZ4lDRM4ZQUmG013gKQrc/z2Rl65ceSBL4sXbYEWFUSQKhrrlO'
                . 'IH9AqS+APdRzq/HQB23bLVk/qrWCnrOKNx7aAs75mTzJDzGx4jc/hyP6EBFszBbw30W5Re7zwAUqXvqS//OYzd/+vhQRCevC'
                . 'Aj1zwRGPNIswEHeITHviI37RwU95AnXq29MtJIF0mZsupv48sdSXldMDN04E1VceU5IDwO73xjTVKb3FVmsv8IDjhDgg299/'
                . 'IEkQpDrtxoaNyoon8LQO/GaeTIYPLMHn+tQc3PP7ye5wy5AxIovnj8/eHhamMHvIsZQQR6qr1Gnq6cBQr+2Kr1It4CGsi9IL'
                . 'CqJAglxafZYu/gI4ydFdjrAV1BOr/8ynh+HylPRVt1zu32GalOql+qQ8+QWC5/uTEbpOUqe+QOq63GxVNjum/8QsIbW8sIqT'
                . 'F3WkdbF2Cb9oUw3bpZ3t+yhz31m+uwrEyL4UlI4jHVyshfrR8Cu1LoWLZWuDrlfAeT/x/Y3LyJSKgCbC6lhDst609XyLNFnx'
                . 'm2birbgly4EpvfFeR2dzdFjiQutKjsEhdRdmjBQJPVUca7TH7RgBuuHWsl51rELRkvQQI5GRjoAtODNKS2D3YGI2oaof10he'
                . 'T8D5yHCvpLr0YvKfmhNPOKDb65j8q1QfuBuzdGJyQcFkLIHTWW1qyEf/WhzR11XJkdKdF/4mULyLpos7VciU4qgULSkvjqPq'
                . '9UEgkpqbwppc364zeU6ctxrrP4y9EP/2oZ/eU9d0tfhnYMx0tbV+tb8k1MvSUG00PLaM2LjmGDYbufX2A3q0ROeOwuvXtpNH'
                . 'FBjD7n6LXr9i331o8xh4Zp8ydg76oczt4mFsAu/VCKlH3HjssI9sXBhEM3aagdGXY8zRZZs8nNPTbLas2RyP0x4Cjl03Lhlj'
                . 'c8RMFO20RQNyQpLjaHVMEn5FL7ZRHPdwR57g7hwhp5N/Yz1gWTab1bLSBmlol2yrTosS8Fui79B77o4ioTHeDYlTyLPZaa8O'
                . 'TP3YRPl/UyoKKAoMi40YHa+HOMKjoobw6PAXbkfQtKBEE6MOs8aZWjuGnnMeoA91OGJ+dRrwl4vzg2Jt9M6yREBNERptZcuL'
                . 'EejavXnXgQNt7IFsr0pzYO2htez449mEZbE+CT5g9S4/B4nxdMuJHu8cB9BRy7Yg0GS2INYQuGTp/Mv8wPme77Pcp+whnFrS'
                . 'UkxSnWazj0XLJ3D/+dtbP6qKlv5XDh0Pka4IzFwHheM29F1z43Etv6kNrlQyhovWnRidUb4mUqn9hcrpxi32pPPSHAbN3rFf'
                . 'uEDmh/tdVRJZX+Ow3wFE6C/4MDL05hIAj//HjnnoCRvgBWqFFqASdAFLcQ6uJxKSRmrJe0pLIVQHtZSqpB7xKJ43r4K3hLeX'
                . 'd59W0w30Fvot38yv5a/i3xBYBUZBrWCqoFJwXfBNqBX6hBnCBcIjIqXILxov2iR6KebEFeJ14rsSlaRGslJSJ+VJY6XjpbXS'
                . 'pwyf6cPMZnYwr2RIZpY5ZTNkZbKHcqa8O2m1/ClLYTPIu9m3CpGiC6VQ8VnZjbpH+YeKohpcWK66rKaqE4vmqw+rn2vEmqzi'
                . 'Is1pLaYkTDu4ZJF2t/amjqoL16WW1uu1+jG0WwaqoRd9neGF0WkczJhr3GV8ZaKYIk1DmKtMR0x/mAXmwax15uuWXHYndj77'
                . 'uJVlTeXs4XjcRO5o7jauyUaxdeAtth2yPbWX2pP4U/mXHYDD3zFUUO5469Q7FztvuWSuea7n7gJxlHiseJ/7d6+oZIrkujff'
                . 'e75uSG/KCuVK+SeKqOJ7ZZfynGoxXAAPwl+otert6utIB2QUTaBr0CfRt9HLGrEmpGnQrNSMa85qC7R6baV2hfZt7Y/aB7pA'
                . 'XbluVPeh7qQeoxfoU/pV+nH95/o7Br2hxrDW8LzhNyPFqDWGjH3GdcajxnsmpUllCpj6Tc+bvjTdNbNmvbndvMG8z/yh+bJF'
                . 'YglbZlvGLK9a/rMWWNnWGdb9NoKtwfaV3WAftR+2/+2g//24kDtCjimOtY79jmOOu84QZ6dztnOtc8L5m/OC876L4BK4VF+/'
                . 'd+tddpfflXDVufpcq1w7XS+4vnA9cpvd/e7N7lfdn7hPuP9wn/NgPVRPr+cTzzmv27vee8fn9JX7mnwjvkW+Nb4dvsO+U36s'
                . 'P+Zf7p/wPwyEAisCHwTBIDFYFESDyWBjcEZwJLg8uDd4IPh68O/gvRAppAjpQ85QY6grtDE0Hnot9H3oZOjv0OVwVlgU1oXr'
                . 'w13hDeEDLmA6BsiBEoD541W/A0RIEBGFiFGR0EVG0jxBn3inTfY8WgNRS9PQWvFNFWm0uaYFWJySxj259o+16a5jRXEvIul0'
                . 'rZWSPMtBdhPjw2V8A/pugfalCZ9onoUOLWli40AHfu95s4eK4r/9z4qfBJQX90gIxO6oLbIS9/KdbG+uoupUmtuhK5PPont9'
                . 'O/RX78SjLphss5mqcENPOt5xoeS4kIvLXO7QS1KXsXz5joM4pRYJtA5lHcmvBLIZy7r0cWo+HU8wllJOo4bVUlYprVxh7emE'
                . 'nyPF7e7sXzi3GGNySre0pJOnF/WylFWK6T+/jfNbSsubIF8QRL6CAZN+2Dq53/maruGBa9quV2rr6HFag2kVmOEcxcvuUk/T'
                . 'Gcs1VbbxeQ5S1dbvUwd+jQEKolfbseBXNVBQUa1OOSdH8MApVpo1c3sCv4j7TNeQWXReCofA0yUVYHHglfgkPkndPN7F1Arb'
                . 'bGihqsLkhckU5DIGltI/XIV7E2cAA9VGWIh/tx/O2M7WSt3A2hqSwe25wjF4ZwcXuAUrHUjga5WgqD9HjAmJCEWIkRiRV04n'
                . 'dDlvkIR5bKj76xRfdMRIfyxn/Ii2IV+F9L12Ng4kXLfPk85pG/Hd+lQVJkO1QrKAOaRlh9dsGUnkTvejJktbvX5+uFu3nXlD'
                . 'n/8X7TPWIJ1gzOQwVy+yLwDiIkgEzPHWyTCC1uVpLL1cY2drHNt6JomAZX+FFm0dXDB7JIQ65KKPVwIuJNflFM9lr2ZdJkTA'
                . 'sm9JgLYAu+/leLHkRtqpwdbBmkKqkuFLFLb79vnMinI9LXvcvVoNRtR+ZWNhuz+vtfKaLzHmqpX6FV70WbDMidplz0f1sgQf'
                . 'xW9w+U8+zB4Gl3OdX2dA1Fn36PFBzlTZ68D2jqzpuLP/aG3klUUqq1pbsjxnNBW4c3It+UlBdjLa7AxIeA3is169Vi+iR1mb'
                . 'sWaJImnUxPKZEWoUMwyrEfOanlDt2u0/4fhjvwxEHed2g8KE22Dffd8g8SyL4xjZakAzVDfE11/hRUVSLQ3PTdj6WfhyCrQu'
                . '1ormdHGhS/LtxmruQe8FItOTLq56YYopC8YDU/hpr4XEEmck5DI0Ply6OhqcIRoSXB1Ub95JMzUX9A4ECF90Kz+SjNHv6Oz0'
                . 'iMaQmxg/oMXpwWxO10uZ3yutni0g4ozRKjHeWSrEugrNYuP8rpz7BNrjQPDBjRYOY6QbRW5x+cDTsbBvy9K0bNC74dBYFPxO'
                . 'I6UNBY4k7LOWXxrgufnE9cMdn20ACN8UUREQO8hAmPGGzciYUZFL49zpUhXTf0ytXavRp3oJlrjKDiAxpk151Ndk1vXs8Aku'
                . 'pLpWpwZMACV8uKAhuTzUmz579vnSskLOi1hlvILD55bYmsX5N8z9zz/86DbZd/+uVJSraLjFiQZlFO/dAE8IkVgkC9UsAxoY'
                . 'Q9SAOEF99+Zd2/q2rZw+3ju+0h0xD38uGJ1CicTSrOJTVndrU8jCl7p/nriE+BVr6Ho+B8Ej8Q/MqhHiGevZGVkBxTYdMhHq'
                . 'PPrFj3+crPzV6pfzGhdJTrMnNoT0X7dtjN8HDqQ+uG7jE+SKWOwBppN4pS+yeYbver+nf4NtWcOAF816fKOYckEpswiz8HUa'
                . 'h2Ec+DGtjaLYD9YYq+TYbV2HGH/v+Ng1mweLOAz9EJMCKc1m5uubPtUTpOk2u/22ATfAJmiT/wTrd+lNfaAUblXBVzTV90i3'
                . '+4oWP8JnBrTJevfa6w/cLHtKkri2XmkSMDM473pxleEcgIdCYbWBdl/a8o2cQuqP/6yg9MS3otDUiqhnLMC1gVUVZnbeKikV'
                . 'z7KdtVJazx2h9C8hwaSvwrRe7j18+OQpCVonjG2EPjXLh9ZbAiLBgFx/TgL31W2Mr3XOHqHb1I58L5nm3XRtbbZEueyvB+Jp'
                . '+nVZAy6jWdD2M/fXlJW1P56pToRXczdDRYC2H20+m20vPLu4HMFet7tF4mi8JdKPPzJNYtHd1Lpq092bcypYwtyUMrYl1CAa'
                . 'e4sDXg0wrD724Gpks4wYOB7IFpMgzA6Hkylu5zwzSM1PUg9aXAhSSQUStA6xiV257ayOJXaonyOTNKYWO5ac7EzERUcrkskc'
                . 'oGaCk6UOIhXeSGElo5AQd6GnsTZEVQkPqSSBhlTQ0+KUrMUwJs1z/Ww0ei6StPFCzcwCfP1wB7aFbVhFh6krvp/hjIUSWI1Z'
                . 'to41hs8LC7gD751OVJj7AjWkRdZ5Z7/Bq+VxXM2P5r0A/2u2DzuvcRNNdO7c/LKyp3IF7rAXzLUb+fMWLc3sg9NNv3MDVnga'
                . 'qvGeR2Vc2mVu1oDrse+nJZcBc+WlrCN8thXPQDJ0KRsVC95gRMxxeJ3x9giQkHSeBsfZkI+lnTGulBRaq3NArv4y81DARFW1'
                . 'n9yCCh8pQAApvmGNyVmdyqj4cCZSPHOBlo5E/gcF76I0JAGdrDtA13h0VG6znBljBePKVUIolhJI6r/PTVjfwTTCAr4/lyWH'
                . 'ZToOvdHUasWXKHfaBS26Iw1YT+rH8QIhdSuA4yXL33ILXjKgWGubKlR1JuJ4gQ7blpFqsZ1vnCIgFt3WOfz8cVzlOl4O22EI'
                . 'oa5KWWTpcd8CGCJcU0YJloZAZdPzwUzsE4yNpslDIxc5SOOQGWObqiDw6MPpaPi1DwuddUmPpitqZ08myNERI9KJNapZcMXY'
                . 'w2VsitfPwy2WBKrhFSIYjUYBmQnZBaPiax4B4dr0xxhhwpSNv/bmhguCO762ibG/F6VShOIq3/6QEhlrEcjPDcKpiYPJYKWk'
                . 'g0DUx3IzVzaGuNvvTJVZSeBBHfEoHBDtY2IWMPxWJ1t3nsaEadarQoLnO0nIfQeMQzEbMpdABJydM5KfO56bwrVs3ZQBuu+M'
                . 'n/cHu35fFENKcDW5TexSlTQg9s/MUc+tpRkUYq2yLSZOYMC2GyFE3MXNZZdyMlIxt5dFe8GJYWXZpebcsTEwzOaOpxZvBTA/'
                . 'jcRBPAdhrFE/8LY3DiHWS+m+bx5/8q5/aZcvwLQ/sS3wNqdJIIKmOaeP3RtIB6oD/m1+Xzzka3GC6VAfS9h9w/lSbX15y5Lj'
                . 'BSEUDLpP1H3BkixNx86nu7lgXgO208v/rp7/YUf2ED0cXX0aJJH5ERmuVqgffBh7EqzhXwf+mJjfkfXRP2N8R5+6N9DeV9OV'
                . 'bf40visW/hM8Ft+7O1mLB0jRt4z5yrE5jRzbswykckr541WjVdFa9AkC9+0ovttefgHn1TI0MwyGI+pi+hXNb36stbIErHKe'
                . 'JLPnp8Z9c84KVdgHA9Sr9YfT8r3y+5yye/gRjd6r6pq6YPGxRzmpi8sIDZ4nk3/sZ6s+TinjFPiaJeHt9SP+h7G3LNI8QaLi'
                . '5LQxerv9TwAYCOLyE/hIT74c6vxZcV9cwK0/JdPuffhouleIzETTVV5cdpZsyf/5v9ttXthXqSG9s+9u/bZbXAZX35pC1QDC'
                . 'hRE/XWJyi70He1EtlUM1u9+PFS67yU416psRjM5cNKsf1p6z3bInHpTWUsdZUuSQ4B61zFRQ+TXNyquoF8mbUDL6VekLH7a1'
                . 'imcWQd+AZkxsRHHlDM12KMlMZdn6m8s3ZoYTx2zKtZgpjmIzNbeeLgZqKGkRq2Lm+KiitPyTwjds0OIW6eYsXBevklJ50cYd'
                . 'cr7sfRZ7Y8oPbrr46/gl+Z4+7vU+FZmGe/nWBMJs8nc/SGcFyZ5QGmkSeUNTqwBVfZSIFd0UJWV567XRT/ye7JRK8hnEKC0V'
                . 'm+ST/+j1bkV8gLukPzy4++LEAHaR2Ulcvl2ZJsSYZiroyZqYZgznUrTZqAToiFViMk8NyNMHhzfe1I9mOexTZbsiiwgu36qM'
                . '4AYTVWbZmJUU9dandeUiI91s0gWTdRKiUSroNfPo5MS1LSxXf+IfYqQCwA2xM/lc3ZCKYBzTgEVz+brH3oMmR6MspcjIwUdc'
                . 'a+klxw5cmmoeQ0vT5qCNUR4dAxmfBRQZXZ9PUnLWv6DWyDuE2mwcz8Ysm3WB62ZPotOYV2xswVHANur3kzZNISFRbKZu7Apb'
                . '5ZST577mcrWhzxOFbmKzQUkfd9Ww07BDjvpDsGoO6+omMGaSY7qutx7Rb550mPaDQfrsGsNcK63rYbuTnQYrVFrRGmVps5E1'
                . 'TYufM6kvF6SmE8ehL/Nv0dVnefcdwU68AHKz+uaxj1UI1fJPh60XoR0Ol4dhI7VWhu3ME+OHBnO1IMoSojta0SRf9D3DTdeu'
                . 'j3CISgli7csrNhhMMvP+Xz6CukYFynbDGyM+o4K+gfM8q5sO16tm3Di7a9YuCp0Pir5TwbzfVLve0fm9UXU0X9p++JJdvVSx'
                . 'MkHpJSNmcNNHRb8ByWLpBB+l9sVKswmYCf+5V++OcL9l4w0Elacjr0rYOH8K8hMaqsJ71OK/rQfNxBoTE1qqyuev8baylWoz'
                . 'WhshieMJi6ii7q9nS30Uix57zNb3+E7v3fVGUyzcFAaFhUF1ofXOiUvrxxRzSPAhHmjd1kJCIOLsJZyv0m3972sVE18PfHev'
                . 'D2SPjMEX08qLV3fxXwnRvGGV8K1WBjUBoKWf+rVfPYjtrsboQ/ClYFmIh2ukHMePn8lhgxUeJqKZC9tT3QzjVlIIa4GG63jN'
                . 'O/K+yGaaxkADGFqAekuwi4mZtr9OCS66prQwo2COdwLJTJoDYWkybVUtwJZLpeVskleaZMn55tbXVFbVIe9NH3pbfVHf3NTh'
                . 'KpTt/qfbmc8FeTi6bLUCIM5qXQBuGRZcuNlT3HEERO85+FS9z7ROstmsPMwZlN81C7nY9gmUfLij6k6GLdx6iePpq1SXeZ67'
                . 'suTIhVzTt8W9gdRgNk42Rqa7lPI1gEeVB/d5s2oEZadPsys7lRbwDraRwjHoxpZKlORN1rUioqSOyzyzvGBGJ61muSpVyejY'
                . 'R7HQj0ZxsdQu6yjt+6ZAwSlD6LuO2i2xjDClZBJqudPRtd13AXjTCBSYo61esbSqBSMeO16PNkmwiOc5ly9+mcgUAQjxHGt9'
                . 'EZAspM8wQWKHCOMi90lc1juJURI4YQiUshPTxMMDCulnluOHqYFZpIzR08pOarMNQ3UFp6P2SZgB959pHbjUmDnadduZbnRt'
                . 'Qp3RkzD9ZWVsOls0gDgcVaVWxHVodAl3e5IQxdRmrl5cn7uIyyi2RsnFzEdxLB95rXEjUbZBP9Khbpp2TYzXLN03ithtaeFC'
                . '9//mW1Y3fWTTVD641rCc0oV51QiEVIdCjvnKroRUhUgZiKikAQxplxuK6E0Mamnm3JallLPCnabXiBp4et/jkfeo+hi4rKlx'
                . 'a0yBaeJ+a06XoKQFVZ2lmJbv5FGDw/rfjt1VcwFdZI+D0zYgZa78x+5HEmLMwTqkwtUH95DihY4MRCfVUgUOeDMvChGRzVpN'
                . 'o7d73BR5cJSZ0hakVIF5nDuCm9MoVhtr0o67MUff4HwXWSUvuL7vxpcoEsBj8kFI5ZWIPSufWjw3o8GbgdWZMeU6SQAvmvMm'
                . 'wa2oXFL3Jp0RxOo6WKCLsMsKoZdWQZpAxb4RmCWUdfg2uvH8NiiC5BwjIdahkMZ05S4fIiQWw5Nkr3/zhYISHG+DdbDvwsdh'
                . 'VucFWfstm3G6DbZgOI5DOWThoQ/u0IREZtYBEXQTTEiTdUOIzlDHp/5FBw/OYCVZUXQ8wwU3tUfxW7iP24zg1igj5awih8ZE'
                . 'dc7ziubMufQYJlXOXxDzDpd7Fjqkz192DRE3F1JrRO9um7hdjKORK2eCwIPDqPRyB29KrFkLm4cxws2F2FJsxYK9yzEvLDCL'
                . 'N9/6hnP4tK8VHW4Qma5CISEIeoOGMxavG1sIaoleaaj4n00cvN9qksnY3NusjGN7HjaMDNupuvBwrgO84I5MrlY2iB1bdY9j'
                . 'koZp1CAnw5HpNst56eyxhFKCveIIc8A8Cm+q0DBX8biX5O1AZpZBCRxnzIX7bOJ4nG3dVZ6ZPIafIYUa3K2kGuCrpubShwpN'
                . 'wIXgOQoGd+l64WuyNO06jCVP5XmvExxe8CREqsl4sojk72r5AoCpG/uAGXya7gNB2jbFzWEZ1Xw3xv6Jt9XrAYDgRQHladkP'
                . 'Ury83zmf7OXqhg9LdY72C9HtTqjd3f9TpZn34MLx5u6XJidxLlbRFV4YddmkYS4W91pKeeZKkptjLlSKWojRLMrk/32bKS74'
                . 'KY1PUZG1LUtQnQJNcx43DPFmqFbmPS271e1OmLkb/9cIK4sB7CardpxJmHIguYiIgO3+kuaj2ZqLKRjPEegaSNnHBlJ8DNxB'
                . 'MUw4hPMXJj8pq+MZ7yXIuM9MIGCZHQSfYqyDEvMD9gxnAUtSTZgNvja6SaqdcgC4e3kOu+ZEwz6WOmTBhVPAfLQpRLCMpRyZ'
                . 'OuxIMriaCsAy6hx/1RlrcGh4y/hWe4eyKgKc8Cy0vJZnW17RmVEEzUq/hLt6CDlgX8ZM5pGPeuM4HA3PBV4QJS4HzJ67wHTt'
                . 'djPD/cCS2ct5K3sbIPc0CEptaUCr8bkqlF2+5ALepIJDyb1+RM2Po/6CT0GYpUlMY2DCqqYIWFwmldyiSaH6bRPzEvfT+jyh'
                . '0niG6vx6i5ize5EwKEqcXcRZ4dg9mWufcJcLHQRKQcmDOV2d4rUQQW/7+FhuWUphok8pGQNCVp7tayLK4RtPDR74WhTIVTHm'
                . 'bvJM9vX1ZlTDS3gZRunEL4Qkfk4XatGp42Nw0iOsDDlNGMXI0iaDc0Yn+kXwIPH+DLPiHgadoVJPPCCH22P3yrywwS8cvM9p'
                . 'uhLnY90155I3Yr6EQ3evX0XJSpOBUw/mKI/cTJbt1Pd70Tfx3ISjUuRJ8DGbr+WZJo8G2pDT52eUibiMzlVYBhZ5xnXSToDS'
                . 'hEvCKMlZlrP2Sxqgh0WlQ+g5SwknaB1GyV+Ig+R6RbYM5VreamorvDwmvY1MqaxiZLueDw7QWNa0WUfmrz1h83VHFwhV4sXN'
                . 'g+XB+SAoqPBos5yqRfRCqgopDDSxFp7jO7aaAz4KUBHCn2jbfs98twvLM87jz0wcveFZ3dLPrJmc02QsZRSBNR+aHjSIIhUz'
                . 'jQmXkKFfn454QxpzeLLFGJem1uWIr6MK43clzAU0h1WjIyQYU0jQ45A0ylwJSG1lpxKNR6wXVS1lXU492HdvS8C45nRKTSvn'
                . 'lnktReu1ghuk2g+0T1a5VFW6BmoGYwfnujoqKwrpZ6pTib7xNuKkPl5WtkOK7w8/ChY+djpXgoG2DhJ7yXKVH6wSH0OKF9c2'
                . 'xGi6ZdoRXc3+EJcjYHp6uQEO52Z0uXkZE0CvDK4NJ5w/NC2hciEGZpJum34chvF08+TIYFXdOtf7XN1h3morCyEFQUk5ZN51'
                . 'w+rjS7eEUIM/hNB9FeGm9wf+iMaXr3ATmD/F5Kt744crPgYojNpoyFTud6Ol5KkhnY/hQdXaVc11eOU7hKCXdHJi2sX9rZC9'
                . 't48NOUwAOIcDyn1IA+71snrhHiaLV1nz92qfetgMl2hdvQTcy2beROiaqtYuzVWVraA9A7UsEbp5EP7Ou42El07oPSYPt5pz'
                . '+rm46UPeNKgqnILJ7PfPeHf+S5AfZWkmgXzSMGv2xGznsiwBqC1NrliT2Ca5RzOVc17IPuJx362LezguL/3+mlmV9tOkVAQl'
                . 'tcypbGrJuqlXU50kKSNTCqPSjW0ZizSemutZNsJRXg6Zp37Q/e9ZP4ZqEE8fge1pojfDIXifzsyzwhkxnxi64HEzDFtZYiGU'
                . 'SmTfCtwKqHLfxHvJedypgWYuDadB4+KeHE4PZ+XAf3GqZYJBLFMj2aQXu4xRZgJl3dZAvzWC0blUuZRWcTe0rOarFMdY1r9q'
                . 'vj5V6LNLSC6YayJQY8tM8kKKySi1j26cZzqe5OPQkTfYNYFvP71sUqsvNVmfbEgjGAIgjn+hN65W5jDIJChKQ+o1vIDzlE0t'
                . 'cokTllvS6TQssjHFQQnvBCK16ZBzze5u/sqbdWso0r7V7ms3hCwMVEqjFQJRuZ/9CRTFS80YC9Ppp4PWh9zlRl9rlkqFLkD9'
                . 'XD2/vNz792rV2iky79p93N18/nNPEvPwIWOx5qMU85qpTBbDqNApPArWADCzrXIfobYz617YBKlDpKxXAiRGXW70tmYjV6Md'
                . 'fIAvjkx55roeFJVQqWmXV3lsJlRbn1w941Jw3zoU2kIAy8jbfU6+VzbMluIY+WOgedb1JBo2iIH6K++CT5JnkrUQLonUTZCa'
                . 'KkOG+8iX+9BvmSqJcacGAI4s9NnqRuyoHa5yt0Gzq806n6nDbsOqhSQpl1tSIb+0tSJzCNjjhrxnNsd0jYmLqA37qap/822r'
                . 'Td84xBA3qS2lK9/5D0Dv0UZnJV2QaqTgcl5C3JqfuGYE1nEL0d3Es0nkdU1E+xpuapOt1SkCJX44eQzPHoGjHznpwFiaTush'
                . 'D/pcr3MnOXajqVWUZed53DMJq8sN9DHu8AeBgskR7Z3/fni7KtPQm+mvIwGiOSdGLNQF/cr8xdzhRYEe90IVFGUk9ByXaOeu'
                . 'ffqfjsmjheiWIhNkcSFXD2FCAO8hBHi13fyraTkom7Up94PySmvJPj4tBr6RNJtSUyqFYFlVZ0dTI523HPqpkORKwLwE6Bts'
                . 'P17LsFxcIiKYtF0UG8LZ2IV04YllgHzbzVR3CatphA6pgTiGiT5Gk97fk8YX9F9HfO63saWV+fmyWS74hQ/Bq/28/JVofNgE'
                . 'eZti1h/NHnkn5KZrT0/edOxdPrjw3klerMo4uSxiaxZb0mk0YssqpWuUEBAtltJ6a3GPvsT5erx8m9G2fehNp+ub98uq+XT4'
                . 'ASHJmtuBseaQ+0QA6TY6RfeJcwmHNOHfRMlq7M8kwXSTzc+myGGmrXNGszT56l3u7U3qm5u+9ltKJ43JohXLpnq0VdOZhWnN'
                . 'xxkMxN60pVa20CulPwtZ0JnSw6rTt19GdtMks8KrHkJlclh9nFM8PlAzxrquq0qTJLdamDemQIJOmbTlohLdhy0N/QrCY8bX'
                . 'siE/m9FkuJq84Pj8kTHPX1ZpjCttUnwp3yborEpcEBKuaBevPw19UhPfJEkgTtDOXVN6dP/0lVcMo0qUmYy+VG6Cy3RoumQF'
                . 'iytq1lYoJQqcPCW0V0zHiJD1hFVoxvdGYWxDrmfWR5FSiZ8nBuGrenVgHeXkc0lr6Rdv2sr4MI9vnH+eyWod6giB7rjPv3DO'
                . 'Vm1UvW27ILOuN4JN/qR6kfLjTdd5TiiXbjnagS4t6qw4gKUjX+8tA0uQ7BlJ3RaV1solctn2yGURGRqnbg+Os+cUvnjGhvw3'
                . '4DdQWu1A6wkJ80pQlNupOQK5ifYilE7mIlpw0TfuAzhhiC+lpo0ZePfUD+W2xXJw1vR16X1pCoo9ENIQBSx8SqX5h2l78JYP'
                . '0V8bYluwbuY9g8B/nl+DfbvdZzlu1VTYw/mozfOZcel67sHGZ6x9D09nsvsg/CaEj/tOl7Hz4+zgkQ1FRW8d8kNjM+f9R+t3'
                . 'HIF9bwK2zwguhqanexzhpgdmQYE+1b0qVnDbxFkeWHcFnksSvjeXZmTCcYzPCJZHSyr7iM26JTuCbPZEXnI8+b6u4PZumt4O'
                . 'xfsckXsjRe8w6NIFMAG7yQ07Wv6FweCtn51cl2nAOnHkhLCfkCupchs2YrPpVfCwji4rKSV8b8kGVWh8CtysJhbfdO+GX6lM'
                . 'c9LlqCmimuNEd5vbtrTG2HKSp1UsvRVpqqfH2k8fjc1cGndC8YWHvFy1mGy6ioV9+bJpLnZSbe1+V1gnu2Weu/B/ELKkLE/C'
                . 'HnVBLl3eRksM7/iOJnJdfMS2NHTt2mraL0e8yL9PB64xubB+imuEjDK61Gxzfoo6jm2M7SfVVYHnTLJDz+ZwIU2UidcLpufK'
                . '/A6ypQJLTlm1Z1In++n8PZB0vMKKfD+983g4W4KKqOfprHSVbNuaoRt/OB0ZD10VUNNXNJWR0kyFmKMI1P1CbxHx6IQHGizD'
                . 'A47NJGPmzmaU/OCYEAFL+Ig9q8Smb1/6jY0ic95E/uR/oqoRaFxi5sD7jBo+zuqmKqSv/Dgr6IGC51a/lQXrkHhP+qTWKrF7'
                . 'NZ9dtiwTdpPhgLAydxmBR49qU+NmSz1PLvS5VK3zrWxv1Zdz0uVQFHPNTvzx5w0XpOBcpKgDGJ4xMyLR/83lfvqA03XHhenH'
                . 'Cvg3Ur8+FgHL936yBGcpHPmHL1bLW1hmyLct82KPVeq6w59tMEyeasK0XzisbxlfIyQrSOrXGCLOrdjvEcliqJ/ei84cTpyU'
                . 'gOfp5FWSkfSRssZuNWxHxqGyBrm+1Fme53wq+EjA065+aWatkp7if0OeuXuVTq9y9Hn8MO4Um3ycS3Souzy7BIHpcABZPc1Z'
                . 'AqRcLdFvXN5qBp+Dq7iu/5pRbtfxFP7lpI/B8RcgqafJ0HiiTu1Vi8pamh9K2qvVGr98tnb2jLuw+iMaJBoFM6VHpor0vpy3'
                . 'glN/nuNhuMWvv3073x7eqMn3f521AU3o7DJlNgrFiyrMwHBUwyW3JMV6t8a/Wi9Os5ZKIaw+fjsJihonpaEHFjVPF1OVMZ0d'
                . '6t8nRkWzlaWKZUi5nrFJKFmg804gf9BOV36ZFFe3RznvFy97LcfwfJ6uA748w+pDJ68gJHU4BWj9gBviWzwZsg0SUlrfDF5W'
                . '+Um5B5zXp8y3Mb12poJoiOo/N+OKV5ALRvhAJPMnF8zwUEVLle2n8pVdZ3axuoM7lZGeGIKQhVHma+k92JHDHIi/HAapFjwN'
                . 'zpltSYgiMhUZpkhOSHURpRppG78pqm2K8Fpe+EVcTxqrKR1MCcL4Zu9SSKVb0odHXTssQWnDPJSYrjajqIFRYzRKH/vQySIl'
                . 'ZUT6XgPRV63plSo3h0XpkLybeidhTFNs48UFWV5Ul6HOSCO+zrLfTUZ66dP1JsyJ30Rw/l0ssg+1erDf+JDz33qIWCZEfCKZ'
                . 'OANrN1b5cA+ZgpB5Rs+i7bOoq5ESZKHKDXihpSzoEB0Fk3cN16dsfx0A4GiJIRaCILa4J2TmPMb/0v2kgt+HSPDemXmjsCe8'
                . 'EptKfjN6Z8y5gv84mK2I+lBGBvBS0YkiMT7hWTglrjHAo/ol+W4w5O0wofXEoRFGMDBMdtJBbhuzM9V7SlTGL0ThlK/Vsuy6'
                . 'wDvhjV9iKAAndSiU2MzgwSSimIRW2zpYWA6L+9+H+d8wpX4QIKHlpNQ0/Zozb37n3iH7M1rwiO+oOlt67l2mkamubI5onkqw'
                . 'inaBM71epRk4YefRcOqRMEiBy9SOCHWXz81RhowIllJA12R7ObnrgQyT1MrGkjPpjHg5dfbStFHiYnbMoJRZj3Qbei2QGYTY'
                . 'JcGpkB7+R08mCJWTwClGull9wpNPdS9rb7lPu4y8R8jVoW2RK+qN0t7k2VcOaxEoPDkIFq3ycO2LPi1cwdzysaaHu0pGhCD7'
                . 'BldVIcjVWQJNTTZZpOAf2ZxSZPBi4nEMk9F0kh8/pgEIvMOy1oSLsI6sij+p4avrV/7O36gF8PjGSL8TUyjxa0NyfiX+9J9F'
                . '/ztAViblgWmt+iP7Yz8MZj3aNRfh7tQhRmibyi2z3T7/z3F5JDpuETeZ7Alf27h505hBT25vn97cPP0BETylCuRxM155vr2I'
                . 'uSvbgGF8YE4BEhebuiWujMsL9EzGkJuGXofdRS+f0VKZyGrc+5UMutC8uoXtJmLeHDxJOSkHtksMK2CwnzCmsu9Vg7XT5C6E'
                . 'Egykc7I3VPc6CBje08N/dgTDQf9lDzorpqKmVGXkILx7V9CaMHOS02RP+ez18tKnkUf99MtHIHUkhZHFXVBD0eCNrbvFNd0r'
                . 'OlQHwjj6yKkWWAhT6+LpCu1yyF910xqiKpt+M46R1Ao7LUNULBuZOKd432x9YC9VKGJUC5f+8wUfquzupDFv787jx9dgcPh/'
                . 'PwiwzGYBiijcIWWRUXVHX73z7P4dfXI+m7gLlPv/BzEWSW9pWmqeZVpUmDSKhkLYzcnUoEm+02Tmh0Qo02QcZwlTA4/T1Fp/'
                . 'e7H61C4FUy+94hA6hFtBD9bbuB46Hc+1ksNWaB3FoSQuKSYD1/oWf7nS3GtDGvI8I9fcDVza4z7a0E7q5vRwYLjVOZ5lweuq'
                . 'RevB2nGmISawj/jCmF2V+Bqjlqq8h+dzHIZ1i3W7JKaPBCElQaSD1P2lECWG3LaKoCAFZc9Sv66a2EKmVpEmbsdQLVhOZhMn'
                . 'nqbxCdhJdTDMqSYm8ODi+lVy57fjOYnhIkQorJ64ui1XfvVpj8sn03De3bFtfKW/aicxc+SWzhemu2N7DpVnx97dAb8gCne4'
                . 'pV12R3B3xKblG/v1sd1R4S4HMa4Th8rat4wnrPYwx/5BVK/zREq0Izo2SnStfuWEEE1OInjTdiQW/LTXL3pJWGGdvUlRyqFX'
                . 'U0OBaPeAEjFY/mGcMWXwxxbfBu6LSbRnJ00Kj2IkCIroLVlT61lKIJThzkbP9j46g8O91FiLpjOF9pYcxfmaEXtwTbrQNvg9'
                . 'Fjk3mPN9T0ruCB0JbBbUzoeb3Ym02r0TDnelQk/qpIe29bhgTFWYUDLZyafDa+ta0KFMEW3oUMnCxnsWBA9SCBjogGJdH8lC'
                . '2PlZZxwOahxlhK0aBLzSBXyo2IYEggfp/cP5RcbAByfaLyIR5O0w0pP+QpdTJYvif911zzmMhBjXqN0ai5nU+jCL3XAOfQ0k'
                . 'FB52cbx3IKX5+bEBOEJsQkSCKBG79Wl4hOGp33wEONXsaOS0OVC/rDXro11PfQZOS02BuE2NKOc+eA2pHrArYS+m1eX/FgDM'
                . 'aGuuD7bM8K1VfgMdOykdKc36I23DJNkwABKVhaadd3xLuCfWkNG9RzvIMo9slndl6lKViWgU0oTYjF8nwD5I6EfuVvMr3XFg'
                . '7l+PGbWLc8PYcz0MQhNLqVCEAvH2xpTtQr9TEZQWsgM4cTaubt0ewEHyKFJFUHbF5WCIgZW0uXLge4BxT4Ll0Ojkjrb2bhRH'
                . 'P4Jt1QPzdxGbNChRC57BFYkVtENb/zXLHIrpWVsGb8GFhdlPJjO5qHUuUVm5E1JwhiqAhuYqMh70aGFLRNjiIB001oBQS3TS'
                . 'AA15DxxCxUM6hFYx1s4uAEyQt2BK7VffNObMzJNRmbXnNWGCSkDIWywoA2ALeKBUUH6nFttbTu5oDFyLIlR08uEY23aU1SPk'
                . 'CrkVkcyQtU9geGDqty2FF+PMxbzEDey61k1ZiRw0LAlV0S1MU4QmfyTnUpe52JZwFybHrVmQUpcnm0IrW/3S0TUSXVUa2YNK'
                . 'm038RcCR3MXCzEV7eAVMYmaz3khAc1/OYyxm6R7ZO9WxXAWz2efLGWqOij9RFyyk9R1AK5Ro9CFKL/0UJdeSpk9QK3F3iizG'
                . 'EJtxQCffPGPMG6GNxjpvKtl6DDShqmoVjEXqUIKH7j2xDJdV6KUGFYR27Y6gFmWKDRqMIAmN9Mu5VsqLjWnLW+lySlErm9pY'
                . 'cbqReNM8AcIRe8qidEvC7I8DbLCgUvC5o5Mp4llZTxC+Ld9WqVDpDp5ahIiZHTCJJY4MmcFDU7R98I3BiXQvJDioJuZeggLJ'
                . 'HPPmKp4tL9jDVAoThEgWOEIFXUbC7xieirlR8k2LtvJwtwt+kKYHyVAmumtHxd4MAFpxPD3DRRtf3fcVBwejjct14nueW1Z7'
                . '/Iixt7d2ITInryCl4RSCOBqYCeoF3WxPkTALMQTHl0GAd5kYuU7szUFBBPmoj+zcKuzSFQRSlCBJZy2lJIWeuV0GqjLQhj1v'
                . '3/GPNCtV9Wg7NlZyvh6Z7Hh+uywgWk49GyNvhIa4gcQpGd/Obu9CltzpkmnPqSzXUrmYrhtGmcqZFEDvGY3AxnpkQhQoLrxx'
                . '1ng0A7gjcRAsrS0tna3BHPSFmtPTtUCdeI/Gu3qUF1BhE98qGa7xEXTrJYZrv6OhpnsHrmkriGjHiPCoEXj1DEXtNdD/dxlC'
                . 'AGW7gLFnObgOWiSbdEUzot3cVStrnJ/zyMjn4ThRtS44BGzaXEUyvzM2u5OG+c+NkD4tawK4bVH4z5kkuNh41Pqbmv3nf+P7'
                . 'pEz2iOK3Pkwmvi1UXLazrOyn5fKPSBim/UJeeSFW8z6fHsyG4ZBKM9p1+eQAhfZJBB4BDynCdHJJ4JRJViv7kuqyJNb+VgDc'
                . 'a926wHSGbxZdLU0vioxs5UmU031LgKIo4TUuNtvB/+VVXJX5jJjbTHwhFHZHkTcs1CLl4nqwUomLz5SwWXfSpQwQhHLaCYuC'
                . 'NCqhULHDw27b7iJc8VftyCggn8imjfDYFcuCizn5fYvIVAF78pfiOyUIyppUMmTiHU8y8uyAcPcVQVC31FZu2pLkUHUBUJhR'
                . '9A9G1mWwHP/1QjYnfgh+byQAUY2Zm1IIVYh9Z9aR0JOkAzE3bM0pNxb8HaYHue5J4wAibjEsoZxc8ebuqGIFN45le21Y7dDb'
                . 'TZAf+jVqEu+YmbmMMHgnJ66e6x/4Q5AvVPwED15JEcONxXD7vICF7vtTcj3oElfRjVsqJTPBiOD+dH58KqgbomTlXmBzZTjS'
                . '+YxalmaxjbF2Bcw27GWcafKsyWk65T/DBV0zp3gw00prS0VknB6mie7RlmqdmeBCqLI7nbYANlh/9d9gDqucCdOhF7lH8hbM'
                . 't7KCHFnlkwhgh6UZL5xvb968/uHD/0bj/76hm2/i0PxmshIz0P07XtK1C0pG2Hgg4EIZ5w4qUDdtyodlyteS23RwnBMGHGX5'
                . '3jZxsFO157Pbt1ltgIh+p6HSga8LqLELVcj/Uc9GH5xeUBksLeHgQ925g2crQLInARr077xalnzlqT67smrqosBQilJJzGih'
                . '9tK512DkuUCWW2HpBx/ZBf/HrayVQJPWFtNdEXQaecnkNcOZVkXvQ+oNPrpGvR9qFM+K+p7SLHxUxSK4Kx+li8GcTg3mQtvE'
                . '2FiQsTJr4FqhY1l0inrhcrWOgOoXf3LcZYRCOxXXH9pXyEh8msEDvZ/qKpEE3zw/Hq+6O5j+xRwRLMFPUASocBu7cHx0+/Qd'
                . '3WQTo/r4CwdxmvT7eJcmcdXsLo+G0+k88eLp+46ZrG+NR4Lg91R30scm1P3+0PtiiVCKej2c+OBXgP3sckXJIANmlF6zTQ/d'
                . 'xdRh9aZWmUSi4uqFtkqp2Z+HXXFXm7nUjlNPJlghdw+a7fxfElydSjKHu73GxHxRsUT6fE6bwYKoC/iIB2tUkV/qSC9Xwzrn'
                . '/4Tl6PjpnsPVWKqYpChYfjkVd8Novh27bjyert40OU9eu725xKODMrzta2vjveZF1ndQPHvl1sSPnSe0S2MA30YVitPWEzEg'
                . 'eVmvyXFaApxtHHjBAigsOHpJp9ijqJPemaMExoTWx8aRz9GbEDwEEEnwxqginsZRoWMeddI3TQJPnOj1+FSvrDFFBDJfDKFt'
                . 'h9YmxV/Ear7QTq/GooOrgsBUZe8gD2mkRDi8RNnqokoU7LW+oTz3GZFQ1lSbIERzcom5fDCyFXl2vSE1p7qqO/matsaJVVOv'
                . 'QWDBh/08XkMg6YJwB9MDooRPG1QI8ahRh9zX8SDRC378c3X+LckAoTBkuvLOGK7qgdNxyunU+LipNNKoQ0Z7aSnAkG1BTAdg'
                . 'QjwpFQqCFWFMjGz34IIaufta+0V/wTE0Mu4j1ZpM0LuN0/CywSXNlX/NTmFuGocd7dQzPLDQbfml06VpmtYp33dm1xfhWR4I'
                . 'dxGXcU10d+8MF9lfIvUoJMMxjdTyAKwxYbcFaxz0Df9kjbsL+HvIf/CyQ5YOsHorTRgrRnqD0xPVIQarJGBQik3TdDBLh2Wv'
                . 'baOZcdsc9t5r38UFjZ1N8NgKCV5LNKFXS2LC13Jc4qKzJJieorRMyBmOLVkseRdRC57LNCttWvl/fbZAVcH2emiPKC5jd0KX'
                . 'CYIFA86gARn1ZVKHH78St3cQz8e5XScELhkIvRlB2bz3taVjAhQuXMfeAJybxgWRSBOhpUpeItkzPYiKcyZRwUwVyFe7ZAcc'
                . 'wVPgirG6xN/3mVd4iE6AAMQ9aU1TntH5HPTc5br5pFw6FJW1z7+kbKgWT6PknHs3WXo1bKGwPE6FenDeD0PvffB++RHLYOhA'
                . 'jZswy80sJzgnR303Z2JdR+/Fmubpih7hYkreJC3AJMhJjkc+y5Ofts+Yqd3qHYK7vXF2bgSt0TmjbVYcjFUZKcyqckZbjHsu'
                . 'hdNp7NOcV7dDf6dTLHzn9PVIQ2A7mwPdfZCspQI4ji+7S/1ksIuFOCD3uuk14FZg1+JKunCI24uJEKHeJR1MpKQEhQhj2WRD'
                . '60anohCJMlp9DBiY3tuZGaqSuyTqHZNAFjl7HxJtia+rYSasHgAWrEiikzTCtBQQNOlCAoKD/OXkk8M0Q+PqwuyquN2dn5Vr'
                . 'fAqPjDm85XZFNr7T+FiItUEpj/wDPn3WGfcN4i4D3PH7nAbo3yGDKyuFKL1pbcqv3vZpYUu4cVfgBHwO1fa1LfeSt7517m/T'
                . '+PhShBAe8l4X4T/UztLtzc1cmAi/9WiQdPoi50T35hL1loK2zpGQM3gYITZ+/fia2WKgl1gg1jggrPymVznldC8CoY3SqBsx'
                . 'sVUFJ/5F/zr8GDuI/+pUsVb8t4E0Vg1kmPBh+nL+xzsK5f1SDQzLaCpIvmvH8XR9/fGK+xRqHcNy8Qr74wm9NHKOmiurw3bV'
                . '1k1W9udiQkSPaDbEuot0AXygM4xioi/FvwzWX7OEI2xiaXzqN6sBbKYFm2mi0+qkzQnoa0jsb5mFy0/g7U0qm1T4dfkir/3P'
                . 'ixSpEHMkuLNbVbfkLtmowgeCQ2i4rJcVSU2tNohlWUp0UIRaJKD2K/Y+UwAWjhWToqJI1vWo2PBlhRNsEzh1ZWaWyZiFQFz0'
                . 'gwdR4P8HFm6NfteQDt0o7aB7FTOB1xIg0tsQ18TyyoVxMea9sWEXd3UpXF00Ri11FDEPH1F03Ttyl1+KPMmI3z+NAaTIL+WY'
                . 'CP3n9G1VowyBNgyarelymn+8fU/phqxKcyYCZ96gwO1NKXgo7twwLmAvneHt/cldud+dHUbM5jY7ro6YG+nazs29Li1cNmiy'
                . 'hpvg2AFM87UqjiETO+XN3UM77+nkGYNKm5vYBxkCMOrZU60ecMiRwOmfrzbX74tB+s6g79teLOF7KJ51yD3WstM+sPx/6NvI'
                . 'q4oXO2ZsRmxPHPrnmHLBK7xKSVncwF3pYT7857YSc0JMeEDmHc7nlQLT7gPzHxjO1H9SLip592C8SoOYEe282V4bNgHgsbKM'
                . 'C+DRCtK4SC6FnE9WI8j4btV0bI3vrTUxbmpTYGqpq9RePa4/v3isyfVpuMsv6cZRBMVT8dZoSxvFJfppxIPpgTKoLmMsYCYv'
                . 'dreKFuZ+5d1zQXSo2rqu8enOB6fWzGxZasELcgd2YsLmFeASnc7KJ2KJQhXhRCb4FWwifOI6BqwoeKFgVvG9dvAoAIkcwcHh'
                . 'TmwNyHygwGZ/uPSsL62cYcvi67XdBduLslI70/JzuukLCjPop943jc69lBuwckCGKJy2rLgwE/4QhW21cTHpq0gLGdr63vEj'
                . 'g8FmtdhVWpsTMSJidsHzmY7GEy8ttQMEgBLzRvell00d27eMkFcA4Nlp+TsA4PmtrdW/1saX8+5pPQAaBgCAALnm/zPA67ut'
                . 'ZTFvNzMFHgw6qpsGJsZVb23x46RTBlQuvnWI/fTNFvGlcm+pusWeiABsaR5was44ttvtVjy1mr0op+/UOHh/1/Ujn2+ThzxU'
                . 's4FWTL5VAtjZnr/F1AhXvckApl0bTduuaizGVtM26esqRCu0BfpBCuiQQwmwFIvSL/69PCv+RNhlgOOffDO71JrD1Twynb2a'
                . '5z5BVnEtVGF0OYG3nBLKkCtIlGwnRFKxlaZ8vV8aYT85jwSA/c51h2gZ5Hubx1yiHZZ+uElvtqIXb+zpROnGkxSfnIPBVAXV'
                . 'umvKAdLs2770F/HVC61CGyAPHFs7bI3jtVerNVQJofy04H+HvU++85gQ1WgZqtqoVsbdzK84LTSCGka34iA8BoKpMu7dSHXV'
                . '5TDhk3Wxp5A5Znq9pk3sWTZNPsRmh8h8z5P9b+H/uuh7rtw8syu1zQcseZkjx0lyWFD+15rKrk1jQnOeP+6FW+JE7MZxuDWO'
                . 'xx5Hc8B9cQb2xpm4HW6FA3Dkwz4rPszJAs4PJv/SJAu0/uFKIZcvDH5HcUbCF2iG/L9TDKFVFa6i7poL7uG7oao9ReYUKgPQ'
                . 'rwDerOg84NcHp7TwROvU0Kq2eb9KskVdExRIHGV3VITfF1n57k0xsFjvqqhgvIxyUbgBc+NAlw0GvTiDOTzMMFAM/IkcTgpM'
                . 'echpAbj3I83xtg5SMdJTLMgCdlhHJdYromqOiOwXhwlXMH3FnBjNAlygS9qm3cBirqiFFNNrPyk027WuqMAcmhXPVnlO7GGt'
                . 'ckuAn4BedvXP0TwVjmPYxiJasfDbgrLWFq3SvBy+l2lCIwA7DsTNcQvs42hd8tKJMeH2BN1yJ8t79KKyaKpLzcSvODwzQIkw'
                . 'N3cEuXvEK3LxYJCBmuQDgVpCvWh5KZYgwTm7BGNyawkRm9F8iraEx5ChJbTAhNYxIiK7CQKQhA5LEIj8l4c5nYjUA1jyMkto'
                . 'vlyiAL27RAPZsMSAtGCJhS6yJECOZ0kIZXG+07EkApQneJkOgGvMpDmiM7lW/hLz7AplAx1ADnhfEww8gjR3zZnnTYWNU4Ht'
                . 'q3IyuAwHQtEn57nzcVKIj6iLwineOBkLDy9bDqvlNN3aPbUwy3pDEwk5yCRABVvEYc1hxwqSAd1bLrZQ7uvqYCRZGEezqa3x'
                . 'i/P18axwaKNH4jd2b7KrOeH276RRHYxGVV4v+bSz8MjC+QrmnRFp6EfuwlJNy1tbI21Xty0Lbzh1/NaMvLLgS1tqFlFxdrty'
                . 'sqBqK31gcW6/roEQv2N0FECFCKIQD5Zy8fKad7IgzXkNLvLhxz/ZcMllV1wVkBwIFJRcCE4eEiMJeEJEDJJBupCQIkeJ6prr'
                . 'Mty0zF6VQr0XJkIzkSl0y22Z7vwRjmvrL5spEi7tsNJo0T8vk9MF5wy12zB7uKXyFc4J7TzXRnztB//FOngTh446SXQ3zkTG'
                . 'Gm9CxMbpzAVpnqSLSRZo7bOVukYVnNpE3bio/UaTTJFnslJTdfeBx9v/pF56RyffdDNM00df/fT3Tp0BXNfeyCZWFnp8jmwj'
                . 'DFciXu4SPXqUBFV+4vk5BgDlJdEJsiUGRyA5tf2+GSwOTyCSarSR0hzvuovPd+P/L2h0BpPF5sTn8vgCoUgskcrkCqVKrdHq'
                . '9AajyWyx2uwOp8vt8fL28fXzVzntsG80U+vchUtXrt24dRexyAOIhVgyZhY7UTazK3PKPFI0hbbZ2FLyjKlWI4aYxECDtRHl'
                . 'tDNq7bPfAdvtcNQxPCIOQgXmWmiBRWZleSsM8iorKzLbEfwUm6M4q1rdmtZifPTJQRwbqxWGKBdNkHWtb0Mb21RJpZW1uXKF'
                . '2lnsnsfue+BJtlTRI7u1xhk1HRsDQ1/asD4fWjueEtLveTzWoYgW8Yj5BykdhD5mnliz8tEExx/SL+ih1dMASZx9/LMc6RTS'
                . 'mVc6+a7VEc6zqc/hly9w4emZFmSLIBC7S4rY8RDV9vq2MBQA9V0O787bR2GKi+S7Kj9iRp+frvjxIYVmvzw9ac871a9Wmphd'
                . '/zgcq53bj9uh2KpdD8m5QrY/Fh5X9AMkRebEVZqDUeWqkRW31ydsyHdOtUZCOvIJLWfKrMkgG6VpMsgooxKbDHJhpYpQoRSo'
                . 'KGzVIa3Wv8b2/LbMvNKJPrzA9RBRw4tmjUNtKZGUlEob6ET7DkowN4mgJwxOmYNTyeBYf818g2PdWKTuOx2c4o+4YdL3/tBR'
                . 'HU3oLfQT0ToS7cRumJq7uV/Vd5ajVb9S5w++pPODvxpVSlSZUUYHyimnjA50oJRAxzGjXbufh7ZRoqjhZAZSVkgw8WgiNRTo'
                . '8SGaQdDbhIdOjcCABDMAAHkGZIAATDSD+LdBPABgQIIZAIA8AzKAEEyFrKQ1aVYa7GJFZUbbLMd+y3bS7tm9z6fJ7Ly/3t+v'
                . 'plEn7e6x15uxeYRsg2KSpbjQ1DjPtqrLhairq0aoExEKOhEuv6lK5bHjyiliCxF2vU7C1k+5vtfEK6/9224VU13y959iy++R'
                . 'dpAae88QU+47Y+TWyC1n/NURE8SN9DDzvRkgyV4Id+zlLfb3f+tFG++93bO8/1r8+3VfXQwAAAA=',
            'family' => 'Cormorant Garamond',
            'style' => 'normal',
            'weight' => '600',
            'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
        ],
        'cormorant-garamond-vietnamese-700.woff2' => [
            'b64' => 'd09GMgABAAAAACvUABQAAAAAvXgAACtjAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGoEdG5oWHIEGP0hWQVKBNAZgP1NUQVSB'
                . 'ACciAIJsL1YRCAqBvSiBlEwLgW4AMP9EATYCJAODSgQgBYdEB4l1DAcbzKYF7NgjbgdCEdGtm42wYONAIOyNRkXNopxMov+P'
                . 'yY0hgu2gVtUHpFC2s7pLjUYiIUpM52LBwj7kaUHC2d7XDhtC0UqYK1JQUo7Z5kU25pUgVit4hn9v4ZGihIZPeVCUJjsqTiia'
                . '/5yb31mRpwfy0frihosAd3YUHXL+PL/NP/feRz9piRJQsBGQoT1UDDBym67S6unWrtvFd+5XuP2qVYTrlPiHcd/PuU+Im7cF'
                . 'x63gl6KVoK7Q5bipf6cF1Ofkt3v3kqYgPPOdQjiEZHAI77B00q3/2HUICQQLETTga/Z0/0l3RXXTX9N/l6Y572prxSwAhurS'
                . 'mcQzgfauFBaHmK9CO613vosIPe7Fah3w8Hi2+1P7QhKSAIFhwk+bQ1H06/2zqbp3XXFdHUKY5LH949rPd4iGdhHdvYlBmqKW'
                . 'v4jlxuC8nAdf4DCd30OInqhOfxjGtt9923w+tdbz0lDw4A0IIBAwAghqW+yAPz4pmW1ZimPwivOv01z/tw7solPU3RXc1+cC'
                . 'OoXd5sIEOAO/Xda3cvqSnSgpSfW1VexjF+zAe05RZ+vLDlklBDkpJGldABg2QN52d+vYYewwdpnbdanf/36tvnl313FfHRJL'
                . 'T19sELUBb6Q95ErJmvD/f5mzsxNK1+CQSISsVmxLqbTaJsN7786Gn3N+hk8kB4UuTW8cpapeZISiFonwzSOfXNziEFIiV0Yi'
                . 'JPDwf/50W91deifUAPfM+n6oP25Zmv3BwsJI54Y4ULdn+sh1pcqQNei0nKuFHOQ6iJBZOt9jElRtmUG8io7NAif2+UQWf7Mf'
                . 'V866k19RIKmROrAGnvoxtnpw8SLn6ode9PDARmzc6goIIRY+IEEAvDAC8YOYlEGqNSEQDEADgIZh1RoANGnChCCNEAAPBIDW'
                . 'CwACgh+sW/qfVMY6H2KCSJ5FW0xTs3kD+JSLMJIZA9YcGGKLdEYlAoKAiwsgAFwulaipz6wiMO9rra8E86H6URWgOj6ssRrM'
                . 'KNDAptJm0tQ6HPuur4aAiqigOVFRDANSABiFgYti5O2CADC7X5/m9Y4NTMLHJIRwIah1nIFQqxYaU5p4dkF0+IjxtQ4uAIDQ'
                . 'K4D4yecAbxViR5kA3iJEQ114zwOAGOOogs6fJ7z3rv+MY1uyyQ4A3em+uh2di/eMUJDMPRscAAibEQBHbcVfX4Ka56GNbO6d'
                . '/7oX75fZMLNn0pRO8eSMe2LHOubRjHjY5FNf9F6v9nSP9q+EYfVv29HFndkJrW8pOg5u0fxXN7yodTSg0nLxu9zL1fkvp3Mw'
                . 'v+X7dGZ1FqYlpSlOTtyJjRXV9ZGFRh98ZrdnYfrtYXf61166RL6U7nXO/8sJVjrcYnN0G6tVvTJp+OCZbpedtB9xo0yufjM6'
                . 'frPW5SpQupY2p/O7VZOLXfzfz2q5h50CCcEVcDMTAPMVQGK1p6y/D0NC4wzruxTi9H6CgJ9I23oGvL1QNVvVRLvJ5TtES/3L'
                . 'quiPOhpitCcbeLe9/VQZdTZNa7/kbhzOO0BP1eT1ZTcd2T1srVy0WN0/ut35UFCb+uJ08bdlWu23PXl3W7w17GRduJ2sxN+F'
                . 'eUNHr1GZPNCNUjXZTJsEJv60TOfkuWqeFc/O5YM6ljUt6lxYOczjtJ0vffHWEnLFKX31umrgVs7z8rQdwI+7QMkfqCgVCLuE'
                . '9gH82ZeJ9kDnyXL1qmR4NcIGc23rf0BpQdahOpyfvGQYweUItU08oEX1na+B92+cq237bESHwM1sAALFO4117vOdZwi53ZfV'
                . 'PrjfI5kYyWc5grRPCz1rNm/zpaJWjTcd3p03sbB2xJe3di0tR+s7VjiWujEDvLk8COo0msZiY5OWd0WkpjmsN3xkyutOYM28'
                . 'ams+3E5587LI4cL61AAq4ivKlNRJ132vfex11/c7wmc3Lgdp4Us6hM+ZxDvrZFz7EX+d1fsmc5PvzgZaycoZviiPRO/9s3xV'
                . '31qA89Q1QE+Mtx1F+/t8Aha8ziVcFyGN2/sb9t7IDgEHeu6uk5Z29eGarJ4jGBqTHbKIgEETUpcOEylwtJUjX6cW1lcHAugL'
                . 'IA+AF4AbgAOADpGethkCrN+2lrOAwm6RH2ybsA3pmEsbP3z8WqJa62lVp0DmhrfU03LF2nF9hGFiYYinvvIBJ2571dshyHl2'
                . 'n/S81/rk22qvw5XOU3HUu2kmPj6zzXpLO78hSjEDL252x+qetGpW0y1WUzKL4Q11z1+e+aTJ+21UTmrk3hO392xzGGhAw4D6'
                . 'nlw5KyI1l8LKEVNJooBIhSTmsVneqrf74Ims9BpAmr4CEE/kzS3syVMWp6D0GFBzy+/tsm+tdNtcyfIKmCeZEIKdhs79CPlY'
                . 'bXk+djt0O/WKlt7OoAKyQ0jYidJ2hjIFigUL886HKKHdVyQ5ItHl3R6NVW/H1YU2O5h8FfnaqUeaIn7guRT3gwAwjSd81kS7'
                . 'JMY+9mpiCm4AZjoIFpAogZeI1yTMV103jzyvFBpfOGLPC0wCGdP+78q00KHsnrfFgDAp8gFQ6oaUz0L/hMP1PAJ6tuYVPY7/'
                . 'coBsS2i34T3vmmJGhd1Jt5a27u5AWyGOxj+Rk5cT+AXKd/eeG7Tjj4R7/s+9GELnKuJzkOzEDzWplLw8RO25aVg4Mv4JkC52'
                . 'DDl6tsp382rhSrkCuoFBTgZjW/CLV0VIoxkTJ9HzfMVfRtc4QL4iJkPvOdhwZUIAiK4AAPENpaQUFIOiKAzZrClG0uADN7AQ'
                . 'kWGnYNpkNMIsGFcIJ4YoacwWpzfE1cCW3JktRTqUk0LxsoGS1UDJH56jQBFaH0Mwc1V4hrEx03CjIWOSbKWvnJbD+JDIy4ai'
                . 'FMwkIsqDYovYWiR2IsuCYopcdZGlSzEoToLxpmj5RMUIFSQGvR0aTkoBzX3l6HEB4hY0hOir4+O/TVUgPV4zohL4AFCbcaow'
                . 'm54MEYWodyVHMUAt8nDWHlJh3URNk6Jpng9g+VATmJDlQ9sJ+LAW5XI5Di7yYYxnZyiKj6nNSPi/6Rwn6Pji/xABbr/FjFq5'
                . '+e3kvVOlMQwGwPdSDEAmXjFaxa5bWLce5ALA2J8b8LRgK/3kd411/1OAmlf9AvQMAEDXUrfONAI1ZlQTyBwuAD79U9qIeADv'
                . 'wvCqIhoQmkIQB5csCACgoDAACAAcghYD+D0Q27eFXtAlO7hY7EnWTSA4PgAJNJvM/MzWoUGI3npptsBysAJcSZBkpAiEbVR0'
                . 'Xy4dIYJPr0OCRAZb5ka7ighFOMT/4+1L30sQz8NIaJClOvWnAmkxW6iOM3r3DgB3+vHUzgE+LoArX7KrraKho2cUAADAuPzv'
                . 'AxCiD0oUgVgmeeSS+fGq1qCXoZyGsRrAbpBIgzkMYTNQqL7CFbMoEaG/MP1wjcFTilaGYTiWkdhG4RiNaQS+GD6i+XKRSSIU'
                . 'RyyBRCKp3kTiKaRQSaWWRiOdkptWBh0PvUxG2fzlCJDLIEugAmb5ghQKVgQBEMIQKQbukQDCWEB7rzMgKADcygsRAHS/oYkY'
                . '8G+mjVgAx+e0LuKktKF+f+5eEghis6/1AKAzgH4bML8GO747AAAXaTwt1AVFKFyI1XZFnHcZ9lphfxSMJwSqMUE5ceOxPldY'
                . 'bVEAOBGG3EhNmrkTEoLCokJCcKJr92fogRDaYixcmOwGIDogA5wHxguz4g1oRMMdExq6G4DeARngPFBeKMY9RpIAfSiq8AM4'
                . 'iFevxFzBdeM1V1af4J5HAERQePD+zOrPTCMBwG4yu8UFyMUhYEgIeWCPRGhRXACwTRQUIxgbj0C0c8BVYDUPyAvDuMdaSACE'
                . 'QuVBBEQW08B4jSIYAxE9tuTn4Fw7F+c8Z5ZIphESAO0mw5YKcBSHDkNCyAOVRdrP7Jv6Me+Aq8BqHkhUZnOuBVpLG0KgMX5U'
                . 'AZA5PDrsOQYoPAQbyHaFiIMYsCzGOxTFbLkoQAMmTiKwSyhYWeFWBaYikuw9+MoTXTUnygbe7RYpHExhOAHsuyb/R8AmOkbM'
                . 'ta7G9PUX/K9a7PnqRvV9lt2mjkn84Rfx8Pf9Vo0y5s7JwQlftVBWjfr4bs1mU5IGZrh3I/XMWCVVw9FUkZFJ8j8p/rjv3F8D'
                . 'g0l132v+58gHxtk+H+DF83V7ABL/w6v2B9xodNTgrGgKOFF6wL6EWnsqg1rVi/Err+ZmooBKL8CTMYQgpsOQUCBWArEOiJJF'
                . '0CjDKbKBZ2VVMC6uXJdegWyS3Edax9TV67muOrPeOss9d0zVNIfGuEZ3Xqo3evKra3RI7EhK2ieNWv+c/n5OZyWjqxrVjZqS'
                . '5BlAUp+KTpWSrrd/qd7esNSB8cMrsqG6QdchcSNJtlZ0oJ/+HqfzfoCphGDhP1d+OkoI/BZFTWXJszZo1EB51wxC0RtQOUPy'
                . 'c0ISYEs9dj3xlOIFDpvbNLVO2dNNuDLk66ZWGV+wi0gaCklpkBL2z03T0oA9fWSRYTtOVVW2/sjMqNUzZdU4HTh9+UjeLw8R'
                . 'LAL5pwX8lJ9Xl8UOZtB3M2rSs1DlS0M+FSY/IWPF6PWW9W8wAoJ4vUiaCJW6/yA+TANwhYRAkBfF5j+RkWDNOcMzPsY9A1tk'
                . 'pwcjJKHgiMH8gj+FpBteaA6LtmSkXvzm5Qnt9Zhlju0GRnawSZa2KU3Z60N0ruSuXAcbVsQcMTRabpqEP1MlHJ4o96h1dTTb'
                . 'zystbVI1pmoW0/nVTas3LmDk8H2Ml9/uhQGj6Zu/OvtCWgK0oHEEeEAKR4g3gZehA9UIGGAXibNBZ6kmOkw+5w/jqJQ2i7ri'
                . 'BI7sXn5FozL3784N77OTqtmTtgP7mTcXvGkhYiumDpe6sfKCNhhK6nPk1y3NCEFrRivYktEQftolaXUgNgLAZVqiL5I2j+DG'
                . 'KjDV1OqGhPZASHKG+jGD3NyHL/92osUuytDIv1bgjA225fVpALVGNI5GasJzOv4uXxCFusYIuc8ZSI4ua2wofxYdXssHZZ9q'
                . 'Dpo0MZjEoudVLCq12QDFsuZqocqYD23dUkEN39Ino3ZyPUg2/axBoqvr9pCBQb/AYMgzrc0YXv7NBEnF/6lCuWsEgEdyVTaN'
                . '7FGCNFxIJCWYkRQqlZyHW5NPi/P6g1WAQsJS66A2j4dPWGPyqkGx8Bx5bDZMxwMCsLRZNdlXHnEWrzTvsTZ8mOHwwTqAwQzP'
                . 'DRdMkqsJhkv1XUXcvbERA57LVZXZVjUk49PTmE6CGorq43acdGkMEn34uMCwX52Vx+k4pd/M6EklWu8trrIMiQLEnSUSsBnW'
                . '3QgSvdGvJXVbHtIUgAX/yAJWJLaDQT6T2zYkNKmtQ8KS2z49ZKgMr0jbqIR+JBK37UzZVouAjFDLEouahOnwZeTwDngtBgHw'
                . 'WSaAYGJKJgn91JyREOzUAIARM0CWGgn12HiaWv5WuZfcBoPbhikKCMqkTIZVHKP78tLct2zvs4mJZJNYnj4xY/nZcO5V18Ja'
                . 'E/7YoRM3s0MM3r7b4c68oo9a99HlL5PN8DS+MNzKSE2LflGUtsDH9HIIaEqjjtUD/wVwXG9Kwv+z/2MP37q47dIG+4ZL0022'
                . 'gX/2TZeuZm+4snmjfCn10pZLm7KvXdpo//Dy4yZ71obLnZtWpIs3HmyssFdku52StE3yparc4wrWGcVpqZa7FhhGuS36/iIg'
                . '9Zv/eT/rtIRLWBBNC9mES7RbeSRPdPmCiZSHuZgtZDBpwvBtX/M1XrKPfKHT3C2EaJ6etToPHSwXXD88/GMaRVOaTWq/HWqc'
                . 'V/Sh4Gu39FuPzF/iZtAMncTsv0lXfWbUDf69j75K4OA7TzOckSNPv3mtvUS08ptUY+HYs0mLb+TL3rbPyLF7kyafBB5OFArC'
                . 'JGw2Edk0+Qx//WYFprG57JD2iPZgfzOmsaJTr8+gVBWf5RbP6ZvZq2Vw0fjwHLpoSrKnbXCxe/7Ewn4J0RHbQ2fBvvXnQUpu'
                . '63wOB7UQHnH0D0s1eQ5qd2kOeEwDg2JSS0kXiaEy/ATp98ILre2S9ZbE4f+QaAVimUqJ6Wu7Rw9w8x2jBuj7CisSKhMg9zed'
                . 'LU08erJcdF3OHm2t+VXXu2zolKSIxSkScaTYzEkpV6zWqb9aP9bpX2uszW8CryP5wr6Rfa86Yrcb8+7HCYXVNP8L4Vj9WzaQ'
                . 'lwuE0TNfxou61HYI7l4odbWKRos6ZSL5/LUqqX6NcKR43DfPReKKv2OoBLVjpGIjdMCRG+N4QulOEetEknCW9e0bGD2W+i7+'
                . 'mKvG1cCkaPH/D7T/lhXhVkv8DRsVhCaV2b/cTGgyy+DfKbfDG2Apz5AgNs1VXummGJCr7ZgjP3JVvIxGe0FJWGNyfmLrmLzx'
                . 'gQneRkd+SUhdfFrU2KElk+z+aXWu5EkDhmQsnlZQ0jh10YS0oHFZA8siU75aCzDYVuw2yuK5hbUPM5pV62Bsfm3D1fBrGR8D'
                . 'EIqrrS2JQbClHC4v3OMXxGtfxxdN9ZGohH5DloGVcPrPsMbqZXmWGYY+5X+4koVjTPD2m3O+VQNcwlk501xqSha49panGWbm'
                . 'W5jW2Kv9oUZRFx7ZEc0+Y/UF9OHk+v2RnL4PcSzr/DBCE9l1jfy6BNM4qNKqy42pKo1J81bFxpVn9GZIi5YajJ2ytfHJB/yr'
                . 'VTVXTRwxcGTDqEsXJSiW+fZL0Yh81vQixFWVx3g8ZTEx5Z7TAK014wYOLBvuZ0hJJny5ij9hLlfU5iNRi2T15cVtQ31FbGJL'
                . 'dqtBty/fllpwY8e1SjXidzg/KJBFunh7Zru2MLGPG2RYe//vd2GYxqpnZuV1meZVvvgqrPPVzSH+Sj+TXKtouEh9dYkI0zg4'
                . '/+XmnsQfaf8tMROayJcYDZvlHMOSceOrAzR2ep8nHdCuATFPOrS4Vm94Onr36KaSYefFR8Qvoewvgt1TSAea4PHTeSagDjLF'
                . 'gTXw2iLxKLwRT8gYmTFhlTyJmVXcsGGwLdcu+eeSyEqj4/yKhRbhODS0g6iJ9y6ho9j07bnivHUTH8eHNyOHMgJkMktiofkS'
                . 'poUve64hcP+D8tB40jI4auuLXzf5fj8rHQpOjwgpiokOKUhx5qpN/ntXLprhcvZy5fQvjPYvvGS2pEc+b05vTAv44fzwAGC4'
                . 'O99e9CaNttoon6wwZsBRKacf05ZpbY0TEpwCMZjGwXNC/w04KuGMfm312sbH6BFOISxdxH+hZSGEJqJjKuWxROKipf73tch0'
                . '16grzFQEZHwjJ3h5ZMVKqXUNjMRz7WssGgO36Li+MZah0Q5T5lWPPCC+T1z4IIcrZsBg27boFG+/hOAh3ih7TO8cb1zQKE98'
                . '0eVaHIJTEtAK+o+Sz592hagTp2xxb8GdKOUN5UiNy1TdEEJxIlNrw2bBYjvuXr54orwqOdZVdj7fJLHiW3P2P2CE1/L4VtyJ'
                . 'oh/jV68KJGhWi9JT4O61fLKZ8N1MEvVY1IlPxTtlcihcw77Jt8f1c5h1QsLa4C0igceWaS21CDEuJM8vVocE51vveX1KHBvn'
                . 'qo18KW+VOSnWVhMpxKyMwD3B23/ux3nIWuCiFo7n3FlMx5hm1pQbtWt9WVfrKB7lZuT6K9hV1TzpYh+xSiDdNN5TwKR0tKjo'
                . 'p30nEdYpQ7RaCzJXGXp0At3KOkufm9nKul+oMbRFvLct841z8Ed9Tw5Yl63unpDlPySpOi08XLJNQtNcqTdUqLzkqPIfLGuP'
                . 'GzB3j3xBgqdGjKSYxn65v83TOUYa7n840e9no9F3yfC4aGuAtKRFIdbQuW4dFFcNOlwrZ5H9H4Noho2iY4K2JdZolUyUqGyX'
                . 'Cq5xRfaqzcrgIsu/AcBIEpNik+Q2U6laJPhh0ASLpiksvaCRJ9uh4TVzks1qr2XRoIgmqgNbEoPMORbcQXkivXa7wwu9m87r'
                . 'R1e8Ijtpyx8di3VasEO8Y60+kdnlDmMYXucuYg4deAt6c/Y5xN1ruZVlCJWJq3CF1aAr0sPn7ZpU8tt5ZRHTsNIZ316vf4RO'
                . '8+JynIH9bAlRQwdHR8dMdPhksiKy2SHRCUXeaP/iVLvdf2eWqFQcnqb8lsu7QQS7bqSuMKaV86fTTQakKcBERkqCMPnph4Ln'
                . 'pxrQw8KTi6fkSgq5MjiutatBr/+x2wOOioWfbJnWCfE2N7UoABju17Kk2KTCBqJUEBZRKMmBOH0tNzDKlpHpb9J2z+S4mlxR'
                . 'vYyVUbEx7lD/4Q3NrBPL05oG+7pJIJSY83+/lhtsc5mfhKT0y2k9/kq3/9BLftuWaZ0Yb+hDOM5RqUnepPgoc1JEYFFMdGBB'
                . 'kjNbbdKdnbmoZI9Ln1WUPsqhTgA3r+Zr+xxxofjjCgPNZth3eth1xCvnpggDjrb9s2+5ScLCyPu+0FBQnue9POX5C47Za/PY'
                . '96YljzGmlu1L/rXYGR4S0fl/IQRQQTMAQE0AAAjWx3p2x2yb15OdP8smY7q7j8Y8Q2bZ7JjXy7ma15p1cL3cgXvAXrRxPcGS'
                . 'snJZ7axz7AB2NftPjoRTxfmD80rbcKdyj/BoXjFvI+8h7abX07d8gn3qfHbxVfx8/mr+HYFJUCb4UnBDKBcWCdcJb4lEomzR'
                . 'ZNFvoufiEHGLeJdELKmVbJcqpc3SQ76xvl/JKJlL1iTbJveRD5fvVAQoZiqeK0cot6sUqrGqa+osdbv6jSZJM0WzT6vV1mu/'
                . '0T7VxZy8/HW6v3SndFd0d/sCu1zfJdFJ9Jpf5QIdwHZhihNcQUAPAOARCRgQUi8/ICO/gQXXHGjUCgySqVHnhHN94BzVKOFa'
                . 'Wd/uxPhp/8Q3cbud/CDIURRdPKIL3yeukaO/Tun0NWtaWA5pEZzsK2ZClmIzU5kGyvJa78V0+uIbRumLv2znsYry+i/rLCDa'
                . 'gPYz62dDuxxrwJ5Z2dc7VS7AM7rpTVVuXsnJdFk4vQpL90JyGU8EkXBRKxxT48M+fJzknJOzvgD3uSyEgDi1eySIlxvoDb2e'
                . 'yYACPLbu6DK7zXDw5fPNpetS4h6ze/9Nf18YrR51Sgcb+FvPI/vX+6Bwpw2r/1vPsmjMqfrvtD6b5Z4p/uev9f5znAF6MMa6'
                . '2yb+B6+jIaYf7zWWQB+szGq3s3aVhkR3kmsvqJCcW2Xl0haOY9GSSrEcqV8I4oDcqrOcTdF9HARHOAn/xavR+lIQx7Ku26bK'
                . '4jDiN/Aj/iMVyogPQRfdZeozJkIPPJnTdRpTdWINfzXjTzzJ9x/rLW3n71eSGwajlkrUuprEiSzb6WwyyVOZiIgf7GcaqSvX'
                . 'UJMtN5u2HBJeSwEnNrZNCfEsu9KgLBw47KKrU6MIQXFWRfxwgFkqihjhnOlUrCTUlLvUEZkV4rl8vy0+lEkbnMMOnlRMUfxs'
                . 'ItHaFfHlfWInIdJ9UFHD4OAi/guVosKV/UmxLoLSFFJM+1YOd6p8C+gb2MmfkMxrtCSxUkYEFPuHaSD5OcCPS3Yjqi7RTGsK'
                . 'KaFqNO2ItA88z99vYpZtlKDgdc8UZcizhMGDYEXwThwMgQjJ0wb1xzYYm27KxMcWHh5d19rBlv/f+UhpKBtj+udq1xVm3Cnb'
                . 'aFF+hWldRhQ6MS3PX41q6/j/cz2SWN/MorGUfUILHoUFEoaTCz7UizS6imvLM1VH9+/lfiVL+fYCEMKaaQ4SErNZNHJzKR3d'
                . 'v8VyDAzOVl60Yff0P65nDG+ebWWTQQ/oQeLAuk7pWcjs8fULULCH2TvXKqQ+yLhnE/ry+C1l97pDa5fWPv2CHIAS9EDEdnxb'
                . '//TL969nFHLRA6dMkPYUQqMc+I5HrQtAJmvIle6aza4iJbZR6m/CyjiPLjSzB79PMaISDpmWqDGhSkjSFfx9WBvoeWE8eKGz'
                . 'FAv+BtCLeMj+B5YV20RV794w2dDTbI2bb2pZGyHEQboBHTJPJmRc+Y1bj4Z90HQqrB2dOn937R44KZTzRc5ZqFfwD4aj/60t'
                . '7O2jx4uB38sbG6KYSxJ4X4/JOApkOcn10YCsDGxDBje7rgYBcGxuzxe5sSPPq4MPoVixRO8j26TQzvP2hRizunfSTTbx323l'
                . 'fLR0qrmLWFFtmD9lQMuR5pwnsq0lNPm7Gp37uAOHUS9lDm8GCdIMzD6WPPO0sEo+/v8sXYdBW0DaPmCzMnXvvWZVpc+JvOS6'
                . '8rkG2U48YkPmhI7YZan4ixEU6Tst/jbhlh5i4uGt/xl7XR1pTwsaKdMWsErBq5Yc70/2vZ+jrbOkgsmWcbJGRD3GD/U2TqRg'
                . 'Ez5eumTBezwgOEMMNOhSbOww0SsaZWMxa7CC0RyzV9uXrflYdrf8OSypBv/bmWz8+dJrzi9LdXGoQMPggxXza9Pns9tsrDwO'
                . 'i5evupTVQJSCjm/6PCibKU+yRcupXuq8CXRbW3vqKqfYcWCub9luP/F2hkZcm8LJiH9nSyT2aKVBDpWr4OhjiM2yvz4NCdYQ'
                . 'iWp86ZGpWGcHr/Mi0Gu9bVbJfcde1iZqPaGUdIQzTKkR4DQUbTvGJ2RQtoY7cn8xViulQ9FRlRWYzLmPwp9MrZwnHw7dDuc0'
                . '2737SVGEA2OMxDANUimJD68Wi4F/LP7FMyX3XfFy+a2wd48pfaU/6+4ts181dzC9nhse4xs9Ei/y/WFKdsV/v68/a73pS27g'
                . 'Fd2zSh0gJHXb9GVg8LLeGaOOEhocqy2w7kT6t23IM7TNQzBH2jA2x9p83zRqozTQuez/Dx7876Xmv0UHr3xC5AQvM+1TuGEO'
                . 'sDDEY+9/lSQ1QvQXNzkcupUnzAAMrzrffQwnPceJK3ycI3ruv3ertp1tNrvtet7WwY1DKj4bSx7PHJ3qpSDNHC+33LlQ36OM'
                . '+pMosbUJaLSg3m3anOBTIyXTZtXtfL3djftMmzYy/XOsQkuA5XpcWMdgN9kfLVfL+WIOr3bRVVmuFxN7wcGG/ZuHCL0vXVfY'
                . 'lzMX5lzOPROWJWE7M9hYW05KxbaLB/1O4K9RnX01/VDXq0yAnA+DKUrUrsVXrhlgVoiX80hgfBqdTlpnq6QpIjyvYuBukQKp'
                . 'fibjVO7UMeahwijeqa7FjLrIl+/Df4Z2axzKJbUHc4hC96IHkpXf4AbzEM0DYkxeOT7/5JmICvVJkcuIU37jvTQwOGJuB2YK'
                . 'o25F2EnVcm23EbWk5Fn7wpp6r4wMo/Qz6rJi05pmiMeNlV/Fp9cnCRV00/1kfbAZJqyiSy6kUxwz2w8McDNY0Abvq//CKBP7'
                . 'uwWyk3WYWQ0+xHo2n/ZftqwkMbXJeu/cSdUxCO6j8+nN2/ezlQe16XqStkryA9m6M2UaqYh+2LLxiNQPIuCU+K1RLRQ9cB0M'
                . 'ajVX8ZrZOu1aGwj7wboS3ArmFErvPVHew8H63Ivv+h4vpuGaLVZ7u9Xhpg7W4MADT0aRIDa0GKuF88fNG3pDh8LCvRjd9JB7'
                . 'xamVMSmVhDc8WOk1oE9u2RQJycBJlThyd3beJ8v7iblihOhVENPCOUcUQIdWS8RcNbgIiryG0UpqNx7kczScYgDv3CJXEGgO'
                . 'ISD0TVLxoBfmJz+d6PF3sHgxMI1WHRFHOoSYYOReUaYLp7q/1fdbHuWILcYMarOU8xFNzkoD8JLqwoViPLx1zThfzDZSkhGQ'
                . 'p8CTxNikyyTwgKP7h9MUcIRkm1XeC1dhjsg5hzqGHYcEQRQKv2/Tho8HdWCWJoaO1YKzXmeqZFkDiHkL+Pj484GF3+f/yWb2'
                . 'fil8bdpJCSlqnXQdl/AoSeOYesQVcFbljnkrBzyaHBMSmjUtOo4aSuIFIpRDznHbsbH1CnZ20U/Fdq2JvYh5pYrOwUkF5kTY'
                . 'MhVzyv+vJKRoJbIahXIi362zD7WpuQ1avBtMUby0UVbvd1Ghtg4QFzuKBl1zk80i0Y/a4csYaIopgkkZGwXIdDfkqeHqwSx5'
                . 'W3/YseOelgMUca5ggdL8MDDKB5PFYAeniWQbHFnRMw5cuI78jkO9JdjKv3EeY6V0Imhf1CsUkf6etgxLZiezu0267lYwIGYJ'
                . 'l4b3uD95r7iY1Q4nf3CcVi+srY+sBqFuCXsHsPg4VRZoKdTTgiRxY9orayLjJXj2noO9ACMMsNuWKDpPFOdBnZGHWVaOu/La'
                . 'aHSwTX2qugyZa3QN/64VHwyptIMIThtEadm4m+u4c+115/JpHVzCOzmTx84eIXO+ttf5YPdbF46c4HCE3D521OZGYKZuAcv3'
                . 'PUIoc1FgfcGoY9RmLHiWT5xOtRS6HsuVK2/Y3nxEmmMGlzL2W64dQ/qMJ+UZ27YqDdCa1WRz2f54htL1RwxpMNhV7ZTLdnw9'
                . 'RYLBHJxDTK1Ga60pTVbG8/gI7v1MvcSIXifx0D/OH7vYggB23DcR7/I6pe2TSn9ZrBrXYz7/j/sO+3o7HHoREfFjXre7DT1z'
                . 'oIVTBcfQa7YWlByfuFEiFfGjXrd07EiCVL0YdixvOh8e0c1pz7Z9wrSkCfNZQiWUk64ELSWh7kjBamE4p7GA/9moTomzY1k1'
                . 'mEvJMlu9gds+g53zca+mj3pJtOMW0/cIhankUMp2dLMze/VJqfJeaVIc+VSYId//SOcHXy6+SC5pylBeAD/j3y+5rN1/XQgq'
                . '/htTRBaBSOeE9ZgH4CaQXPi4FFggTA3DEAjHTPsUUkL1EZw6Ar1I962CHTnHofgJs4K5z2vidUfWWRkb65TuWKQxH7LIjmx5'
                . 'pk7/IK9PV+knWwpqqnvtU0DLHo3ocH+jV9p/cgwGoaZzlutenNJY4H9Ag5Hf0ZivCTKYj5GAwrhmjVFkBIeh7x++/bZSox9C'
                . 'PImEB434X2Pe/QF5T4NFZoRI5xn5eZPb5ZJAvXQg6h3ULu85359TOg/xXo6Y7d/LYZJkTTNp6zyJgrMh0r+p6uK306B75VKF'
                . 'KeODyrRO+wcqA7k3qbvzOC6D0Nf8aZ1Etc5Sn/JI5kWWBYxTYfoxG/y9KBqG4BSROWE5sisV8mK5gNe76OpcbZbRpbmzrcLs'
                . 'M5ZJYLGoAMAdblkQgutJIMZ5UYmMy+fZZSbTdLbHz/IcyMwHazHl/dj0X0ruiLd6BPDzCEe6tydlHVDLnKDWpME70ztd3+dM'
                . 'pXyzDzVFAcB6zOkfo7+3i3evp8RZlj0jVJAGEU3fD+/zVEU/KZOtSxkNJOVS5iToeoygLg1ZiOSO/ii5TT7P9I6nS9SsjVnM'
                . '5EJIJXcJr/2lbwZcZP7PN9kdRHZvuVTGfl5/avE7DJNtgmrIRpvC7CvVQThwhbxr0Vri+Pstqi/LSZWM5I4lHONxSCA+sQB7'
                . 'Sv3Bm1ncu0LOp7/WTwBdz1jqORkeYoxQ1LwA4FbNmd3bkTDer0JNQLLhFp3ZWbSsliyRa3NUwLRopcuPH4w975azjdalDWeD'
                . 'doMg9VGmbCgh2Xg0CaLElXQxpLK+1m0Mo0btYGmY3zGjAzNlIRTCXWZyraWm1Nw1fSDXFFbG+RI4qrKrGmZ0pUJnYZqlc79x'
                . 'vteSR8fONg4fpplFt1HF1LHHrM+x5GZeCUcnr7r6oKCeW6x2Shwol1ZpQ+NrtbVdX+lDrTyX9XyVDAT8NDztoTy5dkM/ENVu'
                . '+XGeuTB/6mrtJl2n9Y/90sx484x2jzHxJoCSN6crMyofv18T+lV4ZV9eh6Cv0Ld5K13eWx3QWj7fRDO7SIL96Jth2m5MzNgn'
                . '4+Eg/It7/l+jEIyu0wpMXcefiERR8Q9AQ+oUh4pDCAB4F20fdIGBMQdrdW+ZAaX8uQPSdeLKbqV8CfCGVUY/bVS88JXpk0cv'
                . 'Qe6hbww27ZAuyI2wIXQMoHskAOmsH/qOcbUbXBh1kdCmK2VmMt+xugTj8nmWSlkkzMFkWx4PxmcH5WJ5cC638+pGuAOqjnhV'
                . 'PQKkWFAI6l/L0HsABeYW5GkIr2GlLHXBhaCe65g3Eh0gAJTki/UT1WuC+L9DSDcAcO3WJAoAbrxKcHvap+X6oesAYMIAABAI'
                . '+kYzEmPi1jHuP4puwvA1ecngZGGqv5443qW7MJYvIUvHS/h2q6tP2Dbbn6g2MuR5j3IzFFaPwBQJhu1WnUuYFr0HDviIi534'
                . '1+cdbL6biMcrVC9Mdg+sBP3AVrxnBcDIIkasYAmMmBCAZt2E+77IopYsfCBQkwMAn0dM3Yg1190YPyfdhDuLyymJm0GVZjeT'
                . 'JRkz+JzR7IYA6DCdGwHXhzIs3w0RKwRuCuz2uxlA+8/NBKuFbhYITXKzwcHr5gBbspsLYWTlvBzcNNC42MeNBlCgRpVhqnk0'
                . 'LkNXKjNCihr1qgYo5myUzlLE5VYbKdz740rY5ZA8Rfmq7VpawM2N04AvsIpnZ1pKngo/cSOK3Qh+Zm6jOf0rOYqumLjy4eSm'
                . 'wAPJ1Pp1lcS0QiyDJ5OI7QbdMNcC/GUqMEGxj5pzDECI5+3r22s0UL1AkcVS+86H3rnoC953NWaSOc0IC4e/BdaKquJ3MnH5'
                . 'dq2StLN6shI8kcxZozXsPCvjyrGXKXyJTZRqMm2Sah11/RVG6fONwtb2rAP676x5DV1WdHqD0WS2qFab3SESS6S+MrlCqVJr'
                . 'tDo/vcHoH2AyBwYFh4SGhVsirDZ7pKOXMwq5YmLj4hMSe7sAEIIRFMMJkqLRGUwWm8Pl8QVCkVgilckVSpVao9XpDUaTOW3u'
                . 'tdrsDqfL7fH66v+1KKj05xj1QOCcTIDun40KwAkwcAi38aA0zWy1ItFFDF4C0CYPTmObPnh7o5Hnb3WrtdVNAEBD4aP7w1/H'
                . 'bfMZjPvRpl3FJPwy8ktA+Jfc7pO8jJarJMIOVTrtJ/I39T9xVPLXLY1WlxLPGiXd/7TufBcqGzEhLScFac7brF6p1goJHW6Z'
                . 'pfM/BpXVF4KBIVCEYto+cTACCYIoqu0mDARHiBL6I4FaUZFBw0wqiUzx4g0qjU7yopennVcgka1FwtqALglpVgNgYWtk9BvZ'
                . '9Ql363eWDPRuBd7/qabLbt1mU9/bSn0Yim75YWkMOqXyQFgedDH92pu98fp0vRn+FmM19z+v/+lD13wD',
            'family' => 'Cormorant Garamond',
            'style' => 'normal',
            'weight' => '700',
            'range' => 'U+0102-0103, U+0110-0111, U+0128-0129, U+0168-0169, U+01A0-01A1, U+01AF-01B0, U+0300-0301, U+0303-0304, U+0308-0309, U+0323, U+0329, U+1EA0-1EF9, U+20AB',
        ],
        'cormorant-garamond-latin-700.woff2' => [
            'b64' => 'd09GMgABAAAAAJMIABQAAAAB1xwAAJKTAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGoVWG9okHJNMP0hWQVKDWQZgP1NUQVSB'
                . 'ACciAIUwL1YRCAqD0WyC7wQLhWwAMIKsXgE2AiQDi1QEIAWHRAeTQQwHW2qhcUEcN7mA2wbQwVzm2TDvAnZsC9Adcqf6BORW'
                . 'sFvg3Q4SUaM82eT//7OSjiEbWANQ9fr/VTrNHBS0cKNI8yob3BHI6JkxOnwSfmRHNHrrq++dJ+Iibhfm7jRkDMfz6cJFFY1y'
                . 'yqA4ZFLntBxKxTKjTCrGmzCwhKBOCiKf757IwrNZ5BK/tzNMBgw0kpKkRhCkMEn5N2GvecY8ftuRyi0/RLtOjOL6gqpff/WZ'
                . 'Ja7xAJVbrYWrYGC3BfLEMBekzqDppBxfc0rHNuFUk2ITVEY3pHSEK0a94vmI/YqrwPfACbeFhcA3i38stvLlQDw2jiox1nnS'
                . 'jfj+PK2t9/+fne3Zna2cTSKXEhsQERERC6voQ8VoxCgsTDAKEDGKssBK7ELUOysvvDv1++a+71ZXU9Lp0CMaIM7XMhnEL8wS'
                . 'Cwmk+Pj1K/QS/7C/53/WPvcBzCI/iCSogUlmkgZobd7f/1N/Dx/Ud93ffUTz8AE8tIggKEiU3eC0F6Irt5mLZNWudC6cc7o2'
                . 'C4N/6Kf9zn3Z8DeULn/JUrpxzQg8g1A4hwbpGMYjVKsJgEWdqui4Ln/7cjzla/tKA2CHnTpWFyuD+1v7hweBP9JlVWrR4C4f'
                . 'cfREEcXpa2R7D71+5Cj6j4/JvzbLI6nVWNXVvbhWfaDtzAGBe6FIqNcvlGRP6BD3FugG2yRDc1prO/sgzAs/pR5Y2dQm9iDJ'
                . 'gEJUggAtJTgYmGduo3TOOydfPyC6Q2Hx+ZN6n8lZ4rsRtZw02ECBt0GZ19UjvzOfWCViFZc9fq36Cn6YUFk40zO9jIqNXSVX'
                . 'TmV1fwS/coU5Ie/UsVzmgeLMfPM6s0+eyUIICaGMJEAwwETba2+yfwpXNKlqr7p/vpBff3UZQv7Rm3zQ7Y0oRIAgXvX19vnV'
                . 'hBs7ryeq3/Cr/fR/TtiFnauwB2XSwG8r96o6V2Aaz76pmacA7Lzj0ShXKIVKkVFoFGlh93drKDcIbN53atIdy82hoUQpD5gt'
                . 'K4na4LrsgL4A69/6FxQobYtTSIHxXZb+7h1sHKpnOtgo4Sb9/5b9svXm/qYJqU5DLnISrqZQINwfRTCGrj89qaZ/TyD17xmi'
                . 'CjH0H2JUKA4KHNi8ZLUqZOFWhqRWEtyqFUqvcAs9tddZblZS1wWJjPzepfRHa0e3f3DBBW+qcE3iV3udffvX7L9NdYmmHoog'
                . 'JCpBqqTvpTRKaap0oZCJ1AzSolCa/99U89v3AIUBd7UfWDmAjpB89P/QEVqnlktR5KYfYqjy4A6gmXkDCMCAlAYAtQsCCgS4'
                . 'tkFQskDQAXEGIECCArkhOibtOiUQ1NqktPYRtcEp5M0OKXffLqp/QFfaH1X6dy5/U+fY1b8oXboof/mbLgWoZDn/hXhwCKVg'
                . '9vZrkajqkQRhgNoU7CmNU6AO8kiw7ob1/cTOsXs7FJEgIQQJEsSVvNJ3d69/EFFNzSNYkHJTXpIB6X73TPNjrS9KGrfmYI++'
                . 'WZoikqLiTYiC5vjeLtO5GLRODQ1lenw71tTQYsfKrxu4c0SQKQJC0mFlbrMVP8RSd+zzicCvABCSSgAKgLcAfKxA6mwov+4a'
                . 'CySN5ItGVxy2w3IF8o2Qqq9k8drAsN76AKh2GoZQSA6UAqGgmNC6iTPr3ay8PkUFH4AGIDsBw8p7/CrbYAo0UkOITOe+uEdf'
                . 'SMKnctq+IzXxNiqutYqhwVcuRE6gqIE2UBouZ/IsAU0wLR1tfAndoTG+gbHZgN+hr4/BNWb8CqRowbpkTQdVlwLbgNak3hMz'
                . 'QnpBxm+L9VUF2R+7nnGO+eUUuSZyt4k88BDSJPJS5G2v+MEn//Tnzj9+7AYQKcSTj/iKkEjfFAamNiqvCUVMLGZSFXYruTq0'
                . 'DgSC7VrTSmD4cd0Onv0o9B5KJnTUg1jkQfjyopwQDPI/Dsd/hEuttLFIgWNCYlFzD1OTv1j5pVTaOt/Ett/jlikyj8pBegKi'
                . '739zUYtenN6Ce4+/XIN7nzzcgOFASQbcKPDwN80P+SFx6YBXuQHvH6cnMB208U9ABQCj8RndGEHhBUuk428K+ry7ntuKzdzx'
                . 'qfXyKNDkEBEEdeGkQJ1Pk6BFieDPTodBTKnHH/gCALE3X4H3OiBOlQt4rwJiTJ18EAMAREadfJIMAER5BVzVsL0BQ8a2qgFy'
                . 'PT6xL6THx/oEnuv0P6r0NyECqPPqEQF1bkVCnvs1db6y8qoKKQ9eOKrcArlStGKHydFIrWvm5WsAiCeZeX8pKEoS5LqyAqwv'
                . 'kq2XMVC9ZqNhDa/+KfnkIllm3A6uQ+GkPIWHYerVKlyint2aQAGCS/9GXNwFDrbVTMkYokzFTpwjw0sZTAdX/ZSSR6bcB0vc'
                . 'xTIuO9k9aY/KFmBpCbNepnXrgtly5Mv5OFQqy595hW/L4u2YOXdAFE0zyYBNKhyPAJUVo26S8bUJ6cr8rGr8qivil8O9JSjC'
                . 'A2Xqr0wDrDHzojUsp1fWgyMpmaWC18nd5r9SrdY++x10SL3DjjjqmONOOE1eOQPltACSk5oB1wIBmPubAekaAVxTXGnQtdid'
                . 'GXWedGAQgDmwGWDaC+D8OQWQ/iyA83UBpJ+nAJJHBRAOFUB8322B9CZid+n33Z37X8C5DASQniWAdFYKIFkjAD1FAOe4FEA6'
                . 'TgDJXwI4/kgBJCUAf5gA0mXNgPhDASR3DQSS55s0Ld6qowna1ALAKCA7XAJIDnWFzmoVwgoUSF/YXsLXQO0qSdxYYP/Atzmu'
                . 'Gt27pRZvbj482Hh0NzvaBh6My8PGNpNahmUWn29cy4Lnnmm1YgKnmYZy35oXXfSuVLstN9lDZzcdxt3JxuFxY82svlieTFX2'
                . 'ru1Hv2dJpa3UXpuaW5Vuuw5bdtKrFx245ZKLLjhqi5mP3ULxYT4tBr/IrUmFAlJSKO/qzhM2mWeUm/BCvo0XNWFhwBgzaSFz'
                . '02HCOD4chJV8bUKuQxeh35n601lDAQllD8Z/41f4Cb6FGwiH38f78S5chlfjQjybaPAcnEu24WycivviHjgJZ+N4HINbYQNW'
                . 'Yj4uxfmYwszyI1BFFP3s7770rqYedadrne14lfPZKhAbatRh4OufutV58l2FLKtRD/ag06FwGIq7420VdWCzf+FIIeme/jAR'
                . 'AJA18wiZmIRuT+64MjQTURwC/1vhs5qL9LEpwulNXpyZ9D6fVcV6f8OLMpN/GsXmj9z8meA7sFDyyhCn19dvtds/vlSxCg83'
                . '1qj1Y26P0ILMmwBV/vhoJUgtcetxcVvPu2eO1sOFWvyiApbNy72CBPdo+X2dbtKBa/96Qvy6tnjlWkbXuTPXa9SywiqnDetI'
                . '8JG4UwH71mXd1rf3aHGsYf6vOrlMBDM3M3NJsmeiR4lo2Fp98fWzN83Ro12S2AUn456evfLflquXVozX3TzqYnm2nDlf2DXl'
                . 'Otsn6k982d7vtyQ6P/K/dVZXiz5+8rmv+7NnFcui4dq/c1a2LMZxcicCEJfcUUmVMd5vhFFkq90nZ2Ox5gMtz96wzhUKIEin'
                . 'fJymZXOsidi+TurA+qL8ZJpat2RmKi6zWvxBeLOVEyyznTikENPUCv4i7LKctYK2oE9aDu8ng7wTYfLsA64b93l4m+VSm7zm'
                . 'u+fYxfvV8jkgrwwGUj6Fh40+8pz97LqWXc3PN7DLUmBZCSQaj6JFMydlE7XF4x3PGjsZ868s280h83SY4bVuf6onMaFF/Nzt'
                . '6Byv5s8lduav/mAiBW/zf34hUfN0eLOtlhxK4gXrjtSj6TzqtMAol8Zr479H34KAZsqPD62E2yBOaRPd4mvorObpS8cTMW+t'
                . 'edcVHsVbynfm5eLfmHzKUNMrT0i69AgExnklzqk5G9OVnPRn4iazyZzyaDoRmwPN8LyQSM0RZh11y2xQkKdXmwP9ibiJLjQ7'
                . 'bTYXNG36pErpyaF0UN4TbbvJQyvTDdpE9pqTOVPTqPYS3Suwl0hND0wvfFeC80B0/AgAA8MebyFiU62pDsqoaUeYQlQifjre'
                . '4RXZCYH3n2l5pecvofU0mhinbJ82R0e70G1R1XJTaulW1r4m23AuYPqnd4EndulF/ZSOWx545IlnXnnjnTkxCbPMscAidWna'
                . 'K86aBthgky22s/fEp6mUD0AygATPeXksgDYAAgFYEB0mo1OrCVwwcVLIvyeyeyUkOU8k6IAFrPTwTxFbqf3C8llDHbE9RSBx'
                . 'lCqhlt6HzaXGU+lyyqxxVASl9Plbrr6lg/1B/Sr/JP74LdJAjpM6UkkqyHoYdzkpIBPJiE1TKQVVRfnPc+tLiaKEp8kLZTIw'
                . 'w94kSXatI20ITISkkL8qjwD+jT/hRnznhEv8JD5o7bHLp7/2jy/Fc3EeHo1z8FDc+zRhEm6DTRjeVIq/JoA/LAq9K7m3vW7p'
                . 'k0/gjXiHfqA3WIf+Qk/QFXT09E1r0R60DQeiJ9FqVEAqUT6QbIgA/T0AiHe7PrJHKOoC3aUdw5Ct6R0uSbKkfjgpmp/w20oE'
                . 'nYB1AwT6ZQiCYsXmk61CgT1JTZuvH7G5ecM3LYkPQEeC4DGD+DWkwd9qQOfpxUc+liWSrkWss+7JTv7EH+xEVfc1L7G3k7XF'
                . 'XRujAOgGAaC6BnTt1f8BO4A86s1pGc1KHJ2Aw+lP7E7TsHOwgBCsUAmK/jEzFGENe7FQN6VSFSZC8JIHhv0sarf34HoxIV+U'
                . 'DhhErEcn7dsRyvakdaOxVe3TGQXjwSCODSm7VTTSqDP2pQGmPLCYLtrjiF88YUPoDAQALBUAMFcA7Y4uZhvDLEzM+AIVm1kL'
                . '+WIF69UZ4hfZqM5oDaBN6M22VDX/QTCFBjb4iSXUPWKCyY6CxVbCSbVGtQQDi0yjPbxTZtgMDdx0sCr9PUi+xwe+1yde6Yep'
                . 'cmZUb1mbqDz2OTAO4hDq+BFoKA3p6L2AFmECn/zbOCViAb6qH0REIt9GOQdjNNg9rrDADuwtELhv6QUlUdOOAeJLPf3HTdc8'
                . 'tJ3LO0o7+45T1HC8F8qt7UMK6j7/1yHesHMaXHbX/RAkRhIkRaqKKo0RK3aceAkSJUmaLCQ0Tdp06TNkzJQ5S9Z8+QsVrh4A'
                . 'PgFgBhiGnHMO7YIL+Bo0ELjoIr7LLuP76ithxEgKJKqKUNFUghddpSgNZTgKHJ93YzEVR/ZDxdkPL64KrK0MR5sMpbCMilCP'
                . '0GaGrxUEKshSQsINrQ46Q3QZWpHh7WFCnvvZUxBSBb46C6d15YxWJQxAwBbWAUiNgIGAgYEJfeoCGE57wuC3Y/9Tt2IaqEei'
                . 'XaQXxY3HqOx6wpwvI1sCZDLX5fIYuNY/+ZBautLjQGat74K/zQPoaWTG2VFLhjYy+gvIJ9j53daKZ6ydVMrkSTgF17qjhMaZ'
                . 'vQKlf0rKzrIqVnz9JslRDdDOdHbkQK/b6FE/5t6yOv0W+7gP/fu8odkk1X9kXnwluO0s9wiSIFNakqCsWlR/YcewzIDca5Vi'
                . 'vSGzL9mPIvhQKW45VSYztEpymAOrj0KaE101uBSdwcHcGaqskZMFHNf/tQr1F7l+lRxexy2KROildpHH3pIgtSrckmLTNYW6'
                . 'vcxWuz194cEEAzfHyM4ByOTQZ0gBgMrWjlyReCrBpkOxjpZAGjUZc5TQXEGGWMtZ+nWnzit21RnKNTtUdyHX52Q4eirk4Luc'
                . 'NAkrAGw1REs5jPPF5sRxOJs4ulVLRl4GiRzHRikaWWuVksGgVdmvYdljeQcczKss2k0to6qbeDNdHPbmVPxEe9gvk1ky91iv'
                . '+7nc/S97Cuzz8x7MfGaawrH03fNYvYxsnnoXDjK3Oisyz0sqPYe9FKQcLP+D3AbZPvUpIqBqzzL3qAy5r1Sfqn5ts47enHew'
                . '6TCx77duuyq8FDN74/d3UX9EvuxQu08wG7Z0WLc4sG/NoR8g84UqkvGQeoORXOlrefUg86ADdv/6M41PQEZLR8/AyMXNizcf'
                . 'vvz4CxAoiEeIUOEiNBOpuRZaaqW1NtqK0l6ceB0l6CRRZ0m6SNZDTyl666Of/gYYbKgsI402xljjjDfZFLPNNU+B+RZYZLkV'
                . 'ihRbZa1S22y3w0677LbHXtVq7XdIvSOOOe60cy/3lXhSfxTJLYBfghEZCgiAEhUG9CDoIhwcHC1INdzyEbzsdgmGhYqFqRD+'
                . 'UiZCS8TUymhDdNZWqWg0aS8WJ9Gxp80EyXi66j7viTwpwYZXmb5I66dYfwUGiAzsuYMMng9FsWEapErJ0CBLi5zWaqRRs8FR'
                . '3EqZYk2BPLMgsxWYIzJXyjzFChSYL2VBU2OhRSiFofpbJVYipShcr6vSaqSsFVmnwXqRjRps0qpEgdImq8xWtG2ytjcFdtgN'
                . '2aPcXgWqpJ1W7px6FzRq0OyiRpdjfHkUUVQv3KJFhRARKlRoESJEhQoRQkSIEF0LM8aGg5FOI4K+UC0Fo00LWaxzWdX0RDSd'
                . 'VcyDJuFhhwaEDGOYQalIvEUNFetB5FCx3sjqI0+/BRmRdcoqMBgFhsqTpdnICIeg8kxGnikhEDK9IZ2HQ6vBogNpzwfdRrJQ'
                . '9XncyUjnO8ruIUBRlapTolZivzyHFKqXOCLPMYWOh/QZRTT9hB9dJ6N7qRsbNHz46KCh0aGDDw0fPvzQ8KyHSN4w/R6igCBZ'
                . 'UOQABAB411UAURIGFzkmTem0JD4gPRi5twjnv1y7fjzdbbFp4XWnYdndead65fy5s3bN6aozc93pyWn2JlrvzK81LY5SE1Cu'
                . 'OCh9uxgy8lJrpK2RtEbcGlFrhK0RtIavpVccEy+0Zbi+Ldk9pCa4tIefbeHkngYzOPz/Uj5NsP9AFUuoPNAUGew4EH0N5QkS'
                . 'G1nNchYxl+lMno/9/yaHEZ/PILN2lAsyBPARI4JGCA8JFAoMsEUekoGNereKho6xXd859nxiPj/X3v8N4+f0bNnd2d3bHdzN'
                . '3aVON6baqW9HWl1uLQkrFnmEYxqXEHU0un50Gp6nn8N6Ky0yW56xcqTqL0WyBLHaiBTCn9spWK6TsGFOwBY6DpvnGGyko7Ac'
                . 'R2BD1cCGqIb1cxgxxEL1qTNPnZPrkDPSQSfHAWeY/c5Q+5whav1+vyRi+tSg3qvCIaRsGGIP1OAG3f8/0EheBDm1jfV4m47g'
                . '+xmcOoAelZVZuSG8EZ/0yKCvEYBWlvP0KKDEoxpc10lREoUqEPlqTAoaUTpL3LLgnRvqlJktWwy7vB1D6s155luk0Bc9SrJ9'
                . 'T9oU05Uq2/VoHcUC6oyJ1I7uzy8mWwy1HWRrzBioIVGlmXlltU2+tg7zNX1R7e/tMkW1vrfYDtXM3kLbVZm9OylTJfVOUKoK'
                . '6x2pUGWIIixqLB7axR87gs5Lv/vDK6+98dY77z30yGNPPNXomSbPvfAhjoRvaD4W5ZwHAPWucHcQFVNvBHGN+A9nx7jmhptu'
                . 'ue2Ou+6573qSS5QsBSPOuQqOKNxbmMTKieb3i/VHidNCIAahIycIBSQ258NLnYbw730FQkWUW8JtMQYdmiEJHuiCiy57oPl9'
                . 'tyLy0WdffPXNd3/6y98+kcjwof7xr//876dfAVAQhh8w3MzBbfiKr1tSjHDMHTi5gWKPapQ715bbMcg1QNZXQu2lOAyNmCl1'
                . 'OqbvWt/MLO7LoHQQb6Nf3IelNQxpSxOxdmKfxLyop3XW26DK2mRB/n7FlHxF6iTDdIXK7Eiqq0Q0fkvxYbiJKxlwK180qFtG'
                . 'wFT8bz45YJIuBqYRjpNfOIoQrDVr5FeOL1m4iv2nyXH77VJmtXypYkQK/NW/EYPhpAV9MyxeQzHRHMs1+Vh+WuHp8vgwU03q'
                . 'prSGITCpiAnJEzEOYzBW1wjSqgXpmU01aqYA3vCBO9kLDtiS7bDCbFtshSXal89pkRFimXBcIiQYJnUPu/UpFim1fTjDTjyG'
                . '1MpBxjlC2qHLBvNoxcsxfIgsoujRufZRofEy7sAh5C5X7srSL7RUNqk8NabT8HnoqhtUK402ghoXgK2v1nuJqPTITblc1r1D'
                . 'ULWYOQeRKiunALFGJGWpEfPkVSOZUecTaIy5S2fbQExNpZkyh1HDWj2S+fyABXWiym1BK20EMQwjLSVClCKkXyLKoyYbYMpQ'
                . 'oVD+xCFnRdERddWKeFkiXIYgk0wQ/mQUNx9jnM9jDB839xubPZ82SA7yDOmHqdquiERVRiSene2ulRc9HwshGCbAv1hhnJ/N'
                . '2JDSWkQAlw+58fF481Vw6sRLlSklHsBnjd4AOl1uHe0sxCuInx6cQWXDAgL/S9onCRzH/Wf0+P9ngFoeYwV0OQBAJ41RADgE'
                . 'qnGg8GB6Mid8zOuESgSAreMeZAkCRELHW5goiYWtaZA/AHJKOAQtBfhBAFI+rA36ll+8He4tdJgBD6M1UgBwoIzQT5ogTion'
                . 'eWkF00vBELi3CWsAcD1RJQCpgar1L8oxovBgHs3lq/UraKnxzjg7HoxHEyIUERLHirkL9wL3JmfgLJydc3OhXAsu1r3tYLct'
                . 'O6y4pfhpZgCcEC2U2B9PyfG2zNcAaYjgcjc4HWf65kK45p5i0fBr4gQ/31tphWESfQ49b/7Z8mz/7CYAQOM//9z1xl9u2jje'
                . 'yH169fQ3p73lAkBiAAz1JgqAbqocjEbXgOGSVmeGEOoerXDyFxurKXvWn3rXjFO6okQVxksdoJrwJKi2TDUxsklA64pPSKY7'
                . 'OZaWniFQxXqE6iGswsbV1XYUHTh+6hqTNUNpFc0/0PYEWRwaH9aMlBvaHHMraI6Ft8JK+4RdHf8CqaqPHL1I8aKVF8xZZy5n'
                . 'ElFD4tz15K3u4M3JgTvujQBn0yw8ohCNTJKqeoZknU87TzcMMQkpDSUVNQGngGohH2R1qXK0rBGe0SghKm1RZrOtylXYZoDd'
                . 'Nb3H928yZMoyyGSzTDfDHDPTy2zLFFpiqWLzrBJsb8bWHxsD6dJPLNLrYSj0M829KjyiiUJKWNkpYLdZK9aICpGmFwDUQrzB'
                . 'vwDUFvEB5ryrPRLyJNTxEv5/S+JnQEc5Nfw9IDNA1+0BBt0TG4KtESHP3cBnB74BOKjcj2CAr/kIdZAwl+KAE2ADxxsCiXEL'
                . 'FOYTjJxYBFP5iDGPpMo5MJbhZuOkVZmN3kIsx5VTv3CKOU4aKKSwNRSUivQd8/x8vAxITl59h0B0KHQvgjO4ZQ/JVGVYqYbY'
                . 'viZHroMpRhHUQrIcgkx3jpGc5qObddTy0WrA6KGopRCNiZWOeQhn03hqaSoNFLSYwQWazRLG2eyYLoyjin1f8h3J2gzOl03n'
                . '/JxbhFHqTmDQs+Pb2dZVtVu8TbcIbnFfQbTkxyPiN6OIxQO5c+AeQ9kWhERMyIrQQylJhKzLcfaS07cWuDlUfeP9PZN9jB+n'
                . 'jxPYTXD0B7HhXAi5J+AHFT7nePs0/jGiSoQ/zhBKPQkMXDihWHs4xLeSQyIgCpIND8s3RCVW1svNJoZqD5uZvUhjtUk2G7v1'
                . 'gZt0gyBOB33iPWy4aBpUVXFcCcVXx1qsKFuKWYSArBailCQBgeUpbOui85a1NyF1gXhLCKLUnUCayX5vN4Lf3sLbBB48bGZ4'
                . '0xaNXcCbxJPedoaHhFJPAsmFE4m2+xnVzYwsLeG0mSWQBp4IpjStZl/VxV2mxB3IRXqwyIhM0gsJ7lNbJ+Ek6XSSueW07yG1'
                . 'pGySmlRpqWANZCwGmpg1fDqRlO4PHawVJCnB14/rcYyv7QmJp6uDDId4u99G5neczpJ7XVC9vxh56PvrPgzAGu53arxgLuha'
                . 'vuZBUG9R+ZBGxDihELw9GiIQEMLwyotit/Nea1ME5S9lX84O3n+phlL8ZmGN25xuP+shGN4KmEo+hqsVF3L2BpQSwoQBQmH7'
                . 'CJ8AIe+NLpfLNE2yUcg2IZl5a6UEe3zazcTCCLX+mkkXX0/6dQ6Qw7kIGiwz0ItdDsgFMF04BYqWSXRD5LDVEXnFpBp17Y5w'
                . 'YwTExo40vktX+BVAVDocUYJBUMAEHIQUR7po3or8rnLlJo9/J3GYv6fuQVelozfnMufFi4VDlEmCsy8fa7qg31okJdJKplsG'
                . 'P7rDiSN7cPdCpmoaiUG+c4p4PIzY4meZuYnZNxri9BF+LZgwblbLai0JEVJ/HEHUMAQ8KFWMqbkYyFMhWCMAnUzLDU2i0C/t'
                . 'ne29dYJglStLNUEpuOw0JAlABngAEINFjb4FIEnEzKDK9gK/qg6HfN27OKfDfQOVei0EnBJrFI9A6m9Xn2OYEq+KS7+ldN9T'
                . '2M8wMxNCm0XtWgE4T44pI8ZlNaijxq4ToIItI8Cpp/BCO3kRLfpNBuoDlejWoPpD9HdZJG3wcxa2ByQJ7D8JNJMEbABRkXVE'
                . 'XezEjC5nbMfNa4brAP1N9JcpJm5tFcboHQNwqMKUPfaS4YNJFQ0g5v9qX6hxClNZ4S+cJd6NdLs24PV5ihKYzAhKMWdpXAoB'
                . 'FSoLUko0xUyUekiyhTyrU2reqRp3fPeFHIAMgTkkTD49bAWhghCAJxp/7+bpk82EPKSlBoUWdpA6h+fo1JD1KRIRuG7UMe8P'
                . 'zKjP2slNJedlj0GPt5OZq4IC+U7J9iMtdkdL/OnKcTzZMv1h9JO4ua5DL3VRdKfeE0fRcetKjrPKw8ytkp8mv6jCyhBmoXsR'
                . '62qAwm+1WV8EopImfdu0b/eAfk9CH4D47fQG7PrKa+kHFT4FR5tJlHMJUaioUU3AQr/cVCV1oaTSlPUNsgnG6SFx2P6t3fyz'
                . 'XgKQyZmt0KPInIUK3fLKfRgVT8XplmkdjrHAHchz58oYyxWW+LkUW2RKvzLIv+RXWdUczTpJnicVJ8lBFa9pEtBXg3GkYod1'
                . 'kHwi1/SUoRswRBMLhs6NHYYv7GAHtJtZtQEP2dNy+nwwBa/gu3JxoEvBzMRUAhUb3qIsdZVX20a0Ln97ZuwajlVLTCQqJhbC'
                . 'vbk1K20X2GPOjrN5SMdL1HYD+sRI4MRRu14Pn+RYL1x9evNGVSHd4v5ORp5IDHvtc0sDSL7SbKAwgRSJHduvlHi/BLwpwcJl'
                . 'sxXDQGWBotkm5KrAFva7njpIKe6ozkNGF1UFME1yLf4aC6Wq6u0SHAF21RaxT2A3tw44qzzNzlBSsJH1mBEn3lmj6fE5T4N4'
                . 'LEXy/s57nut3uO+x/B7ZFsEPy3zRHVHsfDTrM2q71htPQ5V23fwQEeRO/5PraMEC6Yk5WlMmVcGhhcfNppNlMu8agpvt7czA'
                . '12+BnFVtmDSrePDWdwytON7RzvSSi7B0R0hvbXTzL3UYgKe+BcxjEhAAqM7qEaNstz5K0M5EWe9QrIhIFVVki+OE1HE2Vaac'
                . 'k3xzj1Wgy/tZ1Wy1eJplq0S1sS15wZXGSAlLSj9kDEgKQ6UpGhQfCypc4YSgfcAbFTXRF4hXP7Tfqt6KS/jO0DW7AYVKJKtz'
                . 'tgOj40kTHQgL+FViMxLlOGJrGu4tuxWfALvPRaYeho24NiV3V5AUdYVZ5ucoBz9GU7F9G7MdgPS4PKspPugtTUAnWguAYoDq'
                . 'wfwi+ZxZSjl1A8Ws51tjwpBuDAv4SIRjtUj5gOQYgVhpyab6pDdg0V+BjnzPaKw+fUT/Vx6HsM2jrdwVmpWu1D+TmJbeIXNz'
                . 't+Srb5HD147l23RZk2NEu00mqbAGWvYkqkSOE1It4VnOEmmldIUXMQgC2IXW7XBhqHYSL/vnXyOXCQNV4gKXo5+BI+7yWSsE'
                . 'Gle/AyHqxMuIDUjpzoYIuRr54VsGeW8dk+l3jghyzEKQTOltqcMcHOHQWVKXr78eYhNYnbjmx8RvAT0h9NXHovhlo2NgLQSB'
                . '9ABgpeqxTWXEjhiZL3eEM00lMxgFGId50okwttF16/L8brsiW/lDjNB2Sno+UAGbY4uyrEg4ZqfWHRY8YD2LR8NclHpoYsFm'
                . 'h9xYfbRcKX9QgBFbz6w2TiYASr0pJn/zukQCiuGV3LJ2qHHti1knIQNo6EDSSK1dnTIdZsjV+CkNAsgIzlD5gpZKY1PD4eyG'
                . 'YwGFzOOPCfDMmO/ZCnLnyv0qa7ObDSAi/fSWgVs1XsQWT4m0tSrAV9xVM61H1ce4VyTLAlGfp0VIqFbt3k55UkWT27g5fDsX'
                . 'KE6G6c2xBEZvPFM5hzP8m6aIZKJ1dAKr1TLAHJnyqexzCp4jksaSr4aNQv9Y7/04Pfqt9x6dwad6J07aJluZJWUqEJNwVFe3'
                . '2lfJK00qmf0KK/272M9GPm6z36JCS9ly+U5SOMrl20kKyoWUxzNep0tudTGd1GiQ0wQy7qSUUSC5kXFogsNw8Kl0JTpNxRbg'
                . 'OZKSfIDl3djmbFFXGjjqvEgdSZAh4quSo4fmVSnHw78KtVgfty1/WzGAGJYUR/OAZ8FRFRVnyomZzDIwiY3fkoxn0mH3qSOq'
                . 'Bn/Mbs26AKJNeCKMK0wEFWGhMR7jTJobCcZL4vNuSOUGZ0Z2o4VSwpWMi8vCd/K6czaSJvXsmRJpIeV8hascjakXLpyqFr9T'
                . 'l58awx76XgLuLM1K0zCrJp5t0qkeNOcg6iGleI96x4yupRqo0+V3bIPTYFU5jsXV7eQomw8znGW0eV4rHF6AyckhtkIRhDyx'
                . 'Pa/BmUq9kauWSiAjZ0oN6uTYED0sJFsdJi2oaMXWMKVsx1kHkJHdrMGuT/cJmAjS5zWjqmjVtmymeJKlqIZU1L/A8i1l6xoy'
                . 'mV1Pnae7HDofyid6bhpYVCJ7VWQfB4XuSl7nUKbASBbLKVKkcqjnG6loyhYbyhQP6gTuFa0KklANj9UWN1DidydOEU8I7ODF'
                . '0kc1IZLivJpgqWmAmsfMaYKauElphKonC19mrMGqiZ3LRd9uJcW6xwUN6bpwZ4IzgBLOCNO+rRFctKFtdSyy6nHiAsfASbRy'
                . '29Dl51++alGqAGNKIMldJMVqlDhmNA4F+JNimuSs5xlMhMHdLEBvLQ8TEpHYeDPQK7R5xtLur5emMjLkQvOO8pehkSxZoqW/'
                . 'CIVzNIOQrxGY2HIpjiSaESirbNlGC3zTZXiv/SJNgCW+BSN3bT0S3iLCuU2Xio6bsYOZAUiv01wpIWQo1DbzmNFue/suAG9y'
                . 'shOAlrfHfemdqo6ZGkwz1us8h3qqguRwzfF477JD7Tq7s1Ea7Fh1o3kjaW2rjuPaFM08HNxo7enu+gYj6vDJuDje/UmnRsys'
                . 'A462our917+aWmNbZnnZ0maW5/tIU1qAp62swutLytxd9ne2mOxhdyS1nZZTI6G+Jg0du2sTnd/jZDv1uSDkqvPTaydkL7N2'
                . 'hI7azCh1SpAyFbgVPgv1XLS2yVpi2uIXIRXYPZScH//wTrqGsbbszkKJams6Yaw6RdoZs2r9dvhqEtbx3/ylI03ieOHj1pB6'
                . '97TZ7yz1b131byD1H67VXyEcv+S3bRArd1o67GS0hQmnT2CZ+xYSSa38Nco6ivFZ6fqfECAptY80Lomep4py+g8PLh0wglvH'
                . '0j3hdkyDOTof+vtHm9oSZZ10KkvnqPbkCsKfyvb8NwDpEFt1OtxcQdBacAWj3eT9DwJ8JZE1WmssQCFdE/YdFAe2FgG8Q4rf'
                . 'O9iA5oRgUHn3eOmVzPXvKa2vaE8CAnwo1tt+aWMPjKOf0pQQemEOMk1RRrW6AhRalog0wgx38GEdIMEf5OBsob7Ezg8GZSbb'
                . 'TsjKG7cq05HUw/Etwlul9B0z4i0HQ7/Gs976gxPlL0WuswOIt35Ua1Nc1ylhFZq7og7T+Oped9V6bhP5cwrmxs+qGRRrGW0F'
                . 'a7Fdbazo2M569emO4ARj7NOyRG543qkYFcuE44K4QABcxMPkrIy2uI4yPbucqjJMwqwV7GzXbWArXwcfwICrNAPiXEZ1SCuU'
                . 'FQ0Y3EB+gUVuWejTgXIzqoU/U4aLtizC+VgRR7QrDJxd+l0eorKl7W05aOKgSWnfPtHy0ApXbsE5U0QIinIoD9ifbg0knYBe'
                . 'dHZ7B7dWlFUyBwj1mi1oRsac/W1aWNRhWe7ZjRt2tCLl69eF7F0OTeeFhux+DXdrp1jZ0xzQjmY/erNkQMrODRnBgLgjJqZA'
                . 'SE7rM+pUNFxYGGDeYbJ8Zlc0hbgYINudTa8b+ea+tXZls3Pt98LQXitNSjIXdKBYWAxW4eYmmiEFeMDzoQRwwbt2GGVCVxL6'
                . '1duMlJaoF2aNV0RzW1OUeiYxUmtSTS0hDB0NSMA5Qn1RnPAa0IDnmxCTA5zyFd0yoKcT9TIlhsHIFPQmtiSk9TG2hyXmLuwT'
                . '1uEqpvN1es1E4mBb3naRhEuMAckfGyulvFX+stDCmVIh5gk5UJDg6HFHkuhbFhNDXfFKGZufXu7ryq7XbkYejnHW8Q2taCYt'
                . 'F20r2qa/24S2oqXVdhvQVtLmsM1pG9AYBGTCEa2D3LLE6fQ+UtWZo1TYI1KTLcpmuNJklKJ9xJ15pEIyVCKpeFFW80eJIl7G'
                . 'akL1U5e06f8CPJ8brrN9bXfPm75XPBW2kJcUj8N2PdScNMelcE6PhxZAsy18lFwHTFh3+TV+eGc6FENsdLo8qZcMsEAH7tpI'
                . '9vewnK7L+rgJtNtkboKlZtlkBKAIBhAjbFzwJBI9kIpqzFHmTmJ3LIKUCEG8mc8tUjmVI6ni0sQYCuC1jeYd8QTJlDwmhOyE'
                . 'CUc2xwOj1BqOFT0IfpmJwobcpiuBV1WjlgdptnoG7fawMjMnupWXdREDN4YdBC2+xsHr7THP69UolxWXnN37t4u0RhxOr+oW'
                . '7/vDxK3C74YWY2522QRYqJ7V1TzBxzrEPGbO3dEiYLEWjMXxKeegQhpr2RUtc+BY8nq1g/f0b1xZvjKqd/L0WNx31/Fq4UV3'
                . 'dwrSM9xa948P/nR1L2d2LHAmL3hL+6bOjC57k3JLdpSanvB69/HHKi5Tth7TvIDti1oqRPQnBHKPwuiRB53wUGm4IGZ3s1WI'
                . 'nT6W+KGx3s2fAXzheIRfszmU0UMm9/BCRJldvrpJGRvgCk9HSeBzyVLYAsuHLL16qCp+Gxw6II4k92BAS70942CQ18wFn7hZ'
                . 'RgVzJaY/Y1To4cWcE8Kvc45BflOMtvWQWRzxskkX9vOiQfESSWY3PBAvUHTsF0UP4w5/sEVPGLKG+Th1cZuljkZeAQ8otFDG'
                . 'RQSPoqALcZpD3OkNZTZi4mMRjMeCFQr3obsgGWIExxU98OpL0AQSEBMDSMW2s71keUbCHGMEO8bHB76kra0Xr7X3kzLPT45R'
                . 'JbDuj2nacQ+TJ8WbM/JfmM0lTH2pACLBdqEOzBPZx3TSSdYQT/sGG1EgHexMKm18gWqIRWaEfTfhkjIQWptRTl1rMSSO4npI'
                . 'PVNO9dGp8hTysJW9uIdvZoxXhEvMVtWDe91BIFcIQl6JKDkZCGu73PIerAquTxyRMhUDsfWiEjH2CG8dZuAywvAKF7yW9IUh'
                . 'FTA7yh35MXJTc9/AoSTS0rt20sLJOeYrFI73Y4EIWhz72Jv/IdNUqhnc7wvVV9M7AJgeQxfFaQdZeMHI44pnytPKJ/hJ2VPy'
                . 'rPyxPISJ57RJq94BnDkyG7kQb9ktf8zQ+V6x5F1uzLeYmvRsSLZL4kXSRpEFSTdPjkX294FcxaZFNkOZesSwvera4Vw8tpp7'
                . 'mouTlE/IsO/imvN8g9LIohnSa//rSMFCotjXOoA0gDZPKlaUtDCIFWIkqz3sKLocGs9l3nhXJSPlJsnvlDnHxYFlcSfyLQcv'
                . '/k9RlEnyxwzGUSf9H0cXraTvNcAkuTbnKhZycxztL9b3QsDZ7eDSidgSwJ5ZeuBg+7a3ALzk25NgcTRZ5rJpMxkcxfHcbITm'
                . 'HXJnVVKb3SugxWEhT27HwdRaQ4J+E7MexmhdDJRFj6DYd9EiqmdZ4L362iMFgH8FewVidqU+QyARB2OGFHUVNc2CpcbKPkwz'
                . 'St52sFx0DQ0ab6q+3nfhwjCffiQe/OgWkGFQxJb/uTUkohdv3YtppQtuVrhcBcngJFtf9VjjPm6uMOlDSOTRAzqGVt5y9ayu'
                . 'Jn3dCeWjaD/DpKRlE0l6dtkuMEiaviD9REp+U8FaX7LehK2vh+qT0hNLo1OpTDxSdfWy02fLPbbD7DkXMWmOeHY9EDIoizfe'
                . 'BdOL1Kq1SSXlukrTz6gZoHjV+j9Z6Jxodwy3gou5PJa3wB8V9hh/KzdWYHNEN3qEMfzUgIdXMSdvrr1iGNfusF1nD06r4I16'
                . 'dlSqm8VMI98lFoXM6R3nh5U9tjQfzNnpof32Y+Ka23sLC5tH+ECM5DlelPl9Akkml3YZW0gARVggx2dJCE+9c8fz4NgF7BYL'
                . '0w4D0XHZOAMLcDN59puWBwEcRJn2MjgfMR4zijwqO/M39/1v14358BDPQhUL9rLntzLGfoHzu1sUIfjH5ykG7cMP+vAuTQ15'
                . '6z4/PzX8s3v0z0Txtbsj7fDn7oXHaXphf17omk1uI+8pJpes20Uv5O4gtVLm7LvOKma8DeClhDKXudo0b8piDhDC04W5aCKW'
                . 'QGyL32ZZhKEvGV8yvwoLE4yDP/uxh4gZF+IUkMZACLtrjrImm8r+GBl0EZ8BNwVaz37T8luFKoVsoI7BDjHVVj685gJKMZ0G'
                . 'QiCrft5cqYCjiAGtrEIMvHK8gHu4FOvzQscp1resr2vFWIho93DpPnyxNSCcFDavKGt/WHrnqPDjn66OTlPeuIW/eklObSwQ'
                . '90frmsqBysTeFzC0h78BbywzATjnqBGEsO5zkdwQjj7jqfLM6qqofqQxPVcVh9KLPOEljRnfuvmpartJ/aFiJaBTYUtLsVlE'
                . 'ICAsSqUvnhBuVuNYJNOBYbVKvyj8NesTvjJRvdTsn34vBM+pUshO6hNSqxO0cdqyLKBOBvoyyNUjqIVbXKFezqvqfMPlKWgX'
                . '4X8ZpPF8W70vXzetnltFmmGfaQfSD9Oe+Z3qpVC8VIqWIl5NSVH2LxKJWDzEu1TxEfd0kafA9XlngLciAWchFkAlf/j3qYEv'
                . 'abDk10UQiHAJGH+uKupJwN5mM5Vzw5a4kPtDtNl8pJ+X23TbuWqtN8id/bX5W/PRLF8KVnH20k9eW68+E+adb5QfKn95Y84+'
                . 'x3LtOFA/xnomsq7zZhj+oXAs+TGyzGEr1eFb3YFkEwYOtTnKMBRjHyd63RYVw6o4d5W1KYZQcd0Ke1KMdDQfNhOXMvTSETrZ'
                . 'eni3UwymcnCVUbgVufJw2cT7zVH16cEqfriceShlpUcYudu7x5Qo690Oq9l18xcHFwInYyIl8bteFeS+JL09O37H/Ci77+0Z'
                . 'yqxciAZDOKTeb+a8YBUhWNMTk46cqckWMM2f9ZsvblhS+yYq/lVLo9JsPyBCmj2dx/zTeRJ0Gn7hgnCuMJa6ylSKwfY+jNW/'
                . '7hy2RIoJppOV3ugfwe4mMvxjQcGl5P734u85kmuPCsXc4yjSFLLypyFTdMCEjNyriZzL2Fsz9tGcYZYeLuyCvtKDUpkvGudx'
                . '0KJ/HhvyBZ184WN8P2y+8nqxNRAEISxjCfmjsvI/X/3rXztuhiYQc3geIv0qBUPY83Ou4jvoch/8ig936r9uB6z44Q5jqFvy'
                . 'SJz+DOuvxE7imEwcMJhxLXFFq5NjIaJcTOuplv9/t5x4VP6RojTmIzNh0fz8GTSxOGf7JP9TZeMN7T9yiaiW0Ep+sLyj3k8G'
                . 'VPGxGVJXH7pidady1Sd70MB2OY533yur7ueOusShAU2yRjngSThmt1fMldgjA7pEjbzXFjAON9cs0AgCvS7Pgrqm0OjSZM3A'
                . '4o3zAtI5sfoOrXDFsSc1ohJeRfSJZVCJ7HFz/1XV6vhXGlXrVId4MuKpRip69sU/lfDGlHyWOtWPeZx/JNKYsRlyaxf/nMY3'
                . 'CF2PdhGzCjttZ0X1bqDmcGPlXKWfmcTwS/MyuTMKEVvv22y/KPQW40vGO2FRA/29n31YQpTbhpMfx9ZfUqXQHaniPEI1Qv5p'
                . 'mWwW5wMW9kzF677EE17cmPGtX5ACDqUTwiKlUfLJTA/jpcYani5o0UuiLqSsRExcXIYouYt5OcoygTpiNsqjQYlS2HZRCMzm'
                . 'iL6Rj3CVEZCLhUhEXT7n02EeKfLYf74strWZbQlAhZKYG4mXiIlLShEldxEf/qMXS3JqppxeGgDO4a/2iDTJFDnuZp6c1Poa'
                . 'VTVN2lRR82Dh/lCff9t3gUuh83lhpQuBlbEala/4mW8e90iz8mdQu4l97uJvZJytXhUqd7lhn9Ct2qDWaX1Jnoq+5kLfdHxO'
                . 'Fo5A1PnZ3kKFoBKoaXKuzYuBEDaW6bgkPMQTeS/JrQT7g0lQwguVY/MoxlPMhkAJ7mz1q//RmTfcUfOp7f4YwoYojNsZhTFi'
                . 'CPsdem/+lrv3DVY+ssifQETTZCppUoIQ9s5BLF3Mw6eb4yJdlpbZIaeSw8tt9jINn7+6+02YDeO75k+tb+2fhlHqSDmnKn4R'
                . 'cRGnJmoRY+mmJinOLNhURS7SUqa5N+Qqa1pfG05asFTlnya6yV8SmBPHpbpcjAxjG3+Tpx/Kb7M/PIzch/U32+FF7Mx2Ajdt'
                . 'e+FjdXefEVx/+dGwotNNLbRkCKFebphUu+EX3Q9vyfT7td2+viF2YgiLbcn5phB90NT6j6j3A9gU6xP1d+yb4rbf6asYSvaO'
                . 'AfWtCT6e2+1fr8r3ywcWfTZ4lW6Z0B4l3b/h3vYVV/b3DopNanjmRbvOWbctOvvW1EW+uPU3NKw12sE/QGpb97b+hxvAV+4m'
                . 'zTSh/ttWZdmff9UxxVaXiWWHko2Ny9hJTNL1ZtWjQPwl80Sf4tO4zG6rspZA9QFW2dHTPB/2/kUJYQp+5zyUJBwf9xSuRl7F'
                . 'qArZ+ohb7ZnFi1Quja94byEz3y+oHDKRCEsnkQheGMphSwIVv4+qk6YAKXG287K5W3raZh/4y7W0c3ABWSG2SlylLa4CfhJN'
                . 'VUcq1+2Z5awVlWb7Hm3werQMqyZhM2oUEXDOspgMeaCoo6rDXWRPIaYKXPD45vR+a+r8OnXImOWngqk6kEXe7G1VuX4Muq7z'
                . 'qrQaTpSo7VKernr5JC/jRCPwMQRI0c8vwtZPDfxzvlvnqlzZfZdTQogwCtxDipKtyXTN0nBoZb0b7k9Wd6C2qvZkUTk4jrOz'
                . 'CPpUtJbq6BhP1m2ZGtL1N6cWK0F+d0vM/9TVuCGKZRVZJgQ0N4VIFEt2zbhAEsnoodx/td3NAztWA5sJ6WXa2jr1kDdqGmhN'
                . 'rpDHTXVFf04tIhBGYscUz0PZ0YXK5FJ/bHGtRTgQaxo2pzT7tiiQr4nNskj4Y2nAaF4+b/qIhJ7nhHOyQqfQZxYACLmsrGvU'
                . '2/KwcmR4Q51NgYVIgJnSX1YRturtlW2oI7d39C83Bo8tYXHF/r0aTgK/8CmD2sWEhXfVN77DSFO8waWqtHDnKvJZEW9Cfzj3'
                . 'z1jFO+Un7D/zm9dUlJIsXJyH3EGMhV6j/qbHTa0wppk7fAFFJCJj8sZHxj2xMeSSK2De+9/aDtRtLEp6hi7t9DBRqq9/otjq'
                . 'NdQ8nIUnzoea+iVZA+oT59NKkLLLcHXY142hU/sevI0457YqlLhMBgQlGEdOT2tk235jHw2dKRko4t26uu3avLJk1itLz+eB'
                . 'njgoFJ/phHDbm2jz3kMVYFcgBUp4JuAIP+TBu7I7DYhUe12j4xHVY+AYXuo3W74pR76n076n0Ht1tQJPkKwJ+ud8VmxvZHgI'
                . '9g43ieOaL6MXK3Uqw5eODxUdGDsHROJRHNRc+ioqOEthMLK4uc+qiO5TXrbI/Qpd9Jf5sBBQUx1ui1uCcs8W00evzDunLUSq'
                . 'i8hFK6gEYX89TK+CL5eNkVz3V6LeKXG4xC5PKP50FdEd/pqqhmiTWRkyXR8Kpn1ChTwoVSYcMVfrNBSXE2zkHz8mvSK8RiVf'
                . 'RK3IsIULgu2we+BvQmzaIqlVa0Rlf0cZIqaDRoL+YBhPzSEc1+7MhLSCTMRgo3eneO08tp8uMtwvfPu/GH0f8gmq5WmzSZ70'
                . 'GsoZIsHnWzcudxn0rnhtyiRInRLDQe31oeBAQLj/1xYhoM77+B93xN2GoDhiojJL+F1hbnUWGkVmW0lYsB0wSfxXF6vRGK+s'
                . 'MImbAma1qJddEUwpnyhb3da1F82gZYK5XdXL4LFwpbmjETGbUwZlrd4oKv8oSOMJ3LTVVwtIdS6XvJgbzOUCjwitEwZpNWo3'
                . 'NjeZbPplMMGSg7rPyqz2VNgszAS1GnpTL3k6RRVQnlZpEv1Nyfh/6aymjUDEf7vfM/2K3x2vX4hPCagtwxenhf1UMSpJ7t+7'
                . 'bkw1tUhYVJhuvnzIH0PYdoKjo7+7ZgVw3mqvePws0kqUnQxGAhGfSQq+8JqC9NOTgE62IgBNoWS+3yQ5hrDihIH27D+2QZsn'
                . 'DJQkaRpaGiiqS37qgOY2/LY94rymzG37KzgBnWLmENiO8C1BRw15VBvsNqmImCKs41woVNeswTlPafULLkORH/GfsTLldWDx'
                . 'Q3ZPwVsdW33/jtLub0qluhmbbjyDUrc6mc54gjCxNGn2kd2DoWRWxRRtY0p6r1bJE0gq94DKymfMU2xFjrChtrx+cuXJ9Xuj'
                . 'HN2knM6GHZ1Wo9QH8ibWbKPrrWmVi6dVVxyxfFZDFH1gZ/PSqUtQ+Ibk/+ztagifLG3nJ1W+DeWwl5hjIWE98k41oDPDvsq2'
                . 'xafbCywrg4O8vkYU+cmtp5I6hW7NSqqniRkm7dFUYqeQM/E5/2Gh6HUNEka7tSRsdrzkHBJPm2dHb/lnTWF29Jxv9a/ZQUa9'
                . '91fl2W4VueDP2X9CxXH0K+vFSr3S+JbjEzpcNmxdYOX3LURd4OZ/pea6ILtZuRxLpofrz3D+DbWsMsPNJp0o+ke4RGirtKoa'
                . 'dC5zXSP6gckbqbbLmiJGjdkZj1il08I2nlG27yWvJP8oxnH1Kkn2jE7cnFc3ndbILJlDpZkn4pySGGOaTpbYqi0fIV4OuR9b'
                . 'vJ68rJJ/dANS3WjPwV2Fmv4svFIkeo0P2Whsvr/yOvP9ly3HJ7XhqkB/9ejcvVex7EO/yqwPNaRqYvUm2K/FDskbDIjMJ5cl'
                . 'rDZZpccQogNMqRGiC7+jRic0gsHwqOnBWzsBRHQGeTVsPoLDqIymCmivD9EpVNpMHjgKyfypakkSsURJet3opHHGh7sH3pMP'
                . 'Db72qMr6TKDFVCBIPrym0qdY9/3EfTieSE//LbbXfqt9MIQV1w1MGyxgmnf+Q0FSjyOJLjMSaStWGHFO0fu5bW9HdJN9hsCg'
                . 'aEb5tt/WPbrLcb83BpQ+GsWGU5gOpASf8H3Z6IgiSV5elb2w4YJNnPuZjRLBX8qXsVv5DtEZLeMtHBbr9iy3fNBXVbXL3pFe'
                . 'c9gC0se3wNs4/YIno+k46BiQET+9aujgPPs6n2pWFvZ0QjJhOQa7k6+tV/v6dEXeJEj7hJUuMNqZQvE2d7TFM1Irx9nxXud8'
                . 'PuTRtERIdZxxJi2xGtWHyN+QRamOWSkx7d+qA4/5pY/Tq44++s8uK4G0bUZ1oOa/grwgQlUWBLRao7iEEEaM7bOKa1mTnpth'
                . 'zBni3vAzfffyS+3qZbAfBCU6ZYQ28PUZpt8G5xsBimihb1ypeEs6g/WzZjqbbOsM05SZWY9HSokS6GR/bsTtisk3Z17pPsvg'
                . 'woSY/zHXnyjuocbM7mPfSBp1LG+Dqx8YbvO64wXxOVPmDU+UryL5r8KuGUyPsvFVXqJgkLs7oApQnlBCkEiVoJDrH4d1CQYW'
                . 'r23Zb02/e3Hohru8ttASlsp7+x9iPUCNqu9lePUAO7Z8oJn8cmT915YIYbxv3vMtuBpZJ2EqUds+LEd6ANdL+X5whDDSA3s8'
                . 'vRqJMBpCxVFBGR0aKlh6KLybf8uzaL+no+Z8XEw7v33HFzH7ekfs8oY6z63kBlRJfwpDdS+qdDMaWPGiU8kCsi42JFsr7cye'
                . 'aDOLpacxbfRrVbIzIjZk+GG9Tw/4CP1KQYnHlclgZfsH5MGmccoOyQGC5pSJMCSMcRsT4CUDkEaHNZqV+qgV5zStxupDj239'
                . '41l9/NGOx8/qoz74gAde71kNJ0vUdoanq16W6b7ksRvUD1HyrEbqK2KF2CRR+HjSctCLCebWPc5m0//VZW9Xkb6D2OOkzUwB'
                . 'VUnUWrKz6eiNnQj3ioadTnlwdy8kXCGyw9601K4qrV4NGwiWbE1krUBjrHpdLJvOILNfapgH0weVF2zk4lf4ucsNPlluVDM6'
                . 'E7hDaBk3SELRI9FoMpnn64jRbHVZjtxkT0dMgoxfoxF8HDvcDb+QR3hOZEL5PUpChRGGJNUthWHG3N5ofwpjFezeL/9+f6Qk'
                . 'EHHAuqrasOylggGKhPv+C6pwY9glWxrMQeBI1oSN/6Rj3zKEUvicO89SPLYwLJVJg35Dhp3/eLCHbxEII34BanRJ3mbxFF7R'
                . 'A8Ejnfw8whP8qkNs0ZYX+We2CkBVdl4c2kIvJab2AF9DEeVmZbNJJ4ju9pXwDGGdqFrrQVqShsWIOV3RlOhzayxlUZu41WsL'
                . 'k2wViCSpsenbGu02/QpHMUGD916TtXQ1RDKZljB9QXJu6BB6+S89QFwkep+ZM8oQHpcdKyMxpwuKiKd+GShY+EPjCWnJj+1i'
                . 'm3fd/C7FbAUIYd3uEjqpEAuRgB2K/2hDJwQ4iLK/PZ+WNzZwab2aV0ZlNmRn9aUJNa7L1Rv3sjmTr62ZQ12NGpka2O/6UceS'
                . 'WemdMZlRGSzx3V73llQq0tz5Fg3xNLE/xHvSiFHz8TEpELQX3MlgxQReaDOJw0UWRBHki89lZJrG25X0Ns+eZvfBoaKu/U/H'
                . 'lCpvYnhgDO9N16LZfdpD40kzxVoFVJ0u7W7oq8XXw2Rmu1yXxGEIBxhxEOU3I2qweWmr16cOCof2H4gZYW/F4oUPaZ8y8QVS'
                . 'knn+P7w6UrzwHK6ZL4h8m1PIuN7VHL4Xqq988Ouc/J3ceR3Z9yuWqj6NZMP6IVvbl+Xm9zrnbr7rMFfsl8OBx64twP5NnevX'
                . 'obg8c7zsvyBdXc7kHYeMsK5os3sNHhWLeEmsyOWHxUqfSTTUdEKnnmR15wlJAi8+hXXk5k3zlB0mmzBk/RMcQedZUH+iWP+j'
                . 'bmpb0WH3m/3o4c+KpumQwAGT5IGwKaQIFyeuqqycJKokgcnql37l8s5y52WtZEGr+kcu8zjnPbalruIyh8BaCUtKpBhPscQ5'
                . 'kC9ZR4r7Z2B8U2tSR0rS/572iGSyD3lDbbaCnAvywySwDlmnz51f+UKg+A7V1MLHX7P32jg3rDGdeqHShr85aMayg5LX130k'
                . 'Oth/XrTvaZCdWmFqpBnop1DEMYc+GQMYdbKDkDD3Fod/ew8GbCva7PPt2e+Ws99a69/QZk3EqeNw9RgEtN6gA9Yla4Oyly77'
                . 'QAn30BxFuCHskvXvNvbd4HhTs23B+KzjqGb/UabofNXfdcqTozQWJZy0nylx0GNIsaHLOtwhTaM46JabLEqxLe9r7ky8y4kD'
                . '9r+o5v1FYkIomnKRZxV24+g8veQFCWxWwjTiM8RoIprOjwmtE/hPMqWaxbH0cImwnhYX9Fws1z5zD/IXij2R7AmucDyJLRX3'
                . '4YRp7uVBkfhX1p+Kfira6JMFOMA01/9mfn6cZM5Hl2p7lMnqPf43h3jAnwMNcsk/iNlWrpaOq0V097/AR6hlnV7bYLdpa+u1'
                . 'VmtGp6m32TT1NbpJj2NaRVBW61VIXf72couo3q8HOkWhmXjjSqaiXCJ9EcJUYdEm8Ajr3JddYBdotylpLbREYEKjxcUbQEJi'
                . 'BrMbE4fgArjgU1FcNdCSgkQQL7XarPEVetmcCF4BLb7JtSLJQnvIik5oFJ+RXJjDWDhG2YNB3soucAnNFr9SxPOdfYOh80gK'
                . 'BGpr+/wMcADXrmUPUz7Auwl9j5aZSVujiK/FWTJARxTG7CaFhdjeGFgJ5ADwp1qG1PqU0Wyun1M1FUZq0Br51LQkrUj21ndD'
                . 'DM7hLE1PZW4T9MYlGbibOG2vQO8QooX2GCChoUu3cdT6eEZlTE3p62xIHnliQ5cIMfOmKpav84yX2QVTfVZU6/TadJqgkZn9'
                . 'q2IrZTzMBzaeNG9vfRKYhggDxDmV3RhXdv4f3cBxwllG3o1BSnkFjng5B5+hAvJkLt6O7FAJikXcpuOUZVE14tFoTPFGpdla'
                . 'pYLDco0uXWGfX/28q96qlJWFEVQ0IZXpEF8U7qd83MiTUkM0nvSpyzSB2R8Harp0/0+wYcSXkGjs1RakI+CFV9QMLalL0rhh'
                . 'NMLqqY/MEg4ahaSEKHRUiU6YteaohTW2mb+VIgV+lLU2qd3U3KQdq0varJkUGsZrxmoS55WmCGP6x6FZsjFvQKqwlrNXZxpE'
                . 'o74kkIQhHHSXZX4RKSX+4oPWaJ4Y8IB42sm6mOsBIWzyYqRiHwaQ4qp4r08ZqBzWDxgNtqBNF7SJMeO4IL4+6g0nUg4NPD3U'
                . 'Wl7rDmYFcmt7wMm1K1CM0RCcAa8bGQHOEPr7ItSVgndjDBHyJiOgUHIDCXkER8a5Q/yTIqvdbZLGxTLMXtyasVg1bjFRJOL5'
                . 'z7whSYUUohhiVKdTMBCfzNqfywMhrELZwjN0LEQCn5vg/K4832TIjfUB6bgXi6NIyCxwNTRSVQLZEf4HeChPhBgcQlZYTt1U'
                . '4MX5CGY3ec3vY+gSJTXTT4spTwYY4pi3MRCJNWaoaQlJB9zy5curWhvr3JqaaqVBHRbdoM4DBCnEreZFFTokXYWY3EmHeIYv'
                . 'JFufGBwIR7BYb8RNaIm4Z3DmOxSktMj3udKsjTkjjjpD8bVd9QrMklgesKUoNpLDw/qwk8aIXHvSYC9D2Q06dTWUV4YD0gTs'
                . 'CFhVUq1EvtOl1LcFtaqgwWiI1UlNjmolHFOjmuoy1/zgLutUs1paHlFrrBGvG4W9IbQt8GCmXHk/xBEprpnsxnBtN3CJq/Wr'
                . '+TGFAS2vVBo9Sbu40z/9xaEIjivH4YvNcyhPS8BeHmo0F1asoGylfA4UIqyJmv//L1rM5Y4VX6Gz4xTrD9bEYs0YlzPmctVk'
                . 'jlzguwXScwhEIneUTVc0lJ/3/0Wp6dC8uTqJybIG1Q4rzV6GUcPl7edv9w9tPvRXgbbDhfPWoT1vjdyfce2o6i/udoMZh7bx'
                . 'apdPu5jb5MTuIzm029mRerIK/V5WhBd0siNqFTs8QyCIscIwzApH3hLTKlwVsgqBhFnp8IpqAQ8IYe6z+ZwhWHRsD1qLdBnQ'
                . 'SuxFa5dB+rqFQIRdptNfB3XyV/bCHqXGK1pacG9sNS7c5euVyTK0lLw6hpBgz9qKVMzD0J1HuQdLKWB8OT9tpo/+t7c574NT'
                . 'SBXponYaEsVeuzlMwOqDJxndCX0J1F++8y95i1ZZ1bEWqPNW1tkRhKQlSk0yCUHI6aN6+z6zChOv6LPHsRH39sN2RKHyNY1s'
                . 'AoTwrTyamUfTATAkrUhOgSkC6QDE54vSNPPqP6oEJQ7XDx/xpjGIga5eKt9RavvlO8bi6O/ZEWDzTp+uHL/bvmhYe12wd7+o'
                . 'nWXF4IECfReF+Qw2m7sLfWosmh5ZMAakb2GEYWi2Fo1SOAT7WgjhYezOFMp0ff96P9QMiiQMn+Uvk4s0LdRYgIDlu2cJVZZ9'
                . 'ZmaBzvj84aWXZvpfLrP/EI5h99KIyPc1Rvtat5HVG5rqL70wMq4uZrmVRmPhXphdGFRr977uq2ndMtzWr8wUDYK4A5sVXRDz'
                . 'mS9sUzI3kBYqVOfkA8D/CigKXFLUaejEStDHR95c2NXZVZsSCg7DhA5NWHirLHhhCvhhLmGUrUX3Az29sFUEUQtD1mxd0IOa'
                . 'f7/hQB7SoM5oF4aMJ2Tak+x1MtEPUNbPIyDCfF/NGX1fP1AbhrCteqQvqGucV4qk+/NGw/Ma943VB+kVchGerzOdbaGHtksD'
                . 'IRqBDkv23uD7DQezNG7SmJEWPWrBpDfZ1yC59rnmitaATrbguLiogxfvyg04dyshGdcUIW0mpqwcLd2o0WEiR1rsMLdUdIAj'
                . 'q1WUSyptIkeF4clwip7Mke5X75oqzErNnlVyhKN0bv3winnHUWvMHnBV8LK4Q9QLeRN90OF6gpbXugG2VcmXbEXdS01hau/e'
                . 'YClZCM+IhyLQjDk5qBprKIkbgHAAAOzdG8Srb8IMQ0Ta9x8Iaa3NQoW/s9P1kBTK4NcsZnZHCnhkZLuc9fg+MhS6R0crrlpp'
                . '8JRy1/n+gq45YA0ryrNtRqJoaRF/JY5BUVEYFEOvlBTpwn3vUyLSi6MlxiIcjXnwUpFXBD7e1qeaf8r1g7+UMF/FxnaSIOch'
                . 'pw+XhAP7GQckD8LjuF7aXm1Oyf/VwvnX+I983LSdNv8K+/wqXrg93PtAZ+3UQ9lB+NVuMaTEZWDxOsOq+QZ9werjRbNAb5g/'
                . 'D2bODF92UOhZlsKOI0dud21B41HscP0q/rt25yYEDc2wQ+zTc7nIO+p9DNbBVMsQhO49552zUND/oEcZswsLYX5nwn7FQ/3J'
                . 'FQr39nFOAHwDMwqJQoG7R5mEz4rA+ojdVaB1GVLwQdmp0QolnwKqMlnNKPyX46qAuDCDH0rMyPPtWbcSBQGpQE6p4MC2g4T2'
                . 'fatzziRJiIcmujNA+6CB6s19810CeRibFPR/O2/ewVyrk8LlUcK+89Xl8CGowYCfA0KsTI4FkkyBaX7cgdWHEEkPR3Te4JC2'
                . 'orK7baQURLmLFdCFlx6wNbnr80G8aZCkKHaPZ41vm0OixLahomZHNPDjuyFQtqQCoy7UT5GahpJwxQwHZOn5fLo0xF+GlRDv'
                . 'KafMT5ueTPEOB8n8Q3S0AGr8HFxJqC5UW2pKRSqx6X6JEwJe5rd5Jv1BJH+a+dNKrs0ntzJ7nJCCNQ7eeO1fGcZoTz2KNPKM'
                . 'td2NoZSl2NU1fS1UYdOBu5l7VoYyOpoAp+EZa2asQ2nLlPp2TCx3yITt2CE20+cFjcjJ/ipI3BZNPAxmxkJ8Jc3tv+mATfNY'
                . 'Sqv7rc1e7UbZpcdCGWmFxXObrTLQPuz1scgAmjsda+Mw20RRvcs1vcsRiHdHrXOC5f7Bee6EOkj5n1xN7pxZIilf5IwOpwO6'
                . '/tp0H2yPdNltbQ6TekrcqubPmk0q/uM1rqc53Po+rUdRsdC7F3CuUSuCfkuZzm9UuGQw4CP9Tq+TT4+2TW2efWrAus1FxxZq'
                . 'S4jvRUv/kTY3xdsWffN3qUziC8rUJfGITHHQp4siPoPcByvkgbAURlNKe4RXj9JTaiyBugBURDmVP7k3ccrVZV5nncjqKJeL'
                . '/EDc8eLPCphGifzoJiK1sD8v7hxExujaNnrapm2Nzt4wMpsfwCuZS2gghP1+kQCPFkeHAHveyD9ilpIVezVAFsD7PAwBa9+d'
                . '8iR7AAhhedp5jM5cLOOTq15tYKsYX9tH/mLuy9a047P6YyEiTzs0rxTjMgG37ccuWMX8ys4a+UfMVqrqj1fxBPBbHjowPFXd'
                . 'yq8O6ruiPm1PTUWvwhHrtlvbHA5nx0yHz1kGC4MigzYaF0uL175CzHsqlkBgQz8NlQQtMobfLTfBCYEu7sifl4tEd8A0GIro'
                . 'Z6R9TVyZ6MfrSZKevGZWKTByvq5dmA5rZoZc2hk18V6Fo3p+1Dc34kF60+lu9XvLQAg7YdbPDK4QhDCTS6aeJSl+5/NdgVJ3'
                . '4Wp3CSLzcOg2wY8smH/+s5enmDy2vj3aY3f0lcdDCxaEKxLdPlOvJ6jpTAanCDa25+lrxOGy+7KEZgaYi01iID2N3xZ2ApRg'
                . 'LasQYyspgUJIvaZncXK2HQ0RsEitEIBi7NeeZ/zFZxozhyHMcGolx2AkiDNr6jT/P7xKX8Z0g02IISyKkkqTxUiCNPwyLYaw'
                . '5BOrljMYCXIQsr0o68ypwRyQVvT+8fkpCEswH/EE1ylBw4r271ydV0oO4LFIJEVCLMHaBbn4Gt5FqBhwRCNGQelZmSMcMRjC'
                . '4U7+5Fi1oIZHfJnTCf2AFc2HK8xJhELgIhh3GT0DY00InjWDeD4FP4in8FnTh/uk7P4j1Y+rBjjRAjg79gaKDbYcYxSOhLf/'
                . '6fGF07bhNy3Hb6NOjyd+WJDJnxgtys5Lx44TYoeprpChyqiZ4lDRM4ZQUmG013gKQrc/z2Rl65ceSBL4sXbYEWFUSQKhrrlO'
                . 'IH9AqS+APdRzq/HQB23bLVk/qrWCnrOKNx7aAs75mTzJDzGx4jc/hyP6EBFszBbw30W5Re7zwAUqXvqS//OYzd/+vhQRCevC'
                . 'Aj1zwRGPNIswEHeITHviI37RwU95AnXq29MtJIF0mZsupv48sdSXldMDN04E1VceU5IDwO73xjTVKb3FVmsv8IDjhDgg299/'
                . 'IEkQpDrtxoaNyoon8LQO/GaeTIYPLMHn+tQc3PP7ye5wy5AxIovnj8/eHhamMHvIsZQQR6qr1Gnq6cBQr+2Kr1It4CGsi9IL'
                . 'CqJAglxafZYu/gI4ydFdjrAV1BOr/8ynh+HylPRVt1zu32GalOql+qQ8+QWC5/uTEbpOUqe+QOq63GxVNjum/8QsIbW8sIqT'
                . 'F3WkdbF2Cb9oUw3bpZ3t+yhz31m+uwrEyL4UlI4jHVyshfrR8Cu1LoWLZWuDrlfAeT/x/Y3LyJSKgCbC6lhDst609XyLNFnx'
                . 'm2birbgly4EpvfFeR2dzdFjiQutKjsEhdRdmjBQJPVUca7TH7RgBuuHWsl51rELRkvQQI5GRjoAtODNKS2D3YGI2oaof10he'
                . 'T8D5yHCvpLr0YvKfmhNPOKDb65j8q1QfuBuzdGJyQcFkLIHTWW1qyEf/WhzR11XJkdKdF/4mULyLpos7VciU4qgULSkvjqPq'
                . '9UEgkpqbwppc364zeU6ctxrrP4y9EP/2oZ/eU9d0tfhnYMx0tbV+tb8k1MvSUG00PLaM2LjmGDYbufX2A3q0ROeOwuvXtpNH'
                . 'FBjD7n6LXr9i331o8xh4Zp8ydg76oczt4mFsAu/VCKlH3HjssI9sXBhEM3aagdGXY8zRZZs8nNPTbLas2RyP0x4Cjl03Lhlj'
                . 'c8RMFO20RQNyQpLjaHVMEn5FL7ZRHPdwR57g7hwhp5N/Yz1gWTab1bLSBmlol2yrTosS8Fui79B77o4ioTHeDYlTyLPZaa8O'
                . 'TP3YRPl/UyoKKAoMi40YHa+HOMKjoobw6PAXbkfQtKBEE6MOs8aZWjuGnnMeoA91OGJ+dRrwl4vzg2Jt9M6yREBNERptZcuL'
                . 'EejavXnXgQNt7IFsr0pzYO2htez449mEZbE+CT5g9S4/B4nxdMuJHu8cB9BRy7Yg0GS2INYQuGTp/Mv8wPme77Pcp+whnFrS'
                . 'UkxSnWazj0XLJ3D/+dtbP6qKlv5XDh0Pka4IzFwHheM29F1z43Etv6kNrlQyhovWnRidUb4mUqn9hcrpxi32pPPSHAbN3rFf'
                . 'uEDmh/tdVRJZX+Ow3wFE6C/4MDL05hIAj//HjnnoCRvgBWqFFqASdAFLcQ6uJxKSRmrJe0pLIVQHtZSqpB7xKJ43r4K3hLeX'
                . 'd59W0w30Fvot38yv5a/i3xBYBUZBrWCqoFJwXfBNqBX6hBnCBcIjIqXILxov2iR6KebEFeJ14rsSlaRGslJSJ+VJY6XjpbXS'
                . 'pwyf6cPMZnYwr2RIZpY5ZTNkZbKHcqa8O2m1/ClLYTPIu9m3CpGiC6VQ8VnZjbpH+YeKohpcWK66rKaqE4vmqw+rn2vEmqzi'
                . 'Is1pLaYkTDu4ZJF2t/amjqoL16WW1uu1+jG0WwaqoRd9neGF0WkczJhr3GV8ZaKYIk1DmKtMR0x/mAXmwax15uuWXHYndj77'
                . 'uJVlTeXs4XjcRO5o7jauyUaxdeAtth2yPbWX2pP4U/mXHYDD3zFUUO5469Q7FztvuWSuea7n7gJxlHiseJ/7d6+oZIrkujff'
                . 'e75uSG/KCuVK+SeKqOJ7ZZfynGoxXAAPwl+otert6utIB2QUTaBr0CfRt9HLGrEmpGnQrNSMa85qC7R6baV2hfZt7Y/aB7pA'
                . 'XbluVPeh7qQeoxfoU/pV+nH95/o7Br2hxrDW8LzhNyPFqDWGjH3GdcajxnsmpUllCpj6Tc+bvjTdNbNmvbndvMG8z/yh+bJF'
                . 'YglbZlvGLK9a/rMWWNnWGdb9NoKtwfaV3WAftR+2/+2g//24kDtCjimOtY79jmOOu84QZ6dztnOtc8L5m/OC876L4BK4VF+/'
                . 'd+tddpfflXDVufpcq1w7XS+4vnA9cpvd/e7N7lfdn7hPuP9wn/NgPVRPr+cTzzmv27vee8fn9JX7mnwjvkW+Nb4dvsO+U36s'
                . 'P+Zf7p/wPwyEAisCHwTBIDFYFESDyWBjcEZwJLg8uDd4IPh68O/gvRAppAjpQ85QY6grtDE0Hnot9H3oZOjv0OVwVlgU1oXr'
                . 'w13hDeEDLmA6BsiBEoD541W/A0RIEBGFiFGR0EVG0jxBn3inTfY8WgNRS9PQWvFNFWm0uaYFWJySxj259o+16a5jRXEvIul0'
                . 'rZWSPMtBdhPjw2V8A/pugfalCZ9onoUOLWli40AHfu95s4eK4r/9z4qfBJQX90gIxO6oLbIS9/KdbG+uoupUmtuhK5PPont9'
                . 'O/RX78SjLphss5mqcENPOt5xoeS4kIvLXO7QS1KXsXz5joM4pRYJtA5lHcmvBLIZy7r0cWo+HU8wllJOo4bVUlYprVxh7emE'
                . 'nyPF7e7sXzi3GGNySre0pJOnF/WylFWK6T+/jfNbSsubIF8QRL6CAZN+2Dq53/maruGBa9quV2rr6HFag2kVmOEcxcvuUk/T'
                . 'Gcs1VbbxeQ5S1dbvUwd+jQEKolfbseBXNVBQUa1OOSdH8MApVpo1c3sCv4j7TNeQWXReCofA0yUVYHHglfgkPkndPN7F1Arb'
                . 'bGihqsLkhckU5DIGltI/XIV7E2cAA9VGWIh/tx/O2M7WSt3A2hqSwe25wjF4ZwcXuAUrHUjga5WgqD9HjAmJCEWIkRiRV04n'
                . 'dDlvkIR5bKj76xRfdMRIfyxn/Ii2IV+F9L12Ng4kXLfPk85pG/Hd+lQVJkO1QrKAOaRlh9dsGUnkTvejJktbvX5+uFu3nXlD'
                . 'n/8X7TPWIJ1gzOQwVy+yLwDiIkgEzPHWyTCC1uVpLL1cY2drHNt6JomAZX+FFm0dXDB7JIQ65KKPVwIuJNflFM9lr2ZdJkTA'
                . 'sm9JgLYAu+/leLHkRtqpwdbBmkKqkuFLFLb79vnMinI9LXvcvVoNRtR+ZWNhuz+vtfKaLzHmqpX6FV70WbDMidplz0f1sgQf'
                . 'xW9w+U8+zB4Gl3OdX2dA1Fn36PFBzlTZ68D2jqzpuLP/aG3klUUqq1pbsjxnNBW4c3It+UlBdjLa7AxIeA3is169Vi+iR1mb'
                . 'sWaJImnUxPKZEWoUMwyrEfOanlDt2u0/4fhjvwxEHed2g8KE22Dffd8g8SyL4xjZakAzVDfE11/hRUVSLQ3PTdj6WfhyCrQu'
                . '1ormdHGhS/LtxmruQe8FItOTLq56YYopC8YDU/hpr4XEEmck5DI0Ply6OhqcIRoSXB1Ub95JMzUX9A4ECF90Kz+SjNHv6Oz0'
                . 'iMaQmxg/oMXpwWxO10uZ3yutni0g4ozRKjHeWSrEugrNYuP8rpz7BNrjQPDBjRYOY6QbRW5x+cDTsbBvy9K0bNC74dBYFPxO'
                . 'I6UNBY4k7LOWXxrgufnE9cMdn20ACN8UUREQO8hAmPGGzciYUZFL49zpUhXTf0ytXavRp3oJlrjKDiAxpk151Ndk1vXs8Aku'
                . 'pLpWpwZMACV8uKAhuTzUmz579vnSskLOi1hlvILD55bYmsX5N8z9zz/86DbZd/+uVJSraLjFiQZlFO/dAE8IkVgkC9UsAxoY'
                . 'Q9SAOEF99+Zd2/q2rZw+3ju+0h0xD38uGJ1CicTSrOJTVndrU8jCl7p/nriE+BVr6Ho+B8Ej8Q/MqhHiGevZGVkBxTYdMhHq'
                . 'PPrFj3+crPzV6pfzGhdJTrMnNoT0X7dtjN8HDqQ+uG7jE+SKWOwBppN4pS+yeYbver+nf4NtWcOAF816fKOYckEpswiz8HUa'
                . 'h2Ec+DGtjaLYD9YYq+TYbV2HGH/v+Ng1mweLOAz9EJMCKc1m5uubPtUTpOk2u/22ATfAJmiT/wTrd+lNfaAUblXBVzTV90i3'
                . '+4oWP8JnBrTJevfa6w/cLHtKkri2XmkSMDM473pxleEcgIdCYbWBdl/a8o2cQuqP/6yg9MS3otDUiqhnLMC1gVUVZnbeKikV'
                . 'z7KdtVJazx2h9C8hwaSvwrRe7j18+OQpCVonjG2EPjXLh9ZbAiLBgFx/TgL31W2Mr3XOHqHb1I58L5nm3XRtbbZEueyvB+Jp'
                . '+nVZAy6jWdD2M/fXlJW1P56pToRXczdDRYC2H20+m20vPLu4HMFet7tF4mi8JdKPPzJNYtHd1Lpq092bcypYwtyUMrYl1CAa'
                . 'e4sDXg0wrD724Gpks4wYOB7IFpMgzA6Hkylu5zwzSM1PUg9aXAhSSQUStA6xiV257ayOJXaonyOTNKYWO5ac7EzERUcrkskc'
                . 'oGaCk6UOIhXeSGElo5AQd6GnsTZEVQkPqSSBhlTQ0+KUrMUwJs1z/Ww0ei6StPFCzcwCfP1wB7aFbVhFh6krvp/hjIUSWI1Z'
                . 'to41hs8LC7gD751OVJj7AjWkRdZ5Z7/Bq+VxXM2P5r0A/2u2DzuvcRNNdO7c/LKyp3IF7rAXzLUb+fMWLc3sg9NNv3MDVnga'
                . 'qvGeR2Vc2mVu1oDrse+nJZcBc+WlrCN8thXPQDJ0KRsVC95gRMxxeJ3x9giQkHSeBsfZkI+lnTGulBRaq3NArv4y81DARFW1'
                . 'n9yCCh8pQAApvmGNyVmdyqj4cCZSPHOBlo5E/gcF76I0JAGdrDtA13h0VG6znBljBePKVUIolhJI6r/PTVjfwTTCAr4/lyWH'
                . 'ZToOvdHUasWXKHfaBS26Iw1YT+rH8QIhdSuA4yXL33ILXjKgWGubKlR1JuJ4gQ7blpFqsZ1vnCIgFt3WOfz8cVzlOl4O22EI'
                . 'oa5KWWTpcd8CGCJcU0YJloZAZdPzwUzsE4yNpslDIxc5SOOQGWObqiDw6MPpaPi1DwuddUmPpitqZ08myNERI9KJNapZcMXY'
                . 'w2VsitfPwy2WBKrhFSIYjUYBmQnZBaPiax4B4dr0xxhhwpSNv/bmhguCO762ibG/F6VShOIq3/6QEhlrEcjPDcKpiYPJYKWk'
                . 'g0DUx3IzVzaGuNvvTJVZSeBBHfEoHBDtY2IWMPxWJ1t3nsaEadarQoLnO0nIfQeMQzEbMpdABJydM5KfO56bwrVs3ZQBuu+M'
                . 'n/cHu35fFENKcDW5TexSlTQg9s/MUc+tpRkUYq2yLSZOYMC2GyFE3MXNZZdyMlIxt5dFe8GJYWXZpebcsTEwzOaOpxZvBTA/'
                . 'jcRBPAdhrFE/8LY3DiHWS+m+bx5/8q5/aZcvwLQ/sS3wNqdJIIKmOaeP3RtIB6oD/m1+Xzzka3GC6VAfS9h9w/lSbX15y5Lj'
                . 'BSEUDLpP1H3BkixNx86nu7lgXgO208v/rp7/YUf2ED0cXX0aJJH5ERmuVqgffBh7EqzhXwf+mJjfkfXRP2N8R5+6N9DeV9OV'
                . 'bf40visW/hM8Ft+7O1mLB0jRt4z5yrE5jRzbswykckr541WjVdFa9AkC9+0ovttefgHn1TI0MwyGI+pi+hXNb36stbIErHKe'
                . 'JLPnp8Z9c84KVdgHA9Sr9YfT8r3y+5yye/gRjd6r6pq6YPGxRzmpi8sIDZ4nk3/sZ6s+TinjFPiaJeHt9SP+h7G3LNI8QaLi'
                . '5LQxerv9TwAYCOLyE/hIT74c6vxZcV9cwK0/JdPuffhouleIzETTVV5cdpZsyf/5v9ttXthXqSG9s+9u/bZbXAZX35pC1QDC'
                . 'hRE/XWJyi70He1EtlUM1u9+PFS67yU416psRjM5cNKsf1p6z3bInHpTWUsdZUuSQ4B61zFRQ+TXNyquoF8mbUDL6VekLH7a1'
                . 'imcWQd+AZkxsRHHlDM12KMlMZdn6m8s3ZoYTx2zKtZgpjmIzNbeeLgZqKGkRq2Lm+KiitPyTwjds0OIW6eYsXBevklJ50cYd'
                . 'cr7sfRZ7Y8oPbrr46/gl+Z4+7vU+FZmGe/nWBMJs8nc/SGcFyZ5QGmkSeUNTqwBVfZSIFd0UJWV567XRT/ye7JRK8hnEKC0V'
                . 'm+ST/+j1bkV8gLukPzy4++LEAHaR2Ulcvl2ZJsSYZiroyZqYZgznUrTZqAToiFViMk8NyNMHhzfe1I9mOexTZbsiiwgu36qM'
                . '4AYTVWbZmJUU9dandeUiI91s0gWTdRKiUSroNfPo5MS1LSxXf+IfYqQCwA2xM/lc3ZCKYBzTgEVz+brH3oMmR6MspcjIwUdc'
                . 'a+klxw5cmmoeQ0vT5qCNUR4dAxmfBRQZXZ9PUnLWv6DWyDuE2mwcz8Ysm3WB62ZPotOYV2xswVHANur3kzZNISFRbKZu7Apb'
                . '5ZST577mcrWhzxOFbmKzQUkfd9Ww07BDjvpDsGoO6+omMGaSY7qutx7Rb550mPaDQfrsGsNcK63rYbuTnQYrVFrRGmVps5E1'
                . 'TYufM6kvF6SmE8ehL/Nv0dVnefcdwU68AHKz+uaxj1UI1fJPh60XoR0Ol4dhI7VWhu3ME+OHBnO1IMoSojta0SRf9D3DTdeu'
                . 'j3CISgli7csrNhhMMvP+Xz6CukYFynbDGyM+o4K+gfM8q5sO16tm3Di7a9YuCp0Pir5TwbzfVLve0fm9UXU0X9p++JJdvVSx'
                . 'MkHpJSNmcNNHRb8ByWLpBB+l9sVKswmYCf+5V++OcL9l4w0Elacjr0rYOH8K8hMaqsJ71OK/rQfNxBoTE1qqyuev8baylWoz'
                . 'WhshieMJi6ii7q9nS30Uix57zNb3+E7v3fVGUyzcFAaFhUF1ofXOiUvrxxRzSPAhHmjd1kJCIOLsJZyv0m3972sVE18PfHev'
                . 'D2SPjMEX08qLV3fxXwnRvGGV8K1WBjUBoKWf+rVfPYjtrsboQ/ClYFmIh2ukHMePn8lhgxUeJqKZC9tT3QzjVlIIa4GG63jN'
                . 'O/K+yGaaxkADGFqAekuwi4mZtr9OCS66prQwo2COdwLJTJoDYWkybVUtwJZLpeVskleaZMn55tbXVFbVIe9NH3pbfVHf3NTh'
                . 'KpTt/qfbmc8FeTi6bLUCIM5qXQBuGRZcuNlT3HEERO85+FS9z7ROstmsPMwZlN81C7nY9gmUfLij6k6GLdx6iePpq1SXeZ67'
                . 'suTIhVzTt8W9gdRgNk42Rqa7lPI1gEeVB/d5s2oEZadPsys7lRbwDraRwjHoxpZKlORN1rUioqSOyzyzvGBGJ61muSpVyejY'
                . 'R7HQj0ZxsdQu6yjt+6ZAwSlD6LuO2i2xjDClZBJqudPRtd13AXjTCBSYo61esbSqBSMeO16PNkmwiOc5ly9+mcgUAQjxHGt9'
                . 'EZAspM8wQWKHCOMi90lc1juJURI4YQiUshPTxMMDCulnluOHqYFZpIzR08pOarMNQ3UFp6P2SZgB959pHbjUmDnadduZbnRt'
                . 'Qp3RkzD9ZWVsOls0gDgcVaVWxHVodAl3e5IQxdRmrl5cn7uIyyi2RsnFzEdxLB95rXEjUbZBP9Khbpp2TYzXLN03ithtaeFC'
                . '9//mW1Y3fWTTVD641rCc0oV51QiEVIdCjvnKroRUhUgZiKikAQxplxuK6E0Mamnm3JallLPCnabXiBp4et/jkfeo+hi4rKlx'
                . 'a0yBaeJ+a06XoKQFVZ2lmJbv5FGDw/rfjt1VcwFdZI+D0zYgZa78x+5HEmLMwTqkwtUH95DihY4MRCfVUgUOeDMvChGRzVpN'
                . 'o7d73BR5cJSZ0hakVIF5nDuCm9MoVhtr0o67MUff4HwXWSUvuL7vxpcoEsBj8kFI5ZWIPSufWjw3o8GbgdWZMeU6SQAvmvMm'
                . 'wa2oXFL3Jp0RxOo6WKCLsMsKoZdWQZpAxb4RmCWUdfg2uvH8NiiC5BwjIdahkMZ05S4fIiQWw5Nkr3/zhYISHG+DdbDvwsdh'
                . 'VucFWfstm3G6DbZgOI5DOWThoQ/u0IREZtYBEXQTTEiTdUOIzlDHp/5FBw/OYCVZUXQ8wwU3tUfxW7iP24zg1igj5awih8ZE'
                . 'dc7ziubMufQYJlXOXxDzDpd7Fjqkz192DRE3F1JrRO9um7hdjKORK2eCwIPDqPRyB29KrFkLm4cxws2F2FJsxYK9yzEvLDCL'
                . 'N9/6hnP4tK8VHW4Qma5CISEIeoOGMxavG1sIaoleaaj4n00cvN9qksnY3NusjGN7HjaMDNupuvBwrgO84I5MrlY2iB1bdY9j'
                . 'koZp1CAnw5HpNst56eyxhFKCveIIc8A8Cm+q0DBX8biX5O1AZpZBCRxnzIX7bOJ4nG3dVZ6ZPIafIYUa3K2kGuCrpubShwpN'
                . 'wIXgOQoGd+l64WuyNO06jCVP5XmvExxe8CREqsl4sojk72r5AoCpG/uAGXya7gNB2jbFzWEZ1Xw3xv6Jt9XrAYDgRQHladkP'
                . 'Ury83zmf7OXqhg9LdY72C9HtTqjd3f9TpZn34MLx5u6XJidxLlbRFV4YddmkYS4W91pKeeZKkptjLlSKWojRLMrk/32bKS74'
                . 'KY1PUZG1LUtQnQJNcx43DPFmqFbmPS271e1OmLkb/9cIK4sB7CardpxJmHIguYiIgO3+kuaj2ZqLKRjPEegaSNnHBlJ8DNxB'
                . 'MUw4hPMXJj8pq+MZ7yXIuM9MIGCZHQSfYqyDEvMD9gxnAUtSTZgNvja6SaqdcgC4e3kOu+ZEwz6WOmTBhVPAfLQpRLCMpRyZ'
                . 'OuxIMriaCsAy6hx/1RlrcGh4y/hWe4eyKgKc8Cy0vJZnW17RmVEEzUq/hLt6CDlgX8ZM5pGPeuM4HA3PBV4QJS4HzJ67wHTt'
                . 'djPD/cCS2ct5K3sbIPc0CEptaUCr8bkqlF2+5ALepIJDyb1+RM2Po/6CT0GYpUlMY2DCqqYIWFwmldyiSaH6bRPzEvfT+jyh'
                . '0niG6vx6i5ize5EwKEqcXcRZ4dg9mWufcJcLHQRKQcmDOV2d4rUQQW/7+FhuWUphok8pGQNCVp7tayLK4RtPDR74WhTIVTHm'
                . 'bvJM9vX1ZlTDS3gZRunEL4Qkfk4XatGp42Nw0iOsDDlNGMXI0iaDc0Yn+kXwIPH+DLPiHgadoVJPPCCH22P3yrywwS8cvM9p'
                . 'uhLnY90155I3Yr6EQ3evX0XJSpOBUw/mKI/cTJbt1Pd70Tfx3ISjUuRJ8DGbr+WZJo8G2pDT52eUibiMzlVYBhZ5xnXSToDS'
                . 'hEvCKMlZlrP2Sxqgh0WlQ+g5SwknaB1GyV+Ig+R6RbYM5VreamorvDwmvY1MqaxiZLueDw7QWNa0WUfmrz1h83VHFwhV4sXN'
                . 'g+XB+SAoqPBos5yqRfRCqgopDDSxFp7jO7aaAz4KUBHCn2jbfs98twvLM87jz0wcveFZ3dLPrJmc02QsZRSBNR+aHjSIIhUz'
                . 'jQmXkKFfn454QxpzeLLFGJem1uWIr6MK43clzAU0h1WjIyQYU0jQ45A0ylwJSG1lpxKNR6wXVS1lXU492HdvS8C45nRKTSvn'
                . 'lnktReu1ghuk2g+0T1a5VFW6BmoGYwfnujoqKwrpZ6pTib7xNuKkPl5WtkOK7w8/ChY+djpXgoG2DhJ7yXKVH6wSH0OKF9c2'
                . 'xGi6ZdoRXc3+EJcjYHp6uQEO52Z0uXkZE0CvDK4NJ5w/NC2hciEGZpJum34chvF08+TIYFXdOtf7XN1h3morCyEFQUk5ZN51'
                . 'w+rjS7eEUIM/hNB9FeGm9wf+iMaXr3ATmD/F5Kt744crPgYojNpoyFTud6Ol5KkhnY/hQdXaVc11eOU7hKCXdHJi2sX9rZC9'
                . 't48NOUwAOIcDyn1IA+71snrhHiaLV1nz92qfetgMl2hdvQTcy2beROiaqtYuzVWVraA9A7UsEbp5EP7Ou42El07oPSYPt5pz'
                . '+rm46UPeNKgqnILJ7PfPeHf+S5AfZWkmgXzSMGv2xGznsiwBqC1NrliT2Ca5RzOVc17IPuJx362LezguL/3+mlmV9tOkVAQl'
                . 'tcypbGrJuqlXU50kKSNTCqPSjW0ZizSemutZNsJRXg6Zp37Q/e9ZP4ZqEE8fge1pojfDIXifzsyzwhkxnxi64HEzDFtZYiGU'
                . 'SmTfCtwKqHLfxHvJedypgWYuDadB4+KeHE4PZ+XAf3GqZYJBLFMj2aQXu4xRZgJl3dZAvzWC0blUuZRWcTe0rOarFMdY1r9q'
                . 'vj5V6LNLSC6YayJQY8tM8kKKySi1j26cZzqe5OPQkTfYNYFvP71sUqsvNVmfbEgjGAIgjn+hN65W5jDIJChKQ+o1vIDzlE0t'
                . 'cokTllvS6TQssjHFQQnvBCK16ZBzze5u/sqbdWso0r7V7ms3hCwMVEqjFQJRuZ/9CRTFS80YC9Ppp4PWh9zlRl9rlkqFLkD9'
                . 'XD2/vNz792rV2iky79p93N18/nNPEvPwIWOx5qMU85qpTBbDqNApPArWADCzrXIfobYz617YBKlDpKxXAiRGXW70tmYjV6Md'
                . 'fIAvjkx55roeFJVQqWmXV3lsJlRbn1w941Jw3zoU2kIAy8jbfU6+VzbMluIY+WOgedb1JBo2iIH6K++CT5JnkrUQLonUTZCa'
                . 'KkOG+8iX+9BvmSqJcacGAI4s9NnqRuyoHa5yt0Gzq806n6nDbsOqhSQpl1tSIb+0tSJzCNjjhrxnNsd0jYmLqA37qap/822r'
                . 'Td84xBA3qS2lK9/5D0Dv0UZnJV2QaqTgcl5C3JqfuGYE1nEL0d3Es0nkdU1E+xpuapOt1SkCJX44eQzPHoGjHznpwFiaTush'
                . 'D/pcr3MnOXajqVWUZed53DMJq8sN9DHu8AeBgskR7Z3/fni7KtPQm+mvIwGiOSdGLNQF/cr8xdzhRYEe90IVFGUk9ByXaOeu'
                . 'ffqfjsmjheiWIhNkcSFXD2FCAO8hBHi13fyraTkom7Up94PySmvJPj4tBr6RNJtSUyqFYFlVZ0dTI523HPqpkORKwLwE6Bts'
                . 'P17LsFxcIiKYtF0UG8LZ2IV04YllgHzbzVR3CatphA6pgTiGiT5Gk97fk8YX9F9HfO63saWV+fmyWS74hQ/Bq/28/JVofNgE'
                . 'eZti1h/NHnkn5KZrT0/edOxdPrjw3klerMo4uSxiaxZb0mk0YssqpWuUEBAtltJ6a3GPvsT5erx8m9G2fehNp+ub98uq+XT4'
                . 'ASHJmtuBseaQ+0QA6TY6RfeJcwmHNOHfRMlq7M8kwXSTzc+myGGmrXNGszT56l3u7U3qm5u+9ltKJ43JohXLpnq0VdOZhWnN'
                . 'xxkMxN60pVa20CulPwtZ0JnSw6rTt19GdtMks8KrHkJlclh9nFM8PlAzxrquq0qTJLdamDemQIJOmbTlohLdhy0N/QrCY8bX'
                . 'siE/m9FkuJq84Pj8kTHPX1ZpjCttUnwp3yborEpcEBKuaBevPw19UhPfJEkgTtDOXVN6dP/0lVcMo0qUmYy+VG6Cy3RoumQF'
                . 'iytq1lYoJQqcPCW0V0zHiJD1hFVoxvdGYWxDrmfWR5FSiZ8nBuGrenVgHeXkc0lr6Rdv2sr4MI9vnH+eyWod6giB7rjPv3DO'
                . 'Vm1UvW27ILOuN4JN/qR6kfLjTdd5TiiXbjnagS4t6qw4gKUjX+8tA0uQ7BlJ3RaV1solctn2yGURGRqnbg+Os+cUvnjGhvw3'
                . '4DdQWu1A6wkJ80pQlNupOQK5ifYilE7mIlpw0TfuAzhhiC+lpo0ZePfUD+W2xXJw1vR16X1pCoo9ENIQBSx8SqX5h2l78JYP'
                . '0V8bYluwbuY9g8B/nl+DfbvdZzlu1VTYw/mozfOZcel67sHGZ6x9D09nsvsg/CaEj/tOl7Hz4+zgkQ1FRW8d8kNjM+f9R+t3'
                . 'HIF9bwK2zwguhqanexzhpgdmQYE+1b0qVnDbxFkeWHcFnksSvjeXZmTCcYzPCJZHSyr7iM26JTuCbPZEXnI8+b6u4PZumt4O'
                . 'xfsckXsjRe8w6NIFMAG7yQ07Wv6FweCtn51cl2nAOnHkhLCfkCupchs2YrPpVfCwji4rKSV8b8kGVWh8CtysJhbfdO+GX6lM'
                . 'c9LlqCmimuNEd5vbtrTG2HKSp1UsvRVpqqfH2k8fjc1cGndC8YWHvFy1mGy6ioV9+bJpLnZSbe1+V1gnu2Weu/B/ELKkLE/C'
                . 'HnVBLl3eRksM7/iOJnJdfMS2NHTt2mraL0e8yL9PB64xubB+imuEjDK61Gxzfoo6jm2M7SfVVYHnTLJDz+ZwIU2UidcLpufK'
                . '/A6ypQJLTlm1Z1In++n8PZB0vMKKfD+983g4W4KKqOfprHSVbNuaoRt/OB0ZD10VUNNXNJWR0kyFmKMI1P1CbxHx6IQHGizD'
                . 'A47NJGPmzmaU/OCYEAFL+Ig9q8Smb1/6jY0ic95E/uR/oqoRaFxi5sD7jBo+zuqmKqSv/Dgr6IGC51a/lQXrkHhP+qTWKrF7'
                . 'NZ9dtiwTdpPhgLAydxmBR49qU+NmSz1PLvS5VK3zrWxv1Zdz0uVQFHPNTvzx5w0XpOBcpKgDGJ4xMyLR/83lfvqA03XHhenH'
                . 'Cvg3Ur8+FgHL936yBGcpHPmHL1bLW1hmyLct82KPVeq6w59tMEyeasK0XzisbxlfIyQrSOrXGCLOrdjvEcliqJ/ei84cTpyU'
                . 'gOfp5FWSkfSRssZuNWxHxqGyBrm+1Fme53wq+EjA065+aWatkp7if0OeuXuVTq9y9Hn8MO4Um3ycS3Souzy7BIHpcABZPc1Z'
                . 'AqRcLdFvXN5qBp+Dq7iu/5pRbtfxFP7lpI/B8RcgqafJ0HiiTu1Vi8pamh9K2qvVGr98tnb2jLuw+iMaJBoFM6VHpor0vpy3'
                . 'glN/nuNhuMWvv3073x7eqMn3f521AU3o7DJlNgrFiyrMwHBUwyW3JMV6t8a/Wi9Os5ZKIaw+fjsJihonpaEHFjVPF1OVMZ0d'
                . '6t8nRkWzlaWKZUi5nrFJKFmg804gf9BOV36ZFFe3RznvFy97LcfwfJ6uA748w+pDJ68gJHU4BWj9gBviWzwZsg0SUlrfDF5W'
                . '+Um5B5zXp8y3Mb12poJoiOo/N+OKV5ALRvhAJPMnF8zwUEVLle2n8pVdZ3axuoM7lZGeGIKQhVHma+k92JHDHIi/HAapFjwN'
                . 'zpltSYgiMhUZpkhOSHURpRppG78pqm2K8Fpe+EVcTxqrKR1MCcL4Zu9SSKVb0odHXTssQWnDPJSYrjajqIFRYzRKH/vQySIl'
                . 'ZUT6XgPRV63plSo3h0XpkLybeidhTFNs48UFWV5Ul6HOSCO+zrLfTUZ66dP1JsyJ30Rw/l0ssg+1erDf+JDz33qIWCZEfCKZ'
                . 'OANrN1b5cA+ZgpB5Rs+i7bOoq5ESZKHKDXihpSzoEB0Fk3cN16dsfx0A4GiJIRaCILa4J2TmPMb/0v2kgt+HSPDemXmjsCe8'
                . 'EptKfjN6Z8y5gv84mK2I+lBGBvBS0YkiMT7hWTglrjHAo/ol+W4w5O0wofXEoRFGMDBMdtJBbhuzM9V7SlTGL0ThlK/Vsuy6'
                . 'wDvhjV9iKAAndSiU2MzgwSSimIRW2zpYWA6L+9+H+d8wpX4QIKHlpNQ0/Zozb37n3iH7M1rwiO+oOlt67l2mkamubI5onkqw'
                . 'inaBM71epRk4YefRcOqRMEiBy9SOCHWXz81RhowIllJA12R7ObnrgQyT1MrGkjPpjHg5dfbStFHiYnbMoJRZj3Qbei2QGYTY'
                . 'JcGpkB7+R08mCJWTwClGull9wpNPdS9rb7lPu4y8R8jVoW2RK+qN0t7k2VcOaxEoPDkIFq3ycO2LPi1cwdzysaaHu0pGhCD7'
                . 'BldVIcjVWQJNTTZZpOAf2ZxSZPBi4nEMk9F0kh8/pgEIvMOy1oSLsI6sij+p4avrV/7O36gF8PjGSL8TUyjxa0NyfiX+9J9F'
                . '/ztAViblgWmt+iP7Yz8MZj3aNRfh7tQhRmibyi2z3T7/z3F5JDpuETeZ7Alf27h505hBT25vn97cPP0BETylCuRxM155vr2I'
                . 'uSvbgGF8YE4BEhebuiWujMsL9EzGkJuGXofdRS+f0VKZyGrc+5UMutC8uoXtJmLeHDxJOSkHtksMK2CwnzCmsu9Vg7XT5C6E'
                . 'Egykc7I3VPc6CBje08N/dgTDQf9lDzorpqKmVGXkILx7V9CaMHOS02RP+ez18tKnkUf99MtHIHUkhZHFXVBD0eCNrbvFNd0r'
                . 'OlQHwjj6yKkWWAhT6+LpCu1yyF910xqiKpt+M46R1Ao7LUNULBuZOKd432x9YC9VKGJUC5f+8wUfquzupDFv787jx9dgcPh/'
                . 'PwiwzGYBiijcIWWRUXVHX73z7P4dfXI+m7gLlPv/BzEWSW9pWmqeZVpUmDSKhkLYzcnUoEm+02Tmh0Qo02QcZwlTA4/T1Fp/'
                . 'e7H61C4FUy+94hA6hFtBD9bbuB46Hc+1ksNWaB3FoSQuKSYD1/oWf7nS3GtDGvI8I9fcDVza4z7a0E7q5vRwYLjVOZ5lweuq'
                . 'RevB2nGmISawj/jCmF2V+Bqjlqq8h+dzHIZ1i3W7JKaPBCElQaSD1P2lECWG3LaKoCAFZc9Sv66a2EKmVpEmbsdQLVhOZhMn'
                . 'nqbxCdhJdTDMqSYm8ODi+lVy57fjOYnhIkQorJ64ui1XfvVpj8sn03De3bFtfKW/aicxc+SWzhemu2N7DpVnx97dAb8gCne4'
                . 'pV12R3B3xKblG/v1sd1R4S4HMa4Th8rat4wnrPYwx/5BVK/zREq0Izo2SnStfuWEEE1OInjTdiQW/LTXL3pJWGGdvUlRyqFX'
                . 'U0OBaPeAEjFY/mGcMWXwxxbfBu6LSbRnJ00Kj2IkCIroLVlT61lKIJThzkbP9j46g8O91FiLpjOF9pYcxfmaEXtwTbrQNvg9'
                . 'Fjk3mPN9T0ruCB0JbBbUzoeb3Ym02r0TDnelQk/qpIe29bhgTFWYUDLZyafDa+ta0KFMEW3oUMnCxnsWBA9SCBjogGJdH8lC'
                . '2PlZZxwOahxlhK0aBLzSBXyo2IYEggfp/cP5RcbAByfaLyIR5O0w0pP+QpdTJYvif911zzmMhBjXqN0ai5nU+jCL3XAOfQ0k'
                . 'FB52cbx3IKX5+bEBOEJsQkSCKBG79Wl4hOGp33wEONXsaOS0OVC/rDXro11PfQZOS02BuE2NKOc+eA2pHrArYS+m1eX/FgDM'
                . 'aGuuD7bM8K1VfgMdOykdKc36I23DJNkwABKVhaadd3xLuCfWkNG9RzvIMo9slndl6lKViWgU0oTYjF8nwD5I6EfuVvMr3XFg'
                . '7l+PGbWLc8PYcz0MQhNLqVCEAvH2xpTtQr9TEZQWsgM4cTaubt0ewEHyKFJFUHbF5WCIgZW0uXLge4BxT4Ll0Ojkjrb2bhRH'
                . 'P4Jt1QPzdxGbNChRC57BFYkVtENb/zXLHIrpWVsGb8GFhdlPJjO5qHUuUVm5E1JwhiqAhuYqMh70aGFLRNjiIB001oBQS3TS'
                . 'AA15DxxCxUM6hFYx1s4uAEyQt2BK7VffNObMzJNRmbXnNWGCSkDIWywoA2ALeKBUUH6nFttbTu5oDFyLIlR08uEY23aU1SPk'
                . 'CrkVkcyQtU9geGDqty2FF+PMxbzEDey61k1ZiRw0LAlV0S1MU4QmfyTnUpe52JZwFybHrVmQUpcnm0IrW/3S0TUSXVUa2YNK'
                . 'm038RcCR3MXCzEV7eAVMYmaz3khAc1/OYyxm6R7ZO9WxXAWz2efLGWqOij9RFyyk9R1AK5Ro9CFKL/0UJdeSpk9QK3F3iizG'
                . 'EJtxQCffPGPMG6GNxjpvKtl6DDShqmoVjEXqUIKH7j2xDJdV6KUGFYR27Y6gFmWKDRqMIAmN9Mu5VsqLjWnLW+lySlErm9pY'
                . 'cbqReNM8AcIRe8qidEvC7I8DbLCgUvC5o5Mp4llZTxC+Ld9WqVDpDp5ahIiZHTCJJY4MmcFDU7R98I3BiXQvJDioJuZeggLJ'
                . 'HPPmKp4tL9jDVAoThEgWOEIFXUbC7xieirlR8k2LtvJwtwt+kKYHyVAmumtHxd4MAFpxPD3DRRtf3fcVBwejjct14nueW1Z7'
                . '/Iixt7d2ITInryCl4RSCOBqYCeoF3WxPkTALMQTHl0GAd5kYuU7szUFBBPmoj+zcKuzSFQRSlCBJZy2lJIWeuV0GqjLQhj1v'
                . '3/GPNCtV9Wg7NlZyvh6Z7Hh+uywgWk49GyNvhIa4gcQpGd/Obu9CltzpkmnPqSzXUrmYrhtGmcqZFEDvGY3AxnpkQhQoLrxx'
                . '1ng0A7gjcRAsrS0tna3BHPSFmtPTtUCdeI/Gu3qUF1BhE98qGa7xEXTrJYZrv6OhpnsHrmkriGjHiPCoEXj1DEXtNdD/dxlC'
                . 'AGW7gLFnObgOWiSbdEUzot3cVStrnJ/zyMjn4ThRtS44BGzaXEUyvzM2u5OG+c+NkD4tawK4bVH4z5kkuNh41Pqbmv3nf+P7'
                . 'pEz2iOK3Pkwmvi1UXLazrOyn5fKPSBim/UJeeSFW8z6fHsyG4ZBKM9p1+eQAhfZJBB4BDynCdHJJ4JRJViv7kuqyJNb+VgDc'
                . 'a926wHSGbxZdLU0vioxs5UmU031LgKIo4TUuNtvB/+VVXJX5jJjbTHwhFHZHkTcs1CLl4nqwUomLz5SwWXfSpQwQhHLaCYuC'
                . 'NCqhULHDw27b7iJc8VftyCggn8imjfDYFcuCizn5fYvIVAF78pfiOyUIyppUMmTiHU8y8uyAcPcVQVC31FZu2pLkUHUBUJhR'
                . '9A9G1mWwHP/1QjYnfgh+byQAUY2Zm1IIVYh9Z9aR0JOkAzE3bM0pNxb8HaYHue5J4wAibjEsoZxc8ebuqGIFN45le21Y7dDb'
                . 'TZAf+jVqEu+YmbmMMHgnJ66e6x/4Q5AvVPwED15JEcONxXD7vICF7vtTcj3oElfRjVsqJTPBiOD+dH58KqgbomTlXmBzZTjS'
                . '+YxalmaxjbF2Bcw27GWcafKsyWk65T/DBV0zp3gw00prS0VknB6mie7RlmqdmeBCqLI7nbYANlh/9d9gDqucCdOhF7lH8hbM'
                . 't7KCHFnlkwhgh6UZL5xvb968/uHD/0bj/76hm2/i0PxmshIz0P07XtK1C0pG2Hgg4EIZ5w4qUDdtyodlyteS23RwnBMGHGX5'
                . '3jZxsFO157Pbt1ltgIh+p6HSga8LqLELVcj/Uc9GH5xeUBksLeHgQ925g2crQLInARr077xalnzlqT67smrqosBQilJJzGih'
                . '9tK512DkuUCWW2HpBx/ZBf/HrayVQJPWFtNdEXQaecnkNcOZVkXvQ+oNPrpGvR9qFM+K+p7SLHxUxSK4Kx+li8GcTg3mQtvE'
                . '2FiQsTJr4FqhY1l0inrhcrWOgOoXf3LcZYRCOxXXH9pXyEh8msEDvZ/qKpEE3zw/Hq+6O5j+xRwRLMFPUASocBu7cHx0+/Qd'
                . '3WQTo/r4CwdxmvT7eJcmcdXsLo+G0+k88eLp+46ZrG+NR4Lg91R30scm1P3+0PtiiVCKej2c+OBXgP3sckXJIANmlF6zTQ/d'
                . 'xdRh9aZWmUSi4uqFtkqp2Z+HXXFXm7nUjlNPJlghdw+a7fxfElydSjKHu73GxHxRsUT6fE6bwYKoC/iIB2tUkV/qSC9Xwzrn'
                . '/4Tl6PjpnsPVWKqYpChYfjkVd8Novh27bjyert40OU9eu725xKODMrzta2vjveZF1ndQPHvl1sSPnSe0S2MA30YVitPWEzEg'
                . 'eVmvyXFaApxtHHjBAigsOHpJp9ijqJPemaMExoTWx8aRz9GbEDwEEEnwxqginsZRoWMeddI3TQJPnOj1+FSvrDFFBDJfDKFt'
                . 'h9YmxV/Ear7QTq/GooOrgsBUZe8gD2mkRDi8RNnqokoU7LW+oTz3GZFQ1lSbIERzcom5fDCyFXl2vSE1p7qqO/matsaJVVOv'
                . 'QWDBh/08XkMg6YJwB9MDooRPG1QI8ahRh9zX8SDRC378c3X+LckAoTBkuvLOGK7qgdNxyunU+LipNNKoQ0Z7aSnAkG1BTAdg'
                . 'QjwpFQqCFWFMjGz34IIaufta+0V/wTE0Mu4j1ZpM0LuN0/CywSXNlX/NTmFuGocd7dQzPLDQbfml06VpmtYp33dm1xfhWR4I'
                . 'dxGXcU10d+8MF9lfIvUoJMMxjdTyAKwxYbcFaxz0Df9kjbsL+HvIf/CyQ5YOsHorTRgrRnqD0xPVIQarJGBQik3TdDBLh2Wv'
                . 'baOZcdsc9t5r38UFjZ1N8NgKCV5LNKFXS2LC13Jc4qKzJJieorRMyBmOLVkseRdRC57LNCttWvl/fbZAVcH2emiPKC5jd0KX'
                . 'CYIFA86gARn1ZVKHH78St3cQz8e5XScELhkIvRlB2bz3taVjAhQuXMfeAJybxgWRSBOhpUpeItkzPYiKcyZRwUwVyFe7ZAcc'
                . 'wVPgirG6xN/3mVd4iE6AAMQ9aU1TntH5HPTc5br5pFw6FJW1z7+kbKgWT6PknHs3WXo1bKGwPE6FenDeD0PvffB++RHLYOhA'
                . 'jZswy80sJzgnR303Z2JdR+/Fmubpih7hYkreJC3AJMhJjkc+y5Ofts+Yqd3qHYK7vXF2bgSt0TmjbVYcjFUZKcyqckZbjHsu'
                . 'hdNp7NOcV7dDf6dTLHzn9PVIQ2A7mwPdfZCspQI4ji+7S/1ksIuFOCD3uuk14FZg1+JKunCI24uJEKHeJR1MpKQEhQhj2WRD'
                . '60anohCJMlp9DBiY3tuZGaqSuyTqHZNAFjl7HxJtia+rYSasHgAWrEiikzTCtBQQNOlCAoKD/OXkk8M0Q+PqwuyquN2dn5Vr'
                . 'fAqPjDm85XZFNr7T+FiItUEpj/wDPn3WGfcN4i4D3PH7nAbo3yGDKyuFKL1pbcqv3vZpYUu4cVfgBHwO1fa1LfeSt7517m/T'
                . '+PhShBAe8l4X4T/UztLtzc1cmAi/9WiQdPoi50T35hL1loK2zpGQM3gYITZ+/fia2WKgl1gg1jggrPymVznldC8CoY3SqBsx'
                . 'sVUFJ/5F/zr8GDuI/+pUsVb8t4E0Vg1kmPBh+nL+xzsK5f1SDQzLaCpIvmvH8XR9/fGK+xRqHcNy8Qr74wm9NHKOmiurw3bV'
                . '1k1W9udiQkSPaDbEuot0AXygM4xioi/FvwzWX7OEI2xiaXzqN6sBbKYFm2mi0+qkzQnoa0jsb5mFy0/g7U0qm1T4dfkir/3P'
                . 'ixSpEHMkuLNbVbfkLtmowgeCQ2i4rJcVSU2tNohlWUp0UIRaJKD2K/Y+UwAWjhWToqJI1vWo2PBlhRNsEzh1ZWaWyZiFQFz0'
                . 'gwdR4P8HFm6NfteQDt0o7aB7FTOB1xIg0tsQ18TyyoVxMea9sWEXd3UpXF00Ri11FDEPH1F03Ttyl1+KPMmI3z+NAaTIL+WY'
                . 'CP3n9G1VowyBNgyarelymn+8fU/phqxKcyYCZ96gwO1NKXgo7twwLmAvneHt/cldud+dHUbM5jY7ro6YG+nazs29Li1cNmiy'
                . 'hpvg2AFM87UqjiETO+XN3UM77+nkGYNKm5vYBxkCMOrZU60ecMiRwOmfrzbX74tB+s6g79teLOF7KJ51yD3WstM+sPx/6NvI'
                . 'q4oXO2ZsRmxPHPrnmHLBK7xKSVncwF3pYT7857YSc0JMeEDmHc7nlQLT7gPzHxjO1H9SLip592C8SoOYEe282V4bNgHgsbKM'
                . 'C+DRCtK4SC6FnE9WI8j4btV0bI3vrTUxbmpTYGqpq9RePa4/v3isyfVpuMsv6cZRBMVT8dZoSxvFJfppxIPpgTKoLmMsYCYv'
                . 'dreKFuZ+5d1zQXSo2rqu8enOB6fWzGxZasELcgd2YsLmFeASnc7KJ2KJQhXhRCb4FWwifOI6BqwoeKFgVvG9dvAoAIkcwcHh'
                . 'TmwNyHygwGZ/uPSsL62cYcvi67XdBduLslI70/JzuukLCjPop943jc69lBuwckCGKJy2rLgwE/4QhW21cTHpq0gLGdr63vEj'
                . 'g8FmtdhVWpsTMSJidsHzmY7GEy8ttQMEgBLzRvell00d27eMkFcA4Nlp+TsA4PmtrdW/1saX8+5pPQAaBgCAALnm/zPA67ut'
                . 'ZTFvNzMFHgw6qpsGJsZVb23x46RTBlQuvnWI/fTNFvGlcm+pusWeiABsaR5was44ttvtVjy1mr0op+/UOHh/1/Ujn2+ThzxU'
                . 's4FWTL5VAtjZnr/F1AhXvckApl0bTduuaizGVtM26esqRCu0BfpBCuiQQwmwFIvSL/69PCv+RNhlgOOffDO71JrD1Twynb2a'
                . '5z5BVnEtVGF0OYG3nBLKkCtIlGwnRFKxlaZ8vV8aYT85jwSA/c51h2gZ5Hubx1yiHZZ+uElvtqIXb+zpROnGkxSfnIPBVAXV'
                . 'umvKAdLs2770F/HVC61CGyAPHFs7bI3jtVerNVQJofy04H+HvU++85gQ1WgZqtqoVsbdzK84LTSCGka34iA8BoKpMu7dSHXV'
                . '5TDhk3Wxp5A5Znq9pk3sWTZNPsRmh8h8z5P9b+H/uuh7rtw8syu1zQcseZkjx0lyWFD+15rKrk1jQnOeP+6FW+JE7MZxuDWO'
                . 'xx5Hc8B9cQb2xpm4HW6FA3Dkwz4rPszJAs4PJv/SJAu0/uFKIZcvDH5HcUbCF2iG/L9TDKFVFa6i7poL7uG7oao9ReYUKgPQ'
                . 'rwDerOg84NcHp7TwROvU0Kq2eb9KskVdExRIHGV3VITfF1n57k0xsFjvqqhgvIxyUbgBc+NAlw0GvTiDOTzMMFAM/IkcTgpM'
                . 'echpAbj3I83xtg5SMdJTLMgCdlhHJdYromqOiOwXhwlXMH3FnBjNAlygS9qm3cBirqiFFNNrPyk027WuqMAcmhXPVnlO7GGt'
                . 'ckuAn4BedvXP0TwVjmPYxiJasfDbgrLWFq3SvBy+l2lCIwA7DsTNcQvs42hd8tKJMeH2BN1yJ8t79KKyaKpLzcSvODwzQIkw'
                . 'N3cEuXvEK3LxYJCBmuQDgVpCvWh5KZYgwTm7BGNyawkRm9F8iraEx5ChJbTAhNYxIiK7CQKQhA5LEIj8l4c5nYjUA1jyMkto'
                . 'vlyiAL27RAPZsMSAtGCJhS6yJECOZ0kIZXG+07EkApQneJkOgGvMpDmiM7lW/hLz7AplAx1ADnhfEww8gjR3zZnnTYWNU4Ht'
                . 'q3IyuAwHQtEn57nzcVKIj6iLwineOBkLDy9bDqvlNN3aPbUwy3pDEwk5yCRABVvEYc1hxwqSAd1bLrZQ7uvqYCRZGEezqa3x'
                . 'i/P18axwaKNH4jd2b7KrOeH276RRHYxGVV4v+bSz8MjC+QrmnRFp6EfuwlJNy1tbI21Xty0Lbzh1/NaMvLLgS1tqFlFxdrty'
                . 'sqBqK31gcW6/roEQv2N0FECFCKIQD5Zy8fKad7IgzXkNLvLhxz/ZcMllV1wVkBwIFJRcCE4eEiMJeEJEDJJBupCQIkeJ6prr'
                . 'Mty0zF6VQr0XJkIzkSl0y22Z7vwRjmvrL5spEi7tsNJo0T8vk9MF5wy12zB7uKXyFc4J7TzXRnztB//FOngTh446SXQ3zkTG'
                . 'Gm9CxMbpzAVpnqSLSRZo7bOVukYVnNpE3bio/UaTTJFnslJTdfeBx9v/pF56RyffdDNM00df/fT3Tp0BXNfeyCZWFnp8jmwj'
                . 'DFciXu4SPXqUBFV+4vk5BgDlJdEJsiUGRyA5tf2+GSwOTyCSarSR0hzvuovPd+P/L2h0BpPF5sTn8vgCoUgskcrkCqVKrdHq'
                . '9AajyWyx2uwOp8vt8fL28fXzVzntsG80U+vchUtXrt24dRexyAOIhVgyZhY7UTazK3PKPFI0hbbZ2FLyjKlWI4aYxECDtRHl'
                . 'tDNq7bPfAdvtcNQxPCIOQgXmWmiBRWZleSsM8iorKzLbEfwUm6M4q1rdmtZifPTJQRwbqxWGKBdNkHWtb0Mb21RJpZW1uXKF'
                . '2lnsnsfue+BJtlTRI7u1xhk1HRsDQ1/asD4fWjueEtLveTzWoYgW8Yj5BykdhD5mnliz8tEExx/SL+ih1dMASZx9/LMc6RTS'
                . 'mVc6+a7VEc6zqc/hly9w4emZFmSLIBC7S4rY8RDV9vq2MBQA9V0O787bR2GKi+S7Kj9iRp+frvjxIYVmvzw9ac871a9Wmphd'
                . '/zgcq53bj9uh2KpdD8m5QrY/Fh5X9AMkRebEVZqDUeWqkRW31ydsyHdOtUZCOvIJLWfKrMkgG6VpMsgooxKbDHJhpYpQoRSo'
                . 'KGzVIa3Wv8b2/LbMvNKJPrzA9RBRw4tmjUNtKZGUlEob6ET7DkowN4mgJwxOmYNTyeBYf818g2PdWKTuOx2c4o+4YdL3/tBR'
                . 'HU3oLfQT0ToS7cRumJq7uV/Vd5ajVb9S5w++pPODvxpVSlSZUUYHyimnjA50oJRAxzGjXbufh7ZRoqjhZAZSVkgw8WgiNRTo'
                . '8SGaQdDbhIdOjcCABDMAAHkGZIAATDSD+LdBPABgQIIZAIA8AzKAEEyFrKQ1aVYa7GJFZUbbLMd+y3bS7tm9z6fJ7Ly/3t+v'
                . 'plEn7e6x15uxeYRsg2KSpbjQ1DjPtqrLhairq0aoExEKOhEuv6lK5bHjyiliCxF2vU7C1k+5vtfEK6/9224VU13y959iy++R'
                . 'dpAae88QU+47Y+TWyC1n/NURE8SN9DDzvRkgyV4Id+zlLfb3f+tFG++93bO8/1r8+3VfXQwAAAA=',
            'family' => 'Cormorant Garamond',
            'style' => 'normal',
            'weight' => '700',
            'range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
        ],
    ];
}



/**
 * @font-face rules for every embedded face, prepended to the inline <style>.
 */
function nhadep_font_faces_css(): string
{
    $rules = '';
    foreach (nhadep_font_assets() as $filename => $meta) {
        $rules .= '@font-face{font-family:"' . $meta['family'] . '"'
            . ';font-style:' . $meta['style']
            . ';font-weight:' . $meta['weight']
            . ';src:url("/assets/fonts/' . $filename . '") format("woff2")'
            . ';font-display:swap'
            . ';unicode-range:' . $meta['range'] . "}\n";
    }
    return $rules;
}

/**
 * Write every embedded woff2 into <outDir>/assets/fonts/. Idempotent — the
 * same bytes are rewritten harmlessly on each run. Containment (no path can
 * escape the out root) is enforced by nhadep_write_file().
 */
function nhadep_write_font_assets(string $outDir): void
{
    foreach (nhadep_font_assets() as $filename => $meta) {
        $bin = base64_decode($meta['b64'], true);
        if ($bin === false) {
            throw new RuntimeException('Dữ liệu base64 font hỏng: ' . $filename);
        }
        nhadep_write_file($outDir, 'assets/fonts/' . $filename, $bin);
    }
}

/* -------------------------------------------------------------------------
 * Rendering
 * ---------------------------------------------------------------------- */

function nhadep_css(): string
{
    $css = <<<'CSS'
:root{--font-sans:"Be Vietnam Pro",system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;--font-display:"Cormorant Garamond","Iowan Old Style","Palatino Linotype",Georgia,"Times New Roman",serif;--ink:#1B1611;--ink-2:#3A3229;--muted:#6E6355;--faint:#9C9184;--paper:#FCFAF7;--paper-2:#F5EFE7;--card:#FFFFFF;--line:#E8DED2;--line-2:#F2EBE2;--accent:#9A6B2F;--accent-dk:#7B5320;--accent-soft:#F7EFE1;--gold:#C9A96C;--r-sm:12px;--r-md:18px;--r-lg:26px;--r-full:999px;--sh-sm:0 1px 2px rgba(27,22,17,.05),0 6px 18px -8px rgba(27,22,17,.12);--sh-md:0 2px 6px rgba(27,22,17,.06),0 24px 48px -18px rgba(27,22,17,.25);--wrap:1180px;--gutter:clamp(20px,4vw,32px);--band:clamp(58px,7vw,104px)}
*{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%;scroll-behavior:smooth}
body{font-family:var(--font-sans);font-size:16px;line-height:1.7;color:var(--ink);background:var(--paper);-webkit-font-smoothing:antialiased}
img{max-width:100%;display:block}
a{color:var(--accent);text-underline-offset:3px}
h1,h2,h3,h4{font-family:var(--font-display);font-weight:600;line-height:1.2;color:var(--ink);letter-spacing:-.01em}
:focus-visible{outline:2px solid var(--accent);outline-offset:3px;border-radius:6px}
::selection{background:var(--accent-soft);color:var(--accent-dk)}
.wrap{max-width:var(--wrap);margin:0 auto;padding-left:var(--gutter);padding-right:var(--gutter)}
.skip{position:absolute;left:16px;top:-64px;z-index:120;background:var(--ink);color:#fff;padding:10px 18px;border-radius:0 0 10px 10px;text-decoration:none;transition:top .2s ease}
.skip:focus{top:0}

/* ---- sticky top bar ---- */
.topbar{position:sticky;top:0;z-index:80;background:rgba(252,250,247,.88);backdrop-filter:blur(14px) saturate(140%);-webkit-backdrop-filter:blur(14px) saturate(140%);border-bottom:1px solid transparent;transition:background .3s ease,border-color .3s ease,box-shadow .3s ease}
.topbar.scrolled{background:rgba(252,250,247,.96);border-bottom-color:var(--line);box-shadow:0 14px 34px -24px rgba(27,22,17,.55)}
.topbar .wrap{display:flex;align-items:center;gap:clamp(12px,2vw,28px);min-height:76px}
.brand{display:flex;align-items:center;gap:12px;color:var(--ink);text-decoration:none;white-space:nowrap}
.brand-mark{display:grid;place-items:center;width:42px;height:42px;border-radius:13px;background:linear-gradient(145deg,#2C2218,#171310);color:var(--gold);font-family:var(--font-display);font-size:1.12rem;font-weight:700;letter-spacing:.05em;box-shadow:inset 0 0 0 1px rgba(201,169,108,.35),0 8px 18px -10px rgba(27,22,17,.6)}
.brand-name{display:flex;flex-direction:column;font-family:var(--font-display);font-size:1.06rem;font-weight:600;line-height:1;letter-spacing:.03em;text-transform:uppercase}
.brand-name small{margin-top:5px;font-family:var(--font-sans);font-size:.58rem;font-weight:600;letter-spacing:.3em;color:var(--muted);text-transform:uppercase}
.topnav{display:flex;align-items:center;gap:2px;margin-left:auto}
.topnav a{position:relative;color:var(--ink-2);text-decoration:none;font-size:.9rem;font-weight:500;padding:10px 14px;transition:color .25s ease}
.topnav a::after{content:"";position:absolute;left:14px;right:14px;bottom:4px;height:1.5px;background:var(--accent);transform:scaleX(0);transform-origin:left;transition:transform .3s ease}
.topnav a:hover{color:var(--accent)}
.topnav a:hover::after{transform:scaleX(1)}
.hotline-btn{display:inline-flex;align-items:center;gap:9px;background:var(--ink);color:#fff;text-decoration:none;font-weight:600;font-size:.86rem;padding:11px 20px;border-radius:var(--r-full);white-space:nowrap;box-shadow:var(--sh-sm);transition:background .25s ease,transform .25s ease,box-shadow .25s ease}
.hotline-btn svg{width:16px;height:16px;flex:none}
.hotline-btn:hover{background:var(--accent);transform:translateY(-1px);box-shadow:0 14px 26px -14px rgba(154,107,47,.9)}
.nav-open{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}
.nav-burger{display:none;flex-direction:column;align-items:center;justify-content:center;gap:5px;width:42px;height:42px;margin-left:auto;border:1px solid var(--line);border-radius:13px;background:var(--card);cursor:pointer}
.nav-burger span{display:block;width:18px;height:1.6px;background:var(--ink);border-radius:2px;transition:transform .25s ease,opacity .25s ease}
.nav-open:checked~.nav-burger span:nth-child(1){transform:translateY(6.6px) rotate(45deg)}
.nav-open:checked~.nav-burger span:nth-child(2){opacity:0}
.nav-open:checked~.nav-burger span:nth-child(3){transform:translateY(-6.6px) rotate(-45deg)}

/* ---- hero ---- */
.hero{position:relative;display:flex;align-items:center;overflow:hidden;background:var(--ink);color:#F6F1EA;min-height:clamp(580px,80vh,780px)}
.hero::before{content:"";position:absolute;inset:0;background:radial-gradient(120% 120% at 12% 18%,#3B2D1E 0%,transparent 58%),linear-gradient(160deg,#241C14,#171310 62%)}
.hero-bg{position:absolute;inset:0;background-size:cover;background-position:center;opacity:.42;transform:scale(1.02)}
.hero-scrim{position:absolute;inset:0;background:linear-gradient(100deg,rgba(20,16,13,.92) 0%,rgba(20,16,13,.6) 48%,rgba(20,16,13,.32) 100%),linear-gradient(0deg,rgba(20,16,13,.88),transparent 46%)}
.hero-inner{position:relative;z-index:1;width:100%;max-width:var(--wrap);margin:0 auto;padding:clamp(88px,12vw,152px) var(--gutter)}
.kicker{display:inline-flex;align-items:center;gap:12px;margin-bottom:26px;color:var(--gold);font-size:.7rem;font-weight:600;letter-spacing:.3em;text-transform:uppercase}
.kicker::before{content:"";width:26px;height:1px;background:var(--gold);opacity:.8}
.hero h1{max-width:19ch;font-size:clamp(2.35rem,5.6vw,4.3rem);line-height:1.06;letter-spacing:-.02em;color:#FBF8F3;text-wrap:balance}
.hero h1 em{font-style:italic;font-weight:500;color:var(--gold)}
.hero .sub{margin-top:22px;max-width:56ch;color:#D6C9B8;font-size:clamp(1rem,1.4vw,1.12rem);line-height:1.75}
.cta-row{display:flex;flex-wrap:wrap;gap:14px;margin-top:36px}
.btn{display:inline-flex;align-items:center;gap:10px;padding:14px 26px;border-radius:var(--r-full);text-decoration:none;font-weight:600;font-size:.93rem;transition:transform .25s ease,box-shadow .25s ease,background .25s ease,color .25s ease,border-color .25s ease}
.btn svg{width:17px;height:17px;flex:none;transition:transform .25s ease}
.btn:hover svg{transform:translateX(3px)}
.btn-solid{background:var(--accent);color:#fff;box-shadow:0 16px 32px -16px rgba(154,107,47,.95)}
.btn-solid:hover{background:var(--accent-dk);transform:translateY(-2px)}
.btn-ghost{border:1px solid rgba(246,241,234,.38);background:rgba(246,241,234,.04);color:#F6F1EA;backdrop-filter:blur(4px)}
.btn-ghost:hover{background:rgba(246,241,234,.13);border-color:rgba(246,241,234,.65)}
.btn-outline{border:1px solid var(--line);background:var(--card);color:var(--ink)}
.btn-outline:hover{border-color:var(--accent);color:var(--accent);transform:translateY(-2px);box-shadow:var(--sh-sm)}
.trust-row{display:flex;flex-wrap:wrap;gap:12px 28px;list-style:none;margin-top:44px;padding-top:26px;border-top:1px solid rgba(246,241,234,.16);color:#C9BCA9;font-size:.78rem;letter-spacing:.1em;text-transform:uppercase}
.trust-row li{display:inline-flex;align-items:center;gap:10px}
.trust-row li::before{content:"";width:6px;height:6px;border-radius:50%;background:var(--gold)}

/* ---- section scaffolding ---- */
.band{padding:var(--band) 0}
.band.alt{background:var(--paper-2);border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
.sec-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:20px 40px;margin-bottom:clamp(30px,4vw,52px)}
.sec-head-main{max-width:660px}
.sec-kicker{display:inline-flex;align-items:center;gap:11px;color:var(--accent);font-size:.7rem;font-weight:600;letter-spacing:.28em;text-transform:uppercase}
.sec-kicker::before{content:"";width:22px;height:1px;background:currentColor;opacity:.6}
.sec-head h2{margin-top:14px;font-size:clamp(1.75rem,3.6vw,2.6rem);text-wrap:balance}
.sec-head p{margin-top:12px;max-width:60ch;color:var(--muted)}
.sec-link{display:inline-flex;align-items:center;gap:9px;padding-bottom:6px;border-bottom:1px solid var(--line);color:var(--ink);text-decoration:none;font-size:.82rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap;transition:color .25s ease,border-color .25s ease}
.sec-link svg{width:15px;height:15px;transition:transform .25s ease}
.sec-link:hover{color:var(--accent);border-color:var(--accent)}
.sec-link:hover svg{transform:translateX(4px)}

/* ---- news grid cards ---- */
.news-grid{display:grid;grid-template-columns:1fr;gap:clamp(18px,2.4vw,28px)}
.pcard{position:relative;display:flex;flex-direction:column;overflow:hidden;background:var(--card);border:1px solid var(--line);border-radius:var(--r-md);box-shadow:var(--sh-sm);transition:transform .4s cubic-bezier(.2,.7,.3,1),box-shadow .4s ease,border-color .4s ease}
.pcard:hover{transform:translateY(-6px);border-color:#DED0BE;box-shadow:var(--sh-md)}
.pcard-media{position:relative;display:block;aspect-ratio:16/11;overflow:hidden;background:var(--paper-2)}
.pcard-media img{width:100%;height:100%;object-fit:cover;transition:transform .8s cubic-bezier(.2,.7,.3,1)}
.pcard:hover .pcard-media img{transform:scale(1.05)}
.pcard-media::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(27,22,17,.3),transparent 46%);pointer-events:none}
.chip{position:absolute;left:14px;top:14px;z-index:1;padding:7px 13px;border-radius:var(--r-full);background:rgba(252,250,247,.94);color:var(--ink);font-size:.63rem;font-weight:600;letter-spacing:.18em;text-transform:uppercase;box-shadow:0 8px 18px -10px rgba(27,22,17,.6)}
.date-badge{position:absolute;right:14px;bottom:14px;z-index:1;padding:8px 13px;border-radius:var(--r-sm);background:rgba(23,19,16,.82);backdrop-filter:blur(8px);color:#F6F1EA;text-align:center;line-height:1}
.date-badge b{display:block;font-family:var(--font-display);font-size:1.35rem;font-weight:600;color:var(--gold)}
.date-badge small{display:block;margin-top:4px;font-size:.58rem;letter-spacing:.2em;text-transform:uppercase;opacity:.85}
.pcard-body{display:flex;flex:1;flex-direction:column;gap:11px;padding:22px 24px 24px}
.pcard-body h3{font-size:1.22rem;line-height:1.28}
.pcard-body h3 a{color:var(--ink);text-decoration:none;transition:color .25s ease}
.pcard-body h3 a:hover{color:var(--accent)}
.clamp2{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;color:var(--muted);font-size:.92rem;line-height:1.68}
.more-link{display:inline-flex;align-items:center;gap:9px;margin-top:auto;padding-top:16px;border-top:1px solid var(--line-2);color:var(--accent);text-decoration:none;font-weight:600;font-size:.84rem;letter-spacing:.03em}
.more-link svg{width:15px;height:15px;transition:transform .3s ease}
.more-link:hover{color:var(--accent-dk)}
.more-link:hover svg{transform:translateX(4px)}
.link-arrow{display:inline-flex;align-items:center;gap:9px;color:var(--gold);text-decoration:none;font-weight:600;font-size:.85rem}
.link-arrow svg{width:15px;height:15px;transition:transform .25s ease}
.link-arrow:hover svg{transform:translateX(4px)}

/* ---- about + services ---- */
.about-grid{display:grid;grid-template-columns:1fr;gap:clamp(34px,5vw,64px);align-items:start}
.about-copy h2{margin-top:14px;font-size:clamp(1.8rem,3.4vw,2.5rem)}
.about-copy p{margin-top:16px;color:var(--ink-2)}
.about-copy .lead{font-size:1.08rem;line-height:1.75;color:var(--ink)}
.about-list{display:grid;gap:12px;list-style:none;margin-top:22px}
.about-list li{position:relative;padding-left:30px;color:var(--ink-2);font-size:.94rem}
.about-list li::before{content:"";position:absolute;left:0;top:.3em;width:18px;height:18px;border-radius:50%;background:var(--accent-soft);box-shadow:inset 0 0 0 1px rgba(154,107,47,.3)}
.about-list li::after{content:"";position:absolute;left:6px;top:.58em;width:6px;height:3px;border-left:1.5px solid var(--accent);border-bottom:1.5px solid var(--accent);transform:rotate(-45deg)}
.svc-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
.svc{padding:26px 24px;background:var(--card);border:1px solid var(--line);border-radius:var(--r-md);transition:transform .3s ease,box-shadow .3s ease,border-color .3s ease}
.svc:hover{transform:translateY(-4px);border-color:#DED0BE;box-shadow:var(--sh-md)}
.svc svg{width:38px;height:38px;color:var(--accent)}
.svc h3{margin-top:16px;font-size:1.08rem}
.svc p{margin-top:8px;color:var(--muted);font-size:.86rem;line-height:1.65}

/* ---- stats band ---- */
.stats{position:relative;overflow:hidden;padding:clamp(46px,6vw,76px) 0;background:var(--ink);color:#EFE9E1}
.stats::before{content:"";position:absolute;left:-80px;bottom:-140px;width:360px;height:360px;border-radius:50%;background:radial-gradient(circle,rgba(201,169,108,.16),transparent 70%)}
.stats-grid{position:relative;display:grid;grid-template-columns:repeat(3,1fr);gap:32px;text-align:center}
.stat{position:relative}
.stat+.stat::before{content:"";position:absolute;left:0;top:12%;height:76%;width:1px;background:rgba(246,241,234,.14)}
.stat b{display:block;font-family:var(--font-display);font-size:clamp(2.2rem,4.6vw,3.2rem);font-weight:600;line-height:1;color:var(--gold)}
.stat span{display:block;margin-top:10px;color:#BCAF9D;font-size:.74rem;letter-spacing:.2em;text-transform:uppercase}

/* ---- contact ---- */
.contact-card{position:relative;display:grid;grid-template-columns:1fr;gap:clamp(30px,4vw,60px);overflow:hidden;padding:clamp(32px,5vw,64px);background:var(--ink);border-radius:var(--r-lg);color:#EFE9E1}
.contact-card::before{content:"";position:absolute;right:-100px;top:-130px;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,rgba(201,169,108,.22),transparent 68%)}
.contact-info{position:relative}
.contact-info .sec-kicker{color:var(--gold)}
.contact-info h2{margin-top:14px;max-width:20ch;font-size:clamp(1.7rem,3.2vw,2.5rem);color:#FBF8F3}
.contact-note{margin-top:14px;max-width:44ch;color:#BCAF9D;font-size:.95rem}
.contact-big{display:inline-block;margin-top:22px;color:var(--gold);text-decoration:none;font-family:var(--font-display);font-size:clamp(1.9rem,4.6vw,2.9rem);font-weight:600;letter-spacing:.02em;transition:color .25s ease}
.contact-big:hover{color:#E6C88F}
.contact-meta{display:grid;gap:14px;margin-top:26px;color:#D6C9B8;font-size:.95rem}
.contact-meta span{display:flex;gap:12px;align-items:flex-start}
.contact-meta svg{width:18px;height:18px;flex:none;margin-top:.25em;color:var(--gold)}
.contact-meta a{color:#F0E7DA;text-decoration:none}
.contact-meta a:hover{color:var(--gold)}
.contact-actions{position:relative;display:flex;flex-direction:column;justify-content:center;gap:14px}
.contact-actions .btn{justify-content:center}

/* ---- footer ---- */
footer.site{background:#14100D;color:#A99C8B;padding:clamp(52px,6vw,84px) 0 30px;font-size:.9rem}
.footer-grid{display:grid;grid-template-columns:1fr;gap:clamp(30px,4vw,56px)}
.f-brand{font-family:var(--font-display);font-size:1.4rem;font-weight:600;letter-spacing:.04em;color:#FBF8F3}
.f-brand b{color:var(--gold);font-weight:600}
.f-about p{margin-top:14px;max-width:46ch;line-height:1.7}
.f-col h3{margin-bottom:18px;font-family:var(--font-sans);font-size:.7rem;font-weight:600;letter-spacing:.24em;text-transform:uppercase;color:var(--gold)}
.f-col ul{display:grid;gap:11px;list-style:none}
footer.site a{color:#CFC3B2;text-decoration:none;transition:color .25s ease}
footer.site a:hover{color:var(--gold)}
.footer-bottom{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px 26px;margin-top:clamp(38px,5vw,60px);padding-top:24px;border-top:1px solid rgba(246,241,234,.1);font-size:.8rem}

/* ---- breadcrumbs ---- */
.crumbs{padding:26px 0 0;color:var(--muted);font-size:.82rem}
.crumbs ol{display:flex;flex-wrap:wrap;align-items:center;gap:8px;list-style:none}
.crumbs li{display:inline-flex;align-items:center;gap:8px}
.crumbs li+li::before{content:"\203A";color:var(--faint)}
.crumbs a{color:var(--muted);text-decoration:none;transition:color .2s ease}
.crumbs a:hover{color:var(--accent)}
.crumbs [aria-current]{color:var(--ink);font-weight:500}

/* ---- daily page ---- */
.hero-day{min-height:clamp(520px,72vh,720px)}
.hero-day::before{display:none}
.hero-day .hero-scrim{background:linear-gradient(95deg,rgba(20,16,13,.94) 0%,rgba(20,16,13,.72) 48%,rgba(20,16,13,.38) 100%),linear-gradient(0deg,rgba(20,16,13,.9),transparent 44%)}
.hero-day .hero-inner{max-width:960px}
.hero-day h1{max-width:24ch;font-size:clamp(2rem,4.4vw,3.4rem)}
.hero-facts{display:flex;flex-wrap:wrap;gap:14px 40px;list-style:none;margin-top:38px;padding-top:26px;border-top:1px solid rgba(246,241,234,.16)}
.fact b{display:block;font-family:var(--font-display);font-size:1.7rem;font-weight:600;line-height:1;color:var(--gold)}
.fact span{display:block;margin-top:7px;color:#BCAF9D;font-size:.66rem;letter-spacing:.2em;text-transform:uppercase}
.post-list{display:grid;gap:clamp(16px,2vw,22px);margin:44px 0 8px}
.post-card{display:grid;grid-template-columns:minmax(0,340px) minmax(0,1fr);overflow:hidden;background:var(--card);border:1px solid var(--line);border-radius:var(--r-md);box-shadow:var(--sh-sm);transition:transform .35s cubic-bezier(.2,.7,.3,1),box-shadow .35s ease,border-color .35s ease}
.post-card:hover{transform:translateY(-4px);border-color:#DED0BE;box-shadow:var(--sh-md)}
.post-card.no-media{grid-template-columns:1fr}
.post-thumb{position:relative;min-height:230px;overflow:hidden;background:var(--paper-2)}
.post-thumb img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;transition:transform .8s cubic-bezier(.2,.7,.3,1)}
.post-card:hover .post-thumb img{transform:scale(1.04)}
.post-index{position:absolute;left:0;top:0;z-index:1;padding:8px 14px;border-radius:0 0 12px 0;background:rgba(23,19,16,.86);backdrop-filter:blur(6px);color:var(--gold);font-family:var(--font-display);font-size:.95rem;font-weight:600}
.post-body{display:flex;flex-direction:column;padding:clamp(22px,3vw,32px)}
.post-card h2,.post-card h3{font-size:clamp(1.2rem,2vw,1.45rem);line-height:1.28}
.post-card h2 a,.post-card h3 a{color:var(--ink);text-decoration:none;transition:color .25s ease}
.post-card h2 a:hover,.post-card h3 a:hover{color:var(--accent)}
.meta{display:inline-flex;align-items:center;gap:9px;margin-top:12px;color:var(--faint);font-size:.72rem;letter-spacing:.14em;text-transform:uppercase}
.meta svg{width:14px;height:14px;flex:none}
.excerpt{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;margin-top:14px;color:var(--muted);font-size:.94rem;line-height:1.72}
.readorig{display:inline-flex;align-items:center;gap:9px;margin-top:auto;padding-top:18px;color:var(--accent);text-decoration:none;font-weight:600;font-size:.85rem}
.readorig svg{width:15px;height:15px;transition:transform .25s ease}
.readorig:hover{color:var(--accent-dk)}
.readorig:hover svg{transform:translateX(4px)}
.post-card.feature{grid-template-columns:1.15fr 1fr;border-color:#E1D5C4}
.post-card.feature .post-thumb{min-height:360px}
.post-card.feature .post-body{padding:clamp(30px,4vw,54px)}
.post-card.feature h2{font-size:clamp(1.6rem,3vw,2.2rem)}
.post-card.feature .excerpt{-webkit-line-clamp:4}
.day-nav-cards{margin:clamp(40px,5vw,64px) 0 0}
.day-nav-cards .wrap{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.dnav{display:flex;flex-direction:column;gap:8px;padding:22px 24px;background:var(--card);border:1px solid var(--line);border-radius:var(--r-md);text-decoration:none;box-shadow:var(--sh-sm);transition:transform .3s ease,box-shadow .3s ease,border-color .3s ease}
.dnav:hover{transform:translateY(-3px);border-color:var(--accent);box-shadow:var(--sh-md)}
.dnav small{display:inline-flex;align-items:center;gap:8px;color:var(--accent);font-size:.66rem;font-weight:600;letter-spacing:.2em;text-transform:uppercase}
.dnav small svg{width:14px;height:14px;flex:none}
.dnav b{font-family:var(--font-display);font-size:1.1rem;font-weight:600;color:var(--ink)}
.dnav.next{text-align:right;align-items:flex-end}
.dnav.next small{flex-direction:row-reverse}
.dnav.empty{opacity:.45;pointer-events:none}
.home-link-row{margin:34px 0 0;text-align:center}
.home-link-row a{display:inline-flex;align-items:center;gap:10px;padding-bottom:6px;border-bottom:1px solid var(--line);color:var(--ink);text-decoration:none;font-size:.82rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;transition:color .25s ease,border-color .25s ease}
.home-link-row a:hover{color:var(--accent);border-color:var(--accent)}
.contact-mini{display:grid;gap:12px;margin:clamp(40px,5vw,64px) 0 0;padding:clamp(24px,3vw,34px);background:var(--card);border:1px solid var(--line);border-radius:var(--r-md);box-shadow:var(--sh-sm)}
.contact-mini h2{margin-top:6px;font-size:1.25rem}
.contact-mini p{color:var(--muted);font-size:.93rem}
.contact-mini a{color:var(--accent);font-weight:600;text-decoration:none}
.contact-mini a:hover{text-decoration:underline}

/* ---- index: featured day + day cells ---- */
.feature-wrap{margin-bottom:clamp(26px,3vw,38px)}
.feature-card{display:grid;grid-template-columns:1fr;overflow:hidden;background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);text-decoration:none;box-shadow:var(--sh-sm);transition:transform .4s cubic-bezier(.2,.7,.3,1),box-shadow .4s ease,border-color .4s ease}
.feature-card:hover{transform:translateY(-5px);border-color:#DED0BE;box-shadow:var(--sh-md)}
.feature-media{display:block;min-height:230px;background:linear-gradient(135deg,var(--accent-soft),#EADDC7);background-size:cover;background-position:center}
.feature-body{display:flex;flex-direction:column;justify-content:center;gap:12px;padding:clamp(28px,4vw,46px)}
.feature-body h2{font-size:clamp(1.5rem,3vw,2.1rem);line-height:1.22}
.feature-body p{color:var(--muted);font-size:.94rem}
.feature-body .btn{align-self:flex-start;margin-top:6px}
.recent-grid,.archive-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
.day-cell{display:flex;align-items:center;gap:16px;padding:16px 18px;background:var(--card);border:1px solid var(--line);border-radius:var(--r-md);text-decoration:none;box-shadow:var(--sh-sm);transition:transform .3s ease,box-shadow .3s ease,border-color .3s ease}
.day-cell:hover{transform:translateY(-3px);border-color:var(--accent);box-shadow:var(--sh-md)}
.day-cell-date{display:flex;align-items:baseline;gap:3px;min-width:56px;font-family:var(--font-display);color:var(--accent)}
.day-cell-date b{font-size:1.8rem;font-weight:600;line-height:1}
.day-cell-date i{font-size:.8rem;font-style:normal;color:var(--muted)}
.day-cell-info strong{display:block;color:var(--ink);font-size:.92rem;font-weight:600}
.day-cell-info small{display:block;margin-top:3px;color:var(--muted);font-size:.76rem}
.recent-more{margin-top:clamp(26px,3vw,38px);text-align:center}

/* ---- archive page ---- */
.page-head{max-width:800px;padding:clamp(40px,6vw,72px) 0 clamp(26px,3vw,40px)}
.page-head h1{margin-top:16px;font-size:clamp(2.1rem,5vw,3.4rem)}
.page-head .lead{margin-top:16px;max-width:62ch;color:var(--muted);font-size:clamp(1rem,1.4vw,1.1rem)}
.page-facts{display:flex;flex-wrap:wrap;gap:12px 30px;margin-top:26px}
.page-facts span{display:inline-flex;align-items:baseline;gap:8px;color:var(--muted);font-size:.8rem;letter-spacing:.08em;text-transform:uppercase}
.page-facts b{font-family:var(--font-display);font-size:1.35rem;color:var(--ink)}
.month{margin-bottom:clamp(34px,4vw,52px)}
.month-head{display:flex;align-items:baseline;gap:14px;margin-bottom:22px;padding-bottom:14px;border-bottom:1px solid var(--line)}
.month-head h2{font-size:1.5rem}
.month-head span{color:var(--muted);font-size:.7rem;font-weight:600;letter-spacing:.24em;text-transform:uppercase}

/* ---- responsive ---- */
@media(min-width:600px){.news-grid{grid-template-columns:repeat(2,1fr)}}
@media(min-width:900px){.news-grid{grid-template-columns:repeat(3,1fr)}.about-grid{grid-template-columns:1.05fr 1fr}.contact-card{grid-template-columns:1.25fr .85fr;align-items:center}.feature-card{grid-template-columns:1.05fr 1fr}.footer-grid{grid-template-columns:1.6fr 1fr 1.2fr}.feature-media{min-height:100%}}
@media(max-width:1024px){.recent-grid,.archive-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:880px){
.nav-burger{display:flex}
.topnav{position:absolute;left:0;right:0;top:100%;display:none;flex-direction:column;align-items:stretch;gap:2px;margin:0;padding:14px var(--gutter) 20px;background:var(--card);border-bottom:1px solid var(--line);box-shadow:0 26px 44px -26px rgba(27,22,17,.5)}
.topnav a{padding:13px 14px;font-size:.95rem;border-radius:var(--r-sm)}
.topnav a::after{display:none}
.nav-open:checked~.topnav{display:flex}
.post-card{grid-template-columns:1fr}
.post-thumb{aspect-ratio:16/9;min-height:0}
.post-thumb img{position:static;height:100%}
.post-card.feature{grid-template-columns:1fr}
.post-card.feature .post-thumb{min-height:0}
.recent-grid,.archive-grid{grid-template-columns:repeat(2,1fr)}
.topbar .wrap{min-height:68px}
}
@media(max-width:720px){
.stats-grid{grid-template-columns:1fr;gap:28px}
.stat+.stat::before{display:none}
.hero .sub{font-size:1rem}
}
@media(max-width:560px){
.recent-grid,.archive-grid,.svc-grid{grid-template-columns:1fr}
.day-nav-cards .wrap{grid-template-columns:1fr}
.hotline-btn{padding:11px 14px}
.hotline-btn span{display:none}
.brand-name small{display:none}
.sec-head{align-items:flex-start}
}
@media(prefers-reduced-motion:reduce){
html{scroll-behavior:auto}
*,*::before,*::after{animation-duration:.001ms!important;transition-duration:.001ms!important}
}
CSS;
    // The readable CSS above is the source of truth; every page inlines it, so
    // strip comments and inter-rule newlines before emitting (~1.6 KB/page).
    $css = (string) preg_replace('/\/\*.*?\*\//s', '', $css);
    return trim((string) preg_replace('/\s*\n\s*/', '', $css));
}

/**
 * Inline SVG icon set (stroke-based, inherits currentColor).
 * Returns '' for an unknown name so callers can emit nothing safely.
 */
function nhadep_icon(string $name): string
{
    $paths = [
        'arrow-right' => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
        'arrow-left'  => '<path d="M19 12H5"/><path d="m11 18-6-6 6-6"/>',
        'arrow-up'    => '<path d="M12 19V5"/><path d="m6 11 6-6 6 6"/>',
        'phone'       => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>',
        'mail'        => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 7 10-7"/>',
        'pin'         => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'external'    => '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M18 14v6H4V6h6"/>',
        'home'        => '<path d="M3 11 12 4l9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'villa'       => '<path d="M3 21h18"/><path d="M6 21V9l6-5 6 5v12"/><path d="M9 21v-4h6v4"/><circle cx="17.5" cy="5.5" r="1.5"/>',
        'apartment'   => '<rect x="5" y="3" width="14" height="18" rx="1"/><path d="M9 7h2m2 0h2M9 11h2m2 0h2M9 15h2m2 0h2"/><path d="M10 21v-3h4v3"/>',
        'office'      => '<path d="M3 9h18"/><path d="M4 9v11m16-11v11"/><path d="M7 13h10"/><path d="M12 9V5h7"/>',
    ];
    if (!isset($paths[$name])) {
        return '';
    }
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $paths[$name] . '</svg>';
}

/**
 * Common <head> fragment. $ogImage: RAW featured-image URL for og:/twitter:
 * tags; it is escaped exactly once here via nhadep_safe_url().
 * $ogImageW/$ogImageH: intrinsic dimensions of $ogImage; when both > 0, emit
 * og:image:width/height so social cards render at the correct ratio.
 */
function nhadep_head(string $title, string $description, string $canonical, string $extraHead = '', string $ogImage = '', int $ogImageW = 0, int $ogImageH = 0): string
{
    $t = nhadep_san($title);
    $d = nhadep_san($description);
    $c = htmlspecialchars($canonical, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $img = nhadep_safe_url($ogImage);
    $imgTags = '';
    if ($img !== '') {
        $alt = nhadep_san(NHADEP_BRAND_NAME . ' — ' . NHADEP_TAGLINE);
        $dimTags = '';
        if ($ogImageW > 0 && $ogImageH > 0) {
            $dimTags = '<meta property="og:image:width" content="' . $ogImageW . '">' . "\n"
                . '<meta property="og:image:height" content="' . $ogImageH . '">' . "\n";
        }
        $imgTags = '<meta property="og:image" content="' . $img . '">' . "\n"
            . $dimTags
            . '<meta property="og:image:alt" content="' . $alt . '">' . "\n"
            . '<meta name="twitter:image" content="' . $img . '">' . "\n";
    }
    return "<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . '<meta name="theme-color" content="#1B1611">' . "\n"
        . '<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 32 32%27%3E%3Crect width=%2732%27 height=%2732%27 rx=%278%27 fill=%27%231B1611%27/%3E%3Ctext x=%2716%27 y=%2722%27 font-family=%27Georgia,serif%27 font-size=%2714%27 font-weight=%27600%27 fill=%27%23C9A96C%27 text-anchor=%27middle%27%3EDK%3C/text%3E%3C/svg%3E">' . "\n"
        . "<title>{$t}</title>\n<meta name=\"description\" content=\"{$d}\">\n"
        . '<link rel="canonical" href="' . $c . "\">\n"
        . '<link rel="alternate" type="application/rss+xml" title="' . nhadep_san(NHADEP_FEED_TITLE) . '" href="' . NHADEP_FEED_URL . '">' . "\n"
        . '<meta property="og:type" content="website">' . "\n"
        . '<meta property="og:site_name" content="' . nhadep_san(NHADEP_BRAND_NAME) . "\">\n"
        . '<meta property="og:locale" content="vi_VN">' . "\n"
        . '<meta property="og:title" content="' . $t . "\">\n"
        . '<meta property="og:description" content="' . $d . "\">\n"
        . '<meta property="og:url" content="' . $c . "\">\n"
        . $imgTags
        . '<meta name="twitter:card" content="' . ($img !== '' ? 'summary_large_image' : 'summary') . "\">\n"
        . '<meta name="twitter:title" content="' . $t . "\">\n"
        . '<meta name="twitter:description" content="' . $d . "\">\n"
        . '<style>' . nhadep_font_faces_css() . nhadep_css() . '</style>' . "\n" . $extraHead;
}

/**
 * Shared LocalBusiness JSON-LD node — single source of truth is the brand
 * facts block at the top of this file. Included in every page's @graph so
 * search engines tie all pages to one business entity.
 */
function nhadep_localbusiness_node(): array
{
    return [
        '@type'         => 'LocalBusiness',
        '@id'           => NHADEP_CANONICAL . '#localbusiness',
        'name'          => NHADEP_BRAND_NAME,
        'alternateName' => NHADEP_BRAND_SHORT,
        'description'   => NHADEP_TAGLINE,
        'url'           => NHADEP_CANONICAL,
        'telephone'     => substr(NHADEP_HOTLINE_TEL, 4), // strip 'tel:' -> '+84...'
        'email'         => NHADEP_EMAIL,
        'logo'          => ['@type' => 'ImageObject', 'url' => NHADEP_LOGO_URL],
        // Deterministic split of NHADEP_ADDRESS ('<street>, <locality>, <region>')
        // — no second copy of the address string; the constant stays the only source.
        'address'       => (static function (): array {
            $parts    = array_map('trim', explode(',', NHADEP_ADDRESS));
            $address  = ['@type' => 'PostalAddress', 'streetAddress' => NHADEP_ADDRESS, 'addressCountry' => 'VN'];
            if (count($parts) >= 3) {
                $address['addressRegion']   = (string) array_pop($parts);
                $address['addressLocality'] = (string) array_pop($parts);
                $address['streetAddress']   = implode(', ', $parts);
            }
            return $address;
        })(),
        'areaServed'    => ['Nghệ An', 'Hà Tĩnh'],
        'sameAs'        => [NHADEP_SOURCE_ORIGIN],
    ];
}

/**
 * BreadcrumbList JSON-LD node. $crumbs: list of [name, absolute URL] pairs,
 * top level first; the last pair is the current page (must carry its own
 * canonical URL). Mirrors the visible .crumbs nav on daily/archive pages.
 *
 * @param array<int, array{0:string, 1:string}> $crumbs
 */
function nhadep_breadcrumb_node(array $crumbs): array
{
    $items = [];
    foreach (array_values($crumbs) as $i => $crumb) {
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'name'     => $crumb[0],
            'item'     => $crumb[1],
        ];
    }
    return [
        '@type'           => 'BreadcrumbList',
        '@id'             => ($items !== [] ? $items[count($items) - 1]['item'] : '') . '#breadcrumb',
        'itemListElement' => $items,
    ];
}

/**
 * Sticky top bar shared by all pages.
 * $isHome=true -> in-page anchors; false -> root-absolute links (daily pages).
 * Mobile: CSS-only checkbox menu (plus a 10-line script in the footer that
 * unchecks it on link click, so in-page anchors work on small screens).
 */
function nhadep_header_html(bool $isHome): string
{
    $base = $isHome ? '#' : '/#';
    return '<a class="skip" href="#main">Bỏ qua tới nội dung chính</a>'
        . '<header class="topbar">'
        . '<div class="wrap">'
        . '<a class="brand" href="/" aria-label="' . nhadep_san(NHADEP_BRAND_NAME) . ' — về trang chủ">'
        . '<span class="brand-mark" aria-hidden="true">DK</span>'
        . '<span class="brand-name">Nội thất Đạt Kỳ<small>DK Home Design</small></span>'
        . '</a>'
        . '<input type="checkbox" id="nav-open" class="nav-open" tabindex="-1" aria-hidden="true">'
        . '<label class="nav-burger" for="nav-open" aria-label="Mở menu điều hướng"><span></span><span></span><span></span></label>'
        . '<nav class="topnav" aria-label="Điều hướng chính">'
        . '<a href="' . $base . 'tin-moi">Tin tức</a>'
        . '<a href="' . $base . 'tin-theo-ngay">Lưu trữ</a>'
        . '<a href="' . $base . 'gioi-thieu">Giới thiệu</a>'
        . '<a href="' . $base . 'lien-he">Liên hệ</a>'
        . '</nav>'
        . '<a class="hotline-btn" href="' . NHADEP_HOTLINE_TEL . '">' . nhadep_icon('phone') . '<span>' . NHADEP_HOTLINE_TEXT . '</span></a>'
        . '</div></header>';
}

function nhadep_footer_html(): string
{
    $y     = date('Y');
    $b     = nhadep_safe_url(NHADEP_SOURCE_ORIGIN);
    $ct    = nhadep_safe_url(NHADEP_CONTACT_URL);
    $email = nhadep_san(NHADEP_EMAIL);
    // 10-line progressive enhancement: close the mobile menu after an in-page
    // anchor click and add a shadow to the sticky bar once the user scrolls.
    // Everything degrades gracefully without JS (links still navigate).
    $script = '<script>(function(){'
        . 'var t=document.getElementById("nav-open"),h=document.querySelector(".topbar");'
        . 'if(t){Array.prototype.forEach.call(document.querySelectorAll(".topnav a"),function(a){a.addEventListener("click",function(){t.checked=false})})}'
        . 'if(h){var s=function(){h.classList.toggle("scrolled",window.scrollY>8)};window.addEventListener("scroll",s,{passive:true});s()}'
        . '})();</script>';
    return '<footer class="site"><div class="wrap footer-grid">'
        . '<div class="f-col f-about">'
        . '<p class="f-brand">Nội thất Đạt Kỳ — <b>DK Home Design</b></p>'
        . '<p>' . nhadep_san(NHADEP_TAGLINE) . '.</p>'
        . '<p>Tin tức tổng hợp từ <a href="' . $b . '" rel="noopener">noithatdatky.com</a> — cập nhật tự động mỗi ngày.</p>'
        . '</div>'
        . '<div class="f-col"><h3>Khám phá</h3><ul>'
        . '<li><a href="/#tin-moi">Tin tức mới nhất</a></li>'
        . '<li><a href="/#tin-theo-ngay">Lưu trữ theo ngày</a></li>'
        . '<li><a href="/tin-tuc/">Tất cả các ngày</a></li>'
        . '<li><a href="' . $ct . '" rel="noopener">Trang liên hệ</a></li>'
        . '</ul></div>'
        . '<div class="f-col"><h3>Liên hệ</h3><ul>'
        . '<li><a href="' . NHADEP_HOTLINE_TEL . '">Hotline ' . NHADEP_HOTLINE_TEXT . '</a></li>'
        . '<li><a href="mailto:' . $email . '">' . $email . '</a></li>'
        . '<li>' . nhadep_san(NHADEP_ADDRESS) . '</li>'
        . '</ul></div>'
        . '</div>'
        . '<div class="wrap footer-bottom">'
        . '<p>© ' . $y . ' DK Home Design. Bảo lưu mọi quyền.</p>'
        . '<p><a href="/sitemap.xml">Sitemap</a> · <a href="/feed.xml">RSS</a> · <a href="/robots.txt">Robots</a></p>'
        . '</div>'
        . $script
        . '</footer>';
}

/** Shared sanitized-excerpt helper: plain text clamped to $max chars + ellipsis. */
function nhadep_excerpt_text(array $post, int $max = 240): string
{
    $text = trim(strip_tags(html_entity_decode($post['excerpt'], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if ($text === '') {
        $text = trim(strip_tags(html_entity_decode($post['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
    $cut = mb_substr($text, 0, $max);
    return nhadep_san(rtrim($cut) . (mb_strlen($text) > $max ? '…' : ''));
}

/** Alt text for a post's featured image, derived from its title. */
function nhadep_img_alt(array $post): string
{
    return nhadep_san(mb_substr(trim(strip_tags(html_entity_decode($post['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 120));
}

/**
 * Hero/og:image URL for a post: large variant when available, else medium.
 * Returns '' when neither is an allowlisted URL.
 */
function nhadep_hero_src(array $post): string
{
    $large = (string) ($post['thumb_large'] ?? '');
    $src   = $large !== '' ? $large : (string) ($post['thumb'] ?? '');
    return nhadep_raw_url($src);
}

/**
 * Intrinsic [width, height] of the hero/og:image, falling back to the medium
 * dimensions when the large size is unknown. Returns [0, 0] when nothing known.
 */
function nhadep_hero_dims(array $post): array
{
    // Mirror nhadep_hero_src()'s source choice: whenever a large URL string is
    // present, report the large dimensions (0,0 when unknown) rather than
    // describing the large image with the medium image's dimensions.
    $large = (string) ($post['thumb_large'] ?? '');
    if ($large !== '') {
        return [
            max(0, (int) ($post['thumb_large_w'] ?? 0)),
            max(0, (int) ($post['thumb_large_h'] ?? 0)),
        ];
    }
    return [
        max(0, (int) ($post['thumb_w'] ?? 0)),
        max(0, (int) ($post['thumb_h'] ?? 0)),
    ];
}

/**
 * Lazy cover <img> with decoding=async + intrinsic width/height when known.
 * Returns '' when the post has no (allowlisted) image.
 */
function nhadep_cover_img(array $post): string
{
    $src = nhadep_safe_url($post['thumb']);
    if ($src === '') {
        return '';
    }
    $w   = max(0, (int) ($post['thumb_w'] ?? 0));
    $h   = max(0, (int) ($post['thumb_h'] ?? 0));
    $dim = ($w > 0 && $h > 0) ? ' width="' . $w . '" height="' . $h . '"' : ' width="640" height="400"';
    return '<img src="' . $src . '" alt="' . nhadep_img_alt($post) . '" loading="lazy" decoding="async"' . $dim . '>';
}

/** Landing-grid card (#tin-moi): 16:11 cover, category chip, date badge,
 * clamped excerpt and a CTA to the original post. The media block is a
 * decorative duplicate link (tabindex=-1 + aria-hidden) so screen readers
 * only announce the title link.
 */
function nhadep_post_grid_card(array $post): string
{
    $title = nhadep_san(trim(strip_tags(html_entity_decode($post['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    $link  = nhadep_safe_url($post['link']);

    $mediaInner = nhadep_cover_img($post);
    $dk = nhadep_day_key($post['date_gmt']) ?? '';
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $dk, nhadep_tz());
    $dN = $dt instanceof DateTimeImmutable ? $dt->format('j') : '–';
    $mN = $dt instanceof DateTimeImmutable ? 'Th' . $dt->format('n') : '';
    $mediaInner .= '<span class="chip">Tin tức</span>'
        . '<span class="date-badge"><b>' . $dN . '</b><small>' . nhadep_san($mN) . '</small></span>';
    $media = $link !== ''
        ? '<a class="pcard-media" href="' . $link . '" rel="noopener" tabindex="-1" aria-hidden="true">' . $mediaInner . '</a>'
        : '<div class="pcard-media">' . $mediaInner . '</div>';

    $titleHtml = $link !== ''
        ? '<a href="' . $link . '" rel="noopener">' . $title . '</a>'
        : $title;
    $cta = $link !== ''
        ? '<a class="more-link" href="' . $link . '" rel="noopener">Đọc bài viết ' . nhadep_icon('arrow-right') . '</a>'
        : '';

    return '<article class="pcard">' . $media
        . '<div class="pcard-body"><h3>' . $titleHtml . '</h3>'
        . '<p class="clamp2">' . nhadep_excerpt_text($post) . '</p>'
        . $cta
        . '</div></article>';
}

/**
 * Daily-page article card: image left ~340px on desktop, stacked on mobile;
 * numbered badge, publish time, clamped excerpt and CTA — links point at the
 * ORIGINAL post. $headingTag: h2 on the top story, h3 otherwise.
 * $featured=true renders the lead story full-width with a 16:8 image.
 */
function nhadep_post_card(array $post, string $headingTag = 'h3', bool $featured = false, int $index = 0): string
{
    $title     = nhadep_san(trim(strip_tags(html_entity_decode($post['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    $time      = nhadep_time_label($post['date_local']);
    $link      = nhadep_safe_url($post['link']);
    $titleHtml = $link !== ''
        ? '<a href="' . $link . '" rel="noopener">' . $title . '</a>'
        : $title;
    $thumbHtml = '';
    $img = nhadep_cover_img($post);
    if ($img !== '') {
        $num = str_pad((string) max(1, $index + 1), 2, '0', STR_PAD_LEFT);
        $thumbHtml = '<div class="post-thumb"><span class="post-index" aria-hidden="true">' . $num . '</span>' . $img . '</div>';
    }
    $origCta = $link !== ''
        ? '<a class="readorig" href="' . $link . '" rel="noopener">Đọc bài gốc tại noithatdatky.com ' . nhadep_icon('arrow-right') . '</a>'
        : '';
    $classes = 'post-card' . ($featured ? ' feature' : '') . ($thumbHtml === '' ? ' no-media' : '');
    return '<article class="' . $classes . '">' . $thumbHtml
        . '<div class="post-body"><' . $headingTag . '>' . $titleHtml . '</' . $headingTag . '>'
        . '<div class="meta">' . nhadep_icon('clock') . 'Đăng lúc ' . $time . ' · ' . nhadep_day_label(nhadep_day_key($post['date_gmt']) ?? '') . '</div>'
        . '<p class="excerpt">' . nhadep_excerpt_text($post) . '</p>' . $origCta . '</div></article>';
}

/**
 * Render a single daily digest page (magazine layout).
 * $prevDay/$nextDay: ['key' => Y-m-d] or null; rel=prev -> older, rel=next -> newer.
 */
function nhadep_render_daily_page(
    string $dayKey,
    string $slug,
    array $dayPosts,
    string $contactUrl,
    ?array $prevDay,
    ?array $nextDay
): string {
    $canonical = NHADEP_CANONICAL . $slug . '/';
    $count     = count($dayPosts);

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $dayKey, nhadep_tz());
    $dows = [1 => 'Thứ Hai', 2 => 'Thứ Ba', 3 => 'Thứ Tư', 4 => 'Thứ Năm', 5 => 'Thứ Sáu', 6 => 'Thứ Bảy', 7 => 'Chủ Nhật'];
    $dow  = ($dt instanceof DateTimeImmutable && isset($dows[(int) $dt->format('N')]))
        ? $dows[(int) $dt->format('N')] : '';
    $ddmmyyyy = $dt instanceof DateTimeImmutable ? $dt->format('d-m-Y') : $dayKey;

    $primary   = $dayPosts[0] ?? ['title' => '', 'thumb' => ''];
    $heroTitle = nhadep_san(trim(strip_tags(html_entity_decode($primary['title'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    $heroImg   = nhadep_hero_src($primary);
    [$heroW, $heroH] = nhadep_hero_dims($primary);
    $heroBg    = $heroImg !== '' ? ' style="background-image:url(\'' . nhadep_safe_url($heroImg) . '\')"' : '';
    $svc  = static fn (string $iSvg, string $name, string $txt): string =>
        '<div class="svc">' . $iSvg . '<h3>' . $name . '</h3><p>' . $txt . '</p></div>';
    $brandServices = '<section id="gioi-thieu" class="band alt"><div class="wrap"><div class="about-grid">'
        . '<div class="about-copy"><span class="sec-kicker">Về Đạt Kỳ</span>'
        . '<h2>' . nhadep_san(NHADEP_BRAND_NAME) . '</h2>'
        . '<p class="lead">Đơn vị thiết kế &amp; thi công nội thất trọn gói với xưởng sản xuất trực tiếp, đồng hành cùng khách hàng tại Nghệ An và Hà Tĩnh.</p>'
        . '<ul class="about-list"><li>Xưởng sản xuất trực tiếp — giá tốt hơn 15–25%</li><li>Đội ngũ kiến trúc sư &amp; thợ lành nghề</li><li>Bảo hành dài hạn, hỗ trợ trọn đời sản phẩm</li></ul>'
        . '</div><div class="svc-grid">'
        . $svc(nhadep_icon('home'), 'Nội thất nhà phố', 'Thiết kế thông minh cho mặt tiền hẹp, tối ưu từng mét vuông.')
        . $svc(nhadep_icon('villa'), 'Biệt thự &amp; Villa', 'Không gian sang trọng, đồng bộ phong cách từ ngoài vào trong.')
        . $svc(nhadep_icon('apartment'), 'Nội thất căn hộ', 'Giải pháp lưu trữ thông minh, hiện đại cho căn hộ đô thị.')
        . $svc(nhadep_icon('office'), 'Nội thất văn phòng', 'Công năng hiệu suất cao, thẩm mỹ chuẩn thương hiệu doanh nghiệp.')
        . '</div></div></div></section>';

    $desc = 'Tổng hợp ' . $count . ' tin tức nội thất ngày ' . $ddmmyyyy . ' từ ' . NHADEP_BRAND_NAME
        . ' — ' . NHADEP_TAGLINE . '.';

    // JSON-LD: CollectionPage + one BlogPosting per article (links to originals),
    // plus LocalBusiness (brand entity) + BreadcrumbList mirroring .crumbs.
    $graph = [[
        '@type'      => 'CollectionPage',
        '@id'        => $canonical . '#collectionpage',
        'url'        => $canonical,
        'name'       => 'Tin tức nội thất ngày ' . $ddmmyyyy,
        'inLanguage' => 'vi',
        'isPartOf'   => ['@id' => NHADEP_CANONICAL . '#website'],
        'publisher'  => ['@id' => NHADEP_CANONICAL . '#localbusiness'],
    ]];
    foreach ($dayPosts as $p) {
        $item = [
            '@type'            => 'BlogPosting',
            'headline'         => trim(strip_tags(html_entity_decode($p['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
            'url'              => $p['link'],
            'mainEntityOfPage' => $p['link'],
            'datePublished'    => $p['date_gmt'] . '+00:00',
            'inLanguage'       => 'vi',
            'author'           => ['@type' => 'Organization', 'name' => NHADEP_BRAND_NAME],
        ];
        $imgUrl = html_entity_decode(nhadep_safe_url($p['thumb']), ENT_QUOTES, 'UTF-8');
        if ($imgUrl !== '') {
            $item['image'] = $imgUrl;
        }
        $graph[] = $item;
    }
    $graph[] = nhadep_localbusiness_node();
    $graph[] = nhadep_breadcrumb_node([
        ['Trang chủ', NHADEP_CANONICAL],
        ['Tin tức', NHADEP_CANONICAL . 'tin-tuc/'],
        [$ddmmyyyy, $canonical],
    ]);
    $extra = '<script type="application/ld+json">'
        . json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . '</script>';

    $items = '';
    foreach ($dayPosts as $i => $post) {
        $items .= nhadep_post_card($post, $i === 0 ? 'h2' : 'h3', $i === 0, $i) . "\n";
    }

    $prevHtml = $prevDay !== null
        ? '<a class="dnav" href="/' . nhadep_san($prevDay['slug'] ?? '') . '/" rel="prev"><small>' . nhadep_icon('arrow-left') . 'Ngày cũ hơn</small><b>' . nhadep_san(nhadep_day_label($prevDay['key'])) . '</b></a>'
        : '<div class="dnav empty"><small>' . nhadep_icon('arrow-left') . 'Ngày cũ hơn</small><b>—</b></div>';
    $nextHtml = $nextDay !== null
        ? '<a class="dnav next" href="/' . nhadep_san($nextDay['slug'] ?? '') . '/" rel="next"><small>Ngày mới hơn ' . nhadep_icon('arrow-right') . '</small><b>' . nhadep_san(nhadep_day_label($nextDay['key'])) . '</b></a>'
        : '<div class="dnav empty next"><small>Ngày mới hơn ' . nhadep_icon('arrow-right') . '</small><b>—</b></div>';

    $cta   = htmlspecialchars($contactUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $email = nhadep_san(NHADEP_EMAIL);
    $tel   = NHADEP_HOTLINE_TEL;

    return '<!DOCTYPE html>' . "\n" . '<html lang="vi">' . "\n<head>\n"
        . nhadep_head('Tin tức ngày ' . $ddmmyyyy . ' — ' . NHADEP_BRAND_SHORT, $desc, $canonical, $extra, $heroImg, $heroW, $heroH)
        . "</head>\n<body>\n"
        . nhadep_header_html(false)
        . '<main id="main">'
        . '<nav class="crumbs" aria-label="Breadcrumb"><ol><li><a href="/">Trang chủ</a></li><li><a href="/#tin-moi">Tin tức</a></li><li aria-current="page">' . nhadep_san($ddmmyyyy) . '</li></ol></nav>'
        . '<section id="hero" class="hero hero-day"' . $heroBg . ' aria-label="Tin tức nội thất ngày ' . nhadep_san($ddmmyyyy) . '">'
        . '<div class="hero-scrim" aria-hidden="true"></div>'
        . '<div class="hero-inner">'
        . '<span class="kicker">' . nhadep_san($dow) . ' · ' . nhadep_san($ddmmyyyy) . '</span>'
        . '<h1>' . $heroTitle . '</h1>'
        . '<p class="sub">' . nhadep_san(NHADEP_TAGLINE) . '. Cập nhật hằng ngày từ ' . nhadep_san(NHADEP_BRAND_SHORT) . '.</p>'
        . '<ul class="hero-facts"><li class="fact"><b>' . $count . '</b><span>Bài viết trong ngày</span></li>'
        . '<li class="fact"><b>07:15 · 19:15</b><span>Cập nhật tự động</span></li></ul>'
        . '<div class="cta-row"><a class="btn btn-solid" href="#tin-ngay">Xem tin trong ngày ' . nhadep_icon('arrow-right') . '</a>'
        . '<a class="btn btn-ghost" href="' . NHADEP_HOTLINE_TEL . '">' . nhadep_icon('phone') . ' Hotline ' . NHADEP_HOTLINE_TEXT . '</a></div>'
        . '</div></section>'
        . '<section id="tin-ngay" class="band"><div class="wrap">'
        . '<div class="sec-head"><div class="sec-head-main"><span class="sec-kicker">Tin trong ngày</span>'
        . '<h2>Tổng hợp ngày ' . nhadep_san($ddmmyyyy) . '</h2>'
        . '<p>' . $count . ' bài viết mới nhất được tổng hợp từ noithatdatky.com.</p></div>'
        . '<a class="sec-link" href="/tin-tuc/">Xem lưu trữ ' . nhadep_icon('arrow-right') . '</a></div>'
        . '<div class="post-list">' . "\n" . $items . '</div></div></section>'
        . '<nav class="day-nav-cards" aria-label="Điều hướng theo ngày"><div class="wrap">' . $prevHtml . $nextHtml . '</div></nav>'
        . $brandServices
        . '<section id="lien-he" class="band"><div class="wrap"><div class="contact-card">'
        . '<div class="contact-info"><span class="sec-kicker">Liên hệ</span>'
        . '<h2>Nhận tư vấn &amp; báo giá miễn phí</h2>'
        . '<p class="contact-note">Đội ngũ Đạt Kỳ tư vấn thiết kế &amp; thi công nội thất trọn gói cho nhà phố, villa, căn hộ tại Nghệ An — Hà Tĩnh.</p>'
        . '<a class="contact-big" href="' . NHADEP_HOTLINE_TEL . '">' . NHADEP_HOTLINE_TEXT . '</a>'
        . '<div class="contact-meta"><span>' . nhadep_icon('mail') . '<a href="mailto:' . $email . '">' . $email . '</a></span>'
        . '<span>' . nhadep_icon('pin') . nhadep_san(NHADEP_ADDRESS) . '</span></div></div>'
        . '<div class="contact-actions">'
        . '<a class="btn btn-solid" href="' . NHADEP_HOTLINE_TEL . '">' . nhadep_icon('phone') . 'Gọi ngay hotline</a>'
        . '<a class="btn btn-ghost" href="' . nhadep_safe_url(NHADEP_SOURCE_ORIGIN) . '" rel="noopener">' . nhadep_icon('external') . 'Truy cập noithatdatky.com</a>'
        . '<a class="link-arrow" href="' . $cta . '">Hoặc xem trang Liên hệ ' . nhadep_icon('arrow-right') . '</a>'
        . '</div></div></div></section>'
        . '<div class="home-link-row"><a href="/">' . nhadep_icon('arrow-left') . 'Về trang chủ</a></div>'
        . '</main>'
        . nhadep_footer_html()
        . "\n</body>\n</html>\n";
}

/**
 * Render the premium landing page.
 * $days: newest-first [dayKey => ['count','first_title','posts'=>[['title','link'],..]]].
 * $latestPosts: flat newest-first post list (index slices the first 6).
 * $heroImage: raw featured-image URL of the newest post ('' -> gradient-only hero).
 */
function nhadep_render_index_page(
    array $days,
    int $allPostCount,
    string $contactUrl,
    array $latestPosts = [],
    string $heroImage = '',
    int $heroImageW = 0,
    int $heroImageH = 0
): string {
    $desc = 'Cổng tin tức nội thất hằng ngày từ ' . NHADEP_BRAND_NAME . ' — ' . NHADEP_TAGLINE . '.';

    $extra = '<script type="application/ld+json">'
        . json_encode([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'WebSite',
                    '@id'   => NHADEP_CANONICAL . '#website',
                    'url'   => NHADEP_CANONICAL,
                    'name'  => NHADEP_BRAND_SHORT . ' — Tin tức nội thất Nghệ An & Hà Tĩnh',
                    'inLanguage' => 'vi',
                    'publisher'  => ['@id' => NHADEP_CANONICAL . '#localbusiness'],
                ],
                [
                    '@type' => 'ItemList',
                    '@id'   => NHADEP_CANONICAL . '#daily-pages',
                    'name'  => 'Danh sách trang tin tức theo ngày',
                    'itemListElement' => array_values(array_map(
                        static function (array $row): array {
                            return [
                                '@type'    => 'ListItem',
                                'position' => $row['pos'],
                                'name'     => $row['name'],
                                'url'      => $row['url'],
                            ];
                        },
                        nhadep_index_items($days)
                    )),
                ],
                // LocalBusiness entity data on the landing page; BreadcrumbList is
                // intentionally omitted — this IS the homepage/top level (Google
                // guidance: no breadcrumb markup needed for a site's root page).
                nhadep_localbusiness_node(),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . '</script>';

    /* ---- HERO ---- */
    $heroImg  = nhadep_safe_url($heroImage);
    $bgStyle  = $heroImg !== '' ? ' style="background-image:url(\'' . $heroImg . '\')"' : '';
    $heroHtml = '<section id="hero" class="hero" aria-label="Giới thiệu">'
        . '<div class="hero-bg"' . $bgStyle . ' role="img" aria-label="Không gian nội thất ' . nhadep_san(NHADEP_BRAND_SHORT) . '"></div>'
        . '<div class="hero-scrim" aria-hidden="true"></div>'
        . '<div class="hero-inner">'
        . '<span class="kicker">Cổng thông tin nội thất</span>'
        . '<h1>Không gian sống <em>đẳng cấp</em> — cập nhật mỗi ngày</h1>'
        . '<p class="sub">' . nhadep_san(NHADEP_TAGLINE) . '. Theo dõi kinh nghiệm thiết kế, mẫu nội thất và dự án thi công thực tế được tổng hợp mỗi ngày.</p>'
        . '<div class="cta-row">'
        . '<a class="btn btn-solid" href="#tin-moi">Đọc tin mới nhất ' . nhadep_icon('arrow-right') . '</a>'
        . '<a class="btn btn-ghost" href="' . NHADEP_HOTLINE_TEL . '">' . nhadep_icon('phone') . 'Hotline ' . NHADEP_HOTLINE_TEXT . '</a>'
        . '</div>'
        . '<ul class="trust-row"><li>Thi công trọn gói</li><li>Bảo hành dài hạn</li><li>Thiết kế miễn phí</li></ul>'
        . '</div></section>';

    /* ---- #tin-moi: 6 newest posts ---- */
    $cards = '';
    foreach (array_slice($latestPosts, 0, 6) as $post) {
        $cards .= nhadep_post_grid_card($post) . "\n";
    }
    $newsSection = '<section id="tin-moi" class="band"><div class="wrap">'
        . '<div class="sec-head"><div class="sec-head-main"><span class="sec-kicker">Tin tức</span><h2>Tin tức mới nhất</h2>'
        . '<p>Những bài viết chuyên sâu về thiết kế và thi công nội thất, cập nhật liên tục từ noithatdatky.com.</p></div>'
        . '<a class="sec-link" href="/tin-tuc/">Xem lưu trữ ' . nhadep_icon('arrow-right') . '</a></div>'
        . ($cards !== '' ? '<div class="news-grid">' . "\n" . $cards . '</div>' : '')
        . '</div></section>';

    /* ---- #tin-theo-ngay: compact hub of recent days + archive link ---- */
    $rows    = nhadep_index_items($days); // newest-first
    $newest  = $rows[0] ?? null;
    $featured = '';
    if ($newest !== null) {
        $featBg = $heroImg !== '' ? ' style="background-image:url(\'' . $heroImg . '\')"' : '';
        $featured = '<a class="feature-card" href="' . $newest['url'] . '">'
            . '<div class="feature-media"' . $featBg . ' aria-hidden="true"></div>'
            . '<div class="feature-body"><span class="sec-kicker">Tin mới nhất</span>'
            . '<h2>' . nhadep_san($newest['teaser']) . '</h2>'
            . '<p>' . nhadep_san($newest['name']) . ' · ' . $newest['count'] . ' bài viết</p>'
            . '<span class="btn btn-outline">Xem trang tin ngày ' . nhadep_icon('arrow-right') . '</span></div></a>';
    }
    $recent = '';
    foreach (array_slice($rows, 0, 12) as $row) {
        $recent .= nhadep_day_cell($row);
    }
    $recentSection = '<section id="tin-theo-ngay" class="band alt"><div class="wrap">'
        . '<div class="sec-head"><div class="sec-head-main"><span class="sec-kicker">Lưu trữ</span><h2>Các ngày đã cập nhật</h2>'
        . '<p>' . count($days) . ' ngày tin tức, mới nhất ở đầu.</p></div>'
        . '<a class="sec-link" href="/tin-tuc/">Tất cả các ngày ' . nhadep_icon('arrow-right') . '</a></div>'
        . ($featured !== '' ? '<div class="feature-wrap">' . $featured . '</div>' : '')
        . '<div class="recent-grid">' . $recent . '</div>'
        . '<div class="recent-more"><a class="btn btn-outline" href="/tin-tuc/">Xem tất cả các ngày ' . nhadep_icon('arrow-right') . '</a></div>'
        . '</div></section>';

    /* ---- #gioi-thieu: split about + service cards ---- */
    $svc = static fn (string $iconSvg, string $name, string $txt): string =>
        '<div class="svc">' . $iconSvg . '<h3>' . $name . '</h3><p>' . $txt . '</p></div>';
    $aboutSection = '<section id="gioi-thieu" class="band"><div class="wrap"><div class="about-grid">'
        . '<div class="about-copy"><span class="sec-kicker">Giới thiệu</span>'
        . '<h2>' . nhadep_san(NHADEP_BRAND_NAME) . '</h2>'
        . '<p class="lead">Đơn vị thiết kế &amp; thi công nội thất trọn gói với xưởng sản xuất trực tiếp, đồng hành cùng khách hàng tại Nghệ An và Hà Tĩnh.</p>'
        . '<p>Chúng tôi kiến tạo không gian sống tinh tế, tối ưu công năng và chi phí — từ concept, bản vẽ chi tiết đến lắp đặt hoàn thiện, bàn giao đúng tiến độ.</p>'
        . '<ul class="about-list"><li>Xưởng sản xuất trực tiếp — giá tốt hơn 15–25%</li><li>Đội ngũ kiến trúc sư &amp; thợ lành nghề</li><li>Bảo hành dài hạn, hỗ trợ trọn đời sản phẩm</li></ul>'
        . '</div>'
        . '<div class="svc-grid">'
        . $svc(nhadep_icon('home'), 'Nội thất nhà phố', 'Thiết kế thông minh cho mặt tiền hẹp, tối ưu từng mét vuông.')
        . $svc(nhadep_icon('villa'), 'Biệt thự &amp; Villa', 'Không gian sang trọng, đồng bộ phong cách từ ngoài vào trong.')
        . $svc(nhadep_icon('apartment'), 'Nội thất căn hộ', 'Giải pháp lưu trữ thông minh, hiện đại cho căn hộ đô thị.')
        . $svc(nhadep_icon('office'), 'Nội thất văn phòng', 'Công năng hiệu suất cao, thẩm mỹ chuẩn thương hiệu doanh nghiệp.')
        . '</div></div></div></section>';

    /* ---- stats band (real numbers) ---- */
    $statsSection = '<section class="stats" aria-label="Thống kê"><div class="wrap"><div class="stats-grid">'
        . '<div class="stat"><b>' . $allPostCount . '+</b><span>Bài viết chuyên sâu</span></div>'
        . '<div class="stat"><b>' . count($days) . '</b><span>Ngày cập nhật liên tục</span></div>'
        . '<div class="stat"><b>2</b><span>Tỉnh Nghệ An – Hà Tĩnh phục vụ</span></div>'
        . '</div></div></section>';

    /* ---- #lien-he contact strip ---- */
    $cta   = htmlspecialchars($contactUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $email = nhadep_san(NHADEP_EMAIL);
    $originLink = nhadep_safe_url(NHADEP_SOURCE_ORIGIN);
    $contactSection = '<section id="lien-he" class="band"><div class="wrap">'
        . '<div class="contact-card">'
        . '<div class="contact-info"><span class="sec-kicker">Liên hệ</span>'
        . '<h2>Nhận tư vấn &amp; báo giá miễn phí</h2>'
        . '<p class="contact-note">Đội ngũ Đạt Kỳ tư vấn thiết kế &amp; thi công nội thất trọn gói cho nhà phố, villa, căn hộ tại Nghệ An — Hà Tĩnh.</p>'
        . '<a class="contact-big" href="' . NHADEP_HOTLINE_TEL . '">' . NHADEP_HOTLINE_TEXT . '</a>'
        . '<div class="contact-meta">'
        . '<span>' . nhadep_icon('mail') . '<a href="mailto:' . $email . '">' . $email . '</a></span>'
        . '<span>' . nhadep_icon('pin') . nhadep_san(NHADEP_ADDRESS) . '</span>'
        . '</div></div>'
        . '<div class="contact-actions">'
        . '<a class="btn btn-solid" href="' . NHADEP_HOTLINE_TEL . '">' . nhadep_icon('phone') . 'Gọi ngay hotline</a>'
        . '<a class="btn btn-ghost" href="' . $originLink . '" rel="noopener">' . nhadep_icon('external') . 'Truy cập noithatdatky.com</a>'
        . '<a class="link-arrow" href="' . $cta . '">Hoặc xem trang Liên hệ ' . nhadep_icon('arrow-right') . '</a>'
        . '</div></div></div></section>';

    return '<!DOCTYPE html>' . "\n" . '<html lang="vi">' . "\n<head>\n"
        . nhadep_head('Tin tức nội thất mỗi ngày — ' . NHADEP_BRAND_SHORT . ' Nghệ An & Hà Tĩnh', $desc, NHADEP_CANONICAL, $extra, $heroImage, $heroImageW, $heroImageH)
        . "</head>\n<body>\n"
        . nhadep_header_html(true)
        . '<main id="main">'
        . $heroHtml
        . $newsSection
        . $recentSection
        . $aboutSection
        . $statsSection
        . $contactSection
        . '</main>'
        . nhadep_footer_html()
        . "\n</body>\n</html>\n";
}

/** Build deterministic row data for the index listing (newest-first). */
function nhadep_index_items(array $days): array
{
    $dows  = [1 => 'Thứ Hai', 2 => 'Thứ Ba', 3 => 'Thứ Tư', 4 => 'Thứ Năm', 5 => 'Thứ Sáu', 6 => 'Thứ Bảy', 7 => 'Chủ Nhật'];
    $rows  = [];
    $pos   = 1;
    foreach ($days as $key => $meta) { // $days passed newest-first
        $teaser = trim(strip_tags(html_entity_decode((string) $meta['first_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $dt     = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $key, nhadep_tz());
        $rows[] = [
            'pos'    => $pos++,
            'key'    => $key,
            'name'   => nhadep_day_label($key),
            'count'  => (int) $meta['count'],
            'teaser' => nhadep_san($teaser),
            'url'    => '/' . ($meta['slug'] ?? $key) . '/',
            // Compact parts for the day-cell cards (newest-first grid / archive).
            'day'    => $dt instanceof DateTimeImmutable ? $dt->format('j') : '',
            'month'  => $dt instanceof DateTimeImmutable ? 'thg ' . $dt->format('n') : '',
            'year'   => $dt instanceof DateTimeImmutable ? $dt->format('Y') : '',
            'dow'    => ($dt instanceof DateTimeImmutable && isset($dows[(int) $dt->format('N')]))
                ? $dows[(int) $dt->format('N')] : '',
        ];
    }
    return $rows;
}

/** Compact day card shared by the homepage recent grid and the archive hub. */
function nhadep_day_cell(array $row): string
{
    return '<a class="day-cell" href="' . $row['url'] . '">'
        . '<span class="day-cell-date"><b>' . nhadep_san((string) $row['day']) . '</b><i>' . nhadep_san((string) $row['month']) . '</i></span>'
        . '<span class="day-cell-info"><strong>' . nhadep_san((string) $row['dow']) . '</strong><small>'
        . (int) $row['count'] . ' bài viết · ' . nhadep_san((string) $row['year']) . '</small></span>'
        . '</a>';
}

/** Group day rows by YYYY-MM (order preserved: days arrive newest-first). */
function nhadep_group_rows_by_month(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $ym = substr((string) $row['key'], 0, 7);
        $groups[$ym][] = $row;
    }
    return $groups;
}

/** Render the archive hub (out/tin-tuc/index.html): all days, newest-first,
 * grouped under a Vietnamese month heading ("Tháng 10 · 2026").
 */
function nhadep_render_archive(array $days): string
{
    $rows  = nhadep_index_items($days);
    $totalPosts = 0;
    $body  = '';
    foreach (nhadep_group_rows_by_month($rows) as $ym => $monthRows) {
        $cells = '';
        $monthPosts = 0;
        foreach ($monthRows as $row) {
            $cells .= nhadep_day_cell($row);
            $monthPosts += (int) $row['count'];
        }
        $totalPosts += $monthPosts;
        $body .= '<section class="month"><div class="month-head"><h2>Tháng ' . (int) substr($ym, 5, 2) . '</h2>'
            . '<span>Năm ' . substr($ym, 0, 4) . ' · ' . count($monthRows) . ' ngày · ' . $monthPosts . ' bài viết</span></div>'
            . '<div class="archive-grid">' . $cells . '</div></section>';
    }
    // og:image: featured LARGE image of the newest day (days arrive
    // newest-first), falling back to its medium thumb, then the brand logo
    // so the archive always carries an image.
    $newestMeta = $days !== [] ? $days[array_key_first($days)] : [];
    $ogImage = (string) ($newestMeta['thumb_large'] ?? '');
    $ogW     = (int) ($newestMeta['thumb_large_w'] ?? 0);
    $ogH     = (int) ($newestMeta['thumb_large_h'] ?? 0);
    if ($ogImage === '') {
        $ogImage = (string) ($newestMeta['thumb'] ?? '');
        $ogW     = 0;
        $ogH     = 0;
    }
    if ($ogImage === '') {
        $ogImage = NHADEP_LOGO_URL;
    }
    $desc  = 'Lưu trữ toàn bộ các trang tin tức nội thất theo ngày từ ' . NHADEP_BRAND_NAME . '.';
    $extra = '<script type="application/ld+json">'
        . json_encode([
            '@context' => 'https://schema.org',
            '@graph'   => [
                nhadep_breadcrumb_node([
                    ['Trang chủ', NHADEP_CANONICAL],
                    ['Lưu trữ', NHADEP_CANONICAL . 'tin-tuc/'],
                ]),
                nhadep_localbusiness_node(),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . '</script>';
    return '<!DOCTYPE html>' . "\n" . '<html lang="vi">' . "\n<head>\n"
        . nhadep_head('Lưu trữ tin tức — ' . NHADEP_BRAND_SHORT, $desc, NHADEP_CANONICAL . 'tin-tuc/', $extra, $ogImage, $ogW, $ogH)
        . "</head>\n<body>\n"
        . nhadep_header_html(false)
        . '<main id="main" class="wrap">'
        . '<nav class="crumbs" aria-label="Breadcrumb"><ol><li><a href="/">Trang chủ</a></li><li aria-current="page">Lưu trữ</li></ol></nav>'
        . '<header class="page-head"><span class="sec-kicker">Lưu trữ</span>'
        . '<h1>Toàn bộ tin tức theo ngày</h1>'
        . '<p class="lead">' . nhadep_san(NHADEP_TAGLINE) . '. Tất cả các ngày đã cập nhật, mới nhất ở đầu — mỗi ngày là một trang tổng hợp riêng.</p>'
        . '<div class="page-facts"><span><b>' . count($days) . '</b> ngày cập nhật</span><span><b>' . $totalPosts . '</b> bài viết</span></div>'
        . '</header>'
        . $body
        . '<section class="contact-mini"><span class="sec-kicker">Liên hệ</span>'
        . '<h2>Cần tư vấn thiết kế &amp; thi công nội thất?</h2>'
        . '<p>Gọi <a href="' . NHADEP_HOTLINE_TEL . '">' . NHADEP_HOTLINE_TEXT . '</a> hoặc email '
        . '<a href="mailto:' . nhadep_san(NHADEP_EMAIL) . '">' . nhadep_san(NHADEP_EMAIL) . '</a>'
        . ' — Đạt Kỳ tư vấn miễn phí tại Nghệ An &amp; Hà Tĩnh.</p>'
        . '</section>'
        . '</main>'
        . nhadep_footer_html()
        . "\n</body>\n</html>\n";
}

/** Render sitemap.xml covering index + every daily page. */
function nhadep_render_sitemap(array $days, array $lastmods): string
{
    $today = gmdate('Y-m-d');
    $xml   = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $xml .= "\t<url>\n\t\t<loc>" . NHADEP_CANONICAL . "</loc>\n\t\t<lastmod>"
         . htmlspecialchars($lastmods['index'] ?? $today, ENT_QUOTES) . "</lastmod>\n\t</url>\n";
    // Archive hub /tin-tuc/ — rebuilt whenever any day changes, so its
    // lastmod tracks the newest day lastmod (order-independent: max of all).
    $dayLms   = array_values($lastmods['days'] ?? []);
    $archiveLm = $dayLms !== [] ? max($dayLms) : ($lastmods['index'] ?? $today);
    $xml .= "\t<url>\n\t\t<loc>" . NHADEP_CANONICAL . "tin-tuc/</loc>\n\t\t<lastmod>"
         . htmlspecialchars($archiveLm, ENT_QUOTES) . "</lastmod>\n\t</url>\n";
    foreach (array_keys($days) as $key) {
        $s  = $days[$key]['slug'] ?? $key;
        $lm = $lastmods['days'][$key] ?? $today;
        $xml .= "\t<url>\n\t\t<loc>" . NHADEP_CANONICAL . $s . "/</loc>\n\t\t<lastmod>"
             . htmlspecialchars($lm, ENT_QUOTES) . "</lastmod>\n\t</url>\n";
    }
    return $xml . "</urlset>\n";
}

function nhadep_render_robots(): string
{
    return "User-agent: *\nAllow: /\n\nSitemap: " . NHADEP_CANONICAL . "sitemap.xml\n";
}

/**
 * Minimal RSS 2.0 feed with the latest 20 posts across all days.
 *
 * Every item links to the DAY landing page that actually contains the post,
 * never to the post's own source slug (which the generator does not emit as a
 * page). $daySlugByPostId maps post id (int) to that day's generated slug
 * (string); when a post has no mapping (or an unusable one) the original
 * allowlisted source link is used instead, so the feed never advertises a
 * nhadepnghean.vn URL that was not generated. Posts without any allowlisted
 * link are skipped, as before.
 *
 * @param array<int,array> $latestPosts     newest-first posts
 * @param array<int,string> $daySlugByPostId post id => day landing-page slug
 */
function nhadep_render_feed(array $latestPosts, string $contactUrl, array $daySlugByPostId = []): string
{
    $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<rss version="2.0"><channel>' . "\n"
        . '<title>' . $esc(NHADEP_FEED_TITLE) . '</title>' . "\n"
        . '<link>' . $esc(NHADEP_CANONICAL) . '</link>' . "\n"
        . '<description>' . $esc('Tin tức nội thất theo ngày từ Nội thất Đạt Ký — thi công nội thất trọn gói tại Nghệ An.') . '</description>' . "\n"
        . '<language>vi</language>' . "\n";

    foreach ($latestPosts as $p) {
        $link = nhadep_safe_url($p['link']);
        if ($link === '') {
            continue;
        }
        $daySlug = $daySlugByPostId[(int) $p['id']] ?? '';
        $daySlug = is_string($daySlug) ? trim($daySlug) : '';
        // Only trust a well-formed slug: a malformed mapping must not become
        // a fabricated nhadepnghean.vn URL either.
        if ($daySlug !== '' && preg_match('/^[a-z0-9][a-z0-9-]*$/', $daySlug) !== 1) {
            $daySlug = '';
        }
        $pageLink = $daySlug !== '' ? NHADEP_CANONICAL . $daySlug . '/' : $link;
        try {
            $pub = new DateTimeImmutable($p['date_gmt'], new DateTimeZone('UTC'));
            $pubStr = $pub->format(DateTimeInterface::RSS);
        } catch (Exception) {
            $pubStr = gmdate(DateTimeInterface::RSS);
        }
        $title = trim(strip_tags(html_entity_decode($p['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $xml .= "<item>\n<title>" . $esc($title) . "</title>\n"
            . '<link>' . $esc(html_entity_decode($pageLink, ENT_QUOTES, 'UTF-8')) . "</link>\n"
            . '<guid isPermaLink="false">ntdk-post-' . (int) $p['id'] . "</guid>\n"
            . '<pubDate>' . $pubStr . "</pubDate>\n"
            . '<description>' . $esc(mb_substr(trim(strip_tags(html_entity_decode($p['excerpt'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 300)) . "</description>\n</item>\n";
    }
    return $xml . "</channel></rss>\n";
}

/* -------------------------------------------------------------------------
 * CLI parsing + main orchestration
 * ---------------------------------------------------------------------- */

function nhadep_print_help(): void
{
    echo <<<HELP
generate_nhadep_daily.php — sinh trang tĩnh tin tức hàng ngày cho nhadepnghean.vn

Cách dùng:
  php generate_nhadep_daily.php [các tuỳ chọn]

Tuỳ chọn:
  --date=YYYY-MM-DD  Chỉ sinh lại trang của một ngày cụ thể.
  --force            Sinh lại mọi trang bỏ qua kiểm tra idempotency.
  --max-posts=N      Giới hạn số bài viết lấy từ API (mặc định: tất cả).
  --out=DIR          Thư mục xuất kết quả (mặc định: ./out).
  --verbose          In log chi tiết.
  --help             Hiện trợ giúp này.

HELP;
}

/**
 * Parse CLI args into options array. Exits with code 2 on bad input
 * unless invoked through run_main programmatically (then throws).
 */
function nhadep_parse_args(array $argv, bool $throwOnBad = false): array
{
    $opts = [
        'date'      => null,
        'force'     => false,
        'maxPosts'  => 0,
        'outDir'    => 'out',
        'verbose'   => false,
        'help'      => false,
    ];
    $bad = null;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--force') {
            $opts['force'] = true;
        } elseif ($arg === '--verbose') {
            $opts['verbose'] = true;
        } elseif ($arg === '--help') {
            $opts['help'] = true;
        } elseif (str_starts_with($arg, '--date=')) {
            $val = substr($arg, 7);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)
                || !checkdate((int) substr($val, 5, 2), (int) substr($val, 8, 2), (int) substr($val, 0, 4))) {
                $bad = "--date phải có dạng YYYY-MM-DD và là ngày hợp lệ (nhận được: {$val})";
            } else {
                $opts['date'] = $val;
            }
        } elseif (str_starts_with($arg, '--max-posts=')) {
            $val = substr($arg, 12);
            if (!preg_match('/^\d+$/', $val) || (int) $val < 1) {
                $bad = "--max-posts phải là số nguyên dương (nhận được: {$val})";
            } else {
                $opts['maxPosts'] = (int) $val;
            }
        } elseif (str_starts_with($arg, '--out=')) {
            $val = substr($arg, 6);
            if ($val === '') {
                $bad = '--out không được để trống';
            } else {
                $opts['outDir'] = $val;
            }
        } else {
            $bad = "Tuỳ chọn không nhận dạng được: {$arg}";
        }
    }

    if ($bad !== null) {
        if ($throwOnBad) {
            throw new InvalidArgumentException($bad);
        }
        fwrite(STDERR, 'LỖI: ' . $bad . PHP_EOL);
        exit(2);
    }
    return $opts;
}

/**
 * Main entry point. Returns process exit code (0 = success).
 */
function run_main(array $argv): int
{
    try {
        $opts = nhadep_parse_args($argv);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, 'LỖI: ' . $e->getMessage() . PHP_EOL);
        return 2;
    }

    if ($opts['help']) {
        nhadep_print_help();
        return 0;
    }

    $GLOBALS['NHADEP_VERBOSE'] = $opts['verbose'];

    try {
        $outRoot = nhadep_resolve_out_dir($opts['outDir']);
        nhadep_log("Thư mục xuất: {$outRoot}");

        // Local webfonts must exist on disk before any page references them.
        nhadep_write_font_assets($outRoot);
        nhadep_log('Đã ghi ' . count(nhadep_font_assets()) . ' file font local vào assets/fonts/.');

        $contactUrl = nhadep_contact_url($opts['verbose']);

        // ---- Fetch -------------------------------------------------------
        $posts = nhadep_fetch_all_posts($opts['maxPosts'], $opts['verbose']);
        nhadep_log('Đã tải tổng cộng ' . count($posts) . ' bài viết.');

        $groups = nhadep_group_by_day($posts);
        $dayKeys = array_keys($groups); // newest-first
        nhadep_log('Số ngày có bài viết: ' . count($dayKeys));

        // Flat newest-first list shared by index grid + RSS feed.
        $latestAll = [];
        foreach ($dayKeys as $key) {
            foreach ($groups[$key] as $p) {
                $latestAll[] = $p;
            }
        }

        // ---- Idempotency state ------------------------------------------
        $state = nhadep_read_state($outRoot);

        // ---- Daily pages -------------------------------------------------
        $generated = 0;
        $skipped   = 0;
        $lastmods  = ['index' => gmdate('Y-m-d'), 'days' => []];

        $orderedDays = array_reverse($dayKeys); // oldest-first for prev/next math
        $dayMetaNewestFirst = [];

        // Resolve a stable landing-page slug per day: stored slugs win
        // (URL stability), fresh days derive from their newest post.
        $slugByKey = nhadep_resolve_day_slugs($groups, $state['slugs']);
        foreach ($slugByKey as $key => $s) {
            if (($state['slugs'][$key] ?? null) !== $s) {
                $state['slugs'][$key] = $s;
                if ($opts['verbose']) {
                    nhadep_log("Gán slug cố định cho {$key}: {$s}", 'DEBUG');
                }
            }
        }

        foreach ($orderedDays as $idx => $key) {
            $dayPosts = $groups[$key];
            $hash     = nhadep_day_hash($dayPosts);
            $slug     = $slugByKey[$key];
            $fileRel  = $slug . '/index.html';

            $prev = $idx > 0 ? ['key' => $orderedDays[$idx - 1], 'slug' => $slugByKey[$orderedDays[$idx - 1]]] : null;
            $next = $idx < count($orderedDays) - 1 ? ['key' => $orderedDays[$idx + 1], 'slug' => $slugByKey[$orderedDays[$idx + 1]]] : null;

            $unchanged = isset($state['days'][$key]) && $state['days'][$key] === $hash;
            if (!$opts['force'] && $unchanged && !is_file($outRoot . '/' . $fileRel)) {
                // state says unchanged but file missing -> regenerate anyway
                $unchanged = false;
            }

            if ($opts['date'] !== null && $key !== $opts['date']) {
                // Single-day mode: only touch the requested day; record meta either way.
                $dayMetaNewestFirst[$key] = nhadep_day_meta($dayPosts, $slug);
                $lastmods['days'][$key] = nhadep_lastmod_of_day($dayPosts);
                continue;
            }

            if ($unchanged && !$opts['force']) {
                $skipped++;
                if ($opts['verbose']) {
                    nhadep_log("Bỏ qua {$key} (không thay đổi)", 'DEBUG');
                }
            } else {
                $html = nhadep_render_daily_page($key, $slug, $dayPosts, $contactUrl, $prev, $next);
                nhadep_write_file($outRoot, $fileRel, $html);
                $generated++;
                nhadep_log("Đã sinh trang ngày {$key} ({$fileRel})");
            }

            $state['days'][$key]         = $hash;
            $dayMetaNewestFirst[$key]    = nhadep_day_meta($dayPosts, $slug);
            $lastmods['days'][$key]       = nhadep_lastmod_of_day($dayPosts);
        }

        // Newest-first ordering for index/feed/archive rendering.
        $dayMetaNewestFirst = array_reverse($dayMetaNewestFirst, true);

        if ($opts['date'] !== null && !isset($groups[$opts['date']])) {
            nhadep_log("--date={$opts['date']} nhưng không có bài viết nào trong ngày đó.", 'WARN');
        }

        // ---- Landing page / archive / sitemap / robots / feed (always regenerated) --
        $totalPosts = array_sum(array_map(static fn ($m) => (int) $m['count'], $dayMetaNewestFirst));

        [$heroW, $heroH] = nhadep_hero_dims($posts[0] ?? []);
        nhadep_write_file($outRoot, 'index.html', nhadep_render_index_page(
            $dayMetaNewestFirst,
            $totalPosts,
            $contactUrl,
            $latestAll,
            nhadep_hero_src($posts[0] ?? []),
            $heroW,
            $heroH
        ));
        nhadep_log('Đã cập nhật index.html');

        nhadep_write_file($outRoot, 'tin-tuc/index.html', nhadep_render_archive($dayMetaNewestFirst));
        nhadep_log('Đã cập nhật tin-tuc/index.html (lưu trữ)');

        $newestGmt = $posts[0]['date_gmt'];
        try {
            $lastmods['index'] = (new DateTimeImmutable($newestGmt, new DateTimeZone('UTC')))
                ->setTimezone(nhadep_tz())->format('Y-m-d');
        } catch (Exception) {
            // leave default
        }

        nhadep_write_file($outRoot, 'sitemap.xml', nhadep_render_sitemap($dayMetaNewestFirst, $lastmods));
        nhadep_write_file($outRoot, 'robots.txt', nhadep_render_robots());
        nhadep_log('Đã cập nhật sitemap.xml, robots.txt');

        // post id => day landing slug, so the feed links to real day pages.
        $daySlugByPostId = [];
        foreach ($groups as $key => $dayPosts) {
            foreach ($dayPosts as $p) {
                $daySlugByPostId[(int) $p['id']] = $slugByKey[$key] ?? '';
            }
        }

        $latest = array_slice($latestAll, 0, 20); // newest-first
        nhadep_write_file($outRoot, 'feed.xml', nhadep_render_feed($latest, $contactUrl, $daySlugByPostId));
        nhadep_log('Đã cập nhật feed.xml (' . count($latest) . ' mục mới nhất)');

        nhadep_write_state($outRoot, $state);
        nhadep_log("Hoàn tất: {$generated} trang mới, {$skipped} trang bỏ qua (không đổi).");
        return 0;
    } catch (Throwable $e) {
        nhadep_log('LỖI CHÍNH: ' . $e->getMessage(), 'ERROR');
        return 1;
    }
}

/** Deterministic lastmod (Y-m-d) for a day bucket = newest date_gmt in VN tz. */
function nhadep_lastmod_of_day(array $dayPosts): string
{
    $max = '';
    foreach ($dayPosts as $p) {
        if ($p['date_gmt'] > $max) {
            $max = $p['date_gmt'];
        }
    }
    try {
        return (new DateTimeImmutable($max, new DateTimeZone('UTC')))
            ->setTimezone(nhadep_tz())->format('Y-m-d');
    } catch (Exception) {
        return gmdate('Y-m-d');
    }
}

/* -------------------------------------------------------------------------
 * Entry guard: do nothing when included by tests (NHADEP_NO_RUN defined)
 * or when loaded outside CLI.
 * ---------------------------------------------------------------------- */
if (!defined('NHADEP_NO_RUN')) {
    if (PHP_SAPI === 'cli') {
        exit(run_main($argv));
    }
    // Web trigger with secret key: generate static pages into this directory.
    // Usage: https://nhadepnghean.vn/generate_nhadep_daily.php?key=datky-nhadep-gen-2026[&force=1]
    $webKey = isset($_GET['key']) ? (string) $_GET['key'] : '';
    if ($webKey !== '' && hash_equals(NHADEP_WEB_KEY, $webKey)) {
        $fakeArgv = array('generate_nhadep_daily.php', '--out=' . __DIR__);
        if (isset($_GET['force'])) {
            $fakeArgv[] = '--force';
        }
        header('Content-Type: text/plain; charset=utf-8');
        exit(run_main($fakeArgv));
    }
    http_response_code(403);
    exit('Chi chay qua CLI.');
}
