<?php
declare(strict_types=1);
// Install outside the public document root. Never expose as an HTTP endpoint.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
if ($argc!==3 || !in_array($argv[1],['--status','--send','--check'],true) || !str_starts_with($argv[2],'/') || !is_file($argv[2])) {
    fwrite(STDERR,"Usage: php retry-mail.php --status|--send|--check /absolute/public/heartshop/api/lib.php\n");exit(2);
}
require $argv[2];
try {
    if ($argv[1]==='--status') {
        $q=db()->query('SELECT status,COUNT(*) AS count,MAX(attempts) AS max_attempts FROM mail_outbox GROUP BY status');
        $rows=[];while($row=$q->fetchArray(SQLITE3_ASSOC))$rows[]=$row;
        echo json_encode(['queue'=>$rows,'note'=>'sent means accepted by local mail transport, not delivered']).PHP_EOL;
        exit;
    }
    if ($argv[1]==='--check') {
        // Silent while healthy: any output makes cron mail it to MAILTO. Counts only, never message content.
        $now=time();
        $q=db()->querySingle('SELECT COUNT(*) AS unsent, MIN(created_at) AS oldest, MAX(attempts) AS attempts FROM mail_outbox WHERE status<>\'sent\'',true);
        $problems=[];
        if ((int)$q['unsent']>0 && $now-(int)$q['oldest']>900) $problems[]="{$q['unsent']} unsent, oldest ".intdiv($now-(int)$q['oldest'],60).' min';
        if ((int)$q['attempts']>=3) $problems[]="a message failed {$q['attempts']} times";
        $errors=dirname(privatePath()).'/mail-worker.err';
        clearstatcache();
        if (is_file($errors) && filesize($errors)>0 && $now-filemtime($errors)<3600) $problems[]='worker errors in the last hour (mail-worker.err)';
        if (!$problems) exit(0);
        echo 'Heartshop mail alert for '.shopOrigin().': '.implode('; ',$problems).'. Mail may not be reaching customers.'.PHP_EOL;
        exit(1);
    }
    // One sending process per shop, even when mail() blocks past a row lease.
    $lockPath=dirname(privatePath()).'/mail-worker.lock';
    if (is_link($lockPath)) throw new RuntimeException('Unsafe worker lock');
    $lock=fopen($lockPath,'c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { fwrite(STDERR,"Worker busy\n");exit(3); }
    $counts=processMailQueue(null,20);
    echo json_encode($counts).PHP_EOL;
    exit($counts['failed']>0?1:0);
} catch(Throwable $e) {
    logError($e,'mail_worker');fwrite(STDERR,"Worker failed; inspect protected operator logs\n");exit(1);
}
