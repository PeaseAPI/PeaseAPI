<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VendorMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 厂商元数据控制器 - 对标 new-api controller/vendor.go
 */
class VendorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = VendorMeta::query()
            ->when($request->input('keyword'), fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->orderByDesc('id');

        return $this->paginate($query->paginate((int) $request->input('per_page', 20)));
    }

    public function search(Request $request): JsonResponse
    {
        $keyword = $request->input('keyword', '');
        $items = VendorMeta::where('name', 'like', "%{$keyword}%")
            ->limit(50)
            ->get();

        return $this->success($items);
    }

    public function show(int $id): JsonResponse
    {
        $vendor = VendorMeta::find($id);
        if (! $vendor) {
            return $this->error('厂商不存在', 404);
        }

        return $this->success($vendor);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string'],
            'url' => ['nullable', 'string'],
        ]);

        return $this->success(VendorMeta::create($data), '厂商已创建');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string'],
            'url' => ['nullable', 'string'],
        ]);

        $vendor = VendorMeta::find($data['id']);
        if (! $vendor) {
            return $this->error('厂商不存在', 404);
        }

        $vendor->update($data);

        return $this->success($vendor->refresh(), '厂商已更新');
    }

    public function destroy(int $id): JsonResponse
    {
        $vendor = VendorMeta::find($id);
        if (! $vendor) {
            return $this->error('厂商不存在', 404);
        }

        $vendor->delete();

        return $this->success(null, '厂商已删除');
    }
}