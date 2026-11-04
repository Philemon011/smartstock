"use client";

import * as AlertDialogPrimitive from "@radix-ui/react-alert-dialog";
import type { ReactNode } from "react";
import { Alert } from "./alert";
import { Button } from "./button";

type ConfirmDialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description: string;
  confirmLabel: string;
  pending?: boolean;
  destructive?: boolean;
  onConfirm: () => void;
  children?: ReactNode;
  error?: string | null;
};

export function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel,
  pending = false,
  destructive = false,
  onConfirm,
  children,
  error,
}: ConfirmDialogProps) {
  return (
    <AlertDialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
      <AlertDialogPrimitive.Portal>
        <AlertDialogPrimitive.Overlay className="fixed inset-0 z-50 bg-foreground/30 data-[state=closed]:animate-fade-out data-[state=open]:animate-fade-in" />

        <AlertDialogPrimitive.Content className="fixed inset-0 z-50 m-auto h-fit max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-md overflow-y-auto rounded-xl border bg-card p-6 shadow-popover outline-none data-[state=open]:animate-pop-in">
          <AlertDialogPrimitive.Title className="font-display text-lg font-semibold tracking-heading">
            {title}
          </AlertDialogPrimitive.Title>
          <AlertDialogPrimitive.Description className="mt-2 text-sm text-muted-foreground">
            {description}
          </AlertDialogPrimitive.Description>

          {children ? <div className="mt-5">{children}</div> : null}

          {error ? (
            <Alert variant="danger" className="mt-5">
              {error}
            </Alert>
          ) : null}

          <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <AlertDialogPrimitive.Cancel asChild>
              <Button variant="secondary" disabled={pending}>
                Annuler
              </Button>
            </AlertDialogPrimitive.Cancel>

            <Button
              variant={destructive ? "destructive" : "primary"}
              loading={pending}
              onClick={onConfirm}
            >
              {confirmLabel}
            </Button>
          </div>
        </AlertDialogPrimitive.Content>
      </AlertDialogPrimitive.Portal>
    </AlertDialogPrimitive.Root>
  );
}