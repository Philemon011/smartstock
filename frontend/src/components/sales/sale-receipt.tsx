"use client";

import { ArrowLeft, Printer } from "lucide-react";
import Link from "next/link";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { useActiveMembership } from "@/components/session/session-provider";
import { Alert } from "@/components/ui/alert";
import { Button, buttonVariants } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { SegmentedControl } from "@/components/ui/segmented-control";
import { Skeleton } from "@/components/ui/skeleton";
import { useSale } from "@/hooks/use-sales";
import { ApiError } from "@/lib/api/client";
import type { Sale } from "@/lib/api/sales";
import { formatDate, formatDateTime, formatMoney, formatQuantity } from "@/lib/format";

type Format = "a4" | "ticket";

const FORMATS = [
  { value: "a4", label: "Reçu A4" },
  { value: "ticket", label: "Ticket 80 mm" },
] as const;

/** Largeur de la feuille à 96 dpi : sert à l'adapter à l'écran. */
const PAPER_WIDTH: Record<Format, number> = { a4: 794, ticket: 302 };

const STORAGE_KEY = "receipt-format";

function readFormat(): Format {
  if (typeof window === "undefined") return "a4";

  try {
    const saved = window.localStorage.getItem(STORAGE_KEY);

    return saved === "ticket" ? "ticket" : "a4";
  } catch {
    return "a4";
  }
}

/**
 * À l'impression, seul le document est imprimé : tout le reste de la page est retiré (et non masqué),
 * il ne reste donc ni espace vide ni page blanche. Les marges de page à zéro suppriment aussi les
 * en-têtes et pieds de page du navigateur.
 */
function printCss(format: Format): string {
  return `
@page { size: ${format === "a4" ? "A4" : "80mm auto"}; margin: 0; }
#print-root { display: none; }
@media print {
  html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; height: auto !important; overflow: visible !important; }
  body > *:not(#print-root) { display: none !important; }
  #print-root { display: block !important; }
  #print-root, #print-root * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  #print-root tr, #print-root li { break-inside: avoid; }
}
`;
}

const isPositive = (value: string): boolean => /[1-9]/.test(value);

type DocumentProps = {
  sale: Sale;
  company: string;
  currency: string;
  minHeight?: string;
};

// ------------------------------------------------------------------ Reçu A4

function StatusPill({ sale }: { sale: Sale }) {
  const tone =
    sale.status === "cancelled"
      ? "border-[#fecaca] bg-[#fef2f2] text-[#b91c1c]"
      : sale.payment_status === "paid"
        ? "border-[#a7f3d0] bg-[#ecfdf5] text-[#047857]"
        : sale.payment_status === "partial"
          ? "border-[#fde68a] bg-[#fffbeb] text-[#b45309]"
          : "border-[#fecaca] bg-[#fef2f2] text-[#b91c1c]";

  const label = sale.status === "cancelled" ? sale.status_label : sale.payment_status_label;

  return (
    <span
      className={`mt-2 inline-block rounded-full border px-3 py-1 text-[8pt] font-semibold uppercase tracking-[0.08em] ${tone}`}
    >
      {label}
    </span>
  );
}

function Meta({ label, value, sub }: { label: string; value: string; sub?: string | null }) {
  return (
    <div>
      <dt className="text-[7.5pt] font-medium uppercase tracking-[0.1em] text-[#64748b]">{label}</dt>
      <dd className="mt-1 text-[10.5pt] font-medium">{value}</dd>
      {sub ? <p className="mt-0.5 text-[8.5pt] text-[#64748b]">{sub}</p> : null}
    </div>
  );
}

function TotalLine({ label, value, tone }: { label: string; value: string; tone?: "warning" }) {
  return (
    <div className="flex items-baseline justify-between gap-4">
      <dt className="text-[#475569]">{label}</dt>
      <dd className={`tabular-nums ${tone === "warning" ? "font-semibold text-[#b45309]" : ""}`}>{value}</dd>
    </div>
  );
}

function A4Document({ sale, company, currency, minHeight }: DocumentProps) {
  const lines = sale.lines ?? [];
  const payments = (sale.payments ?? []).filter((payment) => payment.status === "valid");
  const cancelled = sale.status === "cancelled";
  const hasDiscount = isPositive(sale.discount_total);
  const hasBalance = !cancelled && isPositive(sale.balance_due);
  const money = (value: string) => formatMoney(value, currency);

  return (
    <div
      style={{ width: "210mm", minHeight }}
      className="relative bg-white text-[10pt] leading-normal text-[#0f172a]"
    >
      {cancelled ? (
        <div aria-hidden className="pointer-events-none absolute inset-0 z-10 flex items-center justify-center">
          <span className="-rotate-[28deg] border-[6px] border-[#b91c1c] px-8 py-2 text-[64pt] font-semibold uppercase tracking-[0.15em] text-[#b91c1c] opacity-[0.12]">
            Annulée
          </span>
        </div>
      ) : null}

      {/* Le tableau extérieur répète une marge en haut et en bas de chaque page. */}
      <table className="w-full border-collapse">
        <thead>
          <tr>
            <td>
              <div className="h-[14mm]" />
            </td>
          </tr>
        </thead>

        <tbody>
          <tr>
            <td className="px-[16mm] align-top">
              <header className="flex items-start justify-between gap-8 border-b-2 border-[#0f172a] pb-6">
                <div>
                  <p className="font-display text-[22pt] font-semibold leading-none tracking-heading">{company}</p>
                  <p className="mt-2 text-[9pt] text-[#64748b]">Reçu de vente</p>
                </div>

                <div className="text-right">
                  <p className="text-[7.5pt] font-medium uppercase tracking-[0.12em] text-[#64748b]">Reçu n°</p>
                  <p className="mt-1 text-[16pt] font-semibold tabular-nums">{sale.number}</p>
                  <StatusPill sale={sale} />
                </div>
              </header>

              <dl className="mt-6 grid grid-cols-3 gap-6">
                <Meta
                  label="Date"
                  value={formatDate(sale.sale_date)}
                  sub={sale.created_at ? formatDateTime(sale.created_at) : null}
                />
                <Meta
                  label="Client"
                  value={sale.customer?.name ?? "Client de passage"}
                  sub={sale.customer?.phone ?? null}
                />
                <Meta label="Servi par" value={sale.created_by ?? "—"} sub={sale.warehouse.name} />
              </dl>

              <table className="mt-8 w-full border-collapse text-[9.5pt]">
                <thead>
                  <tr className="bg-[#f1f5f9] text-left text-[7.5pt] font-semibold uppercase tracking-[0.08em] text-[#475569]">
                    <th className="px-3 py-2.5">Désignation</th>
                    <th className="px-3 py-2.5 text-right">Qté</th>
                    <th className="px-3 py-2.5 text-right">Prix unitaire</th>
                    {hasDiscount ? <th className="px-3 py-2.5 text-right">Remise</th> : null}
                    <th className="px-3 py-2.5 text-right">Total</th>
                  </tr>
                </thead>

                <tbody>
                  {lines.map((line) => {
                    const inPackaging = line.entered_unit !== line.article.unit.symbol;

                    return (
                      <tr key={line.id} className="border-b border-[#e2e8f0] align-top">
                        <td className="px-3 py-3">
                          <p className="font-medium">{line.article.name}</p>
                          <p className="mt-0.5 text-[8pt] text-[#64748b]">{line.article.code}</p>
                        </td>
                        <td className="px-3 py-3 text-right tabular-nums">
                          {formatQuantity(line.entered_quantity)} {line.entered_unit}
                          {inPackaging ? (
                            <p className="mt-0.5 text-[8pt] text-[#64748b]">
                              = {formatQuantity(line.quantity)} {line.article.unit.symbol}
                            </p>
                          ) : null}
                        </td>
                        <td className="px-3 py-3 text-right tabular-nums">{money(line.unit_price)}</td>
                        {hasDiscount ? (
                          <td className="px-3 py-3 text-right tabular-nums">
                            {line.discount ? (
                              <>
                                − {money(line.discount.amount)}
                                {line.discount.type === "percent" ? (
                                  <p className="mt-0.5 text-[8pt] text-[#64748b]">
                                    {formatQuantity(line.discount.value)} %
                                  </p>
                                ) : null}
                              </>
                            ) : (
                              <span className="text-[#94a3b8]">—</span>
                            )}
                          </td>
                        ) : null}
                        <td className="px-3 py-3 text-right font-medium tabular-nums">{money(line.line_total)}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>

              <div className="mt-6 flex justify-end">
                <dl className="w-[78mm] space-y-1.5 text-[10pt]">
                  {hasDiscount ? (
                    <>
                      <TotalLine label="Sous-total" value={money(sale.subtotal)} />
                      <TotalLine label="Remises" value={`− ${money(sale.discount_total)}`} />
                    </>
                  ) : null}

                  <div className="flex items-baseline justify-between gap-4 border-t-2 border-[#0f172a] pt-3">
                    <dt className="text-[9pt] font-semibold uppercase tracking-[0.1em]">Total</dt>
                    <dd className="text-[16pt] font-semibold tabular-nums">{money(sale.total)}</dd>
                  </div>

                  {!cancelled ? (
                    <>
                      <TotalLine label="Payé" value={money(sale.amount_paid)} />
                      {hasBalance ? (
                        <TotalLine label="Reste à payer" value={money(sale.balance_due)} tone="warning" />
                      ) : null}
                    </>
                  ) : null}
                </dl>
              </div>

              {!cancelled && payments.length > 0 ? (
                <section className="mt-8">
                  <h2 className="text-[7.5pt] font-semibold uppercase tracking-[0.1em] text-[#64748b]">Paiements</h2>

                  <table className="mt-2 w-full border-collapse text-[9pt]">
                    <tbody>
                      {payments.map((payment) => (
                        <tr key={payment.id} className="border-b border-[#e2e8f0]">
                          <td className="px-3 py-2 font-medium">{payment.method_label}</td>
                          <td className="px-3 py-2 text-[#64748b]">{formatDateTime(payment.paid_at)}</td>
                          <td className="px-3 py-2 text-[#64748b]">
                            {payment.reference ? `Réf. ${payment.reference}` : ""}
                            {payment.tendered !== null && isPositive(payment.change_given)
                              ? `Reçu ${money(payment.tendered)} · Rendu ${money(payment.change_given)}`
                              : ""}
                          </td>
                          <td className="px-3 py-2 text-right font-medium tabular-nums">{money(payment.amount)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </section>
              ) : null}

              {cancelled && sale.cancel_reason ? (
                <p className="mt-8 rounded-md border border-[#fecaca] bg-[#fef2f2] px-4 py-3 text-[9pt] text-[#b91c1c]">
                  Vente annulée. Motif : {sale.cancel_reason}
                </p>
              ) : null}

              <footer className="mt-10 flex items-end justify-between gap-6 border-t border-[#e2e8f0] pt-4 text-[8.5pt] text-[#64748b]">
                <p>Merci de votre confiance.</p>
                <p className="text-right">
                  {sale.number} · imprimé le {formatDateTime(new Date().toISOString())}
                </p>
              </footer>
            </td>
          </tr>
        </tbody>

        <tfoot>
          <tr>
            <td>
              <div className="h-[14mm]" />
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  );
}

// -------------------------------------------------------------- Ticket 80 mm

function Rule({ strong }: { strong?: boolean }) {
  return <div className={strong ? "border-t-2 border-black" : "border-t border-black"} />;
}

function TicketRow({ label, value, bold }: { label: string; value: string; bold?: boolean }) {
  return (
    <div className={`flex justify-between gap-3 ${bold ? "font-semibold" : ""}`}>
      <span>{label}</span>
      <span className="tabular-nums">{value}</span>
    </div>
  );
}

function TicketDocument({ sale, company, currency }: DocumentProps) {
  const lines = sale.lines ?? [];
  const payments = (sale.payments ?? []).filter((payment) => payment.status === "valid");
  const cancelled = sale.status === "cancelled";
  const hasDiscount = isPositive(sale.discount_total);
  const hasBalance = !cancelled && isPositive(sale.balance_due);
  const cash = payments.find((payment) => payment.tendered !== null && isPositive(payment.change_given));
  const money = (value: string) => formatMoney(value, currency);

  return (
    <div style={{ width: "80mm" }} className="bg-white px-[4mm] py-[5mm] text-[9pt] leading-snug text-black">
      <header className="space-y-1 text-center">
        <p className="font-display text-[13pt] font-semibold leading-tight tracking-heading">{company}</p>
        <p className="text-[7.5pt] font-medium uppercase tracking-[0.18em]">Ticket de vente</p>
        <p className="pt-1 text-[11pt] font-semibold tabular-nums">{sale.number}</p>
        <p className="text-[8pt]">{sale.created_at ? formatDateTime(sale.created_at) : formatDate(sale.sale_date)}</p>
      </header>

      {cancelled ? (
        <p className="mt-3 border-2 border-black py-1 text-center text-[9pt] font-bold uppercase tracking-[0.15em]">
          Vente annulée
        </p>
      ) : null}

      <div className="mt-3 space-y-0.5 text-[8pt]">
        <p>Client : {sale.customer?.name ?? "Client de passage"}</p>
        {sale.created_by ? <p>Servi par : {sale.created_by}</p> : null}
      </div>

      <div className="my-3">
        <Rule />
      </div>

      <ul className="space-y-2.5">
        {lines.map((line) => (
          <li key={line.id}>
            <p className="font-semibold">{line.article.name}</p>
            <div className="flex justify-between gap-3">
              <span>
                {formatQuantity(line.entered_quantity)} {line.entered_unit} × {money(line.unit_price)}
              </span>
              <span className="tabular-nums">{money(line.gross_total)}</span>
            </div>
            {line.discount ? (
              <div className="flex justify-between gap-3 text-[8pt]">
                <span>
                  Remise{" "}
                  {line.discount.type === "percent" ? `${formatQuantity(line.discount.value)} %` : ""}
                </span>
                <span className="tabular-nums">− {money(line.discount.amount)}</span>
              </div>
            ) : null}
          </li>
        ))}
      </ul>

      <div className="my-3">
        <Rule />
      </div>

      <div className="space-y-1">
        {hasDiscount ? (
          <>
            <TicketRow label="Sous-total" value={money(sale.subtotal)} />
            <TicketRow label="Remises" value={`− ${money(sale.discount_total)}`} />
          </>
        ) : null}
        <div className="flex items-baseline justify-between gap-3 pt-1 text-[12pt] font-bold">
          <span>TOTAL</span>
          <span className="tabular-nums">{money(sale.total)}</span>
        </div>
      </div>

      {!cancelled && payments.length > 0 ? (
        <>
          <div className="my-3">
            <Rule />
          </div>
          <div className="space-y-1">
            {payments.map((payment) => (
              <TicketRow key={payment.id} label={payment.method_label} value={money(payment.amount)} />
            ))}
            {cash && cash.tendered ? (
              <>
                <TicketRow label="Espèces reçues" value={money(cash.tendered)} />
                <TicketRow label="Rendu de monnaie" value={money(cash.change_given)} bold />
              </>
            ) : null}
          </div>
        </>
      ) : null}

      {hasBalance ? (
        <div className="mt-3 border-2 border-black px-2 py-1.5">
          <div className="flex items-baseline justify-between gap-3 font-bold">
            <span>RESTE À PAYER</span>
            <span className="tabular-nums">{money(sale.balance_due)}</span>
          </div>
        </div>
      ) : null}

      <div className="my-3">
        <Rule />
      </div>

      <p className="text-center text-[8pt]">Merci de votre confiance.</p>
    </div>
  );
}

// ----------------------------------------------------------------- Page

/** Adapte la feuille à la largeur disponible (utile sur mobile), sans jamais l'agrandir. */
function useFitScale(naturalWidth: number) {
  const containerRef = useRef<HTMLDivElement>(null);
  const paperRef = useRef<HTMLDivElement>(null);
  const [scale, setScale] = useState(1);
  const [height, setHeight] = useState(0);

  useEffect(() => {
    const container = containerRef.current;
    const paper = paperRef.current;

    if (!container || !paper) return;

    const update = () => {
      setScale(Math.min(1, container.clientWidth / naturalWidth));
      setHeight(paper.offsetHeight);
    };

    update();

    const observer = new ResizeObserver(update);
    observer.observe(container);
    observer.observe(paper);

    return () => observer.disconnect();
  }, [naturalWidth]);

  return { containerRef, paperRef, scale, height };
}

function PrintPortal({ children }: { children: ReactNode }) {
  return createPortal(<div id="print-root">{children}</div>, document.body);
}

function Receipt({ sale, company, currency }: { sale: Sale; company: string; currency: string }) {
  const [format, setFormat] = useState<Format>(readFormat);
  const { containerRef, paperRef, scale, height } = useFitScale(PAPER_WIDTH[format]);

  function changeFormat(next: Format) {
    setFormat(next);

    try {
      window.localStorage.setItem(STORAGE_KEY, next);
    } catch {
      // Le choix n'est simplement pas mémorisé.
    }
  }

  const Document = format === "a4" ? A4Document : TicketDocument;

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <style>{printCss(format)}</style>

      <Link
        href={`/sales/${sale.id}`}
        className="inline-flex items-center gap-2 text-sm text-muted-foreground transition-colors duration-200 ease-smooth hover:text-foreground"
      >
        <ArrowLeft className="size-4" strokeWidth={1.5} aria-hidden />
        Retour à la vente
      </Link>

      <header className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-3xl">Reçu {sale.number}</h1>
          <p className="text-sm text-muted-foreground">
            Pour enregistrer en PDF, choisissez « Enregistrer au format PDF » dans la fenêtre d&apos;impression.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <SegmentedControl label="Format du document" options={FORMATS} value={format} onChange={changeFormat} />
          <Button onClick={() => window.print()}>
            <Printer strokeWidth={1.5} aria-hidden />
            Imprimer
          </Button>
        </div>
      </header>

      <div className="rounded-xl bg-muted p-3 sm:p-6">
        <div ref={containerRef}>
          <div className="mx-auto" style={{ width: PAPER_WIDTH[format] * scale, height: height * scale }}>
            <div
              ref={paperRef}
              className="bg-white shadow-popover"
              style={{ width: PAPER_WIDTH[format], transform: `scale(${scale})`, transformOrigin: "top left" }}
            >
              <Document
                sale={sale}
                company={company}
                currency={currency}
                minHeight={format === "a4" ? "297mm" : undefined}
              />
            </div>
          </div>
        </div>
      </div>

      <PrintPortal>
        <Document sale={sale} company={company} currency={currency} />
      </PrintPortal>
    </div>
  );
}

export function SaleReceipt({ id }: { id: string }) {
  const { tenant } = useActiveMembership();
  const query = useSale(id);

  if (query.isPending) {
    return <Skeleton className="mx-auto h-[32rem] w-full max-w-3xl rounded-xl" />;
  }

  if (query.isError) {
    const notFound = query.error instanceof ApiError && query.error.status === 404;

    return notFound ? (
      <EmptyState
        icon={Printer}
        title="Vente introuvable"
        description="Cette vente n'existe pas."
        action={
          <Link href="/sales" className={buttonVariants({ variant: "secondary" })}>
            Retour aux ventes
          </Link>
        }
      />
    ) : (
      <Alert variant="danger" title="Impossible de charger le reçu">
        {query.error instanceof ApiError ? query.error.message : "Une erreur inattendue est survenue. Réessayez."}
      </Alert>
    );
  }

  return <Receipt sale={query.data} company={tenant.name} currency={tenant.currency} />;
}