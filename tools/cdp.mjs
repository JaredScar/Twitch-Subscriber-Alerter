// Minimal Chrome DevTools Protocol driver (dev/testing only, no dependencies).
//   node tools/cdp.mjs <url> <script-file.js> [timeoutMs]
// Opens the URL in headless Chrome, then evaluates the script (an async function body)
// and prints its JSON result. Used to test the alerts in real time.
import { spawn } from 'node:child_process';
import { readFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [url, scriptFile, timeoutArg] = process.argv.slice(2);
const timeout = Number(timeoutArg || 60000);
const chromePath = process.env.CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const port = 9300 + Math.floor(Math.random() * 500);
const profile = mkdtempSync(join(tmpdir(), 'cdp-'));

const chrome = spawn(chromePath, [
  '--headless=new', '--disable-gpu', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`,
  '--window-size=1920,1080', '--allow-file-access-from-files', '--autoplay-policy=no-user-gesture-required',
  '--hide-scrollbars',
  ...(process.env.CHROME_FLAGS ? process.env.CHROME_FLAGS.split(' ') : []),   // e.g. --ignore-certificate-errors for https://localhost
  'about:blank',
], { stdio: 'ignore' });

const sleep = ms => new Promise(r => setTimeout(r, ms));
async function getTarget() {
  for (let i = 0; i < 100; i++) {
    try {
      const list = await (await fetch(`http://127.0.0.1:${port}/json`)).json();
      const page = list.find(t => t.type === 'page');
      if (page) return page;
    } catch { /* not up yet */ }
    await sleep(100);
  }
  throw new Error('Chrome did not start');
}

let exitCode = 0;
try {
  const target = await getTarget();
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
  let id = 0; const pending = new Map();
  ws.onmessage = ev => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
  };
  const send = (method, params = {}) => new Promise(res => { const i = ++id; pending.set(i, res); ws.send(JSON.stringify({ id: i, method, params })); });

  await send('Page.enable');
  await send('Runtime.enable');
  const cookie = process.env.COOKIE || (process.env.COOKIE_FILE ? readFileSync(process.env.COOKIE_FILE, 'utf8').trim() : '');
  if (cookie) {                        // COOKIE=name=value (or COOKIE_FILE=path) -> pre-set a (session) cookie for the URL's host
    const [name, ...v] = cookie.split('=');
    await send('Network.setCookie', { name, value: v.join('='), url, httpOnly: true });
  }
  await send('Page.navigate', { url });
  await sleep(1500);
  const src = readFileSync(scriptFile, 'utf8');
  const r = await Promise.race([
    send('Runtime.evaluate', { expression: `(async () => { ${src} })()`, awaitPromise: true, returnByValue: true }),
    sleep(timeout).then(() => ({ result: { exceptionDetails: { text: 'timeout' } } })),
  ]);
  if (r.result && r.result.exceptionDetails) { console.error('ERROR', JSON.stringify(r.result.exceptionDetails)); exitCode = 1; }
  else console.log(JSON.stringify(r.result.result.value, null, 1));
  if (process.env.SHOT) {               // SHOT=out.png -> screenshot of the full page after the script ran
    const m = await send('Page.getLayoutMetrics');
    const h = Math.min(4000, Math.ceil(m.result.cssContentSize.height));
    await send('Emulation.setDeviceMetricsOverride', { width: 1280, height: h, deviceScaleFactor: 1, mobile: false });
    const shot = await send('Page.captureScreenshot', { format: 'png' });
    (await import('node:fs')).writeFileSync(process.env.SHOT, Buffer.from(shot.result.data, 'base64'));
  }
  ws.close();
} catch (e) {
  console.error(e); exitCode = 1;
} finally {
  chrome.kill();
}
process.exit(exitCode);
