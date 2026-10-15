"use client";

import { useRef, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import { useWarehouseMutations } from "@/hooks/use-warehouses";
import { ApiError } from "@/lib/api/client";
import type { Warehouse } from "@/lib/api/warehouses";
import { pickFieldMessages } from "@/lib/forms";

const FIELDS = ["name", "code", "address", "city", "phone"] as const;

type FieldName = (typeof FIELDS)[number];
type Values = Record<FieldName, string>;
type FieldMessages = Partial<Record<FieldName, string>>;

type WarehouseSheetProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  warehouse: Warehouse | null;
};

const emptyToNull = (value: string): string | null => (value.trim() === "" ? null : value);

function WarehouseForm({ warehouse, onClose }: { warehouse: Warehouse | null; onClose: () => void }) {
  const mutations = useWarehouseMutations();
  const formRef = useRef<HTMLFormElement>(null);

  const [values, setValues] = useState<Values>({
    name: warehouse?.name ?? "",
    code: warehouse?.code ?? "",
    address: warehouse?.address ?? "",
    city: warehouse?.city ?? "",
    phone: warehouse?.phone ?? "",
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
      code: emptyToNull(values.code),
      address: emptyToNull(values.address),
      city: emptyToNull(values.city),
      phone: emptyToNull(values.phone),
    };

    try {
      if (warehouse) {
        await mutations.update.mutateAsync({ id: warehouse.id, input });
        toast.success("Entrepôt modifié.");
      } else {
        await mutations.create.mutateAsync(input);
        toast.success("Entrepôt créé.");
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
        <Field label="Nom de l'entrepôt" error={messages.name}>
          <Input
            size="lg"
            name="name"
            autoComplete="off"
            placeholder="Dépôt Nord"
            value={values.name}
            onChange={(event) => change("name", event.target.value)}
          />
        </Field>

        <Field
          label="Code"
          optional
          error={messages.code}
          hint="Un identifiant court pour vous repérer, par exemple DEP-01."
        >
          <Input
            size="lg"
            name="code"
            autoComplete="off"
            placeholder="DEP-01"
            value={values.code}
            onChange={(event) => change("code", event.target.value)}
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
          {warehouse ? "Enregistrer" : "Créer l'entrepôt"}
        </Button>
      </div>
    </form>
  );
}

export function WarehouseSheet({ open, onOpenChange, warehouse }: WarehouseSheetProps) {
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        title={warehouse ? "Modifier l'entrepôt" : "Nouvel entrepôt"}
        description={
          warehouse
            ? "Mettez à jour les informations de ce lieu de stockage."
            : "Ajoutez un lieu où votre stock est conservé."
        }
      >
        <WarehouseForm warehouse={warehouse} onClose={() => onOpenChange(false)} />
      </SheetContent>
    </Sheet>
  );
}