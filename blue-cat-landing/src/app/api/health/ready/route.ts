import { NextResponse } from "next/server";
import { validateStagingEnvironment } from "@/config/runtime-environment";
import { getPool } from "@/infrastructure/database/mysql";

export const dynamic = "force-dynamic";

export async function GET() {
  if (process.env.APP_ENV === "staging" && !validateStagingEnvironment().ok) {
    return unavailable();
  }

  let connection: Awaited<ReturnType<ReturnType<typeof getPool>["getConnection"]>> | undefined;
  try {
    connection = await getPool().getConnection();
    await connection.query({ sql: "SELECT 1", timeout: 2_000 });
    return NextResponse.json(
      { status: "ready", service: "blue-cat-commercial-portal" },
      { headers: { "Cache-Control": "no-store" } },
    );
  } catch {
    return unavailable();
  } finally {
    connection?.release();
  }
}

function unavailable() {
  return NextResponse.json(
    { status: "unavailable", service: "blue-cat-commercial-portal" },
    { status: 503, headers: { "Cache-Control": "no-store", "Retry-After": "10" } },
  );
}
