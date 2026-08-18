<?php

namespace justinholtweb\alarmclock\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

class CpAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CraftCpAsset::class];
        $this->js = ['alarm-clock.js'];
        $this->css = ['alarm-clock.css'];

        parent::init();
    }
}
