// encounter-summary.js — corrección: no interfiere con "Detalle factura"
(function () {
  "use strict";

  // -------------------- Globals --------------------
  const G = window.kcGlobals || {};
  const ajaxUrl = G.ajaxUrl || "/wp-admin/admin-ajax.php";

  let useAjaxOnly = (function () {
    try { return window.localStorage.getItem("kcSummaryUseAjax") === "1"; } catch { return false; }
  })();
  function setAjaxOnly() {
    try { window.localStorage.setItem("kcSummaryUseAjax", "1"); } catch {}
    useAjaxOnly = true;
  }

  // -------------------- Utils --------------------
  const norm = (s) => (s || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim();
  const looksLikeSummary = (el) => {
    const t = norm(el && el.textContent || "");
    return t.includes("resumen") && (t.includes("atencion") || t.includes("atención"));
  };

  function extractId(str) {
    if (!str) return null;
    const s = String(str);
    let m =
      s.match(/[?&#]\s*encounter_id\s*=\s*(\d+)/i) ||
      s.match(/[?&#]\s*id\s*=\s*(\d+)/i) ||
      s.match(/\bencounter_id\s*=\s*(\d+)/i) ||
      s.match(/\bid\s*=\s*(\d+)/i) ||
      s.match(/(?:encounter|consulta|encuentro)[\/#=-]+(\d+)/i);
    return m ? m[1] : null;
  }

  function parseJSONSafe(resp) {
    return resp.json().catch(async () => {
      const t = await resp.text();
      try { return JSON.parse(t); } catch { return { raw: t, ok: resp.ok, status: resp.status }; }
    });
  }

  function plainTextFromModal(root) {
    const clone = root.cloneNode(true);
    clone.querySelectorAll("style,script,.kc-modal__footer,.kc-modal__header,button").forEach((n) => n.remove());
    return clone.innerText.replace(/\n{3,}/g, "\n\n").trim();
  }

  // memoriza id para navegación tipo SPA
  window.__KC_LAST_ENCOUNTER_ID__ = window.__KC_LAST_ENCOUNTER_ID__ || null;
  (function hookNet() {
    try {
      const _open = XMLHttpRequest.prototype.open;
      const _send = XMLHttpRequest.prototype.send;
      XMLHttpRequest.prototype.open = function (m, u) { this.__kc_url = u; return _open.apply(this, arguments); };
      XMLHttpRequest.prototype.send = function (b) {
        try {
          const id = extractId(this.__kc_url) || extractId(typeof b === "string" ? b : "");
          if (id) window.__KC_LAST_ENCOUNTER_ID__ = id;
        } catch {}
        return _send.apply(this, arguments);
      };
      if (window.fetch) {
        const _f = window.fetch;
        window.fetch = function (input, init) {
          try {
            const url = typeof input === "string" ? input : (input && input.url) || "";
            const body = init && typeof init.body === "string" ? init.body : "";
            const id = extractId(url) || extractId(body);
            if (id) window.__KC_LAST_ENCOUNTER_ID__ = id;
          } catch {}
          return _f.apply(this, arguments);
        };
      }
    } catch {}
  })();

  function findEncounterId() {
    if (window.__KC_LAST_ENCOUNTER_ID__) return window.__KC_LAST_ENCOUNTER_ID__;
    const el = document.querySelector("[data-encounter-id]");
    if (el) return el.getAttribute("data-encounter-id");

    const hidden = document.querySelector('[name="encounter_id"],#encounter_id,input[data-name="encounter_id"]');
    if (hidden && hidden.value) return hidden.value;

    const qs = new URLSearchParams(window.location.search);
    if (qs.get("encounter_id")) return qs.get("encounter_id");
    if (qs.get("id")) return qs.get("id");

    const hid = extractId(window.location.hash || "");
    if (hid) return hid;

    try {
      const entries = performance.getEntriesByType("resource");
      for (let i = entries.length - 1; i >= 0; i--) {
        const id = extractId(entries[i].name || "");
        if (id) return id;
      }
    } catch {}
    return null;
  }

  // -------------------- Endpoints --------------------
  function hasREST() { return !!G.apiBase && !useAjaxOnly; }
  const REST = {
    summary: (id) => `${G.apiBase}/encounter/summary?encounter_id=${encodeURIComponent(id)}`,
    email: () => `${G.apiBase}/encounter/summary/email`,
    headers: (m) =>
      m === "GET"
        ? { "X-WP-Nonce": G.nonce }
        : { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8", "X-WP-Nonce": G.nonce },
  };
  const AJAX = {
    summary: (id) => `${ajaxUrl}?action=kc_encounter_summary&encounter_id=${encodeURIComponent(id)}`,
    email: () => `${ajaxUrl}?action=kc_encounter_summary_email`,
    headers: () => ({ "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" }),
  };

  function fetchJSON(restUrl, restHeaders, ajaxUrl2, ajaxHeaders) {
    if (!hasREST()) {
      return fetch(ajaxUrl2, { credentials: "include", headers: ajaxHeaders }).then(parseJSONSafe);
    }
    return fetch(restUrl, { credentials: "include", headers: restHeaders })
      .then(async (r) => {
        if (!r.ok) {
          setAjaxOnly();
          const r2 = await fetch(ajaxUrl2, { credentials: "include", headers: ajaxHeaders });
          return parseJSONSafe(r2);
        }
        return parseJSONSafe(r);
      })
      .catch(async () => {
        setAjaxOnly();
        const r = await fetch(ajaxUrl2, { credentials: "include", headers: ajaxHeaders });
        return parseJSONSafe(r);
      });
  }

  // -------------------- UI Helpers --------------------
  function getActionsWrap() {
    try {
      const candidates = Array.from(
        document.querySelectorAll(".justify-content-end,.d-flex,.d-md-flex,.d-lg-flex,.d-xl-flex")
      );
      for (const c of candidates) {
        const texts = Array.from(c.querySelectorAll("button,a,[role='button']")).map((b) => norm(b.textContent));
        if (texts.some((t) => t.includes("subir informe") || t.includes("cerrar consulta") || t.includes("volver"))) {
          return c;
        }
      }
    } catch {}
    return null;
  }

  // -------------------- Inyección SIN mover ni reestilizar --------------------
  function injectButtonOnce() {
    const currentId = findEncounterId();

    // a) Adoptar si ya existe (no tocar clases/HTML/posición)
    let btn = Array.from(document.querySelectorAll("button,a,[role='button']"))
      .filter((el) => !el.closest(".kc-modal"))
      .find((el) => looksLikeSummary(el));

    if (btn) {
      btn.setAttribute("data-kc-summary-btn", "1");
      if (currentId) btn.setAttribute("data-encounter-id", currentId);
      return;
    }

    // b) Si NO existe, clonarlo desde un botón vecino conocido y ponerlo justo al lado
    const wrap = getActionsWrap();
    let refBtn = null;
    if (wrap) {
      const buttons = Array.from(wrap.querySelectorAll("button,a,[role='button']"));
      refBtn =
        buttons.find((b) => /subir informe/.test(norm(b.textContent))) ||
        buttons.find((b) => /cerrar consulta/.test(norm(b.textContent))) ||
        buttons.find((b) => /volver/.test(norm(b.textContent)));
    }
    if (!refBtn) {
      // último intento: “Detalle factura”
      refBtn = Array.from(document.querySelectorAll("button,a,[role='button']"))
        .filter((el) => !el.closest(".kc-modal"))
        .find((el) => {
          const t = norm(el.textContent);
          return t.includes("detalle") && t.includes("factura");
        });
    }
    if (!refBtn) return;

    const clone = refBtn.cloneNode(true);
    // limpiar solo atributos de frameworks (no tocamos clases)
    Array.from(clone.attributes).forEach((a) => {
      if (/^data\-v\-/.test(a.name) || /^@/.test(a.name) || /^v\-/.test(a.name)) clone.removeAttribute(a.name);
    });
    clone.setAttribute("data-kc-summary-btn", "1");
    clone.setAttribute("aria-label", "Abrir Resumen de Atención");
    if (currentId) clone.setAttribute("data-encounter-id", currentId);

    // Cambiamos SOLO el texto visible a “Resumen de atención” preservando ícono si existe
    const icon = clone.querySelector("i,svg");
    if (icon) {
      const ico = icon.cloneNode(true);
      clone.innerHTML = "";
      clone.appendChild(ico);
      clone.appendChild(document.createTextNode(" Resumen de atención"));
    } else {
      clone.textContent = "Resumen de atención";
    }

    if (refBtn.parentNode) refBtn.parentNode.insertBefore(clone, refBtn.nextSibling);
  }

  // -------------------- Abrir Resumen --------------------
  function openSummary(id) {
    if (!id) id = findEncounterId();
    if (!id) { alert("No se pudo detectar el ID del encuentro"); return; }

    const restUrl = REST.summary(id);
    const ajaxUrl2 = AJAX.summary(id);

    fetchJSON(restUrl, REST.headers("GET"), ajaxUrl2, AJAX.headers())
      .then((json) => {
        const ok = json && (json.status === "success" || json.success === true);
        const payload = json && (json.data || json);
        const html = payload && (payload.html || (payload.data && payload.data.html));
        if (!ok || !html) {
          alert((json && (json.message || json.error)) || "No se pudo cargar");
          return;
        }

        const old = document.querySelector(".kc-modal.kc-modal-summary");
        if (old && old.remove) old.remove();

        const wrap = document.createElement("div");
        wrap.innerHTML = html;
        document.body.appendChild(wrap);

        // cierres
        wrap.querySelectorAll(".js-kc-summary-close").forEach((b) =>
          b.addEventListener("click", (ev) => { ev.stopPropagation(); wrap.remove(); })
        );
        wrap.addEventListener("click", (e) => { if (e.target.classList.contains("kc-modal")) wrap.remove(); });
        document.addEventListener("keydown", function esc(e) {
          if (e.key === "Escape") { wrap.remove(); document.removeEventListener("keydown", esc); }
        });

        // imprimir
        const printBtn = wrap.querySelector(".js-kc-summary-print,[data-kc-summary-print]");
        if (printBtn) printBtn.addEventListener("click", async (ev) => {
          ev.stopPropagation();

          const node = wrap.querySelector(".kc-modal__dialog");
          const w = window.open("", "_blank"); if (!w) return;

          async function toDataURL(url) {
            try {
              const r = await fetch(url, { credentials: "include" });
              const b = await r.blob();
              const fr = new FileReader();
              return await new Promise(res => { fr.onload = () => res(fr.result); fr.readAsDataURL(b); });
            } catch { return url; }
          }
          const bgURL = "https://intrasalud.com/wp-content/uploads/Fondo-recetario.jpg";
          const bgData = await toDataURL(bgURL);

          const modalRoot = wrap.querySelector(".kc-modal.kc-modal-summary");
          const ga = (el, a) => (el && el.getAttribute && el.getAttribute(a)) || "";

          const clinicLogo =
            (node.querySelector(".clinic-logo img") ||
             node.querySelector('img[alt*="logo" i]') ||
             node.querySelector('img[src*="logo"]'))?.getAttribute("src") ||
            ga(modalRoot, "data-clinic-logo") || (G && G.clinicLogo) || "";

          const doctorSignature =
            (node.querySelector('[data-role="doctor-signature"]') ||
             node.querySelector('img[alt*="firma" i]') ||
             node.querySelector('img[alt*="signature" i]') ||
             node.querySelector('img[src*="firma"],img[src*="signature"]'))?.getAttribute("src") ||
            ga(modalRoot, "data-doctor-signature") || (G && G.doctorSignature) || "";

          let doctorName = ga(modalRoot, "data-doctor-name") || (G && G.doctorName) || "";
          let doctorSpec = ga(modalRoot, "data-doctor-specialty") || (G && G.doctorSpecialty) || "";
          let doctorMPPS = ga(modalRoot, "data-doctor-mpps") || (G && G.doctorMPPS) || "";
          let doctorCM = ga(modalRoot, "data-doctor-cm") || (G && G.doctorCM) || "";
          let doctorCI = ga(modalRoot, "data-doctor-ci") || (G && G.doctorCI) || "";
          if (!doctorName) { const m = (node.textContent || "").match(/Doctor:\s*([^\n]+)/i); if (m) doctorName = m[1].trim(); }

          w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Resumen de Atención</title>');
          w.document.write(`
            <style>
              @page { size: Letter; margin: 1cm; }
              @page { size: 8.5in 11in; margin: 1cm; }
              html, body { height: 100%; }
              body { margin: 0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
              * { box-sizing: border-box; }
              .kc-page-bg { position: fixed; inset: 0; width: 100vw; height: 100vh; object-fit: cover; z-index: 0; pointer-events: none; }
              .kc-print-root { position: relative; z-index: 1; font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,"Helvetica Neue",Helvetica,sans-serif; font-size: 12pt; line-height: 1.45; color: #111; padding: 0; margin: 0; }
              .kc-print-root, .kc-print-root * { background: transparent !important; border: 0 !important; box-shadow: none !important; }
              .kc-print-root * { margin: 0 !important; padding: 0 !important; max-width: 100% !important; }
              .kc-header-logo { width: 100%; margin: 0 0 12px 0 !important; text-align: left !important; }
              .kc-header-logo img { height: 60px; display: block; }
              .kc-footer { position: fixed; bottom: 1cm; left: 0; width: 100%; text-align: center; line-height: 1.1; z-index: 2; }
              .kc-footer img { max-height: 80px; display: inline-block; padding: 10px; }
              @media print { .kc-hard-break { break-before: page; page-break-before: always; } .kc-isolated-page { break-after: page; page-break-after: always; } }
            </style>
          `);
          w.document.write("</head><body>");
          w.document.write('<img class="kc-page-bg" alt="" src="' + bgData + '">');

          const clean = node.cloneNode(true);
          clean.querySelectorAll("style,link").forEach((n) => n.remove());
          clean.querySelectorAll("[style]").forEach((n) => n.removeAttribute("style"));
          clean.querySelectorAll(".kc-modal__footer, .kc-modal__close, .button, button, .dashicons").forEach((n) => n.remove());

          (function tagPrescriptionTable(root) {
            const tables = Array.from(root.querySelectorAll("table"));
            for (const t of tables) {
              const ths = Array.from((t.querySelector("thead") || t).querySelectorAll("th"))
                .map((x) => (x.textContent || "").trim().toLowerCase());
              if (ths.length && ths.join("|").includes("nombre") && ths.join("|").includes("frecuencia")) {
                t.classList.add("kc-prescription");
              }
            }
          })(clean);

          if (clinicLogo) {
            const header = document.createElement("div");
            header.className = "kc-header-logo";
            header.innerHTML = '<img src="' + clinicLogo + '" alt="Logo Clínica">';
            const content = clean.querySelector(".kc-modal__content, .kc-summary, main, .content, .body") || clean;
            content.insertBefore(header, content.firstChild);
          }

          (function isolateAndFooter(root) {
            const container = root.querySelector(".kc-modal__content, .kc-summary, main, .content, .body") || root;
            const nrm = (s) => (s || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim();
            let target = null;
            for (const el of container.querySelectorAll("*")) {
              const t = nrm(el.textContent); if (!t) continue;
              if ((t.includes("ordenes clinicas") || t.includes("órdenes clínicas")) && t.length <= 120) { target = el; break; }
            }
            if (target) {
              const cardSel = ".kc-summary__section,.kc-card,.card,.panel,.kc-box,section,.box,.group,.kc-area,.kc-group,.bg-white,.rounded,.rounded-md,.rounded-lg";
              let block = target.closest(cardSel);
              if (!block) { let p = target; while (p && p.parentElement && p.parentElement !== container) p = p.parentElement; block = p || target; }
              const sep = document.createElement("div"); sep.className = "kc-hard-break";
              block.parentNode.insertBefore(sep, block);
              block.classList.add("kc-isolated-page");
            }

            const footer = document.createElement("div");
            footer.className = "kc-footer";
            footer.innerHTML = `
              ${doctorSignature ? `<div style="display:inline-block;padding:10px;"><img src="${doctorSignature}" alt="signature" style="max-height:80px;"></div>` : ``}
              <br><b>${(doctorName || "").toUpperCase()}</b><br>
              ${doctorSpec || ""}<br>
              ${doctorMPPS ? `MPPS: ${doctorMPPS}` : ""}${doctorMPPS && doctorCM ? " &nbsp;-&nbsp; " : ""}${doctorCM ? `CM: ${doctorCM}` : ""}<br>
              ${doctorCI ? `C.I. ${doctorCI}` : ""}<br>
            `;
            root.appendChild(footer);
          })(clean);

          const wrap2 = w.document.createElement("div");
          wrap2.className = "kc-print-root";
          wrap2.appendChild(clean);
          w.document.body.appendChild(wrap2);

          w.document.write("</body></html>");
          w.document.close();

          function whenImagesReady(doc) {
            const imgs = Array.from(doc.images || []);
            if (!imgs.length) return Promise.resolve();
            return Promise.all(imgs.map(img => img.complete ? Promise.resolve() :
              new Promise(res => { img.addEventListener("load", res, { once: true }); img.addEventListener("error", res, { once: true }); })));
          }
          try { await whenImagesReady(w.document); } catch {}
          w.focus();
          w.onafterprint = () => { try { w.close(); } catch {} };
          setTimeout(() => { try { w.close(); } catch {} }, 2000);
          w.print();
        });

        // correo
        const emailBtn = wrap.querySelector(".js-kc-summary-email,[data-kc-summary-email]");
        const modalRoot = wrap.querySelector(".kc-modal.kc-modal-summary");
        const defaultEmail = modalRoot ? modalRoot.getAttribute("data-patient-email") : "";
        const encounterId = findEncounterId();

        function postEmail(restUrl, ajaxUrl2, to) {
          const body = new URLSearchParams();
          if (encounterId) body.set("encounter_id", encounterId);
          body.set("to", to);

          if (hasREST()) {
            return fetch(restUrl, {
              method: "POST",
              credentials: "include",
              headers: REST.headers("POST"),
              body: body.toString(),
            })
              .then(async (r) => {
                if (!r.ok) { setAjaxOnly(); throw new Error("REST failed"); }
                return parseJSONSafe(r);
              })
              .catch(async () => {
                const r = await fetch(ajaxUrl2, {
                  method: "POST",
                  credentials: "include",
                  headers: AJAX.headers(),
                  body: body.toString(),
                });
                return parseJSONSafe(r);
              });
          }
          return fetch(ajaxUrl2, {
            method: "POST",
            credentials: "include",
            headers: AJAX.headers(),
            body: body.toString(),
          }).then(parseJSONSafe);
        }

        if (emailBtn) emailBtn.addEventListener("click", (ev) => {
          ev.stopPropagation();
          const to = defaultEmail || prompt("Correo de destino", "") || "";
          if (!to) return;

          const restEmail = REST.email();
          const ajaxEmail = AJAX.email();

          postEmail(restEmail, ajaxEmail, to)
            .then((resp) => {
              const ok = resp && (resp.status === "success" || resp.success === true);
              if (ok) { alert("Enviado"); return; }
              throw new Error("Backend dijo que no");
            })
            .catch(() => {
              const body = encodeURIComponent(
                plainTextFromModal(wrap.querySelector(".kc-modal__dialog"))
              );
              const subject = encodeURIComponent("Resumen de Atención");
              window.location.href = `mailto:${encodeURIComponent(to)}?subject=${subject}&body=${body}`;
              alert("No se pudo enviar desde el sistema. Se abrió tu cliente de correo con el contenido.");
            });
        });
      })
      .catch(() => alert("Error de red"));
  }

  // -------------------- Delegación (con verificación de etiqueta) --------------------
  function summaryClickHandler(e) {
    const btn = e.target.closest && e.target.closest('[data-kc-summary-btn="1"]');
    if (!btn) return;

    // Evitar falsos positivos: solo si el texto dice "Resumen de atención"
    if (!looksLikeSummary(btn)) return;

    e.preventDefault();
    e.stopPropagation();
    if (e.stopImmediatePropagation) e.stopImmediatePropagation();
    const id = btn.getAttribute("data-encounter-id") || "";
    openSummary(id);
  }
  document.addEventListener("click", summaryClickHandler, true);
  document.addEventListener("click", summaryClickHandler, false);

  // -------------------- Boot + SPA observer --------------------
  function boot() { injectButtonOnce(); }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
  const mo = new MutationObserver(() => { try { injectButtonOnce(); } catch {} });
  mo.observe(document.documentElement, { childList: true, subtree: true });

  // -------------------- Fallback impresión factura (intacto) --------------------
  document.addEventListener("click", (e) => {
    const btn = e.target.closest && e.target.closest("button, a");
    if (!btn) return;

    const modal = btn.closest && btn.closest(".kc-modal"); // solo si está dentro de una modal
    if (!modal) return;

    const titleEl = modal.querySelector(".kc-modal__header h3");
    const title = ((titleEl && titleEl.textContent) || "").toLowerCase();
    const isBill = /factura|bill|invoice/.test(title);

    const isPrintTrigger =
      btn.matches(".js-kc-bill-print, [data-kc-bill-print]") ||
      ((btn.textContent || "").toLowerCase().includes("imprimir"));

    if (!isBill || !isPrintTrigger) return;

    setTimeout(() => {
      const dialog = modal.querySelector(".kc-modal__dialog");
      if (!dialog) return;

      const clean = dialog.cloneNode(true);
      clean
        .querySelectorAll(".kc-modal__footer, .kc-modal__close, .button, button, .dashicons")
        .forEach((n) => n.remove());

      const w = window.open("", "_blank");
      if (!w) return;

      w.document.write("<html><head><title>Detalle de la factura</title>");
      document.querySelectorAll('link[rel="stylesheet"]').forEach((l) => w.document.write(l.outerHTML));
      w.document.write("</head><body>" + clean.outerHTML + "</body></html>");
      w.document.close();
      w.focus();
      w.onafterprint = () => { try { w.close(); } catch {} };
      setTimeout(() => { try { w.close(); } catch {} }, 2000);
      w.print();
    }, 200);
  });
})();
