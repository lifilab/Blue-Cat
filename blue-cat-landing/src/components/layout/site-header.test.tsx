// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { render, screen, within } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { SiteHeader } from "./site-header";

describe("SiteHeader", () => {
  it("offers one combined account access in each navigation", () => {
    render(<SiteHeader />);

    for (const name of ["Navegación principal", "Navegación móvil"]) {
      const navigation = screen.getByRole("navigation", { name });
      expect(within(navigation).getAllByRole("link", { name: "Iniciar sesión o crear cuenta" })).toHaveLength(1);
      expect(within(navigation).queryByRole("link", { name: "Crear cuenta" })).not.toBeInTheDocument();
    }
  });
});