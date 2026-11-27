#!/bin/sh
# Restaura banco e documentos a partir de um backup.
#
# Uso: restore <nome|latest> --yes [--only-db|--only-files]
#
# ATENÇÃO: substitui os dados atuais. Pare app/queue/scheduler antes:
#   docker compose stop app queue scheduler
# O procedimento completo está em docs/02-infraestrutura-docker.md.
set -eu
. /usr/local/lib/ceof-backup/lib.sh

target="${1:-}"; shift || true
confirm=no; do_db=yes; do_files=yes
for arg in "$@"; do
  case "$arg" in
    --yes) confirm=yes ;;
    --only-db) do_files=no ;;
    --only-files) do_db=no ;;
    *) die "Argumento desconhecido: $arg" ;;
  esac
done

[ -n "$target" ] || die "Informe o backup: restore <nome|latest> --yes"
[ "$confirm" = "yes" ] || die "Restauração substitui os dados atuais. Repita com --yes para confirmar."

dir="$(resolve_backup "$target")"
log "Verificando integridade de $dir"
verify_backup_dir "$dir"

if [ "$do_db" = "yes" ]; then
  log "Restaurando banco $PGDATABASE"
  export PGOPTIONS="-c client_min_messages=warning"
  # Encerra conexões remanescentes para permitir o DROP dos objetos.
  psql -v ON_ERROR_STOP=1 -q -c "select pg_terminate_backend(pid) from pg_stat_activity where datname = current_database() and pid <> pg_backend_pid();" "$PGDATABASE" >/dev/null
  psql -v ON_ERROR_STOP=1 -q -c "drop schema if exists public cascade; create schema public;" "$PGDATABASE"
  psql -v ON_ERROR_STOP=1 -q -c "create extension if not exists pg_trgm; create extension if not exists unaccent; create extension if not exists citext;" "$PGDATABASE"
  pg_restore --no-owner --no-privileges --exit-on-error --single-transaction \
             --dbname="$PGDATABASE" "$dir/database.dump"
fi

if [ "$do_files" = "yes" ] && documents_in_bucket; then
  log "Restaurando documentos no bucket $AWS_BUCKET"
  work="$(mktemp -d)"
  tar -xzf "$dir/storage.tar.gz" -C "$work"
  mkdir -p "$work/private"
  # Espelha os documentos do backup (pasta private/) no bucket, removendo objetos
  # que não existiam no backup. Backups do disco local também servem (migração).
  rclone sync "$work/private" "$(docs_remote)" --exclude '.health/**' || { rm -rf "$work"; die "Falha ao restaurar os documentos no bucket"; }
  rm -rf "$work"
elif [ "$do_files" = "yes" ]; then
  [ -w "$STORAGE_DIR" ] || die "$STORAGE_DIR não está gravável (use o serviço 'restore')"
  log "Restaurando documentos em $STORAGE_DIR"
  find "$STORAGE_DIR" -mindepth 1 -delete
  tar -xzf "$dir/storage.tar.gz" -C "$STORAGE_DIR"
  # Mantém a propriedade do usuário da aplicação (www-data).
  if [ -n "${STORAGE_OWNER:-}" ]; then chown -R "$STORAGE_OWNER" "$STORAGE_DIR"; fi
fi

log "Restauração concluída a partir de $dir"
log "Reinicie a aplicação: docker compose start app queue scheduler"
