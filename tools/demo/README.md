# Demo site and screenshots

Everything used to make the screenshots in the READMEs: the made-up content,
the engines' indexes, the mail, the signed trust pages, and the list of
pages that were photographed. Following these steps on a fresh install gives
the same pictures (dates, keys and onion addresses aside).

Nothing here is meant for a real site: the passwords are public, the IMAP
server accepts plain-text logins, and the keys are throwaway.

## What you need

- the site, installed from a checkout (PHP 8.4 with gd, sodium, intl, pdo_mysql
  and imap; MariaDB)
- dovecot, python3, gpg, git, and Node.js with Playwright
  (`npm i -g playwright && npx playwright install chromium`)
- the engines, as the one `astrx` binary from astrx-suite, and cargo itself
  for the torrent seeder:

  ```sh
  git clone https://github.com/Vxmdzhb/astrx-suite
  (cd astrx-suite && cargo build --release --locked --all-features -p astrx)
  export ASTRX_BIN=$PWD/astrx-suite/target/release/astrx
  ```
- root, once, for `data.sh` (it adds the fake sites to `/etc/hosts` and serves
  them on port 80)

Paths below are relative to the site checkout; `demo-data/` can be anywhere.

## 1. Install the site and fill it

```sh
php tools/install.php --no-input --create-db \
    --db-host=127.0.0.1 --db-name=content_manager --db-user=astrx --db-pass=astrxpass \
    --admin-user=admin --admin-pass='correct horse battery' --admin-mailbox=admin \
    --environment=development
php tools/demo/seed.php
```

`seed.php` adds six people (nova, kestrel, mirelle, otto, juniper; saffi is a
moderator; all with the password `demo password 123`), names the admin "Ash",
and writes news, comments, three boards with threads and generated pictures,
a chat, and an audit log whose hash chain checks out.

## 2. Mail

```sh
mkdir -p demo-data/mail/boxes && chown vmail:vmail demo-data/mail/boxes
sed "s#@MAIL_ROOT@#$PWD/demo-data/mail#g" tools/demo/mail/dovecot.conf > demo-data/mail/dovecot.conf
cp tools/demo/mail/users demo-data/mail/users
dovecot -c demo-data/mail/dovecot.conf
python3 tools/demo/mail/seed_mail.py
```

dovecot listens on 127.0.0.1:1143 and knows two accounts (`mail/users`); the
seeder fills the admin's mailbox: twelve messages in the inbox (one with an
attachment, one newsletter with remote images and a script), three sent, one
draft. The vmail user is dovecot's usual mail user; use any unprivileged one.

## 3. The engines' data

```sh
sudo ASTRX_BIN="$ASTRX_BIN" sh tools/demo/data.sh demo-data
```

It serves `web/fakeweb.py` (a few made-up sites and onion services, by Host
header) on port 80, crawls it with `astrx websearch crawl` and `astrx
onioncrawler crawl --fetcher direct`, writes a torrent index with
`torrents/` (24 public-domain and free-software torrents; names and sizes are
invented), bare-clones the project's repositories for the git browser, and
makes the trust material with `trust/make.sh`. It runs `$ASTRX_BIN` (or
`astrx` from PATH); keep the variable through `sudo` with `sudo -E`, or pass
it on the command line.

## 4. Run everything

Three terminals:

```sh
sh tools/demo/engines.sh demo-data        # gitweb 8801, onion 8802, web 8803, torrents 8804

IMAP_HOST=127.0.0.1 IMAP_PORT=1143 IMAP_ENCRYPTION=none MAIL_DOMAIN=example.onion MAIL_LOCAL=true \
    php -S 127.0.0.1:8082 -t public public/index.php

node tools/demo/fill.js demo-data/trust   # once the site is up
```

The engines listen where the site's defaults expect them, so no engine
setting needs changing. `fill.js` logs in as the admin and fills, through the
admin forms, what the seed cannot: the warrant canary, the signed downloads
manifest, the mirror list, the tip-line key, four wiki pages, three pictures
in the media library, and Admin → Git server (gitweb at
`http://127.0.0.1:8801`, cloned from `http://git.example.onion`).

Then rebuild the site search, so it finds the wiki pages too:

```sh
php tools/search_index.php
```

Every module is on (`resources/config/Modules.config.php` as shipped), and
the theme is the default one.

## 5. The screenshots

```sh
php tools/demo/seed.php --refresh          # puts the chat's people back online
node tools/demo/shots.js ~/src             # ~/src holds astrx, cms, module-chat, ...
node tools/demo/shots.js ~/src chat board  # or just some of them
```

`shots.json` lists every picture: the page, and the files it is saved to.
Each repository's `docs/screenshot.png` is a copy of one of the gallery
pictures in `astrx/docs/screenshots`. All of them are taken logged in as the
admin, in a 1280×860 window (the board thread uses a taller one), so every
page shows the same navigation. The HTML the site sent for each page is saved
to `~/src/screenshots-html/` (outside the repositories: it carries session
tokens).

To log in as someone else, set `DEMO_USER` and `DEMO_PASSWORD`; for a site
on another address, `BASE_URL`.

## Files

| File | What it does |
|---|---|
| `seed.php` | people, news, comments, boards, chat, audit log |
| `captcha.php` | reads a login captcha's answer from the database, for the scripts |
| `mail/dovecot.conf`, `mail/users`, `mail/seed_mail.py` | the IMAP server and its mail |
| `web/fakeweb.py` | the made-up web the crawlers index |
| `torrents/` | writes the torrent index |
| `trust/make.sh` | throwaway OpenPGP and Ed25519 keys, the signed canary and mirror list, the manifest, the tip-line key |
| `data.sh` | runs the crawls, the torrent seeder, the git clones and `trust/make.sh` |
| `engines.sh` | serves the indexes and the repositories |
| `common.js` | the site address and the login, shared by the two scripts below |
| `fill.js` | fills the admin forms |
| `shots.json`, `shots.js` | the screenshot list, and the script that takes it |
