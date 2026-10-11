# Stonefellow v1.3.32 Upgrade Notes

1. Upload/extract the v1.3.32 deploy over the existing Stonefellow installation.
2. Preserve production-owned `data/`, `storage/`, database configuration, secrets, store configuration and generated catalog/config JS.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.27** to **1.3.28**.
5. Migration **2026-10-11-029** creates:
   - `story_entries`
   - `story_events`
6. Existing Catalog, Releases, Shows, Homepage, Media Library and EPK content are not copied or replaced.
7. Storytelling starts with no published entries. Existing public About fallback behavior remains until a governed Artist About is published.
8. Configure Storytelling under **Admin → Settings → Artist Story, Editorial & Timeline**.
9. Publish one Artist About to make it the canonical source for:
   - Public About
   - Homepage Artist Story
   - route-specific About SEO
   - grounded public Agent storytelling context
10. Release/Track/Show stories are supplemental editorial linked to existing public entities; their canonical metadata remains owned by Releases, Catalog and Shows.
11. Attach story imagery through Media Library and explicitly publish the desired Hero relationship.
12. Story events are bounded to real public views, connected-content opens, media opens and completed shares.

Application: **1.3.32**  
Database schema target: **1.3.28**
