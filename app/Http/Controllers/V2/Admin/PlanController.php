<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanSave;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanTranslationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    public function __construct(private PlanTranslationService $translations) {}

    public function fetch(Request $request)
    {
        $plans = Plan::orderBy('sort', 'ASC')
            ->with([
                'group:id,name'
            ])
            ->withCount([
                'users',
                'users as active_users_count' => function ($query) {
                    $query->where(function ($q) {
                        $q->where('expired_at', '>', time())
                          ->orWhereNull('expired_at');
                    });
                }
            ])
            ->get();

        $available = $this->translations->available();
        $directory = $available ? $this->translations->directory($plans->modelKeys()) : [];
        foreach ($plans as $plan) {
            $rows = $directory[$plan->id] ?? [];
            $defaults = array_values(array_filter($rows, fn ($row) => $row['isDefault']));
            $plan->setAttribute('translations', array_map(fn ($row) => array_diff_key($row, ['isDefault' => true]), $rows));
            $plan->setAttribute('defaultLocale', $defaults[0]['locale'] ?? null);
            $plan->setAttribute('translationVersion', PlanTranslationService::version($rows));
            $plan->setAttribute('translationsAvailable', $available);
        }
        return $this->success($plans);
    }

    public function save(PlanSave $request)
    {
        $params = $request->validated();
        if (!$this->translations->available()) {
            return response()->json(['data' => false, 'message' => '套餐国际化结构尚未迁移，请先执行Java V026'], 503);
        }
        $rows = PlanTranslationService::normalize($params['translations'], $params['defaultLocale']);
        $expectedVersion = $params['translationVersion'] ?? null;
        unset($params['translations'], $params['defaultLocale'], $params['translationVersion']);
        DB::beginTransaction();
        try {
            if ($request->input('id')) {
                $plan = Plan::where('id', $request->input('id'))->lockForUpdate()->first();
                if (!$plan) {
                    DB::rollBack();
                    return $this->fail([400202, '该订阅不存在']);
                }
                $currentRows = $this->translations->directory([$plan->id])[$plan->id] ?? [];
                if (!is_string($expectedVersion) || !hash_equals(PlanTranslationService::version($currentRows), $expectedVersion)) {
                    DB::rollBack();
                    return response()->json(['data' => false, 'message' => '套餐翻译已被其他管理员修改，请重新打开后编辑'], 409);
                }
                // 纯翻译变更不能因勾选旧表单force_update而重复同步全部用户权益。
                $plan->fill($params);
                if ($request->boolean('force_update') && $plan->isDirty(['group_id', 'transfer_enable', 'speed_limit', 'device_limit'])) {
                    User::where('plan_id', $plan->id)->update([
                        'group_id' => $params['group_id'],
                        'transfer_enable' => $params['transfer_enable'] * 1073741824,
                        'speed_limit' => $params['speed_limit'],
                        'device_limit' => $params['device_limit'],
                    ]);
                }
                if (!$plan->save()) throw new \RuntimeException('套餐保存未成功');
            } else {
                $plan = Plan::create($params);
            }
            $this->translations->save($plan->id, $rows);
            DB::commit();
            return $this->success(true);
        } catch (\Throwable $e) {
            DB::rollBack();
            // SQL异常可能含描述或名称参数，不记录完整异常和请求体。
            Log::error('套餐翻译保存失败', ['exception_type' => get_class($e)]);
            return $this->fail([500, '保存失败']);
        }
    }

    public function drop(Request $request)
    {
        if (Order::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在订单无法删除']);
        }
        if (User::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在用户无法删除']);
        }
        
        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }
        
        return $this->success($plan->delete());
    }

    public function update(Request $request)
    {
        $updateData = $request->only([
            'show',
            'renew',
            'sell'
        ]);

        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        try {
            $plan->update($updateData);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array'
        ]);

        try {
            DB::beginTransaction();
            foreach ($params['ids'] as $k => $v) {
                if (!Plan::find($v)->update(['sort' => $k + 1])) {
                    throw new \Exception();
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }
}
