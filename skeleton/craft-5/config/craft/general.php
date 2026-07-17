<?php
/**
 * General Configuration
 *
 * @see \craft\config\GeneralConfig
 */

use craft\config\GeneralConfig;
use craft\helpers\App;

return GeneralConfig::create()
	// Prevent generated URLs from including "index.php"
	->omitScriptNameInUrls()
	// Enable Dev Mode (see https://craftcms.com/guides/what-dev-mode-does)
	->devMode(App::env('DEV_MODE') ?? false)
	// Preload Single entries as Twig variables
	->preloadSingles()
	// Allow administrative changes
	->allowAdminChanges(App::env('ALLOW_ADMIN_CHANGES') ?? false)
	// Disallow robots
	->disallowRobots(App::env('DISALLOW_ROBOTS') ?? false)
	// Prevent user enumeration attacks
	->preventUserEnumeration()
	// Set the @webroot alias so the clear-caches command knows where to find CP resources
	->aliases([
		'@web' => App::env('APP_URL'),
		'@webroot' => CRAFT_BASE_PATH . '/web',
	])
;
