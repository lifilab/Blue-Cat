const forbiddenFragments = ["change-me", "replace-with", "example-secret", "tu-dominio"];

export interface RuntimeEnvironmentValidation {
  ok: boolean;
  errors: string[];
}

export function validateStagingEnvironment(
  environment: NodeJS.ProcessEnv = process.env,
): RuntimeEnvironmentValidation {
  const errors: string[] = [];

  expectValue(environment, "APP_ENV", "staging", errors);
  expectValue(environment, "NODE_ENV", "production", errors);
  expectValue(environment, "COMMERCE_ENABLED", "false", errors);
  expectValue(environment, "LEGAL_STATUS", "draft", errors);
  expectValue(environment, "PUBLIC_INDEXING_ENABLED", "false", errors);
  expectValue(environment, "DATABASE_CREATE_ALLOWED", "false", errors);

  validatePublicUrl(environment.NEXT_PUBLIC_SITE_URL, errors);
  validateDeploymentIdentity(environment, errors);
  validateDatabaseUrl(environment.DATABASE_URL, errors);
  validateDataKey(environment.IDENTITY_DATA_KEY, errors);

  const secretNames = ["IDENTITY_HASH_PEPPER", "OUTBOX_WORKER_TOKEN", "PURCHASE_TOKEN_SECRET"] as const;
  for (const name of secretNames) validateSecret(name, environment[name], errors);
  if (environment.CRON_SECRET) validateSecret("CRON_SECRET", environment.CRON_SECRET, errors);
  const secrets = [...secretNames.map((name) => environment[name]?.trim()), environment.CRON_SECRET?.trim()].filter(Boolean);
  if (new Set(secrets).size !== secrets.length) errors.push("STAGING_SECRETS_MUST_BE_UNIQUE");

  if (environment.EMAIL_PROVIDER !== "resend") errors.push("EMAIL_PROVIDER_MUST_BE_RESEND");
  if (!environment.RESEND_API_KEY?.startsWith("re_") || environment.RESEND_API_KEY.length < 20) {
    errors.push("RESEND_API_KEY_INVALID");
  }
  if (!environment.EMAIL_FROM || !/^.+<[^<>\s]+@[^<>\s]+>$/.test(environment.EMAIL_FROM.trim())) {
    errors.push("EMAIL_FROM_INVALID");
  }

  for (const name of ["CURRENT_TERMS_VERSION", "CURRENT_PRIVACY_VERSION", "CURRENT_MARKETING_CONSENT_VERSION"] as const) {
    const value = environment[name]?.trim() ?? "";
    if (!value || containsPlaceholder(value)) errors.push(`${name}_INVALID`);
  }

  for (const name of ["OPERATOR_EMAIL", "OPERATOR_NAME", "OPERATOR_PASSWORD"] as const) {
    if (environment[name]?.trim()) errors.push(`${name}_MUST_NOT_PERSIST`);
  }

  return { ok: errors.length === 0, errors };
}

function validateDeploymentIdentity(environment: NodeJS.ProcessEnv, errors: string[]): void {
  const commit = environment.DEPLOYMENT_COMMIT?.trim() ?? "";
  if (!/^[0-9a-f]{40}$/i.test(commit)) errors.push("DEPLOYMENT_COMMIT_MUST_BE_FULL_SHA");
  if (environment.PORTAL_IMAGE_TAG !== commit) errors.push("PORTAL_IMAGE_TAG_MUST_MATCH_COMMIT");
  try {
    const hostname = new URL(environment.NEXT_PUBLIC_SITE_URL ?? "").hostname;
    if (!environment.STAGING_HOST || environment.STAGING_HOST !== hostname) errors.push("STAGING_HOST_MUST_MATCH_SITE_URL");
  } catch {
    // NEXT_PUBLIC_SITE_URL reports its own validation error.
  }
}

function expectValue(
  environment: NodeJS.ProcessEnv,
  name: string,
  expected: string,
  errors: string[],
): void {
  if (environment[name] !== expected) errors.push(`${name}_MUST_BE_${expected.toUpperCase()}`);
}

function validatePublicUrl(value: string | undefined, errors: string[]): void {
  try {
    if (!value) throw new Error("missing");
    const url = new URL(value);
    if (url.protocol !== "https:") errors.push("SITE_URL_MUST_USE_HTTPS");
    if (url.username || url.password || url.search || url.hash || (url.pathname !== "/" && url.pathname !== "")) {
      errors.push("SITE_URL_MUST_BE_ORIGIN_ONLY");
    }
    if (["localhost", "127.0.0.1", "::1"].includes(url.hostname)) errors.push("SITE_URL_MUST_NOT_BE_LOCAL");
  } catch {
    errors.push("SITE_URL_INVALID");
  }
}

function validateDatabaseUrl(value: string | undefined, errors: string[]): void {
  try {
    if (!value) throw new Error("missing");
    const url = new URL(value);
    if (url.protocol !== "postgresql:" && url.protocol !== "postgres:") {
      errors.push("DATABASE_URL_MUST_USE_POSTGRES");
    }
    if (!url.hostname || !url.username || !url.password || !url.pathname.slice(1)) {
      errors.push("DATABASE_URL_MISSING_CREDENTIALS_OR_DATABASE");
    }
    if (["localhost", "127.0.0.1", "::1"].includes(url.hostname)) errors.push("DATABASE_URL_MUST_NOT_BE_LOCALHOST");
    if (url.hostname.startsWith("db.") && url.hostname.endsWith(".supabase.co")) {
      errors.push("DATABASE_URL_MUST_USE_SUPABASE_POOLER");
    }
  } catch {
    errors.push("DATABASE_URL_INVALID");
  }
}

function validateDataKey(value: string | undefined, errors: string[]): void {
  if (!value || containsPlaceholder(value)) {
    errors.push("IDENTITY_DATA_KEY_INVALID");
    return;
  }
  try {
    if (Buffer.from(value, "base64").length !== 32) errors.push("IDENTITY_DATA_KEY_MUST_BE_32_BYTES");
  } catch {
    errors.push("IDENTITY_DATA_KEY_INVALID");
  }
}

function validateSecret(name: string, value: string | undefined, errors: string[]): void {
  const normalized = value?.trim() ?? "";
  if (normalized.length < 32 || containsPlaceholder(normalized)) errors.push(`${name}_INVALID`);
}

function containsPlaceholder(value: string): boolean {
  const normalized = value.toLowerCase();
  return forbiddenFragments.some((fragment) => normalized.includes(fragment));
}
