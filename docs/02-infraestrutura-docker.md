# CEOF TALENT — Fase 2: Infraestrutura Docker

Este documento descreve como o ambiente é construído, executado, protegido, copiado e
restaurado. Pré-requisito único no host: **Docker Engine 24+ com Docker Compose v2.24+**
(Linux, macOS ou Windows com WSL2).

## 1. Desenvolvimento

```bash
git clone https://github.com/wandiel/ceof-talent.git
cd ceof-talent
cp .env.example .env          # opcional; ajuste HOST_UID/HOST_GID no Linux (id -u / id -g)
docker compose up -d --build
```

Acesse **http://localhost:8080** (página de status da infraestrutura nesta fase).

O que acontece na primeira subida:

1. `database` inicializa o volume, cria o papel da aplicação **sem superusuário**, o banco
   principal, o banco de testes `ceof_talent_test` e as extensões de busca.
2. `app` instala as dependências PHP se `backend/vendor` não existir ou se o `composer.lock` mudou, gera uma `APP_KEY` local
   (guardada em `backend/storage/app/.dev_app_key`), executa as migrations e inicia o PHP-FPM.
3. `queue` e `scheduler` só sobem depois que `app` está saudável.
4. `frontend` sobe o Vite com HMR; `nginx` publica tudo na porta 8080.

Nenhum usuário do sistema é criado automaticamente. O primeiro administrador é criado por
comando explícito: `docker compose exec app php artisan ceof:create-admin` (ver
[03 — Autenticação e permissões](03-autenticacao-permissoes.md)).

### Comandos do dia a dia

| Ação | Comando |
|---|---|
| Status e saúde dos serviços | `docker compose ps` |
| Logs (todos / um serviço) | `docker compose logs -f` / `docker compose logs -f app` |
| Artisan | `docker compose exec app php artisan <comando>` |
| Testes do backend | `docker compose exec app php artisan test` |
| Composer | `docker compose exec app composer <comando>` |
| npm | `docker compose exec frontend npm <comando>` |
| psql | `docker compose exec database psql -U ceof_talent ceof_talent` |
| Parar (mantém dados) | `docker compose down` |
| Teste de infraestrutura | `./docker/scripts/test-infrastructure.sh` |

> `docker compose down -v` **apaga os volumes** (banco, Redis). Use apenas se quiser
> descartar intencionalmente o ambiente de desenvolvimento.

Windows/macOS: se o HMR não detectar alterações de arquivos, defina `VITE_USE_POLLING=true`
no serviço `frontend`.

## 2. Serviços, redes e volumes

| Serviço | Imagem base | Porta interna | Healthcheck |
|---|---|---|---|
| `app` | `ubuntu:noble-20261110.1` + PHP 8.3 | 9000 (FastCGI) | ping do PHP-FPM via `cgi-fcgi` |
| `queue` | mesma imagem do `app` | — | processo `queue:work` ativo |
| `scheduler` | mesma imagem do `app` | — | processo `schedule:work` ativo |
| `frontend` | `node:22.23.5-alpine` (dev) / `nginx:1.30.8-alpine` (prod) | 5173 / 8080 | HTTP |
| `nginx` | `nginx:1.30.8-alpine` | 8080 / 8443 | `/nginx-health` |
| `database` | `postgres:17.10-alpine` | 5432 | `pg_isready` |
| `redis` | `redis:7.4.12-alpine` | 6379 | `PING` autenticado |
| `backup` | `postgres:17.10-alpine` + scripts | — | agendador ativo |

**Volumes persistentes** (sobrevivem a `down`, reinício do Docker/servidor, atualização de
imagens e recriação de containers):

| Volume | Conteúdo | Ambiente |
|---|---|---|
| `postgres_data` | dados do PostgreSQL | dev e prod |
| `redis_data` | filas pendentes e sessões (AOF) | dev e prod |
| `app_storage` | currículos e documentos (`storage/app`) | prod (em dev: `backend/storage/app`) |
| `certbot_webroot` | desafio ACME do Let's Encrypt | prod |
| `BACKUP_HOST_DIR` | backups (diretório do host, fora do Docker) | dev e prod |

**Segurança dos containers**

- Todos os processos de aplicação rodam sem root (`www-data`, `node`, `nginx`).
- Produção: `read_only: true` nos containers PHP e frontend, `cap_drop: ALL`,
  `no-new-privileges`, código pertencente ao root (somente leitura para o PHP).
- Banco e Redis sem portas publicadas e em rede `internal: true`.
- Redis com senha e comandos perigosos (`FLUSHALL`, `CONFIG`, `DEBUG`) desabilitados.
- Segredos somente via `.env` (nunca versionado) — o Compose de produção recusa subir sem eles.
- Logs no `stdout/stderr` (coletados pelo Docker, com rotação de 5 × 20 MB em produção).

## 3. Produção

### 3.1 Primeira implantação

```bash
cp .env.example .env
./docker/scripts/generate-secrets.sh      # copie APP_KEY, DB_PASSWORD, POSTGRES_SUPERUSER_PASSWORD e REDIS_PASSWORD para o .env
# edite no .env: APP_URL, SERVER_NAME, MAIL_*, BACKUP_HOST_DIR, IMAGE_TAG
```

Certificado TLS (escolha um):

- **Let's Encrypt** (recomendado): suba o Nginx, emita o certificado em modo webroot e copie
  `fullchain.pem`/`privkey.pem` para `TLS_CERTS_DIR`:
  ```bash
  docker run --rm -v ceof-talent-prod_certbot_webroot:/var/www/certbot \
    -v /etc/letsencrypt:/etc/letsencrypt certbot/certbot:<versão-estável> certonly --webroot \
    -w /var/www/certbot -d talent.suaempresa.com.br --email ti@suaempresa.com.br --agree-tos
  ```
  Substitua `<versão-estável>` por uma tag fixa da imagem oficial do Certbot. Na primeira
  emissão, use temporariamente um certificado autoassinado para o Nginx subir.
- **Homologação**: `./docker/scripts/generate-self-signed-cert.sh talent.suaempresa.com.br`

Subida:

```bash
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml ps
./docker/scripts/test-infrastructure.sh prod     # opcional, em homologação
```

### 3.2 Atualização sem perda de dados

```bash
docker compose -f docker-compose.prod.yml exec backup backup --label pre-update   # 1. backup
git pull                                                                           # 2. código
# 3. ajuste IMAGE_TAG no .env (ex.: 0.3.0)
docker compose -f docker-compose.prod.yml up -d --build                            # 4. recria containers
docker compose -f docker-compose.prod.yml ps                                       # 5. confira a saúde
```

As migrations rodam na subida do `app` (`RUN_MIGRATIONS=true`). Os volumes não são tocados.
**Nunca** use `down -v`, `docker volume prune` ou `docker system prune --volumes` em produção.

### 3.3 Logs e monitoramento

- `docker compose -f docker-compose.prod.yml logs -f --tail=200 <serviço>`
- `GET /api/health` retorna o estado de banco, Redis, cache e armazenamento (HTTP 200/503),
  adequado para monitores externos (UptimeRobot, Zabbix, etc.).
- `GET /up` (liveness do Laravel) e `/nginx-health` (porta 80).

## 4. Backup e restauração

### 4.1 O que é copiado

Cada backup é um diretório `ceof-talent-AAAAMMDD-HHMMSS[-rótulo]/` com:

| Arquivo | Conteúdo |
|---|---|
| `database.dump` | `pg_dump` formato custom (compactado) |
| `storage.tar.gz` | currículos e documentos (`storage/app`) |
| `manifest.txt` | data, versões, nº de migrations e de arquivos |
| `SHA256SUMS` | checksums para verificação de integridade |

Segredos (`.env`) **não** entram no backup: guarde-os em um cofre de senhas.

### 4.2 Backup manual, programado e verificação

```bash
docker compose exec backup backup                    # manual (prod: -f docker-compose.prod.yml)
docker compose exec backup backup --label antes-migracao
docker compose exec backup list-backups
docker compose exec backup verify-backup latest      # checksums + leitura do dump + tar
```

Backup programado: `BACKUP_ENABLED=true` (padrão em produção), diário em `BACKUP_TIME`
(padrão 02:00, fuso `APP_TIMEZONE`), mantendo `BACKUP_RETENTION_DAYS` dias (padrão 30 em prod).
Todo backup é verificado logo após ser gerado; um backup inválido não é publicado.

### 4.3 Exportação para armazenamento externo / nuvem

Os backups ficam em um diretório do host (`BACKUP_HOST_DIR`), independente dos containers.
Para cópia externa automática, crie o script executável `BACKUP_HOST_DIR/hooks/post-backup.sh`;
ele recebe o caminho do backup recém-criado. Alternativamente, sincronize o diretório a partir
do host (cron + `rclone sync`, `aws s3 sync`, etc.). Desde a Fase 12 há integração S3 nativa
(`BACKUP_S3_*`, comandos `list-backups --remote` e `fetch-backup`): ver
[manuais/backup-e-restauracao.md](manuais/backup-e-restauracao.md).

### 4.4 Restauração

```bash
docker compose stop app queue scheduler nginx
docker compose run --rm restore latest --yes          # ou o nome do backup
docker compose up -d
```

Opções: `--only-db` ou `--only-files`. Em produção acrescente `-f docker-compose.prod.yml`.
A restauração verifica a integridade antes de alterar qualquer dado e recria o schema do banco
por completo (sem resíduos de tabelas posteriores ao backup).

### 4.5 Recuperação de desastres (servidor perdido)

1. Provisione um novo servidor com Docker e clone o repositório na mesma versão (tag).
2. Restaure o `.env` a partir do cofre de senhas e copie o diretório de backups para `BACKUP_HOST_DIR`.
3. `docker compose -f docker-compose.prod.yml up -d --build` (cria volumes vazios).
4. Execute a restauração (4.4) com o backup mais recente e confira `/api/health`.

## 5. Teste de infraestrutura

`./docker/scripts/test-infrastructure.sh [prod]` executa contra os containers reais:

1. subida com espera pelos healthchecks e verificação de API, SPA e `/up`;
2. gravação de um registro de sonda no PostgreSQL e de um documento no storage;
3. `down` + `up` de todos os containers e confirmação de que ambos persistiram;
4. backup completo e verificação de integridade;
5. exclusão proposital dos dados, restauração e confirmação da recuperação;
6. isolamento de rede (banco/Redis não expostos, HTTP → HTTPS em produção) e execução sem root;
7. limpeza da sonda.

Resultado da validação da Fase 2 (26/11/2026): **todas as verificações aprovadas em
desenvolvimento e em produção** (produção com certificado autoassinado em homologação).

## 6. Builds com autenticação do Composer (opcional)

Se o build atingir o limite da API do GitHub ou usar pacotes privados, forneça
`COMPOSER_AUTH` como *build secret* (não fica gravado na imagem). Exemplo de `docker-compose.build-secrets.yml`:

```yaml
services:
  app:
    build:
      secrets: [composer_auth]
secrets:
  composer_auth:
    environment: COMPOSER_AUTH
```

```bash
export COMPOSER_AUTH='{"github-oauth":{"github.com":"<token>"}}'
docker compose -f docker-compose.prod.yml -f docker-compose.build-secrets.yml build
```

## 7. Solução de problemas

| Sintoma | Causa provável / solução |
|---|---|
| `app` reinicia com "APP_KEY não definida" (prod) | preencha `APP_KEY` no `.env` (`generate-secrets.sh`) |
| Erro de permissão em `storage/` (Linux, dev) | defina `HOST_UID`/`HOST_GID` no `.env` e rode `docker compose build app` |
| `port is already allocated` | altere `APP_PORT` (dev) ou `HTTP_PORT`/`HTTPS_PORT` (prod) |
| Nginx de produção não sobe | confira `fullchain.pem`/`privkey.pem` em `TLS_CERTS_DIR` |
| Variáveis do banco alteradas não surtem efeito | o papel/banco é criado só na 1ª inicialização do volume; altere a senha com `ALTER ROLE` |
