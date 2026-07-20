import type { ReactNode } from "react";

export function PageHero({ eyebrow, title, children }: { eyebrow: string; title: string; children: ReactNode }) {
  return <section className="page-hero"><div className="container"><span className="eyebrow">{eyebrow}</span><h1 className="headline">{title}</h1><div className="lede">{children}</div></div></section>;
}
