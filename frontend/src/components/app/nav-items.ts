import { LayoutDashboard, Tags, Warehouse, type LucideIcon } from "lucide-react";

export type NavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
};

export const navItems: NavItem[] = [
  { href: "/", label: "Tableau de bord", icon: LayoutDashboard },
  { href: "/warehouses", label: "Entrepôts", icon: Warehouse },
  { href: "/categories", label: "Catégories", icon: Tags },
];