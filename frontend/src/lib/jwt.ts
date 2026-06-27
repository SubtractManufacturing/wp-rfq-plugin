interface JwtPayload {
  exp?: number;
}

export function getJwtExpiration(token: string): number | null {
  const [, payload] = token.split(".");
  if (!payload) {
    return null;
  }

  try {
    const normalized = payload.replace(/-/g, "+").replace(/_/g, "/");
    const parsed = JSON.parse(atob(normalized)) as JwtPayload;
    return typeof parsed.exp === "number" ? parsed.exp : null;
  } catch {
    return null;
  }
}
