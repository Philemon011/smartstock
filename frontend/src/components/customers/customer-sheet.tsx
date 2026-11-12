"use client";

import { useRef, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { useActiveMembership } from "@/components/session/session-provider";
import { Alert } from "@/components/ui/alert";
import { AffixInput } from "@/components/ui/affix-input";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import { Textarea } from "@/components/ui/textarea";
import { useCustomerMutations } from "@/hooks/use-customers";
import { ApiError } from "@/lib/api/client";
import type { Customer, CustomerInput } from "@/lib/api/customers";
import { pickFieldMessages } from "@/lib/forms";

const FIELDS = ["name", "phone", "email", "address", "city", "notes", "credit_limit"] as const;

type FieldName = (typeof FIELDS)[number];
type Values = Record<FieldName, string>;
type FieldMessages = Partial<Record<FieldName, string>>;

type CustomerSheetProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  customer: Customer | null;
  /** Appelé avec le client créé (pour le sélectionner tout de suite dans une vente). */
  onCreated?: (customer: Customer) => void;
};

const emptyToNull = (value: string): string | null => (value.trim() === "" ? null : value);

function CustomerForm({
  customer,
  onClose,
  onCreated,
}: {
  customer: Customer | null;
  onClose: () => void;
  onCreated?: (customer: Customer) => void;
}) {
  const { tenant, role } = useActiveMembership();
  const mutations = useCustomerMutations();
  const formRef = useRef<HTMLFormElement>(null);

  const canSetLimit = customer ? customer.can.set_credit_limit : role === "owner" || role === "admin";

  const [values, setValues] = useState<Values>({
    name: customer?.name ?? "",
    phone: customer?.phone ?? "",
    email: customer?.email ?? "",
    address: customer?.address ?? "",
    city: customer?.city ?? "",
    notes: customer?.notes ?? "",
    credit_limit: customer?.credit_limit ?? "",
  });
  const [messages, setMessages] = useState<FieldMessages>({});
  const [formError, setFormError] = useState<string | null>(null);

  const pending = mutations.create.isPending || mutations.update.isPending;

  function change(field: FieldName, value: string) {
    setValues((previous) => ({ ...previous, [field]: value }));
    setMessages((previous) => ({ ...previous, [field]: undefined }));
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (pending) return;

    setFormError(null);
    setMessages({});

    const input: CustomerInput = {
      name: values.name,
      phone: emptyToNull(values.phone),
      email: emptyToNull(values.email),
      address: emptyToNull(values.address),
      city: emptyToNull(values.city),
      notes: emptyToNull(values.notes),
    };

    // Le plafond n'est envoyé que par ceux qui ont le droit de le définir.
    if (canSetLimit) {
      input.credit_limit = emptyToNull(values.credit_limit);
    }

    try {
      if (customer) {
        await mutations.update.mutateAsync({ id: customer.id, input });
        toast.success("Client modifié.");
      } else {
                const created = await mutations.create.mutateAsync(input);
        toast.success("Client créé.");
        onCreated?.(created);
      }

      onClose();
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
      className="flex min-h-0 flex-1 flex-col"
    >
      <div className="flex-1 space-y-5 overflow-y-auto px-6 py-6">
        <Field label="Nom du client" error={messages.name}>
          <Input
            size="lg"
            name="name"
            autoComplete="off"
            placeholder="Kofi Mensah"
            value={values.name}
            onChange={(event) => change("name", event.target.value)}
          />
        </Field>

        <Field label="Téléphone" optional error={messages.phone}>
          <Input
            size="lg"
            type="tel"
            name="phone"
            inputMode="tel"
            autoComplete="off"
            placeholder="+229 01 02 03 04"
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
            placeholder="client@exemple.com"
            value={values.email}
            onChange={(event) => change("email", event.target.value)}
          />
        </Field>

        <Field label="Adresse" optional error={messages.address}>
          <Input
            size="lg"
            name="address"
            autoComplete="off"
            placeholder="Quartier, rue…"
            value={values.address}
            onChange={(event) => change("address", event.target.value)}
          />
        </Field>

        <Field label="Ville" optional error={messages.city}>
          <Input
            size="lg"
            name="city"
            autoComplete="off"
            placeholder="Cotonou"
            value={values.city}
            onChange={(event) => change("city", event.target.value)}
          />
        </Field>

        {canSetLimit ? (
          <Field
            label="Plafond de crédit"
            optional
            error={messages.credit_limit}
            hint="Montant maximum que ce client peut devoir. Laissez vide pour ne pas limiter, saisissez 0 pour refuser tout crédit."
          >
            <AffixInput
              size="lg"
              name="credit_limit"
              inputMode="decimal"
              autoComplete="off"
              placeholder="Sans plafond"
              suffix={tenant.currency}
              value={values.credit_limit}
              onChange={(event) => change("credit_limit", event.target.value)}
            />
          </Field>
        ) : null}

        <Field label="Notes" optional error={messages.notes} hint="Habitudes, conditions de paiement…">
          <Textarea
            name="notes"
            value={values.notes}
            onChange={(event) => change("notes", event.target.value)}
          />
        </Field>

        {formError ? (
          <Alert variant="danger" title="Enregistrement impossible">
            {formError}
          </Alert>
        ) : null}
      </div>

      <div className="flex gap-3 border-t px-6 py-4">
        <Button type="button" variant="secondary" size="lg" className="flex-1" onClick={onClose}>
          Annuler
        </Button>
        <Button type="submit" size="lg" className="flex-1" loading={pending}>
          {customer ? "Enregistrer" : "Créer le client"}
        </Button>
      </div>
    </form>
  );
}

export function CustomerSheet({ open, onOpenChange, customer, onCreated }: CustomerSheetProps) {
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        title={customer ? "Modifier le client" : "Nouveau client"}
        description="Les coordonnées de celui à qui vous vendez."
      >
        <CustomerForm customer={customer} onClose={() => onOpenChange(false)} onCreated={onCreated} />
      </SheetContent>
    </Sheet>
  );
}