import type { Metadata } from "next";
import { CompanySettings } from "@/components/company/company-settings";

export const metadata: Metadata = {
  title: "Entreprise",
};

export default function CompanySettingsPage() {
  return <CompanySettings />;
}