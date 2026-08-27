@extends('layouts.dashboard')
@section('title', '令牌管理')

@section('content')
<div class="mb-6 flex items-center justify-between">
    <h2 class="text-lg font-semibold text-gray-900">我的令牌</h2>
    <a href="{{ route('tokens.create') }}" class="bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-primary-700 transition">
        <i class="fas fa-plus mr-1"></i>创建令牌
    </a>
</div>

<!-- Token List -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="p-4 border-b border-gray-200 flex items-center gap-4">
        <input type="text" id="searchInput" placeholder="搜索令牌..." 
            class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
        <select id="statusFilter" class="px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
            <option value="">全部状态</option>
            <option value="1">启用</option>
            <option value="0">禁用</option>
        </select>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider bg-gray-50">
                    <th class="px-6 py-3">名称</th>
                    <th class="px-6 py-3">密钥</th>
                    <th class="px-6 py-3">配额</th>
                    <th class="px-6 py-3">已用</th>
                    <th class="px-6 py-3">状态</th>
                    <th class="px-6 py-3">创建时间</th>
                    <th class="px-6 py-3">操作</th>
                </tr>
            </thead>
            <tbody id="tokenTable" class="text-sm">
                <tr><td colspan="7" class="px-6 py-8 text-center text-gray-500">加载中...</td></tr>
            </tbody>
        </table>
    </div>
    <div class="px-6 py-4 border-t border-gray-200">
        <div class="flex items-center justify-between">
            <span class="text-sm text-gray-500" id="pageInfo">共 0 条</span>
            <div class="flex items-center gap-2" id="pagination"></div>
        </div>
    </div>
</div>

<!-- Create Token Modal -->
<div id="createModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900">创建令牌</h3>
            <button onclick="closeModal('createModal')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="createTokenForm" class="p-6 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">令牌名称 *</label>
                <input type="text" name="name" required
                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-sm"
                    placeholder="如：API Token 1">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">配额限制</label>
                <input type="number" name="quota_quota" min="0"
                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-sm"
                    placeholder="0 表示不限制">
            </div>
            <div>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="unlimited_quota" value="1" class="w-4 h-4 text-primary-600 rounded border-gray-300 focus:ring-primary-500">
                    <span class="text-sm text-gray-700">无限配额</span>
                </label>
            </div>
            <div class="flex gap-3 pt-4">
                <button type="button" onclick="closeModal('createModal')" 
                    class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                    取消
                </button>
                <button type="submit" class="flex-1 px-4 py-2.5 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 transition">
                    创建
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Token Modal -->
<div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900">编辑令牌</h3>
            <button onclick="closeModal('editModal')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="editTokenForm" class="p-6 space-y-4">
            <input type="hidden" name="id" id="editTokenId">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">令牌名称</label>
                <input type="text" name="name" id="editTokenName" required
                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">状态</label>
                <select name="status" id="editTokenStatus" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm">
                    <option value="1">启用</option>
                    <option value="0">禁用</option>
                </select>
            </div>
            <div class="flex gap-3 pt-4">
                <button type="button" onclick="closeModal('editModal')" 
                    class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                    取消
                </button>
                <button type="submit" class="flex-1 px-4 py-2.5 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 transition">
                    保存
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Show Key Modal -->
<div id="keyModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900">令牌密钥</h3>
            <button onclick="closeModal('keyModal')" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="p-6">
            <p class="text-sm text-gray-600 mb-2">请妥善保存以下密钥，关闭后将无法查看：</p>
            <div class="flex items-center gap-2">
                <input type="text" id="showKeyInput" readonly
                    class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-sm font-mono bg-gray-50">
                <button type="button" onclick="copyKey()" class="px-4 py-2.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition">
                    <i class="fas fa-copy"></i>
                </button>
            </div>
            <button onclick="closeModal('keyModal')" 
                class="w-full mt-4 px-4 py-2.5 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700 transition">
                我已保存
            </button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let currentPage = 1;
let currentSearch = '';
let currentStatus = '';

function loadTokens(page = 1) {
    currentPage = page;
    const params = new URLSearchParams({ page, per_page: 10 });
    if (currentSearch) params.append('search', currentSearch);
    if (currentStatus) params.append('status', currentStatus);
    
    fetch(`/web-api/tokens?${params}`, { credentials: 'same-origin' })
        .then(res => res.json())
        .then(data => {
            const tbody = document.getElementById('tokenTable');
            if (data.data && data.data.length > 0) {
                tbody.innerHTML = data.data.map(token => `
                    <tr class="border-b border-gray-100 hover:bg-gray-50">
                        <td class="px-6 py-4 font-medium">${token.name || '-'}</td>
                        <td class="px-6 py-4">
                            <button onclick="showKey('${token.key}')" class="text-primary-600 hover:text-primary-800 font-mono text-sm">
                                ${token.key.substring(0, 12)}...
                            </button>
                        </td>
                        <td class="px-6 py-4">${token.unlimited_quota ? '∞' : (token.quota_quota || 0).toLocaleString()}</td>
                        <td class="px-6 py-4">${(token.quota_used || 0).toLocaleString()}</td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-1 text-xs rounded-full ${token.status === 1 ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'}">
                                ${token.status === 1 ? '启用' : '禁用'}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-gray-500">${token.created_at ? new Date(token.created_at * 1000).toLocaleDateString() : '-'}</td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <button onclick="editToken(${token.id}, '${token.name}', ${token.status})" class="text-blue-600 hover:text-blue-800 text-sm">编辑</button>
                                <button onclick="deleteToken(${token.id})" class="text-red-600 hover:text-red-800 text-sm">删除</button>
                            </div>
                        </td>
                    </tr>
                `).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="7" class="px-6 py-8 text-center text-gray-500">暂无令牌</td></tr>';
            }
            
            document.getElementById('pageInfo').textContent = `共 ${data.total || 0} 条`;
            renderPagination(data);
        });
}

function renderPagination(data) {
    const pag = document.getElementById('pagination');
    if (!data.last_page || data.last_page <= 1) {
        pag.innerHTML = '';
        return;
    }
    let html = '';
    if (data.current_page > 1) {
        html += `<button onclick="loadTokens(${data.current_page - 1})" class="px-3 py-1 border rounded text-sm hover:bg-gray-50">上一页</button>`;
    }
    html += `<span class="px-3 py-1 text-sm text-gray-500">${data.current_page} / ${data.last_page}</span>`;
    if (data.current_page < data.last_page) {
        html += `<button onclick="loadTokens(${data.current_page + 1})" class="px-3 py-1 border rounded text-sm hover:bg-gray-50">下一页</button>`;
    }
    pag.innerHTML = html;
}

function showKey(key) {
    document.getElementById('showKeyInput').value = key;
    document.getElementById('keyModal').classList.remove('hidden');
}

function copyKey() {
    const input = document.getElementById('showKeyInput');
    input.select();
    document.execCommand('copy');
    alert('已复制到剪贴板');
}

function editToken(id, name, status) {
    document.getElementById('editTokenId').value = id;
    document.getElementById('editTokenName').value = name;
    document.getElementById('editTokenStatus').value = status;
    document.getElementById('editModal').classList.remove('hidden');
}

function deleteToken(id) {
    if (!confirm('确定要删除此令牌吗？')) return;
    fetch(`/web-api/tokens/${id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' } })
        .then(res => res.json())
        .then(data => {
            alert(data.message || '删除成功');
            loadTokens(currentPage);
        });
}

function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}

document.getElementById('createTokenForm').onsubmit = function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    fetch('/web-api/tokens', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.fromEntries(formData))
    }).then(res => res.json()).then(data => {
        alert(data.key ? `令牌创建成功！密钥: ${data.key}` : '创建成功');
        closeModal('createModal');
        this.reset();
        loadTokens();
    });
};

document.getElementById('editTokenForm').onsubmit = function(e) {
    e.preventDefault();
    const id = document.getElementById('editTokenId').value;
    const formData = new FormData(this);
    fetch(`/web-api/tokens/${id}`, {
        method: 'PUT',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.fromEntries(formData))
    }).then(res => res.json()).then(data => {
        alert('保存成功');
        closeModal('editModal');
        loadTokens(currentPage);
    });
};

document.getElementById('searchInput').addEventListener('input', e => { currentSearch = e.target.value; loadTokens(1); });
document.getElementById('statusFilter').addEventListener('change', e => { currentStatus = e.target.value; loadTokens(1); });

loadTokens();
</script>
@endpush