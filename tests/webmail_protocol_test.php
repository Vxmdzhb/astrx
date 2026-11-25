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
