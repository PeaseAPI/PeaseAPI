@extends('layouts.dashboard')
@section('title', '用户管理')

@section('content')
<div class="mb-6 flex items-center justify-between">
    <h2 class="text-lg font-semibold">用户管理</h2>
    <button onclick="openCreate()" class="bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-primary-700">
        <i class="fas fa-plus mr-1"></i>添加用户
    </button>
</div>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="p-4 border-b flex items-center gap-4">
        <input type="text" id="searchInput" placeholder="搜索用户名/邮箱..." class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500">
    </div>
    <table class="w-full text-sm">
        <thead><tr class="text-left text-xs font-medium text-gray-500 uppercase bg-gray-50"><th class="px-6 py-3">ID</th><th class="px-6 py-3">用户名</th><th class="px-6 py-3">邮箱</th><th class="px-6 py-3">配额</th><th class="px-6 py-3">已用</th><th class="px-6 py-3">角色</th><th class="px-6 py-3">状态</th><th class="px-6 py-3">操作</th></tr></thead>
        <tbody id="userTable"><tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">加载中...</td></tr></tbody>
    </table>
    <div class="px-6 py-4 border-t flex justify-between items-center">
        <span class="text-sm text-gray-500" id="pageInfo">共 0 条</span>
        <div id="pagination" class="flex gap-2"></div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div id="userModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="text-lg font-semibold" id="modalTitle">添加用户</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form id="userForm" class="p-6 space-y-4">
            <input type="hidden" id="userId">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">用户名 *</label><input type="text" id="formUsername" required class="w-full px-4 py-2.5 border rounded-lg text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">邮箱</label><input type="email" id="formEmail" class="w-full px-4 py-2.5 border rounded-lg text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">密码</label><input type="password" id="formPassword" class="w-full px-4 py-2.5 border rounded-lg text-sm" placeholder="留空不修改"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">配额</label><input type="number" id="formQuota" value="0" class="w-full px-4 py-2.5 border rounded-lg text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">角色</label><select id="formRole" class="w-full px-4 py-2.5 border rounded-lg text-sm"><option value="1">普通用户</option><option value="100">管理员</option></select></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">状态</label><select id="formStatus" class="w-full px-4 py-2.5 border rounded-lg text-sm"><option value="1">正常</option><option value="0">禁用</option></select></div>
            <div class="flex gap-3 pt-4">
                <button type="button" onclick="closeModal()" class="flex-1 px-4 py-2.5 border rounded-lg text-sm">取消</button>
                <button type="submit" class="flex-1 px-4 py-2.5 bg-primary-600 text-white rounded-lg text-sm">保存</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
let page=1;
function loadUsers(p=1){page=p;fetch(`/web-api/users?page=${p}&per_page=10`).then(r=>r.json()).then(d=>{const t=document.getElementById('userTable');if(d.data&&d.data.length){t.innerHTML=d.data.map(u=>`<tr class="border-b border-gray-100 hover:bg-gray-50"><td class="px-6 py-3">${u.id}</td><td class="px-6 py-3 font-medium">${u.username}</td><td class="px-6 py-3">${u.email||'-'}</td><td class="px-6 py-3">${(u.quota||0).toLocaleString()}</td><td class="px-6 py-3">${(u.used_quota||0).toLocaleString()}</td><td class="px-6 py-3"><span class="px-2 py-1 text-xs rounded-full ${u.role>=100?'bg-purple-100 text-purple-700':'bg-gray-100 text-gray-700'}">${u.role>=100?'管理员':'用户'}</span></td><td class="px-6 py-3"><span class="px-2 py-1 text-xs rounded-full ${u.status===1?'bg-green-100 text-green-700':'bg-red-100 text-red-700'}">${u.status===1?'正常':'禁用'}</span></td><td class="px-6 py-3"><button onclick="editUser(${u.id})" class="text-blue-600 hover:text-blue-800 mr-2">编辑</button><button onclick="toggleStatus(${u.id},${u.status})" class="text-orange-600 hover:text-orange-800 mr-2">${u.status===1?'禁用':'启用'}</button></td></tr>`).join('')}else{t.innerHTML='<tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">暂无用户</td></tr>'}document.getElementById('pageInfo').textContent=`共 ${d.total||0} 条`})}
function openCreate(){document.getElementById('modalTitle').textContent='添加用户';document.getElementById('userId').value='';document.getElementById('userForm').reset();document.getElementById('userModal').classList.remove('hidden')}
function editUser(id){fetch(`/web-api/users/${id}`).then(r=>r.json()).then(u=>{document.getElementById('modalTitle').textContent='编辑用户';document.getElementById('userId').value=u.id;document.getElementById('formUsername').value=u.username;document.getElementById('formEmail').value=u.email||'';document.getElementById('formQuota').value=u.quota||0;document.getElementById('formRole').value=u.role||1;document.getElementById('formStatus').value=u.status;document.getElementById('userModal').classList.remove('hidden')})}
function closeModal(){document.getElementById('userModal').classList.add('hidden')}
function toggleStatus(id,s){fetch(`/web-api/users/${id}`,{method:'PUT',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'},body:JSON.stringify({status:s===1?0:1})}).then(r=>r.json()).then(()=>loadUsers(page))}
document.getElementById('userForm').onsubmit=function(e){e.preventDefault();const id=document.getElementById('userId').value;const data={username:document.getElementById('formUsername').value,email:document.getElementById('formEmail').value,quota:document.getElementById('formQuota').value,role:document.getElementById('formRole').value,status:document.getElementById('formStatus').value};const pw=document.getElementById('formPassword').value;if(pw)data.password=pw;const url=id?`/web-api/users/${id}`:'/web-api/users';const method=id?'PUT':'POST';fetch(url,{method,headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'},body:JSON.stringify(data)}).then(r=>r.json()).then(d=>{closeModal();loadUsers(page)})};
loadUsers();
</script>
@endpush