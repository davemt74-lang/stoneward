# Stonefellow development guidance

Build reusable processes, tools, and skills that the Stonefellow agent can combine when a user asks it to build something. The agent interprets the user's prompt, chooses the capabilities it needs, and generates the requested experience.

Treat examples as use cases for validating a general capability. A star cluster showing listening activity is one way to use canvas data access. The same capability should support any presentation the prompt calls for. Add dedicated templates, fixed intent routes, or default outputs only when the user explicitly requests them.

Reuse existing application tools and data sources through stable interfaces. Enforce access in the host, with published artist data available to visitors and personal data scoped to the signed-in account. Preserve established callers and verify that the new capability works through the normal user flow.
