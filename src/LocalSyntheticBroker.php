<?php

declare(strict_types=1);

namespace UniScript\Integration;

use PDO;
use RuntimeException;

/** Standalone developer simulator; no UniScript models, production credentials or core mutations. */
final class LocalSyntheticBroker
{
    private PDO $db;
    public function __construct(string $database)
    {
        $this->db=new PDO('sqlite:'.$database,options:[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS grants (hash TEXT PRIMARY KEY, invocation TEXT NOT NULL, scope TEXT NOT NULL, operation TEXT NOT NULL, logical_key TEXT NOT NULL, expires INTEGER NOT NULL, remaining INTEGER NOT NULL, resources TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS records (scope TEXT NOT NULL, record_key TEXT NOT NULL, revision INTEGER NOT NULL, value TEXT NOT NULL, PRIMARY KEY(scope,record_key))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS transports (scope TEXT NOT NULL, logical_key TEXT NOT NULL, body_hash TEXT NOT NULL, status TEXT NOT NULL, handle TEXT, provider_reference TEXT, PRIMARY KEY(scope,logical_key))');
    }

    public function register(array $envelope,array $resources=[]):void
    {
        Invocation::fromArray($envelope);foreach($resources as $name=>$definition){if(!is_string($name)||!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D',$name)){throw new RuntimeException('resource_name_invalid');}ResourceSchema::assertDefinition(JsonDocument::decode(json_encode($definition,JSON_THROW_ON_ERROR)));}
        if($envelope['environment']!=='sandbox'||($envelope['deployment_environment']??null)!=='local'){throw new RuntimeException('synthetic_scope_required');}
        $scope=[];foreach(['package_id','organization_id','location_id','connection_id','environment'] as $key){$scope[$key]=$envelope[$key];}
        $statement=$this->db->prepare('INSERT INTO grants VALUES (?,?,?,?,?,?,?,?)');$statement->execute([hash('sha256',$envelope['service_grant']),$envelope['invocation_id'],json_encode($scope,JSON_THROW_ON_ERROR),$envelope['operation'],$envelope['idempotency_key'],strtotime($envelope['deadline']),$envelope['request_budget'],json_encode($resources,JSON_THROW_ON_ERROR)]);
    }

    public function request(array $request):array
    {
        if(array_diff(array_keys($request),['invocation_id','grant','service','action','arguments'])!==[]||!is_string($request['grant']??null)||!is_string($request['invocation_id']??null)||!is_array($request['arguments']??null)){throw new RuntimeException('invalid_service_request');}
        $hash=hash('sha256',$request['grant']);$this->db->beginTransaction();
        try{
            $consume=$this->db->prepare('UPDATE grants SET remaining=remaining-1 WHERE hash=? AND invocation=? AND expires>? AND remaining>0');$consume->execute([$hash,$request['invocation_id'],time()]);if($consume->rowCount()!==1){throw new RuntimeException('grant_denied');}
            $query=$this->db->prepare('SELECT * FROM grants WHERE hash=?');$query->execute([$hash]);$grant=$query->fetch(PDO::FETCH_ASSOC);$this->db->commit();
        }catch(\Throwable $error){$this->db->rollBack();throw $error;}
        $args=$request['arguments'];if(array_intersect(array_keys($args),['organization_id','connection_id','location_id','environment','credential','token','secret','url','method','path','headers'])!==[]){throw new RuntimeException('scope_or_transport_override');}
        $scope=hash('sha256',$grant['scope']);$key=$request['service'].'.'.$request['action'];
        $allowed=match($grant['operation']){'orders.submit','orders.reconcile'=>['http.request'],'reference.refresh'=>['storage.read','storage.write'],'catalog.preview'=>['resources.read'],'inbound.decode'=>['commands.propose'],default=>[]};if(!in_array($key,$allowed,true)){throw new RuntimeException('operation_service_denied');}
        return match($key){
            'http.request'=>$this->http($scope,$grant,$args),
            'storage.read'=>$this->read($scope,$args,JsonDocument::decode($grant['resources'],true)),
            'storage.write'=>$this->write($scope,$args,JsonDocument::decode($grant['resources'],true)),
            'resources.read'=>($args['resource']??null)==='catalog'?['records'=>[['id'=>'synthetic-catalog-a','name'=>'Synthetic saline']]]:$this->reference($scope,$args),
            'commands.propose'=>($args['command']??null)==='normalized_event'?['proposal_handle'=>'proposal-'.hash('sha256',$scope.json_encode($args,JSON_THROW_ON_ERROR)),'applied'=>false]:throw new RuntimeException('command_not_supported'),
            default=>throw new RuntimeException('service_not_supported'),
        };
    }

    private function http(string $scope,array $grant,array $args):array
    {
        if(array_diff(array_keys($args),['profile','operation_key','parameters','idempotency_key','body'])!==[]||($args['operation_key']??null)!==$grant['operation']||($args['idempotency_key']??null)!==$grant['logical_key']||!is_array($args['parameters']??null)){throw new RuntimeException('http_profile_denied');}
        $profile=$args['profile']??'';
        if(!in_array($profile,['synthetic.orders','synthetic.reconcile'],true)||$grant['operation']!==($profile==='synthetic.orders'?'orders.submit':'orders.reconcile')||$args['parameters']!==[]){throw new RuntimeException('http_profile_denied');}
        $query=$this->db->prepare('SELECT * FROM transports WHERE scope=? AND logical_key=?');$query->execute([$scope,$grant['logical_key']]);$old=$query->fetch(PDO::FETCH_ASSOC);
        $body=json_encode($args['body']??[],JSON_THROW_ON_ERROR);$bodyHash=hash('sha256',$body);
        if($profile==='synthetic.orders'&&$old!==false){if(!hash_equals($old['body_hash'],$bodyHash)){throw new RuntimeException('idempotency_conflict');}return $old['status']==='accepted'?['status'=>'accepted','external_reference_handle'=>$old['handle']]:['status'=>'unknown'];}
        if($profile==='synthetic.orders'){$insert=$this->db->prepare('INSERT INTO transports VALUES (?,?,?,?,NULL,NULL)');$insert->execute([$scope,$grant['logical_key'],$bodyHash,'unknown']);}
        $method=$profile==='synthetic.orders'?'POST':'GET';$path=$profile==='synthetic.orders'?'/orders':'/orders/reconcile';
        $credentials='Basic '.base64_encode((getenv('MOCK_POS_USER')?:'').':'.(getenv('MOCK_POS_PASSWORD')?:''));
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>"Content-Type: application/json\r\nAuthorization: ".$credentials."\r\nX-Location-Id: ".$scope."\r\nIdempotency-Key: ".hash('sha256',$scope.':'.$grant['logical_key'])."\r\n",'content'=>$method==='POST'?$body:'','timeout'=>5,'follow_location'=>0,'ignore_errors'=>true]]);
        $response=@file_get_contents('http://mock-pos:8080'.$path,false,$context,0,1048577);$headers=http_get_last_response_headers();
        if($response===false||strlen($response)>1048576||!isset($headers[0])||!preg_match('~\s2\d\d\s~',$headers[0])){return ['status'=>'unknown'];}
        $decoded=JsonDocument::decode($response,true);if(($decoded['status']??null)!=='accepted'||!is_string($decoded['reference']??null)){return ['status'=>'unknown'];}
        $handle=$old!==false&&is_string($old['handle'])?$old['handle']:'external-'.bin2hex(random_bytes(16));$update=$this->db->prepare('UPDATE transports SET status=?,handle=?,provider_reference=? WHERE scope=? AND logical_key=?');$update->execute(['accepted',$handle,$decoded['reference'],$scope,$grant['logical_key']]);
        return ['status'=>'accepted','external_reference_handle'=>$handle];
    }

    private function read(string $scope,array $args,array $resources):array
    {
        if(!isset($resources[$args['key']??''])){throw new RuntimeException('resource_not_declared');}
        $query=$this->db->prepare('SELECT revision,value FROM records WHERE scope=? AND record_key=?');$query->execute([$scope,$args['key']??'']);$record=$query->fetch(PDO::FETCH_ASSOC);return ['record'=>$record===false?null:['revision'=>(int)$record['revision'],'value'=>JsonDocument::decode($record['value'],true)]];
    }
    private function write(string $scope,array $args,array $resources):array
    {
        if(!is_string($args['key']??null)||!preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D',$args['key'])||!is_int($args['expected_revision']??null)||!is_array($args['value']??null)){throw new RuntimeException('storage_invalid');}
        $definition=$resources[$args['key']]??throw new RuntimeException('resource_not_declared');ResourceSchema::validateValues(JsonDocument::decode(json_encode($definition,JSON_THROW_ON_ERROR)),$args['value']);
        $this->db->beginTransaction();try{$old=$this->read($scope,$args,$resources)['record'];$revision=$old['revision']??0;if($args['expected_revision']!==$revision){throw new RuntimeException('stale_revision');}$query=$this->db->prepare('INSERT INTO records VALUES (?,?,?,?) ON CONFLICT(scope,record_key) DO UPDATE SET revision=excluded.revision,value=excluded.value');$query->execute([$scope,$args['key'],$revision+1,json_encode($args['value'],JSON_THROW_ON_ERROR)]);$this->db->commit();return ['revision'=>$revision+1,'value'=>$args['value']];}catch(\Throwable $error){$this->db->rollBack();throw $error;}
    }
    private function reference(string $scope,array $args):array
    {
        if(($args['resource']??null)!=='external_reference'||!is_string($args['external_reference_handle']??null)){throw new RuntimeException('resource_denied');}$query=$this->db->prepare('SELECT handle FROM transports WHERE scope=? AND handle=? AND status=?');$query->execute([$scope,$args['external_reference_handle'],'accepted']);$value=$query->fetchColumn();return $value===false?['status'=>'not_found']:['status'=>'accepted','external_reference_handle'=>$value];
    }
}
