#!/usr/bin/env bash
# Funções auxiliares dos scripts de operação (executados no HOST, exigem apenas Docker).
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TOOLS_IMAGE="ceof-talent/tools:local"

# Imagem utilitária (base PHP/Ubuntu com openssl), construída sob demanda.
ensure_tools_image() {
  if ! docker image inspect "$TOOLS_IMAGE" >/dev/null 2>&1; then
    echo "Construindo imagem utilitária $TOOLS_IMAGE..." >&2
    docker build -q --target base -f "$ROOT_DIR/docker/php/Dockerfile" -t "$TOOLS_IMAGE" "$ROOT_DIR" >/dev/null
  fi
}

