import { Badge } from "@/components/ui/badge";
import { EntryStatusBadge } from "@/components/stock-entries/entry-status-badge";
import type { Sale } from "@/lib/api/sales";

const VARIANTS = { paid: "success", partial: "warning", unpaid: "danger" } as const;

/** Une vente annulée affiche son statut d'annulation, sinon son état de paiement. */
export function SaleStatusBadge({ sale }: { sale: Sale }) {
  if (sale.status === "cancelled") {
    return <EntryStatusBadge status="cancelled" label={sale.status_label} />;
  }

  return <Badge variant={VARIANTS[sale.payment_status]}>{sale.payment_status_label}</Badge>;
}