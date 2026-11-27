# CEOF TALENT — Fase 7: Entrevistas, avaliações e leitura automática de currículos

## 1. O que foi entregue

- **Agenda de entrevistas** (menu Recrutamento → Entrevistas): entrevistas agrupadas por dia,
  com as abas *Próximas*, *Hoje*, *Pareceres pendentes* e *Anteriores*, busca pelo nome do
  candidato e o filtro "Só as que eu entrevisto".
- **Agendamento** direto do quadro da vaga: ao clicar no cartão do candidato, o painel lateral
  tem a seção *Entrevistas* com o botão **+ Agendar**. Informa-se tipo (RH, técnica, gestor,
  banca), formato (presencial, videochamada, telefone), data, horário, duração, local ou link,
  orientações para os entrevistadores, os entrevistadores e os critérios que serão avaliados.
  Se o candidato ainda estiver antes da etapa *Entrevista*, ele é movido para ela (opcional).
- **Conflito de horário**: o sistema avisa quando um entrevistador ou o próprio candidato já tem
  outra entrevista no mesmo horário. É possível corrigir o horário ou "Agendar mesmo assim".
- **Remarcar, cancelar (com motivo) e "candidato não compareceu"**, sempre com histórico.
- **E-mails automáticos** para os entrevistadores: agendamento, remarcação, cancelamento e
  **lembrete 24 horas antes** (enviado uma única vez, pelo agendador do sistema, de hora em hora).
- **Parecer por critérios**: cada entrevistador dá nota de 1 a 5 para cada critério, uma
  recomendação (Recomendo fortemente, Recomendo, Recomendo com ressalvas, Não recomendo), um
  resumo e observações. O sistema calcula a média de cada parecer e a média geral. O parecer pode
  ser corrigido depois, e toda correção fica no histórico.
- **Avaliação às cegas**: o entrevistador só vê os pareceres dos colegas depois de registrar o
  próprio, para não ser influenciado. O RH vê todos.
- **Critérios de avaliação** configuráveis (Entrevistas → Critérios de avaliação). Já vêm seis:
  Comunicação, Conhecimento técnico, Experiência relevante, Trabalho em equipe, Postura
  profissional e Foco no paciente. Cada entrevista guarda uma cópia dos critérios usados, então
  mudar a lista depois não altera entrevistas antigas.
- **Entrevistador externo ao RH**: quem só tem o perfil *Entrevistador* vê apenas as entrevistas
  em que participa e, delas, a ficha e o currículo do candidato. Não vê o restante do banco.
- **Leitura automática de currículos** (item 7.9):
  - Ao anexar um documento do tipo *Currículo* (PDF, Word .docx, OpenDocument .odt ou imagem), o
    sistema extrai o texto em segundo plano. PDF escaneado (sem texto) passa por **OCR** em
    português.
  - O texto extraído entra na **busca por palavras-chave** do banco de talentos.
  - O sistema **sugere** competências e a área profissional encontradas no texto, com o trecho
    do currículo que justifica cada sugestão. Nada é aplicado sozinho: alguém do RH clica em
    *Adicionar*, *Usar esta área* ou *Descartar*.
  - No painel do candidato dentro da vaga aparece a **Aderência à vaga**: quais requisitos,
    escolaridade e área o cadastro e o currículo atendem, com um percentual. É só apoio; a
    decisão é sempre da equipe.
  - A verificação de **duplicidades** da Fase 5 também passa pela mesma interface.

## 2. Ciclo da entrevista

```
Agendada ──registrar parecer──▶ Realizada (pareceres podem ser corrigidos)
   │  ├──remarcar──▶ Agendada (entrevistadores avisados)
   │  ├──cancelar (motivo)──▶ Cancelada
   └──────não compareceu (após o horário)──▶ Candidato não compareceu
```

| Ação | Quem pode |
|---|---|
| Ver a agenda | `interviews.view`. Sem `recruitment.view`, só as entrevistas em que é entrevistador |
| Agendar, remarcar, cancelar, marcar não comparecimento | `interviews.schedule` e acesso à vaga |
| Registrar parecer | ser entrevistador da entrevista (`interviews.evaluate`), depois do horário marcado |
| Ser escolhido como entrevistador | usuários ativos com `interviews.evaluate` |
| Gerenciar critérios | `jobs.manage` |
| Revisar sugestões da leitura | quem pode editar o candidato |

Regras: só se agenda para vaga que aceita inscrições e candidato com processo em aberto; a data
precisa ser futura; o formato presencial exige local; o link de videochamada precisa ser http(s);
os critérios só mudam enquanto não houver parecer.

## 3. Arquitetura da leitura automática (IA)

Tudo fica em `backend/app/Domain/Intelligence`, separado por contratos, para que um provedor de
IA externo possa ser plugado no futuro sem mudar telas nem regras:

| Contrato | Implementação local atual |
|---|---|
| `DocumentTextExtractor` | `LocalTextExtractor`: `pdftotext`; se vazio, `pdftoppm` + `tesseract` (por); DOCX/ODT lendo o XML; imagens por OCR. Limite de 200 mil caracteres |
| `CandidateProfileExtractor` | `KeywordProfileExtractor`: catálogo de competências + lista base da saúde; área por palavras-chave |
| `JobMatchScorer` | `KeywordJobMatchScorer`: requisitos por frase ou radicais, escolaridade e área; obrigatórios pesam o dobro |
| `DuplicateDetector` | `FinderDuplicateDetector` (regras da Fase 5) |

As ligações ficam em `AppServiceProvider::register()`. O processamento roda na fila
(`ReadCandidateDocument`), então o envio do arquivo não fica lento. Os dados não saem do
servidor: nenhuma informação de candidato é enviada para serviços externos.

As ferramentas `poppler-utils`, `tesseract-ocr` e `tesseract-ocr-por` foram adicionadas à imagem
PHP (`docker/php/Dockerfile`). Por isso, nesta atualização o `docker compose up -d --build` demora
um pouco mais na primeira vez.

## 4. API (`/api/v1`)

| Método e rota | Descrição |
|---|---|
| `GET /interviews` | agenda paginada; filtros `from`, `to` (datas), `status[]`, `mine`, `pending_evaluation`, `job` (uuid), `search`, `sort` |
| `GET /interviews/options` | tipos, formatos, situações, recomendações, critérios e entrevistadores elegíveis |
| `GET /applications/{uuid}/interviews` · `POST` | entrevistas da inscrição / agendar (`type`, `mode`, `scheduled_at` ISO 8601, `duration_minutes`, `location`, `meeting_url`, `instructions`, `interviewers[]`, `criteria[]`, `force`, `move_to_stage`). Conflito: 422 com `errors.conflicts` |
| `GET /interviews/{uuid}` · `PUT` | detalhe (com `abilities`) / remarcar |
| `POST /interviews/{uuid}/cancel` · `/no-show` | `{"reason": "..."}` |
| `PUT /interviews/{uuid}/evaluation` | `{"scores": [{"criterion": uuid, "score": 1-5, "comment"}], "recommendation", "summary", "observations"}` |
| `GET /interviews/{uuid}/history` | histórico da entrevista |
| `GET/POST/PUT/DELETE /evaluation-criteria` | critérios de avaliação |
| `GET /candidates/{uuid}/suggestions` | sugestões da leitura (`meta.reading` enquanto lê) |
| `POST /candidates/{uuid}/suggestions/{uuid}/accept` · `/dismiss` | aplicar ou descartar |
| `POST /candidates/{uuid}/documents/{uuid}/read` | ler o currículo de novo |
| `GET /applications/{uuid}/match` | aderência da inscrição à vaga |

Comando: `php artisan interviews:send-reminders` (já agendado de hora em hora no container
`scheduler`).

## 5. Testes

132 testes no total (17 novos nesta fase), cobrindo: agendamento com mudança de etapa e
e-mails, validações e entrevistadores inativos, vaga encerrada e processo finalizado, conflitos e
"agendar mesmo assim", horário enviado em UTC pelo navegador guardado no instante certo,
remarcação avisando quem entrou, saiu e permaneceu, cancelamento e não comparecimento,
lembrete enviado uma única vez, parecer por critérios com média e correção, avaliação às cegas,
entrevistador vendo só os próprios candidatos, critérios com cópia na entrevista; e na leitura:
PDF com texto, DOCX, sugestões sem duplicar o que já foi revisado, aplicação e descarte, busca
pelo texto do currículo e aderência à vaga.

O fluxo de tela foi validado no navegador de ponta a ponta: anexar um currículo em PDF, ver e
aceitar as sugestões, encontrar o candidato buscando uma palavra que só existe no currículo,
ver a aderência no quadro da vaga, agendar pelo painel (o cartão vai para *Entrevista*), receber
o aviso de conflito, conferir a agenda, criar um critério, entrar como entrevistadora externa,
registrar o parecer e confirmar que ela não acessa candidatos de outras entrevistas.
