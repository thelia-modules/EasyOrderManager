<?php

namespace EasyOrderManager\Event;

use EasyOrderManager\EasyOrderManager;
use phpDocumentor\Guides\RenderCommand;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Core\Event\ActionEvent;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Base\Order;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\OrderQuery;
use Thelia\Tools\MoneyFormat;
use Thelia\Tools\URL;

class TemplateColumnDefinitionEvent extends ActionEvent
{
    public const ORDER_MANAGER_TEMPLATE_COLUMN_DEFINITION = 'order.manager.template.column.definition';

    protected $columnDefinition = [];

    protected $moneyFormat;
    protected $locale;

    public function __construct($moneyFormat,$locale)
    {
        $this->moneyFormat = $moneyFormat;
        $this->locale = $locale;
    }

    public function initColumnDefinition(){
        $i = -1;
        $this->columnDefinition = [
            [
                'name' => 'checkbox',
                'targets' => ++$i,
                'title' =>  '<input type="checkbox" id="select-all" />',
                'orderable' => false,
                'searchable' => false,
                'className' => "text-center",
                'render' => 'checkboxRender',
                'parseOrderData'=>  function(Order $order){
                    return                        [
                        'id' => $order->getId()
                    ] ;
                }
            ],
            [
                'name' => 'id',
                'targets' => ++$i,
                'orm' => OrderTableMap::COL_ID,
                'title' => Translator::getInstance()->trans('Id', [], EasyOrderManager::DOMAIN_NAME),
                'className' => "text-center",
                'render' => "hrefRender",
                'parseOrderData'=>  function(Order $order){
                    return [
                        'label' => $order->getId(),
                        'href' => URL::getInstance()->absoluteUrl('admin/order/update/'.$order->getId())
                    ];
                }
            ],
            [
                'name' => 'ref',
                'targets' => ++$i,
                'orm' => OrderTableMap::COL_REF,
                'title' => 'Référence',
                'className' => "text-center",
                'render' => "hrefRender",
                'parseOrderData'=>  function(Order $order){
                    return [
                        'label' => $order->getRef(),
                        'href' => URL::getInstance()->absoluteUrl('admin/order/update/'.$order->getId())
                    ];
                }
            ],
            [
                'name' => 'create_date',
                'targets' => ++$i,
                'orm' => OrderTableMap::COL_CREATED_AT,
                'title' => 'Date de création',
                'className' => "text-center",
                'render' => 'defaultRender',
                'parseOrderData'=>  function(Order $order){
                    return $order->getCreatedAt('d/m/y H:i:s');
                }
            ],
            [
                'name' => 'invoice_date',
                'targets' => ++$i,
                'orm' => OrderTableMap::COL_INVOICE_DATE,
                'title' => 'Date de facturation',
                'className' => "text-center",
                'render' => 'defaultRender',
                'parseOrderData'=>  function(Order $order){
                    return $order->getInvoiceDate('d/m/y H:i:s');
                }
            ],
            [
                'name' => 'company',
                'targets' => ++$i,
                'title' => 'Entreprise',
                'orderable' => false,
                'className' => "text-center",
                'render' => 'defaultRender',
                'parseOrderData'=>  function(Order $order){
                    return $order->getOrderAddressRelatedByInvoiceOrderAddressId()->getCompany();
                }
            ],
            [
                'name' => 'client',
                'targets' => ++$i,
                'title' => 'Nom du client',
                'orderable' => false,
                'className' => "text-center",
                'render' => "hrefRender",
                'parseOrderData'=>  function(Order $order){
                    return   [
                        'href' => URL::getInstance()->absoluteUrl('admin/customer/update?customer_id='.$order->getCustomerId()),
                        'label' => $order->getOrderAddressRelatedByInvoiceOrderAddressId()->getFirstname().' '.$order->getOrderAddressRelatedByInvoiceOrderAddressId()->getLastname(),
                    ];
                }
            ],
            [
                'name' => 'amount',
                'targets' => ++$i,
                'title' => 'Montant',
                'orderable' => false,
                'className' => "text-center",
                'render' => 'defaultRender',
                'parseOrderData'=>  function(Order $order){
                    return $this->moneyFormat->formatByCurrency(
                        $order->getTotalAmount(),
                        2,
                        '.',
                        ' ',
                        $order->getCurrencyId()
                    );
                }
            ],
            [
                'name' => 'status',
                'targets' => ++$i,
                'title' => 'Etat',
                'orderable' => false,
                'className' => "text-center",
                'render' => "labelRender",
                'parseOrderData'=>  function(Order $order){
                    return  [
                        'label' => $order->getOrderStatus()->setLocale($this->locale)->getTitle(),
                        'color' => $order->getOrderStatus()->getColor()
                    ];
                }
            ],
            [
                'name' => 'action',
                'targets' => ++$i,
                'title' => 'Action',
                'orderable' => false,
                'className' => "text-right",
                'render' => "actionsRender",
                'parseOrderData'=>  function(Order $order){
                    return [
                        'order_id' => $order->getId(),
                        'hrefUpdate' => URL::getInstance()->absoluteUrl('admin/order/update/'.$order->getId()),
                        'hrefPrint' => URL::getInstance()->absoluteUrl('admin/order/pdf/invoice/'.$order->getId().'/1'),
                        'isCancelled' => $order->isCancelled()
                    ];
                }
            ]
        ];
    }
    public function addColumnDefinition($template,$index = null)
    {
        if($index){
            array_splice($this->columnDefinition, $index, 0, [$template]);
        }else{
            $this->columnDefinition[] = $template;
        }
        $this->reindexColumnDefinition();
    }

    public function removeColumnDefinition($name)
    {
        foreach ($this->columnDefinition as $key => &$definition){
            if ($definition['name']===$name) {
                unset($this->columnDefinition[$key]);
            }
        }
        $this->reindexColumnDefinition();
    }

    public function getColumnDefinition($withPrivateData = false): array
    {
        if (!$withPrivateData) {
            foreach ($this->columnDefinition as &$definition) {
                unset($definition['orm']);
            }
        }

        return $this->columnDefinition;
    }

    private function reindexColumnDefinition()
    {
        $reindexarray = [];

        foreach (array_values($this->columnDefinition) as $key => &$definition){
            $definition['targets'] = $key;
            $reindexarray[$key] = $definition;
        }
        $this->columnDefinition = $reindexarray;
    }
}
