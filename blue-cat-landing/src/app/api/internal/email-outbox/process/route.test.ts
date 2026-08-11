import { afterEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({
  dispatchEmailOutbox: vi.fn(),
}));

vi.mock("@/modules/notifications/application/email-outbox-service", () => ({
  dispatchEmailOutbox: mocks.dispatchEmailOutbox,
}));

import { GET, POST } from "./route";

const originalWorkerToken = process.env.OUTBOX_WORKER_TOKEN;
const originalCronSecret = process.env.CRON_SECRET;

afterEach(() => {
  if (originalWorkerToken === undefined) delete process.env.OUTBOX_WORKER_TOKEN;
  else process.env.OUTBOX_WORKER_TOKEN = originalWorkerToken;
  if (originalCronSecret === undefined) delete process.env.CRON_SECRET;
  else process.env.CRON_SECRET = originalCronSecret;
  mocks.dispatchEmailOutbox.mockReset();
});

describe("email outbox worker authentication", () => {
  it("fails closed when the worker token is not configured", async () => {
    delete process.env.OUTBOX_WORKER_TOKEN;

    const response = await POST(new Request("http://localhost/api/internal/email-outbox/process", { method: "POST" }));

    expect(response.status).toBe(503);
    await expect(response.json()).resolves.toMatchObject({
      error: { code: "SERVICE_NOT_CONFIGURED" },
    });
  });

  it("rejects an invalid bearer token without dispatching email", async () => {
    process.env.OUTBOX_WORKER_TOKEN = "test-only-worker-token-at-least-32-characters";

    const response = await POST(new Request("http://localhost/api/internal/email-outbox/process", {
      method: "POST",
      headers: { Authorization: "Bearer invalid" },
    }));

    expect(response.status).toBe(401);
    expect(mocks.dispatchEmailOutbox).not.toHaveBeenCalled();
  });

  it("accepts a Vercel cron request only with CRON_SECRET", async () => {
    process.env.CRON_SECRET = "test-only-cron-secret-at-least-32-characters";
    mocks.dispatchEmailOutbox.mockResolvedValue({ claimed: 1, sent: 1, failed: 0, dead: 0 });

    const response = await GET(new Request("http://localhost/api/internal/email-outbox/process", {
      headers: { Authorization: `Bearer ${process.env.CRON_SECRET}` },
    }));

    expect(response.status).toBe(200);
    await expect(response.json()).resolves.toMatchObject({ data: { claimed: 1, sent: 1 } });
    expect(mocks.dispatchEmailOutbox).toHaveBeenCalledOnce();
  });
});
