import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

const landingTables = [
  "customers",
  "purchase_requests",
  "audit_events",
  "payment_reports",
  "api_rate_limits",
  "portal_users",
  "portal_email_tokens",
  "portal_sessions",
  "organizations",
  "organization_memberships",
  "organization_billing_profiles",
  "portal_consents",
  "email_outbox",
  "admin_sessions",
  "customer_email_verifications",
  "product_artifacts",
  "licenses",
  "license_challenges",
  "license_leases",
  "download_grants",
];

function sourceFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) return sourceFiles(path);
    return entry.isFile() && /\.tsx?$/.test(entry.name) ? [path] : [];
  });
}

describe("PostgreSQL schema qualification", () => {
  it("qualifies every landing table used by runtime SQL", () => {
    const sourceRoots = ["src", "scripts"].map((directory) => join(process.cwd(), directory));
    const unqualifiedTable = new RegExp(
      `\\b(?:FROM|JOIN|UPDATE|INTO)\\s+(?:${landingTables.join("|")})\\b`,
      "gi",
    );
    const failures = sourceRoots.flatMap(sourceFiles).flatMap((path) => {
      const matches = readFileSync(path, "utf8").match(unqualifiedTable) ?? [];
      return matches.map((match) => `${path}: ${match}`);
    });

    expect(failures).toEqual([]);
  });

  it("does not rely on an asynchronous search_path hook", () => {
    const adapter = readFileSync(join(process.cwd(), "src/infrastructure/database/postgres.ts"), "utf8");
    expect(adapter).not.toContain("SET search_path");
  });
});