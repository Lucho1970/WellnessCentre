import { useCallback, useEffect, useState } from 'react';
import { Alert, Box, Button, Card, CardContent, CircularProgress, Stack, Typography } from '@mui/material';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiBaseUrl, apiErrorMessage, normalizeNumericIds } from '../shared/api';
import { pagePath } from '../portal/access';

type Counts = { scheduled_visits: number; completed: number; no_show: number; awaiting_outcome: number; visits_with_steps: number; onsite_arrivals: number; onsite_departures: number };
type Summary = { start_date: string; end_date: string; timezone: string; counts: Counts };

export function PractitionerVisitSummary() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const load = useCallback(async () => {
    setLoading(true); setError('');
    try {
      const token = await getAccessToken();
      const response = await fetch(`${apiBaseUrl}/practitioner/visit-summary?refresh=${Date.now()}`, { headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json().catch(() => null);
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status));
      const data = normalizeNumericIds(body?.data);
      if (!data || typeof data !== 'object' || !('counts' in data) || !data.counts) throw new Error(t('Unable to load visit activity.'));
      setSummary(data as Summary);
    } catch (cause) { setSummary(null); setError(cause instanceof Error ? cause.message : t('Unable to load visit activity.')); }
    finally { setLoading(false); }
  }, [getAccessToken, t]);
  useEffect(() => { void load(); }, [load]);
  const counts = summary?.counts;
  const metrics = [
    ['Scheduled visits', counts?.scheduled_visits ?? 0],
    ['Completed visits', counts?.completed ?? 0],
    ['No-shows', counts?.no_show ?? 0],
    ['Past visits awaiting outcome', counts?.awaiting_outcome ?? 0],
  ] as const;
  return <Card variant="outlined"><CardContent><Stack spacing={1.5}>
    <Stack direction="row" justifyContent="space-between" alignItems="center" gap={1}>
      <Box><Typography variant="h5">{t('Visit activity · last 7 days')}</Typography>{summary && <Typography color="text.secondary">{summary.start_date} – {summary.end_date} · {summary.timezone}</Typography>}</Box>
      <Button disabled={loading} onClick={() => void load()}>{t('Refresh')}</Button>
    </Stack>
    {loading ? <CircularProgress size={24} aria-label={t('Loading visit activity')} /> : error ? <Alert severity="error">{error}</Alert> : counts && <>
      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: 'repeat(2,minmax(0,1fr))', md: 'repeat(4,minmax(0,1fr))' }, gap: 2 }}>
        {metrics.map(([label, value]) => <Box key={label}><Typography variant="h4" fontWeight={750}>{value}</Typography><Typography variant="body2">{t(label)}</Typography></Box>)}
      </Box>
      <Typography variant="body2">{t('Optional steps currently recorded')}: {counts.visits_with_steps} · {t('On-Site arrivals recorded')}: {counts.onsite_arrivals} · {t('On-Site departures recorded')}: {counts.onsite_departures}</Typography>
      <Typography variant="body2" color="text.secondary">{t('Visits are grouped by scheduled date and current booking status. Steps are optional; an unrecorded step does not mean care was missed.')}</Typography>
      {counts.awaiting_outcome > 0 && <Button component={Link} to={`${pagePath('practitioner', 'appointments')}?view=needs_outcome`} sx={{ alignSelf: 'flex-start' }}>{t('Review visits awaiting outcome')} →</Button>}
    </>}
  </Stack></CardContent></Card>;
}
