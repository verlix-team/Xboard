<?php
/** Read-only real DB/controller/view verification; optional exact seven cache invalidations. No SMTP. */
try {
    require '/www/vendor/autoload.php';
    $app = require '/www/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('database.connections.mysql.database') !== 'xboard') throw new RuntimeException('Unexpected database');
    $connection = Illuminate\Support\Facades\DB::connection();
    $connection->statement('SET TRANSACTION READ ONLY');
    $connection->beginTransaction();
    $controller = new App\Http\Controllers\V2\Admin\MailTemplateController();
    $vars = ['name' => 'Hop', 'url' => 'https://example.invalid', 'code' => '123456', 'link' => 'https://example.invalid/login',
        'subject' => 'Maintenance notice', 'content' => 'English notification.', 'ticketSubject' => 'Connection assistance', 'replyMessage' => 'Please try again.'];
    $renderer = new ReflectionMethod(App\Services\MailService::class, 'renderPlaceholders');
    $records = [];
    foreach (App\Models\MailTemplate::getNames() as $name) {
        $row = $controller->get(Illuminate\Http\Request::create('/', 'GET', ['name' => $name]))->getData(true)['data'];
        if (!$row['customized'] || $row['version'] === 'default') throw new RuntimeException('Missing initialized template');
        $subject = $renderer->invoke(null, $row['subject'], $vars);
        $body = $renderer->invoke(null, $row['content'], $vars);
        $fallback = view('mail.default.' . $name, $vars)->render();
        if (preg_match('/\p{Han}/u', $subject . $body . $fallback) || strpos($subject . $body, '{{') !== false
            || App\Models\MailTemplate::validateContent($name, $row['content']) || App\Models\MailTemplate::validateSubject($name, $row['subject'])) {
            throw new RuntimeException('English template verification failed');
        }
        $records[] = ['name' => $name, 'version' => $row['version'], 'english' => true, 'fallbackRendered' => true];
    }
    $connection->rollBack();
    $invalidated = 0;
    if (($argv[1] ?? '') === '--invalidate-template-cache') {
        foreach (App\Models\MailTemplate::getNames() as $name) {
            Illuminate\Support\Facades\Cache::forget('mail_template:' . $name);
            $invalidated++;
        }
    }
    echo json_encode(['result' => 'PASS', 'templates' => $records, 'invalidatedKeys' => $invalidated,
        'siteNameContainsChinese' => (bool) preg_match('/\p{Han}/u', (string) admin_setting('app_name', 'XBoard')),
        'smtpCalls' => 0]), PHP_EOL;
} catch (Throwable $error) {
    if (isset($connection) && $connection->transactionLevel()) $connection->rollBack();
    fwrite(STDERR, 'Mail verification failed: ' . get_class($error) . PHP_EOL);
    exit(1);
}
