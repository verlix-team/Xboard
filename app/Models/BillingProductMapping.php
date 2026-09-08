<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Xboard 管理的套餐到 Stripe 或 Google Play 商品的不可变映射。 */
class BillingProductMapping extends Model
{
    protected $table = 'v2_billing_product_mapping';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'plan_id' => 'integer',
        'version' => 'integer',
        'enabled' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
