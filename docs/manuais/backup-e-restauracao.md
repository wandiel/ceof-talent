# Backup e restauração — CEOF TALENT

## 1. O que é guardado

Cada backup é uma pasta `ceof-talent-AAAAMMDD-HHMMSS[-rótulo]` dentro de `backups/` (ou da
pasta definida em `BACKUP_HOST_DIR`), com:

| Arquivo | Conteúdo |
|---|---|
| `database.dump` | banco de dados completo (PostgreSQL) |
| `storage.tar.gz` | currículos e documentos (do servidor ou do bucket S3) |
| `manifest.txt` | data, versões, quantidade de migrations e de arquivos, origem dos documentos |
| `SHA256SUMS` | impressões digitais para conferir a integridade |

Todo backup é conferido logo depois de criado (checksums, leitura do dump e do arquivo de
documentos). Um backup com defeito não é publicado. O `.env` (senhas) **não** entra no backup:
guarde-o em um cofre de senhas.

> Nos comandos abaixo, em produção acrescente `-f docker-compose.prod.yml` logo depois de
> `docker compose`. No Windows, rode no PowerShell ou no Git Bash, dentro da pasta do sistema.

## 2. Backup automático

No `.env`: `BACKUP_ENABLED=true` (padrão em produção), `BACKUP_TIME=02:00` e
`BACKUP_RETENTION_DAYS=30` (backups mais antigos são apagados). Para conferir:

```
docker compose logs --tail=20 backup
docker compose exec backup list-backups
```

## 3. Backup manual

Antes de qualquer mudança importante:

```
docker compose exec backup backup --label antes-da-mudanca
docker compose exec backup verify-backup latest
```

## 4. Cópia externa (nuvem)

Um backup no mesmo servidor não protege contra perda do servidor. Configure a cópia
automática de cada backup para um bucket S3 ou compatível (AWS S3, Wasabi, Backblaze B2,
Cloudflare R2, MinIO etc.) no `.env`:

```
BACKUP_S3_BUCKET=ceof-talent-backups
BACKUP_S3_ENDPOINT=            # vazio para Amazon S3; ex.: https://s3.wasabisys.com
BACKUP_S3_REGION=us-east-1
BACKUP_S3_ACCESS_KEY_ID=...
BACKUP_S3_SECRET_ACCESS_KEY=...
BACKUP_S3_PATH_STYLE=false     # true para MinIO e alguns provedores
BACKUP_S3_RETENTION_DAYS=90    # vazio = igual à retenção local; 0 = nunca apaga
```

Aplique com `docker compose up -d backup`. A partir daí, cada backup é enviado para
`s3://BUCKET/ceof-talent/<nome-do-backup>/` e conferido arquivo por arquivo. Se o envio
falhar, o backup local continua válido e o erro aparece no log do serviço `backup`.

Boas práticas: bucket **privado**, chave de acesso **só** para esse bucket, e, se o provedor
oferecer, versionamento ou bloqueio de objetos (proteção contra ransomware).

Ver os backups na nuvem: `docker compose exec backup list-backups --remote`

## 5. Restauração

> A restauração **substitui** os dados atuais pelos do backup. Faça um backup manual antes,
> se os dados atuais tiverem algum valor.

```
docker compose stop app queue scheduler nginx
docker compose run --rm restore latest --yes        # ou o nome exato do backup
docker compose up -d
```

- `--only-db`: só o banco. `--only-files`: só os documentos.
- A integridade é conferida antes de qualquer alteração.
- Se os documentos estiverem em bucket (`FILESYSTEM_DISK=s3`), eles são devolvidos ao bucket.

### Restaurar a partir da cópia externa

```
docker compose exec backup fetch-backup latest      # baixa e confere
docker compose stop app queue scheduler nginx
docker compose run --rm restore latest --yes
docker compose up -d
```

## 6. Servidor perdido (recuperação de desastre)

1. Prepare um servidor novo ([implantacao-na-nuvem.md](implantacao-na-nuvem.md), passos 1 a 4).
2. Recupere o `.env` do cofre de senhas.
3. `docker compose -f docker-compose.prod.yml up -d --build` (sobe com o banco vazio).
4. `docker compose -f docker-compose.prod.yml exec backup fetch-backup latest`
   (ou copie a pasta do backup para `backups/`).
5. Restaure (item 5) e confira `https://SEU-ENDEREÇO/api/health`.

## 7. Teste periódico

Backup que nunca foi restaurado é uma esperança, não um backup. Uma vez por mês (ou depois de
mudanças na infraestrutura), rode em homologação:

```
./docker/scripts/test-infrastructure.sh          # ou: ./docker/scripts/homologacao.sh
```

Ele cria um backup, simula perda de dados, restaura e confere.
