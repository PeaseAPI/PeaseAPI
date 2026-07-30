#!/usr/bin/env bash
# PeaseAPI 500 错误一键修复脚本
# 专治宝塔面板环境下 "ReflectionException: Class 'view' does not exist"
#
# 根本原因：
#   bootstrap/cache/*.php 缓存文件包含本地开发机的绝对路径，
#   上传到服务器后路径无效，导致 ViewServiceProvider 无法注册，
#   容器解析 'view' 抽象时抛出 ReflectionException。
#
# 用法：
#   bash scripts/fix-500-error.sh
#
set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

info()  { echo -e "${BLUE}[i]${NC} $1"; }
pass()  { echo -e "${GREEN}[✓]${NC} $1"; }
warn()  { echo -e "${YELLOW}[!]${NC} $1"; }
fail()  { echo -e "${RED}[✗]${NC} $1"; }

echo -e "${BLUE}═══════════════════════════════════════════════${NC}"
echo -e "${BLUE}  PeaseAPI 500 错误一键修复工具${NC}"
echo -e "${BLUE}  (Class 'view' does not exist)${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════${NC}"
echo ""

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

cd "$PROJECT_ROOT"

# 检测 PHP
if ! command -v php &>/dev/null; then
    fail "未找到 php 可执行文件"
    exit 1
fi

PHP_VERSION=$(php -r "echo PHP_VERSION;")
info "PHP 版本: ${PHP_VERSION}"

# 检测 open_basedir 限制
OBD=$(php -r "echo ini_get('open_basedir');" 2>/dev/null || echo "")
PHP_CMD="php"
if [ -n "$OBD" ]; then
    warn "检测到 open_basedir 限制: $OBD"
    warn "将使用 php -d open_basedir= 绕过限制"
    PHP_CMD="php -d open_basedir="
    echo ""
fi

# ============================================================
# 步骤 1：删除 bootstrap/cache 下的所有缓存文件
# ============================================================
info "【步骤 1/5】清除 bootstrap/cache 缓存文件..."

CACHE_DIR="$PROJECT_ROOT/bootstrap/cache"
REMOVED=0
if [ -d "$CACHE_DIR" ]; then
    find "$CACHE_DIR" -mindepth 1 -maxdepth 1 ! -name '.gitignore' -print0 | while IFS= read -r -d '' fpath; do
        rm -rf "$fpath"
        pass "已删除: $(basename "$fpath")"
        REMOVED=$((REMOVED + 1))
    done
fi

mkdir -p "$CACHE_DIR"

if [ "$REMOVED" -eq 0 ]; then
    pass "bootstrap/cache 中无缓存文件"
else
    pass "已清除 $REMOVED 个缓存文件"
fi
echo ""

# ============================================================
# 步骤 2：清除视图编译缓存
# ============================================================
info "【步骤 2/5】清除视图编译缓存..."

VIEWS_CACHE="$PROJECT_ROOT/storage/framework/views"
if [ -d "$VIEWS_CACHE" ]; then
    find "$VIEWS_CACHE" -type f -name "*.php" -delete 2>/dev/null || true
    pass "已清除 storage/framework/views 编译缓存"
else
    mkdir -p "$VIEWS_CACHE"
    pass "已创建 storage/framework/views 目录"
fi
echo ""

# ============================================================
# 步骤 3：确保关键运行时目录存在且可写
# ============================================================
info "【步骤 3/5】检查运行时目录..."

RUNTIME_DIRS=(
    "storage/framework/views"
    "storage/framework/cache"
    "storage/framework/cache/data"
    "storage/framework/sessions"
    "storage/logs"
    "bootstrap/cache"
)

for dir in "${RUNTIME_DIRS[@]}"; do
    fullpath="$PROJECT_ROOT/$dir"
    if [ ! -d "$fullpath" ]; then
        mkdir -p "$fullpath"
        pass "已创建: $dir/"
    fi
    chmod -R 755 "$fullpath" 2>/dev/null || true
done

# 尝试设置目录所有权（需要 root 权限）
if [ "$(id -u)" -eq 0 ]; then
    # 获取 PHP-FPM 运行用户
    WWW_USER=$(php -r "echo posix_getpwuid(posix_geteuid())['name'];" 2>/dev/null || echo "www")
    chown -R "$WWW_USER:$WWW_USER" "$PROJECT_ROOT/storage" "$PROJECT_ROOT/bootstrap/cache" 2>/dev/null || true
    pass "已设置目录所有权为 $WWW_USER"
else
    warn "非 root 用户，跳过所有权设置"
    warn "如需设置所有权，请以 root 身份运行：sudo bash scripts/fix-500-error.sh"
fi
echo ""

# ============================================================
# 步骤 4：清除 OPcache（通过 CLI 尝试）
# ============================================================
info "【步骤 4/5】尝试清除 OPcache..."

# CLI 下清除 OPcache（可能不会影响 FPM 进程的 OPcache）
$PHP_CMD -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'OPcache cleared (CLI)'; } else { echo 'OPcache not available'; }" 2>/dev/null || true
echo ""

# 提示重启 PHP-FPM
warn "重要：CLI 清除不影响 PHP-FPM 进程的 OPcache"
warn "请在宝塔面板中重启 PHP-FPM："
warn "  软件商店 -> PHP -> 设置 -> 重启"
echo ""

# ============================================================
# 步骤 5：尝试执行 artisan optimize:clear
# ============================================================
info "【步骤 5/5】执行 artisan optimize:clear..."

if $PHP_CMD artisan optimize:clear 2>&1; then
    pass "artisan optimize:clear 完成"
else
    warn "artisan optimize:clear 执行出错（可能 .env 未配置，可忽略）"
fi
echo ""

# ============================================================
# 验证
# ============================================================
echo -e "${BLUE}【验证】检查修复结果...${NC}"

REMAINING=$(find "$CACHE_DIR" -maxdepth 1 -type f -name "*.php" 2>/dev/null | wc -l | tr -d ' ')
if [ "$REMAINING" -eq 0 ]; then
    pass "bootstrap/cache 已清空（仅保留 .gitignore）"
else
    warn "bootstrap/cache 中仍有 $REMAINING 个 .php 文件"
fi

# 检查 storage 目录可写
if [ -w "$PROJECT_ROOT/storage" ]; then
    pass "storage 目录可写"
else
    fail "storage 目录不可写！请执行：chmod -R 755 storage && chown -R www:www storage"
fi

if [ -w "$PROJECT_ROOT/bootstrap/cache" ]; then
    pass "bootstrap/cache 目录可写"
else
    fail "bootstrap/cache 目录不可写！请执行：chmod -R 755 bootstrap/cache && chown -R www:www bootstrap/cache"
fi
echo ""

echo -e "${GREEN}═══════════════════════════════════════════════${NC}"
echo -e "${GREEN}  修复完成！${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════${NC}"
echo ""
echo -e "${YELLOW}必须执行的操作：${NC}"
echo "  1. 重启 PHP-FPM（清除 OPcache）："
echo "     宝塔面板：软件商店 -> PHP -> 设置 -> 重启"
echo "     或命令行：systemctl restart php-fpm"
echo ""
echo "  2. 清除浏览器/CDN 缓存后访问网站"
echo ""
echo -e "${YELLOW}如果仍然 500：${NC}"
echo "  1. 检查 .env 文件是否存在且配置正确"
echo "  2. 执行诊断脚本：bash scripts/server-diagnose.sh"
echo "  3. 检查宝塔 open_basedir 设置是否限制了项目目录"
echo ""
echo -e "${YELLOW}安装步骤：${NC}"
echo "  1. cd /www/wwwroot/www.peaseapi.com/PeaseAPI"
echo "  2. composer install --no-dev --optimize-autoloader"
echo "  3. php artisan pease:install"
echo "  4. 访问 http://你的域名/install 进入安装向导"