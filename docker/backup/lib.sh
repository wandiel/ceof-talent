#!/bin/sh
# Funções compartilhadas pelos scripts de backup do CEOF TALENT.

BACKUP_ROOT="${BACKUP_ROOT:-/backups}"
STORAGE_DIR="${STORAGE_DIR:-/data/storage}"
BACKUP_PREFIX="${BACKUP_PREFIX:-ceof-talent}"

export PGHOST="${PGHOST:-database}"
export PGPORT="${PGPORT:-5432}"
export PGDATABASE="${PGDATABASE:-ceof_talent}"
export PGUSER="${PGUSER:-ceof_talent}"
# PGPASSWORD vem do ambiente (nunca gravado em disco pelos scripts).

log() { printf '%s [backup] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2; }
die() { log "ERRO: $*"; exit 1; }

# Resolve um nome de backup (ou "latest") para o caminho do diretório.
resolve_backup() {
  name="$1"
  if [ "$name" = "latest" ]; then
    name="$(ls -1 "$BACKUP_ROOT" 2>/dev/null | grep "^${BACKUP_PREFIX}-" | sort | tail -n 1 || true)"
    [ -n "$name" ] || die "Nenhum backup encontrado em $BACKUP_ROOT"
  fi
  path="$BACKUP_ROOT/$name"
  [ -d "$path" ] || die "Backup não encontrado: $path"
  printf '%s' "$path"
}

# Verifica checksums, a legibilidade do dump e a integridade do tar.gz.
verify_backup_dir() {
  dir="$1"
  [ -f "$dir/SHA256SUMS" ] || die "SHA256SUMS ausente em $dir"
  [ -f "$dir/manifest.txt" ] || die "manifest.txt ausente em $dir"
  ( cd "$dir" && sha256sum -c -s SHA256SUMS ) || die "Checksum inválido em $dir"
  pg_restore --list "$dir/database.dump" >/dev/null 2>&1 || die "Dump do banco ilegível em $dir"
  gzip -t "$dir/storage.tar.gz" 2>/dev/null || die "Arquivo de documentos corrompido em $dir"
  tar -tzf "$dir/storage.tar.gz" >/dev/null 2>&1 || die "Arquivo de documentos ilegível em $dir"
}
