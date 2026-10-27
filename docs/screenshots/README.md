# Screenshots

All taken on a local install filled with made-up content: the people, posts,
mail and pictures are invented, and the keys are throwaway. Every module is
on, every page is seen by the same administrator ("Ash"), and every one uses
the default theme. [tools/demo](../../tools/demo/README.md) builds the same
site and retakes them all.

## The site

| | |
|---|---|
| ![Home page with news and comments](home.png) | ![The chat](chat.png) |
| Home: news and comments | Chat: messages, people online, moderation |
| ![A profile](profile.png) | ![A wiki page](wiki-page.png) |
| A profile | A wiki page, with its backlinks |
| ![The user area](user-home.png) | ![Account settings](settings.png) |
| The user area | Account settings |

![A thread on the imageboard](board.png)

A thread on the imageboard.

## Webmail

| | |
|---|---|
| ![The inbox](webmail-inbox.png) | ![Reading a message](webmail-message.png) |
| The inbox | A message, with its attachment |
| ![Remote images blocked](webmail-images-blocked.png) | ![Replying](webmail-reply.png) |
| A newsletter: its remote images are blocked until you trust the sender | Replying |

## Search

One search box, with a tab for the site and one per engine. The web, onion
and torrent tabs show the Rust engines from
[astrx-suite](https://github.com/Vxmdzhb/astrx-suite), whose indexes were
filled by crawling a few made-up sites and onion services, and with
public-domain and free-software torrents.

| | |
|---|---|
| ![This site](search-site.png) | ![The web](search-web.png) |
| This site: news, pages, comments and posts | The web |
| ![Onion services](search-onion.png) | ![Torrents](search-torrents.png) |
| Onion services | Torrents: the first files, size, age and links |
| ![A torrent](torrent-detail.png) | ![Engine status in the admin panel](admin-engines.png) |
| A torrent: its facts and all its files | The engines' status, in the admin panel |

## Code

The site's git browser, reading a [gitweb](https://github.com/Vxmdzhb/gitweb)
server that holds the project's own repositories.

| | |
|---|---|
| ![The repositories](git-repos.png) | ![A repository](git-tree.png) |
| The repositories | A repository: files, README, latest commit, `git clone` |
| ![A file](git-file.png) | ![A commit](git-commit.png) |
| A file | A commit and its diff |

![Admin, Git server](admin-git.png)

Admin → Git server: which server to read, and the address visitors clone from.

## Trust pages

| | |
|---|---|
| ![Warrant canary](trust-canary.png) | ![Signed downloads](trust-downloads.png) |
| A warrant canary, signed with the operators' key | A release manifest, its signature checked on the page |
| ![Onion mirrors](trust-mirrors.png) | ![Tip line](trust-tipline.png) |
| The signed list of official addresses | The anonymous tip line |

## The admin panel

| | |
|---|---|
| ![Admin dashboard](admin-dashboard.png) | ![User management](admin-users.png) |
| Dashboard | Users: edit, promote, force logout |
| ![Permission grants](admin-config-access.png) | ![Audit log](admin-audit-log.png) |
| Permissions per group | Audit log, with its hash-chain check |
| ![System configuration](admin-config-system.png) | ![Themes](admin-themes.png) |
| System configuration | Themes |
| ![Pages](admin-pages.png) | ![Navigation](admin-navbar.png) |
| Pages | Navigation menus |
| ![News](admin-news.png) | ![Comments](admin-comments.png) |
| News | Comment moderation |
| ![Chat configuration](admin-config-chat.png) | ![Captcha](admin-config-captcha.png) |
| Chat settings | Captcha, with a live preview |
| ![Ban list](admin-banlist.png) | ![Bot trap](admin-trap.png) |
| Ban list and penalty schedules | Bot trap |
| ![Data retention](admin-retention.png) | ![Panic lockdown](admin-panic.png) |
| Data retention | Panic lockdown |
| ![Languages](admin-language.png) | ![Invitations](admin-invites.png) |
| Languages and translations | Invitations |
| ![Blocklist](admin-blocklist.png) | ![Media library](admin-media.png) |
| The engines' blocklist | The media library |
