<?php

/** One-off legacy xboard template initialization. No schema changes, queue reads or mail sends. */
use App\Services\EnglishMailDefaults;
use App\Models\MailTemplate;

try {
    require '/www/vendor/autoload.php';
    $apply = ($argv[1] ?? '--check') === '--apply';
    if (!in_array($argv[1] ?? '--check', ['--apply', '--check'], true)) throw new RuntimeException('Unknown action');
    if (is_file('/www/bootstrap/cache/config.php')) {
        $config = require '/www/bootstrap/cache/config.php';
        $db = $config['database']['connections']['mysql'];
    } else {
        $env = Dotenv\Dotenv::parse(file_get_contents('/www/.env'));
        $db = ['host' => $env['DB_HOST'], 'port' => $env['DB_PORT'] ?? 3306, 'database' => $env['DB_DATABASE'],
            'username' => $env['DB_USERNAME'], 'password' => $env['DB_PASSWORD']];
    }
    if ($db['database'] !== 'xboard') throw new RuntimeException('Unexpected database');
    $pdo = new PDO('mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306) . ';dbname=xboard;charset=utf8mb4',
        $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + ($db['options'] ?? []));
    if (!$apply) $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $names = array_keys(EnglishMailDefaults::SUBJECTS);
    if (count($names) !== 7 || count(MailTemplate::TEMPLATES) !== 7) throw new RuntimeException('Template contract mismatch');
    $statement = $pdo->prepare('SELECT id,name,subject,content,created_at,updated_at FROM v2_mail_templates WHERE name IN (' . implode(',', array_fill(0, count($names), '?')) . ') ORDER BY name' . ($apply ? ' FOR UPDATE' : ''));
    $statement->execute($names);
    $before = $statement->fetchAll(PDO::FETCH_ASSOC);
    $present = array_column($before, null, 'name');
    $result = ['action' => $apply ? 'apply' : 'check', 'database' => 'xboard', 'existing' => array_keys($present), 'inserted' => [], 'retained' => [], 'reviewRequired' => []];
    if ($apply) {
        if (!is_dir('/backup') || file_exists('/backup/templates-before.json')) throw new RuntimeException('Fresh restricted backup required');
        file_put_contents('/backup/templates-before.json', json_encode($before, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        chmod('/backup/templates-before.json', 0600);
    }
    foreach ($names as $name) {
        if (isset($present[$name])) {
            $result['retained'][] = $name;
            if (preg_match('/\p{Han}/u', $present[$name]['subject'] . $present[$name]['content'])) $result['reviewRequired'][] = $name;
            continue;
        }
        $subject = EnglishMailDefaults::subject($name);
        $content = EnglishMailDefaults::content($name, '/www/resources/views/mail/default');
        if (preg_match('/\p{Han}/u', $subject . $content) || MailTemplate::validateSubject($name, $subject) || MailTemplate::validateContent($name, $content)) throw new RuntimeException('Invalid English default');
        if ($apply) {
            $insert = $pdo->prepare('INSERT INTO v2_mail_templates(name,subject,content,created_at,updated_at) VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
            $insert->execute([$name, $subject, $content]);
            if ($insert->rowCount() !== 1) throw new RuntimeException('Unexpected inserted row count');
            $result['inserted'][] = ['name' => $name, 'id' => (int) $pdo->lastInsertId(), 'sha256' => hash('sha256', $subject . "\0" . $content)];
        }
    }
    if ($apply) $pdo->commit(); else $pdo->rollBack();
    $result['result'] = empty($result['reviewRequired']) ? 'PASS' : 'REVIEW_REQUIRED';
    if ($apply) {
        file_put_contents('/backup/templates-result.json', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        chmod('/backup/templates-result.json', 0600);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Mail initialization failed: ' . get_class($error) . PHP_EOL);
    exit(1);
}
