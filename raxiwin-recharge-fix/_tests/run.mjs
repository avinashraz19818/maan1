/**
 * RaxiWin recharge-gate patch runner.
 *
 *   npm i @php-wasm/node    (ek hi baar)
 *   SITEDIR=/home/user/maan1/raxiwin-recharge-fix/site PHPVER=7.4 node run.mjs
 *
 * /tmp reset ho jaye to is file ko /tmp/phpwasm me copy karke chalao:
 *   cp _tests/run.mjs /tmp/phpwasm/run-recharge.mjs && cd /tmp/phpwasm && PHPVER=7.4 node run-recharge.mjs
 */
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { readFileSync } from 'node:fs';

const ROOT = process.env.SITEDIR || '/home/user/maan1/raxiwin-recharge-fix/site';
const FILES = {
  '/site/developer-maruf/api/webapi/GetAllowBetSetting.php': `${ROOT}/developer-maruf/api/webapi/GetAllowBetSetting.php`,
  '/site/developer-maruf/api/webapi/GetLoadedSetting.php': `${ROOT}/developer-maruf/api/webapi/GetLoadedSetting.php`,
  '/site/raxiwin-recharge-check.php': `${ROOT}/raxiwin-recharge-check.php`,
};

const php = new PHP(await loadNodeRuntime(process.env.PHPVER || '8.3', { emscriptenOptions: { processId: 91 } }));
await php.mkdirTree('/site/developer-maruf/api/webapi');
for (const [target, source] of Object.entries(FILES)) {
  await php.writeFile(target, readFileSync(source));
}

/* 1) syntax check every shipped file on this PHP version */
let fail = 0;
for (const target of Object.keys(FILES)) {
  const src = await php.readFileAsText(target);
  const code = `<?php
    $src = file_get_contents(${JSON.stringify(target)});
    try { token_get_all($src, TOKEN_PARSE); echo 'OK'; }
    catch (Throwable $e) { echo 'SYNTAX ERROR: ' . $e->getMessage(); }`;
  const out = (await php.run({ code })).text.trim();
  if (out !== 'OK') { fail++; }
  console.log(`${out === 'OK' ? 'PASS' : 'FAIL'}  syntax ${target} (${src.length} bytes) ${out === 'OK' ? '' : out}`);
}

/* 2) behaviour tests */
const test = readFileSync('/home/user/maan1/raxiwin-recharge-fix/_tests/test_recharge_gate.php', 'utf8');
await php.writeFile('/site/test.php', test);
const run = await php.run({ code: `<?php $_SERVER['REQUEST_METHOD']='GET'; require '/site/test.php';` });
const text = run.text;
console.log(text.replace(/\s+$/, ''));
if (/FAIL /.test(text)) { fail++; }

/* 3) checker page smoke (galat key -> forbidden) */
const forbidden = await php.run({
  code: `<?php $_GET['key']='nope'; $_SERVER['REQUEST_METHOD']='GET'; chdir('/site'); ob_start(); require '/site/raxiwin-recharge-check.php'; echo ob_get_clean();`,
});
const okForbidden = forbidden.text.includes('forbidden');
if (!okForbidden) { fail++; }
console.log(`${okForbidden ? 'PASS' : 'FAIL'}  checker: galat key -> forbidden`);

const good = await php.run({
  code: `<?php $_GET['key']='b7f3c1a94e2d5068'; $_SERVER['REQUEST_METHOD']='GET'; chdir('/site'); ob_start(); require '/site/raxiwin-recharge-check.php'; echo ob_get_clean();`,
});
const gtext = good.text;
const checks = [
  ['checker header', gtext.includes('RAXIWIN RECHARGE-GATE CHECK')],
  ['checker gate OFF detect', /allowNoRechargeGame = "1"\s+-> HA/.test(gtext)],
  ['checker app faisla', /game SEEDHA khulega/.test(gtext)],
  ['checker verdict', /Gate OFF hai/.test(gtext)],
  ['checker no PHP warning', !/Warning|Notice|Fatal error|Deprecated/.test(gtext)],
];
for (const [name, ok] of checks) { if (!ok) { fail++; } console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}`); }

console.log(`\nTOTAL FAILURES: ${fail}`);
process.exit(fail ? 1 : 0);
