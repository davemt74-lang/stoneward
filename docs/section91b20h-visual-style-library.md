# Stonefellow 91B20H — Visual Style Library (v1.3.234)

## Scope
This section extends the existing Image Studio presets (subject/output format) with a distinct, reusable *visual identity* layer (mood, palette, atmosphere and artistic direction). Its purpose is to make separate images feel like one cohesive artist world.

- Four built-in identities: Nebula Noir, Desert Afterglow, Analog Dream and Ocean Signal.
- Admin may create named custom identities with mood, detailed art direction, and three validated six-digit palette colors.
- An optional eligible Media Library image can appear as a private inspiration thumbnail on the style card. The image's bytes are **never** sent to the generation provider merely by selecting a style.
- Admin can archive only custom styles, with explicit confirmation. Old prompts and artwork are retained.
- The selected style is combined with the existing preset and scene/concept in a locally generated, editable prompt. No provider request or charge occurs.
- The Stonefellow Agent *text-only* prompt composer can receive the style's mood, colors and written guidance **only after a separate confirmation** and may incur text-token charges.
- An image-generation job remains a separately saved draft, followed by the existing prompt approval, paid-generation authorization, generated-image review, and 91B20G public Apply authorization.
- All style create/archive actions use the existing Admin-authenticated CSRF-protected API and Admin audit logger.

## Why a separate table?
Existing `image_studio_presets` represent output format (cover, site hero, merch, portrait). The new `image_studio_styles` represent reusable artistic identity across multiple formats. They are independently selectable and seeded using `CREATE TABLE IF NOT EXISTS` in SQLite/MySQL; no manual HostGator upgrade script or data reset is required.

## Permissions and boundaries
- No UI/public route exposes styles to unauthenticated visitors.
- All custom text and palette hex values are validated and displayed with HTML escaping.
- Only existing image references eligible for the established Image Studio reference list can become inspiration thumbnails.
- Thumbnail use is a local preview, not provider/likeness/rights consent. To send it as an image-edit reference, the Admin must separately choose it in the reference selector and check explicit permission.
- Selecting a style cannot publish an image, mutate a song, or run the paid Image API.
- Archived styles cannot be selected for **new** prompts. Existing saved prompts remain immutable.

## Validation
Run `node --test tests/section91b20h-visual-style-library.test.cjs`, `node --check admin/assets/image-generation.js`, and the repo's permanent Release Gate. Merge only after all PR workflows green. GitHub Releases then builds the single direct deployment ZIP and SHA-256 checksum on main.

## Suggested manual QA
1. Open Admin > AI Image Studio on desktop and mobile; create one identity with three colors and no thumbnail.
2. Choose the identity for an Album Cover preset, apply locally, and confirm the palette and mood appear in the editable draft with no paid request.
3. Save a second identity with an approved Media Library image and confirm only the private preview is shown; the reference image selector remains unchanged.
4. Use the text composer only after explicit permission. Verify a text API usage entry but no image generation.
5. Archive a custom identity and confirm built-ins remain usable and existing artwork remains unchanged.
6. Verify generation and public Apply remain separate approval steps.
