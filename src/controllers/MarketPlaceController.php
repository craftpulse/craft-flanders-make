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
use craft\helpers\App;
use craft\web\Controller;
use craftpulse\flandersmake\FlandersMake;
use GuzzleHttp\Client;
use Throwable;
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
class MarketPlaceController extends Controller
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
     * POST /actions/flanders-make/market-place/check-i3ot-status
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws InvalidConfigException
     * @throws MethodNotAllowedHttpException
     * @throws Throwable
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
     * Proxy endpoint call to Power Automate
     * Avoids CORS issues by calling from server-side
     *
     * POST /actions/flanders-make/market-place/call-endpoint
     * Body: { variantId: 123 }
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     * @throws Throwable
     */
    public function actionCallEndpoint(): Response
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

        $variantId = Craft::$app->getRequest()->getBodyParam('variantId');

        if (empty($variantId)) {
            return $this->asJson([
                'success' => false,
                'error' => 'Variant ID is required',
            ]);
        }

        // Look up the variant and get the endpoint URL server-side
        $variant = \craft\commerce\elements\Variant::find()
            ->id($variantId)
            ->one();

        if (!$variant) {
            Craft::warning("Call endpoint: variant not found: {$variantId}", 'flanders-make');
            return $this->asJson([
                'success' => false,
                'error' => 'Variant not found',
            ]);
        }

        $endpointUrl = $variant->endpoint?->getUrl() ?? null;

        if (empty($endpointUrl)) {
            Craft::warning("Call endpoint: no endpoint URL on variant {$variantId}", 'flanders-make');
            return $this->asJson([
                'success' => false,
                'error' => 'No endpoint configured for this variant',
            ]);
        }

        // Whitelist: only allow Power Automate domains
        $parsedHost = parse_url($endpointUrl, PHP_URL_HOST);
        $allowedDomains = array_map('trim', explode(',', FlandersMake::$plugin->getSettings()->allowedEndpointDomains));

        $isAllowed = false;
        foreach ($allowedDomains as $domain) {
            if ($parsedHost === $domain || str_ends_with($parsedHost, '.' . $domain)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$parsedHost || !$isAllowed) {
            Craft::error("Call endpoint: blocked non-whitelisted domain: {$parsedHost}", 'flanders-make');
            return $this->asJson([
                'success' => false,
                'error' => 'Invalid endpoint domain',
            ]);
        }

        Craft::info("Calling endpoint for user {$currentUser->email}, variant {$variantId}: {$endpointUrl}", 'flanders-make');

        try {
            $client = new Client([
                'verify' => !App::devMode(),
            ]);

            $response = $client->post($endpointUrl, [
                'json' => ['email' => $currentUser->email],
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                Craft::info("Endpoint call successful for {$currentUser->email}, variant {$variantId}", 'flanders-make');
                return $this->asJson([
                    'success' => true,
                    'message' => 'Endpoint called successfully',
                ]);
            }

            Craft::warning("Endpoint call returned status {$statusCode} for variant {$variantId}", 'flanders-make');
            return $this->asJson([
                'success' => false,
                'error' => "Endpoint returned status: {$statusCode}",
            ]);
        } catch (\Exception $e) {
            Craft::error("Endpoint proxy error for variant {$variantId}: {$e->getMessage()}", 'flanders-make');
            return $this->asJson([
                'success' => false,
                'error' => 'Endpoint call failed',
            ]);
        }
    }
}
