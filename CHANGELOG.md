# Changelog — Coturnos & Cia (Back-end Central)

Todas as alterações relevantes, refatorações, migrations e instalações de pacotes no repositório `Coturnos_Back-end_Cliente` estão documentadas neste arquivo.

---

## [Sprint 18.09] - 2026-09-28

### 🔐 Autenticação Sanctum & Model User Blindada (Semana 1 - Tarefa 1.2)

- **Model `User.php`:**
    - Integrado trait `HasApiTokens` do Laravel Sanctum.
    - Implementados casts de criptografia LGPD nativos: `'cpf' => 'encrypted'` e `'phone' => 'encrypted'` (AES-256 via `APP_KEY`), além de `'password' => 'hashed'`, `'birth_date' => 'date'` e `'active' => 'boolean'`.
    - Adicionados helpers `getFullNameAttribute()`, `isAdmin()` e `isCustomer()`.
    - Mapeados relacionamentos `addresses()` e `socialAccounts()`.
- **Models `Address.php` e `SocialAccount.php`:**
    - Criadas models complementares com relacionamento `belongsTo(User::class)` para consistência da arquitetura.
- **Form Requests (`app/Http/Requests/Auth`):**
    - `RegisterRequest.php`: Validações de nome, sobrenome, CPF, telefone, e-mail único e confirmação de senha.
    - `LoginRequest.php`: Validações de e-mail e senha.
- **Controller & Endpoints (`app/Http/Controllers/AuthController.php`):**
    - `POST /api/v1/auth/register`: Cadastro de clientes com emissão de token Sanctum.
    - `POST /api/v1/auth/login`: Autenticação e emissão de token Sanctum.
    - `GET /api/v1/auth/me`: Retorno dos dados do usuário logado (protegido via `auth:sanctum`).
    - `POST /api/v1/auth/logout`: Revogação do Bearer Token atual (protegido via `auth:sanctum`).
    - Contrato JSON 100% alinhado com o esperado pelo front-end do Fabio: `{ "message": "...", "user": { ... }, "token": "..." }`.
- **Testes Automatizados (`tests/Feature/AuthTest.php`):**
    - 4 testes cobrindo registro, login, bloqueio de credenciais inválidas, acesso autenticado, revogação e validação de criptografia bruta no banco de dados.

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
