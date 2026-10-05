import { useEffect, useRef, useState } from 'react';
import { Box, Button, Stack, Typography } from '@mui/material';
import FullCalendar from '@fullcalendar/react';
import themePlugin from '@fullcalendar/react/themes/monarch';
import dayGridPlugin from '@fullcalendar/react/daygrid';
import interactionPlugin from '@fullcalendar/react/interaction';
import frCaLocale from '@fullcalendar/react/locales/fr-ca';
import type { CalendarRef } from '@fullcalendar/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import '@fullcalendar/react/skeleton.css';
import '@fullcalendar/react/themes/monarch/theme.css';
import '@fullcalendar/react/themes/monarch/palettes/green.css';

const dateKey = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
export function AvailabilityDateCalendar({ selectedDate, minDate, disabled, onDateChange }: {
  selectedDate: string;
  minDate: string;
  disabled: boolean;
  onDateChange: (date: string) => void;
}) {
  const { t, i18n } = useTranslation();
  const calendar = useRef<CalendarRef | null>(null);
  const [title, setTitle] = useState('');
  useEffect(() => {
    if (!selectedDate) return;
    const api = calendar.current?.getApi();
    if (api && dateKey(api.getDate()).slice(0, 7) !== selectedDate.slice(0, 7)) api.gotoDate(selectedDate);
  }, [selectedDate]);
  const choose = (date: string) => {
    if (!disabled && date >= minDate) onDateChange(date);
  };
  return <Box className="booking-availability-calendar" sx={{ minWidth: 0, opacity: disabled ? 0.55 : 1 }}>
    <Stack direction="row" alignItems="center" justifyContent="space-between" spacing={1} sx={{ mb: 1 }}>
      <Stack direction="row" alignItems="center" spacing={0.5}>
        <Button size="small" aria-label={t('Previous month')} onClick={() => calendar.current?.getApi().prev()}><ChevronLeft size={18} /></Button>
        <Button size="small" aria-label={t('Next month')} onClick={() => calendar.current?.getApi().next()}><ChevronRight size={18} /></Button>
        <Button size="small" onClick={() => calendar.current?.getApi().today()}>{t('Today')}</Button>
      </Stack>
      <Typography variant="subtitle1" fontWeight={700}>{title}</Typography>
    </Stack>
    <FullCalendar ref={calendar} plugins={[themePlugin, dayGridPlugin, interactionPlugin]} initialView="dayGridMonth" headerToolbar={false}
      fixedWeekCount={false} showNonCurrentDates={false} height="auto" locale={i18n.resolvedLanguage?.startsWith('fr') ? frCaLocale : 'en'}
      datesSet={info => setTitle(info.view.title)} dateClick={info => choose(info.dateStr)}
      dayCellClass={info => {
        const date = dateKey(info.date);
        return [date === selectedDate ? 'booking-calendar-selected' : '', date < minDate ? 'booking-calendar-past' : ''].filter(Boolean).join(' ');
      }}
      dayCellTopContent={info => <span className="booking-calendar-date-number">{info.dayNumberText}</span>}
    />
  </Box>;
}
