import "server-only";
import { createClient } from "@supabase/supabase-js";
import type { InstallerDeliveryConfig } from "@/config/installer-delivery";

const signedUrlLifetimeSeconds = 60;

export async function createSignedInstallerUrl(config: InstallerDeliveryConfig): Promise<URL> {
  const supabase = createClient(config.supabaseUrl.origin, config.serviceRoleKey, {
    auth: { autoRefreshToken: false, persistSession: false },
  });
  const { data, error } = await supabase.storage
    .from(config.bucket)
    .createSignedUrl(config.objectPath, signedUrlLifetimeSeconds, {
      download: "BlueCat-Server-Setup.exe",
    });

  if (error || !data?.signedUrl) throw new Error("INSTALLER_STORAGE_UNAVAILABLE");
  const signedUrl = new URL(data.signedUrl, config.supabaseUrl);
  if (signedUrl.protocol !== "https:" || signedUrl.origin !== config.supabaseUrl.origin) {
    throw new Error("INSTALLER_SIGNED_URL_INVALID");
  }
  return signedUrl;
}
