<?php

namespace paxxion\craftredirector;

use Craft;
use craft\base\Event;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\DefineFieldLayoutElementsEvent;
use craft\events\ElementEvent;
use craft\events\ExceptionEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\ErrorHandler;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use paxxion\craftredirector\elements\UrlHistoryElement;
use paxxion\craftredirector\models\Settings;
use paxxion\craftredirector\services\cp\AuditService;
use paxxion\craftredirector\services\RedirectService;
use paxxion\craftredirector\services\StashService;

/**
 * Redirector plugin
 *
 * @method static Redirector getInstance()
 * @method Settings getSettings()
 * @author Paxxion <web@paxxion.com>
 * @copyright Paxxion
 * @license https://craftcms.github.io/license/ Craft License
 */
class Redirector extends Plugin
{
    public string $schemaVersion = '1.0.5';
    public bool $hasCpSections = true;
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();
        
        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_SAVE_ELEMENT,
            static function(ElementEvent $event): void {
                StashService::stash($event);
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            static function(ElementEvent $event): void {
                StashService::save($event);
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_UPDATE_SLUG_AND_URI,
            static function(ElementEvent $event): void {
                StashService::stash($event);
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_UPDATE_SLUG_AND_URI,
            static function(ElementEvent $event): void {
                StashService::save($event);
            }
        );

        Event::on(
            FieldLayout::class,
            FieldLayout::EVENT_DEFINE_UI_ELEMENTS,
            function(DefineFieldLayoutElementsEvent $event) {
                $event->elements[] = UrlHistoryElement::class;
            }
        );

        Event::on(
            ErrorHandler::class,
            ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION,
            static function(ExceptionEvent $event): void {
                RedirectService::handleException($event);
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                // index
                $event->rules['redirector'] = [ 'route' => 'pxx-redirector/cp/redirects/redirect-to-index'];
                // audit index
                $event->rules['redirector/audit'] = [ 'route' => 'pxx-redirector/cp/audit/index'];
                $event->rules['redirector/audit/get-rows'] = [ 'route' => 'pxx-redirector/cp/audit/get-rows'];
                $event->rules['redirector/audit/delete-rows'] = [ 'route' => 'pxx-redirector/cp/audit/delete-rows'];
                $event->rules['redirector/audit/export-csv'] = [ 'route' => 'pxx-redirector/cp/audit/export-csv'];
                // redirects index
                $event->rules['redirector/redirects'] = [ 'route' => 'pxx-redirector/cp/redirects/index'];
                $event->rules['redirector/redirects/get-rows'] = [ 'route' => 'pxx-redirector/cp/redirects/get-rows'];
                $event->rules['redirector/redirects/update-rows'] = [ 'route' => 'pxx-redirector/cp/redirects/update-rows'];
                $event->rules['redirector/redirects/delete-rows'] = [ 'route' => 'pxx-redirector/cp/redirects/delete-rows'];
                $event->rules['redirector/redirects/export-csv'] = [ 'route' => 'pxx-redirector/cp/redirects/export-csv'];
                $event->rules['redirector/redirects/import'] = [ 'route' => 'pxx-redirector/cp/redirects/import'];
                $event->rules['redirector/redirects/import-csv'] = [ 'route' => 'pxx-redirector/cp/redirects/import-csv'];
                // redirects entry (DO NOT CHANGE ORDER)
                $event->rules['redirector/redirects/save-redirect'] = [ 'route' => 'pxx-redirector/cp/redirects/save-redirect'];
                $event->rules['redirector/redirects/<uid:.+>'] = [ 'route' => 'pxx-redirector/cp/redirects/entry'];
                // ai
                $event->rules['redirector/ai/verify-connection'] = [ 'route' => 'pxx-redirector/cp/ai/verify-connection'];
                $event->rules['redirector/ai/get-suggestions'] = [ 'route' => 'pxx-redirector/cp/ai/get-suggestions'];
                $event->rules['redirector/ai/generate-knowledge-base'] = [ 'route' => 'pxx-redirector/cp/ai/generate-knowledge-base'];
                $event->rules['redirector/ai/upload-knowledge-base'] = [ 'route' => 'pxx-redirector/cp/ai/upload-knowledge-base'];
                // settings
                $event->rules['redirector/settings'] = [ 'route' => 'pxx-redirector/cp/settings/index'];
            }
        );
        
        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function(RegisterCpNavItemsEvent $event) {
                $user = Craft::$app->getUser()->getIdentity();
                if (!$user->can('accessPlugin-redirector')) {
                    return;
                }

                $event->navItems = array_merge($event->navItems, [
                    [
                        'label' => Craft::t('pxx-redirector', 'Redirector'),
                        'url' => 'redirector',
                        'icon' => '@paxxion/craftredirector/icon-mask.svg',
                        'subnav' => [
                            'audit' => [
                                'label' => Craft::t('pxx-redirector', 'Audit'),
                                'url' => 'redirector/audit',
                                'badgeCount' => AuditService::getNotHandledCount(),
                            ],
                            'redirects' => [
                                'label' => Craft::t('pxx-redirector', 'Redirects'),
                                'url' => 'redirector/redirects',
                            ],
                            'settings' => [
                                'label' => Craft::t('pxx-redirector', 'Settings'),
                                'url' => 'redirector/settings',
                            ]
                        ],
                    ]
                ]);
            }
        );

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('pxx-redirector', 'Redirector'),
                    'permissions' => [
                        'accessPlugin-redirector' => [
                            'label' => Craft::t('pxx-redirector', 'Enable'),
                        ],
                    ],
                ];
            }
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    public function getSettingsResponse(): mixed
    {
        // Just redirect to the plugin settings page
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('redirector/settings'));
    }
}
