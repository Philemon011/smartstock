import type { CompanyProfile } from "@/lib/api/company-profile";

export type LetterheadSource = Pick<
  CompanyProfile,
  | "name"
  | "phone"
  | "email"
  | "address"
  | "city"
  | "website"
  | "tax_id"
  | "registration_number"
  | "legal_mentions"
  | "receipt_footer"
>;

export const DEFAULT_RECEIPT_FOOTER = "Merci de votre confiance.";

function filled(value: string | null | undefined): string | null {
  const text = value?.trim();

  return text ? text : null;
}

function compact(parts: (string | null)[]): string[] {
  return parts.filter((part): part is string => part !== null);
}

/** « Rue 12, Haie Vive, Cotonou » */
export function addressLine(source: LetterheadSource): string | null {
  const line = compact([filled(source.address), filled(source.city)]).join(", ");

  return line === "" ? null : line;
}

/** « +229 01 02 03 04  ·  contact@entreprise.com » */
export function contactLine(source: LetterheadSource): string | null {
  const line = compact([filled(source.phone), filled(source.email)]).join("  ·  ");

  return line === "" ? null : line;
}

/** Le site sans « https:// » ni « / » final, comme on l'imprime. */
export function websiteDisplay(source: LetterheadSource): string | null {
  const site = filled(source.website);

  return site ? site.replace(/^https?:\/\//i, "").replace(/\/$/, "") : null;
}

/** Les lignes de coordonnées de l'en-tête, sans aucune ligne vide. */
export function letterheadLines(source: LetterheadSource): string[] {
  return compact([addressLine(source), contactLine(source), websiteDisplay(source)]);
}

/** « N° fiscal : 3201234567890 », « Registre du commerce : RB/COT/21 B 1234 » */
export function identifierLines(source: LetterheadSource): string[] {
  const taxId = filled(source.tax_id);
  const registration = filled(source.registration_number);

  return compact([
    taxId ? `N° fiscal : ${taxId}` : null,
    registration ? `Registre du commerce : ${registration}` : null,
  ]);
}

/** Les mentions légales, une par ligne, sans lignes vides en trop. */
export function legalMentionLines(source: LetterheadSource): string[] {
  const text = source.legal_mentions;

  if (!text) return [];

  return text
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line !== "");
}

export function receiptFooter(source: LetterheadSource): string {
  return filled(source.receipt_footer) ?? DEFAULT_RECEIPT_FOOTER;
}