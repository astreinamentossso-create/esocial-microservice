FROM php:8.2-cli

WORKDIR /app/public

# Instalar dependências do sistema
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libxml2-dev \
    libcurl4-openssl-dev \
    libssl-dev \
    && docker-php-ext-install xml soap curl \
    && rm -rf /var/lib/apt/lists/*

# Copiar composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copiar apenas arquivos do composer primeiro (evita cache errado)
COPY composer.json composer.lock ./

# Instalar dependências
RUN composer install --no-dev --optimize-autoloader

# Agora copia o resto do projeto
COPY . .

# Expor porta
EXPOSE 8080

# Rodar servidor PHP
CMD php -S 0.0.0.0:8080