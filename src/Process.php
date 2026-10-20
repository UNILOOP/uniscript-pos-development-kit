<?php

declare(strict_types=1);

namespace UniScript\Integration;

use RuntimeException;

final class Process
{
    public static function run(array $command, int $timeout = 120): string
    {
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) { throw new RuntimeException('process_start_failed'); }
        fclose($pipes[0]); stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $output='';$error='';$started=microtime(true);$status=[];
        do {
            $output.=stream_get_contents($pipes[1]);$error.=stream_get_contents($pipes[2]);
            if (strlen($output)+strlen($error)>4194304 || microtime(true)-$started>$timeout) { proc_terminate($process,9);throw new RuntimeException('process_limit'); }
            $status=proc_get_status($process);if (!$status['running']) { break; }usleep(20000);
        } while(true);
        $output.=stream_get_contents($pipes[1]);$error.=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
        if (($status['exitcode'] ?? $code)!==0) { throw new RuntimeException('platform_command_failed:'.substr($error,-500)); }
        return trim($output);
    }
}
