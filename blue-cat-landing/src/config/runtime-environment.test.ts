import { describe, expect, it } from "vitest";
import { validateStagingEnvironment } from "./runtime-environment";

function validEnvironment(): NodeJS.ProcessEnv {
  return {
    APP_ENV: "staging",
    NODE_ENV: "production",
    NEXT_PUBLIC_SITE_URL: "https://staging.blue-cat.test",
    STAGING_HOST: "staging.blue-cat.test",
    DEPLOYMENT_COMMIT: "a".repeat(40),
    PORTAL_IMAGE_TAG: "a".repeat(40),
    DATABASE_URL: `postgresql://portal:${"d".repeat(40)}@database:5432/blue_cat_portal`,
    DATABASE_CREATE_ALLOWED: "false",
    IDENTITY_DATA_KEY: Buffer.alloc(32, 7).toString("base64"),
    IDENTITY_HASH_PEPPER: "h".repeat(64),
    OUTBOX_WORKER_TOKEN: "o".repeat(64),
    PURCHASE_TOKEN_SECRET: "p".repeat(64),
    EMAIL_PROVIDER: "resend",
    RESEND_API_KEY: `re_${"r".repeat(32)}`,
    EMAIL_FROM: "Blue Cat <cuentas@blue-cat.test>",
    CURRENT_TERMS_VERSION: "terms-staging-2026-08",
    CURRENT_PRIVACY_VERSION: "privacy-staging-2026-08",
    CURRENT_MARKETING_CONSENT_VERSION: "marketing-staging-2026-08",
    COMMERCE_ENABLED: "false",
    LEGAL_STATUS: "draft",
    PUBLIC_INDEXING_ENABLED: "false",
  };
}

describe("validateStagingEnvironment", () => {
  it("accepts a hardened staging configuration", () => {
    expect(validateStagingEnvironment(validEnvironment())).toEqual({ ok: true, errors: [] });
  });

  it("rejects public commerce, indexing, insecure URLs and persisted bootstrap credentials", () => {
    const environment = validEnvironment();
    environment.NEXT_PUBLIC_SITE_URL = "http://localhost:3000/path";
    environment.COMMERCE_ENABLED = "true";
    environment.PUBLIC_INDEXING_ENABLED = "true";
    environment.OPERATOR_PASSWORD = "must-not-remain";
    const result = validateStagingEnvironment(environment);

    expect(result.ok).toBe(false);
    expect(result.errors).toEqual(expect.arrayContaining([
      "COMMERCE_ENABLED_MUST_BE_FALSE",
      "PUBLIC_INDEXING_ENABLED_MUST_BE_FALSE",
      "SITE_URL_MUST_USE_HTTPS",
      "SITE_URL_MUST_BE_ORIGIN_ONLY",
      "SITE_URL_MUST_NOT_BE_LOCAL",
      "OPERATOR_PASSWORD_MUST_NOT_PERSIST",
    ]));
  });

  it("requires an immutable image tag that matches the public host", () => {
    const environment = validEnvironment();
    environment.PORTAL_IMAGE_TAG = "staging-latest";
    environment.STAGING_HOST = "other.blue-cat.test";
    const result = validateStagingEnvironment(environment);

    expect(result.errors).toEqual(expect.arrayContaining([
      "PORTAL_IMAGE_TAG_MUST_MATCH_COMMIT",
      "STAGING_HOST_MUST_MATCH_SITE_URL",
    ]));
  });

  it("rejects missing, repeated or malformed secrets", () => {
    const environment = validEnvironment();
    environment.IDENTITY_DATA_KEY = "not-base64";
    environment.IDENTITY_HASH_PEPPER = "x".repeat(32);
    environment.OUTBOX_WORKER_TOKEN = "x".repeat(32);
    environment.PURCHASE_TOKEN_SECRET = "short";
    const result = validateStagingEnvironment(environment);

    expect(result.errors).toEqual(expect.arrayContaining([
      "IDENTITY_DATA_KEY_MUST_BE_32_BYTES",
      "PURCHASE_TOKEN_SECRET_INVALID",
      "STAGING_SECRETS_MUST_BE_UNIQUE",
    ]));
  });

  it("rejects direct Supabase database hosts in deployed environments", () => {
    const environment = validEnvironment();
    environment.DATABASE_URL = `postgresql://postgres:${"d".repeat(40)}@db.ujrgeegybvibtjqxnxna.supabase.co:5432/postgres`;
    const result = validateStagingEnvironment(environment);

    expect(result.errors).toContain("DATABASE_URL_MUST_USE_SUPABASE_POOLER");
  });
});
