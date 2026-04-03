import { api } from "../services/api.js";
import { badge, bulkPreviewPanel, confirmActionModal, fieldError, mediaPicker } from "../components/ui.js";
import { showToast } from "../components/shell.js";

export async function renderProducts(root) {
  root.innerHTML = `
    <div class="page-header"><h2>Produits</h2><p>Catalogue dynamique et actions bulk</p></div>
    <div class="filters">
      <input class="input" id="products-q" placeholder="Nom, SKU, slug">
      <select class="select" id="products-status">
        <option value="">Tous statuts</option>
        <option value="published">published</option>
        <option value="draft">draft</option>
        <option value="archived">archived</option>
      </select>
      <select class="select" id="products-type">
        <option value="">Tous types</option>
        <option value="simple">simple</option>
        <option value="variable">variable</option>
        <option value="digital">digital</option>
        <option value="bundle">bundle</option>
      </select>
      <button class="btn" id="products-refresh">Filtrer</button>
    </div>
    <section class="card pad admin-stack-sm">
      <div class="card-head"><strong>Actions bulk</strong><div></div></div>
      <textarea class="input orders-textarea" id="products-ids" placeholder="IDs produits (1 par ligne)"></textarea>
      <div class="filters">
        <select class="select" id="bulk-status">
          <option value="draft">Passer en draft</option>
          <option value="published">Passer en published</option>
          <option value="archived">Passer en archived</option>
        </select>
        <button class="btn" id="bulk-apply">Appliquer</button>
      </div>
      <div id="bulk-preview"></div>
    </section>
    <section class="card pad admin-stack-sm">
      <div class="card-head"><strong>Edition ciblée</strong><div></div></div>
      <div class="filters">
        <input class="input" id="edit-product-id" placeholder="Product UUID">
        <input class="input" id="edit-seo-title" placeholder="SEO title">
        <input class="input" id="edit-seo-description" placeholder="SEO description">
        <button class="btn" id="edit-save">Sauver SEO (fr)</button>
      </div>
      <div id="edit-error"></div>
    </section>
    <section class="card pad admin-stack-sm">
      <div class="card-head"><strong>Médias unifiés</strong><div></div></div>
      <div class="filters">
        <input class="input" id="media-product-id" placeholder="Product UUID">
        <input class="input" id="media-alt" placeholder="Alt image">
        <input class="input" id="media-file" type="file" accept="image/jpeg,image/png,image/webp">
        <button class="btn" id="media-upload">Upload</button>
        <button class="btn" id="media-load">Charger galerie</button>
      </div>
      <div id="media-list"></div>
    </section>
    <section class="card table-wrap admin-table-card">
      <div class="card-head"><strong>Catalogue produits</strong><div></div></div>
      <table>
        <thead><tr><th>Image</th><th>Marque</th><th>Nom</th><th>SKU</th><th>Statut</th><th>Prix d'achat</th><th>Marge</th><th>Prix</th><th>Score SEO</th><th>Type</th></tr></thead>
        <tbody id="products-body"></tbody>
      </table>
    </section>
    ${confirmActionModal("confirm-delete-media", "Supprimer l'image", "Confirmer la suppression de cette image produit ?")}
  `;

  let currentMedia = [];
  let mediaToDelete = null;

  async function load() {
    const q = root.querySelector("#products-q").value.trim();
    const status = root.querySelector("#products-status").value;
    const type = root.querySelector("#products-type").value;
    const out = await api.searchProducts({ q, status, type, limit: 40, offset: 0 });
    const rows = (out.items || [])
      .map(
        (p) => `<tr>
          <td>
            ${
              p.has_image
                ? `<span class="product-media-status product-media-status--ok" title="Image disponible"><span class="material-symbols-outlined">image</span></span>`
                : `<span class="product-media-status product-media-status--missing" title="Image manquante"><span class="material-symbols-outlined">image_not_supported</span></span>`
            }
          </td>
          <td>${p.brand_name || "-"}</td>
          <td>${p.name || "-"}</td>
          <td>${p.sku || "-"}</td>
          <td>${badge(p.status || "draft")}</td>
          <td>${p.purchase_price === null || p.purchase_price === undefined ? "-" : Number(p.purchase_price).toFixed(2)}</td>
          <td>${p.margin === null || p.margin === undefined ? "-" : Number(p.margin).toFixed(2)}</td>
          <td>${p.sale_price === null || p.sale_price === undefined ? Number(p.price || 0).toFixed(2) : Number(p.sale_price || 0).toFixed(2)}</td>
          <td>${p.seo_score === null || p.seo_score === undefined ? "-" : Number(p.seo_score).toFixed(2)}</td>
          <td>${p.type || "-"}</td>
        </tr>`
      )
      .join("");
    root.querySelector("#products-body").innerHTML = rows || `<tr><td colspan="10">Aucun produit</td></tr>`;
  }

  async function loadMedia() {
    const productId = root.querySelector("#media-product-id").value.trim();
    if (!productId) {
      root.querySelector("#media-list").innerHTML = fieldError("Renseigne un Product UUID.");
      return;
    }
    const out = await api.listProductImages({ productId });
    currentMedia = Array.isArray(out.items) ? out.items : [];
    root.querySelector("#media-list").innerHTML = currentMedia.length
      ? mediaPicker(currentMedia)
      : `<p class="admin-muted">Aucune image pour ce produit.</p>`;

    root.querySelectorAll("[data-media-del]").forEach((btn) => {
      btn.addEventListener("click", () => {
        mediaToDelete = Number(btn.getAttribute("data-media-del") || 0);
        root.querySelector("#confirm-delete-media")?.showModal();
      });
    });
    root.querySelectorAll("[data-media-up], [data-media-down]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const up = btn.hasAttribute("data-media-up");
        const id = Number(btn.getAttribute(up ? "data-media-up" : "data-media-down") || 0);
        const idx = currentMedia.findIndex((m) => Number(m.id) === id);
        const target = up ? idx - 1 : idx + 1;
        if (idx < 0 || target < 0 || target >= currentMedia.length) return;
        const copy = [...currentMedia];
        const [item] = copy.splice(idx, 1);
        copy.splice(target, 0, item);
        const orders = copy.map((m, i) => ({ id: Number(m.id), position: i + 1 }));
        await api.reorderProductImages(orders);
        showToast("Ordre des images mis à jour");
        await loadMedia();
      });
    });
  }

  root.querySelector("#products-refresh").addEventListener("click", () => {
    load().catch((e) => (root.querySelector("#products-body").innerHTML = `<tr><td colspan="5">${e.message}</td></tr>`));
  });

  root.querySelector("#bulk-apply").addEventListener("click", async () => {
    const ids = root
      .querySelector("#products-ids")
      .value.split("\n")
      .map((v) => v.trim())
      .filter(Boolean);
    const status = root.querySelector("#bulk-status").value;
    const operations = ids.map((id) => ({ id, changes: { status } }));
    root.querySelector("#bulk-preview").innerHTML = bulkPreviewPanel(operations.map((o) => ({ id: o.id, status })));
    if (operations.length === 0) {
      showToast("Ajoute au moins un ID produit.");
      return;
    }
    try {
      await api.bulkProducts({ operations });
      showToast("Bulk produits applique");
      await load();
    } catch (e) {
      showToast(`Erreur bulk: ${e.message}`);
    }
  });

  root.querySelector("#edit-save").addEventListener("click", async () => {
    const productId = root.querySelector("#edit-product-id").value.trim();
    const seoTitle = root.querySelector("#edit-seo-title").value.trim();
    const seoDescription = root.querySelector("#edit-seo-description").value.trim();
    if (!productId) {
      root.querySelector("#edit-error").innerHTML = fieldError("Product UUID requis.");
      return;
    }
    try {
      await api.upsertTranslation({
        entity: "product",
        locale: "fr",
        productId,
        name: seoTitle || "Produit",
        seoTitle,
        seoDescription,
      });
      root.querySelector("#edit-error").innerHTML = "";
      showToast("SEO produit mis à jour (traduction fr)");
    } catch (e) {
      root.querySelector("#edit-error").innerHTML = fieldError(e.message);
    }
  });

  root.querySelector("#media-load").addEventListener("click", () => {
    loadMedia().catch((e) => (root.querySelector("#media-list").innerHTML = fieldError(e.message)));
  });

  root.querySelector("#media-upload").addEventListener("click", async () => {
    try {
      const productId = root.querySelector("#media-product-id").value.trim();
      const alt = root.querySelector("#media-alt").value.trim();
      const fileInput = root.querySelector("#media-file");
      const file = fileInput.files && fileInput.files[0];
      if (!productId || !file) {
        root.querySelector("#media-list").innerHTML = fieldError("Product UUID + fichier image requis.");
        return;
      }
      await api.uploadProductImage(productId, file, alt);
      showToast("Image uploadée");
      fileInput.value = "";
      await loadMedia();
    } catch (e) {
      root.querySelector("#media-list").innerHTML = fieldError(e.message);
    }
  });

  root.querySelector("[data-close-modal='confirm-delete-media']")?.addEventListener("click", () => {
    root.querySelector("#confirm-delete-media")?.close();
  });
  root.querySelector("[data-confirm-modal='confirm-delete-media']")?.addEventListener("click", async () => {
    try {
      if (mediaToDelete) {
        await api.deleteProductImage(mediaToDelete);
        showToast("Image supprimée");
      }
      mediaToDelete = null;
      root.querySelector("#confirm-delete-media")?.close();
      await loadMedia();
    } catch (e) {
      root.querySelector("#confirm-delete-media")?.close();
      root.querySelector("#media-list").innerHTML = fieldError(e.message);
    }
  });

  await load();
}
