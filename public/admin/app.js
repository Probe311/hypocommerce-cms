import { h, render } from "https://esm.sh/preact@10.26.4";
import { useState } from "https://esm.sh/preact@10.26.4/hooks";
import { AdminShell, LoginView } from "./components/preact-shell.js";
import { clearAuth, setState, state } from "./services/state.js";
import { api } from "./services/api.js";
import { renderDashboard } from "./pages/dashboard.js";
import { renderOrders } from "./pages/orders.js";
import { renderProducts } from "./pages/products.js";
import { renderCustomers } from "./pages/customers.js";
import { renderContent } from "./pages/content.js";
import { renderSeoSettings } from "./pages/seo-settings.js";
import { renderSettings } from "./pages/settings.js";
import { renderTracking } from "./pages/tracking.js";
import { renderPlugins } from "./pages/plugins.js";
import { NAV_BY_ID } from "./components/nav-config.js";

const app = document.getElementById("app");
const BYPASS_LOGIN_FOR_UI_REVIEW = false;

const CMS_SUB_KEYS = new Set(["pages", "blog", "faq", "legal", "translations"]);

/**
 * @returns {{ shellRoute: string, cmsSection: string | null }}
 */
function parseRouteFromHash() {
  const parts = (location.hash.replace(/^#/, "").trim() || "dashboard").split("/").filter(Boolean);
  const first = parts[0] || "dashboard";
  if (first === "content" || first === "cms") {
    const sub = parts[1] && CMS_SUB_KEYS.has(parts[1]) ? parts[1] : "hub";
    return { shellRoute: "cms", cmsSection: sub };
  }
  return { shellRoute: first, cmsSection: null };
}

const routeRenderers = {
  dashboard: renderDashboard,
  orders: renderOrders,
  products: renderProducts,
  customers: renderCustomers,
  marketing: renderSettings,
  inventory: renderProducts,
  promotions: renderSettings,
  analytics: renderDashboard,
  seo: renderSeoSettings,
  settings: renderSettings,
  plugins: renderPlugins,
  tracking: renderTracking,
};

async function renderProtectedApp() {
  const parsed = parseRouteFromHash();
  state.route = NAV_BY_ID[parsed.shellRoute] ? parsed.shellRoute : "dashboard";
  state.cmsSection = parsed.cmsSection;
  render(
    h(AdminShell, {
      route: state.route,
      email: state.auth.email,
      onNavigate: (route) => {
        location.hash = `#${route}`;
      },
      onLogout: () => {
        clearAuth();
        location.hash = "";
        init();
      },
      onCreate: () => {
        const root = document.getElementById("page-root");
        const label = NAV_BY_ID[state.route]?.createLabel || "Créer";
        root?.insertAdjacentHTML("afterbegin", `<div class="inline-alert">Action: ${label}. Utilise les formulaires de la vue courante.</div>`);
      },
    }),
    app
  );

  const root = document.getElementById("page-root");
  try {
    if (state.route === "cms") {
      await renderContent(root, state.cmsSection || "hub");
    } else {
      const renderer = routeRenderers[state.route] || renderDashboard;
      await renderer(root);
    }
  } catch (e) {
    root.innerHTML = `<div class="inline-alert">Mode revue UI actif: impossible de charger les données (${e.message}).</div>`;
  }
}

function LoginScreen() {
  const [error, setError] = useState("");
  return h(LoginView, {
    error,
    onSubmit: async (ev) => {
      ev.preventDefault();
      setError("");
      try {
        const fd = new FormData(ev.currentTarget);
        const email = String(fd.get("email") || "");
        const password = String(fd.get("password") || "");
        const out = await api.login(email, password);
        setState({
          auth: {
            token: out.token || "",
            csrfToken: out.csrfToken || "",
            role: out.role || "",
            email: out.email || email,
          },
        });
        location.hash = "#dashboard";
        await renderProtectedApp();
      } catch (e) {
        setError(`Connexion impossible: ${e.message}`);
      }
    },
  });
}

function bindLogin() {
  render(h(LoginScreen), app);
}

async function init() {
  if (BYPASS_LOGIN_FOR_UI_REVIEW) {
    setState({
      auth: {
        token: state.auth.token || "ui-review-token",
        csrfToken: state.auth.csrfToken || "ui-review-csrf",
        role: state.auth.role || "super_admin",
        email: state.auth.email || "ui-review@hypocommerce.local",
      },
    });
    await renderProtectedApp();
    return;
  }

  if (!state.auth.token || !state.auth.csrfToken) {
    bindLogin();
    return;
  }
  try {
    await renderProtectedApp();
  } catch (e) {
    clearAuth();
    bindLogin();
  }
}

window.addEventListener("hashchange", () => {
  if (BYPASS_LOGIN_FOR_UI_REVIEW || state.auth.token) renderProtectedApp();
});

init();
