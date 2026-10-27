<?php
declare(strict_types=1);

/**
 * Optional-module switchboard — the single place that turns AstrX's optional
 * modules on or off.
 *
 * Each key is a module; `false` removes that module's navigation, footer hooks
 * and page guards site-wide. Core code never names a module — it reads this list
 * through {@see \AstrX\Module\ModuleRegistry}, so this file is the only thing you
 * touch to drop a module's UI surface. Default is ON, so an existing install
 * behaves exactly as before until you flip something off. Unlisted modules also
 * default ON (ModuleRegistry::enabled() falls back to true).
 *
 * Flipping a module off removes its whole surface: its nav entries drop, its
 * pages 404 (themed error page), and its footer/section hooks disappear. Data is
 * untouched — `tools/module.php enable` restores it; `tools/module.php purge`
 * drops its schema. The installed modules are listed in
 * ApplicationModules::INSTALLED; this file only holds their on/off flags (a
 * module without one is OFF).
 */
return [
    // One entry per installed module (ApplicationModules::INSTALLED);
    // tools/check_modules.php fails the build when one is missing.
    //
    // Values must be real booleans. Config::getConfigBool rejects anything else
    // (a quoted 'false' would otherwise read as true) and falls back to on.
    'Modules' => [
        'imageboard'    => true,
        'chat'          => true,
        'bottrap'       => true,
        'search'        => true,
        'webmail'       => true,
        'content'       => true,
        'media'         => true,

        // Transparency / trust pages — each independently toggleable. Flip any to
        // false to 404 that page + its admin editor and drop both nav entries.
        'canary'        => true,
        'downloads'     => true,
        'mirrors'       => true,
        'tipline'       => true,

        // Search back ends and admin panels.
        'blocklist'     => true,
        'gitbrowse'     => true,
        'onionsearch'   => true,
        'suiteadmin'    => true,
        'torrentsearch' => true,
        'websearch'     => true,
    ],
];
