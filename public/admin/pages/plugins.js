import { api } from "../services/api.js";
import { showToast } from "../components/shell.js";
import { escapeHtml, pluginDetailModalMarkup, pluginHighlightCard, skeletonRow } from "../components/ui.js";

function renderRows(items) {
  return items.map((item) => pluginHighlightCard(item)).join("");
}

const CONFIG_FIELDS_BY_PLUGIN_KEY = {
  stripe: [
    { key: "stripeSecretKey", label: "Stripe Secret Key", type: "password", sensitive: true },
    { key: "stripeWebhookSecret", label: "Stripe Webhook Secret", type: "password", sensitive: true },
    { key: "stripePublishableKey", label: "Stripe Publishable Key", type: "text" },
  ],
  paypal: [
    { key: "paypalClientId", label: "PayPal Client ID", type: "text" },
    { key: "paypalClientSecret", label: "PayPal Client Secret", type: "password", sensitive: true },
  ],
  boxtal: [
    { key: "apiKey", label: "Boxtal API Key", type: "password", sensitive: true },
    { key: "apiSecret", label: "Boxtal API Secret", type: "password", sensitive: true },
    { key: "accountId", label: "Account ID", type: "text" },
  ],
  sendcloud: [
    { key: "apiKey", label: "Sendcloud API Key", type: "password", sensitive: true },
    { key: "apiSecret", label: "Sendcloud API Secret", type: "password", sensitive: true },
  ],
  colissimo: [
    { key: "apiKey", label: "Colissimo API Key", type: "password", sensitive: true },
    { key: "accountId", label: "Account ID", type: "text" },
  ],
  mondial_relay: [
    { key: "apiKey", label: "Mondial Relay API Key", type: "password", sensitive: true },
    { key: "accountId", label: "Account ID", type: "text" },
  ],
  relais_colis: [
    { key: "apiKey", label: "Relais Colis API Key", type: "password", sensitive: true },
    { key: "accountId", label: "Account ID", type: "text" },
  ],
  chronopost: [
    { key: "apiKey", label: "Chronopost API Key", type: "password", sensitive: true },
    { key: "accountId", label: "Account ID", type: "text" },
  ],
  tnt_fedex: [
    { key: "apiKey", label: "FedEx API Key", type: "password", sensitive: true },
    { key: "accountId", label: "Account ID", type: "text" },
  ],
  dhl_express: [
    { key: "apiKey", label: "DHL Express API Key", type: "password", sensitive: true },
    { key: "accountId", label: "Account ID", type: "text" },
  ],
};

export async function renderPlugins(root) {
  root.innerHTML = `
    <div class="page-header"><h2>Plugins</h2><p>Extensions paiement et livraison — détails, documentation et activation</p></div>
    ${pluginDetailModalMarkup()}
    <section class="card admin-section-card">
      <div class="card-head"><strong>Catalogue des plugins</strong><div></div></div>
      <div class="pad plugin-grid">
        ${skeletonRow()}
        ${skeletonRow()}
        ${skeletonRow()}
      </div>
    </section>
  `;

  const dialog = root.querySelector("#plugin-detail-dialog");
  let items = [];
  try {
    const list = await api.listPlugins();
    items = Array.isArray(list.items) ? list.items : [];
  } catch (e) {
    root.querySelector(".plugin-grid").innerHTML = `<div class="inline-alert">Impossible de charger les plugins: ${escapeHtml(
      e.message || String(e)
    )}</div>`;
    return;
  }
  root.querySelector(".plugin-grid").innerHTML =
    items.length > 0 ? renderRows(items) : `<div class="inline-alert">Aucun plugin disponible.</div>`;

  function findPlugin(key) {
    return items.find((p) => p.plugin_key === key);
  }

  function prettifyConfigKey(key) {
    return String(key)
      .replace(/_/g, " ")
      .replace(/([a-z])([A-Z])/g, "$1 $2")
      .replace(/\s+/g, " ")
      .trim();
  }

  function inferConfigFieldType(key) {
    const lower = String(key || "").toLowerCase();
    // Heuristique : secrets / tokens / credentials doivent être saisis en mode "password".
    if (
      lower.includes("secret") ||
      lower.includes("token") ||
      lower.includes("credential") ||
      lower.includes("password") ||
      lower.includes("private")
    ) {
      return "password";
    }
    // Cas des "apiKey" / "api_key" : sensible sauf si "publishable/public".
    if (lower.includes("api") && lower.includes("key")) {
      if (lower.includes("publishable") || lower.includes("public")) return "text";
      return "password";
    }
    return "text";
  }

  function getFieldsForPlugin(p) {
    const cfg = p?.config || {};
    const mapped = CONFIG_FIELDS_BY_PLUGIN_KEY[p?.plugin_key] || [];
    const mappedKeys = new Set(mapped.map((f) => f.key));
    const excluded = new Set(["publicLabel", "priority"]);

    const inferred = Object.keys(cfg)
      .filter((k) => !excluded.has(k) && !mappedKeys.has(k))
      .map((k) => ({
        key: k,
        label: prettifyConfigKey(k),
        type: inferConfigFieldType(k),
      }));

    return [...mapped, ...inferred];
  }

  function renderTokenFields(p) {
    if (!dialog) return;
    const container = dialog.querySelector("[data-plugin-modal-token-fields]");
    if (!container) return;

    const fields = getFieldsForPlugin(p);
    const cfg = p.config || {};

    if (!fields.length) {
      container.innerHTML = `<p class="admin-muted">Aucun champ de token prévu pour ce plugin.</p>`;
      return;
    }

    container.innerHTML = fields
      .map((field) => {
        const existing = cfg[field.key];
        const isPassword = field.type === "password";
        const showExistingSecretNote = isPassword && existing !== undefined && existing !== null && String(existing).trim() !== "";
        const value = isPassword ? "" : String(existing ?? "");

        return `
          <div class="plugin-token-field">
            <label class="muted plugin-token-label">${escapeHtml(field.label)}</label>
            <input
              class="input"
              type="${isPassword ? "password" : "text"}"
              data-token-input="${escapeHtml(field.key)}"
              value="${escapeHtml(value)}"
              placeholder="${isPassword ? "" : ""}"
            >
            ${
              showExistingSecretNote
                ? `<div class="muted plugin-token-note">Secret déjà enregistré — laisser vide pour conserver.</div>`
                : ""
            }
          </div>
        `;
      })
      .join("");
  }

  function fillDialog(p) {
    if (!dialog || !p) return;
    dialog.dataset.pluginKey = p.plugin_key;
    const cfg = p.config || {};
    dialog.querySelector("[data-plugin-modal-title]").textContent = p.label || p.plugin_key;
    dialog.querySelector("[data-plugin-modal-category]").textContent = p.category_label || p.plugin_type || "";
    const body = (p.description_long || p.description || "").trim() || "Aucune description détaillée.";
    dialog.querySelector("[data-plugin-modal-body]").textContent = body;
    const docs = dialog.querySelector("[data-plugin-modal-docs]");
    const docsWrap = dialog.querySelector("[data-plugin-modal-docs-wrap]");
    if (p.docs_url) {
      docs.href = p.docs_url;
      docsWrap.style.display = "";
    } else {
      docs.removeAttribute("href");
      docsWrap.style.display = "none";
    }
    dialog.querySelector('[data-field="mode"]').value = p.mode === "live" ? "live" : "sandbox";
    dialog.querySelector('[data-field="priority"]').value = String(Number(cfg.priority || p.sort_order || 100));
    dialog.querySelector('[data-field="publicLabel"]').value = String(cfg.publicLabel || p.label || "");
    renderTokenFields(p);
    const toggleBtn = dialog.querySelector("[data-plugin-modal-toggle]");
    if (p.is_enabled) {
      toggleBtn.textContent = "Désactiver";
      toggleBtn.classList.remove("btn-primary");
    } else {
      toggleBtn.textContent = "Activer";
      toggleBtn.classList.add("btn-primary");
    }
  }

  root.querySelectorAll("[data-plugin-open-modal]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const key = btn.getAttribute("data-plugin-open-modal");
      const p = findPlugin(key);
      if (!p) return;
      fillDialog(p);
      dialog.showModal();
    });
  });

  dialog.querySelector("[data-close-plugin-dialog]")?.addEventListener("click", () => dialog.close());

  dialog.querySelector("[data-plugin-modal-toggle]")?.addEventListener("click", async () => {
    const key = dialog.dataset.pluginKey;
    const p = findPlugin(key);
    if (!p) return;
    const next = !p.is_enabled;
    try {
      await api.togglePlugin({ pluginKey: key, enabled: next });
      showToast(`${key} ${next ? "activé" : "désactivé"}`);
      dialog.close();
      await renderPlugins(root);
    } catch (e) {
      showToast(`Erreur plugin: ${e.message}`);
    }
  });

  dialog.querySelector("[data-plugin-modal-save]")?.addEventListener("click", async () => {
    const key = dialog.dataset.pluginKey;
    const p = findPlugin(key);
    if (!p) return;
    const mode = dialog.querySelector('[data-field="mode"]').value;
    const priority = Number(dialog.querySelector('[data-field="priority"]').value || 100);
    const publicLabel = dialog.querySelector('[data-field="publicLabel"]').value.trim();
    try {
      const fields = getFieldsForPlugin(p);
      const existingConfig = p.config || {};
      const nextConfig = { ...existingConfig };
      delete nextConfig.publicLabel;
      delete nextConfig.priority;

      for (const field of fields) {
        const input = dialog.querySelector(`[data-token-input="${field.key}"]`);
        const value = input ? String(input.value ?? "") : "";
        if (field.type === "password") {
          if (value.trim() === "") {
            // Secret laissé vide => on conserve la valeur existante si elle est déjà en base.
            continue;
          }
          nextConfig[field.key] = value;
          continue;
        }

        // Texte: valeur vide => écrase et permet de nettoyer.
        nextConfig[field.key] = value;
      }

      await api.updatePluginConfig({
        pluginKey: key,
        mode,
        priority,
        publicLabel,
        config: nextConfig,
      });
      showToast(`Configuration ${key} enregistrée`);
      await renderPlugins(root);
    } catch (e) {
      showToast(`Erreur configuration: ${e.message}`);
    }
  });
}
