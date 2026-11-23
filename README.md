# VMO — Value Management Office para GLPI 11 e 12

Plugin que gere iniciativas **da proposta à comprovação de valor**: portfólio, tarefas e dependências, baseline imutável
com controle de mudanças, custos, benefícios (previsto × declarado × validado), riscos, indicadores, dashboard executivo e
relatórios reproduzíveis. Baseado na especificação VMO SRS 1.0 (proposta de requisitos).

Versão **0.3.0** — entrega P0 da especificação (veja "Cobertura" abaixo) mais as etapas da ficha, TAP com assinatura digital e integração com o plugin Escalations. Compatível com **GLPI 11 e 12**. Licença GPLv3+.

## Instalação

Repositório público: https://github.com/evandroevangelista77/VMO-OAK — a pasta do plugin no GLPI precisa se chamar `vmo`.

**No servidor do GLPI** (instala ou atualiza, sem depender de nenhum computador pessoal):

```bash
curl -fsSL https://raw.githubusercontent.com/evandroevangelista77/VMO-OAK/main/tools/instalar-no-servidor.sh | sudo bash
sudo GLPI=/caminho/do/glpi bash tools/instalar-no-servidor.sh     # GLPI fora de /var/www/glpi
```

O script baixa a versão mais nova, copia para `<glpi>/plugins/vmo`, ajusta o dono, roda `plugin:install` + `plugin:activate` e limpa o cache.
Manual: copie a pasta para `<glpi>/plugins/vmo`, `chown -R www-data`, e rode `php bin/console plugin:install vmo` e `plugin:activate vmo`.
**Desinstalar não apaga dados**; para expurgar as tabelas: `VMO_PURGE=1 php bin/console plugin:uninstall vmo`.

## Portal nativo do GLPI

A instalação cria **um** cartão de exemplo, "Nova iniciativa (VMO)", no Início do portal (entidade raiz); ele abre o formulário
`/plugins/vmo/front/initiative.php?id=0`. Texto, ilustração e ordem se editam em Configuração → Entidades → (entidade) → Início do
autoatendimento; a desinstalação remove o cartão. Quem clica precisa do direito VMO de criação (aba VMO em Perfis).

## Brainstorm: onde nasce a oportunidade

Pela tela, "Nova iniciativa" abre a iniciativa na etapa **Brainstorm** (ideia, discussão e a oportunidade que nasceu dela), sem valores
financeiros. Para seguir, a oportunidade precisa estar descrita e a iniciativa é *formalizada* (Mudar situação → Formalizar oportunidade),
virando rascunho; daí em diante o fluxo é o de sempre (análise, aprovação, baseline, custos e benefícios). Os cartões financeiros
(custo real, realização de valor, ROI) só aparecem depois do brainstorm. Criações por API e cópia continuam nascendo como rascunho.

## A ficha da iniciativa, por etapas

Registro de Brainstorm (com o chamado de requisição) → Planejamento Estratégico (critérios e priorização) → **Iniciação e Alinhamento**
(TAP, partes interessadas, departamentos e equipes) → Tarefas (cronograma, RACI e dependências) → **Monitoramento e Controle**
(status report, riscos por tarefa e entrega, requisição de mudança de implantação, checkpoints) → Custos → Documentação →
**Encerramento e Transição** (termo de aceite, lições aprendidas, termo de encerramento).

* **TAP** no modelo de mercado: rascunho composto com os critérios estratégicos, pré-visualização em A4, envio com data e hora visíveis antes de enviar, revisões, assinatura eletrônica registrada no GLPI (nome, papel, data, hora, IP e código sobre o conteúdo) e PDF guardado na Documentação.
* **Status report**, **termo de aceite** e **termo de encerramento** têm PDF, pré-visualização e envio à área interessada (o PDF vai para a Documentação).
* Todas as etapas aceitam anexos, que seguem para a Documentação e aparecem para o Comitê na **Visão do Comitê** (somente leitura, com cronograma e RACI).
* **Requisições**: lista dos chamados abertos sem iniciativa, campo com a lista de chamados na ficha e **Nuvem de Palavras** com estatísticas.

## Escalonamento (plugin Escalations)

Se o plugin **Escalations** estiver ativo, a rotina `vmonotify` (6 em 6 horas) envia as situações críticas como itens de plugin `vmo` e
resolve quando elas acabam. Hierarquia e níveis são definidos em *Escalations → Hierarquias → Regras*. Sem o Escalations o VMO funciona igual.

| Tipo | Situação | Gravidade |
| --- | --- | --- |
| `iniciativa_sem_atualizacao` | Em execução há mais de 15 dias sem checkpoint (mais de 30 dias = 4) | 3 / 4 |
| `iniciativa_prazo_critico` | Término vencido ou saúde crítica (atraso, custo ou risco acima do limite) | 4 |
| `marco_atrasado` | Marco com prazo vencido (acima de 15 dias = 4) | 3 / 4 |
| `bloqueio_aberto` | Checkpoint com bloqueio ou pedindo decisão do comitê (decisão = 4) | 3 / 4 |
| `risco_critico` | Risco aberto com exposição no limite crítico | 4 |
| `tap_nao_enviado` | Em execução sem TAP enviado após N dias | 2 |
| `assinatura_pendente` | TAP enviado sem a assinatura de algum signatário após N dias | 2 |
| `proposta_parada` | Em Qualificação esperando decisão há mais de N dias | 3 |
| `requisicao_parada` | Chamado novo de requisição sem triagem há mais de N dias | 2 |
| `dono_acima_limite` | Dono com mais iniciativas em execução do que o limite | 3 |

Os prazos (N) e o liga/desliga ficam em *VMO → Configuração → Escalonamento*, onde também há o botão **Escalonar agora**.

## Direitos (Administração → Perfis → aba VMO)

| Bit | Para quê |
| --- | --- |
| Ler / Criar / Atualizar / Excluir | uso normal; só vê iniciativas das entidades ativas e (se confidenciais) em que participa |
| **Aprovar** | aprovar iniciativa, baseline, mudança e benefício |
| **Validar** | validar medições de benefício |
| **Financeiro** | ver e lançar custos e valores financeiros |
| **Configurar** | catálogos, limites de saúde, câmbio, delegações, diagnóstico |

A instalação dá todos os direitos a perfis que podem alterar a configuração do GLPI e só leitura/criação/edição aos demais.
Ninguém ganha aprovação, validação, finanças ou configuração implicitamente.

## Regras que o plugin garante (testadas)

* **Segregação (RN07):** quem submeteu não aprova; quem solicitou a mudança não a aprova; quem declarou a medição não a valida; quem cadastrou o benefício não o aprova. Delegação só vale dentro da vigência.
* **Baseline imutável (RN04):** aprovação congela a revisão (hash SHA-256); prazo/escopo/custo só mudam por solicitação aprovada, que gera nova revisão com comparação. Ajustar a *previsão* não mexe na baseline.
* **Valor ≠ entrega (RN05):** previsto, declarado e validado são valores distintos; só medição validada por outra pessoa entra no realizado. Retificação cria revisão e exige nova validação; revisões nunca se somam. Benefício compartilhado soma uma vez (alocações ≤ 100%).
* **Acesso antes de somar (RN09):** listas, dashboard e relatórios partem das iniciativas visíveis; sem o direito financeiro os valores somem; acesso direto a registro restrito devolve 404.
* **Dado ausente nunca vira zero:** "sem dado", "não aplicável", "desatualizado". Moedas diferentes só se somam com taxa registrada (data e fonte); sem taxa, totais separados.
* **Concorrência:** controle otimista de versão (conflito explícito, sem sobrescrita silenciosa); dependências e transições são revalidadas na gravação; dependência com ciclo é rejeitada de forma atômica.
* **Auditoria:** trilha só de inserção, com ator, ação, antes/depois, versão e correlação por requisição; retenção configurável.

## Testes

```bash
php tests/calc.php                                                             # cálculos da seção 5 (sem GLPI)
sudo -u www-data env GLPI_ROOT=/var/www/glpi php tests/smoke.php               # regras de negócio (cenários CA01–CA13)
sudo -u www-data env GLPI_ROOT=/var/www/glpi php tests/pages.php               # renderiza todas as telas (cria e remove uma iniciativa de teste)
```

Rodam no GLPI 11 e no 12 com o mesmo código (as classes de domínio não herdam do núcleo; só a base de direitos tem uma declaração por versão).

## Cobertura da especificação

**P0 implementado:** RF01, RF02, RF03 (sem visões salvas), RF05–RF15, RF17, RF19 (avisos dentro do GLPI), RF20, RF22, RF24; RN01–RN10; RNF de segurança, auditoria, concorrência e privacidade.

**Ainda não implementado (evolução):**

* **P1:** RF04 priorização com pesos, RF16 dashboard operacional (carga/capacidade), RF18 agendamento de relatórios, RF21 importação CSV, RF23 API REST e eventos.
* RF03: visões pessoais/compartilhadas salvas. RF19: envio por e-mail (hoje há fila de avisos + tela "Pendências e avisos"; o lab não tem SMTP).
* Medições de desempenho (RNF03) e recuperação (RNF05) precisam ser executadas num ambiente de referência; acessibilidade WCAG 2.2 AA e testes de segurança dedicados ainda não foram feitos.
* Transferência de entidade e expurgo administrativo de dados de negócio (hoje só a trilha e os relatórios expiram por retenção).
* Aprovação formal de requisitos pendentes da seção 14 da especificação (alçadas, retenção, câmbio) continua com a organização.
