// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { SiteHeader } from "./site-header";

describe("SiteHeader", () => {
  it("offers account access as Ingresar and hides quote button when logged out", () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({
      ok: false,
      json: async () => ({ error: { message: "No session" } }),
    }));

    render(<SiteHeader />);

    for (const name of ["Navegación principal", "Navegación móvil"]) {
      const navigation = screen.getByRole("navigation", { name });
      expect(within(navigation).getAllByRole("link", { name: "Ingresar" })).toHaveLength(1);
      expect(within(navigation).queryByRole("link", { name: "Solicitar licencia" })).not.toBeInTheDocument();
      expect(within(navigation).queryByRole("link", { name: "Crear cuenta" })).not.toBeInTheDocument();
    }
  });
});