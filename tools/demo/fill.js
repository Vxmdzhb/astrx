// Fills what the seed cannot: the trust pages, the wiki, the media library and
// the git server setting, all through the admin forms (so they are saved the
// way an operator would save them).
//
//   node tools/demo/fill.js demo-data/trust
//
// Logs in as DEMO_USER (default admin) with DEMO_PASSWORD; see common.js.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { BASE, ROOT, login } = require('./common');

const TRUST = path.resolve(process.argv[2] || 'demo-data/trust');
const read = f => fs.readFileSync(path.join(TRUST, f), 'utf8');

// Fills and submits the form that holds `marker` (a selector inside it).
async function submit(p, url, marker, fields, files) {
  await p.goto(`${BASE}/en/${url}`);
  const form = p.locator(`form:has(${marker})`).first();
  for (const [name, value] of Object.entries(fields)) {
    const el = form.locator(`[name="${name}"]`);
    if ((await el.getAttribute('type')) === 'checkbox') { if (value) await el.check(); else await el.uncheck(); }
    else await el.fill(value);
  }
  for (const [name, file] of Object.entries(files || {})) await form.locator(`[name="${name}"]`).setInputFiles(file);
  await Promise.all([p.waitForNavigation(), form.locator('[type=submit]').first().click()]);
  const said = await p.locator('.flash-success, .flash-error, .diag-error').allInnerTexts();
  console.log(url, said.join(' | ') || '(no message)');
}
const action = a => `input[name=action][value="${a}"]`;

const PAGES = [
  ['about', 'About this community', `This is a small community for people who like the web **quiet**: no trackers, no scripts, no ads.

We run everything ourselves, on one machine, reachable over Tor. Start with the [[rules]], then say hello in the chat.

- [[tor-guide]]: how to reach us safely
- [[search-engines]]: the search engines we run
- [[rules]]: what we ask of everyone`],
  ['rules', 'Community rules', `1. Be kind. Disagree with ideas, not people.
2. No personal information about anyone, including yourself.
3. Stay on topic in each board; off-topic goes to /b/.
4. No spam, no advertising, no affiliate links.
5. Moderators explain their decisions in the [[about]] thread.

Breaking a rule earns a warning first. See the ban list for how long each penalty lasts.`],
  ['tor-guide', 'Reaching us over Tor', `Download **Tor Browser** from the official site, open it, and paste our onion address.
Check the address against the signed list on the Mirrors page: anyone can make a look-alike.

Our site needs no JavaScript, so it works on the **Safest** security level. Set it in the shield menu.

Lost? Ask in the chat, or read [[about]].`],
  ['search-engines', 'Our search engines', `We crawl and index everything ourselves; no query ever leaves this server.

- **Clear web**: our own crawler, BM25 ranking plus PageRank.
- **Onion services**: crawled through Tor, with abuse blocklists.
- **Torrents**: metadata from the DHT, magnet links only.

Try them all from the *Search* page: one box, a tab per engine. See also [[tor-guide]].`],
];

(async () => {
  const b = await chromium.launch();
  const p = await (await b.newContext()).newPage();
  await login(p);
  await submit(p, 'admin-canary', action('save'), { statement: read('canary.asc'), interval_days: '14', enabled: true });
  await submit(p, 'admin-downloads', action('save'), { manifest: read('manifest.txt'), pubkey: read('manifest.pub'), sig: read('manifest.sig') });
  await submit(p, 'admin-mirrors', action('save'), { primary: read('primary.txt').trim(), mirror_list: read('mirror_list.txt'), signed: read('mirrors.asc') });
  await submit(p, 'admin-tipline', action('save_pubkey'), { pubkey: read('tipline.pub').trim() });
  for (const [slug, title, body] of PAGES) await submit(p, 'admin-content', action('save'), { slug, title, body: `# ${title}\n\n${body}` });
  // The media library gets three of the board pictures seed.php drew (the
  // largest files: the full pictures, not their thumbnails).
  const up = process.env.BOARD_UPLOADS || path.join(ROOT, 'resources/board_uploads');
  const bySize = fs.readdirSync(up).filter(f => f.endsWith('.jpg')).sort((a, b) => fs.statSync(path.join(up, b)).size - fs.statSync(path.join(up, a)).size);
  for (const f of bySize.slice(0, 3)) {
    await submit(p, 'admin-media', action('upload'), {}, { file: path.join(up, f) });
  }
  await submit(p, 'admin-config-git', 'input[name=api_url]', {
    api_url: process.env.GITWEB_URL || 'http://127.0.0.1:8801',
    public_url: process.env.GIT_PUBLIC_URL || 'http://git.example.onion',
  });
  await b.close();
})();
