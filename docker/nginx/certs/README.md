# Certificados TLS (produção)

O Nginx de produção espera os arquivos:

- `fullchain.pem` — certificado + cadeia intermediária
- `privkey.pem` — chave privada

Estes arquivos **nunca** são versionados (ver `.gitignore`).

- Homologação/local: `./docker/scripts/generate-self-signed-cert.sh <dominio>`
- Produção: Let's Encrypt (certbot em modo webroot usando `/.well-known/acme-challenge/`),
  conforme `docs/02-infraestrutura-docker.md`.
