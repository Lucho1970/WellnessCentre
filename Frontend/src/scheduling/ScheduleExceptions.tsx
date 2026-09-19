import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Alert, Button, Grid, MenuItem, Paper, Stack, TextField, Typography } from "@mui/material";
import { CalendarOff, Plus, Trash2 } from "lucide-react";
import { useTranslation } from "react-i18next";
import { useStaffAuth } from "../auth/AuthProvider";
import { apiErrorMessage } from "../shared/api";
import { formatDateTime } from "../i18n/format";
import { useUnsavedForm } from "../shared/UnsavedChanges";

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL ?? "http://localhost:8080/api/v1";

type Practitioner = { practitioner_id: number; display_name: string };
type Location = { id: number; name: string; timezone: string };
type Exception = {
  id: number;
  kind: "override" | "time_off";
  practitioner_name: string;
  location_name: string | null;
  starts_at: string;
  ends_at: string;
  type: string;
  reason: string | null;
};

const initialTime = (hoursAhead: number) => {
  const date = new Date();
  date.setMinutes(0, 0, 0);
  date.setHours(date.getHours() + hoursAhead);
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
  return local.toISOString().slice(0, 16);
};

export function ScheduleExceptions() {
  const { t, i18n } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [practitioners, setPractitioners] = useState<Practitioner[]>([]);
  const [locations, setLocations] = useState<Location[]>([]);
  const [exceptions, setExceptions] = useState<Exception[]>([]);
  const [kind, setKind] = useState<"override" | "time_off">("override");
  const [form, setForm] = useState({ practitioner_id: "", location_id: "", starts_at: initialTime(1), ends_at: initialTime(2), type: "blocked", reason: "" });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [saved, setSaved] = useState("");
  const { markDirty, markClean } = useUnsavedForm();

  const load = useCallback(async () => {
    try {
      const token = await getAccessToken();
      const headers = { Authorization: `Bearer ${token}` };
      const responses = await Promise.all([
        fetch(`${apiBaseUrl}/admin/practitioners`, { headers }),
        fetch(`${apiBaseUrl}/admin/locations`, { headers }),
        fetch(`${apiBaseUrl}/admin/schedule-exceptions`, { headers }),
      ]);
      const bodies = await Promise.all(responses.map((response) => response.json()));
      if (responses.some((response) => !response.ok)) throw new Error(t("Unable to load schedule changes."));
      setPractitioners(bodies[0].data);
      setLocations(bodies[1].data);
      setExceptions(bodies[2].data);
      setForm((current) => ({
        ...current,
        practitioner_id: current.practitioner_id || String(bodies[0].data[0]?.practitioner_id ?? ""),
        location_id: current.location_id || String(bodies[1].data[0]?.id ?? ""),
      }));
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : t("Unable to load schedule changes."));
    }
  }, [getAccessToken, t]);

  useEffect(() => { void load(); }, [load]);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setBusy(true); setError(""); setSaved("");
    try {
      const token = await getAccessToken();
      const payload = {
        practitioner_id: Number(form.practitioner_id),
        location_id: Number(form.location_id),
        starts_at: form.starts_at,
        ends_at: form.ends_at,
        ...(kind === "override" ? { override_type: form.type, reason: form.reason } : { reason_type: form.type, notes: form.reason }),
      };
      const response = await fetch(`${apiBaseUrl}/admin/${kind === "override" ? "availability-overrides" : "time-off"}`, {
        method: "POST",
        headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t("Unable to save schedule change.")));
      markClean();
      setSaved(t(kind === "override" ? "Availability override added." : "Time off added."));
      await load();
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : t("Unable to save schedule change."));
    } finally { setBusy(false); }
  };

  const remove = async (item: Exception) => {
    setError(""); setSaved("");
    try {
      const token = await getAccessToken();
      const path = item.kind === "override" ? "availability-overrides" : "time-off";
      const response = await fetch(`${apiBaseUrl}/admin/${path}/${item.id}`, { method: "DELETE", headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status, t("Unable to remove schedule change.")));
      await load();
    } catch (cause) { setError(cause instanceof Error ? cause.message : t("Unable to remove schedule change.")); }
  };

  const selectedLocation = locations.find((location) => String(location.id) === form.location_id);
  const showUtc = (value: string) => formatDateTime(`${value.replace(" ", "T")}Z`, i18n.resolvedLanguage, { dateStyle: "medium", timeStyle: "short" });
  return <Stack spacing={3} mt={3}>
    <Paper component="form" onSubmit={submit} onChange={markDirty} variant="outlined" sx={{ p: 3 }}>
      <Stack direction="row" spacing={1.5} alignItems="center"><CalendarOff color="#176b62"/><Typography variant="h5">{t("Schedule changes and time off")}</Typography></Stack>
      <Typography color="text.secondary" mt={1}>{t("Add a one-time opening, blocked period, vacation, illness, or personal absence. Enter times in {{timezone}}.", { timezone: selectedLocation?.timezone ?? t("the selected location's timezone") })}</Typography>
      <Grid container spacing={2} mt={1}>
        <Grid size={{ xs: 12, md: 4 }}><TextField select fullWidth label={t("Change type")} value={kind} onChange={(event) => { const next = event.target.value as "override" | "time_off"; setKind(next); setForm((current) => ({ ...current, type: next === "override" ? "blocked" : "vacation" })); }}><MenuItem value="override">{t("Availability override")}</MenuItem><MenuItem value="time_off">{t("Time off")}</MenuItem></TextField></Grid>
        <Grid size={{ xs: 12, md: 4 }}><TextField required select fullWidth label={t("Practitioner")} value={form.practitioner_id} onChange={(event) => setForm((current) => ({ ...current, practitioner_id: event.target.value }))}>{practitioners.map((practitioner) => <MenuItem key={practitioner.practitioner_id} value={String(practitioner.practitioner_id)}>{practitioner.display_name}</MenuItem>)}</TextField></Grid>
        <Grid size={{ xs: 12, md: 4 }}><TextField required select fullWidth label={t("Timezone location")} value={form.location_id} onChange={(event) => setForm((current) => ({ ...current, location_id: event.target.value }))}>{locations.map((location) => <MenuItem key={location.id} value={String(location.id)}>{location.name} ({location.timezone})</MenuItem>)}</TextField></Grid>
        <Grid size={{ xs: 12, md: 6 }}><TextField required type="datetime-local" fullWidth label={t("Starts")} value={form.starts_at} onChange={(event) => setForm((current) => ({ ...current, starts_at: event.target.value }))} InputLabelProps={{ shrink: true }}/></Grid>
        <Grid size={{ xs: 12, md: 6 }}><TextField required type="datetime-local" fullWidth label={t("Ends")} value={form.ends_at} onChange={(event) => setForm((current) => ({ ...current, ends_at: event.target.value }))} InputLabelProps={{ shrink: true }}/></Grid>
        <Grid size={{ xs: 12, md: 4 }}><TextField select fullWidth label={t(kind === "override" ? "Availability" : "Reason type")} value={form.type} onChange={(event) => setForm((current) => ({ ...current, type: event.target.value }))}>{(kind === "override" ? [["blocked", "Blocked"], ["available", "Available"]] : [["vacation", "Vacation"], ["sick", "Sick"], ["personal", "Personal"], ["other", "Other"]]).map(([value, label]) => <MenuItem key={value} value={value}>{t(label)}</MenuItem>)}</TextField></Grid>
        <Grid size={{ xs: 12, md: 8 }}><TextField fullWidth label={t("Notes (optional)")} value={form.reason} inputProps={{ maxLength: 500 }} onChange={(event) => setForm((current) => ({ ...current, reason: event.target.value }))}/></Grid>
      </Grid>
      {error && <Alert severity="error" sx={{ mt: 2 }}>{error}</Alert>}{saved && <Alert severity="success" sx={{ mt: 2 }}>{saved}</Alert>}
      <Button disabled={busy || !form.practitioner_id || !form.location_id} type="submit" variant="contained" startIcon={<Plus size={17}/>} sx={{ mt: 2 }}>{t("Add schedule change")}</Button>
    </Paper>
    <Paper variant="outlined" sx={{ p: 3 }}><Typography variant="h5" mb={2}>{t("Upcoming schedule changes")}</Typography><Stack spacing={1}>{exceptions.length === 0 && <Typography color="text.secondary">{t("No schedule changes have been added.")}</Typography>}{exceptions.map((item) => <Stack key={`${item.kind}-${item.id}`} direction={{ xs: "column", md: "row" }} justifyContent="space-between" alignItems={{ xs: "flex-start", md: "center" }} spacing={1} sx={{ p: 1.5, border: "1px solid", borderColor: "divider", borderRadius: 2 }}><BoxText item={item} showUtc={showUtc}/><Button color="error" startIcon={<Trash2 size={16}/>} onClick={() => void remove(item)}>{t("Remove")}</Button></Stack>)}</Stack></Paper>
  </Stack>;
}

function BoxText({ item, showUtc }: { item: Exception; showUtc: (value: string) => string }) {
  const { t } = useTranslation();
  const typeLabels: Record<string, string> = { blocked: 'Blocked', available: 'Available', vacation: 'Vacation', sick: 'Sick', personal: 'Personal', other: 'Other' };
  const translatedType = t(typeLabels[item.type] ?? item.type.replace("_", " "));
  return <div><Typography fontWeight={700}>{item.practitioner_name} · {item.kind === "override" ? translatedType : t("Time off — {{type}}", { type: translatedType })}</Typography><Typography color="text.secondary">{showUtc(item.starts_at)} – {showUtc(item.ends_at)}{item.location_name ? ` · ${item.location_name}` : ""}{item.reason ? ` · ${item.reason}` : ""}</Typography></div>;
}
