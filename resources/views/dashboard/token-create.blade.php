@extends('layouts.dashboard')
@section('title', '创建令牌')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="text-xl font-bold text-gray-900 mb-6">创建新令牌</h2>
        <form id="createForm" class="space-y-5">
            @csrf
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">名称 <span class="text-red-500">*</span></label>
                <input type="text" name="name" required maxlength="30" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition" placeholder="例如：生产环境令牌">
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">剩余配额</label>
                    <input type="number" name="remain_quota" min="0" value="500000" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition">
                    <p class="text-xs text-gray-400 mt-1">设为 -1 表示不限额度</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">过期时间</label>
                    <input type="datetime-local" name="expired_time" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition">
                    <p class="text-xs text-gray-400 mt-1">留空表示永不过期</p>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">分组</label>
                    <input type="text" name="group" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition" placeholder="default">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">允许 IP</label>
                    <input type="text" name="allow_ips" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition" placeholder="留空不限制，多个用逗号分隔">
                </div>
            </div>
            <div class="border-t border-gray-100 pt-5">
                <label class="flex items-center cursor-pointer">
                    <input type="checkbox" name="unlimited_quota" value="1" class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500">
                    <span class="ml-2 text-sm text-gray-700">无限额度</span>
                </label>
            </div>
            <div class="border-t border-gray-100 pt-5">
                <label class="flex items-center cursor-pointer mb-3">
                    <input type="checkbox" name="model_limits_enabled" id="modelLimitsToggle" value="1" class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500">
                    <span class="ml-2 text-sm font-medium text-gray-700">启用模型限制</span>
                </label>
                <div id="modelLimitsBox" class="hidden">
                    <textarea name="model_limits" rows="4" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition font-mono text-sm" placeholder="每行一个模型名，例如：&#10;gpt-4o&#10;claude-3-5-sonnet"></textarea>
                    <p class="text-xs text-gray-400 mt-1">仅允许该令牌访问上述模型</p>
                </div>
            </div>
            <div class="flex items-center justify-end space-x-3 pt-5 border-t border-gray-100">
                <a href="{{ route('tokens') }}" class="px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">取消</a>
                <button type="submit" class="px-5 py-2.5 bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition font-medium">创建令牌</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.getElementById('modelLimitsToggle').addEventListener('change', function() {
    document.getElementById('modelLimitsBox').classList.toggle('hidden', !this.checked);
});
document.getElementById('createForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const payload = {};
    formData.forEach((v, k) => { payload[k] = v; });
    payload.remain_quota = parseInt(payload.remain_quota || 0);
    if (!payload.expired_time) delete payload.expired_time;
    try {
        const res = await fetch('/web-api/tokens', { credentials: 'same-origin',
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (res.ok && (data.success !== false)) {
            const key = data.data?.key || data.key || '';
            if (key) {
                alert('令牌创建成功！请保存您的密钥：\n\n' + key);
            } else {
                alert('令牌创建成功');
            }
            window.location.href = '{{ route("tokens") }}';
        } else {
            alert('创建失败：' + (data.message || JSON.stringify(data)));
        }
    } catch (err) {
        alert('请求出错：' + err.message);
    }
});
</script>
@endpush