<?php
/**
 * Flanders Make plugin for Craft CMS
 *
 * The Flanders Make connector - handling validation and connections with FM services.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\flandersmake;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\User;
use craft\events\ModelEvent;
use craft\events\PluginEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\UserEvent;
use craft\helpers\Json;
use craft\log\MonologTarget;
use craft\services\Plugins;
use craft\services\Users;

use craft\services\UserPermissions;
use craft\web\UrlManager;
use craftpulse\flandersmake\models\Settings as SettingsModel;
use craftpulse\flandersmake\services\ServicesTrait;

use verbb\auth\events\AuthorizationUrlEvent;
use verbb\auth\services\OAuth;

use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use Throwable;
use yii\base\Event;
use yii\base\InvalidRouteException;
use yii\log\Dispatcher;
use yii\log\Logger;

/**
 * Class FlandersMake
 *
 * @author      CraftPulse
 * @package     FlandersMake
 * @since       5.0.0
 *
 * @method SettingsModel getSettings()
 */
class FlandersMake extends Plugin
{
    // Traits
    // =========================================================================

    use ServicesTrait;

    // Static Properties
    // =========================================================================
    /**
     * @var ?FlandersMake
     */
    public static ?FlandersMake $plugin = null;

    // Public Properties
    // =========================================================================
    /**
     * @var null|SettingsModel
     */
    public static ?SettingsModel $settings = null;

    /**
     * @var string
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @var bool
     */
    public bool $hasCpSection = true;

    /**
     * @var bool
     */
    public bool $hasCpSettings = true;

    /**
     * @var mixed|object|null
     */
    public mixed $queue = null;

    // Public Methods
    // =========================================================================
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // Register custom log target
        $this->registerLogTarget();

        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest()) {
            $this->controllerNamespace = 'craftpulse\flandersmake\console\controllers';
        }

        // Install our global event handlers
        $this->installEventHandlers();

        // Register control panel events
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->registerCpUrlRules();
            $this->installCpEventHandlers();
        }

        // Add login_hint to Azure authorization URL
        Event::on(
            OAuth::class,
            OAuth::EVENT_BEFORE_AUTHORIZATION_REDIRECT,
            function(AuthorizationUrlEvent $event) {
                if ($event->provider->handle === 'azure') {
                    $currentUser = Craft::$app->getUser()->getIdentity();

                    if ($currentUser && $currentUser->email) {
                        $separator = (str_contains($event->authUrl, '?')) ? '&' : '?';
                        $event->authUrl = $event->authUrl . $separator . 'login_hint=' . urlencode($currentUser->email);
                    }
                }
            }
        );

        // Log that the plugin has loaded
        Craft::info(
            Craft::t(
                'flanders-make',
                '{name} plugin loaded',
                ['name' => $this->name]
            )
        );
    }

    /**
     * Logs a message
     * @throws Throwable
     */
    public function log(string $message, array $params = [], int $type = Logger::LEVEL_INFO): void
    {
        /** @var User|null $user */
        $user = Craft::$app->getUser()->getIdentity();

        if ($user !== null) {
            $params['username'] = $user->username;
        }

        $encoded_params = str_replace('\\', '', Json::encode($params));

        $message = Craft::t('flanders-make', $message . ' ' . $encoded_params, $params);

        Craft::getLogger()->log($message, $type, 'flanders-make');
    }

    /**
     * @inheritdoc
     * @throws InvalidRouteException
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect('flanders-make/settings');
    }

    /**
     * @inheritdoc
     * @throws Throwable
     */
    public function getCpNavItem(): ?array
    {
        $subNavs = [];
        $navItem = parent::getCpNavItem();
        $currentUser = Craft::$app->getUser()->getIdentity();

        $editableSettings = true;
        $general = Craft::$app->getConfig()->getGeneral();

        if (!$general->allowAdminChanges) {
            $editableSettings = false;
        }

        if ($currentUser->can('fm:settings') && $editableSettings) {
            $subNavs['settings'] = [
                'label' => 'Settings',
                'url' => 'flanders-make/settings',
            ];
        }

        if (empty($subNavs)) {
            return null;
        }

        // A single sub nav item is redundant
        if (count($subNavs) === 1) {
            $subNavs = [];
        }

        return array_merge($navItem, [
            'subnav' => $subNavs,
        ]);
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'flanders-make/_settings/general',
            ['settings' => $this->getSettings()]
        );
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new SettingsModel();
    }

    /**
     * @return void
     */
    protected function installEventHandlers(): void
    {
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_SAVE_PLUGIN_SETTINGS,
            function(PluginEvent $event) {
                if ($event->plugin === $this) {
                    Craft::debug(
                        'Plugins::EVENT_AFTER_SAVE_PLUGIN_SETTINGS',
                        __METHOD__
                    );
                }
            }
        );

        // Handle user activation (pending → active via email verification or admin)
        Event::on(
            Users::class,
            Users::EVENT_AFTER_ACTIVATE_USER,
            [self::class, 'handleUserActivation']
        );

        // Handle new users created as already active (Social Login with forceActivate)
        Event::on(
            User::class,
            User::EVENT_AFTER_SAVE,
            [self::class, 'handleNewActiveUser']
        );

        $this->registerUserPermissions();
    }

    /**
     * Handle user activation (pending → active)
     * Fires when a user is activated via email verification or admin action.
     */
    public static function handleUserActivation(UserEvent $event): void
    {
        $user = $event->user;

        if (empty($user->email)) {
            return;
        }

        if (!FlandersMake::$plugin->getSettings()->autoRegisterI3oT) {
            return;
        }

        $alreadyRegistered = Craft::$app->getCache()->get("i3ot_registered_{$user->id}");
        if ($alreadyRegistered) {
            return;
        }

        Craft::info("Auto-registering activated user with I3oT: {$user->email}", 'flanders-make');

        $result = FlandersMake::$plugin->getAzure()->registerI3oT($user->email);

        if ($result['success']) {
            Craft::$app->getCache()->set("i3ot_registered_{$user->id}", true, 31536000);
            Craft::info("Successfully auto-registered user with I3oT: {$user->email}", 'flanders-make');
        } else {
            Craft::warning("Failed to auto-register user with I3oT: {$user->email} - {$result['message']}", 'flanders-make');
        }
    }

    /**
     * Handle new users created as already active (Social Login path)
     * Only fires for brand new users, not profile updates.
     */
    public static function handleNewActiveUser(ModelEvent $event): void
    {
        /** @var User $user */
        $user = $event->sender;

        // Only new users
        if (!$event->isNew) {
            return;
        }

        // Only active users with an email
        if ($user->status !== User::STATUS_ACTIVE || empty($user->email)) {
            return;
        }

        if (!FlandersMake::$plugin->getSettings()->autoRegisterI3oT) {
            return;
        }

        $alreadyRegistered = Craft::$app->getCache()->get("i3ot_registered_{$user->id}");
        if ($alreadyRegistered) {
            return;
        }

        Craft::info("Auto-registering new active user with I3oT: {$user->email}", 'flanders-make');

        $result = FlandersMake::$plugin->getAzure()->registerI3oT($user->email);

        if ($result['success']) {
            Craft::$app->getCache()->set("i3ot_registered_{$user->id}", true, 31536000);
            Craft::info("Successfully auto-registered user with I3oT: {$user->email}", 'flanders-make');
        } else {
            Craft::warning("Failed to auto-register user with I3oT: {$user->email} - {$result['message']}", 'flanders-make');
        }
    }

    /**
     * @return void
     */
    protected function installCpEventHandlers(): void
    {}

    // Private Methods
    // =========================================================================

    /**
     * Registers CP URL rules event
     */
    private function registerCpUrlRules(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                // Merge so that settings controller action comes first (important!)
                $event->rules = array_merge(
                    [
                        'flanders-make' => 'flanders-make/settings/edit',
                        'flanders-make/settings' => 'flanders-make/settings/edit',
                        'flanders-make/plugins/flanders-make' => 'flanders-make/settings/edit',
                    ],
                    $event->rules
                );
            }
        );
    }

    /**
     * Registers user permissions
     */
    private function registerUserPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'Flanders Make',
                    'permissions' => [
                        'fm:settings' => [
                            'label' => Craft::t('flanders-make', 'Manage plugin settings.'),
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Registers a custom log target
     *
     * @see LineFormatter::SIMPLE_FORMAT
     */
    private function registerLogTarget(): void
    {
        if (Craft::getLogger()->dispatcher instanceof Dispatcher) {
            Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
                'name' => 'flanders-make',
                'categories' => ['flanders-make'],
                'level' => LogLevel::INFO,
                'logContext' => false,
                'allowLineBreaks' => true,
                'formatter' => new LineFormatter(
                    format: "%datetime% [%channel%.%level_name%] %message% %context%\n",
                    dateFormat: 'Y-m-d H:i:s',
                ),
            ]);
        }
    }
}
