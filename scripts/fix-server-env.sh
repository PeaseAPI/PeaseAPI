#!/usr/bin/env bash
# PeaseAPI 服务器环境修复脚本
# 专门解决宝塔面板 open_basedir 导致 CLI/Composer/Artisan 无法执行的问题
#
# 用法：
#   bash scripts/fix-server-env.sh           # 检测 + 显示修复方案
#   bash scripts/fix-server-env.sh --composer # 临时绕过 open_basedir 执行 composer install
#   bash scripts/fix-server-env.sh --artisan  # 临时绕过 open_basedir 执行 artisan 命令
#   bash scripts/fix-server-env.sh --fix      # 尝试永久修复 open_basedir 配置

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
echo -e "${BLUE}  PeaseAPI 服务器环境修复工具${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════${NC}"
echo ""

# ============================================================
# 检测当前 open_basedir
# ============================================================
detect_obd() {
    local obd
    obd=$(php -r "echo ini_get('open_basedir');" 2>/dev/null || echo "")
    echo "$obd"
}

# 检测 PHP CLI ini 文件
detect_ini() {
    echo "PHP CLI 配置文件位置："
    php --ini 2>/dev/null | grep -E "Loaded Configuration|Scan for" | while read -r line; do
        echo "  $line"
    done
}

# 临时绕过 open_basedir 执行 PHP 命令
exec_php_bypass() {
    local cmd="$1"
    shift
    # -d open_basedir= 临时清空 open_basedir 限制
    php -d open_basedir= $cmd "$@"
}

# ============================================================
# 模式选择
# ============================================================
MODE="${1:-detect}"

case "$MODE" in
    --composer)
        # ============================================================
        # 临时绕过执行 composer install
        # ============================================================
        info "使用临时绕过方式执行 composer install..."
        info "（通过 php -d open_basedir= 禁用限制，不改配置文件）"
        echo ""

        cd "$PROJECT_ROOT"

        # 查找 composer 路径
        COMPOSER_BIN=$(command -v composer 2>/dev/null || echo "/usr/bin/composer")
        if [ ! -f "$COMPOSER_BIN" ]; then
            # 尝试查找
            COMPOSER_BIN=$(find /usr/local/bin /usr/bin /www/server/php/*/bin -name "composer" 2>/dev/null | head -1)
        fi

        if [ -z "$COMPOSER_BIN" ] || [ ! -f "$COMPOSER_BIN" ]; then
            fail "未找到 composer，请先安装："
            echo "  curl -sS https://getcomposer.org/installer | php"
            echo "  mv composer.phar /usr/local/bin/composer"
            exit 1
        fi

        info "Composer 路径: $COMPOSER_BIN"
        info "执行: php -d open_basedir= $COMPOSER_BIN install --no-dev --optimize-autoloader"
        echo ""

        php -d open_basedir= "$COMPOSER_BIN" install --no-dev --optimize-autoloader
        RESULT=$?

        echo ""
        if [ $RESULT -eq 0 ]; then
            pass "composer install 完成！"
        else
            fail "composer install 失败（退出码: $RESULT）"
        fi
        ;;

    --artisan)
        # ============================================================
        # 临时绕过执行 artisan 命令
        # ============================================================
        shift
        ARTISAN_CMD="${*:-list}"

        info "使用临时绕过方式执行: php artisan $ARTISAN_CMD"
        info "（通过 php -d open_basedir= 禁用限制，不改配置文件）"
        echo ""

        cd "$PROJECT_ROOT"
        php -d open_basedir= artisan $ARTISAN_CMD
        RESULT=$?

        echo ""
        if [ $RESULT -eq 0 ]; then
            pass "artisan $ARTISAN_CMD 完成！"
        else
            fail "artisan $ARTISAN_CMD 失败（退出码: $RESULT）"
        fi
        ;;

    --fix)
        # ============================================================
        # 永久修复 open_basedir
        # ============================================================
        echo -e "${BLUE}【永久修复 open_basedir】${NC}"
        echo ""

        OBD=$(detect_obd)
        info "当前 open_basedir: ${OBD:-（未设置）}"
        info "项目目录: $PROJECT_ROOT"
        echo ""

        if [ -z "$OBD" ]; then
            pass "open_basedir 未设置，无需修复"
            exit 0
        fi

        # 检查项目目录是否已在允许路径中
        PARENT_DIR=$(dirname "$PROJECT_ROOT")
        if echo "$OBD" | grep -q "$PARENT_DIR"; then
            pass "项目目录已在 open_basedir 允许范围内"
            echo ""
            info "但 composer/artisan 仍报错？可能是 composer 本身不在允许路径。"
            info "建议使用 --composer / --artisan 模式临时绕过，或关闭 open_basedir。"
            exit 0
        fi

        echo -e "${YELLOW}项目目录不在 open_basedir 允许范围内，需要修复。${NC}"
        echo ""

        # 检测配置来源
        echo -e "${BLUE}检测 open_basedir 配置来源...${NC}"
        detect_ini
        echo ""

        # 方案1：修改 .user.ini（影响 PHP-FPM）
        USER_INI="$PROJECT_ROOT/.user.ini"
        echo -e "${BLUE}方案1：修改 .user.ini（影响 Web/PHP-FPM）${NC}"
        if [ -f "$USER_INI" ]; then
            info "发现 .user.ini: $USER_INI"
            if grep -q "open_basedir" "$USER_INI"; then
                warn "当前 .user.ini 中的 open_basedir 配置："
                grep "open_basedir" "$USER_INI" | sed 's/^/    /'
                echo ""
                read -r -p "是否修正 .user.ini 中的 open_basedir？[y/N]: " confirm
                if [[ "$confirm" =~ ^[Yy]$ ]]; then
                    sed -i "s|open_basedir=.*|open_basedir=${PARENT_DIR}/:/tmp/:/proc/|g" "$USER_INI"
                    pass "已修正 .user.ini"
                    info "需重启 PHP-FPM 生效"
                fi
            else
                pass ".user.ini 中无 open_basedir 配置"
            fi
        else
            info ".user.ini 不存在"
        fi
        echo ""

        # 方案2：修改 php.ini（影响 CLI + FPM）
        echo -e "${BLUE}方案2：修改 php.ini（影响 CLI + PHP-FPM）${NC}"
        CLI_INI=$(php --ini 2>/dev/null | grep "Loaded Configuration" | awk -F': ' '{print $2}' | xargs)
        if [ -n "$CLI_INI" ] && [ -f "$CLI_INI" ]; then
            info "CLI php.ini: $CLI_INI"
            if grep -q "^open_basedir" "$CLI_INI" || grep -q "^;open_basedir" "$CLI_INI"; then
                warn "php.ini 中存在 open_basedir 配置："
                grep -n "open_basedir" "$CLI_INI" | sed 's/^/    /'
                echo ""
                echo -e "${YELLOW}  修复方法（手动执行）：${NC}"
                echo "  方式A - 注释掉（完全关闭 open_basedir）："
                echo "    sed -i 's/^open_basedir=/;open_basedir=/' \"$CLI_INI\""
                echo ""
                echo "  方式B - 设置正确路径："
                echo "    sed -i 's|open_basedir=.*|open_basedir=${PARENT_DIR}/:/tmp/:/proc/|' \"$CLI_INI\""
                echo ""
                read -r -p "选择修复方式 [A=关闭 / B=设路径 / N=跳过]: " fixchoice
                case $fixchoice in
                    [Aa])
                        sed -i 's/^open_basedir=/;open_basedir=/' "$CLI_INI"
                        pass "已注释掉 php.ini 中的 open_basedir"
                        info "需重启 PHP-FPM 生效"
                        ;;
                    [Bb])
                        sed -i "s|open_basedir=.*|open_basedir=${PARENT_DIR}/:/tmp/:/proc/|" "$CLI_INI"
                        pass "已设置 php.ini 中的 open_basedir 为正确路径"
                        info "需重启 PHP-FPM 生效"
                        ;;
                    *)
                        info "跳过 php.ini 修改"
                        ;;
                esac
            else
                pass "php.ini 中无 open_basedir 配置"
                info "open_basedir 可能来自宝塔面板的其他配置"
            fi
        else
            warn "无法定位 CLI php.ini"
        fi
        echo ""

        # 方案3：宝塔面板操作
        echo -e "${BLUE}方案3：宝塔面板操作（如果以上无效）${NC}"
        if [ -d "/www/server/panel" ]; then
            warn "检测到宝塔面板环境"
            echo -e "${YELLOW}  请在宝塔面板操作：${NC}"
            echo "  1. 宝塔面板 -> 网站 -> www.peaseapi.com -> 设置"
            echo "  2. 点击「防跨站攻击 open_basedir」"
            echo "  3. 将路径改为：${PARENT_DIR}/:/tmp/:/proc/"
            echo "     或直接关闭「防跨站攻击」开关"
            echo "  4. 同时检查「配置文件」（Nginx）中是否有 fastcgi_param PHP_ADMIN_VALUE open_basedir"
            echo "  5. 保存后重启 PHP-FPM"
        else
            info "非宝塔环境，请检查 Nginx 配置中的 fastcgi_param PHP_ADMIN_VALUE"
        fi
        echo ""

        # 检测 mbstring 重复加载
        echo -e "${BLUE}【检测 mbstring 重复加载】${NC}"
        PHP_M_OUTPUT=$(php -m 2>&1)
        if echo "$PHP_M_OUTPUT" | grep -qi "already loaded"; then
            warn "检测到扩展重复加载："
            echo "$PHP_M_OUTPUT" | grep -i "already loaded" | sed 's/^/    /'
            echo ""
            echo -e "${YELLOW}  修复方法：${NC}"
            echo "  1. php --ini  # 查看 ini 文件位置"
            echo "  2. grep -rn 'mbstring' /www/server/php/*/etc/  # 宝塔"
            echo "  3. 确保 mbstring 只在一个 ini 文件中加载"
        else
            pass "未检测到扩展重复加载"
        fi
        ;;

    *)
        # ============================================================
        # 默认：检测模式
        # ============================================================
        echo -e "${BLUE}【检测 open_basedir 状态】${NC}"
        OBD=$(detect_obd)
        if [ -z "$OBD" ]; then
            pass "open_basedir 未设置（无限制）"
        else
            warn "当前 open_basedir: $OBD"
            PARENT_DIR=$(dirname "$PROJECT_ROOT")
            if echo "$OBD" | grep -q "$PARENT_DIR"; then
                pass "项目目录在允许范围内"
            else
                fail "项目目录不在允许范围内！"
                echo ""
                echo -e "${RED}  这就是 composer / artisan 无法执行的根本原因。${NC}"
                echo ""
                echo -e "${YELLOW}═══════════════════════════════════════════════${NC}"
                echo -e "${YELLOW}  立即可用的临时方案（不改配置）：${NC}"
                echo -e "${YELLOW}  ───────────────────────────────────────────${NC}"
                echo "  1. 临时执行 composer install："
                echo "     php -d open_basedir= /usr/bin/composer install --no-dev --optimize-autoloader"
                echo ""
                echo "  2. 临时执行 artisan 命令："
                echo "     php -d open_basedir= artisan optimize:clear"
                echo "     php -d open_basedir= artisan config:cache"
                echo ""
                echo -e "${YELLOW}  永久修复方案：${NC}"
                echo -e "${YELLOW}  ───────────────────────────────────────────${NC}"
                echo "  运行本脚本的 --fix 模式："
                echo "     bash scripts/fix-server-env.sh --fix"
                echo ""
                echo "  或在宝塔面板操作："
                echo "     网站 -> www.peaseapi.com -> 设置 -> 防跨站攻击 open_basedir"
                echo "     将路径改为包含项目目录，或关闭该功能"
                echo "     然后重启 PHP-FPM"
            fi
        fi
        echo ""

        # 检测 mbstring 重复加载
        echo -e "${BLUE}【检测 mbstring 重复加载】${NC}"
        PHP_M_OUTPUT=$(php -m 2>&1)
        if echo "$PHP_M_OUTPUT" | grep -qi "already loaded"; then
            warn "检测到扩展重复加载："
            echo "$PHP_M_OUTPUT" | grep -i "already loaded" | sed 's/^/    /'
            echo ""
            echo -e "${YELLOW}  修复方法：${NC}"
            echo "  1. php --ini  # 查看 ini 文件位置"
            echo "  2. grep -rn 'mbstring' /www/server/php/*/etc/  # 宝塔"
            echo "  3. 确保 mbstring 只在一个 ini 文件中加载"
        else
            pass "未检测到扩展重复加载"
        fi
        echo ""

        # 检测 git 状态
        echo -e "${BLUE}【检测 Git 状态】${NC}"
        if [ -d "$PROJECT_ROOT/.git" ]; then
            cd "$PROJECT_ROOT"
            if [ -n "$(git status --porcelain 2>/dev/null)" ]; then
                warn "有未提交的本地修改："
                git status --short 2>/dev/null | head -10 | sed 's/^/    /'
                echo ""
                echo -e "${YELLOW}  git pull 前需处理：${NC}"
                echo "     git checkout -- .          # 丢弃所有本地修改"
                echo "     git stash                   # 暂存本地修改"
            else
                pass "工作区干净"
            fi
        else
            info "非 Git 仓库，跳过"
        fi
        ;;

    --help|-h|*)
        echo "用法："
        echo "  bash scripts/fix-server-env.sh              # 检测环境问题"
        echo "  bash scripts/fix-server-env.sh --composer   # 临时绕过 open_basedir 执行 composer install"
        echo "  bash scripts/fix-server-env.sh --artisan <cmd>  # 临时绕过执行 artisan 命令"
        echo "  bash scripts/fix-server-env.sh --fix        # 永久修复 open_basedir 配置"
        echo ""
        echo "示例："
        echo "  bash scripts/fix-server-env.sh --composer"
        echo "  bash scripts/fix-server-env.sh --artisan optimize:clear"
        echo "  bash scripts/fix-server-env.sh --artisan migrate --force"
        echo "  bash scripts/fix-server-env.sh --artisan config:cache"
        ;;
esac

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════${NC}"
echo -e "${BLUE}  完成${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════${NC}"
