<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;
use RuntimeException;

/** Trusted host/test client. Runtime URL and token must come from operator configuration. */
final class HostRuntimeClient
{
    private readonly string $endpoint;

    public function __construct(string $runtimeUrl,private readonly string $runtimeToken,private readonly int $timeoutSeconds=35)
    {
        $parts=parse_url($runtimeUrl);
        if(!is_array($parts)||!in_array($parts['scheme']??null,['http','https'],true)||!is_string($parts['host']??null)||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||(($parts['scheme']??null)==='http'&&!in_array($parts['host'],['127.0.0.1','localhost','::1','[::1]'],true))||strlen($runtimeToken)<32||strlen($runtimeToken)>8192||preg_match('/[\x00-\x20\x7f]/',$runtimeToken)||$timeoutSeconds<1||$timeoutSeconds>40){throw new InvalidArgumentException('runtime_client_configuration_invalid');}
        $this->endpoint=rtrim($runtimeUrl,'/').'/v1/invocations';
    }

    public function invoke(array $envelope,?string $expectedKind=null):Result
    {
        Invocation::fromArray($envelope);
        if($expectedKind!==null&&!in_array($expectedKind,Result::KINDS,true)){throw new InvalidArgumentException('expected_result_kind_invalid');}
        $context=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nAuthorization: Bearer ".$this->runtimeToken."\r\n",'content'=>json_encode($envelope,JSON_THROW_ON_ERROR),'timeout'=>min($this->timeoutSeconds,max(1,strtotime($envelope['deadline'])-time()+1)),'follow_location'=>0,'max_redirects'=>0,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        // Exactly one request. A lost response may follow an accepted provider operation.
        $response=@file_get_contents($this->endpoint,false,$context,0,1048577);$headers=http_get_last_response_headers();
        try{
            if($response===false||strlen($response)>1048576||!isset($headers[0])||!preg_match('~\s2\d\d(?:\s|$)~',$headers[0])){throw new RuntimeException('runtime_transport_failure');}
            $decoded=JsonDocument::decode($response,true);if(!is_array($decoded)){throw new RuntimeException('runtime_result_invalid');}
            $result=Result::fromArray($decoded);
        }catch(\Throwable){$result=new Result('unknown_remote_outcome','runtime.transport_lost',[],[],['action'=>'reconcile','resubmit_allowed'=>false]);}
        if($expectedKind!==null&&$result->kind!==$expectedKind){throw new RuntimeException('runtime_unexpected_result_kind');}
        return $result;
    }
}
