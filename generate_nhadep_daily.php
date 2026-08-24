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

/* -------------------------------------------------------------------------
 * HTTP layer — named functions so tests can override behaviour via
 * $GLOBALS['NHADEP_FETCH_OVERRIDE'] / $GLOBALS['NHADEP_STATUS_OVERRIDE'].
 * ---------------------------------------------------------------------- */

/**
 * Fetch a JSON document from $url.
 * Returns decoded array on success, false on any failure.
 * Tests may set $GLOBALS['NHADEP_FETCH_OVERRIDE'] = fn(string $url): array|false;
 */
function nhadep_fetch_json(string $url): array|false
{
    if (isset($GLOBALS['NHADEP_FETCH_OVERRIDE']) && is_callable($GLOBALS['NHADEP_FETCH_OVERRIDE'])) {
        return ($GLOBALS['NHADEP_FETCH_OVERRIDE'])($url);
    }

    $body = false;

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

        if ($code !== 200 || $raw === false) {
            nhadep_log("HTTP {$code} khi tải: {$url}" . ($err !== '' ? " ({$err})" : ''), 'WARN');
            return false;
        }
        $body = $raw;
    } else {
        // Fallback without curl.
        $ctx = stream_context_create([
            'http' => [
                'method'           => 'GET',
                'timeout'          => NHADEP_HTTP_TIMEOUT,
                'header'           => "User-Agent: nhadep-static-generator/1.0\r\n",
                'follow_location'  => 1,
                'max_redirects'    => 3,
                'ignore_errors'    => false,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            nhadep_log("Không thể tải: {$url}", 'WARN');
            return false;
        }
        // ignore_errors=false means 4xx/5xx raise a warning + false already.
        $body = $raw;
    }

    $data = json_decode($body, true);
    return is_array($data) ? $data : false;
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
        $dt = new DateTimeImmutable($wpLocalDate);
    } catch (Exception) {
        return nhadep_san($wpLocalDate);
    }
    $dt = $dt->setTimezone(nhadep_tz());
    return $dt->format('H:i');
}

/* -------------------------------------------------------------------------
 * Fetching + grouping
 * ---------------------------------------------------------------------- */

/**
 * Fetch all posts from the REST API (paginated), up to $maxPosts (0 = all).
 * Returns list of normalized post arrays:
 *   id, link, title_raw, excerpt_raw, thumb_raw, date_gmt, date_local
 */
function nhadep_fetch_all_posts(int $maxPosts, bool $verbose): array
{
    $posts   = [];
    $page    = 1;

    while ($page <= NHADEP_MAX_PAGES) {
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

        $batch = nhadep_fetch_json($url);
        if ($batch === false) {
            if ($page === 1) {
                throw new RuntimeException("Không tải được dữ liệu từ REST API (trang 1): {$url}");
            }
            nhadep_log("Trang {$page} lỗi, dừng phân trang.", 'WARN');
            break;
        }
        if ($batch === []) {
            break; // exhausted
        }

        foreach ($batch as $p) {
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

            // Featured image: prefer medium size, fall back to source_url.
            $thumb = '';
            if (isset($p['_embedded']['wp:featuredmedia'][0]) && is_array($p['_embedded']['wp:featuredmedia'][0])) {
                $fm = $p['_embedded']['wp:featuredmedia'][0];
                $thumb = $fm['media_details']['sizes']['medium']['source_url']
                    ?? $fm['media_details']['sizes']['thumbnail']['source_url']
                    ?? ($fm['source_url'] ?? '');
            }

            $posts[] = [
                'id'         => $id,
                'link'       => $link,
                'title'      => is_string($title) ? $title : '',
                'excerpt'    => is_string($excerpt) ? $excerpt : '',
                'thumb'      => is_string($thumb) ? $thumb : '',
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
        throw new RuntimeException('Đường đẫn chứa ký tự null.');
    }
    $target = $outRoot . DIRECTORY_SEPARATOR . $relPath;
    $parent = dirname($target);

    if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
        throw new RuntimeException("Không tạo được thư mục: {$parent}");
    }
    $realParent = realpath($parent);
    if ($realParent === false || !str_starts_with($realParent, $outRoot . DIRECTORY_SEPARATOR)
        && $realParent !== $outRoot) {
        throw new RuntimeException("Đường đẫn ghi nằm ngoài thư mục out: {$relPath}");
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
    $f = $outRoot . '/.state.json';
    if (!is_file($f)) {
        return ['days' => []];
    }
    $decoded = json_decode((string) file_get_contents($f), true);
    return is_array($decoded) && isset($decoded['days']) && is_array($decoded['days'])
        ? $decoded
        : ['days' => []];
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
:root{--ink:#22292f;--muted:#5b6670;--brand:#8a4b2a;--brand-dark:#6e3a1f;--bg:#faf7f3;--card:#ffffff;--line:#eadfd4}
*{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;line-height:1.65;color:var(--ink);background:var(--bg)}
.wrap{max-width:960px;margin:0 auto;padding:0 16px}
header.site{background:var(--brand);color:#fff;padding:18px 0}
header.site a.brand{color:#fff;text-decoration:none;font-size:1.25rem;font-weight:700}
header.site .tagline{font-size:.85rem;opacity:.9;margin-top:2px}
nav.day-nav{display:flex;justify-content:space-between;gap:12px;padding:14px 0;flex-wrap:wrap}
nav.day-nav a{color:var(--brand);text-decoration:none;font-weight:600;border:1px solid var(--line);background:var(--card);padding:8px 14px;border-radius:8px}
.hero{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:22px;margin:18px 0}
.hero h1{font-size:1.5rem;color:var(--brand-dark);margin-bottom:10px;line-height:1.35}
.hero p{color:var(--muted)}
.post-list{list-style:none;display:grid;gap:14px;margin:18px 0}
.post-card{display:flex;gap:14px;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px}
.post-thumb{flex:0 0 120px;height:90px;border-radius:8px;overflow:hidden;background:var(--line)}
.post-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.post-card h2,.post-card h3{font-size:1.02rem;line-height:1.45}
.post-card h2 a,.post-card h3 a{color:var(--ink);text-decoration:none}
.post-card h2 a:hover,.post-card h3 a:hover{color:var(--brand)}
.meta{font-size:.8rem;color:var(--muted);margin-top:4px}
.excerpt{font-size:.9rem;color:var(--muted);margin-top:6px}
.info-block{background:var(--card);border:1px solid var(--line);border-left:4px solid var(--brand);border-radius:12px;padding:20px;margin:24px 0}
.info-block h2{font-size:1.1rem;color:var(--brand-dark);margin-bottom:8px}
.cta{display:inline-block;margin-top:12px;background:var(--brand);color:#fff;text-decoration:none;padding:10px 20px;border-radius:8px;font-weight:600}
.cta:hover{background:var(--brand-dark)}
footer.site{margin-top:30px;padding:20px 0 34px;color:var(--muted);font-size:.82rem;border-top:1px solid var(--line)}
@media(min-width:640px){.hero h1{font-size:1.75rem}.post-thumb{flex-basis:160px;height:110px}}
CSS;
}

function nhadep_head(string $title, string $description, string $canonical, string $extraHead = ''): string
{
    $t = nhadep_san($title);
    $d = nhadep_san($description);
    $c = htmlspecialchars($canonical, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return "<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . "<title>{$t}</title>\n<meta name=\"description\" content=\"{$d}\">\n"
        . "<link rel=\"canonical\" href=\"{$c}\">\n"
        . "<meta property=\"og:type\" content=\"website\">\n<meta property=\"og:site_name\" content=\"Nội thất Đạt Ký\">\n"
        . "<meta property=\"og:title\" content=\"{$t}\">\n<meta property=\"og:description\" content=\"{$d}\">\n"
        . "<meta property=\"og:url\" content=\"{$c}\">\n"
        . "<meta name=\"twitter:card\" content=\"summary\">\n"
        . "<style>" . nhadep_css() . "</style>\n" . $extraHead;
}

function nhadep_header_html(string $brandLink): string
{
    $b = htmlspecialchars($brandLink, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return '<header class="site"><div class="wrap">'
        . '<a class="brand" href="' . $b . '">Nội thất Đạt Ký</a>'
        . '<div class="tagline">Thi công nội thất trọn gói tại Nghệ An</div>'
        . '</div></header>';
}

function nhadep_footer_html(string $brandLink): string
{
    $b = htmlspecialchars($brandLink, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $y = date('Y');
    return '<footer class="site"><div class="wrap">'
        . '<p>Nội thất Đạt Ký — Thi công nội thất trọn gói tại Nghệ An.</p>'
        . '<p>Nguồn tin: <a href="' . $b . '">noithatdatky.com</a> · Trang tĩnh phục vụ nhadepnghean.vn · © ' . $y . '</p>'
        . '</div></footer>';
}

/** Render ONE post card (<li>). $headingTag: h2 on daily top story, h3 otherwise. */
function nhadep_post_card(array $post, string $headingTag = 'h3'): string
{
    $title   = nhadep_san($post['title']);
    $excerpt = mb_substr(trim(strip_tags(html_entity_decode($post['excerpt'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 240);
    $excerpt = nhadep_san(rtrim($excerpt) . (mb_strlen($excerpt) >= 240 ? '…' : ''));
    $time    = nhadep_time_label($post['date_local']);
    $link    = nhadep_safe_url($post['link']);
    $titleHtml = $link !== ''
        ? '<a href="' . $link . '" rel="noopener">' . $title . '</a>'
        : $title;
    $thumbHtml = '';
    $src = nhadep_safe_url($post['thumb']);
    if ($src !== '') {
        $alt = nhadep_san(mb_substr(trim(strip_tags(html_entity_decode($post['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 120));
        $thumbHtml = '<div class="post-thumb"><img src="' . $src . '" alt="' . $alt . '" loading="lazy" width="320" height="220"></div>';
    }
    return '<li class="post-card">' . $thumbHtml
        . '<div class="post-body"><' . $headingTag . '>' . $titleHtml . '</' . $headingTag . '>'
        . '<div class="meta">Đăng lúc ' . $time . ' (' . nhadep_day_label(nhadep_day_key($post['date_gmt']) ?? '') . ')</div>'
        . '<p class="excerpt">' . $excerpt . '</p></div></li>';
}

/**
 * Render a single daily digest page.
 * $prevDay/$nextDay are day keys or null; map keys => url.
 */
function nhadep_render_daily_page(
    string $dayKey,
    array $dayPosts,
    string $contactUrl,
    ?array $prevDay,
    ?array $nextDay
): string {
    $label      = nhadep_day_label($dayKey);
    $canonical  = NHADEP_CANONICAL . 'tin-tuc/' . $dayKey . '/';
    $desc       = 'Tổng hợp tin tức nội thất ngày ' . $dayKey . ' từ Nội thất Đạt Ký — thi công nội thất trọn gói tại Nghệ An.';
    $firstTitle = nhadep_san(mb_substr(strip_tags(html_entity_decode($dayPosts[0]['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 70));

    $items = '';
    foreach ($dayPosts as $i => $post) {
        $items .= nhadep_post_card($post, $i === 0 ? 'h2' : 'h3') . "\n";
    }

    $prevHtml = $prevDay !== null
        ? '<a href="../' . $prevDay['key'] . '/" rel="prev">&larr; ' . nhadep_day_label($prevDay['key']) . '</a>'
        : '<span></span>';
    $nextHtml = $nextDay !== null
        ? '<a href="../' . $nextDay['key'] . '/" rel="next">' . nhadep_day_label($nextDay['key']) . ' &rarr;</a>'
        : '<span></span>';

    $cta = htmlspecialchars($contactUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return '<!DOCTYPE html>' . "\n" . '<html lang="vi">' . "\n<head>\n"
        . nhadep_head('Tin tức ngày ' . $dayKey . ' — Nội thất Đạt Ký', $desc, $canonical)
        . "</head>\n<body>\n"
        . nhadep_header_html(NHADEP_CANONICAL)
        . '<main class="wrap">'
        . '<section class="hero"><h1>Tin tức nội thất ngày ' . nhadep_san($label) . '</h1>'
        . '<p>Cập nhật ' . count($dayPosts) . ' bài viết mới nhất từ Nội thất Đạt Ký. Bài nổi bật: ' . $firstTitle . '</p></section>'
        . '<ul class="post-list">' . "\n" . $items . '</ul>'
        . '<section class="info-block"><h2>Giới thiệu Nội thất Đạt Ký</h2>'
        . '<p>Nội thất Đạt Ký là đơn vị thiết kế và thi công nội thất trọn gói tại Nghệ An — from phòng khách, phòng ngủ đến nội thất văn phòng, với xưởng sản xuất trực tiếp giúp tối ưu chi phí cho khách hàng tại Nghĩa Đàn, Yên Thành, Vinh và các khu vực lân cận.</p>'
        . '<a class="cta" href="' . $cta . '" rel="noopener">Liên hệ tư vấn &amp; báo giá</a></section>'
        . '<nav class="day-nav" aria-label="Điều hướng theo ngày">' . $prevHtml . $nextHtml . '</nav>'
        . '</main>'
        . nhadep_footer_html(NHADEP_SOURCE_ORIGIN)
        . "\n</body>\n</html>\n";
}

/**
 * Render the landing page listing ALL days newest-first.
 * $days: ordered assoc [dayKey => ['count' => n, 'first_title' => esc]].
 * $allPostCount: total posts represented.
 */
function nhadep_render_index_page(array $days, int $allPostCount, string $contactUrl): string
{
    $desc = 'Kho tin tức nội thất theo từng ngày từ Nội thất Đạt Ký — thi công nội thất trọn gói tại Nghệ An.';
    $extra = '<script type="application/ld+json">'
        . json_encode([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'WebSite',
                    '@id'   => NHADEP_CANONICAL . '#website',
                    'url'   => NHADEP_CANONICAL,
                    'name'  => 'Nội thất Đạt Ký — Tin tức nội thất Nghệ An',
                    'inLanguage' => 'vi',
                    'publisher'  => ['@type' => 'Organization', 'name' => 'Nội thất Đạt Ký'],
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
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . '</script>';

    $cards = '';
    foreach (nhadep_index_items($days) as $row) {
        $cards .= '<li class="post-card"><div class="post-body">'
            . '<h3><a href="tin-tuc/' . $row['key'] . '/">Tin tức ngày ' . $row['name'] . '</a></h3>'
            . '<div class="meta">' . $row['count'] . ' bài viết</div>'
            . '<p class="excerpt">' . $row['teaser'] . '</p></div></li>' . "\n";
    }

    $cta = htmlspecialchars($contactUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return '<!DOCTYPE html>' . "\n" . '<html lang="vi">' . "\n<head>\n"
        . nhadep_head('Tin tức nội thất theo ngày — Nội thất Đạt Ký Nghệ An', $desc, NHADEP_CANONICAL, $extra)
        . "</head>\n<body>\n"
        . nhadep_header_html(NHADEP_CANONICAL)
        . '<main class="wrap">'
        . '<section class="hero"><h1>Nội thất Đạt Ký — Thi công nội thất trọn gói tại Nghệ An</h1>'
        . '<p>Chuyên thiết kế và thi công nội thất trọn gói cho nhà ở, căn hộ và văn phòng tại Nghệ An. '
        . 'Trang tổng hợp tin tức nội thất đăng hàng ngày — tổng cộng ' . $allPostCount . ' bài viết trong '
        . count($days) . ' ngày.</p>'
        . '<a class="cta" href="' . $cta . '" rel="noopener">Nhận tư vấn miễn phí</a></section>'
        . '<ul class="post-list">' . "\n" . $cards . '</ul>'
        . '<section class="info-block"><h2>Về chúng tôi</h2>'
        . '<p>Xưởng sản xuất nội thất trực tiếp, đội ngũ thợ lành nghề, bảo hành dài hạn. '
        . 'Xem các bài viết theo ngày bên trên hoặc liên hệ trực tiếp để được khảo sát và báo cáo giá chi tiết.</p>'
        . '<a class="cta" href="' . $cta . '" rel="noopener">Liên hệ tư vấn &amp; báo giá</a></section>'
        . '</main>'
        . nhadep_footer_html(NHADEP_SOURCE_ORIGIN)
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
            'url'    => NHADEP_CANONICAL . 'tin-tuc/' . $key . '/',
        ];
    }
    return $rows;
}

/** Render sitemap.xml covering index + every daily page. */
function nhadep_render_sitemap(array $days, array $lastmods): string
{
    $today = gmdate('Y-m-d');
    $xml   = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $xml .= "\t<url>\n\t\t<loc>" . NHADEP_CANONICAL . "</loc>\n\t\t<lastmod>"
         . htmlspecialchars($lastmods['index'] ?? $today, ENT_QUOTES) . "</lastmod>\n\t</url>\n";
    foreach (array_keys($days) as $key) {
        $lm = $lastmods['days'][$key] ?? $today;
        $xml .= "\t<url>\n\t\t<loc>" . NHADEP_CANONICAL . 'tin-tuc/' . $key . "/</loc>\n\t\t<lastmod>"
             . htmlspecialchars($lm, ENT_QUOTES) . "</lastmod>\n\t</url>\n";
    }
    return $xml . "</urlset>\n";
}

function nhadep_render_robots(): string
{
    return "User-agent: *\nAllow: /\n\nSitemap: " . NHADEP_CANONICAL . "sitemap.xml\n";
}

/** Minimal RSS 2.0 feed with the latest 20 posts across all days. */
function nhadep_render_feed(array $latestPosts, string $contactUrl): string
{
    $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<rss version="2.0"><channel>' . "\n"
        . '<title>' . $esc('Tin tức nội thất — Nội thất Đạt Ký') . '</title>' . "\n"
        . '<link>' . $esc(NHADEP_CANONICAL) . '</link>' . "\n"
        . '<description>' . $esc('Tin tức nội thất theo ngày từ Nội thất Đạt Ký — thi công nội thất trọn gói tại Nghệ An.') . '</description>' . "\n"
        . '<language>vi</language>' . "\n";

    foreach ($latestPosts as $p) {
        $link = nhadep_safe_url($p['link']);
        if ($link === '') {
            continue;
        }
        try {
            $pub = new DateTimeImmutable($p['date_gmt'], new DateTimeZone('UTC'));
            $pubStr = $pub->format(DateTimeInterface::RSS);
        } catch (Exception) {
            $pubStr = gmdate(DateTimeInterface::RSS);
        }
        $title = trim(strip_tags(html_entity_decode($p['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $xml .= "<item>\n<title>" . $esc($title) . "</title>\n"
            . '<link>' . $esc(html_entity_decode($link, ENT_QUOTES, 'UTF-8')) . "</link>\n"
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

        // ---- Idempotency state ------------------------------------------
        $state = nhadep_read_state($outRoot);

        // ---- Daily pages -------------------------------------------------
        $generated = 0;
        $skipped   = 0;
        $lastmods  = ['index' => gmdate('Y-m-d'), 'days' => []];

        $orderedDays = array_reverse($dayKeys); // oldest-first for prev/next math
        $dayMetaNewestFirst = [];

        foreach ($orderedDays as $idx => $key) {
            $dayPosts = $groups[$key];
            $hash     = nhadep_day_hash($dayPosts);
            $fileRel  = 'tin-tuc/' . $key . '/index.html';

            $prev = $idx > 0 ? ['key' => $orderedDays[$idx - 1]] : null;
            $next = $idx < count($orderedDays) - 1 ? ['key' => $orderedDays[$idx + 1]] : null;

            $unchanged = isset($state['days'][$key]) && $state['days'][$key] === $hash;
            if (!$opts['force'] && $unchanged && !is_file($outRoot . '/' . $fileRel)) {
                // state says unchanged but file missing -> regenerate anyway
                $unchanged = false;
            }

            if ($opts['date'] !== null && $key !== $opts['date']) {
                // Single-day mode: only touch the requested day; record meta either way.
                $dayMetaNewestFirst[$key] = [
                    'count'       => count($dayPosts),
                    'first_title' => $dayPosts[0]['title'],
                ];
                $lastmods['days'][$key] = nhadep_lastmod_of_day($dayPosts);
                continue;
            }

            if ($unchanged && !$opts['force']) {
                $skipped++;
                if ($opts['verbose']) {
                    nhadep_log("Bỏ qua {$key} (không thay đổi)", 'DEBUG');
                }
            } else {
                $html = nhadep_render_daily_page($key, $dayPosts, $contactUrl, $prev, $next);
                nhadep_write_file($outRoot, $fileRel, $html);
                $generated++;
                nhadep_log("Đã sinh trang ngày {$key} ({$fileRel})");
            }

            $state['days'][$key] = $hash;
            $dayMetaNewestFirst[$key] = [
                'count'       => count($dayPosts),
                'first_title' => $dayPosts[0]['title'],
            ];
            $lastmods['days'][$key] = nhadep_lastmod_of_day($dayPosts);
        }

        // Newest-first ordering for index/feed rendering.
        $dayMetaNewestFirst = array_reverse($dayMetaNewestFirst, true);

        if ($opts['date'] !== null && !isset($groups[$opts['date']])) {
            nhadep_log("--date={$opts['date']} nhưng không có bài viết nào trong ngày đó.", 'WARN');
        }

        // ---- Landing page / sitemap / robots / feed (always regenerated) --
        $totalPosts = array_sum(array_map(static fn ($m) => (int) $m['count'], $dayMetaNewestFirst));

        nhadep_write_file($outRoot, 'index.html', nhadep_render_index_page($dayMetaNewestFirst, $totalPosts, $contactUrl));
        nhadep_log('Đã cập nhật index.html');

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

        $latest = [];
        foreach ($dayKeys as $key) { // newest-first
            foreach ($groups[$key] as $p) {
                $latest[] = $p;
                if (count($latest) >= 20) {
                    break 2;
                }
            }
        }
        nhadep_write_file($outRoot, 'feed.xml', nhadep_render_feed($latest, $contactUrl));
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
    // Included from web: refuse to run anything.
    http_response_code(403);
    exit('Chỉ chạy qua CLI.');
}
