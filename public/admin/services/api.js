import { state } from "./state.js";

const apiCallLog = [];

function pushApiLog(entry) {
  apiCallLog.unshift({ at: new Date().toISOString(), ...entry });
  if (apiCallLog.length > 60) apiCallLog.length = 60;
}

function getAdminTokenForLegacy() {
  const token = String(state.auth.token || "");
  return token.startsWith("ui-review-token") ? "" : token;
}

function seoGetHeaders() {
  const isUiReviewToken = String(state.auth.token || "").startsWith("ui-review-token");
  if (isUiReviewToken) {
    return { "X-UI-Review": "1" };
  }
  return {
    ...(state.auth.token ? { Authorization: `Bearer ${state.auth.token}` } : {}),
    ...(state.auth.csrfToken ? { "X-CSRF-Token": state.auth.csrfToken } : {}),
  };
}

async function request(path, payload = {}, options = {}) {
  const headers = { "Content-Type": "application/json" };
  const isUiReviewToken = String(state.auth.token || "").startsWith("ui-review-token");
  if (isUiReviewToken) {
    headers["X-UI-Review"] = "1";
  } else {
    if (state.auth.token) headers.Authorization = `Bearer ${state.auth.token}`;
    if (state.auth.csrfToken) headers["X-CSRF-Token"] = state.auth.csrfToken;
  }
  if (options.token) headers.Authorization = `Bearer ${options.token}`;
  if (options.csrfToken) headers["X-CSRF-Token"] = options.csrfToken;

  const endpoint = `/api/v1/admin/${path}`;
  const res = await fetch(endpoint, {
    method: "POST",
    headers,
    body: JSON.stringify(payload),
  });

  const json = await res.json().catch(() => ({}));
  if (!res.ok) {
    const code = Number(res.status || 0);
    const apiError = String(json.error || `HTTP_${code}`);
    const messageMap = {
      401: "Session invalide ou expirée",
      403: "Action non autorisée",
      409: "Conflit métier, vérifier les données",
      422: "Données invalides, vérifie le formulaire",
      500: "Erreur serveur, réessaie",
    };
    const message = messageMap[code] || apiError;
    pushApiLog({ path: endpoint, ok: false, status: code, message });
    throw new Error(message);
  }
  pushApiLog({ path: endpoint, ok: true, status: Number(res.status || 200) });
  return json;
}

async function seoEeatGet(pathWithQuery) {
  const res = await fetch(`/api/v1/admin/seo/eeat/${pathWithQuery}`, {
    method: "GET",
    headers: seoGetHeaders(),
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) {
    const code = Number(res.status || 0);
    const messageMap = {
      401: "Session invalide ou expirée",
      403: "Action non autorisée",
      500: "Erreur serveur, réessaie",
    };
    throw new Error(messageMap[code] || String(json.error || `HTTP_${code}`));
  }
  return json;
}

async function requestLegacy(path, payload, { isFormData = false } = {}) {
  const headers = {};
  const adminToken = getAdminTokenForLegacy();
  if (adminToken) {
    headers["X-Admin-Token"] = adminToken;
    if (state.auth.csrfToken) headers["X-CSRF-Token"] = state.auth.csrfToken;
  }
  const endpoint = `/admin/api/${path}`;
  const res = await fetch(endpoint, {
    method: "POST",
    headers,
    body: isFormData ? payload : JSON.stringify(payload),
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) {
    const message = String(json.error || `LEGACY_HTTP_${res.status}`);
    pushApiLog({ path: endpoint, ok: false, status: Number(res.status || 500), message });
    throw new Error(message);
  }
  pushApiLog({ path: endpoint, ok: true, status: Number(res.status || 200) });
  return json;
}

export const api = {
  login(email, password) {
    return request("auth/login", { email, password });
  },
  searchOrders(payload) {
    return request("search/orders", payload);
  },
  searchProducts(payload) {
    return request("search/products", payload);
  },
  searchCustomers(payload) {
    return request("search/customers", payload);
  },
  segments(payload) {
    return request("crm/segments", payload);
  },
  coupons(payload) {
    return request("coupons", payload);
  },
  updateOrderStatus(payload) {
    return request("orders/status", payload);
  },
  updateOrderShipment(payload) {
    return request("orders/shipments", payload);
  },
  refundOrder(payload) {
    return request("orders/refunds", payload);
  },
  splitOrderShipments(payload) {
    return request("orders/split-shipments", payload);
  },
  bulkProducts(payload) {
    return request("products/bulk", payload);
  },
  upsertPage(payload) {
    return request("pages", payload);
  },
  upsertBlog(payload) {
    return request("blog/articles", payload);
  },
  upsertFaq(payload) {
    return request("faq/items", payload);
  },
  upsertLegal(payload) {
    return request("legal/pages", payload);
  },
  upsertTranslation(payload) {
    return request("translations/upsert", payload);
  },
  listPlugins() {
    return request("plugins/list", {});
  },
  togglePlugin(payload) {
    return request("plugins/toggle", payload);
  },
  updatePluginConfig(payload) {
    return request("plugins/config", payload);
  },
  listTaxRules() {
    return request("settings/taxes/list", {});
  },
  upsertTaxRule(payload) {
    return request("settings/taxes/upsert", payload);
  },
  toggleTaxRule(payload) {
    return request("settings/taxes/toggle", payload);
  },
  listShippingCarriers() {
    return request("settings/shipping/list", {});
  },
  upsertShippingCarrier(payload) {
    return request("settings/shipping/upsert", payload);
  },
  toggleShippingCarrier(payload) {
    return request("settings/shipping/toggle", payload);
  },
  listPaymentMethods() {
    return request("settings/payments/list", {});
  },
  upsertPaymentMethod(payload) {
    return request("settings/payments/upsert", payload);
  },
  togglePaymentMethod(payload) {
    return request("settings/payments/toggle", payload);
  },
  getTrackingSettings() {
    return request("settings/tracking/get", {});
  },
  saveTrackingSettings(payload) {
    return request("settings/tracking/save", payload);
  },
  listProductImages(payload) {
    return request("products/images/list", payload);
  },
  async uploadProductImage(productId, file, alt = "") {
    const fd = new FormData();
    fd.append("product_id", productId);
    fd.append("alt", alt);
    fd.append("image", file);
    return requestLegacy("upload-image.php", fd, { isFormData: true });
  },
  async deleteProductImage(imageId) {
    const fd = new FormData();
    fd.append("image_id", String(imageId));
    return requestLegacy("delete-image.php", fd, { isFormData: true });
  },
  reorderProductImages(orders) {
    return requestLegacy("reorder-images.php", { orders });
  },
  getSeoSettings() {
    return request("seo/eeat/settings", {});
  },
  saveSeoSettings(payload) {
    return request("seo/eeat/settings", payload);
  },
  previewSeoSettings(entityType) {
    return fetch(`/api/v1/admin/seo/eeat/settings/preview?entityType=${encodeURIComponent(entityType)}`, {
      method: "GET",
      headers: seoGetHeaders(),
    }).then((r) => r.json());
  },
  getSeoCoverageReport() {
    return fetch("/api/v1/admin/seo/eeat/coverage-report", {
      method: "GET",
      headers: seoGetHeaders(),
    }).then((r) => r.json());
  },
  getSeoOverview() {
    return seoEeatGet("overview");
  },
  getSeoProgress() {
    return seoEeatGet("progress");
  },
  getSeoRunTrends(limit = 20) {
    return seoEeatGet(`run-trends?limit=${encodeURIComponent(String(limit))}`);
  },
  getApiCallLog() {
    return [...apiCallLog];
  },
};
