import type { MetadataRoute } from "next";
import { siteConfig } from "@/config/site";

export default function robots(): MetadataRoute.Robots {
  if (process.env.PUBLIC_INDEXING_ENABLED !== "true") {
    return { rules: { userAgent: "*", disallow: "/" } };
  }
  return {
    rules: { userAgent: "*", allow: "/", disallow: ["/api/", "/comprar/confirmacion"] },
    sitemap: `${siteConfig.url}/sitemap.xml`,
  };
}
