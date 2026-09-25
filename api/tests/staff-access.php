<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\AdminService;
use Wellness\Service\AuditLogger;

$database = new Database(new Config('test', false, 'test', [], '', 3306, '', '', '', '', '', '', 0));
$admin = new AdminService($database, new AuditLogger($database));
$payload = ['tenant_id' => '11111111-1111-4111-8111-111111111111', 'object_id' => '22222222-2222-4222-8222-222222222222', 'email' => 'staff@example.test', 'display_name' => 'Test Staff', 'role' => 'reception'];
foreach (['clinic_admin', 'reception', 'practitioner'] as $role) {
    try {
        $admin->createStaff(new AuthContext(1, 1, '', '', '', 'staff', [$role]), $payload, 'test');
        throw new RuntimeException("{$role} created a staff account.");
    } catch (ApiException $error) {
        if ($error->status !== 403) throw $error;
    }
}
echo "Staff creation authorization checks passed.\n";
