export function commerceEnabled(): boolean {
  return process.env.COMMERCE_ENABLED === "true"
    && process.env.LEGAL_STATUS === "approved"
    && Boolean(process.env.CURRENT_TERMS_VERSION?.trim())
    && Boolean(process.env.CURRENT_PRIVACY_VERSION?.trim());
}

export function assertCommerceEnabled(): void {
  if (!commerceEnabled()) throw new Error("COMMERCE_NOT_ENABLED");
}
