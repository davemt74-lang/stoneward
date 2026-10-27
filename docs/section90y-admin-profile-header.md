# Section 90Y — Admin profile header (v1.3.158)

The Admin topbar groups its right-side actions into a notification bell and a compact profile-picture dropdown. The previously exposed name, View Site, and Log Out controls were consolidated; the mobile header shows only the bell and circular avatar.

The dropdown contains the signed-in name and email, profile-photo selection, My Account & Profile (existing public account settings), Admin Settings, Users & Access, Agent Brain & History, Operations & Health, View Public Site, and Log Out. Clicking outside the menu, pressing Escape, or selecting an Admin view closes it. Links keep their established routes.

Profile pictures are private per Administrator and served only from `admin/api/profile-photo.php` after authenticated authorization. Uploads require CSRF, valid JPEG/PNG/WebP content, a 2 MB size limit, and image dimension constraints. Storage remains under the existing web-denied `storage/` directory. A generated initials avatar is shown before any photo is uploaded.

Run `node tests/section90y-admin-profile-menu.cjs` for responsive markup, navigation, keyboard dismissal, upload, and access-control regression contracts. The PHP endpoint is syntax-checked during PR certification; the live Admin photo flow still requires host acceptance testing after deployment.
