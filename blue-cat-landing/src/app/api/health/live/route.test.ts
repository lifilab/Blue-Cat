import { describe, expect, it } from "vitest";
import { GET } from "./route";

describe("GET /api/health/live", () => {
  it("returns only non-sensitive service metadata", async () => {
    const previous = process.env.DEPLOYMENT_COMMIT;
    process.env.DEPLOYMENT_COMMIT = "abcdef1234567890";
    try {
      const response = await GET();
      expect(response.status).toBe(200);
      expect(response.headers.get("cache-control")).toBe("no-store");
      await expect(response.json()).resolves.toEqual(expect.objectContaining({
        status: "ok",
        service: "blue-cat-commercial-portal",
        commit: "abcdef123456",
      }));
    } finally {
      if (previous === undefined) delete process.env.DEPLOYMENT_COMMIT;
      else process.env.DEPLOYMENT_COMMIT = previous;
    }
  });
});
