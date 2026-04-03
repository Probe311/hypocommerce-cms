import { api } from "../services/api.js";
import { showToast } from "../components/shell.js";
import { bindTabs, renderTabsBarHtml } from "../components/tabs.js";

const CMS_SECTIONS = new Set(["hub", "pages", "blog", "faq", "legal", "translations"]);

const statusOptionsFull = `
    <option value="draft">draft</option>
    <option value="in_review">in_review</option>
    <option value="scheduled">scheduled</option>
    <option value="published" selected>published</option>
    <option value="archived">archived</option>
  `;

function normalizeSection(section) {
  const s = String(section || "hub").toLowerCase();
  return CMS_SECTIONS.has(s) ? s : "hub";
}

function hubBody() {
  const cards = [
    ["#content/pages", "Pages", "Workflow complet : slug, statut, sections JSON."],
    ["#content/blog", "Blog", "Articles par catégorie, tags et corps."],
    ["#content/faq", "FAQ", "Questions / réponses par catégorie."],
    ["#content/legal", "Légal", "CGV, mentions, paragraphes JSON."],
    ["#content/translations", "Traductions", "Produits, catégories, pages CMS."],
  ];
  const grid = cards
    .map(
      ([href, title, desc]) => `
    <a class="cms-hub-card" href="${href}">
      <strong>${title}</strong>
      <span>${desc}</span>
    </a>`
    )
    .join("");
  return `<div class="cms-hub-grid">${grid}</div>`;
}

function pagesForm() {
  return `
    <section class="card pad admin-form-grid">
      <h3 class="cms-section-title">Publication page CMS</h3>
      <label class="orders-label" for="cms-slug">Slug</label>
      <input class="input" id="cms-slug" placeholder="ex. accueil" autocomplete="off">
      <label class="orders-label" for="cms-title">Titre</label>
      <input class="input" id="cms-title" placeholder="titre" autocomplete="off">
      <div class="filters">
        <label class="orders-label" for="cms-status">Statut</label>
        <select class="select" id="cms-status">${statusOptionsFull}</select>
        <label class="orders-label" for="cms-scheduled">Planification (optionnel)</label>
        <input class="input" id="cms-scheduled" placeholder="YYYY-MM-DD HH:MM:SS" autocomplete="off">
        <label class="orders-label" for="cms-review-note">Note de relecture</label>
        <input class="input" id="cms-review-note" placeholder="optionnel" autocomplete="off">
      </div>
      <label class="orders-label" for="cms-section">Contenu JSON (section)</label>
      <textarea class="input orders-textarea" id="cms-section" placeholder='{"hero":{"title":"Titre"}}'></textarea>
      <button type="button" class="btn btn-primary" id="cms-save">Publier la page</button>
    </section>`;
}

function blogForm() {
  return `
    <section class="card pad admin-form-grid">
      <h3 class="cms-section-title">Article blog</h3>
      <label class="orders-label" for="blog-category">Catégorie (slug)</label>
      <input class="input" id="blog-category" placeholder="ex. actualites" autocomplete="off">
      <label class="orders-label" for="blog-slug">Slug</label>
      <input class="input" id="blog-slug" placeholder="slug" autocomplete="off">
      <label class="orders-label" for="blog-title">Titre</label>
      <input class="input" id="blog-title" placeholder="titre" autocomplete="off">
      <label class="orders-label" for="blog-status">Statut</label>
      <select class="select" id="blog-status"><option value="published">published</option><option value="draft">draft</option></select>
      <label class="orders-label" for="blog-tags">Tags (CSV)</label>
      <input class="input" id="blog-tags" placeholder="ecommerce, seo, ux" autocomplete="off">
      <label class="orders-label" for="blog-body">Contenu</label>
      <textarea class="input orders-textarea" id="blog-body" placeholder="contenu article"></textarea>
      <button type="button" class="btn" id="blog-save">Créer l’article</button>
    </section>`;
}

function faqForm() {
  return `
    <section class="card pad admin-form-grid">
      <h3 class="cms-section-title">FAQ</h3>
      <label class="orders-label" for="faq-category">Catégorie (slug)</label>
      <input class="input" id="faq-category" placeholder="ex. general" autocomplete="off">
      <label class="orders-label" for="faq-question">Question</label>
      <input class="input" id="faq-question" placeholder="question" autocomplete="off">
      <label class="orders-label" for="faq-status">Statut</label>
      <select class="select" id="faq-status"><option value="published">published</option><option value="draft">draft</option></select>
      <label class="orders-label" for="faq-answer">Réponse</label>
      <textarea class="input orders-textarea" id="faq-answer" placeholder="réponse"></textarea>
      <button type="button" class="btn" id="faq-save">Créer la FAQ</button>
    </section>`;
}

function legalForm() {
  return `
    <section class="card pad admin-form-grid">
      <h3 class="cms-section-title">Page légale</h3>
      <label class="orders-label" for="legal-slug">Slug</label>
      <input class="input" id="legal-slug" placeholder="ex. cgv" autocomplete="off">
      <label class="orders-label" for="legal-title">Titre</label>
      <input class="input" id="legal-title" placeholder="titre" autocomplete="off">
      <label class="orders-label" for="legal-status">Statut</label>
      <select class="select" id="legal-status"><option value="published">published</option><option value="draft">draft</option></select>
      <label class="orders-label" for="legal-paragraphs">Paragraphes (JSON)</label>
      <textarea class="input orders-textarea" id="legal-paragraphs" placeholder='["P1","P2"]'></textarea>
      <button type="button" class="btn" id="legal-save">Publier</button>
    </section>`;
}

function translationsForm() {
  return `
    <section class="card pad admin-form-grid">
      <h3 class="cms-section-title">Traduction rapide</h3>
      <div class="filters">
        <label class="orders-label" for="tr-entity">Entité</label>
        <select class="select" id="tr-entity">
          <option value="product">product</option>
          <option value="category">category</option>
          <option value="cms_page">cms_page</option>
        </select>
        <label class="orders-label" for="tr-locale">Locale</label>
        <input class="input" id="tr-locale" value="fr" placeholder="locale" autocomplete="off">
      </div>
      <label class="orders-label" for="tr-ref">Référence</label>
      <input class="input" id="tr-ref" placeholder="productId / categoryId / pageSlug" autocomplete="off">
      <label class="orders-label" for="tr-title">Titre ou nom traduit</label>
      <input class="input" id="tr-title" placeholder="titre" autocomplete="off">
      <button type="button" class="btn" id="tr-save">Enregistrer la traduction</button>
    </section>`;
}

function timelineSection() {
  return `
    <section class="card pad">
      <h3 class="cms-section-title">Timeline d’édition</h3>
      <div id="content-timeline" class="admin-form-grid"></div>
    </section>`;
}

function mainSectionHtml(section) {
  switch (section) {
    case "pages":
      return pagesForm();
    case "blog":
      return blogForm();
    case "faq":
      return faqForm();
    case "legal":
      return legalForm();
    case "translations":
      return translationsForm();
    default:
      return "";
  }
}

/**
 * @param {HTMLElement} root
 * @param {string} [section]
 */
export async function renderContent(root, section = "hub") {
  const sec = normalizeSection(section);

  const header = `
    <div class="page-header">
      <h2>CMS</h2>
      <p>${sec === "hub" ? "Pages, blog, FAQ, légal et traductions" : "Contenu et publication"}</p>
    </div>`;

  const tabBar = renderTabsBarHtml({
    ariaLabel: "Sections CMS",
    tablistClass: "settings-tabs-bar",
    activeKey: sec === "hub" ? "hub" : sec,
    tabs: [
      { key: "hub", label: "Accueil", navHref: "#content" },
      { key: "pages", label: "Pages", navHref: "#content/pages" },
      { key: "blog", label: "Blog", navHref: "#content/blog" },
      { key: "faq", label: "FAQ", navHref: "#content/faq" },
      { key: "legal", label: "Légal", navHref: "#content/legal" },
      { key: "translations", label: "Traductions", navHref: "#content/translations" },
    ],
  });

  const body = sec === "hub" ? hubBody() : `${mainSectionHtml(sec)}${timelineSection()}`;

  root.innerHTML = `${header}${tabBar}${body}`;

  bindTabs(root, { tablistSelector: '[role="tablist"][aria-label="Sections CMS"]' });

  function pushTimeline(message) {
    const el = root.querySelector("#content-timeline");
    if (!el) return;
    const row = document.createElement("div");
    row.className = "admin-recent-row";
    row.textContent = `${new Date().toLocaleString()} - ${message}`;
    el.prepend(row);
  }

  const cmsSave = root.querySelector("#cms-save");
  if (cmsSave) {
    cmsSave.addEventListener("click", async () => {
      try {
        const slug = root.querySelector("#cms-slug").value.trim();
        const title = root.querySelector("#cms-title").value.trim();
        const payloadText = root.querySelector("#cms-section").value.trim() || "{}";
        const sectionPayload = JSON.parse(payloadText);
        await api.upsertPage({
          slug,
          title,
          status: root.querySelector("#cms-status").value,
          scheduledAt: root.querySelector("#cms-scheduled").value.trim() || null,
          reviewNote: root.querySelector("#cms-review-note").value.trim() || null,
          template: "default",
          sections: [{ sectionKey: "main", sectionType: "content", orderIndex: 0, payload: sectionPayload }],
        });
        showToast("Page CMS publiée");
        pushTimeline(`Page CMS ${slug} sauvegardée`);
      } catch (e) {
      showToast(`Erreur CMS: ${e.message}`);
      }
    });
  }

  const blogSave = root.querySelector("#blog-save");
  if (blogSave) {
    blogSave.addEventListener("click", async () => {
      try {
        await api.upsertBlog({
          categorySlug: root.querySelector("#blog-category").value.trim(),
          slug: root.querySelector("#blog-slug").value.trim(),
          title: root.querySelector("#blog-title").value.trim(),
          tags: root.querySelector("#blog-tags").value.split(",").map((v) => v.trim()).filter(Boolean),
          body: root.querySelector("#blog-body").value.trim(),
          publish: root.querySelector("#blog-status").value === "published",
        });
        showToast("Article blog créé");
        pushTimeline(`Article blog ${root.querySelector("#blog-slug").value.trim()} sauvegardé`);
      } catch (e) {
      showToast(`Erreur blog: ${e.message}`);
      }
    });
  }

  const faqSave = root.querySelector("#faq-save");
  if (faqSave) {
    faqSave.addEventListener("click", async () => {
      try {
        await api.upsertFaq({
          categorySlug: root.querySelector("#faq-category").value.trim() || "general",
          question: root.querySelector("#faq-question").value.trim(),
          answer: root.querySelector("#faq-answer").value.trim(),
          publish: root.querySelector("#faq-status").value === "published",
        });
        showToast("FAQ créée");
        pushTimeline("FAQ sauvegardée");
      } catch (e) {
      showToast(`Erreur FAQ: ${e.message}`);
      }
    });
  }

  const legalSave = root.querySelector("#legal-save");
  if (legalSave) {
    legalSave.addEventListener("click", async () => {
      try {
        await api.upsertLegal({
          slug: root.querySelector("#legal-slug").value.trim(),
          title: root.querySelector("#legal-title").value.trim(),
          paragraphs: JSON.parse(root.querySelector("#legal-paragraphs").value.trim() || "[]"),
          publish: root.querySelector("#legal-status").value === "published",
        });
        showToast("Page légale publiée");
        pushTimeline(`Page légale ${root.querySelector("#legal-slug").value.trim()} sauvegardée`);
      } catch (e) {
      showToast(`Erreur légal: ${e.message}`);
      }
    });
  }

  const trSave = root.querySelector("#tr-save");
  if (trSave) {
    trSave.addEventListener("click", async () => {
      try {
        const entity = root.querySelector("#tr-entity").value;
        const locale = root.querySelector("#tr-locale").value.trim() || "fr";
        const ref = root.querySelector("#tr-ref").value.trim();
        const title = root.querySelector("#tr-title").value.trim();
        const payload = { entity, locale };
        if (entity === "product") {
          payload.productId = ref;
          payload.name = title;
        } else if (entity === "category") {
          payload.categoryId = Number(ref || 0);
          payload.name = title;
        } else {
          payload.pageSlug = ref;
          payload.title = title;
        }
        await api.upsertTranslation(payload);
        showToast("Traduction enregistrée");
        pushTimeline(`Traduction ${entity}:${locale} sauvegardée`);
      } catch (e) {
      showToast(`Erreur traduction: ${e.message}`);
      }
    });
  }
}
