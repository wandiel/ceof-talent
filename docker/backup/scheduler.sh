#!/bin/sh
# Agendador de backups: executa "backup" diariamente em BACKUP_TIME (HH:MM, fuso TZ).
# BACKUP_ENABLED=false desativa o agendamento (backups manuais continuam disponíveis).
set -eu
. /usr/local/lib/ceof-backup/lib.sh

if [ "${BACKUP_ENABLED:-true}" != "true" ]; then
  log "Backup programado desativado (BACKUP_ENABLED=false). Aguardando comandos manuais."
  exec tail -f /dev/null
fi

time_of_day="${BACKUP_TIME:-02:00}"
echo "$time_of_day" | grep -Eq '^([01][0-9]|2[0-3]):[0-5][0-9]$' || die "BACKUP_TIME inválido: $time_of_day"
hh="${time_of_day%:*}"; mm="${time_of_day#*:}"

log "Backup programado diariamente às $time_of_day ($TZ), retenção ${BACKUP_RETENTION_DAYS:-14} dias"

while true; do
  now="$(date +%s)"
  next="$(date -d "$(date +%Y-%m-%d) $hh:$mm:00" +%s 2>/dev/null || date -D '%Y-%m-%d %H:%M:%S' -d "$(date +%Y-%m-%d) $hh:$mm:00" +%s)"
  [ "$next" -le "$now" ] && next=$((next + 86400))
  sleep $((next - now))
  backup --label scheduled || log "Falha no backup programado (será tentado novamente no próximo ciclo)"
done
