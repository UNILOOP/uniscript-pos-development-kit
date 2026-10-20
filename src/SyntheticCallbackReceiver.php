<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;
use RuntimeException;

/** Local synthetic host boundary; receipts never apply provider events to core state. */
final class SyntheticCallbackReceiver
{
    private const array SCOPE=['package_id','organization_id','location_id','connection_id','environment'];

    public function __construct(private readonly string $state,private readonly array $endpoints,private readonly string $payloadKey,private readonly SyntheticReplayAuthority $replays)
    {
        if(!extension_loaded('sodium')||strlen($payloadKey)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES||count($endpoints)>100){throw new InvalidArgumentException('callback_profile_unavailable');}
        $owners=[];
        foreach($endpoints as $id=>$endpoint){
            if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||!is_array($endpoint)||!is_string($endpoint['signing_key']??null)||strlen($endpoint['signing_key'])<32){throw new InvalidArgumentException('callback_endpoint_configuration');}
            foreach([...self::SCOPE,'provider_account_handle','execution_revision'] as $key){if(!is_string($endpoint[$key]??null)||$endpoint[$key]===''||strlen($endpoint[$key])>240){throw new InvalidArgumentException('callback_endpoint_scope');}}
            if(!in_array($endpoint['environment'],['local','sandbox','dev'],true)){throw new InvalidArgumentException('callback_production_disabled');}
            $owner=json_encode([$endpoint['package_id'],$endpoint['provider_account_handle'],$endpoint['environment']],JSON_THROW_ON_ERROR);
            if(isset($owners[$owner])&&$owners[$owner]!=array_intersect_key($endpoint,array_flip(self::SCOPE))){throw new InvalidArgumentException('callback_account_ownership_ambiguous');}
            $owners[$owner]=array_intersect_key($endpoint,array_flip(self::SCOPE));
        }
        foreach(['',$state.'/receipts',$state.'/payloads',$state.'/dedupe'] as $directory){if($directory!==''&&!is_dir($directory)){mkdir($directory,0700,true);}}
    }

    public function receive(string $endpointId,string $body,string $timestamp,string $deliveryId,string $signature):array
    {
        $endpoint=$this->endpoints[$endpointId]??throw new InvalidArgumentException('callback_unknown_endpoint');
        if(strlen($body)>262144||!preg_match('/\A[0-9]{10}\z/D',$timestamp)||abs(time()-(int)$timestamp)>300||!preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D',$deliveryId)||!preg_match('/\A[a-f0-9]{64}\z/D',$signature)||!hash_equals(hash_hmac('sha256',$timestamp.'.'.$deliveryId.'.'.$body,$endpoint['signing_key']),$signature)){throw new InvalidArgumentException('callback_unauthorized');}
        $payload=JsonDocument::decode($body,true);
        if(!is_array($payload)||!JsonValue::object($payload)||!is_array($payload['events']??null)||!array_is_list($payload['events'])||count($payload['events'])<1||count($payload['events'])>100){throw new InvalidArgumentException('callback_payload_invalid');}
        $hash=hash('sha256',$body);$namespace=json_encode([$endpoint['package_id'],$endpoint['provider_account_handle'],$endpoint['environment'],$deliveryId],JSON_THROW_ON_ERROR);
        $dedupe=$this->state.'/dedupe/'.hash('sha256',$namespace).'.json';
        return $this->locked(function()use($endpoint,$payload,$hash,$body,$deliveryId,$dedupe):array{
            if(is_file($dedupe)){$entry=JsonDocument::decode(file_get_contents($dedupe),true);if(!hash_equals($entry['body_sha256'],$hash)){throw new InvalidArgumentException('callback_duplicate_conflict');}$receipt=$this->read($entry['receipt_handle']);return ['receipt_handle'=>$receipt['receipt_handle'],'state'=>$receipt['state'],'duplicate'=>true];}
            $external=$payload['external_reference']??null;
            $matches=is_string($external)?($endpoint['references'][$external]??[]):[];
            if(!is_array($matches)||!array_is_list($matches)){$matches=[];}
            $scope=array_intersect_key($endpoint,array_flip(self::SCOPE));ksort($scope);$state='unmatched';$lineage=null;
            if(count($matches)===1){
                $candidate=$matches[0];$candidateScope=is_array($candidate)?array_intersect_key($candidate,array_flip(self::SCOPE)):[];ksort($candidateScope);
                if(is_array($candidate)&&$candidateScope===$scope&&is_string($candidate['order_handle']??null)&&is_string($candidate['attempt_handle']??null)&&is_string($candidate['execution_revision']??null)){$state='matched';$lineage=$candidate;}else{$state='review_required';}
            }elseif(count($matches)>1){$state='review_required';}
            $id=bin2hex(random_bytes(16));$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $this->write($this->state.'/payloads/'.$id.'.bin',$nonce.sodium_crypto_secretbox($body,$nonce,$this->payloadKey));
            $receipt=['receipt_handle'=>$id,...$scope,'provider_account_handle'=>$endpoint['provider_account_handle'],'delivery_id'=>$deliveryId,'body_sha256'=>$hash,'state'=>$state,'revision'=>1,'received_at'=>gmdate('Y-m-d\TH:i:s\Z'),'decoder_execution_revision'=>$lineage['execution_revision']??$endpoint['execution_revision'],'originating_order_handle'=>$lineage['order_handle']??null,'originating_attempt_handle'=>$lineage['attempt_handle']??null,'replay_lineage'=>[]];
            $this->write($this->state.'/receipts/'.$id.'.json',json_encode($receipt,JSON_THROW_ON_ERROR));
            $this->write($dedupe,json_encode(['receipt_handle'=>$id,'body_sha256'=>$hash],JSON_THROW_ON_ERROR));
            return ['receipt_handle'=>$id,'state'=>$state,'duplicate'=>false];
        });
    }

    public function replay(string $receiptHandle,string $grant):array
    {
        $claims=$this->replays->verify($grant);
        return $this->locked(function()use($claims,$receiptHandle):array{
            $receipt=$this->read($receiptHandle);
            if(($claims['receipt_handle']??null)!==$receiptHandle||($claims['expected_revision']??null)!==$receipt['revision']){throw new InvalidArgumentException('replay_receipt_or_revision_mismatch');}
            foreach(self::SCOPE as $key){if(($claims[$key]??null)!==$receipt[$key]){throw new InvalidArgumentException('replay_scope_mismatch');}}
            if($receipt['state']!=='matched'){throw new InvalidArgumentException('replay_requires_resolved_ownership');}
            $cipher=file_get_contents($this->state.'/payloads/'.$receiptHandle.'.bin');
            if($cipher===false||strlen($cipher)<SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+SODIUM_CRYPTO_SECRETBOX_MACBYTES){throw new RuntimeException('callback_payload_unavailable');}
            $body=sodium_crypto_secretbox_open(substr($cipher,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($cipher,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$this->payloadKey);
            if($body===false||!hash_equals($receipt['body_sha256'],hash('sha256',$body))){throw new RuntimeException('callback_payload_integrity');}
            $payload=JsonDocument::decode($body,true);$replayId=bin2hex(random_bytes(16));
            $receipt['replay_lineage'][]=['replay_handle'=>$replayId,'source_receipt_handle'=>$receiptHandle,'actor_id'=>$claims['actor_id'],'decoder_execution_revision'=>$receipt['decoder_execution_revision'],'created_at'=>gmdate('Y-m-d\TH:i:s\Z')];$receipt['revision']++;
            $this->write($this->state.'/receipts/'.$receiptHandle.'.json',json_encode($receipt,JSON_THROW_ON_ERROR));
            return ['replay_handle'=>$replayId,'receipt_handle'=>$receiptHandle,'decoder_execution_revision'=>$receipt['decoder_execution_revision'],'scope'=>array_intersect_key($receipt,array_flip(self::SCOPE)),'originating_order_handle'=>$receipt['originating_order_handle'],'originating_attempt_handle'=>$receipt['originating_attempt_handle'],'input'=>['authenticated_receipt'=>true,'receipt_handle'=>$receiptHandle,'events'=>$payload['events']],'applied'=>false,'revision'=>$receipt['revision']];
        });
    }

    private function read(string $id):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id)||!is_file($this->state.'/receipts/'.$id.'.json')){throw new InvalidArgumentException('receipt_not_found');}
        return JsonDocument::decode(file_get_contents($this->state.'/receipts/'.$id.'.json'),true);
    }

    private function locked(callable $operation):mixed
    {
        $lock=fopen($this->state.'/receipt.lock','c+');if($lock===false||!flock($lock,LOCK_EX)){throw new RuntimeException('callback_ledger_unavailable');}
        try{return $operation();}finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    private function write(string $path,string $contents):void
    {
        $temporary=$path.'.'.bin2hex(random_bytes(4)).'.tmp';if(file_put_contents($temporary,$contents,LOCK_EX)===false){throw new RuntimeException('callback_storage_failed');}chmod($temporary,0600);if(!rename($temporary,$path)){throw new RuntimeException('callback_storage_failed');}
    }
}
