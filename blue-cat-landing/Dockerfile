# syntax=docker/dockerfile:1.7

ARG NODE_VERSION=22.22.0
FROM node:${NODE_VERSION}-bookworm-slim AS base
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1

FROM base AS dependencies
COPY package.json package-lock.json ./
RUN npm ci

FROM base AS builder
ARG NEXT_PUBLIC_SITE_URL=https://staging.blue-cat.test
ARG NEXT_PUBLIC_COMMERCIAL_EMAIL=ventas@blue-cat.test
ARG NEXT_PUBLIC_COMMERCIAL_CURRENCY_DECIMALS=0
ARG PUBLIC_INDEXING_ENABLED=false
ENV NODE_ENV=production \
    NEXT_PUBLIC_SITE_URL=${NEXT_PUBLIC_SITE_URL} \
    NEXT_PUBLIC_COMMERCIAL_EMAIL=${NEXT_PUBLIC_COMMERCIAL_EMAIL} \
    NEXT_PUBLIC_COMMERCIAL_CURRENCY_DECIMALS=${NEXT_PUBLIC_COMMERCIAL_CURRENCY_DECIMALS} \
    PUBLIC_INDEXING_ENABLED=${PUBLIC_INDEXING_ENABLED}
COPY --from=dependencies /app/node_modules ./node_modules
COPY . .
RUN npm run build

FROM base AS migrator
ENV NODE_ENV=production
COPY --from=dependencies /app/node_modules ./node_modules
COPY package.json package-lock.json tsconfig.json ./
COPY scripts/migrate.ts ./scripts/migrate.ts
COPY database ./database
USER node
CMD ["./node_modules/.bin/tsx", "scripts/migrate.ts"]

FROM base AS runner
ENV NODE_ENV=production \
    PORT=3000 \
    HOSTNAME=0.0.0.0
RUN mkdir -p /app/.next/cache && chown -R node:node /app
COPY --from=builder --chown=node:node /app/public ./public
COPY --from=builder --chown=node:node /app/.next/standalone ./
COPY --from=builder --chown=node:node /app/.next/static ./.next/static
USER node
EXPOSE 3000
HEALTHCHECK --interval=20s --timeout=5s --start-period=20s --retries=3 \
  CMD ["node", "-e", "fetch('http://127.0.0.1:3000/api/health/ready').then(r=>{if(!r.ok)process.exit(1)}).catch(()=>process.exit(1))"]
CMD ["node", "server.js"]
