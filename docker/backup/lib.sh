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

# ---------------------------------------------------------------------------
# Armazenamento em nuvem (rclone configurado só por variáveis de ambiente)
#
# Documentos em bucket (mesmas variáveis da aplicação):
#   FILESYSTEM_DISK=s3, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION,
#   AWS_BUCKET, AWS_ENDPOINT, AWS_USE_PATH_STYLE_ENDPOINT
# Cópia externa dos backups:
#   BACKUP_S3_BUCKET, BACKUP_S3_PREFIX, BACKUP_S3_ENDPOINT, BACKUP_S3_REGION,
#   BACKUP_S3_ACCESS_KEY_ID, BACKUP_S3_SECRET_ACCESS_KEY, BACKUP_S3_PATH_STYLE
# ---------------------------------------------------------------------------
export RCLONE_CONFIG=/dev/null RCLONE_STATS_LOG_LEVEL=NOTICE

documents_in_bucket() { [ "${FILESYSTEM_DISK:-local}" = "s3" ]; }
offsite_enabled() { [ -n "${BACKUP_S3_BUCKET:-}" ]; }

is_true() { case "$(printf '%s' "${1:-}" | tr 'A-Z' 'a-z')" in true|1|yes) return 0 ;; *) return 1 ;; esac; }

configure_remotes() {
  if documents_in_bucket; then
    [ -n "${AWS_BUCKET:-}" ] || die "FILESYSTEM_DISK=s3 exige AWS_BUCKET"
    export RCLONE_CONFIG_DOCS_TYPE=s3 RCLONE_CONFIG_DOCS_PROVIDER=Other RCLONE_CONFIG_DOCS_ENV_AUTH=false
    export RCLONE_CONFIG_DOCS_ACCESS_KEY_ID="${AWS_ACCESS_KEY_ID:-}" RCLONE_CONFIG_DOCS_SECRET_ACCESS_KEY="${AWS_SECRET_ACCESS_KEY:-}"
    export RCLONE_CONFIG_DOCS_REGION="${AWS_DEFAULT_REGION:-us-east-1}" RCLONE_CONFIG_DOCS_ENDPOINT="${AWS_ENDPOINT:-}"
    is_true "${AWS_USE_PATH_STYLE_ENDPOINT:-false}" && export RCLONE_CONFIG_DOCS_FORCE_PATH_STYLE=true || export RCLONE_CONFIG_DOCS_FORCE_PATH_STYLE=false
    [ -z "${AWS_ENDPOINT:-}" ] && export RCLONE_CONFIG_DOCS_PROVIDER=AWS
  fi
  if offsite_enabled; then
    export RCLONE_CONFIG_OFFSITE_TYPE=s3 RCLONE_CONFIG_OFFSITE_PROVIDER=Other RCLONE_CONFIG_OFFSITE_ENV_AUTH=false
    export RCLONE_CONFIG_OFFSITE_ACCESS_KEY_ID="${BACKUP_S3_ACCESS_KEY_ID:-}" RCLONE_CONFIG_OFFSITE_SECRET_ACCESS_KEY="${BACKUP_S3_SECRET_ACCESS_KEY:-}"
    export RCLONE_CONFIG_OFFSITE_REGION="${BACKUP_S3_REGION:-us-east-1}" RCLONE_CONFIG_OFFSITE_ENDPOINT="${BACKUP_S3_ENDPOINT:-}"
    is_true "${BACKUP_S3_PATH_STYLE:-false}" && export RCLONE_CONFIG_OFFSITE_FORCE_PATH_STYLE=true || export RCLONE_CONFIG_OFFSITE_FORCE_PATH_STYLE=false
    [ -z "${BACKUP_S3_ENDPOINT:-}" ] && export RCLONE_CONFIG_OFFSITE_PROVIDER=AWS
  fi
  return 0
}

docs_remote() { printf 'docs:%s' "$AWS_BUCKET"; }
offsite_remote() { printf 'offsite:%s/%s' "$BACKUP_S3_BUCKET" "${BACKUP_S3_PREFIX:-ceof-talent}"; }

configure_remotes
