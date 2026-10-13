import type { Metadata } from "next";
import { DashboardHome } from "@/components/app/dashboard-home";

export const metadata: Metadata = {
  title: "Tableau de bord",
};

export default function HomePage() {
  return <DashboardHome />;
}