#!/usr/bin/env bash
# PeaseAPI 服务器环境诊断脚本
# 用途：检测 git 冲突、open_basedir 配置、mbstring 重复加载等常见部署问题
# 用法：bash scripts/server-diagnose.sh [--fix]
#   不带参数：仅诊断，不修改任何文件
#   --fix    ：尝试自动修复可处理的问题（需 root 权限）

set -euo pipefail

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 项目根目录（脚本所在目录的上一级）
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

FIX_MODE=false
[[ "${1:-}" == "--fix" ]] && FIX_MODE=true

echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  PeaseAPI 服务器环境诊断${NC}"
echo -e "${BLUE}  项目路径: ${PROJECT_ROOT}${NC}"
echo -e "${BLUE}  修复模式: ${FIX_MODE}${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo ""

# 计数器
PASS=0
WARN=0
FAIL=0

pass() { echo -e "${GREEN}  [✓]${NC} $1"; ((PASS++)); }
warn() { echo -e "${YELLOW}  [!]${NC} $1"; ((WARN++)); }
fail() { echo -e "${RED}  [✗]${NC} $1"; ((FAIL++)); }
info() { echo -e "${BLUE}  [i]${NC} $1"; }

# ============================================================
# 1. Git 状态检测
# ============================================================
echo -e "${BLUE}【1/5】Git 状态检测${NC}"

if ! command -v git &>/dev/null; then
    fail "git 命令不可用"
else
    cd "$PROJECT_ROOT"

    if [ ! -d ".git" ]; then
        warn "当前目录不是 Git 仓库"
    else
        # 检查是否有未提交的本地修改
        if [ -n "$(git status --porcelain)" ]; then
            warn "检测到本地未提交的修改，可能导致 git pull 冲突："
            git status --short | head -20 | while read -r line; do
                echo -e "${YELLOW}       ${line}${NC}"
            done

            if $FIX_MODE; then
                echo ""
                info "修复模式：可选择处理本地修改"
                echo "  [1] 丢弃所有本地修改（git checkout -- .）"
                echo "  [2] 暂存本地修改（git stash）"
                echo "  [3] 不处理，手动解决"
                read -r -p "请选择 [1-3]: " choice
                case $choice in
                    1) git checkout -- . && pass "已丢弃本地修改" ;;
                    2) git stash && pass "已暂存本地修改（git stash pop 可恢复）" ;;
                    3) info "跳过，请手动处理" ;;
                    *) warn "无效选择，跳过" ;;
                esac
            else
                info "运行 --fix 模式可交互式处理，或手动执行：git checkout -- <file>"
            fi
        else
            pass "工作区干净，无未提交修改"
        fi

        # 检查与远程的差异
        if git remote | grep -q origin; then
            git fetch origin 2>/dev/null || true
            BRANCH=$(git branch --show-current 2>/dev/null || echo "main")
            if git rev-list --count "HEAD..origin/${BRANCH}" 2>/dev/null | grep -q '[1-9]'; then
                BEHIND=$(git rev-list --count "HEAD..origin/${BRANCH}" 2>/dev/null)
                warn "本地落后远程 ${BEHIND} 个提交，需要 git pull"
            else
                pass "本地与远程同步"
            fi
        fi
    fi
fi
echo ""

# ============================================================
# 2. open_basedir 检测
# ============================================================
echo -e "${BLUE}【2/5】open_basedir 配置检测${NC}"

CURRENT_DIR="$PROJECT_ROOT"
# 检测 PHP 是否可用
if ! command -v php &>/dev/null; then
    fail "php 命令不可用，无法检测 open_basedir"
else
    # 获取当前 PHP 的 open_basedir 设置
    OBD=$(php -r "echo ini_get('open_basedir');" 2>/dev/null || echo "")

    if [ -z "$OBD" ] || [ "$OBD" == "" ]; then
        pass "open_basedir 未设置（无限制）"
    else
        info "当前 open_basedir: ${OBD}"

        # 检查项目目录是否在允许路径内
        if echo "$OBD" | grep -q "$(dirname "$CURRENT_DIR")"; then
            pass "项目目录在 open_basedir 允许范围内"
        else
            fail "项目目录不在 open_basedir 允许范围内！"
            echo -e "${RED}       项目目录: ${CURRENT_DIR}${NC}"
            echo -e "${RED}       允许路径: ${OBD}${NC}"
            echo ""
            echo -e "${YELLOW}  修复方法：${NC}"

            # 检测宝塔环境
            if [ -f "/www/server/panel/class/panelPlugin.class.php" ] || [ -d "/www/server/panel" ]; then
                echo -e "${YELLOW}  [宝塔环境检测到]${NC}"
                echo "  1. 宝塔面板 -> 网站 -> 对应站点 -> 设置"
                echo "  2. 找到「防跨站攻击 open_basedir」或「配置文件」"
                echo "  3. 将路径改为包含项目目录："
                echo "     $(dirname "$CURRENT_DIR")/:/tmp/:/proc/"
                echo ""

                # 检查 .user.ini
                USER_INI="$PROJECT_ROOT/.user.ini"
                if [ -f "$USER_INI" ]; then
                    info "发现 .user.ini 文件: $USER_INI"
                    if grep -q "open_basedir" "$USER_INI"; then
                        warn ".user.ini 中已配置 open_basedir，但路径可能错误"
                        if $FIX_MODE; then
                            PARENT_DIR=$(dirname "$CURRENT_DIR")
                            read -r -p "是否自动修正 .user.ini 的 open_basedir？[y/N]: " confirm
                            if [[ "$confirm" =~ ^[Yy]$ ]]; then
                                sed -i "s|open_basedir=.*|open_basedir=${PARENT_DIR}/:/tmp/:/proc/|g" "$USER_INI"
                                pass "已修正 .user.ini 中的 open_basedir"
                                info "请重启 PHP-FPM 使配置生效"
                            fi
                        else
                            info "运行 --fix 模式可自动修正"
                        fi
                    fi
                fi
            else
                echo "  方法一：修改 php.ini"
                echo "    open_basedir = $(dirname "$CURRENT_DIR")/:/tmp/:/proc/"
                echo ""
                echo "  方法二：修改 Nginx 站点配置中的 fastcgi_param"
                echo "    fastcgi_param PHP_ADMIN_VALUE \"open_basedir=$(dirname "$CURRENT_DIR")/:/tmp/:/proc/\";"
                echo ""
                echo "  方法三：修改 .user.ini（项目根目录）"
                echo "    open_basedir=$(dirname "$CURRENT_DIR")/:/tmp/:/proc/"
            fi
            echo ""
            echo -e "${YELLOW}  修改后需重启 PHP-FPM：${NC}"
            echo "    systemctl restart php8.2-fpm   # systemd"
            echo "    /etc/init.d/php-fpm-82 restart # 宝塔"
        fi
    fi
fi
echo ""

# ============================================================
# 3. PHP 扩展重复加载检测
# ============================================================
echo -e "${BLUE}【3/5】PHP 扩展重复加载检测${NC}"

if ! command -v php &>/dev/null; then
    fail "php 命令不可用"
else
    # 检查 PHP 启动时的警告
    PHP_OUTPUT=$(php -m 2>&1)

    # 检测 "already loaded" 警告
    if echo "$PHP_OUTPUT" | grep -qi "already loaded"; then
        fail "检测到扩展重复加载警告："
        echo "$PHP_OUTPUT" | grep -i "already loaded" | while read -r line; do
            echo -e "${RED}       ${line}${NC}"
        done
        echo ""

        # 提取重复的模块名
        DUP_MODULES=$(echo "$PHP_OUTPUT" | grep -i "already loaded" | sed -n 's/.*Module "\([^"]*\)".*/\1/p' | sort -u)

        echo -e "${YELLOW}  修复方法：${NC}"
        echo "  1. 列出所有加载的 ini 文件："
        echo "     php --ini"
        echo ""
        echo "  2. 搜索重复加载的模块："

        for mod in $DUP_MODULES; do
            echo -e "${YELLOW}     模块 ${mod}:${NC}"
            echo "     grep -rn '${mod}' \$(php --ini | grep 'Loaded Configuration' | awk '{print \$NF}')"
            echo "     grep -rn '${mod}' /etc/php*/conf.d/ 2>/dev/null"
            echo "     grep -rn '${mod}' /www/server/php/*/etc/ 2>/dev/null  # 宝塔"
            echo ""
            echo "  确保该模块只在 php.ini 或 conf.d/*.ini 中的一个文件中加载。"
        done

        if $FIX_MODE; then
            info "扩展重复加载需手动定位并删除重复行，--fix 模式无法自动处理"
            info "请根据上方提示手动检查 ini 文件"
        fi
    else
        pass "未检测到扩展重复加载警告"
    fi
fi
echo ""

# ============================================================
# 4. 依赖与文件完整性检测
# ============================================================
echo -e "${BLUE}【4/5】依赖与文件完整性检测${NC}"

# 检查 vendor 目录
if [ ! -d "$PROJECT_ROOT/vendor" ]; then
    fail "vendor 目录不存在，请执行 composer install"
else
    if [ ! -f "$PROJECT_ROOT/vendor/autoload.php" ]; then
        fail "vendor/autoload.php 不存在，请执行 composer install"
    else
        pass "vendor/autoload.php 存在"
    fi
fi

# 检查 .env 文件
if [ ! -f "$PROJECT_ROOT/.env" ]; then
    warn ".env 文件不存在，请执行 cp .env.example .env && php artisan key:generate"
else
    pass ".env 文件存在"
fi

# 检查 storage 目录权限
if [ -d "$PROJECT_ROOT/storage" ]; then
    PERM=$(stat -c "%a" "$PROJECT_ROOT/storage" 2>/dev/null || stat -f "%A" "$PROJECT_ROOT/storage" 2>/dev/null || echo "unknown")
    if [ "$PERM" -ge 755 ] 2>/dev/null; then
        pass "storage 目录权限正常 (${PERM})"
    else
        warn "storage 目录权限可能不足 (${PERM})，建议 chmod -R 755 storage"
        if $FIX_MODE; then
            chmod -R 755 "$PROJECT_ROOT/storage" 2>/dev/null && pass "已修正 storage 目录权限" || warn "权限修正失败，请手动执行: chmod -R 755 storage"
        fi
    fi
else
    fail "storage 目录不存在"
fi

# 检查 bootstrap/cache 目录
if [ -d "$PROJECT_ROOT/bootstrap/cache" ]; then
    pass "bootstrap/cache 目录存在"
else
    fail "bootstrap/cache 目录不存在"
fi
echo ""

# ============================================================
# 5. artisan 命令可用性检测
# ============================================================
echo -e "${BLUE}【5/5】artisan 命令可用性检测${NC}"

if ! command -v php &>/dev/null; then
    fail "php 命令不可用，无法检测 artisan"
else
    cd "$PROJECT_ROOT"

    # 尝试执行 artisan
    ARTISAN_OUTPUT=$(php artisan list 2>&1) || true

    if echo "$ARTISAN_OUTPUT" | grep -qi "open_basedir"; then
        fail "artisan 执行失败：open_basedir 限制（请先修复第 2 项）"
    elif echo "$ARTISAN_OUTPUT" | grep -qi "already loaded"; then
        warn "artisan 可执行但有扩展重复加载警告（请修复第 3 项）"
    elif echo "$ARTISAN_OUTPUT" | grep -qi "Fatal error\|fatal error"; then
        fail "artisan 执行出现致命错误："
        echo "$ARTISAN_OUTPUT" | tail -5 | while read -r line; do
            echo -e "${RED}       ${line}${NC}"
        done
    elif echo "$ARTISAN_OUTPUT" | grep -q "Available commands"; then
        pass "artisan 命令可正常执行"
    else
        warn "artisan 输出异常，请手动检查: php artisan list"
    fi
fi
echo ""

# ============================================================
# 汇总
# ============================================================
echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  诊断汇总${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  通过: ${PASS}${NC}"
echo -e "${YELLOW}  警告: ${WARN}${NC}"
echo -e "${RED}  失败: ${FAIL}${NC}"
echo ""

if [ $FAIL -gt 0 ]; then
    echo -e "${RED}存在必须修复的问题，请按上方提示处理。${NC}"
    exit 1
elif [ $WARN -gt 0 ]; then
    echo -e "${YELLOW}存在警告项，建议处理以保证环境最佳。${NC}"
    exit 0
else
    echo -e "${GREEN}所有检测项通过！${NC}"
    exit 0
fi