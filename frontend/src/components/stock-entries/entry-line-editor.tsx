"use client";

import { ChevronDown, Plus, Trash2 } from "lucide-react";
import { useState } from "react";
import { AffixInput } from "@/components/ui/affix-input";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { RemoteCombobox } from "@/components/ui/remote-combobox";
import { Select } from "@/components/ui/select";
import { listArticles, type Article } from "@/lib/api/articles";
import {
  decimalToString,
  divide,
  multiply,
  parseDecimal,
  round,
  type Decimal,
} from "@/lib/decimal";
import { currencyDecimals, formatMoney, formatQuantity } from "@/lib/format";
import { cn } from "@/lib/utils";

export type LineRow = {
  key: string;
  article: Article | null;
  packagingId: string | null;
  quantity: string;
  unitPrice: string;
  expiryDate: string;
  lotNumber: string;
  salePrice: string;
};

export type LineField =
  | "article_id"
  | "packaging_id"
  | "entered_quantity"
  | "unit_price"
  | "expiry_date"
  | "lot_number"
  | "sale_price";

export type LineErrors = Partial<Record<LineField, string>>;

const BASE_UNIT = "__base__";

export function emptyRow(): LineRow {
  return {
    key: crypto.randomUUID(),
    article: null,
    packagingId: null,
    quantity: "",
    unitPrice: "",
    expiryDate: "",
    lotNumber: "",
    salePrice: "",
  };
}

type Computed = {
  unitLabel: string;
  converted: Decimal | null;
  total: Decimal | null;
  unitCost: Decimal | null;
  convertedInBase: boolean;
};

/** Montants affichés pendant la saisie. Le serveur recalcule et fait foi à la validation. */
export function computeLine(row: LineRow, currency: string): Computed {
  const article = row.article;
  const packaging = article?.packagings.find((item) => item.id === row.packagingId) ?? null;

  const quantity = parseDecimal(row.quantity);
  const price = parseDecimal(row.unitPrice);
  const factor = parseDecimal(packaging?.quantity ?? "1");

  const converted = quantity && factor ? multiply(quantity, factor) : null;
  const total = quantity && price ? round(multiply(quantity, price), currencyDecimals(currency)) : null;
  const unitCost = total && converted ? divide(total, converted, 4) : null;

  return {
    unitLabel: packaging?.name ?? article?.unit.symbol ?? "",
    converted,
    total,
    unitCost,
    convertedInBase: packaging !== null,
  };
}

type EntryLineEditorProps = {
  row: LineRow;
  index: number;
  errors: LineErrors;
  currency: string;
  onChange: (patch: Partial<LineRow>) => void;
  onRemove: () => void;
  removable: boolean;
  onCreateArticle: () => void;
};

export function EntryLineEditor({
  row,
  index,
  errors,
  currency,
  onChange,
  onRemove,
  removable,
  onCreateArticle,
}: EntryLineEditorProps) {
  const [extrasOpen, setExtrasOpen] = useState(row.lotNumber !== "" || row.salePrice !== "");

  const article = row.article;
  const computed = computeLine(row, currency);
  const hasPackagings = (article?.packagings.length ?? 0) > 0;

  const unitOptions = article
    ? [
        { value: BASE_UNIT, label: `${article.unit.name} (${article.unit.symbol})` },
        ...article.packagings.map((packaging) => ({
          value: packaging.id,
          label: `${packaging.name} (= ${formatQuantity(packaging.quantity)} ${article.unit.symbol})`,
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
              queryKey={["entry-articles"]}
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
              onChange={(item) =>
                onChange({
                  article: item,
                  packagingId: null,
                  expiryDate: item.track_expiry ? row.expiryDate : "",
                })
              }
              placeholder="Rechercher un article"
              searchPlaceholder="Nom, code ou code-barres"
              emptyMessage="Aucun article ne correspond."
              footer={(close) => (
                <button
                  type="button"
                  onClick={() => {
                    close();
                    onCreateArticle();
                  }}
                  className="flex h-10 w-full items-center gap-2 rounded-sm px-3 text-sm font-medium text-primary outline-none transition-colors duration-150 hover:bg-accent focus-visible:bg-accent"
                >
                  <Plus className="size-4" strokeWidth={1.5} aria-hidden />
                  Créer un nouvel article
                </button>
              )}
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
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {hasPackagings ? (
              <Field label="Reçu en" error={errors.packaging_id}>
                <Select
                  size="lg"
                  options={unitOptions}
                  value={row.packagingId ?? BASE_UNIT}
                  onValueChange={(value) => onChange({ packagingId: value === BASE_UNIT ? null : value })}
                />
              </Field>
            ) : null}

            <Field label="Quantité reçue" error={errors.entered_quantity}>
              <AffixInput
                size="lg"
                inputMode="decimal"
                autoComplete="off"
                placeholder="0"
                suffix={computed.unitLabel}
                value={row.quantity}
                onChange={(event) => onChange({ quantity: event.target.value })}
              />
            </Field>

            <Field
              label={hasPackagings ? `Prix d'achat par ${computed.unitLabel}` : "Prix d'achat unitaire"}
              error={errors.unit_price}
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

            {article.track_expiry ? (
              <Field label="Date d'expiration" error={errors.expiry_date}>
                <Input
                  size="lg"
                  type="date"
                  value={row.expiryDate}
                  onChange={(event) => onChange({ expiryDate: event.target.value })}
                />
              </Field>
            ) : null}
          </div>

          <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-end sm:justify-between">
            <div className="space-y-1 text-sm text-muted-foreground">
              {computed.convertedInBase && computed.converted ? (
                <p>
                  Stock reçu :{" "}
                  <span className="font-medium tabular-nums text-foreground">
                    {formatQuantity(decimalToString(computed.converted, true))} {article.unit.symbol}
                  </span>
                </p>
              ) : null}

              {computed.unitCost ? (
                <p>
                  Coût :{" "}
                  <span className="font-medium tabular-nums text-foreground">
                    {formatMoney(decimalToString(computed.unitCost, true), currency)}
                  </span>{" "}
                  / {article.unit.symbol}
                </p>
              ) : null}
            </div>

            <p className="text-sm text-muted-foreground sm:text-right">
              Total de la ligne
              <span className="block text-lg font-semibold tabular-nums text-foreground">
                {computed.total ? formatMoney(decimalToString(computed.total), currency) : "—"}
              </span>
            </p>
          </div>

          <div>
            <button
              type="button"
              onClick={() => setExtrasOpen((open) => !open)}
              aria-expanded={extrasOpen}
              className="inline-flex items-center gap-1.5 rounded-sm text-sm font-medium text-primary outline-none transition-colors duration-200 ease-smooth hover:underline focus-visible:ring-4 focus-visible:ring-ring/15"
            >
              <ChevronDown
                className={cn("size-4 transition-transform duration-200 ease-smooth", extrasOpen && "rotate-180")}
                strokeWidth={1.5}
                aria-hidden
              />
              Numéro de lot et nouveau prix de vente
            </button>

            {extrasOpen ? (
              <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <Field
                  label="Numéro de lot du fabricant"
                  optional
                  error={errors.lot_number}
                  hint="Celui imprimé sur l'emballage, pour le retrouver en cas de rappel."
                >
                  <Input
                    size="lg"
                    autoComplete="off"
                    value={row.lotNumber}
                    onChange={(event) => onChange({ lotNumber: event.target.value })}
                  />
                </Field>

                <Field
                  label="Nouveau prix de vente"
                  optional
                  error={errors.sale_price}
                  hint={`Remplace le prix actuel (${formatMoney(article.sale_price, currency)} / ${article.unit.symbol}) à la validation.`}
                >
                  <AffixInput
                    size="lg"
                    inputMode="decimal"
                    autoComplete="off"
                    suffix={currency}
                    value={row.salePrice}
                    onChange={(event) => onChange({ salePrice: event.target.value })}
                  />
                </Field>
              </div>
            ) : null}
          </div>
        </div>
      ) : null}
    </li>
  );
}