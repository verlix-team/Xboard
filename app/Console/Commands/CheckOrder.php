<?php

namespace App\Console\Commands;

use App\Jobs\OrderHandleJob;
use App\Services\OrderService;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\User;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

class CheckOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:order';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '订单检查任务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        Order::whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            // Java 用户订单由 Java 支付/订阅链路处理，避免两套扫描器重复履约。
            ->where('processor', '!=', 'JAVA_USER')
            ->orderBy('created_at', 'ASC')
            ->lazyById(200)
            ->each(function ($order) {
                OrderHandleJob::dispatch($order->trade_no);
            });
    }
}
