# CEOF TALENT — Fase 6: Vagas e processos seletivos

## 1. O que foi entregue

- **Vagas** (menu Recrutamento): lista com atalhos por situação (em andamento, aguardando
  aprovação, rascunhos, encerradas), busca por título ou código, filtro por departamento e
  "só as que eu solicitei". Cada linha mostra candidatos em processo e posições preenchidas.
- **Cadastro da vaga**: título, departamento solicitante, unidade, cargo, área, tipo de
  contratação, motivo (com o colaborador substituído, quando for substituição), quantidade de
  posições, jornada, faixa salarial (e se ela é divulgada), escolaridade mínima, descrição,
  atividades, benefícios, data limite de inscrições e requisitos obrigatórios ou desejáveis.
- **Aprovação**: a vaga nasce como rascunho, é enviada para aprovação, e o gestor do
  departamento (ou o RH) aprova ou devolve com motivo.
- **Publicação, suspensão, encerramento, reabertura e cancelamento**, todos com histórico.
- **Quadro do processo seletivo (Kanban)**: colunas Inscrição, Triagem, Pré-seleção, Entrevista,
  Avaliação técnica, Aprovação, Contratação, Reprovado e Desistente. Os cartões são arrastados
  entre colunas ou movidos pelo botão ⋮ (que também aceita uma observação).
- **Histórico de movimentações** de cada candidato na vaga (painel lateral ao clicar no cartão)
  e nova aba **Processos** na ficha do candidato, com todas as vagas de que ele participou.

## 2. Ciclo da vaga

```
Rascunho ──enviar──▶ Aguardando aprovação ──aprovar──▶ Aprovada ──publicar──▶ Publicada ⇄ Suspensa
   ▲                        │                                                   │
   └──── Não aprovada ◀─────┘ (devolver, com motivo)                            └──encerrar──▶ Encerrada ──reabrir──▶ Publicada
Cancelar: de qualquer situação que não seja final.
```

| Ação | Quem pode | Motivo |
|---|---|---|
| Criar vaga | quem solicita vagas (`jobs.request`) ou gerencia vagas; o gestor de departamento só para o próprio departamento | — |
| Editar | rascunho ou não aprovada: quem solicitou ou o RH. Aprovada, publicada ou suspensa: só quem gerencia vagas (`jobs.manage`). Encerrada ou cancelada: ninguém | — |
| Enviar para aprovação | quem solicitou ou `jobs.manage` | — |
| Aprovar / devolver | `jobs.approve` e (RH com `jobs.manage` **ou** gestor do mesmo departamento que **não** seja o solicitante) | obrigatório ao devolver |
| Publicar | `jobs.publish` (bloqueado se a data limite já passou) | — |
| Suspender | `jobs.publish` | obrigatório |
| Encerrar | `jobs.publish` | obrigatório |
| Reabrir | `jobs.manage`, desde que ainda haja posições livres | obrigatório |
| Cancelar | `jobs.manage`; o solicitante, enquanto a vaga não foi aprovada | obrigatório |

O código da vaga é sequencial por ano: `VAGA-2026-0001`. A quantidade de posições não pode ficar
menor do que o número de contratados.

## 3. Processo seletivo

O quadro só aceita inscrições e movimentações quando a vaga está **aprovada, publicada ou
suspensa**. Encerrada ou cancelada, o quadro fica congelado para consulta.

| Movimentação | Permissão |
|---|---|
| Inscrever candidatos do banco de talentos | `recruitment.move` (apenas candidatos ativos; quem já está na vaga é ignorado) |
| Entre Inscrição, Triagem, Pré-seleção, Entrevista e Avaliação técnica | `recruitment.move` |
| Reprovar até a Avaliação técnica, ou marcar desistência | `recruitment.move` |
| Aprovar, contratar, reprovar quem já foi aprovado | `recruitment.decide` (RH ou gestor do departamento da vaga) |
| Reabrir uma inscrição reprovada, desistente ou contratada | `recruitment.decide` |

Regras:

- **Reprovação exige motivo**: perfil incompatível, pretensão salarial, disponibilidade,
  desempenho na entrevista, avaliação técnica, documentação ou outro motivo. A observação é opcional.
- **Contratação** só a partir da etapa Aprovação e só enquanto houver posição livre.
- Quando o número de contratados chega à quantidade de posições, **a vaga é encerrada
  automaticamente** com o motivo "Todas as posições foram preenchidas."
- Cada movimentação grava quem fez, quando, de qual para qual etapa, motivo e observação. Esse
  histórico (`application_stage_history`) e o da vaga (`job_status_history`) **não podem ser
  alterados** (regra no próprio PostgreSQL). Também entram na auditoria e no histórico do candidato.
- O gestor de departamento passa a ver na ficha de candidatos apenas quem está inscrito em vagas
  do seu departamento (regra anunciada na Fase 4).

## 4. Permissões por perfil

| Perfil | Vagas | Processo seletivo |
|---|---|---|
| Administrador, Gestor de RH | todas as ações | movimenta e decide |
| Analista de RH | vê todas, cria e edita as suas | movimenta (sem aprovar/contratar) |
| Recrutador | vê todas | movimenta (sem aprovar/contratar) |
| Gestor de departamento | vê, cria e aprova as do seu departamento | aprova, reprova aprovados e contrata no seu departamento |
| Entrevistador | sem acesso | sem acesso |

## 5. API (`/api/v1`)

| Método e rota | Descrição |
|---|---|
| `GET /jobs` | lista paginada; filtros `search`, `status[]`, `department_id` (uuid), `unit_id` (uuid), `professional_area`, `mine`, `pending_my_approval`, `sort` (`code`, `title`, `created_at`, `closes_on`, `status`, com `-` para decrescente) |
| `GET /jobs/options` | situações, tipos, motivos, etapas, motivos de reprovação, departamentos, unidades e cargos |
| `POST /jobs` · `PUT /jobs/{uuid}` | cria (como rascunho) ou edita; `department_id`, `unit_id` e `position_id` são uuids; `requirements: [{description, is_mandatory}]` |
| `GET /jobs/{uuid}` | detalhe com `abilities.actions` (ações que o usuário pode executar agora) |
| `POST /jobs/{uuid}/transition` | `{"action": "submit|approve|reject|publish|suspend|close|reopen|cancel", "reason": "..."}` |
| `GET /jobs/{uuid}/history` | histórico da vaga |
| `GET /jobs/{uuid}/applications` | inscrições da vaga e contagem por etapa (`meta.counts`) |
| `POST /jobs/{uuid}/applications` | `{"candidates": [uuid, ...]}` (até 50); retorna `meta.added`, `meta.already`, `meta.inactive` |
| `GET /applications/{uuid}` | inscrição com histórico de movimentações |
| `POST /applications/{uuid}/move` | `{"stage": "...", "reason": "<motivo, se reprovação>", "note": "..."}`; retorna `meta.job_closed` |
| `GET /candidates/{uuid}/applications` | processos de que o candidato participa |

## 6. Testes

115 testes no total (20 novos nesta fase), cobrindo: criação com código sequencial e requisitos,
validações, restrição de departamento, ciclo completo da vaga (incluindo devolução, reenvio,
suspensão, encerramento e reabertura), solicitante não aprovando a própria vaga, visibilidade por
departamento, filtros, prazo vencido, inscrição de candidatos (duplicados e inativos), funil com
histórico, reprovação com motivo, desistência, contratação até preencher as posições com
encerramento automático, quadro congelado, posições abaixo do número de contratados, decisão do
gestor só no próprio departamento e imutabilidade dos históricos.

O fluxo de tela foi validado no navegador de ponta a ponta: criar a vaga pelo formulário, enviar,
aprovar, publicar, inscrever três candidatos, arrastar um cartão, reprovar outro com motivo,
levar o terceiro até a contratação (a vaga encerra sozinha) e conferir os históricos na vaga, no
painel do cartão e na ficha do candidato.
