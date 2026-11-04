# 🛡️ PLANO DE EXECUÇÃO TÉCNICO & ARQUITETURAL — SPRINT_13-10
## PRIMEIRA SEMANA (ETAPA 1: DIAS 1 A 5) — RESPONSÁVEL: LUIS EDUARDO
### Frente de Atuação: Backend Cliente & Infraestrutura | E-commerce Coturnos & Cia

---

## 📌 1. INTRODUÇÃO & CONTEXTO ESTRATÉGICO

O presente documento constitui o **Plano de Execução Oficial e Ultra-Detalhado** da **Primeira Semana (Etapa 1: Dias 1 a 5)** da **Sprint_13-10** (13/10/2026 a 03/11/2026) do projeto integrador **Coturnos & Cia**.

Este plano foi concebido a partir da **análise exaustiva do código-fonte atual**, do ecossistema de microsserviços/módulos (Back-end Cliente, Back-end Admin, Front-end Loja SPA e Front-end Admin) e das diretrizes estabelecidas no documento estratégico da Sprint.

### 🎯 Diretriz Primordial da Sprint
A Sprint_13-10 tem como objetivo consolidar o **"Caminho Feliz"** ponta a ponta do ecossistema e executar um saneamento arquitetural definitivo. Na **Etapa 1**, o foco exclusivo da responsabilidade de **Luis Eduardo** é:
1. **Fundação de Segurança e Credenciais (OWASP A07):** Implementação do fluxo completo e seguro de recuperação de senha com tokens criptografados e expiração temporal.
2. **Infraestrutura de E-mails Locais:** Implantação e orquestração do container Mailpit via Docker/Laravel Sail para captura e inspeção visual de transações de e-mail sem dependência de serviços externos.
3. **Saneamento do Banco de Dados:** Remoção das migrations e tabelas órfãs de carrinho (`carts` e `cart_items`), consolidando a decisão de carrinho 100% no `localStorage` do front-end.
4. **Desbloqueio Crítico da Equipe:** Compartilhamento seguro da `APP_KEY` unificada (D1) e entrega do contrato JSON dos endpoints de autenticação/recuperação de senha (D2).

---

## 🔍 2. DIAGNÓSTICO PROFUNDO DO CÓDIGO-FONTE ATUAL

Antes de traçar as ações, foi realizado o mapeamento completo da arquitetura do repositório `/home/luis/Projetos/Coturnos-Cia_Back-end_Cliente`:

### 2.1. Arquitetura Geral & Stack Tecnológica
- **Framework:** Laravel 11.31 LTS sobre PHP 8.2+.
- **Autenticação:** Laravel Sanctum 4.0 emitindo Bearer Tokens com *abilities* escopadas por `UserRole` (`customer`, `admin`).
- **Persistência Relacional:** MySQL 8.0 (com contingência SQLite para testes locais rápidos).
- **Integrações Ativas:** Mercado Pago SDK (`mercadopago/dx-php` v3.16), SuperFrete API (cálculo de frete agregado com cubagem), ViaCEP (cache inteligente de 24h), Socialite (OAuth Google, Facebook, Apple) e Cloudinary.
- **Suíte de Testes:** 89 testes automatizados (Pest / PHPUnit) com 345 assertions, todos com status 100% aprovado.

### 2.2. Mapeamento de Pontos Sensíveis & Vulnerabilidades Identificadas
1. **Ausência da Tabela `password_reset_tokens`:**
   - O projeto utiliza migrations customizadas com prefixo de data (`2026_09_01_...`). A migration padrão do Laravel que criava `password_resets` ou `password_reset_tokens` não está presente no diretório `database/migrations/`.
   - Inexistência de models ou endpoints para suporte a esquecimento de credenciais (violando o requisito OWASP Top 10 - A07: *Identification and Authentication Failures*).
2. **Tabelas Órfãs de Carrinho (`carts` e `cart_items`):**
   - As migrations `2026_09_03_000001_create_carts_table.php` e `2026_09_03_000002_create_cart_items_table.php` criam tabelas no banco de dados, porém não existe nenhum Model, Controller ou rota associada a elas no Back-end.
   - O Front-end do projeto (Fabio Matheus) implementou o carrinho inteiramente no cliente via `CartContext.tsx` (`localStorage`). A persistência no banco ocorre somente no momento do Checkout diretamente nas tabelas `orders` e `order_items`. Manter essas tabelas gera overhead desnecessário, inconsistência de schema entre os repositórios e risco de dívida técnica.
3. **Ambiente de E-mail Não Padronizado:**
   - O arquivo `.env.example` e a configuração local do `.env` apontam para `MAIL_MAILER=log` ou `MAIL_HOST=127.0.0.1`. Não existe container de Mailpit declarado em um arquivo `docker-compose.yml` no repositório, inviabilizando testes visuais dos templates de e-mail pela equipe de desenvolvimento e homologação integrada.
4. **Criptografia LGPD & Blind Indexing:**
   - O model `app/Models/User.php` já possui blindagem com casts `encrypted` (AES-256-CBC) para `cpf` e `phone`, além de blind index HMAC-SHA256 (`cpf_hash`). Isso reforça a criticidade de manter a `APP_KEY` rigorosamente sincronizada com o Back-end Admin de Erick Montassier e Marcelo Rezende.

---

## 🗺️ 3. MAPA DE DEPENDÊNCIAS CRÍTICAS & DESBLOQUEIOS ÁGEIS (SEMANA 1)

Conforme a Matriz de Dependências Críticas da Sprint_13-10 (Seção 3 do documento oficial), a primeira semana possui dois marcos imediatos de responsabilidade de Luis Eduardo:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        DESBLOQUEIOS IMEDIATOS                          │
├────────────────────────────────┬───────────────────────────────────────┤
│ D1: MySQL & APP_KEY Unificada  │ Fornecer .env com APP_KEY em até      │
│ (Erick e Marcelo dependem)     │ 30 minutos do Dia 1                   │
├────────────────────────────────┼───────────────────────────────────────┤
│ D2: Endpoints Esqueci a Senha  │ Entregar contrato de API JSON para o  │
│ (Fabio Matheus depende)        │ Front-end em até 24 horas (Dia 1 / 2) │
└────────────────────────────────┴───────────────────────────────────────┘
```

### 3.1. Procedimento Operacional D1: Sincronização Segura de Segredos
- **Ação:** Luis Eduardo extrai a `APP_KEY` ativa do Back-end Cliente e repassa por canal seguro interno (Vault / canal criptografado de comunicação da equipe) para Erick Montassier (`ADM-Coturnos-cia`) e Marcelo Rezende.
- **Risco Mitigado:** Caso Erick ou Marcelo utilizem uma `APP_KEY` diferente, qualquer leitura de registros na tabela `users` (colunas `cpf` e `phone`) resultará em exceção fatal `Illuminate\Contracts\Encryption\DecryptException`, paralisando o painel administrativo.

### 3.2. Procedimento Operacional D2: Contrato da API de Recuperação de Senha
- **Ação:** Publicação do contrato OpenAPI/JSON com schemas de Request e Response para as rotas:
  - `POST /api/v1/auth/esqueci-senha`
  - `POST /api/v1/auth/redefinir-senha`
- **Garantia:** Permite a Fabio Matheus criar os formulários em `LoginCadastro.tsx` e `ResetPassword.tsx` com contratos reais, acelerando o desenvolvimento do Front-end sem bloqueios.

---

## 📋 4. PLANO DE EXECUÇÃO ULTRA-DETALHADO — ETAPA 1 (DIAS 1 A 5)

Abaixo, cada atividade atribuída a **Luis Eduardo** é dissecada nos quatro pilares obrigatórios:
1. **O que é**
2. **Por que é necessário**
3. **Como será executado (Passo a Passo Técnico)**
4. **Camada de Segurança, OWASP & Gestão de Riscos**

---

### 🗄️ TAREFA 1.1: Saneamento do Banco de Dados — Remoção de Migrations e Tabelas Órfãs de Carrinho

#### 1. O que é?
A remoção física definitiva das migrations:
- `database/migrations/2026_09_03_000001_create_carts_table.php`
- `database/migrations/2026_09_03_000002_create_cart_items_table.php`  
E a consequente eliminação das tabelas `carts` e `cart_items` do schema relacional do banco de dados (SQLite e MySQL).

#### 2. Por que é necessário?
- **Decisão Arquitetural Consolidada:** O e-commerce Coturnos & Cia opera seguindo o padrão moderno de SPAs (Shopify, Netshoes), no qual o carrinho de compras é reativo e armazenado localmente no navegador do usuário (`localStorage`).
- **Eliminação de Overhead:** Persistir cada clique de "Adicionar ao Carrinho" no banco de dados cria tráfego excessivo de I/O, concorrência desnecessária no MySQL e acúmulo de centenas de carrinhos abandonados (lixo de dados).
- **Consistência de Schema:** As tabelas `carts` e `cart_items` não possuem nenhum model, repository ou controller no ecossistema atual. Sua manutenção apenas confunde outros desenvolvedores da equipe (Erick, Marcelo, Ryan) e polui o versionamento do banco.

#### 3. Como será executado?
1. **Verificação de Chaves Estrangeiras:**
   - Confirmar que nenhuma outra migration ou tabela referencia `carts` ou `cart_items` (análise de código já atestou que apenas `cart_items` faz FK para `carts` e `products`).
2. **Remoção Segura dos Arquivos de Migration:**
   ```bash
   rm database/migrations/2026_09_03_000001_create_carts_table.php
   rm database/migrations/2026_09_03_000002_create_cart_items_table.php
   ```
3. **Execução de Migration Fresh / Rollback Controlado:**
   - Em ambiente local e de homologação:
     ```bash
     php artisan migrate:fresh --seed
     ```
   - Em bases persistentes de desenvolvimento compartilhado, caso as tabelas já tenham sido criadas anteriormente, executar script/migration pontual de drop para higienização:
     ```sql
     DROP TABLE IF EXISTS cart_items;
     DROP TABLE IF EXISTS carts;
     ```
4. **Validação da Suíte de Testes:**
   - Executar `php artisan test` para certificar que nenhum dos 89 testes automatizados dependia dessas tabelas.

#### 4. Camada de Segurança & Riscos
- **Risco:** Perda de dados caso algum pedido fizesse menção a `cart_id`.
- **Mitigação:** Análise do model `app/Models/Order.php` e da migration `2026_09_04_000001_create_orders_table.php` comprovou que pedidos são gravados diretamente a partir do payload recebido pelo `OrderController` / `CheckoutController`, referenciando diretamente `user_id`, `address_id` e itens em `order_items` (tabela de itens definitiva). O risco é **zero**.

---

### 🐳 TAREFA 1.2: Infraestrutura Local de E-mails — Orquestração do Mailpit no Docker / Laravel Sail

#### 1. O que é?
A configuração e inicialização do serviço **Mailpit** (servidor SMTP e dashboard Web mock para captura local de mensagens de e-mail) através do arquivo `docker-compose.yml` integrado ao Laravel Sail, expondo as portas de rede necessárias para o ecossistema.

#### 2. Por que é necessário?
- **Ambiente de Desenvolvimento Seguro:** Em desenvolvimento e testes, sistemas nunca devem disparar e-mails para servidores SMTP reais (como SendGrid, SES ou Gmail), sob risco de vazamento de links de redefinição de senha para e-mails fictícios ou disparos acidentais para caixas reais.
- **Inspeção Visual Imediata:** O Mailpit disponibiliza uma interface gráfica via navegador na porta `8025`, permitindo que os desenvolvedores (Luis Eduardo no Back, Fabio Matheus no Front e Ryan Rangel na Documentação) abram a caixa de entrada, visualizem a renderização HTML do e-mail, inspecionem os cabeçalhos MIME e cliquem no link gerado.
- **Recepção SMTP Resiliente:** Fornece um endpoint SMTP nativo na porta `1025` de altíssima velocidade e sem necessidade de autenticação por usuário/senha.

#### 3. Como será executado?
1. **Definição do `docker-compose.yml` (Integrado ao Laravel Sail):**
   Criar/atualizar o arquivo `docker-compose.yml` na raiz do projeto com o serviço `mailpit`:
   ```yaml
   services:
     laravel.test:
       build:
         context: ./vendor/laravel/sail/runtimes/8.3 # ou 8.2 conforme runtime do Sail
         dockerfile: Dockerfile
         args:
           WWWGROUP: '${WWWGROUP:-1000}'
       image: sail-8.3/app
       extra_hosts:
         - 'host.docker.internal:host-gateway'
       ports:
         - '${APP_PORT:-80}:80'
         - '${VITE_PORT:-5173}:${VITE_PORT:-5173}'
       environment:
         WWWUSER: '${WWWUSER:-1000}'
         LARAVEL_SAIL: 1
         XDEBUG_MODE: '${SAIL_XDEBUG_MODE:-off}'
         XDEBUG_CONFIG: '${SAIL_XDEBUG_CONFIG:-client_host=host.docker.internal}'
         IGNITION_LOCAL_SITES_PATH: '${PWD}'
       volumes:
         - '.:/var/www/html'
       networks:
         - sail
       depends_on:
         - mysql
         - mailpit

     mysql:
       image: 'mysql/mysql-server:8.0'
       ports:
         - '${FORWARD_DB_PORT:-3306}:3306'
       environment:
         MYSQL_ROOT_PASSWORD: '${DB_PASSWORD:-password}'
         MYSQL_ROOT_HOST: '%'
         MYSQL_DATABASE: '${DB_DATABASE:-coturnos_cia}'
         MYSQL_USER: '${DB_USERNAME:-sail}'
         MYSQL_PASSWORD: '${DB_PASSWORD:-password}'
         MYSQL_ALLOW_EMPTY_PASSWORD: 1
       volumes:
         - 'sail-mysql:/var/lib/mysql'
       networks:
         - sail
       healthcheck:
         test: ["CMD", "mysqladmin", "ping", "-p${DB_PASSWORD:-password}"]
         retries: 3
         timeout: 5s

     mailpit:
       image: 'axllent/mailpit:latest'
       ports:
         - '${FORWARD_MAILPIT_PORT:-1025}:1025'
         - '${FORWARD_MAILPIT_DASHBOARD_PORT:-8025}:8025'
       networks:
         - sail

   networks:
     sail:
       driver: bridge

   volumes:
     sail-mysql:
       driver: local
   ```

2. **Inicialização do Ambiente:**
   ```bash
   ./vendor/bin/sail up -d mailpit
   ```
   *(Caso o desenvolvedor opte por rodar Docker puro sem Sail, o comando `docker run -d --name coturnos-mailpit -p 1025:1025 -p 8025:8025 axllent/mailpit:latest` cumpre o papel de forma idêntica).*

3. **Verificação de Acessibilidade:**
   - SMTP: `telnet localhost 1025` ou `nc -zv 127.0.0.1 1025`
   - Web GUI: Acessar `http://localhost:8025` no navegador.

#### 4. Camada de Segurança & Riscos
- **Isolamento de Portas:** As portas `1025` e `8025` devem ser expostas apenas em `localhost` (127.0.0.1) nos ambientes locais de desenvolvimento para evitar que outros nós da rede local interceptem credenciais de teste.
- **Prevenção de Vazamento Externo:** O Mailpit atua como um sumidouro (*blackhole*) seguro: nenhuma mensagem recebida por ele é encaminhada para a internet real.

---

### ⚙️ TAREFA 1.3: Parametrização do Ambiente — Configuração do `.env` e `.env.example`

#### 1. O que é?
A parametrização oficial dos drivers e credenciais de e-mail nos arquivos `.env` (ambiente local ativo) e `.env.example` (documentação versionada do repositório).

#### 2. Por que é necessário?
- Garantir que o Laravel direcione as notificações disparadas pelo método `Notification::send()` ou Mailables para a porta SMTP do Mailpit.
- Evitar divergências de ambiente entre os membros da equipe técnica (Luis, Erick, Marcelo, Fabio e Ryan).

#### 3. Como será executado?
1. **Configuração no `.env` do Back Cliente:**
   ```env
   # ==============================================================================
   # SERVIÇO DE E-MAIL / RECUPERAÇÃO DE SENHA (MAILPIT LOCAL - SPRINT_13-10)
   # ==============================================================================
   MAIL_MAILER=smtp
   MAIL_HOST=mailpit
   MAIL_PORT=1025
   MAIL_USERNAME=null
   MAIL_PASSWORD=null
   MAIL_ENCRYPTION=null
   MAIL_FROM_ADDRESS="contato@coturnosecia.com.br"
   MAIL_FROM_NAME="${APP_NAME}"
   ```
   *(Nota: Se o PHP estiver rodando diretamente no host do Linux sem Docker e o Mailpit estiver no container mapeado, usa-se `MAIL_HOST=127.0.0.1`).*

2. **Atualização no `.env.example`:**
   Adicionar comentários claros e instruções operacionais para os demais integrantes clonarem a configuração sem atrito.

3. **Limpeza de Caches de Configuração:**
   ```bash
   php artisan config:clear
   ```

#### 4. Camada de Segurança & Riscos
- **Prevenção de Credenciais Hardcoded:** Nenhuma senha real de SMTP deve constar nos arquivos versionados no Git.
- **Validação de Driver:** Garantir que `MAIL_MAILER=smtp` não quebre em caso de ausência temporária do container (implementar tratamento de exceção elegante na camada de Controller).

---

### 🗃️ TAREFA 1.4: Camada de Persistência — Migration da Tabela `password_reset_tokens`

#### 1. O que é?
A criação da migration oficial para geração da tabela `password_reset_tokens` no banco de dados.

#### 2. Por que é necessário?
- O ecossistema atual não possui a tabela de controle de redefinição de senhas.
- Essa tabela é o alicerce para armazenar a relação unívoca entre o e-mail do solicitante, o hash seguro do token emitido e o timestamp exato da solicitação para cálculo do tempo de expiração.

#### 3. Como será executado?
1. **Criação do Arquivo de Migration:**
   Executar o comando do Artisan:
   ```bash
   php artisan make:migration create_password_reset_tokens_table
   ```
   Gerará o arquivo em `database/migrations/2026_10_30_000001_create_password_reset_tokens_table.php`.

2. **Estrutura Relacional Estrita:**
   ```php
   <?php

   use Illuminate\Database\Migrations\Migration;
   use Illuminate\Database\Schema\Blueprint;
   use Illuminate\Support\Facades\Schema;

   return new class extends Migration
   {
       /**
        * Run the migrations.
        */
       public function up(): void
       {
           Schema::create('password_reset_tokens', function (Blueprint $table) {
               $table->string('email', 191)->primary();
               $table->string('token', 64); // Armazena o HASH SHA-256 (sempre 64 chars hexadecimais)
               $table->timestamp('created_at')->nullable();
           });
       }

       /**
        * Reverse the migrations.
        */
       public function down(): void
       {
           Schema::dropIfExists('password_reset_tokens');
       }
   };
   ```

3. **Execução da Migration:**
   ```bash
   php artisan migrate
   ```

#### 4. Camada de Segurança & Riscos
- **Chave Primária no E-mail (`email` primary):**
  - **Decisão Arquitetural:** Ao definir `email` como chave primária, o banco de dados impõe naturalmente a restrição de que cada usuário pode ter no máximo **um** token ativo simultaneamente. Se o cliente solicitar uma redefinição repetidas vezes, o registro anterior é sobrescrito (via `updateOrInsert`), invalidando imediatamente links antigos.
- **Tamanho do Campo `token` (64 caracteres):**
  - O campo é dimensionado para exatamente 64 caracteres porque o token armazenado **NÃO** será o token em texto puro, mas sim seu **hash SHA-256 digest** (OWASP A07).
- **Sem Dados Pessoais Extras:** A tabela não deve conter senhas antigas, nomes ou metadados de IP, minimizando a superfície de ataque em caso de vazamento da tabela.

---

### 🛡️ TAREFA 1.5: Solicitação de Redefinição — `ForgotPasswordController.php` & Notificação Segura

#### 1. O que é?
A criação do controller responsável por receber a requisição de "Esqueci minha senha", validar a entrada, gerar um token criptográfico pseudo-aleatório de 64 caracteres, persistir seu hash SHA-256 no banco e disparar a notificação `ResetPasswordNotification` via Mailpit com validade de 60 minutos.

#### 2. Por que é necessário?
- Permitir que clientes que perderam o acesso à sua senha consigam restabelecer o acesso à sua conta de forma autônoma e segura.
- Atender formalmente ao requisito de conformidade **OWASP A07 (Identification and Authentication Failures)**.

#### 3. Como será executado?

##### A. Criação do Form Request de Validação (`ForgotPasswordRequest.php`)
```bash
php artisan make:request Auth/ForgotPasswordRequest
```
Regras de validação estritas:
```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc,dns', 'max:191'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim((string) $this->input('email'))),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'email.required' => 'O campo e-mail é obrigatório.',
            'email.email' => 'Por favor, informe um endereço de e-mail válido.',
        ];
    }
}
```

##### B. Criação da Notificação (`ResetPasswordNotification.php`)
```bash
php artisan make:notification Auth/ResetPasswordNotification
```
Implementação focada em UX e segurança:
```php
<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly string $email
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.front_url', env('FRONT_URL', 'http://localhost:5173'));
        $resetUrl = rtrim($frontendUrl, '/') . '/redefinir-senha?' . http_build_query([
            'token' => $this->token,
            'email' => $this->email,
        ]);

        return (new MailMessage)
            ->subject('Recuperação de Senha — Coturnos & Cia')
            ->greeting('Olá, ' . $notifiable->first_name . '!')
            ->line('Recebemos uma solicitação para redefinir a senha da sua conta na Coturnos & Cia.')
            ->action('Redefinir Minha Senha', $resetUrl)
            ->line('Este link de redefinição é de uso único e expirará em 60 minutos.')
            ->line('Se você não solicitou a redefinição de senha, nenhuma ação adicional é necessária. Sua conta permanece segura.');
    }
}
```

##### C. Criação do Controller (`ForgotPasswordController.php`)
```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ForgotPasswordController extends Controller
{
    /**
     * Envia o link de redefinição de senha com token criptográfico temporário.
     *
     * POST /api/v1/auth/esqueci-senha
     */
    public function sendResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $user = User::where('email', $email)->first();

        // 🛡️ OWASP A07: Proteção estrita contra Enumeração de Usuários (Account Enumeration)
        // Se o usuário não existir ou estiver inativo, não emitimos erro 404.
        // Retornamos exatamente a mesma mensagem genérica com HTTP 200.
        if ($user && $user->active) {
            // Gera um token pseudo-aleatório seguro de 64 caracteres
            $plainToken = Str::random(64);

            // Calcula o hash SHA-256 para persistência segura no banco
            $tokenHash = hash('sha256', $plainToken);

            // Persiste ou atualiza o token na tabela password_reset_tokens
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                [
                    'token' => $tokenHash,
                    'created_at' => Carbon::now(),
                ]
            );

            // Dispara a notificação contendo o token em texto puro (enviado exclusivamente por e-mail)
            $user->notify(new ResetPasswordNotification($plainToken, $email));
        }

        return response()->json([
            'message' => 'Se o e-mail informado estiver cadastrado em nosso sistema, um link seguro para redefinição de senha foi enviado.',
        ], 200);
    }
}
```

#### 4. Camada de Segurança, OWASP & Riscos
1. **Mitigação de Enumeração de Contas (User Account Enumeration):**
   - Um atacante não pode usar este endpoint para descobrir quais e-mails possuem conta no e-commerce. A resposta é idêntica (`HTTP 200`) e com o mesmo payload JSON tanto para e-mails cadastrados quanto para inexistentes.
2. **Criptografia em Repouso do Token (SHA-256 Hash):**
   - Caso ocorra um vazamento acidental do banco de dados (SQL injection em outro módulo ou backup interceptado), o atacante obterá apenas o hash SHA-256 do token, sendo matematicamente incapaz de utilizá-lo para redefinir a senha do cliente.
3. **Rate Limiting Estrito (Anti-Flooding / Anti-DoS):**
   - Aplicar na rota um rate limiter dedicado de no máximo 3 requisições por minuto por IP/e-mail (`throttle:3,1`), impedindo que um script malicioso sobrecarregue a caixa postal de uma vítima com e-mails repetidos (*mail bombing*).

---

### 🔑 TAREFA 1.6: Confirmação e Atualização — `ResetPasswordController.php` & Invalidação

#### 1. O que é?
O controller que recebe o token em texto puro, o e-mail e a nova senha submetidos pelo formulário do Front-end (`ResetPassword.tsx`), valida a integridade do token, checa a janela de expiração (60 minutos), atualiza a senha de forma criptografada (`Hash::make`), exclui o token e revoga todas as sessões ativas do cliente.

#### 2. Por que é necessário?
- Concluir o ciclo de recuperação de credenciais com máxima segurança.
- Assegurar que senhas fracas sejam rejeitadas.
- Garantir que um token seja de uso estritamente único (*Single-Use Token*), inviabilizando ataques de repetição (*replay attack*).
- Desconectar imediatamente invasores que porventura estivessem logados na conta através de tokens de API Sanctum anteriormente emitidos (*Session Revocation*).

#### 3. Como será executado?

##### A. Criação do Form Request de Redefinição (`ResetPasswordRequest.php`)
```bash
php artisan make:request Auth/ResetPasswordRequest
```
Regras de validação estritas de complexidade:
```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],
            'email' => ['required', 'string', 'email:rfc,dns'],
            'password' => [
                'required',
                'string',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
                'confirmed',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim((string) $this->input('email'))),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'token.required' => 'O token de redefinição é obrigatório.',
            'token.size' => 'O token de redefinição fornecido é inválido.',
            'email.required' => 'O campo e-mail é obrigatório.',
            'password.required' => 'A nova senha é obrigatória.',
            'password.confirmed' => 'A confirmação de senha não confere.',
        ];
    }
}
```

##### B. Criação do Controller (`ResetPasswordController.php`)
```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ResetPasswordController extends Controller
{
    /**
     * Valida o token temporário e atualiza a senha do cliente.
     *
     * POST /api/v1/auth/redefinir-senha
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $plainToken = $request->validated('token');
        $newPassword = $request->validated('password');

        // Busca o registro na tabela de tokens
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (! $resetRecord) {
            return response()->json([
                'message' => 'Token de redefinição inválido ou não encontrado.',
            ], 422);
        }

        // 🛡️ Mitigação de Timing Attack via hash_equals
        $calculatedHash = hash('sha256', $plainToken);
        if (! hash_equals($resetRecord->token, $calculatedHash)) {
            return response()->json([
                'message' => 'Token de redefinição inválido.',
            ], 422);
        }

        // 🛡️ Verificação de Expiração Temporal (Janela estrita de 60 minutos)
        $tokenCreatedAt = Carbon::parse($resetRecord->created_at);
        if ($tokenCreatedAt->addMinutes(60)->isPast()) {
            // Remove token expirado para manter o banco higienizado
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return response()->json([
                'message' => 'Este link de redefinição expirou. Por favor, solicite um novo.',
            ], 422);
        }

        // Localiza o usuário correspondente
        $user = User::where('email', $email)->first();
        if (! $user) {
            return response()->json([
                'message' => 'Usuário não localizado.',
            ], 404);
        }

        // 🛡️ Transação Atômica: Atualização de Senha + Invalidação de Token + Revogação de Sessões
        DB::transaction(function () use ($user, $newPassword, $email) {
            // 1. Atualiza a senha do usuário com hash forte (Bcrypt/Argon2id nativo via Hash::make)
            $user->password = Hash::make($newPassword);
            $user->save();

            // 2. Invalidação Imediata do Token (Evita Replay Attack)
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            // 3. Revogação de TODOS os tokens Sanctum ativos do usuário
            // Força encerramento de sessões em outros dispositivos / possíveis invasores
            $user->tokens()->delete();
        });

        return response()->json([
            'message' => 'Sua senha foi redefinida com sucesso! Você já pode realizar o login com as novas credenciais.',
        ], 200);
    }
}
```

#### 4. Camada de Segurança, OWASP & Riscos
1. **Proteção Contra Timing Attack (`hash_equals`):**
   - A comparação de strings padrão (`===`) em PHP retorna `false` assim que encontra o primeiro caractere divergente, permitindo ataques de inferência temporal de chaves. O uso de `hash_equals()` assegura tempo de execução constante.
2. **Janela Temporal Estrita (60 Minutos):**
   - Se o token tiver 60 minutos e 1 segundo de idade, é categoricamente rejeitado e removido do banco.
3. **Invalidação Atômica Pós-Uso (Anti-Replay):**
   - A exclusão do registro de token ocorre dentro da mesma transação (`DB::transaction`) que salva a nova senha. O token não pode ser reutilizado em hipótese alguma.
4. **Isolamento e Revogação Total de Sessão (`$user->tokens()->delete()`):**
   - Se a redefinição de senha foi motivada por suspeita de conta comprometida, o encerramento imediato de todos os Bearer tokens garante que sessões abertas em telefones ou navegadores não autorizados caiam no próximo request.

---

### 🛣️ TAREFA 1.7: Registro e Proteção de Rotas — `routes/api.php`

#### 1. O que é?
O mapeamento das novas rotas de recuperação de senha no arquivo `routes/api.php` sob o prefixo de versão `v1/auth`, aplicando middlewares de rate limiting e sanitização.

#### 2. Por que é necessário?
- Expor os endpoints para consumo seguro do Front-end (`LoginCadastro.tsx` e `ResetPassword.tsx`).
- Blindar a infraestrutura contra ataques de força bruta e negação de serviço.

#### 3. Como será executado?
No arquivo `routes/api.php`, dentro do grupo `Route::prefix('auth')`:
```php
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\ResetPasswordController;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        // ... rotas existentes: /register, /login, /me, /logout ...

        // ─────────────────────────────────────────────────────────────────
        // Recuperação de Senha (OWASP A07 - Sprint_13-10 Etapa 1)
        // throttle:3,1 → máx 3 requisições/min por IP/e-mail (Anti-Spam / Anti-DoS)
        // ─────────────────────────────────────────────────────────────────
        Route::post('/esqueci-senha', [ForgotPasswordController::class, 'sendResetLink'])
            ->middleware('throttle:3,1');

        Route::post('/redefinir-senha', [ResetPasswordController::class, 'reset'])
            ->middleware('throttle:5,1');
    });
});
```

---

## 🧪 5. PLANO DE TESTES AUTOMATIZADOS (PHPUNIT / PEST)

Para garantir conformidade com a política de excelência de software do projeto e manter a suíte de testes íntegra, será criado o arquivo `tests/Feature/PasswordResetTest.php`.

### Cenários de Teste a Cobrir Obrigatoriamente:
1. `test_envio_link_redefinicao_para_email_valido_gera_token_sha256_no_banco()`:
   - Valida que um usuário cadastrado recebe a notificação, que o token no banco possui 64 caracteres e é diferente do token em texto puro (comprovando o hash SHA-256).
2. `test_envio_link_para_email_inexistente_retorna_200_sem_revelar_inexistencia_anti_enumeracao()`:
   - Valida resposta HTTP 200 genérica e ausência de registros na tabela `password_reset_tokens`.
3. `test_redefinicao_com_sucesso_atualiza_senha_e_revoga_tokens_sanctum()`:
   - Valida que a nova senha permite login via `/api/v1/auth/login`, que a senha antiga é rejeitada e que tokens Sanctum pré-existentes foram excluídos da tabela `personal_access_tokens`.
4. `test_redefinicao_invalida_imediatamente_o_token_impedindo_reuso_anti_replay()`:
   - Submete uma segunda requisição com o mesmo token e espera HTTP 422.
5. `test_rejeita_token_com_mais_de_60_minutos_expirado()`:
   - Manipula o campo `created_at` para `now()->subMinutes(61)` e valida rejeição com HTTP 422.
6. `test_rejeita_senha_fraca_fora_do_padrao_de_complexidade()`:
   - Submete senhas com menos de 8 caracteres, sem números ou sem símbolos, esperando HTTP 422 e mensagens de validação claras.
7. `test_rate_limiting_bloqueia_quarta_solicitacao_consecutiva()`:
   - Dispara 4 requisições consecutivas para `/api/v1/auth/esqueci-senha` e valida retorno HTTP 429 com cabeçalho `Retry-After`.

---

## 🔒 6. RESUMO DE CONFORMIDADE DE SEGURANÇA & OWASP

| Princípio / Norma | Implementação no Plano de Execução |
| :--- | :--- |
| **OWASP A07: Identification and Authentication Failures** | Tokens de 64 chars com entropia criptográfica; hashing SHA-256 no banco de dados; expiração estrita de 60 min; expiração pós-uso imediata. |
| **Mitigação de Account Enumeration** | Resposta idêntica (HTTP 200) para e-mails existentes e inexistentes em `esqueci-senha`. |
| **Mitigação de Timing Attack** | Uso de `hash_equals()` na comparação do digest SHA-256 do token temporário. |
| **Políticas de Senha Forte** | Exigência de no mínimo 8 caracteres, letras maiúsculas, minúsculas, números e caracteres especiais (`Password::min(8)->letters()->mixedCase()->numbers()->symbols()`). |
| **Session Hijacking Prevention** | Revogação global de tokens Sanctum ativos (`$user->tokens()->delete()`) logo após a alteração da senha. |
| **Anti-Flooding & Anti-DoS** | Middlewares `throttle:3,1` e `throttle:5,1` escopados por IP/e-mail para proteger as rotas públicas de redefinição de senha. |
| **Conformidade LGPD (Dados em Repouso)** | Manutenção da `APP_KEY` unificada para garantir decodificação transparente de `cpf` e `phone` via AES-256-CBC nos backends. |

---

## 🏁 7. DEFINITION OF DONE (DoD) — CRITÉRIOS DE ACEITE DA ETAPA 1

Para considerar a **Primeira Semana (Etapa 1)** oficialmente concluída e homologada:

- [ ] **Desbloqueio D1 entregue:** `APP_KEY` mestre e instruções repassadas para Erick Montassier e Marcelo Rezende nas primeiras horas da Sprint.
- [ ] **Desbloqueio D2 entregue:** Contrato da API JSON de esqueci/redefinir senha documentado e repassado para Fabio Matheus em até 24h.
- [ ] **Banco Saneado:** Arquivos `create_carts_table.php` e `create_cart_items_table.php` deletados; tabelas órfãs removidas do banco sem quebrar nenhuma funcionalidade.
- [ ] **Mailpit Operacional:** Container ativo e responsivo nas portas `1025` (SMTP) e `8025` (Web GUI).
- [ ] **Configurações Atualizadas:** Variáveis `MAIL_*` atualizadas no `.env` e documentadas com rigor no `.env.example`.
- [ ] **Tabela `password_reset_tokens` Ativa:** Migration executada com chave primária em `email` e campo `token` de 64 caracteres.
- [ ] **Controllers & Notificação Implementados:** `ForgotPasswordController`, `ResetPasswordController` e `ResetPasswordNotification` codificados com todas as barreiras de segurança.
- [ ] **Rotas Registradas:** Endpoints acessíveis sob `/api/v1/auth/` com rate limiters ativos.
- [ ] **Captura no Mailpit Validada:** Teste de ponta a ponta dispara e-mail capturado com sucesso na interface visual do Mailpit (HTTP 8025).
- [ ] **Suíte de Testes 100% Verde:** Novos testes de redefinição de senha criados e aprovados, mantendo todos os 89 testes anteriores funcionando sem regressão.

---
*Plano elaborado por Luis Eduardo — Backend Cliente & Infraestrutura.*

