import { api } from "../services/api.js";
import { showToast } from "../components/shell.js";
import { editableRow, stateToggle } from "../components/ui.js";
import { bindTabs, renderTabsBarHtml } from "../components/tabs.js";

function settingsFormBlock(fieldsHtml, actionHtml) {
  return `
    <div class="settings-form-block">
      <div class="settings-form-fields">${fieldsHtml}</div>
      <div class="settings-form-actions">${actionHtml}</div>
    </div>
  `;
}

function settingsTableSection(tableHtml) {
  return `<div class="settings-table-section">${tableHtml}</div>`;
}

export async function renderSettings(root) {
  const tabBar = renderTabsBarHtml({
    ariaLabel: "Familles de paramètres",
    tablistClass: "settings-tabs-bar",
    activeKey: "coupons",
    tabs: [
      { key: "coupons", label: "Coupons", panelId: "settings-panel-coupons" },
      { key: "taxes", label: "Taxes & TVA", panelId: "settings-panel-taxes" },
      { key: "shipping", label: "Transporteurs", panelId: "settings-panel-shipping" },
      { key: "payments", label: "Paiement", panelId: "settings-panel-payments" },
      { key: "plugins", label: "Plugins", panelId: "settings-panel-plugins" },
      { key: "tools", label: "Outils admin", panelId: "settings-panel-tools" },
    ],
  });

  root.innerHTML = `
    <div class="page-header">
      <h2>Paramètres</h2>
      <p>Coupons, taxes, transporteurs, paiements, plugins et outils</p>
    </div>
    <section class="card settings-page-card admin-section-card">
      ${tabBar}
      <div class="pad settings-tab-panels">
        <div class="settings-tab-panel" role="tabpanel" data-settings-panel="coupons" id="settings-panel-coupons">
          ${settingsFormBlock(
            `
            <input class="input" id="coupon-code" placeholder="Code (ex: SPRING10)" autocomplete="off">
            <select class="select" id="coupon-type" aria-label="Type de remise">
              <option value="percent">percent</option>
              <option value="fixed">fixed</option>
            </select>
            <input class="input" id="coupon-value" type="number" placeholder="Valeur" step="any">
            `,
            `<button type="button" class="btn btn-primary" id="coupon-save">Enregistrer le coupon</button>`
          )}
        </div>
        <div class="settings-tab-panel" role="tabpanel" data-settings-panel="taxes" id="settings-panel-taxes" hidden>
          ${settingsFormBlock(
            `
            <input class="input" id="tax-country" placeholder="Pays (FR)">
            <input class="input" id="tax-region" placeholder="Région (optionnel)">
            <input class="input" id="tax-type" value="vat" placeholder="Type">
            <input class="input" id="tax-rate" type="number" step="0.001" placeholder="Taux %">
            `,
            `<button type="button" class="btn btn-primary" id="tax-save">Ajouter ou mettre à jour</button>`
          )}
          ${settingsTableSection(`
            <div class="table-wrap">
              <table>
                <thead><tr><th>Pays</th><th>Région</th><th>Type</th><th>Taux</th><th>Actif</th></tr></thead>
                <tbody id="tax-body"></tbody>
              </table>
            </div>
          `)}
        </div>
        <div class="settings-tab-panel" role="tabpanel" data-settings-panel="shipping" id="settings-panel-shipping" hidden>
          ${settingsFormBlock(
            `
            <input class="input" id="ship-key" placeholder="carrier_key">
            <input class="input" id="ship-label" placeholder="Libellé">
            <input class="input" id="ship-zones" placeholder="Zones CSV (FR, EU, INTL)">
            <input class="input" id="ship-priority" type="number" placeholder="Priorité">
            `,
            `<button type="button" class="btn btn-primary" id="ship-save">Ajouter ou mettre à jour</button>`
          )}
          ${settingsTableSection(`
            <div class="table-wrap">
              <table>
                <thead><tr><th>Transporteur</th><th>Libellé</th><th>Zones</th><th>Priorité</th><th>Actif</th></tr></thead>
                <tbody id="ship-body"></tbody>
              </table>
            </div>
          `)}
        </div>
        <div class="settings-tab-panel" role="tabpanel" data-settings-panel="payments" id="settings-panel-payments" hidden>
          ${settingsFormBlock(
            `
            <input class="input" id="pay-key" placeholder="method_key">
            <input class="input" id="pay-label" placeholder="Libellé">
            <input class="input" id="pay-provider" placeholder="Fournisseur (stripe / paypal)">
            <select class="select" id="pay-mode" aria-label="Environnement">
              <option value="sandbox">sandbox</option>
              <option value="live">live</option>
            </select>
            <input class="input" id="pay-priority" type="number" placeholder="Priorité">
            `,
            `<button type="button" class="btn btn-primary" id="pay-save">Ajouter ou mettre à jour</button>`
          )}
          ${settingsTableSection(`
            <div class="table-wrap">
              <table>
                <thead><tr><th>Méthode</th><th>Libellé</th><th>Fournisseur</th><th>Mode</th><th>Actif</th></tr></thead>
                <tbody id="pay-body"></tbody>
              </table>
            </div>
          `)}
        </div>
        <div class="settings-tab-panel" role="tabpanel" data-settings-panel="plugins" id="settings-panel-plugins" hidden>
          <p class="settings-panel-intro">
            Gestion avancée Stripe, PayPal, Boxtal et autres extensions depuis la page Plugins.
          </p>
          <div class="settings-form-actions">
            <button type="button" class="btn btn-primary" id="open-plugins">Ouvrir Plugins</button>
          </div>
        </div>
        <div class="settings-tab-panel" role="tabpanel" data-settings-panel="tools" id="settings-panel-tools" hidden>
          <div class="settings-form-actions">
            <button type="button" class="btn btn-primary" id="diag-api">Tester la connectivité API</button>
            <button type="button" class="btn" id="refresh-log">Rafraîchir le journal UI</button>
          </div>
          <p class="settings-status-line" id="admin-tools-status"></p>
          ${settingsTableSection(`
            <div class="table-wrap">
              <table>
                <thead><tr><th>Horodatage</th><th>Endpoint</th><th>Statut</th><th>Message</th></tr></thead>
                <tbody id="api-log-body"></tbody>
              </table>
            </div>
          `)}
        </div>
      </div>
    </section>
  `;
  bindTabs(root, { tablistSelector: '[role="tablist"][aria-label="Familles de paramètres"]' });

  async function loadSettingsData() {
    const [taxesOut, shippingOut, paymentsOut] = await Promise.all([
      api.listTaxRules(),
      api.listShippingCarriers(),
      api.listPaymentMethods(),
    ]);
    const taxes = Array.isArray(taxesOut.items) ? taxesOut.items : [];
    const carriers = Array.isArray(shippingOut.items) ? shippingOut.items : [];
    const methods = Array.isArray(paymentsOut.items) ? paymentsOut.items : [];

    root.querySelector("#tax-body").innerHTML =
      taxes
        .map((t) =>
          editableRow([
            t.country_code || "-",
            t.region_code || "-",
            t.tax_type || "-",
            `${Number(t.rate || 0).toFixed(3)}%`,
            stateToggle({ checked: Boolean(t.is_enabled), id: t.id, action: "tax" }),
          ])
        )
        .join("") || `<tr><td colspan="5">Aucune règle</td></tr>`;

    root.querySelector("#ship-body").innerHTML =
      carriers
        .map((c) =>
          editableRow([
            c.carrier_key || "-",
            c.label || "-",
            Array.isArray(c.zones) ? c.zones.join(", ") : "-",
            String(c.priority || 100),
            stateToggle({ checked: Boolean(c.is_enabled), id: c.id, action: "ship" }),
          ])
        )
        .join("") || `<tr><td colspan="5">Aucun transporteur</td></tr>`;

    root.querySelector("#pay-body").innerHTML =
      methods
        .map((m) =>
          editableRow([
            m.method_key || "-",
            m.label || "-",
            m.provider || "-",
            m.mode || "sandbox",
            stateToggle({ checked: Boolean(m.is_enabled), id: m.id, action: "pay" }),
          ])
        )
        .join("") || `<tr><td colspan="5">Aucune méthode</td></tr>`;

    root.querySelectorAll("[data-toggle-action]").forEach((input) => {
      input.addEventListener("change", async () => {
        const action = input.getAttribute("data-toggle-action");
        const id = Number(input.getAttribute("data-toggle-id") || 0);
        const enabled = Boolean(input.checked);
        try {
          if (action === "tax") await api.toggleTaxRule({ id, enabled });
          if (action === "ship") await api.toggleShippingCarrier({ id, enabled });
          if (action === "pay") await api.togglePaymentMethod({ id, enabled });
          showToast("État mis à jour");
        } catch (e) {
          input.checked = !enabled;
          showToast(`Erreur toggle: ${e.message}`);
        }
      });
    });
  }

  root.querySelector("#coupon-save").addEventListener("click", async () => {
    try {
      await api.coupons({
        code: root.querySelector("#coupon-code").value.trim(),
        type: root.querySelector("#coupon-type").value,
        value: Number(root.querySelector("#coupon-value").value || 0),
      });
      showToast("Coupon enregistré");
    } catch (e) {
      showToast(`Erreur coupon: ${e.message}`);
    }
  });

  root.querySelector("#tax-save").addEventListener("click", async () => {
    try {
      await api.upsertTaxRule({
        countryCode: root.querySelector("#tax-country").value.trim(),
        regionCode: root.querySelector("#tax-region").value.trim() || null,
        taxType: root.querySelector("#tax-type").value.trim() || "vat",
        rate: Number(root.querySelector("#tax-rate").value || 0),
        isDefault: false,
        metadata: {},
      });
      showToast("Règle de taxe enregistrée");
      await loadSettingsData();
    } catch (e) {
      showToast(`Erreur taxes: ${e.message}`);
    }
  });

  root.querySelector("#ship-save").addEventListener("click", async () => {
    try {
      await api.upsertShippingCarrier({
        carrierKey: root.querySelector("#ship-key").value.trim(),
        label: root.querySelector("#ship-label").value.trim(),
        zones: root.querySelector("#ship-zones").value.split(",").map((z) => z.trim()).filter(Boolean),
        priority: Number(root.querySelector("#ship-priority").value || 100),
        metadata: {},
      });
      showToast("Transporteur enregistré");
      await loadSettingsData();
    } catch (e) {
      showToast(`Erreur transporteur: ${e.message}`);
    }
  });

  root.querySelector("#pay-save").addEventListener("click", async () => {
    try {
      await api.upsertPaymentMethod({
        methodKey: root.querySelector("#pay-key").value.trim(),
        label: root.querySelector("#pay-label").value.trim(),
        provider: root.querySelector("#pay-provider").value.trim(),
        mode: root.querySelector("#pay-mode").value,
        priority: Number(root.querySelector("#pay-priority").value || 100),
        metadata: {},
      });
      showToast("Méthode de paiement enregistrée");
      await loadSettingsData();
    } catch (e) {
      showToast(`Erreur paiement: ${e.message}`);
    }
  });

  root.querySelector("#open-plugins").addEventListener("click", () => {
    location.hash = "#plugins";
  });

  function renderApiLog() {
    const logs = api.getApiCallLog();
    root.querySelector("#api-log-body").innerHTML =
      logs
        .slice(0, 20)
        .map(
          (l) => `<tr>
            <td>${l.at || "-"}</td>
            <td>${l.path || "-"}</td>
            <td>${l.ok ? "ok" : "error"} (${l.status || "-"})</td>
            <td>${l.message || "-"}</td>
          </tr>`
        )
        .join("") || `<tr><td colspan="4">Aucune entrée.</td></tr>`;
  }

  root.querySelector("#diag-api").addEventListener("click", async () => {
    const status = root.querySelector("#admin-tools-status");
    status.textContent = "Test API en cours…";
    try {
      await api.searchOrders({ limit: 1, offset: 0 });
      status.textContent = "API admin joignable.";
      showToast("Diagnostic API : OK");
    } catch (e) {
      status.textContent = `Diagnostic en échec : ${e.message}`;
    }
    renderApiLog();
  });
  root.querySelector("#refresh-log").addEventListener("click", () => {
    renderApiLog();
  });

  await loadSettingsData();
  renderApiLog();
}
