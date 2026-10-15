import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { readFileSync } from 'node:fs';

const ROOT = process.env.SITEDIR || '/home/user/maan1/raxiwin-instant-fix/site_v5';
const FILES = {
  '/site/raxiwin-build-check.php': `${ROOT}/raxiwin-build-check.php`,
  '/site/draw-live-v4/index.php': `${ROOT}/draw-live-v4/index.php`,
  '/site/saas_lottery/bootstrap_live_v4.php': `${ROOT}/saas_lottery/bootstrap_live_v4.php`,
  '/site/draw/index.php': `${ROOT}/draw/index.php`,
  '/site/draw-live-v3/index.php': `${ROOT}/draw-live-v3/index.php`,
  '/site/saas_lottery/bootstrap.php': `${ROOT}/saas_lottery/bootstrap.php`,
  '/site/saas_lottery/bootstrap_live_v3.php': `${ROOT}/saas_lottery/bootstrap_live_v3.php`,
};

const php = new PHP(await loadNodeRuntime(process.env.PHPVER || '8.3', { emscriptenOptions: { processId: 93 } }));
await php.mkdirTree('/site/draw-live-v4');
await php.mkdirTree('/site/saas_lottery');
await php.mkdirTree('/site/draw');
await php.mkdirTree('/site/draw-live-v3');
for (const [target, source] of Object.entries(FILES)) {
  await php.writeFile(target, readFileSync(source));
}

/* 1) syntax check every shipped file on this PHP version */
let ok = true;
for (const target of Object.keys(FILES)) {
  const src = await php.readFileAsText(target);
  const code = `<?php
    $src = file_get_contents(${JSON.stringify(target)});
    try { token_get_all($src, TOKEN_PARSE); echo 'OK'; }
    catch (Throwable $e) { echo 'SYNTAX ERROR: ' . $e->getMessage(); }`;
  const out = (await php.run({ code })).text.trim();
  if (out !== 'OK') { ok = false; }
  console.log(`${out === 'OK' ? 'PASS' : 'FAIL'}  syntax ${target} (${src.length} bytes) ${out === 'OK' ? '' : out}`);
}

/* 2) behaviour tests */
const test = readFileSync('/home/user/maan1/raxiwin-instant-fix/_tests/test_v5.php', 'utf8');
await php.writeFile('/site/test.php', test);
const run = await php.run({ code: `<?php require '/site/test.php';` });
console.log(run.text.replace(/\s+$/, ''));

const failed = run.text.includes('RESULT  pass=') === false || !/RESULT\s+pass=\d+ fail=0/.test(run.text);
process.exit(ok && !failed ? 0 : 1);
