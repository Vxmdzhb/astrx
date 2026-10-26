<?php
declare(strict_types=1);

/**
 * Scratch directories for the standalone tests.
 *
 * Every test writes under sys_get_temp_dir(). This removes a test's directory
 * recursively, and loudly, instead of each test doing its own glob() and
 * @rmdir().
 *
 * Not named *_test.php: CI runs tests/*_test.php, and this file asserts
 * nothing.
 */

namespace AstrX\TestSupport;

/**
 * Delete $dir and everything below it — dotfiles and subdirectories included.
 *
 * scandir(), not glob(): glob() skips dotfiles unless asked twice, and the file
 * these tests kept leaking is TemplateEngine's .template-index.php.
 *
 * Symlinks are unlinked, never descended into: a test cleaning up after itself
 * must not delete whatever a link happens to point at.
 */
function rmTree(string $dir): void
{
    if (\is_link($dir) || !\is_dir($dir)) {
        @\unlink($dir);
        return;
    }

    foreach (\scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . \DIRECTORY_SEPARATOR . $entry;
        if (!\is_link($path) && \is_dir($path)) {
            rmTree($path);
        } else {
            @\unlink($path);
        }
    }

    @\rmdir($dir);
}

/**
 * Create a private scratch directory for this run, removed at shutdown even
 * if the test dies early.
 *
 * PHP runs shutdown functions before object destructors, so an object still
 * alive at exit whose destructor writes into the directory (TemplateEngine
 * saves its cache index in __destruct()) recreates part of it after the
 * cleanup. unset() such objects at the end of the test.
 *
 * @param string $prefix e.g. 'astrx-render-safety-'
 * @return string absolute path, no trailing separator
 */
function scratchDir(string $prefix): string
{
    $dir = \rtrim(\sys_get_temp_dir(), '/\\') . \DIRECTORY_SEPARATOR
         . $prefix . \bin2hex(\random_bytes(6));

    if (!@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
        \fwrite(\STDERR, "cannot create the scratch directory {$dir}\n");
        exit(1);
    }

    \register_shutdown_function(static function () use ($dir): void {
        rmTree($dir);
    });

    return $dir;
}
