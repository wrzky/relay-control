# Relay Control

Relay and known-remote fanout for federated WordPress sites running the ActivityPub plugin. Built for a small mutual aid publishing network and used in production.

## The problem

On a federated WordPress site, Delete activities reached hundreds of known servers, while new posts (Create, Update, Announce) reached only a handful. Nothing was broken. ActivityPub core gives Delete a much wider audience than everything else, so the posts that mattered most travelled the least.

## What it does

- Follows relays, records whether each one accepted, and sends through accepted relays only.
- Queues public Create, Update and Announce activities to the wider set of known remote servers, in small resumable batches, so a new post never freezes the site.
- Retries failed deliveries within limits and backs off from inboxes that keep failing.
- Recovers from missed cron runs and interrupted workers without forgetting what was already delivered.
- Writes one readable delivery log instead of several scattered ones.

## How it works

Relay fanout and known-remote fanout are two separate mechanisms, and they can run together.

- **Relay fanout** adds the inboxes of accepted relays to an outgoing public activity and relies on each relay to pass it on.
- **Known-remote fanout** sends directly to remote inboxes the site already knows about, minus the ones ActivityPub already selected and the relay inboxes.

## What I learned

- A logger can only record deliveries that actually happen. It cannot fix a delivery that never started.
- Relay fanout and known-remote fanout are different things. Keeping them apart made every later bug easier to find.
- When a post does not federate, check whether WordPress created an outbox row first. No row means the publish trigger is at fault. A row that never delivers points at dispatch.
- If WP-Cron is disabled, delivery depends on real server cron. This plugin can recover on ordinary requests, but check the schedule before blaming the plugin.

## Requirements

- WordPress with the [ActivityPub plugin](https://wordpress.org/plugins/activitypub/) active. Developed against 9.x. It uses the plugin's native follow and outbox functions and stays idle if they are missing.

## Install

1. Install and activate the ActivityPub plugin.
2. Copy `relay-control.php` into `wp-content/plugins/` and activate it.
3. Open **Tools → Relay Control** for relay status and manual actions.

## Configuration

Three optional settings, each a WordPress option. Set them with WP-CLI or any options tool.

| Option | What it does | Default |
| --- | --- | --- |
| `relay_control_primary_user_id` | The main site account that publishes and signs as the site. | The companion plugin's answer if present, otherwise user 5 |
| `relay_control_secondary_user_id` | An optional second local account. It signs its own activities and never subscribes to relays. | None. Secondary-account handling stays off |
| `relay_control_actor_user_id` | The account that follows relays. It can never be the secondary account. | The primary account |

For example, on a site where the main account is user 2 and a second account is user 7:

```
wp option update relay_control_primary_user_id 2
wp option update relay_control_secondary_user_id 7
```

## Optional integration

If another plugin defines these functions, the extra checks they provide switch on. Without them the plugin runs normally.

- `relay_control_bridge_ap_user_id` (the primary account, used only when the setting above is empty)
- `relay_control_bridge_posts_actor_id`
- `relay_control_bridge_db_circuit_open` (pauses recovery while the database is overloaded)
- `relay_control_bridge_outbox_id_matches`
- `relay_control_bridge_validate_saved_activity`
- `relay_control_secondary_actor_url` (the public actor URL of the secondary account)

## Delivery log

One line per delivery attempt, written to `wp-content/uploads/relay-control-delivery.log`. It contains real server addresses, so never publish it.

## Status and rough edges

Version 2.0.0 is a rename of version 1.9.0, which runs in production on a live federated site, with the two account numbers it used to assume turned into settings. The new account logic passes a set of unit checks and the whole file passes a syntax check, but 2.0.0 has not yet run on a live site.

Known rough edges:

- It handles at most two local accounts, a primary and an optional secondary. The primary defaults to user 5 until the setting is made.
- It has a small built-in blocklist: a relay that rejects every delivery by policy, and the inbox of one deactivated blog. Edit it for another site.
- It is tuned to one setup, so expect friction elsewhere.

## License

GPL-2.0-or-later.
