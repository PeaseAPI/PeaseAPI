<![CDATA[<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PeaseAPI 安装向导</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 720px;
            width: 100%;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            padding: 30px 40px;
            color: #fff;
            text-align: center;
        }
        .header h1 { font-size: 24px; font-weight: 600; margin-bottom: 8px; }
        .header p { color: rgba(255,255,255,0.7); font-size: 14px; }
        .steps {
            display: flex;
            padding: 20px 40px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }
        .step-item {
            flex: 1;
            text-align: center;
            position: relative;
            padding: 8px 0;
        }
        .step-item:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 18px;
            right: -50%;
            width: 100%;
            height: 2px;
            background: #dee2e6;
            z-index: 0;
        }
        .step-item.active:not(:last-child)::after { background: #667eea; }
        .step-item.done:not(:last-child)::after { background: #28a745; }
        .step-num {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: #dee2e6;
            color: #6c757d;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            position: relative;
            z-index: 1;
            transition: all 0.3s;
        }
        .step-item.active .step-num { background: #667eea; color: #fff; }
        .step-item.done .step-num { background: #28a745; color: #fff; }
        .step-label { font-size: 12px; color: #6c757d; margin-top: 6px; }
        .step-item.active .step-label { color: #667eea; font-weight: 600; }
        .step-item.done .step-label { color: #28a745; }
        .body { padding: 30px 40px; }
        .body h2 { font-size: 20px; margin-bottom: 20px; color: #1a1a2e; }
        .check-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .check-table th {
            text-align: left;
            padding: 10px 12px;
            background: #f8f9fa;
            font-size: 13px;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }
        .check-table td {
            padding: 10px 12px;
            font-size: 13px;
            border-bottom: 1px solid #e9ecef;
            color: #495057;
        }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-pass { background: #d4edda; color: #155724; }
        .badge-fail { background: #f8d7da; color: #721c24; }
        .badge-warn { background: #fff3cd; color: #856404; }
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #343a40;
            margin-bottom: 6px;
        }
        .form-group input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ced4da;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
            outline: none;
        }
        .form-group input:focus { border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.15); }
        .form-row { display: flex; gap: 16px; }
        .form-row .form-group { flex: 1; }
        .form-error { color: #dc3545; font-size: 12px; margin-top: 4px; }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .btn {
            display: inline-block;
            padding: 12px 32px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-primary { background: #667eea; color: #fff; }
        .btn-primary:hover { background: #5a6fd6; transform: translateY(-1px); }
        .btn-success { background: #28a745; color: #fff; }
        .btn-success:hover { background: #218838; }
        .actions { margin-top: 24px; text-align: right; }
        .done-icon { font-size: 64px; text-align: center; margin: 20px 0; }
        .done-text { text-align: center; }
        .done-text h2 { color: #28a745; text-align: center; }
        .done-text p { color: #6c757d; margin: 8px 0; font-size: 14px; }
        .done-actions { text-align: center; margin-top: 24px; }
        @media (max-width: 600px) {
            .body, .header, .steps { padding-left: 20px; padding-right: 20px; }
            .form-row { flex-direction: column; gap: 0; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 PeaseAPI 安装向导</h1>
            <p>AI 模型聚合与分发网关 — 快速配置您的实例</p>
        </div>

        <div class="steps">
            <div class="step-item {{ $step >= 1 && $step !== 'migrating' ? ($step === 1 ? 'active' : 'done') : ($step === 'migrating' ? 'done' : '') }}">
                <div class="step-num">{{ $step > 1 || $step === 'migrating' ? '✓' : '1' }}</div>
                <div class="step-label">环境检测</div>
            </div>
            <div class="step-item {{ $step === 'migrating' ? 'active' : ($step >= 2 ? ($step === 2 ? 'active' : 'done') : '') }}">
                <div class="step-num">{{ $step > 2 || $step === 'done' ? '✓' : '2' }}</div>
                <div class="step-label">数据库配置</div>
            </div>
            <div class="step-item {{ $step >= 3 ? ($step === 3 ? 'active' : 'done') : '' }}">
                <div class="step-num">{{ $step === 'done' ? '✓' : '3' }}</div>
                <div class="step-label">管理员账号</div>
            </div>
        </div>

        <div class="body">
            @if(isset($error))
                <div class="alert alert-error">⚠️ {{ $error }}</div>
            @endif

            {{-- Step 1: Environment Check --}}
            @if($step === 1)
                <h2>第一步：环境检测</h2>

                <h3 style="font-size:15px;margin-bottom:10px;color:#495057;">PHP 扩展检测</h3>
                <table class="check-table">
                    <thead>
                        <tr><th>项目</th><th>状态</th><th>当前值</th></tr>
                    </thead>
                    <tbody>
                        @foreach($envChecks as $check)
                        <tr>
                            <td>{{ $check['name'] }}</td>
                            <td>
                                @if($check['passed'])
                                    <span class="badge badge-pass">✓ 通过</span>
                                @elseif(isset($check['optional']))
                                    <span class="badge badge-warn">可选</span>
                                @else
                                    <span class="badge badge-fail">✗ 未通过</span>
                                @endif
                            </td>
                            <td>{{ $check['current'] }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>

                <h3 style="font-size:15px;margin-bottom:10px;color:#495057;">目录权限检测</h3>
                <table class="check-table">
                    <thead>
                        <tr><th>目录/文件</th><th>状态</th></tr>
                    </thead>
                    <tbody>
                        @foreach($dirChecks as $check)
                        <tr>
                            <td><code>{{ $check['name'] }}</code></td>
                            <td>
                                @if($check['passed'])
                                    <span class="badge badge-pass">✓ 可写</span>
                                @else
                                    <span class="badge badge-fail">✗ 不可写</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>

                <form method="POST" action="{{ route('install.process') }}">
                    @csrf
                    <input type="hidden" name="step" value="1">
                    <div class="actions">
                        <button type="submit" class="btn btn-primary">下一步：配置数据库 →</button>
                    </div>
                </form>

            {{-- Step 2: Database Configuration --}}
            @elseif($step === 2)
                <h2>第二步：数据库配置</h2>
                <p style="color:#6c757d;font-size:14px;margin-bottom:20px;">请填写 MySQL 数据库连接信息，系统将自动测试连接并创建数据表。</p>

                <form method="POST" action="{{ route('install.process') }}">
                    @csrf
                    <input type="hidden" name="step" value="2">

                    <div class="form-row">
                        <div class="form-group">
                            <label>主机地址</label>
                            <input type="text" name="db_host" value="{{ old('db_host', $dbDefaults['db_host'] ?? '127.0.0.1') }}" required>
                        </div>
                        <div class="form-group" style="max-width:120px;">
                            <label>端口</label>
                            <input type="number" name="db_port" value="{{ old('db_port', $dbDefaults['db_port'] ?? '3306') }}" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>数据库名</label>
                        <input type="text" name="db_database" value="{{ old('db_database', $dbDefaults['db_database'] ?? 'pease_api') }}" required>
                    </div>

                    <div class="form-group">
                        <label>用户名</label>
                        <input type="text" name="db_username" value="{{ old('db_username', $dbDefaults['db_username'] ?? 'root') }}" required>
                    </div>

                    <div class="form-group">
                        <label>密码</label>
                        <input type="password" name="db_password" value="{{ old('db_password', $dbDefaults['db_password'] ?? '') }}" autocomplete="new-password">
                    </div>

                    @error('db_host')<div class="form-error">{{ $message }}</div>@enderror
                    @error('db_database')<div class="form-error">{{ $message }}</div>@enderror
                    @error('db_username')<div class="form-error">{{ $message }}</div>@enderror

                    <div class="actions" style="display:flex;gap:12px;justify-content:space-between;">
                        <a href="{{ route('install.index') }}" class="btn" style="background:#e9ecef;color:#495057;">← 返回</a>
                        <button type="submit" class="btn btn-primary">测试连接并安装 →</button>
                    </div>
                </form>

            {{-- Step Migrating: Running database migration --}}
            @elseif($step === 'migrating')
                <h2>正在配置数据库...</h2>
                <p style="color:#6c757d;font-size:14px;margin-bottom:20px;">系统正在连接数据库并创建数据表，请稍候。</p>

                <div id="migration-status" class="alert" style="background:#e8f0fe;color:#1a73e8;border:1px solid #d2e3fc;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="spinner" style="width:20px;height:20px;border:3px solid #d2e3fc;border-top-color:#1a73e8;border-radius:50%;animation:spin 1s linear infinite;"></div>
                        <span id="migration-text">正在执行数据库迁移...</span>
                    </div>
                </div>

                <div id="migration-error" class="alert alert-error" style="display:none;"></div>

                <style>
                    @keyframes spin { to { transform: rotate(360deg); } }
                </style>

                <script>
                (function() {
                    let attempts = 0;
                    const maxAttempts = 60; // 5 minutes max

                    function runMigration() {
                        fetch('/install/migrate', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                'Accept': 'application/json'
                            }
                        })
                        .then(response => {
                            if (!response.ok) {
                                // Server might have restarted, retry
                                throw new Error('Server returned ' + response.status);
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data.status === 'done') {
                                document.getElementById('migration-status').style.display = 'none';
                                document.getElementById('migration-error').style.display = 'none';
                                // Redirect to step 3
                                window.location.href = '/install';
                            } else if (data.status === 'failed') {
                                document.getElementById('migration-status').style.display = 'none';
                                const errDiv = document.getElementById('migration-error');
                                errDiv.style.display = 'block';
                                errDiv.textContent = '❌ 数据库迁移失败：' + (data.error || '未知错误');
                            } else {
                                throw new Error('Unexpected response');
                            }
                        })
                        .catch(err => {
                            attempts++;
                            if (attempts < maxAttempts) {
                                document.getElementById('migration-text').textContent =
                                    '等待服务器响应... (第 ' + attempts + ' 次重试)';
                                setTimeout(runMigration, 3000);
                            } else {
                                document.getElementById('migration-status').style.display = 'none';
                                const errDiv = document.getElementById('migration-error');
                                errDiv.style.display = 'block';
                                errDiv.textContent = '❌ 连接超时，请检查服务器状态后刷新页面重试。';
                            }
                        });
                    }

                    // Start migration after a short delay
                    setTimeout(runMigration, 1000);
                })();
                </script>

            {{-- Step 3: Admin Account --}}
            @elseif($step === 3)
                <h2>第三步：创建管理员账号</h2>
                <p style="color:#6c757d;font-size:14px;margin-bottom:20px;">数据库已连接成功，数据表已创建。请设置超级管理员账号。</p>

                <div class="alert alert-success">✓ 数据库连接成功，数据迁移已完成！</div>

                <form method="POST" action="{{ route('install.process') }}">
                    @csrf
                    <input type="hidden" name="step" value="3">

                    <div class="form-group">
                        <label>管理员用户名</label>
                        <input type="text" name="admin_username" value="{{ old('admin_username') }}" required minlength="3" maxlength="32" pattern="[a-zA-Z0-9]+" title="只能包含字母和数字">
                        @error('admin_username')<div class="form-error">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <label>管理员邮箱</label>
                        <input type="email" name="admin_email" value="{{ old('admin_email') }}" required>
                        @error('admin_email')<div class="form-error">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>密码</label>
                            <input type="password" name="admin_password" required minlength="6" autocomplete="new-password">
                            @error('admin_password')<div class="form-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-group">
                            <label>确认密码</label>
                            <input type="password" name="admin_password_confirmation" required minlength="6" autocomplete="new-password">
                        </div>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-success">完成安装 🎉</button>
                    </div>
                </form>

            {{-- Done --}}
            @elseif($step === 'done')
                <div class="done-text">
                    <div class="done-icon">🎉</div>
                    <h2>安装完成！</h2>
                    <p>PeaseAPI 已成功安装，您可以开始使用了。</p>
                    <p>管理员账号：<strong>{{ $admin_username }}</strong></p>
                    <p style="margin-top:16px;font-size:12px;color:#adb5bd;">为安全起见，安装完成后安装向导将不再可用。</p>
                </div>
                <div class="done-actions">
                    <a href="/" class="btn btn-primary">进入系统 →</a>
                </div>
            @endif
        </div>
    </div>
</body>
</html>
]]>