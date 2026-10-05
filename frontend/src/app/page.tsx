export default function Home() {
  return (
    <main className="mx-auto max-w-2xl p-10">
      <h1 className="text-4xl">Gestion de stock</h1>
      <h2 className="mt-2 text-xl">Titre secondaire en Instrument Sans</h2>
      <p className="mt-4">
        Texte courant en Poppins. Les produits, les entrepôts et les mouvements
        de stock s&apos;affichent ici.
      </p>

      <p className="mt-8 text-sm text-neutral-500">Chiffres normaux</p>
      <div className="text-lg">
        <p>1 111 111,00</p>
        <p>8 888 888,00</p>
        <p>1 000 000,00</p>
      </div>

      <p className="mt-6 text-sm text-neutral-500">Chiffres tabulaires</p>
      <div className="text-lg tabular-nums">
        <p>1 111 111,00</p>
        <p>8 888 888,00</p>
        <p>1 000 000,00</p>
      </div>
    </main>
  );
} 