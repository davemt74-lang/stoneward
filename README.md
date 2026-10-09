# Stonefellow v1.3.16 — Fan CRM, Community & Agent Engagement

Stonefellow is still a **single-artist platform**. Section 17 adds a relationship layer around that artist: fans, newsletter contacts, customers, community participation, and governed Agent engagement.

## Fan CRM

The CRM does not copy the existing account, commerce, listening, library, notification, or Agent systems. Those remain authoritative. A fan contact links them into one relationship profile and timeline.

A contact can originate from:

- a Stonefellow account
- newsletter signup
- a purchase, including guest checkout
- existing user activity

Account and newsletter identities merge by verified email/user linkage rather than creating parallel people.

CRM stages support lead, fan, customer, member, and inactive.

## Newsletter

Newsletter signup is available to guests and signed-in fans.

The signup:

- creates or updates the CRM contact
- records explicit marketing opt-in
- records consent timestamps
- creates an unsubscribe token
- sends a welcome/unsubscribe message through the existing email lifecycle
- never grants marketing consent from an Admin edit

Newsletter consent and proactive in-app Agent interaction are separate settings.

## Fan Community

The public Fan Community is controlled by **Admin → Settings → Enable public Fan Community feed and posting** and defaults **OFF**. Turning it off hides public entry points and blocks feed/posting APIs without disabling CRM, newsletter, purchases, listening signals, or Agent CRM context.

When enabled, signed-in fans can post to a first-class Stonefellow community feed. Community activity is linked to their account and CRM profile.

Community posts are:

- authenticated and CSRF-protected
- length bounded
- lightly rate-limited
- limited against link spam
- owner-deletable
- Admin-moderatable

## Governed automatic Agent engagement

The Stonefellow Agent can proactively interact with a signed-in fan after meaningful events such as:

- creating an account
- contributing to the community
- making a purchase
- working with a playlist
- completing a recording

Automatic interaction is:

- in-app only
- independently opt-out
- cooldown-limited
- deduplicated
- driven by recent canonical activity
- recorded in the CRM timeline
- recorded in Admin Agent Brain

There is no generic daily/welcome nag when no meaningful trigger exists.

## Admin Fans + CRM

Admin now includes **Fans + CRM** for:

- CRM totals and stages
- account-linked and guest contacts
- newsletter consent visibility
- fan tags and Admin notes
- proactive-Agent permission
- cross-system CRM timeline
- canonical account activity
- Agent Brain history
- recent automatic Agent outreach
- community moderation
- administrator-approved in-app Agent messages

Newsletter consent is visible but cannot be manufactured by Admin.

## Chat footer quick actions

The chat row now has a **+** button to the left of the text field.

Quick actions include:

- Create Record
- Create Playlist
- Tour Dates
- Merch Store
- Fan Community
- Newsletter

Every action opens a real existing or Section 17 workflow.

## Database

Section 17 adds migration **2026-10-09-014**.

Application: **1.3.16**  
Database schema target: **1.3.13**
