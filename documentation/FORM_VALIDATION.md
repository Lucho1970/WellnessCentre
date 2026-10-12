# Form validation feedback

Staff and client form editors preserve API `error.fields` instead of reducing the response to a generic error string. Named text/select fields show their message directly below the control, with MUI's accessible helper-text association and invalid state. Checkbox and radio/address groups use the same mechanism. Values remain unchanged on failure; editing a field clears its own server error, and a new save attempt clears stale errors before validation.

After a failed request, the form scrolls to and focuses its first visible invalid field in document order, regardless of the order of keys in the API response. Reduced-motion preferences disable animated scrolling. Dialog and drawer controls remain in their editor's validation scope even though MUI renders them through a portal. Unmapped field messages appear in that form's existing error summary; errors without field details focus the summary. Closing an editor removes orphaned field feedback. Correlation references remain in the summary.

The Team profiles/public card phone error now appears under Public contact phone. Enter an international number such as `+12892975234`; the validation rules are unchanged. Common server field messages have English/French resources. Unrecognized field validation text is shown as plain text rather than discarded. Form summaries and infrastructure/server failures retain the existing translated API error handling.

## Maintaining forms

- Throw `ApiError(body, response.status, fallback)` from the shared API module. `apiRequest` already does this. Specialized customer/form request errors also preserve string-valued field details, status and their existing recovery behavior.
- Wrap each editor with `withFormValidation`, and use `useFormValidation().capture(cause)` in failed-request handlers before storing its summary message. Call `clear()` at the start of a new attempt or when changing the edited record.
- Import `TextField`, `FormControlLabel` and error `Alert` from `shared/FormValidation`. Give each control a `name` matching the API field key. Reusable editor fields inherit their parent's scope; do not create an independent scope around an address or an individual question.
- Use `ValidationField` for composite controls. `AddressEntry` supports `namePrefix` for nested address keys. Intake-answer field names use `answers.<question-id>`; local typed-answer validation collects field messages before sending a request.
- APIs that do not identify a field cannot reliably be mapped to a particular input. Their message and reference remain in the visible, focused form summary. Unknown/new field keys also remain visible there rather than being silently dropped.

## Deployment

This change is frontend-only. Deploy a rebuilt portal; no SQL, private API or environment update is needed. It has not been uploaded to the hosted site during development. Hosted acceptance should retry the failing team-profile phone save, verify the inline message and automatic focus, then correct the value and save successfully.

## Local validation

Both frontend builds, TypeScript and English/French resource checks passed. The 162-test browser suite passed, including six new field-feedback regressions and the updated typed-answer test. Seven practitioner-invitation checks and 13 production-bundle smoke checks passed. After retaining the existing translated non-field summaries, the six new feedback checks were repeated successfully against the final source. Coverage includes retrying the same failure, retaining edits, clearing individual errors, visual ordering, checkbox messages, unknown fields, drawer focus/cleanup and reduced-motion French mobile feedback. Browser requests use synthetic fixtures; these results do not establish hosted acceptance. No PHP source or database schema changed, so backend/SQL checks were not applicable to this change.
