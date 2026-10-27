<?php
declare(strict_types=1);

/**
 * Made-up demo content for the screenshots: people, news, comments, boards
 * with generated pictures, chat, and an audit log with a valid hash chain.
 *
 *   php tools/demo/seed.php            # once, on a freshly installed site
 *   php tools/demo/seed.php --refresh  # just before taking screenshots:
 *                                      # puts everyone back "online" in the chat
 *
 * It talks to the database configured in resources/config/PDO.config.php, so
 * run tools/install.php first. Every invented user's password is
 * "demo password 123". The board pictures go to resources/board_uploads
 * (set BOARD_UPLOADS to put them elsewhere).
 */

$root = dirname(__DIR__, 2);
require $root . '/src/autoload.php';
$cfg = (require $root . '/resources/config/PDO.config.php')['PDO'];
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_port'] ?? 3306, $cfg['db_name']),
    $cfg['db_username'], $cfg['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

if (in_array('--refresh', $argv, true)) {
    $pdo->exec('UPDATE chat_presence SET last_seen = NOW()');
    $pdo->exec('UPDATE chat_message SET expires_at = NOW() + INTERVAL 1 DAY');
    echo "refreshed\n";
    exit;
}
if ($pdo->query("SELECT COUNT(*) FROM user WHERE username = 'nova'")->fetchColumn() > 0) {
    fwrite(STDERR, "already seeded (user nova exists)\n");
    exit(1);
}

function hexid(): string { return bin2hex(random_bytes(16)); }
function at(string $ago): string { return date('Y-m-d H:i:s', strtotime("-$ago")); }

// ── users ────────────────────────────────────────────────────────────────────
$users = [];
foreach ([
    ['nova', 'Nova', 0], ['kestrel', 'Kestrel', 0], ['mirelle', 'Mirelle', 0],
    ['otto', 'Otto', 0], ['saffi', 'Saffi', 2], ['juniper', 'Juniper', 0],
] as [$u, $d, $type]) {
    $id = hexid();
    $pdo->prepare('INSERT INTO user (id, username, password, mailbox, email, display_name, type, verified, avatar, created_at, last_access)
                   VALUES (UNHEX(?), ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)')
        ->execute([$id, $u, password_hash('demo password 123', PASSWORD_ARGON2ID), $u, "$u@example.onion", $d, $type, at(rand(20, 300) . ' days'), at(rand(1, 50) . ' minutes')]);
    $users[$u] = $id;
}
$admin = $pdo->query("SELECT LOWER(HEX(id)) FROM user WHERE username='admin'")->fetchColumn();
$pdo->prepare("UPDATE user SET display_name='Ash', created_at=? WHERE username='admin'")->execute([at('412 days')]);

// ── news + comments ──────────────────────────────────────────────────────────
$news = [
    ['Search now covers onion services', "The onion crawler has been running for a week and the index just passed 40 000 pages. Try it from the Search menu: results come straight from our own crawler, no third parties involved.\n\nAs always, everything works with JavaScript turned off.", '2 days'],
    ['New board: /lit/', "By popular demand there's now a board for books and writing. Recommendations, reviews and your own short fiction are all welcome. Be kind, spoiler-tag your endings.", '6 days'],
    ['Warrant canary renewed', "The canary has been re-signed for October. If it ever goes stale, you know what that means.", '13 days'],
];
$newsIds = [];
foreach ($news as [$t, $c, $ago]) {
    $pdo->prepare('INSERT INTO news (title, content, created_at, hidden) VALUES (?, ?, ?, 0)')->execute([$t, $c, at($ago)]);
    $newsIds[] = (int) $pdo->lastInsertId();
}
$comments = [
    [$users['kestrel'], null, 'Searched for a few old forums I remember and found two of them still alive. Great work.', '1 day'],
    [null, 'anon', 'Does the crawler respect robots.txt on onion sites too?', '1 day'],
    [$admin, null, 'It does, and it never follows a link off Tor. The engine is open source if you want to check.', '20 hours'],
    [$users['mirelle'], null, 'Loving the sepia theme by the way.', '9 hours'],
];
$prev = null;
foreach ($comments as $i => [$uid, $name, $text, $ago]) {
    $pdo->prepare('INSERT INTO comment (page_id, item_id, user_id, name, content, reply_to, hidden, flagged, created_at)
                   VALUES (1, NULL, ' . ($uid ? 'UNHEX(?)' : '?') . ', ?, ?, ?, 0, 0, ?)')
        ->execute([$uid, $name, $text, $i === 2 ? $prev : null, at($ago)]);
    $prev = (int) $pdo->lastInsertId();
    if ($i === 1) { $prev = (int) $pdo->lastInsertId(); }
}

// ── boards ───────────────────────────────────────────────────────────────────
$pdo->exec("UPDATE board SET title='Random', subtitle='anything goes', description='The random board.' WHERE slug='b'");
$pdo->exec("INSERT INTO board (slug, title, subtitle, description, active, bbcode, flags_mode, poster_ids, lifecycle, bump_limit, image_limit, thread_limit, max_post_len, cooldown_secs, sort_order)
            VALUES ('lit','Literature','books, writing, poetry','Books and writing.',1,1,'off',0,'archive',300,150,100,4000,30,1),
                   ('g','Technology','computers, privacy, self-hosting','Technology.',1,1,'off',1,'archive',300,150,100,4000,30,2)");
$boards = $pdo->query('SELECT slug, id FROM board')->fetchAll(PDO::FETCH_KEY_PAIR);
$renderer = new AstrX\Imageboard\PostRenderer();
$uploadDir = getenv('BOARD_UPLOADS') ?: $root . '/resources/board_uploads';
@mkdir($uploadDir, 0o775, true);

function artImage(string $path, string $thumb, int $seed, int $w = 640, int $h = 480): array
{
    mt_srand($seed);
    $im = imagecreatetruecolor($w, $h);
    $c1 = [mt_rand(20, 120), mt_rand(20, 120), mt_rand(60, 200)];
    $c2 = [mt_rand(120, 255), mt_rand(60, 200), mt_rand(20, 160)];
    for ($y = 0; $y < $h; $y++) {
        $t = $y / $h;
        $col = imagecolorallocate($im, (int) ($c1[0] + ($c2[0] - $c1[0]) * $t), (int) ($c1[1] + ($c2[1] - $c1[1]) * $t), (int) ($c1[2] + ($c2[2] - $c1[2]) * $t));
        imageline($im, 0, $y, $w, $y, $col);
    }
    // a sun, hills and a few stars: a little landscape
    $sun = imagecolorallocatealpha($im, 255, 230, 160, 30);
    imagefilledellipse($im, mt_rand(120, $w - 120), mt_rand(110, 200), 130, 130, $sun);
    for ($i = 0; $i < 40; $i++) {
        $s = imagecolorallocatealpha($im, 255, 255, 255, mt_rand(40, 100));
        imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, (int) ($h / 2)), 3, 3, $s);
    }
    foreach ([[0.62, 70], [0.72, 45], [0.82, 20]] as [$base, $dark]) {
        $col = imagecolorallocate($im, $dark, $dark + 10, $dark + 25);
        $pts = [0, $h];
        for ($x = 0; $x <= $w; $x += 40) {
            $pts[] = $x;
            $pts[] = (int) ($h * $base + sin($x / 70 + $seed) * 25 + mt_rand(-10, 10));
        }
        $pts[] = $w; $pts[] = $h;
        imagefilledpolygon($im, $pts, $col);
    }
    imagejpeg($im, $path, 88);
    $tw = 250; $th = (int) round($h * $tw / $w);
    $t = imagecreatetruecolor($tw, $th);
    imagecopyresampled($t, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagejpeg($t, $thumb, 85);
    return [$w, $h, $tw, $th];
}

$threads = [
    ['g', 'Self-hosting on a Raspberry Pi over Tor', "Finally moved my little site off shared hosting. Pi 4, external SSD, onion service only.\nUptime has been better than the old host, honestly.\n\nAnyone else running something like this? What did you run into?", true, [
        ["Same setup here, but I'd put the SSD on a powered hub. Mine kept dropping under load.", true],
        [">>%d\n>onion service only\nBased. No ports to forward, no IP to leak.", false],
        ["How's the latency for visitors? Tor adds a few hundred ms per hop for me.", false],
        ["Pages are tiny without JS, so it barely matters. Around a second for a full load here.", false],
    ]],
    ['lit', 'What are you reading this week?', "Halfway through a re-read of an old favourite and it holds up better than I remembered. Post yours.", true, [
        ["A collection of short stories about lighthouse keepers. Slow, quiet, perfect for autumn.", false],
        ["Trying poetry again after years away. Starting small, one poem a night.", false],
        [">>%d\nlighthouse keepers\nTitle? That sounds exactly like what I need right now.", false],
    ]],
    ['b', 'Post your desk', "Mine is mostly cables and an unreasonable number of mugs.", true, [
        ["Cleaned mine for the photo, will be chaos again by tomorrow.", true],
        ["Is that a mechanical keyboard with wooden keycaps? Nice.", false],
    ]],
    ['b', 'Rate my sunset', "Took this from the roof yesterday.", true, [
        ["10/10, the colours are unreal.", false],
    ]],
];
$seed = 7;
foreach ($threads as $ti => [$slug, $subject, $opBody, $opImage, $replies]) {
    $bid = $boards[$slug];
    $pdo->prepare('INSERT INTO board_thread (board_id, subject, reply_count, image_count, bump_time, created_at) VALUES (?, ?, ?, 0, ?, ?)')
        ->execute([$bid, $subject, count($replies), at((10 - $ti * 2) . ' minutes'), at((3 - min($ti, 2)) . ' hours')]);
    $tid = (int) $pdo->lastInsertId();
    $posts = [[$opBody, $opImage, true]];
    foreach ($replies as [$b, $img]) { $posts[] = [$b, $img, false]; }
    $first = null; $images = 0;
    foreach ($posts as $pi => [$body, $img, $isOp]) {
        $no = (int) $pdo->query("SELECT post_seq + 1 FROM board WHERE id = $bid")->fetchColumn();
        $pdo->exec("UPDATE board SET post_seq = $no WHERE id = $bid");
        $first ??= $no;
        $body = sprintf($body, $first);
        $pid = substr(hash('sha256', $slug . $ti . $pi), 0, 8);
        $pdo->prepare('INSERT INTO board_post (thread_id, board_id, no, is_op, name, poster_id, subject, body_raw, body_html, sage, banned, verified, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?)')
            ->execute([$tid, $bid, $no, $isOp ? 1 : 0, 'Anonymous', $pid, $isOp ? $subject : '', $body, $renderer->render($body), at((180 - $ti * 40 - $pi * 9) . ' minutes')]);
        $postId = (int) $pdo->lastInsertId();
        if ($img) {
            // The site serves only <32 hex>.jpg names, so the thumbnail gets a token of its own.
            $tok = bin2hex(random_bytes(16));
            $thumbTok = bin2hex(random_bytes(16));
            [$w, $h, $tw, $th] = artImage("$uploadDir/$tok.jpg", "$uploadDir/$thumbTok.jpg", $seed++);
            $pdo->prepare('INSERT INTO board_image (post_id, token, full_name, thumb_name, mime, byte_size, width, height, thumb_w, thumb_h, ahash, sha256, orig_name, spoiler)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 0)')
                ->execute([$postId, $tok, "$tok.jpg", "$thumbTok.jpg", 'image/jpeg', filesize("$uploadDir/$tok.jpg"), $w, $h, $tw, $th, hash_file('sha256', "$uploadDir/$tok.jpg"), 'IMG_' . (4000 + $seed) . '.jpg']);
            $images++;
        }
    }
    $pdo->prepare('UPDATE board_thread SET image_count = ? WHERE id = ?')->execute([$images, $tid]);
}

// ── chat ─────────────────────────────────────────────────────────────────────
$pdo->exec("UPDATE chat_room SET topic='Be nice. No links to clearnet trackers.' WHERE id=1");
$chat = [
    ['kestrel', '#7fd1ff', 'morning all'],
    ['nova', '#ffb86c', 'morning! coffee number two already'],
    ['otto', '#a6e22e', 'did anyone else see the new /lit/ board?'],
    ['mirelle', '#ff79c6', 'yes!! finally somewhere to post my terrible poems'],
    ['nova', '#ffb86c', '[i]terrible[/i] is doing a lot of work there, they were good'],
    ['juniper', '#bd93f9', 'is the search down for anyone else or just me'],
    ['saffi', '#f1fa8c', 'works here. onion search is a bit slow while the crawler catches up'],
    ['juniper', '#bd93f9', 'ah ok, back now. thanks'],
    ['kestrel', '#7fd1ff', 'how does this chat even work without javascript?'],
    ['saffi', '#f1fa8c', 'the message list is a frame that refreshes itself every few seconds. plain html'],
    ['otto', '#a6e22e', '[b]wizardry[/b]'],
    ['mirelle', '#ff79c6', 'it feels like 2003 in the best way'],
];
$t = count($chat);
foreach ($chat as $i => [$nick, $color, $msg]) {
    $pdo->prepare("INSERT INTO chat_message (room_id, user_id, nick, color, type, content, created_at, expires_at) VALUES (1, UNHEX(?), ?, ?, 'user', ?, ?, ?)")
        ->execute([$users[$nick], $nick, $color, $msg, at(($t - $i) * 2 . ' minutes'), date('Y-m-d H:i:s', time() + 86400)]);
}
foreach (['kestrel' => '#7fd1ff', 'nova' => '#ffb86c', 'otto' => '#a6e22e', 'mirelle' => '#ff79c6', 'juniper' => '#bd93f9', 'saffi' => '#f1fa8c'] as $nick => $color) {
    $pdo->prepare('INSERT INTO chat_presence (ident, is_member, user_id, nick, color, role, status, joined_at, last_seen) VALUES (?, 1, UNHEX(?), ?, ?, ?, 1, ?, NOW())')
        ->execute([substr(hash('sha256', $nick), 0, 32), $users[$nick], $nick, $color, $nick === 'saffi' ? 1 : 0, at('1 hour')]);
}

// ── audit log ────────────────────────────────────────────────────────────────
// Written with the logger's own hash function, so the chain check passes.
$m = new ReflectionMethod(AstrX\Admin\AuditLogger::class, 'computeHash');
$saffi = $pdo->query("SELECT LOWER(HEX(id)) FROM user WHERE username='saffi'")->fetchColumn();
$events = [
  [$admin,'admin','config.save','System','default_language=en','5 days'],
  [$admin,'admin','theme.activate','Themes','sepia','4 days'],
  [$admin,'admin','news.create','News','Warrant canary renewed','13 days'],
  [$admin,'admin','board.create','Imageboard','/lit/','6 days'],
  [$admin,'admin','board.create','Imageboard','/g/','6 days'],
  [$admin,'admin','news.create','News','New board: /lit/','6 days'],
  [$saffi,'saffi','chat.topic','Chat','General','3 days'],
  [$saffi,'saffi','comment.hide','Comments','#12','2 days'],
  [$admin,'admin','user.promote','Users','saffi → MOD','9 days'],
  [$admin,'admin','news.create','News','Search now covers onion services','2 days'],
  [$admin,'admin','invite.create','Invitations','for a friend','1 day'],
  [$admin,'admin','canary.update','Canary','October','13 days'],
];
usort($events, fn($a,$b) => strtotime('-'.$b[5]) <=> strtotime('-'.$a[5]));
$prev = ''; $n = 0;
foreach ($events as [$uid,$user,$action,$res,$detail,$ago]) {
  $ip = '127.0.0.1';
  $h = $m->invoke(null, $prev, $uid, $user, $action, $res, $detail, $ip);
  $pdo->prepare('INSERT INTO admin_audit_log (user_id, username, action, resource, detail, ip, created_at, prev_hash, entry_hash) VALUES (UNHEX(?),?,?,?,?,?,?,?,?)')
      ->execute([$uid,$user,$action,$res,$detail,$ip,date('Y-m-d H:i:s', strtotime("-$ago")),$prev,$h]);
  $prev = $h; $n++;
}
$pdo->prepare("REPLACE INTO site_config (`key`,`value`) VALUES ('audit_chain_head',?),('audit_chain_count',?)")->execute([$prev,(string)$n]);
echo "seeded\n";
