<?php
declare(strict_types=1);
namespace Wellness\Service;
use PDO;
use Wellness\Http\ApiException;

/** Serialize imported STOP decisions with a managed send, including shared clinic senders. */
final class ProviderSenderLock
{
    private array $names=[];
    public function __construct(private readonly PDO $pdo) {}
    public function acquire(array $dids): void
    {
        $database=(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();$dids=array_values(array_unique($dids));sort($dids,SORT_STRING);
        try{foreach($dids as $did){$name='wellness-provider:'.substr(hash('sha256',$database.':'.$did),0,46);$q=$this->pdo->prepare('SELECT GET_LOCK(?,5)');$q->execute([$name]);if((int)$q->fetchColumn()!==1)throw new ApiException(503,'provider_sender_busy','Provider evidence or sending is busy. Retry the same operation.');$this->names[]=$name;}}
        catch(\Throwable $e){$this->release();throw $e;}
    }
    public function release(): void {foreach(array_reverse($this->names) as $name){$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);}$this->names=[];}
    public function __destruct(){$this->release();}
}
