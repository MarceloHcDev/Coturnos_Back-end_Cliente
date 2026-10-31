# Contrato de Integração — Recuperação de Senha (OWASP A07)
## Desbloqueio D2: Endpoints para o Front-End (Fabio Matheus)
**Repositório:** `Coturnos-Cia_Back-end_Cliente`  
**Endpoints:** `/api/v1/auth/esqueci-senha` e `/api/v1/auth/redefinir-senha`  
**Status:** Oficial da Sprint_13-10 (Etapa 1)

---

## 1. POST /api/v1/auth/esqueci-senha

Solicita o envio do e-mail de recuperação de senha com link seguro contendo token criptográfico temporário (expiração: 60 minutos).

### Autenticação
Rota pública (não requer token Bearer / Sanctum).

### Rate Limiting
- **Limite:** Máximo de **3 requisições por minuto** por IP/e-mail (`throttle:3,1`).
- **Excedente:** Retorna `429 Too Many Requests`.

### Request

**Endpoint:** `POST /api/v1/auth/esqueci-senha`

**Headers**
```http
Content-Type: application/json
Accept: application/json
```

**Body**

| Campo | Tipo | Obrigatório | Descrição |
| :--- | :--- | :--- | :--- |
| `email` | string | Sim | Endereço de e-mail do cliente cadastrado (formato válido RFC). |

**Exemplo de Payload:**
```json
{
  "email": "cliente@exemplo.com.br"
}
```

### Responses

#### 🟢 200 OK (Sucesso — Resposta Genérica Anti-Account Enumeration)
> 🛡️ **Regra de Segurança OWASP A07:**  
> Por motivos de segurança contra ataques de enumeração de contas (*User Enumeration*), a API **SEMPRE** retornará HTTP 200 com a mesma mensagem amigável, mesmo se o e-mail não existir na base de dados ou a conta estiver inativa.

```json
{
  "message": "Se o e-mail informado estiver cadastrado em nosso sistema, um link seguro para redefinição de senha foi enviado."
}
```

#### 🟡 422 Unprocessable Entity (Falha de Validação de Formato)
```json
{
  "message": "Por favor, informe um endereço de e-mail válido.",
  "errors": {
    "email": [
      "Por favor, informe um endereço de e-mail válido."
    ]
  }
}
```

#### 🔴 429 Too Many Requests (Excesso de Tentativas)
```json
{
  "message": "Muitas tentativas. Tente novamente em breve."
}
```
*Header retornado:* `Retry-After: 60`

---

## 2. POST /api/v1/auth/redefinir-senha

Valida o token temporário recebido via e-mail e atualiza a senha do cliente, invalidando o token após o uso e revogando todas as sessões ativas.

### Autenticação
Rota pública (não requer token Bearer / Sanctum).

### Rate Limiting
- **Limite:** Máximo de **5 requisições por minuto** por IP (`throttle:5,1`).

### Request

**Endpoint:** `POST /api/v1/auth/redefinir-senha`

**Headers**
```http
Content-Type: application/json
Accept: application/json
```

**Body**

| Campo | Tipo | Obrigatório | Descrição |
| :--- | :--- | :--- | :--- |
| `token` | string | Sim | Token de 64 caracteres recebido no parâmetro da URL do e-mail. |
| `email` | string | Sim | E-mail do cliente (recebido no parâmetro da URL do e-mail). |
| `password` | string | Sim | Nova senha forte: mín. 8 caracteres, maiúsculas, minúsculas, números e símbolos. |
| `password_confirmation` | string | Sim | Confirmação idêntica da nova senha. |

**Exemplo de Payload:**
```json
{
  "token": "a1b2c3d4e5f67890123456789abcdef0123456789abcdef0123456789abcdef0",
  "email": "cliente@exemplo.com.br",
  "password": "SenhaSegura@2026",
  "password_confirmation": "SenhaSegura@2026"
}
```

### Responses

#### 🟢 200 OK (Senha Redefinida com Sucesso)
```json
{
  "message": "Sua senha foi redefinida com sucesso! Você já pode realizar o login com as novas credenciais."
}
```

#### 🟡 422 Unprocessable Entity (Token Inválido ou Expirado)
```json
{
  "message": "Este link de redefinição expirou. Por favor, solicite um novo."
}
```
ou
```json
{
  "message": "Token de redefinição inválido ou não encontrado."
}
```

#### 🟡 422 Unprocessable Entity (Senha Fraca ou Divergente)
```json
{
  "message": "A confirmação de senha não confere.",
  "errors": {
    "password": [
      "The password field must contain at least one uppercase and one lowercase letter.",
      "The password field must contain at least one symbol."
    ]
  }
}
```

---

## 3. Guia de Integração para o Front-end React (Fabio Matheus)

### A. Tela de Login / Modal 'Esqueci minha senha' (`LoginCadastro.tsx`)
1. Adicionar o link *"Esqueci minha senha"* abaixo do campo de senha.
2. Ao clicar, abrir modal solicitando apenas o e-mail cadastrado.
3. Submeter via `POST /api/v1/auth/esqueci-senha` enviando `{ "email": email }`.
4. Ao receber `HTTP 200`, exibir toast/alerta de sucesso: *"Verifique sua caixa de entrada (ou Mailpit em desenvolvimento) para redefinir sua senha."* e fechar o modal.

### B. Tela de Redefinição (`ResetPassword.tsx`)
1. A rota no React Router deve ser `/redefinir-senha`.
2. Ao carregar a página, capturar os search params da URL:
   ```ts
   const [searchParams] = useSearchParams();
   const token = searchParams.get('token') || '';
   const email = searchParams.get('email') || '';
   ```
3. Se `token` ou `email` estiverem ausentes, exibir mensagem de link inválido e redirecionar para a tela de login.
4. O formulário deve apresentar os campos:
   - Nova Senha
   - Confirmar Nova Senha
5. No submit, enviar `POST /api/v1/auth/redefinir-senha` com:
   ```json
   {
     "token": token,
     "email": email,
     "password": password,
     "password_confirmation": passwordConfirmation
   }
   ```
6. Ao receber `HTTP 200`, redirecionar o cliente para `/login` com toast de sucesso.

