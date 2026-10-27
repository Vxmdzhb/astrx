#!/bin/sh
# Serves the demo data (from data.sh) on the ports the site's default engine
# settings expect. Stop with Ctrl-C.
#
#   sh tools/demo/engines.sh demo-data
set -eu
D=$(cd "${1:?usage: engines.sh DATADIR}" && pwd)
ASTRX=${ASTRX_BIN:-astrx}
GITWEB=${GITWEB_BIN:-$ASTRX gitweb}
TORRENTDS=${TORRENTDS_BIN:-$ASTRX torrentds}
trap 'kill 0' INT TERM EXIT
$GITWEB --root "$D/git" --port 8801 -q --clone-base-url http://git.example.onion &
"$ASTRX" onioncrawler search --db "$D/onion/crawl.db" --port 8802 &
"$ASTRX" websearch serve --db "$D/web.db" --port 8803 &
$TORRENTDS search --db "$D/torrentds.db" --port 8804 &
wait
