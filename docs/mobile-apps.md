# Members on phones: existing apps work, no custom app needed

Because this project is built on open code (Friendica core plus our own
addon), members can use existing, free phone apps to connect to your instance.
Nobody has to build, publish, or maintain a "Social Spot app" — that entire
cost category does not exist.

## What connects, and how

Friendica speaks the Mastodon client API alongside its own, so standard
fediverse apps log straight into your instance with the site address and the
member's normal account. Upstream keeps the compatibility lists:

- Friendica-native clients: **Raccoon for Friendica**, **Friendiqa**,
  **DiCa**, **Fedilab** —
  <https://friendi.ca/resources/mobile-clients/>
- Mastodon-API clients that work against Friendica: **Tusky**, **Husky**,
  **Subway Tooter**, **AndStatus**, among others (same page; API reference at
  <https://wiki.friendi.ca/docs/api-mastodon>).

Setup for a member is: install the app, enter the site address as the server,
sign in. That is the whole integration, and it costs us nothing per member.

## The boundary that must stay clear: apps cover the social side only

Those apps talk to Friendica core — feed, posts, notifications. They do **not**
show anything our addon adds:

| Works in the apps | Browser only (the site itself) |
|---|---|
| Social feed, posting, notifications | Dues and payment standing |
| | Member directory |
| | Document library |
| | Announcements |
| | Officer console |
| | Sign-up with the address/membership credential and officer approval |

So the rule for members is simple: **the browser is for organization business;
an app is optional, for the social feed.** Signing up must always happen in
the browser first — the credential field and officer approval exist only
there — and only after approval can the same account sign into an app.

This split is deliberate, not a gap to apologize for: the instance is
configured dashboard-first with the federated navigation hidden, because
residents come for minutes and dues, not a timeline. Say so plainly when a
member asks "is there an app?": "Yes for the feed — Tusky or Friendiqa, free —
but dues, documents, and the directory live on the site itself, which works
fine in your phone's browser."
