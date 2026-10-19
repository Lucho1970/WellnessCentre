<?php
declare(strict_types=1);
namespace Wellness\Service;

use PDO;
use Wellness\Http\ApiException;

/** Connection-scoped lock, separate from business transactions. Hold through disclosure/send. */
final class ConsentLock
{
    private ?string $name = null;
    public function __construct(private readonly PDO $pdo) {}
    public function acquire(int $clinic): void
    {
        if ($this->name !== null) return;
        $name = 'wellness-consent:'.hash('sha256',(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn().':'.$clinic);
        $name = substr($name,0,64);
        $q=$this->pdo->prepare('SELECT GET_LOCK(?,5)');$q->execute([$name]);
        if ((int)$q->fetchColumn()!==1) throw new ApiException(503,'consent_busy','Privacy instructions are being updated. Please retry.');
        $this->name=$name;
    }
    public function release(): void
    {
        if($this->name===null)return;
        $name=$this->name;$this->name=null;
        $q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);
    }
    public function __destruct() { $this->release(); }
}
