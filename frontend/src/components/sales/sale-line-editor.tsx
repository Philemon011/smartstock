"use client";

import { Trash2 } from "lucide-react";
import { MICRO_ZERO, fromMicro, toMicro } from "@/components/stock-exits/exit-line-editor";
import { AffixInput } from "@/components/ui/affix-input";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { RemoteCombobox } from "@/components/ui/remote-combobox";
import { SegmentedControl } from "@/components/ui/segmented-control";
import { Select } from "@/components/ui/select";
import { useArticleStock } from "@/hooks/use-stock-entries";
import { useActiveMembership } from "@/components/session/session-provider";
import type { Article } from "@/lib/api/articles";
import { listAvailableArticles, toArticle, type AvailableArticle } from "@/lib/api/available-articles";
import { decimalToString } from "@/lib/decimal";
import { formatMoney, formatQuantity } from "@/lib/format";
import {
  priceString,
  toMoney,
  type SaleLineComputed,
  type SaleLineRow,
} from "@/lib/sale-math";

export type SaleLineField =
  | "article_id"
  | "packaging_id"
  | "entered_quantity"
  | "unit_price"
  | "discount_type"
  | "discount_value";

export type SaleLineErrors = Partial<Record<SaleLineField, string>>;

const BASE_UNIT = "__base__";

function StockSection({
  article,
  warehouseId,
  requested,
  alreadyRequested,
}: {
  article: Article;
  warehouseId: string;
  requested: string | null;
  alreadyRequested: bigint;
}) {
  const query = useArticleStock(article.id);

  if (!warehouseId) {
    return <p className="text-sm text-muted-foreground">Choisissez l&apos;entrepôt pour voir le stock.</p>;
  }

  if (query.isPending) {
    return <p className="text-sm text-muted-foreground">Chargement du stock…</p>;
  }

  if (query.isError) {
    return <p className="text-sm text-danger">Impossible de charger le stock de cet article.</p>;
  }

  const symbol = article.unit.symbol;
  const lots = query.data.lots.filter(
    (lot) => lot.warehouse.id === warehouseId && (toMicro(lot.quantity_remaining) ?? MICRO_ZERO) > MICRO_ZERO,
  );

  const isExpired = (days: number | null) => days !== null && days < 0;
  const sum = (list: typeof lots) =>
    list.reduce((total, lot) => total + (toMicro(lot.quantity_remaining) ?? MICRO_ZERO), MICRO_ZERO);

  const available = sum(lots.filter((lot) => !isExpired(lot.days_to_expiry)));
  const expired = sum(lots.filter((lot) => isExpired(lot.days_to_expiry)));
  const free = available > alreadyRequested ? available - alreadyRequested : MICRO_ZERO;

  const requestedMicro = toMicro(requested);
  const exceeds = requestedMicro !== null && requestedMicro > free;

  return (
    <div className="space-y-1 text-sm">
      <p className="text-muted-foreground">
        {available === MICRO_ZERO ? (
          "Aucun stock vendable dans cet entrepôt."
        ) : (
          <>
            Disponible :{" "}
            <span className="font-medium tabular-nums text-foreground">
              {formatQuantity(fromMicro(free))} {symbol}
            </span>
          </>
        )}
        {expired > MICRO_ZERO ? (
          <span>
            {" "}
            · {formatQuantity(fromMicro(expired))} {symbol} expiré(s), non vendable(s)
          </span>
        ) : null}
      </p>

      {exceeds ? (
        <p className="text-danger">
          Quantité supérieure au stock disponible ({formatQuantity(fromMicro(free))} {symbol}).
        </p>
      ) : null}
    </div>
  );
}

type SaleLineEditorProps = {
  row: SaleLineRow;
  index: number;
  errors: SaleLineErrors;
  computed: SaleLineComputed;
  currency: string;
  decimals: number;
  warehouseId: string;
  alreadyRequested: bigint;
  onChange: (patch: Partial<SaleLineRow>) => void;
  onRemove: () => void;
  removable: boolean;
};

export function SaleLineEditor({
  row,
  index,
  errors,
  computed,
  currency,
  decimals,
  warehouseId,
  alreadyRequested,
  onChange,
  onRemove,
  removable,
}: SaleLineEditorProps) {
    const { tenant } = useActiveMembership();
  const article = row.article;
  const packaging = article?.packagings.find((item) => item.id === row.packagingId) ?? null;
  const unitLabel = packaging?.name ?? article?.unit.symbol ?? "";
  const hasPackagings = (article?.packagings.length ?? 0) > 0;

  const catalogPrice = article ? priceString(article, row.packagingId, decimals) : "";
  const customPrice = article !== null && row.unitPrice.trim() !== "" && row.unitPrice.trim() !== catalogPrice;

  const unitOptions = article
    ? [
        { value: BASE_UNIT, label: `${article.unit.name} (${article.unit.symbol})` },
        ...article.packagings.map((item) => ({
          value: item.id,
          label: `${item.name} (= ${formatQuantity(item.quantity)} ${article.unit.symbol})`,
        })),
      ]
    : [];

  function changePackaging(value: string) {
    if (!article) return;

    const next = value === BASE_UNIT ? null : value;
    const followedCatalog = row.unitPrice === priceString(article, row.packagingId, decimals);

    onChange({
      packagingId: next,
      ...(followedCatalog ? { unitPrice: priceString(article, next, decimals) } : {}),
    });
  }

  const stockQuantity = computed.stockQuantity ? decimalToString(computed.stockQuantity, true) : null;

  return (
    <li className="rounded-lg border bg-background p-4">
      <div className="flex items-start gap-3">
        <div className="min-w-0 flex-1">
                    <Field label={`Article ${index + 1}`} error={errors.article_id}>
            <RemoteCombobox<Article>
              size="lg"
              queryKey={["stock-articles", tenant.id, warehouseId, "sellable"]}
              fetcher={(term, signal) =>
                warehouseId
                  ? listAvailableArticles(warehouseId, "sellable", term, signal)
                  : Promise.resolve([])
              }
              value={article}
              getKey={(item) => item.id}
              renderItem={(item) => (
                <span className="flex min-w-0 flex-col text-left">
                  <span className="truncate">{item.name}</span>
                  <span className="truncate text-xs text-muted-foreground">
                    {item.code} · {formatMoney(item.sale_price, currency)} / {item.unit.symbol}
                    {"available" in item
                      ? ` · ${formatQuantity((item as AvailableArticle).available)} en stock`
                      : ""}
                  </span>
                </span>
              )}
              onChange={(item) => {
                const chosen = toArticle(item as AvailableArticle);

                onChange({
                  article: chosen,
                  packagingId: null,
                  unitPrice: priceString(chosen, null, decimals),
                  discountType: "none",
                  discountValue: "",
                });
              }}
              disabled={!warehouseId}
              placeholder={warehouseId ? "Rechercher un article en stock" : "Choisissez d'abord l'entrepôt"}
              searchPlaceholder="Nom, code ou code-barres"
              emptyMessage="Aucun article en stock dans cet entrepôt ne correspond."
            />
          </Field>
        </div>

        {removable ? (
          <Button
            type="button"
            variant="ghost"
            size="lg"
            className="mt-[1.625rem] shrink-0 px-3"
            aria-label={`Retirer la ligne ${index + 1}`}
            onClick={onRemove}
          >
            <Trash2 strokeWidth={1.5} aria-hidden />
          </Button>
        ) : null}
      </div>

      {article ? (
        <div className="mt-4 space-y-4">
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {hasPackagings ? (
              <Field label="Vendu en" error={errors.packaging_id}>
                <Select
                  size="lg"
                  options={unitOptions}
                  value={row.packagingId ?? BASE_UNIT}
                  onValueChange={changePackaging}
                />
              </Field>
            ) : null}

            <Field label="Quantité" error={errors.entered_quantity}>
              <AffixInput
                size="lg"
                inputMode="decimal"
                autoComplete="off"
                placeholder="0"
                suffix={unitLabel}
                value={row.quantity}
                onChange={(event) => onChange({ quantity: event.target.value })}
              />
            </Field>

            <Field
              label={hasPackagings ? `Prix par ${unitLabel}` : "Prix unitaire"}
              error={errors.unit_price}
              hint={
                customPrice && computed.listPrice
                  ? `Prix catalogue : ${formatMoney(toMoney(computed.listPrice, decimals), currency)}`
                  : undefined
              }
            >
              <AffixInput
                size="lg"
                inputMode="decimal"
                autoComplete="off"
                placeholder="0"
                suffix={currency}
                value={row.unitPrice}
                onChange={(event) => onChange({ unitPrice: event.target.value })}
              />
            </Field>
          </div>

          <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
            <div className="space-y-2">
              <p className="text-sm font-medium text-foreground">Remise</p>
              <SegmentedControl
                label="Type de remise"
                options={[
                  { value: "none", label: "Aucune" },
                  { value: "percent", label: "%" },
                  { value: "amount", label: currency },
                ]}
                value={row.discountType}
                onChange={(value) => onChange({ discountType: value, discountValue: "" })}
              />
            </div>

            {row.discountType !== "none" ? (
              <div className="sm:w-56">
                <Field
                  label={row.discountType === "percent" ? "Pourcentage" : "Montant de la remise"}
                  error={errors.discount_value ?? errors.discount_type ?? computed.discountError ?? undefined}
                >
                  <AffixInput
                    size="lg"
                    inputMode="decimal"
                    autoComplete="off"
                    placeholder="0"
                    suffix={row.discountType === "percent" ? "%" : currency}
                    value={row.discountValue}
                    onChange={(event) => onChange({ discountValue: event.target.value })}
                  />
                </Field>
              </div>
            ) : null}
          </div>

          <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-end sm:justify-between">
            <div className="space-y-1 text-sm text-muted-foreground">
              {packaging && stockQuantity ? (
                <p>
                  Stock sorti :{" "}
                  <span className="font-medium tabular-nums text-foreground">
                    {formatQuantity(stockQuantity)} {article.unit.symbol}
                  </span>
                </p>
              ) : null}

              <StockSection
                article={article}
                warehouseId={warehouseId}
                requested={stockQuantity}
                alreadyRequested={alreadyRequested}
              />
            </div>

            <p className="text-sm text-muted-foreground sm:text-right">
              {computed.discount && computed.total && computed.discount.value > BigInt(0) && computed.gross ? (
                <span className="block text-xs">
                  {formatMoney(toMoney(computed.gross, decimals), currency)} − remise{" "}
                  {formatMoney(toMoney(computed.discount, decimals), currency)}
                </span>
              ) : null}
              Total de la ligne
              <span className="block text-lg font-semibold tabular-nums text-foreground">
                {computed.total ? formatMoney(toMoney(computed.total, decimals), currency) : "—"}
              </span>
            </p>
          </div>
        </div>
      ) : null}
    </li>
  );
}