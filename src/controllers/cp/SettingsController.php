<?php

namespace paxxion\craftredirector\controllers\cp;

use Craft;
use craft\web\Controller;
use craft\web\Response;
use paxxion\craftredirector\Redirector;
use paxxion\craftredirector\services\cp\AiService;

class SettingsController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;
    
    public function actionIndex():Response
    {
        $generatedKBDate = AiService::getGeneratedKnowledgeBaseDate();
        $uploadedKBDate = AiService::getUploadedKnowledgeBaseDate();
        
        return $this->renderTemplate('pxx-redirector/cp/settings/settings', [
            'generatedKBDate' => $generatedKBDate,
            'uploadedKBDate' => $uploadedKBDate,
            'settings' => Redirector::getInstance()->getSettings(),
        ]);
    }

    public function actionSave():Response
    {
        $this->requirePostRequest();

        $postData = Craft::$app->getRequest()->getBodyParams();
        
        $plugin = Redirector::getInstance();
        
        $settings = [
            'excludedNotFound' => $postData['excludedNotFound'],
            'excludedNotFoundPatterns' => $postData['excludedNotFoundPatterns'],
            'openAiApiKey' => $postData['openAiApiKey'],
        ];

        if (!is_array($settings['excludedNotFound'])) {
            $settings['excludedNotFound'] = [];
        }

        if (!is_array($settings['excludedNotFoundPatterns'])) {
            $settings['excludedNotFoundPatterns'] = [];
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            $settingsModel = $plugin->getSettings();
        }

        return $this->redirectToPostedUrl();
    }
}
