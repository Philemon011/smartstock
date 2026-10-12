type RegistrationSuccessProps = {
  email: string;
  message: string;
};

export function RegistrationSuccess({ email, message }: RegistrationSuccessProps) {
  return (
    <div className="space-y-4">
      <h1 className="text-3xl">Vérifiez votre boîte mail</h1>
      <p className="text-muted-foreground">{message}</p>
      <p className="text-sm text-muted-foreground">
        Adresse saisie :{" "}
        <span className="font-medium text-foreground">{email}</span>
      </p>
    </div>
  );
}