<?php
/**
 * Spark Craft Lab seed controller
 *
 * Creates repeatable content and users for plugin smoke tests.
 *
 * @link https://github.com/jalendport/spark-craft-lab
 * @copyright Copyright (c) 2026 Jalen Davenport
 */

namespace sparkcraftlab\lab\console\controllers;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\enums\Color;
use craft\enums\PropagationMethod;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\PlainText;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use Throwable;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Seeds the primary lab instance.
 *
 * @author Jalen Davenport
 * @since 1.0.0
 */
class SeedController extends Controller
{
	/**
	 * @var string The article section handle
	 * @since 1.0.0
	 */
	private const SECTION_HANDLE = 'labArticles';

	/**
	 * @var string The article body field handle
	 * @since 1.0.0
	 */
	private const BODY_FIELD_HANDLE = 'labBody';

	/**
	 * @var string The article summary field handle
	 * @since 1.0.0
	 */
	private const SUMMARY_FIELD_HANDLE = 'labSummary';

	/**
	 * Creates the lab schema and sample data.
	 *
	 * @return int console exit code
	 * @throws Throwable if Craft cannot save generated content
	 * @author Jalen Davenport
	 * @since 1.0.0
	 */
	public function actionIndex(): int
	{
		$this->ensureProEdition();

		$this->stdout("Ensuring lab schema\n");
		$this->ensureSchema();

		$this->stdout("Seeding entries\n");
		$this->seedEntries();

		$this->stdout("Seeding users\n");
		$this->seedUsers();

		$this->runPluginSeedHook();

		$this->stdout("Seed complete\n");

		return ExitCode::OK;
	}

	/**
	 * Ensures the instance runs the Pro edition.
	 *
	 * Craft installs default to the single-user Solo edition, which would reject
	 * the generic editor user and hide Pro-only features the lab exercises.
	 *
	 * @return void
	 * @author Jalen Davenport
	 * @since 1.0.0
	 */
	private function ensureProEdition(): void
	{
		if (Craft::$app->edition !== CmsEdition::Pro) {
			$this->stdout("Setting edition to Pro\n");
			Craft::$app->setEdition(CmsEdition::Pro);
		}
	}

	/**
	 * Runs the plugin under test's optional lab/seed.php closure.
	 *
	 * The generic content model exists by this point, so hooks can attach
	 * plugin-specific settings and content to it. Runs last and is best-effort:
	 * a plugin that ships no hook (or isn't installed) is simply skipped.
	 *
	 * @return void
	 * @author Jalen Davenport
	 * @since 1.0.0
	 */
	private function runPluginSeedHook(): void
	{
		$hook = $this->module->labSeedHook();
		if ($hook === null) {
			return;
		}

		$this->stdout("Running plugin seed hook\n");
		$hook($this);
	}

	/**
	 * Ensures the fields and section required by the smoke templates exist.
	 *
	 * @return void
	 * @throws Throwable if Craft cannot save fields or sections
	 * @author Jalen Davenport
	 * @since 1.0.0
	 */
	private function ensureSchema(): void
	{
		$summaryField = $this->ensurePlainTextField(
			self::SUMMARY_FIELD_HANDLE,
			'Lab Summary',
			'Short summary text for lab smoke tests.',
			false,
			2,
		);
		$bodyField = $this->ensurePlainTextField(
			self::BODY_FIELD_HANDLE,
			'Lab Body',
			'Long-form body text for lab smoke tests.',
			true,
			10,
		);

		$entries = Craft::$app->getEntries();
		if ($entries->getSectionByHandle(self::SECTION_HANDLE) !== null) {
			return;
		}

		$entryType = new EntryType([
			'name' => 'Lab Article',
			'handle' => 'labArticle',
			'icon' => 'newspaper',
			'color' => Color::Teal,
			'hasTitleField' => true,
			'showSlugField' => true,
			'showStatusField' => true,
		]);
		$entryType->setFieldLayout($this->createArticleFieldLayout($summaryField, $bodyField));

		if (!$entries->saveEntryType($entryType)) {
			$this->failModel('entry type', $entryType);
		}

		$primarySite = Craft::$app->getSites()->getPrimarySite();
		$section = new Section([
			'name' => 'Lab Articles',
			'handle' => self::SECTION_HANDLE,
			'type' => Section::TYPE_CHANNEL,
			'enableVersioning' => true,
			'maxAuthors' => 1,
			'propagationMethod' => PropagationMethod::All,
			'previewTargets' => [
				[
					'label' => 'Primary entry page',
					'urlFormat' => '{url}',
				],
			],
		]);
		$section->setSiteSettings([
			$primarySite->id => new Section_SiteSettings([
				'siteId' => $primarySite->id,
				'enabledByDefault' => true,
				'hasUrls' => true,
				'uriFormat' => 'lab/articles/{slug}',
				'template' => '_lab/article',
			]),
		]);
		$section->setEntryTypes([$entryType]);

		if (!$entries->saveSection($section)) {
			$this->failModel('section', $section);
		}
	}

	/**
	 * Ensures a Plain Text field exists.
	 *
	 * @param string $handle field handle
	 * @param string $name field name
	 * @param string $instructions field instructions
	 * @param bool $multiline whether the field should allow multiple lines
	 * @param int $initialRows initial row count
	 * @return PlainText saved field
	 * @throws Throwable if Craft cannot save the field
	 * @author Jalen Davenport
	 * @since 1.0.0
	 */
	private function ensurePlainTextField(
		string $handle,
		string $name,
		string $instructions,
		bool $multiline,
		int $initialRows,
	): PlainText {
		$fields = Craft::$app->getFields();
		$field = $fields->getFieldByHandle($handle);
		if ($field instanceof PlainText) {
			return $field;
		}

		$field = new PlainText([
			'name' => $name,
			'handle' => $handle,
			'instructions' => $instructions,
			'searchable' => true,
			'multiline' => $multiline,
			'initialRows' => $initialRows,
		]);

		if (!$fields->saveField($field)) {
			$this->failModel('field', $field);
		}

		return $field;
	}

	/**
	 * Creates the field layout for lab article entries.
	 *
	 * @param PlainText $summaryField summary field
	 * @param PlainText $bodyField body field
	 * @return FieldLayout field layout
	 * @author Jalen Davenport
	 * @since 1.0.0
	 */
	private function createArticleFieldLayout(PlainText $summaryField, PlainText $bodyField): FieldLayout
	{
		$fieldLayout = new FieldLayout([
			'type' => Entry::class,
		]);

		// The tab must be linked to its layout before elements are assigned:
		// FieldLayoutTab::setElements() resolves the owning layout, so building
		// the tab with 'elements' in the constructor throws on Craft 5.
		$tab = new FieldLayoutTab([
			'name' => 'Content',
			'layout' => $fieldLayout,
		]);
		$tab->setElements([
			new EntryTitleField([
				'required' => true,
			]),
			new CustomField($summaryField),
			new CustomField($bodyField),
		]);
		$fieldLayout->setTabs([$tab]);

		return $fieldLayout;
	}

	/**
	 * Creates repeatable sample entries.
	 *
	 * @return void
	 * @throws Throwable if Craft cannot save entries
	 * @author Jalen Davenport
	 * @since 1.0.0
	 */
	private function seedEntries(): void
	{
		$section = Craft::$app->getEntries()->getSectionByHandle(self::SECTION_HANDLE);
		$entryType = Craft::$app->getEntries()->getEntryTypeByHandle('labArticle');
		$site = Craft::$app->getSites()->getPrimarySite();

		foreach ($this->articleSeeds() as $seed) {
			$entry = Entry::find()
				->section(self::SECTION_HANDLE)
				->siteId($site->id)
				->slug($seed['slug'])
				->status(null)
				->one() ?? new Entry();

			$entry->sectionId = $section->id;
			$entry->typeId = $entryType->id;
			$entry->siteId = $site->id;
			$entry->enabled = true;
			$entry->title = $seed['title'];
			$entry->slug = $seed['slug'];
			$entry->setFieldValue(self::SUMMARY_FIELD_HANDLE, $seed['summary']);
			$entry->setFieldValue(self::BODY_FIELD_HANDLE, $seed['body']);

			if (!Craft::$app->getElements()->saveElement($entry)) {
				$this->failModel('entry', $entry);
			}
		}
	}

	/**
	 * Returns article seed definitions.
	 *
	 * @return array<int, array{title:string, slug:string, summary:string, body:string}> article seed definitions
	 * @author Jalen Davenport
	 * @since 1.0.0
	 */
	private function articleSeeds(): array
	{
		return [
			[
				'title' => 'Lab Sample Article One',
				'slug' => 'lab-sample-article-one',
				'summary' => 'A short summary used to verify plain text field traversal.',
				'body' => str_repeat(
					'This is ordinary sample prose so the lab has real body content to render and measure. ',
					80,
				),
			],
			[
				'title' => 'Lab Sample Article Two',
				'slug' => 'lab-sample-article-two',
				'summary' => 'A second sample entry for template and query smoke tests.',
				'body' => str_repeat(
					'A second sample entry gives lab templates more than one row to iterate over. ',
					40,
				),
			],
		];
	}

	/**
	 * Creates the generic non-admin users lab templates query against.
	 *
	 * @return void
	 * @throws Throwable if Craft cannot save users
	 * @author Jalen Davenport
	 * @since 1.0.0
	 */
	private function seedUsers(): void
	{
		foreach ($this->userSeeds() as $seed) {
			$user = User::find()
				->email($seed['email'])
				->status(null)
				->one() ?? new User();

			$isNew = !$user->id;

			$user->username = $seed['username'];
			$user->email = $seed['email'];
			$user->firstName = $seed['firstName'];
			$user->lastName = $seed['lastName'];

			if ($isNew) {
				$user->newPassword = $seed['password'];
			}

			if (!Craft::$app->getElements()->saveElement($user)) {
				$this->failModel('user', $user);
			}

			// A user's active state can't be forced via the `active` property on
			// save (Craft silently rejects it); activate after the initial save.
			if ($isNew) {
				Craft::$app->getUsers()->activateUser($user);
			}
		}
	}

	/**
	 * Returns user seed definitions.
	 *
	 * @return array<int, array{username:string, email:string, firstName:string, lastName:string, password:string}> user seed definitions
	 * @author Jalen Davenport
	 * @since 1.0.0
	 */
	private function userSeeds(): array
	{
		return [
			[
				'username' => 'editor',
				'email' => 'editor@lab.test',
				'firstName' => 'Lab',
				'lastName' => 'Editor',
				'password' => 'password',
			],
		];
	}

	/**
	 * Throws a validation failure for a Craft model.
	 *
	 * @param string $label model label
	 * @param mixed $model failed model
	 * @return never
	 * @author Jalen Davenport
	 * @since 1.0.0
	 */
	private function failModel(string $label, mixed $model): never
	{
		$errors = method_exists($model, 'getErrors') ? $model->getErrors() : [];
		$message = $errors !== [] ? json_encode($errors) : 'unknown validation error';

		throw new \RuntimeException(sprintf('Could not save %s: %s', $label, $message));
	}
}
