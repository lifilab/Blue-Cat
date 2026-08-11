// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { LoginForm } from "./login-form";

describe("LoginForm email verification", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("offers and completes a verification-email resend for pending accounts", async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({
        ok: false,
        status: 403,
        json: async () => ({ error: { code: "EMAIL_NOT_VERIFIED", message: "Verifica tu correo antes de ingresar." } }),
      })
      .mockResolvedValueOnce({
        ok: true,
        status: 202,
        json: async () => ({ data: { accepted: true, message: "Si la cuenta está pendiente, enviaremos un nuevo enlace." } }),
      });
    vi.stubGlobal("fetch", fetchMock);

    render(<LoginForm />);
    fireEvent.change(screen.getByLabelText("Correo"), { target: { value: "cliente@example.com" } });
    fireEvent.change(screen.getByLabelText("Contraseña"), { target: { value: "Clave-Segura!2026" } });
    fireEvent.click(screen.getByRole("button", { name: "Ingresar" }));

    const resend = await screen.findByRole("button", { name: "Reenviar correo de confirmación" });
    fireEvent.click(resend);

    expect(await screen.findByRole("status")).toHaveTextContent("enviaremos un nuevo enlace");
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock.mock.calls[1]?.[0]).toBe("/api/auth/resend-verification");
    expect(JSON.parse(String(fetchMock.mock.calls[1]?.[1]?.body))).toEqual({
      email: "cliente@example.com",
      password: "Clave-Segura!2026",
    });
  });
});
