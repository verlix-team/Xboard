#!/usr/bin/env python3
"""Enable one old-test email-only consumer after explicit authorization and empty-queue checks."""
import argparse
import datetime
import json
import os
from pathlib import Path
import subprocess
import time

IMAGE = 'xboard-mail-english:05524daf89fb'
IMAGE_ID = 'sha256:c7a046bea5346384302402ad9b7af6a1fdba708f2801de37511b9f055e5a0abf'
DIRECTORY = Path('/data/xboard-legacy-mail')
NAME = 'xboard-legacy-mail-1'
QUEUE_PROBE = r'''require '/www/vendor/autoload.php'; $app=require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r=Illuminate\Support\Facades\Redis::connection(config('queue.connections.redis.connection','default'));
$result=[]; foreach(['send_email','send_email_mass'] as $q) $result[$q]=[
'ready'=>$r->llen('queues:'.$q),'delayed'=>$r->zcard('queues:'.$q.':delayed'),'reserved'=>$r->zcard('queues:'.$q.':reserved')];
echo json_encode($result);'''


def run(*command):
    return subprocess.check_output(command, stdin=subprocess.DEVNULL, stderr=subprocess.PIPE)


def inspect(name):
    return json.loads(run('docker', 'inspect', name))[0]


def snapshot():
    ids = run('docker', 'ps', '-q').decode().split()
    return {c['Name']: [c['Id'], c['Image'], c['State']['StartedAt'], c['RestartCount']]
            for c in json.loads(run('docker', 'inspect', *ids)) if c['Name'] != '/' + NAME}


def write(path, value):
    descriptor = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(descriptor, 'w') as stream:
        stream.write(json.dumps(value, indent=2))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    if json.loads(run('docker', 'image', 'inspect', IMAGE))[0]['Id'] != IMAGE_ID:
        raise RuntimeError('Candidate image drift')
    if inspect('xboard-web-web-1')['Image'] != IMAGE_ID:
        raise RuntimeError('Web must use the same mail image')
    if DIRECTORY.exists() or run('docker', 'ps', '-aq', '--filter', 'name=^/' + NAME + '$').strip():
        raise RuntimeError('Worker directory/container already exists; inspect before retrying')
    queues = json.loads(run('docker', 'exec', 'xboard-web-web-1', 'php', '-r', QUEUE_PROBE))
    if any(count for q in queues.values() for count in q.values()):
        raise RuntimeError('Non-empty queue needs separate backlog authorization')
    worker = inspect('xboard-task512-workers-default-1')
    if worker['HostConfig']['NetworkMode'] != 'xboard-web_default' or worker['Config']['User'] != '1000:1000':
        raise RuntimeError('Unexpected existing worker runtime')
    env = dict(entry.split('=', 1) for entry in worker['Config']['Env'])
    environment = {'APP_ENV': env['APP_ENV'], 'QUEUE_CONNECTION': 'redis', 'XBOARD_SKIP_STARTUP_UPDATE': '1'}
    environment.update({key: 'false' for key in ['ENABLE_WEB', 'ENABLE_HORIZON', 'ENABLE_REDIS', 'ENABLE_WS_SERVER', 'ENABLE_CADDY']})
    volumes = [{'type': 'bind', 'source': m['Source'], 'target': m['Destination'], 'read_only': not m['RW']}
               for m in worker['Mounts'] if m['Type'] == 'bind']
    config = {'name': 'xboard-legacy-mail', 'services': {'mail': {
        'image': IMAGE, 'pull_policy': 'never', 'container_name': NAME, 'user': '1000:1000',
        'entrypoint': ['php', '/www/artisan', 'queue:work', 'redis'],
        'command': ['--name=legacy-mail', '--queue=send_email,send_email_mass', '--sleep=1', '--tries=3',
                    '--timeout=60', '--memory=128', '--max-jobs=1000', '--max-time=3600'],
        'environment': environment, 'volumes': volumes, 'networks': ['legacy'], 'restart': 'unless-stopped',
        'stop_grace_period': '60s', 'mem_limit': '256m',
        'healthcheck': {'test': ['CMD', 'php', '-r', "exit(str_contains(file_get_contents('/proc/1/cmdline'),'queue:work')?0:1);"],
                        'interval': '10s', 'timeout': '3s', 'retries': 3, 'start_period': '5s'},
        'logging': {'driver': 'json-file', 'options': {'max-size': '10m', 'max-file': '3'}}}},
        'networks': {'legacy': {'external': True, 'name': 'xboard-web_default'}}}
    if not args.apply:
        print(json.dumps({'result': 'PREFLIGHT_PASS', 'queues': queues, 'imageId': IMAGE_ID}))
        return
    before = snapshot()
    batch = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    backup = Path('/data/legacy-mail-backups') / (batch + '-mail-worker')
    backup.mkdir(mode=0o700, parents=True)
    write(backup / 'protected-before.json', before)
    write(backup / 'queues-before.json', queues)
    DIRECTORY.mkdir(mode=0o700)
    file = DIRECTORY / 'compose.yaml'
    write(file, config)
    command = ['docker', 'compose', '-f', str(file)]
    try:
        run(*command, 'up', '-d', '--no-build', '--pull', 'never', 'mail')
        for _ in range(20):
            current = inspect(NAME)
            if current['State'].get('Health', {}).get('Status') == 'healthy':
                break
            time.sleep(2)
        else:
            raise RuntimeError('Mail worker readiness timeout')
        if current['Image'] != IMAGE_ID or snapshot() != before or current['RestartCount'] != 0:
            raise RuntimeError('Protected runtime changed or worker restarted')
        result = {'result': 'ENABLED', 'name': NAME, 'imageId': IMAGE_ID, 'queues': ['send_email', 'send_email_mass'],
                  'restartCount': 0, 'otherContainersUnchanged': True, 'backup': str(backup), 'testEmailsSent': 0}
        write(backup / 'result.json', result)
        print(json.dumps(result))
    except Exception:
        run(*command, 'stop', 'mail')
        raise


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'result': 'FAILED', 'type': type(error).__name__}))
        raise SystemExit(1)
