# Stonefellow Team Workspace — v1.3.110
Built on Stonefellow's existing account authentication, not a parallel team login.

## Team roles
An account can have many active, independently revocable roles: Band Member, Manager, Website Manager, Road Crew, Sound Crew, Lighting Crew, Photographer, Videographer, Producer, Tour Manager, Merch Manager and Publicist. Permissions are the union of current assignments. These never modify users.role, grant Admin, billing, secret storage, marketing sends or payment powers. Admin-only role assignments are explicitly confirmed and recorded.

## Timeline
Team actions have actor ID, UTC timestamp, event type, entity and a redacted operational description. Administrators additionally see existing Admin audit records and site events in a unified chronologically ordered and paginated view. Website Managers and Managers can see team actions; other team roles see only their own actions.

The existing original song-source upload and catalog metadata-edit flows now record who acted. Existing Admin Media Library already logs media uploads and publishing. Private listener history, fan records and confidential audit payloads are not exposed to team users. This timeline is comprehensive for integrated operational audit sources, not a claim that every historical business event has yet been instrumented. Later builds will add publishing, tour, credits, service access, transcription and AI analysis timestamps.

## Team portal
Signed-in people with team roles see the Team Workspace menu. The Team portal shows permissions, role labels, permitted roster views and activity. Scoped media.upload allows upload to the EXISTING Media Library for review, with CSRF protection; it does not publish tracks or grant rights. Admin uses Team Workspace to select an existing registered account and assign multiple roles.

## Next sections
- 88C3: Band-member biography, approved likeness gallery, photo consent and member-specific knowledge.
- 88C4: Media Intelligence metadata ingestion, transcripts with timecodes, photo descriptions, repeated motifs and review/provenance.
- 88C5: Governed third-party/service connections, scoped to roles, credential permissions and expiry/revocation.
- 88C6: Expand actor-stamped timeline coverage and filterable entity-linked event views.

## Live acceptance
HostGator deployment, two different real user logins, actual upload attribution, immediate role revocation and mobile/team screen layout still require field testing. No HomeServer changes.
