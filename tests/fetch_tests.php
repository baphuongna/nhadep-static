<?php
declare(strict_types=1);

/**
 * Offline resilience tests for the REST-API fetch path of
 * generate_nhadep_daily.php — deterministic, no network.
 *
 * Loaded by tests/run_tests.php AFTER the generator has already been required
 * with NHADEP_NO_RUN. This file must NOT require/define the generator again
 * (that would fatal on redeclare).
 *
 * Entrypoint (only): php tests/run_tests.php
 */

/** Build a raw response array as consumed by nhadep_http_get's override hook. */
function nhadep_fetch_t_http(int $status, $body): array
{
    return ['status' => $status, 'body' => $body, 'err' => ''];
}

/** Build one minimal REST post object (only the fetch-layer fields matter). */
function nhadep_fetch_t_post(int $id): array
{
    return [
        'id'        => $id,
        'link'      => 'https://noithatdatky.com/bai-' . $id . '/',
        'title'     => ['rendered' => 'Bài ' . $id],
        'excerpt'   => ['rendered' => 'Mô tả ' . $id],
        'date'      => '2026-10-13T10:00:00',
        'date_gmt'  => '2026-10-13T03:00:00',
    ];
}

/** Remove every global override so no case leaks into another test. */
function nhadep_fetch_t_clear(): void
{
    unset(
        $GLOBALS['NHADEP_HTTP_GET_OVERRIDE'],
        $GLOBALS['NHADEP_RETRY_NO_SLEEP'],
        $GLOBALS['NHADEP_STATUS_OVERRIDE']
    );
}

/**
 * @param callable(string, bool):void $check
 */
function nhadep_run_fetch_tests(callable $check): void
{
    // (a) end-of-pagination: HTTP 400 rest_post_invalid_page_number is a
    //     normal end, NOT an error. page1 ok, page2 ok, page3 = 400.
    $calls = 0;
    $GLOBALS['NHADEP_RETRY_NO_SLEEP'] = true;
    $GLOBALS['NHADEP_HTTP_GET_OVERRIDE'] = function (string $url) use (&$calls): array {
        $calls++;
        if ($calls <= 2) {
            return nhadep_fetch_t_http(200, json_encode([nhadep_fetch_t_post($calls)]));
        }
        return nhadep_fetch_t_http(400, json_encode([
            'code'    => 'rest_post_invalid_page_number',
            'message' => 'The page number requested is larger than the number of pages available.',
        ]));
    };
    $ok = true;
    $posts = [];
    try {
        $posts = nhadep_fetch_all_posts(0, false);
    } catch (Throwable $e) {
        $ok = false;
    }
    $check(
        'fetch: 400 rest_post_invalid_page_number ends pagination, returns all posts',
        $ok && count($posts) === 2 && $calls === 3
    );
    nhadep_fetch_t_clear();

    // (b) bounded retry: 500, 500, 200 -> success on the 3rd attempt.
    $calls = 0;
    $GLOBALS['NHADEP_RETRY_NO_SLEEP'] = true;
    $GLOBALS['NHADEP_HTTP_GET_OVERRIDE'] = function (string $url) use (&$calls): array {
        $calls++;
        if ($calls < 3) {
            return nhadep_fetch_t_http(500, 'server error');
        }
        return nhadep_fetch_t_http(200, json_encode([nhadep_fetch_t_post(1)]));
    };
    $ok = true;
    $posts = [];
    try {
        $posts = nhadep_fetch_all_posts(1, false);
    } catch (Throwable $e) {
        $ok = false;
    }
    $check(
        'fetch: transient 500 is retried and succeeds (exactly 3 attempts)',
        $ok && $calls === 3 && count($posts) === 1
    );
    nhadep_fetch_t_clear();

    // (c) fail-closed fetch: page2 stays 500 after retries -> throw.
    $calls = 0;
    $GLOBALS['NHADEP_RETRY_NO_SLEEP'] = true;
    $GLOBALS['NHADEP_HTTP_GET_OVERRIDE'] = function (string $url) use (&$calls): array {
        $calls++;
        if ($calls === 1) {
            return nhadep_fetch_t_http(200, json_encode([nhadep_fetch_t_post(1)]));
        }
        return nhadep_fetch_t_http(500, 'down');
    };
    $threw = false;
    try {
        nhadep_fetch_all_posts(0, false);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    $check(
        'fetch: unrecoverable page-2 error throws RuntimeException (fail-closed)',
        $threw && $calls === 4 // page 1 once + 3 attempts on page 2
    );
    nhadep_fetch_t_clear();

    // (e) HTTP 200 + empty array is a normal end (no error).
    $GLOBALS['NHADEP_RETRY_NO_SLEEP'] = true;
    $GLOBALS['NHADEP_HTTP_GET_OVERRIDE'] = static fn (string $url): array =>
        nhadep_fetch_t_http(200, '[]');
    $pageRes = nhadep_fetch_page('http://offline.test');
    $check('fetch: HTTP 200 + empty array classifies as end', $pageRes['state'] === 'end');
    nhadep_fetch_t_clear();

    // (f) HTTP 200 + broken JSON -> retried, then error.
    $calls = 0;
    $GLOBALS['NHADEP_RETRY_NO_SLEEP'] = true;
    $GLOBALS['NHADEP_HTTP_GET_OVERRIDE'] = function (string $url) use (&$calls): array {
        $calls++;
        return nhadep_fetch_t_http(200, '{broken');
    };
    $pageRes = nhadep_fetch_page_retry('http://offline.test');
    $check(
        'fetch: HTTP 200 + broken JSON retried then error (exactly 3 attempts)',
        $pageRes['state'] === 'error' && $calls === 3
    );
    nhadep_fetch_t_clear();

    // (d) run_main integration: an unrecoverable page-2 fetch error must make
    //     run_main return 1 WITHOUT writing/clobbering any existing file.
    $base = sys_get_temp_dir() . '/nhadep-fetch-' . getmypid() . '-' . substr(md5((string) mt_rand()), 0, 6);
    mkdir($base, 0777, true);
    $sentinel    = "<!doctype html>\n<title>SENTINEL</title>\n";
    $stateBefore = json_encode(
        ['days' => ['2026-10-13' => ['hash' => 'keepme', 'slug' => 'keepme']]],
        JSON_PRETTY_PRINT
    ) . "\n";
    file_put_contents($base . '/index.html', $sentinel);
    file_put_contents($base . '/.state.json', $stateBefore);

    $calls = 0;
    $GLOBALS['NHADEP_RETRY_NO_SLEEP']  = true;
    $GLOBALS['NHADEP_STATUS_OVERRIDE'] = static fn (string $url): int => 200;
    $GLOBALS['NHADEP_HTTP_GET_OVERRIDE'] = function (string $url) use (&$calls): array {
        $calls++;
        if ($calls === 1) {
            return nhadep_fetch_t_http(200, json_encode([nhadep_fetch_t_post(1)]));
        }
        return nhadep_fetch_t_http(500, 'down');
    };
    $exit = run_main(['generate_nhadep_daily.php', '--out=' . $base]);
    $indexAfter = file_get_contents($base . '/index.html');
    $stateAfter = file_get_contents($base . '/.state.json');
    $check(
        'run_main: fetch failure returns 1 and clobbers no existing file (fail-closed)',
        $exit === 1 && $indexAfter === $sentinel && $stateAfter === $stateBefore
    );
    nhadep_fetch_t_clear();

    // Recursive cleanup of the temp tree.
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
    $rrm($base);
}
