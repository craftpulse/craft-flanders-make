<?php
/**
 * Flanders Make plugin for Craft CMS
 *
 * The Flanders Make connector - handling validation and connections with FM services.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\flandersmake\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craftpulse\flandersmake\FlandersMake;
use GuzzleHttp\Client;
use yii\base\Exception;

/**
 * Class AzureService
 *
 * @author      CraftPulse
 * @package     FlandersMake
 * @since       5.0.0
 */
class AzureService extends Component
{
    /**
     * Default Power Automate URL - can be overridden in settings
     */
    private const DEFAULT_POWER_AUTOMATE_URL = 'https://default1da30297840843baa2da17a602c6cb.0b.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/710933a869954187b350caf9805ed720/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=pm9W6-in8S_zZAyi9xhTA_jMOv8r4aH2VwPXymulq9E';

    /**
     * Check if user has Azure SSO connected via Social Login plugin
     *
     * @param User $user
     * @return bool
     */
    public function hasAzureConnection(User $user): bool
    {
        // Check if Verbb Social Login plugin is installed
        $socialLogin = Craft::$app->getPlugins()->getPlugin('social-login');

        if (!$socialLogin) {
            Craft::warning('Social Login plugin not found', 'flanders-make');
            return false;
        }

        // Check for Azure AD token
        try {
            $tokens = \verbb\sociallogin\SocialLogin::$plugin->getTokens()->getTokensByUserId($user->id);

            foreach ($tokens as $token) {
                if ($token->providerHandle === 'azure') {
                    return true;
                }
            }
        } catch (\Exception $e) {
            Craft::error("Error checking Azure connection: " . $e->getMessage(), 'flanders-make');
        }

        return false;
    }

    /**
     * Get Azure OAuth connection URL
     *
     * @return string
     */
    public function getAzureConnectUrl(): string
    {
        return '/social-login/auth/connect?provider=azure';
    }

    /**
     * Register user with I3oT via Power Automate
     * This is called automatically on user registration
     *
     * @param string $email
     * @return array
     */
    public function registerI3oT(string $email): array
    {
        $settings = FlandersMake::$plugin->getSettings();
        $webhookUrl = $settings->powerAutomateUrl ?? self::DEFAULT_POWER_AUTOMATE_URL;

        try {
            $client = new Client(['verify' => false]);

            $response = $client->post($webhookUrl, [
                'json' => ['email' => $email],
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 30,
            ]);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                Craft::info("I3oT registration successful: {$email}", 'flanders-make');

                return [
                    'success' => true,
                    'message' => 'Registration successful',
                ];
            }

            return [
                'success' => false,
                'message' => 'Registration failed with status: ' . $response->getStatusCode(),
            ];

        } catch (\Exception $e) {
            Craft::error("Power Automate API error: {$e->getMessage()}", 'flanders-make');

            return [
                'success' => false,
                'message' => 'API error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Validate user access to an application
     * For POC: Returns mock validation data
     * For Production: Would call actual Azure API
     *
     * @param string $userEmail
     * @param string $appHandle
     * @return array
     */
    public function validateUserAccess(string $userEmail, string $appHandle): array
    {
        // POC: Return mock successful validation
        // In production, this would call an Azure API endpoint

        Craft::info("Validating access for {$userEmail} to {$appHandle}", 'flanders-make');

        // Mock response structure
        return [
            'hasAccess' => true,
            'paymentStatus' => 'active',
            'isOnboarded' => true,
            'message' => 'Access granted',
            'azureUserId' => hash('sha256', $userEmail), // Mock Azure user ID
        ];
    }

    /**
     * Check if user has full access based on Azure response
     *
     * @param array $azureResponse
     * @return bool
     */
    public function hasFullAccess(array $azureResponse): bool
    {
        return ($azureResponse['hasAccess'] ?? false)
            && ($azureResponse['paymentStatus'] ?? '') === 'active'
            && ($azureResponse['isOnboarded'] ?? false);
    }

    /**
     * Get application launch URL
     * For POC: Returns the product's application URL
     * For Production: Would generate SSO token and return authenticated URL
     *
     * @param string $userEmail
     * @param string $appHandle
     * @return string|null
     */
    public function getAppLaunchUrl(string $userEmail, string $appHandle): ?string
    {
        // Get the product entry
        $product = \craft\elements\Entry::find()
            ->section('products')
            ->slug($appHandle)
            ->one();

        if (!$product) {
            Craft::warning("Product not found: {$appHandle}", 'flanders-make');
            return null;
        }

        // Get the application URL from the product
        $applicationUrl = $product->applicationUrl ?? null;

        if (!$applicationUrl) {
            Craft::warning("No application URL configured for: {$appHandle}", 'flanders-make');
            return null;
        }

        // POC: Return URL directly
        // Production: Would append SSO token/parameters
        Craft::info("Launching {$appHandle} for {$userEmail}: {$applicationUrl}", 'flanders-make');

        return $applicationUrl;
    }

    /**
     * Get Power Automate webhook URL
     *
     * @return string
     */
    public function getPowerAutomateUrl(): string
    {
        $settings = FlandersMake::$plugin->getSettings();
        return $settings->powerAutomateUrl ?? self::DEFAULT_POWER_AUTOMATE_URL;
    }
}
