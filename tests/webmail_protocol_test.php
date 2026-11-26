<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use AstrX\Mail\ImapClient;
use AstrX\Mail\Mailer;
use AstrX\Webmail\WebmailService;

function check(string $label, bool $ok): void
{
    if (!$ok) { throw new RuntimeException($label); }
    echo "ok: $label\n";
}

/** @return array{ImapClient, resource, resource} */
function connection(string $responses): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) { throw new RuntimeException('Cannot open test socket pair'); }
    [$client, $server] = $pair;
    stream_set_timeout($client, 1);
    stream_set_timeout($server, 1);
    fwrite($server, $responses);
    $imap = new ImapClient();
    (new ReflectionProperty($imap, 'socket'))->setValue($imap, $client);
    return [$imap, $client, $server];
}

function closeConnection(ImapClient $imap, mixed $client, mixed $server): void
{
    (new ReflectionProperty($imap, 'socket'))->setValue($imap, null);
    fclose($client);
    fclose($server);
}

[$imap, $client, $server] = connection("* 1 FETCH (UID 42 FLAGS () ENVELOPE NIL)\r\nA0001 OK done\r\n");
$headers = $imap->fetchHeadersPage(1, 1, 10);
check('inbox reads headers in one round trip', $headers->isOk()
    && ($headers->unwrap()[0]['uid'] ?? 0) === 42
    && fgets($server) === "A0001 FETCH 1:1 (UID FLAGS ENVELOPE)\r\n");
closeConnection($imap, $client, $server);

[$imap, $client, $server] = connection("A0001 ok accepted\r\n");
$webmail = new WebmailService($imap, new Mailer());
$webmail->setMailDomain('example.test');
check('connected socket still authenticates', $webmail->connect('', 'test-password', 'alice')->isOk());
check('username names the default mailbox',
    fgets($server) === "A0001 LOGIN \"alice@example.test\" \"test-password\"\r\n");
closeConnection($imap, $client, $server);

[$imap, $client, $server] = connection("A0001 OK accepted\r\n");
$webmail = new WebmailService($imap, new Mailer());
$webmail->setMailboxIsUsername(false);
$webmail->setMailDomain('example.test');
check('legacy qualified mailbox authenticates', $webmail->connect('alice@legacy.test', 'test-password')->isOk());
check('legacy mailbox domain appears once',
    fgets($server) === "A0001 LOGIN \"alice@legacy.test\" \"test-password\"\r\n");
closeConnection($imap, $client, $server);

[$imap, $client, $server] = connection("A0001 NO rejected\r\nA0002 OK logout\r\n");
$webmail = new WebmailService($imap, new Mailer());
check('authentication rejection remains an error', $webmail->connect('', 'wrong', 'alice')->isErr());
check('rejected authentication closes connection', !$imap->isConnected());
fclose($server);

[$imap, $client, $server] = connection("A0001 BAD no MOVE\r\nA0002 NO cannot COPY\r\n");
check('failed Trash transfer remains an error', $imap->deleteMessage(17)->isErr());
check('attempts move to Trash', fgets($server) === "A0001 UID MOVE 17 \"Trash\"\r\n");
check('falls back to COPY', fgets($server) === "A0002 UID COPY 17 \"Trash\"\r\n");
stream_set_blocking($server, false);
check('failed Trash transfer never deletes original', fread($server, 4096) === '');
closeConnection($imap, $client, $server);

[$imap, $client, $server] = connection("A0001 OK marked\r\nA0002 NO expunge failed\r\n");
check('expunge failure remains an error', $imap->deleteMessage(17, '')->isErr());
closeConnection($imap, $client, $server);

// The ENVELOPE lists from, sender, reply-to, then to (RFC 3501): the list's
// recipient column (Sent, Drafts) must read the fourth, not the reply-to.
[$imap, $client, $server] = connection(
    '* 1 FETCH (UID 7 FLAGS (\Seen) ENVELOPE ("Mon, 23 Nov 2026 10:06:20 +0100" "Hi" '
    . '(("Ash" NIL "ash" "example.test")) (("Ash" NIL "ash" "example.test")) '
    . '(("Replies" NIL "replies" "example.test")) (("Kestrel" NIL "kestrel" "example.test")) '
    . "NIL NIL NIL \"<id@example.test>\"))\r\nA0001 OK done\r\n");
$row = $imap->fetchHeadersPage(1, 1, 10)->unwrap()[0] ?? [];
check('recipient comes from the envelope To', ($row['to_display'] ?? '') === 'Kestrel <kestrel@example.test>');
check('sender comes from the envelope From', ($row['from_display'] ?? '') === 'Ash <ash@example.test>');
closeConnection($imap, $client, $server);

// Oldest first starts at the folder's first message, not at the newest page.
[$imap, $client, $server] = connection("A0001 OK done\r\n");
$imap->fetchHeadersPage(30, 1, 10, true);
check('oldest first fetches the oldest messages', fgets($server) === "A0001 FETCH 1:10 (UID FLAGS ENVELOPE)\r\n");
closeConnection($imap, $client, $server);

// A saved draft's UID (UIDPLUS), so saving again replaces it.
[$imap, $client, $server] = connection("+ go\r\nA0001 OK [APPENDUID 1700000000 55] Append completed.\r\n");
check('append succeeds', $imap->appendToSent("Subject: x\r\n\r\nbody", 'Drafts')->isOk());
check('append reports the new UID', $imap->lastAppendUid() === 55);
closeConnection($imap, $client, $server);

// Attachment names in every form senders use.
$filename = new ReflectionMethod(ImapClient::class, 'extractFilename');
$imap = new ImapClient();
foreach ([
    "attachment; filename*=utf-8''notes%20%C3%A8.txt"            => 'notes è.txt',
    'attachment; filename="two words.pdf"'                      => 'two words.pdf',
    'attachment; filename=plain.txt; size=3'                    => 'plain.txt',
    "attachment; filename*0*=utf-8''caf%C3%A8; filename*1=.doc" => 'cafè.doc',
    'attachment; filename="=?UTF-8?B?Y2Fmw6guanBn?="'           => 'cafè.jpg',
    'attachment; filename="C:\\\\tmp\\\\evil.txt"'              => 'evil.txt',
] as $header => $want) {
    $got = $filename->invoke($imap, ['content-disposition' => $header]);
    check("attachment name from: $header", $got === $want);
}

// Address lists with display names: bare addresses for the envelope, the
// names kept for the headers, quoted commas not splitting.
$mailer = new Mailer();
$parse = new ReflectionMethod(Mailer::class, 'parseAddressList');
$list = $parse->invoke($mailer, '"Doe, John" <john@example.test>, ann@example.test; Zoë <zoe@example.test>');
check('address list splits outside quotes', $list === [
    ['address' => 'john@example.test', 'name' => 'Doe, John'],
    ['address' => 'ann@example.test',  'name' => ''],
    ['address' => 'zoe@example.test',  'name' => 'Zoë'],
]);
$format = new ReflectionMethod(Mailer::class, 'formatAddressList');
check('non-ASCII display names are encoded words',
    $format->invoke($mailer, $list) === '"Doe, John" <john@example.test>, <ann@example.test>, =?UTF-8?B?Wm/Dqw==?= <zoe@example.test>');
