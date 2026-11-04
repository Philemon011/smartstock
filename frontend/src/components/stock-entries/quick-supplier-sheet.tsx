"use client";

import { useRef, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import { useSupplierMutations } from "@/hooks/use-suppliers";
import { ApiError } from "@/lib/api/client";
import type { Supplier } from "@/lib/api/suppliers";
import { pickFieldMessages } from "@/lib/forms";

const FIELDS = ["name", "contact_name", "phone"] as const;

type FieldName = (typeof FIELDS)[number];
type FieldMessages = Partial<Record<FieldName, string>>;

type QuickSupplierSheetProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated: (supplier: Supplier) => void;
};

function QuickSupplierForm({
  onCreated,
  onClose,
}: {
  onCreated: (supplier: Supplier) => void;
  onClose: () => void;
}) {
  const mutations = useSupplierMutations();
  const formRef = useRef<HTMLFormElement>(null);

  const [name, setName] = useState("");
  const [contact, setContact] = useState("");
  const [phone, setPhone] = useState("");
  const [messages, setMessages] = useState<FieldMessages>({});
  const [formError, setFormError] = useState<string | null>(null);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    // Ce formulaire est dans un panneau rendu hors de la page : on empêche l'événement de remonter au formulaire de l'entrée.
    event.stopPropagation();

    if (mutations.create.isPending) return;

    setFormError(null);
    setMessages({});

    try {
      const supplier = await mutations.create.mutateAsync({
        name,
        contact_name: contact.trim() === "" ? null : contact,
        phone: phone.trim() === "" ? null : phone,
        email: null,
        address: null,
        city: null,
        notes: null,
      });

      toast.success("Fournisseur créé.");
      onCreated(supplier);
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
      className="flex min-h-0 flex-1 flex-col"
    >
      <div className="flex-1 space-y-5 overflow-y-auto px-6 py-6">
        <Field label="Nom du fournisseur" error={messages.name}>
          <Input
            size="lg"
            autoComplete="off"
            placeholder="Riz Afrique SARL"
            value={name}
            onChange={(event) => {
              setName(event.target.value);
              setMessages((previous) => ({ ...previous, name: undefined }));
            }}
          />
        </Field>

        <Field label="Personne à contacter" optional error={messages.contact_name}>
          <Input
            size="lg"
            autoComplete="off"
            placeholder="Moussa Diallo"
            value={contact}
            onChange={(event) => {
              setContact(event.target.value);
              setMessages((previous) => ({ ...previous, contact_name: undefined }));
            }}
          />
        </Field>

        <Field label="Téléphone" optional error={messages.phone}>
          <Input
            size="lg"
            type="tel"
            inputMode="tel"
            autoComplete="off"
            placeholder="+229 01 02 03 04"
            value={phone}
            onChange={(event) => {
              setPhone(event.target.value);
              setMessages((previous) => ({ ...previous, phone: undefined }));
            }}
          />
        </Field>

        <p className="text-xs text-muted-foreground">
          Vous pourrez compléter l&apos;adresse, l&apos;email et les notes depuis la page Fournisseurs.
        </p>

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
        <Button type="submit" size="lg" className="flex-1" loading={mutations.create.isPending}>
          Créer et choisir
        </Button>
      </div>
    </form>
  );
}

export function QuickSupplierSheet({ open, onOpenChange, onCreated }: QuickSupplierSheetProps) {
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        title="Nouveau fournisseur"
        description="Créez-le sans quitter votre entrée : il sera choisi automatiquement."
      >
        <QuickSupplierForm onCreated={onCreated} onClose={() => onOpenChange(false)} />
      </SheetContent>
    </Sheet>
  );
}