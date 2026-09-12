@extends('layouts.dashboard')
@section('title', '编辑令牌')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="bg-gradient-to-r from-primary-600 to-indigo-600 px-6 py-5 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-bold text-white">编辑令牌</h2>
                <p class="text-xs text-white/70 mt-0.5">令牌 #<span id="tokenIdLabel">{{ $tokenId }}</span></p>
            </div>
            <span id="statusPill" class="hidden px-2.5 py-1 rounded-full text-xs font-medium bg-white/15 text-white backdrop-blur">—</span>
        </div>
        <div id="loadingBox" class="px-6 py-16 text-center text-sm text-gray-400">加载中…</div>
        <form id="editForm" class="p-6 space-y-5 hidden">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">名称 <span class="text-red-500">*</span></label>
                <input type="text" id="f_name" required maxlength="100" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition" placeholder="令牌名称">
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">状态</label>
                    <select id="f_status" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition">
                        <option value="1">启用</option>
                        <option value="0">禁用</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">剩余配额</label>
                    <input type="number" id="f_remain_quota" min="0" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition disabled:bg-gray-50 disabled:text-gray-400">
                    <p class="text-xs text-gray-400 mt-1">勾选无限额度时忽略此值</p>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">过期时间</label>
                    <input type="datetime-local" id="f_expired_time" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition">
                    <p class="text-xs text-gray-400 mt-1">留空表示永不过期</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">分组</label>
                    <input type="text" id="f_group" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition" placeholder="default">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">允许 IP</label>
                <input type="text" id="f_allow_ips" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition" placeholder="留空不限制，多个用逗号分隔">
            </div>
            <div class="border-t border-gray-100 pt-5 space-y-3">
                <label class="flex items-center cursor-pointer">
                    <input type="checkbox" id="f_unlimited" class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500">
                    <span class="ml-2 text-sm text-gray-700">无限额度</span>
                </label>
                <label class="flex items-center cursor-pointer">
                    <input type="checkbox" id="f_model_limits_enabled" class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500">
                    <span class="ml-2 text-sm font-medium text-gray-700">启用模型限制</span>
                </label>
                <div id="modelLimitsBox" class="hidden">
                    <textarea id="f_model_limits" rows="4" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition font-mono text-sm" placeholder="每行一个模型名，例如：&#10;gpt-4o&#10;claude-3-5-sonnet"></textarea>
                    <p class="text-xs text-gray-400 mt-1">仅允许该令牌访问上述模型</p>
                </div>
            </div>
            <div class="flex items-center justify-end space-x-3 pt-5 border-t border-gray-100">
                <a href="{{ route('tokens') }}" class="px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">取消</a>
                <button type="submit" id="submitBtn" class="px-5 py-2.5 bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition font-medium disabled:opacity-60">保存修改</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
const tokenId = {{ $tokenId }};
document.getElementById('f_model_limits_enabled').addEventListener('change', function() {
    document.getElementById('modelLimitsBox').classList.toggle('hidden', !this.checked);
});
document.getElementById('f_unlimited').addEventListener('change', function() {
    document.getElementById('f_remain_quota').disabled = this.checked;
});
function toLocalInput(unix) {
    const d = new Date(unix * 1000);
    const p = n => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes());
}
(async function load() {
    try {
        const res = await fetch('/web-api/tokens/' + tokenId, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || '加载失败');
        const t = data.data || data;
        document.getElementById('f_name').value = t.name || '';
        document.getElementById('f_status').value = String(t.status ?? 1);
        document.getElementById('f_remain_quota').value = t.remain_quota ?? 0;
        document.getElementById('f_unlimited').checked = !!t.unlimited_quota;
        document.getElementById('f_remain_quota').disabled = !!t.unlimited_quota;
        if (t.expired_time && t.expired_time > 0) document.getElementById('f_expired_time').value = toLocalInput(t.expired_time);
        document.getElementById('f_group').value = t.group || '';
        document.getElementById('f_allow_ips').value = t.allow_ips || '';
        document.getElementById('f_model_limits_enabled').checked = !!t.model_limits_enabled;
        document.getElementById('f_model_limits').value = t.model_limits || '';
        document.getElementById('modelLimitsBox').classList.toggle('hidden', !t.model_limits_enabled);
        const pill = document.getElementById('statusPill');
        pill.textContent = (t.status === 1) ? '启用中' : '已禁用';
        pill.classList.remove('hidden');
        document.getElementById('loadingBox').classList.add('hidden');
        document.getElementById('editForm').classList.remove('hidden');
    } catch (err) {
        alert('加载令牌失败：' + err.message);
        window.location.href = '{{ route("tokens") }}';
    }
})();
document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.textContent = '保存中…';
    const expired = document.getElementById('f_expired_time').value;
    const payload = {
        name: document.getElementById('f_name').value,
        status: parseInt(document.getElementById('f_status').value),
        remain_quota: parseInt(document.getElementById('f_remain_quota').value || '0'),
        unlimited_quota: document.getElementById('f_unlimited').checked,
        group: document.getElementById('f_group').value,
        allow_ips: document.getElementById('f_allow_ips').value,
        model_limits_enabled: document.getElementById('f_model_limits_enabled').checked,
        model_limits: document.getElementById('f_model_limits').value,
        expired_time: expired ? (new Date(expired).getTime() / 1000 | 0) : -1
    };
    try {
        const res = await fetch('/web-api/tokens/' + tokenId, { credentials: 'same-origin',
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (res.ok && !data.error) {
            alert('令牌已更新');
            window.location.href = '{{ route("tokens") }}';
        } else {
            alert('保存失败：' + (data.message || data.error || JSON.stringify(data)));
            btn.disabled = false;
            btn.textContent = '保存修改';
        }
    } catch (err) {
        alert('请求出错：' + err.message);
        btn.disabled = false;
        btn.textContent = '保存修改';
    }
});
</script>
@endpush
