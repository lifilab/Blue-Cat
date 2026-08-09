import { NextResponse } from "next/server";

export const dynamic = "force-dynamic";

export async function GET() {
  return NextResponse.json(
    {
      status: "ok",
      service: "blue-cat-commercial-portal",
      environment: process.env.APP_ENV || "development",
      commit: safeCommit(process.env.DEPLOYMENT_COMMIT),
    },
    { headers: { "Cache-Control": "no-store" } },
  );
}

function safeCommit(value: string | undefined): string {
  const commit = value?.trim() ?? "unknown";
  return /^[0-9a-f]{7,40}$/i.test(commit) ? commit.slice(0, 12) : "unknown";
}
