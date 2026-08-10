# syntax=docker/dockerfile:1

FROM node:22-alpine AS base

WORKDIR /app

ENV NODE_ENV=development

# ---------------------------------------------------------------------------
# Development — Nuxt dev server with HMR against a bind-mounted source tree.
# ---------------------------------------------------------------------------
FROM base AS development

# node_modules is warmed in the image so the first `up` does not stall, and a
# named volume keeps it from being shadowed by the host bind mount.
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm install --no-audit --no-fund

COPY frontend/ ./

EXPOSE 3000

# Bind to all interfaces so the port publish from the host reaches it.
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "3000"]

# ---------------------------------------------------------------------------
# Build — produces .output/ for the production runtime.
# ---------------------------------------------------------------------------
FROM base AS build

COPY frontend/package.json frontend/package-lock.json* ./
RUN npm ci --no-audit --no-fund

COPY frontend/ ./

RUN npm run build

# ---------------------------------------------------------------------------
# Production — Nitro server only, no source or dev dependencies.
# ---------------------------------------------------------------------------
FROM node:22-alpine AS production

WORKDIR /app

ENV NODE_ENV=production \
    NITRO_HOST=0.0.0.0 \
    NITRO_PORT=3000

COPY --from=build --chown=node:node /app/.output ./.output

USER node

EXPOSE 3000

CMD ["node", ".output/server/index.mjs"]
