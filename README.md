# Umami Analytics Plugin

The **Umami Analytics** Plugin is an extension for [Grav CMS](https://github.com/getgrav/grav) for adding [Umami Analytics](https://umami.is/) to your site

## Installation

Installing the Umami Analytics plugin can be done in one of three ways: The GPM (Grav Package Manager) installation method lets you quickly install the plugin with a simple terminal command, the manual method lets you do so via a zip file, and the admin method lets you do so via the Admin Plugin.

### GPM Installation (Preferred)

To install the plugin via the [GPM](https://learn.getgrav.org/cli-console/grav-cli-gpm), through your system's terminal (also called the command line), navigate to the root of your Grav-installation, and enter:

    bin/gpm install umami-analytics

This will install the Umami Analytics plugin into your `/user/plugins`-directory within Grav. Its files can be found under `/your/site/grav/user/plugins/umami-analytics`.

### Manual Installation

To install the plugin manually, download the zip-version of this repository and unzip it under `/your/site/grav/user/plugins`. Then rename the folder to `umami-analytics`. You can find these files on [GitHub](https://github.com/crabston/grav-plugin-umami-analytics) or via [GetGrav.org](https://getgrav.org/downloads/plugins).

You should now have all the plugin files under

    /your/site/grav/user/plugins/umami-analytics
	
### Admin Plugin

If you use the Admin Plugin, you can install the plugin directly by browsing the `Plugins`-menu and clicking on the `Add` button.

## Configuration

Before configuring this plugin, you should copy the `user/plugins/umami-analytics/umami-analytics.yaml` to `user/config/plugins/umami-analytics.yaml` and only edit that copy.

Here is the default configuration and an explanation of available options:

```yaml
enabled: true                      # Enable or disable the plugin
script_src: 'https://us.umami.is'  # Source URL of the Umami script
website_id: null                   # ID of the website
disable_auto_track: false          # Disable auto tracking
domains: null                      # Domains to track
enable_localhost: false            # Enable tracking on localhost
doNotTrack: true                   # Honor the browser Do Not Track signal
performance: false                 # Collect page performance metrics
recorder: false                    # Proxy recorder.js for session recording
proxy:
  enable: true                     # Enable the proxy
  script_path: stats.js            # Path to the tracker script 
  api_endpoint: /stats-api/        # API endpoint for proxy requests 
  cache_ttl: 3600                  # Cache lifetime for proxied scripts in seconds 
```

**The fields `script_src` and `website_id` are required.**

Note that if you use the Admin Plugin, a file with your configuration named umami-analytics.yaml will be saved in the `user/config/plugins/`-folder once the configuration is saved in the Admin.

## Usage

The plugin will inject the Umami Analytics script into your site's `<head>` tag. You can verify this by inspecting the source code of your site.

After you've added your website to Umami, set `script_src` and `website_id` in the plugin configuration. You can find these values in the Umami dashboard.

- `script_src` is the URL of your Umami server, e.g. `https://us.umami.is`.
- `website_id` is the ID of your website, e.g. `76e4f3db-8fec-47ad-82ba-f5O21cOac915`.

The proxy serves the tracker at `<site>/<proxy.script_path>` and, when `recorder` is enabled, serves `recorder.js` separately at `<site>/<proxy.script_path with "-recorder.js">`. Tracker and recorder requests are forwarded below `proxy.api_endpoint`; `/stats-api/send` is forwarded to Umami's `/api/send`. The defaults use `/stats.js` and `/stats-api/`; change them if either path conflicts with a route on your site. `proxy.cache_ttl` sets the server-side script cache lifetime in seconds (0 disables it).

`doNotTrack` maps to Umami's `data-do-not-track` setting; when enabled, Umami suppresses tracking for visitors whose browser signals Do Not Track. `performance` enables Umami performance metrics, and `recorder` loads the separate recording script. Session recording must also be enabled for the website in the Umami dashboard.
