# Manual do usuário — CEOF TALENT

Guia para a equipe de RH, gestores e entrevistadores. Cada pessoa vê apenas os menus e os
dados que o seu perfil permite (ver [perfis](#perfis-de-acesso)).

## 1. Entrar no sistema

1. Abra o endereço do sistema no navegador (Chrome, Edge ou Firefox atualizados).
2. Informe e-mail e senha e clique em **Entrar**.
3. No primeiro acesso, a senha é provisória: o sistema pede uma senha nova antes de liberar as
   telas. A senha precisa ter pelo menos 10 caracteres, com letras maiúsculas, minúsculas,
   números e símbolos.
4. Esqueceu a senha? Clique em **Esqueci minha senha**: o link de redefinição chega por e-mail.

Para sair, clique no seu nome no canto superior direito → **Sair**. A sessão também expira
sozinha após um período sem uso.

## 2. Tela inicial (dashboard)

Mostra os indicadores do período: candidatos no banco, novos cadastros, vagas abertas,
candidatos em processo, entrevistas agendadas, contratações e tempo médio de contratação, além
de gráficos por mês, funil do processo seletivo, vagas por departamento e próximas entrevistas.
Use os filtros (período, departamento, unidade, área) no topo. Clique em um indicador para
abrir a lista correspondente.

## 3. Banco de talentos (candidatos)

### Cadastrar
**Banco de talentos → + Novo candidato.** Preencha os dados pessoais, o contato (e-mail ou
telefone), área, escolaridade, formação, experiências, cursos e competências, e clique em
**Cadastrar candidato**. Em seguida, na aba **Documentos**, anexe o currículo (PDF, Word, ODT,
JPG ou PNG, até 10 MB).

O sistema avisa quando já existe alguém com o mesmo e-mail, CPF ou nome e telefone parecidos
(menu **Duplicidades**).

### Leitura automática do currículo
Depois do envio, o sistema lê o currículo e sugere competências, área e cursos na aba
**Perfil**. As sugestões **não** mudam o cadastro sozinhas: clique em **Aceitar** ou
**Descartar** em cada uma. O texto do currículo passa a ser encontrado na busca.

### Buscar
- **Busca rápida**: nome, e-mail, telefone ou cargo pretendido.
- **Busca avançada**: palavras-chave do currículo (com ou sem acento), área, escolaridade,
  cidade, anos de experiência, empresa ou cargo anterior, competências, etiquetas,
  disponibilidade, pretensão salarial máxima e "tem currículo".
- **★ Meus favoritos**: candidatos que você marcou com a estrela.
- **Etiquetas**: marque candidatos com etiquetas da equipe (ex.: "Banco 2026", "Plantão").

### Ficha do candidato
Abas: **Perfil**, **Processos** (vagas de que participa), **Documentos**, **Anotações**
(observações internas da equipe), **Privacidade (LGPD)** (só Gestor de RH e Administrador) e
**Histórico** (tudo o que foi feito no cadastro, por quem e quando).

## 4. Vagas e processo seletivo

### Ciclo da vaga
**Vagas → Nova vaga** → preencha e clique em **Criar rascunho**. Depois:

| Situação | Próximo passo | Quem faz |
|---|---|---|
| Rascunho | Enviar para aprovação | quem criou |
| Aguardando aprovação | Aprovar ou Devolver sem aprovar (com motivo) | Gestor de RH / Administrador / gestor do departamento |
| Aprovada | Publicar | RH |
| Publicada | Suspender, Encerrar | RH |

Vagas publicadas e marcadas como públicas aparecem no portal **Trabalhe conosco**.

### Quadro do processo (Kanban)
Na vaga, aba **Processo seletivo**:
- **+ Adicionar candidatos** para inscrever pessoas do banco de talentos.
- Arraste os cartões entre as colunas **Inscrição → Triagem → Pré-seleção → Entrevista →
  Avaliação técnica → Aprovação → Contratação**, ou use o botão ⋮ do cartão.
- **Reprovado** e **Desistente** pedem o motivo.
- Ao contratar todas as posições, a vaga é encerrada automaticamente.
- Clique no cartão para ver o histórico da inscrição e a **aderência** do candidato à vaga.

## 5. Entrevistas

- **Agendar**: no cartão do candidato (quadro da vaga) → **+ Agendar**. Informe tipo, data e
  hora, duração, formato (presencial, videochamada ou telefone), local ou link e
  entrevistadores. O sistema avisa conflitos de agenda.
- Os entrevistadores e o candidato recebem e-mail; há lembrete automático antes da entrevista.
- **Entrevistas** (menu) mostra a sua agenda.
- Depois da entrevista, abra-a e clique em **Registrar parecer**: nota por critério,
  recomendação e comentários. Também é possível **Cancelar** ou **Registrar ausência do
  candidato**.

## 6. Relatórios

**Relatórios** → escolha o relatório à esquerda, ajuste os filtros e clique em **Exportar PDF**,
**Exportar Excel** ou **Exportar CSV**. Há 14 relatórios: candidatos (geral, por área,
escolaridade, cidade, novos, origem), vagas abertas e encerradas, processos, candidatos por
etapa, entrevistas, aprovações, contratações e tempo de contratação. Toda exportação fica
registrada na auditoria.

## 7. Portal "Trabalhe conosco"

Endereço público: `/trabalhe-conosco` (há um link na tela de login). O candidato vê as vagas
abertas, envia o currículo para uma vaga ou para o banco de talentos e recebe um protocolo por
e-mail. No sistema, ele aparece com origem **Portal** e já inscrito na vaga. Se a pessoa já
tinha cadastro, os dados existentes não são alterados: o histórico mostra o que veio diferente
para o RH conferir.

## 8. LGPD (Gestor de RH e Administrador)

Menu **LGPD e privacidade**: pedidos dos titulares (acesso, correção, eliminação,
portabilidade, revogação), prazos, consentimentos e política de retenção. O passo a passo está
em [docs/10-lgpd-auditoria.md](../10-lgpd-auditoria.md). Resumo:

1. **+ Registrar solicitação**, vinculando o candidato. O prazo de 15 dias é calculado.
2. Na ficha do candidato, aba **Privacidade (LGPD)**: **Exportar dados do titular (JSON)** para
   pedidos de acesso/portabilidade; **Anonimizar cadastro** para eliminação.
3. Volte à solicitação, mude a situação para **Concluída** e registre a resposta dada.

## Perfis de acesso

| Perfil | O que pode fazer |
|---|---|
| Administrador | tudo, inclusive usuários, perfis e auditoria |
| Gestor de RH | recrutamento completo, vagas, relatórios, LGPD e auditoria |
| Analista de RH | candidatos, vagas e processos seletivos |
| Recrutador | triagem, movimentação de candidatos e entrevistas |
| Gestor de departamento | solicita e aprova vagas e decide sobre candidatos do próprio departamento |
| Entrevistador | só as entrevistas atribuídas a ele, com registro de parecer |

## Dúvidas frequentes

- **"Você não tem permissão para esta ação."**: o seu perfil não inclui essa função; peça ao
  Administrador.
- **"Muitas tentativas em pouco tempo."**: aguarde um minuto e tente de novo.
- **O currículo não gerou sugestões**: arquivos escaneados como imagem são lidos por OCR e
  podem ter qualidade menor; confira o status na aba Documentos.
