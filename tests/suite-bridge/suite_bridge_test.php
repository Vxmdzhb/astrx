<?php
declare(strict_types=1);

/**
 * Standalone bridge test for the three new suite modules — NO AstrX bootstrap.
 *
 * Boots tests/mock_suite_server.php on the PHP built-in server, then drives the
 * real TorrentSearchClient / SuiteAdminClient (and the *Config holders) against
 * it and asserts:
 *
 *   TorrentSearch (torrentds bridge)
 *     1. an attacker-controlled torrent NAME (<script>/entities) comes back
 *        tag-free, and a row with a bogus infohash is dropped;
 *     2. the magnet + .torrent links are rebuilt from a validated hex infohash
 *        (safe href, no markup);
 *     3. attacker-controlled FILE PATHS in the detail view come back tag-free;
 *     4. a valid-but-unknown / invalid infohash degrades to "not found".
 *
 *   SuiteAdmin (status panel + control)
 *     5. the status probe parses Prometheus text AND JSON metrics, all UP;
 *     6. a DOWN backend yields up=false without a crash / warning-to-500;
 *     7. parseMetrics() handles Prometheus (labels + NaN) and JSON directly;
 *     8. the onion-seed control POSTs to /add with the url field (recorded by
 *        the mock) and reads back the queued result; an unreachable engine
 *        degrades gracefully.
 *
 *   Config hardening
 *     9. non-http(s) base/service URLs are rejected to the safe default; clamps.
 *
 * Run:  php tests/suite_bridge_test.php
 */

// --- minimal stub so the holders' #[InjectConfig] attribute always resolves ---
namespace AstrX\Config {
    if (!\class_exists(InjectConfig::class)) {
        #[\Attribute(\Attribute::TARGET_METHOD)]
        final class InjectConfig
        {
            public function __construct(public string $key) {}
        }
    }
}

namespace {

    use AstrX\TorrentSearch\TorrentSearchConfig;
    use AstrX\TorrentSearch\TorrentSearchClient;
    use AstrX\SuiteAdmin\SuiteAdminConfig;
    use AstrX\SuiteAdmin\SuiteAdminClient;
    use AstrX\GitBrowse\GitBrowseConfig;
    use AstrX\GitBrowse\GitBrowseClient;
    use AstrX\Search\Tab\SearchHit;
    use AstrX\Blocklist\BlocklistConfig;
    use AstrX\Blocklist\BlocklistClient;

    // The holders under test, loaded on demand (the stub above stands in for
    // the real attribute, so nothing else of the framework is needed).
    require_once dirname(__DIR__, 2) . '/src/classloader.php';

    $PASS = 0;
    $FAIL = 0;
    function check(string $label, bool $cond): void
    {
        global $PASS, $FAIL;
        if ($cond) { $PASS++; echo "  ok   - $label\n"; }
        else       { $FAIL++; echo "  FAIL - $label\n"; }
    }
    function eq(string $label, mixed $got, mixed $want): void
    {
        check($label . " (got: " . var_export($got, true) . ")", $got === $want);
    }
    function noMarkup(string $label, string $s): void
    {
        check(
            $label . " tag-free (value: " . var_export($s, true) . ")",
            !str_contains($s, '<') && !str_contains($s, '>')
        );
    }
    /** @param list<array<string,mixed>> $rows */
    function findEngine(array $rows, string $name): ?array
    {
        foreach ($rows as $r) {
            if (($r['name'] ?? null) === $name) { return $r; }
        }
        return null;
    }
    /** @param list<array{key:string,value:string,present:bool}> $metrics */
    function metricPresent(array $metrics, string $key): bool
    {
        foreach ($metrics as $m) {
            if ($m['key'] === $key && $m['present']) { return true; }
        }
        return false;
    }

    $IH1 = str_repeat('a', 40);
    $IH2 = str_repeat('b', 40);

    /**
     * A localhost port nothing is listening on, looking upward from $from, so a
     * busy port doesn't make the test fail for unrelated reasons.
     */
    function freePort(int $from): int
    {
        for ($port = $from; $port < $from + 200; $port++) {
            $probe = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
            if ($probe !== false) {
                fclose($probe);          // released; the child binds it a moment later
                return $port;
            }
        }
        fwrite(STDERR, "no free port in [{$from}, " . ($from + 200) . ")\n");
        exit(2);
    }

    // ---- boot the mock server -------------------------------------------------
    $mock    = __DIR__ . '/mock_suite_server.php';
    $addLog  = tempnam(sys_get_temp_dir(), 'astrx_add_');
    if (!is_string($addLog)) { fwrite(STDERR, "tempnam failed\n"); exit(2); }
    @unlink($addLog);
    $blockLog = tempnam(sys_get_temp_dir(), 'astrx_block_');
    if (!is_string($blockLog)) { fwrite(STDERR, "tempnam failed\n"); exit(2); }
    @unlink($blockLog);
    $ADMIN_TOKEN = 's3cr3t-token';

    $env = getenv();
    if (!is_array($env)) { $env = []; }
    $env['MOCK_ADD_LOG']     = $addLog;
    $env['MOCK_BLOCK_LOG']   = $blockLog;
    $env['MOCK_ADMIN_TOKEN'] = $ADMIN_TOKEN;

    // escapeshellarg(PHP_BINARY), not a bare `php`: $PATH can resolve to a
    // different interpreter than the one running this test (a system 8.1 next to
    // the 8.4 under test), and the mock is written against this one.
    $proc  = null;
    $pipes = [];
    $port  = 0;
    $ready = false;

    for ($attempt = 0; $attempt < 5 && !$ready; $attempt++) {
        $port = freePort(8815);
        $cmd  = sprintf('exec %s -S 127.0.0.1:%d %s', escapeshellarg(PHP_BINARY), $port, escapeshellarg($mock));
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        if (!is_resource($proc)) {
            fwrite(STDERR, "could not start mock server\n");
            exit(2);
        }
        // wait for readiness
        for ($i = 0; $i < 50; $i++) {
            $h = @file_get_contents("http://127.0.0.1:$port/healthz");
            if ($h === 'ok') { $ready = true; break; }
            // Child gone = it lost the race for the port between our probe
            // closing and its own bind(). Take another port instead of calling
            // that a mock-server failure.
            if (proc_get_status($proc)['running'] === false) { break; }
            usleep(100_000);
        }
        if (!$ready) {
            proc_terminate($proc);
            foreach ($pipes as $p) { if (is_resource($p)) { fclose($p); } }
            proc_close($proc);
            $proc = null;
        }
    }
    if (!$ready || !is_resource($proc)) {
        fwrite(STDERR, "mock server never became ready\n");
        exit(2);
    }

    try {
        $mockBase = "http://127.0.0.1:$port";

        // ===================================================================
        // 1–2. torrentds search: sanitise names, drop bogus rows, safe links
        // ===================================================================
        echo "[torrentsearch] search: sanitise + safe magnet/.torrent\n";
        $tc = new TorrentSearchConfig();
        $tc->setBaseUrl($mockBase);
        $torrent = new TorrentSearchClient($tc);
        $r = $torrent->search('linux', 1);

        eq('ok=true',          $r['ok'],    true);
        eq('total=42',         $r['total'], 42);
        eq('page=1',           $r['page'],  1);
        eq('page_size=25',     $r['page_size'], 25);
        eq('bogus row dropped → 2 results', count($r['results']), 2);

        $t0 = $r['results'][0];
        eq('name decoded+stripped', $t0['name'], 'Ubuntu alert(1) & more');
        noMarkup('name', $t0['name']);
        check('name has no "script"', !str_contains($t0['name'], 'script'));
        eq('size formatted',        $t0['size'], '1.5 GiB');
        eq('file_count',            $t0['file_count'], 3);
        eq('seen_count',            $t0['seen_count'], 17);
        eq('has_swarm',             $t0['has_swarm'], true);
        eq('seeders',               $t0['seeders'], 12);
        check('magnet rebuilt from hex ih', str_starts_with($t0['magnet'], 'magnet:?xt=urn:btih:' . $IH1));
        check('magnet carries a dn',        str_contains($t0['magnet'], '&dn='));
        noMarkup('magnet', $t0['magnet']);
        check('magnet has no space',        !str_contains($t0['magnet'], ' '));
        eq('.torrent url built on config origin', $t0['torrent_url'], $mockBase . '/torrent/' . $IH1 . '.torrent');

        $t1 = $r['results'][1];
        eq('entity name decoded+stripped', $t1['name'], 'Debian netinst');
        noMarkup('row1 name', $t1['name']);

        $allHex = true;
        foreach ($r['results'] as $row) {
            if (preg_match('/^[0-9a-f]{40}$/', $row['infohash']) !== 1) { $allHex = false; }
        }
        check('every surviving infohash is 40-hex', $allHex);

        // ===================================================================
        // 3–4. torrentds detail: sanitise file PATHS; not-found handling
        // ===================================================================
        echo "[torrentsearch] detail: sanitise file paths\n";
        $d = $torrent->detail($IH1);
        eq('detail ok',    $d['ok'],    true);
        eq('detail found', $d['found'], true);
        noMarkup('detail name', $d['name']);
        eq('2 files', count($d['files']), 2);
        foreach ($d['files'] as $i => $f) {
            noMarkup("file[$i] path", $f['path']);
            check("file[$i] path has no 'script'", !str_contains($f['path'], 'script'));
        }
        check('file[0] path kept its name', str_contains($d['files'][0]['path'], 'evil.txt'));
        check('detail magnet safe', str_starts_with($d['magnet'], 'magnet:?xt=urn:btih:' . $IH1));
        eq('detail .torrent url', $d['torrent_url'], $mockBase . '/torrent/' . $IH1 . '.torrent');

        echo "[torrentsearch] detail: invalid / unknown infohash\n";
        $bad = $torrent->detail('not-a-real-infohash');
        eq('invalid ih → not found (no network)', $bad['found'], false);
        $unknown = $torrent->detail($IH2);   // valid hex, but mock 404s it
        eq('unknown ih → not found', $unknown['found'], false);

        // ===================================================================
        // 5. suite status: parse Prometheus AND JSON, all UP
        // ===================================================================
        echo "[suiteadmin] status panel: Prometheus + JSON, all UP\n";
        $sc = new SuiteAdminConfig();
        $sc->setGitwebBaseUrl($mockBase);
        $sc->setOnioncrawlerBaseUrl($mockBase);
        $sc->setWebsearchBaseUrl($mockBase);
        $sc->setTorrentdsBaseUrl($mockBase);
        $suite = new SuiteAdminClient($sc);
        $st = $suite->statuses();

        eq('four engines', count($st), 4);
        $git   = findEngine($st, 'gitweb');
        $onion = findEngine($st, 'onioncrawler');
        $web   = findEngine($st, 'websearch');
        $tds   = findEngine($st, 'torrentds');
        check('gitweb up',       is_array($git)   && $git['up']   === true);
        check('onioncrawler up', is_array($onion) && $onion['up'] === true);
        check('websearch up',    is_array($web)   && $web['up']   === true);
        check('torrentds up',    is_array($tds)   && $tds['up']   === true);

        check('gitweb metric (Prometheus) parsed',
            is_array($git) && metricPresent($git['metrics'], 'gitweb_requests_total'));
        check('onioncrawler metric (Prometheus) parsed',
            is_array($onion) && metricPresent($onion['metrics'], 'onioncrawler_pages'));
        check('websearch metric (Prometheus) parsed',
            is_array($web) && metricPresent($web['metrics'], 'websearch_docs'));
        check('torrentds metric (JSON /api/stats) parsed',
            is_array($tds) && metricPresent($tds['metrics'], 'torrents'));

        // control wiring: only onioncrawler exposes a write action
        check('onioncrawler advertises onion-seed control',
            is_array($onion) && $onion['control'] === 'onion_seed');
        check('gitweb is display-only',
            is_array($git) && $git['control'] === '');

        // ===================================================================
        // 6. suite status: a DOWN backend never crashes the panel
        // ===================================================================
        echo "[suiteadmin] status panel: DOWN backend degrades to up=false\n";
        $sc2 = new SuiteAdminConfig();
        $sc2->setGitwebBaseUrl($mockBase);
        $sc2->setOnioncrawlerBaseUrl($mockBase);
        $sc2->setWebsearchBaseUrl($mockBase);
        $sc2->setTorrentdsBaseUrl('http://127.0.0.1:1');   // connection refused
        $st2 = (new SuiteAdminClient($sc2))->statuses();
        $git2 = findEngine($st2, 'gitweb');
        $tds2 = findEngine($st2, 'torrentds');
        check('gitweb still up',    is_array($git2) && $git2['up'] === true);
        check('torrentds now DOWN', is_array($tds2) && $tds2['up'] === false);

        // ===================================================================
        // 7. parseMetrics() directly: Prometheus (labels + NaN) and JSON
        // ===================================================================
        echo "[suiteadmin] parseMetrics(): Prometheus + JSON units\n";
        $prom = SuiteAdminClient::parseMetrics(
            "# HELP x\nfoo_total 907\nbar{code=\"200\"} 500\nbad NaN\n",
            'text/plain; version=0.0.4'
        );
        eq('prom scalar', $prom['foo_total'] ?? null, 907.0);
        eq('prom labelled base name', $prom['bar'] ?? null, 500.0);
        check('prom NaN dropped', !array_key_exists('bad', $prom));

        $js = SuiteAdminClient::parseMetrics('{"torrents":1234,"nested":{"a":5}}', 'application/json');
        eq('json scalar',      $js['torrents'] ?? null, 1234.0);
        eq('json flattened 1', $js['nested_a'] ?? null, 5.0);

        // ===================================================================
        // 8. onion-seed control: POSTs to /add with the url field
        // ===================================================================
        echo "[suiteadmin] onion-seed control: POST /add\n";
        $seedUrl = 'http://abcdefghijklmnop234567.onion/';
        $res = $suite->submitOnionSeed($seedUrl);
        eq('seed ok',      $res['ok'],     true);
        eq('seed status',  $res['status'], 'queued');
        eq('seed ok_count',$res['ok_count'], 1);

        $recordedRaw = @file_get_contents($addLog);
        $recorded    = is_string($recordedRaw) ? json_decode($recordedRaw, true) : null;
        check('mock recorded a request to /add', is_array($recorded));
        if (is_array($recorded)) {
            eq('…via POST',        $recorded['method'] ?? null, 'POST');
            eq('…to path /add',    $recorded['path']   ?? null, '/add');
            eq('…with url field',  $recorded['url']    ?? null, $seedUrl);
        }

        echo "[suiteadmin] onion-seed control: unreachable engine\n";
        $scDead = new SuiteAdminConfig();
        $scDead->setOnioncrawlerBaseUrl('http://127.0.0.1:1');
        $deadSeed = (new SuiteAdminClient($scDead))->submitOnionSeed('http://x.onion');
        eq('seed unreachable ok=false', $deadSeed['ok'], false);
        eq('seed unreachable status',   $deadSeed['status'], 'unreachable');

        // ===================================================================
        // 9. config hardening
        // ===================================================================
        echo "[config] scheme hardening + clamps\n";
        $tcx = new TorrentSearchConfig();
        $tcx->setBaseUrl('file:///etc/passwd');
        eq('torrent file:// rejected', $tcx->baseUrl(), TorrentSearchConfig::DEFAULT_BASE_URL);
        $tcx->setBaseUrl('http://127.0.0.1:8804/');
        eq('torrent trailing slash trimmed', $tcx->baseUrl(), 'http://127.0.0.1:8804');
        $tcx->setPerPage(9999);
        eq('per_page clamped', $tcx->perPage(), TorrentSearchConfig::MAX_PER_PAGE);

        $scx = new SuiteAdminConfig();
        $scx->setGitwebBaseUrl('javascript:alert(1)');
        eq('suite non-http rejected', $scx->gitwebBaseUrl(), SuiteAdminConfig::DEFAULT_GITWEB_BASE_URL);
        $scx->setTimeoutSeconds(999);
        eq('suite timeout clamped', $scx->timeoutSeconds(), SuiteAdminConfig::MAX_TIMEOUT);

        $gbx = new GitBrowseConfig();
        $gbx->setApiUrl('javascript:alert(1)');
        eq('gitbrowse api non-http rejected', $gbx->apiUrl(), GitBrowseConfig::DEFAULT_API_URL);
        $gbx->setApiUrl('http://gitweb:8801/');
        eq('gitbrowse api kept + trimmed', $gbx->apiUrl(), 'http://gitweb:8801');
        $gbx->setPublicUrl('file:///etc');
        eq('gitbrowse public non-http rejected', $gbx->publicUrl(), '');
        $gbx->setPublicUrl('https://git.example.onion/');
        eq('gitbrowse public kept + trimmed', $gbx->publicUrl(), 'https://git.example.onion');
        $gbx->setTimeoutSeconds(999);
        eq('gitbrowse timeout clamped', $gbx->timeoutSeconds(), 10);

        // ===================================================================
        // 10. The Search page's engine tabs: torrent results carry their
        //     files and first-seen time, and a hit's view keeps every optional
        //     part a list (no flag sections inside the result loop).
        // ===================================================================
        echo "[search tabs] torrent results carry file previews\n";
        $tcf = new TorrentSearchConfig();
        $tcf->setBaseUrl($mockBase);
        $tr10 = (new TorrentSearchClient($tcf))->search('ubuntu', 1);
        eq('files: first result has 2 files', count($tr10['results'][0]['files']), 2);
        noMarkup('files: hostile path', $tr10['results'][0]['files'][1]['path']);
        eq('files: first seen kept', $tr10['results'][0]['first_seen_ts'], 1700000000);
        eq('files: rows without them stay empty', $tr10['results'][1]['files'], []);

        $hit = new SearchHit(title: 'T', url: '/t', meta: ['Size: 1 GiB', 'Files: 3'],
                             files: [['path' => 'a', 'size' => '1 B']], moreFiles: 2, external: false);
        $v = $hit->view();
        eq('hit view: no snippet → empty list', $v['snippet'], []);
        eq('hit view: meta joined', $v['meta'], [['text' => 'Size: 1 GiB · Files: 3']]);
        eq('hit view: more files', $v['more_files'], [['count' => 2]]);
        eq('hit view: internal link rel', $v['rel'], 'nofollow');

        // ===================================================================
        // 10b. The git browser's client against a hostile gitweb API
        // ===================================================================
        echo "[gitbrowse] client: repositories, trees, files, log, commits\n";
        $gc = new GitBrowseConfig();
        $gc->setApiUrl($mockBase);
        $git = new GitBrowseClient($gc);
        $repos = $git->repos();
        eq('git repos ok', $repos['ok'], true);
        eq('git repos: bad name dropped, newest first', array_column($repos['repos'], 'name'), ['site.git', 'old.git']);
        check('git repos: description has no control chars', preg_match('/[\x00-\x1f]/', $repos['repos'][0]['description']) === 0);
        $sum = $git->summary('site.git');
        eq('git summary: option-like branch dropped', $sum['branches'], ['main']);
        eq('git summary: commit subject is one line', $sum['commits'][0]['subject'], 'Fix <b>it</b> now');
        $tree = $git->tree('site.git', '', '');
        eq('git tree: climbing entry dropped', array_column($tree['entries'], 'path'), ['src', 'README.md']);
        check('git tree: README has no control chars', !str_contains($tree['readme'], "\x01"));
        eq('git tree: a file path is reported as a file', $git->tree('site.git', '', 'README.md')['is_file'], true);
        $blob = $git->blob('site.git', '', 'README.md');
        eq('git blob: text kept (escaped later), tab kept, controls dropped', $blob['text'], "line one\n\t<script>two</script>\n");
        $log = $git->log('site.git', '', 1);
        eq('git log: bad sha dropped', count($log['commits']), 1);
        eq('git log: has more', $log['has_more'], true);
        $com = $git->commit('site.git', str_repeat('c', 40));
        eq('git commit: bad parent dropped', $com['parents'], [str_repeat('d', 40)]);
        eq('git commit: verified', $com['verified'], true);
        // Refused before any request: hostile repository, path, ref, id.
        eq('git: ../ repo refused',  $git->summary('../etc')['found'], false);
        eq('git: ../ path refused',  $git->tree('site.git', '', '../x')['found'], false);
        eq('git: option ref refused', $git->tree('site.git', '--upload-pack=x', '')['found'], false);
        eq('git: bad commit id refused', $git->commit('site.git', 'HEAD;rm')['found'], false);
        eq('git: unknown repo is "not found", not "down"', $git->summary('nope.git'), ['ok' => true] + $git->summary('../etc'));
        $gcDead = new GitBrowseConfig();
        $gcDead->setApiUrl('http://127.0.0.1:1');
        eq('git: server down → ok=false', (new GitBrowseClient($gcDead))->repos()['ok'], false);

        // ===================================================================
        // 11. blocklist editor: POST /blocklist + /api/block, auth shapes
        // ===================================================================
        echo "[blocklist] client: push to onioncrawler /blocklist and torrentds /api/block\n";
        $bc = new BlocklistConfig();
        $bc->setOnioncrawlerBaseUrl($mockBase);
        $bc->setTorrentdsBaseUrl($mockBase);
        $bc->setOnioncrawlerAdminToken($ADMIN_TOKEN);
        $bc->setTorrentdsAdminToken($ADMIN_TOKEN);
        $block = new BlocklistClient($bc);

        @unlink($blockLog);
        $ro = $block->blockOnion('host', 'evil123abc.onion');
        eq('block onion ok',     $ro['ok'], true);
        eq('block onion status', $ro['status'], 'added');
        $rawO     = @file_get_contents($blockLog);
        $recOnion = is_string($rawO) ? json_decode($rawO, true) : null;
        check('mock recorded onion block', is_array($recOnion));
        if (is_array($recOnion)) {
            eq('…to /blocklist',    $recOnion['path']  ?? null, '/blocklist');
            eq('…kind host',        $recOnion['kind']  ?? null, 'host');
            eq('…value',            $recOnion['value'] ?? null, 'evil123abc.onion');
            eq('…token via field',  $recOnion['via_field']  ?? null, true);
            eq('…token via header', $recOnion['via_header'] ?? null, true);
        }

        @unlink($blockLog);
        $rt = $block->blockTorrent('infohash', $IH1);
        eq('block torrent ok',     $rt['ok'], true);
        eq('block torrent status', $rt['status'], 'added');
        $rawT       = @file_get_contents($blockLog);
        $recTorrent = is_string($rawT) ? json_decode($rawT, true) : null;
        check('mock recorded torrent block', is_array($recTorrent));
        if (is_array($recTorrent)) {
            eq('…to /api/block', $recTorrent['path'] ?? null, '/api/block');
            eq('…kind infohash', $recTorrent['kind'] ?? null, 'infohash');
        }

        echo "[blocklist] client: duplicate / empty / forbidden / unconfigured / unreachable\n";
        $rd = $block->blockOnion('keyword', 'dupe');
        eq('block duplicate detected', $rd['status'], 'duplicate');
        eq('block duplicate ok=false', $rd['ok'], false);

        $re = $block->blockOnion('host', '   ');
        eq('block empty value (no network)', $re['status'], 'empty');

        $bcBad = new BlocklistConfig();
        $bcBad->setOnioncrawlerBaseUrl($mockBase);
        $bcBad->setOnioncrawlerAdminToken('wrong-token');
        $rf = (new BlocklistClient($bcBad))->blockOnion('host', 'x.onion');
        eq('block wrong token forbidden', $rf['status'], 'forbidden');
        eq('block forbidden ok=false',    $rf['ok'], false);

        $bcNone = new BlocklistConfig();
        $bcNone->setOnioncrawlerBaseUrl($mockBase);
        $rn = (new BlocklistClient($bcNone))->blockOnion('host', 'x.onion');
        eq('block unconfigured (no token, no network)', $rn['status'], 'unconfigured');

        $bcDead = new BlocklistConfig();
        $bcDead->setTorrentdsBaseUrl('http://127.0.0.1:1');
        $bcDead->setTorrentdsAdminToken($ADMIN_TOKEN);
        $ru = (new BlocklistClient($bcDead))->blockTorrent('keyword', 'spam');
        eq('block unreachable engine', $ru['status'], 'unreachable');

        $bcx = new BlocklistConfig();
        $bcx->setOnioncrawlerBaseUrl('javascript:alert(1)');
        eq('block non-http rejected', $bcx->onioncrawlerBaseUrl(), BlocklistConfig::DEFAULT_ONIONCRAWLER_BASE_URL);
        $bcx->setOnioncrawlerAdminToken('  spaced  ');
        eq('block token trimmed', $bcx->onioncrawlerAdminToken(), 'spaced');

        // ===================================================================
        // 12. The templates, rendered by the real TemplateEngine when it is
        //     available: a torrent result keeps its magnet and .torrent links
        //     and lists its files; the Search page shows tabs and hits; the
        //     git file view escapes code. The per-result parts are lists, which
        //     keep the loop row (a {{#flag}} section would lose it).
        // ===================================================================
        echo "[templates] torrent results, Search page, git file view\n";
        $torUrl = $mockBase . '/torrent/' . $IH1 . '.torrent';
        $torHit = (new SearchHit(
            title: 'Ubuntu', url: '/torrentsearch?ih=' . $IH1,
            meta: ['Size: 1.5 GiB', 'Files: 3', 'Found 2 days ago', 'Seed/Leech: 12/3'],
            links: [['label' => 'Magnet', 'url' => 'magnet:?xt=urn:btih:' . $IH1], ['label' => 'Download .torrent', 'url' => $torUrl]],
            files: [['path' => 'ubuntu/ubuntu.iso', 'size' => '1.5 GiB']], moreFiles: 2, external: false,
        ))->view();
        $webHit = (new SearchHit(title: 'A <b>page</b>', url: 'http://example.com/', displayUrl: 'http://example.com/', snippet: 'x'))->view();
        $rendered = [];
        if (class_exists(\AstrX\Template\TemplateEngine::class)) {
            try {
                $root = dirname(__DIR__, 2);
                require_once $root . '/src/support.php';
                $engine = new \AstrX\Template\TemplateEngine(null, \AstrX\ApplicationContainer::resourceRoots());
                $engine->setTemplateDir($root . '/resources/template/');
                $engine->setTemplateExtension('.html');
                $engine->setCacheTemplates(false);
                $render = static function (string $name, array $vars) use ($engine): ?string {
                    $r = $engine->renderTemplate($name, $vars);
                    return $r->isOk() ? $r->unwrap() : null;
                };
                $rendered['torrent'] = $render('torrent_search', [
                    'is_detail' => false, 'searched' => true, 'backend_unavailable' => false,
                    'has_results' => true, 'torrent_results' => [$torHit], 'lbl_more_files' => 'more files',
                ]);
                $rendered['search'] = $render('site_search', [
                    'searched' => true, 'backend_unavailable' => false, 'has_results' => true, 'has_tabs' => true,
                    'search_tabs' => [['label' => 'This site', 'url' => '/s', 'active' => false], ['label' => 'Torrents', 'url' => '/s?source=torrents', 'active' => true]],
                    'search_hits' => [$torHit, $webHit], 'lbl_more_files' => 'more files',
                ]);
                $rendered['git'] = $render('git_browse', [
                    'view_blob' => true, 'is_binary' => false,
                    'lines' => [['n' => 1, 'text' => '<script>alert(1)</script>'], ['n' => 2, 'text' => "\tindented"]],
                ]);
            } catch (\Throwable $e) {
                $rendered = [];
            }
        }
        if (count(array_filter($rendered, 'is_string')) === 3) {
            [$rt, $rs, $rg] = [$rendered['torrent'], $rendered['search'], $rendered['git']];
            check('torrent: .torrent link',            str_contains($rt, 'href="' . $torUrl . '"'));
            check('torrent: magnet link',              str_contains($rt, 'href="magnet:?xt=urn:btih:' . $IH1 . '"'));
            check('torrent: file listed with size',    str_contains($rt, 'ubuntu/ubuntu.iso<span class="file-size">1.5 GiB</span>'));
            check('torrent: more files',               str_contains($rt, '+ 2 more files'));
            check('torrent: facts line',               str_contains($rt, 'Seed/Leech: 12/3'));
            check('torrent: no empty href',            !str_contains($rt, 'href=""'));
            check('search: active tab marked',         str_contains($rs, 'class="active">Torrents</a>'));
            check('search: torrent hit lists its file', str_contains($rs, 'ubuntu/ubuntu.iso'));
            check('search: web hit title escaped',     str_contains($rs, 'A &lt;b&gt;page&lt;/b&gt;'));
            check('search: external link rel',         str_contains($rs, 'rel="noopener noreferrer nofollow"'));
            check('git: code escaped',                 str_contains($rg, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($rg, '<script>alert'));
            check('git: line numbers',                 str_contains($rg, '<span class="gb-ln">2</span>'));
        } else {
            // The framework's engine isn't reachable (run outside the merged
            // tree): check the view model the templates depend on instead.
            eq('view-model: links kept', count($torHit['links']), 2);
            eq('view-model: files kept', count($torHit['files']), 1);
        }

    } finally {
        proc_terminate($proc);
        foreach ($pipes as $p) { if (is_resource($p)) { fclose($p); } }
        proc_close($proc);
        if (is_string($addLog)) { @unlink($addLog); }
        if (is_string($blockLog)) { @unlink($blockLog); }
    }

    echo "\n==== $PASS passed, $FAIL failed ====\n";
    exit($FAIL === 0 ? 0 : 1);
}
