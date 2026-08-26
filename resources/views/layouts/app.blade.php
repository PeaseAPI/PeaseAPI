<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', $systemName ?? 'Pease API') - {{ $systemName ?? 'Pease API' }}</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <style>
        [x-cloak] { display: none !important; }
        .fade-in { animation: fadeIn 0.3s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
    @stack('head')
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen">
    @yield('content')
    @if(!empty($footerHtml))
    <footer class="bg-white border-t border-gray-200 py-6 text-center text-sm text-gray-500">
        {!! $footerHtml !!}
    </footer>
    @endif
    @stack('scripts')
</body>
</html>