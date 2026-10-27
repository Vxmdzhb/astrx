"""A small made-up web for the crawlers to index: several clearnet sites and
onion services, all served from one process by Host header."""
import base64, hashlib, html, os, re, sys
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler


def onion(seed):
    pub = hashlib.sha256(seed.encode()).digest()
    ver = b'\x03'
    chk = hashlib.sha3_256(b'.onion checksum' + pub + ver).digest()[:2]
    return base64.b32encode(pub + chk + ver).decode().lower() + '.onion'


P = lambda *ps: list(ps)

SITES = {
    'selfhosting.example': ('Selfhosting Notes', 'Guides for running your own services at home.', {
        'mariadb-backups': ('Backing up MariaDB without downtime', P(
            'A backup you have never restored is a hope, not a backup. This guide shows how to take consistent MariaDB backups with mariadb-dump and the --single-transaction flag, so the site keeps serving visitors while the dump runs.',
            'For larger databases, mariabackup copies the data files directly and can be restored much faster. We compare both approaches, explain retention, and show a small cron job that keeps seven daily and four weekly copies.',
            'Finally, restore the backup into a scratch container every month. Automate it, check that the row counts match, and you will sleep better.')),
        'onion-service': ('Running a Tor onion service for your website', P(
            'An onion service makes your website reachable inside Tor without exposing your server address. You only need a Tor daemon and two lines in torrc: HiddenServiceDir and HiddenServicePort.',
            'Keep the web server bound to localhost, so the only way in is through Tor. Back up the hidden service directory: the keys inside it are your onion address, and losing them means losing the address.',
            'Add an Onion-Location header to the clearnet version of the site and Tor Browser will offer visitors the onion address automatically.')),
        'home-server': ('Choosing a quiet home server', P(
            'A second-hand mini PC with a low-power CPU, 16 GB of memory and an SSD runs a forum, a chat, mail and a search engine for a small community without breaking a sweat.',
            'Measure idle power draw before you buy: a machine that is on all year should sit under 10 watts. Fanless cases keep it silent in a living room.')),
        'docker-volumes': ('Docker volumes: what survives docker compose down', P(
            'Named volumes survive docker compose down and up, restarts and image rebuilds. Only docker compose down -v, docker volume rm or a volume prune delete them.',
            'If your database lives inside the container instead of a volume, every down and up starts from an empty database. Declare a named volume for /var/lib/mysql and never pass -v in production.')),
    }),
    'quietweb.example': ('The Quiet Web', 'Essays on building websites without JavaScript.', {
        'no-js-chat': ('A chat room without JavaScript', P(
            'Before WebSockets, chat rooms refreshed a frame every few seconds. That trick still works: an iframe with a meta refresh shows new messages, and a plain form posts yours. It works in Tor Browser on its safest setting.',
            'Server-rendered HTML is fast, accessible and private. There are no trackers to block because there is no script to run.')),
        'csp': ('A strict Content-Security-Policy in practice', P(
            'If your pages need no script at all, say so: default-src none, style-src self, img-src self, and form-action self. Every injected script tag is then dead on arrival.',
            'A strict policy is also documentation. Anyone reading the headers knows the site will never run code in their browser.')),
        'themes': ('Ten themes, zero JavaScript', P(
            'Let each visitor pick a theme with a normal form and remember it server-side. CSS variables make a new theme a few dozen lines: amber terminal, synthwave, sepia, newsprint.',
            'Respect prefers-reduced-motion and keep contrast high; a theme is an accessibility feature as much as a style.')),
    }),
    'rustnotes.example': ('Rust Notes', 'Short articles about systems programming in Rust.', {
        'bm25': ('Implementing BM25 ranking from scratch', P(
            'BM25 scores a document by how often the query terms appear in it, damped so that the tenth occurrence counts less than the first, and normalised by document length.',
            'Combine it with PageRank computed over the link graph and a small search engine starts to return surprisingly good results for a few thousand pages.')),
        'atomic-writes': ('Atomic file writes that survive a crash', P(
            'Write to a temporary file in the same directory, fsync it, rename it over the old file, then fsync the directory. A reader sees either the old snapshot or the new one, never half of each.',
            'Hold an advisory lock while you do it, so two writers cannot publish at the same time.')),
        'zero-deps': ('Shipping a crawler with zero dependencies', P(
            'A web crawler needs an HTTP client, an HTML tokenizer, a URL parser and a robots.txt parser. All of them fit in a few thousand lines of standard-library Rust.',
            'Fewer dependencies means a smaller supply chain to audit and builds that still work in ten years.')),
    }),
    'openmaps.example': ('Open Maps Community', 'People mapping their neighbourhoods together.', {
        'mapping-party': ('How to run a mapping party', P(
            'Pick a neighbourhood, print field papers, walk the streets in pairs and note every bench, shop and bike rack. Back at the cafe, everyone adds what they found.',
            'Newcomers learn faster in person than from any tutorial.')),
        'offline-maps': ('Offline maps for travelling', P(
            'Download a regional extract before you leave and your phone shows streets, footpaths and opening hours with no signal at all.')),
    }),
}

ONIONS = {
    onion('library'): ('Onion Library', 'A reading room of public-domain books, mirrored for Tor users.', {
        'catalog': ('Catalog: classics in the public domain', P(
            'Pride and Prejudice, Frankenstein, Moby-Dick and a thousand more, as EPUB and plain text. Every file is checked against its Project Gutenberg original.',
            'No account, no tracking, no JavaScript. Read in the browser or download.')),
        'reading-circle': ('Monthly reading circle', P(
            'This month we read Frankenstein. Join the discussion on the forum; the reading guide covers chapters one to twelve.')),
    }),
    onion('privacy-forum'): ('Privacy Forum', 'Discussion about digital privacy and self-defence.', {
        'threat-model': ('Start with a threat model', P(
            'Before choosing tools, ask who you are protecting yourself from, what they want and what it would cost them. A journalist and a student need different answers.',
            'Write it down. A threat model you can read back is one you can improve.')),
        'pgp-guide': ('A gentle guide to encrypted email', P(
            'Generate a key, publish the fingerprint where your contacts can verify it, and sign your messages. Encryption only helps if the other side checks who they are talking to.')),
        'tails': ('Using Tails for sensitive work', P(
            'Tails boots from a USB stick, sends everything through Tor and forgets everything when you shut it down. Use the persistent storage only for what you really need.')),
    }),
    onion('recipes'): ('Hidden Kitchen', 'Recipes from a small community of home cooks.', {
        'focaccia': ('Focaccia with rosemary and sea salt', P(
            'Mix flour, water, yeast and salt the evening before. In the morning, stretch the dough in an oiled tray, dimple it with your fingers, add rosemary and coarse salt, and bake at 230 degrees for 20 minutes.')),
        'risotto': ('Risotto alla milanese', P(
            'Toast the rice in butter, add white wine, then hot stock one ladle at a time. Saffron goes in near the end, parmesan and cold butter off the heat.')),
    }),
    onion('mirror-wiki'): ('Tor Wiki Mirror', 'A read-only mirror of a community wiki about running Tor.', {
        'relay': ('Running a relay at home', P(
            'A non-exit relay adds bandwidth to the Tor network without exposing you to exit traffic. Set RelayBandwidthRate to what your connection can spare and keep the machine updated.')),
        'bridges': ('Bridges and pluggable transports', P(
            'Where Tor is blocked, bridges help users connect. obfs4 and Snowflake disguise the traffic so it is harder to recognise and block.')),
    }),
}


def page(site_title, title, body, links):
    nav = ''.join(f'<li><a href="{h}">{html.escape(t)}</a></li>' for h, t in links)
    return (f'<!doctype html><html><head><meta charset="utf-8"><title>{html.escape(title if title == site_title else title + ' - ' + site_title)}</title>'
            f'<meta name="description" content="{html.escape(re.sub(r"<[^>]+>", "", body)[:150])}"></head>'
            f'<body><h1>{html.escape(title)}</h1>{body}<nav><ul>{nav}</ul></nav></body></html>').encode()


def render(host, path):
    sites = {**SITES, **ONIONS}
    host = host.split(':')[0]
    if host not in sites:
        return None
    st, tagline, arts = sites[host]
    other = [(f'http://{h}/', sites[h][0]) for h in sites if h != host and (h.endswith('.onion') == host.endswith('.onion'))]
    if path == '/robots.txt':
        return b'User-agent: *\nAllow: /\n'
    if path in ('/', '/index.html'):
        body = f'<p>{html.escape(tagline)}</p>' + ''.join(f'<h2><a href="/{k}">{html.escape(t)}</a></h2><p>{html.escape(ps[0][:160])}…</p>' for k, (t, ps) in arts.items())
        return page(st, st, body, other)
    k = path.strip('/')
    if k in arts:
        t, ps = arts[k]
        body = ''.join(f'<p>{html.escape(p)}</p>' for p in ps)
        links = [('/', 'Home')] + [(f'/{o}', arts[o][0]) for o in arts if o != k] + other[:2]
        return page(st, t, body, links)
    return None


class H(BaseHTTPRequestHandler):
    def do_GET(self):
        out = render(self.headers.get('Host', ''), self.path.split('?')[0])
        if out is None:
            self.send_response(404); self.end_headers(); return
        self.send_response(200)
        self.send_header('Content-Type', 'text/plain' if self.path.endswith('.txt') else 'text/html; charset=utf-8')
        self.send_header('Content-Length', str(len(out)))
        self.end_headers(); self.wfile.write(out)

    def log_message(self, *a):
        pass


if __name__ == '__main__':
    if sys.argv[1:] == ['hosts']:
        print('\n'.join(SITES)); sys.exit()
    if sys.argv[1:] == ['onions']:
        print('\n'.join(ONIONS)); sys.exit()
    ThreadingHTTPServer(('127.0.0.1', int(sys.argv[1])), H).serve_forever()
