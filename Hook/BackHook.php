<?php
/*************************************************************************************/
/*      This file is part of the module EasyProductManager.                          */
/*                                                                                   */
/*      Copyright (c) Gilles Bourgeat                                                */
/*      email : gilles.bourgeat@gmail.com                                            */
/*                                                                                   */
/*      This module is not open source                                               /*
/*      please contact gilles.bourgeat@gmail.com for a license                       */
/*                                                                                   */
/*                                                                                   */
/*************************************************************************************/

namespace EasyOrderManager\Hook;

use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;

class BackHook extends BaseHook
{
    public function onMainInTopMenuItems(HookRenderEvent $event)
    {
        $event->add(
            $this->render('EasyOrderManager/hook/main.in.top.menu.items.html', [])
        );
    }
}
