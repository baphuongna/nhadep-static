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
        . '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600&amp;family=Be+Vietnam+Pro:wght@300;400;500;600;700&amp;display=swap">' . "\n"
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
        . '<style>' . nhadep_css() . '</style>' . "\n" . $extraHead;
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
