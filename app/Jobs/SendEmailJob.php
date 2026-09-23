<?php

namespace App\Jobs;

use App\Services\MailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Support\ChecksProcessingAuthority;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ChecksProcessingAuthority;
    protected $params;
    protected ?array $processingPermit;

    public $tries = 3;
    public $timeout = 10;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($params, $queue = 'send_email', ?array $processingPermit = null)
    {
        $this->onQueue($queue);
        $this->params = $params;
        $this->processingPermit = $processingPermit ?? $this->scanPermit('notificationIntent');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (!$this->executionAllowed($this->processingPermit)) return;
        $mailLog = MailService::sendEmail($this->params);
        if ($mailLog['error']) {
            $this->release(); //发送失败将触发重试
        }
    }
}
