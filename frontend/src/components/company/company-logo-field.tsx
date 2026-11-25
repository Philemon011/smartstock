"use client";

import { Building2, ImagePlus } from "lucide-react";
import { useRef, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { useCompanyProfileMutations } from "@/hooks/use-company-profile";
import { ApiError } from "@/lib/api/client";

const MAX_BYTES = 2 * 1024 * 1024;
const TYPES = ["image/jpeg", "image/png", "image/webp"];

type CompanyLogoFieldProps = {
  logoUrl: string | null;
  name: string;
  canEdit: boolean;
};

export function CompanyLogoField({ logoUrl, name, canEdit }: CompanyLogoFieldProps) {
  const mutations = useCompanyProfileMutations();
  const inputRef = useRef<HTMLInputElement>(null);
  const [error, setError] = useState<string | null>(null);
  const [removeOpen, setRemoveOpen] = useState(false);

  async function upload(file: File) {
    setError(null);

    if (!TYPES.includes(file.type)) {
      setError("Le logo doit être une image JPG, PNG ou WebP.");
      return;
    }

    if (file.size > MAX_BYTES) {
      setError("Le logo ne peut pas dépasser 2 Mo.");
      return;
    }

    try {
      await mutations.uploadLogo.mutateAsync(file);
      toast.success("Logo enregistré.");
    } catch (caught) {
      if (caught instanceof ApiError && caught.code === "validation_error" && caught.fieldErrors.logo?.[0]) {
        setError(caught.fieldErrors.logo[0]);
        return;
      }

      setError(caught instanceof ApiError ? caught.message : "Une erreur inattendue est survenue. Réessayez.");
    }
  }

  async function remove() {
    try {
      await mutations.removeLogo.mutateAsync();
      toast.success("Logo retiré.");
    } catch (caught) {
      toast.error(caught instanceof ApiError ? caught.message : "Une erreur inattendue est survenue. Réessayez.");
    } finally {
      setRemoveOpen(false);
    }
  }

  return (
    <div className="space-y-2">
      <p className="text-sm font-medium text-foreground">
        Logo <span className="ml-1.5 font-normal text-subtle-foreground">Optionnel</span>
      </p>

      <div className="flex items-center gap-4">
        {/* Fond toujours blanc : un logo foncé reste lisible en thème sombre, comme sur le reçu imprimé. */}
        <div className="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-white p-2">
          {logoUrl ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={logoUrl} alt={`Logo de ${name}`} className="max-h-full max-w-full object-contain" />
          ) : (
            <Building2 className="size-8 text-[#94a3b8]" strokeWidth={1.5} aria-hidden />
          )}
        </div>

        <div className="space-y-2">
          {canEdit ? (
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant="secondary"
                size="sm"
                loading={mutations.uploadLogo.isPending}
                onClick={() => inputRef.current?.click()}
              >
                <ImagePlus strokeWidth={1.5} aria-hidden />
                {logoUrl ? "Changer le logo" : "Ajouter un logo"}
              </Button>

              {logoUrl ? (
                <Button type="button" variant="ghost" size="sm" onClick={() => setRemoveOpen(true)}>
                  Retirer
                </Button>
              ) : null}
            </div>
          ) : null}

          <p className="text-xs text-muted-foreground">
            JPG, PNG ou WebP, 2 Mo maximum, 64 × 64 pixels au minimum. Un fond transparent (PNG) donne le
            meilleur rendu sur les reçus.
          </p>
        </div>
      </div>

      <input
        ref={inputRef}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        className="sr-only"
        tabIndex={-1}
        aria-label="Choisir le logo"
        onChange={(event) => {
          const file = event.target.files?.[0];

          if (file) {
            void upload(file);
          }

          event.target.value = "";
        }}
      />

      {error ? <p className="text-sm text-danger">{error}</p> : null}

      <ConfirmDialog
        open={removeOpen}
        onOpenChange={(open) => {
          if (!open && !mutations.removeLogo.isPending) setRemoveOpen(false);
        }}
        title="Retirer le logo ?"
        description="Le logo ne sera plus affiché sur vos reçus. Vous pourrez en ajouter un autre quand vous voulez."
        confirmLabel="Retirer"
        destructive
        pending={mutations.removeLogo.isPending}
        onConfirm={() => void remove()}
      />
    </div>
  );
}