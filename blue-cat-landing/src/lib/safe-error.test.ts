import { describe, expect, it } from "vitest";
import { safeErrorCode } from "./safe-error";

describe("safeErrorCode", () => {
  it("preserves the public commerce gate code", () => {
    expect(safeErrorCode(new Error("COMMERCE_NOT_ENABLED"))).toBe("COMMERCE_NOT_ENABLED");
  });

  it("does not expose unexpected internal messages", () => {
    expect(safeErrorCode(new Error("database host and password leaked"))).toBe("Error");
  });
});
