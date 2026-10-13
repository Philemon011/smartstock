const AUTH_PATHS = ["/login", "/register", "/forgot-password", "/reset-password", "/verify-email"];

export function firstParam(value: string | string[] | undefined): string | undefined {
  return Array.isArray(value) ? value[0] : value;
}

/**
 * N'accepte qu'un chemin interne : jamais d'adresse externe (//site.com), jamais une page d'authentification.
 */
export function safeNextPath(value: string | string[] | undefined): string {
  const path = firstParam(value);

  if (!path || !path.startsWith("/") || path.startsWith("//") || path.startsWith("/\\")) {
    return "/";
  }

  const isAuthPath = AUTH_PATHS.some(
    (authPath) =>
      path === authPath || path.startsWith(`${authPath}/`) || path.startsWith(`${authPath}?`),
  );

  return isAuthPath ? "/" : path;
}