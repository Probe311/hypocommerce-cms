import { api } from "../services/api.js";
import { kpiCard, sectionCard, skeletonRow } from "../components/ui.js";

export async function renderDashboard(root) {
  root.innerHTML = `
    <div class="page-header">
      <h2>Tableau de bord</h2>
      <p>Vue d’ensemble du back-office</p>
    </div>
    <div class="kpi-grid">
      ${kpiCard({ title: "Commandes", value: "...", delta: "chargement" })}
      ${kpiCard({ title: "Produits", value: "...", delta: "chargement" })}
      ${kpiCard({ title: "Clients", value: "...", delta: "chargement" })}
      ${kpiCard({ title: "Segments CRM", value: "...", delta: "chargement" })}
    </div>
    ${sectionCard("Activité récente", `<div class="admin-recent-list">${skeletonRow() + skeletonRow() + skeletonRow()}</div>`)}
  `;

  try {
    const [orders, products, customers, segments] = await Promise.all([
      api.searchOrders({ limit: 5, offset: 0 }),
      api.searchProducts({ limit: 5, offset: 0 }),
      api.searchCustomers({ limit: 5, offset: 0 }),
      api.segments({ limit: 5, offset: 0 }),
    ]);

    const cards = root.querySelectorAll(".kpi-value");
    cards[0].textContent = String(orders.items?.length || 0);
    cards[1].textContent = String(products.items?.length || 0);
    cards[2].textContent = String(customers.items?.length || 0);
    cards[3].textContent = String(segments.items?.length || 0);

    const recent = (orders.items || [])
      .slice(0, 5)
      .map(
        (o) => `
      <div class="admin-recent-row">
        <span><strong>${o.number || o.id}</strong> - ${o.customer_email || "client inconnu"}</span>
        <span>${o.total || 0} ${o.currency || "EUR"}</span>
      </div>`
      )
      .join("");

    const cardBody = recent || `<p class="admin-muted">Aucune commande récente.</p>`;
    root.querySelectorAll(".card .pad")[0].innerHTML = cardBody;
  } catch (e) {
    root.insertAdjacentHTML("beforeend", `<div class="inline-alert">Erreur dashboard: ${e.message}</div>`);
  }
}
