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
 * Only allow URLs that start with https://noithatdatky.com/
 * Returns the escaped URL for embedding, or '' when rejected.
 */
function nhadep_safe_url(?string $url): string
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
    return htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
            }

            $posts[] = [
                'id'         => $id,
                'link'       => $link,
                'title'      => is_string($title) ? $title : '',
                'excerpt'    => is_string($excerpt) ? $excerpt : '',
                'thumb'      => is_string($thumb) ? $thumb : '',
                'thumb_w'    => $tw,
                'thumb_h'    => $th,
                'date_gmt'   => $dateGmt,
                'date_local' => is_string($p['date'] ?? null) ? $p['date'] : '',
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
    return <<<'CSS'
:root{--font-sans:"Be Vietnam Pro",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;--font-serif:"Cormorant Garamond","Hoefler Text","Iowan Old Style",Georgia,"Times New Roman",serif;--amber:#B45309;--amber-dk:#92400E;--ink:#1C1917;--bg:#FAF7F2;--gold:#D4A853;--gold-soft:#F1E4CE;--card:#FFFFFF;--line:#EADFD4;--muted:#6B5B4E;--cream-txt:#EFE6D8}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;-webkit-text-size-adjust:100%}
body{font-family:var(--font-sans);line-height:1.7;color:var(--ink);background:var(--bg)}
h1,h2,h3,.serif{font-family:var(--font-serif);line-height:1.28;font-weight:700}
img{max-width:100%}
a{color:var(--amber)}
.wrap{max-width:1100px;margin:0 auto;padding:0 20px}

/* ---- sticky top bar ---- */
.topbar{position:sticky;top:0;z-index:60;background:rgba(28,25,23,.97);border-bottom:2px solid var(--amber)}
.topbar .wrap{display:flex;align-items:center;gap:16px;min-height:62px;flex-wrap:wrap;padding-top:8px;padding-bottom:8px}
.brand{display:flex;align-items:center;gap:10px;color:var(--bg);text-decoration:none;font-family:var(--font-serif);font-size:.98rem;letter-spacing:.08em;text-transform:uppercase;white-space:nowrap}
.brand b{color:var(--gold);font-weight:700}
.topnav{display:flex;gap:2px;margin-left:auto;align-items:center}
.topnav a{color:var(--cream-txt);text-decoration:none;font-size:.86rem;padding:7px 11px;border-radius:8px;letter-spacing:.03em}
.topnav a:hover{color:#fff;background:rgba(212,168,83,.18)}
.hotline-btn{display:inline-flex;align-items:center;gap:7px;background:var(--amber);color:#fff;text-decoration:none;font-weight:700;font-size:.87rem;padding:9px 17px;border-radius:999px;letter-spacing:.02em;white-space:nowrap;transition:background .2s ease,transform .2s ease}
.hotline-btn:hover{background:var(--amber-dk);transform:translateY(-1px)}

/* ---- hero ---- */
.hero{position:relative;min-height:70vh;display:flex;align-items:center;color:var(--bg);overflow:hidden;background:#1C1917}
.hero::before{content:"";position:absolute;inset:0;background:linear-gradient(155deg,#33261A 0%,#1C1917 55%,#4A321B 100%)}
.hero-bg{position:absolute;inset:0;background-size:cover;background-position:center;opacity:.36}
.hero-scrim{position:absolute;inset:0;background:linear-gradient(180deg,rgba(28,25,23,.5) 0%,rgba(28,25,23,.28) 45%,rgba(28,25,23,.94) 100%)}
.hero-inner{position:relative;z-index:1;width:100%;max-width:1100px;margin:0 auto;padding:96px 20px 88px}
.kicker{display:inline-block;color:var(--gold);border:1px solid rgba(212,168,83,.55);padding:7px 16px;border-radius:999px;font-size:.72rem;letter-spacing:.3em;text-transform:uppercase;margin-bottom:24px}
.hero h1{font-size:clamp(2.05rem,5.4vw,3.5rem);max-width:22ch;color:#FAF7F2;letter-spacing:.01em;text-shadow:0 2px 18px rgba(0,0,0,.35)}
.hero .sub{margin-top:18px;max-width:58ch;color:#DFD2BC;font-size:1.04rem}
.cta-row{display:flex;gap:14px;margin-top:32px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:9px;text-decoration:none;font-weight:700;padding:14px 27px;border-radius:10px;font-size:.95rem;transition:transform .2s ease,box-shadow .2s ease,background .2s ease}
.btn-solid{background:var(--amber);color:#fff}
.btn-solid:hover{background:var(--amber-dk);transform:translateY(-2px);box-shadow:0 12px 26px rgba(180,83,9,.38)}
.btn-outline{border:1.5px solid var(--gold);color:var(--gold)}
.btn-outline:hover{background:rgba(212,168,83,.16)}
.trust-row{display:flex;gap:24px;flex-wrap:wrap;margin-top:36px;color:#CDBDA2;font-size:.88rem}
.trust-row span::before{content:"\2713\00a0\00a0";color:var(--gold);font-weight:700}

/* ---- section scaffolding ---- */
.band{padding:74px 0}
.band.alt{background:#F3EBDE;border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
.sec-head{text-align:center;max-width:680px;margin:0 auto 46px}
.sec-kicker{display:block;color:var(--amber);font-size:.76rem;letter-spacing:.26em;text-transform:uppercase;font-weight:700}
.sec-head h2{font-size:clamp(1.65rem,3.4vw,2.35rem);margin-top:10px;color:var(--ink)}
.sec-head p{color:var(--muted);margin-top:12px}
.rule{width:64px;height:3px;background:var(--gold);margin:18px auto 0;border-radius:2px}

/* ---- latest-news grid (#tin-moi) ---- */
.news-grid{list-style:none;display:grid;gap:26px;grid-template-columns:1fr}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}
.pcard{position:relative;background:var(--card);border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:0 2px 12px rgba(28,25,23,.06);transition:transform .25s ease,box-shadow .25s ease;display:flex;flex-direction:column}
.pcard:hover{transform:translateY(-6px);box-shadow:0 20px 40px rgba(28,25,23,.15)}
.pcard-media{position:relative;aspect-ratio:16/10;overflow:hidden;background:var(--gold-soft)}
.pcard-media img{width:100%;height:100%;object-fit:cover;transition:transform .45s ease}
.pcard:hover .pcard-media img{transform:scale(1.07)}
.date-badge{position:absolute;left:13px;top:13px;background:rgba(28,25,23,.9);color:var(--bg);border-radius:10px;padding:6px 11px;text-align:center;line-height:1.15}
.date-badge b{display:block;font-family:var(--font-serif);font-size:1.2rem;color:var(--gold)}
.date-badge small{font-size:.66rem;letter-spacing:.1em;text-transform:uppercase}
.chip{position:absolute;right:13px;top:13px;background:var(--amber);color:#fff;font-size:.68rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:4px 11px;border-radius:999px}
.pcard-body{padding:20px 22px 22px;display:flex;flex-direction:column;gap:10px;flex:1}
.pcard-body h3{font-size:1.1rem}
.pcard-body h3 a{color:var(--ink);text-decoration:none}
.pcard-body h3 a:hover{color:var(--amber)}
.clamp2{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;color:var(--muted);font-size:.9rem}
.more-link{margin-top:auto;color:var(--amber);text-decoration:none;font-weight:700;font-size:.87rem;letter-spacing:.02em}
.more-link:hover{color:var(--amber-dk)}

/* ---- day landing page (slug) + hub/archive (defined below) ---- */

/* ---- about (#gioi-thieu) ---- */
.about-grid{display:grid;gap:44px;grid-template-columns:1fr;align-items:start}
.about-copy p{color:#4A4038;margin-top:14px}
.about-copy .lead{font-size:1.05rem;color:var(--ink)}
.about-list{list-style:none;margin-top:18px;display:grid;gap:8px}
.about-list li{padding-left:26px;position:relative;color:#4A4038;font-size:.93rem}
.about-list li::before{content:"\2713";position:absolute;left:0;color:var(--amber);font-weight:700}
.svc-grid{display:grid;gap:18px;grid-template-columns:repeat(2,1fr)}
.svc{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:24px 22px;transition:transform .2s ease,box-shadow .2s ease}
.svc:hover{transform:translateY(-4px);box-shadow:0 14px 30px rgba(28,25,23,.1)}
.svc svg{width:36px;height:36px;color:var(--amber)}
.svc h3{font-size:1.02rem;margin-top:14px}
.svc p{color:var(--muted);font-size:.85rem;margin-top:6px}

/* ---- stats band ---- */
.stats{background:linear-gradient(135deg,#92400E 0%,#B45309 70%,#C05F14 100%);color:#FFF6E8;padding:60px 0}
.stats-grid{display:grid;gap:26px;grid-template-columns:repeat(3,1fr);text-align:center}
.stat b{display:block;font-family:var(--font-serif);font-size:clamp(1.9rem,4.4vw,2.8rem);color:#FFE3B3}
.stat span{display:block;font-size:.84rem;letter-spacing:.09em;text-transform:uppercase;margin-top:6px;opacity:.95}

/* ---- contact strip (#lien-he) ---- */
.contact-card{background:linear-gradient(150deg,#211B15,#1C1917 60%);color:var(--bg);border-radius:22px;padding:52px 46px;display:grid;gap:36px;grid-template-columns:1fr;box-shadow:0 24px 50px rgba(28,25,23,.25)}
.contact-card h2{font-size:clamp(1.5rem,3vw,2rem);color:#FAF7F2}
.contact-big{display:inline-block;font-family:var(--font-serif);font-size:clamp(1.7rem,4.6vw,2.7rem);color:var(--gold);text-decoration:none;margin-top:14px;letter-spacing:.02em}
.contact-big:hover{color:#E8BE72}
.contact-meta{display:grid;gap:10px;margin-top:22px;color:var(--cream-txt);font-size:.95rem}
.contact-meta a{color:var(--gold);text-decoration:none}
.contact-meta a:hover{text-decoration:underline}
.contact-actions{display:flex;flex-wrap:wrap;gap:14px;align-content:center}

/* ---- footer ---- */
footer.site{background:#171310;color:#B7A992;padding:48px 0 38px;font-size:.86rem}
footer.site .wrap{display:grid;gap:13px}
.f-brand{font-family:var(--font-serif);font-size:1.12rem;color:var(--bg);letter-spacing:.06em}
.f-brand b{color:var(--gold)}
footer.site a{color:var(--gold);text-decoration:none}
footer.site a:hover{text-decoration:underline}

/* ---- daily page ---- */
.crumbs{padding:20px 0 0;font-size:.83rem;color:var(--muted)}
.crumbs nav[aria-label] span[aria-current]{color:var(--ink)}
.crumbs a{color:var(--amber);text-decoration:none}
.crumbs a:hover{text-decoration:underline}
.day-hero{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px 26px;padding:30px 0 8px}
.day-num{font-family:var(--font-serif);font-size:clamp(4rem,13vw,6.8rem);line-height:.92;color:var(--amber);letter-spacing:-.01em}
.day-sub{padding-bottom:10px}
.day-sub .month{font-family:var(--font-serif);font-size:clamp(1.25rem,3vw,1.7rem);color:var(--ink)}
.day-sub .dow{color:var(--muted);font-size:.95rem;margin-top:2px}
.count-badge{align-self:center;background:var(--ink);color:var(--gold);border-radius:999px;padding:9px 20px;font-weight:700;font-size:.86rem;letter-spacing:.03em}
.post-list{list-style:none;display:grid;gap:22px;margin:34px 0 8px}
.post-card{display:flex;gap:22px;background:var(--card);border:1px solid var(--line);border-radius:18px;padding:20px;box-shadow:0 2px 12px rgba(28,25,23,.05);transition:transform .2s ease,box-shadow .2s ease}
.post-card:hover{transform:translateY(-3px);box-shadow:0 16px 34px rgba(28,25,23,.12)}
.post-thumb{flex:0 0 40%;min-height:180px;border-radius:12px;overflow:hidden;background:var(--gold-soft)}
.post-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.post-body{flex:1;display:flex;flex-direction:column}
.post-card h2,.post-card h3{font-size:1.18rem}
.post-card h2 a,.post-card h3 a{color:var(--ink);text-decoration:none}
.post-card h2 a:hover,.post-card h3 a:hover{color:var(--amber)}
.meta{font-size:.76rem;color:var(--muted);margin-top:8px;letter-spacing:.06em;text-transform:uppercase}
.excerpt{font-size:.93rem;color:var(--muted);margin-top:9px;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.readorig{display:inline-block;margin-top:auto;padding-top:12px;color:var(--amber);font-weight:700;font-size:.87rem;text-decoration:none}
.readorig:hover{color:var(--amber-dk);text-decoration:underline}
.day-nav-cards{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin:40px 0 14px}
.dnav{display:block;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px 20px;text-decoration:none;transition:border-color .2s ease,transform .2s ease,box-shadow .2s ease}
.dnav:hover{border-color:var(--amber);transform:translateY(-3px);box-shadow:0 10px 24px rgba(28,25,23,.09)}
.dnav small{display:block;color:var(--muted);font-size:.71rem;letter-spacing:.12em;text-transform:uppercase;margin-bottom:6px}
.dnav b{font-family:var(--font-serif);font-size:.98rem;color:var(--ink);font-weight:700}
.dnav.next{text-align:right}
.dnav.empty{opacity:.45;pointer-events:none}
.home-link-row{text-align:center;margin:22px 0 8px}
.home-link-row a{display:inline-flex;align-items:center;gap:8px;background:var(--card);border:1.5px solid var(--gold);color:var(--amber-dk);font-weight:700;text-decoration:none;padding:12px 26px;border-radius:999px;font-size:.9rem}
.home-link-row a:hover{background:var(--gold-soft)}
.contact-mini{background:var(--card);border:1px solid var(--line);border-left:4px solid var(--amber);border-radius:16px;padding:26px 28px;margin:14px 0 48px;display:grid;gap:8px;box-shadow:0 2px 12px rgba(28,25,23,.05)}
.contact-mini h2{font-size:1.12rem}
.contact-mini p{color:#4A4038;font-size:.92rem}
.contact-mini a{color:var(--amber);text-decoration:none;font-weight:600}
.contact-mini a:hover{text-decoration:underline}

/* ---- responsive ---- */
@media(min-width:600px){.news-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:720px){.post-card{flex-direction:column}.post-thumb{flex-basis:auto;min-height:0;aspect-ratio:16/10}}
@media(min-width:900px){.news-grid{grid-template-columns:repeat(3,1fr)}.about-grid{grid-template-columns:1.05fr 1fr}.contact-card{grid-template-columns:1.4fr 1fr;align-items:center}}
@media(max-width:420px){.topbar .wrap{gap:10px}.brand{font-size:.88rem}.hotline-btn{padding:8px 14px;font-size:.8rem}.svc-grid{grid-template-columns:1fr}.stats-grid{grid-template-columns:1fr;gap:30px}.day-nav-cards{grid-template-columns:1fr}}

/* ---- daily landing page (slug) + hub/archive ---- */
.hero.day-hero-page{position:relative;min-height:64vh;display:flex;align-items:center;background-size:cover;background-position:center;background-color:var(--ink)}
.hero.day-hero-page .hero-scrim{position:absolute;inset:0;background:linear-gradient(180deg,rgba(28,25,23,.55),rgba(28,25,23,.82))}
.hero.day-hero-page .hero-inner{position:relative;z-index:1;max-width:820px}
.hero.day-hero-page h1{color:#fff;font-size:clamp(1.9rem,4.4vw,3.3rem);line-height:1.08;margin:14px 0 14px;text-shadow:0 2px 18px rgba(0,0,0,.35)}
.feature-wrap{margin:30px 0}
.feature-card{display:grid;grid-template-columns:1.1fr 1fr;gap:0;background:var(--card);border:1px solid var(--line);border-radius:18px;overflow:hidden;text-decoration:none;box-shadow:0 4px 18px rgba(28,25,23,.06);transition:transform .25s ease,box-shadow .25s ease}
.feature-card:hover{transform:translateY(-4px);box-shadow:0 22px 44px rgba(28,25,23,.15)}
.feature-media{background:linear-gradient(135deg,var(--gold-soft),var(--amber-soft));min-height:200px}
.feature-body{padding:28px 30px;display:flex;flex-direction:column;gap:10px;justify-content:center}
.feature-body h2{font-size:1.5rem;color:var(--ink);line-height:1.15}
.feature-body p{color:var(--muted)}
.recent-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:8px}
.day-cell{display:flex;flex-direction:column;gap:6px;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px 16px;text-decoration:none;transition:border-color .2s ease,transform .2s ease}
.day-cell:hover{border-color:var(--amber);transform:translateY(-3px)}
.day-cell b{font-family:var(--font-serif);font-size:1.02rem;color:var(--ink);font-weight:700;line-height:1.2}
.day-cell small{color:var(--muted);font-size:.74rem;letter-spacing:.06em;text-transform:uppercase}
.recent-more{margin-top:26px;text-align:center}
.archive-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin:28px 0 40px}
.archive-cell{display:flex;flex-direction:column;gap:6px;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px 16px;text-decoration:none;transition:border-color .2s ease,transform .2s ease}
.archive-cell:hover{border-color:var(--amber);transform:translateY(-3px)}
.archive-cell b{font-family:var(--font-serif);font-size:.98rem;color:var(--ink);font-weight:700}
.archive-cell small{color:var(--muted);font-size:.74rem}
@media(max-width:900px){.feature-card{grid-template-columns:1fr}.feature-media{min-height:160px}.recent-grid,.archive-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:520px){.recent-grid,.archive-grid{grid-template-columns:1fr}}
CSS;
}

/**
 * Common <head> fragment. $ogImage: safe featured-image URL for og:/twitter: tags.
 */
function nhadep_head(string $title, string $description, string $canonical, string $extraHead = '', string $ogImage = ''): string
{
    $t = nhadep_san($title);
    $d = nhadep_san($description);
    $c = htmlspecialchars($canonical, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $img = nhadep_safe_url($ogImage);
    $imgTags = '';
    if ($img !== '') {
        $alt = nhadep_san(NHADEP_BRAND_NAME . ' — ' . NHADEP_TAGLINE);
        $imgTags = '<meta property="og:image" content="' . $img . '">' . "\n"
            . '<meta property="og:image:alt" content="' . $alt . '">' . "\n"
            . '<meta name="twitter:image" content="' . $img . '">' . "\n";
    }
    return "<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&amp;family=Be+Vietnam+Pro:wght@400;500;600&amp;display=swap">' . "\n"
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
 */
function nhadep_header_html(bool $isHome): string
{
    $base = $isHome ? '#' : '/#';
    return '<header class="topbar"><div class="wrap">'
        . '<a class="brand" href="/' . '" aria-label="' . nhadep_san(NHADEP_BRAND_NAME) . ' — về trang chủ">NỘI THẤT ĐẠT KỲ <b>DK HOME</b></a>'
        . '<nav class="topnav" aria-label="Điều hướng chính">'
        . '<a href="' . $base . 'tin-moi">Tin tức</a>'
        . '<a href="' . $base . 'gioi-thieu">Giới thiệu</a>'
        . '<a href="' . $base . 'lien-he">Liên hệ</a>'
        . '</nav>'
        . '<a class="hotline-btn" href="' . NHADEP_HOTLINE_TEL . '">☎ ' . NHADEP_HOTLINE_TEXT . '</a>'
        . '</div></header>';
}

function nhadep_footer_html(): string
{
    $y  = date('Y');
    $b  = nhadep_safe_url(NHADEP_SOURCE_ORIGIN);
    $ct = nhadep_safe_url(NHADEP_CONTACT_URL);
    return '<footer class="site"><div class="wrap">'
        . '<div class="f-brand">NỘI THẤT ĐẠT KỲ — <b>DK HOME DESIGN</b></div>'
        . '<p>' . nhadep_san(NHADEP_TAGLINE) . '.</p>'
        . '<p>Tin tức tổng hợp từ <a href="' . $b . '" rel="noopener">noithatdatky.com</a> — DK Home Design · '
        . '<a href="' . $ct . '">Liên hệ</a> · <a href="/sitemap.xml">Sitemap</a></p>'
        . '<p>© ' . $y . ' DK Home Design. Địa chỉ: ' . nhadep_san(NHADEP_ADDRESS) . '.</p>'
        . '</div></footer>';
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

/** Landing-grid card (#tin-moi): 16:10 cover, date badge, chip, clamp, CTA to original post. */
function nhadep_post_grid_card(array $post): string
{
    $title  = nhadep_san(trim(strip_tags(html_entity_decode($post['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    $link   = nhadep_safe_url($post['link']);
    $linked = static fn (string $inner): string => $link !== ''
        ? '<a href="' . $link . '" rel="noopener">' . $inner . '</a>'
        : $inner;

    $media = '<div class="pcard-media">';
    $img   = nhadep_cover_img($post);
    if ($img !== '') {
        $media .= $img;
    }
    $dk = nhadep_day_key($post['date_gmt']) ?? '';
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $dk, nhadep_tz());
    $dN = $dt instanceof DateTimeImmutable ? $dt->format('j') : '–';
    $mN = $dt instanceof DateTimeImmutable ? 'Tháng ' . $dt->format('m') : '';
    $media .= '<div class="date-badge"><b>' . $dN . '</b><small>' . nhadep_san($mN) . '</small></div>'
        . '<span class="chip">Tin tức</span></div>';

    return '<article class="pcard">' . $media
        . '<div class="pcard-body"><h3>' . $linked($title) . '</h3>'
        . '<p class="clamp2">' . nhadep_excerpt_text($post) . '</p>'
        . '<a class="more-link" href="' . $link . '" rel="noopener">Xem bài viết &rarr;</a>'
        . '</div></article>';
}

/**
 * Daily-page article card (<li class="post-card">): image left ~40% on
 * desktop, stacked on mobile; links point at the ORIGINAL post.
 * $headingTag: h2 on the top story, h3 otherwise.
 */
function nhadep_post_card(array $post, string $headingTag = 'h3'): string
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
        $thumbHtml = '<div class="post-thumb">' . $img . '</div>';
    }
    $origCta = $link !== ''
        ? '<a class="readorig" href="' . $link . '" rel="noopener">Đọc bài gốc tại noithatdatky.com &rarr;</a>'
        : '';
    return '<article class="post-card">' . $thumbHtml
        . '<div class="post-body"><' . $headingTag . '>' . $titleHtml . '</' . $headingTag . '>'
        . '<div class="meta">Đăng lúc ' . $time . ' · ' . nhadep_day_label(nhadep_day_key($post['date_gmt']) ?? '') . '</div>'
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
    $label     = nhadep_day_label($dayKey);
    $canonical = NHADEP_CANONICAL . $slug . '/';
    $count     = count($dayPosts);

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $dayKey, nhadep_tz());
    $dN = $dt instanceof DateTimeImmutable ? $dt->format('d') : $dayKey;
    $mo = $dt instanceof DateTimeImmutable ? (int) $dt->format('n') : 0;
    $yN = $dt instanceof DateTimeImmutable ? $dt->format('Y') : '';
    $dows = [1 => 'Thứ Hai', 2 => 'Thứ Ba', 3 => 'Thứ Tư', 4 => 'Thứ Năm', 5 => 'Thứ Sáu', 6 => 'Thứ Bảy', 7 => 'Chủ Nhật'];
    $dow  = ($dt instanceof DateTimeImmutable && isset($dows[(int) $dt->format('N')]))
        ? $dows[(int) $dt->format('N')] : '';
    $ddmmyyyy = $dt instanceof DateTimeImmutable ? $dt->format('d-m-Y') : $dayKey;

    $primary   = $dayPosts[0] ?? ['title' => '', 'thumb' => ''];
    $heroTitle = nhadep_san(trim(strip_tags(html_entity_decode($primary['title'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    $heroImg   = nhadep_safe_url($primary['thumb'] ?? '');
    $heroBg    = $heroImg !== '' ? ' style="background-image:url(\'' . $heroImg . '\')"' : '';
    $icon = static fn (string $paths): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
    $svc  = static fn (string $iSvg, string $name, string $txt): string =>
        '<div class="svc">' . $iSvg . '<h3>' . $name . '</h3><p>' . $txt . '</p></div>';
    $brandServices = '<section id="gioi-thieu" class="band alt"><div class="wrap"><div class="about-grid">' 
        . '<div class="about-copy"><span class="sec-kicker">Về Đạt Kỳ</span>'
        . '<h2>' . nhadep_san(NHADEP_BRAND_NAME) . '</h2>'
        . '<p class="lead">Đơn vị thiết kế &amp; thi công nội thất trọn gói với xưởng sản xuất trực tiếp, đồng hành cùng khách hàng tại Nghệ An và Hà Tĩnh.</p>'
        . '<ul class="about-list"><li>Xưởng sản xuất trực tiếp — giá tốt hơn 15–25%</li><li>Đội ngũ kiến trúc sư &amp; thợ lành nghề</li><li>Bảo hành dài hạn, hỗ trợ trọn đời sản phẩm</li></ul>'
        . '</div><div class="svc-grid">' 
        . $svc($icon('<path d="M3 11 12 4l9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>'), 'Nội thất nhà phố', 'Thiết kế thông minh cho mặt tiền hẹp, tối ưu từng mét vuông.')
        . $svc($icon('<path d="M3 21h18"/><path d="M6 21V9l6-5 6 5v12"/><path d="M9 21v-4h6v4"/><circle cx="17.5" cy="5.5" r="1.5"/>'), 'Biệt thự &amp; Villa', 'Không gian sang trọng, đồng bộ phong cách từ ngoài vào trong.')
        . $svc($icon('<rect x="5" y="3" width="14" height="18" rx="1"/><path d="M9 7h2m2 0h2M9 11h2m2 0h2M9 15h2m2 0h2"/><path d="M10 21v-3h4v3"/>'), 'Nội thất căn hộ', 'Giải pháp lưu trữ thông minh, hiện đại cho căn hộ đô thị.')
        . $svc($icon('<path d="M3 9h18"/><path d="M4 9v11m16-11v11"/><path d="M7 13h10"/><path d="M12 9V5h7"/>'), 'Nội thất văn phòng', 'Công năng hiệu suất cao, thẩm mỹ chuẩn thương hiệu doanh nghiệp.')
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
        $items .= nhadep_post_card($post, $i === 0 ? 'h2' : 'h3') . "\n";
    }

    $prevHtml = $prevDay !== null
        ? '<a class="dnav" href="/' . nhadep_san($prevDay['slug'] ?? '') . '/" rel="prev"><small>&larr; Ngày cũ hơn</small><b>' . nhadep_san(nhadep_day_label($prevDay['key'])) . '</b></a>'
        : '<div class="dnav empty"><small>&larr; Ngày cũ hơn</small><b>—</b></div>';
    $nextHtml = $nextDay !== null
        ? '<a class="dnav next" href="/' . nhadep_san($nextDay['slug'] ?? '') . '/" rel="next"><small>Ngày mới hơn &rarr;</small><b>' . nhadep_san(nhadep_day_label($nextDay['key'])) . '</b></a>'
        : '<div class="dnav empty next"><small>Ngày mới hơn &rarr;</small><b>—</b></div>';

    $cta   = htmlspecialchars($contactUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $email = nhadep_san(NHADEP_EMAIL);
    $tel   = NHADEP_HOTLINE_TEL;

    return '<!DOCTYPE html>' . "\n" . '<html lang="vi">' . "\n<head>\n"
        . nhadep_head('Tin tức ngày ' . $ddmmyyyy . ' — ' . NHADEP_BRAND_SHORT, $desc, $canonical, $extra, $dayPosts[0]['thumb'] ?? '')
        . "</head>\n<body>\n"
        . nhadep_header_html(false)
        . '<main>'
        . '<nav class="crumbs" aria-label="Breadcrumb"><a href="/">Trang chủ</a> &rsaquo; <a href="/#tin-moi">Tin tức</a> &rsaquo; <span aria-current="page">' . nhadep_san($ddmmyyyy) . '</span></nav>'
        . '<section id="hero" class="hero day-hero-page"' . $heroBg . ' aria-label="Tin tức nội thất ngày ' . nhadep_san($ddmmyyyy) . '">'
        . '<div class="hero-scrim" aria-hidden="true"></div>'
        . '<div class="hero-inner">' 
        . '<span class="kicker">TIN TỨC NỘI THẤT · ' . nhadep_san($dow) . ', ' . nhadep_san($ddmmyyyy) . '</span>'
        . '<h1>' . $heroTitle . '</h1>'
        . '<p class="sub">' . nhadep_san(NHADEP_TAGLINE) . '. Cập nhật hằng ngày từ ' . nhadep_san(NHADEP_BRAND_SHORT) . '.</p>'
        . '<div class="cta-row"><a class="btn btn-outline" href="#tin-ngay">Xem tin ngày</a>'
        . '<a class="btn btn-solid" href="' . NHADEP_HOTLINE_TEL . '">&#9742; Hotline ' . NHADEP_HOTLINE_TEXT . '</a></div>'
        . '<div class="trust-row"><span>Thi công trọn gói</span><span>Bảo hành dài hạn</span><span>Thiết kế miễn phí</span></div>'
        . '</div></section>'
        . '<section id="tin-ngay" class="band"><div class="wrap"><div class="sec-head"><span class="sec-kicker">Tin ngày</span><h2>Tin tức ngày ' . nhadep_san($ddmmyyyy) . '</h2><p>' . $count . ' bài viết mới nhất được tổng hợp từ noithatdatky.com.</p><span class="rule"></span></div>'
        . '<div class="post-list">' . "\n" . $items . '</div></div></section>'
        . '<nav class="day-nav-cards" aria-label="Điều hướng theo ngày"><div class="wrap">' . $prevHtml . $nextHtml . '</div></nav>'
        . $brandServices
        . '<section id="lien-he" class="band"><div class="wrap"><div class="contact-card">' 
        . '<div class="contact-info"><span class="sec-kicker">Liên hệ</span>'
        . '<h2>Nhận tư vấn &amp; báo giá miễn phí</h2>'
        . '<a class="contact-big" href="' . NHADEP_HOTLINE_TEL . '">' . NHADEP_HOTLINE_TEXT . '</a>'
        . '<div class="contact-meta"><span>&#9993; <a href="mailto:' . $email . '">' . $email . '</a></span>'
        . '<span>&#128205; ' . nhadep_san(NHADEP_ADDRESS) . '</span></div></div>'
        . '<div class="contact-actions">'
        . '<a class="btn btn-solid" href="' . NHADEP_HOTLINE_TEL . '">&#9742; Gọi ngay hotline</a>'
        . '<a class="btn btn-outline" href="' . nhadep_safe_url(NHADEP_SOURCE_ORIGIN) . '" rel="noopener">Truy cập noithatdatky.com</a>'
        . '<a class="more-link" style="color:var(--gold)" href="' . $cta . '">Hoặc xem trang Liên hệ &rarr;</a>'
        . '</div></div></div></section>'
        . '<div class="home-link-row"><a href="/">&larr; Về trang chủ</a></div>'
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
    string $heroImage = ''
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
        . '<span class="kicker">CỔNG THÔNG TIN NỘI THẤT</span>'
        . '<h1>Không gian sống đẳng cấp — Cập nhật mỗi ngày</h1>'
        . '<p class="sub">' . nhadep_san(NHADEP_TAGLINE) . '. Theo dõi kinh nghiệm thiết kế, mẫu nội thất và dự án thi công thực tế được tổng hợp mỗi ngày.</p>'
        . '<div class="cta-row">'
        . '<a class="btn btn-outline" href="#tin-moi">Đọc tin mới nhất</a>'
        . '<a class="btn btn-solid" href="' . NHADEP_HOTLINE_TEL . '">&#9742; Hotline ' . NHADEP_HOTLINE_TEXT . '</a>'
        . '</div>'
        . '<div class="trust-row"><span>Thi công trọn gói</span><span>Bảo hành dài hạn</span><span>Thiết kế miễn phí</span></div>'
        . '</div></section>';

    /* ---- #tin-moi: 6 newest posts ---- */
    $cards = '';
    foreach (array_slice($latestPosts, 0, 6) as $post) {
        $cards .= nhadep_post_grid_card($post) . "\n";
    }
    $newsSection = '<section id="tin-moi" class="band"><div class="wrap">'
        . '<div class="sec-head"><span class="sec-kicker">Tin tức</span><h2>Tin tức mới nhất</h2>'
        . '<p>Những bài viết chuyên sâu về thiết kế và thi công nội thất, cập nhật liên tục từ noithatdatky.com.</p><span class="rule"></span></div>'
        . ($cards !== '' ? '<div class="news-grid">' . "\n" . $cards . '</div>' : '')
        . '</div></section>';

    /* ---- #tin-theo-ngay: compact hub of recent days + archive link ---- */
    $rows    = nhadep_index_items($days); // newest-first
    $newest  = $rows[0] ?? null;
    $featured = '';
    if ($newest !== null) {
        $featured = '<a class="feature-card" href="' . $newest['url'] . '">'
            . '<div class="feature-media"></div>'
            . '<div class="feature-body"><span class="sec-kicker">Tin mới nhất</span>'
            . '<h2>' . nhadep_san($newest['teaser']) . '</h2>'
            . '<p>' . nhadep_san($newest['name']) . ' · ' . $newest['count'] . ' bài viết</p>'
            . '<span class="btn btn-outline">Xem trang tin ngày &rarr;</span></div></a>';
    }
    $recent = '';
    foreach (array_slice($rows, 0, 12) as $row) {
        $recent .= '<a class="day-cell" href="' . $row['url'] . '">'
            . '<b>' . nhadep_san($row['name']) . '</b>'
            . '<small>' . $row['count'] . ' bài</small></a>';
    }
    $recentSection = '<section id="tin-theo-ngay" class="band alt"><div class="wrap">'
        . '<div class="sec-head"><span class="sec-kicker">Lưu trữ</span><h2>Các ngày đã cập nhật</h2>'
        . '<p>' . count($days) . ' ngày tin tức, mới nhất đầu tiên.</p><span class="rule"></span></div>'
        . ($featured !== '' ? '<div class="feature-wrap">' . $featured . '</div>' : '')
        . '<div class="recent-grid">' . $recent . '</div>'
        . '<div class="recent-more"><a class="btn btn-outline" href="/tin-tuc/">Xem tất cả các ngày &rarr;</a></div>'
        . '</div></section>';

    /* ---- #gioi-thieu: split about + service cards ---- */
    $icon = static fn (string $paths): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
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
        . $svc($icon('<path d="M3 11 12 4l9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>'), 'Nội thất nhà phố', 'Thiết kế thông minh cho mặt tiền hẹp, tối ưu từng mét vuông.')
        . $svc($icon('<path d="M3 21h18"/><path d="M6 21V9l6-5 6 5v12"/><path d="M9 21v-4h6v4"/><circle cx="17.5" cy="5.5" r="1.5"/>'), 'Biệt thự &amp; Villa', 'Không gian sang trọng, đồng bộ phong cách từ ngoài vào trong.')
        . $svc($icon('<rect x="5" y="3" width="14" height="18" rx="1"/><path d="M9 7h2m2 0h2M9 11h2m2 0h2M9 15h2m2 0h2"/><path d="M10 21v-3h4v3"/>'), 'Nội thất căn hộ', 'Giải pháp lưu trữ thông minh, hiện đại cho căn hộ đô thị.')
        . $svc($icon('<path d="M3 9h18"/><path d="M4 9v11m16-11v11"/><path d="M7 13h10"/><path d="M12 9V5h7"/>'), 'Nội thất văn phòng', 'Công năng hiệu suất cao, thẩm mỹ chuẩn thương hiệu doanh nghiệp.')
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
        . '<a class="contact-big" href="' . NHADEP_HOTLINE_TEL . '">' . NHADEP_HOTLINE_TEXT . '</a>'
        . '<div class="contact-meta">'
        . '<span>&#9993; <a href="mailto:' . $email . '">' . $email . '</a></span>'
        . '<span>&#128205; ' . nhadep_san(NHADEP_ADDRESS) . '</span>'
        . '</div></div>'
        . '<div class="contact-actions">'
        . '<a class="btn btn-solid" href="' . NHADEP_HOTLINE_TEL . '">&#9742; Gọi ngay hotline</a>'
        . '<a class="btn btn-outline" href="' . $originLink . '" rel="noopener">Truy cập noithatdatky.com</a>'
        . '<a class="more-link" style="color:var(--gold)" href="' . $cta . '">Hoặc xem trang Liên hệ &rarr;</a>'
        . '</div></div></div></section>';

    return '<!DOCTYPE html>' . "\n" . '<html lang="vi">' . "\n<head>\n"
        . nhadep_head('Tin tức nội thất mỗi ngày — ' . NHADEP_BRAND_SHORT . ' Nghệ An & Hà Tĩnh', $desc, NHADEP_CANONICAL, $extra, $heroImage)
        . "</head>\n<body>\n"
        . nhadep_header_html(true)
        . '<main>'
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
    $rows  = [];
    $pos   = 1;
    foreach ($days as $key => $meta) { // $days passed newest-first
        $teaser = trim(strip_tags(html_entity_decode((string) $meta['first_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $rows[] = [
            'pos'    => $pos++,
            'key'    => $key,
            'name'   => nhadep_day_label($key),
            'count'  => (int) $meta['count'],
            'teaser' => nhadep_san($teaser),
            'url'    => '/' . ($meta['slug'] ?? $key) . '/',
        ];
    }
    return $rows;
}

/** Render the archive hub (out/tin-tuc/index.html): all days, newest-first. */
function nhadep_render_archive(array $days): string
{
    $cells = '';
    foreach ($days as $key => $meta) {
        $slug = $meta['slug'] ?? $key;
        $name = nhadep_day_label($key);
        $url  = '/' . $slug . '/';
        $cells .= '<a class="day-cell archive-cell" href="' . $url . '">'
            . '<b>' . nhadep_san($name) . '</b>'
            . '<small>' . (int) $meta['count'] . ' bài viết</small></a>';
    }
    // og:image: featured thumb of the newest day (days arrive newest-first);
    // fall back to the brand logo so the archive always carries an image.
    $ogImage = (string) (($days !== [] ? ($days[array_key_first($days)]['thumb'] ?? '') : ''));
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
        . nhadep_head('Lưu trữ tin tức — ' . NHADEP_BRAND_SHORT, $desc, NHADEP_CANONICAL . 'tin-tuc/', $extra, $ogImage)
        . "</head>\n<body>\n"
        . nhadep_header_html(false)
        . '<main class="wrap">' 
        . '<nav class="crumbs" aria-label="Breadcrumb"><a href="/">Trang chủ</a> &rsaquo; <span aria-current="page">Lưu trữ</span></nav>'
        . '<h1>Lưu trữ tin tức nội thất</h1>'
        . '<p class="lead">' . nhadep_san(NHADEP_TAGLINE) . '.</p>'
        . '<div class="archive-grid">' . $cells . '</div>'
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

        nhadep_write_file($outRoot, 'index.html', nhadep_render_index_page(
            $dayMetaNewestFirst,
            $totalPosts,
            $contactUrl,
            $latestAll,
            $posts[0]['thumb'] ?? ''
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
