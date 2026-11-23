# Changelog

## 0.3.0 — 2026-11-23

* **Escalonamento**: integração opcional com o plugin Escalations (10 situações críticas; ver README). Configuração → Escalonamento e botão Escalonar agora.
* **Requisições**: botão **Nuvem de Palavras** (palavras dos chamados abertos sem iniciativa, em nuvem colorida, com filtro de novos e de alertas) e aba **Estatísticas** (termos, temas juntos, semanas, espera, prioridade, solicitantes). Na ficha, o chamado de requisição é escolhido numa lista dentro do campo, com os mais parecidos primeiro.
* **Ficha reorganizada por etapas**: Registro de Brainstorm (com chamado de requisição) → Planejamento Estratégico (cartão "Definição de Critérios Estratégicos", sem Ciclo OKR, Linha de base e Indicador impactado) → Iniciação e Alinhamento (TAP completo, registro das partes interessadas, departamentos e equipes) → Tarefas (cronograma, lista, RACI e dependências) → Monitoramento e Controle (status report, riscos por tarefa/entrega, requisição de mudança de implantação, checkpoints) → Custos → Documentação → Encerramento e Transição (termo de aceite, lições aprendidas, termo de encerramento). "Mudar situação" fica recolhido no alto de todas as etapas.
* **TAP no modelo de mercado**: composto ao formalizar a oportunidade; a data e hora do envio aparecem antes de enviar; ao enviar, uma fotografia do termo vai para o Comitê (tabela `records`, colunas novas em `charters`).
* Anexos em todas as etapas (vão para a Documentação, com a coluna Etapa) e nova tela **Fichas por etapa** (`front/stages.php`) para o Comitê, com cronograma, RACI, riscos, requisições, status reports e termos; o painel do Comitê lista os TAPs enviados.
* **TAP (Termo de Abertura do Projeto)**: nasce em rascunho com a iniciativa (quem demandou, quem acompanha, objetivo, escopo, entregas, início e prazo de entrega). É emitido sozinho quando a iniciativa entra em execução, ou à mão na nova aba **TAP**; avisa requisitante e acompanhante, registra acompanhamento no chamado de origem e gera o **Comunicado de início** imprimível (`front/charter.php`). Tabela nova `charters`.
* Nova aba **Cronograma e RACI**: barras do cronograma e matriz R/A/C/I por tarefa e pessoa, com conferência (um A e pelo menos um R) e sugestão automática. Tabela nova `raci`.

## 2026-11-15

* Nova aba **Documentação** na ficha da iniciativa: anexa vários arquivos (PDF, Word, Excel, PowerPoint, texto, imagem; até 20 MB) a qualquer momento, com baixar e remover (mesmo armazenamento das atas, tipo `doc`).
* Fases e campos da planilha OAK: catálogos de perspectivas, objetivos e donos executivos, notas de priorização com pesos, filtro de renúncia, limite de iniciativas em execução por dono.
* Requisições de Iniciativa a partir de chamados (gaveta de resumo, "NOVO" piscando) e ata do brainstorm em anexo.
* Comitê: painel executivo, checkpoints quinzenais, ata de decisões, atas em anexo, farol calculado.
* KPIs de performance e book PDF "KPI's VMO".
* Dashboard: novas requisições, cartões de status de entrega e Roadmap de integração (todas as iniciativas, pelo novo campo Status de entrega: Em desenvolvimento, Desenvolvido, Em produção).
* Custos por valor/hora × horas; benefícios e riscos saíram da interface.

## 0.1.0 — 2026-11-08

Primeira versão: escopo P0 da especificação VMO SRS 1.0.

* Iniciativas: ciclo rascunho → análise → aprovada → execução → suspensa → entregue → mensuração → encerrada (rejeitada/cancelada), com justificativas, aprovação por outra pessoa e delegação com vigência.
* Baseline imutável com hash, solicitações de mudança e comparação entre revisões.
* Tarefas hierárquicas (5 níveis), marcos, progresso ponderado, dependências término→início com defasagem em dias úteis e exceções auditadas.
* Custos (previsão e real), estorno, CAPEX/OPEX, câmbio com data e fonte.
* Benefícios financeiros e não financeiros, alocação ≤ 100%, plano por mês, medição declarada × validada com revisões.
* Riscos, problemas e decisões; indicadores e metas; vínculos com chamados e projetos do GLPI.
* Dashboard executivo com detalhamento, relatórios (PDF, DOCX, CSV) a partir de snapshot reproduzível, avisos e pendências.
* Configuração (limites de saúde versionados, catálogos, departamentos, câmbio, delegações), diagnóstico e exportação sem segredos; aba de direitos em Perfis.
* Rotinas agendadas: avisos (atrasos, medições vencidas, aprovações) e retenção.
