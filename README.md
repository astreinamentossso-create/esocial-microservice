# Microserviço eSocial - ALA SST

Microserviço PHP que utiliza a biblioteca `sped-esocial` para assinar digitalmente e enviar eventos eSocial via mTLS.

## Requisitos

- Docker e Docker Compose (recomendado)
- Ou: PHP 8.1+, Composer, extensões: soap, openssl, curl, xml

## Deploy Rápido (Oracle Cloud Always Free / VPS)

### 1. Copie os arquivos para o servidor

```bash
scp -r esocial-microservice/ user@seu-servidor:/opt/esocial/
```

### 2. Configure as variáveis de ambiente

```bash
cd /opt/esocial
cp .env.example .env
nano .env
```

Defina:
- `API_TOKEN`: Token secreto para autenticação (gere com: `openssl rand -hex 32`)
- `ESOCIAL_ENV`: `restricted_production` (homologação) ou `production`

### 3. Inicie com Docker

```bash
docker-compose up -d
```

### 4. Verifique se está rodando

```bash
curl http://localhost:8080/health
```

### 5. (Opcional) Proxy reverso com Caddy para HTTPS

Instale o Caddy:
```bash
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update && sudo apt install caddy
```

Configure o Caddyfile:
```
esocial.seudominio.com {
    reverse_proxy localhost:8080
}
```

```bash
sudo systemctl restart caddy
```

## Endpoints

### `GET /health`
Health check. Sem autenticação.

### `POST /esocial/submit`
Envia um evento eSocial.

Headers: `Authorization: Bearer <API_TOKEN>`

Body (JSON):
```json
{
  "event_type": "S-2210",
  "event_data": { ... },
  "cnpj": "12345678000190",
  "certificate": "<base64 do arquivo .pfx>",
  "certificate_password": "senha_do_certificado",
  "environment": "restricted_production"
}
```

### `POST /esocial/status`
Consulta status de um protocolo.

Body (JSON):
```json
{
  "protocol": "PROT123...",
  "cnpj": "12345678000190",
  "certificate": "<base64 do arquivo .pfx>",
  "certificate_password": "senha_do_certificado",
  "environment": "restricted_production"
}
```

## Segurança

- O `API_TOKEN` protege o microserviço contra acesso não autorizado
- Certificados são recebidos via base64 e usados temporariamente (nunca persistidos no servidor)
- A senha do certificado é transmitida via HTTPS (configure o proxy reverso)
