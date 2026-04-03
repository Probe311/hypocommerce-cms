const initialState = {
  route: "dashboard",
  cmsSection: null,
  auth: {
    token: "",
    csrfToken: "",
    role: "",
    email: "",
  },
  search: "",
};

const STORAGE_KEY = "hypocommerce_admin_state";
const LEGACY_STORAGE_KEY = "nexora_admin_state";

const persisted = (() => {
  try {
    let raw = sessionStorage.getItem(STORAGE_KEY);
    if (!raw) {
      raw = sessionStorage.getItem(LEGACY_STORAGE_KEY);
      if (raw) {
        sessionStorage.setItem(STORAGE_KEY, raw);
        sessionStorage.removeItem(LEGACY_STORAGE_KEY);
      }
    }
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
})();

export const state = persisted ? { ...initialState, ...persisted } : initialState;

export function setState(patch) {
  Object.assign(state, patch);
  sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
}

export function clearAuth() {
  state.auth = { token: "", csrfToken: "", role: "", email: "" };
  sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
}
