<?php

declare(strict_types=1);

namespace UniScript\Integration;

use Closure;
use RuntimeException;

/** Trusted supervisor: package runs only in an external networkless container. */
final class RuntimeProcess
{
    public static function invoke(array $command, array $envelope, Closure $services): Result
    {
        $container=null;
        if(($command[0]??null)==='docker'&&($command[1]??null)==='run'){
            $nameIndex=array_search('--name',$command,true);
            if($nameIndex===false){$container='uniscript-invocation-'.bin2hex(random_bytes(16));array_splice($command,2,0,['--name',$container]);}
            else{$container=$command[$nameIndex+1]??null;}
            if(!is_string($container)||!preg_match('/\Auniscript-invocation-[a-f0-9]{32}\z/D',$container)){throw new RuntimeException('invalid_runtime_container_name');}
        }
        // Validate before any untrusted process starts.
        $invocation=Invocation::fromArray($envelope);
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) { throw new RuntimeException('runtime_start_failed'); }
        fwrite($pipes[0],json_encode($envelope,JSON_THROW_ON_ERROR)."\n");fflush($pipes[0]);
        stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $buffer='';$result=null;$calls=0;$started=microtime(true);$status=[];
        try {
            do {
                $chunk=stream_get_contents($pipes[1]);$buffer.=$chunk;stream_get_contents($pipes[2],65536);
                if (strlen($buffer)>1048576 || microtime(true)-$started>30 || strtotime($envelope['deadline'])<=time()) { throw new RuntimeException('runtime_limit'); }
                while (($newline=strpos($buffer,"\n"))!==false) {
                    $line=substr($buffer,0,$newline);$buffer=substr($buffer,$newline+1);
                    $message=json_decode($line,true,32,JSON_THROW_ON_ERROR);
                    if (isset($message['service_request'])) {
                        $request=$message['service_request'];$calls++;
                        // SDK checks are conveniences; this is the trusted supervisor boundary.
                        if ($calls>$envelope['request_budget'] || ($request['invocation_id']??null)!==$envelope['invocation_id'] || !hash_equals($envelope['service_grant'],(string)($request['grant']??''))) { throw new RuntimeException('service_grant_mismatch'); }
                        try { $reply=['response'=>$services($request)]; } catch (\Throwable) { $reply=['error'=>'service_denied']; }
                        fwrite($pipes[0],json_encode($reply,JSON_THROW_ON_ERROR)."\n");fflush($pipes[0]);
                    } else {
                        if ($result!==null) { throw new RuntimeException('multiple_results'); }
                        $result=Result::fromArray($message);
                    }
                }
                $status=proc_get_status($process);
                if (!$status['running']) { break; }usleep(10000);
            } while(true);
            if ($result===null || trim($buffer)!=='' || ($status['exitcode']??-1)!==0) { throw new RuntimeException('runtime_result_missing'); }
            return $result;
        } catch (\Throwable) {
            return new Result('unknown_remote_outcome','runtime.worker_lost',[],[],['action'=>'reconcile','resubmit_allowed'=>false]);
        } finally {
            if (($status['running']??true)===true) { proc_terminate($process,9); }
            foreach($pipes as $pipe){fclose($pipe);}proc_close($process);
            // Killing the Docker client does not kill its container; remove it explicitly.
            if($container!==null){try{Process::run(['docker','rm','-f',$container],15);}catch(\Throwable $cleanup){if(!str_contains($cleanup->getMessage(),'No such container')){throw new RuntimeException('runtime_container_cleanup_failed');}}}
        }
    }

    public static function flags(): array
    {
        return ['--network','none','--read-only','--user','10001:10001','--cap-drop','ALL','--security-opt','no-new-privileges','--pids-limit','32','--memory','256m','--cpus','0.5','--tmpfs','/tmp:rw,noexec,nosuid,size=16m,uid=10001,gid=10001'];
    }
}
