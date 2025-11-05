<?php

namespace paxxion\craftredirector\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class RedirectorAssets extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@paxxion/craftredirector/web/dist';

        $this->depends = [CpAsset::class];

        $this->css = ['css/app.css'];
        $this->js  = ['js/app.js'];

        $this->publishOptions = [
            'forceCopy' => YII_ENV_DEV,
        ];

        parent::init();
    }
}
