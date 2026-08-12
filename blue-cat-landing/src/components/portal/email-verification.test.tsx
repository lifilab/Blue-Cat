// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { EmailVerification } from "./email-verification";

describe("EmailVerification", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    window.history.replaceState(null, "", "/");
  });

  it("confirms the one-time token and removes it from the browser URL", async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ data: { verified: true } }),
    });
    vi.stubGlobal("fetch", fetchMock);
    window.history.replaceState(null, "", "/verificar-correo#token=verification-token");

    render(<EmailVerification />);

    expect(await screen.findByRole("heading", { name: "Cuenta verificada" })).toBeInTheDocument();
    expect(fetchMock).toHaveBeenCalledWith("/api/auth/verify-email", expect.objectContaining({
      method: "POST",
      body: JSON.stringify({ token: "verification-token" }),
    }));
    expect(window.location.hash).toBe("");
  });

  it("does not show missing-token message after successful verification", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ data: { verified: true } }),
    }));
    window.history.replaceState(null, "", "/verificar-correo#token=one-time-token");

    render(<EmailVerification />);

    await screen.findByRole("heading", { name: "Cuenta verificada" });
    expect(screen.queryByText("Falta el token")).not.toBeInTheDocument();
  });

  it("shows missing-token message when no token is present in the URL", () => {
    window.history.replaceState(null, "", "/verificar-correo");

    render(<EmailVerification />);

    expect(screen.getByRole("heading", { name: "Falta el token" })).toBeInTheDocument();
  });
});

