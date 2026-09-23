<?php

namespace App\Console;

use App\Services\Plugin\PluginManager;
use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;
use App\Services\ProcessingAuthorityService;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());
        // v2board
        $allowed = fn(string $taskCode) => fn() => app(ProcessingAuthorityService::class)->scanPermit($taskCode) !== null;
        $schedule->command('xboard:statistics')->dailyAt('0:10')->onOneServer()->when($allowed('dailyStatistics'));
        // check
        $schedule->command('check:order')->everyMinute()->onOneServer()->withoutOverlapping(5)
            ->when(fn() => $allowed('expiredOrder')() || $allowed('orderFulfillment')());
        $schedule->command('check:commission')->everyMinute()->onOneServer()->withoutOverlapping(5)->when($allowed('commissionConfirmation'));
        $schedule->command('check:ticket')->everyMinute()->onOneServer()->withoutOverlapping(5)->when($allowed('ticketAutoClose'));
        $schedule->command('check:traffic-exceeded')->everyMinute()->onOneServer()->withoutOverlapping(10)->runInBackground()->when($allowed('trafficExceeded'));
        $schedule->command('node:reconcile-users')->everyFiveMinutes()->onOneServer()->withoutOverlapping(10)->runInBackground()->when($allowed('nodeFullSync'));
        // reset
        $schedule->command('reset:traffic')->everyMinute()->onOneServer()->withoutOverlapping(10)->when($allowed('trafficReset'));
        $schedule->command('reset:log')->daily()->onOneServer()->when($allowed('logRetention'));
        // send
        $schedule->command('send:remindMail', ['--force'])->dailyAt('11:30')->onOneServer()->when($allowed('reminderMail'));
        // horizon metrics
        $schedule->command('horizon:snapshot')->everyFiveMinutes()->onOneServer();
        // cleanup stale online_count (GC for Redis TTL expiration)
        $schedule->command('cleanup:online-status')->everyFiveMinutes()->onOneServer()->when($allowed('onlineStatusCleanup'));
        // backup Timing
        // if (env('ENABLE_AUTO_BACKUP_AND_UPDATE', false)) {
        //     $schedule->command('backup:database', ['true'])->daily()->onOneServer();
        // }
        app(PluginManager::class)->registerPluginSchedules($schedule);

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        try {
            app(PluginManager::class)->initializeEnabledPlugins();
        } catch (\Exception $e) {
        }
        require base_path('routes/console.php');
    }
}
