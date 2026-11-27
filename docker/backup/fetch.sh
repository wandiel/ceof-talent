#!/bin/sh
# Baixa um backup da cópia externa (S3) para o diretório local de backups.
# Uso: fetch-backup <nome|latest>
# Depois, restaure normalmente: docker compose run --rm restore <nome> --yes
set -eu
. /usr/local/lib/ceof-backup/lib.sh

offsite_enabled || die "Cópia externa não configurada (BACKUP_S3_BUCKET)"
name="${1:-}"
[ -n "$name" ] || die "Informe o backup: fetch-backup <nome|latest>"
if [ "$name" = "latest" ]; then
  name="$(rclone lsf --dirs-only "$(offsite_remote)" | sed 's#/$##' | grep "^${BACKUP_PREFIX}-" | sort | tail -n 1)"
  [ -n "$name" ] || die "Nenhum backup encontrado em $(offsite_remote)"
fi
[ -e "$BACKUP_ROOT/$name" ] && die "Já existe um backup local com esse nome: $BACKUP_ROOT/$name"

tmp="$BACKUP_ROOT/.tmp-fetch-$name"
trap 'rm -rf "$tmp"' EXIT INT TERM
log "Baixando $(offsite_remote)/$name"
rclone copy "$(offsite_remote)/$name" "$tmp"
verify_backup_dir "$tmp"
mv "$tmp" "$BACKUP_ROOT/$name"
trap - EXIT INT TERM
log "Backup baixado e verificado: $BACKUP_ROOT/$name"
printf '%s\n' "$BACKUP_ROOT/$name"
