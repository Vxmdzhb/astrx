#!/bin/sh
# Builds everything the engines serve in the screenshots, into one folder:
#
#   OUT/web.db            the clear-web index (websearch), from crawling web/fakeweb.py
#   OUT/onion/crawl.db    the onion index (onioncrawler), from the same fake web
#   OUT/torrentds.db      the torrent index (torrents/, a tiny Rust program)
#   OUT/git/*.git         bare clones of the project's repositories (gitweb)
#   OUT/trust/            throwaway keys and signed texts for the trust pages
#
#   sudo ASTRX_BIN=/path/to/astrx sh tools/demo/data.sh demo-data   # root: /etc/hosts, port 80
#
# Needs: python3, php (with sodium), gpg, git, cargo, and the astrx engine
# binary (astrx-suite: cargo build --release --all-features -p astrx) on PATH,
# or ASTRX_BIN=/path/to/astrx.
set -eu
OUT=${1:?usage: data.sh OUTDIR}
mkdir -p "$OUT" && OUT=$(cd "$OUT" && pwd)
HERE=$(cd "$(dirname "$0")" && pwd)
ASTRX=${ASTRX_BIN:-astrx}
ORG=${ORG_URL:-https://github.com/Vxmdzhb}

# ── the fake web ─────────────────────────────────────────────────────────────
# fakeweb.py answers for a handful of made-up sites and onion services on port
# 80, picking the site by Host header. The clear-web crawler resolves names, so
# they go in /etc/hosts; the onion crawler is pointed at it with --direct-map.
for h in $(python3 "$HERE/web/fakeweb.py" hosts); do
  grep -q " $h\$" /etc/hosts || echo "127.0.0.1 $h" >> /etc/hosts
done
python3 "$HERE/web/fakeweb.py" 80 & WEB=$!
trap 'kill $WEB 2>/dev/null' EXIT
sleep 1

SEEDS=""; ALLOW=""
for h in $(python3 "$HERE/web/fakeweb.py" hosts); do SEEDS="$SEEDS http://$h/"; ALLOW="$ALLOW --allow-host $h"; done
# shellcheck disable=SC2086
"$ASTRX" websearch crawl $SEEDS $ALLOW --db "$OUT/web.db" --delay 0 --jitter 0 --max-pages 200

mkdir -p "$OUT/onion"
: > "$OUT/onion/blocklist_hosts.txt"; : > "$OUT/onion/blocklist_keywords.txt"; : > "$OUT/onion/blocklist_media.txt"
ONION_ARGS=""
for h in $(python3 "$HERE/web/fakeweb.py" onions); do ONION_ARGS="$ONION_ARGS --seed http://$h/ --direct-map $h=127.0.0.1:80"; done
# shellcheck disable=SC2086
"$ASTRX" onioncrawler crawl $ONION_ARGS --db "$OUT/onion/crawl.db" --fetcher direct --crawl-delay 0 \
  --blocklist-hosts "$OUT/onion/blocklist_hosts.txt" --blocklist-keywords "$OUT/onion/blocklist_keywords.txt" \
  --blocklist-media "$OUT/onion/blocklist_media.txt"

# ── torrents ─────────────────────────────────────────────────────────────────
cargo run --quiet --release --manifest-path "$HERE/torrents/Cargo.toml" -- "$OUT/torrentds.db"

# ── git repositories ─────────────────────────────────────────────────────────
mkdir -p "$OUT/git"
while read -r repo desc; do
  [ -d "$OUT/git/$repo.git" ] || git clone --quiet --bare "$ORG/$repo.git" "$OUT/git/$repo.git"
  echo "$desc" > "$OUT/git/$repo.git/description"
done <<'LIST'
astrx The website: forum, chat, wiki, webmail and search, without JavaScript
astrx-suite The Rust engines as one binary, with Docker and Tor
cms Accounts, admin panel, pages and themes
framework The PHP framework
crawlcore The crawl library the engines share
gitweb The git server and browser behind the Code page
onioncrawler Onion crawler and search
websearch Clearnet crawler and search engine
torrentds DHT torrent indexer, search and tracker
suitedash Status dashboard for the engines
LIST

# ── trust pages ──────────────────────────────────────────────────────────────
[ -d "$OUT/trust" ] || sh "$HERE/trust/make.sh" "$OUT/trust"
echo "demo data in $OUT"
