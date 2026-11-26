#!/bin/sh
# Mantém node_modules (volume nomeado) sincronizado com o package-lock.json
# montado do host. Reinstala apenas quando o lockfile muda.
set -eu
cd /app

marker=node_modules/.package-lock.sha256
current="$(sha256sum package-lock.json | cut -d' ' -f1)"

if [ ! -f "$marker" ] || [ "$(cat "$marker")" != "$current" ]; then
  echo "[frontend] package-lock.json alterado: executando npm ci"
  npm ci --no-audit --no-fund
  echo "$current" > "$marker"
fi

exec "$@"
