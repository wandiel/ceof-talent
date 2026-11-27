# syntax=docker/dockerfile:1.10
# =============================================================================
# CEOF TALENT — imagem do frontend (Vue 3 + TypeScript + Vite + Tailwind)
#
# Targets:
#   dev  -> servidor Vite com HMR, código montado via bind mount
#   prod -> build estático servido por Nginx sem privilégios (porta 8080)
# =============================================================================

ARG NODE_IMAGE=node:22.23.5-alpine
ARG NGINX_IMAGE=nginx:1.30.8-alpine

# -----------------------------------------------------------------------------
# deps: instala dependências a partir do lockfile (reprodutível)
# -----------------------------------------------------------------------------
FROM ${NODE_IMAGE} AS deps
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund \
    && sha256sum package-lock.json | cut -d' ' -f1 > node_modules/.package-lock.sha256

# -----------------------------------------------------------------------------
# dev
# -----------------------------------------------------------------------------
FROM ${NODE_IMAGE} AS dev
WORKDIR /app
COPY --from=deps --chown=node:node /app/node_modules /app/node_modules
COPY --chown=node:node frontend/package.json frontend/package-lock.json ./
COPY docker/frontend/entrypoint-dev.sh /usr/local/bin/frontend-entrypoint
RUN chmod 0755 /usr/local/bin/frontend-entrypoint && chown node:node /app
USER node
EXPOSE 5173
ENTRYPOINT ["frontend-entrypoint"]
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "5173"]

# -----------------------------------------------------------------------------
# build: gera os arquivos estáticos
# -----------------------------------------------------------------------------
FROM deps AS build
COPY frontend/ ./
RUN npm run build

# -----------------------------------------------------------------------------
# prod: Nginx servindo o SPA, executado como usuário nginx (não root)
# -----------------------------------------------------------------------------
FROM ${NGINX_IMAGE} AS prod
COPY docker/frontend/nginx.conf /etc/nginx/nginx.conf
COPY --from=build /app/dist /usr/share/nginx/html
RUN chown -R nginx:nginx /var/cache/nginx \
    && rm -f /etc/nginx/conf.d/default.conf
USER nginx
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1:8080/healthz || exit 1
CMD ["nginx", "-g", "daemon off;"]
