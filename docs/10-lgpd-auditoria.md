# CEOF TALENT — Fase 10: LGPD e auditoria

## 1. O que foi entregue

| Onde | O que faz |
|---|---|
| Menu **Administração → LGPD e privacidade** (`/lgpd`) | visão geral (solicitações, consentimentos, anonimizações, política de retenção, cadastros que vencem em 30 dias, registro das atividades de tratamento e encarregado) e a lista de **solicitações dos titulares** |
| Cadastro do candidato → aba **Privacidade (LGPD)** | consentimentos (registrar e revogar), envios feitos pelo portal, data prevista de anonimização, solicitações ligadas ao titular, **exportar dados** e **anonimizar** |
| Menu **Administração → Auditoria** (`/auditoria`) | trilha de auditoria com filtros por área, usuário, período, texto e IP; cada linha abre os valores "antes" e "depois" |
| Rotina diária às 03:15 | aplica a política de retenção (anonimiza cadastros vencidos e apaga auditoria antiga) |

Quem acessa: **Administrador** e **Gestor de RH** (permissões `privacy.manage` e `audit.view`).
Os outros perfis não veem os menus nem a aba, e a API responde 403.

## 2. Direitos do titular (art. 18)

1. O pedido chega (e-mail, telefone, presencial, carta). O RH clica em **+ Registrar
   solicitação**, escolhe o tipo, vincula o candidato (busca por nome, e-mail ou CPF) e descreve
   o pedido.
2. O sistema gera o protocolo `LGPD-AAMMDD-XXXXX` e o **prazo de 15 dias**. Pedidos vencidos
   aparecem em vermelho e no filtro "Prazo vencido".
3. A tela do pedido mostra **como atender** cada tipo:

| Tipo | Como atender no sistema |
|---|---|
| Acesso / Portabilidade | aba Privacidade → **Exportar dados do titular (JSON)**: cadastro, formação, experiências, cursos, competências, documentos (nomes), processos seletivos com etapas, entrevistas, consentimentos, envios pelo portal e solicitações. Anotações internas e pareceres da equipe não entram |
| Correção | botão **Editar** do candidato |
| Revogação de consentimento | aba Privacidade → **Revogar**. O cadastro passa a seguir o prazo menor de retenção |
| Eliminação | aba Privacidade → **Anonimizar cadastro** |

4. Para **concluir** ou **indeferir**, a resposta dada ao titular é obrigatória. Todo o
   andamento fica no histórico do pedido e na auditoria.

## 3. Anonimização

Exige motivo e a digitação de `ANONIMIZAR`. **Não pode ser desfeita.** Não é permitida
enquanto o candidato estiver em processo seletivo em andamento (encerre ou reprove antes).

| Apagado | Mantido (estatística, sem identificar a pessoa) |
|---|---|
| nome, nome social, e-mail, telefones, CPF, nascimento, endereço, LinkedIn, resumo, pretensão | cidade/UF, área, escolaridade, origem |
| documentos e arquivos (removidos do disco), formação, experiências, cursos, competências, etiquetas, favoritos, anotações, sugestões | processos seletivos (vaga, etapas, datas) para os indicadores |
| textos de pareceres, comentários de notas, observações de movimentação, instruções de entrevista | notas numéricas |
| IP dos consentimentos e dados enviados pelo portal; valores antigos/novos da auditoria do candidato | o registro de que houve anonimização, quando e por quem |

O cadastro passa a se chamar "Titular anonimizado #N", fica inativo, some da busca do banco de
talentos e não pode mais ser editado.

## 4. Política de retenção

| Situação | Prazo após a última atividade | Variável |
|---|---|---|
| Banco de talentos com consentimento, ou cadastro feito pelo RH (legítimo interesse) | 24 meses | `PRIVACY_TALENT_POOL_MONTHS` |
| Veio pelo portal e não autorizou o banco de talentos, ou revogou | 6 meses | `PRIVACY_NO_CONSENT_MONTHS` |
| Cadastro excluído pelo RH | anonimizado após 30 dias | `PRIVACY_DELETED_GRACE_DAYS` |
| Contratado | mantido (vira dado de pessoal) | `PRIVACY_KEEP_HIRED=true` |
| Trilha de auditoria | 60 meses | `PRIVACY_AUDIT_MONTHS` |

"Última atividade" é a mais recente entre: alteração do cadastro, movimentação em processo
seletivo, envio pelo portal e consentimento. Candidatos em processo seletivo nunca entram na
rotina. A rotina pode ser desligada com `PRIVACY_RETENTION_ENABLED=false`.

Para conferir o que seria feito sem alterar nada:

```
docker compose exec app php artisan privacy:apply-retention --dry-run
```

## 5. Consentimentos

Cada registro guarda finalidade, versão do aviso de privacidade, canal (portal ou RH), data,
quem registrou, IP e navegador (quando veio do portal) e, se revogado, data e motivo. O RH pode
registrar um consentimento obtido fora do sistema (por exemplo, termo assinado) informando
como foi obtido.

## 6. Auditoria

São registrados, entre outros: login, logout e falhas de login; criação e alteração de
usuários e perfis; visualização, criação, alteração, exclusão e download de documentos de
candidatos; movimentações nos processos; entrevistas e pareceres; exportação de relatórios;
solicitações LGPD, consentimentos, exportações e anonimizações. Cada registro guarda usuário,
data e hora, IP, navegador e, nas alterações, os valores antes e depois. Não há tela nem API
para editar ou apagar registros; só a rotina de retenção remove os mais antigos que o prazo.

## 7. API

| Método e rota | Descrição |
|---|---|
| `GET /api/v1/privacy/overview` | números, retenção e registro de atividades |
| `GET /api/v1/privacy/requests/options` | tipos, situações, canais e prazo |
| `GET/POST /api/v1/privacy/requests` | lista (filtros `search`, `status[]`, `type`, `overdue`, `candidate`) e criação |
| `GET/PUT /api/v1/privacy/requests/{uuid}` | detalhe com histórico e atualização (situação e resposta) |
| `GET /api/v1/candidates/{uuid}/privacy` | dados de privacidade do candidato |
| `POST /api/v1/candidates/{uuid}/consents` | registra consentimento (`purpose`, `evidence`) |
| `POST /api/v1/candidates/{uuid}/consents/{uuid}/revoke` | revoga (`reason`) |
| `GET /api/v1/candidates/{uuid}/privacy/export` | baixa o JSON do titular |
| `POST /api/v1/candidates/{uuid}/anonymize` | anonimiza (`reason`, `confirm=ANONIMIZAR`, `request` opcional) |
| `GET /api/v1/audit-logs` | auditoria (`group`, `event`, `user`, `from`, `to`, `search`, `ip`) |

Tabela nova: `privacy_requests`. Coluna nova: `candidates.anonymized_at`.

## 8. Testes

155 testes no total (7 novos nesta fase): ciclo da solicitação com prazo e resposta
obrigatória, bloqueio de acesso para perfis sem permissão, registro e revogação de
consentimento, exportação do titular, anonimização completa (dados, arquivos, auditoria) e
bloqueio com processo em andamento, rotina de retenção (prazo longo, curto, excluídos,
contratados, auditoria antiga, `--dry-run`) e filtros da auditoria.

No navegador: visão geral, registro de solicitação vinculada ao candidato, resposta
obrigatória ao concluir, aba Privacidade, consentimento registrado e revogado, exportação
JSON, anonimização com confirmação, bloqueio para candidato em processo e auditoria filtrada.
