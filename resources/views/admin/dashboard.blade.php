@extends('layouts.dashboard')
@section('title', '管理面板')

@section('content')
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-center justify-between">
            <div><p class="text-sm text-gray-500 mb-1">总用户</p><p class="text-2xl font-bold" id="totalUsers">-</p></div>
            <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-lg flex items-center justify-center"><i class="fas fa-users text-xl"></i></div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-center justify-between">
            <div><p class="text-sm text-gray-500 mb-1">总渠道</p><p class="text-2xl font-bold" id="totalChannels">-</p></div>
            <div class="w-12 h-12 bg-green-100 text-green-600 rounded-lg flex items-center justify-center"><i class="fas fa-server text-xl"></i></div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-center justify-between">
            <div><p class="text-sm text-gray-500 mb-1">总令牌</p><p class="text-2xl font-bold" id="totalTokens">-</p></div>
            <div class="w-12 h-12 bg-purple-100 text-purple-600 rounded-lg flex items-center justify-center"><i class="fas fa-key text-xl"></i></div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-center justify-between">
            <div><p class="text-sm text-gray-500 mb-1">总请求</p><p class="text-2xl font-bold" id="totalRequests">-</p></div>
            <div class="w-12 h-12 bg-orange-100 text-orange-600 rounded-lg flex items-center justify-center"><i class="fas fa-chart-bar text-xl"></i></div>
        </div>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <h3 class="text-lg font-semibold text-gray-900 mb-4">最近注册用户</h3>
    <table class="w-full text-sm">
        <thead><tr class="text-left text-xs font-medium text-gray-500 uppercase border-b"><th class="pb-3">用户名</th><th class="pb-3">邮箱</th><th class="pb-3">配额</th><th class="pb-3">状态</th><th class="pb-3">注册时间</th></tr></thead>
        <tbody id="recentUsers"><tr><td colspan="5" class="py-4 text-center text-gray-500">加载中...</td></tr></tbody>
    </table>
</div>
@endsection

@push('scripts')
<script>
fetch('/web-api/users?per_page=5').then(r=>r.json()).then(d=>{
    document.getElementById('totalUsers').textContent=d.total||0;
    const t=document.getElementById('recentUsers');
    if(d.data&&d.data.length){t.innerHTML=d.data.map(u=>`<tr class="border-b border-gray-100"><td class="py-3 font-medium">${u.username}</td><td class="py-3">${u.email||'-'}</td><td class="py-3">${(u.quota||0).toLocaleString()}</td><td class="py-3"><span class="px-2 py-1 text-xs rounded-full ${u.status===1?'bg-green-100 text-green-700':'bg-red-100 text-red-700'}">${u.status===1?'正常':'禁用'}</span></td><td class="py-3 text-gray-500">${u.created_at?new Date(u.created_at).toLocaleDateString():'-'}</td></tr>`).join('')}
    else{t.innerHTML='<tr><td colspan="5" class="py-4 text-center text-gray-500">暂无用户</td></tr>'}
});
fetch('/web-api/channels?per_page=1').then(r=>r.json()).then(d=>{document.getElementById('totalChannels').textContent=d.total||0});
fetch('/web-api/tokens?per_page=1').then(r=>r.json()).then(d=>{document.getElementById('totalTokens').textContent=d.total||0});
</script>
@endpush