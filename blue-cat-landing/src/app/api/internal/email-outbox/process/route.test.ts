import { afterEach, describe, expect, it } from "vitest";
import { POST } from "./route";

const originalToken = process.env.OUTBOX_WORKER_TOKEN;

afterEach(() => {
  if (originalToken === undefined) {
    delete process.env.OUTBOX_WORKER_TOKEN;
  } else {
    process.env.OUTBOX_WORKER_TOKEN = originalToken;
  }
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
  });
});
