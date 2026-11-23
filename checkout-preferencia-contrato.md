# POST /api/v1/checkout/preferencia

Cria a preferência de pagamento no Mercado Pago para um pedido existente e retorna o `init_point` para redirecionar o cliente ao checkout.

## Autenticação
Requer usuário autenticado (`auth:sanctum`). Enviar o token no header:
```
Authorization: Bearer {token}
```

## Request

**Endpoint:** `POST /api/v1/checkout/preferencia`

**Headers**
```
Content-Type: application/json
Accept: application/json
```

**Body**

| Campo               | Tipo   | Obrigatório | Descrição                                  |
|---------------------|--------|--------------|---------------------------------------------|
| order_id            | int    | Sim          | Id do pedido (`orders.id`), deve pertencer ao usuário autenticado e estar com `status = pending` |
| back_urls.success   | string | Não          | URL de retorno em caso de pagamento aprovado |
| back_urls.failure   | string | Não          | URL de retorno em caso de pagamento rejeitado |
| back_urls.pending   | string | Não          | URL de retorno em caso de pagamento pendente |

**Exemplo**
```json
{
  "order_id": 42,
  "back_urls": {
    "success": "https://minhaloja.com/pedidos/42/sucesso",
    "failure": "https://minhaloja.com/pedidos/42/falha",
    "pending": "https://minhaloja.com/pedidos/42/pendente"
  }
}
```

## Responses

### 201 Created
Preferência criada com sucesso.
```json
{
  "order_id": 42,
  "preference_id": "123456789-abcdef01-2345-6789-abcd-ef0123456789",
  "init_point": "https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=123456789-abcdef01-2345-6789-abcd-ef0123456789"
}
```
Redirecionar o cliente para `init_point` para concluir o pagamento no Mercado Pago.

### 422 Unprocessable Entity
Erro de validação do request, ou pedido que não está `pending`.
```json
{
  "message": "Apenas pedidos pendentes podem gerar um pagamento."
}
```
Ou, em caso de campos inválidos, o formato padrão de validação do Laravel:
```json
{
  "message": "The order id field is required.",
  "errors": {
    "order_id": ["The order id field is required."]
  }
}
```

### 404 Not Found (Anti-IDOR)
`order_id` não existe ou não pertence ao usuário autenticado.

### 401 Unauthorized
Requisição sem token de autenticação válido.

### 502 Bad Gateway
Falha ao comunicar com a API do Mercado Pago.
```json
{
  "message": "Não foi possível criar a preferência de pagamento."
}
```

## Observações
- O valor cobrado é sempre `orders.total_amount`, calculado no backend — o front não envia valores.
- Ao gerar a preferência, o backend grava/atualiza um registro em `payments` vinculado ao pedido (`preference_id`, `init_point`, `status = pending`).
- Chamadas repetidas para o mesmo pedido atualizam a preferência existente em vez de criar uma nova.

