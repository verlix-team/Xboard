<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add cross-runtime ownership and database-enforced active-order uniqueness.
     */
    public function up(): void
    {
        if (!Schema::hasTable('v2_order')) {
            throw new RuntimeException('v2_order must be created by the Xboard base migration first.');
        }

        if (!Schema::hasColumn('v2_order', 'processor')) {
            Schema::table('v2_order', function (Blueprint $table) {
                $table->string('processor', 16)
                    ->default('LEGACY')
                    ->comment('订单处理方:JAVA_USER/XBOARD_ADMIN/LEGACY')
                    ->after('paid_at');
            });
        }

        $duplicates = DB::table('v2_order')
            ->select('user_id')
            ->whereIn('status', [0, 1])
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicates) {
            throw new RuntimeException('Duplicate active v2_order rows must be reconciled before migration.');
        }

        if (!Schema::hasColumn('v2_order', 'active_user_id')) {
            Schema::table('v2_order', function (Blueprint $table) {
                $table->integer('active_user_id')
                    ->nullable()
                    ->storedAs('CASE WHEN status IN (0, 1) THEN user_id ELSE NULL END')
                    ->comment('待支付或开通中订单的用户唯一键')
                    ->after('processor');
                $table->unique('active_user_id', 'uk_v2_order_active_user');
                $table->index(['user_id', 'status'], 'idx_v2_order_user_status');
                $table->index(['plan_id', 'status', 'type'], 'idx_v2_order_capacity_reservation');
                $table->index(['processor', 'status', 'created_at'], 'idx_v2_order_processor_scan');
            });
        }
    }

    /**
     * Remove Java/Xboard shared-order hardening fields and indexes.
     */
    public function down(): void
    {
        if (!Schema::hasTable('v2_order')) {
            return;
        }
        Schema::table('v2_order', function (Blueprint $table) {
            if (Schema::hasColumn('v2_order', 'active_user_id')) {
                $table->dropUnique('uk_v2_order_active_user');
                $table->dropIndex('idx_v2_order_user_status');
                $table->dropIndex('idx_v2_order_capacity_reservation');
                $table->dropIndex('idx_v2_order_processor_scan');
                $table->dropColumn('active_user_id');
            }
            if (Schema::hasColumn('v2_order', 'processor')) {
                $table->dropColumn('processor');
            }
        });
    }
};
