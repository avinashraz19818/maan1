/**
 * DMFirst recharge-gate patch runner.
 *
 *   npm i @php-wasm/node    (ek hi baar)
 *   SITEDIR=/home/user/maan1/dmfirst-recharge-fix/site PHPVER=7.4 node run.mjs
 *
 * /tmp reset ho jaye to /tmp/phpwasm me copy karke chalao:
 *   cp _tests/run.mjs /tmp/phpwasm/run-dmfirst.mjs && cd /tmp/phpwasm && PHPVER=7.4 node run-dmfirst.mjs
 */
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { readFileSync } from 'node:fs';

const ROOT = process.env.SITEDIR || '/home/user/maan1/dmfirst-recharge-fix/site';
const FILES = {
  '/site/devmaruf/api/webapi/GetAllowBetSetting.php': `${ROOT}/devmaruf/api/webapi/GetAllowBetSetting.php`,
  '/site/devmaruf/api/webapi/GetLoadedSetting.php': `${ROOT}/devmaruf/api/webapi/GetLoadedSetting.php`,
  '/site/dmfirst-recharge-check.php': `${ROOT}/dmfirst-recharge-check.php`,
};

const php = new PHP(await loadNodeRuntime(process.env.PHPVER || '8.3', { emscriptenOptions: { processId: 89 } }));
await php.mkdirTree('/site/devmaruf/api/webapi');
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
const test = readFileSync('/home/user/maan1/dmfirst-recharge-fix/_tests/test_recharge_gate.php', 'utf8');
await php.writeFile('/site/test.php', test);
const run = await php.run({ code: `<?php $_SERVER['REQUEST_METHOD']='GET'; require '/site/test.php';` });
const text = run.text;
console.log(text.replace(/\s+$/, ''));
if (/FAIL /.test(text)) { fail++; }

/* 3) checker smoke: galat key -> forbidden */
const forbidden = await php.run({
  code: `<?php $_GET['key']='nope'; $_SERVER['REQUEST_METHOD']='GET'; chdir('/site'); ob_start(); require '/site/dmfirst-recharge-check.php'; echo ob_get_clean();`,
});
const okForbidden = forbidden.text.includes('forbidden');
if (!okForbidden) { fail++; }
console.log(`${okForbidden ? 'PASS' : 'FAIL'}  checker: galat key -> forbidden`);

/* 4) checker smoke: sahi key par gate OFF detect + app ka faisla + verdict */
const good = await php.run({
  code: `<?php $_GET['key']='b7f3c1a94e2d5068'; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['HTTP_HOST']='localhost'; chdir('/site'); ob_start(); require '/site/dmfirst-recharge-check.php'; echo ob_get_clean();`,
});
const gtext = good.text;
const checks = [
  ['checker header', gtext.includes('DMFIRST RECHARGE-GATE CHECK')],
  ['checker gate OFF detect', /gate: OFF/.test(gtext)],
  ['checker junk branch gayab', /hata diya gaya/.test(gtext)],
  ['checker app faisla (0 recharge)', /user ka recharge ₹0 -> app ka faisla: open/.test(gtext)],
  ['checker app faisla (₹500)', /user ka recharge ₹500 -> app ka faisla: open/.test(gtext)],
  ['checker verdict', /Gate OFF hai/.test(gtext)],
  ['checker legacy note', /legacy betting files/i.test(gtext)],
  ['checker no PHP warning/notice', !/Warning|Notice|Fatal error|Deprecated/.test(gtext)],
];
for (const [name, ok] of checks) { if (!ok) { fail++; } console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}`); }
if (!checks.every(([, ok]) => ok)) {
  console.log('--- report ka sample ---');
  console.log(gtext.split('\n').slice(0, 30).join('\n'));
}

console.log(`\nTOTAL FAILURES: ${fail}`);
process.exit(fail ? 1 : 0);
