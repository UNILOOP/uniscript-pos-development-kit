<?php

declare(strict_types=1);

namespace UniScript\Integration;

use Closure;
use InvalidArgumentException;

/** Local trusted simulator issuer. Production replay authority belongs to the host. */
final class SyntheticReplayAuthority
{
    public function __construct(private readonly string $key,private readonly ?Closure $clock=null)
    {
        if(strlen($key)!==32){throw new InvalidArgumentException('replay_key_length');}
    }

    public function issue(array $claims,int $ttl=60):string
    {
        $keys=['actor_id','receipt_handle','package_id','organization_id','location_id','connection_id','environment','expected_revision'];
        if(array_diff($keys,array_keys($claims))!==[]||array_diff(array_keys($claims),$keys)!==[]||$ttl<1||$ttl>60){throw new InvalidArgumentException('replay_claims');}
        foreach(array_diff($keys,['expected_revision']) as $key){if(!is_string($claims[$key])||$claims[$key]===''||strlen($claims[$key])>240){throw new InvalidArgumentException('replay_claims');}}
        if(!is_int($claims['expected_revision'])||$claims['expected_revision']<1){throw new InvalidArgumentException('replay_revision');}
        $payload=rtrim(strtr(base64_encode(json_encode(['purpose'=>'callback.replay','issued_at'=>$this->now(),'expires_at'=>$this->now()+$ttl,...$claims],JSON_THROW_ON_ERROR)),'+/','-_'),'=');
        return $payload.'.'.hash_hmac('sha256',$payload,$this->key);
    }

    public function verify(string $token):array
    {
        if(strlen($token)>8192||!preg_match('/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D',$token,$parts)||!hash_equals(hash_hmac('sha256',$parts[1],$this->key),$parts[2])){throw new InvalidArgumentException('replay_unauthorized');}
        $json=base64_decode(strtr($parts[1],'-_','+/'),true);if($json===false){throw new InvalidArgumentException('replay_unauthorized');}
        $claims=JsonDocument::decode($json,true);
        if(!is_array($claims)||($claims['purpose']??null)!=='callback.replay'||!is_int($claims['issued_at']??null)||!is_int($claims['expires_at']??null)||$claims['issued_at']>$this->now()||$claims['expires_at']<=$this->now()||$claims['expires_at']-$claims['issued_at']>60){throw new InvalidArgumentException('replay_expired_or_wrong_class');}
        return $claims;
    }

    private function now():int{return $this->clock===null?time():($this->clock)();}
}
