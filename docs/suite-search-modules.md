# Connecting the Rust engines

Seven modules turn the [astrx-suite](https://github.com/Vxmdzhb/astrx-suite)
engines into ordinary pages of the site. Each is a small PHP client for an
engine's HTTP API: the engine does the crawling and indexing, and the site
makes a short HTTP request, cleans every field and renders the result with its
own templates. None of them adds database tables of its own.

| Module          | What it adds                                 | Talks to                         | Default address          |
|-----------------|----------------------------------------------|----------------------------------|--------------------------|
| `websearch`     | clear-web search page                        | websearch                        | `http://127.0.0.1:8803`  |
| `onionsearch`   | onion search page                            | onioncrawler                     | `http://127.0.0.1:8802`  |
| `torrentsearch` | torrent search page, with magnet links       | torrentds                        | `http://127.0.0.1:8804`  |
| `fedsearch`     | one search box with a tab per source         | the three above, plus site search | per source              |
| `gitbrowse`     | a link-through page to the git browser       | gitweb                           | `http://127.0.0.1:8801`  |
| `suiteadmin`    | admin panel: engine status, add onion seeds  | every engine's health/metrics    | per engine               |
| `blocklist`     | admin page: push blocklist entries           | onioncrawler, torrentds          | per engine               |

The site's own search (module `search`) is separate and needs no engine.

Each page is its own module, so you can switch any of them off (or purge it)
without touching the others: `php tools/module.php disable websearch`. A page
whose engine is down shows a friendly "unavailable" message; it never breaks
the site.

The installer creates each page and its navbar link. *Search All* (fedsearch)
and *Git* (gitbrowse) appear in the public navbar, *Engines* and *Blocklist*
in the admin panel. The three single-engine pages get a link too, switched
off, because *Search All* already has a tab for each; switch them on in
Admin → Navbar to list them on their own. On a site installed before these
pages existed, `php tools/install.php --schema-only` adds them.

[Screenshots](screenshots/README.md#the-search-engines) show the pages with
the engines running.

## Running the engines

**With Docker**, clone astrx-suite next to this repository and bring
everything up together:

```sh
git clone https://github.com/Vxmdzhb/astrx-suite.git ../astrx-suite
docker compose up -d
```

`docker-compose.yml` includes the suite's compose file. The engines run in a
container called `serve` that answers on the names `gitweb`, `onioncrawler-search`,
`websearch-serve`, `torrentds-search` and `suitedash`, on an internal network
with no route to the internet. `phpfpm` joins that network, so in the module
configs below use those names instead of `127.0.0.1`:

| Engine       | Address from phpfpm              |
|--------------|----------------------------------|
| gitweb       | `http://gitweb:8801`             |
| onioncrawler | `http://onioncrawler-search:8802` |
| websearch    | `http://websearch-serve:8803`    |
| torrentds    | `http://torrentds-search:8804`   |

The crawlers themselves (filling the indexes) are described in the
astrx-suite README.

**Without Docker**, build the single `astrx` binary from astrx-suite and run
the engines on the same host; the default `127.0.0.1` addresses then work as
they are:

```sh
astrx run "gitweb=gitweb --root /srv/git --port 8801" \
          "onion=onioncrawler search --db /var/lib/astrx/onion.db --port 8802" \
          "web=websearch serve --db /var/lib/astrx/web.db --port 8803" \
          "torrent=torrentds search --db /var/lib/astrx/torrentds.db --port 8804"
```

## Configuration

Every module ships its defaults in its own `resources/config/`; override any
key in the site's `resources/config/` file of the same name, or from the
admin pages.

| File                          | Keys |
|-------------------------------|------|
| `WebSearch.config.php`        | `base_url`, `timeout_seconds` (1–5), `per_page` (1–50) |
| `OnionSearch.config.php`      | `base_url`, `timeout_seconds` (1–5), `per_page` (1–50) |
| `TorrentSearch.config.php`    | `base_url`, `timeout_seconds` (1–5), `per_page` (1–100) |
| `FederatedSearch.config.php`  | `websearch_base_url`, `onioncrawler_base_url`, `torrentds_base_url`, `timeout_seconds`, `per_page` |
| `GitBrowse.config.php`        | `service_url`: where visitors are sent, so it must be an address *they* can reach (an onion address, or a public URL) |
| `SuiteAdmin.config.php`       | one `*_base_url` per engine, `timeout_seconds` |
| `Blocklist.config.php`        | `onioncrawler_base_url`, `onioncrawler_admin_token`, `torrentds_base_url`, `torrentds_admin_token`, `timeout_seconds` |

The admin tokens are secrets: they must match the `--admin-token` the engines
were started with, and with an empty token the blocklist push for that engine
is off. They're never shown on a page or written to a log.

Only `http` and `https` addresses are accepted; anything else falls back to
the default. The site only ever adds fixed paths to these addresses, so a
visitor can't make it request anything else.

## How engine results are kept safe

Everything an engine returns came from the open internet, a hidden service
or the DHT, so it's treated as hostile:

- every text field is decoded, stripped of tags and has its whitespace
  collapsed before it leaves the client class, so no markup reaches a
  template;
- templates print every field with `{{ }}`, which escapes it again;
- result links are reduced to `http`/`https` (anything else becomes `#`);
- magnet and `.torrent` links are rebuilt from a validated infohash instead
  of being copied from the engine's answer;
- requests have a short timeout and a size cap, never follow redirects, and
  a failure turns into a message on the page rather than an error.

The tests in `tests/suite-bridge/` run each client against a mock server
that returns exactly this kind of hostile data.
