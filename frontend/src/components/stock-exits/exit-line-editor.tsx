"use client";

import { ChevronDown, Trash2 } from "lucide-react";
import { useState } from "react";
import { AffixInput } from "@/components/ui/affix-input";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { RemoteCombobox } from "@/components/ui/remote-combobox";
import { Select } from "@/components/ui/select";
import { useArticleStock } from "@/hooks/use-stock-entries";
import { listArticles, type Article } from "@/lib/api/articles";
import type { StockLot } from "@/lib/api/article-stock";
import { decimalToString, multiply, parseDecimal } from "@/lib/decimal";
import { formatDate, formatQuantity } from "@/lib/format";
import { cn } from "@/lib/utils";

export type ExitLineRow = {
  key: string;
  article: Article | null;
  packagingId: string | null;
  quantity: string;
  lotId: string | null;
};

export type ExitLineField = "article_id" | "packaging_id" | "entered_quantity" | "lot_id";

export type ExitLineErrors = Partial<Record<ExitLineField, string>>;

const BASE_UNIT = "__base__";
const AUTO_LOT = "__auto__";

const MICRO = BigInt(1000000);
export const MICRO_ZERO = BigInt(0);

export function emptyExitRow(): ExitLineRow {
  return { key: crypto.randomUUID(), article: null, packagingId: null, quantity: "", lotId: null };
}

/** Quantité saisie convertie dans l'unité de base de l'article (null tant que la saisie est incomplète). */
export function convertedQuantity(row: ExitLineRow): string | null {
  const article = row.article;

  if (!article) return null;

  const packaging = article.packagings.find((item) => item.id === row.packagingId) ?? null;
  const quantity = parseDecimal(row.quantity);
  const factor = parseDecimal(packaging?.quantity ?? "1");

  if (!quantity || !factor) return null;

  return decimalToString(multiply(quantity, factor), true);
}

/** Quantité en millionièmes : comparaisons exactes, sans nombres à virgule. */
export function toMicro(value: string | null | undefined): bigint | null {
  if (!value) return null;

  const match = /^(\d+)(?:\.(\d+))?$/.exec(value.trim());

  if (!match) return null;

  const fraction = (match[2] ?? "").padEnd(6, "0").slice(0, 6);

  return BigInt(match[1]) * MICRO + BigInt(fraction);
}

export function fromMicro(value: bigint): string {
  const whole = value / MICRO;
  const fraction = (value % MICRO).toString().padStart(6, "0").replace(/0+$/, "");

  return fraction ? `${whole}.${fraction}` : whole.toString();
}

function isExpired(lot: StockLot): boolean {
  return lot.days_to_expiry !== null && lot.days_to_expiry < 0;
}

function sumMicro(lots: StockLot[]): bigint {
  return lots.reduce((sum, lot) => sum + (toMicro(lot.quantity_remaining) ?? MICRO_ZERO), MICRO_ZERO);
}

type StockSectionProps = {
  article: Article;
  warehouseId: string;
  expiredOnly: boolean;
  requested: string | null;
  alreadyRequested: bigint;
  lotId: string | null;
  lotError?: string;
  onLotChange: (lotId: string | null) => void;
};

function StockSection({
  article,
  warehouseId,
  expiredOnly,
  requested,
  alreadyRequested,
  lotId,
  lotError,
  onLotChange,
}: StockSectionProps) {
  const query = useArticleStock(article.id);
  const [lotOpen, setLotOpen] = useState(lotId !== null);

  if (!warehouseId) {
    return <p className="text-sm text-muted-foreground">Choisissez l&apos;entrepôt pour voir le stock disponible.</p>;
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
  const eligible = lots.filter((lot) => (expiredOnly ? isExpired(lot) : !isExpired(lot)));

  const available = sumMicro(eligible);
  const excluded = expiredOnly ? MICRO_ZERO : sumMicro(lots.filter(isExpired));
  const free = available > alreadyRequested ? available - alreadyRequested : MICRO_ZERO;

  const chosen = lotId ? (lots.find((lot) => lot.id === lotId) ?? null) : null;
  const capacity = chosen ? (toMicro(chosen.quantity_remaining) ?? MICRO_ZERO) : free;
  const requestedMicro = toMicro(requested);
  const exceeds = requestedMicro !== null && requestedMicro > capacity;

  const lotOptions = [
    { value: AUTO_LOT, label: "Automatique : le lot qui expire en premier" },
    ...(expiredOnly ? lots.filter(isExpired) : lots).map((lot) => ({
      value: lot.id,
      label: `${lot.code} · ${formatQuantity(lot.quantity_remaining)} ${symbol}${
        lot.expiry_date ? ` · exp. ${formatDate(lot.expiry_date)}` : ""
      }${isExpired(lot) ? " (expiré)" : ""}`,
    })),
  ];

  return (
    <div className="space-y-3">
      <div className="space-y-1 text-sm">
        <p className="text-muted-foreground">
          {available === MICRO_ZERO ? (
            expiredOnly ? (
              "Aucun lot expiré dans cet entrepôt."
            ) : (
              "Aucun stock disponible dans cet entrepôt."
            )
          ) : (
            <>
              Disponible :{" "}
              <span className="font-medium tabular-nums text-foreground">
                {formatQuantity(fromMicro(free))} {symbol}
              </span>
            </>
          )}
          {excluded > MICRO_ZERO ? (
            <span> · {formatQuantity(fromMicro(excluded))} {symbol} expiré(s), non prélevé(s) automatiquement</span>
          ) : null}
        </p>

        {exceeds ? (
          <p className="text-danger">
            Quantité supérieure au stock disponible ({formatQuantity(fromMicro(capacity))} {symbol}).
          </p>
        ) : null}
      </div>

      {lotOptions.length > 1 ? (
        <div>
          <button
            type="button"
            onClick={() => setLotOpen((open) => !open)}
            aria-expanded={lotOpen}
            className="inline-flex items-center gap-1.5 rounded-sm text-sm font-medium text-primary outline-none transition-colors duration-200 ease-smooth hover:underline focus-visible:ring-4 focus-visible:ring-ring/15"
          >
            <ChevronDown
              className={cn("size-4 transition-transform duration-200 ease-smooth", lotOpen && "rotate-180")}
              strokeWidth={1.5}
              aria-hidden
            />
            Choisir un lot précis
          </button>

          {lotOpen ? (
            <div className="mt-4 max-w-xl">
              <Field
                label="Lot à prélever"
                optional
                error={lotError}
                hint="Pour un rappel de lot ou la destruction d'un lot donné. Sinon, le lot qui expire en premier est pris."
              >
                <Select
                  size="lg"
                  options={lotOptions}
                  value={lotId ?? AUTO_LOT}
                  onValueChange={(value) => onLotChange(value === AUTO_LOT ? null : value)}
                />
              </Field>
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

type ExitLineEditorProps = {
  row: ExitLineRow;
  index: number;
  errors: ExitLineErrors;
  warehouseId: string;
  expiredOnly: boolean;
  alreadyRequested: bigint;
  onChange: (patch: Partial<ExitLineRow>) => void;
  onRemove: () => void;
  removable: boolean;
};

export function ExitLineEditor({
  row,
  index,
  errors,
  warehouseId,
  expiredOnly,
  alreadyRequested,
  onChange,
  onRemove,
  removable,
}: ExitLineEditorProps) {
  const article = row.article;
  const packaging = article?.packagings.find((item) => item.id === row.packagingId) ?? null;
  const unitLabel = packaging?.name ?? article?.unit.symbol ?? "";
  const hasPackagings = (article?.packagings.length ?? 0) > 0;
  const converted = convertedQuantity(row);

  const unitOptions = article
    ? [
        { value: BASE_UNIT, label: `${article.unit.name} (${article.unit.symbol})` },
        ...article.packagings.map((item) => ({
          value: item.id,
          label: `${item.name} (= ${formatQuantity(item.quantity)} ${article.unit.symbol})`,
        })),
      ]
    : [];

  return (
    <li className="rounded-lg border bg-background p-4">
      <div className="flex items-start gap-3">
        <div className="min-w-0 flex-1">
          <Field label={`Article ${index + 1}`} error={errors.article_id}>
            <RemoteCombobox<Article>
              size="lg"
              queryKey={["exit-articles"]}
              fetcher={(term, signal) =>
                listArticles({ search: term, status: "active", categoryId: null, page: 1 }, signal).then(
                  (result) => result.data,
                )
              }
              value={article}
              getKey={(item) => item.id}
              renderItem={(item) => (
                <span className="flex min-w-0 flex-col text-left">
                  <span className="truncate">{item.name}</span>
                  <span className="truncate text-xs text-muted-foreground">
                    {item.code} · {item.unit.symbol}
                  </span>
                </span>
              )}
              onChange={(item) => onChange({ article: item, packagingId: null, lotId: null })}
              placeholder="Rechercher un article"
              searchPlaceholder="Nom, code ou code-barres"
              emptyMessage="Aucun article ne correspond."
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
          <div className="grid gap-4 sm:grid-cols-2">
            {hasPackagings ? (
              <Field label="Sorti en" error={errors.packaging_id}>
                <Select
                  size="lg"
                  options={unitOptions}
                  value={row.packagingId ?? BASE_UNIT}
                  onValueChange={(value) => onChange({ packagingId: value === BASE_UNIT ? null : value })}
                />
              </Field>
            ) : null}

            <Field label="Quantité sortie" error={errors.entered_quantity}>
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
          </div>

          {packaging && converted ? (
            <p className="text-sm text-muted-foreground">
              Stock sorti :{" "}
              <span className="font-medium tabular-nums text-foreground">
                {formatQuantity(converted)} {article.unit.symbol}
              </span>
            </p>
          ) : null}

          <div className="border-t pt-4">
            <StockSection
              article={article}
              warehouseId={warehouseId}
              expiredOnly={expiredOnly}
              requested={converted}
              alreadyRequested={alreadyRequested}
              lotId={row.lotId}
              lotError={errors.lot_id}
              onLotChange={(lotId) => onChange({ lotId })}
            />
          </div>
        </div>
      ) : null}
    </li>
  );
}