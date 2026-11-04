"use client";

import { useRef, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import { Textarea } from "@/components/ui/textarea";
import { useSupplierMutations } from "@/hooks/use-suppliers";
import { ApiError } from "@/lib/api/client";
import type { Supplier } from "@/lib/api/suppliers";
import { pickFieldMessages } from "@/lib/forms";

const FIELDS = ["name", "contact_name", "phone", "email", "address", "city", "notes"] as const;

type FieldName = (typeof FIELDS)[number];
type Values = Record<FieldName, string>;
type FieldMessages = Partial<Record<FieldName, string>>;

type SupplierSheetProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  supplier: Supplier | null;
};

const emptyToNull = (value: string): string | null => (value.trim() === "" ? null : value);

function SupplierForm({ supplier, onClose }: { supplier: Supplier | null; onClose: () => void }) {
  const mutations = useSupplierMutations();
  const formRef = useRef<HTMLFormElement>(null);

  const [values, setValues] = useState<Values>({
    name: supplier?.name ?? "",
    contact_name: supplier?.contact_name ?? "",
    phone: supplier?.phone ?? "",
    email: supplier?.email ?? "",
    address: supplier?.address ?? "",
    city: supplier?.city ?? "",
    notes: supplier?.notes ?? "",
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

    const input = {
      name: values.name,
      contact_name: emptyToNull(values.contact_name),
      phone: emptyToNull(values.phone),
      email: emptyToNull(values.email),
      address: emptyToNull(values.address),
      city: emptyToNull(values.city),
      notes: emptyToNull(values.notes),
    };

    try {
      if (supplier) {
        await mutations.update.mutateAsync({ id: supplier.id, input });
        toast.success("Fournisseur modifié.");
      } else {
        await mutations.create.mutateAsync(input);
        toast.success("Fournisseur créé.");
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
        <Field label="Nom du fournisseur" error={messages.name}>
          <Input
            size="lg"
            name="name"
            autoComplete="off"
            placeholder="Riz Afrique SARL"
            value={values.name}
            onChange={(event) => change("name", event.target.value)}
          />
        </Field>

        <Field label="Personne à contacter" optional error={messages.contact_name}>
          <Input
            size="lg"
            name="contact_name"
            autoComplete="off"
            placeholder="Moussa Diallo"
            value={values.contact_name}
            onChange={(event) => change("contact_name", event.target.value)}
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
            placeholder="contact@fournisseur.com"
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

        <Field
          label="Notes"
          optional
          error={messages.notes}
          hint="Jours de livraison, conditions de paiement…"
        >
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
          {supplier ? "Enregistrer" : "Créer le fournisseur"}
        </Button>
      </div>
    </form>
  );
}

export function SupplierSheet({ open, onOpenChange, supplier }: SupplierSheetProps) {
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        title={supplier ? "Modifier le fournisseur" : "Nouveau fournisseur"}
        description="Les coordonnées de celui qui vous livre."
      >
        <SupplierForm supplier={supplier} onClose={() => onOpenChange(false)} />
      </SheetContent>
    </Sheet>
  );
}