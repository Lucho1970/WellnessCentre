import { useCallback, useEffect, useState, type FormEvent } from "react";
import {
  Alert,
  Button,
  FormControlLabel,
  Grid,
  MenuItem,
  Paper,
  Stack,
  Switch,
  TextField,
  Typography,
} from "@mui/material";
import { DoorOpen, Pencil, Plus, Save, X } from "lucide-react";
import { useTranslation } from "react-i18next";
import { useStaffAuth } from "../auth/AuthProvider";
import { apiErrorMessage, normalizeNumericIds } from "../shared/api";
import { useUnsavedForm } from "../shared/UnsavedChanges";
const api = import.meta.env.VITE_API_BASE_URL ?? "http://localhost:8080/api/v1";
type Location = { id: number; name: string };
type Room = {
  id: number;
  location_id: number;
  location_name: string;
  name: string;
  room_type: string | null;
  equipment_notes: string | null;
  turnover_minutes: number;
  is_bookable: number | boolean;
};
type Form = {
  location_id: string;
  name: string;
  room_type: string;
  equipment_notes: string;
  turnover_minutes: string;
  is_bookable: boolean;
};
const blank: Form = {
  location_id: "",
  name: "",
  room_type: "Treatment room",
  equipment_notes: "",
  turnover_minutes: "0",
  is_bookable: true,
};
export function RoomAdmin() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [locations, setLocations] = useState<Location[]>([]),
    [rooms, setRooms] = useState<Room[]>([]),
    [form, setForm] = useState<Form>(blank);
  const [editing, setEditing] = useState<number | null>(null),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [saved, setSaved] = useState("");
  const { markDirty, markClean } = useUnsavedForm();
  const load = useCallback(async () => {
    setBusy(true);
    try {
      const token = await getAccessToken(),
        [lr, rr] = await Promise.all([
          fetch(`${api}/admin/locations`, {
            headers: { Authorization: `Bearer ${token}` },
          }),
          fetch(`${api}/admin/rooms`, {
            headers: { Authorization: `Bearer ${token}` },
          }),
        ]),
        [lb, rb] = await Promise.all([lr.json(), rr.json()]);
      if (!lr.ok)
        throw new Error(
          apiErrorMessage(lb, lr.status, t("Unable to load locations.")),
        );
      if (!rr.ok)
        throw new Error(
          apiErrorMessage(rb, rr.status, t("Unable to load rooms.")),
        );
      const loadedLocations = normalizeNumericIds<Location[]>(lb.data);
      setLocations(loadedLocations);
      setRooms(normalizeNumericIds(rb.data));
      setForm((f) => ({
        ...f,
        location_id: f.location_id || String(loadedLocations[0]?.id ?? ""),
      }));
    } catch (c) {
      setError(c instanceof Error ? c.message : t("Unable to load rooms."));
    } finally {
      setBusy(false);
    }
  }, [getAccessToken, t]);
  useEffect(() => {
    void load();
  }, [load]);
  const field = <K extends keyof Form>(k: K, v: Form[K]) =>
    setForm((f) => ({ ...f, [k]: v }));
  const reset = () => {
    markClean();
    setEditing(null);
    setForm({ ...blank, location_id: String(locations[0]?.id ?? "") });
  };
  const edit = (r: Room) => {
    markClean();
    setEditing(r.id);
    setForm({
      location_id: String(r.location_id),
      name: r.name,
      room_type: r.room_type ?? "",
      equipment_notes: r.equipment_notes ?? "",
      turnover_minutes: String(r.turnover_minutes),
      is_bookable: Boolean(Number(r.is_bookable)),
    });
    setSaved("");
    setError("");
  };
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError("");
    setSaved("");
    try {
      const token = await getAccessToken(),
        response = await fetch(
          editing ? `${api}/admin/rooms/${editing}` : `${api}/admin/rooms`,
          {
            method: editing ? "PATCH" : "POST",
            headers: {
              Authorization: `Bearer ${token}`,
              "Content-Type": "application/json",
            },
            body: JSON.stringify({
              ...form,
              location_id: Number(form.location_id),
              turnover_minutes: Number(form.turnover_minutes),
            }),
          },
        ),
        body = await response.json();
      if (!response.ok)
        throw new Error(
          apiErrorMessage(body, response.status, t("Unable to save room.")),
        );
      const message = t("{{name}} was {{action}}.", {
        name: form.name,
        action: t(editing ? "updated" : "created"),
      });
      reset();
      await load();
      setSaved(message);
    } catch (c) {
      setError(c instanceof Error ? c.message : t("Unable to save room."));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Stack spacing={3}>
      <Paper
        component="form"
        onSubmit={submit}
        onChange={markDirty}
        variant="outlined"
        sx={{ p: { xs: 2, md: 3 } }}
      >
        <Stack direction="row" justifyContent="space-between">
          <span>
            <Typography variant="h5">
              {t(editing ? "Edit room" : "Add a room")}
            </Typography>
            <Typography color="text.secondary" mb={2}>
              {t(
                "Configure treatment spaces and turnaround time between appointments.",
              )}
            </Typography>
          </span>
          {editing && (
            <Button startIcon={<X size={16} />} onClick={reset}>
              {t("Cancel")}
            </Button>
          )}
        </Stack>
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, md: 6 }}>
            <TextField
              required
              select
              fullWidth
              label={t("Location")}
              value={form.location_id}
              onChange={(e) => field("location_id", e.target.value)}
            >
              {locations.map((l) => (
                <MenuItem key={l.id} value={String(l.id)}>
                  {l.name}
                </MenuItem>
              ))}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, md: 6 }}>
            <TextField
              required
              fullWidth
              label={t("Room name")}
              value={form.name}
              onChange={(e) => field("name", e.target.value)}
            />
          </Grid>
          <Grid size={{ xs: 12, md: 6 }}>
            <TextField
              fullWidth
              label={t("Room type")}
              value={form.room_type}
              onChange={(e) => field("room_type", e.target.value)}
            />
          </Grid>
          <Grid size={{ xs: 12, md: 6 }}>
            <TextField
              required
              type="number"
              fullWidth
              label={t("Turnover time (minutes)")}
              value={form.turnover_minutes}
              onChange={(e) => field("turnover_minutes", e.target.value)}
              inputProps={{ min: 0, max: 240, step: 5 }}
            />
          </Grid>
          <Grid size={{ xs: 12 }}>
            <TextField
              multiline
              minRows={2}
              fullWidth
              label={t("Equipment and room notes")}
              value={form.equipment_notes}
              onChange={(e) => field("equipment_notes", e.target.value)}
            />
          </Grid>
          <Grid size={{ xs: 12 }}>
            <FormControlLabel
              control={
                <Switch
                  checked={form.is_bookable}
                  onChange={(e) => field("is_bookable", e.target.checked)}
                />
              }
              label={t("Available for booking")}
            />
          </Grid>
        </Grid>
        {error && (
          <Alert severity="error" sx={{ mt: 2 }}>
            {error}
          </Alert>
        )}
        {saved && (
          <Alert severity="success" sx={{ mt: 2 }}>
            {saved}
          </Alert>
        )}
        <Button
          type="submit"
          variant="contained"
          disabled={busy || !form.location_id}
          startIcon={editing ? <Save size={17} /> : <Plus size={17} />}
          sx={{ mt: 2 }}
        >
          {t(busy ? "Saving…" : editing ? "Save changes" : "Add room")}
        </Button>
      </Paper>
      <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 } }}>
        <Typography variant="h5" mb={2}>
          {t("Rooms")}
        </Typography>
        <Stack spacing={1.5}>
          {rooms.map((r) => (
            <Stack
              key={r.id}
              direction={{ xs: "column", sm: "row" }}
              justifyContent="space-between"
              alignItems={{ sm: "center" }}
              sx={{
                p: 2,
                border: "1px solid",
                borderColor: "divider",
                borderRadius: 2,
              }}
            >
              <Stack direction="row" spacing={1.5}>
                <DoorOpen color="#176b62" />
                <span>
                  <Typography fontWeight={700}>{r.name}</Typography>
                  <Typography variant="body2" color="text.secondary">
                    {r.location_name} · {r.room_type || t("General")} ·{" "}
                    {t("{{minutes}} min turnover", {
                      minutes: r.turnover_minutes,
                    })}{" "}
                    ·{" "}
                    {t(
                      Boolean(Number(r.is_bookable))
                        ? "Bookable"
                        : "Not bookable",
                    )}
                  </Typography>
                </span>
              </Stack>
              <Button startIcon={<Pencil size={16} />} onClick={() => edit(r)}>
                {t("Edit")}
              </Button>
            </Stack>
          ))}
          {!busy && !rooms.length && (
            <Typography color="text.secondary">
              {t("No rooms configured.")}
            </Typography>
          )}
        </Stack>
      </Paper>
    </Stack>
  );
}
