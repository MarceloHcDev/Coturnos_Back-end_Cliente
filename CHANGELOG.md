# Changelog — Coturnos & Cia (Back-end Central)

Todas as alterações relevantes, refatorações, migrations e instalações de pacotes no repositório `Coturnos_Back-end_Cliente` estão documentadas neste arquivo.

---

## [Semana 3 — Tarefa 3.1] - 2026-10-04

### 📦 SuperFrete Unificado por Carrinho & ViaCEP com Cache Inteligente (24h)

Implementação da infraestrutura de logística, consulta de CEPs e cálculo de frete agregado para N coturnos do carrinho com blindagem de segurança, tipagem estrita de retorno e resiliência a falhas externas.

- **Consulta de CEP (`app/Http/Controllers/CepController.php`):**
    - **Sanitização Estrita:** Remoção de máscara e validação de tamanho exato de 8 dígitos numéricos antes de qualquer requisição externa ou montagem de chave de cache.
    - **Cache Inteligente:** Respostas válidas são cacheadas por 24 horas (`now()->addHours(24)`) no Redis/File (`viacep_{cep}`), zerando latência para consultas repetidas.
    - **TTL Curto para Falhas:** CEPs inexistentes recebem cache temporário de 60 segundos para evitar sobrecarga repetitiva sem travar o CEP por um dia.
    - **Timeout Explícito:** Timeout de 5 segundos (`Http::timeout(5)`) para não bloquear workers PHP em caso de oscilação do ViaCEP.
    - **Payload Padronizado:** Normalização compatível com o frontend (`cep`, `logradouro`, `bairro`, `cidade`, `localidade`, `estado`, `uf`).
- **Refatoração do Cálculo de Frete (`app/Services/SuperFreteService.php` & `app/Http/Controllers/FreteController.php`):**
    - **Blindagem contra Manipulação de Peso/Dimensão:** Pesos e dimensões foram totalmente expurgados do payload da requisição. O cálculo agora busca obrigatoriamente os produtos no catálogo oficial (`Product`) e deriva o peso e dimensões através do modelo `CoturnoEmbalado` (1.5kg, 14x22x35cm por unidade).
    - **Cálculo Agregado por Carrinho:** Suporte a N itens com cálculo proporcional de peso e empilhamento vertical de altura, respeitando as restrições máximas dos Correios (30kg e 105cm por dimensão).
    - **FormRequest com Teto Anti-DoS (`app/Http/Requests/FreteRequest.php`):** Validação estrita exigindo CEP de 8 dígitos e limitando cada item a um teto máximo de 50 unidades, impedindo abusos e sobrecarga de CPU.
    - **Normalização de Contrato (`app/Http/Resources/FreteResource.php`):** Padronização de tipos de dados para o frontend React:
        - `valor`: float estrito (ex: `23.90`, nunca string `"R$ 23,90"`).
        - `prazo_dias`: inteiro estrito (ex: `5`, nunca string `"5 dias"`).
        - `disponivel`: booleano (`true`/`false`).
    - **Proteção de Credenciais em Logs:** Tratamento de erros externos sem logar cabeçalhos ou tokens da SuperFrete.
- **Rotas & Rate Limiting (`routes/api.php`):**
    - `GET /api/v1/cep/{cep}` (protegido com `throttle:30,1`)
    - `POST /api/v1/frete` (protegido com `throttle:30,1`)
- **Testes Automatizados (`tests/Feature/CepTest.php` e `tests/Feature/FreteTest.php`):**
    - 17 novos testes cobrindo: sanitização de CEP, cache de 24h, erro 404, resiliência 503, rate limiting (30 req/min), cálculo unitário e agregado de frete, rejeição de produtos inexistentes, validação de teto de quantidade (anti-DoS), tratamento de serviço indisponível e tipagem rigorosa de floats e inteiros.
    - Total de testes do projeto elevado para 55 testes (223 assertions), 100% aprovados.

---

## [Semana 2 — Tarefa 2.1] - 2026-10-04

### 🌐 Login Social Multi-Provider (Google, Facebook, Apple) & Anti Account-Takeover

Implementação completa da autenticação social via Laravel Socialite, integrando provedores OAuth e aplicando proteções contra sequestro de conta, IDOR e escalada de privilégios.

- **Pacotes Instalados:**
    - `laravel/socialite` (v5.31.0)
    - `socialiteproviders/apple` (v6.1.0)
- **Configurações & Providers:**
    - `config/services.php`: Adicionados blocos para `google`, `facebook` e `apple` lendo do `.env`. Adicionada chave `social_allowed_emails` mapeando `SOCIAL_AUTH_ALLOWED_EMAILS`.
    - `app/Providers/AppServiceProvider.php`: Registrado o listener de extensão do driver da Apple (`Event::listen(SocialiteWasCalled::class, [AppleExtendSocialite::class, 'handle'])`).
- **Banco de Dados & Migrations:**
    - `2026_09_01_000001_create_users_table.php`: Colunas `cpf` e `phone` ajustadas para `nullable` (já que provedores OAuth não fornecem dados fiscais e telefones brasileiros no handshake inicial; serão coletados na finalização de compra ou atualização de perfil).
    - `2026_09_01_000002_create_social_accounts_table.php`: Constraint única `$table->unique(['provider_name', 'provider_id'])` verificada e validada como segunda camada de defesa no banco de dados.
- **Controller `app/Http/Controllers/AuthSocialController.php`:**
    - **Whitelist de Provedores:** Apenas `['google', 'facebook', 'apple']` são aceitos. Requisições com provedores arbitrários são rejeitadas com `400 Bad Request`.
    - **`redirect()`:** Suporta redirecionamento direto de browser (302) e retorno JSON com URL para SPAs React (`wantsJson()`).
    - **`callback()` com Regra Anti-Sequestro de Conta (Anti Account-Takeover):**
        1. Verificação de whitelist de e-mails via `isEmailAllowed()` antes de qualquer processamento.
        2. Se `SocialAccount` já existir por `provider_name` + `provider_id`: autentica o usuário e emite token Sanctum.
        3. Se não existir vínculo, verifica se o e-mail retornado foi atestado como verificado pelo provedor (`email_verified` / `verified_email`).
        4. Se não houver usuário prévio com o e-mail: cria novo `User` com senha nula e vincula a conta social.
        5. Se o usuário já existir E o e-mail for verificado: vincula a conta social e autentica com segurança.
        6. Se o usuário já existir E o e-mail NÃO for verificado: **bloqueia o vínculo automático** e responde `409 Conflict` com `requires_password_confirmation = true`.
    - **`confirmLink()`:** Endpoint para confirmação manual de titularidade através da senha atual da conta para completar vínculos não verificados.
    - **`isEmailAllowed()`:** Whitelist de e-mails configurável via `SOCIAL_AUTH_ALLOWED_EMAILS` no `.env`. Vazio = produção (libera todos). Preenchido = homologação (restringe à lista separada por vírgula).
    - **`isEmailVerified()`:** Lê a flag de verificação de e-mail retornada por cada provedor (`email_verified` / `verified_email`). Fail-safe: ausência da flag = não verificado.
    - **Anti-IDOR:** Toda criação de `SocialAccount` é realizada estritamente via `$user->socialAccounts()->create([...])`.
    - **Escopo e Validade dos Tokens:** Tokens Sanctum emitidos com abilities tipadas (`tokenAbilities()` baseado no `UserRole`) e expiração de 7 dias (`now()->addDays(7)`).
- **Rotas (`routes/api.php`):**
    - `GET /api/v1/auth/{provider}/redirect` (protegido com `throttle:20,1`)
    - `GET /api/v1/auth/{provider}/callback` (protegido com `throttle:10,1`)
    - `POST /api/v1/auth/{provider}/confirm-link` (protegido com `throttle:6,1`)
- **Testes Automatizados (`tests/Feature/SocialAuthTest.php`):**
    - 10 testes cobrindo: whitelist de provedores, primeiro login (criação + vínculo), segundo login (reaproveitamento sem duplicar), bloqueio de provider_id duplicado por constraint única, vinculação automática com e-mail verificado, proteção anti account-takeover com e-mail não verificado, abilities do token por role, rate limiting no callback, bloqueio de e-mail fora da whitelist e liberação de e-mail autorizado.
    - `setUp()` garante whitelist vazia por padrão em todos os testes (isolamento com o `.env` local).
- **`.gitignore`:**
    - Adicionado `/database/database.sqlite` (banco local não deve subir ao repositório).
    - Adicionado `/storage/logs/*.log` (logs podem conter dados sensíveis).

---

## [Sprint 18.09] - 2026-10-01 / 2026-10-04

### 🔗 Alinhamento do Contrato de API com o Frontend React (Semana 1 — Tarefa 1.2)


Após análise do repositório `luisesoliveira-dev/ecommerce-coturnos-e-cia` (branch `rabello/login-register-axios-implement`), o `RegisterRequest` e o `AuthController` foram ajustados para receber exatamente o payload que o React envia, sem exigir alterações no front-end.

#### `RegisterRequest.php` — `prepareForValidation()` e Regras

- **Campo `name` único → `first_name` + `last_name`:** O front envia um único campo `name`. O `prepareForValidation()` agora divide automaticamente no primeiro espaço e popula `first_name` e `last_name`. Se o payload já vier com `first_name` separado (Postman / mobile), o comportamento nativo é mantido.
- **`birthdate` DD/MM/YYYY → `birth_date` YYYY-MM-DD:** O front envia a data no formato brasileiro com barra. O `prepareForValidation()` converte para o formato ISO antes da validação. Aceita também o formato ISO diretamente.
- **Validação de idade mínima (16 anos) e máxima (110 anos):** Substituídas as regras genéricas `before:today` / `after:1900-01-01` pelas regras `before_or_equal` e `after_or_equal` calculadas dinamicamente com `now()->subYears()`, alinhando com a função `validarData` do frontend.
- **Regra de senha atualizada para `Password::min(8)->mixedCase()->symbols()`:** Alinha com o `validarSenha` do front (`/[A-Z]/` + `/[^A-Za-z0-9]/`). **Números não são mais obrigatórios** (NIST/OWASP — complexidade por variedade de classes, não por tipo fixo).
- **`confirmed` condicional com `Rule::when()`:** O front **não** envia `password_confirmation`. A regra agora só é aplicada se o campo estiver presente no payload — o front cadastra sem quebras, e clientes que enviam confirmação (Postman / mobile) continuam sendo validados.
- **`last_name` alterado para `nullable`:** Como o front pode enviar um nome sem sobrenome, o campo não pode mais ser `required`.
- **Campos de endereço adicionados ao `rules()`:** `zip`, `street`, `number`, `complement`, `neighborhood`, `city`, `state` — todos `nullable`, permitindo que o endereço seja enviado opcionalmente no mesmo payload do cadastro.

#### `AuthController::register()` — Criação de Endereço no Cadastro

- O método `register()` agora **cria automaticamente o primeiro endereço do usuário** se os campos de endereço estiverem presentes no payload.
- A criação é feita via `$user->addresses()->create()` (Anti-IDOR: `user_id` jamais entra por mass assignment).
- O CEP é normalizado para somente dígitos antes de salvar (`preg_replace('/\D/', '')`).
- O endereço é criado com `is_default = true`, disparando automaticamente a regra de exclusividade do `Address::booted()`.
- Campos de usuário são extraídos explicitamente via `$request->safe()->only([...])` — campos de endereço não chegam ao `User::create()`.

### 🛣️ Rotas — Limpeza, Versionamento e Rate Limiting Básico (`routes/api.php`)

- **Rota `/frete` duplicada removida** — a rota solta fora do grupo `v1` foi eliminada. Toda a API agora vive exclusivamente sob o prefixo `/api/v1`, eliminando risco de manutenção assimétrica.
- **Rate Limiting básico nas rotas sensíveis** (proteção preventiva, ajuste fino planejado para Semana 4 — Tarefa 4.3):
    - `POST /api/v1/auth/register` → `throttle:10,1` (10 req/min por IP — Anti-DoS / Anti-bot).
    - `POST /api/v1/auth/login` → `throttle:6,1` (6 req/min por IP — Anti-força-bruta).
    - `POST /api/v1/frete` → `throttle:30,1` (30 req/min por IP — protege cota financeira da API SuperFrete).
- **Nova rota `POST /api/v1/auth/logout-all`** registrada dentro do grupo `auth:sanctum`.

### 🔐 Endpoint `logout-all` — Encerrar Todas as Sessões

- **`AuthController::logoutAll()`** adicionado:
    - Chama `$request->user()->tokens()->delete()` para revogar **todos** os tokens Sanctum ativos do usuário (todos os dispositivos simultaneamente).
    - Retorna `200` com mensagem `"Todas as sessões foram encerradas com sucesso."`.
    - Útil em cenários de token vazado, roubo de dispositivo ou troca de senha.
    - O `/logout` existente permanece inalterado (revoga apenas o token atual).

### 🧪 Testes Adicionados / Atualizados

- `test_user_can_register_with_frontend_payload_shape_and_address` — payload exato do front React (campo `name`, `birthdate` em DD/MM/YYYY, senha sem confirmação, endereço completo). Verifica divisão de nome, conversão de data, criação de endereço no banco.
- `test_registration_fails_with_age_less_than_16` — data em formato brasileiro 15 anos atrás → deve retornar 422 com mensagem de idade mínima.
- `test_registration_fails_with_age_more_than_110` — data em formato brasileiro 115 anos atrás → deve retornar 422 com mensagem de data inválida.
- `test_registration_fails_with_weak_or_oversized_password` — atualizado: senha sem maiúscula/símbolo falha; senha com maiúscula+símbolo+tamanho inválido (>128 chars) também falha.
- `test_logout_all_revokes_all_active_tokens` — cria 3 tokens simultâneos (mobile, PC, tablet) e verifica que o banco fica com 0 tokens após a chamada.

---

## [Sprint 18.09] - 2026-09-28

### 🔐 Autenticação Sanctum & Model User Blindada (Semana 1 - Tarefa 1.2)

- **Model `User.php`:**
    - Integrado trait `HasApiTokens` do Laravel Sanctum.
    - Implementados casts de criptografia LGPD nativos: `'cpf' => 'encrypted'` e `'phone' => 'encrypted'` (AES-256-CBC via `APP_KEY`), além de `'password' => 'hashed'`, `'birth_date' => 'date'` e `'active' => 'boolean'`.
    - **Proteção contra Mass Assignment:** `role` e `active` removidos de `$fillable`. Defaults seguros definidos em `$attributes` (`role = 'customer'`, `active = true`).
    - **`$hidden` expandido:** `cpf`, `phone` e `cpf_hash` adicionados ao array para nunca vazar na serialização JSON.
    - **Imutabilidade do CPF:** Evento `updating` no `booted()` lança `InvalidArgumentException` se houver tentativa de alterar o CPF após o cadastro (requisito fiscal / NF-e / antifraude).
    - **Blind Indexing (CPF):** Coluna `cpf_hash` (HMAC-SHA256 com `APP_KEY`), UNIQUE + INDEX. Métodos estáticos `generateCpfHash()` (normaliza para dígitos antes do hash) e `findByCpf()`. Evento `saving` gera o hash automaticamente.
    - **`UserRole` Enum (PHP 8.1+):** Criado `app/Enums/UserRole.php` com cases `CUSTOMER`, `ADMIN`, `CLIENTE` (alias legado). Cast `'role' => UserRole::class` no modelo. `isAdmin()` e `isCustomer()` atualizados para comparação tipada com Enum.
    - **Accessor `fullName()` modernizado:** Substituído `getFullNameAttribute()` pelo padrão `Attribute::get()` do Laravel 9+.
    - Mapeados relacionamentos `addresses()` e `socialAccounts()`.
- **Model `Address.php`:**
    - **Anti-IDOR:** `user_id` removido de `$fillable`. Criação deve obrigatoriamente ocorrer via `$user->addresses()->create()`.
    - **SoftDeletes:** Trait `SoftDeletes` adicionada e coluna `deleted_at` adicionada à migration. Endereços deletados são preservados para integridade de pedidos e NF-e históricas.
    - **Exclusividade de endereço padrão:** Evento `saving` no `booted()` zera `is_default` de todos os outros endereços do usuário quando um novo é marcado como padrão.
- **Model `SocialAccount.php`:**
    - **Anti-IDOR:** `user_id` removido de `$fillable`.
    - **Helper `findByProvider()`** adicionado para consulta segura por `provider_name` + `provider_id`.
- **Enum `app/Enums/UserRole.php`:** Criado com métodos `label()`, `isAdmin()` e `isCustomer()`.
- **Resource `app/Http/Resources/UserResource.php`:** Criado para serialização controlada do User na API (inclui `role->value` para Enum, exclui campos sensíveis).
- **Regra `app/Rules/Cpf.php`:** Criada com algoritmo oficial Receita Federal (Módulo 11) + rejeição de CPFs com dígitos repetidos (ex: `111.111.111-11`).
- **Form Requests (`app/Http/Requests/Auth`):**
    - `RegisterRequest.php`:
        - `prepareForValidation()`: normaliza e-mail para lowercase e gera `cpf_hash` para validação de unicidade antes do insert.
        - Regras: CPF com validação matemática (`Cpf` rule), `cpf_hash` unique, e-mail `max:191` + unique, telefone com regex DDD+número, `birth_date` com limites, senha `max:128` + `Password::min(8)->letters()->numbers()`.
        - Mensagens amigáveis para todas as regras.
    - `LoginRequest.php`:
        - `prepareForValidation()`: normaliza e-mail para lowercase.
        - Regras: `email max:191`, `password max:128` (Anti-DoS — evita Bcrypt com payload gigante).
        - Mensagens amigáveis para todas as regras.
- **`AuthController.php`:**
    - **Proteção contra Timing Attack (User Enumeration):** Hash dummy Bcrypt executado quando o e-mail não existe, garantindo tempo de resposta constante independentemente da existência do usuário.
    - Tokens emitidos com expiração de **7 dias** (`now()->addDays(7)`).
- **Migrations:**
    - `create_users_table.php`: Coluna `cpf_hash VARCHAR(64) UNIQUE NULLABLE INDEX` adicionada para Blind Indexing.
    - `create_addresses_table.php`: `softDeletes()` adicionado.
- **Testes Automatizados (`tests/Feature/AuthTest.php`):**
    - 12+ testes cobrindo: registro, login, enumeração de usuário, acesso autenticado `/me` e `/logout`, proteção contra mass assignment de `role`/`active`, imutabilidade de CPF, Blind Indexing (duplicidade de CPF com e sem máscara, busca por CPF), casting do Enum `UserRole`, Anti-IDOR no endereço, exclusividade de endereço padrão, SoftDelete de endereço, unicidade de `SocialAccount`, helper `findByProvider`, validação matemática de CPF, normalização de e-mail, regex de telefone, data futura de nascimento, senha fraca / oversized, normalização de e-mail no login, payload oversized no login.

### 🗄️ Atualização e Alinhamento das Migrations (Planos Operacionais)

- **`payments` (`2026_09_04_000003_create_payments_table.php`):**
    - Adicionado campo indexado `preference_id` (VARCHAR 100) para compatibilidade oficial com a geração de Checkout Pro do Mercado Pago.
    - Adicionado campo `init_point` (TEXT, nullable) para armazenar a URL de redirecionamento do gateway.
    - Flexibilizado campo `method` para `VARCHAR(50)` (nullable), suportando retornos de webhook (`credit_card`, `pix`, `ticket`).
- **`users` (`2026_09_01_000001_create_users_table.php`):**
    - Expandido o enum `role` para suportar `['customer', 'admin', 'cliente']`, assegurando total compatibilidade com seeders e models dos membros.
- **`orders` (`2026_09_04_000001_create_orders_table.php`):**
    - Adicionados aliases de status em português (`pendente`, `pago`, `em_separacao`, etc.) para interoperabilidade completa com o front-end e backend de pagamentos.

---

## [Sprint 18.09] - 2026-09-25

### 🚀 Pacotes & Autenticação

- **Laravel Sanctum (v4.3.3):**
    - Instalado o pacote `laravel/sanctum` via `php artisan install:api`.
    - Executada a migration `2026_09_25_070257_create_personal_access_tokens_table` para suporte a autenticação SPA / Tokens Bearer na API.

---

### 🗄️ Banco de Dados & Migrations (Esquema Unificado)

- **Limpeza de Migrations Legadas:**
    - Removidos arquivos obsoletos e inconsistentes (`CreatePedidoMigration.php`, `CreateProdutoMigration.php`, migrations com tabelas duplicadas em português).
- **Criação da Estrutura Oficial (14 Migrations):**
    - `2026_09_01_000001_create_users_table.php`: Tabela unificada de usuários com suporte a senha nula (login social), enum de permissões (`cliente`, `admin`) e flag `ativo`.
    - `2026_09_01_000002_create_social_accounts_table.php`: Vínculo 1:N com redes sociais (Google, Facebook, Apple).
    - `2026_09_01_000003_create_addresses_table.php`: Endereços de entrega vinculados com `user_id` (relacionamento `onDelete('cascade')`).
    - `2026_09_02_000001_create_products_table.php`: Catálogo de coturnos com `slug` único, preços (`preco_base`, `preco_promocional`), descrição (TEXT) e `softDeletes`.
    - `2026_09_02_000002_create_product_sizes_table.php`: Grade de tamanhos (37 a 44) e controle de estoque com chave única composta `[product_id, size]`.
    - `2026_09_02_000003_create_product_images_table.php`: Galeria de fotos integrada com Cloudinary (`public_id`, `url`, `principal`, `ordem`).
    - `2026_09_02_000004_create_collections_table.php`: Gestão de coleções ativas e banners.
    - `2026_09_02_000005_create_collection_product_table.php`: Tabela pivô com chave primária composta `[collection_id, product_id]`.
    - `2026_09_03_000001_create_carts_table.php`: Carrinhos de compras com suporte a checkout de convidados (`user_id` nullable, `session_id`).
    - `2026_09_03_000002_create_cart_items_table.php`: Itens do carrinho com tamanho, quantidade e preço unitário.
    - `2026_09_04_000001_create_orders_table.php`: Pedidos de vendas com status enum (`pending`, `paid`, `preparing`, `shipped`, `delivered`, `cancelled`), frete e código de rastreio.
    - `2026_09_04_000002_create_order_items_table.php`: Detalhamento de itens de cada pedido.
    - `2026_09_04_000003_create_payments_table.php`: Gateway Mercado Pago (Pix QR Code Base64, Copia/Cola, Cartão e payload JSON).
    - `2026_09_05_000001_create_audit_logs_table.php`: Auditoria administrativa para rastreabilidade de alterações por IP e data.

---

### ⚙️ Configurações & Ambiente

- `.env`: Atualizado `SESSION_DRIVER=file`.
- Adicionados docblocks explicativos nas migrations de dev `cache` e `jobs` (dev local, migração futura para Redis).
