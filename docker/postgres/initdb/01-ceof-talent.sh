#!/bin/sh
# =============================================================================
# CEOF TALENT — inicialização do PostgreSQL
# Executado pela imagem oficial SOMENTE na primeira inicialização do volume de
# dados (diretório vazio). Nunca roda novamente sobre um banco existente.
#
# Cria:
#   - papel da aplicação (APP_DB_USER), SEM superusuário (privilégio mínimo)
#   - banco principal (APP_DB_NAME) e banco de testes (APP_DB_NAME_test)
#   - extensões usadas pela busca de currículos
# Não cria usuários do sistema nem dados de negócio.
# =============================================================================
set -eu

: "${APP_DB_USER:?APP_DB_USER não definido}"
: "${APP_DB_PASSWORD:?APP_DB_PASSWORD não definido}"
: "${APP_DB_NAME:?APP_DB_NAME não definido}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
     -v app_user="$APP_DB_USER" -v app_password="$APP_DB_PASSWORD" \
     -v app_db="$APP_DB_NAME" -v test_db="${APP_DB_NAME}_test" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE', :'app_user', :'app_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'app_user')\gexec

SELECT format('CREATE DATABASE %I OWNER %I ENCODING ''UTF8'' TEMPLATE template0', :'app_db', :'app_user')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'app_db')\gexec

SELECT format('CREATE DATABASE %I OWNER %I ENCODING ''UTF8'' TEMPLATE template0', :'test_db', :'app_user')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'test_db')\gexec

-- Ninguém além do dono conecta aos bancos da aplicação.
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', :'app_db')\gexec
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', :'test_db')\gexec
SQL

# Extensões usadas pela busca avançada de currículos (Fase 5):
#   pg_trgm  -> similaridade de texto / detecção de duplicidades
#   unaccent -> busca sem acentos ("Joao" encontra "João")
#   citext   -> e-mails sem distinção de maiúsculas
for db in "$APP_DB_NAME" "${APP_DB_NAME}_test"; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" -v app_user="$APP_DB_USER" <<'SQL'
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS citext;
SELECT format('ALTER SCHEMA public OWNER TO %I', :'app_user')\gexec
SQL
done
