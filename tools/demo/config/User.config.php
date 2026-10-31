<?php
declare(strict_types=1);

// Turns on key login for the demo, so the login and sign-up screenshots show
// it. Copy to resources/config/User.config.php (it only adds these keys).
return [
    'ZkpService' => [
        'enabled' => true,
        'origins' => ['http://127.0.0.1:8082'],
    ],
];
