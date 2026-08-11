import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({
  connectionQuery: vi.fn(),
  poolQuery: vi.fn(),
  release: vi.fn(),
  decryptPrivatePayload: vi.fn(),
}));

vi.mock("@/infrastructure/database/postgres", () => ({
  getPool: () => ({
    connect: async () => ({ query: mocks.connectionQuery, release: mocks.release }),
    query: mocks.poolQuery,
  }),
}));

vi.mock("@/modules/identity/domain/secure-values", () => ({
  decryptPrivatePayload: mocks.decryptPrivatePayload,
}));

import { dispatchEmailOutbox } from "./email-outbox-service";

const originalProvider = process.env.EMAIL_PROVIDER;
const originalApiKey = process.env.RESEND_API_KEY;
const originalFrom = process.env.EMAIL_FROM;

describe("dispatchEmailOutbox", () => {
  beforeEach(() => {
    process.env.EMAIL_PROVIDER = "resend";
    process.env.RESEND_API_KEY = "re_test_key_longer_than_twenty_characters";
    process.env.EMAIL_FROM = "Blue Cat <cuentas@example.com>";
    mocks.connectionQuery.mockImplementation(async (sql: string) => {
      if (sql.includes("SELECT id,recipient")) {
        return { rows: [{
          id: "11111111-1111-4111-8111-111111111111",
          recipient: "cliente@example.com",
          template_key: "verify_email",
          encrypted_payload: "encrypted",
          attempts: 0,
        }] };
      }
      return { rows: [] };
    });
    mocks.poolQuery.mockResolvedValue({ rows: [] });
    mocks.decryptPrivatePayload.mockReturnValue({
      displayName: "Cliente",
      actionUrl: "https://blue-cat-mu.vercel.app/verificar-correo#token=one-time",
      expiresHours: 24,
    });
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: true, json: async () => ({ id: "resend-message-id" }) }));
  });

  afterEach(() => {
    if (originalProvider === undefined) delete process.env.EMAIL_PROVIDER; else process.env.EMAIL_PROVIDER = originalProvider;
    if (originalApiKey === undefined) delete process.env.RESEND_API_KEY; else process.env.RESEND_API_KEY = originalApiKey;
    if (originalFrom === undefined) delete process.env.EMAIL_FROM; else process.env.EMAIL_FROM = originalFrom;
    mocks.connectionQuery.mockReset();
    mocks.poolQuery.mockReset();
    mocks.release.mockReset();
    mocks.decryptPrivatePayload.mockReset();
    vi.unstubAllGlobals();
  });

  it("claims only the requested job and uses a stable idempotency key", async () => {
    const jobId = "11111111-1111-4111-8111-111111111111";

    await expect(dispatchEmailOutbox(1, jobId)).resolves.toEqual({ claimed: 1, sent: 1, failed: 0, dead: 0 });

    const selectCall = mocks.connectionQuery.mock.calls.find(([sql]) => String(sql).includes("SELECT id,recipient"));
    expect(selectCall?.[1]).toEqual([1, jobId]);
    expect(fetch).toHaveBeenCalledWith("https://api.resend.com/emails", expect.objectContaining({
      headers: expect.objectContaining({ "Idempotency-Key": `bluecat-outbox-${jobId}` }),
    }));
  });
});
