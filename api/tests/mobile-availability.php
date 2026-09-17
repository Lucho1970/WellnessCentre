<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Database;
use Wellness\Service\AvailabilityService;

// Exercises the real slot engine with deterministic database results, not MySQL SQL execution.
final class SlotStatement extends PDOStatement
{
    private array $rows=[];
    public function __construct(private SlotPDO $db,private string $sql){}
    public function execute(?array $params=null):bool {$this->rows=$this->db->rows($this->sql,$params??[]);return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
    public function fetchColumn(int $column=0):mixed{return $this->rows[0][$column]??false;}
}
final class SlotPDO extends PDO
{
    public bool $occupied=false;
    public int $roomQueries=0;
    public string $date;
    public function __construct(){$this->date=(new DateTimeImmutable('tomorrow',new DateTimeZone('America/Toronto')))->format('Y-m-d');}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new SlotStatement($this,$query);}
    public function rows(string $sql,array $params):array
    {
        if(str_contains($sql,'FROM services s'))return [['offers_mobile'=>1,'offers_clinic'=>1,'requires_room'=>1,'travel_buffer_minutes'=>30,'mobile_fee_cents'=>2500,'base_price_cents'=>12000,'lead_time_minutes'=>0,'booking_horizon_days'=>365,'buffer_before_minutes'=>0,'buffer_after_minutes'=>0,'duration_option_id'=>4,'duration_minutes'=>60,'timezone'=>'America/Toronto']];
        if(str_contains($sql,'FROM availability_rules'))return [['start_time'=>'09:00:00','end_time'=>'20:00:00']];
        if(str_contains($sql,'FROM rooms')){$this->roomQueries++;return [];}
        if(str_contains($sql,'FROM appointments')&&$this->occupied){
            $zone=new DateTimeZone('America/Toronto');$utc=new DateTimeZone('UTC');
            $start=(new DateTimeImmutable($this->date.' 12:00:00',$zone))->setTimezone($utc)->format('Y-m-d H:i:s');
            $end=(new DateTimeImmutable($this->date.' 13:00:00',$zone))->setTimezone($utc)->format('Y-m-d H:i:s');
            return $params['start']<$end&&$params['end']>$start?[[1]]:[];
        }
        if(str_contains($sql,'FROM appointments')||str_contains($sql,'FROM time_off')||str_contains($sql,'FROM availability_overrides')||str_contains($sql,'FROM imported_calendar_entries'))return [];
        throw new RuntimeException('Unhandled test query');
    }
}
$pdo=new SlotPDO();
$reflection=new ReflectionClass(Database::class);$db=$reflection->newInstanceWithoutConstructor();
$reflection->getProperty('connection')->setValue($db,$pdo);
$engine=new AvailabilityService($db);
$query=['service_id'=>2,'practitioner_id'=>3,'location_id'=>1,'date_from'=>$pdo->date,'date_to'=>$pdo->date,'delivery_mode'=>'mobile'];
$slots=$engine->search($query)['availability'];
if(!$slots||substr($slots[0]['starts_at'],11,5)!=='09:30'||substr(end($slots)['starts_at'],11,5)!=='18:30')throw new RuntimeException('Travel buffers must fit inside working hours');
if($pdo->roomQueries!==0||$slots[0]['available_room_ids']!==[])throw new RuntimeException('Mobile visit queried/required rooms');
if(isset($slots[0]['destination_snapshot']))throw new RuntimeException('Public availability exposed a destination');
$pdo->occupied=true;$filtered=$engine->search($query)['availability'];
foreach($filtered as $slot){$start=new DateTimeImmutable($slot['starts_at']);$end=new DateTimeImmutable($slot['ends_at']);if($start->modify('-30 minutes')->format('H:i')<'13:00'&&$end->modify('+30 minutes')->format('H:i')>'12:00')throw new RuntimeException('Travel conflict not excluded');}
if(count($filtered)>=count($slots))throw new RuntimeException('Existing appointment did not block slots');
$query['delivery_mode']='clinic';if($engine->search($query)['availability']!==[]||$pdo->roomQueries===0)throw new RuntimeException('Clinic room requirement bypassed');
echo "6 mobile availability checks passed.\n";
