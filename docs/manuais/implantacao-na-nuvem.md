# Implantação em servidor ou nuvem — CEOF TALENT

O sistema roda igual em qualquer servidor Linux com Docker: um VPS (Hostinger, Locaweb,
DigitalOcean, Contabo...), uma máquina virtual em nuvem (AWS EC2, Azure, Google Cloud, Oracle)
ou um servidor físico no hospital. Só o `.env` muda entre ambientes.

## 1. Servidor

| Item | Mínimo | Recomendado |
|---|---|---|
| Sistema | Ubuntu Server 24.04 LTS (64 bits) | idem |
| CPU / memória | 2 vCPU / 4 GB | 4 vCPU / 8 GB |
| Disco | 40 GB SSD | 80 GB SSD + cópia externa dos backups |
| Rede | IP fixo, portas 80 e 443 liberadas | idem |

Também é preciso um **domínio** (ex.: `talentos.ceof.com.br`) apontando (registro DNS do tipo A)
para o IP do servidor, e um **e-mail SMTP** para o envio de mensagens.

## 2. Preparar o servidor

Conectado por SSH como um usuário com `sudo`:

```bash
sudo apt update && sudo apt -y upgrade
sudo apt -y install ca-certificates curl git ufw
# Docker oficial
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" | sudo tee /etc/apt/sources.list.d/docker.list
sudo apt update && sudo apt -y install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker $USER     # saia e entre de novo no SSH
# Firewall: só SSH, HTTP e HTTPS
sudo ufw allow OpenSSH && sudo ufw allow 80/tcp && sudo ufw allow 443/tcp && sudo ufw --force enable
```

> O Docker publica portas por fora do `ufw`. O `docker-compose.prod.yml` só publica 80 e 443;
> banco e Redis ficam em rede interna, sem porta exposta (o roteiro de homologação confere).

## 3. Baixar o sistema

```bash
sudo mkdir -p /opt/ceof-talent && sudo chown $USER /opt/ceof-talent
git clone https://github.com/wandiel/ceof-talent.git /opt/ceof-talent
cd /opt/ceof-talent
git checkout v1.0.0          # use sempre uma versão (tag) fixa em produção
```

(Repositório privado: crie um token de leitura no GitHub ou uma "deploy key".)

## 4. Configurar o `.env`

```bash
cp .env.example .env
./docker/scripts/generate-secrets.sh
```

Copie para o `.env` os valores gerados (`APP_KEY`, `DB_PASSWORD`,
`POSTGRES_SUPERUSER_PASSWORD`, `REDIS_PASSWORD`) e guarde-os no cofre de senhas. Depois
preencha:

| Variável | Exemplo |
|---|---|
| `APP_URL` | `https://talentos.ceof.com.br` |
| `SERVER_NAME` | `talentos.ceof.com.br` |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | dados do SMTP |
| `PRIVACY_CONTROLLER`, `PRIVACY_DPO_NAME`, `PRIVACY_DPO_EMAIL` | dados da LGPD |
| `BACKUP_ENABLED`, `BACKUP_TIME`, `BACKUP_RETENTION_DAYS` | `true`, `02:00`, `30` |
| `BACKUP_S3_*` | cópia externa dos backups (item 8) |
| `IMAGE_TAG` | a versão, ex.: `1.0.0` |

`APP_ENV=production`, `APP_DEBUG=false` e cookies só por HTTPS já são impostos pelo
`docker-compose.prod.yml`, independentemente do `.env`.

## 5. Certificado HTTPS (Let's Encrypt)

1. Gere um certificado provisório para o Nginx conseguir subir:
   `./docker/scripts/generate-self-signed-cert.sh talentos.ceof.com.br`
2. Suba o sistema: `docker compose -f docker-compose.prod.yml up -d --build --wait`
3. Emita o certificado verdadeiro (troque domínio e e-mail):

```bash
docker run --rm -v ceof-talent-prod_certbot_webroot:/var/www/certbot -v /etc/letsencrypt:/etc/letsencrypt \
  certbot/certbot:v4.1.1 certonly --webroot -w /var/www/certbot \
  -d talentos.ceof.com.br --email ti@ceof.com.br --agree-tos --no-eff-email
sudo cp -L /etc/letsencrypt/live/talentos.ceof.com.br/fullchain.pem docker/nginx/certs/fullchain.pem
sudo cp -L /etc/letsencrypt/live/talentos.ceof.com.br/privkey.pem docker/nginx/certs/privkey.pem
docker compose -f docker-compose.prod.yml exec nginx nginx -s reload
```

4. Renovação automática (o certificado vale 90 dias): `sudo crontab -e` e adicione

```
30 3 * * 1 cd /opt/ceof-talent && docker run --rm -v ceof-talent-prod_certbot_webroot:/var/www/certbot -v /etc/letsencrypt:/etc/letsencrypt certbot/certbot:v4.1.1 renew --webroot -w /var/www/certbot -q && cp -L /etc/letsencrypt/live/talentos.ceof.com.br/*.pem docker/nginx/certs/ && docker compose -f docker-compose.prod.yml exec -T nginx nginx -s reload
```

(Se a tag `v4.1.1` não existir mais, use a versão estável atual da imagem `certbot/certbot`.)

## 6. Primeiro acesso

```bash
docker compose -f docker-compose.prod.yml exec app php artisan ceof:create-admin --name="Nome" --email=ti@ceof.com.br
./docker/scripts/homologacao.sh prod
```

Abra `https://talentos.ceof.com.br`, entre com o administrador e siga o
[manual do administrador](manual-do-administrador.md).

## 7. Documentos em bucket S3 (opcional)

Por padrão, currículos e documentos ficam no disco do servidor (incluídos no backup). Em nuvem
pode ser melhor guardá-los em um bucket S3 ou compatível (espaço ilimitado, redundância do
provedor). No `.env`:

```
FILESYSTEM_DISK=s3
AWS_BUCKET=ceof-talent-documentos     # bucket PRIVADO, só para isso
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=sa-east-1           # São Paulo, se for AWS
AWS_ENDPOINT=                          # vazio na AWS; outros provedores: o endpoint
AWS_USE_PATH_STYLE_ENDPOINT=false      # true para MinIO e alguns provedores
```

O sistema grava, lê (inclusive a leitura automática de currículos), baixa e apaga os arquivos
no bucket. O backup diário continua completo: ele copia os documentos do bucket para dentro do
backup, e a restauração os devolve ao bucket.

**Migrar documentos que já existem no servidor para o bucket:**

1. `docker compose -f docker-compose.prod.yml exec backup backup --label antes-s3`
2. Preencha as variáveis acima no `.env` e rode `docker compose -f docker-compose.prod.yml up -d`.
3. `docker compose -f docker-compose.prod.yml stop app queue scheduler nginx`
4. `docker compose -f docker-compose.prod.yml run --rm restore antes-s3 --yes --only-files`
   (use o nome completo da pasta do backup, mostrado no passo 1)
5. `docker compose -f docker-compose.prod.yml up -d` e confira `/api/health` e o download de
   um currículo.

> O suporte a S3 foi testado com bucket simulado nos testes automáticos. Ao ativar em
> produção, faça o teste do passo 5 e um backup manual seguido de `verify-backup latest`
> antes de liberar o uso.

## 8. Cópia externa dos backups

Configure `BACKUP_S3_*` (ver [backup-e-restauracao.md](backup-e-restauracao.md), item 4). Use
um bucket e uma chave **diferentes** dos documentos, de preferência em outro provedor ou outra
região.

## 9. Checklist de produção

- [ ] Domínio com HTTPS válido; `http://` redireciona para `https://`
- [ ] Senhas geradas pelo `generate-secrets.sh` e guardadas no cofre
- [ ] SMTP testado (recuperação de senha chega por e-mail)
- [ ] Backup diário ativo e cópia externa configurada e conferida (`list-backups --remote`)
- [ ] Restauração testada em homologação
- [ ] Monitor externo em `/api/health`
- [ ] Aviso de privacidade revisado e dados do encarregado preenchidos
- [ ] `./docker/scripts/homologacao.sh prod` aprovado
