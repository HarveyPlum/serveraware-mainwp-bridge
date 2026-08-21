# ServerAware MainWP Bridge

This WordPress plugin runs on the existing MainWP Dashboard. It gives the ServerAware Mac app a narrow, authenticated path to:

- read the cache solution and last-purge value synced by MainWP Cache Control;
- ask one selected MainWP Child site to run its registered cache purge action.

It does not create a server, store an additional API secret, expose child-site credentials, or accept unauthenticated requests.

## Install

1. In the MainWP Dashboard WordPress site, open **Plugins → Add New Plugin → Upload Plugin**.
2. Upload `serveraware-mainwp-bridge.zip`, install it, and activate it.
3. Confirm that **MainWP Dashboard**, **MainWP Cache Control**, and the current **MainWP Child** plugin on each managed site are active.
4. In MainWP **API Access**, give the dedicated ServerAware API key **Read/Write** permission. Read-only keys can load metadata but cannot purge.
5. In ServerAware **Settings**, refresh MainWP. The status should say that the ServerAware bridge is available.

## Security model

- All routes are under MainWP REST API v2 and reuse MainWP's Bearer-token authentication.
- MainWP enforces read permission for metadata requests and write permission for purge requests.
- Each requested site is checked against the API key's WordPress user before data is returned or an action is run.
- Only numeric MainWP site IDs are accepted, with a maximum of 500 IDs per metadata request.
- No MainWP signing keys, child credentials, API tokens, or cache logs are returned.
- ServerAware keeps the MainWP API token in macOS Keychain and records successful or failed purge attempts in its local audit log.

## Endpoints

- `GET /wp-json/mainwp/v2/serveraware-bridge`
- `GET /wp-json/mainwp/v2/serveraware-bridge/cache?site_ids=1,2,3`
- `POST /wp-json/mainwp/v2/serveraware-bridge/cache/1/purge`

The plugin intentionally exposes no bulk purge endpoint.

## Release

Version 1.0.1 adds Harvey Plum GitHub update metadata and fails closed whenever MainWP permission validation does not explicitly approve a request.
