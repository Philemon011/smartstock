export default function Home() {
  return (
    <main className="mx-auto max-w-3xl space-y-8 p-10">
      <header className="space-y-2">
        <h1 className="text-4xl">Gestion de stock</h1>
        <p className="text-muted-foreground">Texte secondaire (niveau 2)</p>
        <p className="text-sm text-subtle-foreground">
          Information discrète (niveau 3)
        </p>
      </header>

      <section className="rounded-xl border bg-card p-6 shadow-card">
        <h2 className="text-xl">Carte</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Surface blanche en Light, surface élevée en Dark.
        </p>

        <p className="mt-6 text-4xl font-semibold tracking-display tabular-nums">
          216 450
        </p>

        <div className="mt-6 flex flex-wrap items-center gap-3">
          <button className="h-10 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground transition-opacity duration-200 ease-smooth hover:opacity-90">
            Action principale
          </button>
          <span className="rounded-full bg-success-soft px-2.5 py-1 text-xs font-medium text-success">
            Payé
          </span>
          <span className="rounded-full bg-warning-soft px-2.5 py-1 text-xs font-medium text-warning">
            En attente
          </span>
          <span className="rounded-full bg-danger-soft px-2.5 py-1 text-xs font-medium text-danger">
            En retard
          </span>
        </div>
      </section>
    </main>
  );
}