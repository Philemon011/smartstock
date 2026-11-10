import type { Metadata } from "next";
import { CustomersView } from "@/components/customers/customers-view";

export const metadata: Metadata = {
  title: "Clients",
};

export default function CustomersPage() {
  return <CustomersView />;
}