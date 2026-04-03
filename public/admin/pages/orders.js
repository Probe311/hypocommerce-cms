import { api } from "../services/api.js";
import { badge } from "../components/ui.js";
import { showToast } from "../components/shell.js";

export async function renderOrders(root) {
  root.innerHTML = `
    <div class="page-header"><h2>Commandes</h2><p>Recherche, statuts, pilotage fulfilment</p></div>
    <div class="filters">
      <label class="sr-only" for="orders-q">Recherche numéro ou e-mail</label>
      <input class="input" id="orders-q" placeholder="Recherche numéro/email" autocomplete="off">
      <label class="sr-only" for="orders-status">Filtrer par statut</label>
      <select class="select" id="orders-status" aria-label="Statut commande">
        <option value="">Tous statuts</option>
        <option value="pending">pending</option>
        <option value="paid">paid</option>
        <option value="fulfilled">fulfilled</option>
        <option value="cancelled">cancelled</option>
      </select>
      <button type="button" class="btn" id="orders-refresh">Filtrer</button>
    </div>
    <section class="card pad orders-advanced" aria-labelledby="orders-adv-heading">
      <h2 id="orders-adv-heading" class="orders-adv-title">Actions avancées</h2>
      <p class="orders-adv-intro" id="orders-adv-desc">Saisissez l’UUID de la commande concernée, puis utilisez les actions ci-dessous.</p>
      <div class="orders-target-row">
        <label class="orders-label" for="action-order-id">UUID de la commande</label>
        <input class="input orders-target-input" id="action-order-id" type="text" autocomplete="off" spellcheck="false" aria-describedby="orders-adv-desc" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
      </div>
      <fieldset class="orders-fieldset">
        <legend>Statut de la commande</legend>
        <div class="orders-field-row">
          <label class="orders-label" for="action-order-status">Nouveau statut</label>
          <select class="select" id="action-order-status">
            <option value="pending">pending</option>
            <option value="authorized">authorized</option>
            <option value="paid">paid</option>
            <option value="fulfilled">fulfilled</option>
            <option value="cancelled">cancelled</option>
            <option value="refunded">refunded</option>
          </select>
          <button type="button" class="btn btn-primary" id="order-status-save">Mettre à jour le statut</button>
        </div>
      </fieldset>
      <details class="orders-details">
        <summary>Expédition (transporteur et suivi)</summary>
        <div class="orders-details-body">
          <div class="orders-field-row orders-field-stack">
            <label class="orders-label" for="shipment-carrier">Transporteur</label>
            <input class="input" id="shipment-carrier" placeholder="ex. boxtal" autocomplete="off">
            <label class="orders-label" for="shipment-tracking">Numéro de suivi</label>
            <input class="input" id="shipment-tracking" placeholder="Numéro de tracking" autocomplete="off">
            <button type="button" class="btn" id="order-shipment-save">Mettre à jour l’expédition</button>
          </div>
        </div>
      </details>
      <details class="orders-details">
        <summary>Fractionnement d’envois (JSON)</summary>
        <div class="orders-details-body">
          <p class="orders-help" id="split-shipments-help">Tableau JSON des envois : chaque entrée peut contenir <code>warehouse</code> et <code>items</code> (<code>orderItemId</code>, <code>quantity</code>).</p>
          <pre class="orders-json-sample" id="split-shipments-sample" aria-hidden="true">[{"warehouse":"WH1","items":[{"orderItemId":1,"quantity":1}]}]</pre>
          <label class="orders-label" for="split-shipments">JSON des envois</label>
          <textarea class="input orders-textarea" id="split-shipments" aria-describedby="split-shipments-help" rows="4" placeholder='[{"warehouse":"WH1","items":[{"orderItemId":1,"quantity":1}]}]'></textarea>
          <button type="button" class="btn" id="order-split-save">Enregistrer le fractionnement</button>
        </div>
      </details>
      <details class="orders-details">
        <summary>Remboursement</summary>
        <div class="orders-details-body">
          <div class="orders-field-row orders-field-stack">
            <label class="orders-label" for="refund-amount">Montant</label>
            <input class="input" id="refund-amount" type="number" step="0.01" min="0" placeholder="0.00">
            <label class="orders-label" for="refund-reason">Motif</label>
            <input class="input" id="refund-reason" placeholder="Raison du remboursement" autocomplete="off">
            <button type="button" class="btn" id="order-refund-save">Rembourser</button>
          </div>
        </div>
      </details>
    </section>
    <section class="card pad table-wrap admin-table-card" aria-label="Liste des commandes">
      <div class="card-head"><strong>Liste des commandes</strong><div></div></div>
      <table>
        <thead><tr><th>Commande</th><th>Client</th><th>Statut</th><th>Total</th><th>Date</th></tr></thead>
        <tbody id="orders-body"></tbody>
      </table>
    </section>
  `;

  async function load() {
    const q = root.querySelector("#orders-q").value.trim();
    const status = root.querySelector("#orders-status").value;
    const out = await api.searchOrders({ q, status, limit: 30, offset: 0 });
    const rows = (out.items || [])
      .map(
        (o) => `<tr>
          <td>${o.number || o.id}</td>
          <td>${o.customer_email || "-"}</td>
          <td>${badge(o.status || "pending")}</td>
          <td>${o.total || 0} ${o.currency || "EUR"}</td>
          <td>${o.placed_at || "-"}</td>
        </tr>`
      )
      .join("");
    root.querySelector("#orders-body").innerHTML = rows || `<tr><td colspan="5">Aucune donnée</td></tr>`;
  }

  root.querySelector("#orders-refresh").addEventListener("click", () => {
    load().catch((e) => (root.querySelector("#orders-body").innerHTML = `<tr><td colspan="5">${e.message}</td></tr>`));
  });

  root.querySelector("#order-status-save").addEventListener("click", async () => {
    try {
      await api.updateOrderStatus({
        orderId: root.querySelector("#action-order-id").value.trim(),
        status: root.querySelector("#action-order-status").value,
      });
      showToast("Statut commande mis a jour");
    } catch (e) {
      showToast(`Erreur statut: ${e.message}`);
    }
  });

  root.querySelector("#order-shipment-save").addEventListener("click", async () => {
    try {
      await api.updateOrderShipment({
        orderId: root.querySelector("#action-order-id").value.trim(),
        carrier: root.querySelector("#shipment-carrier").value.trim(),
        trackingNumber: root.querySelector("#shipment-tracking").value.trim(),
        status: "shipped",
      });
      showToast("Shipment mis a jour");
    } catch (e) {
      showToast(`Erreur expédition: ${e.message}`);
    }
  });

  root.querySelector("#order-refund-save").addEventListener("click", async () => {
    try {
      await api.refundOrder({
        orderId: root.querySelector("#action-order-id").value.trim(),
        amount: Number(root.querySelector("#refund-amount").value || 0),
        reason: root.querySelector("#refund-reason").value.trim(),
      });
      showToast("Remboursement enregistre");
    } catch (e) {
      showToast(`Erreur remboursement: ${e.message}`);
    }
  });

  root.querySelector("#order-split-save").addEventListener("click", async () => {
    try {
      const raw = root.querySelector("#split-shipments").value.trim() || "[]";
      await api.splitOrderShipments({
        orderId: root.querySelector("#action-order-id").value.trim(),
        shipments: JSON.parse(raw),
      });
      showToast("Split shipment enregistre");
    } catch (e) {
      showToast(`Erreur fractionnement: ${e.message}`);
    }
  });
  await load();
}
