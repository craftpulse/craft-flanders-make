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
use craft\helpers\App;

use craftpulse\flandersmake\FlandersMake;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

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
     * @throws GuzzleException
     */
    public function registerI3oT(string $email): array
    {
        $webhookUrl = FlandersMake::$plugin->getSettings()->powerAutomateUrl;

        try {
            $client = new \GuzzleHttp\Client([
                'verify' => !App::devMode(),
            ]);

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
     * Get Power Automate webhook URL
     *
     * @return string
     */
    public function getPowerAutomateUrl(): string
    {
        return FlandersMake::$plugin->getSettings()->powerAutomateUrl ?? '';
    }
}
