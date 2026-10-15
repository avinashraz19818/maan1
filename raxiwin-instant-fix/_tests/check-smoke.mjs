/**
 * Smoke test for the shipped raxiwin-build-check.php (checker v2).
 *
 * Chalane ka tareeka:
 *   npm i @php-wasm/node  (ek hi baar)
 *   SITEDIR=/home/user/maan1/raxiwin-instant-fix/site_v6 PHPVER=7.4 node check-smoke.mjs
 *
 * /tmp reset ho jaye to is file ko /tmp/phpwasm me copy karke chalao.
 */
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { readFileSync } from 'node:fs';

const ROOT = process.env.SITEDIR || '/home/user/maan1/raxiwin-instant-fix/site_v6';
const php = new PHP(await loadNodeRuntime(process.env.PHPVER || '7.4', { emscriptenOptions: { processId: 97 } }));

for (const dir of ['saas_lottery', 'draw-live-v4', 'draw', 'draw-live-v3', 'config', 'developer-maruf']) {
  await php.mkdirTree('/site/' + dir);
}
for (const rel of [
  'saas_lottery/bootstrap.php', 'saas_lottery/bootstrap_live_v3.php', 'saas_lottery/bootstrap_live_v4.php',
  'draw/index.php', 'draw-live-v3/index.php', 'draw-live-v4/index.php', 'raxiwin-build-check.php',
]) {
  await php.writeFile('/site/' + rel, readFileSync(ROOT + '/' + rel));
}
await php.writeFile('/site/config/database.php', "<?php return ['host'=>'127.0.0.1','port'=>3306,'user'=>'x','password'=>'y','database'=>'z'];");
await php.writeFile('/site/saas_lottery/config_live_v4.php', "<?php return ['draw_base_url'=>'https://draw.ar-lottery01.example','force_remote_draw'=>true];");
await php.writeFile('/site/developer-maruf/conn.php', "<?php // stub");
await php.writeFile('/site/developer-maruf/functions2.php', "<?php function app_setting_bool($k,$d=true){return true;} function app_setting($k,$d=null){return $d;}");
await php.writeFile('/site/developer-maruf/app_core_live_v4.php', "<?php // stub");
await php.writeFile('/site/developer-maruf/vip_core.php', "<?php // stub");

const runChecker = async (query) => php.run({
  code: `<?php parse_str(${JSON.stringify(new URLSearchParams(query).toString())}, $_GET); ` +
    `$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['HTTP_HOST']='localhost'; ` +
    `chdir('/site'); ob_start(); require '/site/raxiwin-build-check.php'; echo ob_get_clean();`,
});

const bad = await runChecker({ key: 'nope' });
const good = await runChecker({ key: 'b7f3c1a94e2d5068', quick: '1' });
const text = good.text;

const checks = [
  ['galat key -> forbidden', bad.text.includes('forbidden')],
  ['header v2', text.includes('RAXIWIN BUILD CHECK v2')],
  ['file versions + hash', text.includes('=== FILE VERSIONS') && /v6 \(sweep \+ tolerant gate\)/.test(text)],
  ['yeshaan/sha256 print', /[0-9a-f]{16}/.test(text)],
  ['adapter detect', /adapter v5\+ \(self-contained shift\)/.test(text)],
  ['opcache section', text.includes('=== OPCACHE')],
  ['settings section', text.includes('=== SETTINGS (DATABASE)')],
  ['bets & settlement section', text.includes('=== BETS & SETTLEMENT (WINGO_30S)')],
  ['settle hint ya DB skip', /settle=1|database nahi mila|mysqli nahi/.test(text)],
  ['http self-probe section', text.includes('=== HTTP SELF-PROBE')],
  ['probe fail gracefully', /request fail|http=|koi cache/i.test(text)],
  ['verdict section', text.includes('=== VERDICT')],
  ['verdict notices v6 files', /v6|sweep|adapter/i.test(text.split('=== VERDICT')[1] || '')],
  ['no PHP warning/notice/fatal', !/Warning|Notice|Fatal error|Deprecated/.test(text)],
  ['UTC time line', /UTC\s+php/.test(text)],
];
let pass = 0, fail = 0;
for (const [name, ok] of checks) { ok ? pass++ : fail++; console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}`); }
console.log(`\nRESULT  pass=${pass} fail=${fail}`);
console.log('--- report ka pehla hissa ---');
console.log(text.split('\n').slice(0, 14).join('\n'));
process.exit(fail ? 1 : 0);
