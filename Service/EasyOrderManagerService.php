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

namespace EasyOrderManager\Service;

use EasyOrderManager\EasyOrderManager;
use EasyOrderManager\Event\BeforeFilterEvent;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Join;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Map\CustomerTableMap;
use Thelia\Model\Map\OrderAddressTableMap;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\OrderQuery;

class EasyOrderManagerService
{
    protected const ORDER_INVOICE_ADDRESS_JOIN = 'orderInvoiceAddressJoin';
    private $dispatcher;

    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function getOrderFilter(Request $request, $limit = null, $export = null)
    {
        $filters = $request->get('filter');
        $isFiltered = false;

        foreach ((array) $filters as $key => $value) {
            $requestGetParam = $request->get($key);
            if ($requestGetParam != null && $filters[$key] == '') {
                $filters[$key] = $requestGetParam;
                $request->request->set('filter', $filters);
            }
            if ($filters[$key] != '') {
                $isFiltered = true;
            }
        }

        // Use Customer for email column in applySearchCustomer
        if ($export == 'csv') {
            $query = OrderQuery::create()
                ->select('Id')
                ->joinWithCustomer('inner')
                ->joinWithOrderStatus()
                ->withColumn('order.id', 'Id')
                ->withColumn('order.ref', 'Ref')
                ->withColumn('order.created_at', 'Date_Creation')
                ->withColumn('order.invoice_date', 'Date_Facture')
                ->withColumn('order_address.company', 'company')
                ->withColumn('order_address.firstname', 'firstname')
                ->withColumn('order_address.lastname', 'lastname')
                ->withColumn('ROUND'.'('.'('.'order_product_tax.amount'.'+'.'order_product.price'.')'.'*'.'order_product.quantity'.','.'2'.')', 'amount')
                ->withColumn('order_status.code', 'status')
                ->useCustomerQuery()
                ->endUse()
                ->useOrderProductQuery()
                ->joinWithOrderProductTax()
                ->endUse();
        } else {
            $query = OrderQuery::create()
                ->useCustomerQuery()
                ->endUse();
        }

        //$request->query->get('filter')['searchCompany']
        //$request->query->get('filter')['searchCustomer']

        $this->applyOrder($request, $query);

        $queryCount = clone $query;

        $beforeFilterEvent = new BeforeFilterEvent($request, $query);
        $this->dispatcher->dispatch($beforeFilterEvent, BeforeFilterEvent::ORDER_MANAGER_BEFORE_FILTER);

        $this->filterByStatus($request, $query);
        $this->filterByPaymentModule($request, $query);
        $this->filterByCreatedAt($request, $query);
        $this->filterByInvoiceDate($request, $query);

        $this->applySearchOrder($request, $query);
        $this->applySearchCompany($request, $query);
        $this->applySearchCustomer($request, $query);

        $querySearchCount = clone $query;

        $query->offset($this->getOffset($request));

        if ($limit && $export == null) {
            $orders = $query->limit($limit)->find();
        } else {
            if ($request->query->get('filter')['searchCompany'] == '' && $request->query->get('filter')['searchCustomer'] == '') {
                $query->joinWithOrderAddressRelatedByDeliveryOrderAddressId();
            }
            $orders = $query->find();
        }

        return [
            'orders' => $orders,
            'recordsTotal' => $queryCount->count(),
            'recordsFiltered' => $querySearchCount->count(),
            'isFiltered' => $isFiltered,
        ];
    }

    protected function filterByStatus(Request $request, OrderQuery $query): void
    {
        if (0 !== $statusId = (int) $request->get('filter')['status']) {
            $query->filterByStatusId($statusId);
        }
    }

    protected function filterByPaymentModule(Request $request, OrderQuery $query): void
    {
        if (0 !== $paymentModuleId = (int) $request->get('filter')['paymentModuleId']) {
            $query->filterByPaymentModuleId($paymentModuleId);
        }
    }

    protected function filterByCreatedAt(Request $request, OrderQuery $query): void
    {
        if ('' !== $createdAtFrom = $request->get('filter')['createdAtFrom']) {
            $query->filterByInvoiceDate(sprintf('%s 00:00:00', $createdAtFrom), Criteria::GREATER_EQUAL);
        }
        if ('' !== $createdAtTo = $request->get('filter')['createdAtTo']) {
            $query->filterByInvoiceDate(sprintf('%s 23:59:59', $createdAtTo), Criteria::LESS_EQUAL);
        }
    }

    protected function filterByInvoiceDate(Request $request, OrderQuery $query): void
    {
        if ('' !== $invoiceDateFrom = $request->get('filter')['invoiceDateFrom']) {
            $query->filterByCreatedAt(sprintf('%s 00:00:00', $invoiceDateFrom), Criteria::GREATER_EQUAL);
        }
        if ('' !== $invoiceDateTo = $request->get('filter')['invoiceDateTo']) {
            $query->filterByCreatedAt(sprintf('%s 23:59:59', $invoiceDateTo), Criteria::LESS_EQUAL);
        }
    }

    protected function applySearchOrder(Request $request, OrderQuery $query): void
    {
        $value = $this->getSearchValue($request, 'searchOrder');

        if (\strlen($value) > 2) {
            $query->where(OrderTableMap::COL_REF.' LIKE ?', '%'.$value.'%', \PDO::PARAM_STR);
            $query->_or()->where(OrderTableMap::COL_ID.' LIKE ?', '%'.$value.'%', \PDO::PARAM_STR);
            $query->_or()->where(OrderTableMap::COL_INVOICE_REF.' LIKE ?', '%'.$value.'%', \PDO::PARAM_STR);
            $query->_or()->where(OrderTableMap::COL_DELIVERY_REF.' LIKE ?', '%'.$value.'%', \PDO::PARAM_STR);
        }
    }

    protected function applySearchCompany(Request $request, OrderQuery $query): void
    {
        $value = $this->getSearchValue($request, 'searchCompany');

        if (\strlen($value) > 2) {
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
                OrderAddressTableMap::COL_COMPANY." LIKE '%".$value."%'"
            );
        }
    }

    protected function applySearchCustomer(Request $request, OrderQuery $query): void
    {
        $value = $this->getSearchValue($request, 'searchCustomer');

        if (\strlen($value) > 2) {
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
                CustomerTableMap::COL_EMAIL." LIKE '%".$value."%')"
            );

            $query->groupById();
        }
    }

    protected function getSearchValue(Request $request, $searchKey)
    {
        if ($request->get($searchKey) !== null) {
            return (string) $request->get($searchKey)['value'];
        }

        return $request->get('filter')[$searchKey];
    }

    /**
     * @return string
     */
    protected function getOrderColumnName(Request $request)
    {
        if ($request->get('order') !== null) {
            $columnDefinition = $this->defineColumnsDefinition(true)[
            (int) $request->get('order')[0]['column']
        ];
        } else {
            $columnDefinition = $this->defineColumnsDefinition(true)[(int) $request->query->get('filter')];
        }

        return $columnDefinition['orm'];
    }

    protected function applyOrder(Request $request, OrderQuery $query): void
    {
        $columnName = $this->getOrderColumnName($request);
        if ($columnName == null) {
            return;
        }

        if ($request->get('order') !== null) {
            $query->orderBy(
                $columnName,
                $this->getOrderDir($request)
            );
        }
    }

    /**
     * @return string
     */
    protected function getOrderDir(Request $request)
    {
        return (string) $request->get('order')[0]['dir'] === 'asc' ? Criteria::ASC : Criteria::DESC;
    }

    /**
     * @return int
     */
    protected function getLength(Request $request)
    {
        return (int) $request->get('length');
    }

    /**
     * @return int
     */
    protected function getOffset(Request $request)
    {
        return (int) $request->get('start');
    }

    /**
     * @return int
     */
    public function getDraw(Request $request)
    {
        return (int) $request->get('draw');
    }

    /**
     * @param bool $withPrivateData
     *
     * @return array
     */
    public function defineColumnsDefinition($withPrivateData = false)
    {
        $i = -1;

        $definitions = [
            [
                'name' => 'id',
                'targets' => ++$i,
                'orm' => OrderTableMap::COL_ID,
                'title' => Translator::getInstance()->trans('Id', [], EasyOrderManager::DOMAIN_NAME),
            ],
            [
                'name' => 'ref',
                'targets' => ++$i,
                'orm' => OrderTableMap::COL_REF,
                'title' => 'Référence',
            ],
            [
                'name' => 'create_date',
                'targets' => ++$i,
                'orm' => OrderTableMap::COL_CREATED_AT,
                'title' => 'Date de création',
            ],
            [
                'name' => 'invoice_date',
                'targets' => ++$i,
                'orm' => OrderTableMap::COL_INVOICE_DATE,
                'title' => 'Date de facturation',
            ],
            [
                'name' => 'company',
                'targets' => ++$i,
                'title' => 'Entreprise',
                'orderable' => false,
            ],
            [
                'name' => 'client',
                'targets' => ++$i,
                'title' => 'Nom du client',
                'orderable' => false,
            ],
            [
                'name' => 'amount',
                'targets' => ++$i,
                'title' => 'Montant',
                'orderable' => false,
            ],
            [
                'name' => 'status',
                'targets' => ++$i,
                'title' => 'Etat',
                'orderable' => false,
            ],
            [
                'name' => 'action',
                'targets' => ++$i,
                'title' => 'Action',
                'orderable' => false,
            ],
        ];

        if (!$withPrivateData) {
            foreach ($definitions as &$definition) {
                unset($definition['orm']);
            }
        }

        return $definitions;
    }
}
