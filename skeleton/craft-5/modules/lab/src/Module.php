<?php
/**
 * Spark Craft Lab module
 *
 * Provides console tooling for seeded lab instances and serves the plugin
 * under test's optional lab/test.twig at /lab-test.
 *
 * @link https://github.com/jalendport/spark-craft-lab
 * @copyright Copyright (c) 2026 Jalen Davenport
 */

namespace sparkcraftlab\lab;

use Craft;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;
use yii\base\Module as BaseModule;

/**
 * Bootstraps the lab module.
 *
 * @author Jalen Davenport
 * @since 1.0.0
 */
class Module extends BaseModule
{
	/**
	 * Initializes the module.
	 *
	 * @return void
	 * @author Jalen Davenport
	 * @since 1.0.0
	 */
	public function init(): void
	{
		parent::init();

		// Register the alias explicitly so Craft's console can resolve the
		// module's controller path even while listing unknown commands.
		Craft::setAlias('@sparkcraftlab/lab', __DIR__);

		if (Craft::$app->getRequest()->getIsConsoleRequest()) {
			$this->controllerNamespace = 'sparkcraftlab\\lab\\console\\controllers';
		} else {
			$this->registerTestPageRoutes();
		}
	}

	/**
	 * Describes the plugin working copy mounted into the instance.
	 *
	 * The lab mounts the plugin under test at LAB_PLUGIN_DIR (default /plugin).
	 * Discovery is by convention: the composer.json's extra.handle plus a lab/
	 * directory holding the optional test.twig / seed.php material.
	 *
	 * @return array{handle:string, dir:string, labDir:string}|null plugin descriptor, or null
	 * @author Jalen Davenport
	 * @since 1.0.0
	 */
	public function labPlugin(): ?array
	{
		$dir = getenv('LAB_PLUGIN_DIR') ?: '/plugin';
		$composerPath = $dir . '/composer.json';
		if (!is_file($composerPath)) {
			return null;
		}

		$data = json_decode((string)file_get_contents($composerPath), true);
		$handle = is_array($data) ? ($data['extra']['handle'] ?? null) : null;
		if (!is_string($handle) || $handle === '') {
			return null;
		}

		return [
			'handle' => $handle,
			'dir' => $dir,
			'labDir' => $dir . '/lab',
		];
	}

	/**
	 * Requires the plugin's lab/seed.php hook and returns its closure.
	 *
	 * @return callable|null the seed closure, or null when the plugin ships none
	 * @author Jalen Davenport
	 * @since 1.0.0
	 */
	public function labSeedHook(): ?callable
	{
		$plugin = $this->labPlugin();
		if ($plugin === null) {
			return null;
		}

		$file = $plugin['labDir'] . '/seed.php';
		if (!is_file($file)) {
			return null;
		}

		$hook = require $file;

		return is_callable($hook) ? $hook : null;
	}

	/**
	 * Serves the plugin's lab/test.twig at /lab-test.
	 *
	 * Registers a site template root pointing at the plugin's lab/ directory
	 * plus a URL rule routing /lab-test to its test template.
	 *
	 * @return void
	 * @author Jalen Davenport
	 * @since 1.0.0
	 */
	private function registerTestPageRoutes(): void
	{
		$plugin = $this->labPlugin();
		if ($plugin === null || !is_file($plugin['labDir'] . '/test.twig')) {
			return;
		}

		$labDir = $plugin['labDir'];

		Event::on(
			View::class,
			View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
			static function (RegisterTemplateRootsEvent $event) use ($labDir): void {
				$event->roots['_lab_test'] = $labDir;
			},
		);

		Event::on(
			UrlManager::class,
			UrlManager::EVENT_REGISTER_SITE_URL_RULES,
			static function (RegisterUrlRulesEvent $event): void {
				$event->rules['lab-test'] = ['template' => '_lab_test/test'];
			},
		);
	}
}
