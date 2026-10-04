# 🛡️ Guia Oficial de Arquitetura de Autenticação & Transição de Segurança
## E-commerce Coturnos & Cia — Matriz Completa de Impactos por Integrante

> [!IMPORTANT]
> Este documento consolida os impactos arquiteturais da autenticação entre o modelo atual de **Bearer Token (Sanctum + localStorage)** e a transição planejada para **Cookie HttpOnly (Sanctum SPA + CSRF)** para o cliente real.
> Ele detalha as responsabilidades e configurações exatas de **cada um dos 5 membros da equipe**.

---

## 👥 Resumo de Impacto por Integrante

| Integrante | Área | Impacto da Autenticação | Ação Necessária |
|---|---|---|---|
| **Luis Eduardo** | Backend Cliente & Auth | **Alto** | Configurar CORS com credenciais, `statefulApi()`, sessões no banco e suporte dual no `AuthController`. |
| **Fabio Matheus** | Front-end React | **Alto** | Configurar `withCredentials: true` no Axios, handshake `/sanctum/csrf-cookie` e migrar `AuthContext`. |
| **Marcelo Rezende** | Pagamentos MP | **Médio / Crítico** | **Atenção:** Webhook do Mercado Pago (`api/v1/webhooks/*`) **deve** ser isento de CSRF para não tomar erro 419. |
| **Erick Montassier** | Backend Admin | **Baixo** | O middleware `EnsureUserIsAdmin` funciona idêntico nos 2 modos. Manter Bearer para testes no Postman. |
| **Ryan Rangel** | DevOps & Docker | **Médio** | Padronizar variáveis de ambiente de sessão, portas de container e HTTPS com `SameSite=none` no deploy. |

---

## 1. 👨‍💻 Luis Eduardo — Backend Cliente & Núcleo de Auth

### O que você já fez (Semana 1 — Concluído):
* ✅ Migrations estruturais e tokens Sanctum criados.
* ✅ Model `User.php` blindada com casts de criptografia LGPD (AES-256 via `APP_KEY` no CPF e telefone) e senhas em `hashed` (Argon2id).
* ✅ Form Requests (`RegisterRequest`, `LoginRequest`) com validação estrita.
* ✅ `AuthController.php` com expiração de token em 7 dias (`now()->addDays(7)`).
* ✅ Suíte de testes automatizados (`tests/Feature/AuthTest.php`) passando com 100% de sucesso.

### O que você precisará ajustar na virada para Cookie HttpOnly:

#### A. Arquivo `config/cors.php`:
Habilitar tráfego de cookies entre portas/domínios:
```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_origins' => [
    'http://localhost:5173', // React em desenvolvimento
    'https://app.coturnos.com.br', // Produção
],
'supports_credentials' => true, // ← OBRIGATÓRIO: permite envio de cookies
```

#### B. Arquivo `bootstrap/app.php`:
Registrar o middleware do Sanctum SPA e **manter os Webhooks do Marcelo isentos de CSRF**:
```php
->withMiddleware(function (Middleware $middleware) {
    // Registra o middleware SPA do Sanctum (valida X-XSRF-TOKEN)
    $middleware->statefulApi();

    // Isenção de CSRF: CRÍTICO para os Webhooks do Mercado Pago
    $middleware->validateCsrfTokens(except: [
        'api/v1/webhooks/*', // Webhooks externos não possuem CSRF token!
    ]);
})
```

#### C. Arquivo `.env`:
Configurar sessões no banco de dados para permitir auditoria e revogação:
```env
SESSION_DRIVER=database
SESSION_LIFETIME=10080
SESSION_ENCRYPT=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173
```

#### D. Tabela `sessions`:
```bash
php artisan session:table
php artisan migrate
```

#### E. Login Social OAuth (Semana 2):
Ao receber o callback do Google/Apple, a rota de redirect para o front deve continuar repassando o token provisório para evitar bloqueios de *third-party cookies*:
```php
return redirect("http://localhost:5173/auth/callback?token={$token}");
```

---

## 2. 🎨 Fabio Matheus — Front-end Loja & Admin

### O que o Fabio faz agora (Modo Atual - Sprint):
* O Fabio consome o contrato que entregamos: `{ "message": "...", "user": {...}, "token": "..." }`.
* Salva o token no `localStorage` e usa o interceptor do Axios:
  ```typescript
  api.interceptors.request.use(config => {
      const token = localStorage.getItem('token');
      if (token) config.headers.Authorization = `Bearer ${token}`;
      return config;
  });
  ```

### O que o Fabio fará na virada para Cookie HttpOnly (Produção):
1. **Configuração global do Axios (`src/services/api.ts`):**
   ```typescript
   const api = axios.create({
       baseURL: import.meta.env.VITE_API_URL,
       withCredentials: true, // ← Permite que o navegador envie/receba cookies automaticamente
   });
   ```
2. **Handshake CSRF antes do login:**
   O Axios precisa buscar o token anti-CSRF do Laravel antes de disparar o login:
   ```typescript
   // 1. O Laravel seta o cookie XSRF-TOKEN no navegador
   await api.get('/sanctum/csrf-cookie');

   // 2. O Axios lê o cookie e envia no header X-XSRF-TOKEN automaticamente
   const response = await api.post('/api/v1/auth/login', credentials);
   ```
3. **`AuthContext.tsx` sem `localStorage`:**
   * Remover todas as leituras e gravações de `localStorage.getItem('token')`.
   * Para checar se o cliente continua logado ao recarregar a página (F5):
     ```typescript
     useEffect(() => {
         api.get('/api/v1/auth/me')
             .then(res => setUser(res.data.user))
             .catch(() => setUser(null));
     }, []);
     ```

---

## 3. 💳 Marcelo Rezende — Pagamentos Mercado Pago

### Pontos de Atenção Críticos para o Marcelo:

#### ⚠️ 1. O Webhook NÃO PODE ter CSRF (Risco de Quebra do Fluxo Financeiro):
* O Mercado Pago envia notificações de pagamento aprovado direto do servidor deles (`mercadopago.com`) para:
  `POST /api/v1/webhooks/mercadopago`
* Como o Mercado Pago não é um navegador e não tem o cookie CSRF da loja, **se a rota estiver protegida por CSRF, o Laravel responderá HTTP 419**.
* **Impacto:** O webhook falharia, o pagamento seria concluído no Mercado Pago mas o pedido no e-commerce ficaria eternamente como "pendente", **sem dar baixa no estoque**.
* **Garantia:** O Luis já configurou a rota `api/v1/webhooks/*` na lista de exceções do CSRF. O Marcelo valida a autenticidade pelo cabeçalho `x-signature` com HMAC-SHA256.

#### 2. Geração da Preferência de Checkout Pro (`Semana 2`):
* Endpoint `POST /api/v1/checkout/preferencia`.
* Esta rota é protegida por `auth:sanctum`.
* Funciona de forma **transparente** nos dois modos:
  * Com Bearer Token: o Marcelo lê `$request->user()` normalmente.
  * Com Cookie HttpOnly: o Sanctum autentica pela sessão e o `$request->user()` é injetado da mesma forma.

---

## 4. 📦 Erick Montassier — Backend Admin & Catálogo

### Como a autenticação impacta o Erick:

#### 1. Middleware `EnsureUserIsAdmin.php` (`Semana 2`):
* O Erick valida as permissões de acesso ao catálogo e estoque com:
  ```php
  if (! $request->user() || ! $request->user()->isAdmin()) {
      return response()->json(['error' => 'Acesso restrito a administradores.'], 403);
  }
  ```
* **Impacto Zero:** Como o `User.php` implementado pelo Luis possui o método `$user->isAdmin()`, a lógica do Erick funcionará de forma idêntica tanto com Bearer Token quanto com Cookie HttpOnly.

#### 2. Testes de Endpoints Admin no Postman / Insomnia:
* Com o modo Bearer Token, o Erick pode fazer login como admin, copiar o token gerado e colá-lo no Postman para testar o cadastro de produtos (`POST /api/v1/admin/produtos`), upload de imagens Cloudinary e métricas da dashboard sem atritos com cookies.

---

## 5. 🐳 Ryan Rangel — DevOps & Infraestrutura Docker Sail

### O que o Ryan precisa garantir no ambiente:

#### 1. Ambiente Local (Docker Sail):
* O container Sail roda na porta `80` ou `8000`. O front do Fabio roda na porta `5173`.
* O Ryan precisa garantir que o container PHP tenha a extensão `pdo_sqlite` (para testes rápidos) e `pdo_mysql` + `redis` ativas.
* As permissões de escrita na pasta `storage/framework/sessions` devem estar liberadas para o usuário `sail` (`chmod -R 775 storage`).

#### 2. Ambiente de Produção (Deploy Oficial do Cliente):
Quando o cliente contratar a hospedagem final (ex: AWS, DigitalOcean, VPS ou Hostinger):
* **Domínio Unificado:** Front e Back devem compartilhar o mesmo domínio pai:
  * Front-end: `loja.coturnos.com.br`
  * API / Back-end: `api.coturnos.com.br`
* **HTTPS Obrigatório:** Para cookies HttpOnly funcionarem com `SameSite=none`, o certificado SSL/TLS (HTTPS) é **mandatório**. Sem HTTPS, os navegadores modernos descartam o cookie silenciosamente.
* **Variáveis no `.env` do servidor de produção:**
  ```env
  SESSION_DRIVER=database
  SESSION_DOMAIN=.coturnos.com.br
  SESSION_SECURE_COOKIE=true
  SESSION_SAME_SITE=none
  SANCTUM_STATEFUL_DOMAINS=loja.coturnos.com.br
  ```

---

## 📅 Linha do Tempo e Estratégia de Transição

```
┌──────────────────────────────────────────────┐
│ SPRINT ATUAL (Dias 1 a 30)                   │
│ Modo: Bearer Token (Sanctum + localStorage)  │
│ ├─ Zero atrito entre os 5 integrantes        │
│ ├─ Login e cadastro rápidos no React         │
│ ├─ Testes no Postman sem CSRF                │
│ └─ Criptografia LGPD ativa no banco (AES-256)│
└──────────────────────┬───────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────┐
│ FASE DE PRÉ-PRODUÇÃO (Deploy Final)          │
│ Modo: Cookie HttpOnly + CSRF                 │
│ ├─ Compra do domínio do cliente              │
│ ├─ Emissão de certificado SSL (HTTPS)        │
│ ├─ Ativação de supports_credentials no CORS  │
│ ├─ Ativação de withCredentials no Axios      │
│ └─ Imunidade total contra XSS                │
└──────────────────────────────────────────────┘
```

---

## 🏁 Conclusão
O backend construído pelo **Luis Eduardo** na branch `feat/auth-sanctum` foi arquitetado para ser **híbrido por design**: ele atende 100% às necessidades imediatas da equipe na Sprint sem criar bloqueios cruzados, e já contém toda a fundação necessária para a virada de chave em produção para o cliente real.
