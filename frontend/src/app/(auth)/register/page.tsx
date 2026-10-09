import type { Metadata } from "next";
import { connection } from "next/server";
import { ReferenceDataError } from "@/components/auth/reference-data-error";
import { RegisterForm } from "@/components/auth/register-form";
import { fetchReferenceData } from "@/lib/api/meta";

export const metadata: Metadata = {
  title: "Créer mon espace",
};

export default async function RegisterPage() {
  await connection();

  const data = await fetchReferenceData();

  if (data === null) {
    return <ReferenceDataError />;
  }

  return (
    <RegisterForm
      countries={data.countries}
      currencies={data.currencies}
      trialDays={data.trial_days}
      passwordMinLength={data.password_min_length}
    />
  );
}