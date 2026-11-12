import type { Article } from "@/lib/api/articles";
import {
  add,
  decimalToString,
  divide,
  multiply,
  parseDecimal,
  round,
  type Decimal,
} from "@/lib/decimal";

export type SaleLineRow = {
  key: string;
  article: Article | null;
  packagingId: string | null;
  quantity: string;
  unitPrice: string;
  discountType: "none" | "percent" | "amount";
  discountValue: string;
};

export function emptySaleRow(): SaleLineRow {
  return {
    key: crypto.randomUUID(),
    article: null,
    packagingId: null,
    quantity: "",
    unitPrice: "",
    discountType: "none",
    discountValue: "",
  };
}

const TEN = BigInt(10);
const HUNDRED: Decimal = { value: BigInt(100), scale: 0 };

function pow10(exponent: number): bigint {
  let result = BigInt(1);

  for (let index = 0; index < exponent; index += 1) {
    result *= TEN;
  }

  return result;
}

export const zero = (scale: number): Decimal => ({ value: BigInt(0), scale });

export function compare(a: Decimal, b: Decimal): number {
  const scale = Math.max(a.scale, b.scale);
  const left = a.value * pow10(scale - a.scale);
  const right = b.value * pow10(scale - b.scale);

  return left === right ? 0 : left < right ? -1 : 1;
}

export function subtract(a: Decimal, b: Decimal): Decimal {
  return add(a, { value: -b.value, scale: b.scale });
}

/** Montant arrondi aux décimales de la devise, prêt à être affiché ou envoyé. */
export function toMoney(a: Decimal, decimals: number): string {
  return decimalToString(round(a, decimals));
}

/** « 2,5 » devient « 2.5 » : le serveur attend le point décimal. */
export function normalizeNumber(value: string): string {
  const parsed = parseDecimal(value);

  return parsed ? decimalToString(parsed, true) : value.trim();
}

/** Prix de la liste pour l'unité vendue : prix de l'article × quantité du conditionnement. */
export function listPriceFor(article: Article, packagingId: string | null, decimals: number): Decimal | null {
  const price = parseDecimal(article.sale_price);
  const packaging = article.packagings.find((item) => item.id === packagingId);
  const factor = parseDecimal(packaging?.quantity ?? "1");

  if (!price || !factor) return null;

  return round(multiply(price, factor), decimals);
}

export function priceString(article: Article, packagingId: string | null, decimals: number): string {
  const price = listPriceFor(article, packagingId, decimals);

  return price ? decimalToString(price, true) : article.sale_price;
}

export type SaleLineComputed = {
  listPrice: Decimal | null;
  /** Quantité dans l'unité de base, celle du stock. */
  stockQuantity: Decimal | null;
  gross: Decimal | null;
  discount: Decimal | null;
  total: Decimal | null;
  discountError: string | null;
};

const EMPTY: SaleLineComputed = {
  listPrice: null,
  stockQuantity: null,
  gross: null,
  discount: null,
  total: null,
  discountError: null,
};

export function computeSaleLine(row: SaleLineRow, decimals: number): SaleLineComputed {
  const article = row.article;

  if (!article) return EMPTY;

  const packaging = article.packagings.find((item) => item.id === row.packagingId);
  const quantity = parseDecimal(row.quantity);
  const price = parseDecimal(row.unitPrice);
  const factor = parseDecimal(packaging?.quantity ?? "1");

  const listPrice = listPriceFor(article, row.packagingId, decimals);
  const stockQuantity = quantity && factor ? multiply(quantity, factor) : null;
  const gross = quantity && price ? round(multiply(quantity, price), decimals) : null;

  if (!gross) {
    return { ...EMPTY, listPrice, stockQuantity };
  }

  if (row.discountType === "none") {
    return { listPrice, stockQuantity, gross, discount: zero(decimals), total: gross, discountError: null };
  }

  const value = parseDecimal(row.discountValue);

  if (!value) {
    return { listPrice, stockQuantity, gross, discount: null, total: null, discountError: null };
  }

  if (value.value === BigInt(0)) {
    return {
      listPrice,
      stockQuantity,
      gross,
      discount: null,
      total: null,
      discountError: "Saisissez une remise supérieure à zéro.",
    };
  }

  if (row.discountType === "percent" && compare(value, HUNDRED) > 0) {
    return {
      listPrice,
      stockQuantity,
      gross,
      discount: null,
      total: null,
      discountError: "Le pourcentage de remise ne peut pas dépasser 100.",
    };
  }

  const discount =
    row.discountType === "percent"
      ? divide(multiply(gross, value), HUNDRED, decimals)
      : round(value, decimals);

  if (!discount) {
    return { listPrice, stockQuantity, gross, discount: null, total: null, discountError: null };
  }

  if (compare(discount, gross) > 0) {
    return {
      listPrice,
      stockQuantity,
      gross,
      discount,
      total: null,
      discountError: "La remise dépasse le total de la ligne.",
    };
  }

  return { listPrice, stockQuantity, gross, discount, total: subtract(gross, discount), discountError: null };
}

export type PaymentRowResult = {
  applied: Decimal;
  change: Decimal;
  status: "ok" | "exceeds" | "settled";
};

/**
 * Répartit les paiements dans l'ordre, comme le serveur : seules les espèces peuvent dépasser
 * ce qui reste à payer (le surplus est rendu).
 */
export function allocatePayments(
  total: Decimal,
  payments: { isCash: boolean; amount: string }[],
  decimals: number,
): { rows: PaymentRowResult[]; paid: Decimal; change: Decimal; remaining: Decimal } {
  let remaining = total;
  let changeTotal = zero(decimals);
  const rows: PaymentRowResult[] = [];

  for (const payment of payments) {
    const amount = parseDecimal(payment.amount);

    if (!amount) {
      rows.push({ applied: zero(decimals), change: zero(decimals), status: "ok" });
      continue;
    }

    if (compare(remaining, zero(decimals)) <= 0) {
      rows.push({ applied: zero(decimals), change: zero(decimals), status: "settled" });
      continue;
    }

    if (compare(amount, remaining) > 0) {
      if (!payment.isCash) {
        rows.push({ applied: zero(decimals), change: zero(decimals), status: "exceeds" });
        continue;
      }

      const change = subtract(amount, remaining);

      rows.push({ applied: remaining, change, status: "ok" });
      changeTotal = add(changeTotal, change);
      remaining = zero(decimals);
      continue;
    }

    rows.push({ applied: amount, change: zero(decimals), status: "ok" });
    remaining = subtract(remaining, amount);
  }

  return { rows, paid: subtract(total, remaining), change: changeTotal, remaining };
}