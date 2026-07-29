"use client";

export function readPortalCsrf(): string {
  if (typeof document === "undefined") return "";
  const prefix = "bc_portal_csrf=";
  const value = document.cookie.split(";").map((part) => part.trim()).find((part) => part.startsWith(prefix));
  return value ? decodeURIComponent(value.slice(prefix.length)) : "";
}

export async function portalMutation<T>(url: string, method: "POST" | "PATCH", body?: unknown): Promise<T> {
  const response = await fetch(url, {
    method,
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": readPortalCsrf(),
    },
    body: body === undefined ? "{}" : JSON.stringify(body),
    cache: "no-store",
  });
  const payload = await response.json() as { data?: T; error?: { message?: string; fields?: Record<string, string[]> } };
  if (!response.ok || !payload.data) {
    const error = new Error(payload.error?.message || "No pudimos completar la operación.") as Error & { fields?: Record<string, string[]> };
    error.fields = payload.error?.fields;
    throw error;
  }
  return payload.data;
}
