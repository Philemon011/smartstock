import {
  LayoutDashboard,
  Package,
  PackagePlus,
  Ruler,
  Tags,
  Truck,
  Warehouse,
  type LucideIcon,
} from "lucide-react";

export type NavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
  /** Droit exigé, fourni par le serveur : l'entrée du menu n'apparaît que si la personne l'a. */
  ability?: "manage_stock";
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
      { href: "/warehouses", label: "Entrepôts", icon: Warehouse },
    ],
  },
];