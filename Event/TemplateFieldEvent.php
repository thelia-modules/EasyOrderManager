<?php

namespace EasyOrderManager\Event;

use Symfony\Component\HttpFoundation\Request;
use Thelia\Core\Event\ActionEvent;
use Thelia\Model\OrderQuery;

class TemplateFieldEvent extends ActionEvent
{
    public const ORDER_MANAGER_TEMPLATE_FIELD = 'order.manager.template.field';

    protected $templateFields = [];

    public function addTemplateField($name, $template)
    {
        $this->templateFields[$name] = $template;
    }

    public function removeTemplateField($name)
    {
        if (isset($this->templateFields[$name])) {
            unset($this->templateFields[$name]);
        }
    }

    public function getTemplateFields()
    {
        return $this->templateFields;
    }
}
