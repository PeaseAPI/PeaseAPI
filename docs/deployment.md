# PeaseAPI 部署文档

本文档详细说明 PeaseAPI 的三种部署方式：独立服务器部署、宝塔面板部署、Docker 部署。

---

## 目录

- [环境要求](#环境要求)
- [独立服务器部署](#独立服务器部署)
- [宝塔面板部署](#宝塔面板部署)
- [Docker 部署](#docker-部署)
- [Nginx 配置参考](#nginx-配置参考)
- [队列与定时任务](#队列与定时任务)
- [SSL/HTTPS 配置](#sslhttps-配置)
- [性能优化](#性能优化)
- [升级与备份](#升级与备份)
- [故障排查](#故障排查)

---

## 环境要求

### 最低配置

| 组件 | 最低版本 | 说明 |
|------|---------|------|
| PHP | 8.2 | 需安装 CLI + FPM |
| MySQL | 8.0 | 或 MariaDB 10.6+ |
| Redis | 6.0 | 推荐 7.0+ |
| Nginx | 1.18 | 或 Apache 2.4+ |
| Composer | 2.6 | 依赖管理 |

### 推荐服务器配置

| 规模 | CPU | 内存 | 磁盘 | 带宽 |
|------|-----|------|------|------|
| 小型（<100 用户） | 2 核 | 4 GB | 40 GB SSD | 10 Mbps |
| 中型（100-1000 用户） | 4 核 | 8 GB | 80 GB SSD | 50 Mbps |
| 大型（>1000 用户） | 8 核+ | 16 GB+ | 200 GB SSD | 100 Mbps+ |

### 必需的 PHP 扩展

```
php8.2-fpm php8.2-mysql php8.2-redis php8.2-gmp php8.2-mbstring
php8.2-xml php8.2-curl php8.2-zip php8.2-fileinfo php8.2-openssl php8.2-bcmath
```

---

## 独立服务器部署

适用于 Ubuntu 22.04/24.04、Debian 12、CentOS 9 等 Linux 发行版。

### 第一步：安装系统依赖

#### Ubuntu / Debian

```bash
# 更新系统
sudo apt update && sudo apt upgrade -y

# 添加 PHP 8.2 仓库（Ubuntu 22.04）
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# 安装 PHP 8.2 及扩展
sudo apt install -y php8.2-fpm php8.2-cli php8.2-mysql php8.2-redis \
  php8.2-gmp php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip \
  php8.2-fileinfo php8.2-bcmath php8.2-intl

# 安装 Nginx、MySQL、Redis
sudo apt install -y nginx mysql-server redis-server
```

#### CentOS / RHEL / Rocky Linux

```bash
# 安装 EPEL 和 Remi 仓库
sudo dnf install -y epel-release
sudo dnf install -y https://rpms.remirepo.net/enterprise/remi-release-9.rpm
sudo dnf module reset php -y
sudo dnf module enable php:remi-8.2 -y

# 安装 PHP 8.2 及扩展
sudo dnf install -y php-fpm php-cli php-mysqlnd php-pecl-redis5 \
  php-gmp php-mbstring php-xml php-curl php-zip php-fileinfo \
  php-bcmath php-intl php-opcache

# 安装 Nginx、MySQL、Redis
sudo dnf install -y nginx mysql-server redis
```

### 第二步：配置 MySQL

```bash
# 启动 MySQL 并设置开机自启
sudo systemctl start mysql
sudo systemctl enable mysql

# 安全初始化
sudo mysql_secure_installation

# 创建数据库和用户
sudo mysql -u root -p
```

```sql
CREATE DATABASE peaseapi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'peaseapi'@'localhost' IDENTIFIED BY '你的强密码';
GRANT ALL PRIVILEGES ON peaseapi.* TO 'peaseapi'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 第三步：配置 Redis

```bash
# 启动 Redis 并设置开机自启
sudo systemctl start redis-server   # Ubuntu/Debian
sudo systemctl enable redis-server

# 或 CentOS
sudo systemctl start redis
sudo systemctl enable redis

# 设置 Redis 密码（推荐）
sudo nano /etc/redis/redis.conf
# 找到 # requirepass foobared，取消注释并修改：
# requirepass 你的Redis密码

sudo systemctl restart redis-server
```

### 第四步：配置 PHP-FPM

```bash
# 编辑 PHP-FPM 配置
sudo nano /etc/php/8.2/fpm/php.ini
```

修改以下配置项：

```ini
memory_limit = 256M
upload_max_filesize = 20M
post_max_size = 20M
max_execution_time = 300
max_input_time = 300
; 去掉注释启用 OPcache
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 10000
opcache.revalidate_freq = 2
```

```bash
# 重启 PHP-FPM
sudo systemctl restart php8.2-fpm
sudo systemctl enable php8.2-fpm
```

### 第五步：获取项目代码

```bash
# 创建网站目录
sudo mkdir -p /var/www/peaseapi
sudo chown $USER:$USER /var/www/peaseapi

# 克隆项目
cd /var/www
git clone https://github.com/peaseapi/peaseapi.git peaseapi

# 安装 Composer（如未安装）
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# 安装 PHP 依赖
cd /var/www/peaseapi
composer install --no-dev --optimize-autoloader
```

### 第六步：配置 Nginx

```bash
sudo nano /etc/nginx/sites-available/peaseapi
```

配置内容见 [Nginx 配置参考](#nginx-配置参考)，创建软链并测试：

```bash
sudo ln -s /etc/nginx/sites-available/peaseapi /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### 第七步：初始化 PeaseAPI

```bash
# 设置目录权限
cd /var/www/peaseapi
sudo chown -R www-data:www-data .
sudo chmod -R 755 .
sudo chmod -R 775 storage bootstrap/cache

# 复制环境配置
cp .env.example .env

# 方式一：Web 安装向导（推荐）
# 浏览器访问 http://你的服务器IP/install 按向导完成

# 方式二：命令行安装
php artisan pease:install
```

### 第八步：配置队列与定时任务

```bash
# 配置定时任务
sudo crontab -u www-data -e
# 添加以下行：
* * * * * cd /var/www/peaseapi && php artisan schedule:run >> /dev/null 2>&1
```

配置 Supervisor 管理队列进程：

```bash
sudo apt install -y supervisor
sudo nano /etc/supervisor/conf.d/peaseapi-worker.conf
```

```ini
[program:peaseapi-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/peaseapi/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/peaseapi/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start peaseapi-worker:*
```

---

## 宝塔面板部署

适合国内用户，全程图形化操作，无需命令行。

### 第一步：安装宝塔面板

```bash
# Ubuntu/Debian
wget -O install.sh https://download.bt.cn/install/install-ubuntu_6.0.sh && sudo bash install.sh ed8484bec

# CentOS
yum install -y wget && wget -O install.sh https://download.bt.cn/install/install_6.0.sh && sh install.sh ed8484bec
```

安装完成后，记录面板地址、账号、密码，登录宝塔面板。

### 第二步：安装运行环境

在宝塔面板首页，选择 **LNMP 环境**：

1. **Nginx**：选择 1.24 或最新稳定版，编译安装
2. **MySQL**：选择 8.0 或以上
3. **PHP**：选择 8.2
4. **Redis**：在 **软件商店** 中搜索 Redis，安装 7.x 版本

#### 安装 PHP 扩展

进入 **软件商店 -> PHP 8.2 -> 设置 -> 安装扩展**，确保以下扩展已安装：

- `fileinfo`（必装）
- `redis`（必装）
- `gmp`（必装，Passkey 需要）
- `mbstring`
- `openssl`
- `bcmath`
- `curl`
- `zip`

#### 解禁禁用函数

进入 **PHP 8.2 -> 设置 -> 禁用函数**，确保以下函数**未被禁用**（PeaseAPI 已尽量减少依赖，但建议确保）：

- `proc_open`（Supervisor/队列可能需要）
- `putenv`（Composer 可能需要）
- `shell_exec`（可选）

> 💡 **注意**：PeaseAPI 的 Web 安装向导和 `pease:install` 命令**不依赖** `proc_open`/`putenv`，可在宝塔默认安全配置下直接运行。仅在使用 Composer 安装依赖或运行队列时可能需要。

### 第三步：创建数据库

1. 进入 **数据库 -> 添加数据库**
2. 数据库名：`peaseapi`
3. 用户名：`peaseapi`
4. 密码：设置强密码
5. 访问权限：**本地服务器**
6. 字符集：`utf8mb4`

### 第四步：创建网站

1. 进入 **网站 -> 添加站点**
2. 域名：填写你的域名（如 `api.example.com`）
3. 根目录：`/www/wwwroot/peaseapi`
4. PHP 版本：**PHP 8.2**
5. 数据库：不创建（已在第三步创建）

### 第五步：上传项目代码

#### 方式一：Git 克隆（推荐）

在宝塔面板进入 **终端**（左侧菜单），执行：

```bash
cd /www/wwwroot
git clone https://github.com/peaseapi/peaseapi.git peaseapi
cd peaseapi
composer install --no-dev --optimize-autoloader
```

#### 方式二：上传压缩包

1. 在本地下载项目 zip 包
2. 宝塔面板 **文件** 功能上传到 `/www/wwwroot/`
3. 解压并重命名为 `peaseapi`

### 第六步：配置运行目录

1. 进入 **网站 -> peaseapi -> 设置 -> 网站目录**
2. 运行目录：选择 `/public`
3. 点击 **保存**

### 第七步：设置权限

在宝塔终端执行：

```bash
cd /www/wwwroot/peaseapi
chown -R www:www .
chmod -R 755 .
chmod -R 775 storage bootstrap/cache
```

### 第八步：配置伪静态

进入 **网站 -> peaseapi -> 设置 -> 伪静态**，粘贴：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

点击 **保存**。

### 第九步：初始化安装

#### 方式一：Web 安装向导（推荐）

1. 浏览器访问 `http://你的域名/install`
2. 按向导填写数据库信息、Redis 信息
3. 创建管理员账号
4. 完成安装

#### 方式二：命令行安装

在宝塔终端执行：

```bash
cd /www/wwwroot/peaseapi
cp .env.example .env
php artisan pease:install
```

按提示输入数据库、Redis、管理员信息。

### 第十步：配置 SSL

进入 **网站 -> peaseapi -> SSL -> Let's Encrypt**，申请免费 SSL 证书，开启强制 HTTPS。

### 第十一步：配置定时任务

进入 **计划任务 -> 添加任务**：

- 任务类型：**Shell 脚本**
- 任务名称：`PeaseAPI 定时任务`
- 执行周期：**每分钟**
- 脚本内容：

```bash
cd /www/wwwroot/peaseapi && php artisan schedule:run >> /dev/null 2>&1
```

### 第十二步：配置队列守护进程

宝塔面板安装 **Supervisor管理器**（软件商店搜索）：

1. 安装 Supervisor 管理器
2. 添加守护进程：
   - **名称**：`peaseapi-worker`
   - **启动用户**：`www`
   - **运行目录**：`/www/wwwroot/peaseapi`
   - **启动命令**：`php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600`
   - **进程数量**：`2`
3. 点击 **启动**

---

## Docker 部署

最快部署方式，适合容器化环境。

### 方式一：Docker Compose（推荐）

#### 1. 创建 `docker-compose.yml`

```yaml
version: '3.8'

services:
  app:
    image: peaseapi/peaseapi:latest
    container_name: peaseapi-app
    restart: always
    ports:
      - "8080:80"
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - APP_URL=https://api.example.com
      - DB_HOST=mysql
      - DB_PORT=3306
      - DB_DATABASE=peaseapi
      - DB_USERNAME=peaseapi
      - DB_PASSWORD=peaseapi_secret_password
      - REDIS_HOST=redis
      - REDIS_PORT=6379
      - REDIS_PASSWORD=
      - QUEUE_CONNECTION=redis
      - SESSION_DRIVER=redis
      - CACHE_STORE=redis
    volumes:
      - app-storage:/var/www/html/storage
      - app-public:/var/www/html/public
    depends_on:
      - mysql
      - redis
    networks:
      - peaseapi-network

  queue:
    image: peaseapi/peaseapi:latest
    container_name: peaseapi-queue
    restart: always
    command: php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
    environment:
      - APP_ENV=production
      - DB_HOST=mysql
      - DB_PORT=3306
      - DB_DATABASE=peaseapi
      - DB_USERNAME=peaseapi
      - DB_PASSWORD=peaseapi_secret_password
      - REDIS_HOST=redis
      - REDIS_PORT=6379
      - QUEUE_CONNECTION=redis
      - SESSION_DRIVER=redis
      - CACHE_STORE=redis
    volumes:
      - app-storage:/var/www/html/storage
    depends_on:
      - app
      - mysql
      - redis
    networks:
      - peaseapi-network

  scheduler:
    image: peaseapi/peaseapi:latest
    container_name: peaseapi-scheduler
    restart: always
    command: php artisan schedule:work
    environment:
      - APP_ENV=production
      - DB_HOST=mysql
      - DB_PORT=3306
      - DB_DATABASE=peaseapi
      - DB_USERNAME=peaseapi
      - DB_PASSWORD=peaseapi_secret_password
      - REDIS_HOST=redis
      - REDIS_PORT=6379
      - QUEUE_CONNECTION=redis
      - SESSION_DRIVER=redis
      - CACHE_STORE=redis
    volumes:
      - app-storage:/var/www/html/storage
    depends_on:
      - app
      - mysql
      - redis
    networks:
      - peaseapi-network

  mysql:
    image: mysql:8.0
    container_name: peaseapi-mysql
    restart: always
    environment:
      - MYSQL_ROOT_PASSWORD=root_secret_password
      - MYSQL_DATABASE=peaseapi
      - MYSQL_USER=peaseapi
      - MYSQL_PASSWORD=peaseapi_secret_password
    volumes:
      - mysql-data:/var/lib/mysql
    ports:
      - "3306:3306"
    networks:
      - peaseapi-network

  redis:
    image: redis:7-alpine
    container_name: peaseapi-redis
    restart: always
    ports:
      - "6379:6379"
    volumes:
      - redis-data:/data
    networks:
      - peaseapi-network

  nginx:
    image: nginx:alpine
    container_name: peaseapi-nginx
    restart: always
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./nginx/conf.d:/etc/nginx/conf.d
      - ./nginx/ssl:/etc/nginx/ssl
      - app-public:/var/www/html/public
    depends_on:
      - app
    networks:
      - peaseapi-network

volumes:
  app-storage:
  app-public:
  mysql-data:
  redis-data:

networks:
  peaseapi-network:
    driver: bridge
```

#### 2. 创建 Nginx 配置

创建 `nginx/conf.d/peaseapi.conf`：

```nginx
server {
    listen 80;
    server_name api.example.com;

    # 强制跳转 HTTPS（如有证书）
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name api.example.com;

    ssl_certificate     /etc/nginx/ssl/fullchain.pem;
    ssl_certificate_key /etc/nginx/ssl/privkey.pem;

    root /var/www/html/public;
    index index.php index.html;

    client_max_body_size 20M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

#### 3. 启动服务

```bash
# 创建目录
mkdir -p nginx/conf.d nginx/ssl

# 启动所有服务
docker compose up -d

# 查看状态
docker compose ps

# 查看日志
docker compose logs -f app
```

#### 4. 初始化安装

```bash
# 进入 app 容器执行安装
docker exec -it peaseapi-app php artisan pease:install
```

或浏览器访问 `http://服务器IP:8080/install` 使用 Web 向导。

### 方式二：Docker 单容器（开发/测试）

```bash
# 拉取镜像
docker pull peaseapi/peaseapi:latest

# 运行容器（使用外部 MySQL 和 Redis）
docker run -d \
  --name peaseapi \
  -p 8080:80 \
  -e DB_HOST=192.168.1.100 \
  -e DB_PORT=3306 \
  -e DB_DATABASE=peaseapi \
  -e DB_USERNAME=peaseapi \
  -e DB_PASSWORD=secret \
  -e REDIS_HOST=192.168.1.100 \
  -e REDIS_PORT=6379 \
  -v peaseapi-storage:/var/www/html/storage \
  peaseapi/peaseapi:latest
```

### 方式三：使用现有 Dockerfile 自行构建

项目根目录创建 `Dockerfile`：

```dockerfile
FROM php:8.2-fpm-alpine

# 安装系统依赖
RUN apk add --no-cache \
    nginx mysql-client redis \
    libpng-dev libjpeg-turbo-dev freetype-dev \
    libzip-dev oniguruma-dev gmp-dev \
    git curl

# 安装 PHP 扩展
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql gd zip mbstring bcmath gmp opcache pcntl

RUN pecl install redis && docker-php-ext-enable redis

# 安装 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 复制项目
WORKDIR /var/www/html
COPY . .

# 安装依赖
RUN composer install --no-dev --optimize-autoloader

# 设置权限
RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
```

```bash
docker build -t peaseapi-custom .
```

---

## Nginx 配置参考

### 标准生产环境配置

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name api.example.com;
    # 强制 HTTPS
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name api.example.com;

    # SSL 证书
    ssl_certificate     /etc/letsencrypt/live/api.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.example.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256;
    ssl_prefer_server_ciphers off;

    # 网站根目录
    root /var/www/peaseapi/public;
    index index.php index.html;

    # 上传大小限制
    client_max_body_size 20M;

    # 日志
    access_log /var/log/nginx/peaseapi-access.log;
    error_log  /var/log/nginx/peaseapi-error.log;

    # 主路由
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
        fastcgi_buffering off;  # SSE 流式响应需要
    }

    # 静态资源缓存
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # 禁止访问隐藏文件
    location ~ /\.(?!well-known).* {
        deny all;
    }

    # 禁止访问敏感目录
    location ~ ^/(storage|bootstrap/cache)/ {
        deny all;
    }

    # 健康检查
    location = /health {
        access_log off;
        return 200 "OK";
        add_header Content-Type text/plain;
    }
}
```

> ⚠️ **重要**：`fastcgi_buffering off;` 对于 SSE 流式响应（如 GPT 逐字输出）是必需的，否则流式效果会被缓冲破坏。

---

## 队列与定时任务

### 队列说明

PeaseAPI 使用 Laravel Queue 处理异步任务：

- **Midjourney 任务**：轮询上游任务状态
- **Suno 任务**：轮询音乐生成进度
- **视频任务**：轮询视频生成进度
- **订阅重置**：定时重置订阅配额
- **Coding Plan 重置**：滚动窗口配额重置
- **统计聚合**：定时统计聚合任务

### Supervisor 配置（生产环境）

生产环境必须使用 Supervisor 守护队列进程：

```ini
[program:peaseapi-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/peaseapi/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/peaseapi/storage/logs/worker.log
stopwaitsecs=3600
```

### 定时任务

Laravel Schedule 需要通过 Cron 每分钟执行：

```bash
* * * * * cd /var/www/peaseapi && php artisan schedule:run >> /dev/null 2>&1
```

定时任务包括：

| 任务 | 执行周期 | 说明 |
|------|---------|------|
| 订阅配额重置 | 每分钟检查 | 到期订阅配额重置 |
| Coding Plan 5h 窗口重置 | 每分钟检查 | 5 小时滚动窗口重置 |
| Coding Plan 周/月重置 | 每分钟检查 | 周/月窗口重置 |
| 日志清理 | 每日凌晨 | 清理过期日志 |
| 统计聚合 | 每小时 | 聚合用量数据 |

---

## SSL/HTTPS 配置

### Let's Encrypt 免费证书（独立服务器）

```bash
# 安装 Certbot
sudo apt install -y certbot python3-certbot-nginx

# 申请证书
sudo certbot --nginx -d api.example.com

# 自动续期（Certbot 默认已配置）
sudo certbot renew --dry-run
```

### 宝塔面板 SSL

1. 进入 **网站 -> peaseapi -> SSL**
2. 选择 **Let's Encrypt**
3. 点击 **申请**
4. 勾选 **强制 HTTPS**

### 手动配置 SSL

将证书文件放到服务器，在 Nginx 配置中指定：

```nginx
ssl_certificate     /path/to/fullchain.pem;
ssl_certificate_key /path/to/privkey.pem;
```

---

## 性能优化

### PHP OPcache

确保 `php.ini` 中 OPcache 已启用：

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.revalidate_freq=2
opcache.validate_timestamps=0  ; 生产环境设为 0，更新代码后需 reload
```

更新代码后重启 PHP-FPM：

```bash
sudo systemctl reload php8.2-fpm
```

### Laravel 缓存优化

生产环境部署后执行：

```bash
cd /var/www/peaseapi

# 配置缓存
php artisan config:cache

# 路由缓存
php artisan route:cache

# 视图缓存
php artisan view:cache

# 事件缓存
php artisan event:cache
```

> ⚠️ 更新 `.env` 后需重新执行 `php artisan config:cache`。

### Redis 优化

```ini
# redis.conf
maxmemory 512mb
maxmemory-policy allkeys-lru
```

### MySQL 优化

```ini
# my.cnf
innodb_buffer_pool_size = 1G    # 服务器内存的 50-70%
innodb_log_file_size = 256M
innodb_flush_log_at_trx_commit = 2
query_cache_size = 0            # MySQL 8.0 已移除查询缓存
```

---

## 升级与备份

### 备份

#### 数据库备份

```bash
# 手动备份
mysqldump -u peaseapi -p peaseapi > peaseapi_backup_$(date +%Y%m%d).sql

# 自动备份（Cron）
0 3 * * * mysqldump -u peaseapi -p'密码' peaseapi | gzip > /backup/peaseapi_$(date +\%Y\%m\%d).sql.gz
```

#### 文件备份

需要备份的目录：

- `/var/www/peaseapi/storage/` -- 用户上传、日志
- `/var/www/peaseapi/.env` -- 环境配置

```bash
tar -czf peaseapi_files_$(date +%Y%m%d).tar.gz /var/www/peaseapi/storage /var/www/peaseapi/.env
```

### 升级

```bash
cd /var/www/peaseapi

# 1. 备份
mysqldump -u peaseapi -p peaseapi > backup_before_upgrade.sql

# 2. 拉取最新代码
git pull origin main

# 3. 更新依赖
composer install --no-dev --optimize-autoloader

# 4. 执行数据库迁移
php artisan migrate --force

# 5. 清除并重建缓存
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6. 重启队列
sudo supervisorctl restart peaseapi-worker:*
sudo systemctl reload php8.2-fpm
```

---

## 故障排查

### 常见问题

#### 1. 访问首页 500 错误

```bash
# 检查日志
tail -f storage/logs/laravel.log

# 常见原因：
# - .env 未配置或 APP_KEY 未生成 -> php artisan key:generate
# - storage 目录权限不对 -> chmod -R 775 storage bootstrap/cache
# - 数据库连接失败 -> 检查 DB_* 配置
```

#### 2. 流式响应不生效（一次性返回）

检查 Nginx 配置中是否设置了 `fastcgi_buffering off;`。

#### 3. 队列不执行

```bash
# 检查 Supervisor 状态
sudo supervisorctl status

# 检查队列任务
php artisan queue:failed
php artisan queue:retry all

# 手动执行测试
php artisan queue:work redis --once
```

#### 4. 定时任务不执行

```bash
# 检查 Crontab
sudo crontab -u www-data -l

# 手动测试
cd /var/www/peaseapi && php artisan schedule:run
```

#### 5. Redis 连接失败

```bash
# 测试 Redis 连接
redis-cli -h 127.0.0.1 -p 6379 ping
# 应返回 PONG

# 检查 .env 中 REDIS_* 配置
```

#### 6. 宝塔面板 proc_open 被禁用

PeaseAPI 核心功能不依赖 `proc_open`。如需使用 Composer 或队列，在 **PHP 设置 -> 禁用函数** 中移除 `proc_open`、`putenv`。

#### 7. 权限问题

```bash
# 标准权限设置
cd /var/www/peaseapi
chown -R www-data:www-data .
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod -R 775 storage bootstrap/cache
```

#### 8. Composer 安装失败

```bash
# 清除缓存
composer clear-cache

# 忽略平台要求（如扩展缺失但实际可用）
composer install --ignore-platform-reqs --no-dev
```

### 获取帮助

- 📖 查看完整文档：[使用指南](usage-guide.md)
- 🐛 提交 Issue：[GitHub Issues](https://github.com/peaseapi/peaseapi/issues)
- 💬 社区讨论：[GitHub Discussions](https://github.com/peaseapi/peaseapi/discussions)
