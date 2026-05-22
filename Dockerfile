FROM php:8.2-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    sqlite3 \
    libsqlite3-dev \
    sudo \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite module
RUN a2enmod rewrite

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_sqlite

# Directorio de trabajo
WORKDIR /opt/chilemon

# Copiar los archivos de la aplicación para que no dependa solo de volumenes (Proxmox)
COPY . /opt/chilemon/

# Copiar el wrapper simulado (mock)
COPY .docker/chilemon-rpt.sh /usr/local/bin/chilemon-rpt
RUN chmod +x /usr/local/bin/chilemon-rpt

# Configure sudo for www-data (to run the wrapper without password)
RUN echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/chilemon-rpt" > /etc/sudoers.d/chilemon

# Configure Apache document root to public/
ENV APACHE_DOCUMENT_ROOT /opt/chilemon/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!/opt/chilemon/!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Set permissions for data and logs
RUN mkdir -p data logs \
    && chown -R www-data:www-data data logs

# Expose port 80
EXPOSE 80

CMD ["apache2-foreground"]
