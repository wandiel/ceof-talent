#!/bin/sh
# Lista os backups disponíveis.
set -eu
. /usr/local/lib/ceof-backup/lib.sh
ls -1 "$BACKUP_ROOT" 2>/dev/null | grep "^${BACKUP_PREFIX}-" | sort | while read -r b; do
  printf '%s\t%s\n' "$b" "$(du -sh "$BACKUP_ROOT/$b" | cut -f1)"
done
