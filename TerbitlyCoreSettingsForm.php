<?php

/**
 * @file plugins/generic/terbitlyCore/TerbitlyCoreSettingsForm.php
 *
 * @class TerbitlyCoreSettingsForm
 *
 * @brief The app address and this journal's token, both copied from the journal's OJS tab in the app.
 */

namespace APP\plugins\generic\terbitlyCore;

use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorRegExp;

class TerbitlyCoreSettingsForm extends Form
{
    public function __construct(public TerbitlyCorePlugin $plugin, public int $journalId)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        // Not FormValidatorUrl: its browser check refuses local addresses such as 127.0.0.1, used while testing.
        $this->addCheck(new FormValidatorRegExp($this, 'appUrl', 'required', 'plugins.generic.terbitlyCore.settings.appUrlRequired', '#^https?://[^\s/]+#'));
        $this->addCheck(new FormValidatorRegExp($this, 'token', 'required', 'plugins.generic.terbitlyCore.settings.tokenRequired', '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{16,}$/'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    public function initData()
    {
        $this->_data = [
            'appUrl' => $this->plugin->getSetting($this->journalId, 'appUrl'),
            'token' => $this->plugin->getSetting($this->journalId, 'token'),
        ];
    }

    public function readInputData()
    {
        $this->readUserVars(['appUrl', 'token']);
    }

    public function fetch($request, $template = null, $display = false)
    {
        TemplateManager::getManager($request)->assign('pluginName', $this->plugin->getName());
        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting($this->journalId, 'appUrl', rtrim(trim($this->getData('appUrl')), '/'), 'string');
        $this->plugin->updateSetting($this->journalId, 'token', trim($this->getData('token')), 'string');
        parent::execute(...$functionArgs);
    }
}
