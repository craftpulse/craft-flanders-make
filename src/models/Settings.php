<?php
/**
 * Flanders Make plugin for Craft CMS
 *
 * The Flanders Make connector - handling validation and connections with FM services.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\flandersmake\models;

use Craft;
use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;

/**
 * Model Settings
 *
 * @author      CraftPulse
 * @package     FlandersMake
 * @since       5.0.0
 *
 */
class Settings extends Model
{
    /**
     * Power Automate webhook URL for I3oT registration
     *
     * @var string|null
     */
    public ?string $powerAutomateUrl = 'https://default1da30297840843baa2da17a602c6cb.0b.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/710933a869954187b350caf9805ed720/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=pm9W6-in8S_zZAyi9xhTA_jMOv8r4aH2VwPXymulq9E';

    /**
     * Comma-separated list of allowed endpoint domains
     *
     * @var string
     */
    public string $allowedEndpointDomains = 'powerplatform.com,logic.azure.com,flow.microsoft.com';

    /**
     * Enable auto-registration with I3oT on user creation
     *
     * @var bool
     */
    public bool $autoRegisterI3oT = true;

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => [
                    'powerAutomateUrl',
                ],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['powerAutomateUrl', 'allowedEndpointDomains'], 'string'],
            [['powerAutomateUrl'], 'url'],
            [['autoRegisterI3oT'], 'boolean'],
        ];
    }
}
