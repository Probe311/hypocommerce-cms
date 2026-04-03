export const NAV_ITEMS = [
  { id: "dashboard", label: "Tableau de bord", icon: "dashboard", createLabel: "Nouvelle vue" },
  { id: "orders", label: "Commandes", icon: "shopping_cart", createLabel: "Créer une commande" },
  { id: "products", label: "Produits", icon: "inventory_2", createLabel: "Créer un produit" },
  { id: "customers", label: "Clients", icon: "group", createLabel: "Créer un client" },
  { id: "cms", label: "CMS", icon: "article", createLabel: "Créer une page" },
  { id: "marketing", label: "Marketing", icon: "campaign", createLabel: "Créer une campagne" },
  { id: "inventory", label: "Inventaire", icon: "inventory", createLabel: "Ajouter un stock" },
  { id: "promotions", label: "Promotions", icon: "local_offer", createLabel: "Créer une promotion" },
  { id: "analytics", label: "Analytique", icon: "monitoring", createLabel: "Lancer un rapport" },
  { id: "seo", label: "SEO", icon: "travel_explore", createLabel: "Créer une règle SEO" },
  { id: "tracking", label: "Tracking", icon: "ads_click", createLabel: "Ajouter un outil" },
  { id: "settings", label: "Paramètres", icon: "settings", createLabel: "Ajouter un réglage" },
  { id: "plugins", label: "Plugins", icon: "extension", createLabel: "Configurer un plugin" },
];

export const NAV_BY_ID = NAV_ITEMS.reduce((acc, item) => {
  acc[item.id] = item;
  return acc;
}, {});
