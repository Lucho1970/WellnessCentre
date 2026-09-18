import { useEffect, useState } from 'react';
import { Alert, Button, Checkbox, Divider, FormControlLabel, Stack, TextField, Typography } from '@mui/material';
import { portalLink } from '../shared/urls';
import { useTranslation } from 'react-i18next';

type Invitation = { id: number; expires_at: string; revoked_at: string | null; consumed_at: string | null; claim_status: string | null; claimant_name: string | null };
export function ClientInvitations({ clientId, request }: { clientId: number; request: (path: string, init?: RequestInit) => Promise<any> }) {
  const { t } = useTranslation();
  const [items, setItems] = useState<Invitation[]>([]), [linked, setLinked] = useState(false);
  const [ready, setReady] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState(''), [link, setLink] = useState('');
  const [code, setCode] = useState(''), [verified, setVerified] = useState(false);
  const [refresh, setRefresh] = useState(0);
  useEffect(() => {
    const controller = new AbortController(); setReady(false); setError('');
    void request(`/${clientId}/invitations`, { signal: controller.signal }).then(data => {
      if (!controller.signal.aborted) { setItems(data.items); setLinked(data.linked); setReady(true); }
    }).catch(cause => { if (!controller.signal.aborted) setError(cause.message); });
    return () => controller.abort();
  }, [clientId, request, refresh]);
  const run = async (action: () => Promise<void>) => { setBusy(true); setError(''); try { await action(); setCode(''); setVerified(false); setRefresh(v => v + 1); } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to update invitation.')); } finally { setBusy(false); } };
  const issue = () => void run(async () => {
    if (!window.confirm(t('Create a new invitation? Previous invitations and pending claims for this client will be revoked.'))) return;
    const result = await request(`/${clientId}/invitations`, { method: 'POST', body: '{}' });
    setLink(`${portalLink('client/invite')}#token=${result.token}`);
  });
  const review = (id: number, action: string) => void run(async () => {
    if (!window.confirm(t(action === 'approve' ? 'Approve this identity link?' : 'Reject/revoke this invitation?'))) return;
    await request(`/${clientId}/invitations/${id}`, { method: 'POST', body: JSON.stringify({ action, identity_verified: verified, review_code: code }) });
    setLink('');
  });
  return <Stack spacing={2} mt={3}>
    <Divider /><Typography variant="h6">{t('Client portal access')}</Typography>
    <Typography variant="body2">{t('Invitation links are copied and sent manually. No email is sent by this application. Confirm the recipient through an established contact channel.')}</Typography>
    {error && <Alert severity="warning">{error}</Alert>}
    {ready && (linked ? <Alert severity="success">{t('This client record has an approved customer identity link.')}</Alert> : <Button disabled={busy} onClick={issue}>{t('Create invitation (48 hours)')}</Button>)}
    {link && <><TextField label={t('Private invitation link — shown only now')} value={link} slotProps={{ input: { readOnly: true } }} /><Button onClick={() => void navigator.clipboard.writeText(link).catch(() => setError(t('Select and copy the link manually.')))}>{t('Copy invitation link')}</Button></>}
    {items.map(item => <Stack key={item.id} spacing={1} sx={{ p: 2, border: '1px solid', borderColor: 'divider' }}>
      <Typography>{t('Invitation #{{id}} · {{status}} · Expires {{expires}} UTC',{id:item.id,status:item.revoked_at?t('Revoked'):item.claim_status??t('Awaiting acceptance'),expires:item.expires_at})}</Typography>
      {item.claim_status === 'pending' && !item.revoked_at && <>
        <Typography>{t('Claimant-provided name (unverified): {{name}}',{name:item.claimant_name})}</Typography>
        <TextField label={t('Review code from the verified client')} value={code} onChange={e => setCode(e.target.value)} inputProps={{ maxLength: 12 }} />
        <FormControlLabel control={<Checkbox checked={verified} onChange={e => setVerified(e.target.checked)} />} label={t("I independently verified this person's identity through the client's established contact channel. A matching email/name alone is not enough.")} />
        <Button disabled={busy || !verified || code.length !== 12} onClick={() => review(item.id, 'approve')}>{t('Approve client link')}</Button>
      </>}
      {!item.revoked_at && item.claim_status !== 'approved' && <Button color="error" disabled={busy} onClick={() => review(item.id, 'revoke')}>{t('Revoke / reject')}</Button>}
    </Stack>)}
    <Button disabled={busy} onClick={() => setRefresh(v => v + 1)}>{t('Refresh portal access')}</Button>
  </Stack>;
}
