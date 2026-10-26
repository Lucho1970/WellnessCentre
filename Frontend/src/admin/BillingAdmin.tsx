import { useCallback, useEffect, useRef, useState } from 'react';
import { Button, Checkbox, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, MenuItem, Paper, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { ApiError, apiRequest } from '../shared/api';
import { Alert, TextField, useFormValidation, withFormValidation } from '../shared/FormValidation';
import { useUnsavedForm } from '../shared/UnsavedChanges';

type Plan = { id: number; code: string; display_name: string; scope: 'tenant' | 'practitioner'; version: number; base_monthly_cents: number | string | null; features: Record<string, boolean> };
type Pool = { id: number; revision: number; timezone: string; monthly_limit_cents: number; member_limit_cents: number; max_members: number; status: string };
type Budget = { limit_cents: number; spent_cents: number; reserved_cents: number; threshold: number };
type Subscription = { id: number; version: number; membership_id: number | null; member_name: string | null; plan_name: string; plan_version: number; status: string; trial_ends_at: string | null; trial_timezone: string; resume_status: string | null; allows_new_activity: boolean };
type Entry = { id: number; reference: string; membership_id: number | null; estimated_cents: number; actual_cents: number | null; status: string };
type Dashboard = {
  month: string; pool: Pool | null;
  summary: Budget & { remaining_cents: number; over_limit_cents: number; overhead_cents?: number; overhead_reserved_cents?: number; unresolved_count?: number };
  members: (Budget & { membership_id: number; display_name: string; status: string; membership_status: string })[];
  candidates: { id: number; display_name: string }[]; entries: Entry[]; subscriptions: Subscription[];
  events: { id: number; action: string; reason: string; administrator: string; created_at: string }[];
};
type Draft = { kind: 'plan' | 'subscription' | 'action' | 'pool' | 'member' | 'overhead'; values: Record<string, string>; features: Record<string, boolean>; target?: number; version?: number };
type Operation = { path: string; body: Record<string, unknown> };
const featureLabels: Record<string, string> = { booking: 'Bookings', calendar: 'Calendar', client_management: 'Client management', reporting: 'Reports', sms_reminders: 'SMS reminders', dedicated_number: 'Dedicated phone number', custom_email: 'Custom email connection' };
const statusLabels: Record<string, string> = { pending: 'Pending', trialing: 'Free trial', active: 'Active', past_due: 'Past due', restricted: 'Restricted', canceled: 'Canceled', suspended: 'Suspended', inactive: 'Inactive', removed: 'Removed', reserved: 'Reserved', submitted: 'Awaiting reconciliation', settled: 'Confirmed', released: 'Released' };
const eventLabels: Record<string, string> = { 'plan.version.create': 'Plan version created', 'subscription.create': 'Subscription created', 'subscription.activate_trial': 'Trial started', 'subscription.extend': 'Trial extended', 'subscription.suspend': 'Subscription suspended', 'subscription.resume': 'Subscription resumed', 'subscription.cancel': 'Subscription canceled', 'sms.pool.configure': 'SMS budget configured', 'sms.member.enroll': 'Practitioner funding added', 'sms.member.remove': 'Practitioner funding removed', 'sms.overhead.reserve': 'Expected overhead reserved', 'sms.overhead.record': 'Actual overhead recorded', 'sms.overhead.settle': 'Overhead reconciled', 'sms.overhead.correct': 'Overhead corrected' };

function cents(value: string, field: string): number {
  if (!/^\d{1,7}(?:\.\d{1,2})?$/.test(value)) throw new ApiError({ error: { fields: { [field]: 'Enter a valid CAD amount.' } } }, 422);
  const [whole, fraction = ''] = value.split('.');
  return Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
}

function BillingAdminForm({ onDirtyChange }: { onDirtyChange?: (dirty: boolean) => void }) {
  const { t, i18n } = useTranslation(); const { getAccessToken } = useStaffAuth();
  const validation = useFormValidation(); const guard = useUnsavedForm(); const sequence = useRef(0);
  const [clinics, setClinics] = useState<{ id: number; name: string }[]>([]);
  const [catalog, setCatalog] = useState<{ plans: Plan[]; features: string[] } | null>(null);
  const [clinic, setClinic] = useState(''); const [month, setMonth] = useState(''); const [data, setData] = useState<Dashboard | null>(null);
  const [busy, setBusy] = useState(false); const [blocked, setBlocked] = useState(false);
  const [error, setError] = useState(''); const [saved, setSaved] = useState(false); const [draft, setDraft] = useState<Draft | null>(null);
  const [pending, setPending] = useState<Operation | null>(null);
  const money = (value: number | string | undefined) => new Intl.NumberFormat(i18n.language.startsWith('fr') ? 'fr-CA' : 'en-CA', { style: 'currency', currency: 'CAD' }).format(Number(value ?? 0) / 100);
  const status = (value: string) => t(statusLabels[value] ?? value);
  const timestamp = (value: string | null, timezone?: string) => value ? new Intl.DateTimeFormat(i18n.language, { dateStyle: 'medium', timeStyle: 'short', timeZone: timezone ?? 'America/Toronto' }).format(new Date(value.replace(' ', 'T') + 'Z')) : '—';
  useEffect(() => { onDirtyChange?.(guard.dirty); }, [guard.dirty, onDirtyChange]);
  useEffect(() => () => onDirtyChange?.(false), [onDirtyChange]);
  const fail = useCallback((cause: unknown) => {
    if (!(cause instanceof ApiError) || cause.status !== 422) setBlocked(true);
    if (cause instanceof ApiError && [401, 403, 404, 409].includes(cause.status)) {
      setData(null); setCatalog(null); setClinics([]); setDraft(null); setPending(null); setBlocked(true); guard.markClean();
    }
    validation.capture(cause); setError(cause instanceof Error ? cause.message : t('Unable to load billing administration.'));
  }, [validation.capture, guard.markClean, t]);
  const reload = useCallback(async () => {
    const revision = ++sequence.current; setBusy(true);
    try {
      const headers = { Authorization: `Bearer ${await getAccessToken()}` };
      const [list, plans, dashboard] = await Promise.all([
        apiRequest<{ items: { id: number; name: string }[] }>('/application/clinics', { headers }),
        apiRequest<{ plans: Plan[]; features: string[] }>('/application/billing', { headers }),
        clinic ? apiRequest<Dashboard>(`/application/billing/clinics/${clinic}${month ? `?month=${encodeURIComponent(month)}` : ''}`, { headers }) : Promise.resolve(null),
      ]);
      if (revision !== sequence.current) return;
      setClinics(list.items); setCatalog(plans); setData(dashboard); setBlocked(false); setError('');
      if (!clinic && list.items.length) setClinic(String(list.items[0].id));
    } catch (cause) { if (revision === sequence.current) fail(cause); }
    finally { if (revision === sequence.current) setBusy(false); }
  }, [clinic, month, getAccessToken, fail]);
  useEffect(() => { setData(null); void reload(); return () => { sequence.current++; }; }, [reload]);
  const canLeave = () => !guard.dirty || window.confirm(t('Discard your unsaved changes?'));
  const open = (value: Draft) => { if (!canLeave()) return; guard.markClean(); validation.clear(); setSaved(false); setError(''); setPending(null); setDraft(value); };
  const close = () => { if (!canLeave() || pending) return; guard.markClean(); setDraft(null); validation.clear(); };
  const field = (key: string, value: string) => { if (draft) { setDraft({ ...draft, values: { ...draft.values, ...(key === 'membership_id' && draft.kind === 'subscription' ? { plan_version_id: '' } : {}), [key]: value } }); guard.markDirty(); } };
  const build = (): Operation => {
    if (!draft) throw new Error('No billing change selected');
    const v = draft.values; let path = `/application/billing/clinics/${clinic}`;
    const body: Record<string, unknown> = { request_key: crypto.randomUUID(), reason: v.reason };
    if (draft.kind === 'plan') {
      path = '/application/billing/plans'; Object.assign(body, { code: v.code, display_name: v.display_name, scope: v.scope, base_monthly_cents: v.price === '' ? null : cents(v.price, 'base_monthly_cents'), features: draft.features });
    } else if (draft.kind === 'subscription') {
      path += '/subscriptions'; Object.assign(body, { membership_id: v.membership_id === 'clinic' ? null : Number(v.membership_id), plan_version_id: Number(v.plan_version_id), status: v.status, timezone: v.timezone });
    } else if (draft.kind === 'action') {
      path += `/subscriptions/${draft.target}`; Object.assign(body, { action: v.action, version: draft.version }); if (v.action === 'extend') body.trial_last_day = v.trial_last_day;
    } else if (draft.kind === 'pool') {
      path += '/pool'; Object.assign(body, { revision: draft.version ?? 0, timezone: v.timezone, status: v.status, monthly_limit_cents: cents(v.monthly, 'monthly_limit_cents'), member_limit_cents: cents(v.member, 'member_limit_cents'), max_members: Number(v.max_members) });
    } else if (draft.kind === 'member') {
      path += '/members'; Object.assign(body, { membership_id: Number(v.membership_id), action: v.action, revision: draft.version });
    } else {
      path += '/overhead'; Object.assign(body, { action: v.action, amount_cents: cents(v.amount, 'amount_cents'), reference: v.reference, month: data?.month });
      if (v.action === 'correct') body.expected_actual_cents = Number(v.expected_actual_cents);
    }
    return { path, body };
  };
  const perform = async (operation: Operation) => {
    setBusy(true); setError(''); validation.clear(); setPending(operation);
    try {
      await apiRequest(operation.path, { method: 'POST', headers: { Authorization: `Bearer ${await getAccessToken()}`, 'Content-Type': 'application/json' }, body: JSON.stringify(operation.body) });
    } catch (cause) {
      if (cause instanceof ApiError && cause.status < 500) setPending(null);
      fail(cause); setBusy(false); return;
    }
    setPending(null); setDraft(null); guard.markClean(); setSaved(true); await reload();
  };
  const submit = (event: React.FormEvent) => { event.preventDefault(); try { void perform(build()); } catch (cause) { fail(cause); } };
  const editPool = () => open({ kind: 'pool', version: Number(data?.pool?.revision ?? 0), features: {}, values: { timezone: data?.pool?.timezone ?? 'America/Toronto', status: data?.pool?.status ?? 'inactive', monthly: (Number(data?.pool?.monthly_limit_cents ?? 10000) / 100).toFixed(2), member: (Number(data?.pool?.member_limit_cents ?? 1000) / 100).toFixed(2), max_members: String(data?.pool?.max_members ?? 10), reason: '' } });
  const action = (subscription: Subscription, name: string) => open({ kind: 'action', target: subscription.id, version: Number(subscription.version), features: {}, values: { action: name, trial_last_day: '', reason: '' } });
  const dialogTitle = draft?.kind === 'plan' ? 'Create plan version' : draft?.kind === 'subscription' ? 'Create subscription' : draft?.kind === 'pool' ? 'Configure SMS budget' : draft?.kind === 'member' ? 'Change funded practitioner' : draft?.kind === 'overhead' ? 'Record shared SMS overhead' : 'Change subscription';
  const input = (key: string, label: string, options: { type?: string; required?: boolean; disabled?: boolean; helperText?: string } = {}) => <TextField name={({ price: 'base_monthly_cents', monthly: 'monthly_limit_cents', member: 'member_limit_cents', amount: 'amount_cents' } as Record<string, string>)[key] ?? key} label={t(label)} value={draft?.values[key] ?? ''} onChange={e => field(key, e.target.value)} fullWidth required {...options} disabled={busy || !!pending || options.disabled} slotProps={options.type === 'date' ? { inputLabel: { shrink: true } } : undefined} helperText={options.helperText ? t(options.helperText) : undefined} />;
  const choose = (key: string, label: string, choices: { value: string; label: string }[]) => <TextField select name={key} label={t(label)} fullWidth required disabled={busy || !!pending} value={draft?.values[key] ?? ''} onChange={e => field(key, e.target.value)}>{choices.map(item => <MenuItem key={item.value} value={item.value}>{item.label}</MenuItem>)}</TextField>;
  const controlsDisabled = busy || blocked || !catalog || !data;
  return <Stack spacing={2}>
    <Typography variant="h5">{t('Subscriptions and SMS costs')}</Typography>
    <Alert severity="info">{t('Configure plans and trial memberships here. Payment collection and automatic SMS budget enforcement are not active yet.')}</Alert>
    {error && !draft && <Alert severity="error">{error}</Alert>}{saved && <Alert severity="success">{t('Billing changes saved.')}</Alert>}
    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
      <TextField select label={t('Clinic')} value={clinic} disabled={busy || !!draft} onChange={e => { setData(null); setClinic(e.target.value); setMonth(''); setSaved(false); }} sx={{ minWidth: 200 }}>{clinics.map(item => <MenuItem key={item.id} value={String(item.id)}>{item.name}</MenuItem>)}</TextField>
      <TextField label={t('Cost month')} type="month" value={month || data?.month || ''} disabled={busy || !!draft} slotProps={{ inputLabel: { shrink: true } }} onChange={e => setMonth(e.target.value)} />
      <Button disabled={busy} onClick={() => { if (!canLeave()) return; setDraft(null); setPending(null); guard.markClean(); validation.clear(); void reload(); }}>{t('Reload billing data')}</Button>
    </Stack>
    {busy && <Typography role="status">{t('Loading…')}</Typography>}
    {!clinics.length && catalog && <Typography>{t('Create a clinic before configuring subscriptions.')}</Typography>}
    {data && !blocked && <>
      <Typography variant="h6">{t('Monthly SMS budget')}</Typography>
      <Typography variant="body2">{t('All amounts are in Canadian dollars (CAD).')}</Typography>
      <Stack direction="row" useFlexGap flexWrap="wrap" spacing={2}>{[
        ['Monthly limit', data.summary.limit_cents], ['Confirmed costs', data.summary.spent_cents], ['Committed estimates', data.summary.reserved_cents], ['Remaining allowance', data.summary.remaining_cents],
      ].map(([label, value]) => <Paper key={label} variant="outlined" sx={{ p: 2, flex: '1 1 180px' }}><Typography>{t(String(label))}</Typography><Typography variant="h5">{money(value)}</Typography></Paper>)}</Stack>
      {!!data.summary.threshold && <Alert severity={data.summary.threshold >= 100 ? 'error' : 'warning'}>{t('Clinic SMS budget has reached {{percent}}% of its monthly limit.', { percent: data.summary.threshold })}</Alert>}
      {data.summary.over_limit_cents > 0 && <Alert severity="error">{t('Confirmed and committed costs exceed the limit by {{amount}}. Review provider costs before allowing more SMS.', { amount: money(data.summary.over_limit_cents) })}</Alert>}
      {!!data.summary.unresolved_count && <Alert severity="warning">{Number(data.summary.unresolved_count) === 1 ? t('One submitted message awaits cost reconciliation.') : t('{{count}} submitted messages await cost reconciliation.', { count: data.summary.unresolved_count })}</Alert>}
      <Typography>{t('Shared overhead: {{confirmed}} confirmed; {{reserved}} reserved.', { confirmed: money(data.summary.overhead_cents), reserved: money(data.summary.overhead_reserved_cents) })}</Typography>
      <Typography>{t('Funded practitioners: {{count}} of {{maximum}}. SMS budget status: {{status}}.', { count: data.members.filter(item => item.status === 'active').length, maximum: data.pool?.max_members ?? 10, status: data.pool ? status(data.pool.status) : t('Not configured') })}</Typography>
      <Stack direction="row" useFlexGap flexWrap="wrap" spacing={1}><Button disabled={controlsDisabled} onClick={editPool}>{t('Configure SMS budget')}</Button><Button disabled={controlsDisabled || !data.pool} onClick={() => open({ kind: 'member', version: Number(data.pool?.revision), features: {}, values: { membership_id: '', action: 'enroll', reason: '' } })}>{t('Fund a practitioner')}</Button><Button disabled={controlsDisabled || !data.pool} onClick={() => open({ kind: 'overhead', features: {}, values: { action: 'reserve', reference: `overhead-${crypto.randomUUID()}`, amount: '', reason: '' } })}>{t('Record shared SMS overhead')}</Button></Stack>
      <TableContainer component={Paper} variant="outlined"><Table size="small" aria-label={t('Funded practitioner budgets')}><TableHead><TableRow>{['Practitioner', 'Status', 'Monthly limit', 'Confirmed costs', 'Committed estimates', 'Budget alerts', 'Actions'].map(label => <TableCell key={label}>{t(label)}</TableCell>)}</TableRow></TableHead><TableBody>{data.members.map(member => <TableRow key={member.membership_id}><TableCell>{member.display_name}</TableCell><TableCell>{status(member.status)} / {status(member.membership_status)}</TableCell><TableCell>{money(member.limit_cents)}</TableCell><TableCell>{money(member.spent_cents)}</TableCell><TableCell>{money(member.reserved_cents)}</TableCell><TableCell>{member.threshold ? `${member.threshold}%` : '—'}</TableCell><TableCell>{member.status === 'active' && <Button disabled={controlsDisabled} aria-label={t('Remove funding for {{name}}', { name: member.display_name })} onClick={() => open({ kind: 'member', version: Number(data.pool?.revision), features: {}, values: { action: 'remove', membership_id: String(member.membership_id), reason: '' } })}>{t('Remove funding')}</Button>}</TableCell></TableRow>)}</TableBody></Table></TableContainer>
      <Typography variant="h6">{t('Subscriptions')}</Typography><Button disabled={controlsDisabled} onClick={() => open({ kind: 'subscription', features: {}, values: { membership_id: 'clinic', plan_version_id: '', status: 'pending', timezone: data.pool?.timezone ?? 'America/Toronto', reason: '' } })}>{t('Create subscription')}</Button>
      {data.subscriptions.map(subscription => <Paper key={subscription.id} variant="outlined" sx={{ p: 2 }}><Stack spacing={1}><Typography variant="subtitle1">{subscription.member_name ?? t('Clinic subscription')} — {subscription.plan_name} v{subscription.plan_version}</Typography><Typography>{subscription.status === 'trialing' && !subscription.allows_new_activity ? t('Trial expired') : status(subscription.status)} · {t('Trial ends')}: {timestamp(subscription.trial_ends_at, subscription.trial_timezone)} ({subscription.trial_timezone})</Typography><Stack direction="row" useFlexGap flexWrap="wrap" spacing={1}>{(subscription.status === 'pending' ? ['activate_trial', 'suspend', 'cancel'] : subscription.status === 'trialing' ? ['extend', 'suspend', 'cancel'] : subscription.status === 'suspended' && ['pending', 'trialing'].includes(subscription.resume_status ?? '') ? ['resume', 'cancel'] : []).map(name => <Button key={name} disabled={controlsDisabled} onClick={() => action(subscription, name)}>{t({ activate_trial: 'Start six-month trial', suspend: 'Suspend subscription', resume: 'Resume subscription', cancel: 'Cancel subscription', extend: 'Extend trial' }[name] ?? name)}</Button>)}</Stack></Stack></Paper>)}
      <Typography variant="h6">{t('Shared plan catalogue')}</Typography><Button disabled={controlsDisabled} onClick={() => open({ kind: 'plan', features: Object.fromEntries((catalog?.features ?? []).map(key => [key, false])), values: { code: '', display_name: '', scope: 'practitioner', price: '', reason: '' } })}>{t('Create plan version')}</Button>
      <Typography>{t('New versions do not change existing subscriptions. Leave the price empty while pricing is undecided.')}</Typography>
      {catalog?.plans.map(plan => <Paper key={plan.id} variant="outlined" sx={{ p: 2 }}><Typography>{plan.display_name} · {plan.code} v{plan.version} · {t(plan.scope === 'tenant' ? 'Clinic plan' : 'Practitioner plan')} · {plan.base_monthly_cents === null ? t('Price not set') : money(plan.base_monthly_cents)}</Typography><Typography variant="body2">{Object.entries(plan.features).filter(([, enabled]) => enabled).map(([key]) => t(featureLabels[key] ?? key)).join(', ') || t('No features selected')}</Typography><Button disabled={controlsDisabled} onClick={() => open({ kind: 'plan', features: plan.features, values: { code: plan.code, display_name: plan.display_name, scope: plan.scope, price: plan.base_monthly_cents === null ? '' : (Number(plan.base_monthly_cents) / 100).toFixed(2), reason: '' } })}>{t('Create next version')}</Button></Paper>)}
      <Typography variant="h6">{t('Recent cost entries')}</Typography><Typography variant="body2">{t('Latest 50 entries for the selected month. Costs are recorded in CAD.')}</Typography>
      <TableContainer component={Paper} variant="outlined"><Table size="small" aria-label={t('SMS cost entries')}><TableHead><TableRow>{['Reference', 'Status', 'Estimate', 'Confirmed costs', 'Actions'].map(label => <TableCell key={label}>{t(label)}</TableCell>)}</TableRow></TableHead><TableBody>{data.entries.map(entry => <TableRow key={entry.id}><TableCell sx={{ overflowWrap: 'anywhere', maxWidth: 200 }}>{entry.reference}</TableCell><TableCell>{status(entry.status)}</TableCell><TableCell>{money(entry.estimated_cents)}</TableCell><TableCell>{entry.actual_cents === null ? '—' : money(entry.actual_cents)}</TableCell><TableCell>{entry.membership_id === null && ['reserved', 'settled'].includes(entry.status) && <Button disabled={controlsDisabled} onClick={() => open({ kind: 'overhead', features: {}, values: { action: entry.status === 'reserved' ? 'settle' : 'correct', reference: entry.reference, expected_actual_cents: String(entry.actual_cents ?? 0), amount: (Number(entry.status === 'reserved' ? entry.estimated_cents : entry.actual_cents) / 100).toFixed(2), reason: '' } })}>{t(entry.status === 'reserved' ? 'Confirm overhead cost' : 'Correct overhead cost')}</Button>}</TableCell></TableRow>)}</TableBody></Table></TableContainer>
      <Typography variant="h6">{t('Recent billing changes')}</Typography>{data.events.map(event => <Typography key={event.id} variant="body2">{timestamp(event.created_at)} · {event.administrator} · {t(eventLabels[event.action] ?? event.action)} · {event.reason}</Typography>)}
    </>}
    <Dialog open={!!draft} onClose={busy || pending ? undefined : close} fullWidth maxWidth="sm" aria-labelledby="billing-dialog-title"><DialogTitle id="billing-dialog-title">{t(dialogTitle)}</DialogTitle><DialogContent><Stack component="form" id="billing-form" onSubmit={submit} spacing={2} sx={{ pt: 1 }}>
      {error && <Alert severity="error">{error}</Alert>}
      {pending && !busy && <Alert severity="warning">{t('The save outcome is uncertain. Retry the same change or reload billing data before editing.')}</Alert>}
      {draft?.kind === 'plan' && <>{input('code', 'Plan code')}{input('display_name', 'Plan name')}{choose('scope', 'Plan type', [{ value: 'tenant', label: t('Clinic plan') }, { value: 'practitioner', label: t('Practitioner plan') }])}{input('price', 'Monthly price (CAD)', { required: false, helperText: 'Optional until pricing is decided.' })}{catalog?.features.map(key => <FormControlLabel key={key} control={<Checkbox checked={draft.features[key] ?? false} disabled={busy || !!pending} onChange={e => { setDraft({ ...draft, features: { ...draft.features, [key]: e.target.checked } }); guard.markDirty(); }} />} label={t(featureLabels[key] ?? key)} />)}</>}
      {draft?.kind === 'subscription' && <>{choose('membership_id', 'Subscription owner', [{ value: 'clinic', label: t('Clinic subscription') }, ...(data?.candidates ?? []).map(member => ({ value: String(member.id), label: member.display_name }))])}{choose('plan_version_id', 'Plan version', (catalog?.plans ?? []).filter(plan => plan.scope === (draft.values.membership_id === 'clinic' ? 'tenant' : 'practitioner')).map(plan => ({ value: String(plan.id), label: `${plan.display_name} v${plan.version}` })))}{choose('status', 'Initial status', [{ value: 'pending', label: t('Pending') }, { value: 'trialing', label: t('Start six-month trial') }])}{input('timezone', 'Trial timezone')}</>}
      {draft?.kind === 'action' && <><Typography>{t({ activate_trial: 'Start six-month trial', suspend: 'Suspend subscription', resume: 'Resume subscription', cancel: 'Cancel subscription', extend: 'Extend trial' }[draft.values.action] ?? '')}</Typography>{draft.values.action === 'extend' && input('trial_last_day', 'Last day of trial', { type: 'date', helperText: 'The trial ends at midnight after this date in its original timezone.' })}<Typography>{t('These changes update billing records only. Clinic access is unchanged until subscription enforcement is enabled.')}</Typography></>}
      {draft?.kind === 'pool' && <>{input('timezone', 'Budget timezone', { disabled: !!data?.pool })}{input('monthly', 'Clinic monthly limit (CAD)')}{input('member', 'Practitioner monthly limit (CAD)')}{input('max_members', 'Maximum funded practitioners', { type: 'number' })}{choose('status', 'SMS budget status', [{ value: 'inactive', label: t('Inactive') }, { value: 'active', label: t('Active') }])}<Alert severity="info">{t('Spending limits apply to new months. Existing monthly limits stay unchanged. Status and cohort size changes apply immediately.')}</Alert></>}
      {draft?.kind === 'member' && <>{draft.values.action === 'enroll' ? choose('membership_id', 'Practitioner', (data?.candidates ?? []).filter(member => !data?.members.some(enrolled => enrolled.membership_id === member.id && enrolled.status === 'active')).map(member => ({ value: String(member.id), label: member.display_name }))) : <Typography>{t('Removing funding preserves recorded costs and outstanding reservations.')}</Typography>}</>}
      {draft?.kind === 'overhead' && <>{!['settle', 'correct'].includes(draft.values.action) && choose('action', 'Cost action', [{ value: 'reserve', label: t('Reserve expected overhead') }, { value: 'record', label: t('Record confirmed overhead') }])}{input('reference', 'Reference', { disabled: ['settle', 'correct'].includes(draft.values.action) })}{input('amount', 'Amount (CAD)')}<Typography>{t('Include shared number rental, taxes and provider fees. Record actual costs even when they exceed a reservation.')}</Typography></>}
      {draft && input('reason', 'Reason for change')}
    </Stack></DialogContent><DialogActions><Button disabled={busy || !!pending} onClick={close}>{t('Close')}</Button>{pending && !busy ? <><Button onClick={() => { setDraft(null); setPending(null); guard.markClean(); void reload(); }}>{t('Reload billing data')}</Button><Button variant="contained" onClick={() => void perform(pending)}>{t('Retry same change')}</Button></> : <Button form="billing-form" type="submit" variant="contained" disabled={busy || blocked}>{t('Save billing change')}</Button>}</DialogActions></Dialog>
  </Stack>;
}
export const BillingAdmin = withFormValidation(BillingAdminForm);
