<?php
declare(strict_types=1);

/**
 * Offline render tests for generate_nhadep_daily.php — deterministic, no network.
 *
 * Loaded by tests/run_tests.php AFTER the generator has already been required
 * with NHADEP_NO_RUN. This file must NOT require/define the generator again
 * (that would fatal on redeclare), and must not change any existing output.
 *
 * No generator refactor was needed: every render function below is already a
 * global callable once the main guard is defined.
 *
 * Entrypoint (only): php tests/run_tests.php
 */

/**
 * Fixed fixture posts. Every post carries the full normalized key set:
 * id, link, title, excerpt, thumb, thumb_w, thumb_h, date_gmt, date_local.
 *
 * Post 101 is a late-evening GMT post (18:30Z) whose VN (+7) day is the next
 * calendar day — used to pin the timezone bucketing through the render path.
 */
function nhadep_render_fixture_posts(): array
{
    return [
        [
            'id'          => 101,
            'link'        => 'https://noithatdatky.com/thiet-ke-noi-that-chung-cu/',
            'title'       => 'Thiết kế nội thất chung cư tối ưu diện tích',
            'excerpt'     => 'Bí quyết bố trí nội thất chung cư nhỏ gọn, hiện đại và tiết kiệm chi phí.',
            'thumb'       => 'https://noithatdatky.com/wp-content/uploads/2026/10/chung-cu.jpg',
            'thumb_w'     => 1200,
            'thumb_h'     => 800,
            'date_gmt'    => '2026-10-13T18:30:00',
            'date_local'  => '2026-10-14T01:30:00',
        ],
        [
            'id'          => 102,
            'link'        => 'https://noithatdatky.com/mau-biet-thu-villa-dep/',
            'title'       => 'Mẫu biệt thự &amp; villa đẹp 2026',
            'excerpt'     => 'Tổng hợp mẫu biệt thự sang trọng, đồng bộ phong cách.',
            'thumb'       => 'https://noithatdatky.com/wp-content/uploads/2026/10/villa.jpg',
            'thumb_w'     => 1024,
            'thumb_h'     => 768,
            'date_gmt'    => '2026-10-13T20:00:00',
            'date_local'  => '2026-10-14T03:00:00',
        ],
        [
            'id'          => 103,
            'link'        => 'https://noithatdatky.com/nen-chon-go-gi-cho-noi-that/',
            'title'       => 'Nên chọn gỗ gì cho nội thất',
            'excerpt'     => 'So sánh các loại gỗ phổ biến khi làm nội thất.',
            'thumb'       => '',
            'thumb_w'     => 0,
            'thumb_h'     => 0,
            'date_gmt'    => '2026-10-12T02:00:00',
            'date_local'  => '2026-10-12T09:00:00',
        ],
    ];
}

/** Return true when $xml parses. If no XML extension is available, skip (true). */
function nhadep_render_xml_ok(string $xml): bool
{
    if (class_exists('DOMDocument')) {
        $prev = libxml_use_internal_errors(true);
        $doc  = new DOMDocument();
        $ok   = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok;
    }
    if (function_exists('simplexml_load_string')) {
        $prev = libxml_use_internal_errors(true);
        $x    = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $x !== false;
    }
    return true;
}

/**
 * Run all render assertions through $check(string $name, bool $cond).
 */
function nhadep_run_render_tests(callable $check): void
{
    $fixture = nhadep_render_fixture_posts();
    $contact = 'https://noithatdatky.com/lien-he/';

    $dayKeyA = '2026-10-14';
    $dayKeyB = '2026-10-12';
    $postsA  = [$fixture[0], $fixture[1]]; // VN 2026-10-14 (late GMT on the 13th)
    $postsB  = [$fixture[2]];              // VN 2026-10-12

    $slugA = nhadep_day_slug($postsA, $dayKeyA);
    $slugB = nhadep_day_slug($postsB, $dayKeyB);

    $days = [
        $dayKeyA => nhadep_day_meta($postsA, $slugA),
        $dayKeyB => nhadep_day_meta($postsB, $slugB),
    ];
    $lastmods = [
        'index' => $dayKeyA,
        'days'  => [$dayKeyA => $dayKeyA, $dayKeyB => $dayKeyB],
    ];

    /* ---- +7 fixture sanity: evening GMT buckets to next VN day ---- */
    $check('render fixture: evening GMT post buckets to VN next day (+7)',
        nhadep_day_key($fixture[0]['date_gmt']) === $dayKeyA);
    $check('render fixture: day slug derived from primary post',
        $slugA === 'thiet-ke-noi-that-chung-cu');

    /* =====================================================================
     * nhadep_render_daily_page
     * ================================================================== */
    $daily = nhadep_render_daily_page(
        $dayKeyA,
        $slugA,
        $postsA,
        $contact,
        ['key' => $dayKeyB, 'slug' => $slugB],
        null
    );

    $check('daily: <title> carries the VN day label',
        str_contains($daily, 'Tin tức ngày 14-10-2026'));
    $check('daily: canonical points at nhadepnghean.vn/<slug>/',
        str_contains($daily, '<link rel="canonical" href="https://nhadepnghean.vn/' . $slugA . '/">'));
    $check('daily: canonical matches slug URL shape',
        (bool) preg_match('#https://nhadepnghean\.vn/[a-z0-9-]+/#', $daily));
    $check('daily: has an <h1>', str_contains($daily, '<h1>'));
    $check('daily: original allowlisted post URL is emitted',
        str_contains($daily, 'https://noithatdatky.com/thiet-ke-noi-that-chung-cu/'));
    $check('daily: prev link points at older day slug',
        str_contains($daily, 'href="/' . $slugB . '/" rel="prev"'));
    $check('daily: missing next day renders the disabled nav',
        str_contains($daily, '<div class="dnav empty next">'));

    $ld = null;
    if (preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $daily, $m)) {
        $ld = json_decode($m[1], true);
    }
    $check('daily: JSON-LD parses to an @graph', is_array($ld) && isset($ld['@graph']));

    $graph = (is_array($ld) && is_array($ld['@graph'] ?? null)) ? $ld['@graph'] : [];
    $types = array_map(static fn (array $n): string => (string) ($n['@type'] ?? ''), $graph);
    $check('daily: JSON-LD @graph has CollectionPage', in_array('CollectionPage', $types, true));
    $check('daily: JSON-LD has one BlogPosting per article',
        count(array_keys($types, 'BlogPosting', true)) === count($postsA));

    $bp = null;
    foreach ($graph as $node) {
        if (($node['@type'] ?? '') === 'BlogPosting') {
            $bp = $node;
            break;
        }
    }
    $check('daily: BlogPosting has headline/url/datePublished',
        is_array($bp) && ($bp['headline'] ?? '') !== '' && ($bp['url'] ?? '') !== '' && ($bp['datePublished'] ?? '') !== '');

    /* =====================================================================
     * JSON-LD LocalBusiness + BreadcrumbList
     * ================================================================== */
    $nodeByType = static function (array $graph, string $type): ?array {
        foreach ($graph as $node) {
            if (is_array($node) && ($node['@type'] ?? '') === $type) {
                return $node;
            }
        }
        return null;
    };
    $graphOf = static function (string $html): array {
        if (preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m)) {
            $ld = json_decode($m[1], true);
            return is_array($ld) && is_array($ld['@graph'] ?? null) ? $ld['@graph'] : [];
        }
        return [];
    };

    $lb = $nodeByType($graph, 'LocalBusiness');
    $check('daily: JSON-LD has LocalBusiness with PostalAddress + hotline',
        is_array($lb)
        && ($lb['@id'] ?? '') === NHADEP_CANONICAL . '#localbusiness'
        && ($lb['address']['@type'] ?? '') === 'PostalAddress'
        && ($lb['address']['addressRegion'] ?? '') === 'Nghệ An'
        && ($lb['telephone'] ?? '') === '+84922355368');

    // BR-ND-003: address node must be DERIVED from NHADEP_ADDRESS — every part
    // (street, locality, region) is contained in the single-source constant.
    $lbStreet = (string) ($lb['address']['streetAddress'] ?? '');
    $lbLoc    = (string) ($lb['address']['addressLocality'] ?? '');
    $lbReg    = (string) ($lb['address']['addressRegion'] ?? '');
    $check('daily: LocalBusiness address derived from NHADEP_ADDRESS (no duplicate literal)',
        is_array($lb)
        && ($lb['address']['@type'] ?? '') === 'PostalAddress'
        && $lbStreet !== '' && str_contains(NHADEP_ADDRESS, $lbStreet)
        && $lbLoc !== '' && str_contains(NHADEP_ADDRESS, $lbLoc)
        && $lbReg !== '' && str_contains(NHADEP_ADDRESS, $lbReg));

    $bc    = $nodeByType($graph, 'BreadcrumbList');
    $bItems = is_array($bc) ? ($bc['itemListElement'] ?? []) : [];
    $check('daily: BreadcrumbList mirrors .crumbs (3 levels, current page last)',
        is_array($bc)
        && ($bc['@id'] ?? '') === NHADEP_CANONICAL . $slugA . '/#breadcrumb'
        && count($bItems) === 3
        && ($bItems[0]['name'] ?? '') === 'Trang chủ' && ($bItems[0]['item'] ?? '') === NHADEP_CANONICAL
        && ($bItems[1]['name'] ?? '') === 'Tin tức' && ($bItems[1]['item'] ?? '') === NHADEP_CANONICAL . 'tin-tuc/'
        && ($bItems[2]['name'] ?? '') === '14-10-2026' && ($bItems[2]['item'] ?? '') === NHADEP_CANONICAL . $slugA . '/'
        && ($bItems[2]['position'] ?? 0) === 3);

    $check('daily: CollectionPage.publisher links to LocalBusiness @id',
        ($nodeByType($graph, 'CollectionPage')['publisher']['@id'] ?? '') === NHADEP_CANONICAL . '#localbusiness');

    /* =====================================================================
     * nhadep_render_sitemap
     * ================================================================== */
    $sitemap = nhadep_render_sitemap($days, $lastmods);
    $check('sitemap: <urlset> root present', str_contains($sitemap, '<urlset'));
    $check('sitemap: index loc is canonical root',
        str_contains($sitemap, '<loc>' . NHADEP_CANONICAL . '</loc>'));

    $sitemapLocsOk = true;
    foreach ($days as $meta) {
        if (!str_contains($sitemap, '<loc>' . NHADEP_CANONICAL . $meta['slug'] . '/</loc>')) {
            $sitemapLocsOk = false;
        }
    }
    $check('sitemap: <loc> for every day == canonical/<slug>/', $sitemapLocsOk);
    $check('sitemap: well-formed XML (skipped only if no XML extension)',
        nhadep_render_xml_ok($sitemap));

    /* =====================================================================
     * nhadep_render_feed
     * ================================================================== */
    $latestPosts = array_merge($postsA, $postsB);
    $feed        = nhadep_render_feed($latestPosts, $contact);
    $validCount  = count(array_filter(
        $latestPosts,
        static fn (array $p): bool => nhadep_safe_url($p['link']) !== ''
    ));
    $check('feed: <item> count equals posts with allowlisted link',
        substr_count($feed, '<item>') === $validCount);
    $check('feed: well-formed XML (skipped only if no XML extension)',
        nhadep_render_xml_ok($feed));

    $feedLinksOk = true;
    if (class_exists('SimpleXMLElement')) {
        $sx = simplexml_load_string($feed);
        if ($sx !== false && isset($sx->channel->item)) {
            foreach ($sx->channel->item as $item) {
                if (!str_starts_with((string) $item->link, NHADEP_CANONICAL)) {
                    $feedLinksOk = false;
                }
                if (!str_starts_with((string) $item->guid, 'ntdk-post-')) {
                    $feedLinksOk = false;
                }
            }
        }
    }
    $check('feed: every item link is canonical and guid is prefixed', $feedLinksOk);

    /* =====================================================================
     * nhadep_render_archive + nhadep_render_index_page
     * ================================================================== */
    $archive = nhadep_render_archive($days);
    $archiveOk = true;
    foreach ($days as $meta) {
        if (!str_contains($archive, 'href="/' . $meta['slug'] . '/"')) {
            $archiveOk = false;
        }
    }
    $check('archive: links to every day slug', $archiveOk);

    $index = nhadep_render_index_page($days, count($latestPosts), $contact, $latestPosts, '');
    $indexOk = true;
    foreach ($days as $meta) {
        if (!str_contains($index, 'href="/' . $meta['slug'] . '/"')) {
            $indexOk = false;
        }
    }
    $check('index: links to every day slug', $indexOk);

    /* ---- JSON-LD: LocalBusiness entity; breadcrumb stays off the homepage ---- */
    $indexGraph = $graphOf($index);
    $indexTypes = array_map(static fn (array $n): string => (string) ($n['@type'] ?? ''), $indexGraph);
    $check('index: JSON-LD graph keeps WebSite + ItemList',
        in_array('WebSite', $indexTypes, true) && in_array('ItemList', $indexTypes, true));
    $check('index: JSON-LD has LocalBusiness entity',
        $nodeByType($indexGraph, 'LocalBusiness') !== null);
    $check('index: WebSite.publisher links to LocalBusiness @id',
        ($nodeByType($indexGraph, 'WebSite')['publisher']['@id'] ?? '') === NHADEP_CANONICAL . '#localbusiness');
    $check('index: no BreadcrumbList on the homepage (top level)',
        !in_array('BreadcrumbList', $indexTypes, true));

    $archiveGraph = $graphOf($archive);
    $archiveBc    = $nodeByType($archiveGraph, 'BreadcrumbList');
    $aItems       = is_array($archiveBc) ? ($archiveBc['itemListElement'] ?? []) : [];
    $check('archive: JSON-LD has LocalBusiness + BreadcrumbList',
        $nodeByType($archiveGraph, 'LocalBusiness') !== null && is_array($archiveBc)
        && count($aItems) === 2
        && ($aItems[0]['name'] ?? '') === 'Trang chủ' && ($aItems[0]['item'] ?? '') === NHADEP_CANONICAL
        && ($aItems[1]['name'] ?? '') === 'Lưu trữ' && ($aItems[1]['item'] ?? '') === NHADEP_CANONICAL . 'tin-tuc/');

    /* =====================================================================
     * BR-ND-001 regression: HTML rendered via nhadep_head() must contain
     * REAL newlines between tags — never the two characters '\\'+'n'.
     * (Historical bug: "\n" written inside single-quoted PHP strings is a
     * literal backslash-n, gluing meta/link tags onto one line and breaking
     * the <head> markup. Same for '\' before '"' in og:image:alt.)
     * ================================================================== */
    $literalBackslashN = '\\' . 'n'; // two chars: backslash + n
    $headOf = static function (string $html): string {
        $start = strpos($html, '<head>');
        $end   = strpos($html, '</head>');

        return ($start === false || $end === false) ? '' : substr($html, $start, $end - $start);
    };

    $check('regress BR-ND-001: daily page has no literal backslash-n',
        !str_contains($daily, $literalBackslashN));
    $check('regress BR-ND-001: index page has no literal backslash-n',
        !str_contains($index, $literalBackslashN));
    $check('regress BR-ND-001: archive page has no literal backslash-n',
        !str_contains($archive, $literalBackslashN));

    foreach (['daily' => $daily, 'index' => $index, 'archive' => $archive] as $label => $html) {
        $head = $headOf($html);
        $check("regress BR-ND-001: {$label} <head> contains <title",
            str_contains($head, '<title'));
        $check("regress BR-ND-001: {$label} <head> contains rel=\"canonical\"",
            str_contains($head, 'rel="canonical"'));
        $check("regress BR-ND-001: {$label} <head> contains name=\"description\"",
            str_contains($head, 'name="description"'));
    }

    // Daily passes the first post's thumb as ogImage (fixture post 101 has one);
    // index/archive render without ogImage — no og:image assert for them.
    $check('regress BR-ND-001: daily <head> contains property=\"og:image\" (featured thumb)',
        str_contains($headOf($daily), 'property="og:image"'));
}
