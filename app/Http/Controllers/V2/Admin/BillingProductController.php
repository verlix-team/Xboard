<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingProductMapping;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/** 管理带版本的渠道产品映射，PHP 不执行用户支付。 */
class BillingProductController extends Controller
{
    public function fetch(Request $request)
    {
        $query = BillingProductMapping::query()->orderBy('provider')->orderBy('plan_id')
            ->orderBy('period')->orderByDesc('version');
        if ($request->filled('provider')) {
            $query->where('provider', strtoupper((string) $request->input('provider')));
        }
        if ($request->filled('environment')) {
            $query->where('environment', strtoupper((string) $request->input('environment')));
        }
        return $this->success($query->get());
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer',
            'provider' => ['required', Rule::in(['STRIPE', 'GOOGLE_PLAY'])],
            'environment' => ['required', Rule::in(['TEST', 'PRODUCTION'])],
            'plan_id' => 'required|integer|exists:v2_plan,id',
            'period' => ['required', Rule::in([
                'monthly', 'quarterly', 'half_yearly', 'yearly',
                'two_yearly', 'three_yearly', 'onetime', 'reset_traffic',
            ])],
            'purchase_mode' => ['required', Rule::in(['ONE_TIME', 'AUTO_RENEW'])],
            'package_name' => 'nullable|string|max:255',
            'external_product_id' => 'required|string|max:255',
            'external_base_plan_id' => 'nullable|string|max:255',
            'external_offer_id' => 'nullable|string|max:255',
            'version' => 'required|integer|min:1',
            'enabled' => 'required|boolean',
        ]);
        $params['package_name'] = trim((string) ($params['package_name'] ?? ''));
        $params['external_product_id'] = trim((string) $params['external_product_id']);
        $params['external_base_plan_id'] = trim((string) ($params['external_base_plan_id'] ?? ''));
        $params['external_offer_id'] = trim((string) ($params['external_offer_id'] ?? ''));

        if ($params['external_product_id'] === '') {
            return $this->fail([422, '渠道产品 ID 不能为空']);
        }

        if ($params['provider'] === 'GOOGLE_PLAY'
            && ($params['package_name'] === '' || $params['external_base_plan_id'] === '')) {
            return $this->fail([422, 'Google Play 映射必须填写包名和基础方案 ID']);
        }

        $mapping = isset($params['id']) ? BillingProductMapping::find($params['id']) : null;
        if (isset($params['id']) && !$mapping) {
            return $this->fail([400202, 'Billing 产品映射不存在']);
        }
        if ($mapping && $this->isReferencedByBilling($mapping->id)) {
            return $this->fail([409, '该版本已被 Billing 协议引用，请新建更高版本']);
        }
        unset($params['id']);
        $mapping ? $mapping->update($params) : BillingProductMapping::create($params);
        return $this->success(true);
    }

    public function show(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer']);
        $mapping = BillingProductMapping::find($params['id']);
        if (!$mapping) {
            return $this->fail([400202, 'Billing 产品映射不存在']);
        }
        $mapping->enabled = !$mapping->enabled;
        return $this->success($mapping->save());
    }

    public function drop(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer']);
        $mapping = BillingProductMapping::find($params['id']);
        if (!$mapping) {
            return $this->fail([400202, 'Billing 产品映射不存在']);
        }
        if ($this->isReferencedByBilling($mapping->id)) {
            return $this->fail([409, '该版本已被 Billing 协议引用，只能停用']);
        }
        return $this->success($mapping->delete());
    }

    /** 跨运行时引用检查保持只读，并兼容 Java 稍后部署的情况。 */
    private function isReferencedByBilling(int $mappingId): bool
    {
        if (Schema::hasTable('java_billing_agreement')
            && Schema::hasColumn('java_billing_agreement', 'product_mapping_id')
            && DB::table('java_billing_agreement')->where('product_mapping_id', $mappingId)->exists()) {
            return true;
        }
        return Schema::hasTable('java_google_play_purchase_context')
            && Schema::hasColumn('java_google_play_purchase_context', 'product_mapping_id')
            && DB::table('java_google_play_purchase_context')
                ->where('product_mapping_id', $mappingId)
                ->exists();
    }
}
