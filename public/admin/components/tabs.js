/**
 * Composant Tabs réutilisable (vanilla JS).
 * - Supporte tabs "in-page" via `aria-controls` (hide/show des panneaux).
 * - Supporte aussi un mode "navigation" via `data-nav-href` (hash update).
 * - Gère la navigation clavier (Arrow/Home/End) et Enter/Espace.
 */

function normalizeHashHref(href) {
  const s = String(href || "").trim();
  if (!s) return "";
  return s.startsWith("#") ? s : `#${s}`;
}

/**
 * @param {{key:string,label:string,panelId?:string,navHref?:string,tabClassName?:string,tabId?:string}} tab
 * @returns {string}
 */
function tabButtonHtml(tab, { activeKey }) {
  const active = tab.key === activeKey;
  const ariaControls = tab.panelId ? `aria-controls="${tab.panelId}"` : "";
  const navHref = tab.navHref ? `data-nav-href="${normalizeHashHref(tab.navHref)}"` : "";
  const extra = tab.tabClassName ? ` ${tab.tabClassName}` : "";
  const tabId = tab.tabId ? `id="${tab.tabId}"` : "";
  return `
    <button
      type="button"
      role="tab"
      class="settings-tab${extra}${active ? " is-active" : ""}"
      ${tabId}
      data-tab-key="${tab.key}"
      aria-selected="${active ? "true" : "false"}"
      tabindex="${active ? "0" : "-1"}"
      ${ariaControls}
      ${navHref}
    >${tab.label}</button>
  `;
}

/**
 * @param {{
 *  ariaLabel?:string,
 *  tablistClass?:string,
 *  tabs:Array<{key:string,label:string,panelId?:string,navHref?:string,tabClassName?:string,tabId?:string}>,
 *  activeKey:string,
 *  stacked?:false
 * }} opts
 */
export function renderTabsBarHtml({ ariaLabel = "Tabs", tablistClass = "settings-tabs-bar", tabs = [], activeKey }) {
  return `
    <div class="${tablistClass}" role="tablist" aria-label="${ariaLabel}">
      ${tabs.map((t) => tabButtonHtml(t, { activeKey })).join("")}
    </div>
  `;
}

/**
 * @param {{
 *  ariaLabel?:string,
 *  tablistClass?:string,
 *  groups:Array<{label:string,tabs:Array<{key:string,label:string,panelId?:string,navHref?:string,tabClassName?:string,tabId?:string}>}>,
 *  activeKey:string
 * }} opts
 */
export function renderGroupedTabsBarHtml({ ariaLabel = "Tabs", tablistClass = "settings-tabs-bar settings-tabs-bar--stacked", groups = [], activeKey }) {
  return `
    <div class="${tablistClass}" role="tablist" aria-label="${ariaLabel}">
      ${groups
        .map(
          (g) => `
          <div class="settings-tab-group">
            <span class="settings-tab-group-label">${g.label}</span>
            <div class="settings-tab-group-row">
              ${g.tabs.map((t) => tabButtonHtml(t, { activeKey })).join("")}
            </div>
          </div>
        `
        )
        .join("")}
    </div>
  `;
}

/**
 * Active / navigation binding.
 * @param {HTMLElement} root
 * @param {{tablistSelector:string}} opts
 */
export function bindTabs(root, { tablistSelector }) {
  const tablist = root.querySelector(tablistSelector);
  if (!tablist) return;

  const tabs = Array.from(tablist.querySelectorAll('[role="tab"]'));
  if (!tabs.length) return;

  const panels = tabs
    .map((t) => t.getAttribute("aria-controls") || "")
    .filter(Boolean)
    .map((id) => root.querySelector(`#${CSS.escape(id)}`))
    .filter(Boolean);

  const navOnly = panels.length === 0;

  function setActiveTab(nextTab) {
    const controlsId = nextTab.getAttribute("aria-controls") || "";
    const navHref = nextTab.getAttribute("data-nav-href") || "";

    if (navOnly && navHref) {
      location.hash = normalizeHashHref(navHref);
      return;
    }

    if (!controlsId) return;
    const activePanel = root.querySelector(`#${CSS.escape(controlsId)}`);

    tabs.forEach((t) => {
      const on = t === nextTab;
      t.classList.toggle("is-active", on);
      t.setAttribute("aria-selected", on ? "true" : "false");
      t.tabIndex = on ? 0 : -1;
    });

    panels.forEach((p) => {
      p.hidden = p !== activePanel;
    });
  }

  // Click = activation (and navigation when relevant).
  tabs.forEach((tab) => {
    tab.addEventListener("click", () => {
      setActiveTab(tab);
    });
  });

  // Keyboard navigation inside tablist.
  tablist.addEventListener("keydown", (ev) => {
    const currentIndex = tabs.findIndex((t) => t.getAttribute("aria-selected") === "true" || t.classList.contains("is-active"));
    const safeCurrent = currentIndex >= 0 ? currentIndex : 0;
    let nextIndex = safeCurrent;

    if (ev.key === "ArrowRight" || ev.key === "ArrowDown") {
      nextIndex = Math.min(safeCurrent + 1, tabs.length - 1);
      ev.preventDefault();
    } else if (ev.key === "ArrowLeft" || ev.key === "ArrowUp") {
      nextIndex = Math.max(safeCurrent - 1, 0);
      ev.preventDefault();
    } else if (ev.key === "Home") {
      nextIndex = 0;
      ev.preventDefault();
    } else if (ev.key === "End") {
      nextIndex = tabs.length - 1;
      ev.preventDefault();
    } else if ((ev.key === "Enter" || ev.key === " ") && document.activeElement) {
      ev.preventDefault();
      const activeEl = document.activeElement;
      if (activeEl && activeEl.getAttribute("role") === "tab") setActiveTab(activeEl);
      return;
    } else {
      return;
    }

    const next = tabs[nextIndex];
    next.focus();

    if (!navOnly) {
      setActiveTab(next);
    }
  });
}

