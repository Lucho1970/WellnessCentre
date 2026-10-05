import { useEffect, useState } from 'react';
import { Alert, Box, Button, CircularProgress, Divider, List, ListItem, ListItemText, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { formatDateTime } from '../i18n/format';

type VisitEvent = { event_code: string; event_action: 'record' | 'undo'; occurred_at: string };
type VisitHistory = { events: VisitEvent[]; truncated: boolean };
type Props = {
  appointmentId: number;
  serviceName: string;
  timezone: string;
  enabled: boolean;
  request: (path: string, init?: RequestInit) => Promise<unknown>;
};

export function PractitionerVisitHistory({ appointmentId, serviceName, timezone, enabled, request }: Props) {
  const { t, i18n } = useTranslation();
  const [history, setHistory] = useState<VisitHistory | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [refresh, setRefresh] = useState(0);
  useEffect(() => {
    if (!enabled) return;
    const controller = new AbortController();
    setLoading(true); setError(''); setHistory(null);
    void request(`/practitioner/appointments/${appointmentId}/visit-history`, { signal: controller.signal })
      .then(data => {
        if (controller.signal.aborted) return;
        if (!data || typeof data !== 'object' || !('events' in data) || !Array.isArray(data.events)) throw new Error(t('Unable to load visit history.'));
        setHistory(data as VisitHistory);
      })
      .catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : t('Unable to load visit history.')); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [appointmentId, enabled, refresh, request, t]);
  const stepLabel = (code: string) => {
    if (code === 'session_started') return t('Started {{service}}', { service: serviceName });
    if (code === 'session_finished') return t('Finished {{service}}', { service: serviceName });
    return t(({ en_route: 'En route', arrived: 'Arrived', left_residence: 'Left residence' } as Record<string, string>)[code] ?? code);
  };
  return <Stack spacing={1.5}>
    <Divider />
    <Stack direction="row" justifyContent="space-between" alignItems="center" gap={1}>
      <Typography variant="h6">{t('Visit step history')}</Typography>
      <Button size="small" onClick={() => setRefresh(value => value + 1)} disabled={loading}>{t('Refresh')}</Button>
    </Stack>
    <Typography variant="body2" color="text.secondary">{t('These are manually recorded steps and corrections, not proof of physical location or treatment.')}</Typography>
    {loading && <CircularProgress size={20} aria-label={t('Loading visit history')} />}
    {error && <Alert severity="error">{error}</Alert>}
    {history && (history.events.length === 0 ? <Typography color="text.secondary">{t('No visit steps recorded.')}</Typography> : <>
      <List dense disablePadding aria-label={t('Visit step history')}>
        {history.events.map((event, index) => <ListItem key={`${event.occurred_at}-${event.event_code}-${index}`} disableGutters>
          <ListItemText primary={t(event.event_action === 'undo' ? 'Undid {{step}}' : 'Recorded {{step}}', { step: stepLabel(event.event_code) })} secondary={formatDateTime(`${event.occurred_at.replace(' ', 'T')}Z`, i18n.resolvedLanguage, { timeZone: timezone, dateStyle: 'medium', timeStyle: 'short' })} />
        </ListItem>)}
      </List>
      {history.truncated && <Box><Typography variant="body2" color="text.secondary">{t('Showing the latest 100 visit steps.')}</Typography></Box>}
    </>)}
  </Stack>;
}
