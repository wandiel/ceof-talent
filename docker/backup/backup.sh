#!/bin/sh
# Gera um backup completo: banco PostgreSQL + documentos/currículos.
# Uso: backup [--label nome]
set -eu
. /usr/local/lib/ceof-backup/lib.sh

label=""
[ "${1:-}" = "--label" ] && label="-$(printf '%s' "${2:-manual}" | tr -cd 'a-zA-Z0-9_-')"

stamp="$(date '+%Y%m%d-%H%M%S')"
name="${BACKUP_PREFIX}-${stamp}${label}"
tmp="$BACKUP_ROOT/.tmp-$name"
final="$BACKUP_ROOT/$name"

mkdir -p "$BACKUP_ROOT"
umask 077
trap 'rm -rf "$tmp"' EXIT INT TERM
mkdir -p "$tmp"

log "Iniciando backup $name"

log "Exportando banco $PGDATABASE@$PGHOST"
pg_dump --format=custom --compress=6 --no-owner --no-privileges \
        --file="$tmp/database.dump" "$PGDATABASE"

log "Compactando documentos de $STORAGE_DIR"
if [ -d "$STORAGE_DIR" ]; then
  # .health/ contém apenas arquivos temporários do healthcheck.
  tar -czf "$tmp/storage.tar.gz" -C "$STORAGE_DIR" --exclude='./private/.health' .
else
  log "Aviso: $STORAGE_DIR não existe; gerando arquivo de documentos vazio"
  tar -czf "$tmp/storage.tar.gz" -T /dev/null
fi

{
  echo "backup=$name"
  echo "created_at=$(date -Iseconds)"
  echo "database=$PGDATABASE"
  echo "postgres_server=$(psql -tAc 'show server_version' "$PGDATABASE")"
  echo "pg_dump=$(pg_dump --version)"
  echo "migrations=$(psql -tAc 'select count(*) from migrations' "$PGDATABASE" 2>/dev/null || echo 0)"
  echo "storage_files=$(tar -tzf "$tmp/storage.tar.gz" | grep -vc '/$' || true)"
} > "$tmp/manifest.txt"

( cd "$tmp" && sha256sum database.dump storage.tar.gz manifest.txt > SHA256SUMS )

verify_backup_dir "$tmp"
mv "$tmp" "$final"
trap - EXIT INT TERM
log "Backup concluído e verificado: $final ($(du -sh "$final" | cut -f1))"

# Retenção: remove backups mais antigos que BACKUP_RETENTION_DAYS (0 = nunca).
retention="${BACKUP_RETENTION_DAYS:-14}"
if [ "$retention" -gt 0 ] 2>/dev/null; then
  find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -name "${BACKUP_PREFIX}-*" -mtime +"$retention" \
    -exec sh -c 'echo "$(date "+%Y-%m-%d %H:%M:%S") [backup] Removendo backup expirado: $1" >&2; rm -rf "$1"' _ {} \;
fi

# Exportação externa opcional: script executável em /backups/hooks/post-backup.sh
# recebe o caminho do backup (ex.: rclone/aws s3 sync). Ver docs/02-infraestrutura-docker.md.
hook="$BACKUP_ROOT/hooks/post-backup.sh"
if [ -x "$hook" ]; then
  log "Executando hook de exportação externa"
  "$hook" "$final"
fi

printf '%s\n' "$final"
