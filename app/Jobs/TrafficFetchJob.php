<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use App\Support\ChecksProcessingAuthority;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ChecksProcessingAuthority;
    protected $data;
    protected $server;
    protected $protocol;
    protected $timestamp;
    protected ?array $processingPermit;
    public $tries = 1;
    public $timeout = 20;

    public function __construct(array $server, array $data, $protocol, int $timestamp, ?array $processingPermit = null)
    {
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
        $this->processingPermit = $processingPermit ?? $this->scanPermit('nodeTrafficIngest');
    }

    public function handle(): void
    {
        if (!$this->executionAllowed($this->processingPermit)) return;
        $userIds = array_keys($this->data);

        foreach ($this->data as $uid => $v) {
            if (!$this->executionAllowed($this->processingPermit)) return;
            User::where('id', $uid)
                ->incrementEach(
                    [
                        'u' => $v[0] * $this->server['rate'],
                        'd' => $v[1] * $this->server['rate'],
                    ],
                    ['t' => time()]
                );
        }

        if (!empty($userIds)) {
            Redis::sadd('traffic:pending_check', ...$userIds);
        }
    }
}
