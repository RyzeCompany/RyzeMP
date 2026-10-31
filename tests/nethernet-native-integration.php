<?php
declare(strict_types=1);
/** Real TCP signaling + shared UDP RakLib owner + two native WebRTC clients. */
$autoload = getenv('RYZE_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
require $autoload;
foreach(['NetherNetDatagramClassifier','NetherNetSharedPortSocket','NetherNetFrameCodec','NetherNetIdentityAssertionVerifier',
    'NetherNetServerIdentity','NetherNetSharedPortSdp','NetherNetSharedPortSignalingBridge','NetherNetHttpServer','NetherNetTransport'] as $class){
    require_once dirname(__DIR__).'/src/network/mcpe/nethernet/'.$class.'.php';
}
require __DIR__.'/nethernet-fixtures.php';
use pocketmine\network\mcpe\nethernet\NetherNetSharedPortSocket as SharedSocket;
use pocketmine\network\mcpe\nethernet\NetherNetSharedPortSignalingBridge as Bridge;
use pocketmine\network\mcpe\nethernet\NetherNetIdentityAssertionVerifier as Verifier;
use pocketmine\network\mcpe\nethernet\NetherNetServerIdentity as Identity;
use pocketmine\network\mcpe\nethernet\NetherNetTransport as Transport;
use pocketmine\network\mcpe\nethernet\NetherNetHttpServer as Http;
use pocketmine\network\mcpe\nethernet\NetherNetFrameCodec as Frames;
use pmmp\thread\ThreadSafeArray;
use raklib\utils\InternetAddress;
use RyzeRTC\PeerConnection;
function ensure(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}}
$fixture=new NetherNetFixtures();$verifier=Verifier::fromOfficialJwks($fixture->jwks());
$commands=new ThreadSafeArray();$observations=new ThreadSafeArray();
$directory=sys_get_temp_dir().'/ryze-native-'.bin2hex(random_bytes(6));mkdir($directory,0700);
$reserve=socket_create(AF_INET,SOCK_DGRAM,SOL_UDP);socket_bind($reserve,'127.0.0.1',0);socket_getsockname($reserve,$ip,$port);socket_close($reserve);
$accepted=[];$closed=[];$messages=[];$logs=[];$http=null;$transport=null;$public=null;$clients=[];$rakClient=null;
try{
    $bridge=new Bridge(static function(string $ufrag)use($commands):void{$commands[]='+'.$ufrag;},static function(string $ufrag)use($commands):void{$commands[]='-'.$ufrag;});
    $transport=new Transport($port,new Identity($directory.'/key.pem'),$bridge,fn()=>$verifier,
        static function(int $request,int $code,string $sdp)use(&$http):bool{return $http->respond($request,$code,$sdp,$code===200?'application/sdp':'text/plain');},
        static function(int $id,string $ip,int $peerPort,array $identity,Closure $send,Closure $close)use(&$accepted):void{
            ensure($ip==='127.0.0.1' && $peerPort>0,'Wrong selected public UDP endpoint');
            ensure($identity['xuid']==='987654','Unauthenticated player accepted');
            $accepted[$id]=['send'=>$send,'close'=>$close,'port'=>$peerPort];
        },
        static function(int $id,string $data)use(&$messages,&$accepted):void{$messages[$id][]=$data;($accepted[$id]['send'])($data,false,null);},
        static function(int $id,string $reason)use(&$closed):void{$closed[$id]=$reason;},
        static function(string $message)use(&$logs):void{$logs[]=$message;fwrite(STDERR,$message."\n");});
    $public=new SharedSocket(new InternetAddress('127.0.0.1',$port,4),$transport->getBackendPort(),$commands,$observations);$public->setBlocking(false);
    $http=new Http('127.0.0.1',$port,fn()=>['name'=>'RyzeMP test','protocol'=>2225,'version'=>'1.26.60','level'=>'test','players'=>count($accepted),'maxPlayers'=>20,'gameType'=>0],
        fn(int $request,string $networkId,string $sdp,string $host,string $ip)=>$transport->offer($request,$networkId,$sdp,'127.0.0.1',$ip),
        $transport->cancelRequest(...));$http->start();
    $rakClient=socket_create(AF_INET,SOCK_DGRAM,SOL_UDP);socket_bind($rakClient,'127.0.0.1',0);socket_set_nonblock($rakClient);
    $rakPayload="\x01".str_repeat("\x55",32);$rakReceived=0;
    $pump=static function()use($http,$transport,$public,$observations,&$rakReceived,$rakPayload):void{
        $source=$sourcePort=null;
        for($i=0;$i<32;++$i){$packet=$public->readPacket($source,$sourcePort);if($packet===null){break;}ensure($packet===$rakPayload,'WebRTC packet leaked to RakNet');++$rakReceived;}
        $events=[];while(($event=$observations->shift())!==null){$events[]=json_decode($event,true,flags:JSON_THROW_ON_ERROR);}
        $transport->observePaths($events);$transport->tick();$http->tick();usleep(1000);
    };
    $request=static function(string $wire)use($port,$pump):string{
        $s=stream_socket_client('tcp://127.0.0.1:'.$port,$error,$reason,2);stream_set_blocking($s,false);
        // Deliberately split body and headers across writes.
        foreach(str_split($wire,173) as $part){ensure(fwrite($s,$part)===strlen($part),'HTTP partial request write failed');$pump();}
        $response='';$deadline=microtime(true)+12;
        while(microtime(true)<$deadline){$pump();$response.=stream_get_contents($s);if(feof($s)){break;}}
        fclose($s);return $response;
    };
    $until=static function(Closure $condition)use($pump):void{$deadline=microtime(true)+12;while(microtime(true)<$deadline && !$condition()){$pump();}ensure($condition(),'Integration phase timed out');};
    $until(fn()=>$transport->isReady());
    $response=$request("GET /v1/join HTTP/1.1\r\nHost: 127.0.0.1:$port\r\n\r\n");
    ensure(str_starts_with($response,'HTTP/1.1 200 '),'GET capability failed');
    $meta=json_decode(explode("\r\n\r\n",$response,2)[1],true,flags:JSON_THROW_ON_ERROR);ensure($meta['protocol']===2225,'Wrong advertised protocol');
    $bad=$request("POST /v1/join/bad HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nContent-Type: application/sdp\r\nContent-Length: 4\r\n\r\nv=0\n");ensure(str_starts_with($bad,'HTTP/1.1 401 '),'Unsigned offer was not rejected');
    $smuggle=$request("POST /v1/join/bad HTTP/1.1\r\nHost: test\r\nContent-Length: 1\r\nContent-Length: 2\r\n\r\na");ensure(str_starts_with($smuggle,'HTTP/1.1 400 '),'Ambiguous content-length accepted');
    for($attempt=0;$attempt<4;++$attempt){
        $client=new PeerConnection('127.0.0.1',0,false);$clients[]=$client;
        $reliable=$client->createDataChannel('ReliableDataChannel',true,true);
        $unreliable=$client->createDataChannel('UnreliableDataChannel',false,false);$client->beginOffer();
        $deadline=microtime(true)+5;$gathered=false;
        while(microtime(true)<$deadline && !$gathered){foreach($client->pollEvents()as$event){if($event['type']==='gathering'&&$event['data']==='2'){$gathered=true;}}$pump();}
        ensure($gathered,'Client failed full ICE gathering');
        $offer=$fixture->offer($client->localDescription());
        $response=$request("POST /v1/join/".($attempt<2 ? "concurrent-$attempt" : "reconnect")." HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nContent-Type: application/sdp\r\nContent-Length: ".strlen($offer)."\r\n\r\n".$offer);
        ensure(str_starts_with($response,'HTTP/1.1 200 '),'SDP exchange failed: '.substr($response,0,100));
        $answer=explode("\r\n\r\n",$response,2)[1];NetherNetFixtures::verifyAnswer($answer);
        ensure(preg_match('/^a=candidate:[^\r\n]+ 127\.0\.0\.1 '.$port.' typ host\r?$/m',$answer)===1,'Answer leaked private backend port');
        $client->setRemoteAnswer(preg_replace('/^a=identity:[^\r\n]+\r\n/m','',$answer));
        $ready=[];$received=[];$decoder=new Frames();$deadline=microtime(true)+12;$sent=false;
        $payload="BEDROCK\x00\xff".str_repeat('Ryze',35000);$unreliablePayload="MOVE\x00";
        while(microtime(true)<$deadline && count($received)<2){
            $pump();
            foreach($client->pollEvents() as $event){
                if($event['type']==='channel_open'){$ready[$event['channel']]=true;}
                if($event['type']==='message'){$decoded=$decoder->receive($event['data'],true);if($decoded!==null){$received[]=$decoded;}}
                ensure($event['type']!=='overflow','Native client event overflow');
            }
            if(isset($ready[$reliable],$ready[$unreliable]) && !$sent){
                foreach(Frames::fragment($payload,16384,true)as$frame){$client->sendMessage($reliable,$frame);}
                $client->sendMessage($unreliable,"\x00".$unreliablePayload);$sent=true;
                socket_sendto($rakClient,$rakPayload,strlen($rakPayload),0,'127.0.0.1',$port);
            }
        }
        ensure($sent,'Native channels did not open');ensure(count($received)===2,'Bidirectional framed messages not delivered');
        ensure(in_array($payload,$received,true)&&in_array($unreliablePayload,$received,true),'Binary SCTP corruption');
        ensure(count($accepted)===$attempt+1,'Session open callback missing');
        $lastId=array_key_last($accepted);
        if($attempt === 0){continue;}
        if($attempt === 1){($accepted[array_key_first($accepted)]['close'])('Concurrent peer closed independently');$clients[0]->close();}
        ($accepted[$lastId]['close'])('Test reconnect');$client->close();
        ensure(isset($closed[$lastId]),'Session close callback missing');
        $pump();
    }
    ensure($rakReceived===4,'Legacy RakNet did not coexist with WebRTC on shared UDP');
    ensure($bridge->getConnectionCount()===0,'ICE authorization leaked after disconnect');
    echo "PASS: GET metadata, HTTP framing/error rejection, official-style JWT + signed SDP, full ICE/DTLS/SCTP through shared RakLib UDP, both channels, 140KB binary fragmentation, two concurrent sessions, independent close and repeated-ID reconnects, RakNet coexistence. Synthetic identities only; retail Minecraft client still required.\n";
}finally{
    foreach($clients as $client){$client->close();}
    $http?->shutdown();$transport?->shutdown();$public?->close();if($rakClient!==null){socket_close($rakClient);}
    array_map('unlink',glob($directory.'/*'));rmdir($directory);
}
