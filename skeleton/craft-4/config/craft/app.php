<?php
/**
 * Yii Application Config
 *
 * Read more about application configuration:
 * https://craftcms.com/docs/5.x/reference/config/app.html
 */

use craft\helpers\App;

return [
	'id' => App::env('CRAFT_APP_ID') ?: 'spark-craft-lab',
	'bootstrap' => ['lab'],
	'modules' => [
		'lab' => sparkcraftlab\lab\Module::class,
	],
	'components' => [
		'cache' => function() {
			$config = [
				'class' => yii\redis\Cache::class,
				'keyPrefix' => Craft::$app->id,
				'defaultDuration' => Craft::$app->config->general->cacheDuration,
				'redis' => [
					'class' => yii\redis\Connection::class,
					'hostname' => App::env('REDIS_HOSTNAME') ?: 'localhost',
					'port' => App::env('REDIS_PORT') ?: 6379,
					'password' => App::env('REDIS_PASSWORD') ?: null,
				],
			];

			return Craft::createObject($config);
		},
		'deprecator' => [
			'throwExceptions' => App::env('DEV_MODE') ?? false,
		],
		'mailer' => function() {
			$config = App::mailerConfig();

			// Capture all outgoing mail with Mailpit
			$adapter = craft\helpers\MailerHelper::createTransportAdapter(
				craft\mail\transportadapters\Smtp::class,
				[
					'host' => App::env('MAILPIT_HOST') ?: 'mailpit',
					'port' => App::env('MAILPIT_SMTP_PORT') ?: 1025,
				]
			);
			$config['transport'] = $adapter->defineTransport();

			return Craft::createObject($config);
		},
	],
];
