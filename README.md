# AstrX

AstrX is a website you can run yourself: news and comments, an imageboard,
a chat, a wiki, webmail, an anonymous tip line, and search across your site,
the web and onion services. All of it works without JavaScript, so it runs
fine in Tor Browser on its safest setting.

It is also the PHP framework the site is built on, split into small packages
you can use on their own.

<p align="center">
  <img src="docs/screenshots/home-default.png" width="49%" alt="The home page with news and comments">
  <img src="docs/screenshots/chat-default.png" width="49%" alt="The chat, with messages and the list of people online">
  <img src="docs/screenshots/board-default.png" width="49%" alt="A thread on the imageboard">
  <img src="docs/screenshots/admin-users.png" width="49%" alt="User management in the admin panel">
</p>

<p align="center"><a href="docs/screenshots/README.md">More screenshots, including the admin panel</a></p>

## What's in it

- **Imageboard**: boards, threads, image posts, moderation, feeds and a choice
  of classic styles.
- **Chat**: rooms, private messages and attachments. New messages show up
  through a plain HTML refresh, no script needed.
- **Content**: Markdown pages with wiki links, backlinks and a page graph.
- **Webmail**: an IMAP/SMTP client in the browser.
- **Tip line**: anonymous tips, sealed with a public key the moment they
  arrive and only readable offline, with the private key.
- **Search**: the site's own full-text search, plus web, onion and torrent
  search when you run the engines from [astrx-suite][suite].
- **Trust pages**: a warrant canary, signed downloads and a signed list of
  onion mirrors.
- **The basics**: accounts with two-factor login, an admin panel, themes,
  comments, invites, captchas, a bot trap and data retention rules.

Ten themes ship with it, from plain black-and-white to amber terminal and
synthwave, and each visitor can pick their own:

<p align="center"><img src="docs/screenshots/themes.png" alt="The same page in six of the bundled themes"></p>

Every feature is a module. Switch off the ones you don't want and their pages
and menu entries disappear; their data stays until you purge it.

## Running it

You need PHP 8.4 with PDO MySQL, OpenSSL, GD and mbstring, Composer and
MariaDB (or MySQL).

```sh
git clone https://github.com/Vxmdzhb/astrx.git
cd astrx
composer install
php tools/install.php
php -S 127.0.0.1:8080 -t public public/index.php
```

Then open <http://127.0.0.1:8080/en/>. The installer asks for the database
details and an admin account. For a real server, point nginx (or anything
that speaks PHP-FPM) at `public/`.

There is also a Docker setup that brings up the site, the database and (with
[astrx-suite][suite] cloned next to this repository) the Rust engines, and a Nix flake (`nix develop`) with everything needed
to work on it. [Getting started](docs/getting-started.md) covers both, along
with backups and upgrades.

## Using the framework without the site

The site is one application of the framework, not the only one. Each part is
its own package with its own repository:

| Package | What it gives you |
|---|---|
| [framework] | Typed results, configuration, injection, HTTP and routing, templates, translation, sessions |
| [library] | Database, permissions, captchas, image handling, mail |
| [cms] | Accounts, admin panel, pages, navigation, themes, comments, news |
| `module-*` | One package per feature above, for example [module-chat] |

`framework` runs an application on its own; [`examples/minimal`](examples/minimal)
is a small one. None of the packages pulls in third-party PHP code.

## The Rust engines

Search, the git browser and the operations dashboard are separate services
written in Rust. They live in [astrx-suite][suite], which builds them into a
single `astrx` binary and runs them in a few containers. The site talks to
them over HTTP, and is perfectly happy without them.

## Documentation

- [Getting started](docs/getting-started.md): installing, Docker, Nix
- [Modules](docs/MODULES.md): turning features on and off, writing your own
- [Architecture](docs/architecture.md): how a request moves through the code
- [Operations](docs/OPERATIONS.md): storage, backups, upgrades
- [Connecting the Rust engines](docs/suite-search-modules.md)
- [How the project is organised](docs/repository-structure.md): the
  repositories, and working on several at once
- [JSON API](docs/API.md)

## Contributing

Bug reports and small, focused changes are the easiest to take.
[CONTRIBUTING.md](CONTRIBUTING.md) explains the layout and the checks a
change has to pass. Please report security problems privately, as described
in [SECURITY.md](SECURITY.md).

## Status

There are no version numbers: every repository moves forward on `main`, and
lockfiles pin the exact commits a build uses. APIs can still change between
commits, and the code hasn't had an independent security audit.

The license is still being written. Until it is published, please ask before
reusing the code.

[suite]: https://github.com/Vxmdzhb/astrx-suite
[framework]: https://github.com/Vxmdzhb/framework
[library]: https://github.com/Vxmdzhb/library
[cms]: https://github.com/Vxmdzhb/cms
[module-chat]: https://github.com/Vxmdzhb/module-chat
