# Wellnexa brand palette

The Wellnexa website stylesheets define Turquoise `#3CC7C4`, Violet `#7A4DFF`, Deep Teal `#0F4C5C`, Soft Gold `#F4D57E`, Mint Glow `#A8F2D6`, Cloud White `#F8F9FA` and Graphite `#2E2E2E` as named CSS variables.

Cloud White provides the page background; Graphite provides body text. Deep Teal provides readable links, headings and supporting text. Turquoise highlights buttons, borders and the brand mark with dark text. Violet accents the hero and actionable card links. Mint and gold provide gentle supporting surfaces and accents. Focus indicators use violet.

The clinic portal has a matching **Use Wellnexa palette** preset under **Business settings → Portal appearance**. Select it, then **Save portal appearance** in the Wellnexa clinic portal. The existing clinic-scoped save API persists primary Deep Teal `#0F4C5C`, secondary Violet `#7A4DFF` and Inter. The frontend recognizes that pair and applies the full seven-colour theme to guest, client and staff portal pages. Deep Teal is the portal's primary action/text colour so small text on Cloud White remains readable; turquoise is its primary highlight colour. Mint accents chips and information panels, gold accents warning panels, and turquoise accents active tabs.

Other saved colour combinations retain their existing custom theme. No new database migration is required. Saving the preset is an authenticated clinic administrator action; local development does not alter the hosted clinic's settings. Deploy the updated Wellnexa website and clinic portal bundles before selecting the preset on the hosted portal. The existing API can persist it without an API update.
