#!/bin/bash
set -e

echo "🚀 Configurando ambiente Laravel + Flux UI..."

# ============================================================
# Composer: instalar dependências
# ============================================================
if [ -f "composer.json" ]; then
    echo "📦 Instalando dependências do Composer..."

    # Configurar autenticação do Flux Pro (se licença disponível)
    if [ -n "$FLUX_EMAIL" ] && [ -n "$FLUX_LICENSE_KEY" ]; then
        echo "🔑 Configurando autenticação Flux Pro..."
        composer config http-basic.composer.fluxui.dev "$FLUX_EMAIL" "$FLUX_LICENSE_KEY"
        echo "✅ Flux Pro autenticado com sucesso"
    fi

    # Fallback: usar COMPOSER_AUTH se definido
    if [ -n "$COMPOSER_AUTH" ]; then
        echo "$COMPOSER_AUTH" > auth.json
        echo "✅ auth.json criado via COMPOSER_AUTH"
    fi

    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# ============================================================
# NPM: instalar dependências frontend
# ============================================================
if [ -f "package.json" ]; then
    echo "📦 Instalando dependências NPM..."
    npm install
fi

# ============================================================
# Laravel: setup inicial
# ============================================================
if [ -f "artisan" ]; then
    echo "⚙️  Configurando Laravel..."

    # Copiar .env se não existir
    if [ ! -f ".env" ]; then
        if [ -f ".env.devcontainer" ]; then
            cp .env.devcontainer .env
        elif [ -f ".env.example" ]; then
            cp .env.example .env
        fi
    fi

    # Gerar app key se necessário
    php artisan key:generate --force --no-interaction 2>/dev/null || true

    # Aguardar PostgreSQL e rodar migrations
    echo "⏳ Aguardando PostgreSQL..."
    sleep 3
    php artisan migrate --force --no-interaction 2>/dev/null || echo "⚠️  Migrations pendentes (rode manualmente)"

    # Storage link
    php artisan storage:link 2>/dev/null || true

    # Cache de config/rotas
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear
fi

echo ""
echo "============================================"
echo "✅ Ambiente pronto!"
echo "============================================"
echo ""
echo "  🌐 Laravel:    php artisan serve --host=0.0.0.0"
echo "  ⚡ Vite:       npm run dev -- --host 0.0.0.0"
echo "  🤖 Claude:     claude"
echo "  🗄️  PostgreSQL: postgres:5432 (laravel/secret)"
echo "  📮 Redis:      redis:6379"
echo ""
