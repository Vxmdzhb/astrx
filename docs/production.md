# Running it in production

Everything here uses the Docker setup: the site, its database and the Rust
engines on one host, with Tor in front. Commands run from the `astrx`
checkout unless they say otherwise.

## The first launch

Clone both repositories side by side and fetch the PHP packages:

```sh
git clone https://github.com/Vxmdzhb/astrx.git
git clone https://github.com/Vxmdzhb/astrx-suite.git
cd astrx
composer install
cp .env.example .env
```

Fill in `.env`. Three values have no default and Compose refuses to start
without them:

- `DB_USER` and `DB_PASSWORD`: the account the site uses. Pick a long random
  password.
- `MARIADB_ROOT_PASSWORD_HASH`: the hash of a separate root password, not
  the password itself. To make it:

  ```sh
  printf '%s' 'the root password' | openssl sha1 -binary | openssl sha1 | awk '{print "*" toupper($NF)}'
  ```

Keep the root password somewhere safe; the site never needs it.

Then start the database and the site, and run the installer:

```sh
docker compose up -d --build --no-deps mariadb phpfpm nginx
docker compose run --rm --no-deps phpfpm php tools/install.php
docker compose run --rm --no-deps phpfpm php tools/warm-template-cache.php --clear
docker compose up -d --build
```

The installer asks for the database (host `mariadb`, and the name, user and
password from `.env`) and for the first admin account. The last command
starts everything else: the engines and Tor.

## Putting the site on Tor

The site answers on the host's `127.0.0.1:80` only, so nothing is exposed
until a Tor daemon on the host publishes it. In `/etc/tor/torrc`:

```
HiddenServiceDir /var/lib/tor/astrx/
HiddenServicePort 80 127.0.0.1:80
```

On NixOS:

```nix
services.tor = {
  enable = true;
  relay.onionServices.astrx.map = [
    { port = 80; target = { addr = "127.0.0.1"; port = 80; }; }
  ];
};
```

After restarting Tor, the address is in `hostname` in that directory
(`/var/lib/tor/onion/astrx/hostname` on NixOS). Back up the directory: its
keys *are* the address.

The engines get onion addresses of their own from the `tor` container:

```sh
docker compose exec tor sh -c 'cat /var/lib/tor/*/hostname'
```

## What survives what

All state lives in named volumes: the database (`mariadb-data`), the
site's configuration (`app-config`, which holds the installation secret),
uploads, avatars, catalogs, the engines' indexes and Tor's keys.
`docker volume ls --filter name=astrx` lists them.

| Command | Data |
|---|---|
| `docker compose down` then `up -d` | kept |
| `docker compose restart`, `up -d --build`, a reboot | kept |
| `docker compose down -v` | **deleted**, every volume |
| `docker volume rm …`, `docker system prune --volumes` | **deleted** |

So never pass `-v` to `down`, and never prune volumes on the server.

Older versions of `docker-compose.yml` kept the database inside the
container instead of in a volume, which is why `down` and `up` used to start
from an empty database.

## Backups

The database, from the running container:

```sh
docker compose exec -T mariadb sh -c \
  'exec mariadb-dump --single-transaction -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' \
  > backup-$(date +%F).sql
```

A volume (repeat for `app-config`, the uploads, and `tor-keys`):

```sh
docker run --rm -v astrx_app-config:/v:ro -v "$PWD":/b alpine \
  tar czf /b/app-config-$(date +%F).tgz -C /v .
```

The database is useless without `app-config`: encrypted fields, sessions
and two-factor secrets depend on the installation secret stored there.
Restore into a separate machine now and then to know the backups work.
[Operations](OPERATIONS.md) has more on the engines' indexes.

## Updating

```sh
git pull
composer install
docker compose up -d --build
docker compose exec --user www-data phpfpm php tools/install.php --schema-only
docker compose exec --user www-data phpfpm php tools/warm-template-cache.php --clear
```

`--schema-only` applies new migrations and nothing else. Take a backup
first.

## Adding a module

Nothing needs to be stopped or rebuilt: the containers read `src/` and
`vendor/` straight from the checkout, and PHP picks up changed files on the
next request.

1. Write it. `php tools/make_module.php forum --nav --guard` creates
   `../module-forum`; [Modules](MODULES.md#adding-a-module) explains each
   part. It's easiest to develop it outside Docker, with `nix develop` and
   `php -S`, and to plug it into the server once it works.
2. Install the package. Push the module to its own repository, then add that
   repository and `"astrx/module-forum": "*@dev"` to `composer.json`, and:

   ```sh
   composer update astrx/module-forum
   ```

   While it isn't pushed yet, a `path` repository works too, with
   `"options": { "symlink": false }`: the containers can only see files
   inside this checkout, so Composer must copy the module rather than link
   to it. Run `composer update astrx/module-forum` again after each change.
3. Register it: add `Forum\ForumModule::class` to
   `ApplicationModules::INSTALLED` in `src/AstrX/ApplicationModules.php`,
   then run `php tools/packages.php --write`. This and the Composer step run
   on the host (`nix develop` has PHP and Composer), because the containers
   mount the source read-only.
4. Create its tables and pages, switch it on, and refresh the templates:

   ```sh
   docker compose exec --user www-data phpfpm php tools/install.php --schema-only
   docker compose exec --user www-data phpfpm php tools/module.php enable forum
   docker compose exec --user www-data phpfpm php tools/warm-template-cache.php --clear
   ```

Run `tools/module.php` inside the container, not on the host: the live
configuration is in the `app-config` volume, and editing
`resources/config/` in the checkout changes nothing once the site is
installed.

`tools/module.php disable forum` takes a module off the site again and keeps
its data; `purge` deletes its tables and pages.
