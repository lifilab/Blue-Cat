import assert from "node:assert/strict";

const configuredUrl = process.env.STAGING_URL?.trim();
if (!configuredUrl) throw new Error("STAGING_URL_REQUIRED");
const baseUrl = new URL(configuredUrl);
if (baseUrl.protocol !== "https:" && process.env.ALLOW_HTTP_SMOKE !== "true") {
  throw new Error("STAGING_URL_MUST_USE_HTTPS");
}
const origin = baseUrl.origin;

async function fetchPath(path: string, init?: RequestInit): Promise<Response> {
  const response = await fetch(new URL(path, origin), {
    redirect: "follow",
    signal: AbortSignal.timeout(10_000),
    ...init,
  });
  assert.equal(new URL(response.url).origin, origin, `Redirección fuera de staging: ${path}`);
  return response;
}

async function main() {
  const home = await fetchPath("/");
  assert.equal(home.status, 200, "La landing debe responder 200.");
  assert.match(await home.text(), /Blue Cat/i, "La landing debe contener la marca Blue Cat.");
  assert.equal(home.headers.get("x-content-type-options"), "nosniff");
  assert.equal(home.headers.get("x-frame-options"), "DENY");
  assert.match(home.headers.get("content-security-policy") ?? "", /default-src 'self'/);
  assert.match(home.headers.get("x-robots-tag") ?? "", /noindex/i);
  if (baseUrl.protocol === "https:") assert.match(home.headers.get("strict-transport-security") ?? "", /max-age=/);

  const live = await fetchPath("/api/health/live");
  assert.equal(live.status, 200, "Liveness debe responder 200.");
  assert.equal((await live.json() as { status?: string }).status, "ok");

  const ready = await fetchPath("/api/health/ready");
  assert.equal(ready.status, 200, "Readiness debe confirmar aplicación y base de datos.");
  assert.equal((await ready.json() as { status?: string }).status, "ready");

  const robots = await fetchPath("/robots.txt");
  assert.equal(robots.status, 200);
  assert.match(await robots.text(), /Disallow:\s*\//i, "Staging no debe ser indexable.");

  const admin = await fetchPath("/api/admin/payment-reports");
  assert.equal(admin.status, 401, "Las rutas administrativas deben exigir autenticación.");

  const worker = await fetchPath("/api/internal/email-outbox/process", { method: "POST" });
  assert.equal(worker.status, 401, "El worker interno debe rechazar llamadas sin token.");

  const disabledCommerce = await fetchPath("/api/admin/payment-reports", {
    method: "POST",
    headers: { "Content-Type": "application/json", Origin: origin },
    body: "{}",
  });
  assert.equal(disabledCommerce.status, 503, "Staging no debe aceptar conciliaciones comerciales reales.");
  assert.equal((await disabledCommerce.json() as { error?: { code?: string } }).error?.code, "COMMERCE_NOT_ENABLED");

  for (const privatePath of ["/Blue-Cat/", "/public/pos.html", "/assets/api/auth.php"]) {
    const response = await fetchPath(privatePath);
    assert.equal(response.status, 404, `El ERP/POS local no debe publicarse: ${privatePath}`);
  }

  console.info(JSON.stringify({ status: "passed", origin, checks: 14 }));
}

main().catch((error) => {
  console.error(error instanceof Error ? error.message : "STAGING_SMOKE_FAILED");
  process.exitCode = 1;
});
