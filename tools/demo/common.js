// Shared by fill.js and shots.js: where the site is, and logging in.
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = (process.env.BASE_URL || 'http://127.0.0.1:8082').replace(/\/$/, '');
const USER = process.env.DEMO_USER || 'admin';
const PASSWORD = process.env.DEMO_PASSWORD || 'correct horse battery';
const ROOT = path.resolve(__dirname, '../..');

// The login form has a captcha; its answer is read from the database with
// captcha.php (a demo database only).
async function login(page) {
  await page.goto(`${BASE}/en/login`);
  const id = await page.getAttribute('input[name=captcha_id]', 'value');
  const text = execFileSync('php', [path.join(__dirname, 'captcha.php'), id]).toString();
  await page.fill('#username', USER);
  await page.fill('#password', PASSWORD);
  await page.fill('input[name=captcha_text]', text);
  await Promise.all([page.waitForNavigation(), page.click('input[type=submit][value="Log in"]')]);
  if (page.url().includes('/login')) throw new Error(`could not log in as ${USER}`);
}

module.exports = { BASE, USER, ROOT, login };
