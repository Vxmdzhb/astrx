// Takes every screenshot listed in shots.json, and saves the HTML the site
// sent for each page next to it, so a page can be looked at (or retaken by
// hand) later.
//
//   node tools/demo/shots.js ~/src                 # all of them
//   node tools/demo/shots.js ~/src home chat       # only these
//
// ~/src is the folder that holds the repository checkouts (astrx, cms,
// module-chat, ...): each PNG goes to the path its "to" names there. The HTML
// goes to ~/src/screenshots-html/<name>.html (outside every repository: it
// holds session tokens), or to HTML_DIR.
//
// Logs in as DEMO_USER (default admin), so every page has the same navigation.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { BASE, login } = require('./common');

const manifest = JSON.parse(fs.readFileSync(path.join(__dirname, 'shots.json'), 'utf8'));
const [outArg, ...only] = process.argv.slice(2);
if (!outArg) { console.error('usage: node shots.js OUTDIR [name ...]'); process.exit(2); }
const OUT = path.resolve(outArg);

(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: manifest.viewport, deviceScaleFactor: 1 });
  const p = await ctx.newPage();
  await login(p);
  const htmlDir = path.resolve(process.env.HTML_DIR || path.join(OUT, 'screenshots-html'));
  fs.mkdirSync(htmlDir, { recursive: true });
  for (const shot of manifest.shots) {
    if (only.length && !only.includes(shot.name)) continue;
    await p.setViewportSize({ width: manifest.viewport.width, height: shot.height || manifest.viewport.height });
    let resp = await p.goto(BASE + shot.path, { waitUntil: 'networkidle' });
    if (shot.click) {
      [resp] = await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click(shot.click)]);
    }
    await p.waitForTimeout(300);
    const first = path.join(OUT, shot.to[0]);
    fs.mkdirSync(path.dirname(first), { recursive: true });
    await p.screenshot({ path: first });
    for (const copy of shot.to.slice(1)) {
      fs.mkdirSync(path.dirname(path.join(OUT, copy)), { recursive: true });
      fs.copyFileSync(first, path.join(OUT, copy));
    }
    fs.writeFileSync(path.join(htmlDir, `${shot.name}.html`), await resp.text());
    console.log(resp.status(), shot.name, p.url().replace(BASE, ''));
  }
  await b.close();
})();
