import { beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({
  registerPortalAccount: vi.fn(),
  dispatchEmailOutbox: vi.fn(),
}));

vi.mock("@/lib/http-security", () => ({
  clientRateLimitKey: () => "test-client",
  enforceRateLimit: async () => true,
}));

vi.mock("@/modules/identity/application/identity-service", () => ({
  registerPortalAccount: mocks.registerPortalAccount,
}));

vi.mock("@/modules/identity/domain/identity-input", () => ({
  registerInputSchema: { safeParse: () => ({ success: true, data: { email: "cliente@example.com" } }) },
}));

vi.mock("@/modules/identity/infrastructure/identity-http", () => ({
  enforceIdentityOrigin: () => null,
  identityError: vi.fn(),
  identityException: vi.fn(),
  readIdentityJson: async () => ({}),
}));

vi.mock("@/modules/notifications/application/email-outbox-service", () => ({
  dispatchEmailOutbox: mocks.dispatchEmailOutbox,
}));

import { POST } from "./route";

describe("portal registration email delivery", () => {
  beforeEach(() => {
    mocks.registerPortalAccount.mockReset();
    mocks.dispatchEmailOutbox.mockReset();
  });

  it("dispatches the exact verification email after creating the account", async () => {
    mocks.registerPortalAccount.mockResolvedValue({ accepted: true, outboxId: "11111111-1111-4111-8111-111111111111" });
    mocks.dispatchEmailOutbox.mockResolvedValue({ claimed: 1, sent: 1, failed: 0, dead: 0 });

    const response = await POST(new Request("https://blue-cat-mu.vercel.app/api/auth/register", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: "{}",
    }));

    expect(response.status).toBe(202);
    expect(mocks.dispatchEmailOutbox).toHaveBeenCalledWith(1, "11111111-1111-4111-8111-111111111111");
  });
});
