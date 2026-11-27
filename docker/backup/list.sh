#!/bin/sh
# Lista os backups disponíveis.
set -eu
. /usr/local/lib/ceof-backup/lib.sh
if [ "${1:-}" = "--remote" ]; then
  offsite_enabled || die "Cópia externa não configurada (BACKUP_S3_BUCKET)"
  rclone lsf --dirs-only "$(offsite_remote)" | sed 's#/$##' | grep "^${BACKUP_PREFIX}-" | sort
  exit 0
fi
ls -1 "$BACKUP_ROOT" 2>/dev/null | grep "^${BACKUP_PREFIX}-" | sort | while read -r b; do
  printf '%s\t%s\n' "$b" "$(du -sh "$BACKUP_ROOT/$b" | cut -f1)"
done
