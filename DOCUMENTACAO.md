# Documentação do Projeto Cobrança Task

**Versão:** 1.5.6  
**Última atualização:** 25/09/2026

---

## Visão Geral

O **Cobrança Task** é um sistema web para gestão de cobranças e tarefas de condomínios. O sistema permite criar tarefas a partir de dados de clientes (integração com API externa "Condado"), distribuí-las entre usuários, acompanhar o progresso via Kanban e gerar relatórios gerenciais.

### Tecnologias Utilizadas

| Camada | Tecnologia |
|--------|------------|
| Frontend | HTML5, CSS3, JavaScript (vanilla) |
| Backend | PHP 8.2 |
| Banco de dados | MySQL / MariaDB 10.4 |
| Hospedagem | XAMPP (local) / cPanel (produção) |
| Controle de versão | GitHub |

### Como Rodar Localmente

1. Instale o [XAMPP](https://www.apachefriends.org/)
2. Clone o repositório para `C:\xampp\htdocs\Automacao_andrea-main`
3. Inicie o Apache e o MySQL pelo Painel XAMPP
4. Acesse `http://localhost/Automacao_andrea-main/login.html`
5. Login padrão: `admin@cobrancatask.com` / `password`
6. Copie as credenciais do banco para o arquivo `.env` (não versionado):
```
DB_HOST=localhost
DB_NAME=bvgarantia_cobrancatask
DB_USER=bvgarantia_cobranca
DB_PASS=sua_senha
CONDADO_DB_HOST=sistemasnovacorp.com.br
CONDADO_DB_PORT=5643
CONDADO_DB_NAME=novacorpconect
CONDADO_DB_USER=Intelligence
CONDADO_DB_PASS=sua_senha

# Integracao externa - outro site/banco (esqueleto)
#EXTERNAL_API_URL=https://outro-site.com/api/atividades
#EXTERNAL_API_TOKEN=cole_o_token_aqui
```

---

## Estrutura de Pastas

```
Automacao_andrea-main/
├── api/                    # Backend PHP (APIs)
│   ├── config.php          # Configurações do banco de dados
│   ├── auth_middleware.php  # Middleware de autenticação e autorização
│   ├── auth.php            # Autenticação (login/logout)
│   ├── tasks.php           # CRUD de tarefas
│   ├── users.php           # Gerenciamento de usuários
│   ├── reports.php         # Relatórios e estatísticas
│   ├── settings.php        # Configurações do sistema
│   ├── tickets.php         # Sistema de chamados
│   ├── condado.php         # Integração com API Condado
│   ├── sync_condado.php    # Sincronização de dados
│   ├── external_sync.php   # Esqueleto: replica atividades p/ API externa
│   ├── test_condado.php    # Teste de conexão
│   └── settings.json       # Permissões de usuários
├── css/                    # Estilos
│   └── style.css           # Estilo principal (tema claro/escuro)
├── js/                     # JavaScript frontend
│   ├── app.js              # Lógica principal da aplicação (SPA)
│   ├── auth.js             # Autenticação e sessão
│   └── theme.js            # Toggle de tema (claro/escuro)
├── img/                    # Imagens
│   ├── logo.svg            # Logo principal
│   ├── logo1.svg           # Logo variante
│   └── avatar_*.png        # Avatares de usuários
├── dashboard.html          # Dashboard principal (SPA)
├── login.html              # Página de login
├── index.html              # Página inicial (landing page)
├── database.sql            # Schema do banco de dados
├── DOCUMENTACAO.md         # Este arquivo
├── .env                    # Credenciais (NÃO versionado, ver .gitignore)
├── .htaccess               # Bloqueio de arquivos de teste/debug/.env
└── .gitignore              # Regras de ignore do Git
```

---

## Arquivos de Produção

### HTML

#### `index.html`
Página inicial (landing page) com apresentação do sistema. Possui link para login e botão de ação. Carrega o `css/style.css` e o favicon.

#### `login.html`
Página de autenticação. Formulário com campos de e-mail e senha (v1.5.3: sem `required` — validação fica no backend, que responde "Preencha todos os campos"). Botão `Entrar` (`#btnEntrar`). Redireciona para `dashboard.html` após login bem-sucedido. Carrega `js/auth.js` e `js/theme.js`.

#### `dashboard.html`
Dashboard principal e mais importante do sistema. É uma **SPA (Single Page Application)** que carrega todas as visualizações dinamicamente via JavaScript. Contém:

- **Sidebar** com navegação (Tarefas, Kanban, Relatórios, Configurações, Ajuda)
- **Área principal** onde as views são renderizadas
- **Modais** para criação de tarefas, distribuição, detalhes, etc.
- **Versão do sistema** exibida no rodapé da sidebar (v1.4.0)

---

### CSS

#### `css/style.css`
Estilo principal do sistema (1185 linhas). Funcionalidades:

- **Variáveis CSS** para temas claro/escuro
- **Cores da marca BV Garantia**: Verde escuro `#1B3A2A`, Dourado `#8B6E2F`, Preto `#1A1A1A`
- **Layout responsivo** com sidebar colapsável
- **Componentes**: cards, tabelas, botões, modais, toasts, badges
- **Animações**: transições suaves, slide-in de modais, fade de toasts
- **Scrollbar personalizada**
- **Google Fonts Inter** para tipografia

---

### JavaScript Frontend

#### `js/app.js`
Arquivo principal da aplicação (2563 linhas). Contém toda a lógica do frontend:

**Segurança frontend (v1.3.0):**
- Wrapper do `fetch` — injeta `X-CSRF-Token` (de `cobranca_csrf`) em todo POST
- `escapeHtml(str)` — neutraliza XSS antes de qualquer `innerHTML`

**Sessão e Usuário:**
- Leitura da sessão via `localStorage` (`cobranca_user`)
- Exibição do nome, perfil e avatar na sidebar
- Controle de visibilidade baseado no papel (admin/usuário)

**Navegação SPA:**
- `loadView(viewName)` — renderiza views dinamicamente
- Navegação via sidebar com hash URLs
- Guard de permissões — impede acesso a telas não autorizadas

**Permissões:**
- `window.appPermissions` — objeto global de permissões
- `applySidebarPermissions()` — esconde/mostra itens da sidebar
- `loadPermissions()` / `savePermissions()` — gerencia checkboxes de permissão

**Tarefas:**
- `loadTarefas(reset)` — carrega tarefas com paginação (50 por página)
- `loadMoreTarefas()` — botão "Carregar Mais"
- `buildTarefaRowHtml()` / `updateTarefasFooter()` — renderiza linhas da tabela
- `openTaskDetails()` — abre modal com detalhes da tarefa
- `deleteServerTask()` — move tarefa para lixeira

**Kanban:**
- `loadKanbanCards()` — carrega cards progressivamente
- `renderKanbanColumn()` / `loadMoreKanbanColumn()` (botao unico "Carregar mais") — injeta cards nas colunas
- `dragCard()` / `dropCard()` — drag and drop de cards (v1.5.6: lê resposta do servidor, toast sucesso/erro, rollback via `oldParent` se falhar)
- `promptAddColumn()` — adiciona colunas personalizadas (via `promptModal()`)

**Relatórios:**
- `loadRelatorios()` — carrega dados dos relatórios
- `renderRankingBar(name, total, percent, index, color)` — padrão único dos 3 rankings (posição + nome + `%` + `(total)` + barra de progresso, com `escapeHtml`)
- `renderRankingRow()` — wrapper legado, delega para `renderRankingBar`
- `getRankingName()` — retorna `atendente || imovel || devolutiva`
- `openRankingModal()` — abre modal com ranking completo no mesmo padrão barra + % (título escapado, tolera lista nula)

**Configurações:**
- `saveProfile()` — salva dados do perfil
- `handleSettingsAvatarUpload()` — upload de avatar
- `loadTickets()` — carrega chamados abertos
- `updateTicketStatus()` — altera status de chamado

**Chamados (Tickets):**
- `sendTicket()` — envia novo chamado
- `viewTicket()` — visualiza detalhes do chamado

**Outros:**
- `showToast(message, type)` — exibe notificações toast
- `filterTarefas()` / `filterKanban()` — busca nas listas
- Integração com API Condado para busca de clientes

---

#### `js/auth.js`
Gerencia autenticação e sessão (106 linhas):

- `handleLogin()` — envia formulário de login para `api/auth.php`, salva `cobranca_user` e `cobranca_csrf` no localStorage
- `checkSession()` — verifica se o usuário está logado
- `handleLogout()` — limpa `cobranca_user` e `cobranca_csrf`, redireciona para login
- **Timeout de 10 minutos** — logout automático por inatividade (limpa user + token CSRF)

---

#### `js/theme.js`
Toggle de tema claro/escuro (19 linhas):

- Lê tema salvo no `localStorage`
- Alterna atributo `data-theme` no `<html>`
- Salva preferência do usuário

---

### PHP API

#### `api/config.php`
Configurações e bootstrap do sistema (95 linhas):

- **Credenciais via `.env`** (nunca hardcoded): `DB_HOST/DB_NAME/DB_USER/DB_PASS` e `CONDADO_DB_*`, lidos com `getenv()` (`.env` está no `.gitignore`); v1.5.3: parser remove aspas simples/duplas dos valores e espelha em `$_ENV`
- `getConnection()` — conexão PDO com o banco local
- `getCondadoConnection()` — conexão PDO com o banco externo
- `jsonResponse()` — helper para respostas JSON
- Handler de erros com `set_exception_handler` — detalhe vai para `error_log`, cliente recebe mensagem genérica (`Erro interno do servidor` / `Falha na conexão...`), sem vazar `$e->getMessage()`

---

#### `api/auth_middleware.php`
Middleware de autenticação e autorização (64 linhas):

- `requireAuth()` — bloqueia acesso se não houver sessão (HTTP 401)
- `requireAdmin()` — bloqueia acesso se o usuário não for admin (HTTP 403)
- `requireCsrf()` — bloqueia POST sem `X-CSRF-Token` válido (HTTP 403, compara com `hash_equals`)
- `generateCsrfToken()` — gera token aleatório (`random_bytes`) por sessão; **v1.5.0:** login descarta o antigo e gera um novo
- `getCurrentUserId()` — retorna o ID do usuário logado (da sessão)
- `getCurrentUserRole()` — retorna o papel do usuário logado
- Cookie de sessão **v1.5.0:** `httponly=true`, `samesite=Lax`, `secure` automático em HTTPS (via `session_set_cookie_params` antes do `start`)
- `canAccessTask($pdo, $taskId)` — admin passa; demais só se forem `assigned_to` ou estiverem em `task_shares` (403 caso contrário)

> **Todos os endpoints protegidos devem incluir este arquivo no início.**

---

#### `api/auth.php`
API de autenticação (74 linhas):

| Ação | Método | Acesso | Descrição |
|------|--------|--------|-----------|
| `?action=login` | POST | Público | Valida e-mail e senha, rate-limit (5 erros/15min = bloqueio 5min, HTTP 429 + delay 1s), regenera ID da sessão + CSRF novo, retorna dados do usuário + `csrf_token` |
| `?action=logout` | POST (GET legado) | Logado | POST exige `X-CSRF-Token`; limpa `$_SESSION`, expira cookie `PHPSESSID` e destrói sessão |
| `?action=check` | GET | Logado | Retorna `{id, role}` se a sessão vale (inclui timeout 10min); 401 se expirada |
| `?action=change_password` | POST | Logado | Troca senha (exige `requireCsrf()`) |

---

#### `api/tasks.php`
API de tarefas (285 linhas):

| Ação | Método | Acesso | Descrição |
|------|--------|--------|-----------|
| `?action=list` | GET | Logado | Lista tarefas com paginação (`limit`, `offset` ou `page`, `search`; retorna `total`/`hasMore`). **v1.5.0:** não-admin só recebe `assigned_to=self` ou compartilhadas (`task_shares`) |
| `?action=get` | GET | Logado | Busca uma tarefa por ID |
| `?action=create` | POST | Logado | Cria tarefas em lote |
| `?action=update_status` | POST | Logado | Atualiza status (todo/in_progress/done) |
| `?action=soft_delete` | POST | Logado | Move tarefa para lixeira |
| `?action=restore` | POST | Logado | Restaura tarefa da lixeira |
| `?action=force_delete` | POST | Logado | Exclui tarefa permanentemente |
| `?action=truncate_tasks` | POST | **Admin** | Limpa todas as tarefas |
| `?action=update_details` | POST | Logado | Atualiza detalhes (datas, observações) |
| `?action=add_update` | POST | Logado | Adiciona atendimento |
| `?action=reassign` | POST | Logado | Reatribui tarefa para outro usuário |
| `?action=share_task` | POST | Logado | Compartilha tarefa com outro usuário |
| `?action=unshare_task` | POST | Logado | Remove compartilhamento |
| `?action=import_bulk` | POST | Logado | Importa tarefas em lote (via planilha) |
| `?action=list_trash` | GET | Logado | Lista tarefas excluídas |

> **Segurança (v1.3.0–v1.5.0):** Todos os endpoints exigem sessão válida. Todo POST exige `requireCsrf()`. `get`, `soft_delete`, `restore`, `force_delete`, `update_status`, `add_update`, `reassign`, `share_task`, `unshare_task` e `update_details` exigem `canAccessTask()` (dono, compartilhado ou admin). `list` filtra por dono/compartilhado p/ não-admin (v1.5.0). `update_status` só aceita `todo/in_progress/done` (v1.5.0). `truncate_tasks` é restrito a admin.

---

#### `api/users.php`
API de usuários (145 linhas):

| Ação | Método | Acesso | Descrição |
|------|--------|--------|-----------|
| `?action=list` | GET | Admin | Lista todos os usuários |
| `?action=create` | POST | Admin | Cria novo usuário (role sempre `user`, e-mail com formato válido) |
| `?action=update` | POST | Admin | Atualiza dados (valida formato + duplicidade de e-mail, whitelist `admin/user`, ID 1 nunca rebaixado) |
| `?action=delete` | POST | Admin | Exclui usuário (ID 1 protegido) |
| `?action=update_profile` | POST | Qualquer logado | Atualiza próprio nome/e-mail (valida formato + duplicidade, não altera role) |
| `?action=update_avatar` | POST | Qualquer logado | Atualiza foto do próprio perfil (só admin pode passar `id` de outro) |

> **Segurança:** As actions `list`, `create`, `update` e `delete` exigem sessão de admin. A action `update_profile` permite que o próprio usuário edite seus dados sem poder alterar seu cargo.

---

#### `api/reports.php`
API de relatórios (90 linhas):

- Retorna total de atendimentos
- Ranking de atendentes (top 5 + lista completa + `percent`)
- Ranking de imóveis (top 5 + lista completa + `percent`; sem `LIMIT` no SQL, corte via `array_slice`)
- Ranking de devolutivas (top 5 + lista completa + `percent`)
- Padrão único: todo ranking retorna `{top5, *_all}` com `total` + `percent`; frontend desenha barra + % + botão "Ver todos" nos 3
- Filtra dados por papel do usuário (admin vê tudo, usuário só as suas)

---

#### `api/settings.php`
API de configurações (31 linhas):

| Ação | Método | Acesso | Descrição |
|------|--------|--------|-----------|
| `?action=get` | GET | Logado | Lê permissões do `settings.json` |
| `?action=save` | POST | **Admin** | Salva permissões no `settings.json` (exige `requireCsrf()`; v1.5.0 valida JSON e grava só as 7 chaves booleanas conhecidas) |

---

#### `api/tickets.php`
API de chamados (58 linhas):

| Ação | Método | Acesso | Descrição |
|------|--------|--------|-----------|
| `?action=create` | POST | Logado | Abre novo chamado (dados do usuário vêm da sessão) |
| `?action=list` | GET | **Admin** | Lista chamados |
| `?action=update_status` | POST | **Admin** | Atualiza status (aberto/em_andamento/resolvido) |

> **Segurança:** Os dados do usuário (id, nome, e-mail) são obtidos da sessão no servidor, não do cliente.

---

#### `api/condado.php`
API de integração com Condado (103 linhas):

| Ação | Método | Descrição |
|------|--------|-----------|
| `?action=fetch_data` | GET | Busca imóveis e clientes do Condado |
| `?action=fetch_inadimplentes` | GET | Busca dados de inadimplentes |
| `?action=fetch_clientes` | GET | Busca clientes no cache local |

---

#### `api/sync_condado.php`
Script de sincronização (196 linhas):

- Exige `requireAdmin()` — só administradores logados podem executar
- Conecta ao banco Condado (externo)
- Puxa dados de imóveis, clientes e boletos
- Salva em tabelas de cache local (`cache_imoveis`, `cache_clientes`, `cache_boletos`)
- Retorna progresso em tempo real via HTML
- Erros vão para `error_log`; a tela mostra mensagem genérica

---

#### `api/external_sync.php`
Esqueleto de integração com o outro site/banco (38 linhas):

| Ação | Método | Acesso | Descrição |
|------|--------|--------|-----------|
| `?action=push_activity` | POST | Logado (dono/compartilhado/admin) | Envia a "Descrição das Atividades (Atendimento)" em JSON via POST para `EXTERNAL_API_URL` |

- Exige `requireCsrf()` + `canAccessTask()`; sem `EXTERNAL_API_URL`/`EXTERNAL_API_TOKEN` no `.env`, responde `skipped: true` sem quebrar o fluxo local
- Frontend: `syncActivityToExternal(taskId, content)` em `js/app.js` dispara após `add_update` (fire-and-forget)
- **TODO:** ajustar nomes dos campos do payload e o esquema de autenticação quando tiver acesso ao outro ambiente

---

#### `api/test_condado.php`
Script de diagnóstico (22 linhas):

- Testa conexão com o banco Condado
- Retorna número de registros na tabela `cache_boletos`

---

#### `api/settings.json`
Arquivo de configuração JSON:

```json
{
  "view_tarefas": true,
  "create_task": true,
  "distribute_task": false,
  "view_kanban": true,
  "view_reports": false,
  "view_trash": true,
  "view_config": false
}
```

Controla as permissões de acesso dos usuários padrão no sistema.

---

### Banco de Dados

#### `database.sql`
Schema do banco `bvgarantia_cobrancatask`:

| Tabela | Descrição |
|--------|-----------|
| `users` | Usuários do sistema (id, name, email, password, role, avatar) |
| `tasks` | Tarefas de cobrança (property_name, client_name, status, assigned_to, etc.) |
| `task_shares` | Compartilhamento de tarefas entre usuários |
| `task_updates` | Histórico de atendimentos de cada tarefa (inclui `devolutiva` VARCHAR(100), ver `migrate_devolutiva.php`) |
| `tickets` | Chamados de suporte |
| `cache_imoveis` | Cache de imóveis do Condado |
| `cache_clientes` | Cache de clientes do Condado |
| `cache_boletos` | Cache de boletos do Condado |

---

## Scripts de Desenvolvimento

> **ATENÇÃO:** Estes scripts são apenas para desenvolvimento e diagnóstico. Não devem ser usados em produção.

### PHP (raiz)

| Arquivo | Descrição |
|---------|-----------|
| `debug_map.php` | Consulta tabelas do Condado (LIMIT 10) para inspecionar estrutura |
| `dump_imoveis.php` | Despeja todos os registros da tabela `Tbimovel` |
| `dump_tasks.php` | Exibe a estrutura da tabela `tasks` (DESCRIBE) |
| `run_query.php` | Executa query de agregação financeira no Condado |
| `test_hash.php` | Testa verificação de senhas com `password_verify` |
| `test_query.php` | Testa query JOIN entre `tbcliente` e `tbimovel` |

### JavaScript (raiz — Node.js)

| Arquivo | Descrição |
|---------|-----------|
| `check.js` | Conecta ao Condado e lista tabelas filtradas por empresa |
| `check_schema.js` | Inspeciona schemas das tabelas do Condado |
| `check_schema_2.js` | Variante do inspetor de schemas |
| `extrair_inadimplentes.js` | Extrai dados de inadimplentes e salva em JSON |
| `find_tables.js` | Lista todas as tabelas do banco Condado |
| `fix_emoji.js` | Corrige problemas de rendering de emoji no `app.js` |
| `run_query.js` | Executa queries via Node.js no Condado |
| `sample_data.js` | Busca dados de exemplo para testes |
| `test.js` | Testa inicialização do `app.js` com jsdom |
| `test_query.js` | Testa queries SQL no Condado |
| `test_query_3.js` | Queries adicionais de teste no Condado |

---

## Arquivos de Dados

| Arquivo | Descrição |
|---------|-----------|
| `resultado_condado.json` | Resultado de query com erro (coluna ausente no JOIN) |
| `resultado_condado_novo.json` | Resultado de query com erro (coluna desconhecida) |
| `resultado_inadimplentes.json` | Lista de clientes inadimplentes com valores |
| `schema_novo_query.json` | Referência dos schemas das tabelas do Condado |

---

## Imagens

| Arquivo | Descrição |
|---------|-----------|
| `img/logo.svg` | Logo principal (usado no login e landing page) |
| `img/logo1.svg` | Logo variante (usado na sidebar do dashboard) |
| `img/bg-landing.png` | Imagem de fundo da landing page |
| `favicon.png` | Ícone da aba do navegador |
| `img/avatar_*.png` | Avatares de usuários (uploads) |

---

## Configurações

| Arquivo | Descrição |
|---------|-----------|
| `.gitignore` | Exclui do Git: node_modules, .env, logs, debug, avatars, temp |
| `package.json` | Dependências Node.js: `jsdom` (testes), `mysql2` (acesso a DB) |
| `.github/workflows/deploy.yml` | Pipeline CI/CD — deploy via FTP para cPanel |
| `.vscode/sftp.json` | Configuração de sincronização FTP (VS Code) |

---

## Segurança

> **AVISO:** O arquivo `.vscode/sftp.json` contém credenciais FTP em texto plano. Recomenda-se adicionar ao `.gitignore` e rotacionar as credenciais expostas.

> Os scripts de desenvolvimento na raiz também contêm credenciais de banco de dados em texto plano.

### Modelo de Autenticação

Todos os endpoints da API (exceto `login`) exigem sessão válida. O middleware `auth_middleware.php` fornece:

| Função | HTTP Status | Quando aplicado |
|--------|-------------|-----------------|
| `requireAuth()` | 401 | Qualquer endpoint que exija login |
| `requireAdmin()` | 403 | Endpoints restritos a administradores |

### Restrições por Endpoint

| Endpoint | Acesso | Observação |
|----------|--------|------------|
| `auth.php?action=login` | Público | Único endpoint sem autenticação |
| `users.php?action=list/create/update/delete` | Admin | Gerenciamento de usuários |
| `users.php?action=update_profile` | Qualquer logado | Usuário edita apenas seu próprio nome/e-mail |
| `tasks.php?action=truncate_tasks` | Admin | Limpeza total de dados |
| `settings.php?action=save` | Admin | Alteração de permissões do sistema |
| `tickets.php?action=list/update_status` | Admin | Gerenciamento de chamados |
| `reports.php` | Logado | Relatórios filtrados por papel |

### Proteções Adicionais

- **Role ignore no frontend:** O `saveProfile()` envia apenas nome/e-mail; o cargo é definido exclusivamente pelo admin
- **Dados de sessão:** Chamados (`tickets`) obtêm dados do usuário da sessão PHP, não do request do cliente
- **Fallback admin removido:** Todos os endpoints que usavam `$_SESSION['user_id'] ?? 1` agora usam `getCurrentUserId()` que bloqueia sem sessão

### Correções High (v1.3.0)

1. **Credenciais no `.env`:** `api/config.php` lê tudo via `getenv()`; `.env` está no `.gitignore` e é bloqueado pelo `.htaccess`
2. **Sem vazamento de erros:** `catch` e exception handler registram o detalhe em `error_log` e respondem mensagem genérica (inclui `test_condado.php` e `sync_condado.php`)
3. **CSRF:** `generateCsrfToken()` no login, `requireCsrf()` em todo POST (`tasks`, `users`, `tickets`, `settings`, `change_password`); frontend injeta `X-CSRF-Token` via wrapper do `fetch`, token guardado em `cobranca_csrf`
4. **XSS:** `escapeHtml()` no `js/app.js` aplicado às renderizações (usuários, tarefas, kanban, lixeira, Condado, tickets)
5. **IDOR tarefas:** `canAccessTask()` verifica dono/compartilhamento antes de ler, alterar, excluir, reatribuir ou compartilhar
6. **IDOR avatar:** `update_avatar` usa sempre o ID da sessão; só admin pode informar `id` de outro usuário
7. **Sync protegido:** `sync_condado.php` exige `requireAdmin()`
8. **Arquivos de teste bloqueados:** `.htaccess` nega `test_*`, `debug_*`, `dump_*`, `run_query`, `find_tables`, `check_*`, `fix_emoji`, `sample_data`, `extrair_*`, além de `.env` e JSONs de dados

---

## Controle de Versão

| Versão | Data | Alterações |
|--------|------|------------|
| 1.5.6 | 25/09/2026 | Kanban confiável: `dropCard` lê a resposta do servidor (antes ignorava), toast de sucesso/erro e rollback visual (`oldParent` + `updateKanbanCounters` + recarrega) se falhar; `dashboard.html` passa a `app.js?v=14` |
| 1.5.5 | 25/09/2026 | Fix botão "Ir para Tarefas": `loadView` e `openCreateTaskModal` viviam presas no closure do `DOMContentLoaded` (invisíveis para `onclick` inline) — expostas via `window.loadView`/`window.openCreateTaskModal`; `dashboard.html` passa a `app.js?v=13` (cache-busting) |
| 1.5.4 | 24/09/2026 | Usabilidade: loading anti-duplo-clique nos botões Entrar e Registrar atendimento (`disabled` + `btn-loading`, destrava no `finally`); `confirmModal()` próprio substituindo os 8 `confirm()` nativos; `promptModal()` substituindo os 2 `prompt()` (nova coluna, nova etiqueta + fix XSS na etiqueta); `emptyStateHtml()` único para Tarefas/Lixeira/Kanban; incentivo à devolutiva (estado vazio convidativo + flag `first_devolutiva` no `add_update` com toast 🎉); `database.sql` ganha tabela `tickets`; `.vscode/` no `.gitignore` |
| 1.5.3 | 24/09/2026 | Login em produção: `api/config.php` remove aspas dos valores do `.env` (`trim($value), "\"'"`) e espelha em `$_ENV`, default `DB_USER` volta para `bvgarantia_cobranca`; `login.html` sem `required` (validação no backend) + botão `#btnEntrar`, removido link "Acessar o site"; limpeza no `index.html` |
| 1.5.1 | 24/09/2026 | Padronização dos 3 rankings de relatórios (barra + % + Ver todos): `reports.php` com `percent` nos 3 + retorno `{top5, *_all}` (Imóveis sem `LIMIT`, corte via `array_slice`); `app.js` com `renderRankingBar`/`getRankingName` únicos, `openRankingModal` no mesmo padrão (título escapado, tolera lista nula), 3 cards gêmeos com scroll 320px; `task_updates.devolutiva` (whitelist de 12 valores em `tasks.php`, select em `dashboard.html`, coluna em `database.sql` + `migrate_devolutiva.php`) |
| 1.5.0 | 21/09/2026 | Segurança lista/escrita: `tasks:list` filtra por dono/compartilhado p/ não-admin, `update_status` com whitelist (todo/in_progress/done), `settings:save` valida JSON + 7 chaves booleanas, `users:create/update/update_profile` validam formato e duplicidade de e-mail + whitelist de role + proteção ID 1; Auth/sessão: rate-limit de login (5 erros/15min = bloqueio 5min + 429 + sleep 1s), nova `auth:check`, login regenera CSRF e grava `last_activity`, `logout` via POST+CSRF com limpeza total (sessão+cookie), cookie `httponly`+`samesite=Lax`+`secure` em HTTPS, frontend `auth.js` com logout POST |
| 1.4.1 | 18/09/2026 | `list_trash` filtra por dono/compartilhado p/ não-admin, avatar por MIME real + limite 2MB, `assertUserExists` em create/import/reassign/share, timeout de inatividade (10min) no servidor via `last_activity`, `change_password` usa `getCurrentUserId` |
| 1.4.0 | 18/09/2026 | Esqueleto de integração externa: `api/external_sync.php` (push_activity em JSON via cURL), `syncActivityToExternal()` no frontend após add_update, config via `EXTERNAL_API_URL`/`TOKEN` no `.env`, correção XSS na lista de atendimentos |
| 1.3.0 | 18/09/2026 | Correções high: credenciais via `.env`, erros genéricos ao cliente (log no servidor), CSRF em todo POST, `escapeHtml()` no frontend, `canAccessTask()` anti-IDOR, avatar usa ID da sessão, `sync_condado.php` restrito a admin, `.htaccess` bloqueando testes/debug/`.env` |
| 1.2.0 | 18/09/2026 | Correções de segurança: middleware de autenticação (auth_middleware.php), todos os endpoints protegidos com requireAuth/requireAdmin, removido fallback admin em tasks/reports/condado, nova action update_profile para autoatendimento, tickets usa dados da sessão, testes automatizados de segurança (test_security.php) |
| 1.1.0 | 17/09/2026 | Paginacao real 50/50 (limit/offset/search + total/hasMore), kanban progressivo com botao unico Carregar mais, refresh suave de relatorios (skeleton + fade + count-up) |
| 1.0.12 | 17/09/2026 | Ranking Top 5 com modal, versão no rodapé da sidebar |
| 1.0.11 | 16/09/2026 | Paginação de tarefas (50/página), carregamento progressivo Kanban |
| 1.0.10 | 16/09/2026 | Sistema de permissões aplicado na interface |
| 1.0.9 | 15/09/2026 | Melhoria visual do layout, correção da logo |
| 1.0.8 | 15/09/2026 | Botão trocar senha, dados do perfil |
| 1.0.0 | — | Estrutura base do projeto |
