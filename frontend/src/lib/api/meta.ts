export type Country = {
  code: string;
  name: string;
  currency: string;
};

export type Currency = {
  code: string;
  name: string;
};

export type ReferenceData = {
  countries: Country[];
  currencies: Currency[];
  trial_days: number;
  password_min_length: number;
};

function isReferenceData(value: unknown): value is ReferenceData {
  if (typeof value !== "object" || value === null) return false;

  const data = value as Record<string, unknown>;

  return (
    Array.isArray(data.countries) &&
    Array.isArray(data.currencies) &&
    typeof data.trial_days === "number" &&
    typeof data.password_min_length === "number"
  );
}

async function request(init: RequestInit): Promise<ReferenceData | null> {
  const base = process.env.NEXT_PUBLIC_API_URL?.replace(/\/+$/, "");

  if (!base) {
    console.error("[reference-data] NEXT_PUBLIC_API_URL est manquante.");
    return null;
  }

  try {
    const response = await fetch(`${base}/api/v1/meta/countries`, {
      ...init,
      headers: { Accept: "application/json" },
      signal: AbortSignal.timeout(8_000),
    });

    if (!response.ok) {
      console.error(`[reference-data] Réponse inattendue : ${response.status}`);
      return null;
    }

    const body: unknown = await response.json();

    return isReferenceData(body) ? body : null;
  } catch (error) {
    console.error("[reference-data] Backend injoignable.", error);
    return null;
  }
}

export async function fetchReferenceData(): Promise<ReferenceData | null> {
  return (
    (await request({ next: { revalidate: 3600 } })) ??
    (await request({ cache: "no-store" }))
  );
}