import { NextResponse } from "next/server";
import { consumeInstallerGrant } from "@/modules/licenses/application/customer-license-service";

export async function GET(request: Request) {
  const token = new URL(request.url).searchParams.get("token") ?? "";
  if (!/^[A-Za-z0-9_-]{40,100}$/.test(token)) {
    return NextResponse.json(
      { error: { code: "DOWNLOAD_GRANT_INVALID", message: "El enlace es inválido o expiró." } },
      { status: 401, headers: { "Cache-Control": "no-store" } },
    );
  }

  try {
    const downloadUrl = await consumeInstallerGrant(token);
    const response = NextResponse.redirect(downloadUrl, 303);
    response.headers.set("Cache-Control", "no-store");
    response.headers.set("Referrer-Policy", "no-referrer");
    return response;
  } catch (error) {
    const code = error instanceof Error ? error.message : "INTERNAL_ERROR";
    console.error(JSON.stringify({ level: "error", event: "installer_download_failed", code }));
    if (["DOWNLOAD_GRANT_INVALID", "LICENSE_NOT_ACTIVE", "LICENSE_EXPIRED"].includes(code)) {
      return NextResponse.json(
        { error: { code, message: "El enlace es inválido, expiró o la licencia ya no está activa." } },
        { status: 401, headers: { "Cache-Control": "no-store" } },
      );
    }
    return NextResponse.json(
      { error: { code: "INSTALLER_UNAVAILABLE", message: "El instalador no está disponible temporalmente." } },
      { status: 503, headers: { "Cache-Control": "no-store" } },
    );
  }
}
