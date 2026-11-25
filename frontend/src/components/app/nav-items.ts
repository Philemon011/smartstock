import {
  ArrowLeftRight,
  Bell,
  Building2,
  LayoutDashboard,
  Package,
  PackageMinus,
  PackagePlus,
  Receipt,
  Ruler,
  ShoppingCart,
  Tags,
  Truck,
  Users,
  Warehouse,
  type LucideIcon,
} from "lucide-react";

export type NavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
  /** Droit exigé, fourni par le serveur : l'entrée du menu n'apparaît que si la personne l'a. */
    ability?: "manage_stock" | "sell" | "transfer";
  /** Pastille de compteur affichée à droite. */
  badge?: "alerts";
    /** Actif uniquement sur cette page exacte, pas sur ses sous-pages. */
  exact?: boolean;
  /** Chemins qui ne doivent pas activer ce lien. */
  exclude?: string[];
};

export type NavGroup = {
  label: string | null;
  items: NavItem[];
};

export const navGroups: NavGroup[] = [
  {
    label: null,
    items: [{ href: "/", label: "Tableau de bord", icon: LayoutDashboard }],
  },
  {
    label: "Catalogue",
    items: [
      { href: "/articles", label: "Articles", icon: Package },
      { href: "/categories", label: "Catégories", icon: Tags },
      { href: "/units", label: "Unités de mesure", icon: Ruler },
      { href: "/suppliers", label: "Fournisseurs", icon: Truck },
    ],
  },
  {
    label: "Stock",
    items: [
      { href: "/stock-entries", label: "Entrées", icon: PackagePlus, ability: "manage_stock" },
      { href: "/stock-exits", label: "Sorties", icon: PackageMinus, ability: "manage_stock" },
            { href: "/stock-transfers", label: "Transferts", icon: ArrowLeftRight, ability: "transfer" },
      { href: "/alerts", label: "Alertes", icon: Bell, badge: "alerts" },
      { href: "/warehouses", label: "Entrepôts", icon: Warehouse },
    ],
  },
    {
    label: "Ventes",
    items: [
      { href: "/sales/new", label: "Caisse", icon: ShoppingCart, ability: "sell", exact: true },
      { href: "/sales", label: "Ventes", icon: Receipt, exclude: ["/sales/new"] },
      { href: "/customers", label: "Clients", icon: Users },
    ],
  },
    {
    label: "Paramètres",
    items: [{ href: "/settings/company", label: "Entreprise", icon: Building2 }],
  },
];