# Stonefellow v1.3.36 Upgrade Notes

1. Upload/extract the v1.3.36 deploy over the existing installation.
2. Preserve production-owned data, storage, database config, secrets, store config and generated catalog/config JS.
3. Run /upgrade.php.
4. Database target advances from **1.3.30** to **1.3.31**.
5. Migration **2026-10-11-032** creates:
   - professional_inquiries
   - professional_inquiry_messages
   - professional_inquiry_events
6. Public Contact is immediately available at /contact after deployment.
7. Professional inquiries do not create Fan CRM/newsletter contacts or marketing consent.
8. Configure/test the existing transactional email provider before sending real Admin replies.
9. Replies are marked Replied only after the outbox reports Sent; queued/logged/failed states remain truthful.
10. Identical in-flight/successful replies are suppressed for five minutes; failed replies may retry immediately.

Application: **1.3.36**  
Database schema target: **1.3.31**
