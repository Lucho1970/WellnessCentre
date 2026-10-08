import { Alert, FormControlLabel, TextField, withFormValidation, useFormValidation } from '../shared/FormValidation';
import { useEffect, useState } from 'react';
import { Button, Checkbox, Divider, Stack, Typography } from '@mui/material';
import { portalLink } from '../shared/urls';
import { useTranslation } from 'react-i18next';
import { useUnsavedChanges } from '../shared/UnsavedChanges';

type Invitation = { id: number; expires_at: string; revoked_at: string | null; consumed_at: string | null; claim_status: string | null; claimant_name: string | null };
function ClientInvitationsForm({ clientId, request }: { clientId: number; request: (path: string, init?: RequestInit) => Promise<any> }) {
  const formValidation = useFormValidation();
  const { t } = useTranslation();
  const [items, setItems] = useState<Invitation[]>([]), [linked, setLinked] = useState(false);
  const [ready, setReady] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState(''), [link, setLink] = useState('');
  const [code, setCode] = useState(''), [verified, setVerified] = useState(false), [deliveryNotice, setDeliveryNotice] = useState('');
  const [refresh, setRefresh] = useState(0);
  useUnsavedChanges(code.trim() !== '' || verified);
  useEffect(() => {
    const controller = new AbortController(); setReady(false); (formValidation.clear(), setError(''));
    void request(`/${clientId}/invitations`, { signal: controller.signal }).then(data => {
      if (!controller.signal.aborted) { setItems(data.items); setLinked(data.linked); setReady(true); }
    }).catch(cause => { formValidation.capture(cause); if (!controller.signal.aborted) setError(cause.message); });
    return () => controller.abort();
  }, [clientId, request, refresh]);
  const run = async (action: () => Promise<void>) => { setBusy(true); (formValidation.clear(), setError('')); try { await action(); setCode(''); setVerified(false); setRefresh(v => v + 1); } catch (cause) { formValidation.capture(cause); setError(cause instanceof Error ? cause.message : t('Unable to update invitation.')); } finally { setBusy(false); } };
  const issue = (delivery: 'email' | 'manual') => void run(async () => {
    if (!window.confirm(t('Create a new invitation? Previous invitations and pending claims for this client will be revoked.'))) return;
    const result = await request(`/${clientId}/invitations`, { method: 'POST', body: JSON.stringify({ delivery }) });
    setDeliveryNotice(result.delivery === 'email_accepted' ? t('The mail provider accepted the invitation. The client must still complete sign-in and identity review.') : delivery === 'email' ? t('The invitation email was not confirmed. Copy and send this private link through a verified contact channel.') : '');
    setLink(result.delivery === 'email_accepted' ? '' : `${portalLink('client/invite')}#token=${result.token}`);
  });
  const review = (id: number, action: string) => void run(async () => {
    if (!window.confirm(t(action === 'approve' ? 'Approve this identity link?' : 'Reject/revoke this invitation?'))) return;
    await request(`/${clientId}/invitations/${id}`, { method: 'POST', body: JSON.stringify({ action, identity_verified: verified, review_code: code }) });
    setLink('');
  });
  return <Stack spacing={2} mt={3}>
    <Divider /><Typography variant="h6">{t('Client portal access')}</Typography>
    <Typography variant="body2">{t('Send a private invitation email or copy the one-time link. The client cannot view records until staff independently verify and approve the identity link.')}</Typography>
    {error && <Alert severity="error">{error}</Alert>}
    {deliveryNotice && <Alert severity={link ? 'warning' : 'info'}>{deliveryNotice}</Alert>}
    {ready && (linked ? <Alert severity="success">{t('This client record has an approved customer identity link.')}</Alert> : <Stack direction="row" gap={1}><Button disabled={busy} onClick={() => issue('email')}>{t('Send portal invitation email')}</Button><Button disabled={busy} onClick={() => issue('manual')}>{t('Create invitation link (48 hours)')}</Button></Stack>)}
    {link && <><TextField name="link" label={t('Private invitation link — shown only now')} value={link} slotProps={{ input: { readOnly: true } }} /><Button onClick={() => void navigator.clipboard.writeText(link).catch(() => setError(t('Select and copy the link manually.')))}>{t('Copy invitation link')}</Button></>}
    {items.map(item => <Stack key={item.id} spacing={1} sx={{ p: 2, border: '1px solid', borderColor: 'divider' }}>
      <Typography>{t('Invitation #{{id}} · {{status}} · Expires {{expires}} UTC',{id:item.id,status:item.revoked_at?t('Revoked'):item.claim_status??t('Awaiting acceptance'),expires:item.expires_at})}</Typography>
      {item.claim_status === 'pending' && !item.revoked_at && <>
        <Typography>{t('Claimant-provided name (unverified): {{name}}',{name:item.claimant_name})}</Typography>
        <TextField name="code" label={t('Review code from the verified client')} value={code} onChange={e => setCode(e.target.value)} inputProps={{ maxLength: 12 }} />
        <FormControlLabel name="verified" control={<Checkbox checked={verified} onChange={e => setVerified(e.target.checked)} />} label={t("I independently verified this person's identity through the client's established contact channel. A matching email/name alone is not enough.")} />
        <Button disabled={busy || !verified || code.length !== 12} onClick={() => review(item.id, 'approve')}>{t('Approve client link')}</Button>
      </>}
      {!item.revoked_at && item.claim_status !== 'approved' && <Button color="error" disabled={busy} onClick={() => review(item.id, 'revoke')}>{t('Revoke / reject')}</Button>}
    </Stack>)}
    <Button disabled={busy} onClick={() => setRefresh(v => v + 1)}>{t('Refresh portal access')}</Button>
  </Stack>;
}
export const ClientInvitations = withFormValidation(ClientInvitationsForm);
