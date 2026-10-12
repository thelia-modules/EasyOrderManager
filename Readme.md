# Easy Order Manager

Add a short description here. You can also add a screenshot if needed.

## Installation

### Manually

* Copy the module into ```<thelia_root>/local/modules/``` directory and be sure that the name of the module is EasyOrderManager.
* Activate it in your thelia administration panel

### Composer

Add it in your main thelia composer.json file

```
composer require your-vendor/easy-order-manager-module
```

## Usage

Once activated, you will see a new menu link in Thelia's Back Office. This new page allows you to easly manage all orders
thanks to filters and search bars. This module uses Datables.

## Events

You can use 2 events to add filters to this module :

```
BeforeFilterEvent::ORDER_MANAGER_BEFORE_FILTER
TemplateFieldEvent::ORDER_MANAGER_TEMPLATE_FIELD
```

In BeforeFilterEvent you have access to the order query and request.

In TemplateFieldEvent you can use the function addTemplateField(fieldName, templateName)
to add a template with your new filter in it. You just need to add `js-filter-element` class to your filter input.


You can use one event to add or remove column to this module :

```
TemplateColumnDefinitionEvent::ORDER_MANAGER_TEMPLATE_COLUMN_DEFINITION
```

In TemplateColumnDefinitionEvent you can use the function `addColumnDefinition(template, index)`
to add a new column to the dataTable.

```PHP
//Example : 

<?php
 //***
class EasyOrderManagerListener implements EventSubscriberInterface
{

    public function addFieldsToTemplate( TemplateFieldEvent $event)
    {
        $event->addTemplateField('item_to_filter','item_to_filter_template_name.html');
    }

    public function addFilterOnQuery( BeforeFilterEvent $event)
    {
        $filters =$event->getRequest()->get('filter');
        $search = $event->getQuery();
        if ("" !== $filters['item_to_filter']) {
            //update $search to filter with the data in $filters['item_to_filter']
        }
    }

    public function updateColumnDefinition( TemplateColumnDefinitionEvent $event)
    {
       $event->removeColumnDefinition('invoice_date');
       $event->addColumnDefinition([
               'name' => 'id_column',
               'targets' => 6, //index of column
               'title' => 'title_column',
               'orderable' => false, // /!\ orderable is not manageable for the moment always set false
               'className' => "text-center",// class applied to the column
               'render' => "defaultRender", // name of the js function when render data
               'parseOrderData'=>  function(Order $order){
                    //return the data wich will be used in the render function
               }
       ],6);
       // /!\ targets and the index param must be the same.
    }
    public static function getSubscribedEvents()
    {
        return [
            BeforeFilterEvent::ORDER_MANAGER_BEFORE_FILTER => ['addFilterOnQuery'],
            TemplateFieldEvent::ORDER_MANAGER_TEMPLATE_FIELD => ['addFieldsToTemplate'],
            TemplateColumnDefinitionEvent::ORDER_MANAGER_TEMPLATE_COLUMN_DEFINITION => ['updateColumnDefinition'],
        ];
    }
//...
}

```

`parseOrderData` receives the order of the row and, as a second argument, the collection of the orders of the
page (`Propel\Runtime\Collection\ObjectCollection`). A column that needs related data (payment, comments, country...)
can read it for the whole page in one query, on the first row, instead of one query per row:

```PHP
'parseOrderData' => function (Order $order, ObjectCollection $page) use ($repository) {
    return $repository->forPage($page)[$order->getId()] ?? '';
},
```

The second argument is optional: a closure that declares only `Order $order` keeps working.

A sortable column names the column it sorts on in `orm` (`OrderTableMap::COL_...`). When that column does not sort the
way the data reads (a number stored in a text column), give `orderBy` instead: a closure that receives the `OrderQuery`
and the direction (`Criteria::ASC` or `Criteria::DESC`) and adds the order itself.

```PHP
'orm' => OrderTableMap::COL_INVOICE_REF,
'orderBy' => static function (OrderQuery $query, string $direction): void {
    $expression = 'CAST('.OrderTableMap::COL_INVOICE_REF.' AS UNSIGNED)';
    Criteria::ASC === $direction ? $query->addAscendingOrderByColumn($expression) : $query->addDescendingOrderByColumn($expression);
},
```

You can use this function to render the data in JS :


| Name | Render                          | Data returned by `parseOrderData`                              |
|------|---------------------------------|-------------------------------------------------|
|   **defaultRender**   | return the data without parsing | a string                                        |
|   **checkboxRender**   | create a checkbox               | `[id => 'order.id']` |
|   **hrefRender**   | create a href link              | `[href =>'urlToGo', label => 'label']`          |
|   **labelRender**   | create a label with a color     | `[color =>'#ccc', label => 'label']`          |

