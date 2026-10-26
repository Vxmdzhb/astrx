<?php
declare(strict_types=1);

/**
 * Where a package's files are, for the tests: vendor/<name>, or its own
 * directory when it's a path repository in this checkout (the same rule
 * src/sources.php follows). Not named *_test.php: it asserts nothing.
 */

namespace AstrX\TestSupport;

require_once dirname(__DIR__, 2) . '/tools/lib/sources.php';

/** Absolute directory of package $name (e.g. 'astrx/cms'); throws when it is not there. */
function packageDir(string $name): string
{
    $root = dirname(__DIR__, 2);
    $pkg  = \AstrX\Tools\packages($root)[$name] ?? null;
    if ($pkg === null) {
        throw new \LogicException("Package {$name} is not installed (run composer install).");
    }
    return $root . '/' . $pkg['dir'];
}
