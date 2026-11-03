<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root=dirname(__DIR__,2);
$port=(int)($argv[1] ?? 8080);
if ($port<1024 || $port>65535) { fwrite(STDERR,"Choose a port between 1024 and 65535.\n");exit(1); }
foreach (['curl','sqlite3','mbstring'] as $ext) {
    if (!extension_loaded($ext)) { fwrite(STDERR,"Missing PHP extension: $ext\n");exit(1); }
}
umask(0077);
$dir=$root.'/var/local';
if (!is_dir($dir)) mkdir($dir,0700,true);
if (is_link($dir) || (fileperms($dir)&0077)!==0) { fwrite(STDERR,"Unsafe local data directory.\n");exit(1); }
// Dedicated synthetic configuration: never load the operator's production .env here.
$envfile=$dir.'/.env';
if (is_link($envfile)) exit(1);
$origin='http://localhost:'.$port;
file_put_contents($envfile,"PAYMOVE_API_KEY=local-simulation-only\nPAYMOVE_PRODUCT_ID=local\nPAYMOVE_WEBHOOK_SECRET=local-simulation-only\nPAYMOVE_ENV=sandbox\nMAIL_FROM=shop@example.invalid\nMERCHANT_EMAIL=merchant@example.invalid\nSHOP_ORIGIN=$origin\nPANEL_PASSWORD_HASH=".password_hash('local-heartshop-only',PASSWORD_DEFAULT)."\n");
chmod($envfile,0600);
putenv('HEARTSHOP_LOCAL_SIMULATION=1');putenv('HEARTSHOP_ENV_FILE='.$envfile);putenv('HEARTSHOP_ORIGIN='.$origin);
fwrite(STDOUT,"Heartshop local simulation: $origin/heartshop/\nPanel: $origin/heartshop/panel/\nLocal-only password: local-heartshop-only\nNo real payments, shipments or email. Ctrl-C stops the server.\n");
$command=[PHP_BINARY,'-d','sendmail_path=/usr/bin/false','-S','127.0.0.1:'.$port,'-t',$root.'/public',__DIR__.'/router.php'];
$process=proc_open($command,[STDIN,STDOUT,STDERR],$pipes,$root);
exit(is_resource($process)?proc_close($process):1);
