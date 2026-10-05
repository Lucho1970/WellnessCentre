<?php
declare(strict_types=1);

namespace Wellness;

use Wellness\Auth\AuthContext;
use Wellness\Http\ApiException;
use Wellness\Http\Request;

final readonly class ClinicContext
{
    private function __construct(public int $clinicId, public string $host) {}

    public static function normalizeHost(string $host): string
    {
        // Exact authority matching includes local development ports. Forwarded
        // host headers and browser clinic IDs are deliberately not consulted.
        if ($host !== trim($host) || !preg_match('/^([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.?)(?::([0-9]{1,5}))?$/iD', $host, $matches)) {
            throw new ApiException(400, 'invalid_host', 'Invalid request host.');
        }
        $name = strtolower(rtrim($matches[1], '.'));
        foreach (explode('.', $name) as $label) {
            if (strlen($label) > 63 || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label)) {
                throw new ApiException(400, 'invalid_host', 'Invalid request host.');
            }
        }
        if (strlen($name) > 253 || (isset($matches[2]) && ((int)$matches[2] < 1 || (int)$matches[2] > 65535))) {
            throw new ApiException(400, 'invalid_host', 'Invalid request host.');
        }
        return $name . (isset($matches[2]) ? ':' . (int)$matches[2] : '');
    }

    public static function forHost(Config $config, string $host): self
    {
        $host = self::normalizeHost($host);
        if ($config->clinicHostMap === []) throw new ApiException(503, 'clinic_routing_not_configured', 'Clinic routing is not configured.');
        $map = [];
        foreach ($config->clinicHostMap as $configuredHost => $clinicId) {
            if (!is_string($configuredHost) || !is_int($clinicId) || $clinicId < 1) {
                throw new ApiException(503, 'clinic_routing_not_configured', 'Invalid clinic routing configuration.');
            }
            try { $key = self::normalizeHost($configuredHost); }
            catch (ApiException) { throw new ApiException(503, 'clinic_routing_not_configured', 'Invalid clinic routing configuration.'); }
            if (isset($map[$key])) throw new ApiException(503, 'clinic_routing_not_configured', 'Duplicate clinic host configuration.');
            $map[$key] = $clinicId;
        }
        if (!isset($map[$host])) throw new ApiException(404, 'clinic_host_not_found', 'No clinic is configured for this host.');
        return new self($map[$host], $host);
    }

    public static function resolve(Config $config, Database $database, Request $request): self
    {
        $context = self::forHost($config, (string)($request->headers['host'] ?? ''));
        $context->assertActive($database);
        return $context;
    }

    public function assertActive(Database $database): void
    {
        $query = $database->connection()->prepare("SELECT id FROM clinics WHERE id=:clinic AND status='active'");
        $query->execute(['clinic' => $this->clinicId]);
        if (!$query->fetchColumn()) throw new ApiException(404, 'clinic_host_not_found', 'The clinic is unavailable.');
    }

    public function assertActor(AuthContext $actor): AuthContext
    {
        if ($actor->clinicId !== $this->clinicId) throw new ApiException(403, 'clinic_access_denied', 'This account cannot access this clinic.');
        return $actor;
    }
}
