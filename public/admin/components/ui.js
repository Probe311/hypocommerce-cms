export function escapeHtml(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

export function icon(name) {
  return `<span class="material-symbols-outlined">${name}</span>`;
}

export function badge(status) {
  const map = {
    paid: "success",
    fulfilled: "success",
    published: "success",
    pending: "neutral",
    draft: "neutral",
    cancelled: "warn",
    refunded: "warn",
    error: "warn",
  };
  const cls = map[String(status).toLowerCase()] || "neutral";
  return `<span class="badge ${cls}">${status}</span>`;
}

export function kpiCard({ title, value, delta = "" }) {
  return `
    <article class="card pad">
      <div class="kpi-title">${title}</div>
      <div class="kpi-value">${value}</div>
      <div class="kpi-delta">${delta}</div>
    </article>
  `;
}

export function sectionCard(title, body, actions = "") {
  return `
    <section class="card">
      <div class="card-head">
        <strong>${title}</strong>
        <div>${actions}</div>
      </div>
      <div class="pad">${body}</div>
    </section>
  `;
}

export function skeletonRow() {
  return `<div class="skeleton skeleton-row"></div>`;
}

export function toggleSwitch({ checked, pluginKey }) {
  return `
    <label class="toggle-switch">
      <input type="checkbox" data-plugin-toggle="${pluginKey}" ${checked ? "checked" : ""}>
      <span class="toggle-slider"></span>
    </label>
  `;
}

export function pluginStatusBadge(enabled) {
  return `<span class="badge ${enabled ? "success" : "neutral"}">${enabled ? "active" : "inactive"}</span>`;
}

export function configFormBlock({ pluginKey, mode = "sandbox", priority = 100, publicLabel = "" }) {
  return `
    <div class="plugin-config" data-plugin-config="${pluginKey}">
      <select class="select" data-field="mode">
        <option value="sandbox" ${mode === "sandbox" ? "selected" : ""}>sandbox</option>
        <option value="live" ${mode === "live" ? "selected" : ""}>live</option>
      </select>
      <input class="input" type="number" min="1" max="999" data-field="priority" value="${priority}">
      <input class="input" data-field="publicLabel" value="${publicLabel}" placeholder="Label public">
      <button class="btn" type="button" data-plugin-save="${pluginKey}">Sauver</button>
    </div>
  `;
}

export function pluginCard(plugin) {
  const cfg = plugin.config || {};
  return `
    <article class="card pad plugin-card">
      <div class="plugin-head">
        <div>
          <strong>${plugin.label}</strong>
          <p class="plugin-description">${plugin.description || ""}</p>
        </div>
        <div class="plugin-head-actions">
          ${pluginStatusBadge(Boolean(plugin.is_enabled))}
          ${toggleSwitch({ checked: Boolean(plugin.is_enabled), pluginKey: plugin.plugin_key })}
        </div>
      </div>
      ${configFormBlock({
        pluginKey: plugin.plugin_key,
        mode: plugin.mode || "sandbox",
        priority: Number(cfg.priority || plugin.sort_order || 100),
        publicLabel: String(cfg.publicLabel || plugin.label || ""),
      })}
    </article>
  `;
}

/** Carte courte + ouverture modale (aligné admin Next.js). */
export function pluginHighlightCard(plugin) {
  const key = escapeHtml(plugin.plugin_key);
  const cat = escapeHtml(plugin.category_label || plugin.plugin_type || "—");
  const enabled = Boolean(plugin.is_enabled);
  return `
    <article class="card pad plugin-card plugin-highlight">
      <div class="plugin-head">
        <div>
          <div class="plugin-meta-row">
            <span class="badge neutral">${cat}</span>
            ${pluginStatusBadge(enabled)}
          </div>
          <strong>${escapeHtml(plugin.label)}</strong>
          <p class="plugin-description">${escapeHtml(plugin.description || "")}</p>
        </div>
        <button type="button" class="btn btn-primary" data-plugin-open-modal="${key}">Détails</button>
      </div>
    </article>
  `;
}

export function pluginDetailModalMarkup() {
  return `
    <dialog id="plugin-detail-dialog" class="confirm-modal plugin-detail-dialog">
      <h3 class="plugin-dialog-title" data-plugin-modal-title></h3>
      <p class="muted plugin-dialog-category" data-plugin-modal-category></p>
      <p class="plugin-dialog-body" data-plugin-modal-body></p>
      <p data-plugin-modal-docs-wrap><a data-plugin-modal-docs href="#" target="_blank" rel="noopener noreferrer">Documentation officielle</a></p>
      <div class="plugin-config plugin-config-dialog">
        <label class="muted plugin-dialog-label">Mode</label>
        <select class="select" data-field="mode">
          <option value="sandbox">sandbox</option>
          <option value="live">live</option>
        </select>
        <label class="muted plugin-dialog-label">Priorité</label>
        <input class="input" type="number" min="1" max="999" data-field="priority" value="100">
        <label class="muted plugin-dialog-label">Libellé public</label>
        <input class="input" data-field="publicLabel" placeholder="Label public">
        <div class="plugin-dialog-token-block">
          <div class="muted plugin-dialog-token-title">Tokens de configuration</div>
          <div data-plugin-modal-token-fields class="plugin-dialog-token-fields"></div>
        </div>
      </div>
      <div class="filters plugin-dialog-actions">
        <button type="button" class="btn" data-close-plugin-dialog>Fermer</button>
        <button type="button" class="btn" data-plugin-modal-save>Sauver la config</button>
        <button type="button" class="btn btn-primary" data-plugin-modal-toggle>Activer</button>
      </div>
    </dialog>
  `;
}

export function actionToast(message) {
  return `<div class="toast">${message}</div>`;
}

export function stateToggle({ checked, id, action }) {
  return `
    <label class="toggle-switch">
      <input type="checkbox" data-toggle-action="${action}" data-toggle-id="${id}" ${checked ? "checked" : ""}>
      <span class="toggle-slider"></span>
    </label>
  `;
}

export function editableRow(columns = []) {
  return `<tr>${columns.map((c) => `<td>${c}</td>`).join("")}</tr>`;
}

export function settingsSectionCard(title, body, actions = "") {
  return `
    <section class="card">
      <div class="card-head">
        <strong>${title}</strong>
        <div>${actions}</div>
      </div>
      <div class="pad settings-block">${body}</div>
    </section>
  `;
}

export function inlineFormBlock(inner) {
  return `<div class="filters settings-inline-form">${inner}</div>`;
}

export function fieldError(message) {
  return `<p class="field-error">${message}</p>`;
}

export function bulkPreviewPanel(items = []) {
  if (!items.length) return `<p class="admin-muted">Aucune operation en attente.</p>`;
  return `
    <div class="bulk-preview">
      ${items.map((it) => `<span class="badge neutral">${it.id} -> ${it.status}</span>`).join("")}
    </div>
  `;
}

export function mediaPicker(images = []) {
  return `
    <div class="media-grid">
      ${images
        .map(
          (img) => `
        <div class="media-item" data-media-id="${img.id}">
          <img src="${img.url}" alt="${img.alt || "image"}">
          <div class="media-actions">
            <button class="btn btn-icon" data-media-up="${img.id}">↑</button>
            <button class="btn btn-icon" data-media-down="${img.id}">↓</button>
            <button class="btn btn-icon" data-media-del="${img.id}">✕</button>
          </div>
        </div>
      `
        )
        .join("")}
    </div>
  `;
}

export function confirmActionModal(id, title, content) {
  return `
    <dialog id="${id}" class="confirm-modal">
      <h3 class="confirm-modal-title">${title}</h3>
      <p>${content}</p>
      <div class="filters">
        <button class="btn" data-close-modal="${id}">Annuler</button>
        <button class="btn btn-primary" data-confirm-modal="${id}">Confirmer</button>
      </div>
    </dialog>
  `;
}
