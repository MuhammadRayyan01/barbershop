
# Stage 1: Build Tailwind CSS
FROM node:20-alpine AS frontend-build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build:css


# Stage 2: Run PHP application
FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . .

# Copy generated Tailwind CSS
COPY --from=frontend-build /app/assets/css/tailwind.css ./assets/css/tailwind.css

# Configure Apache permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
