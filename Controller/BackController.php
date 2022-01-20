<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*      Copyright (c) Gilles Bourgeat                                                */
/*      email : gilles.bourgeat@gmail.com                                            */

/*      This module is not open source                                               /*
/*      please contact gilles.bourgeat@gmail.com for a license                       */

namespace EasyOrderManager\Controller;

use EasyOrderManager\EasyOrderManager;
use EasyOrderManager\Event\TemplateFieldEvent;
use EasyOrderManager\Service\EasyOrderManagerService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Controller\Admin\ProductController;
use Thelia\Core\HttpFoundation\JsonResponse;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Thelia;
use Thelia\Model\Order;
use Thelia\Tools\MoneyFormat;
use Thelia\Tools\URL;

/**
 * @Route("/admin/easy-order-manager", name="admin_easy_order_manager")
 */
class BackController extends ProductController
{
    /**
     * @Route("/list", name="_list", methods={"GET","POST"})
     */
    public function listAction(RequestStack $requestStack, EventDispatcherInterface $dispatcher, EasyOrderManagerService $service)
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return $response;
        }
        $request = $requestStack->getCurrentRequest();
        if ($request->isXmlHttpRequest()) {
            $locale = $request->getSession()->getLang()->getLocale();

            $ordersWithCount = $service->getOrderFilter($request, 25);
            $orders = $ordersWithCount['orders'];

            $json = [
                'draw' => $service->getDraw($request),
                'recordsTotal' => $ordersWithCount['recordsTotal'],
                'recordsFiltered' => $ordersWithCount['recordsFiltered'],
                'data' => [],
                'orders' => \count($orders->getData()),
            ];

            $moneyFormat = MoneyFormat::getInstance($request);

            /** @var Order $order */
            foreach ($orders as $order) {
                $amount = $moneyFormat->formatByCurrency(
                    $order->getTotalAmount(),
                    2,
                    '.',
                    ' ',
                    $order->getCurrencyId()
                );
                // for each defineColumnsDefinition

                $updateUrl = URL::getInstance()->absoluteUrl('admin/order/update/'.$order->getId());

                $json['data'][] = [
                    [
                        'id' => $order->getId(),
                        'href' => $updateUrl,
                    ],
                    [
                        'ref' => $order->getRef(),
                        'href' => $updateUrl,
                    ],
                    $order->getCreatedAt('d/m/y H:i:s'),
                    $order->getInvoiceDate('d/m/y H:i:s'),
                    $order->getOrderAddressRelatedByInvoiceOrderAddressId()->getCompany(),
                    [
                        'href' => URL::getInstance()->absoluteUrl('admin/customer/update?customer_id='.$order->getCustomerId()),
                        'name' => $order->getOrderAddressRelatedByInvoiceOrderAddressId()->getFirstname().' '.$order->getOrderAddressRelatedByInvoiceOrderAddressId()->getLastname(),
                    ],
                    $amount,
                    [
                        'name' => $order->getOrderStatus()->setLocale($locale)->getTitle(),
                        'color' => $order->getOrderStatus()->getColor(),
                    ],
                    [
                        'order_id' => $order->getId(),
                        'hrefUpdate' => $updateUrl,
                        'hrefPrint' => URL::getInstance()->absoluteUrl('admin/order/pdf/invoice/'.$order->getId().'/1'),
                        'isCancelled' => $order->isCancelled(),
                    ],
                ];
            }

            return new JsonResponse($json);
        }

        $templateFieldEvent = new TemplateFieldEvent();
        $dispatcher->dispatch($templateFieldEvent, TemplateFieldEvent::ORDER_MANAGER_TEMPLATE_FIELD);

        return $this->render('EasyOrderManager/list', [
            'columnsDefinition' => $service->defineColumnsDefinition(),
            'theliaVersion' => Thelia::THELIA_VERSION,
            'moduleVersion' => EasyOrderManager::MODULE_VERSION,
            'moduleName' => EasyOrderManager::MODULE_NAME,
            'template_fields' => $templateFieldEvent->getTemplateFields(),
        ]);
    }

    /**
     * @Route("/list/csv", name="_csv", methods={"GET","POST"})
     */
    public function listCSVAction(RequestStack $requestStack, EventDispatcherInterface $dispatcher, EasyOrderManagerService $service)
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return $response;
        }

        $request = $requestStack->getCurrentRequest();

        $ordersWithCount = $service->getOrderFilter($request, 100, 'csv');
        $orders = $ordersWithCount['orders'];
        //dump($orders->toArray());die;
        $name = md5(serialize($orders->toArray()));
        $filename = 'export_list_order_datatable'.$name.'.csv';
        $file = THELIA_CACHE_DIR.$filename;

        if (file_exists($file)) {
            unlink($file);
        }

        $this->writeData([[
            'ID',
            'Ref',
            'CreateDate',
            'InvoiceDate',
            'Company',
            'Customer firstName',
            'Customer lastName',
            'Amount',
            'Status',
        ]], $file);

        $this->writeData($orders->toArray(), $file);

        $header = [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => sprintf(
                '%s; filename="%s.%s"',
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $filename,
                'csv'
            ),
        ];

        return new BinaryFileResponse($file, 200, $header);
    }

    protected function writeData($data, $file): void
    {
        if ($filetoExport = fopen($file, 'a+')) {
            foreach ($data as $item) {
                fputcsv($filetoExport, $item, ';', ' ');
            }

            fclose($filetoExport);
        }
    }
}
