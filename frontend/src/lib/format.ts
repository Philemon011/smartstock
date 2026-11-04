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


/** Date « AAAA-MM-JJ » affichée sans décalage de fuseau. */
export function formatDate(isoDate: string): string {
  const [year, month, day] = isoDate.split("-").map(Number);

  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium" }).format(
    new Date(year, month - 1, day),
  );
}

/** Nombre de décimales usuel d'une devise (0 pour le franc CFA, 2 pour l'euro…). */
export function currencyDecimals(currency: string): number {
  try {
    return (
      new Intl.NumberFormat("fr-FR", { style: "currency", currency }).resolvedOptions()
        .maximumFractionDigits ?? 2
    );
  } catch {
    return 2;
  }
}

/** Date du jour « AAAA-MM-JJ » dans le fuseau horaire de l'entreprise. */
export function todayIn(timeZone: string): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}