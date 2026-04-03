import { api } from "../services/api.js";
import { badge } from "../components/ui.js";

export async function renderCustomers(root) {
  root.innerHTML = `
    <div class="page-header"><h2>Clients</h2><p>Base clients et recherche CRM</p></div>
    <div class="filters">
      <input class="input" id="customers-q" placeholder="Email, prénom, nom">
      <button class="btn" id="customers-refresh">Filtrer</button>
    </div>
    <section class="card table-wrap admin-table-card">
      <div class="card-head"><strong>Annuaire clients</strong><div></div></div>
      <table>
        <thead><tr><th>Email</th><th>Prénom</th><th>Nom</th><th>Créé le</th><th>Dernière connexion</th></tr></thead>
        <tbody id="customers-body"></tbody>
      </table>
    </section>
    <section class="card table-wrap admin-table-card">
      <div class="card-head"><strong>Segments CRM</strong><div></div></div>
      <table>
        <thead><tr><th>Segment</th><th>Email</th><th>Score</th><th>Calculé le</th><th>Statut</th></tr></thead>
        <tbody id="segments-body"></tbody>
      </table>
    </section>
  `;

  async function load() {
    const q = root.querySelector("#customers-q").value.trim();
    const [out, segOut] = await Promise.all([
      api.searchCustomers({ q, limit: 30, offset: 0 }),
      api.segments({ limit: 30, offset: 0 }),
    ]);
    const rows = (out.items || [])
      .map(
        (c) => `<tr>
          <td>${c.email || "-"}</td>
          <td>${c.first_name || "-"}</td>
          <td>${c.last_name || "-"}</td>
          <td>${c.created_at || "-"}</td>
          <td>${c.last_login_at || "-"}</td>
        </tr>`
      )
      .join("");
    root.querySelector("#customers-body").innerHTML = rows || `<tr><td colspan="5">Aucun client</td></tr>`;

    const segRows = (segOut.items || [])
      .map(
        (s) => `<tr>
          <td>${s.segment_code || "-"}</td>
          <td>${s.email || "-"}</td>
          <td>${s.score || 0}</td>
          <td>${s.computed_at || "-"}</td>
          <td>${badge("published")}</td>
        </tr>`
      )
      .join("");
    root.querySelector("#segments-body").innerHTML = segRows || `<tr><td colspan="5">Aucun segment</td></tr>`;
  }

  root.querySelector("#customers-refresh").addEventListener("click", () => {
    load().catch((e) => (root.querySelector("#customers-body").innerHTML = `<tr><td colspan="5">${e.message}</td></tr>`));
  });
  await load();
}
