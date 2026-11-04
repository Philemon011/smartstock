import type { ReactNode } from "react";

type FormSectionProps = {
  title: string;
  description?: string;
  children: ReactNode;
};

export function FormSection({ title, description, children }: FormSectionProps) {
  return (
    <section className="rounded-xl border bg-card p-6 shadow-card">
      <h2 className="text-lg">{title}</h2>
      {description ? <p className="mt-1 text-sm text-muted-foreground">{description}</p> : null}
      <div className="mt-6 space-y-5">{children}</div>
    </section>
  );
}