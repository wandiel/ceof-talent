# CEOF TALENT — Fase 9: Portal público "Trabalhe conosco"

## 1. O que foi entregue

Endereços públicos, sem login (no mesmo endereço do sistema):

| Endereço | O que mostra |
|---|---|
| `/trabalhe-conosco` | vagas abertas, com busca por nome ou código e filtro por área |
| `/trabalhe-conosco/vagas/{id}` | detalhes da vaga (descrição, atividades, requisitos, benefícios, local, jornada e salário se a vaga divulgar) e o botão "Quero me candidatar" |
| `/trabalhe-conosco/cadastro` | formulário de candidatura (vindo de uma vaga) ou de **cadastro espontâneo** para o banco de talentos |
| `/trabalhe-conosco/confirmacao` | comprovante com **número de protocolo** |
| `/privacidade` | aviso de privacidade (LGPD) |

A tela de login ganhou o link "Veja as vagas abertas", e o portal tem o link "Acesso da equipe".

**Quais vagas aparecem:** só as **publicadas**, marcadas como **públicas** e com a data limite de
inscrição não vencida. Vagas suspensas, em rascunho ou encerradas não aparecem. Os dados
internos (solicitante, motivo, colaborador substituído, faixa salarial não divulgada) nunca
são enviados para o portal.

**O formulário pede:** nome, nome social (opcional), e-mail, telefone, CPF e nascimento
(opcionais), cidade, UF, área, escolaridade, cargo pretendido, LinkedIn e resumo (opcionais),
**currículo** (obrigatório: PDF, Word, ODT, JPG ou PNG até 10 MB) e até 3 documentos extras. A
pessoa precisa marcar que leu o aviso de privacidade e pode autorizar (ou não) a permanência do
currículo no banco de talentos.

## 2. O que acontece no envio

1. Se é uma pessoa nova, o cadastro é criado com origem **Portal**.
2. Se já existe cadastro com o mesmo e-mail ou CPF, **o cadastro existente não é alterado** (quem
   envia pode não ser o dono do e-mail). Os dados enviados ficam guardados no protocolo e o
   histórico do candidato diz quais campos vieram diferentes (por exemplo: "telefone, cidade"),
   para o RH conferir.
3. O currículo é anexado e **lido automaticamente** (Fase 7): o texto entra na busca e gera
   sugestões de competências para o RH revisar. Reenviar o mesmo arquivo não duplica.
4. Se veio de uma vaga, a pessoa entra no quadro da vaga na etapa **Inscrição**, com origem
   "portal". Se já estava inscrita, nada muda. Se o cadastro estava **inativo**, a inscrição não
   é feita sozinha: o histórico avisa o RH.
5. Ficam registrados a ciência do aviso de privacidade e, se marcada, a autorização do banco de
   talentos, com a **versão do aviso**, data, IP e navegador (base para a Fase 10).
6. A pessoa recebe o **comprovante por e-mail** com o protocolo (formato `TC-AAMMDD-XXXXXX`,
   não sequencial).

A resposta na tela é sempre a mesma, exista ou não cadastro anterior, para não revelar a
terceiros se um e-mail já está no banco.

**Proteções:** limite de 10 envios por minuto e 60 por dia por endereço IP, campo invisível
contra robôs, conferência do conteúdo real dos arquivos (Fase 4), CSRF e nenhuma informação
interna exposta.

## 3. Aviso de privacidade

O texto está em `/privacidade` e usa estes ajustes do `.env` (também listados no `.env.example`):

| Variável | Uso |
|---|---|
| `PRIVACY_NOTICE_VERSION` | versão do aviso registrada em cada aceite (aumente ao mudar o texto) |
| `PRIVACY_NOTICE_UPDATED_AT` | data mostrada no aviso |
| `PRIVACY_CONTROLLER` | nome do controlador dos dados |
| `PRIVACY_DPO_NAME`, `PRIVACY_DPO_EMAIL` | encarregado de dados (recomendado preencher) |
| `PRIVACY_TALENT_POOL_MONTHS` | meses de permanência no banco de talentos (padrão 24) |

> O texto do aviso foi escrito como modelo. Recomenda-se a revisão pelo jurídico ou pelo
> encarregado de dados do hospital antes de abrir o portal ao público.

## 4. API pública (`/api/v1/public`)

| Método e rota | Descrição |
|---|---|
| `GET /jobs` | vagas abertas (`search`, `area`) |
| `GET /jobs/{uuid}` | detalhe da vaga (404 se não estiver mais aberta) |
| `GET /options` | áreas, escolaridades, UFs, limites de arquivo e dados do aviso de privacidade |
| `GET /privacy` | dados do aviso de privacidade |
| `POST /applications` | envio (multipart): campos do formulário, `resume`, `documents[]`, `job` (uuid, opcional), `privacy_accepted`, `talent_pool_consent`. Responde `201` com `protocol`, `received_at`, `job` e `email` |

Tabelas novas: `portal_submissions` (cada envio, imutável) e `candidate_consents`
(consentimentos com versão, canal, data de concessão e de revogação).

## 5. Testes

148 testes no total (7 novos nesta fase): só vagas abertas e públicas aparecem e sem dados
internos; candidatura de pessoa nova (cadastro, currículo, inscrição, consentimentos, protocolo,
e-mail e histórico); cadastro espontâneo sem autorização de banco de talentos; pessoa existente
sem sobrescrever dados e com reenvio idempotente; cadastro inativo; validações (aviso de
privacidade, campo anti-robô, CPF, tipo de arquivo, vaga encerrada); limite de envios.

No navegador: link do login para o portal, busca, detalhe da vaga, validação no formulário,
erro do servidor (CPF), envio com comprovante, envio espontâneo, aviso de privacidade, e-mail
enfileirado, e o RH encontrando o candidato com origem Portal, a inscrição e o histórico com o
protocolo.
