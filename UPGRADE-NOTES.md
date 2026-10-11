# Stonefellow v1.3.30 Upgrade Notes

1. Upload/extract the v1.3.30 deploy over the existing Stonefellow installation.
2. Preserve production-owned `data/`, `storage/`, database configuration, secrets, store configuration and generated catalog/config JS.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.25** to **1.3.26**.
5. Migration **2026-10-11-027** creates:
   - `artist_home_sections`
   - `artist_home_events`
6. Migration 027 seeds the default Stonefellow single-artist homepage section layout.
7. The new Artist Homepage plugin defaults **OFF**. Existing public Home behavior therefore remains unchanged after upgrade until an Administrator enables it.
8. The existing Splash toggle remains independent. You may use:
   - Splash ON + Homepage ON
   - Splash OFF + Homepage ON
   - Homepage OFF to retain the existing Agent/Personalized Home
9. Configure the homepage in Admin → Settings → **Artist Homepage**.
10. Before enabling it, review the readiness score and explicitly publish the desired site hero, release cover, featured-track primary audio, show poster and other public Media Library assets.
11. Public featured-track playback requires an explicitly Public `primary_audio` Media Library relationship; private/legacy catalog audio is not used as a fallback.
12. Fan Community remains controlled by its existing independent on/off toggle; CRM/newsletter behavior is unchanged.

Application: **1.3.30**  
Database schema target: **1.3.26**
