<?php

namespace WPUM\Composer\Installers;

class WolfCMSInstaller extends BaseInstaller
{
    protected $locations = array('plugin' => 'wolf/plugins/{$name}/');
}
