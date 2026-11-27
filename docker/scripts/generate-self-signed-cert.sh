#!/usr/bin/env bash
# Gera um certificado TLS autoassinado para homologação/testes do ambiente
# de produção. NÃO use em produção real: utilize Let's Encrypt.
# Uso: ./docker/scripts/generate-self-signed-cert.sh [dominio] [diretorio]
set -euo pipefail
source "$(dirname "$0")/lib.sh"
ensure_tools_image

domain="${1:-localhost}"
out="${2:-$ROOT_DIR/docker/nginx/certs}"
mkdir -p "$out"

docker run --rm --entrypoint openssl -v "$out:/out" "$TOOLS_IMAGE" \
  req -x509 -nodes -newkey rsa:2048 -days 365 \
  -subj "/CN=${domain}/O=CEOF TALENT (homologacao)" \
  -addext "subjectAltName=DNS:${domain},DNS:localhost,IP:127.0.0.1" \
  -keyout /out/privkey.pem -out /out/fullchain.pem

chmod 644 "$out/fullchain.pem"
chmod 644 "$out/privkey.pem"   # legível pelo usuário nginx (uid 101) dentro do container
echo "Certificado autoassinado gerado em $out para $domain"
