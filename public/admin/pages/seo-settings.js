import { api } from "../services/api.js";
import { showToast } from "../components/shell.js";
import { bindTabs, renderTabsBarHtml } from "../components/tabs.js";

function safeJson(value, fallback = "") {
  if (!value) return fallback;
  try {
    return JSON.stringify(value, null, 2);
  } catch (_e) {
    return fallback;
  }
}

const SEVERITY_COLORS = {
  critical: "#9e3f4e",
  high: "#c45c00",
  medium: "#b8860b",
  low: "#5f5e61",
};

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

/**
 * @param {Array<{label:string,value:number,color?:string}>} rows
 * @param {{title:string,width?:number,height?:number,max?:number,unit?:string,ariaLabel?:string}} opts
 */
function svgHorizontalBarChart(rows, opts) {
  const width = opts.width ?? 420;
  const rowH = 26;
  const labelW = 120;
  const barX = labelW + 8;
  const barW = width - barX - 16;
  const height = Math.max(80, rows.length * rowH + 24);
  const maxVal = opts.max ?? Math.max(1, ...rows.map((r) => r.value));
  const bars = rows
    .map((r, i) => {
      const y = 16 + i * rowH;
      const bw = maxVal > 0 ? (r.value / maxVal) * barW : 0;
      const fill = r.color || "#535f78";
      return `
        <text class="seo-chart-label" x="0" y="${y + 14}" font-size="11">${escapeHtml(r.label)}</text>
        <rect x="${barX}" y="${y + 4}" width="${barW}" height="16" rx="4" fill="#ebeeef" />
        <rect x="${barX}" y="${y + 4}" width="${Math.max(0, bw)}" height="16" rx="4" fill="${escapeHtml(fill)}" />
        <text class="seo-chart-value" x="${barX + barW + 6}" y="${y + 14}" font-size="11">${escapeHtml(String(r.value))}${escapeHtml(opts.unit || "")}</text>`;
    })
    .join("");
  return `<svg class="seo-chart-svg" width="${width}" height="${height}" role="img" aria-label="${escapeHtml(opts.ariaLabel || opts.title)}" viewBox="0 0 ${width} ${height}">
    <text class="seo-chart-t-title" x="0" y="12" font-size="12" font-weight="700">${escapeHtml(opts.title)}</text>
    ${bars}
  </svg>`;
}

/**
 * @param {number[]} values
 * @param {{title:string,width?:number,height?:number,labels?:string[],ariaLabel?:string}} opts
 */
function svgLineChart(values, opts) {
  const width = opts.width ?? 420;
  const height = opts.height ?? 140;
  const pad = { t: 28, r: 12, b: 28, l: 12 };
  const w = width - pad.l - pad.r;
  const h = height - pad.t - pad.b;
  const n = values.length;
  const maxV = Math.max(1, ...values, 0.01);
  const minV = Math.min(0, ...values);
  const range = maxV - minV || 1;
  const pts = values.map((v, i) => {
    const x = pad.l + (n <= 1 ? w / 2 : (i / (n - 1)) * w);
    const y = pad.t + h - ((v - minV) / range) * h;
    return `${x},${y}`;
  });
  const polyline = pts.join(" ");
  const dots = values
    .map((v, i) => {
      const x = pad.l + (n <= 1 ? w / 2 : (i / (n - 1)) * w);
      const y = pad.t + h - ((v - minV) / range) * h;
      return `<circle cx="${x}" cy="${y}" r="3" fill="#535f78" />`;
    })
    .join("");
  const xLabels = (opts.labels || []).map((lab, i) => {
    const x = pad.l + (n <= 1 ? w / 2 : (i / Math.max(1, n - 1)) * w);
    return `<text class="seo-chart-xlabel" x="${x}" y="${height - 6}" font-size="9" text-anchor="middle">${escapeHtml(lab)}</text>`;
  });
  return `<svg class="seo-chart-svg" width="${width}" height="${height}" role="img" aria-label="${escapeHtml(opts.ariaLabel || opts.title)}" viewBox="0 0 ${width} ${height}">
    <text class="seo-chart-t-title" x="0" y="16" font-size="12" font-weight="700">${escapeHtml(opts.title)}</text>
    <polyline fill="none" stroke="#535f78" stroke-width="2" points="${polyline}" />
    ${dots}
    ${xLabels.join("")}
  </svg>`;
}

function panelDashboard() {
  return `
    <div class="seo-panel-inner seo-dashboard">
      <div class="seo-dash-toolbar">
        <button type="button" class="btn" id="seo-dash-refresh">Rafraîchir les statistiques</button>
        <span class="seo-dash-updated" id="seo-dash-updated" aria-live="polite"></span>
      </div>
      <p class="seo-dash-intro" id="seo-dash-error" hidden></p>
      <div class="seo-kpi-grid" id="seo-dash-kpis">
        <div class="seo-kpi"><span class="seo-kpi-label">Entités scorées</span><strong class="seo-kpi-value" id="seo-kpi-total">—</strong></div>
        <div class="seo-kpi"><span class="seo-kpi-label">Score moyen global</span><strong class="seo-kpi-value" id="seo-kpi-avg">—</strong></div>
        <div class="seo-kpi"><span class="seo-kpi-label">Blockers (total)</span><strong class="seo-kpi-value" id="seo-kpi-blockers">—</strong></div>
        <div class="seo-kpi"><span class="seo-kpi-label">Reco. ouvertes</span><strong class="seo-kpi-value" id="seo-kpi-open-rec">—</strong></div>
      </div>
      <div class="seo-charts-grid" id="seo-dash-charts"></div>
    </div>`;
}

function panelSite() {
  return `
    <div class="seo-panel-inner admin-form-grid">
      <label class="orders-label" for="seo-site-name">Nom du site</label>
      <input class="input" id="seo-site-name" placeholder="Site name" autocomplete="off">
      <label class="orders-label" for="seo-title-template">Modèle de titre</label>
      <input class="input" id="seo-title-template" placeholder="{title} | {site_name}" autocomplete="off">
      <label class="orders-label" for="seo-meta-template">Modèle meta description</label>
      <input class="input" id="seo-meta-template" placeholder="{title} - {site_name}" autocomplete="off">
      <label class="orders-label" for="seo-canonical-base">URL canonique de base</label>
      <input class="input" id="seo-canonical-base" placeholder="https://example.com" autocomplete="off">
      <label class="orders-label" for="seo-robots-default">Robots par défaut</label>
      <select class="select" id="seo-robots-default">
        <option value="index,follow">index,follow</option>
        <option value="noindex,follow">noindex,follow</option>
        <option value="noindex,nofollow">noindex,nofollow</option>
      </select>
    </div>`;
}

function panelSocial() {
  return `
    <div class="seo-panel-inner admin-form-grid">
      <label class="orders-label" for="seo-og-site-name">OG site name</label>
      <input class="input" id="seo-og-site-name" placeholder="OG site name" autocomplete="off">
      <label class="orders-label" for="seo-og-image">Image OG par défaut (URL)</label>
      <input class="input" id="seo-og-image" placeholder="Default OG image URL" autocomplete="off">
      <label class="orders-label" for="seo-twitter-site">Compte Twitter / X</label>
      <input class="input" id="seo-twitter-site" placeholder="@compte" autocomplete="off">
    </div>`;
}

function panelSchema() {
  return `
    <div class="seo-panel-inner admin-form-grid">
      <label class="orders-label" for="seo-org-name">Organisation (nom)</label>
      <input class="input" id="seo-org-name" placeholder="Organization name" autocomplete="off">
      <label class="orders-label" for="seo-org-url">Organisation (URL)</label>
      <input class="input" id="seo-org-url" placeholder="Organization URL" autocomplete="off">
      <label class="orders-label" for="seo-org-logo">Logo (URL)</label>
      <input class="input" id="seo-org-logo" placeholder="Organization logo URL" autocomplete="off">
      <label class="orders-label" for="seo-search-template">URL de recherche (modèle)</label>
      <input class="input" id="seo-search-template" placeholder="https://site/recherche?q={query}" autocomplete="off">
    </div>`;
}

function panelEeat() {
  return `
    <div class="seo-panel-inner admin-form-grid">
      <label class="orders-label" for="seo-default-author">Auteur par défaut</label>
      <input class="input" id="seo-default-author" placeholder="Auteur par défaut" autocomplete="off">
      <label class="orders-label" for="seo-default-role">Rôle auteur par défaut</label>
      <input class="input" id="seo-default-role" placeholder="Rôle auteur par défaut" autocomplete="off">
      <label class="orders-label" for="seo-trust-statement">Statement de confiance</label>
      <textarea class="input orders-textarea" id="seo-trust-statement" placeholder="Statement de confiance"></textarea>
      <label class="orders-label" for="seo-min-score">Score minimum par défaut</label>
      <input class="input" id="seo-min-score" type="number" min="40" max="95" placeholder="60">
    </div>`;
}

function panelAdvanced() {
  return `
    <div class="seo-panel-inner admin-form-grid">
      <label class="orders-label" for="seo-indexation-rules">Règles d’indexation (JSON)</label>
      <textarea class="input orders-textarea" id="seo-indexation-rules"></textarea>
      <div class="seo-actions-bar">
        <button type="button" class="btn" id="seo-preview">Prévisualiser génération</button>
        <button type="button" class="btn" id="seo-coverage">Rapport complétude singles</button>
      </div>
      <label class="orders-label" for="seo-preview-output">Résultat (aperçu / rapport)</label>
      <textarea class="input orders-textarea" id="seo-preview-output" placeholder="Preview / coverage"></textarea>
    </div>`;
}

function bindSeoTabs(root) {
  const tablist = root.querySelector("#seo-tablist");
  if (!tablist) return;
  const tabs = [...tablist.querySelectorAll('[role="tab"]')];
  const panels = tabs.map((t) => document.getElementById(t.getAttribute("aria-controls") || ""));

  function select(index) {
    const i = ((index % tabs.length) + tabs.length) % tabs.length;
    tabs.forEach((tab, j) => {
      const on = j === i;
      tab.setAttribute("aria-selected", on ? "true" : "false");
      tab.tabIndex = on ? 0 : -1;
      if (panels[j]) panels[j].hidden = !on;
    });
  }

  tabs.forEach((tab, i) => {
    tab.addEventListener("click", () => select(i));
    tab.addEventListener("keydown", (ev) => {
      if (ev.key === "ArrowRight" || ev.key === "ArrowDown") {
        ev.preventDefault();
        select(i + 1);
        tabs[(i + 1) % tabs.length].focus();
      } else if (ev.key === "ArrowLeft" || ev.key === "ArrowUp") {
        ev.preventDefault();
        select(i - 1);
        tabs[(i - 1 + tabs.length) % tabs.length].focus();
      } else if (ev.key === "Home") {
        ev.preventDefault();
        select(0);
        tabs[0].focus();
      } else if (ev.key === "End") {
        ev.preventDefault();
        select(tabs.length - 1);
        tabs[tabs.length - 1].focus();
      }
    });
  });

  select(0);
}

async function loadSeoDashboard(root) {
  const errEl = root.querySelector("#seo-dash-error");
  const chartsEl = root.querySelector("#seo-dash-charts");
  const updatedEl = root.querySelector("#seo-dash-updated");
  if (!chartsEl) return;

  errEl.hidden = true;
  errEl.textContent = "";

  try {
    const [ovJson, progJson, trendsJson] = await Promise.all([
      api.getSeoOverview(),
      api.getSeoProgress(),
      api.getSeoRunTrends(16),
    ]);

    const overview = ovJson.item || {};
    const summary = overview.summary || {};
    const byEntity = Array.isArray(overview.byEntityType) ? overview.byEntityType : [];
    const severityRows = Array.isArray(overview.severityBreakdown) ? overview.severityBreakdown : [];

    const openRecSum = severityRows.reduce((acc, r) => acc + Number(r.total || 0), 0);

    root.querySelector("#seo-kpi-total").textContent = String(summary.total ?? 0);
    root.querySelector("#seo-kpi-avg").textContent =
      summary.averageScore != null ? String(summary.averageScore) : "—";
    root.querySelector("#seo-kpi-blockers").textContent = String(summary.totalBlockers ?? 0);
    root.querySelector("#seo-kpi-open-rec").textContent = String(openRecSum);

    const entityBars = byEntity.map((r) => ({
      label: String(r.entity_type || "?"),
      value: Math.round(Number(r.avg_score || 0) * 100) / 100,
      color: "#535f78",
    }));
    const sevBars = severityRows.map((r) => ({
      label: String(r.severity || "?"),
      value: Number(r.total || 0),
      color: SEVERITY_COLORS[String(r.severity || "").toLowerCase()] || "#5f5e61",
    }));

    const progress = progJson.item || {};
    const recStatus = progress.recommendationStatus || {};
    const statusBars = ["open", "in_progress", "done", "dismissed"].map((k) => ({
      label: k.replace("_", " "),
      value: Number(recStatus[k] || 0),
      color: k === "open" ? "#9e3f4e" : k === "in_progress" ? "#b8860b" : k === "done" ? "#0f9d6a" : "#5f5e61",
    }));
    const evo = progress.scoreEvolution || {};
    const evoBars = [
      { label: "Améliorés", value: Number(evo.improved || 0), color: "#0f9d6a" },
      { label: "Stables", value: Number(evo.stable || 0), color: "#5f5e61" },
      { label: "Dégradés", value: Number(evo.degraded || 0), color: "#9e3f4e" },
    ];

    const runs = Array.isArray(trendsJson.items) ? trendsJson.items : [];
    const runsChrono = [...runs].reverse();
    const avgScores = runsChrono.map((run) => Number(run.stats?.averageScore ?? 0));
    const runLabels = runsChrono.map((run) => {
      const d = run.started_at ? String(run.started_at).slice(5, 16).replace("T", " ") : "#";
      return d;
    });

    const parts = [];
    if (entityBars.length) {
      parts.push(
        `<div class="seo-chart-card">${svgHorizontalBarChart(entityBars, {
          title: "Score moyen par type d’entité",
          max: 100,
          unit: " pts",
          ariaLabel: "Histogramme des scores moyens EEAT par type d’entité",
        })}</div>`
      );
    } else {
      parts.push(
        `<div class="seo-chart-card seo-chart-empty"><p class="seo-chart-empty-msg">Aucun score EEAT en base — lancez une analyse pour alimenter ce graphique.</p></div>`
      );
    }
    if (sevBars.length) {
      parts.push(
        `<div class="seo-chart-card">${svgHorizontalBarChart(sevBars, {
          title: "Recommandations ouvertes par sévérité",
          ariaLabel: "Nombre de recommandations SEO ouvertes par niveau de sévérité",
        })}</div>`
      );
    }
    parts.push(
      `<div class="seo-chart-card">${svgHorizontalBarChart(statusBars, {
        title: "Recommandations par statut",
        ariaLabel: "Répartition des recommandations par statut de traitement",
      })}</div>`
    );
    parts.push(
      `<div class="seo-chart-card">${svgHorizontalBarChart(evoBars, {
        title: "Évolution des scores (entités avec historique)",
        ariaLabel: "Nombre d’entités améliorées, stables ou dégradées depuis l’historique des scores",
      })}</div>`
    );
    if (avgScores.length > 1) {
      parts.push(
        `<div class="seo-chart-card">${svgLineChart(avgScores, {
          title: "Scores moyens des dernières analyses (runs)",
          labels: runLabels,
          ariaLabel: "Courbe du score moyen global par run d’analyse EEAT",
        })}</div>`
      );
    } else if (avgScores.length === 1) {
      parts.push(
        `<div class="seo-chart-card"><p class="seo-chart-empty-msg">Un seul run enregistré — la courbe s’affichera après plusieurs analyses.</p></div>`
      );
    } else {
      parts.push(
        `<div class="seo-chart-card seo-chart-empty"><p class="seo-chart-empty-msg">Aucun run d’analyse — aucune courbe de tendance.</p></div>`
      );
    }

    chartsEl.innerHTML = parts.join("");
    updatedEl.textContent = `Mis à jour : ${new Date().toLocaleString()}`;
  } catch (e) {
    errEl.hidden = false;
    errEl.textContent = `Impossible de charger le tableau de bord : ${e.message}`;
    chartsEl.innerHTML = `<div class="seo-chart-card seo-chart-empty"><p class="seo-chart-empty-msg">Vérifiez la connexion API et les droits SEO.</p></div>`;
  }
}

export async function renderSeoSettings(root) {
  const tabBar = renderTabsBarHtml({
    ariaLabel: "Sections SEO",
    tablistClass: "settings-tabs-bar",
    activeKey: "dashboard",
    tabs: [
      { key: "dashboard", label: "Tableau de bord", panelId: "seo-panel-dashboard", tabId: "seo-tab-dashboard" },
      { key: "site", label: "Site", panelId: "seo-panel-site", tabId: "seo-tab-site" },
      { key: "social", label: "Social", panelId: "seo-panel-social", tabId: "seo-tab-social" },
      { key: "schema", label: "Schema", panelId: "seo-panel-schema", tabId: "seo-tab-schema" },
      { key: "eeat", label: "EEAT", panelId: "seo-panel-eeat", tabId: "seo-tab-eeat" },
      { key: "advanced", label: "Avancé", panelId: "seo-panel-advanced", tabId: "seo-tab-advanced" },
    ],
  });

  root.innerHTML = `
    <div class="page-header"><h2>Réglages SEO</h2><p>Tableau de bord, métadonnées globales, social, schéma, indexation et EEAT</p></div>
    <section class="card settings-page-card admin-section-card seo-settings-card">
      ${tabBar}
      <div class="pad settings-tab-panels">
        <div class="seo-toolbar-bar">
          <button type="button" class="btn btn-primary" id="seo-save">Enregistrer tout</button>
          <span class="seo-toolbar-hint">Les champs de tous les onglets (sauf le tableau de bord) sont enregistrés ensemble.</span>
        </div>
        <div id="seo-panel-dashboard" role="tabpanel" aria-labelledby="seo-tab-dashboard">${panelDashboard()}</div>
        <div id="seo-panel-site" role="tabpanel" aria-labelledby="seo-tab-site" hidden>${panelSite()}</div>
        <div id="seo-panel-social" role="tabpanel" aria-labelledby="seo-tab-social" hidden>${panelSocial()}</div>
        <div id="seo-panel-schema" role="tabpanel" aria-labelledby="seo-tab-schema" hidden>${panelSchema()}</div>
        <div id="seo-panel-eeat" role="tabpanel" aria-labelledby="seo-tab-eeat" hidden>${panelEeat()}</div>
        <div id="seo-panel-advanced" role="tabpanel" aria-labelledby="seo-tab-advanced" hidden>${panelAdvanced()}</div>
      </div>
    </section>
  `;

  bindTabs(root, { tablistSelector: '[role="tablist"][aria-label="Sections SEO"]' });

  const refreshBtn = root.querySelector("#seo-dash-refresh");
  if (refreshBtn) {
    refreshBtn.addEventListener("click", () => {
      loadSeoDashboard(root).catch(() => {});
    });
  }

  loadSeoDashboard(root).catch(() => {});

  const out = await api.getSeoSettings();
  const item = out.item || {};
  const site = item.site || {};
  const social = item.social || {};
  const schema = item.schema || {};
  const eeat = item.eeat || {};
  const rules = item.indexationRules || [];

  root.querySelector("#seo-site-name").value = site.site_name || "";
  root.querySelector("#seo-title-template").value = site.title_template || "";
  root.querySelector("#seo-meta-template").value = site.meta_description_template || "";
  root.querySelector("#seo-canonical-base").value = site.canonical_base || "";
  root.querySelector("#seo-robots-default").value = site.robots_default || "index,follow";
  root.querySelector("#seo-og-site-name").value = social.og_site_name || "";
  root.querySelector("#seo-og-image").value = social.default_og_image_url || "";
  root.querySelector("#seo-twitter-site").value = social.twitter_site || "";
  root.querySelector("#seo-org-name").value = schema.organization_name || "";
  root.querySelector("#seo-org-url").value = schema.organization_url || "";
  root.querySelector("#seo-org-logo").value = schema.organization_logo_url || "";
  root.querySelector("#seo-search-template").value = schema.search_url_template || "";
  root.querySelector("#seo-default-author").value = eeat.default_author_name || "";
  root.querySelector("#seo-default-role").value = eeat.default_author_role || "";
  root.querySelector("#seo-trust-statement").value = eeat.trust_statement || "";
  root.querySelector("#seo-min-score").value = String(eeat.min_score_default || 60);
  root.querySelector("#seo-indexation-rules").value = safeJson(rules, "[]");

  root.querySelector("#seo-save").addEventListener("click", async () => {
    try {
      const payload = {
        site: {
          site_name: root.querySelector("#seo-site-name").value.trim(),
          title_template: root.querySelector("#seo-title-template").value.trim(),
          meta_description_template: root.querySelector("#seo-meta-template").value.trim(),
          canonical_base: root.querySelector("#seo-canonical-base").value.trim(),
          robots_default: root.querySelector("#seo-robots-default").value,
        },
        social: {
          og_site_name: root.querySelector("#seo-og-site-name").value.trim(),
          default_og_image_url: root.querySelector("#seo-og-image").value.trim(),
          twitter_site: root.querySelector("#seo-twitter-site").value.trim(),
        },
        schema: {
          organization_name: root.querySelector("#seo-org-name").value.trim(),
          organization_url: root.querySelector("#seo-org-url").value.trim(),
          organization_logo_url: root.querySelector("#seo-org-logo").value.trim(),
          search_url_template: root.querySelector("#seo-search-template").value.trim(),
        },
        eeat: {
          default_author_name: root.querySelector("#seo-default-author").value.trim(),
          default_author_role: root.querySelector("#seo-default-role").value.trim(),
          trust_statement: root.querySelector("#seo-trust-statement").value.trim(),
          min_score_default: Number(root.querySelector("#seo-min-score").value || 60),
        },
        indexationRules: JSON.parse(root.querySelector("#seo-indexation-rules").value.trim() || "[]"),
      };
      await api.saveSeoSettings(payload);
      showToast("Réglages SEO enregistrés");
    } catch (e) {
      showToast(`Erreur sauvegarde SEO: ${e.message}`);
    }
  });

  root.querySelector("#seo-preview").addEventListener("click", async () => {
    try {
      const preview = await api.previewSeoSettings("product");
      root.querySelector("#seo-preview-output").value = safeJson(preview.item || {});
    } catch (e) {
      showToast(`Erreur prévisualisation: ${e.message}`);
    }
  });

  root.querySelector("#seo-coverage").addEventListener("click", async () => {
    try {
      const report = await api.getSeoCoverageReport();
      root.querySelector("#seo-preview-output").value = safeJson(report.item || {});
    } catch (e) {
      showToast(`Erreur rapport: ${e.message}`);
    }
  });
}
