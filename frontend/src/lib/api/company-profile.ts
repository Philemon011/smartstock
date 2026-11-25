import { apiRequest } from "./client";

export type CompanyProfile = {
  id: string;
  name: string;
  country: string;
  currency: string;
  timezone: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  city: string | null;
  website: string | null;
  tax_id: string | null;
  registration_number: string | null;
  legal_mentions: string | null;
  receipt_footer: string | null;
  /** Lien signé et daté : à rafraîchir en rechargeant le profil. */
  logo_url: string | null;
  can: { update: boolean };
};

export type CompanyProfileInput = {
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  city: string | null;
  website: string | null;
  tax_id: string | null;
  registration_number: string | null;
  legal_mentions: string | null;
  receipt_footer: string | null;
};

const BASE = "/api/v1/company-profile";

export async function getCompanyProfile(signal?: AbortSignal): Promise<CompanyProfile> {
  const response = await apiRequest<{ data: CompanyProfile }>(BASE, { signal });

  return response.data;
}

export async function updateCompanyProfile(input: CompanyProfileInput): Promise<CompanyProfile> {
  const response = await apiRequest<{ data: CompanyProfile }>(BASE, { method: "PUT", body: input });

  return response.data;
}

export async function uploadCompanyLogo(file: File): Promise<CompanyProfile> {
  const form = new FormData();
  form.append("logo", file);

  const response = await apiRequest<{ data: CompanyProfile }>(`${BASE}/logo`, {
    method: "POST",
    body: form,
  });

  return response.data;
}

export async function removeCompanyLogo(): Promise<CompanyProfile> {
  const response = await apiRequest<{ data: CompanyProfile }>(`${BASE}/logo`, { method: "DELETE" });

  return response.data;
}