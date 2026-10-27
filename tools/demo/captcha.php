<?php
declare(strict_types=1);

// Prints the text of one login captcha, so the screenshot script can log in:
//   php tools/demo/captcha.php <captcha id>
// For a local demo database only.
$root = dirname(__DIR__, 2);
$cfg = (require $root . '/resources/config/PDO.config.php')['PDO'];
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_port'] ?? 3306, $cfg['db_name']),
    $cfg['db_username'], $cfg['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$q = $pdo->prepare('SELECT text FROM captcha WHERE id = ?');
$q->execute([$argv[1] ?? '']);
echo (string) $q->fetchColumn();
