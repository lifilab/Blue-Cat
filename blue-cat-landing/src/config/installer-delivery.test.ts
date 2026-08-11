import { describe, expect, it } from "vitest";
import { getInstallerDeliveryConfig } from "./installer-delivery";

const validEnvironment = {
  SUPABASE_URL: "https://ujrgeegybvibtjqxnxna.supabase.co/rest/v1/",
  SUPABASE_SERVICE_ROLE_KEY: "s".repeat(64),
  INSTALLER_STORAGE_BUCKET: "blue-cat-releases",
  INSTALLER_STORAGE_PATH: "windows/v1.0.0/BlueCat-Server-Setup.exe",

  INSTALLER_SHA256: "a".repeat(64),
  INSTALLER_VERSION: "1.0.0",
};

describe("installer delivery configuration", () => {
  it("accepts private Supabase storage and integrity metadata", () => {
    const config = getInstallerDeliveryConfig(validEnvironment);
    expect(config.supabaseUrl.origin).toBe("https://ujrgeegybvibtjqxnxna.supabase.co");
    expect(config.bucket).toBe("blue-cat-releases");
    expect(config.objectPath).toBe("windows/v1.0.0/BlueCat-Server-Setup.exe");
    expect(config.sha256).toBe("a".repeat(64));
    expect(config.version).toBe("1.0.0");
  });

  it("rejects HTTP, unsafe paths and missing checksums", () => {
    expect(() => getInstallerDeliveryConfig({
      ...validEnvironment,
      SUPABASE_URL: "http://ujrgeegybvibtjqxnxna.supabase.co",
    })).toThrow("SUPABASE_URL_HTTPS_REQUIRED");
    expect(() => getInstallerDeliveryConfig({
      ...validEnvironment,
      INSTALLER_STORAGE_PATH: "../BlueCat-Server-Setup.exe",
    })).toThrow("INSTALLER_PATH_INVALID");
    expect(() => getInstallerDeliveryConfig({
      ...validEnvironment,
      INSTALLER_SHA256: "",
    })).toThrow("INSTALLER_SHA256_INVALID");
  });
});
