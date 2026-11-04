import { Badge } from "@/components/ui/badge";
import type { StockEntryStatus } from "@/lib/api/stock-entries";

const VARIANTS = {
  draft: "warning",
  validated: "success",
  cancelled: "danger",
} as const;

export function EntryStatusBadge({ status, label }: { status: StockEntryStatus; label: string }) {
  return <Badge variant={VARIANTS[status]}>{label}</Badge>;
}