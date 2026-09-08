# Wellness Centre Documentation

This folder contains the living technical and delivery documentation for the Wellness Centre scheduling platform.

Start with [Solution Build Plan](SOLUTION_BUILD_PLAN.md). It is the authoritative implementation roadmap and records the product scope, architecture, security requirements, system components, delivery phases, and acceptance criteria.

The original product requirements remain in `wellness-centre-app-requirements.docx` at the repository root. The solution plan translates those requirements into buildable pieces and tracks the current implementation state.

## Document maintenance

- Update the solution plan whenever a requirement or architectural decision changes.
- Mark work complete only after its acceptance criteria and tests pass.
- Never place credentials, tenant secrets, database passwords, client records, or production configuration values in documentation.
- Keep implementation-specific setup instructions beside the relevant component, such as `api/README.md`, while keeping cross-solution decisions here.
