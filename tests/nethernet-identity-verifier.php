<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/network/mcpe/nethernet/NetherNetIdentityAssertionVerifier.php';
require dirname(__DIR__).'/src/network/mcpe/nethernet/NetherNetServerIdentity.php';
require __DIR__.'/nethernet-fixtures.php';
use pocketmine\network\mcpe\nethernet\NetherNetIdentityAssertionVerifier as Verifier;
use pocketmine\network\mcpe\nethernet\NetherNetServerIdentity as Identity;
$f = new NetherNetFixtures(); $v=Verifier::fromOfficialJwks($f->jwks());
$fp=implode(':',array_fill(0,32,'AB'));
$sdp="v=0\r\na=fingerprint:sha-256 $fp\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\n";
$valid=$f->offer($sdp); $identity=$v->validate($valid);
if($identity['xuid']!=='987654' || !hash_equals($f->clientDer,$identity['publicKeyDer'])){throw new RuntimeException('Incorrect verified identity');}
$v->validate($f->offer($sdp, [], true));
$reject=static function(string $offer) use($v): void{
    try{$v->validate($offer);}catch(InvalidArgumentException|JsonException){return;}
    throw new RuntimeException('Accepted invalid identity');
};
foreach([
    ['exp'=>time()-3600], ['aud'=>'wrong-audience'], ['iss'=>'https://attacker.invalid/'], ['nbf'=>time()+3600],
    ['iat'=>time()+3600], ['cpk'=>base64_encode(random_bytes(120))], ['xid'=>''], ['sub'=>'']
] as $claims){$reject($f->offer($sdp,$claims));}
$reject(str_replace($fp,str_replace('AB','CD',$fp),$valid));
$reject(str_replace('a=identity:','a=unknown:',$valid));
$reject($valid."a=identity:AAAA\r\n");
$other=new NetherNetFixtures(false);
try{Verifier::fromOfficialJwks($other->jwks())->validate($valid);throw new RuntimeException('Accepted untrusted authority');}catch(InvalidArgumentException){}
$directory=sys_get_temp_dir().'/ryze-identity-'.bin2hex(random_bytes(6));
mkdir($directory,0700);
try{
    $a=new Identity($directory.'/key.pem'); $answer=$a->signAnswer($sdp); $public=NetherNetFixtures::verifyAnswer($answer);
    $b=new Identity($directory.'/key.pem');
    if(!hash_equals($public,NetherNetFixtures::verifyAnswer($b->signAnswer($sdp)))){throw new RuntimeException('Operator key changed on restart');}
    if((fileperms($directory.'/key.pem') & 0777)!==0600){throw new RuntimeException('Operator key permissions too broad');}
    file_put_contents($directory.'/key.pem','corrupt');
    try{new Identity($directory.'/key.pem');throw new LogicException('Silently replaced corrupt operator key');}catch(RuntimeException){}
}finally{array_map('unlink',glob($directory.'/*'));rmdir($directory);}
echo "Official JWT verification, issuer/audience/time/key/fingerprint rejection, SPKI/JWK cpk, signed answers and persistent operator identity OK\n";
