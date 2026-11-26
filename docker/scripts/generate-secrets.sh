#!/usr/bin/env bash
# Gera valores aleatórios fortes para os segredos do .env de produção.
# Uso: ./docker/scripts/generate-secrets.sh   (copie a saída para o .env)
set -euo pipefail
source "$(dirname "$0")/lib.sh"
ensure_tools_image

rand() { docker run --rm --entrypoint openssl "$TOOLS_IMAGE" rand -base64 "$1" | tr -d '\n/+=' | cut -c1-"$2"; }

echo "# Segredos gerados em $(date -Iseconds). Guarde-os em local seguro (cofre de senhas)."
echo "APP_KEY=base64:$(docker run --rm --entrypoint openssl "$TOOLS_IMAGE" rand -base64 32)"
echo "DB_PASSWORD=$(rand 48 40)"
echo "POSTGRES_SUPERUSER_PASSWORD=$(rand 48 40)"
echo "REDIS_PASSWORD=$(rand 48 40)"
