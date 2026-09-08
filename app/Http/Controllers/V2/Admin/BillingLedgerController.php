<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 提供 Java 管理的 Billing 账本只读运维视图。 */
class BillingLedgerController extends Controller
{
    public function agreements(Request $request)
    {
        if (!Schema::hasTable('java_billing_agreement')) return $this->success([]);
        $query = DB::table('java_billing_agreement')->select([
            'id', 'agreement_no', 'user_id', 'provider', 'payment_instance_id',
            'product_mapping_id', 'initial_order_id', 'plan_id', 'period',
            'locked_plan_amount_cents', 'locked_handling_amount_cents',
            'locked_charge_amount_cents', 'locked_currency', 'status',
            'current_period_start', 'current_period_end', 'next_charge_at',
            'cancel_at_period_end', 'retry_count', 'created_at', 'updated_at',
        ])->orderByDesc('id');
        if ($request->filled('user_id')) $query->where('user_id', (int) $request->input('user_id'));
        if ($request->filled('provider')) $query->where('provider', strtoupper((string) $request->input('provider')));
        if ($request->filled('status')) $query->where('status', strtoupper((string) $request->input('status')));
        return $this->success($query->paginate(min(100, max(1, (int) $request->input('per_page', 20)))));
    }

    public function cycles(Request $request)
    {
        if (!Schema::hasTable('java_billing_cycle')) return $this->success([]);
        $query = DB::table('java_billing_cycle')->select([
            'id', 'cycle_no', 'agreement_id', 'cycle_sequence', 'order_id',
            'order_trade_no', 'site_amount_cents', 'site_currency',
            'provider_amount_minor', 'provider_currency', 'provider_exponent',
            'provider_transaction_no', 'period_start', 'period_end', 'status',
            'failure_code', 'retry_count', 'next_retry_at', 'created_at', 'updated_at',
        ])->orderByDesc('id');
        if ($request->filled('agreement_id')) $query->where('agreement_id', (int) $request->input('agreement_id'));
        if ($request->filled('status')) $query->where('status', strtoupper((string) $request->input('status')));
        return $this->success($query->paginate(min(100, max(1, (int) $request->input('per_page', 20)))));
    }

    public function events(Request $request)
    {
        if (!Schema::hasTable('java_billing_event')) return $this->success([]);
        $query = DB::table('java_billing_event')->select([
            'id', 'provider', 'provider_event_no', 'payment_instance_id',
            'agreement_id', 'cycle_id', 'event_type', 'payload_sha256', 'status',
            'result_code', 'retry_count', 'next_retry_at', 'received_at', 'processed_at',
        ])->orderByDesc('id');
        if ($request->filled('provider')) $query->where('provider', strtoupper((string) $request->input('provider')));
        if ($request->filled('status')) $query->where('status', strtoupper((string) $request->input('status')));
        return $this->success($query->paginate(min(100, max(1, (int) $request->input('per_page', 20)))));
    }
}
