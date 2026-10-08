import { Alert, FormControlLabel, TextField, withFormValidation, useFormValidation } from '../shared/FormValidation';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Button, Checkbox, Dialog, DialogActions, DialogContent, DialogTitle, MenuItem, Paper, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiRequest } from '../shared/api';

type Invitation = { id: number; recipient_email: string; given_name: string; family_name: string; expires_at: string; revoked_at: string | null; accepted_at: string | null; claimant_name: string | null; claim_status: string | null; verification_code: string | null };
type Location = { id: number; name: string; is_bookable: boolean | number };
const blank = { recipient_email: '', given_name: '', family_name: '', discipline: '', location_id: '', existing_user_id: '' };

function StaffInvitationsForm() {
  const formValidation = useFormValidation();
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [items, setItems] = useState<Invitation[]>([]), [locations, setLocations] = useState<Location[]>([]);
  const [form, setForm] = useState(blank), [busy, setBusy] = useState(false), [error, setError] = useState(''), [link, setLink] = useState('');
  const [review, setReview] = useState<Invitation | null>(null), [verified, setVerified] = useState(false), [code, setCode] = useState('');
  const request = useCallback(async <T,>(path: string, body?: unknown): Promise<T> => apiRequest<T>(path, {
    method: body === undefined ? 'GET' : 'POST', headers: { Authorization: `Bearer ${await getAccessToken()}`, 'Content-Type': 'application/json' },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  }), [getAccessToken]);
  const load = useCallback(async () => {
    const [invitations, available] = await Promise.all([request<{ items: Invitation[] }>('/admin/staff-invitations'), request<Location[]>('/admin/locations')]);
    setItems(invitations.items); setLocations(available.filter(location => Boolean(Number(location.is_bookable))));
  }, [request]);
  useEffect(() => { void load().catch(cause => setError(cause instanceof Error ? cause.message : t('Unable to load invitations.'))); }, [load, t]);
  const create = async (event: FormEvent) => {
    event.preventDefault(); setBusy(true); (formValidation.clear(), setError('')); setLink('');
    try {
      const result = await request<{ token: string }>('/admin/staff-invitations', { ...form, role: 'practitioner', location_id: Number(form.location_id), existing_user_id: form.existing_user_id ? Number(form.existing_user_id) : null });
      const url = new URL(`${import.meta.env.BASE_URL}staff/invitation`, window.location.origin); url.hash = `token=${result.token}`;
      setLink(url.href); setForm(blank); await load();
    } catch (cause) { formValidation.capture(cause); setError(cause instanceof Error ? cause.message : t('Unable to create invitation.')); }
    finally { setBusy(false); }
  };
  const action = async (item: Invitation, approve: boolean) => {
    setBusy(true); (formValidation.clear(), setError(''));
    try {
      await request(`/admin/staff-invitations/${item.id}/${approve ? 'approve' : 'revoke'}`, approve ? { recipient_verified: verified, verification_code: code } : {});
      setReview(null); await load();
    } catch (cause) { formValidation.capture(cause); setError(cause instanceof Error ? cause.message : t('Unable to update invitation.')); }
    finally { setBusy(false); }
  };
  return <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 } }}><Stack spacing={2}>
    <Typography variant="h5">{t('Practitioner invitations')}</Typography>
    <Typography>{t('Invite a practitioner without creating a workforce account. Sign-in claims require your approval before access is granted.')}</Typography>
    {error && <Alert severity="error">{error}</Alert>}
    {link && <Alert severity="success"><Stack spacing={1}><Typography>{t('Copy this link now and send it to the intended practitioner. No email has been sent. The link expires in 48 hours.')}</Typography><TextField name="link" label={t('Invitation link')} value={link} fullWidth slotProps={{ input: { readOnly: true } }}/></Stack></Alert>}
    <Stack component="form" onSubmit={event => void create(event)} spacing={2}>
      <TextField name="recipient_email" required type="email" label={t('Email')} value={form.recipient_email} onChange={event => setForm({ ...form, recipient_email: event.target.value })}/>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
        <TextField name="given_name" required label={t('Given name')} value={form.given_name} onChange={event => setForm({ ...form, given_name: event.target.value })}/>
        <TextField name="family_name" required label={t('Family name')} value={form.family_name} onChange={event => setForm({ ...form, family_name: event.target.value })}/>
      </Stack>
      <TextField name="discipline" required label={t('Discipline')} value={form.discipline} onChange={event => setForm({ ...form, discipline: event.target.value })}/>
      <TextField name="location_id" select required label={t('Location')} value={form.location_id} onChange={event => setForm({ ...form, location_id: event.target.value })}>{locations.map(location => <MenuItem key={location.id} value={location.id}>{location.name}</MenuItem>)}</TextField>
      <TextField name="existing_user_id" type="number" label={t('Existing staff user ID (optional)')} value={form.existing_user_id} onChange={event => setForm({ ...form, existing_user_id: event.target.value })} helperText={t('Leave blank for a new practitioner. Accounts with an existing membership require a separate reviewed migration.')}/>
      <Button type="submit" variant="contained" disabled={busy || !locations.length}>{t('Create practitioner invitation')}</Button>
    </Stack>
    <Typography variant="h6">{t('Recent invitations')}</Typography>
    {!items.length && <Typography>{t('No invitations yet.')}</Typography>}
    {items.map(item => {
      const expired = Date.parse(`${item.expires_at.replace(' ', 'T')}Z`) <= Date.now();
      const usable = !item.accepted_at && !item.revoked_at && !expired;
      const status = item.accepted_at ? t('Approved') : item.revoked_at ? t('Revoked') : expired ? t('Expired') : item.claim_status === 'pending' ? t('Awaiting approval') : t('Awaiting sign-in');
      return <Paper variant="outlined" key={item.id} sx={{ p: 2 }}><Stack spacing={1}>
        <Typography fontWeight={700}>{item.given_name} {item.family_name} · {status}</Typography><Typography>{item.recipient_email}</Typography>
        {item.claimant_name && <Typography>{t('Claimed by: {{name}}', { name: item.claimant_name })}</Typography>}
        <Stack direction="row" gap={1} flexWrap="wrap">
          {usable && item.claim_status === 'pending' && <Button disabled={busy} onClick={() => { setReview(item); setVerified(false); setCode(''); }}>{t('Review claim')}</Button>}
          {usable && <Button color="error" disabled={busy} onClick={() => void action(item, false)}>{t('Revoke invitation')}</Button>}
        </Stack>
      </Stack></Paper>;
    })}
    <Dialog open={review !== null} onClose={() => { if (!busy) setReview(null); }} fullWidth maxWidth="sm">
      <DialogTitle>{t('Review practitioner claim')}</DialogTitle><DialogContent><Stack spacing={2} sx={{ pt: 1 }}>
        <Typography>{review?.recipient_email} · {review?.claimant_name}</Typography>
        <Alert severity="warning">{t('Contact the intended recipient through a known channel and ask for the verification code shown on their claim. A matching name or email is not proof of identity.')}</Alert>
        <TextField name="verification_code" label={t('Verification code')} value={code} onChange={event => setCode(event.target.value)} slotProps={{ htmlInput: { maxLength: 12 } }}/>
        <FormControlLabel name="verified" control={<Checkbox checked={verified} onChange={event => setVerified(event.target.checked)}/>} label={t('I verified this claim with the intended recipient.')}/>
        <Typography>{t('Approval creates practitioner access for this clinic. It does not grant administration access or publish the public profile.')}</Typography>
        {error && <Alert severity="error">{error}</Alert>}
      </Stack></DialogContent><DialogActions><Button disabled={busy} onClick={() => setReview(null)}>{t('Cancel')}</Button><Button variant="contained" disabled={busy || !verified || code.trim().toUpperCase() !== review?.verification_code} onClick={() => { if (review) void action(review, true); }}>{t('Approve practitioner')}</Button></DialogActions>
    </Dialog>
  </Stack></Paper>;
}
export const StaffInvitations = withFormValidation(StaffInvitationsForm);
