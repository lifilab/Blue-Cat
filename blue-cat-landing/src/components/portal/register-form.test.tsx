// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { RegisterForm } from "./register-form";

describe("RegisterForm", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("shows the success state after the asynchronous registration completes", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ data: { accepted: true } }),
    }));

    render(<RegisterForm termsVersion="terms-2026-07" privacyVersion="privacy-2026-07" />);
    fireEvent.change(screen.getByLabelText("Nombre completo"), { target: { value: "Codex Verification" } });
    fireEvent.change(screen.getByLabelText("Correo"), { target: { value: "codex@example.invalid" } });
    fireEvent.change(screen.getByLabelText("Contraseña"), { target: { value: "Codex-Verify!2026" } });
    fireEvent.click(screen.getByRole("checkbox", { name: /Acepto los términos/i }));
    fireEvent.click(screen.getByRole("button", { name: "Crear cuenta segura" }));

    expect(await screen.findByRole("status")).toHaveTextContent("Revisa tu correo");
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });
});