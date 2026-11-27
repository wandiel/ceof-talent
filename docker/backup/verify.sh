#!/bin/sh
# Verifica a integridade de um backup. Uso: verify-backup <nome|latest>
set -eu
. /usr/local/lib/ceof-backup/lib.sh
dir="$(resolve_backup "${1:-latest}")"
verify_backup_dir "$dir"
log "Backup íntegro: $dir"
cat "$dir/manifest.txt"
