# Umami Analytics for Grav

[![Grav plugin](https://img.shields.io/badge/Grav-plugin-4B7BEC?logo=grav&logoColor=white)](https://getgrav.org/)
[![Latest release](https://img.shields.io/github/v/release/Crabston/grav-plugin-umami-analytics?sort=semver)](https://github.com/Crabston/grav-plugin-umami-analytics/releases/latest)
[![License](https://img.shields.io/github/license/Crabston/grav-plugin-umami-analytics)](LICENSE)

This Grav plugin adds [Umami](https://umami.is/) tracking to a site. It can serve the tracker through a same-origin proxy and, with Grav Admin2, display an analytics dashboard inside the admin panel.

## Features

- Adds Umami's tracker to the site, with optional domain filtering, Do Not Track support, performance metrics, and session recording.
- Optionally proxies the tracker script and its supported event requests through the Grav site. This can help when browser extensions block third-party analytics requests.
- Adds an optional Admin2 dashboard with summary statistics, pageviews over time, and top pages, referrers, countries, browsers, operating systems, and devices.
- Keeps the Umami API token on the server; it is not sent to the browser.

## Requirements

- Grav **2.0.0 or later**.
- An Umami website and its website ID.
- For the Admin2 dashboard only: the Grav API and Admin2 plugins, plus an Umami API key with read access to that website.

## Installation

### GPM

From the root of your Grav installation, run:

```sh
bin/gpm install umami-analytics
```

### Admin

In Grav Admin, open **Plugins**, select **Add**, and install **Umami Analytics**.

### Manual

Download or clone this repository into `user/plugins/umami-analytics`. The plugin files should be in that directory (not in an additional nested directory).

## Configuration

Copy the default configuration to Grav's user configuration directory, then edit the copy:

```sh
cp user/plugins/umami-analytics/umami-analytics.yaml \
   user/config/plugins/umami-analytics.yaml
```

Alternatively, configure the plugin in Grav Admin under **Plugins → Umami Analytics**. Grav saves the settings to `user/config/plugins/umami-analytics.yaml`.

At minimum, set `website_id` to the website ID from Umami. `script_src` is the Umami server's base URL; use the URL shown for your website in Umami. The default is `https://us.umami.is`. Ensure the plugin is enabled in Grav Admin or in its YAML configuration.

```yaml
enabled: true
script_src: 'https://us.umami.is'
website_id: '00000000-0000-0000-0000-000000000000'
api_base_url: null
api_token: null
disable_auto_track: false
domains: null
enable_localhost: false
doNotTrack: true
performance: false
recorder: false
proxy:
  enable: true
  script_path: stats.js
  api_endpoint: /stats-api/
  cache_ttl: 3600
```

### Configuration reference

| Setting | Default | Description |
|---|---|---|
| `enabled` | `true` | Enables the plugin. |
| `script_src` | `https://us.umami.is` | Umami server base URL, without a trailing slash. Required. |
| `website_id` | `null` | Website ID from the Umami dashboard. Required. |
| `api_base_url` | `null` | Optional API root used by the Admin2 dashboard. For Umami Cloud, use `https://api.umami.is/v1`. When blank, the plugin uses `<script_src>/api`, suitable for self-hosted Umami. |
| `api_token` | `null` | Read-only Umami API key used by the Admin2 dashboard. Keep it private. |
| `disable_auto_track` | `false` | Disable Umami's automatic pageview and event tracking if you intend to use Umami's tracker functions to send events yourself. |
| `domains` | `null` | Optional comma-separated list of domains on which Umami should track. |
| `enable_localhost` | `false` | Allow tracking on `localhost` and `127.0.0.1`, useful for development. |
| `doNotTrack` | `true` | Pass the browser's Do Not Track signal to Umami so tracking is suppressed when the visitor enables it. |
| `performance` | `false` | Enable Umami performance metrics. |
| `recorder` | `false` | Load Umami's separate `recorder.js` script. Session recording must also be enabled for the website in Umami. |
| `proxy.enable` | `true` | Serve the tracker and forward supported tracking requests through this Grav site. |
| `proxy.script_path` | `stats.js` | Local tracker URL. Must be a relative `.js` path using letters, numbers, underscores, and hyphens. |
| `proxy.api_endpoint` | `/stats-api/` | Root-relative local endpoint for proxied tracker requests. Must use letters, numbers, underscores, and hyphens. |
| `proxy.cache_ttl` | `3600` | Number of seconds to cache the upstream tracker scripts on the server. Set to `0` to disable this cache. |

If the proxy is enabled, the tracker is served from `<your-site>/<proxy.script_path>` and its requests use `<your-site><proxy.api_endpoint>`. With the defaults, these are `/stats.js` and `/stats-api/`. If `recorder` is enabled, the recorder script is served from `stats-recorder.js` (or the configured script path with `-recorder.js` in place of `.js`). Change the paths if they conflict with routes on your site.

Set `proxy.enable: false` to load the tracker directly from `<script_src>/script.js`. This disables the same-origin tracking proxy; the Admin2 dashboard still uses the server-side Umami API.

## Admin2 dashboard

The dashboard is optional and does not need to be configured to use site tracking. To enable it:

1. Install and enable Grav's **API** and **Admin2** plugins.
2. Set `website_id` to the Umami website ID.
3. Create a read-only Umami API key with access to that website and set it as `api_token`.
4. Set `api_base_url` if the default `<script_src>/api` is not your Umami API root. For Umami Cloud, use `https://api.umami.is/v1`.
5. In Admin2, open **Umami Analytics**. Select a 7-, 30-, or 90-day range.

The dashboard fetches summary statistics, daily pageviews, and up to ten rows for each top metric. Its responses are cached for 60 seconds by default. To change the dashboard cache lifetime, set `dashboard_cache_ttl` in the plugin configuration (seconds; use `0` to disable caching).

If the dashboard says it is not configured, check that both `website_id` and `api_token` are set. If requests fail, verify the API URL, token permissions, and website ID, and check Grav's server logs.

## Keeping credentials private

Do not put `api_token` in a public repository or browser-side configuration. Store it in `user/config/plugins/umami-analytics.yaml` with appropriate file permissions, or provide it through Grav's environment configuration. For example, add these values to Grav's `.env` file or export them in the PHP process environment:

```sh
GRAV_CONFIG=1
GRAV_CONFIG_ALIAS__DASH=-
GRAV_CONFIG__plugins__umami_DASH_analytics__website_id=00000000-0000-0000-0000-000000000000
GRAV_CONFIG__plugins__umami_DASH_analytics__api_token=your-read-only-api-key
```

The `DASH` alias maps the hyphen in `umami-analytics`; `__` separates configuration path components. Environment overrides take precedence over values in the plugin YAML. Grav loads `.env` by default. To load another environment file, set the server-side `GRAV_ENV_PATH` variable before PHP starts; keep that file outside the public web root.

## Visitor IP and reverse proxies

The tracking proxy forwards the visitor IP to Umami using Grav's `Uri::ip()` resolution and replaces any incoming `X-Forwarded-For` header. If Grav is behind a reverse proxy, trust forwarded IPs only when that proxy securely sets or overwrites `X-Forwarded-For`; configure `system.http_x_forwarded.ip` accordingly. `system.reverse_proxy_setup` affects URL generation but does not enable forwarded-IP trust.

## License

This plugin is distributed under the [GNU General Public License v3.0](LICENSE).
