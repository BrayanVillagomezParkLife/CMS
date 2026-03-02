#!/bin/bash
# ============================================================
# setup.sh — Park Life Properties
# Ejecutar UNA VEZ en tu máquina local antes de subir al servidor
# ============================================================

echo "🏡 Park Life Properties — Setup inicial"
echo "========================================="

# 1. Verificar que estamos en el directorio correcto
if [ ! -f "composer.json" ]; then
    echo "❌ Error: ejecuta este script desde la raíz del proyecto (v1.1/)"
    exit 1
fi

# 2. Verificar PHP
if ! command -v php &> /dev/null; then
    echo "❌ PHP no encontrado. Instala PHP 8.2+"
    exit 1
fi

PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
echo "✅ PHP $PHP_VERSION detectado"

# 3. Verificar / instalar Composer
if ! command -v composer &> /dev/null; then
    echo "⬇️  Instalando Composer..."
    curl -sS https://getcomposer.org/installer | php
    mv composer.phar /usr/local/bin/composer
fi
echo "✅ Composer $(composer --version --no-ansi 2>/dev/null | head -1)"

# 4. Instalar dependencias
echo ""
echo "📦 Instalando dependencias PHP..."
composer install --no-dev --optimize-autoloader

# 5. Crear directorios necesarios
echo ""
echo "📁 Creando directorios..."
mkdir -p logs
mkdir -p pics/uploads
mkdir -p pics/propiedades

# 6. Permisos
chmod 755 logs
chmod 755 pics/uploads

echo ""
echo "✅ Setup completado!"
echo ""
echo "📋 Próximos pasos:"
echo "   1. Copia includes/_config.template.php → includes/_config.local.php"
echo "   2. Completa tus credenciales en includes/config.php"
echo "   3. Importa parklife_2026.sql en tu MySQL"
echo "   4. Ejecuta: php -S localhost:8000"
echo "   5. Abre: http://localhost:8000"
echo ""
echo "🚀 Para producción:"
echo "   - Cambia APP_ENV='production' en config.php"
echo "   - Cambia timezone de '-06:00' a 'America/Mexico_City' en db.php"
echo "   - Sube la carpeta completa al servidor"
echo "   - Verifica que el vendor/ esté incluido"
