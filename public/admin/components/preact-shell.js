import { h } from "https://esm.sh/preact@10.26.4";
import { NAV_ITEMS, NAV_BY_ID } from "./nav-config.js";

function Icon({ name }) {
  return h("span", { className: "material-symbols-outlined" }, name);
}

export function AdminShell({ route, email, onNavigate, onLogout, onCreate }) {
  const createLabel = NAV_BY_ID[route]?.createLabel || "Créer";
  return h(
    "div",
    { className: "app-shell" },
    h(
      "aside",
      { className: "sidebar" },
      h(
        "div",
        { className: "brand" },
        h(
          "span",
          { className: "brand-logo-wrap", "aria-hidden": "true" },
          h("img", { className: "brand-logo", src: "/brand/hippocampus.svg", width: "40", height: "40", alt: "" })
        ),
        h(
          "div",
          { className: "brand-text" },
          h("h1", null, "Hypocommerce CMS"),
          h("p", null, "v1.0.0")
        )
      ),
      h(
        "nav",
        { className: "nav-list" },
        NAV_ITEMS.map(({ id, label, icon }) =>
          h(
            "button",
            {
              type: "button",
              key: id,
              className: `nav-item ${route === id ? "active" : ""}`,
              "aria-current": route === id ? "page" : undefined,
              onClick: () => onNavigate(id),
            },
            h(Icon, { name: icon }),
            h("span", null, label)
          )
        )
      ),
      h(
        "div",
        { className: "sidebar-profile" },
        h("div", { className: "avatar" }, "A"),
        h(
          "div",
          null,
        h("div", { className: "sidebar-profile-name" }, email || "Admin"),
        h("div", { className: "sidebar-profile-role" }, "Back-office")
        )
      )
    ),
    h(
      "div",
      { className: "app-main" },
      h(
        "header",
        { className: "topbar" },
        h(
          "div",
          { className: "search-wrap" },
          h(Icon, { name: "search" }),
          h("input", { id: "global-search", placeholder: "Rechercher commandes, produits, clients..." })
        ),
        h(
          "div",
          { className: "topbar-actions" },
          h(
            "button",
            { type: "button", className: "btn btn-primary", id: "top-create", onClick: onCreate },
            h(Icon, { name: "add" }),
            ` ${createLabel}`
          ),
          h(
            "button",
            { type: "button", className: "btn btn-icon", id: "logout-btn", onClick: onLogout },
            h(Icon, { name: "logout" })
          )
        )
      ),
      h("main", { id: "page-root", className: "page" })
    )
  );
}

export function LoginView({ onSubmit, error }) {
  return h(
    "div",
    { className: "login-view" },
    h(
      "form",
      {
        className: "login-card",
        id: "login-form",
        onSubmit: onSubmit,
      },
      h("h2", { className: "login-title" }, "Connexion admin"),
      h("p", { className: "login-subtitle" }, "Accès CMS + e-commerce + settings"),
      h("label", { className: "login-label" }, "Email"),
      h("input", {
        className: "input login-input",
        type: "email",
        name: "email",
        required: true,
        placeholder: "admin@hypocommerce.local",
      }),
      h("label", { className: "login-label" }, "Mot de passe"),
      h("input", {
        className: "input login-input",
        type: "password",
        name: "password",
        required: true,
        placeholder: "••••••••",
      }),
      h("button", { className: "btn btn-primary login-submit", type: "submit" }, "Se connecter"),
      h("p", { className: `inline-alert login-error ${error ? "" : "hidden"}` }, error || "")
    )
  );
}
