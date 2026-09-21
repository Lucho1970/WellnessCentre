import { useState } from "react";
import {
  Dialog,
  DialogContent,
  DialogTitle,
  IconButton,
  List,
  ListItemButton,
  ListItemIcon,
  ListItemText,
  Typography,
} from "@mui/material";
import { Check } from "lucide-react";
import { useTranslation } from "react-i18next";
import globeLanguageIcon from "../images/globe-language.svg";

const languages = [
  { code: "en", name: "English", regionalName: "English (Canada)" },
  { code: "fr", name: "French", regionalName: "Français (Canada)" },
] as const;

export function LanguageSwitcher() {
  const { t, i18n } = useTranslation();
  const [open, setOpen] = useState(false);
  const selected = i18n.resolvedLanguage === "fr" ? "fr" : "en";
  const choose = async (language: "en" | "fr") => {
    await i18n.changeLanguage(language);
    setOpen(false);
  };

  return (
    <>
      <IconButton
        color="primary"
        aria-label={t("LanguageAndRegion")}
        aria-haspopup="dialog"
        aria-expanded={open ? "true" : undefined}
        title={t("LanguageAndRegion")}
        onClick={() => setOpen(true)}
        sx={{ flexShrink: 0 }}
      >
        <span
          aria-hidden="true"
          style={{
            display: "block",
            width: 20,
            height: 20,
            backgroundColor: "currentColor",
            mask: `url("${globeLanguageIcon}") center / contain no-repeat`,
            WebkitMask: `url("${globeLanguageIcon}") center / contain no-repeat`,
          }}
        />
      </IconButton>
      <Dialog
        open={open}
        onClose={() => setOpen(false)}
        fullWidth
        maxWidth="xs"
        aria-labelledby="language-region-title"
      >
        <DialogTitle id="language-region-title">
          {t("LanguageAndRegion")}
        </DialogTitle>
        <DialogContent dividers>
          <Typography
            variant="overline"
            color="text.secondary"
            fontWeight={800}
          >
            {t("Language")}
          </Typography>
          <List
            disablePadding
            sx={{ mt: 1 }}
            aria-label={t("Choose a language")}
          >
            {languages.map((language) => (
              <ListItemButton
                key={language.code}
                selected={selected === language.code}
                aria-current={selected === language.code ? "true" : undefined}
                onClick={() => void choose(language.code)}
                sx={{ borderRadius: 2 }}
              >
                <ListItemText
                  primary={language.regionalName}
                  secondary={t(language.name)}
                />
                <ListItemIcon sx={{ minWidth: 32, justifyContent: "flex-end" }}>
                  {selected === language.code && (
                    <Check size={19} aria-hidden="true" />
                  )}
                </ListItemIcon>
              </ListItemButton>
            ))}
          </List>
          <Typography variant="body2" color="text.secondary" mt={2}>
            {t("Additional regional preferences can be added here later.")}
          </Typography>
        </DialogContent>
      </Dialog>
    </>
  );
}
