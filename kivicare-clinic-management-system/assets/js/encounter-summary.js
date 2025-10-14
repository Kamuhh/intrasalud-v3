(function () {
    "use strict";

    // -------------------- Globals --------------------
    const G = window.kcGlobals || {};
    const ajaxUrl = G.ajaxUrl || "/wp-admin/admin-ajax.php";

    let useAjaxOnly = (() => {
        try { return window.localStorage.getItem("kcSummaryUseAjax") === "1"; } catch { return false; }
    })();
    function setAjaxOnly() {
        try { window.localStorage.setItem("kcSummaryUseAjax", "1"); } catch { }
        useAjaxOnly = true;
    }

    // -------------------- Utils --------------------
    const norm = (s) => (s || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim();

    // Solo consideramos botones cuyo texto sea “Resumen de atención”
    const looksLikeSummary = (el) => {
        if (!el) return false;
        const t = norm(el.textContent || "");
        return t.includes("resumen") && (t.includes("atencion") || t.includes("atención"));
    };

    // Detectar si esta vista es realmente de un “encounter”
    function isEncounterContext() {
        // 1) Debe existir un encounter_id
        if (!findEncounterId()) return false;

        // 2) Heurísticas suaves de la página de consulta
        const txt = norm(document.body.innerText || "");
        const hasKeywords =
            (txt.includes("detalles de la consulta") || txt.includes("detalle de la consulta")) &&
            (txt.includes("doctor") || txt.includes("paciente"));
        // 3) Evitar páginas de administración/configuración
        const url = location.href;
        const notAdminForms = !/\/wp-admin\/admin\.php\?page=dashboard#\/clinic\/edit/i.test(url);

        return hasKeywords && notAdminForms;
    }

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
                } catch { }
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
                    } catch { }
                    return _f.apply(this, arguments);
                };
            }
        } catch { }
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
        } catch { }
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

    function parseJSONSafe(resp) {
        return resp.json().catch(async () => {
            const t = await resp.text();
            try { return JSON.parse(t); } catch { return { raw: t, ok: resp.ok, status: resp.status }; }
        });
    }

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

    // -------------------- Construcción del HTML limpio (para PDF) --------------------
    function buildPrintableDoc(wrap) {
        const node = wrap.querySelector(".kc-modal__dialog");
        if (!node) return "";

        const clean = node.cloneNode(true);
        clean.querySelectorAll("style,link,script").forEach(n => n.remove());
        clean.querySelectorAll(".kc-modal__footer, .kc-modal__close, .button, button, .dashicons, [data-kc-summary-email], [data-kc-summary-print], .js-kc-summary-email, .js-kc-summary-print").forEach(n => n.remove());
        Array.from(clean.querySelectorAll("a,button")).forEach((b) => {
            const t = (b.textContent || "").toLowerCase();
            if (/(correo|electrónico|imprimir|cerrar|email|print|close)/.test(t)) b.remove();
        });

        // Documento completo con estilos (idéntico al usado por el servidor/Dompdf)
        return '' +
            '<!doctype html><html><head><meta charset="utf-8"><title>Resumen de la atención</title>' +
            '<style>@page{size:Letter;margin:1cm}html,body{height:100%}body{margin:0;-webkit-print-color-adjust:exact;print-color-adjust:exact}' +
            '*{box-sizing:border-box}.kc-print-root{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,"Helvetica Neue",Helvetica,sans-serif;font-size:12pt;line-height:1.45;color:#111}' +
            '.kc-print-root,.kc-print-root *{background:transparent!important;border:0!important;box-shadow:none!important}' +
            '.kc-print-root *{margin:0!important;padding:0!important;max-width:100%!important}' +
            '.kc-header-logo{width:100%;margin:0 0 12px 0!important;text-align:left!important}.kc-header-logo img{height:60px;display:block}' +
            '.kc-footer{position:fixed;bottom:1cm;left:0;width:100%;text-align:center;line-height:1.1}.kc-footer img{max-height:80px;display:inline-block;padding:10px}</style>' +
            '</head><body><div class="kc-print-root">' + clean.outerHTML + '</div></body></html>';
    }

    // -------------------- UI Helpers --------------------
    function getActionsWrap() {
        try {
            const cands = Array.from(document.querySelectorAll(".justify-content-end,.d-flex,.d-md-flex,.d-lg-flex,.d-xl-flex"));
            for (const c of cands) {
                const texts = Array.from(c.querySelectorAll("button,a,[role='button']")).map((b) => norm(b.textContent));
                if (texts.some((t) => t.includes("subir informe") || t.includes("cerrar consulta") || t.includes("volver"))) {
                    return c;
                }
            }
        } catch { }
        return null;
    }

    // -------------------- Inyección (solo en páginas de encounter) --------------------
    function injectButtonOnce() {
        if (!isEncounterContext()) return;       // <- guard principal
        const currentId = findEncounterId();
        if (!currentId) return;

        // a) Adoptar si ya existe
        let btn = Array.from(document.querySelectorAll("button,a,[role='button']"))
            .filter((el) => !el.closest(".kc-modal"))
            .find((el) => looksLikeSummary(el));

        if (btn) {
            btn.setAttribute("data-kc-summary-btn", "1");
            btn.setAttribute("data-encounter-id", currentId);
            return;
        }

        // b) Clonar botón conocido
        const wrap = getActionsWrap();
        let refBtn = null;
        if (wrap) {
            const buttons = Array.from(wrap.querySelectorAll("button,a,[role='button']"));
            refBtn =
                buttons.find((b) => /subir informe/.test(norm(b.textContent))) ||
                buttons.find((b) => /cerrar consulta/.test(norm(b.textContent))) ||
                buttons.find((b) => /volver/.test(norm(b.textContent)));
        }
        if (!refBtn) return;

        const clone = refBtn.cloneNode(true);
        Array.from(clone.attributes).forEach((a) => {
            if (/^data\-v\-/.test(a.name) || /^@/.test(a.name) || /^v\-/.test(a.name)) clone.removeAttribute(a.name);
        });
        clone.setAttribute("data-kc-summary-btn", "1");
        clone.setAttribute("aria-label", "Abrir Resumen de atención");
        clone.setAttribute("data-encounter-id", currentId);

        const icon = clone.querySelector("i,svg");
        if (icon) {
            const ico = icon.cloneNode(true);
            clone.innerHTML = "";
            clone.appendChild(ico);
            clone.appendChild(document.createTextNode(" Resumen de la atención"));
        } else {
            clone.textContent = "Resumen de la atención";
        }

        if (refBtn.parentNode) refBtn.parentNode.insertBefore(clone, refBtn.nextSibling);
    }

    // -------------------- Abrir Resumen + manejar PDF/Email --------------------
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

                // “Ver PDF” -> POST a AJAX con view=1 para que entregue PDF inline
                const printBtn = wrap.querySelector(".js-kc-summary-print,[data-kc-summary-print]");
                if (printBtn) {
                    printBtn.textContent = "Ver PDF";
                    printBtn.addEventListener("click", (ev) => {
                        ev.stopPropagation();
                        const htmlDoc = buildPrintableDoc(wrap);
                        if (!htmlDoc) return;

                        // submit POST a una pestaña nueva para recibir el PDF inline
                        const form = document.createElement("form");
                        form.method = "POST";
                        form.action = AJAX.email();
                        form.target = "_blank";
                        form.style.display = "none";

                        const f1 = document.createElement("input"); f1.name = "encounter_id"; f1.value = String(id);
                        const f2 = document.createElement("input"); f2.name = "view"; f2.value = "1";
                        const f3 = document.createElement("textarea"); f3.name = "html"; f3.value = htmlDoc;

                        form.appendChild(f1); form.appendChild(f2); form.appendChild(f3);
                        document.body.appendChild(form);
                        form.submit();
                        setTimeout(() => form.remove(), 1000);
                    });
                }

                // correo (adjunto PDF)
                const emailBtn = wrap.querySelector(".js-kc-summary-email,[data-kc-summary-email]");
                const modalRoot = wrap.querySelector(".kc-modal.kc-modal-summary");
                const defaultEmail = modalRoot ? modalRoot.getAttribute("data-patient-email") : "";

                if (emailBtn) emailBtn.addEventListener("click", (ev) => {
                    ev.stopPropagation();
                    const to = defaultEmail || prompt("Correo de destino", "") || "";
                    if (!to) return;

                    const htmlDoc = buildPrintableDoc(wrap);
                    if (!htmlDoc) return;

                    // POST send=1 para enviar correo con PDF adjunto
                    const body = new URLSearchParams();
                    body.set("encounter_id", String(id));
                    body.set("send", "1");
                    body.set("to", to);
                    body.set("html", htmlDoc);

                    fetch(AJAX.email(), {
                        method: "POST",
                        credentials: "include",
                        headers: AJAX.headers(),
                        body: body.toString()
                    })
                        .then(parseJSONSafe)
                        .then((resp) => {
                            const ok2 = resp && (resp.status === "success" || resp.success === true);
                            alert(ok2 ? "Enviado" : "No se pudo enviar");
                        })
                        .catch(() => alert("Error de red al enviar"));
                });
            })
            .catch(() => alert("Error de red"));
    }

    // -------------------- Delegación de clicks --------------------
    function summaryClickHandler(e) {
        const btn = e.target.closest && e.target.closest('[data-kc-summary-btn="1"]');
        if (!btn) return;
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
    const mo = new MutationObserver(() => { try { injectButtonOnce(); } catch { } });
    mo.observe(document.documentElement, { childList: true, subtree: true });

    // -------------------- Fallback impresión factura (intacto) --------------------
    document.addEventListener("click", (e) => {
        const btn = e.target.closest && e.target.closest("button, a");
        if (!btn) return;

        const modal = btn.closest && btn.closest(".kc-modal");
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
            clean.querySelectorAll(".kc-modal__footer, .kc-modal__close, .button, button, .dashicons").forEach((n) => n.remove());

            const w = window.open("", "_blank"); if (!w) return;
            w.document.write("<html><head><title>Detalle de la factura</title>");
            document.querySelectorAll('link[rel="stylesheet"]').forEach((l) => w.document.write(l.outerHTML));
            w.document.write("</head><body>" + clean.outerHTML + "</body></html>");
            w.document.close();
            w.focus();
            w.onafterprint = () => { try { w.close(); } catch { } };
            setTimeout(() => { try { w.close(); } catch { } }, 2000);
            w.print();
        }, 200);
    });

})();
