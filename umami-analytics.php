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
	 * @var string serverUrl
	 * @var string websiteId
	 * @var string hostUrl
	 * @var string autoTrack
	 * @var string domains
	 */
	protected $scriptSrc;
	protected $websiteId;
	protected $hostUrl;
	protected $autoTrack;
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
            'onPluginsInitialized' => [
                // Uncomment following line when plugin requires Grav < 1.7
                // ['autoload', 100000],
                ['onPluginsInitialized', 0]
            ]
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

	    $this->scriptSrc = trim($this->config->get('plugins.umami-analytics.script_src', 'https://us.umami.is'));
	    $this->websiteId = trim($this->config->get('plugins.umami-analytics.website_id', ''));
	    $this->hostUrl = trim($this->config->get('plugins.umami-analytics.host_url', ''));
	    $this->autoTrack = trim($this->config->get('plugins.umami-analytics.auto_track', ''));
	    $this->domains = trim($this->config->get('plugins.umami-analytics.domains', ''));

	    // Don't proceed if there is no website ID
	    if (empty($this->websiteId)) {
		    $this->grav['debugger']->addMessage('Umami Analytics Plugin: No website ID configured!', 'error');
		    return;
	    }

	    // Enable the main event we are interested in
	    $this->enable([
		    'onOutputGenerated' => ['onOutputGenerated', 0],
	    ]);
    }

	/**
	 * The output has been processed by the Twig templating engine and is now just a string of HTML.
	 */
	public function onOutputGenerated(): void {
		// Required parameters
		$srcParam = "src=\"{$this->scriptSrc}/script.js\"";
		$websiteIdParam = "data-website-id=\"{$this->websiteId}\"";

		// Optional parameters
		$hostUrlParam = $this->hostUrl ? "data-host-url=\"{$this->hostUrl}\"" : '';
		// TODO: fix autoTrackParam:
		// if autoTrack is empty in config file, it should not be added to the script tag, if it is true, it should be added with value true, if it is false, it should be added with value false
		$autoTrackPram = $this->autoTrack ? "data-auto-track=\"{$this->autoTrack}\"" : '';
		$domaisParam = $this->domains ? "data-domains=\"{$this->domains}\"" : '';

		$code = implode(PHP_EOL, [
			'<!-- Umami Analytics Script -->',
			"<script defer ${srcParam} ${websiteIdParam} ${hostUrlParam} ${autoTrackPram} ${domaisParam}></script>",
		]);

		$content = preg_replace('/<head\s?\S*?(>)/si', "$0\n\n{$code}\n", $this->grav->output);
		$this->grav->output = $content;
	}
}
