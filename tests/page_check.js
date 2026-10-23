// Opens every page of a rendered report in jsdom and fails on a script error, an empty page, or a link whose
// scheme is not http(s) (notes are written from audited code: a javascript: link must never be rendered).
// Usage: node page_check.js <report.html> [--expect-link=<url>]...   (each url must be linked on some page)
const { JSDOM, VirtualConsole } = require('jsdom');
const fs = require('fs');

const file = process.argv.slice(2).find((a) => !a.startsWith('--'));
const expected = process.argv.slice(2).filter((a) => a.startsWith('--expect-link=')).map((a) => a.slice(14));
const seen = new Set();
if (!file) { console.error('Usage: node page_check.js <report.html>'); process.exit(2); }
const html = '<!doctype html><html><head></head><body>' + fs.readFileSync(file, 'utf8') + '</body></html>';

function load(hash) {
  const errors = [];
  const vc = new VirtualConsole();
  vc.on('jsdomError', (e) => errors.push((e.message || '').split('\n')[0]));
  vc.on('error', (e) => errors.push(String(e)));
  const dom = new JSDOM(html, { runScripts: 'dangerously', url: 'http://localhost/#' + hash, virtualConsole: vc, pretendToBeVisual: true });
  return { dom, errors };
}

const first = load('overview');
const hashes = ['overview', ...[...first.dom.window.document.querySelectorAll('#nav a')].map((a) => a.getAttribute('href').slice(1))];
// older scan versions are reachable as #<page>~<scan-id>; open every one that exists
const data = JSON.parse(first.dom.window.document.getElementById('audit-data').textContent);
const slug = (s) => String(s).toLowerCase().replace(/[^a-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '');
for (const e of data.entities || []) {
  if (e.hidden) continue;
  for (const v of e.versions.slice(0, -1)) hashes.push(`${slug(e.name)}~${v.scan_id}`);
}

let failed = 0;
for (const h of [...new Set(hashes)]) {
  const { dom, errors } = load(h);
  const pages = dom.window.document.querySelector('#pages');
  const text = pages ? pages.textContent.length : 0;
  if (errors.length || !pages || pages.children.length === 0 || text < 200) {
    failed++;
    console.error(`FAIL #${h}: ${errors[0] || 'page is empty (' + text + ' chars)'}`);
  }
  for (const a of dom.window.document.querySelectorAll('a[href]')) {
    const href = a.getAttribute('href');
    seen.add(href);
    if (!/^(https?:\/\/|#)/i.test(href)) {
      failed++;
      console.error(`FAIL #${h}: unsafe link href="${href.slice(0, 60)}"`);
    }
  }
}
for (const u of expected) {
  if (!seen.has(u)) { failed++; console.error(`FAIL: expected link not found: ${u}`); }
}
const total = new Set(hashes).size;
console.log(failed ? `pages: ${failed} of ${total} failed` : `pages: ok (${total} pages opened)`);
process.exit(failed ? 1 : 0);
