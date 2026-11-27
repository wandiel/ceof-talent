# CEOF TALENT — Fase 12: Documentação e preparação para nuvem

## 1. O que foi entregue

| Item | Onde |
|---|---|
| Manual do usuário (RH, gestores, entrevistadores) | [manuais/manual-do-usuario.md](manuais/manual-do-usuario.md) |
| Manual do administrador | [manuais/manual-do-administrador.md](manuais/manual-do-administrador.md) |
| Backup e restauração | [manuais/backup-e-restauracao.md](manuais/backup-e-restauracao.md) |
| Atualização de versão | [manuais/atualizacao.md](manuais/atualizacao.md) e `docker/scripts/atualizar.sh` |
| Implantação em VPS/nuvem | [manuais/implantacao-na-nuvem.md](manuais/implantacao-na-nuvem.md) |
| Documentação da API (OpenAPI 3.1) | [api/openapi.json](api/openapi.json) e [api/README.md](api/README.md) |
| Documentos em bucket S3 | `FILESYSTEM_DISK=s3` + `AWS_*` |
| Cópia externa automática dos backups | `BACKUP_S3_*` |

## 2. Documentos em S3

Antes desta fase, a variável `FILESYSTEM_DISK=s3` existia mas os documentos continuavam presos
ao disco local. Agora:

- O disco dos documentos vem da configuração (`filesystems.documents`): `local` ou `s3`.
- Upload, download, exclusão, anonimização (LGPD) e a verificação de saúde (`/api/health`) usam
  o disco configurado.
- A leitura automática de currículos baixa uma cópia temporária do bucket e a apaga logo depois.
- O bucket é gravado como **privado** e erros de gravação aparecem (não são ignorados).
- Pacote `league/flysystem-aws-s3-v3` adicionado.
- O serviço de backup copia os documentos do bucket para dentro do backup e a restauração os
  devolve ao bucket. O formato do backup é o mesmo nos dois modos, o que permite **migrar** do
  disco local para o bucket com uma restauração (`--only-files`).

## 3. Cópia externa dos backups

O serviço de backup ganhou o `rclone`. Com `BACKUP_S3_BUCKET` preenchido, cada backup verificado
é enviado para `s3://BUCKET/PREFIXO/<backup>/` e conferido (`rclone check`). Há retenção
própria na nuvem (`BACKUP_S3_RETENTION_DAYS`) e dois comandos novos:

| Comando | Uso |
|---|---|
| `list-backups --remote` | lista os backups na nuvem |
| `fetch-backup <nome\|latest>` | baixa e verifica um backup da nuvem para restaurar |

Funciona com AWS S3 e serviços compatíveis (Wasabi, Backblaze B2, Cloudflare R2, MinIO etc.),
configurado só por variáveis de ambiente.

## 4. Atualização segura

`./docker/scripts/atualizar.sh <versão> [prod|dev]`: confere a saúde, faz backup
`pre-update` verificado, baixa a versão, reconstrói, recria os containers, espera os
healthchecks e confere `/api/health`. Em caso de falha, mostra os comandos de retorno.

## 5. Validação

| Item | Como foi validado |
|---|---|
| Documentos em S3 (aplicação) | testes automáticos com bucket simulado: upload, leitura do currículo a partir do bucket, download com conferência do SHA-256, exclusão e `/api/health` |
| Backup/restauração no modo local, com os scripts novos | `test-infrastructure.sh` completo aprovado |
| `atualizar.sh` | executado em desenvolvimento (backup `pre-update`, recriação e conferência de saúde) |
| OpenAPI | gerado do código: 78 caminhos, 42 esquemas |
| Suíte completa | 180 testes PHPUnit aprovados |

**Não validado contra um bucket real**: a cópia externa dos backups e o backup/restauração com
documentos em bucket usam o `rclone`, que é instalado na construção da imagem de backup. O
ambiente onde o sistema foi desenvolvido não tem acesso ao repositório de pacotes nem a um
serviço S3, então esses fluxos não puderam ser executados aqui. Antes de depender deles em
produção, faça o teste descrito no manual de implantação (item 7, passo 5) e confira a cópia
externa com `list-backups --remote` e `fetch-backup latest`.

## 6. Documentação técnica existente

| Assunto | Documento |
|---|---|
| Arquitetura e decisões | [01-planejamento-arquitetura.md](01-planejamento-arquitetura.md) |
| Docker, produção, backup (detalhes técnicos) | [02-infraestrutura-docker.md](02-infraestrutura-docker.md) |
| Módulos (Fases 3 a 10) | `03-` a `10-` nesta pasta |
| Testes e homologação | [11-testes-homologacao.md](11-testes-homologacao.md) |
