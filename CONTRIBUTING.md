# Contributing to AstrX

Thank you for helping. This page says where things live, which rules the
checks enforce, and what a change needs before it is merged.

## Set up

You need PHP 8.4 with the extensions listed in `composer.json` (and
`pdo_sqlite` for the tests) and, for the database tests, MariaDB or MySQL.

- With Nix: `nix develop` gives you all of it.
- Without Nix: install them yourself, or use the Docker setup in
  [getting started](docs/getting-started.md).

Then `composer install`, which puts the packages in `vendor/`.

## Where things live

This repository is the site. The code it's built from lives in packages, each
in a repository of its own (see
[how the project is organised](docs/repository-structure.md)):

| Package | What |
|---|---|
| [`astrx/result`](https://github.com/Vxmdzhb/result) | typed results and diagnostics |
| [`astrx/support`](https://github.com/Vxmdzhb/support) | paths, resource roots, atomic writes |
| [`astrx/config`](https://github.com/Vxmdzhb/config) | configuration and `#[InjectConfig]` |
| [`astrx/injector`](https://github.com/Vxmdzhb/injector) | constructor injection |
| [`astrx/http`](https://github.com/Vxmdzhb/http) | requests, responses, uploads |
| [`astrx/routing`](https://github.com/Vxmdzhb/routing) | URL parsing and generation |
| [`astrx/i18n`](https://github.com/Vxmdzhb/i18n) | translations and diagnostic messages |
| [`astrx/error-handler`](https://github.com/Vxmdzhb/error-handler) | PHP errors as diagnostics |
| [`astrx/template`](https://github.com/Vxmdzhb/template) | the template engine |
| [`astrx/session`](https://github.com/Vxmdzhb/session) | encrypted sessions, flash, POST/redirect/GET |
| [`astrx/csrf`](https://github.com/Vxmdzhb/csrf) | CSRF tokens |
| [`astrx/module`](https://github.com/Vxmdzhb/module) | the module contract |
| [`astrx/pagination`](https://github.com/Vxmdzhb/pagination) | page links |
| [`astrx/kernel`](https://github.com/Vxmdzhb/kernel) | controllers |
| [`astrx/auth`](https://github.com/Vxmdzhb/auth) | permissions, policies, TOTP |
| [`astrx/api`](https://github.com/Vxmdzhb/api) | API keys |
| [`astrx/database`](https://github.com/Vxmdzhb/database) | the database connection |
| [`astrx/captcha`](https://github.com/Vxmdzhb/captcha) | captchas |
| [`astrx/image`](https://github.com/Vxmdzhb/image) | upload checks and re-encoding |
| [`astrx/identicon`](https://github.com/Vxmdzhb/identicon) | generated avatars |
| [`astrx/mail`](https://github.com/Vxmdzhb/mail) | SMTP, IMAP, mailboxes |
| [`astrx/cms`](https://github.com/Vxmdzhb/cms) | accounts, admin panel, pages, navigation, themes, comments, news, invites, data retention |
| `astrx/module-<key>` | one optional module each (chat, imageboard, search, webmail, …) |

In this repository:

| Path | What |
|---|---|
| `src/AstrX/` | the site: the request pipeline, its own pages, the installed-module list |
| `resources/` | the site's layout, themes and catalogs; overrides of any package's templates, catalogs and config |
| `src/setup/` | the site's own schema pieces and migrations (its JS runtime pages, sitemap, exit page); the core tables are in the cms package |
| `tools/`, `tests/` | the installer, the checks below, and the tests |

A fix to a package goes to that package's repository; to try it here, see
[working on several repositories at once](docs/repository-structure.md#working-on-several-repositories-at-once).

A package keeps its templates, catalogs and default config in its own
`resources/`. The site's `resources/` overrides a template by name, and a
catalog or config file key by key. See [architecture](docs/architecture.md)
and [modules](docs/MODULES.md).

## Rules the checks enforce

- **Layers.** foundation → component → library → feature → application. A
  namespace may use its own layer or a lower one, never a higher one, and
  there are no cycles. `tools/check_architecture.php` reports the offending
  `file:line`.
- **Packages declare what they use.** Each `composer.json` requires exactly the
  packages its code uses. After you add, move or rename code, run
  `php tools/packages.php --write`, which regenerates the class loader's map
  (`src/sources.php`, `src/support.php`, `src/resources.php`), then
  `php tools/packages.php`.
- **Core never names a module.** When core needs something from modules, it
  owns an interface and asks `ModuleRegistry::extensions()`. A module's chrome
  joins the layout through `layout` slots. Only `ApplicationModules` lists
  modules. See [extension points](docs/MODULES.md#extension-points-optional).
- **Every string in both languages.** Each catalog key exists in `en` and `it`
  (`tools/check_lang_parity.php`). Every emitted diagnostic id has a message in
  every locale (`tools/check_diagnostics.php`).
- **Config keys reach code.** A key the admin can save must have a consumer
  (`tools/check_config.php`).
- **Module manifests are complete.** `tools/check_modules.php` covers
  controllers, guards, policies, permissions, extensions, layout partials,
  teardown and SQL names.
- **Migrations are append-only.** A shipped `migrate_*.sql` is recorded by name
  and checksum, so never edit or rename one; add a new one. Schema pieces under
  `schema/` are re-applied on every install and must stay idempotent.
- **Pages work without JavaScript.** The JS runtime is an optional layer over
  server-rendered pages.

## Before you open a pull request

```sh
php phpstan.phar analyse -l 10 --no-progress   # version pinned in .github/workflows/ci.yml
php tools/check_architecture.php
php tools/packages.php
php tools/check_modules.php
php tools/check_config.php
php tools/check_lang_parity.php
php tools/check_diagnostics.php
for t in tests/*_test.php tests/suite-bridge/*_test.php; do php "$t" || echo "FAILED: $t"; done
```

`nix flake check` runs the gates. Tests that need a database read
`ASTRX_TEST_DB_HOST`, `ASTRX_TEST_DB_PORT`, `ASTRX_TEST_DB_USER` and
`ASTRX_TEST_DB_PASS`, and skip with a note when there is none. A skip is not a
pass: CI runs them against MariaDB.

A change that fixes observable behaviour comes with a test that fails without
it. For a change to what pages render, compare a crawl of the site before and
after.

## Commits

- One logical change per commit; the tree builds and the checks pass at every
  commit.
- Subject line in the imperative, about 72 characters at most ("Move the chat
  toolbar into the nav slot").
- The body says why the change is needed and what it changes for users or
  operators, not a list of files.
- Moves are moves: rename files with `git mv`, and keep content edits in a
  separate commit where you can, so history follows the file.

## Reporting bugs and security issues

For a bug report, include:
- the commit;
- the PHP version (or Rust version, for the engines);
- the enabled modules;
- the steps that reproduce it.

Remove credentials and personal data from logs.

Do not report security vulnerabilities in public issues: see
[SECURITY.md](SECURITY.md).
