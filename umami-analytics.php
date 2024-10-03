<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;

/**
 * Class UmamiAnalyticsPlugin
 * @package Grav\Plugin
 */
class UmamiAnalyticsPlugin extends Plugin
{

	/**
	 * @var string scriptSrc
	 * @var string websiteId
	 * @var string hostUrl
	 * @var string disableAutoTrack
	 * @var string domains
	 */
	protected $scriptSrc;
	protected $websiteId;
	protected $hostUrl;
	protected $disableAutoTrack;
	protected $domains;

	/**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents(): array {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    /**
     * Composer autoload
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized(): void {
	    // Don't proceed if we are in the admin plugin
	    if ($this->isAdmin()) {
		    return;
	    }

	    // Don't proceed if we are in the localhost
	    $uri = $this->grav['uri']->host();
	    $enableLocalhost = $this->config->get('plugins.umami-analytics.enable_localhost', false);
	    if (($uri === 'localhost' || $uri === '127.0.0.1') && !$enableLocalhost) {
		    $this->grav['debugger']->addMessage("Umami Analytics Plugin: Ignoring localhost!");
		    return;
	    }

		// Load the plugin configuration
	    $this->scriptSrc = trim($this->config->get('plugins.umami-analytics.script_src', 'https://us.umami.is'));
	    $this->websiteId = trim($this->config->get('plugins.umami-analytics.website_id', ''));
	    $this->hostUrl = trim($this->config->get('plugins.umami-analytics.host_url', ''));
	    $this->disableAutoTrack = trim($this->config->get('plugins.umami-analytics.disable_auto_track', ''));
	    $this->domains = trim($this->config->get('plugins.umami-analytics.domains', ''));

	    // Don't proceed if there is no website ID
	    if (empty($this->websiteId)) {
		    $this->grav['debugger']->addMessage('Umami Analytics Plugin: No website ID configured!', 'error');
		    return;
	    }

	    // Enable the main event we are interested in
	    $this->enable([
		    'onAssetsInitialized' => ['onAssetsInitialized', 0],
	    ]);
    }

	/**
	 * Add the plugin assets
	 */
	public function onAssetsInitialized(): void {
		$this->grav['assets']->addJs("$this->scriptSrc/script.js", [
			'loading' => 'defer',
			'data-website-id' => $this->websiteId,
			'data-host-url' => $this->hostUrl,
			'data-auto-track' => $this->disableAutoTrack,
			'data-domains' => $this->domains,
		]);
	}
}
