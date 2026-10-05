import { Alert, Box, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';

export type NotificationHealth = {
  state: 'healthy' | 'not_observed' | 'running' | 'failed' | 'stuck' | 'stale' | 'overdue';
  last_status: 'running' | 'succeeded' | 'failed' | null;
  last_started_at: string | null;
  last_completed_at: string | null;
  last_success_at: string | null;
  last_failure_at: string | null;
  overdue_count: number;
  stale_after_minutes: number;
  overdue_after_minutes: number;
};

const label: Record<NotificationHealth['state'], string> = {
  healthy: 'Healthy',
  not_observed: 'No scheduled run recorded yet',
  running: 'Scheduled run in progress',
  failed: 'Last scheduled run failed',
  stuck: 'Scheduled run appears stuck',
  stale: 'Scheduler has not succeeded recently',
  overdue: 'Notifications are overdue',
};
const time = (value: string | null) => value ? new Date(`${value.replace(' ', 'T')}Z`).toLocaleString() : '—';

export function NotificationHealthBanner({ health }: { health: NotificationHealth | null | undefined }) {
  const { t } = useTranslation();
  if (!health) return null;
  return <Alert severity={health.state === 'healthy' ? 'success' : health.state === 'not_observed' || health.state === 'running' ? 'info' : 'warning'}>
    <Box><Typography fontWeight={700}>{t('Notification scheduler')}: {t(label[health.state])}</Typography>
      <Typography variant="body2">{t('Last successful scheduled run')}: {time(health.last_success_at)} · {t('Overdue notices (more than 30 minutes)')}: {health.overdue_count}</Typography>
      {health.state === 'not_observed' && <Typography variant="body2">{t('The next scheduled run should establish a health baseline. Immediate booking sends do not count as scheduled runs.')}</Typography>}
      {health.state === 'stale' && <Typography variant="body2">{t('Check the Azure scheduler and Netfirms trigger; a successful HTTP call alone does not prove queue processing.')}</Typography>}
      {health.state === 'failed' && <Typography variant="body2">{t('Last failed scheduled run')}: {time(health.last_failure_at)}</Typography>}
    </Box>
  </Alert>;
}
