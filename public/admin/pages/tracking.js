import { api } from "../services/api.js";
import { showToast } from "../components/shell.js";
import { bindTabs, renderTabsBarHtml } from "../components/tabs.js";
import { settingsSectionCard, inlineFormBlock } from "../components/ui.js";

const TOOL_DEFS = [
  {
    key: "gtm",
    title: "Google Tag Manager",
    tabLabel: "GTM",
    category: "analytics",
    hint: "Container ID (GTM-XXXX). Les balises configurées dans GTM se chargent après consentement analytique.",
    fields: [{ name: "containerId", label: "Container ID", placeholder: "GTM-XXXXXXX" }],
  },
  {
    key: "ga4",
    title: "Google Analytics 4",
    tabLabel: "GA4",
    category: "analytics",
    hint: "ID de mesure (G-XXXXXXXX). À utiliser seul ou en complément de GTM.",
    fields: [{ name: "measurementId", label: "ID de mesure", placeholder: "G-XXXXXXXXXX" }],
  },
  {
    key: "clarity",
    title: "Microsoft Clarity",
    tabLabel: "Clarity",
    category: "analytics",
    hint: "Identifiant du projet dans Clarity.",
    fields: [{ name: "projectId", label: "ID projet", placeholder: "xxxxxxxx" }],
  },
  {
    key: "hotjar",
    title: "Hotjar",
    tabLabel: "Hotjar",
    category: "analytics",
    hint: "ID de site Hotjar (numérique).",
    fields: [{ name: "siteId", label: "ID site", placeholder: "1234567" }],
  },
  {
    key: "plausible",
    title: "Plausible",
    tabLabel: "Plausible",
    category: "analytics",
    hint: "Domaine mesuré ; précisez l’hôte si instance self-hosted.",
    fields: [
      { name: "domain", label: "Domaine", placeholder: "boutique.example.com" },
      { name: "apiHost", label: "Hôte API (optionnel)", placeholder: "plausible.io" },
    ],
  },
  {
    key: "meta",
    title: "Meta (Facebook) Pixel",
    tabLabel: "Meta",
    category: "marketing",
    hint: "ID du pixel (chiffres). Nécessite le consentement marketing.",
    fields: [{ name: "pixelId", label: "ID pixel", placeholder: "123456789012345" }],
  },
  {
    key: "tiktok",
    title: "TikTok Pixel",
    tabLabel: "TikTok",
    category: "marketing",
    hint: "Identifiant du pixel TikTok.",
    fields: [{ name: "pixelId", label: "ID pixel", placeholder: "XXXXXXXX" }],
  },
  {
    key: "linkedin",
    title: "LinkedIn Insight Tag",
    tabLabel: "LinkedIn",
    category: "marketing",
    hint: "Partner ID fourni par LinkedIn.",
    fields: [{ name: "partnerId", label: "Partner ID", placeholder: "123456" }],
  },
  {
    key: "googleAds",
    title: "Google Ads (conversion)",
    tabLabel: "Google Ads",
    category: "marketing",
    hint: "ID de conversion AW-… ; champ send_to optionnel pour une balise précise.",
    fields: [
      { name: "conversionId", label: "ID conversion", placeholder: "AW-123456789" },
      { name: "sendTo", label: "send_to (optionnel)", placeholder: "AW-xxx/yyy" },
    ],
  },
];

function toolPanel(def, index) {
  const inputs = def.fields
    .map(
      (f) => `
      <label class="tracking-field">
        <span class="tracking-field-label">${f.label}</span>
        <input class="input tracking-input" data-tool="${def.key}" data-field="${f.name}" placeholder="${f.placeholder}" autocomplete="off">
      </label>`
    )
    .join("");

  return `
    <div
      class="settings-tab-panel tracking-tool-panel"
      role="tabpanel"
      id="tracking-panel-${def.key}"
      aria-labelledby="tracking-tab-${def.key}"
      ${index === 0 ? "" : "hidden"}
    >
      <div class="settings-form-block">
        <div class="tracking-panel-head">
          <div>
            <h3 class="tracking-panel-title">${def.title}</h3>
            <span class="tracking-panel-badge tracking-panel-badge--${def.category}">${def.category === "marketing" ? "Marketing" : "Analytique"}</span>
          </div>
          <label class="tracking-enable">
            <input type="checkbox" data-tool="${def.key}" data-field="enabled">
            <span>Activer cet outil</span>
          </label>
        </div>
        <p class="tracking-hint">${def.hint}</p>
        <div class="tracking-fields">${inputs}</div>
        <label class="tracking-field tracking-field--inline">
          <span class="tracking-field-label">Catégorie de consentement (RGPD)</span>
          <select class="select tracking-select" data-tool="${def.key}" data-field="consentCategory">
            <option value="analytics">Analytique</option>
            <option value="marketing">Marketing</option>
          </select>
        </label>
      </div>
    </div>
  `;
}

function integrationsCardHtml(panelsHtml, tabsHtml) {
  return `
    <section class="card settings-page-card admin-section-card">
      <div class="card-head">
        <strong>Intégrations</strong>
        <div></div>
      </div>
      ${tabsHtml}
      <div class="pad settings-tab-panels">
        ${panelsHtml}
      </div>
      <div class="pad card-footer-actions">
        ${inlineFormBlock(
          `<button type="button" class="btn btn-primary" id="tracking-save">Enregistrer toute la configuration</button> <span class="tracking-status" id="tracking-status"></span>`
        )}
      </div>
    </section>
  `;
}

function applyIntegrations(root, integrations) {
  const tools = integrations?.tools || {};
  TOOL_DEFS.forEach((def) => {
    const t = tools[def.key] || {};
    const en = root.querySelector(`[data-tool="${def.key}"][data-field="enabled"]`);
    if (en) en.checked = Boolean(t.enabled);
    const cat = root.querySelector(`[data-tool="${def.key}"][data-field="consentCategory"]`);
    if (cat) {
      const c = t.consentCategory;
      cat.value = c === "marketing" || c === "analytics" ? c : def.category;
    }
    def.fields.forEach((f) => {
      const el = root.querySelector(`[data-tool="${def.key}"][data-field="${f.name}"]`);
      if (el) el.value = String(t[f.name] ?? "");
    });
  });
}

function collectIntegrations(root) {
  const tools = {};
  TOOL_DEFS.forEach((def) => {
    const en = root.querySelector(`[data-tool="${def.key}"][data-field="enabled"]`);
    const cat = root.querySelector(`[data-tool="${def.key}"][data-field="consentCategory"]`);
    const row = {
      enabled: Boolean(en?.checked),
      consentCategory: cat?.value === "marketing" ? "marketing" : "analytics",
    };
    def.fields.forEach((f) => {
      const el = root.querySelector(`[data-tool="${def.key}"][data-field="${f.name}"]`);
      row[f.name] = el ? String(el.value || "").trim() : "";
    });
    tools[def.key] = row;
  });
  return { version: 1, tools };
}

export async function renderTracking(root) {
  const panelsHtml = TOOL_DEFS.map((d, i) => toolPanel(d, i)).join("");
  const tabsHtml = renderTabsBarHtml({
    ariaLabel: "Outils de tracking",
    tablistClass: "settings-tabs-bar",
    activeKey: TOOL_DEFS[0]?.key || "",
    tabs: TOOL_DEFS.map((def) => ({
      key: def.key,
      label: def.tabLabel,
      panelId: `tracking-panel-${def.key}`,
      tabId: `tracking-tab-${def.key}`,
      tabClassName: `settings-tab--${def.category}`,
    })),
  });

  root.innerHTML = `
    <div class="page-header">
      <h2>Tracking &amp; consentement</h2>
      <p>Identifiants publics des outils d&apos;analyse et de pub. Les scripts ne sont chargés sur la boutique qu&apos;après consentement (CMP). Aucun secret serveur ici.</p>
    </div>
    ${settingsSectionCard(
      "RGPD / CMP",
      `<p class="tracking-intro">
        Les visiteurs choisissent analytique et marketing via la bannière cookies du site vitrine.
        Les outils classés <strong>Analytique</strong> respectent le toggle analytique ; <strong>Marketing</strong> le toggle marketing.
        Documentez les finalités dans votre politique de confidentialité et politique cookies.
      </p>`,
      ""
    )}
    ${integrationsCardHtml(panelsHtml, tabsHtml)}
  `;
  bindTabs(root, { tablistSelector: '[role="tablist"][aria-label="Outils de tracking"]' });

  const statusEl = root.querySelector("#tracking-status");

  try {
    const out = await api.getTrackingSettings();
    const integrations = out.integrations || out;
    applyIntegrations(root, integrations);
    statusEl.textContent = "";
    statusEl.classList.remove("tracking-status--error");
  } catch (e) {
    statusEl.textContent = `Chargement impossible : ${e.message}`;
    statusEl.classList.add("tracking-status--error");
    showToast(`Tracking: ${e.message}`);
  }

  root.querySelector("#tracking-save")?.addEventListener("click", async () => {
    statusEl.textContent = "Enregistrement…";
    statusEl.classList.remove("tracking-status--error", "tracking-status--ok");
    try {
      const integrations = collectIntegrations(root);
      await api.saveTrackingSettings({ integrations });
      statusEl.textContent = "Enregistré.";
      statusEl.classList.add("tracking-status--ok");
      showToast("Configuration tracking enregistrée");
    } catch (e) {
      statusEl.textContent = `Erreur : ${e.message}`;
      statusEl.classList.add("tracking-status--error");
      showToast(e.message || "Erreur sauvegarde");
    }
  });
}
