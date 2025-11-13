<?php

namespace paxxion\craftredirector\controllers\cp;

use Craft;
use craft\web\Controller;
use craft\web\Response;
use paxxion\craftredirector\services\cp\AiService;

class AiController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;
    
    public function actionVerifyConnection():Response
    {
        return $this->asJson(AiService::verifyConnection());
    }

    public function actionGetSuggestions():Response
    {
        $this->requirePostRequest();
        $postData = Craft::$app->getRequest()->getBodyParams();

        return $this->asJson(AiService::getSuggestions($postData));
    }

    public function actionGenerateKnowledgeBase():Response
    {
        return $this->asJson(AiService::generateKnowledgeBase());
    }

    public function actionUploadKnowledgeBase():Response
    {
        return $this->asJson(AiService::uploadKnowledgeBase());
    }
}
