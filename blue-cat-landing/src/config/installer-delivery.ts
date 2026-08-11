export interface InstallerDeliveryConfig {
  supabaseUrl: URL;
  serviceRoleKey: string;
  bucket: string;
  objectPath: string;
  sha256: string;
  version: string;
}

export interface InstallerDeliveryEnvironment {
  SUPABASE_URL?: string;
  SUPABASE_SERVICE_ROLE_KEY?: string;
  INSTALLER_STORAGE_BUCKET?: string;
  INSTALLER_STORAGE_PATH?: string;
  INSTALLER_SHA256?: string;
  INSTALLER_VERSION?: string;
}

export function getInstallerDeliveryConfig(
  environment: InstallerDeliveryEnvironment = process.env as InstallerDeliveryEnvironment,
): InstallerDeliveryConfig {
  const rawUrl = environment.SUPABASE_URL?.trim() ?? "";
  let supabaseUrl: URL;
  try {
    supabaseUrl = new URL(rawUrl);
  } catch {
    throw new Error("SUPABASE_URL_INVALID");
  }

  if (supabaseUrl.protocol !== "https:") throw new Error("SUPABASE_URL_HTTPS_REQUIRED");
  if (supabaseUrl.username || supabaseUrl.password || supabaseUrl.search || supabaseUrl.hash) {
    throw new Error("SUPABASE_URL_INVALID");
  }
  supabaseUrl.pathname = "/";

  const serviceRoleKey = environment.SUPABASE_SERVICE_ROLE_KEY?.trim() ?? "";
  if (serviceRoleKey.length < 32) throw new Error("SUPABASE_SERVICE_ROLE_KEY_INVALID");

  const bucket = environment.INSTALLER_STORAGE_BUCKET?.trim() ?? "";
  if (!/^[a-z0-9][a-z0-9._-]{2,62}$/i.test(bucket)) throw new Error("INSTALLER_BUCKET_INVALID");

  const objectPath = environment.INSTALLER_STORAGE_PATH?.trim().replace(/^\/+/, "") ?? "";
  if (!objectPath
    || objectPath.length > 500
    || objectPath.split("/").some((segment) => !segment || segment === "." || segment === "..")
    || !objectPath.endsWith("BlueCat-Server-Setup.exe")) {
    throw new Error("INSTALLER_PATH_INVALID");
  }
  const sha256 = environment.INSTALLER_SHA256?.trim().toLowerCase() ?? "";
  if (!/^[a-f0-9]{64}$/.test(sha256)) throw new Error("INSTALLER_SHA256_INVALID");

  const version = environment.INSTALLER_VERSION?.trim() ?? "";
  if (!/^[0-9A-Za-z][0-9A-Za-z._+-]{0,39}$/.test(version)) {
    throw new Error("INSTALLER_VERSION_INVALID");
  }

  return { supabaseUrl, serviceRoleKey, bucket, objectPath, sha256, version };
}
