
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
    libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_pgsql curl \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . .

# Copy generated Tailwind CSS
COPY --from=frontend-build /app/assets/css/tailwind.css ./assets/css/tailwind.css

# Configure Apache permissions
RUN chown -R www-data:www-data /var/www/html

# Expose Render's default port
EXPOSE 10000

# Configure Apache to use Render's PORT environment variable
CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT:-10000}/\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT:-10000}>/\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
