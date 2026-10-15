"use client";

import { useRef, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import { Textarea } from "@/components/ui/textarea";
import { useCategories, useCategoryMutations } from "@/hooks/use-categories";
import type { Category } from "@/lib/api/categories";
import { ApiError } from "@/lib/api/client";
import { pickFieldMessages } from "@/lib/forms";

const FIELDS = ["name", "description", "parent_id"] as const;
const NONE = "__none__";

type FieldName = (typeof FIELDS)[number];
type FieldMessages = Partial<Record<FieldName, string>>;

type CategorySheetProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  category: Category | null;
  parentId: string | null;
};

type FormProps = {
  category: Category | null;
  parentId: string | null;
  onClose: () => void;
};

function CategoryForm({ category, parentId, onClose }: FormProps) {
  const mutations = useCategoryMutations();
  const all = useCategories({ search: "", status: "all" });
  const formRef = useRef<HTMLFormElement>(null);

  const [name, setName] = useState(category?.name ?? "");
  const [description, setDescription] = useState(category?.description ?? "");
  const [parent, setParent] = useState(category?.parent_id ?? parentId ?? NONE);
  const [messages, setMessages] = useState<FieldMessages>({});
  const [formError, setFormError] = useState<string | null>(null);

  const pending = mutations.create.isPending || mutations.update.isPending;
  const hasChildren = (category?.children_count ?? 0) > 0;

  const parentOptions = [
    { value: NONE, label: "Aucune (catégorie principale)" },
    ...(all.data?.data ?? [])
      .filter(
        (item) =>
          item.depth === 0 &&
          item.id !== category?.id &&
          (item.is_active || item.id === category?.parent_id),
      )
      .map((item) => ({
        value: item.id,
        label: item.is_active ? item.name : `${item.name} (désactivée)`,
      })),
  ];

  function clearMessage(field: FieldName) {
    setMessages((previous) => ({ ...previous, [field]: undefined }));
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (pending) return;

    setFormError(null);
    setMessages({});

    const input = {
      name,
      description: description.trim() === "" ? null : description,
      parent_id: hasChildren || parent === NONE ? null : parent,
    };

    try {
      if (category) {
        await mutations.update.mutateAsync({ id: category.id, input });
        toast.success("Catégorie modifiée.");
      } else {
        await mutations.create.mutateAsync(input);
        toast.success(input.parent_id ? "Sous-catégorie créée." : "Catégorie créée.");
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
        <Field label="Nom de la catégorie" error={messages.name}>
          <Input
            size="lg"
            name="name"
            autoComplete="off"
            placeholder="Boissons"
            value={name}
            onChange={(event) => {
              setName(event.target.value);
              clearMessage("name");
            }}
          />
        </Field>

        <Field
          label="Catégorie parente"
          optional
          error={messages.parent_id}
          hint={
            hasChildren
              ? "Cette catégorie contient des sous-catégories : elle doit rester une catégorie principale."
              : "Laissez « Aucune » pour créer une catégorie principale."
          }
        >
          <Select
            size="lg"
            options={parentOptions}
            value={hasChildren ? NONE : parent}
            onValueChange={(value) => {
              setParent(value);
              clearMessage("parent_id");
            }}
            disabled={hasChildren}
          />
        </Field>

        <Field label="Description" optional error={messages.description}>
          <Textarea
            name="description"
            placeholder="À quoi sert cette catégorie ?"
            value={description}
            onChange={(event) => {
              setDescription(event.target.value);
              clearMessage("description");
            }}
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
          {category ? "Enregistrer" : "Créer"}
        </Button>
      </div>
    </form>
  );
}

export function CategorySheet({ open, onOpenChange, category, parentId }: CategorySheetProps) {
  const title = category
    ? "Modifier la catégorie"
    : parentId
      ? "Nouvelle sous-catégorie"
      : "Nouvelle catégorie";

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        title={title}
        description="Les catégories servent à classer vos articles et à retrouver votre stock plus vite."
      >
        <CategoryForm
          category={category}
          parentId={parentId}
          onClose={() => onOpenChange(false)}
        />
      </SheetContent>
    </Sheet>
  );
}