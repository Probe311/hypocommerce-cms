import { icon } from "./ui.js";
import { NAV_ITEMS, NAV_BY_ID } from "./nav-config.js";

export function renderShell({ route, email }) {
  const createLabel = NAV_BY_ID[route]?.createLabel || "Créer";
  const nav = NAV_ITEMS
    .map(
      ({ id, label, icon: ico }) => `
      <button class="nav-item ${route === id ? "active" : ""}" data-route="${id}" ${route === id ? 'aria-current="page"' : ""}>
        ${icon(ico)}
        <span>${label}</span>
      </button>
    `
    )
    .join("");

  return `
    <div class="app-shell">
      <aside class="sidebar">
        <div class="brand">
          <span class="brand-logo-wrap" aria-hidden="true">
            <img class="brand-logo" src="/brand/hippocampus.svg" width="40" height="40" alt="" />
          </span>
          <div class="brand-text">
            <h1>Hypocommerce CMS</h1>
            <p>v1.0.0</p>
          </div>
        </div>
        <nav class="nav-list">${nav}</nav>
        <div class="sidebar-profile">
          <div class="avatar">A</div>
          <div>
            <div class="sidebar-profile-name">${email || "Admin"}</div>
            <div class="sidebar-profile-role">Back-office</div>
          </div>
        </div>
      </aside>
      <div class="app-main">
        <header class="topbar">
          <div class="search-wrap">
            ${icon("search")}
            <input id="global-search" placeholder="Rechercher commandes, produits, clients...">
          </div>
          <div class="topbar-actions">
            <button class="btn btn-primary" id="top-create">${icon("add")} ${createLabel}</button>
            <button class="btn btn-icon" id="logout-btn">${icon("logout")}</button>
          </div>
        </header>
        <main id="page-root" class="page"></main>
      </div>
    </div>
    <div id="toast-root"></div>
  `;
}

export function renderLogin() {
  return `
    <div class="login-view">
      <form class="login-card" id="login-form">
        <h2 class="login-title">Connexion admin</h2>
        <p class="login-subtitle">Accès CMS + e-commerce + settings</p>
        <label class="login-label">Email</label>
        <input class="input login-input" type="email" name="email" required placeholder="admin@hypocommerce.local">
        <label class="login-label">Mot de passe</label>
        <input class="input login-input" type="password" name="password" required placeholder="••••••••">
        <button class="btn btn-primary login-submit" type="submit">Se connecter</button>
        <p id="login-error" class="inline-alert hidden login-error"></p>
      </form>
    </div>
  `;
}

export function showToast(message) {
  const root = document.getElementById("toast-root");
  if (!root) return;
  root.innerHTML = `<div class="toast">${message}</div>`;
  window.setTimeout(() => {
    root.innerHTML = "";
  }, 2200);
}
