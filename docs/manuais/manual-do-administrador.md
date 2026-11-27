# Manual do administrador — CEOF TALENT

Para quem administra o sistema (perfil **Administrador**) e para a TI que cuida do servidor.

## 1. Primeiro administrador

O primeiro usuário é criado por comando, no servidor:

```
docker compose exec app php artisan ceof:create-admin --name="Nome Completo" --email=ti@ceof.com.br
```

A senha é pedida sem aparecer na tela. (Em produção, acrescente `-f docker-compose.prod.yml`
logo depois de `docker compose`.)

## 2. Usuários e perfis

**Administração → Usuários → + Novo usuário.** Informe nome, e-mail, perfis e, para o
**Gestor de departamento**, o departamento. O sistema gera uma senha provisória, que a pessoa
troca no primeiro acesso.

- **Editar**: muda nome, e-mail, perfis e departamento (a alteração de perfis fica na auditoria).
- **Senha**: define uma nova senha provisória (ex.: quando a pessoa esqueceu e não tem e-mail).
- **Desativar**: bloqueia o acesso e encerra as sessões abertas na hora. O histórico é mantido.
- Proteções: ninguém remove o próprio perfil de administrador nem desativa a si mesmo, e o
  último administrador ativo não pode ser desativado.

**Perfis de acesso** mostra o que cada perfil pode fazer. Os perfis são fixos (definidos no
código) para evitar combinações perigosas.

## 3. Estrutura organizacional

**Unidades**, **Departamentos** e **Cargos** alimentam vagas, filtros e relatórios. Cadastre os
reais antes de criar vagas. Itens em uso não são apagados: desative-os.

## 4. Critérios de avaliação

**Entrevistas → botão Critérios de avaliação** (perfis com gestão de vagas): os critérios em
que os entrevistadores dão nota no parecer (ex.: comunicação, conhecimento técnico). Critérios
já usados não são apagados: desative-os.

## 5. Auditoria

**Administração → Auditoria**: quem fez o quê, quando, de qual IP e navegador, com valores
antes e depois. Filtre por área (acesso, usuários, candidatos, processos, vagas, entrevistas,
relatórios, LGPD), usuário, período, texto e IP. Os registros não podem ser editados nem
apagados pela interface; são guardados por `PRIVACY_AUDIT_MONTHS` (padrão 60 meses).

## 6. LGPD

Ver [docs/10-lgpd-auditoria.md](../10-lgpd-auditoria.md). Antes de abrir o portal ao público:

1. Preencha no `.env`: `PRIVACY_CONTROLLER`, `PRIVACY_DPO_NAME`, `PRIVACY_DPO_EMAIL`.
2. Revise o aviso de privacidade (`/privacidade`) com o jurídico. Ao mudar o texto, aumente
   `PRIVACY_NOTICE_VERSION`.
3. Ajuste os prazos de retenção se a política do hospital for diferente.

## 7. Configurações (arquivo `.env`)

O `.env` fica na pasta do sistema, no servidor. Depois de mudar, aplique com
`docker compose up -d` (produção: `docker compose -f docker-compose.prod.yml up -d`).
O `.env.example` explica cada item. Os mais usados:

| Variável | Para quê |
|---|---|
| `APP_URL`, `SERVER_NAME` | endereço do sistema |
| `MAIL_*` | servidor de e-mail (SMTP) para senhas, entrevistas e protocolos do portal |
| `APP_TIMEZONE` | fuso (padrão America/Manaus) |
| `PRIVACY_*` | aviso de privacidade, encarregado e prazos de retenção |
| `BACKUP_*` | backup diário, horário, retenção e cópia externa |
| `FILESYSTEM_DISK`, `AWS_*` | documentos no servidor (`local`) ou em bucket S3 (`s3`) |

> O `.env` contém senhas. Nunca o envie por e-mail ou chat; guarde uma cópia em um cofre de
> senhas. Ele não entra nos backups.

## 8. Monitoramento

- `https://SEU-ENDEREÇO/api/health`: responde `"status":"ok"` com o estado do banco, Redis,
  cache e armazenamento (HTTP 200) ou 503 se algo falhar. Use em um monitor externo
  (UptimeRobot, Zabbix etc.).
- `docker compose ps`: todos os serviços devem aparecer como `healthy`.
- Logs: `docker compose logs -f --tail=200 app` (troque `app` por `nginx`, `queue`,
  `scheduler`, `database` ou `backup`).
- Fila de e-mails: o serviço `queue` envia os e-mails; se eles não chegam, veja
  `docker compose logs queue`.

## 9. Rotinas automáticas

| Rotina | Quando | Onde ver |
|---|---|---|
| Backup completo (banco + documentos) | diário, `BACKUP_TIME` (02:00) | `docker compose logs backup`, pasta `backups/` |
| Lembretes de entrevista | a cada hora | `docker compose logs scheduler` |
| Política de retenção LGPD | diário, 03:15 | auditoria, área LGPD |

## 10. Homologação e atualização

- Conferência completa do sistema: [docs/11-testes-homologacao.md](../11-testes-homologacao.md)
  (`./docker/scripts/homologacao.sh`).
- Backup e restauração: [backup-e-restauracao.md](backup-e-restauracao.md).
- Atualização de versão: [atualizacao.md](atualizacao.md).
- Implantação em servidor/nuvem: [implantacao-na-nuvem.md](implantacao-na-nuvem.md).
