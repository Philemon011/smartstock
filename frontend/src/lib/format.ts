/**
 * Montant avec le séparateur de milliers et le symbole de la devise.
 * Le nombre de décimales affichées est celui que le serveur a renvoyé.
 */
export function formatMoney(amount: string, currency: string): string {
  const decimals = amount.split(".")[1]?.length ?? 0;

  try {
    return new Intl.NumberFormat("fr-FR", {
      style: "currency",
      currency,
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    }).format(Number(amount));
  } catch {
    return `${amount} ${currency}`;
  }
}

export function formatQuantity(value: string): string {
  return value.replace(".", ",");
}

export function formatDateTime(iso: string): string {
  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short" }).format(
    new Date(iso),
  );
}