import type { Metadata } from "next";
import { UnitsView } from "@/components/units/units-view";

export const metadata: Metadata = {
  title: "Unités de mesure",
};

export default function UnitsPage() {
  return <UnitsView />;
}