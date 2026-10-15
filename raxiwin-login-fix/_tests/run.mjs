import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { readFileSync } from 'node:fs';

const ROOT = '/home/user/maan1/raxiwin-login-fix';
const LOGIN = readFileSync(`${ROOT}/developer-maruf/api/webapi/Login.php`, 'utf8');
const STUB = readFileSync(`${ROOT}/_tests/stub_app_api.php`, 'utf8');
const RUNNER = readFileSync(`${ROOT}/_tests/runner.php`, 'utf8');
const DOCTOR = readFileSync(`${ROOT}/raxiwin-login-doctor.php`, 'utf8');

const FULL_COLUMNS = {
  id: 'int(11)', mobile: 'varchar(20)', email: 'varchar(190)', password: 'varchar(255)', pwd: 'varchar(255)',
  status: 'tinyint(4)', ishonup: 'varchar(64)', codechorkamukala: 'varchar(190)', shonupwderr: 'int(11)',
  shonullgnt: 'datetime', akshinak: 'varchar(512)', tnegaresunohs: 'varchar(255)',
  createdate: 'datetime', code: 'varchar(32)', code1: 'varchar(32)', code2: 'varchar(32)', code3: 'varchar(32)',
  code4: 'varchar(32)', code5: 'varchar(32)', owncode: 'varchar(32)', privacy: 'varchar(10)',
};

const scenarios = [
  { name: 'happy_bcrypt', columns: FULL_COLUMNS },
  { name: 'md5_legacy', columns: FULL_COLUMNS, passwordFormat: 'md5' },
  { name: 'missing_columns', columns: { id: 'int(11)', mobile: 'varchar(20)', password: 'varchar(255)', status: 'tinyint(4)' } },
  { name: 'narrow_token', columns: { ...FULL_COLUMNS, akshinak: 'varchar(255)' } },
  { name: 'select_throws', columns: FULL_COLUMNS, failOn: { 'FROM `shonu_subjects` WHERE': "Table 'club532583_x.shonu_subjects' doesn't exist" } },
  { name: 'user_missing', columns: FULL_COLUMNS, userExists: false },
  { name: 'wrong_password', columns: FULL_COLUMNS, passwordFormat: 'md5', body: { language: 'en', logintype: 'mobile', phonetype: '+91', pwd: 'wrongpass', random: 'abc1234567', signature: 'X', timestamp: '1', username: '9876543210' } },
  { name: 'suspended', columns: FULL_COLUMNS, userStatus: 0 },
  { name: 'no_user_table', columns: {} },
  { name: 'sha1_legacy', columns: FULL_COLUMNS, passwordFormat: 'sha1' },
  { name: 'plain_legacy', columns: FULL_COLUMNS, passwordFormat: 'plain' },
  { name: 'numeric_types', columns: { ...FULL_COLUMNS, ishonup: 'int(11)', shonullgnt: 'int(11)', tnegaresunohs: 'int(11)' } },
  { name: 'login_disabled', columns: FULL_COLUMNS, settings: { timezone: 'Asia/Kolkata', login_enabled: '0' } },
];

let pass = 0;
let fail = 0;
const check = (name, condition, detail = '') => {
  if (condition) { pass++; console.log(`PASS  ${name}`); }
  else { fail++; console.log(`FAIL  ${name}  ${detail}`); }
};
const tokensInUpdates = (statements) => statements.filter((s) => /^\s*UPDATE/i.test(s));

const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 91 } }));
await php.mkdirTree('/site/developer-maruf/api/webapi');
await php.mkdirTree('/site/config');
await php.writeFile('/site/developer-maruf/api/webapi/_app_api.php', STUB);
await php.writeFile('/site/developer-maruf/api/webapi/Login.php', LOGIN);
await php.writeFile('/site/runner.php', RUNNER);
await php.writeFile('/site/raxiwin-login-doctor.php', DOCTOR);
await php.writeFile('/site/config/database.php', `<?php return ['host'=>'127.0.0.1','port'=>3306,'user'=>'x','password'=>'y','database'=>'z','charset'=>'utf8mb4'];`);
await php.writeFile('/site/developer-maruf/functions2.php', `<?php function generate_jwt($h,$p,$s=null){ return 'hdr.' . rtrim(strtr(base64_encode(json_encode($p)),'+/','-_'),'=') . '.sig'; }`);

const run = async (code) => (await php.run({ code })).text;

for (const scenario of scenarios) {
  await php.writeFile('/site/scenario.json', JSON.stringify(scenario));
  const raw = await run(`<?php require '/site/runner.php';`);
  let out;
  try { out = JSON.parse(raw); } catch (e) { check(`${scenario.name}: runner output`, false, raw.slice(0, 300)); continue; }

  if (out.thrown) { check(`${scenario.name}: no uncaught error`, false, out.thrown); continue; }
  const res = out.response || {};
  const statements = out.statements || [];
  const updates = tokensInUpdates(statements);
  const logText = (out.errorLog || []).join('\n');
  const pre = `${scenario.name}:`;

  switch (scenario.name) {
    case 'happy_bcrypt': {
      check(`${pre} login succeeds`, res.code === 0 && res.http === 200, JSON.stringify(res));
      check(`${pre} token returned`, typeof res.data?.token === 'string' && res.data.token.length > 20);
      check(`${pre} refresh token returned`, typeof res.data?.refreshToken === 'string' && res.data.refreshToken.length > 20);
      check(`${pre} no schema repair needed`, (out.alters || []).length === 0, JSON.stringify(out.alters));
      check(`${pre} session token stored in akshinak`, updates.some((s) => /`akshinak`=/.test(s)), JSON.stringify(updates));
      check(`${pre} password not rewritten (already modern)`, !updates.some((s) => /`password`=/.test(s)), JSON.stringify(updates));
      break;
    }
    case 'md5_legacy': {
      check(`${pre} login succeeds with legacy md5`, res.code === 0, JSON.stringify(res));
      check(`${pre} hash upgraded to bcrypt`, updates.some((s) => /`password`='\$2y\$/.test(s)), JSON.stringify(updates));
      break;
    }
    case 'missing_columns': {
      check(`${pre} login succeeds on a thin database`, res.code === 0, JSON.stringify(res));
      const added = (out.alters || []).join(',');
      for (const column of ['email', 'ishonup', 'codechorkamukala', 'shonupwderr', 'shonullgnt', 'akshinak', 'tnegaresunohs', 'pwd']) {
        check(`${pre} added missing column ${column}`, added.includes(`ADD ${column} `), added);
      }
      check(`${pre} token stored after repair`, updates.some((s) => /`akshinak`=/.test(s)));
      break;
    }
    case 'narrow_token': {
      check(`${pre} login succeeds`, res.code === 0, JSON.stringify(res));
      check(`${pre} akshinak widened`, (out.alters || []).some((a) => a.toLowerCase().includes('modify akshinak varchar(512)')), JSON.stringify(out.alters));
      check(`${pre} no data-too-long failure`, !logText.includes('Data too long'), logText);
      break;
    }
    case 'select_throws': {
      check(`${pre} answers HTTP 200 (not 503)`, res.http === 200, JSON.stringify(res));
      check(`${pre} code 1 + readable message`, res.code === 1 && /ref [A-F0-9]{8}/.test(res.msg || ''), JSON.stringify(res));
      check(`${pre} technical reason logged with ref`, /\[Login\] ref=[A-F0-9]{8}.*doesn't exist/.test(logText), logText);
      break;
    }
    case 'user_missing': {
      check(`${pre} user not exists`, res.code === 1 && res.msgCode === 101 && res.msg === 'User not exists', JSON.stringify(res));
      break;
    }
    case 'wrong_password': {
      check(`${pre} wrong password payload`, res.code === 1 && res.msgCode === 117 && res.data?.passwordErrorNum === 1, JSON.stringify(res));
      check(`${pre} password error counter incremented`, updates.some((s) => /shonupwderr/.test(s)), JSON.stringify(updates));
      break;
    }
    case 'suspended': {
      check(`${pre} suspended account rejected`, res.msgCode === 116, JSON.stringify(res));
      break;
    }
    case 'no_user_table': {
      check(`${pre} HTTP 200 + clear message`, res.http === 200 && res.code === 1 && /shonu_subjects/.test(res.msg || ''), JSON.stringify(res));
      check(`${pre} logged`, /\[Login\] ref=[A-F0-9]{8}/.test(logText), logText);
      break;
    }
    case 'sha1_legacy': {
      check(`${pre} sha1 password accepted`, res.code === 0, JSON.stringify(res));
      check(`${pre} upgraded to bcrypt`, updates.some((s) => /`password`='\$2y\$/.test(s)));
      break;
    }
    case 'plain_legacy': {
      check(`${pre} plain password accepted`, res.code === 0, JSON.stringify(res));
      break;
    }
    case 'numeric_types': {
      check(`${pre} login succeeds with numeric session columns`, res.code === 0, JSON.stringify(res));
      check(`${pre} numeric ishonup not written with an IP`, !updates.some((s) => /`ishonup`=/.test(s)), JSON.stringify(updates));
      check(`${pre} numeric shonullgnt written as unix time`, updates.some((s) => /`shonullgnt`=\d+/.test(s)), JSON.stringify(updates));
      break;
    }
    case 'login_disabled': {
      check(`${pre} login disabled respected`, res.code === 12 && res.msgCode === 12, JSON.stringify(res));
      break;
    }
  }
}

/* doctor smoke test: with no reachable database it must explain itself, not fatal */
const doctorRun = await php.run({
  code: `<?php
    $_GET['key'] = 'b7f3c1a94e2d5068';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    chdir('/site');
    ob_start();
    require '/site/raxiwin-login-doctor.php';
    $out = ob_get_clean();
    echo $out;
  `,
});
const doctorText = doctorRun.text;
check('doctor: key gate accepts the README key', doctorText.includes('RAXIWIN LOGIN DOCTOR') && !doctorText.includes('forbidden'), doctorText.slice(0, 200));
check('doctor: reports the connection failure instead of fataling', /connection\s+:\s+FAILED/.test(doctorText), doctorText.slice(0, 400));
check('doctor: prints a verdict', doctorText.includes('VERDICT: database connection failed'), doctorText.slice(-400));

const badKey = await php.run({ code: `<?php $_GET['key']='nope'; ob_start(); require '/site/raxiwin-login-doctor.php'; echo ob_get_clean();` });
check('doctor: rejects a wrong key', badKey.text.includes('forbidden'), badKey.text.slice(0, 80));

console.log(`\nRESULT  pass=${pass} fail=${fail}`);
process.exit(fail === 0 ? 0 : 1);
