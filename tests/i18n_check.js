// Duplicate keys in the report's I18N dictionaries: JavaScript keeps the last one silently, which once replaced
// the "How this audit was made" list with a column label and broke the overview page.
// Usage: node tests/i18n_check.js assets/report-template.html
const src = require('fs').readFileSync(process.argv[2], 'utf8');
const start = src.indexOf('const I18N = {');
if (start < 0) { console.error('I18N not found'); process.exit(1); }
let i = src.indexOf('{', start), depth = 0, lang = null, keys = {}, bad = [];
for (; i < src.length; i++) {
  const c = src[i];
  if (c === "'" || c === '"' || c === '`') { // skip strings and template literals (with ${} nesting)
    const q = c; i++;
    while (i < src.length && src[i] !== q) {
      if (src[i] === '\\') i++;
      else if (q === '`' && src[i] === '$' && src[i + 1] === '{') { let d = 1; i += 2; while (d && i < src.length) { if (src[i] === '{') d++; else if (src[i] === '}') d--; i++; } i--; }
      i++;
    }
    continue;
  }
  if ('{[('.includes(c)) depth++;
  else if ('}])'.includes(c)) { depth--; if (depth === 0) break; }
  else if ((depth === 1 || depth === 2) && /[A-Za-z_$]/.test(c) && !/[\w$]/.test(src[i - 1])) {
    const m = /^([A-Za-z_$][\w$]*)\s*:/.exec(src.slice(i));
    if (m) {
      if (depth === 1) { lang = m[1]; keys[lang] = new Set(); }
      else if (keys[lang].has(m[1])) bad.push(`${lang}.${m[1]}`);
      else keys[lang].add(m[1]);
      i += m[1].length - 1;
    }
  }
}
const langs = Object.keys(keys);
for (const k of keys[langs[0]] || []) for (const l of langs.slice(1)) if (!keys[l].has(k)) bad.push(`${l}.${k} missing`);
for (const l of langs.slice(1)) for (const k of keys[l]) if (!keys[langs[0]].has(k)) bad.push(`${langs[0]}.${k} missing`);
if (bad.length) { console.error('I18N problems: ' + bad.join(', ')); process.exit(1); }
console.log(`i18n: ok (${langs.join(', ')}, ${keys[langs[0]].size} keys)`);
