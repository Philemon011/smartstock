"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";
import { PasswordRequirement } from "@/components/auth/password-requirement";
import { Button } from "@/components/ui/button";
import { Combobox } from "@/components/ui/combobox";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PasswordInput } from "@/components/ui/password-input";
import { Select } from "@/components/ui/select";
import type { Country, Currency } from "@/lib/api/meta";

type RegisterFormProps = {
  countries: Country[];
  currencies: Currency[];
  trialDays: number;
  passwordMinLength: number;
};

type FormValues = {
  company_name: string;
  country: string;
  currency: string;
  name: string;
  email: string;
  password: string;
};

const initialValues: FormValues = {
  company_name: "",
  country: "",
  currency: "",
  name: "",
  email: "",
  password: "",
};

export function RegisterForm({
  countries,
  currencies,
  trialDays,
  passwordMinLength,
}: RegisterFormProps) {
  const [values, setValues] = useState<FormValues>(initialValues);
  const [currencyTouched, setCurrencyTouched] = useState(false);

  const countryOptions = countries.map((item) => ({ value: item.code, label: item.name }));
  const currencyOptions = currencies.map((item) => ({ value: item.code, label: item.name }));

  const passwordLongEnough = Array.from(values.password).length >= passwordMinLength;

  function update<K extends keyof FormValues>(key: K, value: FormValues[K]) {
    setValues((previous) => ({ ...previous, [key]: value }));
  }

  function handleCountryChange(code: string) {
    setValues((previous) => {
      const next = { ...previous, country: code };

      if (!currencyTouched) {
        const country = countries.find((item) => item.code === code);

        if (country) {
          next.currency = country.currency;
        }
      }

      return next;
    });
  }

  function handleCurrencyChange(code: string) {
    setCurrencyTouched(true);
    update("currency", code);
  }

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    // L'envoi au backend est branché à l'étape 3b.
  }

  return (
    <div>
      <header className="space-y-2">
        <h1 className="text-3xl">Créez votre espace</h1>
        <p className="text-muted-foreground">
          Essayez gratuitement pendant {trialDays} jours, sans carte bancaire.
        </p>
      </header>

      <form noValidate onSubmit={handleSubmit} className="mt-8 space-y-8">
        <section aria-labelledby="company-section" className="space-y-5">
          <h2 id="company-section" className="text-base tracking-heading">
            Votre entreprise
          </h2>

          <Field label="Nom de l'entreprise">
            <Input
              size="lg"
              name="company_name"
              autoComplete="organization"
              placeholder="Boutique Soleil"
              value={values.company_name}
              onChange={(event) => update("company_name", event.target.value)}
            />
          </Field>

          <Field label="Pays">
            <Combobox
              size="lg"
              name="country"
              options={countryOptions}
              value={values.country}
              onValueChange={handleCountryChange}
              placeholder="Choisir un pays"
              searchPlaceholder="Rechercher un pays"
              emptyMessage="Aucun pays ne correspond à votre recherche."
            />
          </Field>

          <Field
            label="Devise"
            hint="Elle ne pourra plus être modifiée après votre première opération."
          >
            <Select
              size="lg"
              name="currency"
              options={currencyOptions}
              value={values.currency}
              onValueChange={handleCurrencyChange}
              placeholder="Choisir une devise"
            />
          </Field>
        </section>

        <section aria-labelledby="account-section" className="space-y-5">
          <h2 id="account-section" className="text-base tracking-heading">
            Votre compte
          </h2>

          <Field label="Nom complet">
            <Input
              size="lg"
              name="name"
              autoComplete="name"
              placeholder="Jean Dupont"
              value={values.name}
              onChange={(event) => update("name", event.target.value)}
            />
          </Field>

          <Field label="Adresse email">
            <Input
              size="lg"
              type="email"
              name="email"
              autoComplete="email"
              inputMode="email"
              placeholder="nom@entreprise.com"
              value={values.email}
              onChange={(event) => update("email", event.target.value)}
            />
          </Field>

          <Field label="Mot de passe">
            <div className="space-y-2.5">
              <PasswordInput
                size="lg"
                name="password"
                autoComplete="new-password"
                placeholder="Choisissez un mot de passe"
                value={values.password}
                onChange={(event) => update("password", event.target.value)}
              />
              <div className="space-y-1.5">
                <PasswordRequirement met={passwordLongEnough}>
                  Au moins {passwordMinLength} caractères
                </PasswordRequirement>
                <p className="text-xs text-subtle-foreground">
                  Les mots de passe déjà apparus dans des fuites de données sont refusés.
                </p>
              </div>
            </div>
          </Field>
        </section>

        <Button type="submit" size="lg" className="w-full">
          Créer mon espace
        </Button>
      </form>

      <p className="mt-6 text-center text-sm text-muted-foreground">
        Vous avez déjà un compte ?{" "}
        <Link
          href="/login"
          className="font-medium text-primary underline-offset-4 hover:underline"
        >
          Se connecter
        </Link>
      </p>
    </div>
  );
}