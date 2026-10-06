(function () {
  var cfg = window.P7 || {};
  var left = parseInt(cfg.seconds, 10);
  if (!isFinite(left) || left < 0) left = 0;
  var expired = !!cfg.expired || left <= 0;
  var timerEl = document.getElementById("timer");

  function showExpired() {
    if (document.getElementById("p7-expired")) return;
    expired = true;
    document.body.classList.add("is-expired");
    var wrap = document.createElement("div");
    wrap.id = "p7-expired";
    wrap.className = "p7-expired";
    wrap.innerHTML = '<div class="p7-expired__card">' +
      '<div class="p7-expired__ico" aria-hidden="true">!</div>' +
      '<h2>Expired Order</h2>' +
      '<p>This payment time is over. Do not pay on this QR. Create a new deposit order.</p>' +
      '<a href="' + String(cfg.cancelUrl || "/#/wallet/Recharge").replace(/"/g, "") + '">Back to Deposit</a>' +
      '</div>';
    document.body.appendChild(wrap);
    var sub = document.getElementById("submitUtr");
    var input = document.getElementById("utr");
    if (sub) sub.disabled = true;
    if (input) input.disabled = true;
    var links = document.querySelectorAll("a.method, a.ar-m, a.gx-row, a.wk-row, a.veer-m");
    var i;
    for (i = 0; i < links.length; i++) {
      links[i].removeAttribute("href");
      links[i].style.pointerEvents = "none";
    }
  }

  function tick() {
    if (timerEl) {
      if (left < 0) left = 0;
      var m = Math.floor(left / 60);
      var s = left % 60;
      timerEl.textContent = (m < 10 ? "0" : "") + m + ":" + (s < 10 ? "0" : "") + s;
    }
    if (left <= 0) {
      showExpired();
      return;
    }
    left -= 1;
    setTimeout(tick, 1000);
  }
  tick();

  var upi = cfg.upi || "";
  var box = document.getElementById("qrInner");
  var fb = document.getElementById("qrFallback");
  if (upi && box && !expired) {
    try {
      if (window.QRCode) {
        new QRCode(box, { text: upi, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M });
      } else {
        throw new Error("no qr lib");
      }
    } catch (e) {
      if (fb) {
        fb.style.display = "block";
        fb.src = "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=" + encodeURIComponent(upi);
        box.style.display = "none";
      }
    }
  }

  var saveBtn = document.getElementById("saveQr");
  if (saveBtn) {
    saveBtn.addEventListener("click", function () {
      if (expired) return;
      var node = document.querySelector("#qrInner img, #qrInner canvas, #qrFallback");
      if (!node) return;
      var href = "";
      if (node.tagName === "IMG") href = node.src;
      else {
        try { href = node.toDataURL("image/png"); } catch (e) { href = ""; }
      }
      if (!href) return;
      var a = document.createElement("a");
      a.href = href;
      a.download = "upi-qr.png";
      a.click();
    });
  }

  function copyText(t) {
    t = String(t || "");
    if (!t || expired) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(t).catch(function () {});
      return;
    }
    var ta = document.createElement("textarea");
    ta.value = t;
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand("copy"); } catch (e) {}
    document.body.removeChild(ta);
  }

  var copyVpa = document.getElementById("copyVpa");
  if (copyVpa) {
    copyVpa.addEventListener("click", function () {
      copyText(cfg.vpa || cfg.upi || "");
    });
  }
  var copyAmt = document.getElementById("copyAmt");
  if (copyAmt) {
    copyAmt.addEventListener("click", function () {
      copyText(copyAmt.getAttribute("data-copy") || "");
    });
  }

  function goReturn() {
    window.location.replace(cfg.returnUrl || "/#/wallet/RechargeHistory");
  }

  var cancelBtn = document.getElementById("cancelPay");
  if (cancelBtn) {
    cancelBtn.addEventListener("click", function () {
      window.location.href = cfg.cancelUrl || "/#/wallet/Recharge";
    });
  }

  function poll() {
    if (!cfg.statusUrl) return;
    fetch(cfg.statusUrl, { credentials: "same-origin", cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.paid) {
          goReturn();
          return;
        }
        setTimeout(poll, 4000);
      })
      .catch(function () { setTimeout(poll, 5000); });
  }
  setTimeout(poll, 4000);

  var sub = document.getElementById("submitUtr");
  var input = document.getElementById("utr");
  var msg = document.getElementById("utrMsg");

  function utrValue() {
    return (input && input.value ? input.value : "").replace(/\s+/g, "");
  }

  function refreshSubmit() {
    if (!sub) return;
    if (expired) {
      sub.disabled = true;
      return;
    }
    var utr = utrValue();
    var need = sub.getAttribute("data-need-utr") === "1";
    var emptyL = sub.getAttribute("data-empty-label");
    var readyL = sub.getAttribute("data-ready-label");
    if (utr.length < 8) {
      if (emptyL) sub.textContent = emptyL;
      if (need) sub.disabled = true;
      sub.classList.remove("is-on");
    } else {
      if (readyL) sub.textContent = readyL;
      sub.disabled = false;
      sub.classList.add("is-on");
    }
  }

  if (input) {
    input.addEventListener("input", refreshSubmit);
  }
  refreshSubmit();

  var pasteBtn = document.getElementById("pasteUtr");
  if (pasteBtn && input) {
    pasteBtn.addEventListener("click", function () {
      if (expired) return;
      if (navigator.clipboard && navigator.clipboard.readText) {
        navigator.clipboard.readText().then(function (t) {
          input.value = String(t || "").replace(/\s+/g, "");
          refreshSubmit();
        }).catch(function () {});
      }
    });
  }

  if (sub) {
    sub.addEventListener("click", function () {
      if (expired) {
        showExpired();
        return;
      }
      var utr = utrValue();
      if (utr.length < 8) {
        if (msg) msg.textContent = "Enter a valid UTR / UPI Ref No";
        return;
      }
      sub.disabled = true;
      fetch(cfg.statusUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "action=utr&utr=" + encodeURIComponent(utr)
      })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          sub.disabled = false;
          refreshSubmit();
          if (j && j.paid) {
            goReturn();
            return;
          }
          if (msg) msg.textContent = (j && j.message) ? j.message : "UTR saved. Waiting for payment confirmation.";
        })
        .catch(function () {
          sub.disabled = false;
          refreshSubmit();
          if (msg) msg.textContent = "Could not save UTR. Keep this page open.";
        });
    });
  }
})();
