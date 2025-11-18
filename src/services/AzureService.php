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
    private const POWER_AUTOMATE_URL = 'https://default1da30297840843baa2da17a602c6cb.0b.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/710933a869954187b350caf9805ed720/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=pm9W6-in8S_zZAyi9xhTA_jMOv8r4aH2VwPXymulq9E';

    /**
     * Check if user has Azure SSO connected via Social Login plugin
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
        $tokens = \verbb\sociallogin\SocialLogin::$plugin->getTokens()->getTokensByUserId($user->id);

        foreach ($tokens as $token) {
            if ($token->providerHandle === 'azure') {
                return true;
            }
        }

        return false;
    }

    /**
     * Get Azure OAuth connection URL
     */
    public function getAzureConnectUrl(): string
    {
        return '/social-login/auth/connect?provider=azure';
    }

    /**
     * Register user with I3oT via Power Automate (after Azure SSO connected)
     */
    public function registerI3oT(string $email): array
    {
        try {
            $client = new Client(['verify' => false]);

            $response = $client->post(self::POWER_AUTOMATE_URL, [
                'json' => ['email' => $email],
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 30,
            ]);

            if ($response->getStatusCode() === 200) {
                Craft::info("I3oT registration successful: {$email}", 'flanders-make');

                return [
                    'success' => true,
                    'message' => 'Registration successful',
                ];
            }

            return [
                'success' => false,
                'message' => 'Registration failed',
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
     * Get I3oT Documentation URL
     */
    public function getI3oTUrl(): string
    {
        return 'https://dev.azure.com/iiot-platform/IIoT%20Documentation/_wiki/wikis/IIoT-Documentation.wiki/565/I3oT-Documentation';
    }
}
