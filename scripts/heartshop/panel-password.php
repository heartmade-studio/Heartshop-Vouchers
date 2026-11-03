<?php
declare(strict_types=1);
// Prints the PANEL_PASSWORD_HASH line for a shop's private .env. The password is read without echo
// and never written anywhere; paste only the printed line into the .env, outside the repo.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$tty = stream_isatty(STDIN);
if ($tty) { fwrite(STDERR, 'Hasło do panelu (min. 12 znaków): '); shell_exec('stty -echo'); }
$password = rtrim((string)fgets(STDIN), "\r\n");
if ($tty) { shell_exec('stty echo'); fwrite(STDERR, PHP_EOL); }
if (mb_strlen($password) < 12) { fwrite(STDERR, "Za krótkie hasło: co najmniej 12 znaków.\n"); exit(2); }
echo 'PANEL_PASSWORD_HASH=' . password_hash($password, PASSWORD_DEFAULT) . PHP_EOL;
