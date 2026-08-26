@php
    $systemName = 'PeaseAPI';
    $systemLogo = '';
    try {
        if (app()->bound('db')) {
            $systemName = \App\Services\OptionService::get('SystemName', $systemName);
            $systemLogo = \App\Services\OptionService::get('SystemLogo', '');
        }
    } catch (\Throwable $e) {}
@endphp
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', '仪表盘') - {{ $systemName }}</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        [x-cloak] { display: none !important; }
        .fade-in { animation: fadeIn 0.3s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .sidebar-link { transition: all 0.2s; }
        .sidebar-link:hover, .sidebar-link.active { background: rgba(59,130,246,0.1); color: #2563eb; }
        .sidebar-link.active { border-right: 3px solid #2563eb; }
    </style>
    @stack('head')
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <aside id="sidebar" class="w-64 bg-white border-r border-gray-200 flex-shrink-0 fixed h-full z-30 transition-transform lg:translate-x-0 -translate-x-full">
            <div class="h-16 flex items-center px-6 border-b border-gray-200">
                <a href="{{ route('dashboard') }}" class="flex items-center space-x-2">
                    @if(!empty($systemLogo))
                        <img src="{{ $systemLogo }}" alt="{{ $systemName }}" class="w-8 h-8 rounded-lg object-cover">
                    @else
                        <div class="w-8 h-8 bg-primary-600 rounded-lg flex items-center justify-center">
                            <span class="text-white font-bold text-sm">{{ mb_substr($systemName, 0, 1) }}</span>
                        </div>
                    @endif
                    <span class="text-xl font-bold text-gray-900">{{ $systemName }}</span>
                </a>
            </div>
            <nav class="p-4 space-y-1 overflow-y-auto" style="height: calc(100% - 8rem);">
                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2 px-3">用户面板</div>
                <a href="{{ route('dashboard') }}" class="sidebar-link flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <i class="fas fa-home w-5 mr-3"></i>仪表盘
                </a>
                <a href="{{ route('tokens') }}" class="sidebar-link flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 {{ request()->routeIs('tokens*') ? 'active' : '' }}">
                    <i class="fas fa-key w-5 mr-3"></i>令牌管理
                </a>
                <a href="{{ route('user.logs') }}" class="sidebar-link flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 {{ request()->routeIs('user.logs') ? 'active' : '' }}">
                    <i class="fas fa-list-alt w-5 mr-3"></i>请求日志
                </a>
                <a href="{{ route('redeem') }}" class="sidebar-link flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 {{ request()->routeIs('redeem') ? 'active' : '' }}">
                    <i class="fas fa-gift w-5 mr-3"></i>兑换码
                </a>
                <a href="{{ route('profile') }}" class="sidebar-link flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 {{ request()->routeIs('profile') ? 'active' : '' }}">
                    <i class="fas fa-user w-5 mr-3"></i>个人信息
                </a>
                <a href="{{ route('news-keys') }}" class="sidebar-link flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 {{ request()->routeIs('news-keys') ? 'active' : '' }}">
                    <i class="fas fa-key w-5 mr-3"></i>中转 Key 设置
                </a>

                @if(auth()->user() && auth()->user()->role >= 100)
                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mt-6 mb-2 px-3">管理后台</div>
                <a href="{{ route('admin.dashboard') }}" class="sidebar-link flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                    <i class="fas fa-tachometer-alt w-5 mr-3"></i>管理面板
                </a>
                <a href="{{ route('admin.users') }}" class="sidebar-link flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 {{ request()->routeIs('admin.users') ? 'active' : '' }}">
                    <i class="fas fa-users w-5 mr-3"></i>用户管理
                </a>
                <a href="{{ route('admin.channels') }}" class="sidebar-link flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 {{ request()->routeIs('admin.channels') ? 'active' : '' }}">
                    <i class="fas fa-server w-5 mr-3"></i>渠道管理
                </a>
                <a href="{{ route('admin.tokens') }}" class="sidebar-link flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 {{ request()->routeIs('admin.tokens') ? 'active' : '' }}">
                    <i class="fas fa-key w-5 mr-3"></i>令牌总览
                </a>
                <a href="{{ route('admin.abilities') }}" class="sidebar-link flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 {{ request()->routeIs('admin.abilities') ? 'active' : '' }}">
                    <i class="fas fa-cubes w-5 mr-3"></i>能力管理
                </a>
                <a href="{{ route('admin.logs') }}" class="sidebar-link flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 {{ request()->routeIs('admin.logs') ? 'active' : '' }}">
                    <i class="fas fa-file-alt w-5 mr-3"></i>全局日志
                </a>
                <a href="{{ route('admin.redemptions') }}" class="sidebar-link flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 {{ request()->routeIs('admin.redemptions') ? 'active' : '' }}">
                    <i class="fas fa-ticket-alt w-5 mr-3"></i>兑换码管理
                </a>
                <a href="{{ route('admin.system-settings') }}" class="sidebar-link flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 {{ request()->routeIs('admin.options*') || request()->routeIs('admin.system-settings') ? 'active' : '' }}">
                    <i class="fas fa-cog w-5 mr-3"></i>系统设置
                </a>
                @endif
            </nav>
            <div class="absolute bottom-0 w-full p-4 border-t border-gray-200 bg-white">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex items-center w-full px-3 py-2 text-sm text-gray-600 hover:text-red-600 rounded-lg hover:bg-red-50 transition">
                        <i class="fas fa-sign-out-alt w-5 mr-3"></i>退出登录
                    </button>
                </form>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 lg:ml-64">
            <!-- Top Navbar -->
            <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-6 sticky top-0 z-20">
                <div class="flex items-center">
                    <button id="sidebar-toggle" class="lg:hidden mr-4 text-gray-500 hover:text-gray-700">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <h1 class="text-lg font-semibold text-gray-900">@yield('title', '仪表盘')</h1>
                </div>
                <a href="{{ route('profile') }}" class="flex items-center space-x-3 group cursor-pointer px-2 py-1 rounded-lg hover:bg-gray-50 transition" title="点击查看/编辑个人信息">
                    <span id="topNavUserName" class="text-sm text-gray-500 group-hover:text-primary-600 transition">{{ auth()->user()->display_name ?? auth()->user()->username }}</span>
                    <span id="topNavAvatar" class="w-8 h-8 rounded-full overflow-hidden flex items-center justify-center ring-2 ring-transparent group-hover:ring-primary-400 transition flex-shrink-0">
                        @if(auth()->user()->avatar_url)
                            <img src="{{ auth()->user()->avatar_url }}" alt="头像" class="w-full h-full object-cover">
                        @else
                            <span class="w-full h-full bg-primary-100 text-primary-700 flex items-center justify-center text-sm font-medium group-hover:bg-primary-200 transition">
                                {{ strtoupper(mb_substr(auth()->user()->display_name ?? auth()->user()->username, 0, 1)) }}
                            </span>
                        @endif
                    </span>
                </a>
            </header>

            <!-- Page Content -->
            <main class="p-6 fade-in">
                @if(session('success'))
                <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
                    {{ session('success') }}
                </div>
                @endif
                @if(session('error'))
                <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                    {{ session('error') }}
                </div>
                @endif
                @yield('content')
            </main>
        </div>
    </div>

    <!-- Mobile sidebar overlay -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-20 hidden lg:hidden" onclick="document.getElementById('sidebar').classList.add('-translate-x-full');this.classList.add('hidden')"></div>

    <script>
        document.getElementById('sidebar-toggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        });
    </script>
    @stack('scripts')
</body>
</html>