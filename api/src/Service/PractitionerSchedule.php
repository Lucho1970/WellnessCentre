<?php
declare(strict_types=1);
namespace Wellness\Service;

use PDO;

/** Shared busy time reveals no patient or other-clinic appointment details. */
final class PractitionerSchedule
{
    public static function idsSql(): string
    {
        return 'SELECT p2.id FROM practitioners p2 LEFT JOIN staff_memberships m2 ON m2.local_user_id=p2.user_id WHERE p2.id=:p OR m2.identity_id=(SELECT m.identity_id FROM staff_memberships m JOIN practitioners p ON p.user_id=m.local_user_id WHERE p.id=:identity_p)';
    }

    public static function lock(PDO $pdo,int ...$practitioners): void
    {
        if($practitioners===[])return;
        $q=$pdo->prepare('SELECT DISTINCT m.identity_id FROM staff_memberships m JOIN practitioners p ON p.user_id=m.local_user_id WHERE p.id IN ('.implode(',',array_fill(0,count($practitioners),'?')).')');$q->execute($practitioners);$identities=$q->fetchAll(PDO::FETCH_COLUMN);sort($identities,SORT_NUMERIC);
        foreach($identities as $identity){$q=$pdo->prepare('SELECT id FROM product_identities WHERE id=? FOR UPDATE');$q->execute([$identity]);$q->fetchColumn();}
    }
}
