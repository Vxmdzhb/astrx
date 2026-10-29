<?php
declare(strict_types=1);

// Real PHP/libsodium and repository SQL, isolated SQLite; no bootstrap or network.
// Run after applying the cms patch to vendor/astrx/cms. Requires PHP 8.4 + pdo_sqlite.
require dirname(__DIR__) . '/src/autoload.php';

use AstrX\Http\Request;
use AstrX\Image\ImageSanitizer;
use AstrX\Session\ServerSecret;
use AstrX\Support\Paths;
use AstrX\User\AvatarService;
use AstrX\User\UserRepository;
use AstrX\User\UserService;
use AstrX\User\UserSession;
use AstrX\User\Zkp\ChallengeRepository;
use AstrX\User\Zkp\Proof;
use AstrX\User\Zkp\ZkpService;

if (!Proof::available() || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('Requires PHP sodium with Ristretto and PDO SQLite');
}
$checks = [];
function check(string $name, bool $ok): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
    $checks[] = $name;
}

$scratch = sys_get_temp_dir() . '/astrx-zkp-test-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
session_save_path($scratch);
session_start();

/** Independent test prover, with fresh nonces. Production never creates proofs. */
function makeProof(array $challenge, string $username, string $secret): array {
    $r = sodium_crypto_core_ristretto255_scalar_random();
    $R = sodium_crypto_scalarmult_ristretto255_base($r);
    $X = sodium_crypto_scalarmult_ristretto255_base($secret);
    $fields = ['AstrX Schnorr proof of knowledge v1', 'ristretto255-sha512',
        hex2bin('e2f2ae0a6abc4e71a884a961c500515f58e30b6aa582dd8db6a65945e08d2d76'),
        $challenge['origin'], $challenge['action'], hex2bin($challenge['nonce']), (string) $challenge['expires'], $username, $X, $R];
    $bytes = '';
    foreach ($fields as $field) { $bytes .= pack('N', strlen($field)) . $field; }
    $c = sodium_crypto_core_ristretto255_scalar_reduce(hash('sha512', $bytes, true));
    $z = sodium_crypto_core_ristretto255_scalar_add($r, sodium_crypto_core_ristretto255_scalar_mul($c, $secret));
    return ['v' => 1, 'nonce' => $challenge['nonce'], 'username' => $username,
        'public_key' => bin2hex($X), 'commitment' => bin2hex($R), 'response' => bin2hex($z)];
}

try {
    $vector = json_decode(file_get_contents(__DIR__ . '/fixtures/zkp-v1.json'), true, flags: JSON_THROW_ON_ERROR);
    $c = $vector['challenge']; $p = $vector['proof'];
    check('Python-generated vector verifies in PHP', Proof::verify($c, 'alice', $p));
    check('Python transport roundtrips canonically', Proof::decode($vector['proof_token']) === $p);
    foreach (['origin' => 'https://wrong.example', 'action' => 'register', 'expires' => $c['expires'] + 1, 'nonce' => str_repeat('cd',32), 'suite' => 'wrong', 'v' => 2] as $field => $value) {
        $changed = $c; $changed[$field] = $value;
        check('reject changed ' . $field, !Proof::verify($changed, 'alice', $p));
    }
    check('reject wrong account', !Proof::verify($c, 'bob', $p));
    foreach (['public_key', 'commitment', 'response'] as $field) {
        $bad = $p; $bad[$field] = str_repeat('00', 32);
        check('reject zero ' . $field, !Proof::verify($c, 'alice', $bad));
        $bad[$field] = str_repeat('ff', 32);
        check('reject malformed ' . $field, !Proof::verify($c, 'alice', $bad));
    }
    $bad = $p;
    $bad['response'] = 'edd3f55c1a631258d69cf7a2def9de1400000000000000000000000000000010'; // L
    check('reject noncanonical scalar', !Proof::verify($c, 'alice', $bad));
    check('reject overlong token', Proof::decode(str_repeat('a',4097)) === null);
    $duplicates = rtrim(strtr(base64_encode('{"v":1,"v":2}'), '+/', '-_'), '=');
    check('reject duplicate JSON fields', Proof::decode($duplicates) === null);
    $infinite = rtrim(strtr(base64_encode('{"v":1e400}'), '+/', '-_'), '=');
    check('reject overflowing JSON numbers without throwing', Proof::decode($infinite) === null);

    $pdo = PDO::connect('sqlite:' . $scratch . '/db.sqlite');
    if (!$pdo instanceof Pdo\Sqlite) { throw new RuntimeException('PHP 8.4 PDO SQLite required'); }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->createFunction('UNHEX', static fn(string $s): string|false => hex2bin($s), 1);
    $pdo->createFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
    $pdo->createFunction('UNIX_TIMESTAMP', static fn(?string $s): ?int => $s === null ? null : strtotime($s), 1);
    $pdo->exec("CREATE TABLE user (
        id BLOB PRIMARY KEY, username TEXT NOT NULL COLLATE NOCASE UNIQUE, password TEXT NOT NULL,
        mailbox TEXT, email TEXT, display_name TEXT, birth TEXT, type INTEGER DEFAULT 0,
        verified INTEGER DEFAULT 0, avatar INTEGER DEFAULT 0, deleted INTEGER DEFAULT 0,
        deletion_mode TEXT DEFAULT 'none', theme TEXT, session_epoch INTEGER DEFAULT 0,
        login_attempts INTEGER DEFAULT 0, login_locked_until INTEGER, last_access TEXT,
        totp_secret TEXT, totp_enabled INTEGER DEFAULT 0, totp_recovery TEXT, totp_last_step INTEGER DEFAULT 0,
        totp_fail_count INTEGER DEFAULT 0, token_hash TEXT, token_type INTEGER, token_used INTEGER DEFAULT 0, token_expires_at TEXT
    )");
    $pdo->exec('CREATE TABLE user_zkp_key (user_id BLOB PRIMARY KEY REFERENCES user(id) ON DELETE CASCADE, public_key TEXT UNIQUE NOT NULL)');
    $pdo->exec('CREATE TABLE user_zkp_challenge (nonce TEXT PRIMARY KEY, session_hash TEXT, action TEXT, origin TEXT, expires INTEGER, used INTEGER DEFAULT 0)');
    $challenges = new ChallengeRepository($pdo);
    $now = time(); $binding = hash('sha256', 'binding-one');
    $issued = $challenges->issue($binding, 'login', 'https://blackhost.xyz', $now);
    check('challenge TTL is five minutes', $issued['expires'] === $now + 300);
    check('challenge is bound to browser', $challenges->find($issued['nonce'],hash('sha256','other'),'login','https://blackhost.xyz',$now) === null);
    check('challenge is bound to operation', !$challenges->consume($issued['nonce'],$binding,'register','https://blackhost.xyz',$now));
    check('challenge is bound to origin', !$challenges->consume($issued['nonce'],$binding,'login','https://wrong.example',$now));
    check('challenge expires at boundary', !$challenges->consume($issued['nonce'],$binding,'login','https://blackhost.xyz',$now+300));
    check('one winner spends challenge', $challenges->consume($issued['nonce'],$binding,'login','https://blackhost.xyz',$now));
    $secondPdo = PDO::connect('sqlite:' . $scratch . '/db.sqlite');
    check('second connection cannot replay', !(new ChallengeRepository($secondPdo))->consume($issued['nonce'],$binding,'login','https://blackhost.xyz',$now));

    $_SERVER = ['HTTP_HOST'=>'blackhost.xyz','SERVER_PORT'=>443,'HTTPS'=>'on','REQUEST_URI'=>'/en/login','REQUEST_METHOD'=>'GET'];
    $_GET = $_POST = $_COOKIE = $_FILES = [];
    $request = Request::fromGlobals()->unwrap();
    $zkp = new ZkpService($challenges,$request);
    $domain = AstrX\Config\ConfigDomainResolver::declaredOn(ZkpService::class);
    check('config is explicitly loaded from User.config.php', count($domain) === 1 && $domain[0]->section === 'ZkpService' && $domain[0]->fileBaseName() === 'User');
    check('feature defaults off', $zkp->origin() === null);
    $zkp->setEnabled(true); $zkp->setOrigins(['https://elsewhere.example']);
    check('untrusted Host is rejected', $zkp->origin() === null);
    $zkp->setOrigins(['https://blackhost.xyz']);
    check('configured origin works', $zkp->origin() === 'https://blackhost.xyz');
    $_SESSION['_zkp_binding'] = str_repeat('12',32);
    $sessionBinding = hash('sha256',$_SESSION['_zkp_binding']);
    $secret = sodium_crypto_core_ristretto255_scalar_random();
    $key = bin2hex(sodium_crypto_scalarmult_ristretto255_base($secret));
    $live = $challenges->issue($sessionBinding,'login','https://blackhost.xyz',time());
    $valid = Proof::encode(makeProof($live,'alice',$secret));
    check('service accepts valid bound proof', $zkp->verify('login','alice',$valid)->unwrap() === $key);
    check('service rejects replay', !$zkp->verify('login','alice',$valid)->isOk());
    $live = $challenges->issue($sessionBinding,'login','https://blackhost.xyz',time());
    $proof = makeProof($live,'alice',$secret); $bad = $proof; $bad['response'] = str_repeat('00',32);
    check('invalid proof fails', !$zkp->verify('login','alice',Proof::encode($bad))->isOk());
    check('invalid proof burns challenge', !$zkp->verify('login','alice',Proof::encode($proof))->isOk());
    $live = $challenges->issue($sessionBinding,'login','https://blackhost.xyz',time());
    $valid = Proof::encode(makeProof($live,'alice',$secret));
    $_SESSION['_zkp_binding'] = str_repeat('34',32);
    check('captured proof cannot enter another browser', !$zkp->verify('login','alice',$valid)->isOk());
    for ($i=0; $i<12; $i++) { $challenges->issue('cap','login','https://blackhost.xyz',time()); }
    $thrown=false; try { $challenges->issue('cap','login','https://blackhost.xyz',time()); } catch (RuntimeException) { $thrown=true; }
    check('issuance cap enforced', $thrown);

    $repo = new UserRepository($pdo); $session = new UserSession();
    $paths = new Paths(resources:$scratch.'/',config:$scratch.'/',temp:$scratch.'/');
    $serverSecret = new ServerSecret($paths); $serverSecret->setConfigured(str_repeat('a',64));
    $users = new UserService($repo,new AvatarService($repo,new ImageSanitizer(),$serverSecret,$paths),$session,$serverSecret);
    $users->setRequireEmail(false); $users->setRequireRecoveryEmail(false); $users->setRequireDisplayName(false);
    $users->setAllowRegister(false);
    check('closed signup also rejects keys', !$users->register('alice','','',null,null,null,null,null,null,$key)->isOk());
    $users->setAllowRegister(true); $users->setRequireDisplayName(true);
    check('key signup keeps profile requirements', !$users->register('alice','','',null,null,null,null,null,null,$key)->isOk());
    $users->setRequireDisplayName(false);
    check('ordinary signup still enforces passwords', !$users->register('pw-user','short','short',null,null,null,null,null,null)->isOk());
    $signup = $users->register('alice','','',null,null,null,null,null,null,$key);
    check('key signup creates account', $signup->isOk()); $uid = $signup->unwrap();
    check('stored credential is only public key', $repo->findZkpKey($uid)->unwrap() === $key);
    check('key signup has no usable password', $repo->findPasswordHash($uid)->unwrap() === UserService::KEY_ONLY_PASSWORD);
    check('duplicate credential fails atomically', !$users->register('duplicate','','',null,null,null,null,null,null,$key)->isOk());
    check('failed credential insert leaves no account', $repo->findByUsername('duplicate')->unwrap() === null);
    check('password path cannot use sentinel', !$users->login('alice',UserService::KEY_ONLY_PASSWORD,false)->isOk());
    check('wrong key cannot authenticate account', !$users->loginWithPublicKey('alice',$p['public_key'],false)->isOk());
    $users->setAllowLoginNonVerified(false);
    check('key path keeps verification requirement', !$users->loginWithPublicKey('alice',$key,false)->isOk());
    $repo->setVerified($uid);
    $pdo->exec("UPDATE user SET deletion_mode = 'keep_visible'");
    check('closed account cannot key-login', !$users->loginWithPublicKey('alice',$key,false)->isOk());
    $pdo->exec("UPDATE user SET deletion_mode = 'none', login_locked_until = ".(time()+300));
    check('account lockout applies to key login', !$users->loginWithPublicKey('alice',$key,false)->isOk());
    $pdo->exec("UPDATE user SET login_locked_until = NULL, totp_enabled = 1, totp_secret = 'enc:v1:broken'");
    check('broken second factor fails closed', !$users->loginWithPublicKey('alice',$key,false)->isOk());
    $pdo->exec('UPDATE user SET totp_enabled = 0');
    $accepted = $users->loginWithPublicKey('alice',$key,false);
    check('key login returns first-factor identity', $accepted->isOk());
    check('service does not bypass controller second-factor gate', !$session->isLoggedIn());
    $session->login($accepted->unwrap());
    check('key login pins the credential epoch', $session->sessionEpoch() === 0);
    check('key-only account cannot remove its only credential', !$users->changePublicKey($uid,'',null)->isOk());
    check('credential still exists after refused removal', $repo->findZkpKey($uid)->unwrap() === $key);
    $password='a real existing account password';
    $repo->updatePassword($uid,password_hash($password,PASSWORD_ARGON2ID));
    $before=(int)$pdo->query('SELECT session_epoch FROM user')->fetchColumn();
    check('password reauthentication permits key removal', $users->changePublicKey($uid,$password,null)->isOk());
    check('removal revokes other sessions atomically', (int)$pdo->query('SELECT session_epoch FROM user')->fetchColumn() === $before+1);
    check('removal signs out acting session', !$session->isLoggedIn());
    check('revoked key no longer authenticates', !$users->loginWithPublicKey('alice',$key,false)->isOk());
    $session->login($accepted->unwrap()); // Simulates an old proof finishing TOTP after revocation.
    check('delayed login cannot adopt the post-revocation epoch', $session->sessionEpoch() === $before);
    $session->logout();
    check('password alternative still authenticates', $users->login('alice',$password,false)->isOk());
} finally {
    session_destroy();
    unset($pdo,$secondPdo,$repo,$users,$challenges,$zkp);
    foreach (glob($scratch.'/*') ?: [] as $file) { unlink($file); }
    rmdir($scratch);
}
foreach ($checks as $name) { echo "ok: $name\n"; }
echo count($checks)." checks passed\n";
