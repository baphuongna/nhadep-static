<?php
declare(strict_types=1);

/**
 * Self-contained test suite for generate_nhadep_daily.php — no dependencies.
 *
 * Run:  php tests/run_tests.php
 * Exit code 0 = all pass, 1 = failures.
 */

error_reporting(E_ALL);
define('NHADEP_NO_RUN', true);          // load the generator without executing main()
require __DIR__ . '/../generate_nhadep_daily.php';

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__failures'] = [];

function check(string $name, bool $cond): void
{
    if ($cond) {
        $GLOBALS['__pass']++;
        return;
    }
    $GLOBALS['__fail']++;
    $GLOBALS['__failures'][] = $name;
    fwrite(STDERR, "  FAIL: {$name}\n");
}

/* -------------------------------------------------------------------------
 * nhadep_san — strip tags, decode entities, re-escape for HTML text nodes
 * ---------------------------------------------------------------------- */
check('san: tags stripped, entity decoded then re-escaped',
    nhadep_san('<p>Cafe&amp;Bar</p>') === 'Cafe&amp;Bar');
check('san: html-only input collapses to empty',
    nhadep_san('<img src=x onerror=alert(1)>') === '');
check('san: quotes and angle brackets escaped (tags stripped first)',
    nhadep_san('A "quote" & 5>3 <tag>') === 'A &quot;quote&quot; &amp; 5&gt;3 ');

/* -------------------------------------------------------------------------
 * nhadep_safe_url — allowlist origin, reject attribute/scheme breakouts
 * ---------------------------------------------------------------------- */
check('safe_url: same-origin https URL passes verbatim (escaped)',
    nhadep_safe_url('https://noithatdatky.com/lien-he/') === 'https://noithatdatky.com/lien-he/');
check('safe_url: null rejected', nhadep_safe_url(null) === '');
check('safe_url: empty string rejected', nhadep_safe_url('') === '');
check('safe_url: other host rejected', nhadep_safe_url('https://evil.com/x/') === '');
check('safe_url: plain http rejected (origin is https)',
    nhadep_safe_url('http://noithatdatky.com/x/') === '');
check('safe_url: quote breakout rejected',
    nhadep_safe_url('https://noithatdatky.com/x" onmouseover="alert(1)') === '');
check('safe_url: space injection rejected',
    nhadep_safe_url('https://noithatdatky.com/x javascript:alert(1)') === '');

/* -------------------------------------------------------------------------
 * nhadep_day_key — GMT → Asia/Ho_Chi_Minh (+7) day bucketing
 * ---------------------------------------------------------------------- */
check('day_key: 14:22Z stays 13-10 (21:22 VN same day)',
    nhadep_day_key('2026-10-13T14:22:01') === '2026-10-13');
check('day_key: 18:30Z rolls to next VN day (01:30)',
    nhadep_day_key('2026-10-13T18:30:00') === '2026-10-14');
check('day_key: garbage returns null', nhadep_day_key('not-a-date') === null);

/* -------------------------------------------------------------------------
 * nhadep_post_slug / nhadep_day_slug
 * ---------------------------------------------------------------------- */
$post = static fn (string $link): array => ['link' => $link];
check('post_slug: trailing-slash path segment extracted',
    nhadep_post_slug($post('https://noithatdatky.com/thi-cong-noi-that-chung-cu-do-luong/'))
        === 'thi-cong-noi-that-chung-cu-do-luong');
check('post_slug: uppercase lowered', nhadep_post_slug($post('https://noithatdatky.com/Mau-Thi-Cong/')) === 'mau-thi-cong');
check('post_slug: foreign origin gives empty', nhadep_post_slug($post('https://other.com/abc/')) === '');
check('day_slug: uses newest post slug',
    nhadep_day_slug([$post('https://noithatdatky.com/b/'), $post('https://noithatdatky.com/a/')], '2026-10-13') === 'b');
check('day_slug: falls back to day key on foreign links',
    nhadep_day_slug([$post('https://other.com/a/')], '2026-10-13') === '2026-10-13');
check('day_slug: empty list falls back to day key',
    nhadep_day_slug([], '2026-10-13') === '2026-10-13');

/* -------------------------------------------------------------------------
 * nhadep_resolve_day_slugs — stable day slugs (stored wins, suffix on clash)
 * ---------------------------------------------------------------------- */
$rg = static fn (string $day, array $links): array => [
    $day => array_map(static fn (string $l): array => $post('https://noithatdatky.com/' . $l . '/'), $links),
];

check('resolve_slugs: fresh day takes newest post slug',
    nhadep_resolve_day_slugs($rg('2026-10-13', ['b', 'a']), [])
        === ['2026-10-13' => 'b']);
check('resolve_slugs: stored slug survives newer post changing the natural one',
    nhadep_resolve_day_slugs(
        $rg('2026-10-13', ['bai-moi-hon']),
        ['2026-10-13' => 'slug-goc-khi-sinh']
    ) === ['2026-10-13' => 'slug-goc-khi-sinh']);
check('resolve_slugs: fresh day cannot steal stored slug of absent day',
    nhadep_resolve_day_slugs($rg('2026-10-13', ['trung']), ['2026-10-01' => 'trung'])
        === ['2026-10-13' => 'trung-2']);
check('resolve_slugs: two fresh days with same natural slug — older keeps, newer suffixed',
    nhadep_resolve_day_slugs(
        $rg('2026-10-14', ['giong-nhau']) + $rg('2026-10-13', ['giong-nhau']),
        []
    ) === ['2026-10-13' => 'giong-nhau', '2026-10-14' => 'giong-nhau-2']);
check('resolve_slugs: every stored slot reserved across suffix chain',
    nhadep_resolve_day_slugs(
        $rg('2026-10-15', ['doc-dao']) + $rg('2026-10-12', ['doc-dao']),
        ['2026-10-10' => 'doc-dao', '2026-10-11' => 'doc-dao-2']
    ) === ['2026-10-12' => 'doc-dao-3', '2026-10-15' => 'doc-dao-4']);
check('resolve_slugs: day-key fallback participates like any slug',
    nhadep_resolve_day_slugs(['2026-10-13' => []], [])
        === ['2026-10-13' => '2026-10-13']);

/* -------------------------------------------------------------------------
 * nhadep_day_label — Vietnamese long date, weekday included
 * ---------------------------------------------------------------------- */
check('day_label: 2026-10-14 is a Wednesday',
    nhadep_day_label('2026-10-14') === 'Thứ Tư, ngày 14/10/2026');
check('day_label: invalid key echoed as-is', nhadep_day_label('abc') === 'abc');

/* -------------------------------------------------------------------------
 * nhadep_time_label — WP local (site tz) time rendered as-is, independent of
 * the server's default timezone (regression: UTC servers shifted +7h)
 * ---------------------------------------------------------------------- */
$tzBackup = date_default_timezone_get();
date_default_timezone_set('UTC');
check('time_label: UTC server default must not shift WP local time',
    nhadep_time_label('2026-10-13T21:22:01') === '21:22');
date_default_timezone_set('America/New_York');
check('time_label: NY server default must not shift WP local time',
    nhadep_time_label('2026-10-13T21:22:01') === '21:22');
date_default_timezone_set($tzBackup);
check('time_label: explicit offset in string wins (wall time shown as given)',
    nhadep_time_label('2026-10-13T21:22:01+00:00') === '21:22');
check('time_label: garbage returns escaped original',
    nhadep_time_label('25:99-bad') === '25:99-bad');

/* -------------------------------------------------------------------------
 * nhadep_group_by_day — buckets, ordering, invalid-date skip
 * ---------------------------------------------------------------------- */
$p = static fn (int $id, string $gmt): array => [
    'id' => $id, 'link' => '', 'title' => "t{$id}", 'excerpt' => '', 'thumb' => '',
    'thumb_w' => 0, 'thumb_h' => 0, 'date_gmt' => $gmt, 'date_local' => '',
];
$groups = nhadep_group_by_day([
    $p(1, '2026-10-12T05:00:00'),
    $p(2, '2026-10-13T14:22:01'),
    $p(3, '2026-10-13T09:00:00'),
    $p(4, 'garbage'),
]);
check('group_by_day: invalid date skipped', count($groups) === 2);
check('group_by_day: newest day first', array_key_first($groups) === '2026-10-13');
check('group_by_day: within-day newest first',
    array_column($groups['2026-10-13'], 'id') === [2, 3]);

/* -------------------------------------------------------------------------
 * nhadep_day_hash — idempotency hash
 * ---------------------------------------------------------------------- */
$a = [$p(1, '2026-10-13T14:22:01'), $p(2, '2026-10-13T09:00:00')];
$b = [$p(1, '2026-10-13T14:22:01'), $p(2, '2026-10-13T09:00:00')];
$c = [$p(1, '2026-10-13T14:22:01'), $p(2, '2026-10-13T09:00:01')];
check('day_hash: identical inputs identical hash', nhadep_day_hash($a) === nhadep_day_hash($b));
check('day_hash: content change detected', nhadep_day_hash($a) !== nhadep_day_hash($c));

/* -------------------------------------------------------------------------
 * nhadep_day_meta
 * ---------------------------------------------------------------------- */
$meta = nhadep_day_meta($a, 'thi-cong-noi-that-chung-cu-do-luong');
check('day_meta: count', $meta['count'] === 2);
check('day_meta: slug + canonical url',
    $meta['slug'] === 'thi-cong-noi-that-chung-cu-do-luong'
    && $meta['url'] === 'https://nhadepnghean.vn/thi-cong-noi-that-chung-cu-do-luong/');
check('day_meta: first title from newest post', $meta['first_title'] === 't1');

/* -------------------------------------------------------------------------
 * Filesystem containment — writes may never escape the out root
 * ---------------------------------------------------------------------- */
$tmp = sys_get_temp_dir() . '/nhadep-tests-' . getmypid() . '-' . substr(md5((string) mt_rand()), 0, 6);
$root = nhadep_resolve_out_dir($tmp . '/out');
check('resolve_out_dir: creates and returns realpath', is_string($root) && is_dir($root));

nhadep_write_file($root, 'nested/deep/page.html', 'hello');
check('write_file: nested dirs auto-created',
    is_file($root . '/nested/deep/page.html') && file_get_contents($root . '/nested/deep/page.html') === 'hello');

$escaped = false;
try {
    nhadep_write_file($root, '../escape.html', 'x');
} catch (RuntimeException) {
    $escaped = true;
}
check('write_file: ../ escape refused', $escaped && !is_file(dirname($root) . '/escape.html'));

$escaped2 = false;
try {
    nhadep_write_file($root, 'a/../../escape2.html', 'x');
} catch (RuntimeException) {
    $escaped2 = true;
}
check('write_file: deep ../ escape refused', $escaped2 && !is_file(dirname($root) . '/escape2.html'));

/* -------------------------------------------------------------------------
 * State persistence round-trip
 * ---------------------------------------------------------------------- */
nhadep_write_state($root, ['days' => ['2026-10-13' => ['hash' => 'abc', 'slug' => 'x']]]);
$st = nhadep_read_state($root);
check('state: round-trip keeps day hashes', ($st['days']['2026-10-13']['hash'] ?? '') === 'abc');
file_put_contents($root . '/.state.json', '{corrupt json');
check('state: corrupt file falls back to empty days+slugs',
    nhadep_read_state($root) === ['days' => [], 'slugs' => []]);
check('state: missing file gives empty days+slugs',
    nhadep_read_state($root . '/does-not-exist') === ['days' => [], 'slugs' => []]);

/* Legacy .state.json (pre slug-pinning) must load with an empty slugs map. */
file_put_contents(
    $root . '/.state.json',
    json_encode(['days' => ['2026-10-13' => 'hash1']]) . "\n"
);
$legacy = nhadep_read_state($root);
check('state: legacy file (days only) loads with empty slugs',
    $legacy === ['days' => ['2026-10-13' => 'hash1'], 'slugs' => []]);

/* Two-run simulation: slug assigned once, then reused despite newer post. */
$gRun1 = $rg('2026-10-13', ['slug-dau-tien']);
$run1  = nhadep_resolve_day_slugs($gRun1, []);
nhadep_write_state($root, ['days' => [], 'slugs' => $run1]);
$gRun2 = $rg('2026-10-13', ['bai-moi-hon', 'slug-dau-tien']);
$run2  = nhadep_resolve_day_slugs($gRun2, nhadep_read_state($root)['slugs']);
check('state: slug pinned across runs even with newer post (write→read→resolve)',
    $run1 === ['2026-10-13' => 'slug-dau-tien'] && $run2 === $run1);
check('state: malformed stored slugs ignored, day re-derives natural slug',
    nhadep_resolve_day_slugs($rg('2026-10-13', ['tu-nhien']), ['2026-10-13' => 'BAD SLUG!'])
        === ['2026-10-13' => 'tu-nhien']);

/* recursive cleanup of the whole tmp tree */
$rrm = static function (string $dir) use (&$rrm): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $rrm("{$dir}/{$f}");
    }
    @rmdir($dir);
};
$rrm($tmp);

/* -------------------------------------------------------------------------
 * CLI: --help exits 0 and documents flags
 * ---------------------------------------------------------------------- */
$help = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../generate_nhadep_daily.php') . ' --help';
exec($help . ' 2>&1', $outLines, $exitCode);
$helpText = implode("\n", $outLines);
check('cli: --help exits 0', $exitCode === 0);
check('cli: --help documents --out and --force',
    str_contains($helpText, '--out=') && str_contains($helpText, '--force'));

/* -------------------------------------------------------------------------
 * Offline render tests (fixture-driven, no network)
 * ---------------------------------------------------------------------- */
require __DIR__ . '/render_tests.php';
nhadep_run_render_tests('check');

/* -------------------------------------------------------------------------
 * Summary
 * ---------------------------------------------------------------------- */
$total = $GLOBALS['__pass'] + $GLOBALS['__fail'];
echo PHP_EOL . ($GLOBALS['__fail'] === 0 ? 'PASS' : 'FAIL')
    . ": {$GLOBALS['__pass']}/{$total} tests passed" . PHP_EOL;
exit($GLOBALS['__fail'] === 0 ? 0 : 1);
