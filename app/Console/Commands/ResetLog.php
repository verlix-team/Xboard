<?php

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Models\StatServer;
use App\Models\StatUser;
use Illuminate\Console\Command;
use App\Support\ChecksProcessingAuthority;

class ResetLog extends Command
{
    use ChecksProcessingAuthority;
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:log';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清空日志';

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
        $permit = $this->scanPermit('logRetention');
        if (!$permit || !$this->executionAllowed($permit)) return self::SUCCESS;
        StatUser::where('record_at', '<', strtotime('-2 month', time()))->delete();
        if (!$this->executionAllowed($permit)) return self::SUCCESS;
        StatServer::where('record_at', '<', strtotime('-2 month', time()))->delete();
        if (!$this->executionAllowed($permit)) return self::SUCCESS;
        AdminAuditLog::where('created_at', '<', strtotime('-3 month', time()))->delete();
        return self::SUCCESS;
    }
}
