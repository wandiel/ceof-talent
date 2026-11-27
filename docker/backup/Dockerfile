# syntax=docker/dockerfile:1.10
# =============================================================================
# CEOF TALENT — serviço de backup e restauração
# Usa as mesmas ferramentas cliente do servidor (pg_dump/pg_restore 17).
# Os backups são gravados em /backups, montado a partir de um diretório do
# HOST (BACKUP_HOST_DIR), portanto independem do ciclo de vida dos containers
# e podem ser sincronizados para armazenamento externo/nuvem.
# =============================================================================
ARG POSTGRES_IMAGE=postgres:17.10-alpine
FROM ${POSTGRES_IMAGE}

# rclone: cópia dos backups para armazenamento externo (S3 e compatíveis) e
# leitura/gravação dos documentos quando eles ficam em bucket (FILESYSTEM_DISK=s3).
RUN apk add --no-cache rclone ca-certificates

COPY docker/backup/lib.sh        /usr/local/lib/ceof-backup/lib.sh
COPY docker/backup/backup.sh     /usr/local/bin/backup
COPY docker/backup/restore.sh    /usr/local/bin/restore
COPY docker/backup/verify.sh     /usr/local/bin/verify-backup
COPY docker/backup/list.sh       /usr/local/bin/list-backups
COPY docker/backup/scheduler.sh  /usr/local/bin/backup-scheduler
COPY docker/backup/fetch.sh      /usr/local/bin/fetch-backup

RUN chmod 0755 /usr/local/bin/backup /usr/local/bin/restore /usr/local/bin/verify-backup \
               /usr/local/bin/list-backups /usr/local/bin/backup-scheduler /usr/local/bin/fetch-backup

ENTRYPOINT []
CMD ["backup-scheduler"]
