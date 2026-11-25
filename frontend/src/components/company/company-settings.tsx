"use client";

import { useRef, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { PageHeader } from "@/components/app/page-header";
import { CompanyLogoField } from "@/components/company/company-logo-field";
import { LetterheadPreview } from "@/components/company/letterhead-preview";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { FormSection } from "@/components/ui/form-section";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { useCompanyProfile, useCompanyProfileMutations } from "@/hooks/use-company-profile";
import { ApiError } from "@/lib/api/client";
import type { CompanyProfile, CompanyProfileInput } from "@/lib/api/company-profile";
import { pickFieldMessages } from "@/lib/forms";
import { currencyName, regionName } from "@/lib/session";

const FIELDS = [
  "name",
  "phone",
  "email",
  "website",
  "address",
  "city",
  "tax_id",
  "registration_number",
  "legal_mentions",
  "receipt_footer",
] as const;

type FieldName = (typeof FIELDS)[number];
type Values = Record<FieldName, string>;
type FieldMessages = Partial<Record<FieldName, string>>;

const MENTIONS_MAX = 500;
const FOOTER_MAX = 200;

const emptyToNull = (value: string): string | null => (value.trim() === "" ? null : value);

function toValues(profile: CompanyProfile): Values {
  return {
    name: profile.name,
    phone: profile.phone ?? "",
    email: profile.email ?? "",
    website: profile.website ?? "",
    address: profile.address ?? "",
    city: profile.city ?? "",
    tax_id: profile.tax_id ?? "",
    registration_number: profile.registration_number ?? "",
    legal_mentions: profile.legal_mentions ?? "",
    receipt_footer: profile.receipt_footer ?? "",
  };
}

function CompanyForm({ profile }: { profile: CompanyProfile }) {
  const mutations = useCompanyProfileMutations();
  const formRef = useRef<HTMLFormElement>(null);
  const canEdit = profile.can.update;

  const [values, setValues] = useState<Values>(() => toValues(profile));
  const [messages, setMessages] = useState<FieldMessages>({});
  const [formError, setFormError] = useState<string | null>(null);

  const saved = toValues(profile);
  const dirty = FIELDS.some((field) => values[field] !== saved[field]);
  const pending = mutations.update.isPending;

  function change(field: FieldName, value: string) {
    setValues((previous) => ({ ...previous, [field]: value }));
    setMessages((previous) => ({ ...previous, [field]: undefined }));
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (pending || !canEdit) return;

    setFormError(null);
    setMessages({});

    const input: CompanyProfileInput = {
      name: values.name,
      phone: emptyToNull(values.phone),
      email: emptyToNull(values.email),
      address: emptyToNull(values.address),
      city: emptyToNull(values.city),
      website: emptyToNull(values.website),
      tax_id: emptyToNull(values.tax_id),
      registration_number: emptyToNull(values.registration_number),
      legal_mentions: emptyToNull(values.legal_mentions),
      receipt_footer: emptyToNull(values.receipt_footer),
    };

    try {
      const result = await mutations.update.mutateAsync(input);

      // Les valeurs affichées redeviennent celles que le serveur a retenues (espaces, majuscules, « https:// »).
      setValues(toValues(result));
      toast.success("Profil de l'entreprise enregistré.");
    } catch (error) {
      if (error instanceof ApiError && error.code === "validation_error") {
        const fieldMessages = pickFieldMessages(error.fieldErrors, FIELDS);

        if (Object.keys(fieldMessages).length > 0) {
          setMessages(fieldMessages);
          window.setTimeout(() => {
            formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
          }, 0);
          return;
        }
      }

      setFormError(
        error instanceof ApiError ? error.message : "Une erreur inattendue est survenue. Réessayez.",
      );
    }
  }

  return (
    <form
      ref={formRef}
      noValidate
      onSubmit={handleSubmit}
      aria-busy={pending || undefined}
      className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start"
    >
      <div className="space-y-6">
        {!canEdit ? (
          <Alert variant="info" title="Lecture seule">
            Ces informations sont en lecture seule. Seuls le propriétaire et les administrateurs peuvent les
            modifier.
          </Alert>
        ) : null}

        <FormSection title="Identité" description="Le nom et le logo apparaissent en haut de chaque reçu.">
          <CompanyLogoField logoUrl={profile.logo_url} name={profile.name} canEdit={canEdit} />

          <Field label="Nom de l'entreprise" error={messages.name}>
            <Input
              size="lg"
              name="name"
              autoComplete="organization"
              disabled={!canEdit}
              value={values.name}
              onChange={(event) => change("name", event.target.value)}
            />
          </Field>

          <p className="text-xs text-muted-foreground">
            Pays : {regionName(profile.country)} · Devise : {currencyName(profile.currency)}. Ces deux
            réglages ne se modifient pas : vos montants enregistrés en dépendent.
          </p>
        </FormSection>

        <FormSection title="Coordonnées" description="Elles permettent à vos clients de vous joindre.">
          <div className="grid gap-5 sm:grid-cols-2">
            <Field label="Téléphone" optional error={messages.phone}>
              <Input
                size="lg"
                type="tel"
                name="phone"
                inputMode="tel"
                autoComplete="off"
                placeholder="+229 01 02 03 04"
                disabled={!canEdit}
                value={values.phone}
                onChange={(event) => change("phone", event.target.value)}
              />
            </Field>

            <Field label="Adresse email" optional error={messages.email}>
              <Input
                size="lg"
                type="email"
                name="email"
                inputMode="email"
                autoComplete="off"
                placeholder="contact@entreprise.com"
                disabled={!canEdit}
                value={values.email}
                onChange={(event) => change("email", event.target.value)}
              />
            </Field>
          </div>

          <Field label="Adresse" optional error={messages.address}>
            <Input
              size="lg"
              name="address"
              autoComplete="off"
              placeholder="Quartier, rue…"
              disabled={!canEdit}
              value={values.address}
              onChange={(event) => change("address", event.target.value)}
            />
          </Field>

          <div className="grid gap-5 sm:grid-cols-2">
            <Field label="Ville" optional error={messages.city}>
              <Input
                size="lg"
                name="city"
                autoComplete="off"
                placeholder="Cotonou"
                disabled={!canEdit}
                value={values.city}
                onChange={(event) => change("city", event.target.value)}
              />
            </Field>

            <Field label="Site web" optional error={messages.website}>
              <Input
                size="lg"
                name="website"
                inputMode="url"
                autoComplete="off"
                placeholder="www.entreprise.com"
                disabled={!canEdit}
                value={values.website}
                onChange={(event) => change("website", event.target.value)}
              />
            </Field>
          </div>
        </FormSection>

        <FormSection
          title="Mentions légales"
          description="Imprimées en bas des reçus. Selon votre pays, elles peuvent être obligatoires."
        >
          <div className="grid gap-5 sm:grid-cols-2">
            <Field
              label="Numéro d'identification fiscale"
              optional
              error={messages.tax_id}
              hint="IFU, NIF ou équivalent selon votre pays."
            >
              <Input
                size="lg"
                name="tax_id"
                autoComplete="off"
                disabled={!canEdit}
                value={values.tax_id}
                onChange={(event) => change("tax_id", event.target.value)}
              />
            </Field>

            <Field
              label="Registre du commerce"
              optional
              error={messages.registration_number}
              hint="RCCM ou équivalent."
            >
              <Input
                size="lg"
                name="registration_number"
                autoComplete="off"
                disabled={!canEdit}
                value={values.registration_number}
                onChange={(event) => change("registration_number", event.target.value)}
              />
            </Field>
          </div>

          <Field
            label="Autres mentions"
            optional
            error={messages.legal_mentions}
            hint={`Forme juridique, capital, siège… Une mention par ligne. ${values.legal_mentions.length}/${MENTIONS_MAX}`}
          >
            <Textarea
              name="legal_mentions"
              placeholder="SARL au capital de 1 000 000 FCFA"
              disabled={!canEdit}
              value={values.legal_mentions}
              onChange={(event) => change("legal_mentions", event.target.value)}
            />
          </Field>
        </FormSection>

        <FormSection title="Reçus" description="Le message affiché tout en bas, sous le total.">
          <Field
            label="Message de fin de reçu"
            optional
            error={messages.receipt_footer}
            hint={`Par défaut : « Merci de votre confiance. » ${values.receipt_footer.length}/${FOOTER_MAX}`}
          >
            <Input
              size="lg"
              name="receipt_footer"
              autoComplete="off"
              placeholder="Merci et à bientôt !"
              disabled={!canEdit}
              value={values.receipt_footer}
              onChange={(event) => change("receipt_footer", event.target.value)}
            />
          </Field>
        </FormSection>

        {formError ? (
          <Alert variant="danger" title="Enregistrement impossible">
            {formError}
          </Alert>
        ) : null}

        {canEdit ? (
          <div className="flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-end">
            {dirty ? <p className="text-sm text-muted-foreground sm:mr-auto">Modifications non enregistrées.</p> : null}

            <Button
              type="button"
              variant="ghost"
              size="lg"
              disabled={!dirty || pending}
              onClick={() => {
                setValues(toValues(profile));
                setMessages({});
                setFormError(null);
              }}
            >
              Annuler les modifications
            </Button>
            <Button type="submit" size="lg" loading={pending} disabled={!dirty}>
              Enregistrer
            </Button>
          </div>
        ) : null}
      </div>

      <aside className="lg:sticky lg:top-24">
        <LetterheadPreview source={values} logoUrl={profile.logo_url} />
      </aside>
    </form>
  );
}

export function CompanySettings() {
  const query = useCompanyProfile();

  return (
    <div className="max-w-5xl space-y-8">
      <PageHeader
        title="Entreprise"
        description="Le logo, les coordonnées et les mentions légales qui apparaissent sur vos reçus."
      />

      {query.isPending ? (
        <div className="space-y-6">
          <Skeleton className="h-64 w-full rounded-xl" />
          <Skeleton className="h-64 w-full rounded-xl" />
        </div>
      ) : query.isError && !query.data ? (
        <div className="space-y-4">
          <Alert variant="danger" title="Impossible de charger le profil">
            {query.error instanceof ApiError
              ? query.error.message
              : "Une erreur inattendue est survenue. Réessayez."}
          </Alert>
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Réessayer
          </Button>
        </div>
      ) : query.data ? (
        <CompanyForm key={query.data.id} profile={query.data} />
      ) : null}
    </div>
  );
}