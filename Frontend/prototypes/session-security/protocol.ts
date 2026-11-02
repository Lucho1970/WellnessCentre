// Local synthetic feasibility fixture. Never imported by production entry points.
export const prototypeMarker = '__SESSION_SECURITY_PROTOTYPE__';
export const tenant = '11111111-1111-4111-8111-111111111111';
export const authority = `https://login.session.test/${tenant}`;
export const issuer = `${authority}/v2.0`;
export const realmNames = ['workforce', 'practitioner', 'client', 'central'] as const;
export type Realm = typeof realmNames[number];
export const realms: Record<Realm, { client: string; api: string; scope: string }> = {
  workforce: { client: '22222222-2222-4222-8222-222222222221', api: '33333333-3333-4333-8333-333333333331', scope: 'access_as_user' },
  practitioner: { client: '22222222-2222-4222-8222-222222222222', api: '33333333-3333-4333-8333-333333333332', scope: 'access_as_staff' },
  client: { client: '22222222-2222-4222-8222-222222222223', api: '33333333-3333-4333-8333-333333333333', scope: 'access_as_client' },
  central: { client: '22222222-2222-4222-8222-222222222224', api: '33333333-3333-4333-8333-333333333334', scope: 'access_as_user' },
};
export const hosts = ['clinic-a.session.test', 'clinic-b.session.test', 'admin.session.test'];
export const apiPrefix = '/__session-prototype';
export function isRealm(value: string): value is Realm { return realmNames.some(realm => realm === value); }
export type Lease = { csrf: string; idle_expires_at: number; absolute_expires_at: number; now: number };
export function isLease(value: unknown): value is Lease {
  if (!value || typeof value !== 'object') return false;
  return 'csrf' in value && typeof value.csrf === 'string' && /^[a-f0-9]{64}$/.test(value.csrf)
    && 'idle_expires_at' in value && 'absolute_expires_at' in value && 'now' in value
    && [value.idle_expires_at, value.absolute_expires_at, value.now].every(item => typeof item === 'number' && Number.isSafeInteger(item) && item > 0)
    && Number(value.idle_expires_at) > Number(value.now) && Number(value.absolute_expires_at) > Number(value.now);
}
export type StorageItem = { key: string; bytes: number };
export function inventory(): StorageItem[] {
  return [localStorage, sessionStorage].flatMap(storage => Object.keys(storage).map(key => ({ key, bytes: storage.getItem(key)?.length ?? 0 })));
}
