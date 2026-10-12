"use client";

import Link from "next/link";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { PasswordRequirement } from "@/components/auth/password-requirement";
import { RegistrationSuccess } from "@/components/auth/registration-success";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Combobox } from "@/components/ui/combobox";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PasswordInput } from "@/components/ui/password-input";
import { Select } from "@/components/ui/select";
import { register } from "@/lib/api/auth";
import { ApiError, type FieldErrors } from "@/lib/api/client";
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

type FieldMessages = Partial<Record<keyof FormValues, string>>;

type FormError = {
  variant: "danger" | "warning";
  title: string;
  message: string;
};

type SuccessState = {
  email: string;
  message: string;
};

const initialValues: FormValues = {
  company_name: "",
  country: "",
  currency: "",
  name: "",
  email: "",
  password: "",
};

function toFieldMessages(fieldErrors: FieldErrors): FieldMessages {
  const messages: FieldMessages = {};

  for (const key of Object.keys(initialValues) as (keyof FormValues)[]) {
    const first = fieldErrors[key]?.[0];

    if (first) {
      messages[key] = first;
    }
  }

  return messages;
}

export function RegisterForm({
  countries,
  currencies,
  trialDays,
  passwordMinLength,
}: RegisterFormProps) {
  const [values, setValues] = useState<FormValues>(initialValues);
  const [currencyTouched, setCurrencyTouched] = useState(false);
  const [fieldMessages, setFieldMessages] = useState<FieldMessages>({});
  const [formError, setFormError] = useState<FormError | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [success, setSuccess] = useState<SuccessState | null>(null);
  const [focusToken, setFocusToken] = useState(0);

  const formRef = useRef<HTMLFormElement>(null);
  const abortRef = useRef<AbortController | null>(null);

  useEffect(() => {
    return () => abortRef.current?.abort();
  }, []);

  useEffect(() => {
    if (focusToken === 0) return;

    formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
  }, [focusToken]);

  if (success) {
    return <RegistrationSuccess email={success.email} message={success.message} />;
  }

  const countryOptions = countries.map((item) => ({ value: item.code, label: item.name }));
  const currencyOptions = currencies.map((item) => ({ value: item.code, label: item.name }));

  const passwordLongEnough = Array.from(values.password).length >= passwordMinLength;

  function clearMessages(...keys: (keyof FormValues)[]) {
    setFieldMessages((previous) => {
      if (keys.every((key) => previous[key] === undefined)) return previous;

      const next = { ...previous };

      for (const key of keys) {
        delete next[key];
      }

      return next;
    });
  }

  function update<K extends keyof FormValues>(key: K, value: FormValues[K]) {
    setValues((previous) => ({ ...previous, [key]: value }));
    clearMessages(key);
  }

  function handleCountryChange(code: string) {
    const country = countries.find((item) => item.code === code);

    setValues((previous) => ({
      ...previous,
      country: code,
      currency: !currencyTouched && country ? country.currency : previous.currency,
    }));

    clearMessages("country", ...(!currencyTouched ? (["currency"] as const) : []));
  }

  function handleCurrencyChange(code: string) {
    setCurrencyTouched(true);
    update("currency", code);
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (submitting) return;

    setSubmitting(true);
    setFormError(null);
    setFieldMessages({});

    const controller = new AbortController();
    abortRef.current = controller;

    try {
      const result = await register(values, controller.signal);

      setValues((previous) => ({ ...previous, password: "" }));
      setSuccess({ email: values.email.trim().toLowerCase(), message: result.message });
    } catch (error) {
      if (controller.signal.aborted) return;

      if (error instanceof ApiError && error.code === "validation_error") {
        const messages = toFieldMessages(error.fieldErrors);

        if (Object.keys(messages).length > 0) {
          setFieldMessages(messages);
          setFocusToken((token) => token + 1);
        } else {
          setFormError({
            variant: "danger",
            title: "Impossible de créer votre espace",
            message: error.message,
          });
        }
      } else if (error instanceof ApiError) {
        const tooMany = error.code === "too_many_requests";

        setFormError({
          variant: tooMany ? "warning" : "danger",
          title: tooMany ? "Trop de tentatives" : "Impossible de créer votre espace",
          message: error.message,
        });
      } else {
        setFormError({
          variant: "danger",
          title: "Impossible de créer votre espace",
          message: "Une erreur inattendue est survenue. Réessayez.",
        });
      }
    } finally {
      if (!controller.signal.aborted) {
        setSubmitting(false);
      }
    }
  }

  return (
    <div>
      <header className="space-y-2">
        <h1 className="text-3xl">Créez votre espace</h1>
        <p className="text-muted-foreground">
          Essayez gratuitement pendant {trialDays} jours, sans carte bancaire.
        </p>
      </header>

      <form
        ref={formRef}
        noValidate
        onSubmit={handleSubmit}
        aria-busy={submitting || undefined}
        className="mt-8 space-y-8"
      >
        <section aria-labelledby="company-section" className="space-y-5">
          <h2 id="company-section" className="text-base tracking-heading">
            Votre entreprise
          </h2>

          <Field label="Nom de l'entreprise" error={fieldMessages.company_name}>
            <Input
              size="lg"
              name="company_name"
              autoComplete="organization"
              placeholder="Boutique Soleil"
              value={values.company_name}
              onChange={(event) => update("company_name", event.target.value)}
            />
          </Field>

          <Field label="Pays" error={fieldMessages.country}>
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
            error={fieldMessages.currency}
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

          <Field label="Nom complet" error={fieldMessages.name}>
            <Input
              size="lg"
              name="name"
              autoComplete="name"
              placeholder="Jean Dupont"
              value={values.name}
              onChange={(event) => update("name", event.target.value)}
            />
          </Field>

          <Field label="Adresse email" error={fieldMessages.email}>
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

          <Field label="Mot de passe" error={fieldMessages.password}>
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

        <div className="space-y-4">
          {formError ? (
            <Alert variant={formError.variant} title={formError.title}>
              {formError.message}
            </Alert>
          ) : null}

          <Button type="submit" size="lg" className="w-full" loading={submitting}>
            Créer mon espace
          </Button>
        </div>
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