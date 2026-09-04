<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ensure callback UUIDs identify exactly one Xboard payment instance.
     */
    public function up(): void
    {
        if (!Schema::hasTable('v2_payment') || !Schema::hasColumn('v2_payment', 'uuid')) {
            throw new RuntimeException('v2_payment.uuid must be created by the Xboard base migration first.');
        }

        $duplicates = DB::table('v2_payment')
            ->select('uuid')
            ->groupBy('uuid')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicates) {
            throw new RuntimeException('Duplicate v2_payment.uuid rows must be reconciled before migration.');
        }

        // 本地参考库可能已在真实验证时手工建立同名索引，重复执行时不得再次创建。
        if (!Schema::hasIndex('v2_payment', 'uk_v2_payment_uuid')) {
            Schema::table('v2_payment', function (Blueprint $table) {
                $table->unique('uuid', 'uk_v2_payment_uuid');
            });
        }
    }

    /**
     * Remove only the callback UUID uniqueness introduced by this migration.
     */
    public function down(): void
    {
        if (Schema::hasTable('v2_payment')
            && Schema::hasIndex('v2_payment', 'uk_v2_payment_uuid')) {
            Schema::table('v2_payment', function (Blueprint $table) {
                $table->dropUnique('uk_v2_payment_uuid');
            });
        }
    }
};
