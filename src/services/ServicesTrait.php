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

use yii\base\InvalidConfigException;

/**
 * Trait ServicesTrait
 *
 * @author      CraftPulse
 * @package     FlandersMake
 * @since       5.0.0
 *
 */
trait ServicesTrait
{
    // Static Methods
    // =========================================================================
    public static function config(): array
    {
        return [
            'components' => [
                'azure' => AzureService::class,
            ],
        ];
    }

    // Public Methods
    // =========================================================================
    /**
     * Returns the azure service
     *
     * @return AzureService The azure service
     * @throws InvalidConfigException
     */
    public function getAzure(): AzureService
    {
        return $this->get('azure');
    }
}