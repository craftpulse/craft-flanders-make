<?php
/**
 * Flanders Make plugin for Craft CMS
 *
 * The Flanders Make connector - handling validation and connections with FM services.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\flandersmake\controllers;

use Craft;
use craft\errors\MissingComponentException;
use craft\web\Controller;
use craftpulse\flandersmake\FlandersMake;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

/**
 * Marketplace Controller
 *
 * Handles marketplace app validation and launching
 *
 * @author      CraftPulse
 * @package     FlandersMake
 * @since       5.0.0
 */
class MarketplaceController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        // Require login for all marketplace actions
        $this->requireLogin();

        return parent::beforeAction($action);
    }

    /**
     * Check user's I3oT access status
     * Returns Azure connection status + I3oT registration status
     *
     * POST /actions/flanders-make/marketplace/check-i3ot-status
     *
     */
    public function actionCheckI3otStatus(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $currentUser = Craft::$app->getUser()->getIdentity();

        if (!$currentUser) {
            return $this->asJson([
                'success' => false,
                'error' => 'User not authenticated',
            ]);
        }

        // Check Azure SSO connection
        $hasAzureConnection = FlandersMake::$plugin->getAzure()->hasAzureConnection($currentUser);

        // Check if user is registered with I3oT (from cache - set during user registration)
        $isI3otRegistered = Craft::$app->getCache()->get("i3ot_registered_{$currentUser->id}");

        return $this->asJson([
            'success' => true,
            'hasAzureConnection' => $hasAzureConnection,
            'isI3otRegistered' => (bool)$isI3otRegistered,
            'connectUrl' => $hasAzureConnection ? null : FlandersMake::$plugin->getAzure()->getAzureConnectUrl(),
        ]);
    }

    /**
     * Get I3oT documentation URL
     * Requires both Azure SSO connection AND I3oT registration
     *
     * POST /actions/flanders-make/marketplace/get-i3ot-url
     *
     */
    public function actionGetI3otUrl(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $currentUser = Craft::$app->getUser()->getIdentity();

        if (!$currentUser) {
            return $this->asJson([
                'success' => false,
                'error' => 'User not authenticated',
            ]);
        }

        // Check Azure connection
        if (!FlandersMake::$plugin->getAzure()->hasAzureConnection($currentUser)) {
            return $this->asJson([
                'success' => false,
                'error' => 'Azure account must be connected first',
            ]);
        }

        // Check I3oT registration
        $isRegistered = Craft::$app->getCache()->get("i3ot_registered_{$currentUser->id}");
        if (!$isRegistered) {
            return $this->asJson([
                'success' => false,
                'error' => 'User not registered with I3oT',
            ]);
        }

        $url = FlandersMake::$plugin->getAzure()->getI3oTUrl();

        return $this->asJson([
            'success' => true,
            'url' => $url,
        ]);
    }

    /**
     * Validate user access to an app
     * LEGACY - kept for backwards compatibility
     *
     * This endpoint checks with Azure if the current user has access to the requested app
     *
     * POST /actions/flanders-make/marketplace/validate-access
     * Body: { appHandle: "demo-app" }
     *
     * @return Response
     * @throws \Throwable
     * @throws MissingComponentException
     * @throws InvalidConfigException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function actionValidateAccess(): Response
{
    $this->requirePostRequest();
    $this->requireAcceptsJson();

    $appHandle = Craft::$app->getRequest()->getBodyParam('appHandle');

    if (empty($appHandle)) {
        return $this->asJson([
            'success' => false,
            'error' => 'App handle is required',
        ]);
    }

    // Get current user
    $currentUser = Craft::$app->getUser()->getIdentity();

    if (!$currentUser) {
        return $this->asJson([
            'success' => false,
            'error' => 'User not authenticated',
        ]);
    }

    $userEmail = $currentUser->email;

    Craft::info("Validating access for user: {$userEmail} to app: {$appHandle}", 'flanders-make');

    // Call Azure service to validate access
    $azureResponse = FlandersMake::$plugin->getAzure()->validateUserAccess($userEmail, $appHandle);

    // Check if user has full access
    $hasFullAccess = FlandersMake::$plugin->getAzure()->hasFullAccess($azureResponse);

    // Store validation result in session for this demo
    Craft::$app->getSession()->set("fm_access_{$appHandle}", [
        'validated' => true,
        'hasFullAccess' => $hasFullAccess,
        'azureResponse' => $azureResponse,
        'timestamp' => time(),
    ]);

    return $this->asJson([
        'success' => true,
        'hasFullAccess' => $hasFullAccess,
        'data' => [
            'hasAccess' => $azureResponse['hasAccess'] ?? false,
            'paymentStatus' => $azureResponse['paymentStatus'] ?? 'unknown',
            'isOnboarded' => $azureResponse['isOnboarded'] ?? false,
            'message' => $azureResponse['message'] ?? '',
            'azureUserId' => $azureResponse['azureUserId'] ?? null,
        ],
    ]);
}

    /**
     * Launch an app
     *
     * This endpoint provides the launch URL for an app if the user has access
     *
     * POST /actions/flanders-make/marketplace/launch-app
     * Body: { appHandle: "demo-app" }
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws InvalidConfigException
     * @throws MethodNotAllowedHttpException
     * @throws MissingComponentException
     * @throws \Throwable
     */
    public function actionLaunchApp(): Response
{
    $this->requirePostRequest();
    $this->requireAcceptsJson();

    $appHandle = Craft::$app->getRequest()->getBodyParam('appHandle');

    if (empty($appHandle)) {
        return $this->asJson([
            'success' => false,
            'error' => 'App handle is required',
        ]);
    }

    // Get current user
    $currentUser = Craft::$app->getUser()->getIdentity();

    if (!$currentUser) {
        return $this->asJson([
            'success' => false,
            'error' => 'User not authenticated',
        ]);
    }

    // Check if user has been validated (from session)
    $accessData = Craft::$app->getSession()->get("fm_access_{$appHandle}");

    if (!$accessData || !$accessData['hasFullAccess']) {
        return $this->asJson([
            'success' => false,
            'error' => 'Access validation required or insufficient permissions',
            'requiresValidation' => true,
        ]);
    }

    // Get launch URL from Azure
    $launchUrl = FlandersMake::$plugin->getAzure()->getAppLaunchUrl($currentUser->email, $appHandle);

    Craft::info("Launching app {$appHandle} for user: {$currentUser->email}", 'flanders-make');

    return $this->asJson([
        'success' => true,
        'launchUrl' => $launchUrl,
        'message' => 'Launching application...',
    ]);
}

    /**
     * Get marketplace apps list
     *
     * GET /actions/flanders-make/marketplace/get-apps
     *
     */
    public function actionGetApps(): Response
{
    $this->requireAcceptsJson();

    // For POC, return a single demo app
    $apps = [
        [
            'handle' => 'demo-app',
            'name' => 'Demo Application',
            'description' => 'A demonstration application for the Flanders Make marketplace',
            'icon' => '/icons/demo-app.svg',
            'category' => 'Tools',
        ],
    ];

    return $this->asJson([
        'success' => true,
        'apps' => $apps,
    ]);
}

    /**
     * Clear validation cache (for testing)
     *
     * POST /actions/flanders-make/marketplace/clear-validation
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws MissingComponentException
     */
    public function actionClearValidation(): Response
{
    $this->requirePostRequest();
    $this->requireAcceptsJson();

    $appHandle = Craft::$app->getRequest()->getBodyParam('appHandle');

    if ($appHandle) {
        Craft::$app->getSession()->remove("fm_access_{$appHandle}");
    } else {
        // Clear all FM access keys
        $session = Craft::$app->getSession();
        $allKeys = array_keys($session->getAll());
        foreach ($allKeys as $key) {
            if (str_starts_with($key, 'fm_access_')) {
                $session->remove($key);
            }
        }
    }

    return $this->asJson([
        'success' => true,
        'message' => 'Validation cache cleared',
    ]);
}
}
