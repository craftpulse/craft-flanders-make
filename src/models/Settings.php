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
    public ?string $powerAutomateUrl = null;

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
            [['powerAutomateUrl'], 'string'],
            [['powerAutomateUrl'], 'url'],
            [['autoRegisterI3oT'], 'boolean'],
        ];
    }
}
