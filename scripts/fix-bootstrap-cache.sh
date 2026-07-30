#!/usr/bin/env bash
# PeaseAPI Bootstrap 缓存修复脚本
# 专门解决 bootstrap/cache 中缓存文件包含错误路径导致
# "ReflectionException: Class 'view' does not exist" 的问题
#
# 根本原因：
#   本地开发执行 php artisan optimize 后生成的 bootstrap/cache/*.php
#   包含本地绝对路径（如 /Users/.../resources/views），
#   这些文件被上传到生产服务器后路径无效，导致 ViewServiceProvider
#   无法注册，容器解析 'view' 抽象时抛出 ReflectionException。
#
# 用法：
#   bash scripts/fix-bootstrap-cache.sh          # 清除缓存（不重新生成）
#   bash scripts/fix-bootstrap-cache.sh --rebuild # 清除后重新生成缓存

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

info()  { echo -e "${BLUE}[i]${NC} $1"; }
pass()  { echo -e "${GREEN}[✓]${NC} $1"; }
warn()  { echo -e "${YELLOW}[!]${NC} $1"; }
fail()  { echo -e "${RED}[✗]${NC} $1"; }

echo -e "${BLUE}═══════════════════════════════════════════════${NC}"
echo -e "${BLUE}  PeaseAPI Bootstrap 缓存修复工具${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════${NC}"
echo ""

cd "$PROJECT_ROOT"

# 检测 PHP 是否可用
if ! command -v php &>/dev/null; then
    fail "未找到 php 可执行文件"
    exit 1
fi

# 检测是否存在 open_basedir 限制（宝塔环境常见）
OBD=$(php -r "echo ini_get('open_basedir');" 2>/dev/null || echo "")
PHP_CMD="php"
if [ -n "$OBD" ]; then
    warn "检测到 open_basedir 限制: $OBD"
    warn "将使用 php -d open_basedir= 绕过限制执行 artisan 命令"
    PHP_CMD="php -d open_basedir="
    echo ""
fi

# ============================================================
# 步骤 1：清除 bootstrap/cache 中的缓存文件
# ============================================================
info "【步骤 1】清除 bootstrap/cache 缓存文件..."

CACHE_DIR="$PROJECT_ROOT/bootstrap/cache"
CACHE_FILES=(
    "config.php"
    "services.php"
    "packages.php"
    "routes.php"
    "routes-v7.php"
    "events.php"
    "compiled.php"
)

REMOVED_COUNT=0
for fname in "${CACHE_FILES[@]}"; do
    fpath="$CACHE_DIR/$fname"
    if [ -f "$fpath" ]; then
        rm -f "$fpath"
        pass "已删除: bootstrap/cache/$fname"
        REMOVED_COUNT=$((REMOVED_COUNT + 1))
    fi
done

if [ "$REMOVED_COUNT" -eq 0 ]; then
    pass "bootstrap/cache 中无缓存文件需要清除"
else
    pass "已清除 $REMOVED_COUNT 个缓存文件"
fi
echo ""

# ============================================================
# 步骤 2：清除视图编译缓存
# ============================================================
info "【步骤 2】清除视图编译缓存..."

VIEWS_CACHE="$PROJECT_ROOT/storage/framework/views"
if [ -d "$VIEWS_CACHE" ]; then
    # 删除编译的视图文件（保留目录）
    find "$VIEWS_CACHE" -type f -name "*.php" -delete 2>/dev/null || true
    pass "已清除 storage/framework/views 中的编译视图"
else
    mkdir -p "$VIEWS_CACHE"
    pass "已创建 storage/framework/views 目录"
fi
echo ""

# ============================================================
# 步骤 3：通过 artisan optimize:clear 彻底清除所有缓存
# ============================================================
info "【步骤 3】执行 artisan optimize:clear..."

if $PHP_CMD artisan optimize:clear 2>&1; then
    pass "artisan optimize:clear 完成"
else
    warn "artisan optimize:clear 执行出错（可能数据库未配置，可忽略）"
fi
echo ""

# ============================================================
# 步骤 4（可选）：重新生成缓存
# ============================================================
MODE="${1:-}"
if [ "$MODE" = "--rebuild" ]; then
    echo -e "${BLUE}【步骤 4】重新生成缓存...${NC}"

    info "执行 artisan config:cache..."
    if $PHP_CMD artisan config:cache 2>&1; then
        pass "配置缓存已重新生成（使用当前服务器路径）"
    else
        fail "artisan config:cache 失败"
        warn "请检查 .env 配置和数据库连接"
    fi
    echo ""

    info "执行 artisan route:cache..."
    if $PHP_CMD artisan route:cache 2>&1; then
        pass "路由缓存已重新生成"
    else
        warn "artisan route:cache 失败（路由中可能存在闭包，可忽略）"
    fi
    echo ""

    info "执行 artisan view:cache..."
    if $PHP_CMD artisan view:cache 2>&1; then
        pass "视图缓存已重新生成"
    else
        warn "artisan view:cache 失败（可忽略，运行时按需编译）"
    fi
    echo ""

    info "执行 artisan event:cache..."
    if $PHP_CMD artisan event:cache 2>&1; then
        pass "事件缓存已重新生成"
    else
        warn "artisan event:cache 失败（可忽略）"
    fi
else
    info "跳过缓存重新生成（如需重新生成，请使用 --rebuild 参数）"
    info "  bash scripts/fix-bootstrap-cache.sh --rebuild"
fi
echo ""

# ============================================================
# 验证
# ============================================================
echo -e "${BLUE}【验证】检查 bootstrap/cache 状态...${NC}"
REMAINING=$(find "$CACHE_DIR" -maxdepth 1 -type f -name "*.php" 2>/dev/null | wc -l | tr -d ' ')
if [ "$REMAINING" -eq 0 ]; then
    pass "bootstrap/cache 已清空（仅保留 .gitignore）"
else
    warn "bootstrap/cache 中仍有 $REMAINING 个 .php 文件:"
    find "$CACHE_DIR" -maxdepth 1 -type f -name "*.php" | sed 's/^/    /'
fi
echo ""

echo -e "${GREEN}═══════════════════════════════════════════════${NC}"
echo -e "${GREEN}  修复完成！${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════${NC}"
echo ""
echo -e "${YELLOW}后续操作建议：${NC}"
echo "  1. 重启 PHP-FPM 使变更生效："
echo "     systemctl restart php-fpm"
echo "     # 或宝塔面板：软件商店 -> PHP -> 重启"
echo ""
echo "  2. 清除浏览器/CDN 缓存后访问网站验证"
echo ""
echo "  3. 如需启用缓存提升性能（推荐生产环境）："
echo "     bash scripts/fix-bootstrap-cache.sh --rebuild"
echo ""
echo -e "${YELLOW}防止问题再次发生：${NC}"
echo "  - 部署时切勿将本地 bootstrap/cache/*.php 上传到服务器"
echo "  - .gitignore 已正确忽略这些文件，使用 git 部署不会包含"
echo "  - 如使用 rsync/scp 部署，请排除 bootstrap/cache/*.php"
echo "    rsync -av --exclude='bootstrap/cache/*.php' ./ user@server:/path/"