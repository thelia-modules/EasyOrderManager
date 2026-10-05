<?php
/*************************************************************************************/
/*      This file is part of the module EasyOrderManager.                            */
/*                                                                                   */
/*      Copyright (c) Gilles Bourgeat                                                */
/*      email : gilles.bourgeat@gmail.com                                            */
/*                                                                                   */
/*      This module is not open source                                              */
/*      please contact gilles.bourgeat@gmail.com for a license                       */
/*                                                                                   */
/*                                                                                   */
/*************************************************************************************/

namespace EasyOrderManager\Controller;

use EasyOrderManager\EasyOrderManager;
use EasyOrderManager\Event\BeforeFilterEvent;
use EasyOrderManager\Event\TemplateColumnDefinitionEvent;
use EasyOrderManager\Event\TemplateFieldEvent;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Join;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\JsonResponse;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\TheliaKernel;
use Thelia\Model\Map\CustomerTableMap;
use Thelia\Model\Map\OrderAddressTableMap;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;
use Thelia\Tools\MoneyFormat;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/easy-order-manager', name: 'admin_easy_order_manager')]
class BackController extends BaseAdminController
{
    protected const ORDER_INVOICE_ADDRESS_JOIN = 'orderInvoiceAddressJoin';

    #[Route('/list', name: '_list', methods: ['GET', 'POST'])]
    public function listAction(RequestStack $requestStack, EventDispatcherInterface $dispatcher): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return $response;
        }
        $request = $requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return $this->errorPage('This action requires an HTTP request with a session.', 400);
        }

        $locale = $request->getSession()->getLang()->getLocale();
        $templateFieldEvent = new TemplateFieldEvent();
        $dispatcher->dispatch($templateFieldEvent, TemplateFieldEvent::ORDER_MANAGER_TEMPLATE_FIELD);

        $templateColumnDefinitionEvent = new TemplateColumnDefinitionEvent(
            MoneyFormat::getInstance($request),
            $locale
        );
        $templateColumnDefinitionEvent->initColumnDefinition();
        $dispatcher->dispatch($templateColumnDefinitionEvent, TemplateColumnDefinitionEvent::ORDER_MANAGER_TEMPLATE_COLUMN_DEFINITION);
        $columnDefinitions = $templateColumnDefinitionEvent->getColumnDefinition();

        if ($request->isXmlHttpRequest()) {
            // Use Customer for email column in applySearchCustomer
            $query = OrderQuery::create()
                ->useCustomerQuery()
                ->endUse();

            $this->applyOrder($request, $query, $dispatcher);

            $queryCount = clone $query;

            $beforeFilterEvent = new BeforeFilterEvent($request, $query);
            $dispatcher->dispatch($beforeFilterEvent, BeforeFilterEvent::ORDER_MANAGER_BEFORE_FILTER);

            $this->filterByStatus($request, $query);
            $this->filterByPaymentModule($request, $query);
            $this->filterByDeliveryModule($request, $query);
            $this->filterByCreatedAt($request, $query);
            $this->filterByInvoiceDate($request, $query);

            $this->applySearchOrder($request, $query);
            $this->applySearchCompany($request, $query);
            $this->applySearchCustomer($request, $query);

            $querySearchCount = clone $query;

            $query->offset($this->getOffset($request));

            // Utilisez le paramètre length pour la pagination
            $orders = $query->limit($this->getLength($request))->find();

            $json = [
                "draw"=> $this->getDraw($request),
                "recordsTotal"=> $queryCount->count(),
                "recordsFiltered"=> $querySearchCount->count(),
                "data" => [],
                "orders" => count($orders->getData()),
            ];

            $moneyFormat = MoneyFormat::getInstance($request);

            /** @var Order $order */
            foreach ($orders as $order) {
                // for each defineColumnsDefinition
                $orderDatas = [];
                foreach ($columnDefinitions as $definition){
                    $orderDatas[] = $definition['parseOrderData']($order);
                }
                $json['data'][]=$orderDatas;
            }

            return new JsonResponse($json);
        }

        $orderStatuses = [];
        foreach (OrderStatusQuery::create()->find() as $orderStatus) {
            $orderStatus->setLocale($locale);
            $orderStatuses[] = [
                'id' => $orderStatus->getId(),
                'title' => $orderStatus->getTitle(),
            ];
        }

        $paymentModules = [];
        foreach (ModuleQuery::create()->filterByType(BaseModule::PAYMENT_MODULE_TYPE)->find() as $module) {
            $module->setLocale($locale);
            $paymentModules[] = [
                'id' => $module->getId(),
                'title' => $module->getTitle(),
            ];
        }

        $deliveryModules = [];
        foreach (ModuleQuery::create()->filterByType(BaseModule::DELIVERY_MODULE_TYPE)->find() as $module) {
            $module->setLocale($locale);
            $deliveryModules[] = [
                'id' => $module->getId(),
                'title' => $module->getTitle(),
            ];
        }

        return $this->render('EasyOrderManager/list', [
            'columnsDefinition' => $columnDefinitions,
            'theliaVersion' => TheliaKernel::THELIA_VERSION,
            'moduleVersion' => EasyOrderManager::MODULE_VERSION,
            'moduleName' => EasyOrderManager::MODULE_NAME,
            'template_fields' => $templateFieldEvent->getTemplateFields(),
            'selected_status' => $request->query->get('status'),
            'order_statuses' => $orderStatuses,
            'payment_modules' => $paymentModules,
            'delivery_modules' => $deliveryModules,
        ]);
    }

    protected function getOrderColumnName(Request $request, EventDispatcherInterface $dispatcher): string
    {
        $locale = $request->hasSession() ? $request->getSession()->getLang()->getLocale() : $request->getLocale();
        $templateColumnDefinitionEvent = new TemplateColumnDefinitionEvent(
            MoneyFormat::getInstance($request),
            $locale
        );
        $templateColumnDefinitionEvent->initColumnDefinition();

        $dispatcher->dispatch($templateColumnDefinitionEvent, TemplateColumnDefinitionEvent::ORDER_MANAGER_TEMPLATE_COLUMN_DEFINITION);
        $columnDefinition = $templateColumnDefinitionEvent->getColumnDefinition(true)[
            (int) ($request->request->all('order')[0]['column'] ?? 0)
        ];

        return $columnDefinition['orm'];
    }

    protected function applyOrder(Request $request, OrderQuery $query, EventDispatcherInterface $dispatcher): void
    {
        $query->orderBy(
            $this->getOrderColumnName($request, $dispatcher),
            $this->getOrderDir($request)
        );
    }

    protected function getOrderDir(Request $request): string
    {
        return 'asc' === (string) ($request->request->all('order')[0]['dir'] ?? '') ? Criteria::ASC : Criteria::DESC;
    }

    protected function getLength(Request $request): int
    {
        return (int) $request->request->get('length');
    }

    protected function getOffset(Request $request): int
    {
        return (int) $request->request->get('start');
    }

    protected function getDraw(Request $request): int
    {
        return (int) $request->request->get('draw');
    }

    protected function filterByStatus(Request $request, OrderQuery $query): void
    {
        if (0 !== $statusId = (int) ($request->request->all('filter')['status'] ?? 0)) {
            $query->filterByStatusId($statusId);
        }
    }

    protected function filterByPaymentModule(Request $request, OrderQuery $query): void
    {
        if (0 !== $paymentModuleId = (int) ($request->request->all('filter')['paymentModuleId'] ?? 0)) {
            $query->filterByPaymentModuleId($paymentModuleId);
        }
    }

    protected function filterByDeliveryModule(Request $request, OrderQuery $query): void
    {
        if (0 !== $deliveryModuleId = (int) ($request->request->all('filter')['deliveryModuleId'] ?? 0)) {
            $query->filterByDeliveryModuleId($deliveryModuleId);
        }
    }

    protected function filterByCreatedAt(Request $request, OrderQuery $query): void
    {
        $filter = $request->request->all('filter');

        if ('' !== $createdAtFrom = (string) ($filter['createdAtFrom'] ?? '')) {
            $query->filterByCreatedAt(sprintf("%s 00:00:00", $createdAtFrom), Criteria::GREATER_EQUAL);
        }
        if ('' !== $createdAtTo = (string) ($filter['createdAtTo'] ?? '')) {
            $query->filterByCreatedAt(sprintf("%s 23:59:59", $createdAtTo), Criteria::LESS_EQUAL);
        }
    }

    protected function filterByInvoiceDate(Request $request, OrderQuery $query): void
    {
        $filter = $request->request->all('filter');

        if ('' !== $invoiceDateFrom = (string) ($filter['invoiceDateFrom'] ?? '')) {
            $query->filterByInvoiceDate(sprintf("%s 00:00:00", $invoiceDateFrom), Criteria::GREATER_EQUAL);
        }
        if ('' !== $invoiceDateTo = (string) ($filter['invoiceDateTo'] ?? '')) {
            $query->filterByInvoiceDate(sprintf("%s 23:59:59", $invoiceDateTo), Criteria::LESS_EQUAL);
        }
    }

    protected function applySearchOrder(Request $request, OrderQuery $query): void
    {
        $value = $this->getSearchValue($request, 'searchOrder');

        if (strlen($value) > 2) {
            $query->where(OrderTableMap::COL_REF . ' LIKE ?', '%' . $value . '%', \PDO::PARAM_STR);
            $query->_or()->where(OrderTableMap::COL_ID . ' LIKE ?', '%' . $value . '%', \PDO::PARAM_STR);
            $query->_or()->where(OrderTableMap::COL_INVOICE_REF . ' LIKE ?', '%' . $value . '%', \PDO::PARAM_STR);
            $query->_or()->where(OrderTableMap::COL_DELIVERY_REF . ' LIKE ?', '%' . $value . '%', \PDO::PARAM_STR);
        }
    }

    /**
     * @throws PropelException
     */
    protected function applySearchCompany(Request $request, OrderQuery $query): void
    {
        $value = $this->getSearchValue($request, 'searchCompany');

        if (strlen($value) > 2) {
            if (!$query->hasJoin($this::ORDER_INVOICE_ADDRESS_JOIN)) {
                $orderInvoiceAddressJoin = new Join(
                    OrderTableMap::COL_INVOICE_ORDER_ADDRESS_ID,
                    OrderAddressTableMap::COL_ID,
                    Criteria::INNER_JOIN
                );

                $query->addJoinObject($orderInvoiceAddressJoin, $this::ORDER_INVOICE_ADDRESS_JOIN);
            }

            $query->addJoinCondition(
                $this::ORDER_INVOICE_ADDRESS_JOIN,
                OrderAddressTableMap::COL_COMPANY . " LIKE '%" . $value . "%'"
            );
        }
    }

    /**
     * @throws PropelException
     */
    protected function applySearchCustomer(Request $request, OrderQuery $query): void
    {
        $value = $this->getSearchValue($request, 'searchCustomer');

        if (strlen($value) > 2) {

            $value = $value[0] === '0' ? substr($value, 1) : $value;
            $value = str_replace('+33', '', $value);

            if (!$query->hasJoin($this::ORDER_INVOICE_ADDRESS_JOIN)) {
                $orderInvoiceAddressJoin = new Join(
                    OrderTableMap::COL_INVOICE_ORDER_ADDRESS_ID,
                    OrderAddressTableMap::COL_ID,
                    Criteria::INNER_JOIN
                );

                $query->addJoinObject($orderInvoiceAddressJoin, $this::ORDER_INVOICE_ADDRESS_JOIN);
            }

            $query->addJoinCondition(
                $this::ORDER_INVOICE_ADDRESS_JOIN,
                '('.OrderAddressTableMap::COL_FIRSTNAME." LIKE '%".$value."%' OR ".
                OrderAddressTableMap::COL_LASTNAME." LIKE '%".$value."%' OR ".
                OrderAddressTableMap::COL_CELLPHONE." LIKE '%".$value."%' OR ".
                OrderAddressTableMap::COL_LASTNAME." LIKE '%".$value."%' OR ".
                OrderAddressTableMap::COL_PHONE." LIKE '%".$value."%' OR ".
                OrderAddressTableMap::COL_CELLPHONE." LIKE '%".$value."%' OR ".
                CustomerTableMap::COL_EMAIL." LIKE '%".$value."%')"
            );

            $query->groupById();
        }
    }

    protected function getSearchValue(Request $request, string $searchKey): string
    {
        return (string) ($request->request->all($searchKey)['value'] ?? '');
    }

    #[Route('/change-status-selected', name: '_change_status_selected', methods: ['POST'])]
    public function changeStatusSelectedAction(Request $request, EventDispatcherInterface $eventDispatcher): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return $response;
        }

        $this->getTokenProvider()->checkToken((string) $request->request->get('_token'));

        $orderIds = array_map('intval', $request->request->all('order_ids'));
        $statusId = (int) $request->request->get('status_id');

        $orders = OrderQuery::create()
            ->filterById($orderIds, Criteria::IN)
            ->find();

        $updatedOrders = [];

        // Le passage par ORDER_UPDATE_STATUS garantit les effets de bord du coeur
        // (réajustement du stock, listeners métier), contrairement à un save() direct.
        foreach ($orders as $order) {
            $orderEvent = new OrderEvent($order);
            $orderEvent->setStatus($statusId);
            $eventDispatcher->dispatch($orderEvent, TheliaEvents::ORDER_UPDATE_STATUS);
            $updatedOrders[] = $order->getId();
        }

        $responseMessage = [
            'success' => 'Selected orders status updated successfully',
            'updated_orders' => $updatedOrders
        ];

        return new JsonResponse($responseMessage);
    }

    /**
     * @throws PropelException
     */
    #[Route('/get-status-selected', name: '_get_status_selected', methods: ['POST'])]
    public function getStatusSelectedAction(Request $request): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return $response;
        }

        $this->getTokenProvider()->checkToken((string) $request->request->get('_token'));

        $orderIds = array_map('intval', $request->request->all('order_ids'));

        $orders = OrderQuery::create()
            ->filterById($orderIds, Criteria::IN)
            ->find();

        $statuses = [];

        foreach ($orders as $order) {
            $orderStatus = $order->getOrderStatus();
            $statuses[] = [
                'order_id' => $order->getId(),
                'status' => $orderStatus ? $orderStatus->setLocale('fr_FR')->getTitle() : 'unknown',
                'color' => $orderStatus ? $orderStatus->getColor() : '#000000'
            ];
        }

        return new JsonResponse(['statuses' => $statuses]);
    }
}