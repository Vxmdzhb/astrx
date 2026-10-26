# How the project is organised

AstrX is a set of repositories under [github.com/Vxmdzhb](https://github.com/Vxmdzhb).
Each one can be used on its own, and this one puts them together.

## The repositories

| Repository | What it is |
|---|---|
| [astrx](https://github.com/Vxmdzhb/astrx) | This one: the website. It wires the packages together and holds the site's own pages, configuration, themes, installer, Docker setup and tests. |
| [framework](https://github.com/Vxmdzhb/framework) | `astrx/framework`: results and diagnostics, configuration, injection, HTTP and routing, templates, translation, sessions, the module contract. Runs an application on its own. |
| [library](https://github.com/Vxmdzhb/library) | `astrx/library`: database connection, permissions, API keys, captchas, images, identicons, mail. |
| [cms](https://github.com/Vxmdzhb/cms) | `astrx/cms`: accounts, admin panel, pages, navigation, themes, comments, news, invites, data retention. |
| `module-*` | `astrx/module-<key>`: one optional feature each (chat, imageboard, content, webmail, search, …). |
| [astrx-suite](https://github.com/Vxmdzhb/astrx-suite) | The Rust engines as one `astrx` binary, with their Docker deployment. |
| [crawlcore](https://github.com/Vxmdzhb/crawlcore), [gitweb](https://github.com/Vxmdzhb/gitweb), [onioncrawler](https://github.com/Vxmdzhb/onioncrawler), [suitedash](https://github.com/Vxmdzhb/suitedash), [torrentds](https://github.com/Vxmdzhb/torrentds), [websearch](https://github.com/Vxmdzhb/websearch) | The Rust crates: the shared crawl library and the five engines. |

Each repository keeps its full history, back through the time when they were
all one repository.

## Versions are commits

There are no version tags. Each consumer pins the exact commit of everything
it uses, in its lockfile:

- this site's `composer.lock` pins every PHP package;
- each engine's `Cargo.lock` pins crawlcore, and astrx-suite's pins every
  engine.

`composer update 'astrx/*'` or `cargo update` moves to the latest commit on
`main`; commit the lockfile change like any other.

## Working on several repositories at once

Clone the repositories you're changing next to each other.

**PHP.** Add a `path` repository to this site's `composer.json`, in front of
the `vcs` one, and run `composer update astrx/<name>`:

```json
{ "type": "path", "url": "../module-chat" }
```

Composer links your checkout into `vendor/`, so changes show up immediately.
Don't commit that entry.

**Rust.** Point Cargo at a local checkout with a `[patch]` section, for
example in astrx-suite's `.cargo/config.toml`:

```toml
[patch.'https://github.com/Vxmdzhb/gitweb']
gitweb = { path = "../gitweb" }
```

## Boundaries

The PHP code is layered (foundation → component → library → feature →
application) and every package declares exactly the packages its code uses;
`tools/check_architecture.php` and `tools/packages.php` check both in CI.
Optional modules reach the core only through declared extension points, and
the site runs with no module installed at all. See
[architecture](architecture.md) and [modules](MODULES.md).

The site talks to the Rust engines over HTTP only, so either side can be
upgraded or run without the other ([connecting the engines](suite-search-modules.md)).
